<?php
/**
 * Master Report PDF Generation using FPDF
 */

require_once __DIR__ . '/fpdf_lib/fpdf186/fpdf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supabase_storage.php';

class MasterReportPDF extends FPDF {
    protected $reportDate;

    function setDate($date) {
        $this->reportDate = $date;
    }

    function Header() {
        // Dark Blue Header Background
        $this->SetFillColor(15, 23, 42); // #0f172a
        $this->Rect(0, 0, 210, 35, 'F');
        
        // Sky Blue border line
        $this->SetFillColor(56, 189, 248); // #38bdf8
        $this->Rect(0, 35, 210, 1.5, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(0, 10, 'Crescent Clinic & Scans', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Master Daily Report (' . date('d-m-Y', strtotime($this->reportDate)) . ')', 0, 1, 'C');
        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(148, 163, 184); // #94a3b8
        $this->Cell(0, 10, 'Generated automatically by Crescent System Scheduler. Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(15, 23, 42); // #0f172a
        $this->Cell(0, 10, $title, 0, 1);
        $this->SetDrawColor(56, 189, 248);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX(), $this->GetY(), 200, $this->GetY());
        $this->Ln(4);
    }
}

function generate_master_report_pdf($date) {
    $conn = get_db();
    
    // 1. Data Retrieval (Same logic as reports_api.php for 'today')
    $date_filter_patients = "DATE(created_at) = '$date'";
    $date_filter_sales = "DATE(created_at) = '$date'";
    $date_filter_agency = "purchase_date = '$date'";
    $date_filter_returns = "return_date = '$date'";

    // Patients Count
    $stmt = $conn->query("SELECT COUNT(*) FROM patients WHERE $date_filter_patients");
    $total_patients = $stmt->fetchColumn() ?: 0;

    // Prescriptions Stats
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

    // Medicines Decoder
    $stmt = $conn->query("SELECT id, medicines FROM prescriptions WHERE status='dispensed' AND $date_filter_patients");
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

    $total_revenue = $doctor_revenue + $medicine_revenue_presc + $scan_revenue + $direct_revenue;
    $total_discount = (float)($pr['total_discount'] ?? 0);
    $total_revenue -= $total_discount;

    // Agency Purchases (Expenses)
    $stmt = $conn->query("SELECT SUM(grand_total) as total_purchases FROM agency_purchases WHERE $date_filter_agency");
    $total_expenses = (float)$stmt->fetchColumn() ?: 0;

    // Returns Total
    $stmt = $conn->query("SELECT SUM(return_amount) FROM medicine_returns WHERE $date_filter_returns");
    $total_returned_amount = (float)$stmt->fetchColumn() ?: 0;

    // Net Profit (Analytics formula)
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

    // Doctor Splits
    $stmt_doc = $conn->query("SELECT doctor_type, SUM(consultation_fee) as doc_rev FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY doctor_type");
    $gents_rev = 0; $ladies_rev = 0;
    foreach ($stmt_doc->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $type = strtolower($d['doctor_type'] ?? '');
        if (strpos($type, 'gent') !== false) $gents_rev += (float)$d['doc_rev'];
        else $ladies_rev += (float)$d['doc_rev'];
    }

    // Doctors table list
    $stmt = $conn->query("SELECT doctor_name, doctor_type, COUNT(*) as p_count, SUM(consultation_fee) as doc_rev, SUM(total_amount) as med_rev FROM prescriptions WHERE status='dispensed' AND $date_filter_patients GROUP BY doctor_name, doctor_type");
    $doctors_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Medicines sorted
    uasort($medicine_stats, function($a, $b) { return $b['qty'] <=> $a['qty']; });
    $top_meds = []; $i = 0;
    foreach ($medicine_stats as $name => $stats) {
        if ($i++ >= 10) break;
        $top_meds[] = ['name' => $name, 'qty' => $stats['qty'], 'revenue' => $stats['revenue']];
    }

    // 2. PDF Rendering
    $pdf = new MasterReportPDF();
    $pdf->AliasNbPages();
    $pdf->setDate($date);
    $pdf->AddPage();

    // Section 1: Executive Summary
    $pdf->SectionTitle('1. Executive Summary');
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(95, 8, ' Metric', 1, 0, 'L', true);
    $pdf->Cell(95, 8, ' Value', 1, 1, 'L', true);

    $pdf->SetFont('Arial', '', 10);
    $summary_rows = [
        'Total Revenue' => 'Rs. ' . number_format($total_revenue, 2),
        'Total Expenses (Supplier Purchases)' => 'Rs. ' . number_format($total_expenses, 2),
        'Net Profit (Revenue - Costs)' => 'Rs. ' . number_format($net_profit, 2),
        'Gents Doctor Fee' => 'Rs. ' . number_format($gents_rev, 2),
        'Ladies Doctor Fee' => 'Rs. ' . number_format($ladies_rev, 2),
        'Pending Collections' => 'Rs. ' . number_format($pending_amount, 2),
        'Cleared Pending' => 'Rs. ' . number_format($cleared_pending, 2),
        'Total Patients' => $total_patients,
        'Direct Med Sales Transactions' => $ds['total_sales'] ?? 0,
        'Total Returns' => 'Rs. ' . number_format($total_returned_amount, 2),
    ];

    foreach ($summary_rows as $metric => $val) {
        $pdf->Cell(95, 7, ' ' . $metric, 1, 0, 'L');
        $pdf->Cell(95, 7, ' ' . $val, 1, 1, 'L');
    }
    $pdf->Ln(6);

    // Section 2: Financials & Payment Modes
    $pdf->SectionTitle('2. Financials & Payment Modes');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(95, 8, ' Payment Mode', 1, 0, 'L', true);
    $pdf->Cell(95, 8, ' Amount Collected', 1, 1, 'L', true);

    $pdf->SetFont('Arial', '', 10);
    $upi_total = $gpay_collection + $phonepe_collection + $bank_collection;
    $financial_rows = [
        'Cash Total' => $cash_collection,
        'UPI Total' => $upi_total,
        'Pending Fees' => $pending_amount,
        'Cleared Pending Fees' => $cleared_pending,
    ];

    foreach ($financial_rows as $mode => $amt) {
        $pdf->Cell(95, 7, ' ' . $mode, 1, 0, 'L');
        $pdf->Cell(95, 7, ' Rs. ' . number_format($amt, 2), 1, 1, 'L');
    }
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(95, 7, ' Total Collected', 1, 0, 'L', true);
    $pdf->Cell(95, 7, ' Rs. ' . number_format($collection_amount, 2), 1, 1, 'L', true);
    $pdf->Ln(6);

    // Section 3: Doctor Revenue Summary
    if ($pdf->GetY() > 220) $pdf->AddPage();
    $pdf->SectionTitle('3. Doctor Revenue Summary');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(60, 8, ' Doctor Name', 1, 0, 'L', true);
    $pdf->Cell(30, 8, ' Type', 1, 0, 'C', true);
    $pdf->Cell(25, 8, ' Consults', 1, 0, 'C', true);
    $pdf->Cell(35, 8, ' Doctor Rev', 1, 0, 'R', true);
    $pdf->Cell(40, 8, ' Total Rev', 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 9);
    if ($doctors_list) {
        foreach ($doctors_list as $d) {
            $pdf->Cell(60, 7, ' ' . $d['doctor_name'], 1, 0, 'L');
            $pdf->Cell(30, 7, $d['doctor_type'], 1, 0, 'C');
            $pdf->Cell(25, 7, $d['p_count'], 1, 0, 'C');
            $pdf->Cell(35, 7, 'Rs. ' . number_format($d['doc_rev'] ?? 0, 2) . ' ', 1, 0, 'R');
            $total_doc_rev = ($d['doc_rev'] ?? 0) + ($d['med_rev'] ?? 0);
            $pdf->Cell(40, 7, 'Rs. ' . number_format($total_doc_rev, 2) . ' ', 1, 1, 'R');
        }
    } else {
        $pdf->Cell(190, 8, 'No doctor revenue records today', 1, 1, 'C');
    }
    $pdf->Ln(6);

    // Section 4: Top Selling Medicines
    if ($pdf->GetY() > 220) $pdf->AddPage();
    $pdf->SectionTitle('4. Top Selling Medicines');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(10, 8, ' #', 1, 0, 'C', true);
    $pdf->Cell(100, 8, ' Medicine Name', 1, 0, 'L', true);
    $pdf->Cell(35, 8, ' Quantity Sold', 1, 0, 'C', true);
    $pdf->Cell(45, 8, ' Total Revenue', 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 9);
    if ($top_meds) {
        foreach ($top_meds as $idx => $m) {
            $pdf->Cell(10, 7, ' ' . ($idx + 1), 1, 0, 'C');
            $pdf->Cell(100, 7, ' ' . $m['name'], 1, 0, 'L');
            $pdf->Cell(35, 7, $m['qty'], 1, 0, 'C');
            $pdf->Cell(45, 7, 'Rs. ' . number_format($m['revenue'], 2) . ' ', 1, 1, 'R');
        }
    } else {
        $pdf->Cell(190, 8, 'No medicine sales recorded today', 1, 1, 'C');
    }

    $filename = "Master_Report_" . $date . ".pdf";
    $pdf_buffer = $pdf->Output('S');
    upload_buffer_to_supabase($pdf_buffer, 'backups', $filename, 'application/pdf');
    return $pdf_buffer;
}
