<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Set content type to JSON
header('Content-Type: application/json');

// Check if required parameters are provided
if (!isset($_GET['employee_no']) || !isset($_GET['start_date']) || !isset($_GET['end_date'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$employeeNo = $_GET['employee_no'];
$startDate = $_GET['start_date'];
$endDate = $_GET['end_date'];

try {
    // First check if employee exists
    $stmt = $conn->prepare("SELECT id FROM employees WHERE employee_no = ?");
    $stmt->execute([$employeeNo]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['error' => 'Employee not found']);
        exit();
    }

    // Get attendance records for the date range
    $stmt = $conn->prepare("
        SELECT 
            a.status,
            a.time_in,
            a.time_out
        FROM attendance a
        WHERE a.employee_no = ? 
        AND a.date BETWEEN ? AND ?
        ORDER BY a.date
    ");
    $stmt->execute([$employeeNo, $startDate, $endDate]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If no records found, return zeros
    if (empty($records)) {
        echo json_encode([
            'days_worked' => 0,
            'working_hours' => 0,
            'overtime_hours' => 0,
            'message' => 'No attendance records found for the selected period'
        ]);
        exit();
    }

    // Initialize counters
    $totalDaysWorked = 0;
    $totalWorkingHours = 0;
    $totalOvertimeHours = 0;

    // Calculate totals
    foreach ($records as $record) {
        // Only count days with "Present" or "Late" status
        if ($record['status'] === 'Present' || $record['status'] === 'Late') {
            $totalDaysWorked++;

            // Calculate working hours if time_in and time_out are available
            if ($record['time_in'] && $record['time_out']) {
                $timeIn = new DateTime($record['time_in']);
                $timeOut = new DateTime($record['time_out']);
                $interval = $timeIn->diff($timeOut);
                
                // Convert to hours
                $hoursWorked = $interval->h + ($interval->i / 60);
                
                // Add to total working hours
                $totalWorkingHours += $hoursWorked;
                
                // Calculate overtime (hours beyond 8)
                if ($hoursWorked > 8) {
                    $totalOvertimeHours += ($hoursWorked - 8);
                }
            }
        }
    }

    // Round to 2 decimal places
    $totalWorkingHours = round($totalWorkingHours, 2);
    $totalOvertimeHours = round($totalOvertimeHours, 2);

    // Return the calculated values
    echo json_encode([
        'days_worked' => $totalDaysWorked,
        'working_hours' => $totalWorkingHours,
        'overtime_hours' => $totalOvertimeHours
    ]);

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'query' => $stmt->queryString,
        'params' => [$employeeId, $startDate, $endDate]
    ]);
    exit();
}
?> 