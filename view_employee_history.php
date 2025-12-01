<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
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
    
    // Check if deleted column exists, if not add it
    try {
        $conn->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deleted TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) {
        // Column might already exist, continue
    }
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// FIXED: Use employee_no instead of employee_id
$employeeNo   = $_GET['employee_no']   ?? '';
$employeeName = $_GET['employee_name'] ?? '';

if (!$employeeNo) {
    die("Employee not specified");
}

// Get employee salary history
$employeeHistory = [];
try {
    // FIXED: Use employee_no in the query instead of employee_id
    $stmt = $conn->prepare("
        SELECT * FROM payroll_records 
        WHERE employee_no = ? AND deleted = 0
        ORDER BY week_period DESC, created_at DESC
    ");
    
    $stmt->execute([$employeeNo]);
    $employeeHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching employee history: " . $e->getMessage();
}

// Calculate totals
$totalBasicSalary = 0;
$totalOvertimePay = 0;
$totalAllowances = 0;
$totalDeductions = 0;
$totalNetSalary = 0;

foreach ($employeeHistory as $record) {
    $totalBasicSalary += $record['basic_salary'];
    $totalOvertimePay += $record['overtime_pay'];
    $totalAllowances += $record['allowances'];
    $totalDeductions += $record['total_deductions'];
    $totalNetSalary += $record['net_salary'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Salary History - <?php echo htmlspecialchars($employeeName); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f8;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #003366;
        }
        .header h1 {
            color: #003366;
            margin: 0;
        }
        .back-btn {
            background: #003366;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }
        .back-btn:hover {
            background: #002244;
        }
        .employee-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .employee-info h3 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .employee-info p {
            margin: 5px 0;
            color: #666;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        .summary-card h4 {
            margin: 0 0 10px 0;
            color: #1976d2;
            font-size: 14px;
        }
        .summary-card .amount {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background: #003366;
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 14px;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .amount-cell {
            text-align: right;
            font-family: monospace;
        }
        .no-records {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Salary History - <?php echo htmlspecialchars($employeeName); ?></h1>
            <a href="payroll.php" class="back-btn">← Back to Payroll</a>
        </div>

        <div class="employee-info">
            <h3>Employee Information</h3>
            <p><strong>Employee No:</strong> <?php echo htmlspecialchars($employeeNo); ?></p>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($employeeName); ?></p>
            <p><strong>Total Records:</strong> <?php echo count($employeeHistory); ?></p>
        </div>

        <?php if (!empty($employeeHistory)): ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Total Basic Salary</h4>
                    <div class="amount">₱<?php echo number_format($totalBasicSalary, 2); ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Overtime Pay</h4>
                    <div class="amount">₱<?php echo number_format($totalOvertimePay, 2); ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Allowances</h4>
                    <div class="amount">₱<?php echo number_format($totalAllowances, 2); ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Deductions</h4>
                    <div class="amount">₱<?php echo number_format($totalDeductions, 2); ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Net Salary</h4>
                    <div class="amount">₱<?php echo number_format($totalNetSalary, 2); ?></div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Week Period</th>
                        <th>Days Worked</th>
                        <th>Working Hours</th>
                        <th>OT Hours</th>
                        <th>Basic Salary</th>
                        <th>OT Pay</th>
                        <th>Allowances</th>
                        <th>Deductions</th>
                        <th>Net Salary</th>
                        <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($employeeHistory as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['week_period']); ?></td>
                            <td><?php echo $record['days_worked']; ?></td>
                            <td><?php echo $record['working_hours']; ?></td>
                            <td><?php echo $record['overtime_hours']; ?></td>
                            <td class="amount-cell">₱<?php echo number_format($record['basic_salary'], 2); ?></td>
                            <td class="amount-cell">₱<?php echo number_format($record['overtime_pay'], 2); ?></td>
                            <td class="amount-cell">₱<?php echo number_format($record['allowances'], 2); ?></td>
                            <td class="amount-cell">₱<?php echo number_format($record['total_deductions'], 2); ?></td>
                            <td class="amount-cell"><strong>₱<?php echo number_format($record['net_salary'], 2); ?></strong></td>
                            <td><?php echo date('M j, Y', strtotime($record['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-records">
                <h3>No salary records found for this employee.</h3>
                <p>This employee has no processed payroll records yet.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>