<?php
// Database connection
$host = 'localhost';
$dbname = 'employee_managements';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['error' => 'Database connection failed']));
}

if (!isset($_GET['employee_no'])) {
    die(json_encode(['error' => 'Employee number required']));
}

$employeeNo = $_GET['employee_no'];

try {
    $stmt = $conn->prepare("
        SELECT e.*, t.* 
        FROM employees e
        LEFT JOIN thirteenth_month_pay t ON e.employee_no = t.employee_no
        WHERE e.employee_no = ?
    ");
    $stmt->execute([$employeeNo]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        die(json_encode(['error' => 'Employee not found']));
    }

    // Ensure status has a default value if null
    $status = $employee['status'] ?? 'pending';
    
    $response = [
        'success' => true,
        'data' => [
            'employee_no' => htmlspecialchars($employee['employee_no']),
            'name' => htmlspecialchars($employee['name']),
            'department' => htmlspecialchars($employee['department_name']),
            'date_hired' => htmlspecialchars($employee['date_hired']),
            'basic_pay' => $employee['basic_pay'] ? '₱' . number_format($employee['basic_pay'], 2) : 'N/A',
            'months_worked' => $employee['months_worked'] ?? 'N/A',
            'thirteenth_pay' => $employee['thirteenth_pay'] ? '₱' . number_format($employee['thirteenth_pay'], 2) : 'N/A',
            'status' => $status // Use the ensured status value
        ]
    ];

    header('Content-Type: application/json');
    echo json_encode($response);

} catch(PDOException $e) {
    die(json_encode(['error' => 'Database error']));
}
?>