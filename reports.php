<?php
// reports.php
session_start();
require_once 'db.php'; // Database connection (same directory)

// --- Security Check: Ensure user is logged in and is an admin ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php"); // Redirect non-admins
    exit();
}

$current_username = $_SESSION['username'] ?? 'Admin';
$role = $_SESSION['role']; // Confirmed as 'admin'

// --- FPDF PDF Generation Logic ---
if (isset($_GET['generate_pdf'])) {

    // --- Check if FPDF library exists ---
    $fpdf_path = __DIR__ . '/fpdf/fpdf.php'; // Path relative to the current file (reports.php)
    if (!file_exists($fpdf_path)) {
        die("FPDF library not found. Please download it and place it in the 'fpdf' directory.");
    }
    require($fpdf_path);

    // --- Helper function to get data ---
    function fetch_report_data($conn, $sql) {
        $result = $conn->query($sql);
        $data = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }
     // --- Helper function to get single value ---
    function fetch_report_value($conn, $sql, $default = 0.0) {
        $result = $conn->query($sql);
        if ($result && $row = $result->fetch_array()) {
            // Check if the result is numeric before casting
             return is_numeric($row[0]) ? (float)$row[0] : $default;
        }
        return $default;
    }

    // --- Get Report Parameters ---
    $report_type = $_GET['report_type'] ?? 'summary';
    $period_type = $_GET['period'] ?? 'daily';
    $report_date = $_GET['report_date'] ?? date('Y-m-d');
    $report_week = $_GET['report_week'] ?? date('Y-\WW');
    $report_month = $_GET['report_month'] ?? date('Y-m');
    $report_year = $_GET['report_year'] ?? date('Y');

    // --- Determine Date Range ---
    $start_date_time = ''; // For DATETIME columns like transaction_datetime
    $end_date_time = '';
    $start_date_only = ''; // For DATE columns like date_of_payment
    $end_date_only = '';
    $period_label = '';

    switch ($period_type) {
        case 'daily':
            $start_date_time = date('Y-m-d 00:00:00', strtotime($report_date));
            $end_date_time = date('Y-m-d 23:59:59', strtotime($report_date));
            $start_date_only = date('Y-m-d', strtotime($report_date));
            $end_date_only = date('Y-m-d', strtotime($report_date));
            $period_label = "Date: " . date('M d, Y', strtotime($report_date));
            break;
        case 'weekly':
            $year = substr($report_week, 0, 4);
            $week = substr($report_week, 6, 2);
            $start_timestamp = strtotime($year . 'W' . $week . '1'); // Monday
            $end_timestamp = strtotime($year . 'W' . $week . '7');   // Sunday
            $start_date_time = date('Y-m-d 00:00:00', $start_timestamp);
            $end_date_time = date('Y-m-d 23:59:59', $end_timestamp);
            $start_date_only = date('Y-m-d', $start_timestamp);
            $end_date_only = date('Y-m-d', $end_timestamp);
            $period_label = "Week: " . date('M d', $start_timestamp) . " - " . date('M d, Y', $end_timestamp);
            break;
        case 'monthly':
            $start_date_time = date('Y-m-01 00:00:00', strtotime($report_month . '-01'));
            $end_date_time = date('Y-m-t 23:59:59', strtotime($report_month . '-01'));
            $start_date_only = date('Y-m-01', strtotime($report_month . '-01'));
            $end_date_only = date('Y-m-t', strtotime($report_month . '-01'));
            $period_label = "Month: " . date('F Y', strtotime($report_month . '-01'));
            break;
        case 'yearly':
            $start_date_time = date('Y-01-01 00:00:00', strtotime($report_year . '-01-01'));
            $end_date_time = date('Y-12-31 23:59:59', strtotime($report_year . '-01-01'));
            $start_date_only = date('Y-01-01', strtotime($report_year . '-01-01'));
            $end_date_only = date('Y-12-31', strtotime($report_year . '-01-01'));
            $period_label = "Year: " . $report_year;
            break;
        default:
            die("Invalid period type.");
    }
     // Date range conditions for different column types
    $date_condition_datetime = "transaction_datetime BETWEEN '$start_date_time' AND '$end_date_time'";
    $date_condition_date = "date_of_payment BETWEEN '$start_date_only' AND '$end_date_only'"; // Using date_of_payment for receivables
    $created_condition_datetime = "created_at BETWEEN '$start_date_time' AND '$end_date_time'";


    // --- Custom FPDF Class with Header and Footer ---
    class PDF extends FPDF {
        private $reportTitle = 'Report';
        private $periodLabel = '';
        private $generatedBy = '';

        function setReportHeader($title, $period, $user) {
            $this->reportTitle = $title;
            $this->periodLabel = $period;
            $this->generatedBy = $user;
        }

        // Page header
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'RCRAO Accounting System', 0, 1, 'C');
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 10, $this->reportTitle, 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, $this->periodLabel, 0, 1, 'C');
            $this->Cell(0, 5, 'Generated By: ' . $this->generatedBy . ' on ' . date('Y-m-d H:i'), 0, 1, 'C');
            $this->Ln(5);
        }

        // Page footer
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }

        // Simple table
        function BasicTable($header, $data) {
            $this->SetFillColor(255, 214, 229);
            $this->SetTextColor(107, 74, 87);
            $this->SetDrawColor(243, 208, 220);
            $this->SetFont('', 'B');
            $widths = $this->CalculateWidths($header, $data);
            for ($i = 0; $i < count($header); $i++)
                $this->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
            $this->Ln();
            $this->SetFont('');
            $this->SetTextColor(0);
            $fill = false;
            foreach ($data as $row) {
                 $this->SetFillColor(255, 240, 246);
                for ($i = 0; $i < count($header); $i++) {
                    $cellValue = $row[$i] ?? '';
                    $cleanValue = str_replace(['₱', ',', ' '], '', $cellValue);
                    $align = (is_numeric($cleanValue)) ? 'R' : 'L';
                    $this->Cell($widths[$i], 6, $cellValue, 'LR', 0, $align, $fill);
                }
                $this->Ln();
                $fill = !$fill;
            }
            $this->Cell(array_sum($widths), 0, '', 'T');
        }

         // Calculate optimal column widths
        function CalculateWidths($header, $data) {
            $num_cols = count($header);
            $pageWidth = $this->GetPageWidth() - 20;
            $widths = [];
            for ($i = 0; $i < $num_cols; $i++) {
                $widths[$i] = $this->GetStringWidth($header[$i]) + 6;
            }
            $sampleData = array_slice($data, 0, 20);
             foreach ($sampleData as $row) {
                 for ($i = 0; $i < $num_cols; $i++) {
                      $cellValue = $row[$i] ?? '';
                     $widths[$i] = max($widths[$i], $this->GetStringWidth((string)$cellValue) + 6);
                 }
             }
            $totalWidth = array_sum($widths);
            if ($totalWidth <= 0) {
                return array_fill(0, $num_cols, $pageWidth / $num_cols);
            }
             $scaleFactor = $pageWidth / $totalWidth;
             for ($i = 0; $i < $num_cols; $i++) {
                 $widths[$i] *= $scaleFactor;
             }
            return $widths;
        }

        // Summary Section
        function SummarySection($title, $items) {
             $this->SetFont('Arial', 'B', 11);
             $this->Cell(0, 8, $title, 0, 1, 'L');
             $this->SetFont('Arial', '', 10);
             $this->SetFillColor(255, 240, 246);
             $fill = true;
             foreach ($items as $label => $value) {
                 if (strpos(strtoupper($label), 'TOTAL') !== false || strpos(strtoupper($label), 'NET') !== false) {
                     $this->SetFont('Arial', 'B', 10);
                 } else {
                      $this->SetFont('Arial', '', 10);
                 }
                 $this->Cell(90, 7, $label, 'LR', 0, 'L', $fill); // Increased label width
                 $this->Cell(40, 7, '₱ ' . number_format($value, 2), 'LR', 1, 'R', $fill);
                 $fill = !$fill;
             }
              $this->SetFont('Arial', '', 10);
             $this->Cell(130, 0, '', 'T'); // Adjusted closing line width
             $this->Ln(5);
        }
    } // End PDF Class

    // --- Create PDF Instance ---
    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);

    // --- Generate Content Based on Report Type ---
    $filename_suffix = strtolower($period_type) . '_' . date('Ymd');
    $main_title = '';

    if ($report_type === 'collections') {
        $main_title = 'Collections Report';
        $sql = "SELECT client_name, affiliation, reference_number, amount, cash_received, (amount - IFNULL(cash_received,0)) as unpaid, mode_of_payment, person_in_charge, DATE(created_at) as date
                FROM collections WHERE $date_condition_datetime ORDER BY created_at ASC"; // Use datetime condition
        $data = fetch_report_data($conn, $sql);
        $total_amount = 0;
        $total_received = 0;
        $table_data = [];
        foreach ($data as $row) {
            $table_data[] = [
                $row['date'], $row['client_name'], $row['reference_number'],
                '₱ ' . number_format($row['amount'], 2), '₱ ' . number_format($row['cash_received'], 2),
                '₱ ' . number_format($row['unpaid'], 2), $row['mode_of_payment'], $row['person_in_charge'],
            ];
            $total_amount += $row['amount'];
            $total_received += $row['cash_received'];
        }
        $pdf->setReportHeader($main_title, $period_label, $current_username);
        if (!empty($table_data)) {
            $header = ['Date', 'Client', 'Ref #', 'Amount', 'Received', 'Unpaid', 'Mode', 'In-Charge'];
            $pdf->BasicTable($header, $table_data);
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 7, 'Total Amount: ₱ ' . number_format($total_amount, 2), 0, 1, 'R');
            $pdf->Cell(0, 7, 'Total Cash Received: ₱ ' . number_format($total_received, 2), 0, 1, 'R');
        } else {
             $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No collection data found.', 0, 1, 'C');
        }
         $filename_suffix = 'collections_' . $filename_suffix;

    } elseif ($report_type === 'expenses') {
        $main_title = 'Expenses Report';
        $sql = "SELECT expense, store_or_merchant, amount, person_in_charge, DATE(created_at) as date
                FROM expenses WHERE $date_condition_datetime ORDER BY created_at ASC"; // Use datetime condition
        $data = fetch_report_data($conn, $sql);
        $total_expenses = 0;
        $table_data = [];
        foreach ($data as $row) {
             $table_data[] = [
                 $row['date'], $row['expense'], $row['store_or_merchant'],
                 '₱ ' . number_format($row['amount'], 2), $row['person_in_charge'],
             ];
            $total_expenses += $row['amount'];
        }
        $pdf->setReportHeader($main_title, $period_label, $current_username);
         if (!empty($table_data)) {
            $header = ['Date', 'Expense', 'Store/Merchant', 'Amount', 'In-Charge'];
            $pdf->BasicTable($header, $table_data);
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 7, 'Total Expenses: ₱ ' . number_format($total_expenses, 2), 0, 1, 'R');
         } else {
              $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No expense data found.', 0, 1, 'C');
         }
         $filename_suffix = 'expenses_' . $filename_suffix;

    } elseif ($report_type === 'receivables') {
         $main_title = 'Receivables Report';
         // Fetch receivables added during the period
         $sql_added = "SELECT client_name, affiliation, reference_number, amount, DATE(created_at) as date
                       FROM receivables WHERE $created_condition_datetime ORDER BY created_at ASC"; // Use datetime condition

         $data_added = fetch_report_data($conn, $sql_added);
         $total_added = 0;

         $pdf->setReportHeader($main_title, $period_label, $current_username);

         // Section for Receivables Added
         $pdf->SetFont('Arial', 'B', 11);
         $pdf->Cell(0, 8, 'Receivables Added During Period', 0, 1);
         $table_data_added = [];
         if (!empty($data_added)) {
             foreach ($data_added as $row) {
                  $table_data_added[] = [
                      $row['date'], $row['client_name'], $row['reference_number'],
                      '₱ ' . number_format($row['amount'], 2),
                  ];
                 $total_added += $row['amount'];
             }
             $header_added = ['Date Added', 'Client', 'Ref #', 'Amount'];
             $pdf->BasicTable($header_added, $table_data_added);
             $pdf->Ln(2);
             $pdf->SetFont('Arial', 'B', 10);
             $pdf->Cell(0, 7, 'Total Added: ₱ ' . number_format($total_added, 2), 0, 1, 'R');
         } else {
              $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No new receivables added.', 0, 1);
         }
         $pdf->Ln(5);

         // --- Removed "Payments Received During Period" section ---

         // Section for Outstanding Receivables (as of end of period)
         $pdf->SetFont('Arial', 'B', 11);
         $pdf->Cell(0, 8, 'Outstanding Receivables (As of ' . date('M d, Y', strtotime($end_date_time)) . ')', 0, 1);
         $sql_outstanding = "SELECT client_name, reference_number, amount, amount_paid, (amount - IFNULL(amount_paid, 0)) AS balance
                             FROM receivables
                             WHERE created_at <= '$end_date_time' AND is_paid = 0
                             ORDER BY client_name ASC";
         $data_outstanding = fetch_report_data($conn, $sql_outstanding);
         $total_outstanding = 0;
         $table_data_outstanding = [];
         if(!empty($data_outstanding)){
             foreach($data_outstanding as $row){
                 $table_data_outstanding[] = [
                     $row['client_name'],
                     $row['reference_number'],
                     '₱ ' . number_format($row['amount'], 2),
                     '₱ ' . number_format($row['amount_paid'], 2),
                     '₱ ' . number_format($row['balance'], 2),
                 ];
                 $total_outstanding += $row['balance'];
             }
             $header_outstanding = ['Client', 'Ref #', 'Total Amount', 'Amount Paid', 'Outstanding Balance'];
             $pdf->BasicTable($header_outstanding, $table_data_outstanding);
             $pdf->Ln(2);
             $pdf->SetFont('Arial', 'B', 10);
             $pdf->Cell(0, 7, 'Total Outstanding Balance: ₱ ' . number_format($total_outstanding, 2), 0, 1, 'R');
         } else {
             $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 10, 'No outstanding receivables found.', 0, 1);
         }


         $filename_suffix = 'receivables_' . $filename_suffix;

    } elseif ($report_type === 'summary') {
        $main_title = 'Consolidated Financial Summary';
        $pdf->setReportHeader($main_title, $period_label, $current_username);

        // --- Calculate totals for the period (Modified) ---
        $collections_cash = fetch_report_value($conn, "SELECT IFNULL(SUM(cash_received),0) FROM collections WHERE $date_condition_datetime");

        // Estimate receivable payments: Sum amount_paid for receivables marked paid within the period
        // Using date_of_payment assuming it holds the final payment date. If not reliable, this estimate is weak.
        $receivable_payments_estimated = fetch_report_value($conn, "SELECT IFNULL(SUM(amount_paid),0) FROM receivables WHERE is_paid = 1 AND $date_condition_date"); // Use DATE condition

        $total_inflow = $collections_cash + $receivable_payments_estimated;

        $total_expenses = fetch_report_value($conn, "SELECT IFNULL(SUM(amount),0) FROM expenses WHERE $date_condition_datetime");
        $total_outflow = $total_expenses;

        $receivables_added = fetch_report_value($conn, "SELECT IFNULL(SUM(amount),0) FROM receivables WHERE $created_condition_datetime");

        $net_cash_flow = $total_inflow - $total_outflow;

         // Get total outstanding receivables at the END of the period (remains the same)
         $total_receivables_outstanding = fetch_report_value($conn, "SELECT IFNULL(SUM(amount - IFNULL(amount_paid, 0)), 0) FROM receivables WHERE created_at <= '$end_date_time' AND is_paid = 0");


        // Display Summary
        $pdf->SummarySection('Income / Inflows (Period)', [
            'Collections (Cash Received)' => $collections_cash,
            'Receivable Payments (Estimated*)' => $receivable_payments_estimated, // Indicate it's estimated
            'TOTAL INFLOWS' => $total_inflow,
        ]);

        $pdf->SummarySection('Expenses / Outflows (Period)', [
            'Total Expenses Paid' => $total_expenses,
            'TOTAL OUTFLOWS' => $total_outflow,
        ]);

         $pdf->SummarySection('Receivables Activity (Period)', [
            'New Receivables Added' => $receivables_added,
            // Removed payment details, added outstanding total
             'TOTAL OUTSTANDING (End of Period)' => $total_receivables_outstanding,
        ]);

         $pdf->SummarySection('Period Summary', [
            'Total Inflows' => $total_inflow,
            'Total Outflows' => $total_outflow,
            'NET CASH FLOW' => $net_cash_flow,
        ]);

        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->MultiCell(0, 5, '*Receivable Payments are estimated based on items marked as fully paid within the period using their `date_of_payment`. Actual cash inflow timing might differ if multiple payments were made.');

         $filename_suffix = 'summary_' . $filename_suffix;

    } else {
        $pdf->SetFont('Arial','', 10);
        $pdf->Cell(0, 10, 'Invalid Report Type Selected.', 0, 1, 'C');
    }

    // --- Output PDF ---
    $filename = "RCRAO_Report_" . $filename_suffix . ".pdf";
    if (ob_get_level()) { ob_end_clean(); } // Clean buffer
    $pdf->Output('D', $filename);
    exit();
} // End if generate_pdf

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports — RCRAO Accounting</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
  --accent:#d84c73;
  --accent-light:#ffb6c1;
  --bg1:#fff0f6;
  --bg2:#ffe6ee;
  --card:#fff;
  --muted:#6b4a57;
  --shadow:0 8px 25px rgba(216,76,115,0.1);
  --sidebar-collapsed:72px;
  --sidebar-expanded:230px;
}
*{box-sizing:border-box;font-family:"Poppins",sans-serif;margin:0;padding:0}
body{background:linear-gradient(135deg,var(--bg1),var(--bg2));color:var(--muted);overflow-x:hidden}
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-collapsed);background:linear-gradient(180deg,var(--accent) 0%,#ff7ea1 100%);
display:flex;flex-direction:column;justify-content:space-between;padding:12px;transition:width .3s;z-index:50}
.sidebar:hover,.sidebar.expanded{width:var(--sidebar-expanded);box-shadow:var(--shadow)}
nav.side-menu{margin-top:20px;display:flex;flex-direction:column;gap:6px}
nav.side-menu a{display:flex;align-items:center;gap:12px;padding:10px;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;transition:.2s}
nav.side-menu a i{width:24px;text-align:center}
nav.side-menu a .label{display:none}
.sidebar:hover nav.side-menu a .label,.sidebar.expanded nav.side-menu a .label{display:inline}
nav.side-menu a:hover,nav.side-menu a.active{background:rgba(255,255,255,0.15);transform:translateX(6px)}
.main{margin-left:var(--sidebar-collapsed);padding:28px;transition:.3s}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.header h1{color:var(--accent);font-size:22px;font-weight:800}
.user-info{font-size:14px;font-weight:600;background:var(--card);padding:10px 14px;border-radius:10px;box-shadow:var(--shadow)}
.toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;background:var(--card);padding:14px 16px;border-radius:12px;box-shadow:var(--shadow)}
.toolbar input, .toolbar select {padding:8px 12px;border-radius:8px;border:1px solid #ccc;}

/* Global Button Styles */
.btn{
  border: none;
  border-radius: 10px;
  padding: 10px 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.25s ease;
  background: #ffeaf1; /* Light pink */
  color: var(--accent);
  margin: 0 4px;
}
.btn:hover {
  background: #ffd6e5;
  transform: translateY(-2px);
}
.btn.primary{
  background:var(--accent);
  color:#fff;
}
.btn.primary:hover{
  background:#ff7ea1;
  transform:translateY(-2px);
}
.btn.small{padding:6px 10px;font-size:13px}

.report-form-card { background: var(--card); border-radius:14px; padding: 20px; box-shadow: var(--shadow); }
.report-form-card h2 { color: var(--accent); margin-bottom: 15px; text-align: center; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; align-items: end; }
.form-group { display: flex; flex-direction: column; }
.form-group label { font-size: 14px; font-weight: 600; color: var(--muted); margin-bottom: 5px; }
.form-group input, .form-group select { width: 100%; padding:8px 12px; border-radius:8px; border:1px solid #ccc; font-family:"Poppins",sans-serif; font-size: 14px;}
.form-actions { text-align: center; margin-top: 20px; }

/* Style for date inputs */
.date-input { display: none; } /* Hide all by default */
.date-input.visible { display: flex; flex-direction: column; } /* Show when visible class is added */

</style>
</head>
<body>
<aside class="sidebar">
  <nav class="side-menu">
    <a href="dashboard.php"><i class="fa fa-chart-line"></i><span class="label">Dashboard</span></a>
    <a href="transactions/collections.php"><i class="fa fa-exchange-alt"></i><span class="label">Transactions</span></a>
    <?php if ($_SESSION['role'] === 'admin'): ?>
    <a href="users.php"><i class="fa fa-users-cog"></i><span class="label">Users</span></a>
    <a href="reports.php" class="active"><i class="fa fa-file-export"></i><span class="label">Reports</span></a>
    <?php endif; ?>
    <a href="logout.php"><i class="fa fa-sign-out-alt"></i><span class="label">Logout</span></a>
  </nav>
</aside>

<main class="main">
  <div class="header">
    <h1>Generate Reports</h1>
    <div class="user-info">Logged in as: <b><?= htmlspecialchars($current_username) ?></b> (Admin)</div>
  </div>

  <div class="report-form-card">
    <h2>Select Report Criteria</h2>
    <form method="GET" action="reports.php" id="reportForm" target="_blank"> <input type="hidden" name="generate_pdf" value="1"> <div class="form-grid">
            <div class="form-group">
                <label for="report_type">Report Type</label>
                <select name="report_type" id="report_type" required>
                    <option value="summary">Consolidated Summary</option>
                    <option value="collections">Collections</option>
                    <option value="expenses">Expenses</option>
                    <option value="receivables">Receivables</option>
                </select>
            </div>
            <div class="form-group">
                <label for="period">Period</label>
                <select name="period" id="period" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </div>

            <div class="form-group date-input visible" id="daily_input">
                <label for="report_date">Select Date</label>
                <input type="date" name="report_date" id="report_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group date-input" id="weekly_input">
                <label for="report_week">Select Week</label>
                <input type="week" name="report_week" id="report_week" value="<?= date('Y-\WW') ?>">
            </div>
             <div class="form-group date-input" id="monthly_input">
                <label for="report_month">Select Month</label>
                <input type="month" name="report_month" id="report_month" value="<?= date('Y-m') ?>">
            </div>
             <div class="form-group date-input" id="yearly_input">
                <label for="report_year">Enter Year</label>
                <input type="number" name="report_year" id="report_year" min="2000" max="<?= date('Y') ?>" value="<?= date('Y') ?>" step="1">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn primary"><i class="fa fa-file-pdf"></i> Generate PDF Report</button>
        </div>
    </form>
  </div>

</main>

<script>
    const periodSelect = document.getElementById('period');
    const dailyInput = document.getElementById('daily_input');
    const weeklyInput = document.getElementById('weekly_input');
    const monthlyInput = document.getElementById('monthly_input');
    const yearlyInput = document.getElementById('yearly_input');

    function toggleDateInputs() {
        dailyInput.classList.remove('visible');
        weeklyInput.classList.remove('visible');
        monthlyInput.classList.remove('visible');
        yearlyInput.classList.remove('visible');

        const selectedPeriod = periodSelect.value;
        if (selectedPeriod === 'daily') { dailyInput.classList.add('visible'); }
        else if (selectedPeriod === 'weekly') { weeklyInput.classList.add('visible'); }
        else if (selectedPeriod === 'monthly') { monthlyInput.classList.add('visible'); }
        else if (selectedPeriod === 'yearly') { yearlyInput.classList.add('visible'); }
    }

    toggleDateInputs(); // Initial call
    periodSelect.addEventListener('change', toggleDateInputs);

    // FPDF Check
    <?php
        $fpdf_check_path = __DIR__ . '/fpdf/fpdf.php';
        if (!file_exists($fpdf_check_path)):
    ?>
        document.addEventListener('DOMContentLoaded', () => {
             Swal.fire({
                icon: 'error', title: 'Configuration Error',
                html: 'The FPDF library needed for PDF generation was not found in the expected location: <br><code><?= htmlspecialchars($fpdf_check_path) ?></code>.<br> Please install it correctly.',
                confirmButtonText: 'OK', buttonsStyling: false,
                customClass: { confirmButton: 'btn primary' }
            });
            const submitBtn = document.querySelector('#reportForm button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.style.opacity = 0.5;
            submitBtn.style.cursor = 'not-allowed';
        });
    <?php endif; ?>
</script>
</body>
</html>