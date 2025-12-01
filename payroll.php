<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'employee_managements';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if deleted column exists, if not add it
try {
    $conn->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0");
} catch(PDOException $e) {
    // Column might already exist, continue
}

// Default rates configuration
$defaultRates = [
    'Regular' => [
        'daily_rate' => 700.00,
        'hourly_rate' => 87.50,
        'ot_rate' => 87.50
    ],
    'Probationary' => [
        'daily_rate' => 600.00,
        'hourly_rate' => 75.00,
        'ot_rate' => 75.00
    ],
    'Terminated' => [
        'daily_rate' => 0.00,
        'hourly_rate' => 0.00,
        'ot_rate' => 0.00
    ]
];

// Get payroll settings (create default if not exists)
try {
    $stmt = $conn->query("SELECT * FROM payroll_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        // Create default settings
        $conn->exec("INSERT INTO payroll_settings (sss_rate, philhealth_rate, pagibig_rate) VALUES (3.63, 2.75, 2.00)");
        $settings = ['sss_rate' => 3.63, 'philhealth_rate' => 2.75, 'pagibig_rate' => 2.00];
    }
} catch(PDOException $e) {
    $settings = ['sss_rate' => 3.63, 'philhealth_rate' => 2.75, 'pagibig_rate' => 2.00];
}

$sssRate = $settings['sss_rate'];
$philhealthRate = $settings['philhealth_rate'];
$pagibigRate = $settings['pagibig_rate'];

// Function to determine which week of the month (1st, 2nd, 3rd, 4th)
function getWeekOfMonth($date) {
    $dateObj = is_string($date) ? new DateTime($date) : $date;
    $firstDayOfMonth = new DateTime($dateObj->format('Y-m-01'));
    $dayOfMonth = (int)$dateObj->format('d');
    
    // Calculate which week of the month (1-4)
    // Week 1: days 1-7, Week 2: days 8-14, Week 3: days 15-21, Week 4: days 22-28, Week 5: days 29-31
    if ($dayOfMonth <= 7) {
        return 1;
    } elseif ($dayOfMonth <= 14) {
        return 2;
    } elseif ($dayOfMonth <= 21) {
        return 3;
    } elseif ($dayOfMonth <= 28) {
        return 4;
    } else {
        return 5; // Last few days of month
    }
}

// Get all employees
$employees = [];
$departments = [];
$shifts = [];

try {
    // Get all active employees
    $stmt = $conn->prepare("
        SELECT employee_no, name as employee_name, department_name, shift, status, basic_pay
        FROM employees 
        WHERE status != 'Terminated'
        ORDER BY name
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique departments
    $stmt = $conn->prepare("SELECT DISTINCT department_name FROM employees WHERE department_name IS NOT NULL ORDER BY department_name");
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique shifts
    $stmt = $conn->prepare("SELECT DISTINCT shift FROM employees WHERE shift IS NOT NULL ORDER BY shift");
    $stmt->execute();
    $shifts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    $error = "Error fetching employee data: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['process_payroll'])) {
        try {
            $employeeNo  = $_POST['employee_no'];
            $allowances  = $_POST['allowances'] ?? 0;

            // Get employee details
            $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_no = ?");
            $stmt->execute([$employeeNo]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                throw new Exception("Employee not found");
            }
            if ($employee['status'] === 'Terminated') {
                throw new Exception("Cannot process payroll for terminated employee");
            }

            // 1) AUTO: current Monday–Sunday pay week
            $today     = new DateTime();
            $weekStart = (clone $today)->modify('monday this week');
            $weekEnd   = (clone $today)->modify('sunday this week');

            $startDate  = $weekStart->format('Y-m-d');
            $endDate    = $weekEnd->format('Y-m-d');
            $weekPeriod = $startDate . ' to ' . $endDate;

            // 2) Get attendance for that period
            $stmt = $conn->prepare("
                SELECT date, time_in, time_out
                FROM attendance
                WHERE employee_no = :emp_no
                  AND date BETWEEN :start_date AND :end_date
                  AND status IN ('Present','Late')
            ");
            $stmt->execute([
                ':emp_no'     => $employee['employee_no'],
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ]);
            $attRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $daysWorked     = 0;  // Mon–Sat only
            $totalNormalHrs = 0;
            $totalOtHrs     = 0;

            // OPTIONAL: holiday support – create a holidays table with a `date` column
            $holidayDates = [];
            $hStmt = $conn->query("SELECT date FROM holidays");     // if you have this table
            $holidayDatesRaw = $hStmt ? $hStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            foreach ($holidayDatesRaw as $hd) {
                $holidayDates[$hd] = true;
            }

            foreach ($attRows as $att) {
                if (!$att['time_in'] || !$att['time_out']) continue;

                $dateObj = new DateTime($att['date']);
                $dow     = (int)$dateObj->format('N'); // 1=Mon ... 7=Sun

                $timeIn  = strtotime($att['time_in']);
                $timeOut = strtotime($att['time_out']);
                $total   = ($timeOut - $timeIn) / 3600;
                $total   = max(0, $total - 1); // minus 1h break
                if ($total <= 0) continue;

                $isSunday = ($dow === 7);
                $shiftEnd = strtotime('16:00:00');

                if ($isSunday) {
                    // Sunday: all hours are OT
                    $totalOtHrs += $total;
                } else {
                    // Mon–Sat: OT only after 4 PM
                    $otFromTime = 0;
                    if ($timeOut > $shiftEnd) {
                        $otFromTime = ($timeOut - $shiftEnd) / 3600;
                    }

                    $normal = max(0, min(8, $total - $otFromTime));
                    $ot     = max(0, $otFromTime);

                    if ($normal > 0) {
                        $daysWorked++;
                        $totalNormalHrs += $normal;
                    }
                    $totalOtHrs += $ot;
                }
            }

            // Fixed rule: 8 working hours per Mon–Sat day
            $workingHours  = $daysWorked * 8;
            $overtimeHours = $totalOtHrs;

            // Determine pay rates based on status
            $status = !empty($employee['status']) ? $employee['status'] : 'Regular';
            $status = isset($defaultRates[$status]) ? $status : 'Regular';
            $dailyRate  = $defaultRates[$status]['daily_rate'];
            $hourlyRate = $defaultRates[$status]['hourly_rate'];
            $otRate     = $defaultRates[$status]['ot_rate'];

            // Calculate payroll
            $basicSalary  = $dailyRate * $daysWorked;
            $overtimePay  = $overtimeHours * $otRate;

            // Determine which week of the month (based on week start date)
            $weekOfMonth = getWeekOfMonth($weekStart);
            
            // Calculate deductions - only on 2nd and 4th week of the month
            // Monthly deductions divided by 2 (since deducted twice per month)
            $sssDeduction = 0;
            $philhealthDeduction = 0;
            $pagibigDeduction = 0;
            
            if ($weekOfMonth == 2 || $weekOfMonth == 4) {
                // Calculate monthly basic salary (daily rate * 26 working days per month)
                $monthlyBasic = $dailyRate * 26;
                
                // Calculate monthly deductions, then divide by 2 (for 2 deductions per month)
                $sssDeduction        = ($monthlyBasic * ($sssRate / 100)) / 2;
                $philhealthDeduction = ($monthlyBasic * ($philhealthRate / 100)) / 2;
                $pagibigDeduction    = ($monthlyBasic * ($pagibigRate / 100)) / 2;
            }
            
            $totalDeductions = $sssDeduction + $philhealthDeduction + $pagibigDeduction;

            // Allowance - only 30 pesos on 4th week
            $finalAllowances = 0;
            if ($weekOfMonth == 4) {
                // Default allowance is 30 pesos on 4th week
                $finalAllowances = 30.00;
                // Override with user input if provided (but minimum 30 on 4th week)
                if ($allowances > 0) {
                    $finalAllowances = $allowances;
                }
            }

            $grossPay = $basicSalary + $overtimePay + $finalAllowances;
            $netSalary = $grossPay - $totalDeductions;

            // Save to payroll_records
            $stmt = $conn->prepare("
                INSERT INTO payroll_records (
                    employee_no, employee_name, department, shift, week_period,
                    working_hours, days_worked, overtime_hours, basic_salary,
                    overtime_pay, allowances, pagibig, sss, philhealth,
                    gross_pay, total_deductions, net_salary
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $employee['employee_no'],     // employee_no
                $employee['name'],           // employee_name
                $employee['department_name'],// department
                $employee['shift'],          // shift
                $weekPeriod,                 // week_period
                $workingHours,               // working_hours (8 * daysWorked)
                $daysWorked,                 // days_worked
                $overtimeHours,              // overtime_hours (sum from attendance)
                $basicSalary,                // basic_salary
                $overtimePay,                // overtime_pay
                $finalAllowances,           // allowances
                $pagibigDeduction,           // pagibig
                $sssDeduction,               // sss
                $philhealthDeduction,        // philhealth
                $grossPay,                   // gross_pay
                $totalDeductions,            // total_deductions
                $netSalary                   // net_salary
            ]);

            $success = "Payroll processed successfully for " . $employee['name'];

        } catch(PDOException $e) {
            $error = "Error processing payroll: " . $e->getMessage();
        } catch(Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_payroll'])) {
        try {
            $payrollId = $_POST['payroll_id'];
            
            // Soft delete - mark as deleted instead of actually deleting
            $stmt = $conn->prepare("UPDATE payroll_records SET deleted = 1 WHERE id = ?");
            $stmt->execute([$payrollId]);
            
            $success = "Payroll record moved to trash successfully";
            
        } catch(PDOException $e) {
            $error = "Error deleting payroll record: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['restore_payroll'])) {
        try {
            $payrollId = $_POST['payroll_id'];
            
            // Restore deleted record
            $stmt = $conn->prepare("UPDATE payroll_records SET deleted = 0 WHERE id = ?");
            $stmt->execute([$payrollId]);
            
            $success = "Payroll record restored successfully";
            
        } catch(PDOException $e) {
            $error = "Error restoring payroll record: " . $e->getMessage();
        }
    }
}

// Get payroll history (only non-deleted records)
$payrollHistory = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM payroll_records 
        WHERE deleted = 0
        ORDER BY week_period DESC, employee_name
    ");
    $stmt->execute();
    $payrollHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching payroll history: " . $e->getMessage();
}

// Get deleted payroll records for trash view
$deletedPayrollHistory = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM payroll_records 
        WHERE deleted = 1
        ORDER BY week_period DESC, employee_name
    ");
    $stmt->execute();
    $deletedPayrollHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching deleted payroll history: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Processing</title>
	<link rel="stylesheet" href="payroll.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
	/* General Styles */
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background-color: #f4f4f8;
}

/* Sidebar */
.sidebar {
    width: 80px;
    background-color: #000066;
    height: 100vh;
    color: white;
    position: fixed;
    display: flex;
    flex-direction: column;
    transition: width 0.3s ease;
    overflow: hidden;
}

.sidebar:hover {
    width: 250px;
}

.logo-section {
    padding: 20px 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.logo-text {
    display: none;
}

.sidebar:hover .logo-text {
    display: inline;
}

nav ul,
.reports ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

nav ul li,
.reports ul li {
    padding: 15px 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: background-color 0.3s ease;
    border-radius: 0; /* removed rounded edges */
}

/* Hover effect */
nav ul li:hover,
.reports ul li:hover {
    background-color: #1a1a80;
    border-left: 5px solid #ccc;
}

/* Active class style */
nav ul li.active,
.reports ul li.active {
    background-color: #333399;
    border-left: 5px solid white;
    border-radius: 0;
}

nav ul li span,
.reports ul li span,
.reports > span,
.power-btn span {
    display: none;
    white-space: nowrap;
}

.sidebar:hover nav ul li span,
.sidebar:hover .reports ul li span,
.sidebar:hover .reports > span,
.sidebar:hover .power-btn span {
    display: inline;
}

.reports {
    margin-top: auto;
    padding: 10px;
    color: white;
}

.sidebar-footer {
    padding: 10px;
}

.power-btn {
    font-size: 18px;
    background-color: red;
    color: white;
    border: none;
    padding: 10px;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    justify-content: center;
    border-radius: 0;
}

/* Main Content */
.main-content {
    margin-left: 80px;
    padding: 20px;
}

.header-logo {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px 20px;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.logo-img {
    height: 50px;
    width: auto;
}

.logo-text-right {
    font-size: 28px;
    font-weight: bold;
    color: black;
}

/* Remove any duplicate or conflicting styles */
.logo-text {
    display: none;
}

/* Cards Section */
.top-cards {
    display: flex;
    gap: 20px;
    margin: 20px 0;
}

.card {
    flex: 1;
    background-color: white;
    padding: 20px;
    box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
    border-radius: 0;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.card:hover {
    transform: translateY(-5px);
}

.card h3 {
    margin: 0 0 10px;
    font-size: 16px;
    color: #444;
}

.card p {
    font-size: 24px;
    font-weight: bold;
}

/* Content Area */
.content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Recent Activities Section */
.recent-activities-section {
    background: white;
    padding: 20px;
    box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
    border-radius: 0;
    margin-top: 0;
    height: calc(100vh - 300px); /* Adjust height to fill remaining space */
    min-height: 400px;
}

.activities-list {
    height: calc(100% - 60px); /* Subtract header height */
    overflow-y: auto;
    padding-right: 10px;
}

.activity-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid #eee;
    transition: background-color 0.2s ease;
}

.activity-item:hover {
    background-color: #f8f9fa;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    font-size: 24px;
    margin-right: 15px;
    min-width: 24px;
}

.activity-content {
    flex: 1;
}

.activity-title {
    font-weight: 500;
    color: #333;
    margin-bottom: 5px;
    font-size: 14px;
}

.activity-timestamp {
    font-size: 12px;
    color: #666;
}

/* Customize scrollbar for activities list */
.activities-list::-webkit-scrollbar {
    width: 8px;
}

.activities-list::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.activities-list::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

.activities-list::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Content Area */
.content {
    display: flex;
    gap: 20px;
}

.payroll-section {
    flex: 2;
    background: white;
    padding: 20px;
    box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
    border-radius: 0;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-header button {
    background: #ddd;
    border: none;
    padding: 5px 10px;
    cursor: pointer;
    border-radius: 0;
}

/* Tables */
table {
    width: 100%;
    margin-top: 10px;
    border-collapse: collapse;
}

table th {
    background-color: #003366;
    color: white;
    padding: 10px;
    text-align: center;
}

table td {
    padding: 10px;
    border-bottom: 1px solid #bbb;
    text-align: center;
}

.status.completed {
    background-color: green;
    color: white;
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 0;
}

/* Action Buttons */
.action-btn {
    border: none;
    color: white;
    padding: 4px 6px;
    margin-right: 5px;
    cursor: pointer;
    font-size: 12px;
    border-radius: 0;
}

.action-btn.btn-view {
    background-color: #007bff;
}

.action-btn.btn-edit {
    background-color: #28a745;
}

.action-btn.btn-delete {
    background-color: #dc3545;
}

/* Sidebar Widgets */
.sidebar-widgets {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.widget {
    background: white;
    padding: 20px;
    box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
    border-radius: 0;
}

.department-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr); /* exactly 3 columns */
    gap: 1.5rem;
    margin-top: 2rem;
    padding: 0 2rem;
    justify-items: center;
  }
  
  .dept-card {
    width: 100%; /* fills the grid column */
    max-width: 300px;
    background-color: #ffffff;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    cursor: pointer;
  }
  
  .dept-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  }
  
  .dept-card h3 {
    font-size: 1.2rem;
    margin-bottom: 0.5rem;
    color: #333;
  }
  
  .dept-card p {
    font-size: 1rem;
    color: #666;
  }
  .section-header h2 {
    font-size: 28px;
    font-weight: bold;
    color: #003399;
    margin: 0;
}

.dept-section-title {
    font-size: 28px;
    font-weight: bold;
    color: #003399;
    margin-bottom: 1.5rem;
}



        .container {
            display: flex;
            gap: 20px;
            margin: 20px;
        }
        
        .filter-section {
            flex: 1;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .payroll-section {
            flex: 2;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .employee-card {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .employee-card:hover {
            background-color: #f8f9fa;
        }
        
        .employee-card.selected {
            background-color: #e3f2fd;
            border-color: #2196f3;
        }
        
        .employee-info h4 {
            margin: 0 0 5px 0;
            color: #333;
        }
        
        .employee-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        
        .employee-status {
            font-size: 12px;
            font-weight: bold;
            margin-left: 5px;
        }
        
        .rate-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .employee-id {
            font-size: 12px;
            color: #999;
        }
        
        .btn-select {
            background-color: #2196f3;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            float: right;
        }
        
        .btn-select:hover {
            background-color: #1976d2;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background-color: #2196f3;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #1976d2;
        }
        
        .btn-danger {
            background-color: #f44336;
            color: white;
        }
        
        .btn-info {
            background-color: #17a2b8;
            color: white;
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
            margin: 2px;
        }
        
        /* Action buttons container */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .history-table th,
        .history-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 12px;
        }
        
        .history-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        
        .alert {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .payroll-form {
            margin-bottom: 30px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .employee-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .logo-img {
            height: 50px;
            width: auto;
        }
        
        .logo-text {
            font-size: 30px;
            font-weight: bold;
            color: #ffffff;
        }
		    .employee-details-container {
        padding: 20px;
        background: #f9f9f9;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .employee-details-container h3 {
        margin-top: 0;
        color: #333;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
    }
    .employee-details-container p {
        margin: 8px 0;
    }
    .status-pending {
        color: orange;
        font-weight: bold;
    }
    .status-released {
        color: green;
        font-weight: bold;
    }
    .status-n/a {
        color: #666;
        font-weight: bold;
    }
    .error-message {
        color: red;
        padding: 20px;
        background: #ffeeee;
        border-radius: 5px;
    }
    /* --- COMPACT FORM & TABLE STYLES START --- */
.payroll-form {
    margin-bottom: 20px;
    padding: 10px 0 0 0;
}
.payroll-form h3 {
    margin-bottom: 10px;
    font-size: 18px;
}
.payroll-form .form-group,
.payroll-form .form-row {
    margin-bottom: 8px;
}
.payroll-form .form-control {
    padding: 4px 8px;
    font-size: 13px;
    border-radius: 3px;
}
.payroll-form label {
    font-size: 13px;
    margin-bottom: 2px;
}
.payroll-form .form-row {
    display: flex;
    gap: 8px;
}
.payroll-form .form-row .form-group {
    flex: 1;
    margin-bottom: 0;
}
.payroll-form button.btn {
    padding: 6px 14px;
    font-size: 13px;
    border-radius: 3px;
}

.history-section h3 {
    font-size: 18px;
    margin-bottom: 8px;
}

.history-table {
    font-size: 12px;
    margin-top: 10px;
}
.history-table th,
.history-table td {
    padding: 4px 6px;
}
.history-table th {
    font-size: 13px;
}

.employee-list h3 {
    font-size: 15px;
    margin-bottom: 6px;
}
.employee-card {
    padding: 8px;
    margin-bottom: 6px;
    border-radius: 3px;
}
.employee-info h4 {
    font-size: 14px;
    margin-bottom: 2px;
}
.employee-info p, .rate-info, .employee-id {
    font-size: 12px;
}
.employee-status {
    font-size: 11px;
    margin-left: 3px;
}
.btn-select {
    padding: 3px 8px;
    font-size: 12px;
    border-radius: 3px;
}

.container {
    gap: 10px;
    margin: 10px;
}
.filter-section, .payroll-section {
    padding: 10px;
    border-radius: 5px;
}

/* --- COMPACT FORM & TABLE STYLES END --- */

/* --- STICKY TABLE HEADER FOR PAYROLL HISTORY --- */
.history-section .table-responsive {
    max-height: 260px;
    overflow-y: auto;
    border: 1px solid #eee;
    background: #fff;
}
.history-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.history-table th {
    position: sticky;
    top: 0;
    background: #003366;
    color: #fff;
    z-index: 2;
}
/* --- END STICKY TABLE HEADER --- */
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo-section">
            <span class="menu-icon">☰</span>
            <span class="logo-text">Menu</span>
        </div>
        <nav>
            <ul>
                <li data-page="admin-dashboard.php"><i class="icon">🏠</i><span>Dashboard</span></li>
                <li data-page="employees.php"><i class="icon">👤</i><span>Employees</span></li>
                <li data-page="attendance.php"><i class="icon">📋</i><span>Attendance</span></li>
                <li data-page="payroll.php" class="active"><i class="icon">📝</i><span>Payroll Processing</span></li>
                <li data-page="department.php"><i class="icon">🏢</i><span>Department</span></li>
                <li data-page="analytics.php"><i class="icon">📈</i><span>Analytics</span></li>
            </ul>
        </nav>
        <div class="reports">
            <span>Reports</span>
            <ul>
                <li data-page="13th-month.php"><span>13th Month Pay</span></li>
            </ul>
        </div>
        <div class="sidebar-footer">
            <button class="power-btn" onclick="if(confirm('Are you sure you want to log out?')) window.location.href='?logout=1'">⏻<span> Log Out</span></button>
        </div>
    </div>

    <div class="main-content">
        <div class="header-logo">
            <img src="download-removebg-preview (14).png" alt="Logo" class="logo-img"/>
            <span class="logo-text-right">Payroll Processing</span>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="container">
            <div class="filter-section">
                <h3>Filter Employees</h3>
                
                <div class="form-group">
                    <label for="department">Department:</label>
                    <select id="department" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="shift">Shift:</label>
                    <select id="shift" class="form-control">
                        <option value="">All Shifts</option>
                        <?php foreach($shifts as $shift): ?>
                            <option value="<?php echo htmlspecialchars($shift); ?>"><?php echo htmlspecialchars($shift); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="status">Status:</label>
                    <select id="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="Regular">Regular</option>
                        <option value="Probationary">Probationary</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="employeeSearch">Search Employee:</label>
                    <input type="text" id="employeeSearch" class="form-control" placeholder="Search by name or ID">
                </div>
                
                <div class="employee-list">
                    <h3>Employee List</h3>
                    <div id="employeeContainer">
                        <?php foreach($employees as $employee): 
                            $status = !empty($employee['status']) ? $employee['status'] : 'Regular';
                            $status = isset($defaultRates[$status]) ? $status : 'Regular';
                            $dailyRate = $defaultRates[$status]['daily_rate'];
                            $hourlyRate = $defaultRates[$status]['hourly_rate'];
                            $otRate = $defaultRates[$status]['ot_rate'];
                        ?>
                            <div class="employee-card" 
                                 data-id="<?php echo htmlspecialchars($employee['employee_no']); ?>"
                                 data-department="<?php echo htmlspecialchars($employee['department_name']); ?>"
                                 data-shift="<?php echo htmlspecialchars($employee['shift']); ?>"
                                 data-status="<?php echo htmlspecialchars($employee['status']); ?>"
                                 data-daily-rate="<?php echo $dailyRate; ?>"
                                 data-hourly-rate="<?php echo $hourlyRate; ?>"
                                 data-ot-rate="<?php echo $otRate; ?>">
                                <div class="employee-info">
                                    <h4><?php echo htmlspecialchars($employee['employee_name']); ?></h4>
                                    <p>
                                        <?php echo htmlspecialchars($employee['department_name']); ?> 
                                        <?php echo $employee['shift'] ? "- " . htmlspecialchars($employee['shift']) : ""; ?>
                                        <span class="employee-status" style="color: 
                                            <?php echo $employee['status'] == 'Regular' ? '#28a745' : 
                                                  ($employee['status'] == 'Probationary' ? '#ffc107' : '#dc3545'); ?>">
                                            • <?php echo htmlspecialchars($employee['status']); ?>
                                        </span>
                                    </p>
                                    <div class="rate-info">
                                        Daily: ₱<?php echo number_format($dailyRate, 2); ?> | 
                                        Hourly: ₱<?php echo number_format($hourlyRate, 2); ?> | 
                                        OT: ₱<?php echo number_format($otRate, 2); ?>
                                    </div>
                                    <span class="employee-id"><?php echo htmlspecialchars($employee['employee_no']); ?></span>
                                </div>
                                <button class="btn-select">Select</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="payroll-section">
                <div class="payroll-form">
                    <h3>Payroll Calculation</h3>
                    <form method="POST" id="processPayrollForm">
                        <input type="hidden" name="employee_no" id="employeeNo">
                        
                        <div class="form-group">
                            <label for="employeeName">Employee Name:</label>
                            <input type="text" id="employeeName" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="employeeStatus">Employee Status:</label>
                            <input type="text" id="employeeStatus" class="form-control" readonly>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="workingHours">Working Hours:</label>
                                <input type="number" id="workingHours" name="working_hours" class="form-control" min="0" step="0.01" required readonly>
                            </div>
                            <div class="form-group">
                                <label for="daysWorked">Days Worked:</label>
                                <input type="number" id="daysWorked" name="days_worked" class="form-control" min="0" max="7" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="overtimeHours">Overtime Hours:</label>
                            <input type="number" id="overtimeHours" name="overtime_hours" class="form-control" min="0" step="0.01" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="allowances">Allowances (₱):</label>
                            <input type="number" id="allowances" name="allowances" class="form-control" min="0" step="0.01" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="basicSalary">Basic Salary:</label>
                            <input type="text" id="basicSalary" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="overtimePay">Overtime Pay:</label>
                            <input type="text" id="overtimePay" class="form-control" readonly>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sss">SSS (<?php echo $sssRate; ?>%):</label>
                                <input type="text" id="sss" class="form-control" readonly>
                            </div>
                            <div class="form-group">
                                <label for="philhealth">PhilHealth (<?php echo $philhealthRate; ?>%):</label>
                                <input type="text" id="philhealth" class="form-control" readonly>
                            </div>
                            <div class="form-group">
                                <label for="pagibig">Pag-ibig (<?php echo $pagibigRate; ?>%):</label>
                                <input type="text" id="pagibig" class="form-control" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="grossPay">Gross Pay:</label>
                            <input type="text" id="grossPay" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="netSalary">Net Salary:</label>
                            <input type="text" id="netSalary" class="form-control" readonly style="font-weight: bold; font-size: 16px;">
                        </div>
                        
                        <button type="submit" name="process_payroll" class="btn btn-primary">Process Payroll</button>
                    </form>
                </div>
                
                <div class="history-section">
                    <h3>Payroll History</h3>
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Week Period</th>
                                    <th>Days</th>
                                    <th>Hours</th>
                                    <th>OT Hours</th>
                                    <th>Basic Salary</th>
                                    <th>OT Pay</th>
                                    <th>Allowances</th>
                                    <th>Deductions</th>
                                    <th>Net Salary</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($payrollHistory as $record): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['employee_name']); ?></td>
                                        <td><?php echo htmlspecialchars($record['week_period']); ?></td>
                                        <td><?php echo $record['days_worked']; ?></td>
                                        <td><?php echo $record['working_hours']; ?></td>
                                        <td><?php echo $record['overtime_hours']; ?></td>
                                        <td>₱<?php echo number_format($record['basic_salary'], 2); ?></td>
                                        <td>₱<?php echo number_format($record['overtime_pay'], 2); ?></td>
                                        <td>₱<?php echo number_format($record['allowances'], 2); ?></td>
                                        <td>₱<?php echo number_format($record['total_deductions'], 2); ?></td>
                                        <td><strong>₱<?php echo number_format($record['net_salary'], 2); ?></strong></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_employee_history.php?employee_no=<?php echo urlencode($record['employee_no']); ?>&employee_name=<?php echo urlencode($record['employee_name']); ?>" 
                                                   class="btn btn-sm btn-info" title="View Employee History">
                                                    👁️ View
                                                </a>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                                    <button type="submit" name="delete_payroll" class="btn btn-sm btn-danger" 
                                                            onclick="return confirm('Are you sure you want to move this payroll record to trash?')" 
                                                            title="Move to Trash">
                                                        🗑️ Trash
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Trash Section for Deleted Records -->
                <?php if (!empty($deletedPayrollHistory)): ?>
                <div class="history-section" style="margin-top: 30px;">
                    <h3>🗑️ Trash (Deleted Records)</h3>
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Week Period</th>
                                    <th>Days</th>
                                    <th>Hours</th>
                                    <th>OT Hours</th>
                                    <th>Basic Salary</th>
                                    <th>OT Pay</th>
                                    <th>Allowances</th>
                                    <th>Deductions</th>
                                    <th>Net Salary</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($deletedPayrollHistory as $record): ?>
                                    <tr style="opacity: 0.7; background-color: #f8f9fa;">
                                        <td><?php echo htmlspecialchars($record['employee_name']); ?></td>
                                        <td><?php echo htmlspecialchars($record['week_period']); ?></td>
                                        <td><?php echo $record['days_worked']; ?></td>
                                        <td><?php echo $record['working_hours']; ?></td>
                                        <td><?php echo $record['overtime_hours']; ?></td>
                                        <td>₱<?php echo number_format($record['basic_salary'], 2); ?></td>
                                        <td>₱<?php echo number_format($record['overtime_pay'], 2); ?></td>
                                        <td>₱<?php echo number_format($record['allowances'], 2); ?></td>
                                        <td>₱<?php echo number_format($record['total_deductions'], 2); ?></td>
                                        <td><strong>₱<?php echo number_format($record['net_salary'], 2); ?></strong></td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                                    <button type="submit" name="restore_payroll" class="btn btn-sm btn-success" 
                                                            onclick="return confirm('Are you sure you want to restore this payroll record?')" 
                                                            title="Restore Record">
                                                        🔄 Restore
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Employee selection
            document.querySelectorAll('.btn-select').forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.employee-card');
                    document.querySelectorAll('.employee-card').forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    
                    // Fill employee details in form
                    const empNo = card.dataset.id;
                    document.getElementById('employeeNo').value = empNo;
                    document.getElementById('employeeName').value = card.querySelector('h4').textContent;
                    document.getElementById('employeeStatus').value = card.dataset.status;
                    
                    // Reset calculations
                    resetCalculations();

                    // NEW: fetch weekly attendance summary for this employee
                    fetch('get_employee_week_summary.php?employee_no=' + encodeURIComponent(empNo))
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                console.warn('Summary error:', data.error);
                                return;
                            }

                            // Fill Days Worked and Working Hours from attendance
                            const daysWorkedInput   = document.getElementById('daysWorked');
                            const workingHoursInput = document.getElementById('workingHours');
                            const overtimeHoursInput = document.getElementById('overtimeHours');
                            const weekPeriodInput   = document.getElementById('weekPeriod'); // if you still show it

                            if (daysWorkedInput)   daysWorkedInput.value   = data.days_worked ?? 0;
                            if (workingHoursInput) workingHoursInput.value = (data.working_hours ?? 0).toFixed(2);
                            if (overtimeHoursInput) overtimeHoursInput.value = (data.overtime_hours ?? 0).toFixed(2);
                            if (weekPeriodInput && data.week_period) weekPeriodInput.value = data.week_period;

                            // Recalculate payroll preview with new values
                            calculatePayroll();
                        })
                        .catch(err => {
                            console.error('Error fetching week summary:', err);
                        });
                });
            });
            
            // Filter employees
            document.getElementById('department').addEventListener('change', filterEmployees);
            document.getElementById('shift').addEventListener('change', filterEmployees);
            document.getElementById('status').addEventListener('change', filterEmployees);
            document.getElementById('employeeSearch').addEventListener('input', filterEmployees);
            
            // Calculate payroll when values change
            document.getElementById('daysWorked').addEventListener('input', calculatePayroll);
            document.getElementById('workingHours').addEventListener('input', calculatePayroll);
            document.getElementById('overtimeHours').addEventListener('input', calculatePayroll);
            document.getElementById('allowances').addEventListener('input', calculatePayroll);

            const daysWorkedInput   = document.getElementById('daysWorked');
            const workingHoursInput = document.getElementById('workingHours');

            function updateWorkingHours() {
                const days = parseFloat(daysWorkedInput.value) || 0;
                const hours = days * 8; // fixed 8 hours per day
                workingHoursInput.value = hours.toFixed(2);
            }

            if (daysWorkedInput && workingHoursInput) {
                daysWorkedInput.addEventListener('input', updateWorkingHours);
                daysWorkedInput.addEventListener('change', updateWorkingHours);
            }

            // Optional: initialize when form loads
            updateWorkingHours();
        });
        
        function filterEmployees() {
            const department = document.getElementById('department').value;
            const shift = document.getElementById('shift').value;
            const status = document.getElementById('status').value;
            const search = document.getElementById('employeeSearch').value.toLowerCase();
            
            document.querySelectorAll('.employee-card').forEach(card => {
                const cardDept = card.dataset.department;
                const cardShift = card.dataset.shift;
                const cardStatus = card.dataset.status;
                const cardName = card.querySelector('h4').textContent.toLowerCase();
                const cardId = card.querySelector('.employee-id').textContent.toLowerCase();
                
                const deptMatch = !department || cardDept === department;
                const shiftMatch = !shift || cardShift === shift;
                const statusMatch = !status || cardStatus === status;
                const searchMatch = !search || cardName.includes(search) || cardId.includes(search);
                
                if (deptMatch && shiftMatch && statusMatch && searchMatch) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function resetCalculations() {
            document.getElementById('basicSalary').value = '';
            document.getElementById('overtimePay').value = '';
            document.getElementById('sss').value = '';
            document.getElementById('philhealth').value = '';
            document.getElementById('pagibig').value = '';
            document.getElementById('grossPay').value = '';
            document.getElementById('netSalary').value = '';
        }
        
        // Function to get week of month (1-4)
        function getWeekOfMonth(date) {
            const dayOfMonth = date.getDate();
            if (dayOfMonth <= 7) return 1;
            if (dayOfMonth <= 14) return 2;
            if (dayOfMonth <= 21) return 3;
            if (dayOfMonth <= 28) return 4;
            return 5; // Last few days
        }
        
        function calculatePayroll() {
            const daysWorked = parseFloat(document.getElementById('daysWorked').value) || 0;
            const workingHours = parseFloat(document.getElementById('workingHours').value) || 0;
            const overtimeHours = parseFloat(document.getElementById('overtimeHours').value) || 0;
            const allowancesInput = parseFloat(document.getElementById('allowances').value) || 0;
            
            // Get selected employee's rates
            const selectedCard = document.querySelector('.employee-card.selected');
            if (!selectedCard) return;
            
            const dailyRate = parseFloat(selectedCard.dataset.dailyRate);
            const hourlyRate = parseFloat(selectedCard.dataset.hourlyRate);
            const otRate = parseFloat(selectedCard.dataset.otRate);
            
            // Calculate values
            const basicSalary = daysWorked * dailyRate;
            const overtimePay = overtimeHours * otRate;
            
            // Determine which week of the month (based on current date)
            const today = new Date();
            const weekOfMonth = getWeekOfMonth(today);
            
            // Deductions - only on 2nd and 4th week of the month
            let sss = 0;
            let philhealth = 0;
            let pagibig = 0;
            
            if (weekOfMonth == 2 || weekOfMonth == 4) {
                // Calculate monthly basic salary (daily rate * 26 working days per month)
                const monthlyBasic = dailyRate * 26;
                // Calculate monthly deductions, then divide by 2 (for 2 deductions per month)
                sss = (monthlyBasic * (<?php echo $sssRate; ?> / 100)) / 2;
                philhealth = (monthlyBasic * (<?php echo $philhealthRate; ?> / 100)) / 2;
                pagibig = (monthlyBasic * (<?php echo $pagibigRate; ?> / 100)) / 2;
            }
            
            const totalDeductions = sss + philhealth + pagibig;
            
            // Allowance - only 30 pesos on 4th week
            let finalAllowances = 0;
            if (weekOfMonth == 4) {
                // Default allowance is 30 pesos on 4th week
                finalAllowances = 30.00;
                // Override with user input if provided (but minimum 30 on 4th week)
                if (allowancesInput > 0) {
                    finalAllowances = allowancesInput;
                }
            }
            
            const grossPay = basicSalary + overtimePay + finalAllowances;
            const netSalary = grossPay - totalDeductions;
            
            // Update form
            document.getElementById('basicSalary').value = '₱' + basicSalary.toFixed(2);
            document.getElementById('overtimePay').value = '₱' + overtimePay.toFixed(2);
            document.getElementById('sss').value = '₱' + sss.toFixed(2);
            document.getElementById('philhealth').value = '₱' + philhealth.toFixed(2);
            document.getElementById('pagibig').value = '₱' + pagibig.toFixed(2);
            document.getElementById('grossPay').value = '₱' + grossPay.toFixed(2);
            document.getElementById('netSalary').value = '₱' + netSalary.toFixed(2);
        }
        
        // Navigation functionality
        document.querySelectorAll('.sidebar nav li').forEach(item => {
            item.addEventListener('click', function() {
                const page = this.getAttribute('data-page');
                if (page) window.location.href = page;
            });
        });

        document.querySelectorAll('.reports li').forEach(item => {
            item.addEventListener('click', function() {
                const page = this.getAttribute('data-page');
                if (page) window.location.href = page;
            });
        });
    </script>
</body>
</html>