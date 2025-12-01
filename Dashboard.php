<?php

// Calculate attendance stats from database
$days_present = 0;
$hours_worked = 0;
$attendance_rate = 0;

if ($employee_no) {
    // Count days present in current month
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT date) as days_present FROM attendance WHERE employee_no = ? AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) AND status = 'Present'");
    $stmt->bind_param("s", $employee_no);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $attendance_data = $result->fetch_assoc();
        $days_present = $attendance_data['days_present'];
    }

    // Calculate total hours worked in current month
    $stmt = $conn->prepare("SELECT SUM(TIMESTAMPDIFF(HOUR, time_in, COALESCE(time_out, '18:00:00'))) as total_hours FROM attendance WHERE employee_no = ? AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) AND time_in IS NOT NULL AND time_out IS NOT NULL");
    $stmt->bind_param("s", $employee_no);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $hours_data = $result->fetch_assoc();
        $hours_worked = $hours_data['total_hours'] ?? 0;
    }
}

// Get actual working days in the month (unique dates in attendance table for this month)
$working_days_in_month = 0;
$stmt = $conn->prepare("SELECT COUNT(DISTINCT date) as working_days FROM attendance WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())");
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $working_days_in_month = $row['working_days'];
}

// Calculate attendance rate based on actual working days
$attendance_rate = $working_days_in_month > 0 ? round(($days_present / $working_days_in_month) * 100) : 0;

// DEBUG OUTPUT
// Remove or comment out after debugging
echo '<pre style="background:#fffbe6; color:#333; border:1px solid #fbbf24; padding:10px;">';
echo 'EMPLOYEE NO: ' . htmlspecialchars($employee_no) . PHP_EOL;
echo 'Days Present: ' . $days_present . PHP_EOL;
echo 'Hours Worked: ' . $hours_worked . PHP_EOL;
echo 'Working Days in Month: ' . $working_days_in_month . PHP_EOL;
echo 'Attendance Rate: ' . $attendance_rate . PHP_EOL;
echo '</pre>'; 