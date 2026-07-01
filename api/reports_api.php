<?php
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

// Enforce management access
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'management') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = get_db();

$action = $_GET['action'] ?? 'get_reports';

if ($action === 'get_reports') {
    $period = $_GET['period'] ?? 'today';
    $start_date = $_GET['start'] ?? '';
    $end_date = $_GET['end'] ?? '';
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weekly_start = date('Y-m-d', strtotime('-7 days'));
    $thirty_days_start = date('Y-m-d', strtotime('-30 days'));
    $this_month = date('Y-m');
    $last_month = date('Y-m', strtotime('first day of last month'));
    $this_year = date('Y');

    $date_filter_patients = "1=1";
    $date_filter_sales = "1=1";
    $date_filter_agency = "1=1";
    $date_filter_returns = "1=1";
    
    if ($period === 'today') {
        $date_filter_patients = "DATE(created_at) = '$today'";
        $date_filter_sales = "DATE(created_at) = '$today'";
        $date_filter_agency = "purchase_date = '$today'";
        $date_filter_returns = "return_date = '$today'";
    } elseif ($period === 'yesterday') {
        $date_filter_patients = "DATE(created_at) = '$yesterday'";
        $date_filter_sales = "DATE(created_at) = '$yesterday'";
        $date_filter_agency = "purchase_date = '$yesterday'";
        $date_filter_returns = "return_date = '$yesterday'";
    } elseif ($period === 'weekly') {
        $date_filter_patients = "DATE(created_at) >= '$weekly_start'";
        $date_filter_sales = "DATE(created_at) >= '$weekly_start'";
        $date_filter_agency = "purchase_date >= '$weekly_start'";
        $date_filter_returns = "return_date >= '$weekly_start'";
    } elseif ($period === 'thirty_days') {
        $date_filter_patients = "DATE(created_at) >= '$thirty_days_start'";
        $date_filter_sales = "DATE(created_at) >= '$thirty_days_start'";
        $date_filter_agency = "purchase_date >= '$thirty_days_start'";
        $date_filter_returns = "return_date >= '$thirty_days_start'";
    } elseif ($period === 'monthly') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y-%m') = '$this_month'";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y-%m') = '$this_month'";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y-%m') = '$this_month'";
        $date_filter_returns = "DATE_FORMAT(return_date, '%Y-%m') = '$this_month'";
    } elseif ($period === 'last_month') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y-%m') = '$last_month'";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y-%m') = '$last_month'";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y-%m') = '$last_month'";
        $date_filter_returns = "DATE_FORMAT(return_date, '%Y-%m') = '$last_month'";
    } elseif ($period === 'yearly') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y') = '$this_year'";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y') = '$this_year'";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y') = '$this_year'";
        $date_filter_returns = "DATE_FORMAT(return_date, '%Y') = '$this_year'";
    } elseif ($period === 'custom' && $start_date && $end_date) {
        $date_filter_patients = "DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
        $date_filter_sales = "DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
        $date_filter_agency = "purchase_date BETWEEN '$start_date' AND '$end_date'";
        $date_filter_returns = "return_date BETWEEN '$start_date' AND '$end_date'";
    }

    $response = [];

    // 1. Executive Summary & Financial Report
    // Patients
    $stmt = $conn->query("SELECT COUNT(*) as total_patients FROM patients WHERE $date_filter_patients");
    $total_patients = $stmt->fetchColumn() ?: 0;

    // Consultations & Revenue
    $stmt = $conn->query("SELECT 
        COUNT(*) as total_consultations,
        SUM(consultation_fee) as doctor_revenue,
        SUM(total_amount) as med_revenue,
        SUM(cost_amount) as med_cost,
        SUM(scan_fee) as scan_revenue,
        SUM(injection_cost) as inj_cost,
        SUM(iv_cost) as iv_cost,
        SUM(upt_cost) as upt_cost,
        SUM(cash_amount) as cash,
        SUM(gpay_amount) as gpay,
        SUM(phonepe_amount) as phonepe,
        SUM(paid_amount) as paid,
        SUM(balance_amount) as pending,
        SUM(prev_balance_cleared) as cleared_pending,
        SUM((total_amount + consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) * (IFNULL(discount_percent, 0) / 100.0)) as total_discount
        FROM prescriptions WHERE status='dispensed' AND $date_filter_patients");
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch Injection, IV, UPT revenue from custom logic where they are inside medicines JSON
    $stmt = $conn->query("SELECT id, medicines, injection_details, iv_details, upt_card FROM prescriptions WHERE status='dispensed' AND $date_filter_patients");
    $all_prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inj_revenue = 0;
    $iv_revenue = 0;
    $upt_revenue = 0;
    $total_medicine_revenue_only = 0;

    $medicine_stats = [];

    foreach ($all_prescriptions as $p) {
        $meds = json_decode($p['medicines'] ?: '[]', true);
        foreach ($meds as $m) {
            $name = $m['name'] ?? '';
            $qty = (float)($m['qty'] ?? 0) - (float)($m['returned_qty'] ?? 0);
            $amt = (float)($m['amount'] ?? 0) - (float)($m['returned_amount'] ?? 0);
            
            if ($name === 'Injection Fee') {
                $inj_revenue += $amt;
            } elseif ($name === 'IV Fluid Fee') {
                $iv_revenue += $amt;
            } elseif ($name === 'UPT Card Fee') {
                $upt_revenue += $amt;
            } else {
                $total_medicine_revenue_only += $amt;
            }

            if (!isset($medicine_stats[$name])) {
                $medicine_stats[$name] = ['qty' => 0, 'revenue' => 0];
            }
            $medicine_stats[$name]['qty'] += $qty;
            $medicine_stats[$name]['revenue'] += $amt;
        }
    }

    $doctor_revenue = (float)($pr['doctor_revenue'] ?? 0);
    $scan_revenue = (float)($pr['scan_revenue'] ?? 0);
    $medicine_revenue_presc = $total_medicine_revenue_only + $inj_revenue + $iv_revenue + $upt_revenue;

    // Direct Sales
    $stmt = $conn->query("SELECT 
        COUNT(*) as total_sales,
        SUM(total_amount) as direct_revenue,
        SUM(cost_amount) as direct_cost,
        SUM(injection_cost) as ds_inj,
        SUM(iv_cost) as ds_iv,
        SUM(upt_cost) as ds_upt,
        SUM(cash_amount) as ds_cash,
        SUM(gpay_amount) as ds_gpay,
        SUM(phonepe_amount) as ds_phonepe,
        SUM(bank_amount) as ds_bank,
        SUM(paid_amount) as ds_paid,
        SUM(balance_amount) as ds_pending
        FROM direct_sales WHERE $date_filter_sales");
    $ds = $stmt->fetch(PDO::FETCH_ASSOC);

    $direct_revenue = (float)($ds['direct_revenue'] ?? 0);
    $ds_inj = (float)($ds['ds_inj'] ?? 0);
    $ds_iv = (float)($ds['ds_iv'] ?? 0);
    $ds_upt = (float)($ds['ds_upt'] ?? 0);
    $ds_med_revenue = $direct_revenue - $ds_inj - $ds_iv - $ds_upt;

    // Combined Totals
    $total_revenue = $doctor_revenue + $medicine_revenue_presc + $scan_revenue + $direct_revenue;
    $total_discount = (float)($pr['total_discount'] ?? 0);
    $total_revenue -= $total_discount;

    // Agency Purchases (Expenses)
    $stmt = $conn->query("SELECT COUNT(*) as purchase_count, SUM(grand_total) as total_purchases FROM agency_purchases WHERE $date_filter_agency");
    $ap = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_expenses = (float)($ap['total_purchases'] ?? 0);

    // Returns Total
    $stmt = $conn->query("SELECT SUM(return_amount) FROM medicine_returns WHERE $date_filter_returns");
    $total_returned_amount = (float)$stmt->fetchColumn() ?: 0;

    // Net Profit
    $med_cost = (float)($pr['med_cost'] ?? 0);
    $direct_cost = (float)($ds['direct_cost'] ?? 0);
    $net_profit = $total_revenue - ($med_cost + $direct_cost);

    // Inventory Value
    $stmt = $conn->query("SELECT COUNT(*) as count, SUM(stock * purchase_price) as inventory_value FROM inventory WHERE stock > 0");
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    $inventory_value = (float)($inv['inventory_value'] ?? 0);

    // Low stock
    $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE stock <= min_stock AND stock > 0");
    $low_stock = $stmt->fetchColumn() ?: 0;

    $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE stock = 0");
    $out_of_stock = $stmt->fetchColumn() ?: 0;

    // Suppliers
    $stmt = $conn->query("SELECT COUNT(*) FROM agency_suppliers");
    $total_suppliers = $stmt->fetchColumn() ?: 0;

    $pending_amount = (float)($pr['pending'] ?? 0) + (float)($ds['ds_pending'] ?? 0);
    $collection_amount = (float)($pr['paid'] ?? 0) + (float)($ds['ds_paid'] ?? 0);
    $cleared_pending = (float)($pr['cleared_pending'] ?? 0);

    $cash_collection = (float)($pr['cash'] ?? 0) + (float)($ds['ds_cash'] ?? 0);
    $gpay_collection = (float)($pr['gpay'] ?? 0) + (float)($ds['ds_gpay'] ?? 0);
    $phonepe_collection = (float)($pr['phonepe'] ?? 0) + (float)($ds['ds_phonepe'] ?? 0);
    $bank_collection = (float)($ds['ds_bank'] ?? 0);

    // Calculate Gents and Ladies doctor revenue splits
    $stmt_doc = $conn->query("SELECT doctor_type, SUM(consultation_fee) as doc_rev FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY doctor_type");
    $gents_rev = 0;
    $ladies_rev = 0;
    foreach ($stmt_doc->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $type = strtolower($d['doctor_type'] ?? '');
        $rev = (float)($d['doc_rev'] ?? 0);
        if (strpos($type, 'gents') !== false) {
            $gents_rev += $rev;
        } else {
            $ladies_rev += $rev;
        }
    }

    $response['executive'] = [
        'total_patients' => $total_patients,
        'total_consultations' => (int)($pr['total_consultations'] ?? 0),
        'direct_sales_count' => (int)($ds['total_sales'] ?? 0),
        'total_revenue' => $total_revenue,
        'doctor_revenue' => $doctor_revenue,
        'gents_doctor_revenue' => $gents_rev,
        'ladies_doctor_revenue' => $ladies_rev,
        'pharmacy_sales_revenue' => $total_medicine_revenue_only,
        'direct_sales_revenue' => $ds_med_revenue,
        'injection_sales_revenue' => $inj_revenue + $ds_inj,
        'iv_sales_revenue' => $iv_revenue + $ds_iv,
        'upt_sales_revenue' => $upt_revenue + $ds_upt,
        'scan_revenue' => $scan_revenue,
        'scan_profit' => $scan_revenue,
        'medicine_profit' => ($total_medicine_revenue_only - $med_cost) + ($ds_med_revenue - $direct_cost),
        'total_expenses' => $total_expenses,
        'net_profit' => $net_profit,
        'pending_amount' => $pending_amount,
        'cleared_pending' => $cleared_pending,
        'collection_amount' => $collection_amount,
        'total_returns' => $total_returned_amount,
        'total_purchases' => $total_expenses,
        'total_suppliers' => $total_suppliers,
        'inventory_value' => $inventory_value,
        'low_stock_count' => $low_stock,
        'out_of_stock_count' => $out_of_stock,
        'medicine_cost' => $med_cost,
        'direct_sales_cost' => $direct_cost
    ];

    $upi_total = $gpay_collection + $phonepe_collection + $bank_collection;
    $response['financial'] = [
        'Cash Total' => $cash_collection,
        'UPI Total' => $upi_total,
        'Pending Fees' => $pending_amount,
        'Cleared Pending Fees' => $cleared_pending
    ];

    // Doctors Report
    $stmt = $conn->query("SELECT doctor_name, doctor_type, COUNT(*) as p_count, SUM(consultation_fee) as doc_rev, SUM(total_amount) as med_rev FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY doctor_name, doctor_type");
    $response['doctors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pharmacy Report (Top meds)
    uasort($medicine_stats, function($a, $b) { return $b['qty'] <=> $a['qty']; });
    $top_meds = [];
    $i = 0;
    foreach ($medicine_stats as $name => $stats) {
        if ($i++ >= 10) break;
        $top_meds[] = ['name' => $name, 'qty' => $stats['qty'], 'revenue' => $stats['revenue']];
    }
    $response['top_medicines'] = $top_meds;

    // Latest Patients List (with all payment/breakdown details)
    $date_filter_patients_join = str_replace("created_at", "p.created_at", $date_filter_patients);
    $stmt = $conn->query("SELECT p.id, p.patient_id, p.name, p.phone, p.doctor_name, p.created_at, 
        pr.consultation_fee, pr.total_amount, pr.scan_fee, pr.scan_type, pr.scan_notes, 
        pr.injection_cost, pr.injection_details, pr.iv_cost, pr.iv_details, pr.upt_cost, pr.upt_card, 
        pr.paid_amount, pr.balance_amount, pr.medicines, pr.cash_amount, pr.gpay_amount, pr.phonepe_amount, pr.discount_percent
        FROM patients p LEFT JOIN prescriptions pr ON p.id = pr.patient_id 
        WHERE $date_filter_patients_join ORDER BY p.id DESC LIMIT 100");
    $response['patients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Direct Sales List
    $stmt = $conn->query("SELECT * FROM direct_sales WHERE $date_filter_sales ORDER BY id DESC LIMIT 100");
    $response['direct_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Returns List
    $stmt = $conn->query("SELECT * FROM medicine_returns WHERE $date_filter_returns ORDER BY id DESC LIMIT 100");
    $response['returns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agency Purchases List
    $stmt = $conn->query("SELECT a.invoice_number, a.purchase_date, a.payment_mode, a.grand_total, s.name as supplier_name 
        FROM agency_purchases a LEFT JOIN agency_suppliers s ON a.supplier_id = s.id 
        WHERE $date_filter_agency ORDER BY a.purchase_date DESC LIMIT 100");
    $response['agency_purchases'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agency Suppliers List
    $stmt = $conn->query("
        SELECT s.*, 
               COALESCE((SELECT SUM(grand_total) FROM agency_purchases WHERE supplier_id = s.id), 0) as total_purchase,
               COALESCE((SELECT SUM(paid_amount) FROM agency_purchases WHERE supplier_id = s.id), 0) as paid_amount,
               COALESCE((SELECT SUM(balance_amount) FROM agency_purchases WHERE supplier_id = s.id), 0) as pending_balance
        FROM agency_suppliers s 
        ORDER BY s.name ASC
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as &$s) {
        if ($s['total_purchase'] > 0 && $s['pending_balance'] <= 0) {
            $s['payment_status'] = 'Paid';
        } else if ($s['paid_amount'] > 0) {
            $s['payment_status'] = 'Partially Paid';
        } else {
            $s['payment_status'] = 'Not Paid';
        }
    }
    $response['agency_suppliers'] = $suppliers;

    // Inventory List
    $stmt = $conn->query("SELECT * FROM inventory ORDER BY name ASC");
    $response['inventory'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Daily Statistics
    $daily_stmt = $conn->query("SELECT DATE(created_at) as date, SUM(consultation_fee) as doc_fee, SUM(total_amount) as med_fee, SUM(scan_fee) as scan_fee, SUM(injection_cost) as inj_fee, SUM(iv_cost) as iv_fee, SUM(upt_cost) as upt_fee, SUM((total_amount + consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) * (IFNULL(discount_percent, 0) / 100.0)) as daily_discount FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY DATE(created_at)");
    $daily_presc = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_ds_stmt = $conn->query("SELECT DATE(created_at) as date, SUM(total_amount) as ds_fee FROM direct_sales WHERE $date_filter_sales GROUP BY DATE(created_at)");
    $daily_ds = $daily_ds_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_pur_stmt = $conn->query("SELECT purchase_date as date, SUM(grand_total) as expense FROM agency_purchases WHERE $date_filter_agency GROUP BY purchase_date");
    $daily_pur = $daily_pur_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_stats = [];
    foreach ($daily_presc as $r) {
        $d = $r['date'];
        if (!$d) continue;
        $discount = (float)($r['daily_discount'] ?? 0);
        $inj = (float)($r['inj_fee'] ?? 0);
        $iv = (float)($r['iv_fee'] ?? 0);
        $upt = (float)($r['upt_fee'] ?? 0);
        $presc_rev = (float)$r['doc_fee'] + (float)$r['med_fee'] + (float)$r['scan_fee'] + $inj + $iv + $upt - $discount;
        $daily_stats[$d] = [
            'date' => $d,
            'doc_fee' => (float)$r['doc_fee'],
            'med_fee' => (float)$r['med_fee'] + $inj + $iv + $upt,
            'scan_fee' => (float)$r['scan_fee'],
            'ds_fee' => 0.0,
            'revenue' => $presc_rev,
            'expense' => 0.0,
            'profit' => $presc_rev,
            'total' => $presc_rev
        ];
    }
    foreach ($daily_ds as $r) {
        $d = $r['date'];
        if (!$d) continue;
        if (!isset($daily_stats[$d])) {
            $daily_stats[$d] = [
                'date' => $d,
                'doc_fee' => 0.0,
                'med_fee' => 0.0,
                'scan_fee' => 0.0,
                'ds_fee' => (float)$r['ds_fee'],
                'revenue' => (float)$r['ds_fee'],
                'expense' => 0.0,
                'profit' => (float)$r['ds_fee'],
                'total' => (float)$r['ds_fee']
            ];
        } else {
            $daily_stats[$d]['ds_fee'] = (float)$r['ds_fee'];
            $daily_stats[$d]['revenue'] += (float)$r['ds_fee'];
            $daily_stats[$d]['profit'] += (float)$r['ds_fee'];
            $daily_stats[$d]['total'] += (float)$r['ds_fee'];
        }
    }
    foreach ($daily_pur as $r) {
        $d = $r['date'];
        if (!$d) continue;
        if (!isset($daily_stats[$d])) {
            $daily_stats[$d] = [
                'date' => $d,
                'doc_fee' => 0.0,
                'med_fee' => 0.0,
                'scan_fee' => 0.0,
                'ds_fee' => 0.0,
                'revenue' => 0.0,
                'expense' => (float)$r['expense'],
                'profit' => -(float)$r['expense'],
                'total' => 0.0
            ];
        } else {
            $daily_stats[$d]['expense'] = (float)$r['expense'];
            $daily_stats[$d]['profit'] -= (float)$r['expense'];
        }
    }
    krsort($daily_stats);
    $response['daily_stats'] = array_values($daily_stats);

    // Parse patient medicines for cleaner frontend use
    foreach ($response['patients'] as &$p) {
        $meds_arr = [];
        $p_meds = json_decode($p['medicines'] ?: '[]', true);
        foreach ($p_meds as $m) {
            $name = $m['name'] ?? '';
            if ($name === 'Injection Fee' || $name === 'IV Fluid Fee' || $name === 'UPT Card Fee') continue;
            $meds_arr[] = $name . ' (' . ($m['qty'] ?? 1) . ')';
        }
        $p['parsed_medicines'] = implode(', ', $meds_arr);
    }
    unset($p);

    // Parse direct sales medicines for cleaner frontend use
    foreach ($response['direct_sales'] as &$s) {
        $meds_arr = [];
        $s_meds = json_decode($s['medicines'] ?: '[]', true);
        foreach ($s_meds as $m) {
            $name = $m['name'] ?? '';
            $meds_arr[] = $name . ' (' . ($m['qty'] ?? 1) . ')';
        }
        $s['parsed_medicines'] = implode(', ', $meds_arr);
    }
    unset($s);

    // Fetch backup settings
    $backup_settings = [];
    $stmt_set = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmt_set) {
        foreach ($stmt_set->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $backup_settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    $response['backup_settings'] = [
        'whatsapp_backup_number' => $backup_settings['whatsapp_backup_number'] ?? '',
        'auto_backup_time' => $backup_settings['auto_backup_time'] ?? '',
        'whatsapp_api_provider' => $backup_settings['whatsapp_api_provider'] ?? 'mock',
        'whatsapp_meta_token' => $backup_settings['whatsapp_meta_token'] ?? '',
        'whatsapp_meta_phone_id' => $backup_settings['whatsapp_meta_phone_id'] ?? '',
        'whatsapp_custom_url' => $backup_settings['whatsapp_custom_url'] ?? '',
        'whatsapp_api_token' => $backup_settings['whatsapp_api_token'] ?? ''
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

if ($action === 'save_backup_settings') {
    $conn = get_db();
    
    // Create system_settings table if it doesn't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(255) PRIMARY KEY,
        setting_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $settings_to_save = [
        'whatsapp_backup_number' => trim($_POST['whatsapp_backup_number'] ?? ''),
        'auto_backup_time' => trim($_POST['auto_backup_time'] ?? ''),
        'whatsapp_api_provider' => trim($_POST['whatsapp_api_provider'] ?? 'mock'),
        'whatsapp_meta_token' => trim($_POST['whatsapp_meta_token'] ?? ''),
        'whatsapp_meta_phone_id' => trim($_POST['whatsapp_meta_phone_id'] ?? ''),
        'whatsapp_custom_url' => trim($_POST['whatsapp_custom_url'] ?? ''),
        'whatsapp_api_token' => trim($_POST['whatsapp_api_token'] ?? '')
    ];
    
    $stmt_ins = $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings_to_save as $key => $value) {
        $stmt_ins->execute([$key, $value]);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'trigger_whatsapp_backup') {
    require_once __DIR__ . '/../cron_backup.php';
    $to = trim($_POST['to'] ?? $_GET['to'] ?? '');
    $result = run_instant_backup_send($to);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if ($action === 'get_print_report') {
    $period = $_GET['period'] ?? 'today';
    $start_date = $_GET['start'] ?? '';
    $end_date = $_GET['end'] ?? '';
    
    // Copy the same date filter logic
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weekly_start = date('Y-m-d', strtotime('-7 days'));
    $thirty_days_start = date('Y-m-d', strtotime('-30 days'));
    $this_month = date('Y-m');
    $last_month = date('Y-m', strtotime('first day of last month'));
    $this_year = date('Y');

    $date_filter_patients = "1=1";
    $date_filter_sales = "1=1";
    $date_filter_agency = "1=1";
    $date_filter_returns = "1=1";
    
    if ($period === 'today') {
        $date_filter_patients = "DATE(created_at) = '$today'";
        $date_filter_sales = "DATE(created_at) = '$today'";
        $date_filter_agency = "purchase_date = '$today'";
        $date_filter_returns = "return_date = '$today'";
    } elseif ($period === 'yesterday') {
        $date_filter_patients = "DATE(created_at) = '$yesterday'";
        $date_filter_sales = "DATE(created_at) = '$yesterday'";
        $date_filter_agency = "purchase_date = '$yesterday'";
        $date_filter_returns = "return_date = '$yesterday'";
    } elseif ($period === 'weekly') {
        $date_filter_patients = "DATE(created_at) >= '$weekly_start'";
        $date_filter_sales = "DATE(created_at) >= '$weekly_start'";
        $date_filter_agency = "purchase_date >= '$weekly_start'";
        $date_filter_returns = "return_date >= '$weekly_start'";
    } elseif ($period === 'thirty_days') {
        $date_filter_patients = "DATE(created_at) >= '$thirty_days_start'";
        $date_filter_sales = "DATE(created_at) >= '$thirty_days_start'";
        $date_filter_agency = "purchase_date >= '$thirty_days_start'";
        $date_filter_returns = "return_date >= '$thirty_days_start'";
    } elseif ($period === 'monthly') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y-%m') = '$this_month'";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y-%m') = '$this_month'";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y-%m') = '$this_month'";
        $date_filter_returns = "DATE_FORMAT(return_date, '%Y-%m') = '$this_month'";
    } elseif ($period === 'last_month') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y-%m') = '$last_month'";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y-%m') = '$last_month'";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y-%m') = '$last_month'";
        $date_filter_returns = "DATE_FORMAT(return_date, '%Y-%m') = '$last_month'";
    } elseif ($period === 'yearly') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y') = '$this_year'";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y') = '$this_year'";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y') = '$this_year'";
        $date_filter_returns = "DATE_FORMAT(return_date, '%Y') = '$this_year'";
    } elseif ($period === 'custom' && $start_date && $end_date) {
        $date_filter_patients = "DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
        $date_filter_sales = "DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
        $date_filter_agency = "purchase_date BETWEEN '$start_date' AND '$end_date'";
        $date_filter_returns = "return_date BETWEEN '$start_date' AND '$end_date'";
    }

    $response = [];

    // Include original get_reports data but without LIMITs
    // Executive Summary & Financial Report
    $stmt = $conn->query("SELECT COUNT(*) as total_patients FROM patients WHERE $date_filter_patients");
    $total_patients = $stmt->fetchColumn() ?: 0;

    $stmt = $conn->query("SELECT 
        COUNT(*) as total_consultations,
        SUM(consultation_fee) as doctor_revenue,
        SUM(total_amount) as med_revenue,
        SUM(cost_amount) as med_cost,
        SUM(scan_fee) as scan_revenue,
        SUM(injection_cost) as inj_cost,
        SUM(iv_cost) as iv_cost,
        SUM(upt_cost) as upt_cost,
        SUM(cash_amount) as cash,
        SUM(gpay_amount) as gpay,
        SUM(phonepe_amount) as phonepe,
        SUM(paid_amount) as paid,
        SUM(balance_amount) as pending,
        SUM(prev_balance_cleared) as cleared_pending,
        SUM((total_amount + consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) * (IFNULL(discount_percent, 0) / 100.0)) as total_discount
        FROM prescriptions WHERE status='dispensed' AND $date_filter_patients");
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->query("SELECT id, medicines, injection_details, iv_details, upt_card FROM prescriptions WHERE status='dispensed' AND $date_filter_patients");
    $all_prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inj_revenue = 0; $iv_revenue = 0; $upt_revenue = 0; $total_medicine_revenue_only = 0;
    $medicine_stats = [];

    foreach ($all_prescriptions as $p) {
        $meds = json_decode($p['medicines'] ?: '[]', true);
        foreach ($meds as $m) {
            $name = $m['name'] ?? '';
            $qty = (float)($m['qty'] ?? 0) - (float)($m['returned_qty'] ?? 0);
            $amt = (float)($m['amount'] ?? 0) - (float)($m['returned_amount'] ?? 0);
            
            if ($name === 'Injection Fee') $inj_revenue += $amt;
            elseif ($name === 'IV Fluid Fee') $iv_revenue += $amt;
            elseif ($name === 'UPT Card Fee') $upt_revenue += $amt;
            else $total_medicine_revenue_only += $amt;

            if (!isset($medicine_stats[$name])) $medicine_stats[$name] = ['qty' => 0, 'revenue' => 0];
            $medicine_stats[$name]['qty'] += $qty;
            $medicine_stats[$name]['revenue'] += $amt;
        }
    }

    $doctor_revenue = (float)($pr['doctor_revenue'] ?? 0);
    $scan_revenue = (float)($pr['scan_revenue'] ?? 0);
    $medicine_revenue_presc = $total_medicine_revenue_only + $inj_revenue + $iv_revenue + $upt_revenue;

    // Direct Sales
    $stmt = $conn->query("SELECT 
        COUNT(*) as total_sales,
        SUM(total_amount) as direct_revenue,
        SUM(cost_amount) as direct_cost,
        SUM(injection_cost) as ds_inj,
        SUM(iv_cost) as ds_iv,
        SUM(upt_cost) as ds_upt,
        SUM(cash_amount) as ds_cash,
        SUM(gpay_amount) as ds_gpay,
        SUM(phonepe_amount) as ds_phonepe,
        SUM(bank_amount) as ds_bank,
        SUM(paid_amount) as ds_paid,
        SUM(balance_amount) as ds_pending
        FROM direct_sales WHERE $date_filter_sales");
    $ds = $stmt->fetch(PDO::FETCH_ASSOC);

    $direct_revenue = (float)($ds['direct_revenue'] ?? 0);
    $ds_inj = (float)($ds['ds_inj'] ?? 0);
    $ds_iv = (float)($ds['ds_iv'] ?? 0);
    $ds_upt = (float)($ds['ds_upt'] ?? 0);
    $ds_med_revenue = $direct_revenue - $ds_inj - $ds_iv - $ds_upt;

    // Combined Totals
    $total_revenue = $doctor_revenue + $medicine_revenue_presc + $scan_revenue + $direct_revenue;
    $total_discount = (float)($pr['total_discount'] ?? 0);
    $total_revenue -= $total_discount;

    // Agency Purchases (Expenses)
    $stmt = $conn->query("SELECT COUNT(*) as purchase_count, SUM(grand_total) as total_purchases FROM agency_purchases WHERE $date_filter_agency");
    $ap = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_expenses = (float)($ap['total_purchases'] ?? 0);

    // Fetch Medicine Returns for the period
    $stmt = $conn->query("SELECT * FROM medicine_returns WHERE $date_filter_returns ORDER BY id DESC");
    $response['returns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate Return Totals
    $total_returned_amount = 0;
    foreach ($response['returns'] as $r) {
        $total_returned_amount += (float)($r['return_amount'] ?? 0);
    }

    // Inventory Value
    $stmt = $conn->query("SELECT COUNT(*) as count, SUM(stock * purchase_price) as inventory_value FROM inventory WHERE stock > 0");
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    $inventory_value = (float)($inv['inventory_value'] ?? 0);

    $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE stock <= min_stock AND stock > 0");
    $low_stock = $stmt->fetchColumn() ?: 0;

    $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE stock = 0");
    $out_of_stock = $stmt->fetchColumn() ?: 0;

    $stmt = $conn->query("SELECT COUNT(*) FROM agency_suppliers");
    $total_suppliers = $stmt->fetchColumn() ?: 0;

    // Net Profit - MUST USE THE FORMULA: Total Revenue - (Medicine Cost + Direct Sale Cost)
    $med_cost = (float)($pr['med_cost'] ?? 0);
    $direct_cost = (float)($ds['direct_cost'] ?? 0);
    $net_profit = $total_revenue - ($med_cost + $direct_cost);

    $pending_amount = (float)($pr['pending'] ?? 0) + (float)($ds['ds_pending'] ?? 0);
    $collection_amount = (float)($pr['paid'] ?? 0) + (float)($ds['ds_paid'] ?? 0);
    $cleared_pending = (float)($pr['cleared_pending'] ?? 0);

    $cash_collection = (float)($pr['cash'] ?? 0) + (float)($ds['ds_cash'] ?? 0);
    $gpay_collection = (float)($pr['gpay'] ?? 0) + (float)($ds['ds_gpay'] ?? 0);
    $phonepe_collection = (float)($pr['phonepe'] ?? 0) + (float)($ds['ds_phonepe'] ?? 0);
    $bank_collection = (float)($ds['ds_bank'] ?? 0);

    // Calculate Gents and Ladies doctor revenue splits
    $stmt_doc = $conn->query("SELECT doctor_type, SUM(consultation_fee) as doc_rev FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY doctor_type");
    $gents_rev = 0;
    $ladies_rev = 0;
    foreach ($stmt_doc->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $type = strtolower($d['doctor_type'] ?? '');
        $rev = (float)($d['doc_rev'] ?? 0);
        if (strpos($type, 'gents') !== false) {
            $gents_rev += $rev;
        } else {
            $ladies_rev += $rev;
        }
    }

    $response['executive'] = [
        'total_patients' => $total_patients,
        'total_consultations' => (int)($pr['total_consultations'] ?? 0),
        'direct_sales_count' => (int)($ds['total_sales'] ?? 0),
        'total_revenue' => $total_revenue,
        'doctor_revenue' => $doctor_revenue,
        'gents_doctor_revenue' => $gents_rev,
        'ladies_doctor_revenue' => $ladies_rev,
        'pharmacy_sales_revenue' => $total_medicine_revenue_only,
        'direct_sales_revenue' => $ds_med_revenue,
        'injection_sales_revenue' => $inj_revenue + $ds_inj,
        'iv_sales_revenue' => $iv_revenue + $ds_iv,
        'upt_sales_revenue' => $upt_revenue + $ds_upt,
        'scan_revenue' => $scan_revenue,
        'scan_profit' => $scan_revenue,
        'medicine_profit' => ($total_medicine_revenue_only - $med_cost) + ($ds_med_revenue - $direct_cost),
        'total_expenses' => $total_expenses,
        'net_profit' => $net_profit,
        'pending_amount' => $pending_amount,
        'cleared_pending' => $cleared_pending,
        'collection_amount' => $collection_amount,
        'total_returns' => $total_returned_amount,
        'total_purchases' => $total_expenses,
        'total_suppliers' => $total_suppliers,
        'inventory_value' => $inventory_value,
        'low_stock_count' => $low_stock,
        'out_of_stock_count' => $out_of_stock,
        'medicine_cost' => $med_cost,
        'direct_sales_cost' => $direct_cost
    ];

    $upi_total = $gpay_collection + $phonepe_collection + $bank_collection;
    $response['financial'] = [
        'Cash Total' => $cash_collection,
        'UPI Total' => $upi_total,
        'Pending Fees' => $pending_amount,
        'Cleared Pending Fees' => $cleared_pending
    ];

    $stmt = $conn->query("SELECT doctor_name, doctor_type, COUNT(*) as p_count, SUM(consultation_fee) as doc_rev, SUM(total_amount) as med_rev FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY doctor_name, doctor_type");
    $response['doctors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    uasort($medicine_stats, function($a, $b) { return $b['qty'] <=> $a['qty']; });
    $top_meds = [];
    $i = 0;
    foreach ($medicine_stats as $name => $stats) {
        if ($i++ >= 50) break; // Limit top meds in print to 50
        $top_meds[] = ['name' => $name, 'qty' => $stats['qty'], 'revenue' => $stats['revenue']];
    }
    $response['top_medicines'] = $top_meds;

    $date_filter_patients_join = str_replace("created_at", "p.created_at", $date_filter_patients);
    
    // NO LIMIT FOR PRINT
    $stmt = $conn->query("SELECT p.name, p.phone, p.doctor_name, p.created_at, pr.consultation_fee, pr.total_amount, pr.paid_amount, pr.balance_amount, pr.medicines 
        FROM patients p LEFT JOIN prescriptions pr ON p.id = pr.patient_id 
        WHERE $date_filter_patients_join ORDER BY p.id ASC");
    $response['patients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // NO LIMIT FOR PRINT
    $stmt = $conn->query("SELECT customer_name, mobile_number, medicines, total_amount, paid_amount, balance_amount, created_at FROM direct_sales WHERE $date_filter_sales ORDER BY id ASC");
    $response['direct_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // AGENCY PURCHASES
    $stmt = $conn->query("SELECT a.invoice_number, a.purchase_date, a.payment_mode, a.grand_total, s.name as supplier_name 
        FROM agency_purchases a LEFT JOIN agency_suppliers s ON a.supplier_id = s.id 
        WHERE $date_filter_agency ORDER BY a.purchase_date ASC");
    $response['agency_purchases'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // INVENTORY STOCK (ALL)
    $stmt = $conn->query("SELECT * FROM inventory");
    $response['inventory'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse patient medicines for cleaner frontend use
    foreach ($response['patients'] as &$p) {
        $meds_arr = [];
        $p_meds = json_decode($p['medicines'] ?: '[]', true);
        foreach ($p_meds as $m) {
            $name = $m['name'] ?? '';
            if ($name === 'Injection Fee' || $name === 'IV Fluid Fee' || $name === 'UPT Card Fee') continue;
            $meds_arr[] = $name . ' (' . ($m['qty'] ?? 1) . ')';
        }
        $p['parsed_medicines'] = implode(', ', $meds_arr);
    }
    unset($p);

    // Parse direct sales medicines for cleaner frontend use
    foreach ($response['direct_sales'] as &$s) {
        $meds_arr = [];
        $s_meds = json_decode($s['medicines'] ?: '[]', true);
        foreach ($s_meds as $m) {
            $name = $m['name'] ?? '';
            $meds_arr[] = $name . ' (' . ($m['qty'] ?? 1) . ')';
        }
        $s['parsed_medicines'] = implode(', ', $meds_arr);
    }
    unset($s);

    // Daily Statistics
    $daily_stmt = $conn->query("SELECT DATE(created_at) as date, SUM(consultation_fee) as doc_fee, SUM(total_amount) as med_fee, SUM(scan_fee) as scan_fee, SUM(injection_cost) as inj_fee, SUM(iv_cost) as iv_fee, SUM(upt_cost) as upt_fee, SUM((total_amount + consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) * (IFNULL(discount_percent, 0) / 100.0)) as daily_discount FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY DATE(created_at)");
    $daily_presc = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_ds_stmt = $conn->query("SELECT DATE(created_at) as date, SUM(total_amount) as ds_fee FROM direct_sales WHERE $date_filter_sales GROUP BY DATE(created_at)");
    $daily_ds = $daily_ds_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_pur_stmt = $conn->query("SELECT purchase_date as date, SUM(grand_total) as expense FROM agency_purchases WHERE $date_filter_agency GROUP BY purchase_date");
    $daily_pur = $daily_pur_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_stats = [];
    foreach ($daily_presc as $r) {
        $d = $r['date'];
        if (!$d) continue;
        $discount = (float)($r['daily_discount'] ?? 0);
        $inj = (float)($r['inj_fee'] ?? 0);
        $iv = (float)($r['iv_fee'] ?? 0);
        $upt = (float)($r['upt_fee'] ?? 0);
        $presc_rev = (float)$r['doc_fee'] + (float)$r['med_fee'] + (float)$r['scan_fee'] + $inj + $iv + $upt - $discount;
        $daily_stats[$d] = [
            'date' => $d,
            'doc_fee' => (float)$r['doc_fee'],
            'med_fee' => (float)$r['med_fee'] + $inj + $iv + $upt,
            'scan_fee' => (float)$r['scan_fee'],
            'ds_fee' => 0.0,
            'revenue' => $presc_rev,
            'expense' => 0.0,
            'profit' => $presc_rev,
            'total' => $presc_rev
        ];
    }
    foreach ($daily_ds as $r) {
        $d = $r['date'];
        if (!$d) continue;
        if (!isset($daily_stats[$d])) {
            $daily_stats[$d] = [
                'date' => $d,
                'doc_fee' => 0.0,
                'med_fee' => 0.0,
                'scan_fee' => 0.0,
                'ds_fee' => (float)$r['ds_fee'],
                'revenue' => (float)$r['ds_fee'],
                'expense' => 0.0,
                'profit' => (float)$r['ds_fee'],
                'total' => (float)$r['ds_fee']
            ];
        } else {
            $daily_stats[$d]['ds_fee'] = (float)$r['ds_fee'];
            $daily_stats[$d]['revenue'] += (float)$r['ds_fee'];
            $daily_stats[$d]['profit'] += (float)$r['ds_fee'];
            $daily_stats[$d]['total'] += (float)$r['ds_fee'];
        }
    }
    foreach ($daily_pur as $r) {
        $d = $r['date'];
        if (!$d) continue;
        if (!isset($daily_stats[$d])) {
            $daily_stats[$d] = [
                'date' => $d,
                'doc_fee' => 0.0,
                'med_fee' => 0.0,
                'scan_fee' => 0.0,
                'ds_fee' => 0.0,
                'revenue' => 0.0,
                'expense' => (float)$r['expense'],
                'profit' => -(float)$r['expense'],
                'total' => 0.0
            ];
        } else {
            $daily_stats[$d]['expense'] = (float)$r['expense'];
            $daily_stats[$d]['profit'] -= (float)$r['expense'];
        }
    }
    krsort($daily_stats);
    $response['daily_stats'] = array_values($daily_stats);

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

if ($action === 'export_csv') {
    $period = $_GET['period'] ?? 'today';
    $start_date = $_GET['start'] ?? '';
    $end_date = $_GET['end'] ?? '';
    
    $date_filter_patients = "1=1";
    $date_filter_sales = "1=1";
    $date_filter_agency = "1=1";
    
    if ($period === 'today') {
        $date_filter_patients = "DATE(created_at) = CURDATE()";
        $date_filter_sales = "DATE(created_at) = CURDATE()";
        $date_filter_agency = "purchase_date = CURDATE()";
    } elseif ($period === 'yesterday') {
        $date_filter_patients = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $date_filter_sales = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $date_filter_agency = "purchase_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($period === 'weekly') {
        $date_filter_patients = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_filter_sales = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_filter_agency = "purchase_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'monthly') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    } elseif ($period === 'last_month') {
        $date_filter_patients = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
        $date_filter_sales = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
        $date_filter_agency = "DATE_FORMAT(purchase_date, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
    } elseif ($period === 'yearly') {
        $date_filter_patients = "YEAR(created_at) = YEAR(CURDATE())";
        $date_filter_sales = "YEAR(created_at) = YEAR(CURDATE())";
        $date_filter_agency = "YEAR(purchase_date) = YEAR(CURDATE())";
    } elseif ($period === 'custom' && $start_date && $end_date) {
        $date_filter_patients = "DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
        $date_filter_sales = "DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
        $date_filter_agency = "purchase_date BETWEEN '$start_date' AND '$end_date'";
    }

    $filename = "Hospital_Full_Report_" . date('Y-m-d_H-i-s') . ".csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $out = fopen('php://output', 'w');
    
    // PATIENTS
    fputcsv($out, ['--- PATIENTS REPORT ---']);
    fputcsv($out, ['Patient Name', 'Phone', 'Doctor', 'Visit Date', 'Consultation Fee', 'Medicine Bill', 'Total Bill', 'Status']);
    
    $date_filter_patients_join = str_replace("created_at", "p.created_at", $date_filter_patients);
    $stmt = $conn->query("SELECT p.name, p.phone, p.doctor_name, DATE(p.created_at), pr.consultation_fee, pr.total_amount, (pr.consultation_fee + pr.total_amount), pr.status 
        FROM patients p LEFT JOIN prescriptions pr ON p.id = pr.patient_id 
        WHERE $date_filter_patients_join ORDER BY p.id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($out, $row); }
    fputcsv($out, []);
    
    // DIRECT SALES
    fputcsv($out, ['--- DIRECT MEDICINE SALES ---']);
    fputcsv($out, ['Customer Name', 'Phone', 'Total Amount', 'Paid Amount', 'Pending Amount', 'Date']);
    $stmt = $conn->query("SELECT customer_name, mobile_number, total_amount, paid_amount, balance_amount, DATE(created_at) FROM direct_sales WHERE $date_filter_sales ORDER BY id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($out, $row); }
    fputcsv($out, []);
    
    // INVENTORY
    fputcsv($out, ['--- CURRENT INVENTORY ---']);
    fputcsv($out, ['Item Code', 'Name', 'Category', 'Batch', 'Stock', 'MRP', 'Purchase Price', 'Selling Price']);
    $stmt = $conn->query("SELECT item_code, name, category, batch_number, stock, mrp, purchase_price, selling_price FROM inventory ORDER BY name ASC");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($out, $row); }
    fputcsv($out, []);

    // AGENCY PURCHASES
    fputcsv($out, ['--- AGENCY PURCHASES ---']);
    fputcsv($out, ['Invoice', 'Supplier ID', 'Purchase Date', 'Payment Mode', 'Sub Total', 'Discount', 'GST Total', 'Grand Total', 'Status']);
    $stmt = $conn->query("SELECT invoice_number, supplier_id, purchase_date, payment_mode, sub_total, discount_total, gst_total, grand_total, payment_status FROM agency_purchases WHERE $date_filter_agency ORDER BY id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($out, $row); }
    
    fclose($out);
    exit;
}

if ($action === 'backup') {
    // Generate backup metadata
    // Turso/libSQL handles cloud backups natively via their platform.
    // We return a metadata payload for the dashboard to consume.
    $conn = get_db();
    $stmt = $conn->query("SELECT COUNT(*) as cnt FROM patients");
    $p_cnt = $stmt->fetch()['cnt'];
    $stmt = $conn->query("SELECT COUNT(*) as cnt FROM prescriptions");
    $pr_cnt = $stmt->fetch()['cnt'];

    $metadata = [
        'success' => true,
        'message' => 'Database is hosted on Turso. Backups are managed automatically in the cloud.',
        'timestamp' => date('Y-m-d H:i:s'),
        'provider' => 'Turso/libSQL',
        'stats' => [
            'total_patients' => $p_cnt,
            'total_prescriptions' => $pr_cnt
        ],
        'instruction' => 'Use the Turso CLI (turso db dump hospital-db) or Vercel dashboard to export raw data if needed.'
    ];

    header('Content-Type: application/json');
    echo json_encode($metadata);
    exit;
}
