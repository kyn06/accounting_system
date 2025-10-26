<?php
session_start();
require_once "db.php"; // Database connection

// --- Security Check: Ensure user is logged in ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$is_admin = ($user_role === 'admin');

// *** HELPER FUNCTIONS (Defined Globally) ***
// *** ENSURE THIS FUNCTION IS DEFINED HERE ***
function fetch_val($conn, $sql) {
    // Uses mysqli_query for compatibility with original dashboard logic
    $r = mysqli_query($conn, $sql);
    if (!$r || mysqli_num_rows($r) == 0) return 0.0;
    $row = mysqli_fetch_assoc($r); // Fetch as associative array
    // Check if the specific 'val' column exists and is numeric
    return isset($row['val']) && is_numeric($row['val']) ? (float)$row['val'] : 0.0;
}
// --- Helper function to get data for PDF reports ---
function fetch_report_data($conn, $sql) {
    $result = $conn->query($sql); $data = [];
    if ($result && $result->num_rows > 0) { while ($row = $result->fetch_assoc()) { $data[] = $row; } }
    return $data;
}
// --- Helper function to get single value for PDF reports ---
function fetch_report_value($conn, $sql, $default = 0.0) {
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_array()) { // Can fetch numerically
        return is_numeric($row[0]) ? (float)$row[0] : $default;
    }
    return $default;
}
// --- Function to fetch grouped data for charts ---
function fetch_grouped_data($conn, $sql) {
    $result = $conn->query($sql); $labels = []; $values = [];
    if ($result && $result->num_rows > 0) { while ($row = $result->fetch_assoc()) { $labels[] = $row['label']; $values[] = (float)$row['value']; } }
    return ['labels' => $labels, 'values' => $values];
}
// *** END HELPER FUNCTIONS ***


// --- FPDF PDF Generation Logic (Triggered by GET parameter) ---
if (isset($_GET['generate_pdf']) && $is_admin) {

    // --- Check if FPDF library exists ---
    $fpdf_path = __DIR__ . '/fpdf/fpdf.php'; // Path relative to dashboard.php
    if (!file_exists($fpdf_path)) {
        $_SESSION['report_error'] = "FPDF library not found at: " . htmlspecialchars($fpdf_path);
        header("Location: dashboard.php");
        exit();
    }
    require($fpdf_path);

    // --- Get Report Parameters ---
    $report_type = $_GET['report_type'] ?? 'summary';
    $period_type = $_GET['period'] ?? 'monthly';
    $report_date = $_GET['report_date'] ?? date('Y-m-d');
    $report_week = $_GET['report_week'] ?? date('Y-\WW');
    $report_month = $_GET['report_month'] ?? date('Y-m');
    $report_year = $_GET['report_year'] ?? date('Y');

    // --- Determine Date Range ---
    $start_date_time = ''; $end_date_time = ''; $start_date_only = ''; $end_date_only = ''; $period_label = '';
    switch ($period_type) {
        case 'daily': $start_date_time = date('Y-m-d 00:00:00', strtotime($report_date)); $end_date_time = date('Y-m-d 23:59:59', strtotime($report_date)); $start_date_only = $report_date; $end_date_only = $report_date; $period_label = "Date: " . date('M d, Y', strtotime($report_date)); break;
        case 'weekly': $year = substr($report_week, 0, 4); $week = substr($report_week, 6, 2); $start_timestamp = strtotime($year . 'W' . $week . '1'); $end_timestamp = strtotime($year . 'W' . $week . '7'); $start_date_time = date('Y-m-d 00:00:00', $start_timestamp); $end_date_time = date('Y-m-d 23:59:59', $end_timestamp); $start_date_only = date('Y-m-d', $start_timestamp); $end_date_only = date('Y-m-d', $end_timestamp); $period_label = "Week: " . date('M d', $start_timestamp) . " - " . date('M d, Y', $end_timestamp); break;
        case 'monthly': $start_date_time = date('Y-m-01 00:00:00', strtotime($report_month . '-01')); $end_date_time = date('Y-m-t 23:59:59', strtotime($report_month . '-01')); $start_date_only = date('Y-m-01', strtotime($report_month . '-01')); $end_date_only = date('Y-m-t', strtotime($report_month . '-01')); $period_label = "Month: " . date('F Y', strtotime($report_month . '-01')); break;
        case 'yearly': $start_date_time = date('Y-01-01 00:00:00', strtotime($report_year . '-01-01')); $end_date_time = date('Y-12-31 23:59:59', strtotime($report_year . '-01-01')); $start_date_only = date('Y-01-01', strtotime($report_year . '-01-01')); $end_date_only = date('Y-12-31', strtotime($report_year . '-01-01')); $period_label = "Year: " . $report_year; break;
        default: die("Invalid period type.");
    }
    $date_condition_datetime = "transaction_datetime BETWEEN '$start_date_time' AND '$end_date_time'";
    $date_condition_date = "date_of_payment BETWEEN '$start_date_only' AND '$end_date_only'";
    $created_condition_datetime = "created_at BETWEEN '$start_date_time' AND '$end_date_time'";


    // --- Custom FPDF Class ---
    class PDF extends FPDF {
        private $reportTitle = 'Report'; private $periodLabel = ''; private $generatedBy = '';
        private $colorAccent = [216, 76, 115]; private $colorLightPink = [255, 240, 246]; private $colorMuted = [107, 74, 87]; private $colorDark = [61, 26, 42]; private $colorBorder = [243, 208, 220];
        function setReportHeader($title, $period, $user) { $this->reportTitle = $title; $this->periodLabel = $period; $this->generatedBy = $user; }
        function Header() { $this->SetFillColor($this->colorAccent[0], $this->colorAccent[1], $this->colorAccent[2]); $this->Rect(0, 0, $this->GetPageWidth(), 30, 'F'); $this->SetTextColor(255, 255, 255); $this->SetFont('Arial', 'B', 15); $this->SetY(8); $this->Cell(0, 10, 'RCRAO Accounting System Report', 0, 1, 'C'); $this->SetFont('Arial', 'B', 12); $this->Cell(0, 8, $this->reportTitle . ' (' . $this->periodLabel . ')', 0, 1, 'C'); $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]); $this->SetY(35); }
        function Footer() { $this->SetY(-15); $this->SetFont('Arial', 'I', 8); $this->SetTextColor(150); $this->Cell(0, 5, 'Generated By: ' . $this->generatedBy . ' on ' . date('Y-m-d H:i'), 0, 1, 'L'); $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C'); }
        function BasicTable($header, $data) { $this->SetFillColor($this->colorAccent[0], $this->colorAccent[1], $this->colorAccent[2]); $this->SetTextColor(255); $this->SetDrawColor($this->colorAccent[0]-20 > 0 ? $this->colorAccent[0]-20 : 0, $this->colorAccent[1]-20 > 0 ? $this->colorAccent[1]-20 : 0, $this->colorAccent[2]-20 > 0 ? $this->colorAccent[2]-20 : 0); $this->SetLineWidth(.3); $this->SetFont('', 'B', 10); $widths = $this->CalculateWidths($header, $data); for ($i = 0; $i < count($header); $i++) $this->Cell($widths[$i], 8, $header[$i], 1, 0, 'C', true); $this->Ln(); $this->SetFont('Arial', '', 9); $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]); $this->SetFillColor(255); $this->SetDrawColor($this->colorBorder[0], $this->colorBorder[1], $this->colorBorder[2]); $fill = false; foreach ($data as $row) { $this->SetFillColor($fill ? 245 : 255); for ($i = 0; $i < count($header); $i++) { $cellValue = $row[$i] ?? ''; $cleanValue = str_replace(['₱', ',', ' '], '', $cellValue); $align = (is_numeric($cleanValue)) ? 'R' : 'L'; $this->Cell($widths[$i], 7, $cellValue, 'LR', 0, $align, true); } $this->Ln(); $fill = !$fill; } $this->Cell(array_sum($widths), 0, '', 'T'); $this->Ln(5); }
        function CalculateWidths($header, $data) { $num_cols = count($header); $pageWidth = $this->GetPageWidth() - 20; $widths = []; for ($i = 0; $i < $num_cols; $i++) { $widths[$i] = $this->GetStringWidth($header[$i]) + 8; } $sampleData = array_slice($data, 0, 20); foreach ($sampleData as $row) { for ($i = 0; $i < $num_cols; $i++) { $cellValue = $row[$i] ?? ''; $widths[$i] = max($widths[$i], $this->GetStringWidth((string)$cellValue) + 8); } } $totalWidth = array_sum($widths); if ($totalWidth <= 0) { return array_fill(0, $num_cols, $pageWidth / $num_cols); } $scaleFactor = $pageWidth / $totalWidth; for ($i = 0; $i < $num_cols; $i++) { $widths[$i] *= $scaleFactor; } return $widths; }
        function SummarySection($title, $items) { $this->SetFont('Arial', 'B', 12); $this->SetTextColor($this->colorAccent[0], $this->colorAccent[1], $this->colorAccent[2]); $this->Cell(0, 10, $title, 0, 1, 'L'); $this->SetFont('Arial', '', 10); $this->SetTextColor($this->colorDark[0], $this->colorDark[1], $this->colorDark[2]); $this->SetFillColor(255); $this->SetDrawColor($this->colorBorder[0], $this->colorBorder[1], $this->colorBorder[2]); $this->SetLineWidth(.2); $labelWidth = 80; $valueWidth = 40; $fill = false; foreach ($items as $label => $value) { $isTotal = (strpos(strtoupper($label), 'TOTAL') !== false || strpos(strtoupper($label), 'NET') !== false); $this->SetFont('Arial', $isTotal ? 'B' : '', 10); $this->SetFillColor($fill ? $this->colorLightPink[0] : 255, $fill ? $this->colorLightPink[1] : 255, $fill ? $this->colorLightPink[2] : 255); $this->Cell($labelWidth, 8, $label, 'LR', 0, 'L', true); $this->Cell($valueWidth, 8, '₱ ' . number_format($value, 2), 'LR', 1, 'R', true); $fill = !$fill; } $this->SetFont('Arial', '', 10); $this->Cell($labelWidth + $valueWidth, 0, '', 'T'); $this->Ln(8); }
    }

    // --- Create PDF Instance ---
    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // --- Generate Content Based on Report Type ---
    $filename_suffix = strtolower($report_type) . '_' . strtolower($period_type) . '_' . date('Ymd');
    $main_title = '';
     if ($report_type === 'collections') {
        $main_title = 'Collections Report';
        $sql = "SELECT client_name, affiliation, reference_number, amount, cash_received, (amount - IFNULL(cash_received,0)) as unpaid, mode_of_payment, person_in_charge, DATE(created_at) as date
                FROM collections WHERE $date_condition_datetime ORDER BY created_at ASC";
        $data = fetch_report_data($conn, $sql); $total_amount = 0; $total_received = 0; $table_data = [];
        foreach ($data as $row) { $table_data[] = [ $row['date'], $row['client_name'], $row['reference_number'], '₱ '.number_format($row['amount'], 2), '₱ '.number_format($row['cash_received'], 2), '₱ '.number_format($row['unpaid'], 2), $row['mode_of_payment'], $row['person_in_charge'], ]; $total_amount += $row['amount']; $total_received += $row['cash_received']; }
        $pdf->setReportHeader($main_title, $period_label, $current_username);
        if (!empty($table_data)) { $header = ['Date', 'Client', 'Ref #', 'Amount', 'Received', 'Unpaid', 'Mode', 'In-Charge']; $pdf->BasicTable($header, $table_data); $pdf->Ln(5); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 7, 'Total Amount: ₱ '.number_format($total_amount, 2), 0, 1, 'R'); $pdf->Cell(0, 7, 'Total Cash Received: ₱ '.number_format($total_received, 2), 0, 1, 'R'); } else { $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No collection data found.', 0, 1, 'C'); }
        $filename_suffix = 'collections_' . $filename_suffix;
     }
     elseif ($report_type === 'expenses') {
        $main_title = 'Expenses Report';
        $sql = "SELECT expense, store_or_merchant, amount, person_in_charge, DATE(created_at) as date FROM expenses WHERE $date_condition_datetime ORDER BY created_at ASC";
        $data = fetch_report_data($conn, $sql); $total_expenses = 0; $table_data = [];
        foreach ($data as $row) { $table_data[] = [ $row['date'], $row['expense'], $row['store_or_merchant'], '₱ '.number_format($row['amount'], 2), $row['person_in_charge'], ]; $total_expenses += $row['amount']; }
        $pdf->setReportHeader($main_title, $period_label, $current_username);
        if (!empty($table_data)) { $header = ['Date', 'Expense', 'Store/Merchant', 'Amount', 'In-Charge']; $pdf->BasicTable($header, $table_data); $pdf->Ln(5); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 7, 'Total Expenses: ₱ '.number_format($total_expenses, 2), 0, 1, 'R'); } else { $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No expense data found.', 0, 1, 'C'); }
        $filename_suffix = 'expenses_' . $filename_suffix;
     }
     elseif ($report_type === 'receivables') {
         $main_title = 'Receivables Report';
         $sql_added = "SELECT client_name, affiliation, reference_number, amount, DATE(created_at) as date FROM receivables WHERE $created_condition_datetime ORDER BY created_at ASC";
         $data_added = fetch_report_data($conn, $sql_added); $total_added = 0;
         $pdf->setReportHeader($main_title, $period_label, $current_username);
         $pdf->SetFont('Arial', 'B', 11); $pdf->Cell(0, 8, 'Receivables Added During Period', 0, 1); $table_data_added = [];
         if (!empty($data_added)) { foreach ($data_added as $row) { $table_data_added[] = [ $row['date'], $row['client_name'], $row['reference_number'], '₱ '.number_format($row['amount'], 2), ]; $total_added += $row['amount']; } $header_added = ['Date Added', 'Client', 'Ref #', 'Amount']; $pdf->BasicTable($header_added, $table_data_added); $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 7, 'Total Added: ₱ '.number_format($total_added, 2), 0, 1, 'R'); } else { $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No new receivables added.', 0, 1); }
         $pdf->Ln(5); $pdf->SetFont('Arial', 'B', 11); $pdf->Cell(0, 8, 'Outstanding Receivables (As of ' . date('M d, Y', strtotime($end_date_time)) . ')', 0, 1);
         $sql_outstanding = "SELECT client_name, reference_number, amount, amount_paid, (amount - IFNULL(amount_paid, 0)) AS balance FROM receivables WHERE created_at <= '$end_date_time' AND is_paid = 0 ORDER BY client_name ASC";
         $data_outstanding = fetch_report_data($conn, $sql_outstanding); $total_outstanding = 0; $table_data_outstanding = [];
         if(!empty($data_outstanding)){ foreach($data_outstanding as $row){ $table_data_outstanding[] = [ $row['client_name'], $row['reference_number'], '₱ '.number_format($row['amount'], 2), '₱ '.number_format($row['amount_paid'], 2), '₱ '.number_format($row['balance'], 2), ]; $total_outstanding += $row['balance']; } $header_outstanding = ['Client', 'Ref #', 'Total Amount', 'Amount Paid', 'Outstanding Balance']; $pdf->BasicTable($header_outstanding, $table_data_outstanding); $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 7, 'Total Outstanding Balance: ₱ '.number_format($total_outstanding, 2), 0, 1, 'R'); } else { $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No outstanding receivables found.', 0, 1); }
         $filename_suffix = 'receivables_' . $filename_suffix;
     }
     elseif ($report_type === 'summary') {
        $main_title = 'Consolidated Financial Summary';
        $pdf->setReportHeader($main_title, $period_label, $current_username);
        $collections_cash = fetch_report_value($conn, "SELECT IFNULL(SUM(cash_received),0) FROM collections WHERE $date_condition_datetime");
        $receivable_payments_estimated = fetch_report_value($conn, "SELECT IFNULL(SUM(amount_paid),0) FROM receivables WHERE is_paid = 1 AND $date_condition_date");
        $total_inflow = $collections_cash + $receivable_payments_estimated;
        $total_expenses = fetch_report_value($conn, "SELECT IFNULL(SUM(amount),0) FROM expenses WHERE $date_condition_datetime");
        $total_outflow = $total_expenses;
        $receivables_added = fetch_report_value($conn, "SELECT IFNULL(SUM(amount),0) FROM receivables WHERE $created_condition_datetime");
        $net_cash_flow = $total_inflow - $total_outflow;
        $total_receivables_outstanding = fetch_report_value($conn, "SELECT IFNULL(SUM(amount - IFNULL(amount_paid, 0)), 0) FROM receivables WHERE created_at <= '$end_date_time' AND is_paid = 0");
        $pdf->SummarySection('Income / Inflows (Period)', [ 'Collections (Cash Received)' => $collections_cash, 'Receivable Payments (Estimated*)' => $receivable_payments_estimated, 'TOTAL INFLOWS' => $total_inflow, ]);
        $pdf->SummarySection('Expenses / Outflows (Period)', [ 'Total Expenses Paid' => $total_expenses, 'TOTAL OUTFLOWS' => $total_outflow, ]);
        $pdf->SummarySection('Receivables Activity (Period)', [ 'New Receivables Added' => $receivables_added, 'TOTAL OUTSTANDING (End of Period)' => $total_receivables_outstanding, ]);
        $pdf->SummarySection('Period Summary', [ 'Total Inflows' => $total_inflow, 'Total Outflows' => $total_outflow, 'NET CASH FLOW' => $net_cash_flow, ]);
        $pdf->Ln(5); $pdf->SetFont('Arial', 'I', 9); $pdf->MultiCell(0, 5, '*Receivable Payments estimated based on items marked paid within the period using `date_of_payment`.');
        $filename_suffix = 'summary_' . $filename_suffix;
     }
     else { $pdf->SetFont('Arial','', 10); $pdf->Cell(0, 10, 'Invalid Report Type Selected.', 0, 1, 'C'); }


    // --- Output PDF ---
    $filename = "RCRAO_Report_" . $filename_suffix . ".pdf";
    if (ob_get_level()) { ob_end_clean(); }
    $pdf->Output('D', $filename);
    exit();
}
// --- END FPDF Generation Logic ---


// --- Data Fetching for Dashboard Display ---
// === All-Time Totals ===
$total_income_collections = fetch_val($conn, "SELECT IFNULL(SUM(cash_received),0) AS val FROM collections");
$total_income_receivables = fetch_val($conn, "SELECT IFNULL(SUM(amount_paid),0) AS val FROM receivables WHERE is_paid = 1");
$total_income = $total_income_collections + $total_income_receivables;
$total_expenses = fetch_val($conn, "SELECT IFNULL(SUM(amount),0) AS val FROM expenses");
$total_receivables_outstanding = fetch_val($conn, "SELECT IFNULL(SUM(amount - IFNULL(amount_paid, 0)), 0) AS val FROM receivables WHERE is_paid = 0");
$all_time_net = $total_income - $total_expenses;
$unpaid_client_count = fetch_val($conn, "SELECT COUNT(DISTINCT client_name) AS val FROM receivables WHERE is_paid = 0");

// === Current Month Totals ===
$current_month_start = date('Y-m-01 00:00:00'); $current_month_end = date('Y-m-t 23:59:59');
$month_collections = fetch_val($conn, "SELECT IFNULL(SUM(cash_received),0) AS val FROM collections WHERE transaction_datetime BETWEEN '$current_month_start' AND '$current_month_end'");
$month_receivables_paid = fetch_val($conn, "SELECT IFNULL(SUM(amount_paid),0) AS val FROM receivables WHERE is_paid = 1 AND transaction_datetime BETWEEN '$current_month_start' AND '$current_month_end'");
$month_income = $month_collections + $month_receivables_paid;
$month_expenses = fetch_val($conn, "SELECT IFNULL(SUM(amount),0) AS val FROM expenses WHERE transaction_datetime BETWEEN '$current_month_start' AND '$current_month_end'");
$month_net = $month_income - $month_expenses;

// === Today's Totals ===
$today = date('Y-m-d'); $today_start = $today . ' 00:00:00'; $today_end = $today . ' 23:59:59';
$today_collections = fetch_val($conn, "SELECT IFNULL(SUM(cash_received),0) AS val FROM collections WHERE transaction_datetime BETWEEN '$today_start' AND '$today_end'");
$today_receivables_paid = fetch_val($conn, "SELECT IFNULL(SUM(amount_paid),0) AS val FROM receivables WHERE is_paid = 1 AND DATE(transaction_datetime) = '$today'");
$today_income = $today_collections + $today_receivables_paid; // Used only for KPI now
$today_expenses = fetch_val($conn, "SELECT IFNULL(SUM(amount),0) AS val FROM expenses WHERE transaction_datetime BETWEEN '$today_start' AND '$today_end'");
$today_receivables_added = fetch_val($conn, "SELECT IFNULL(SUM(amount),0) AS val FROM receivables WHERE created_at BETWEEN '$today_start' AND '$today_end'"); // Data for doughnut


// === Monthly Chart (6 Months) ===
$months = []; $monthly_collections = []; $monthly_expenses = []; $monthly_receivables_added = []; // Added receivables for bar chart
for ($i = 5; $i >= 0; $i--) {
    $label = date('M Y', strtotime("-$i month")); $months[] = $label;
    $start_dt = date('Y-m-01 00:00:00', strtotime("-$i month")); $end_dt = date('Y-m-t 23:59:59', strtotime("-$i month"));
    $v_cash = fetch_val($conn, "SELECT IFNULL(SUM(cash_received),0) AS val FROM collections WHERE transaction_datetime BETWEEN '$start_dt' AND '$end_dt'");
    $v_receivable_paid = fetch_val($conn, "SELECT IFNULL(SUM(amount_paid),0) AS val FROM receivables WHERE is_paid = 1 AND transaction_datetime BETWEEN '$start_dt' AND '$end_dt'");
    $monthly_collections[] = $v_cash + $v_receivable_paid; // Use combined income for line chart
    $monthly_expenses[] = fetch_val($conn, "SELECT IFNULL(SUM(amount),0) AS val FROM expenses WHERE transaction_datetime BETWEEN '$start_dt' AND '$end_dt'");
    $monthly_receivables_added[] = fetch_val($conn, "SELECT IFNULL(SUM(amount),0) AS val FROM receivables WHERE created_at BETWEEN '$start_dt' AND '$end_dt'"); // Fetch receivables added
}


// Get potential report error from session
$report_error = $_SESSION['report_error'] ?? null;
unset($_SESSION['report_error']);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RCRAO Accounting System — Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
  --accent:#d84c73; --accent-light:#ffb6c1; --accent-dark: #b83b5e;
  --bg1:#fff0f6; --bg2:#ffe6ee; --card:#ffffff;
  --text-dark:#3d1a2a; --text-muted:#6b4a57;
  --shadow:0 8px 25px rgba(216,76,115,0.08);
  --shadow-hover:0 12px 30px rgba(216,76,115,0.12);
  --success: #28a745; --danger: #dc3545; --info: #17a2b8; --warning: #ffc107;
  --purple: #6f42c1; /* Purple for expenses chart */
  --sidebar-collapsed:72px; --sidebar-expanded:230px;
}
*{box-sizing:border-box;font-family:"Poppins",sans-serif;margin:0;padding:0}
body{background:linear-gradient(135deg,var(--bg1),var(--bg2));color:var(--text-muted);overflow-x:hidden; font-size: 14px;}
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-collapsed);background:linear-gradient(180deg,var(--accent) 0%,#ff7ea1 100%); display:flex;flex-direction:column;justify-content:space-between;padding:12px;transition:width .3s ease;z-index:1001}
.sidebar:hover,.sidebar.expanded{width:var(--sidebar-expanded);box-shadow:var(--shadow-hover)}
nav.side-menu{margin-top:20px;display:flex;flex-direction:column;gap:8px}
nav.side-menu a{display:flex;align-items:center;gap:15px;padding:12px;color:#fff;text-decoration:none;border-radius:8px;font-weight:500;transition: all .2s ease;}
nav.side-menu a i{width:24px;text-align:center; font-size: 1.1em; transition: transform 0.2s ease;}
nav.side-menu a .label{display:none; white-space: nowrap; opacity: 0; transition: opacity 0.2s ease;}
.sidebar:hover nav.side-menu a .label,.sidebar.expanded nav.side-menu a .label{display:inline; opacity: 1;}
nav.side-menu a:hover{background:rgba(255,255,255,0.2);transform:translateX(8px); }
nav.side-menu a.active{background:rgba(255,255,255,0.15);}
nav.side-menu a:hover i { transform: scale(1.1); }
.main{margin-left:var(--sidebar-collapsed);padding:30px;transition: margin-left .3s ease;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px; flex-wrap: wrap; gap: 15px;}
.header h1{color:var(--accent-dark);font-size:26px;font-weight:700; margin-right: auto;}
.user-info{font-size:14px;font-weight:500;background:var(--card);padding:10px 15px;border-radius:12px;box-shadow:var(--shadow); color: var(--text-dark);}

/* KPI Cards */
.kpi-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:20px; margin-bottom: 35px;}
.kpi-card{background:var(--card);border-radius:16px;padding:20px; /* Adjusted padding */ box-shadow:var(--shadow);transition:transform .25s ease, box-shadow .25s ease; display: flex; flex-direction: column;} /* Removed border */
.kpi-card:hover{transform:translateY(-6px); box-shadow: var(--shadow-hover);}
.kpi-header{display:flex;align-items:center;justify-content:space-between; margin-bottom: 12px;} /* Adjusted margin */
.kpi-icon{width:45px;height:45px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:19px;} /* Adjusted size/radius */
.kpi-icon.income{background-color: rgba(40, 167, 69, 0.1); color: var(--success);}
.kpi-icon.expense{background-color: rgba(220, 53, 69, 0.1); color: var(--danger);}
.kpi-icon.receivable{background-color: rgba(23, 162, 184, 0.1); color: var(--info);}
.kpi-icon.summary{background-color: rgba(216, 76, 115, 0.1); color: var(--accent);}
.kpi-icon.users{background-color: rgba(255, 193, 7, 0.1); color: var(--warning);}
.kpi-title{font-size:13px;font-weight:500;color:var(--text-muted); line-height: 1.3;} /* Adjusted font size/weight */
.kpi-value{font-size:24px;font-weight:700;color:var(--text-dark); margin-bottom: 8px; line-height: 1.2;} /* Adjusted font size */
.kpi-footer{font-size:11px; color: #aaa; margin-top: auto;}

/* Charts Section */
.charts-section{display:grid;grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap:25px;margin-bottom: 35px;} /* Adjusted minmax */
.chart-card{ background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:30px 25px; position: relative; display: flex; flex-direction: column; transition:transform .25s ease, box-shadow .25s ease;}
.chart-card:hover{transform:translateY(-6px); box-shadow: var(--shadow-hover);}
.chart-card h3{color:var(--text-dark);margin-bottom:25px; text-align: center; font-weight: 600; font-size: 18px;}
.chart-container { position: relative; width: 100%; flex-grow: 1; min-height: 280px; } /* Adjusted min height */
.chart-card p { margin-top: 20px; font-size: 14px; font-weight:500; text-align: center; color: var(--text-muted);}
@media (max-width: 992px) {
    .charts-section { grid-template-columns: 1fr; }
    .kpi-cards{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));}
}
@media (max-width: 768px) {
     .header h1 { font-size: 22px; } .main { padding: 20px; }
     .kpi-value { font-size: 22px; } .kpi-icon { width: 40px; height: 40px; font-size: 18px; }
}


/* Global Button Styles */
.btn{ border: none; border-radius: 8px; padding: 10px 18px; font-weight: 600; cursor: pointer; transition: all 0.25s ease; background: var(--accent-light); color: var(--accent-dark); margin: 0 5px; font-family:"Poppins",sans-serif; font-size: 14px;}
.btn:hover { background: var(--accent); color: #fff; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(216,76,115,0.2);}
.btn.primary{ background:var(--accent); color:#fff;}
.btn.primary:hover{ background: var(--accent-dark); transform:translateY(-2px); box-shadow: 0 4px 15px rgba(216,76,115,0.3);}
.btn i { margin-right: 8px; }

/* Modal Styles */
.modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); display: none; justify-content: center; align-items: center; z-index: 1000; backdrop-filter: blur(5px); padding: 15px;}
.modal.active { display: flex; animation: fadeIn .3s ease; }
.modal-content { background: var(--card); border-radius: 16px; padding: 30px; width: 500px; max-width: 95%; box-shadow: 0 10px 40px rgba(0,0,0,0.15); animation: slideUp .35s ease; position: relative; }
.modal h2 { color: var(--accent-dark); text-align: center; margin-bottom: 25px; font-weight: 700; font-size: 20px;}
.modal h2 i { margin-right: 8px; color: var(--accent); }
.modal form label { font-size: 14px; font-weight: 600; color: var(--text-muted); display: block; margin-top: 15px; margin-bottom: 5px; }
.modal form input, .modal form select { width: 100%; padding: 12px 15px; margin-bottom: 12px; border: 1px solid #ddd; border-radius: 8px; background: #f9f9f9; font-size: 14px; transition: all 0.2s ease; font-family:"Poppins",sans-serif; }
.modal form input:focus, .modal form select:focus { outline: none; border-color: var(--accent); background: #fff; box-shadow: 0 0 0 3px rgba(216, 76, 115, 0.15); }
.modal .actions { display: flex; justify-content: flex-end; margin-top: 25px; gap: 10px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px 20px; align-items: end; }
.date-input { display: none; }
.date-input.visible { display: flex; flex-direction: column; }
@keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
@keyframes slideUp { from { transform: translateY(15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

</style>
</head>
<body>
<aside class="sidebar" id="sidebar">
  <nav class="side-menu">
    <a href="dashboard.php" class="active"><i class="fa fa-chart-pie"></i><span class="label">Dashboard</span></a>
    <a href="transactions/collections.php"><i class="fa fa-cash-register"></i><span class="label">Transactions</span></a>
    <?php if ($is_admin): ?>
    <a href="users.php"><i class="fa fa-users-cog"></i><span class="label">Users</span></a>
    <?php endif; ?>
    <a href="logout.php"><i class="fa fa-sign-out-alt"></i><span class="label">Logout</span></a>
  </nav>
</aside>

<main class="main">
  <div class="header">
    <h1>Dashboard Overview</h1>
    <?php if ($is_admin): ?>
    <button class="btn primary" id="openReportModalBtn"><i class="fa fa-file-pdf"></i> Generate PDF Report</button>
    <?php endif; ?>
    <div class="user-info">
      Logged in as: <b><?= htmlspecialchars($user_name) ?></b> (<?= htmlspecialchars(ucfirst($user_role)) ?>)
    </div>
  </div>

  <section class="kpi-cards">
    <div class="kpi-card income-border">
        <div class="kpi-header"><span class="kpi-title">Income (This Month)</span><div class="kpi-icon income"><i class="fa fa-arrow-trend-up"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($month_income, 2) ?></div><div class="kpi-footer"><?= date('F Y') ?></div>
    </div>
     <div class="kpi-card expense-border">
        <div class="kpi-header"><span class="kpi-title">Expenses (This Month)</span><div class="kpi-icon expense"><i class="fa fa-arrow-trend-down"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($month_expenses, 2) ?></div><div class="kpi-footer"><?= date('F Y') ?></div>
    </div>
     <div class="kpi-card summary-border">
        <div class="kpi-header"><span class="kpi-title">Net Flow (This Month)</span><div class="kpi-icon summary"><i class="fa fa-scale-balanced"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($month_net, 2) ?></div><div class="kpi-footer"><?= date('F Y') ?> (Income - Exp.)</div>
    </div>
     <div class="kpi-card receivable-border">
        <div class="kpi-header"><span class="kpi-title">Outstanding Receivables</span><div class="kpi-icon receivable"><i class="fa fa-file-invoice-dollar"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($total_receivables_outstanding, 2) ?></div><div class="kpi-footer">Total Unpaid Amount</div>
    </div>
    <div class="kpi-card receivable-border">
        <div class="kpi-header"><span class="kpi-title">Unpaid Clients</span><div class="kpi-icon users"><i class="fa fa-user-clock"></i></div></div>
        <div class="kpi-value"><?= number_format($unpaid_client_count) ?></div><div class="kpi-footer">Clients with Balance</div>
    </div>
     <div class="kpi-card income-border">
        <div class="kpi-header"><span class="kpi-title">Today's Income</span><div class="kpi-icon income"><i class="fa fa-sun"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($today_income, 2) ?></div><div class="kpi-footer"><?= date('M d, Y') ?></div>
    </div>
     <div class="kpi-card expense-border">
        <div class="kpi-header"><span class="kpi-title">Today's Expenses</span><div class="kpi-icon expense"><i class="fa fa-sign-out-alt"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($today_expenses, 2) ?></div><div class="kpi-footer"><?= date('M d, Y') ?></div>
    </div>
     <div class="kpi-card summary-border">
        <div class="kpi-header"><span class="kpi-title">All-Time Net</span><div class="kpi-icon summary"><i class="fa fa-landmark"></i></div></div>
        <div class="kpi-value">₱ <?= number_format($all_time_net, 2) ?></div><div class="kpi-footer">Since Beginning</div>
    </div>
  </section>

  <section class="charts-section">
    <div class="chart-card">
      <h3>6-Month Financial Overview</h3>
      <div class="chart-container">
          <canvas id="overviewChart"></canvas> </div>
    </div>
    <div class="chart-card">
      <h3>Today's Summary</h3>
       <div class="chart-container">
          <canvas id="dailySummaryChart"></canvas> </div>
       <p>Collections: ₱<?= number_format($today_collections, 2) ?><br> Expenses: ₱<?= number_format($today_expenses, 2) ?><br> Receivables Added: ₱<?= number_format($today_receivables_added, 2) ?></p>
    </div>
  </section>

</main>

<?php if ($is_admin): ?>
<div class="modal" id="reportModal">
  <div class="modal-content">
    <h2><i class="fa fa-file-pdf"></i> Generate PDF Report</h2>
    <form method="GET" action="dashboard.php" id="reportForm" target="_blank">
        <input type="hidden" name="generate_pdf" value="1">
        <div class="form-grid">
            <div class="form-group">
                <label for="modal_report_type">Report Type</label>
                <select name="report_type" id="modal_report_type" required>
                    <option value="summary">Consolidated Summary</option>
                    <option value="collections">Collections</option>
                    <option value="expenses">Expenses</option>
                    <option value="receivables">Receivables</option>
                </select>
            </div>
            <div class="form-group">
                <label for="modal_period">Period</label>
                <select name="period" id="modal_period" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </div>
            <div class="form-group date-input" id="modal_daily_input">
                <label for="modal_report_date">Select Date</label>
                <input type="date" name="report_date" id="modal_report_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group date-input" id="modal_weekly_input">
                <label for="modal_report_week">Select Week</label>
                <input type="week" name="report_week" id="modal_report_week" value="<?= date('Y-\WW') ?>">
            </div>
             <div class="form-group date-input visible" id="modal_monthly_input">
                <label for="modal_report_month">Select Month</label>
                <input type="month" name="report_month" id="modal_report_month" value="<?= date('Y-m') ?>">
            </div>
             <div class="form-group date-input" id="modal_yearly_input">
                <label for="modal_report_year">Enter Year</label>
                <input type="number" name="report_year" id="modal_report_year" min="2000" max="<?= date('Y') ?>" value="<?= date('Y') ?>" step="1">
            </div>
        </div>
        <div class="actions">
            <button type="button" class="btn" id="closeReportModalBtn">Cancel</button>
            <button type="submit" class="btn primary"><i class="fa fa-download"></i> Generate PDF</button>
        </div>
    </form>
  </div>
</div>
<?php endif; ?>


<script>
    // --- Chart Colors from CSS Variables ---
    const rootStyles = getComputedStyle(document.documentElement);
    const accentColor = rootStyles.getPropertyValue('--accent').trim();
    const purpleColor = rootStyles.getPropertyValue('--purple').trim() || '#6f42c1';
    const warningColor = rootStyles.getPropertyValue('--warning').trim() || '#ffc107';
    const mutedColor = rootStyles.getPropertyValue('--text-muted').trim();

    // --- Chart Default Styling ---
    Chart.defaults.font.family = "'Poppins', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = mutedColor;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0,0,0,0.8)';
    Chart.defaults.plugins.tooltip.titleFont = { weight: '600', size: 13 };
    Chart.defaults.plugins.tooltip.bodyFont = { size: 12 };
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 4;
    Chart.defaults.plugins.tooltip.displayColors = true;
    Chart.defaults.plugins.tooltip.boxPadding = 3;
    Chart.defaults.plugins.legend.position = 'bottom';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.padding = 15;


// --- Chart JS Logic (Matching Screenshot) ---

// 1. 6-Month Overview (Bar Chart)
const overviewCtx = document.getElementById('overviewChart')?.getContext('2d');
if (overviewCtx) {
    new Chart(overviewCtx, {
        type: 'bar',
        data: {
            labels: <?=json_encode($months)?>,
            datasets:[
                { label:'Collections', data: <?=json_encode($monthly_collections)?>, backgroundColor: accentColor }, // Pink
                { label:'Expenses', data: <?=json_encode($monthly_expenses)?>, backgroundColor: purpleColor }, // Purple
                { label:'Receivables Added', data: <?=json_encode($monthly_receivables_added)?>, backgroundColor: warningColor } // Yellow
            ]
        },
        options:{
            responsive:true, maintainAspectRatio: false,
            plugins:{ legend:{ display: true }, tooltip: { mode: 'index', intersect: false } },
            scales:{ y:{ beginAtZero:true, grid: { color: '#f0f0f0' }, ticks: { callback: function(value) { return '₱' + new Intl.NumberFormat('en-PH').format(value); } } }, x:{ grid: { display: false } } },
            barPercentage: 0.8, categoryPercentage: 0.7
        }
    });
}

// 2. Today's Summary (Doughnut Chart)
const dailyCtx = document.getElementById('dailySummaryChart')?.getContext('2d');
if (dailyCtx) {
    new Chart(dailyCtx, {
        type:'doughnut',
        data:{
            labels:['Collections', 'Expenses', 'Receivables Added'],
            datasets:[{
                data:[ <?= max(0.01, $today_collections) ?>, <?= max(0.01, $today_expenses) ?>, <?= max(0.01, $today_receivables_added) ?> ],
                backgroundColor:[ accentColor, purpleColor, warningColor ], // Pink, Purple, Yellow
                borderColor: 'var(--card)', borderWidth: 5, hoverOffset: 10
            }]
        },
        options:{
            responsive: true, maintainAspectRatio: false,
            plugins:{ legend:{ display: false }, // Hide legend as per screenshot
                      tooltip: { callbacks: { label: function(context) { return `${context.label}: ₱${ Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(context.parsed)}`; } } }
            },
            cutout:'70%'
        }
    });
}


// --- Report Modal Logic ---
const reportModal = document.getElementById('reportModal');
const openReportModalBtn = document.getElementById('openReportModalBtn');
const closeReportModalBtn = document.getElementById('closeReportModalBtn');

if (openReportModalBtn && reportModal && closeReportModalBtn) {
    openReportModalBtn.onclick = () => reportModal.classList.add('active');
    closeReportModalBtn.onclick = () => reportModal.classList.remove('active');
    reportModal.addEventListener('click', (ev) => {
        if (ev.target === reportModal) { reportModal.classList.remove('active'); }
    });

    const modalPeriodSelect = document.getElementById('modal_period');
    const modalDailyInput = document.getElementById('modal_daily_input');
    const modalWeeklyInput = document.getElementById('modal_weekly_input');
    const modalMonthlyInput = document.getElementById('modal_monthly_input');
    const modalYearlyInput = document.getElementById('modal_yearly_input');

    function toggleModalDateInputs() {
        [modalDailyInput, modalWeeklyInput, modalMonthlyInput, modalYearlyInput].forEach(el => el?.classList.remove('visible'));
        const selectedPeriod = modalPeriodSelect?.value;
        const elementToShow = document.getElementById(`modal_${selectedPeriod}_input`);
        elementToShow?.classList.add('visible');
    }

    if(modalPeriodSelect) {
        toggleModalDateInputs();
        modalPeriodSelect.addEventListener('change', toggleModalDateInputs);
    }
}

// --- FPDF Error Display via SweetAlert ---
<?php if ($report_error && $is_admin): ?>
    document.addEventListener('DOMContentLoaded', () => {
         Swal.fire({
            icon: 'error', title: 'PDF Library Error',
            text: '<?= addslashes($report_error) ?>',
            confirmButtonText: 'OK', buttonsStyling: false,
            customClass: { confirmButton: 'btn primary' }
        });
    });
<?php endif; ?>

</script>
</body>
</html>