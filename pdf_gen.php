<?php
/**
 * PDF Generation logic using FPDF (PHP Version)
 * Replicates the ReportLab layout from app.py
 */

require_once __DIR__ . '/fpdf_lib/fpdf186/fpdf.php';
require_once __DIR__ . '/db.php';

class PrescriptionPDF extends FPDF {
    protected $patientData;

    function setData($data) {
        $this->patientData = $data;
    }

    function Header() {
        // Dark Header Background
        $this->SetFillColor(15, 23, 42); // #0f172a
        $this->Rect(0, 0, 210, 40, 'F');
        
        // Blue border line
        $this->SetFillColor(56, 189, 248); // #38bdf8
        $this->Rect(0, 40, 210, 1.5, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 22);
        $this->Cell(0, 15, 'Crescent Clinic and Scans', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 5, 'Medical Prescription & Bill', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 8, 'Date: ' . date('d-m-Y h:i A'), 0, 1, 'C');
        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(148, 163, 184); // #94a3b8
        $this->Cell(0, 10, 'This is a computer-generated medical prescription bill. No signature required.', 0, 0, 'C');
    }

    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(15, 23, 42); // #0f172a
        $this->Cell(0, 10, $title, 0, 1);
        $this->SetDrawColor(56, 189, 248);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX(), $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }

    function InfoRow($label, $value) {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(100, 116, 139); // #64748b
        $this->Cell(45, 7, $label . ':', 0, 0);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 7, $value, 0, 1);
    }

    function VitalRow($vitals) {
        $this->SetFont('Arial', '', 10);
        $x = $this->GetX();
        foreach ($vitals as $label => $val) {
            $this->SetTextColor(100, 116, 139);
            $this->Write(7, $label . ': ');
            $this->SetTextColor(15, 23, 42);
            $this->Write(7, $val . '    ');
        }
        $this->Ln(10);
    }

    function PaymentRow($label, $value, $isBold = false, $color = [15, 23, 42]) {
        $this->SetFont('Arial', $isBold ? 'B' : '', 11);
        $this->SetTextColor($color[0], $color[1], $color[2]);
        $this->Cell(100, 7, $label, 0, 0);
        $valStr = is_numeric($value) ? 'Rs. ' . number_format($value, 2) : $value;
        $this->Cell(0, 7, $valStr, 0, 1, 'R');
    }
}

function generate_prescription_pdf($presc_id) {
    $conn = get_db();
    $stmt = $conn->prepare("SELECT p.*, pr.diagnosis, pr.prescription_text, pr.medicines,
               pr.total_amount, pr.consultation_fee, pr.scan_fee, pr.doctor_name as presc_doctor,
               pr.cash_amount, pr.gpay_amount, pr.paid_amount, pr.balance_amount,
               pr.injection_details, pr.iv_details,
               pr.injection_cost, pr.iv_cost, pr.upt_cost
        FROM prescriptions pr
        JOIN patients p ON pr.patient_id = p.id
        WHERE pr.id = ?");
    $stmt->execute([$presc_id]);
    $rec = $stmt->fetch();

    if (!$rec) return null;

    $pdf = new PrescriptionPDF();
    $pdf->setData($rec);
    $pdf->AddPage();

    // Patient Info
    $pdf->SectionTitle('Patient Information');
    $pdf->InfoRow('Patient Name', $rec['name']);
    $pdf->InfoRow('Phone', $rec['phone']);
    $pdf->InfoRow('Age / Gender', $rec['age'] . ' / ' . $rec['gender']);
    $pdf->InfoRow('Token', $rec['token']);
    $pdf->InfoRow('Doctor', $rec['presc_doctor']);
    $pdf->InfoRow('Patient In', $rec['created_at'] ? date('d-m-Y h:i A', strtotime($rec['created_at'])) : '-');
    $pdf->InfoRow('Patient Out', $rec['completed_at'] ? date('d-m-Y h:i A', strtotime($rec['completed_at'])) : '-');
    $pdf->Ln(5);

    // Vitals
    $pdf->SectionTitle('Vitals');
    $vitals = [
        'BP' => $rec['bp'] ?: '-',
        'Temp' => $rec['temp'] ?: '-',
        'Pulse' => $rec['pulse'] ?: '-',
        'Weight' => $rec['weight'] ?: '-',
        'Height' => $rec['height'] ?: '-'
    ];
    $pdf->VitalRow($vitals);

    // Diagnosis
    $pdf->SectionTitle('Diagnosis & Prescription');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(51, 65, 85); // #334155
    $pdf->MultiCell(0, 6, $rec['diagnosis'] ?: '-');
    $pdf->Ln(2);
    if ($rec['prescription_text']) {
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->MultiCell(0, 6, $rec['prescription_text']);
        $pdf->Ln(2);
    }
    if ($rec['injection_details']) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Write(6, 'Injection: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Write(6, $rec['injection_details']);
        $pdf->Ln(8);
    }
    if ($rec['iv_details']) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Write(6, 'IV Fluid: ');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Write(6, $rec['iv_details']);
        $pdf->Ln(8);
    }

    // Medicines Table
    $medicines = json_decode($rec['medicines'], true) ?: [];
    if ($medicines) {
        $pdf->SectionTitle('Medicines');
        $pdf->SetFillColor(15, 23, 42);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(10, 10, '#', 1, 0, 'C', true);
        $pdf->Cell(90, 10, 'Medicine Name', 1, 0, 'L', true);
        $pdf->Cell(20, 10, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'Price', 1, 0, 'C', true);
        $pdf->Cell(40, 10, 'Amount', 1, 1, 'C', true);

        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('Arial', '', 9);
        foreach ($medicines as $i => $m) {
            $pdf->Cell(10, 8, $i + 1, 1, 0, 'C');
            $pdf->Cell(90, 8, $m['name'], 1, 0, 'L');
            $pdf->Cell(20, 8, $m['qty'], 1, 0, 'C');
            $pdf->Cell(30, 8, 'Rs. ' . number_format($m['amount'] / $m['qty'], 2), 1, 0, 'C');
            $pdf->Cell(40, 8, 'Rs. ' . number_format($m['amount'], 2), 1, 1, 'C');
        }
        $pdf->Ln(10);
    }

    // Payment Summary
    if ($pdf->GetY() > 200) $pdf->AddPage();
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'PAYMENT SUMMARY', 0, 1);
    $pdf->Ln(2);

    $medicine_total = (float)$rec['total_amount'];
    $consultation_fee = (float)$rec['consultation_fee'];
    $scan_fee = (float)$rec['scan_fee'];
    $injection_cost = (float)$rec['injection_cost'];
    $iv_cost = (float)$rec['iv_cost'];
    $upt_cost = (float)$rec['upt_cost'];
    $grand_total = $medicine_total + $consultation_fee + $scan_fee + $injection_cost + $iv_cost + $upt_cost;

    $pdf->PaymentRow('Doctor Fee:', $consultation_fee);
    $pdf->PaymentRow('Medicine Total:', $medicine_total);
    if ($scan_fee > 0) $pdf->PaymentRow('Scan Fee:', $scan_fee);
    if ($injection_cost > 0) $pdf->PaymentRow('Injection Fee:', $injection_cost);
    if ($iv_cost > 0) $pdf->PaymentRow('IV Fee:', $iv_cost);
    if ($upt_cost > 0) $pdf->PaymentRow('UPT Card Fee:', $upt_cost);
    
    $pdf->Ln(2);
    $pdf->Line(60, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->PaymentRow('Grand Total:', $grand_total, true);
    $pdf->Ln(2);
    $pdf->Line(60, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(5);

    $pdf->PaymentRow('Paid via Cash:', (float)$rec['cash_amount']);
    $pdf->PaymentRow('Paid via GPay:', (float)$rec['gpay_amount']);
    $pdf->Ln(2);
    $pdf->PaymentRow('Total Paid:', (float)$rec['paid_amount'], true);
    $pdf->Ln(5);

    $balance = (float)$rec['balance_amount'];
    if ($balance > 0) {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor(220, 38, 38); // Red
        $pdf->Cell(0, 10, 'Balance Due: Rs. ' . number_format($balance, 2), 0, 1);
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 7, 'Pending Amount: Rs. ' . number_format($balance, 2) . ' to be paid later', 0, 1);
    } else {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor(22, 163, 74); // Green
        $pdf->Cell(0, 10, 'Balance: Paid in Full', 0, 1);
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 7, 'Payment Completed', 0, 1);
    }

    return $pdf->Output('S'); // Return as string
}
