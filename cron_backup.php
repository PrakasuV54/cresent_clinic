<?php
/**
 * Daily Auto WhatsApp PDF Backup Cron/Worker
 */
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_pdf_gen.php';
require_once __DIR__ . '/whatsapp_service.php';

function run_auto_backup_check() {
    $conn = get_db();
    
    // Create logs table if not exists
    $conn->exec("CREATE TABLE IF NOT EXISTS whatsapp_backup_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        backup_date DATE NOT NULL UNIQUE,
        status TEXT NOT NULL,
        attempts INTEGER DEFAULT 1,
        last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        error_message TEXT,
        pdf_path TEXT
    )");

    // Get settings
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    $waNumber = $settings['whatsapp_backup_number'] ?? '';
    $backupTime = $settings['auto_backup_time'] ?? '';

    if (!$waNumber || !$backupTime) {
        return "Auto backup settings not fully configured (WhatsApp Number: '$waNumber', Time: '$backupTime').";
    }

    $now = new DateTime();
    $todayStr = $now->format('Y-m-d');
    $currentTimeStr = $now->format('H:i');

    // Parse backup time
    $backupTimeParts = explode(':', $backupTime);
    if (count($backupTimeParts) !== 2) {
        return "Invalid backup time configuration: '$backupTime'.";
    }
    
    $backupDateTime = new DateTime($todayStr . ' ' . $backupTime);
    
    // Only check if current time is equal or past the backup time
    if ($now < $backupDateTime) {
        return "Scheduled time ($backupTime) is in the future. Current time: $currentTimeStr.";
    }

    // Check if backup already succeeded today
    $stmt = $conn->prepare("SELECT * FROM whatsapp_backup_logs WHERE backup_date = ?");
    $stmt->execute([$todayStr]);
    $log = $stmt->fetch();

    if ($log) {
        if ($log['status'] === 'success') {
            return "Auto backup for today ($todayStr) already sent successfully.";
        }
        if ($log['status'] === 'failed' && $log['attempts'] >= 3) {
            return "Auto backup failed today after max retries.";
        }
        // If failed and less than 3 attempts, we can retry if last attempt was > 15 minutes ago
        $last_attempt = strtotime($log['last_attempt']);
        $diff_minutes = (time() - $last_attempt) / 60;
        if ($diff_minutes < 15) {
            return "Auto backup retry cooldown active. Last attempt was $diff_minutes minutes ago.";
        }
        $attempts = $log['attempts'] + 1;
    } else {
        $attempts = 1;
    }

    // Process backup
    try {
        // 1. Generate PDF
        $pdf_buffer = generate_master_report_pdf($todayStr);
        if (!$pdf_buffer) {
            throw new Exception("PDF buffer was not created successfully by generator.");
        }

        // 2. Prepare text message summary
        // Fetch some basic stats for the summary text
        $stmt_stats = $conn->prepare("SELECT 
            (SELECT COUNT(*) FROM patients WHERE DATE(created_at) = ?) as total_patients,
            (SELECT SUM(consultation_fee) + SUM(total_amount) + SUM(scan_fee) + SUM(injection_cost) + SUM(iv_cost) + SUM(upt_cost) FROM prescriptions WHERE status='dispensed' AND DATE(created_at) = ?) as presc_rev,
            (SELECT SUM(total_amount) FROM direct_sales WHERE DATE(created_at) = ?) as direct_rev,
            (SELECT SUM(grand_total) FROM agency_purchases WHERE purchase_date = ?) as expenses");
        $stmt_stats->execute([$todayStr, $todayStr, $todayStr, $todayStr]);
        $stats = $stmt_stats->fetch();
        
        $presc_rev = (float)($stats['presc_rev'] ?? 0);
        $direct_rev = (float)($stats['direct_rev'] ?? 0);
        $expenses = (float)($stats['expenses'] ?? 0);
        $total_revenue = $presc_rev + $direct_rev;

        $text = "*🏥 CRESCENT CLINIC AUTOMATIC BACKUP*\n"
              . "Date: " . date('d-m-Y', strtotime($todayStr)) . "\n"
              . "Time: " . date('h:i A') . "\n\n"
              . "📊 *QUICK STATS SUMMARY*\n"
              . "• Patients Visited: " . ($stats['total_patients'] ?? 0) . "\n"
              . "• Total Revenue: Rs. " . number_format($total_revenue, 2) . "\n"
              . "• Total Expenses: Rs. " . number_format($expenses, 2) . "\n\n"
              . "_Please see the attached PDF for the full breakdown._";

        // 3. Send WhatsApp
        $result = send_whatsapp_pdf($waNumber, $pdf_path, "Master_Report_" . $todayStr . ".pdf", $text);

        if ($result['success']) {
            // Log success
            if ($log) {
                $stmt_update = $conn->prepare("UPDATE whatsapp_backup_logs SET status = 'success', attempts = ?, last_attempt = CURRENT_TIMESTAMP, error_message = NULL, pdf_path = ? WHERE id = ?");
                $stmt_update->execute([$attempts, $pdf_path, $log['id']]);
            } else {
                $stmt_insert = $conn->prepare("INSERT INTO whatsapp_backup_logs (backup_date, status, attempts, pdf_path) VALUES (?, 'success', 1, ?)");
                $stmt_insert->execute([$todayStr, $pdf_path]);
            }
            return "SUCCESS: Auto backup sent successfully to +{$waNumber} for today.";
        } else {
            throw new Exception($result['error'] ?? 'Unknown WhatsApp sending failure');
        }

    } catch (Exception $e) {
        $error_msg = $e->getMessage();
        // Log failure
        if ($log) {
            $stmt_update = $conn->prepare("UPDATE whatsapp_backup_logs SET status = 'failed', attempts = ?, last_attempt = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?");
            $stmt_update->execute([$attempts, $error_msg, $log['id']]);
        } else {
            $stmt_insert = $conn->prepare("INSERT INTO whatsapp_backup_logs (backup_date, status, attempts, error_message) VALUES (?, 'failed', 1, ?)");
            $stmt_insert->execute([$todayStr, $error_msg]);
        }
        return "FAILURE: Auto backup attempt #{$attempts} failed. Error: {$error_msg}";
    }
}

function run_instant_backup_send($to = null) {
    $conn = get_db();
    
    // Get settings
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    $waNumber = $to ?: ($settings['whatsapp_backup_number'] ?? '');
    if (!$waNumber) {
        return ['success' => false, 'error' => 'WhatsApp Number not configured in settings. Please save them in Auto Backup Settings or enter a custom number.'];
    }

    $todayStr = date('Y-m-d');
    try {
        // 1. Generate PDF
        $pdf_path = generate_master_report_pdf($todayStr);
        if (!file_exists($pdf_path)) {
            throw new Exception("PDF file was not created successfully by generator.");
        }

        // 2. Prepare text message summary
        $stmt_stats = $conn->prepare("SELECT 
            (SELECT COUNT(*) FROM patients WHERE DATE(created_at) = ?) as total_patients,
            (SELECT SUM(consultation_fee) + SUM(total_amount) + SUM(scan_fee) + SUM(injection_cost) + SUM(iv_cost) + SUM(upt_cost) FROM prescriptions WHERE status='dispensed' AND DATE(created_at) = ?) as presc_rev,
            (SELECT SUM(total_amount) FROM direct_sales WHERE DATE(created_at) = ?) as direct_rev,
            (SELECT SUM(grand_total) FROM agency_purchases WHERE purchase_date = ?) as expenses");
        $stmt_stats->execute([$todayStr, $todayStr, $todayStr, $todayStr]);
        $stats = $stmt_stats->fetch();
        
        $presc_rev = (float)($stats['presc_rev'] ?? 0);
        $direct_rev = (float)($stats['direct_rev'] ?? 0);
        $expenses = (float)($stats['expenses'] ?? 0);
        $total_revenue = $presc_rev + $direct_rev;

        $text = "*🏥 CRESCENT CLINIC REPORT BACKUP*\n"
              . "Date: " . date('d-m-Y', strtotime($todayStr)) . "\n"
              . "Time: " . date('h:i A') . "\n\n"
              . "📊 *QUICK STATS SUMMARY*\n"
              . "• Patients Visited: " . ($stats['total_patients'] ?? 0) . "\n"
              . "• Total Revenue: Rs. " . number_format($total_revenue, 2) . "\n"
              . "• Total Expenses: Rs. " . number_format($expenses, 2) . "\n\n"
              . "_Attached is the PDF report backup details._";

        // 3. Send WhatsApp
        $result = send_whatsapp_pdf($waNumber, $pdf_path, "Master_Report_" . $todayStr . ".pdf", $text);
        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'WhatsApp backup sent successfully. ' . ($result['message'] ?? '')
            ];
        } else {
            return ['success' => false, 'error' => $result['error'] ?? 'Unknown WhatsApp API failure'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Run CLI directly if requested
if (php_sapi_name() === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] Running Backup Check...\n";
    $status = run_auto_backup_check();
    echo $status . "\n";
}
