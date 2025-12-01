<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized access']);
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
    die(json_encode(['error' => 'Connection failed: ' . $e->getMessage()]));
}

if (!isset($_GET['employee_no'])) {
    echo json_encode(['error' => 'Employee number is required']);
    exit();
}

$employeeNo = $_GET['employee_no'];

try {
    // Get current Monday–Sunday pay week
    $today = new DateTime();
    $weekStart = (clone $today)->modify('monday this week');
    $weekEnd = (clone $today)->modify('sunday this week');

    $startDate = $weekStart->format('Y-m-d');
    $endDate = $weekEnd->format('Y-m-d');

    // Get attendance for that period
    $stmt = $conn->prepare("
        SELECT date, time_in, time_out, status
        FROM attendance
        WHERE employee_no = :emp_no
          AND date BETWEEN :start_date AND :end_date
          AND status IN ('Present','Late')
    ");
    $stmt->execute([
        ':emp_no'     => $employeeNo,
        ':start_date' => $startDate,
        ':end_date'   => $endDate
    ]);
    $attRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $daysWorked = 0;
    $totalOtHrs = 0;

    foreach ($attRows as $att) {
        if (!$att['time_in'] || !$att['time_out']) continue;

        $dateObj = new DateTime($att['date']);
        $dow = (int)$dateObj->format('N'); // 1=Mon ... 7=Sun

        $timeIn = strtotime($att['time_in']);
        $timeOut = strtotime($att['time_out']);
        $total = ($timeOut - $timeIn) / 3600;
        $total = max(0, $total - 1); // minus 1h break
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

            if ($normal > 0) {
                $daysWorked++;
            }
            $totalOtHrs += $otFromTime;
        }
    }

    $workingHours = $daysWorked * 8;
    $overtimeHours = $totalOtHrs;

    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'days_worked' => $daysWorked,
        'working_hours' => $workingHours,
        'overtime_hours' => $overtimeHours,
        'week_period' => $startDate . ' to ' . $endDate
    ]);

} catch(PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>