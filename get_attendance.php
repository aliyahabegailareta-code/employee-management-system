<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
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
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

header('Content-Type: application/json');

try {
    if (isset($_GET['id'])) {
        // Get single attendance record by ID
        $id = $_GET['id'];
        
        $stmt = $conn->prepare("
            SELECT a.*, e.employee_no, e.name, e.department_name, e.status as employee_status 
            FROM attendance a 
            JOIN employees e ON a.employee_no = e.employee_no  -- FIX: Join on employee_no
            WHERE a.id = :id
        ");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($attendance) {
            echo json_encode($attendance);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Attendance record not found']);
        }
        
    } elseif (isset($_GET['date'])) {
        // Get attendance records for a specific date
        $date = $_GET['date'];
        
        $stmt = $conn->prepare("
            SELECT 
                a.id,
                a.date,
                a.time_in,
                a.time_out,
                a.status,
                e.employee_no,
                e.name,
                e.department_name,
                e.status AS employee_status
            FROM employees e
            LEFT JOIN attendance a 
                ON e.employee_no = a.employee_no  -- FIX: Join on employee_no
               AND a.date = :date
            WHERE e.status != 'Terminated'
            ORDER BY e.name
        ");
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($attendance);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'No ID or date parameter provided']);
    }
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>