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

// Constants
define('BASIC_PAY', 479);
define('REGULARIZATION_PERIOD', 6); // months
define('SALARY_INCREASE_PERIOD', 12); // months

// Get all employees with their 13th month pay data
$stmt = $conn->query("
    SELECT 
        e.*,
        t.status,
        t.thirteenth_pay,
        t.months_worked,
        -- total days worked based on payroll_records
        (SELECT SUM(pr.days_worked)
           FROM payroll_records pr
          WHERE pr.employee_no = e.employee_no
        ) AS total_days_worked,
        -- latest net salary based on last payroll record
        (SELECT pr.net_salary
           FROM payroll_records pr
          WHERE pr.employee_no = e.employee_no
          ORDER BY pr.created_at DESC
          LIMIT 1
        ) AS latest_net_salary
    FROM employees e
    LEFT JOIN thirteenth_month_pay t 
      ON e.employee_no = t.employee_no
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary counts
$totalCount = count($employees);
$regularCount = 0;
$probationaryCount = 0;
$releasedCount = 0;
$pendingCount = 0;
$totalAmount = 0;

foreach ($employees as $employee) {
    $hireDate = new DateTime($employee['date_hired']);
    $today = new DateTime();
    $interval = $hireDate->diff($today);
    $monthsDiff = $interval->y * 12 + $interval->m;
    
    if ($monthsDiff >= REGULARIZATION_PERIOD) {
        $regularCount++;
    } else {
        $probationaryCount++;
    }
    
    if ($employee['status'] === 'Approved') {
        $releasedCount++;
        $totalAmount += $employee['thirteenth_pay'] ?? 0;
    } else {
        $pendingCount++;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['calculate'])) {
        $employeeNo = $_POST['employee_no'];
        $basicPay = $_POST['basic_pay'];
        $monthsWorked = $_POST['months_worked'];
        $thirteenthPay = ($basicPay / 12) * $monthsWorked;
        
        // Insert or update record
        $stmt = $conn->prepare("
            INSERT INTO thirteenth_month_pay (employee_no, basic_pay, months_worked, thirteenth_pay, status)
            VALUES (?, ?, ?, ?, 'Pending')
            ON DUPLICATE KEY UPDATE 
            basic_pay = VALUES(basic_pay),
            months_worked = VALUES(months_worked),
            thirteenth_pay = VALUES(thirteenth_pay),
            status = VALUES(status)
        ");
        $stmt->execute([$employeeNo, $basicPay, $monthsWorked, $thirteenthPay]);
        
        $_SESSION['success'] = "13th month pay calculated successfully!";
        header("Location: 13th-month.php");
        exit();
    }
    elseif (isset($_POST['release'])) {
        // Verify admin password
        $password = $_POST['password'] ?? '';
        
        // Check admin password from database
        $stmt = $conn->prepare("SELECT * FROM admin_users WHERE username = 'admin' AND password = ?");
        $stmt->execute([$password]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            $_SESSION['error'] = "Incorrect admin password!";
            header("Location: 13th-month.php");
            exit();
        }
        
        if (isset($_POST['employee_no'])) {
            // Single release
            $employeeNo = $_POST['employee_no'];
            
            // Get employee data
            $stmt = $conn->prepare("
                SELECT e.*, 
                       (SELECT SUM(days_worked) FROM payroll_records WHERE employee_no = e.employee_no) as total_days_worked,
                       (SELECT SUM(net_salary) FROM payroll_records WHERE employee_no = e.employee_no) as latest_net_salary
                FROM employees e 
                WHERE e.employee_no = ?
            ");
            $stmt->execute([$employeeNo]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate values
            $totalDaysWorked = floatval($employee['total_days_worked'] ?? 0);
            $monthsWorked = floor($totalDaysWorked / 24);
            $basicPay = ($totalDaysWorked >= 24) ? ($employee['latest_net_salary'] ?? 0) : 0;
            $thirteenthPay = ($totalDaysWorked >= 24) ? ($basicPay / 12) * $monthsWorked : 0;
            
            // Insert or update record in thirteenth_month_pay table
            $stmt = $conn->prepare("
                INSERT INTO thirteenth_month_pay 
                (employee_no, year, months_worked, basic_pay, thirteenth_pay, status, created_at)
                VALUES (?, YEAR(CURRENT_DATE), ?, ?, ?, 'Approved', CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                months_worked = VALUES(months_worked),
                basic_pay = VALUES(basic_pay),
                thirteenth_pay = VALUES(thirteenth_pay),
                status = VALUES(status),
                created_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $employeeNo,
                $monthsWorked,
                $basicPay,
                $thirteenthPay
            ]);
            
            $_SESSION['success'] = "13th month pay released successfully!";
        }
        elseif (isset($_POST['selected_employees'])) {
            // Batch release
            $selectedEmployees = json_decode($_POST['selected_employees']);
            
            foreach ($selectedEmployees as $employeeNo) {
                // Get employee data
                $stmt = $conn->prepare("
                    SELECT e.*, 
                           (SELECT SUM(days_worked) FROM payroll_records WHERE employee_no = e.employee_no) as total_days_worked,
                           (SELECT SUM(net_salary) FROM payroll_records WHERE employee_no = e.employee_no) as latest_net_salary
                    FROM employees e 
                    WHERE e.employee_no = ?
                ");
                $stmt->execute([$employeeNo]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Calculate values
                $totalDaysWorked = floatval($employee['total_days_worked'] ?? 0);
                $monthsWorked = floor($totalDaysWorked / 24);
                $basicPay = ($totalDaysWorked >= 24) ? ($employee['latest_net_salary'] ?? 0) : 0;
                $thirteenthPay = ($totalDaysWorked >= 24) ? ($basicPay / 12) * $monthsWorked : 0;
                
                // Insert or update record in thirteenth_month_pay table
                $stmt = $conn->prepare("
                    INSERT INTO thirteenth_month_pay 
                    (employee_no, year, months_worked, basic_pay, thirteenth_pay, status, created_at)
                    VALUES (?, YEAR(CURRENT_DATE), ?, ?, ?, 'Approved', CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE 
                    months_worked = VALUES(months_worked),
                    basic_pay = VALUES(basic_pay),
                    thirteenth_pay = VALUES(thirteenth_pay),
                    status = VALUES(status),
                    created_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    $employeeNo,
                    $monthsWorked,
                    $basicPay,
                    $thirteenthPay
                ]);
            }
            
            $_SESSION['success'] = "Batch release completed successfully!";
        }
        
        header("Location: 13th-month.php");
        exit();
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>13th Month Pay</title>
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
    z-index: 1000;
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
    border-radius: 0;
}

nav ul li:hover,
.reports ul li:hover {
    background-color: #1a1a80;
    border-left: 5px solid #ccc;
}

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
    gap: 10px;
    margin-bottom: 20px;
}

.logo-img {
    width: 150px;
    height: auto;
}

.logo-text {
    font-size: 30px;
    font-weight: bold;
    color: #ffffff;
}

.logo-text-right {
    font-size: 70px;
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



    /* All your existing CSS styles here */
    .card-boxes {
      display: flex;
      justify-content: space-around;
      margin: 20px 0;
    }
    .card-boxes {
      display: flex;
      justify-content: space-around;
      margin: 20px 0;
    }
    .card-box {
      background: white;
      border-radius: 5px;
      box-shadow: 2px 2px 5px #ccc;
      width: 200px;
      text-align: center;
      padding: 20px;
    }
    .card-box h2 {
      margin: 0;
      font-size: 24px;
    }
    .card-box p {
      margin: 5px 0;
      font-size: 16px;
    }
    .thirteenth-container {
      background: #ffffff;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 2px 2px 10px #ffffff;
      margin: 0 20px 20px;
    }
    .thirteenth-container h2 {
      color: #001858;
      font-weight: bold;
      margin: 0;
      padding: 10px 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: white;
    }
    th, td {
      border: 1px solid #999;
      padding: 10px;
      text-align: center;
    }
    th {
      background: #d3d3d3;
    }
    .status-pending { color: orange; font-weight: bold; }
    .status-released { color: green; font-weight: bold; }
    .status-processing { color: blue; font-weight: bold; }
    .action-btn {
      padding: 5px 8px;
      margin: 0 2px;
      border: none;
      cursor: pointer;
      border-radius: 4px;
      display: inline-block;
    }
    .btn-calculate { background: #007bff; color: white; }
    .btn-release { background: #28a745; color: white; }
    .btn-view { background: #17a2b8; color: white; }
    
    /* Search container styles */
    .search-container {
      display: flex;
      align-items: center;
      margin-left: auto;
      gap: 8px;
    }
    
    .search-input-container {
      position: relative;
      display: flex;
      border: 1px solid #ccc;
      border-radius: 4px;
      overflow: hidden;
    }
    
    .search-input {
      padding: 10px 15px;
      padding-right: 40px;
      border: none;
      font-size: 16px;
      width: 250px;
      outline: none;
    }
    
    .reload-button {
      position: absolute;
      right: 0;
      top: 0;
      height: 100%;
      width: 40px;
      background-color: #e6e6e6;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #666;
      font-size: 18px;
    }
    
    .reload-button:hover {
      background-color: #d9d9d9;
    }
    
    .search-button {
      padding: 10px 20px;
      background-color: #e6e6e6;
      color: #000;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 16px;
    }
    
    .search-button:hover {
      background-color: #d9d9d9;
    }
    
    .filter-select {
      margin-left: 15px;
      padding: 8px 12px;
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
    }
    
    /* Header controls container */
    .header-controls {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }
    
    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
    }
    
    .modal-content {
      background-color: white;
      margin: 5% auto;
      padding: 20px;
      border-radius: 5px;
      width: 50%;
      max-width: 600px;
      max-height: 80vh;
      overflow-y: auto;
      box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    
    .close:hover {
      color: black;
    }
    
    .form-group {
      margin-bottom: 15px;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: bold;
    }
    
    .form-group input, .form-group select {
      width: 100%;
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
    }
    
    .form-buttons {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 20px;
      position: sticky;
      bottom: 0;
      background-color: white;
      padding: 10px 0;
    }
    
    .btn-cancel {
      padding: 8px 16px;
      background-color: #6c757d;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    
    .btn-save {
      padding: 8px 16px;
      background-color: #28a745;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    
    .employee-details p {
      margin: 8px 0;
      padding: 8px;
      background-color: #f8f9fa;
      border-radius: 4px;
    }
    
    .employee-details strong {
      display: inline-block;
      width: 120px;
      font-weight: bold;
    }
    
    .action-buttons {
      display: flex;
      justify-content: center;
      gap: 5px;
    }

    .batch-actions {
      margin-bottom: 15px;
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .batch-btn {
      padding: 8px 15px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;
    }

    .btn-primary { background: #007bff; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-warning { background: #ffc107; color: black; }

    .checkbox-column {
      width: 50px;
      text-align: center;
    }

    .employee-checkbox {
      transform: scale(1.2);
    }

    .main-content {
      z-index: 1;
    }
	
    .employee-details-container {
      padding: 20px;
    }

    .employee-details-container h3 {
      color: #003366;
      border-bottom: 2px solid #003366;
      padding-bottom: 10px;
      margin-top: 20px;
    }

    .employee-info, .pay-details {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin: 10px 0;
    }

    .employee-info p, .pay-details p {
      margin: 10px 0;
      padding: 8px;
      border-bottom: 1px solid #eee;
    }

    .employee-info p:last-child, .pay-details p:last-child {
      border-bottom: none;
    }

    .employee-info strong, .pay-details strong {
      display: inline-block;
      width: 150px;
      color: #003366;
    }

    .error-message {
      color: #dc3545;
      padding: 20px;
      text-align: center;
    }

    .error-message h3 {
      color: #dc3545;
      margin-bottom: 10px;
    }
	
	
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
        <li data-page="payroll.php"><i class="icon">📝</i><span>Payroll Processing</span></li>
        <li data-page="department.php"><i class="icon">🏢</i><span>Department</span></li>
        <li data-page="analytics.php"><i class="icon">📈</i><span>Analytics</span></li>
      </ul>
    </nav>
    <div class="reports">
      <span>Reports</span>
      <ul>
        <li class="active" data-page="13th-month.php"><i class="icon">💰</i><span>13th Month Pay</span></li>
      </ul>
    </div>
    <div class="sidebar-footer">
      <button class="power-btn" onclick="if(confirm('Are you sure you want to log out?')) window.location.href='?logout=1'">⏻<span> Log Out</span></button>
    </div>
  </div>

  <div class="main-content">
    <div class="header-logo" style="display: flex; align-items: center; gap: 15px;">
      <img src="download-removebg-preview (14).png" alt="Logo" class="logo-img" style="height: auto; width: 150px;"/>
      <span class="logo-text" style="font-size: 28px;">13th Month Pay</span>
      <span class="logo-text-right" style="font-size: 24px; font-weight: bold; color: black;">13th Month Pay</span>
    </div>

    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <div class="card-boxes">
      <div class="card-box">
        <h2 style="color: #007bff;"><?php echo $totalCount; ?></h2>
        <p>Total Employees</p>
      </div>
      <div class="card-box">
        <h2 style="color: green;">₱<?php echo number_format($totalAmount, 2); ?></h2>
        <p>Total 13th Month Pay</p>
      </div>
      <div class="card-box">
        <h2 style="color: green;"><?php echo $releasedCount; ?></h2>
        <p>Released</p>
      </div>
      <div class="card-box">
        <h2 style="color: orange;"><?php echo $pendingCount; ?></h2>
        <p>Pending</p>
      </div>
    </div>

    <div class="thirteenth-container">
      <div class="header-controls">
        <div style="display: flex; align-items: center;">
          <h2>13th Month Pay Management</h2>
        </div>
        
        <div class="search-container">
          <div class="search-input-container">
            <input type="text" id="thirteenthSearch" class="search-input" placeholder="Search employees...">
            <button id="thirteenthReloadButton" class="reload-button">↻</button>
          </div>
          <button id="thirteenthSearchButton" class="search-button">Search</button>
          <select id="statusFilter" class="filter-select">
            <option value="All">Show all</option>
            <option value="pending">Pending</option>
            <option value="released">Released</option>
          </select>
        </div>
      </div>

      <div class="batch-actions">
        <button class="batch-btn btn-success" id="releaseSelectedBtn">Release Selected</button>
        <button class="batch-btn btn-primary" id="selectAllBtn">Select All</button>
        <button class="batch-btn btn-warning" id="exportBtn">Export to Excel</button>
      </div>
      
      <table>
        <thead>
          <tr>
            <th class="checkbox-column">
              <input type="checkbox" id="selectAllCheckbox">
            </th>
            <th>Employee No.</th>
            <th>Employee Name</th>
            <th>Department</th>
            <th>Basic Pay</th>
            <th>Months Worked</th>
            <th>13th Month Pay</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="thirteenthBody">
          <?php foreach ($employees as $employee): ?>
            <?php
            $hireDate = new DateTime($employee['date_hired']);
            $today = new DateTime();
            $interval = $hireDate->diff($today);
            $monthsDiff = $interval->y * 12 + $interval->m;
            
            $status = $employee['status'] ?? 'pending';
            $statusClass = '';
            if ($status === 'pending') $statusClass = 'status-pending';
            elseif ($status === 'released') $statusClass = 'status-released';
            
            // Get total days worked and ensure it's a number
            $totalDaysWorked = floatval($employee['total_days_worked'] ?? 0);
            
            // Calculate months worked based on total days (24 days = 1 month)
            $monthsWorked = floor($totalDaysWorked / 24);
            
            // Calculate 13th month pay using the formula: total basic salary/12 * months worked
            $basicPay = ($totalDaysWorked >= 24) ? ($employee['latest_net_salary'] ?? 0) : 0;
            $thirteenthPay = ($totalDaysWorked >= 24) ? ($basicPay / 12) * $monthsWorked : 0;
            
            // Debug output
            error_log("Employee: " . $employee['employee_no'] . ", Days Worked: " . $totalDaysWorked . 
                     ", Months Worked: " . $monthsWorked . 
                     ", Basic Pay: " . $basicPay . 
                     ", 13th Month Pay: " . $thirteenthPay);
            ?>
            <tr data-status="<?php echo $status; ?>" data-employee-no="<?php echo $employee['employee_no']; ?>">
              <td class="checkbox-column">
                <input type="checkbox" class="employee-checkbox" value="<?php echo $employee['employee_no']; ?>">
              </td>
              <td><?php echo htmlspecialchars($employee['employee_no']); ?></td>
              <td><?php echo htmlspecialchars($employee['name']); ?></td>
              <td><?php echo htmlspecialchars($employee['department_name']); ?></td>
              <td><?php echo $basicPay > 0 ? '₱' . number_format($basicPay, 2) : 'N/A'; ?></td>
              <td><?php echo $monthsWorked > 0 ? $monthsWorked : 'N/A'; ?></td>
              <td><?php echo $thirteenthPay > 0 ? '₱' . number_format($thirteenthPay, 2) : 'N/A'; ?></td>
              <td class="<?php echo $statusClass; ?>"><?php echo ucfirst($status); ?></td>
              <td class="action-buttons">
                <button class="action-btn btn-view" onclick="viewThirteenthDetails('<?php echo $employee['employee_no']; ?>')">👁️</button>
                <?php if ($status === 'pending'): ?>
                  <button class="action-btn btn-release" onclick="releaseThirteenth('<?php echo $employee['employee_no']; ?>', '<?php echo htmlspecialchars($employee['name']); ?>')">💰</button>
                <?php else: ?>
                  <button class="action-btn" style="background: #dc3545; color: white;" onclick="undoRelease('<?php echo $employee['employee_no']; ?>', '<?php echo htmlspecialchars($employee['name']); ?>')">↩️</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- View 13th Month Pay Modal -->
  <div id="viewThirteenthModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>13th Month Pay Details</h2>
      <div class="employee-details" id="viewEmployeeDetails">
        <!-- Content will be loaded via AJAX -->
      </div>
      <div class="form-buttons">
        <button type="button" class="btn-cancel" id="closeViewThirteenth">Close</button>
      </div>
    </div>
  </div>

  <!-- Calculate 13th Month Pay Modal -->
  <div id="calculateThirteenthModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Calculate 13th Month Pay</h2>
      <form id="calculateThirteenthForm" method="POST">
        <input type="hidden" name="calculate" value="1">
        <input type="hidden" name="employee_no" id="calcEmployeeNo">
        <div class="form-group">
          <label for="calcEmployeeName">Employee Name</label>
          <input type="text" id="calcEmployeeName" readonly>
        </div>
        <div class="form-group">
          <label for="calcDepartment">Department</label>
          <input type="text" id="calcDepartment" readonly>
        </div>
        <div class="form-group">
          <label for="calcBasicPay">Basic Monthly Pay (₱)</label>
          <input type="number" id="calcBasicPay" name="basic_pay" min="0" step="0.01" required>
        </div>
        <div class="form-group">
          <label for="calcMonthsWorked">Months Worked</label>
          <input type="number" id="calcMonthsWorked" name="months_worked" min="1" max="12" step="1" required>
        </div>
        <div class="form-group">
          <label for="calcThirteenthPay">13th Month Pay (₱)</label>
          <input type="text" id="calcThirteenthPay" readonly>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn-cancel" id="cancelCalculateThirteenth">Cancel</button>
          <button type="submit" class="btn-save">Calculate & Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Release Confirmation Modal -->
  <div id="releaseModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Confirm Release</h2>
      <div id="releaseDetails">
        <!-- Release details will be populated here -->
      </div>
      <form id="releaseForm" method="POST">
        <input type="hidden" name="release" value="1">
        <input type="hidden" name="employee_no" id="releaseEmployeeNo">
        <div class="form-group">
          <label for="adminPassword">Admin Password:</label>
          <input type="password" name="password" id="adminPassword" required>
          <p id="passwordError" style="color: red; display: none;">Incorrect password. Please try again.</p>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn-cancel" id="cancelRelease">Cancel</button>
          <button type="submit" class="btn-save">Confirm Release</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Batch Release Modal -->
  <div id="batchReleaseModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Confirm Batch Release</h2>
      <div id="batchReleaseDetails">
        <!-- Batch release details will be populated here -->
      </div>
      <form id="batchReleaseForm" method="POST">
        <input type="hidden" name="release" value="1">
        <input type="hidden" name="selected_employees" id="selectedEmployeesInput">
        <div class="form-group">
          <label for="batchAdminPassword">Admin Password:</label>
          <input type="password" name="password" id="batchAdminPassword" required>
          <p id="batchPasswordError" style="color: red; display: none;">Incorrect password. Please try again.</p>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn-cancel" id="cancelBatchRelease">Cancel</button>
          <button type="submit" class="btn-save">Confirm Release</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Add this at the beginning of your script section
    document.addEventListener('DOMContentLoaded', function() {
        // Handle sidebar navigation
        document.querySelectorAll('.sidebar li[data-page]').forEach(item => {
            item.addEventListener('click', function() {
                const page = this.getAttribute('data-page');
                if (page) {
                    window.location.href = page;
                }
            });
        });
    });

    // Modal functionality
    const viewThirteenthModal = document.getElementById('viewThirteenthModal');
    const calculateThirteenthModal = document.getElementById('calculateThirteenthModal');
    const releaseModal = document.getElementById('releaseModal');
    const batchReleaseModal = document.getElementById('batchReleaseModal');
    
    // Close modals when clicking the X button
    document.querySelectorAll('.modal .close').forEach(closeBtn => {
      closeBtn.addEventListener('click', function() {
        this.closest('.modal').style.display = 'none';
      });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
      }
    });
    
    // Close buttons for each modal
    document.getElementById('closeViewThirteenth').addEventListener('click', function() {
      viewThirteenthModal.style.display = 'none';
    });
    
    document.getElementById('cancelCalculateThirteenth').addEventListener('click', function() {
      calculateThirteenthModal.style.display = 'none';
    });
    
    document.getElementById('cancelRelease').addEventListener('click', function() {
      releaseModal.style.display = 'none';
    });
    
    document.getElementById('cancelBatchRelease').addEventListener('click', function() {
      batchReleaseModal.style.display = 'none';
    });
    
    // View 13th month pay details
    function viewThirteenthDetails(employeeNo) {
        fetch(`get_thirteenth_details.php?employee_no=${employeeNo}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // Get the row data from the table
                const row = document.querySelector(`tr[data-employee-no="${employeeNo}"]`);
                const basicPay = row.querySelector('td:nth-child(5)').textContent;
                const monthsWorked = row.querySelector('td:nth-child(6)').textContent;
                const thirteenthPay = row.querySelector('td:nth-child(7)').textContent;
                const status = row.querySelector('td:nth-child(8)').textContent;
                const statusClass = row.querySelector('td:nth-child(8)').className;
                
                let html = `
                    <div class="employee-details-container">
                        <h3>Employee Information</h3>
                        <div class="employee-info">
                            <p><strong>Employee No:</strong> ${data.data.employee_no}</p>
                            <p><strong>Name:</strong> ${data.data.name}</p>
                            <p><strong>Department:</strong> ${data.data.department}</p>
                            <p><strong>Date Hired:</strong> ${data.data.date_hired}</p>
                            <p><strong>Position:</strong> ${data.data.position || 'N/A'}</p>
                            <p><strong>Contact:</strong> ${data.data.contact || 'N/A'}</p>
                            <p><strong>Email:</strong> ${data.data.email || 'N/A'}</p>
                            <p><strong>Address:</strong> ${data.data.address || 'N/A'}</p>
                            <p><strong>Birthday:</strong> ${data.data.birthday || 'N/A'}</p>
                            <p><strong>Gender:</strong> ${data.data.gender || 'N/A'}</p>
                            <p><strong>Civil Status:</strong> ${data.data.civil_status || 'N/A'}</p>
                            <p><strong>SSS No:</strong> ${data.data.sss_no || 'N/A'}</p>
                            <p><strong>PhilHealth No:</strong> ${data.data.philhealth_no || 'N/A'}</p>
                            <p><strong>Pag-IBIG No:</strong> ${data.data.pagibig_no || 'N/A'}</p>
                            <p><strong>Tax ID:</strong> ${data.data.tax_id || 'N/A'}</p>
                        </div>
                        
                        <h3>13th Month Pay Details</h3>
                        <div class="pay-details">
                            <p><strong>Basic Pay:</strong> ${basicPay}</p>
                            <p><strong>Months Worked:</strong> ${monthsWorked}</p>
                            <p><strong>13th Month Pay:</strong> ${thirteenthPay}</p>
                            <p><strong>Status:</strong> <span class="${statusClass}">${status}</span></p>
                            <p><strong>Last Updated:</strong> ${data.data.created_at || 'N/A'}</p>
                        </div>
                    </div>
                `;
                
                document.getElementById('viewEmployeeDetails').innerHTML = html;
                viewThirteenthModal.style.display = 'block';
            })
            .catch(error => {
                document.getElementById('viewEmployeeDetails').innerHTML = `
                    <div class="error-message">
                        <h3>Error Loading Details</h3>
                        <p>${error.message}</p>
                        <p>Please try again or contact support.</p>
                    </div>
                `;
                viewThirteenthModal.style.display = 'block';
            });
    }
    // Calculate 13th month pay
    function calculateThirteenth(employeeNo, employeeName, department) {
      document.getElementById('calcEmployeeNo').value = employeeNo;
      document.getElementById('calcEmployeeName').value = employeeName;
      document.getElementById('calcDepartment').value = department;
      
      // Calculate button event
      document.getElementById('calcBasicPay').addEventListener('input', updateThirteenthPay);
      document.getElementById('calcMonthsWorked').addEventListener('input', updateThirteenthPay);
      
      function updateThirteenthPay() {
        const basicPay = parseFloat(document.getElementById('calcBasicPay').value) || 0;
        const monthsWorked = parseInt(document.getElementById('calcMonthsWorked').value) || 0;
        const thirteenthPay = (basicPay / 12) * monthsWorked;
        document.getElementById('calcThirteenthPay').value = thirteenthPay.toFixed(2);
      }
      
      calculateThirteenthModal.style.display = 'block';
    }
    
    // Release 13th month pay
    function releaseThirteenth(employeeNo, employeeName) {
      document.getElementById('releaseEmployeeNo').value = employeeNo;
      document.getElementById('releaseDetails').innerHTML = `
        <p>Are you sure you want to release the 13th month pay for <strong>${employeeName}</strong>?</p>
      `;
      releaseModal.style.display = 'block';
    }
    
    // Undo release
    function undoRelease(employeeNo, employeeName) {
      if (confirm(`Are you sure you want to undo the release for ${employeeName}?`)) {
        fetch('undo_release.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `employee_no=${employeeNo}`
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Remove the row from the table
            const row = document.querySelector(`tr[data-employee-no="${employeeNo}"]`);
            if (row) {
              row.remove();
            }
            // Show success message
            alert('Record deleted successfully!');
            // Reload the page to update the counts
            location.reload();
          } else {
            alert(data.message || 'Error deleting record');
          }
        })
        .catch(error => {
          alert('Error: ' + error.message);
        });
      }
    }
    
    // Batch release functionality
    document.getElementById('releaseSelectedBtn').addEventListener('click', function() {
      const selectedCheckboxes = document.querySelectorAll('.employee-checkbox:checked');
      if (selectedCheckboxes.length === 0) {
        alert('Please select at least one employee to release.');
        return;
      }
      
      const selectedEmployees = Array.from(selectedCheckboxes).map(cb => cb.value);
      document.getElementById('selectedEmployeesInput').value = JSON.stringify(selectedEmployees);
      
      document.getElementById('batchReleaseDetails').innerHTML = `
        <p>Are you sure you want to release 13th month pay for <strong>${selectedEmployees.length}</strong> selected employees?</p>
        <p>Total amount: ₱${calculateTotalSelectedAmount(selectedEmployees).toFixed(2)}</p>
      `;
      
      batchReleaseModal.style.display = 'block';
    });
    
    function calculateTotalSelectedAmount(employeeNos) {
      let total = 0;
      employeeNos.forEach(no => {
        const row = document.querySelector(`.employee-checkbox[value="${no}"]`).closest('tr');
        const amountText = row.querySelector('td:nth-child(7)').textContent;
        if (amountText) {
          total += parseFloat(amountText.replace(/[^\d.]/g, ''));
        }
      });
      return total;
    }
    
    // Select all functionality
    document.getElementById('selectAllBtn').addEventListener('click', function() {
      const checkboxes = document.querySelectorAll('.employee-checkbox');
      const selectAll = !Array.from(checkboxes).every(cb => cb.checked);
      
      checkboxes.forEach(cb => {
        cb.checked = selectAll;
      });
      
      document.getElementById('selectAllCheckbox').checked = selectAll;
      this.textContent = selectAll ? 'Deselect All' : 'Select All';
    });
    
    document.getElementById('selectAllCheckbox').addEventListener('change', function() {
      const checkboxes = document.querySelectorAll('.employee-checkbox');
      checkboxes.forEach(cb => {
        cb.checked = this.checked;
      });
      document.getElementById('selectAllBtn').textContent = this.checked ? 'Deselect All' : 'Select All';
    });
    
    // Individual checkbox functionality
    document.querySelectorAll('.employee-checkbox').forEach(cb => {
      cb.addEventListener('change', function() {
        const allChecked = Array.from(document.querySelectorAll('.employee-checkbox')).every(cb => cb.checked);
        document.getElementById('selectAllCheckbox').checked = allChecked;
        document.getElementById('selectAllBtn').textContent = allChecked ? 'Deselect All' : 'Select All';
      });
    });
    
    // Filter functionality
    document.getElementById('statusFilter').addEventListener('change', function() {
      const status = this.value;
      const rows = document.querySelectorAll('#thirteenthBody tr');
      
      rows.forEach(row => {
        if (status === 'All' || row.getAttribute('data-status') === status) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
    
    // Search functionality
    document.getElementById('thirteenthSearchButton').addEventListener('click', function() {
      const searchTerm = document.getElementById('thirteenthSearch').value.toLowerCase();
      const rows = document.querySelectorAll('#thirteenthBody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
    
    // Reload button
    document.getElementById('thirteenthReloadButton').addEventListener('click', function() {
      location.reload();
    });
    
    // Export to Excel
    document.getElementById('exportBtn').addEventListener('click', function() {
      // This would typically be implemented with a server-side script
      alert('Export to Excel functionality would be implemented here');
    });

    // View employee details
    function viewEmployee(employeeNo) {
        fetch(`get_employee_details.php?employee_no=${employeeNo}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                let html = `
                    <div class="employee-details-container">
                        <h3>Employee Information</h3>
                        <div class="employee-info">
                            <p><strong>Employee No:</strong> ${data.data.employee_no}</p>
                            <p><strong>Name:</strong> ${data.data.name}</p>
                            <p><strong>Department:</strong> ${data.data.department}</p>
                            <p><strong>Date Hired:</strong> ${data.data.date_hired}</p>
                            <p><strong>Position:</strong> ${data.data.position || 'N/A'}</p>
                            <p><strong>Contact:</strong> ${data.data.contact || 'N/A'}</p>
                            <p><strong>Email:</strong> ${data.data.email || 'N/A'}</p>
                            <p><strong>Address:</strong> ${data.data.address || 'N/A'}</p>
                            <p><strong>Birthday:</strong> ${data.data.birthday || 'N/A'}</p>
                            <p><strong>Gender:</strong> ${data.data.gender || 'N/A'}</p>
                            <p><strong>Civil Status:</strong> ${data.data.civil_status || 'N/A'}</p>
                            <p><strong>SSS No:</strong> ${data.data.sss_no || 'N/A'}</p>
                            <p><strong>PhilHealth No:</strong> ${data.data.philhealth_no || 'N/A'}</p>
                            <p><strong>Pag-IBIG No:</strong> ${data.data.pagibig_no || 'N/A'}</p>
                            <p><strong>Tax ID:</strong> ${data.data.tax_id || 'N/A'}</p>
                        </div>
                    </div>
                `;
                
                document.getElementById('employeeDetails').innerHTML = html;
                document.getElementById('viewEmployeeModal').style.display = 'block';
            })
            .catch(error => {
                document.getElementById('employeeDetails').innerHTML = `
                    <div class="error-message">
                        <h3>Error Loading Details</h3>
                        <p>${error.message}</p>
                        <p>Please try again or contact support.</p>
                    </div>
                `;
                document.getElementById('viewEmployeeModal').style.display = 'block';
            });
    }
  </script>
</body>
</html>