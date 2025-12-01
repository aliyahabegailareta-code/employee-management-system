<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    die("Unauthorized access");
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
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$payrollId = $_GET['id'] ?? 0;

try {
    $stmt = $conn->prepare("
        SELECT pr.*, ph.approved_at, ph.approved_by
        FROM payroll_records pr
        LEFT JOIN payroll_history ph ON pr.id = ph.payroll_record_id
        WHERE pr.id = ?
    ");
    $stmt->execute([$payrollId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        die("Payroll record not found");
    }
    
    // Generate payslip HTML
    $html = '
    <div class="payslip">
        <div class="payslip-header">
            <h2>YAZAKI TORRES MANUFACTURING INCORPORATED</h2>
            <h3>Weekly Payslip</h3>
        </div>
        
        <div class="payslip-row">
            <span>Employee Name:</span>
            <span>' . htmlspecialchars($record['employee_name']) . '</span>
        </div>
        <div class="payslip-row">
            <span>Employee No:</span>
            <span>' . htmlspecialchars($record['employee_no']) . '</span>
        </div>
        <div class="payslip-row">
            <span>Pay Week:</span>
            <span>' . htmlspecialchars($record['week_period']) . '</span>
        </div>
        
        <div class="payslip-row">
            <span>Basic Salary:</span>
            <span>₱' . number_format($record['basic_salary'], 2) . '</span>
        </div>
        <div class="payslip-row">
            <span>Overtime Pay:</span>
            <span>₱' . number_format($record['overtime_pay'], 2) . '</span>
        </div>
        <div class="payslip-row">
            <span>Allowances:</span>
            <span>₱' . number_format($record['allowances'], 2) . '</span>
        </div>
        
        <div class="payslip-row">
            <span>Gross Pay:</span>
            <span>₱' . number_format($record['gross_pay'], 2) . '</span>
        </div>
        <div class="payslip-row">
            <span>Pag-ibig:</span>
            <span>₱' . number_format($record['pagibig'], 2) . '</span>
        </div>
        <div class="payslip-row">
            <span>SSS:</span>
            <span>₱' . number_format($record['sss'], 2) . '</span>
        </div>
        <div class="payslip-row">
            <span>PhilHealth:</span>
            <span>₱' . number_format($record['philhealth'], 2) . '</span>
        </div>
        <div class="payslip-row total">
            <span>Net Pay:</span>
            <span>₱' . number_format($record['net_salary'], 2) . '</span>
        </div>
        
        <div class="payslip-row">
            <span>Generated on:</span>
            <span>' . date('M d, Y', strtotime($record['approved_at'] ?: 'now')) . '</span>
        </div>
    </div>
    ';
    
    echo $html;
    
} catch(PDOException $e) {
    die("Error fetching payroll record: " . $e->getMessage());
}
?>