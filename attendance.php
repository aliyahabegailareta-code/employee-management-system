<?php
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
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_attendance'])) {
        // Mark attendance for selected employees
        $selectedEmployees = $_POST['selected_employees'] ?? [];
        $attendanceDate = $_POST['attendance_date'];
        $status = $_POST['status'] ?? 'Present'; // FIX: Add default value
        $timeIn = $_POST['time_in'] ?? null;
        $timeOut = $_POST['time_out'] ?? null;
        
        // Force status based on time-in:
        if (!empty($timeIn)) {
            $cutoff   = new DateTime('06:45:00');
            $actualIn = new DateTime($timeIn);

            if ($actualIn >= $cutoff) {
                $status = 'Late';
            } else {
                $status = 'Present';
            }
        }
        
        if (!empty($selectedEmployees)) {
            try {
                foreach ($selectedEmployees as $employeeNo) {
                    // FIX: Use employee_no directly (no need to find employee_id)
                    
                    // Check if attendance already exists for this date
                    $stmt = $conn->prepare("
                        SELECT id 
                        FROM attendance 
                        WHERE employee_no = :employee_no AND date = :date
                    ");
                    $stmt->bindParam(':employee_no', $employeeNo);
                    $stmt->bindParam(':date', $attendanceDate);
                    $stmt->execute();

                    if ($stmt->rowCount() > 0) {
                        // Update existing record
                        $stmt = $conn->prepare("
                            UPDATE attendance 
                            SET status = :status, time_in = :time_in, time_out = :time_out 
                            WHERE employee_no = :employee_no AND date = :date
                        ");
                    } else {
                        // Insert new record
                        $stmt = $conn->prepare("
                            INSERT INTO attendance (employee_no, date, status, time_in, time_out) 
                            VALUES (:employee_no, :date, :status, :time_in, :time_out)
                        ");
                    }

                    $stmt->bindParam(':employee_no', $employeeNo);
                    $stmt->bindParam(':date', $attendanceDate);
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':time_in', $timeIn);
                    $stmt->bindParam(':time_out', $timeOut);
                    $stmt->execute();
                }

                header("Location: attendance.php?date=$attendanceDate&success=Attendance marked successfully");
                exit();
            } catch(PDOException $e) {
                $error = "Error marking attendance: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['edit_attendance'])) {
        // Edit attendance record
        $id = $_POST['id'];
        $timeIn = $_POST['time_in'] ?? null;
        $timeOut = $_POST['time_out'] ?? null;
        $status = $_POST['status'] ?? 'Present'; // FIX: Add default value

        // Force status based on time-in:
        if (!empty($timeIn)) {
            $cutoff   = new DateTime('06:45:00');
            $actualIn = new DateTime($timeIn);

            if ($actualIn >= $cutoff) {
                $status = 'Late';
            } else {
                $status = 'Present';
            }
        }
        
        try {
            $stmt = $conn->prepare("UPDATE attendance SET time_in = :time_in, time_out = :time_out, status = :status WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':time_in', $timeIn);
            $stmt->bindParam(':time_out', $timeOut);
            $stmt->bindParam(':status', $status);
            $stmt->execute();
            
            header("Location: attendance.php?success=Attendance record updated successfully");
            exit();
        } catch(PDOException $e) {
            $error = "Error updating attendance: " . $e->getMessage();
        }
    }
}

// Get current date for default display
$currentDate = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $currentDate;

// Get all employees for attendance marking
$employees = [];
try {
    $stmt = $conn->prepare("SELECT id, employee_no, name, department_name FROM employees WHERE status != 'Terminated' ORDER BY name");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching employees: " . $e->getMessage();
}

// Get attendance records for selected date with employee info
$attendance = [];
$presentCount = 0;
$absentCount = 0;
$lateCount = 0;
$leaveCount = 0;

try {
    // Get attendance records for selected date - FIXED QUERY
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
            ON e.employee_no = a.employee_no  -- FIX: Join on employee_no, not id
           AND a.date = :date
        WHERE e.status != 'Terminated'
        ORDER BY e.name
    ");
    $stmt->bindParam(':date', $selectedDate);
    $stmt->execute();
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count attendance statuses
    foreach ($attendance as $record) {
        switch ($record['status']) {
            case 'Present':
                $presentCount++;
                break;
            case 'Absent':
                $absentCount++;
                break;
            case 'Late':
                $lateCount++;
                break;
            case 'On Leave':
                $leaveCount++;
                break;
        }
    }
    
} catch(PDOException $e) {
    $error = "Error fetching attendance: " . $e->getMessage();
}

// Get total employee count
$totalEmployees = count($employees);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Attendance Management</title>
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
    margin-bottom: 20px;
    background-color: white;
    padding: 15px 20px;
    border-radius: 0;
}

.logo-img {
    width: 150px;
    height: auto;
}

.logo-text-right {
    font-size: 28px;
    font-weight: bold;
    color: black;
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



    /* Status styles */
    .status-present { color: #059669; font-weight: bold; }
    .status-absent { color: #dc2626; font-weight: bold; }
    .status-late { color: #d97706; font-weight: bold; }
    .status-onleave { color: #7c3aed; font-weight: bold; }
    .status-notmarked { color: #6b7280; font-style: italic; }
    
    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 9999;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
      animation: fadeIn 0.3s;
    }

    .modal-content {
      background-color: #fefefe;
      margin: 5% auto;
      padding: 20px;
      border: none;
      border-radius: 8px;
      width: 90%;
      max-width: 600px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      animation: slideIn 0.3s;
      position: relative;
    }

    @keyframes fadeIn {
      from {opacity: 0;}
      to {opacity: 1;}
    }

    @keyframes slideIn {
      from {transform: translateY(-50px); opacity: 0;}
      to {transform: translateY(0); opacity: 1;}
    }

    .modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h2 {
      margin: 0;
      font-size: 1.5rem;
      font-weight: 600;
      color: #111827;
    }

    .close {
      color: #9ca3af;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
      line-height: 1;
    }

    .close:hover {
      color: #374151;
    }

    .modal-body {
      padding: 24px;
      max-height: 500px;
      overflow-y: auto;
    }

    /* Form styles */
    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
      color: #374151;
      font-size: 14px;
    }

    .form-group input,
    .form-group select {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
    }

    .form-group input:focus,
    .form-group select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .form-buttons {
      display: flex;
      gap: 12px;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid #e5e7eb;
    }

    .btn-cancel {
      flex: 1;
      padding: 10px 16px;
      border: 1px solid #d1d5db;
      background-color: white;
      color: #374151;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
    }

    .btn-save {
      flex: 1;
      padding: 10px 16px;
      border: none;
      background-color: #3b82f6;
      color: white;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
    }

    .btn-mark {
      background-color: #059669;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
    }

    /* Action buttons */
    .action-buttons {
      display: flex;
      gap: 8px;
    }

    .action-btn {
      padding: 6px 8px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
    }

    .view-btn {
      background-color: #e0f2fe;
      color: #0277bd;
    }

    .edit-btn {
      background-color: #fff3e0;
      color: #f57c00;
    }

    /* Header controls */
    .header-controls {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 15px;
    }

    .date-selector {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-left: 20px;
    }

    .date-selector input {
      padding: 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
    }

    .search-container {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .search-input-container {
      position: relative;
    }

    .search-input {
      padding: 8px 40px 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
      width: 250px;
    }

    .reload-button {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: #6b7280;
      font-size: 16px;
    }

    .search-button {
      background-color: #6b7280;
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-size: 14px;
      cursor: pointer;
    }

    .filter-select {
      padding: 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 14px;
    }

    /* Checkbox styles */
    .checkbox-column {
      width: 40px;
      text-align: center;
    }

    .attendance-checkbox {
      width: 16px;
      height: 16px;
      cursor: pointer;
    }

    /* Alert styles */
    .alert {
      padding: 12px 16px;
      margin-bottom: 16px;
      border-radius: 6px;
      font-size: 14px;
    }

    .alert-success {
      background-color: #d1fae5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }

    .alert-error {
      background-color: #fee2e2;
      color: #991b1b;
      border: 1px solid #fca5a5;
    }

    /* Card boxes */
    .card-boxes {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .card-box {
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      text-align: center;
    }

    .card-box h2 {
      font-size: 2rem;
      margin: 0 0 8px 0;
      font-weight: bold;
    }

    .card-box p {
      margin: 0;
      color: #6b7280;
      font-size: 14px;
    }

    /* Table styles */
    .attendance-container {
      background: white;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
    }

    th, td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #e5e7eb;
    }

    th {
      background-color: #f9fafb;
      font-weight: 600;
      color: #374151;
      font-size: 14px;
    }

    td {
      font-size: 14px;
      color: #111827;
    }

    tr:hover {
      background-color: #f9fafb;
    }

    /* Employee selection list */
    .employee-list {
      max-height: 300px;
      overflow-y: auto;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      padding: 10px;
    }

    .employee-item {
      display: flex;
      align-items: center;
      padding: 8px;
      border-bottom: 1px solid #f3f4f6;
    }

    .employee-item:last-child {
      border-bottom: none;
    }

    .employee-item input {
      margin-right: 10px;
    }

    .employee-info {
      flex: 1;
    }

    .employee-name {
      font-weight: 500;
      color: #111827;
    }

    .employee-details {
      font-size: 12px;
      color: #6b7280;
    }

    /* Detail view styles */
    .detail-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #e5e7eb;
    }

    .detail-label {
      font-weight: 500;
      color: #6b7280;
    }

    .detail-value {
      color: #111827;
      font-weight: 500;
    }

    /* Add styles for employee status */
    .emp-status-active { color: #059669; font-weight: bold; }
    .emp-status-probationary { color: #d97706; font-weight: bold; }
    .emp-status-terminated { color: #dc2626; font-weight: bold; }
    .emp-status-unknown { color: #6b7280; font-style: italic; }
    .btn-unmarked { background-color: #ff9800 !important; color: #fff !important; font-weight: bold !important; }
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
        <li data-page="attendance.php" class="active"><i class="icon">📋</i><span>Attendance</span></li>
        <li data-page="payroll.php"><i class="icon">📝</i><span>Payroll Processing</span></li>
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
      <span class="logo-text-right">Attendance</span>
      <div style="margin-left: auto; display: flex; gap: 10px;">
        <button onclick="showMarkAttendanceModal()" class="btn-mark">Mark Attendance</button>
        <button onclick="generateReport()" style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer;">Generate Report</button>
      </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
      <div class="alert alert-success">
        <?= htmlspecialchars($_GET['success']) ?>
      </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
      <div class="alert alert-error">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <div class="card-boxes">
      <div class="card-box">
        <h2 style="color: #111827;"><?= $totalEmployees ?></h2>
        <p>Total Employees</p>
      </div>
      <div class="card-box">
        <h2 style="color: #059669;"><?= $presentCount ?></h2>
        <p>Present Today</p>
      </div>
      <div class="card-box">
        <h2 style="color: #dc2626;"><?= $absentCount ?></h2>
        <p>Absent Today</p>
      </div>
      <div class="card-box">
        <h2 style="color: #d97706;"><?= $lateCount ?></h2>
        <p>Late Arrivals</p>
      </div>
    </div>

    <div class="attendance-container">
      <div class="header-controls">
        <div style="display: flex; align-items: center;">
          <h2>Daily Attendance</h2>
          <div class="date-selector">
            <label for="attendanceDate">Date:</label>
            <input type="date" id="attendanceDate" value="<?= $selectedDate ?>" onchange="fetchAttendanceByDate(this.value)">
          </div>
        </div>
        
        <div class="search-container">
          <div class="search-input-container">
            <input type="text" id="attendanceSearch" class="search-input" placeholder="Search employees...">
            <button id="attendanceReloadButton" class="reload-button">↻</button>
          </div>
          <button id="attendanceSearchButton" class="search-button">Search</button>
          <select id="statusFilter" class="filter-select">
            <option value="All">Show all</option>
            <option value="Present">Present</option>
            <option value="Late">Late</option>
            <option value="Absent">Absent</option>
            <option value="On Leave">On Leave</option>
            <option value="">Not Marked</option>
          </select>
        </div>
      </div>
      
      <?php if (empty($attendance)): ?>
        <div style="text-align: center; padding: 40px; color: #6b7280;">
          <p style="font-size: 18px; margin-bottom: 8px;">No employees found</p>
          <p style="font-size: 14px;">Please add employees first</p>
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Employee No.</th>
            <th>Employee Name</th>
            <th>Department</th>
            <th>Employee Status</th>
            <th>Date</th>
            <th>Check-In</th>
            <th>Check-Out</th>
            <th>Working Hrs</th>
            <th>OT Hours</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="attendanceBody">
          <?php foreach ($attendance as $record): ?>
          <tr data-status="<?= htmlspecialchars($record['status'] ?? '') ?>" 
              data-employee="<?= htmlspecialchars($record['name']) ?>" 
              data-department="<?= htmlspecialchars($record['department_name']) ?>" 
              data-empno="<?= htmlspecialchars($record['employee_no']) ?>"
              data-empstatus="<?= htmlspecialchars($record['employee_status']) ?>">
            <td><?= htmlspecialchars($record['employee_no']) ?></td>
            <td><?= htmlspecialchars($record['name']) ?></td>
            <td><?= htmlspecialchars($record['department_name']) ?></td>
            <td>
              <?php 
                $empStatus = $record['employee_status'] ?? 'Unknown';
                $empStatusClass = strtolower(str_replace(' ', '-', $empStatus));
              ?>
              <span class="emp-status emp-status-<?= $empStatusClass ?>">
                <?= htmlspecialchars($empStatus) ?>
              </span>
            </td>
            <td><?= date('m/d/Y', strtotime($selectedDate)) ?></td>
            <td><?= $record['time_in'] ? date('h:i A', strtotime($record['time_in'])) : '-' ?></td>
            <td><?= $record['time_out'] ? date('h:i A', strtotime($record['time_out'])) : '-' ?></td>
            <td>
              <?php 
              if ($record['time_in'] && $record['time_out']) {
                  $timeIn  = strtotime($record['time_in']);
                  $timeOut = strtotime($record['time_out']);

                  // Raw hours between IN and OUT
                  $totalHours = ($timeOut - $timeIn) / 3600;

                  // Subtract 1-hour break
                  $totalHours = max(0, $totalHours - 1);

                  // OT starts only after 4:00 PM
                  $shiftEnd   = strtotime('16:00:00');
                  $otHours    = 0;

                  if ($timeOut > $shiftEnd) {
                      $otHours = ($timeOut - $shiftEnd) / 3600;
                  }

                  // Normal working hours (max 8), not counting OT portion
                  $normalHours = max(0, min(8, $totalHours - $otHours));

                  echo number_format($normalHours, 2) . ' hrs';
              } else {
                  echo '0 hrs';
              }
              ?>
            </td>
            <td>
              <?php 
              if ($record['time_in'] && $record['time_out']) {
                  $timeIn  = strtotime($record['time_in']);
                  $timeOut = strtotime($record['time_out']);

                  $totalHours = ($timeOut - $timeIn) / 3600;
                  $totalHours = max(0, $totalHours - 1);

                  $shiftEnd = strtotime('16:00:00');
                  $otHours  = 0;

                  if ($timeOut > $shiftEnd) {
                      $otHours = ($timeOut - $shiftEnd) / 3600;
                  }

                  echo number_format(max(0, $otHours), 2) . ' hrs';
              } else {
                  echo '0 hrs';
              }
              ?>
            </td>
            <td class="status-<?= strtolower(str_replace(' ', '', $record['status'] ?? 'notmarked')) ?>">
              <?= $record['status'] ? htmlspecialchars($record['status']) : 'Not Marked' ?>
            </td>
            <td class="action-buttons">
              <?php if ($record['id']): ?>
                <button class="action-btn view-btn" onclick="viewAttendance(<?= $record['id'] ?>)">👁️</button>
                <button class="action-btn edit-btn" onclick="editAttendance(<?= $record['id'] ?>)">✏️</button>
              <?php else: ?>
                <button class="action-btn edit-btn btn-unmarked" 
                        style="background-color: #ff9800; color: #fff; font-weight: bold;" 
                        onclick="markSingleAttendance('<?= $record['employee_no'] ?>')" 
                        data-employee-id="<?= $record['employee_no'] ?>"
                        data-employee-name="<?= htmlspecialchars($record['name']) ?>">📝 Mark</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Mark Attendance Modal -->
  <div id="markAttendanceModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Mark Attendance</h2>
        <span class="close" onclick="closeMarkModal()">&times;</span>
      </div>
      <div class="modal-body">
        <form method="POST" action="attendance.php">
          <div class="form-group">
            <label for="attendanceDate">Date</label>
            <input type="date" id="markAttendanceDate" name="attendance_date" value="<?= $selectedDate ?>" required>
          </div>
          
          <div class="form-group">
            <label for="attendanceStatus">Status</label>
            <select id="attendanceStatus" name="status" required>
              <option value="Present">Present</option>
              <option value="Late">Late</option>
              <option value="Absent">Absent</option>
              <option value="On Leave">On Leave</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="timeIn">Check-In Time (Optional)</label>
            <input type="time" id="timeIn" name="time_in">
          </div>
          
          <div class="form-group">
            <label for="timeOut">Check-Out Time (Optional)</label>
            <input type="time" id="timeOut" name="time_out">
          </div>
          
          <div class="form-group">
            <label>Select Employees:</label>
            <div style="margin-bottom: 10px;">
              <label style="display: inline-flex; align-items: center; font-weight: normal;">
                <input type="checkbox" id="selectAllEmployees" style="margin-right: 8px;">
                Select All
              </label>
            </div>
            <div class="employee-list">
              <?php foreach ($employees as $employee): ?>
              <div class="employee-item">
                <input type="checkbox" 
                       name="selected_employees[]" 
                       value="<?= $employee['employee_no'] ?>" 
                       class="employee-checkbox"
                       id="employee_<?= $employee['employee_no'] ?>">
                <div class="employee-info">
                  <div class="employee-name"><?= htmlspecialchars($employee['name']) ?></div>
                  <div class="employee-details"><?= htmlspecialchars($employee['employee_no']) ?> - <?= htmlspecialchars($employee['department_name']) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          
          <div class="form-buttons">
            <button type="button" class="btn-cancel" onclick="closeMarkModal()">Cancel</button>
            <button type="submit" class="btn-save" name="mark_attendance">Mark Attendance</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- View Attendance Modal -->
  <div id="viewAttendanceModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Attendance Details</h2>
        <span class="close" onclick="closeViewModal()">&times;</span>
      </div>
      <div class="modal-body">
        <div id="viewAttendanceContent">
          <!-- Content will be populated by JavaScript -->
        </div>
        <div class="form-buttons">
          <button type="button" class="btn-cancel" onclick="closeViewModal()" style="width: 100%;">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Attendance Modal -->
  <div id="editAttendanceModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Edit Attendance</h2>
        <span class="close" onclick="closeEditModal()">&times;</span>
      </div>
      <div class="modal-body">
        <form method="POST" action="attendance.php">
          <input type="hidden" id="editAttendanceId" name="id">
          
          <div class="form-group">
            <label for="editTimeIn">Check-In Time</label>
            <input type="time" id="editTimeIn" name="time_in">
          </div>
          
          <div class="form-group">
            <label for="editTimeOut">Check-Out Time</label>
            <input type="time" id="editTimeOut" name="time_out">
          </div>
          
          <div class="form-group">
            <label for="editStatus">Status</label>
            <select id="editStatus" name="status" required>
              <option value="Present">Present</option>
              <option value="Late">Late</option>
              <option value="Absent">Absent</option>
              <option value="On Leave">On Leave</option>
            </select>
          </div>
          
          <div class="form-buttons">
            <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
            <button type="submit" class="btn-save" name="edit_attendance">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Modal functions
    function showMarkAttendanceModal() {
      const modal = document.getElementById('markAttendanceModal');

      // Get selected date from main calendar
      const mainDateInput = document.getElementById('attendanceDate');
      const modalDateInput = document.getElementById('markAttendanceDate');

      if (mainDateInput && modalDateInput && mainDateInput.value) {
        modalDateInput.value = mainDateInput.value;
      }

      modal.style.display = 'block';
    }

    function closeMarkModal() {
      document.getElementById('markAttendanceModal').style.display = 'none';
    }

    function viewAttendance(id) {
      fetch('get_attendance.php?id=' + id)
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(attendance => {
          if (!attendance || attendance.error) {
            throw new Error(attendance.error || 'Invalid attendance data received');
          }
          const content = `
            <div class="detail-row">
              <span class="detail-label">Employee Number:</span>
              <span class="detail-value">${attendance.employee_no || 'N/A'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Employee Name:</span>
              <span class="detail-value">${attendance.name || 'N/A'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Department:</span>
              <span class="detail-value">${attendance.department_name || 'N/A'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Date:</span>
              <span class="detail-value">${attendance.date ? new Date(attendance.date).toLocaleDateString() : 'N/A'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Check-In Time:</span>
              <span class="detail-value">${attendance.time_in ? new Date('1970-01-01T' + attendance.time_in).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'Not recorded'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Check-Out Time:</span>
              <span class="detail-value">${attendance.time_out ? new Date('1970-01-01T' + attendance.time_out).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'Not recorded'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Status:</span>
              <span class="detail-value status-${(attendance.status || '').toLowerCase().replace(' ', '')}">${attendance.status || 'Not Marked'}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Working Hours:</span>
              <span class="detail-value">${calculateWorkingHours(attendance.time_in, attendance.time_out)}</span>
            </div>
          `;
          document.getElementById('viewAttendanceContent').innerHTML = content;
          document.getElementById('viewAttendanceModal').style.display = 'block';
        })
        .catch(error => {
          document.getElementById('viewAttendanceContent').innerHTML = `<div style='color:red; padding:20px; text-align:center;'>Error loading attendance data: ${error.message}</div>`;
          document.getElementById('viewAttendanceModal').style.display = 'block';
        });
    }

    function editAttendance(id) {
      fetch('get_attendance.php?id=' + id)
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(attendance => {
          if (!attendance || attendance.error) {
            throw new Error(attendance.error || 'Invalid attendance data received');
          }
          document.getElementById('editAttendanceId').value = attendance.id;
          document.getElementById('editTimeIn').value = attendance.time_in || '';
          document.getElementById('editTimeOut').value = attendance.time_out || '';
          document.getElementById('editStatus').value = attendance.status || 'Present';
          document.getElementById('editAttendanceModal').style.display = 'block';
        })
        .catch(error => {
          // Show a simple error modal
          alert('Error loading attendance data: ' + error.message);
        });
    }

    function markSingleAttendance(employeeNo) {
      console.log('Marking attendance for employee No:', employeeNo);
      
      // Show the modal first
      showMarkAttendanceModal();
      
      // Get the button that was clicked
      const button = document.querySelector(`button[data-employee-id="${employeeNo}"]`);
      const employeeName = button.getAttribute('data-employee-name');
      console.log('Employee name:', employeeName);
      
      // Uncheck all employees first
      document.querySelectorAll('.employee-checkbox').forEach(cb => {
        console.log('Unchecking:', cb.value);
        cb.checked = false;
      });
      
      // Find and check the specific employee
      const employeeCheckbox = document.querySelector(`input[type="checkbox"][value="${employeeNo}"]`);
      console.log('Found employee checkbox:', employeeCheckbox);
      
      if (employeeCheckbox) {
        employeeCheckbox.checked = true;
        console.log('Checked employee:', employeeNo);
      } else {
        console.error('Could not find checkbox for employee:', employeeNo);
      }
    }

    function closeViewModal() {
      document.getElementById('viewAttendanceModal').style.display = 'none';
    }

    function closeEditModal() {
      document.getElementById('editAttendanceModal').style.display = 'none';
    }

    function calculateWorkingHours(timeIn, timeOut) {
      if (!timeIn || !timeOut) return '0 hours';

      const start = new Date('1970-01-01T' + timeIn);
      const end   = new Date('1970-01-01T' + timeOut);

      let total = (end - start) / (1000 * 60 * 60); // raw hours
      total = total - 1; // minus 1-hour break
      if (total <= 0) return '0 hours';

      const shiftEnd = new Date('1970-01-01T16:00:00');
      let ot = 0;

      if (end > shiftEnd) {
        ot = (end - shiftEnd) / (1000 * 60 * 60);
      }

      const normal = Math.max(0, Math.min(8, total - ot));
      return normal.toFixed(2) + ' hours';
    }

    function calculateOvertimeHours(timeIn, timeOut) {
      if (!timeIn || !timeOut) return '0 hours';

      const start = new Date('1970-01-01T' + timeIn);
      const end   = new Date('1970-01-01T' + timeOut);

      let total = (end - start) / (1000 * 60 * 60); // raw hours
      total = total - 1; // minus 1-hour break
      if (total <= 0) return '0 hours';

      const shiftEnd = new Date('1970-01-01T16:00:00');
      let ot = 0;

      if (end > shiftEnd) {
        ot = (end - shiftEnd) / (1000 * 60 * 60);
      }

      return Math.max(0, ot).toFixed(2) + ' hours';
    }

    function generateReport() {
      alert('Report generation feature will be implemented.');
    }

    // Select all employees functionality
    document.getElementById('selectAllEmployees').addEventListener('change', function() {
      const checkboxes = document.querySelectorAll('.employee-checkbox');
      checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
      });
    });

    // Search functionality
    document.getElementById('attendanceSearchButton').addEventListener('click', function() {
      const searchTerm = document.getElementById('attendanceSearch').value.toLowerCase();
      const rows = document.querySelectorAll('#attendanceBody tr');
      
      rows.forEach(row => {
        const employeeNo = row.getAttribute('data-empno').toLowerCase();
        const employeeName = row.getAttribute('data-employee').toLowerCase();
        const department = row.getAttribute('data-department').toLowerCase();
        
        if (employeeNo.includes(searchTerm) || employeeName.includes(searchTerm) || department.includes(searchTerm)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });

    // Filter by status
    document.getElementById('statusFilter').addEventListener('change', function() {
      const status = this.value;
      const rows = document.querySelectorAll('#attendanceBody tr');
      
      rows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        if (status === 'All' || rowStatus === status || (status === '' && !rowStatus)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });

    // Reload button
    document.getElementById('attendanceReloadButton').addEventListener('click', function() {
      document.getElementById('attendanceSearch').value = '';
      document.getElementById('statusFilter').value = 'All';
      const rows = document.querySelectorAll('#attendanceBody tr');
      rows.forEach(row => row.style.display = '');
    });

    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
      const modals = ['markAttendanceModal', 'viewAttendanceModal', 'editAttendanceModal'];
      modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
          modal.style.display = 'none';
        }
      });
    });

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

    // Enter key search
    document.getElementById('attendanceSearch').addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        document.getElementById('attendanceSearchButton').click();
      }
    });

    function fetchAttendanceByDate(date) {
      console.log('Fetching attendance for date:', date);
      fetch('get_attendance.php?date=' + date)
        .then(response => {
          console.log('Response status:', response.status);
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          console.log('Received attendance data:', data);
          if (data.error) {
            throw new Error(data.error);
          }
          updateAttendanceTable(data, date);
        })
        .catch(error => {
          console.error('Error fetching attendance:', error);
          alert('Error loading attendance data: ' + error.message);
        });
    }

    function updateAttendanceTable(data, selectedDate) {
      const tbody = document.getElementById('attendanceBody');
      tbody.innerHTML = '';
      
      data.forEach(record => {
        const empStatus = record.employee_status || 'Unknown';
        const empStatusClass = empStatus.toLowerCase().replace(/\s+/g, '-');

        // Use the selected date for display; fall back to record.date if needed
        let displayDateText = '';
        if (selectedDate) {
          const d = new Date(selectedDate);
          displayDateText = d.toLocaleDateString();
        } else if (record.date) {
          const d = new Date(record.date);
          displayDateText = d.toLocaleDateString();
        } else {
          displayDateText = '-';
        }

        const row = document.createElement('tr');
        row.setAttribute('data-status', record.status || '');
        row.setAttribute('data-employee', record.name);
        row.setAttribute('data-department', record.department_name);
        row.setAttribute('data-empno', record.employee_no);
        row.setAttribute('data-empstatus', empStatus);
        row.innerHTML = `
          <td>${record.employee_no}</td>
          <td>${record.name}</td>
          <td>${record.department_name}</td>
          <td><span class="emp-status emp-status-${empStatusClass}">${empStatus}</span></td>
          <td>${displayDateText}</td>
          <td>${record.time_in ? new Date('1970-01-01T' + record.time_in).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '-'}</td>
          <td>${record.time_out ? new Date('1970-01-01T' + record.time_out).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '-'}</td>
          <td>${calculateWorkingHours(record.time_in, record.time_out)}</td>
          <td>
            ${record.time_in && record.time_out ? calculateOvertimeHours(record.time_in, record.time_out) : '0 hrs'}
          </td>
          <td class="status-${(record.status || '').toLowerCase().replace(' ', '')}">${record.status || 'Not Marked'}</td>
          <td class="action-buttons">
            ${record.id ? `
              <button class="action-btn view-btn" onclick="viewAttendance(${record.id})">👁️</button>
              <button class="action-btn edit-btn" onclick="editAttendance(${record.id})">✏️</button>
            ` : `
              <button class="action-btn edit-btn btn-unmarked" 
                      style="background-color: #ff9800; color: #fff; font-weight: bold;" 
                      onclick="markSingleAttendance('${record.employee_no}')" 
                      data-employee-id="${record.employee_no}"
                      data-employee-name="${record.name}">📝 Mark</button>
            `}
          </td>
        `;
        tbody.appendChild(row);
      });
      
      // Update counts
      updateAttendanceCounts(data);
    }

    function updateAttendanceCounts(data) {
      let presentCount = 0;
      let absentCount = 0;
      let lateCount = 0;
      let leaveCount = 0;
      
      data.forEach(record => {
        switch (record.status) {
          case 'Present':
            presentCount++;
            break;
          case 'Absent':
            absentCount++;
            break;
          case 'Late':
            lateCount++;
            break;
          case 'On Leave':
            leaveCount++;
            break;
        }
      });
      
      // Update the count boxes
      document.querySelector('.card-box:nth-child(2) h2').textContent = presentCount;
      document.querySelector('.card-box:nth-child(3) h2').textContent = absentCount;
      document.querySelector('.card-box:nth-child(4) h2').textContent = lateCount;
    }

    // Apply auto-status logic to mark modal
    const timeInInput       = document.getElementById('timeIn');
    const statusSelect      = document.getElementById('attendanceStatus');
    const editTimeInInput   = document.getElementById('editTimeIn');
    const editStatusSelect  = document.getElementById('editStatus');

    function applyAutoStatus(timeStr, selectEl) {
      if (!timeStr || !selectEl) return;

      const [h, m] = timeStr.split(':').map(Number);
      const minutes = h * 60 + m;          // minutes since midnight
      const cutoffMinutes = 6 * 60 + 45;   // 06:45

      if (minutes >= cutoffMinutes) {
        selectEl.value = 'Late';
      } else {
        selectEl.value = 'Present';
      }
    }

    // Mark modal: auto-change + lock status based on time-in
    if (timeInInput && statusSelect) {
      timeInInput.addEventListener('change', function () {
        if (!this.value) {
          statusSelect.disabled = false;
          return;
        }
        applyAutoStatus(this.value, statusSelect);
        statusSelect.disabled = true;  // no manual choice
      });
    }

    // Edit modal: same behavior
    if (editTimeInInput && editStatusSelect) {
      editTimeInInput.addEventListener('change', function () {
        if (!this.value) {
          editStatusSelect.disabled = false;
          return;
        }
        applyAutoStatus(this.value, editStatusSelect);
        editStatusSelect.disabled = true;
      });
    }
  </script>
</body>
</html>