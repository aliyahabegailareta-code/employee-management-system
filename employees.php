<?php
// Start session and check authentication
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

// Function to check if 6 months have passed since hire date
function shouldBeRegularized($dateHired) {
    if (empty($dateHired)) {
        return false;
    }
    
    $hireDate = new DateTime($dateHired);
    $today = new DateTime();
    $sixMonthsAgo = clone $today;
    $sixMonthsAgo->modify('-6 months');
    
    // Check if hire date is 6 months or more ago
    return $hireDate <= $sixMonthsAgo;
}

// Function to calculate new salary when promoted to Regular
function calculateRegularSalary($currentBasicPay, $currentStatus) {
    // If already Regular, return current salary
    if ($currentStatus === 'Regular') {
        return $currentBasicPay;
    }
    
    // Rate increase from Probationary to Regular
    // Probationary daily rate: 600, Regular daily rate: 700
    // Increase ratio: 700/600 = 1.1667 (16.67% increase)
    if ($currentStatus === 'Probationary') {
        return $currentBasicPay * (700 / 600);
    }
    
    // For other statuses, return current salary
    return $currentBasicPay;
}

// Auto-update employees who should be regularized (check on page load)
try {
    $stmt = $conn->prepare("SELECT id, date_hired, status, basic_pay, name, employee_no FROM employees WHERE status = 'Probationary' AND date_hired IS NOT NULL");
    $stmt->execute();
    $probationaryEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($probationaryEmployees as $emp) {
        if (shouldBeRegularized($emp['date_hired'])) {
            $newSalary = calculateRegularSalary($emp['basic_pay'], $emp['status']);
            
            $updateStmt = $conn->prepare("UPDATE employees SET status = 'Regular', basic_pay = :basic_pay WHERE id = :id");
            $updateStmt->bindParam(':basic_pay', $newSalary);
            $updateStmt->bindParam(':id', $emp['id']);
            $updateStmt->execute();
            
            // Log activity
            $activityText = "Auto-promoted employee: {$emp['name']} ({$emp['employee_no']}) from Probationary to Regular after 6 months";
            $logStmt = $conn->prepare("INSERT INTO activities (text) VALUES (:text)");
            $logStmt->bindParam(':text', $activityText);
            $logStmt->execute();
        }
    }
} catch(PDOException $e) {
    // Silently continue if there's an error
    error_log("Error auto-updating employees: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_employee'])) {
        // Add new employee
        $employeeNo = $_POST['employeeNo'];
        $employeeName = $_POST['employeeName'];
        $department = $_POST['department'];
        $dateHired = $_POST['dateHired'];
        $basicPay = $_POST['basicPay'];
        $shift = $_POST['shift'];
        
        // Automatically set status to Probationary for new employees
        $status = 'Probationary';
        
        // Auto-check if employee should be regularized (6 months passed)
        if (shouldBeRegularized($dateHired)) {
            $status = 'Regular';
            $basicPay = calculateRegularSalary($basicPay, 'Probationary');
        }

        try {
            $stmt = $conn->prepare("INSERT INTO employees (employee_no, name, department_name, date_hired, basic_pay, shift, status) 
                                   VALUES (:employee_no, :name, :department, :date_hired, :basic_pay, :shift, :status)");
            $stmt->bindParam(':employee_no', $employeeNo);
            $stmt->bindParam(':name', $employeeName);
            $stmt->bindParam(':department', $department);
            $stmt->bindParam(':date_hired', $dateHired);
            $stmt->bindParam(':basic_pay', $basicPay);
            $stmt->bindParam(':shift', $shift);
            $stmt->bindParam(':status', $status);
            $stmt->execute();
            
            // Log activity
            $activityText = "Added new employee: $employeeName ($employeeNo)";
            if ($status === 'Regular') {
                $activityText .= " (Auto-promoted to Regular - 6 months completed)";
            } else {
                $activityText .= " (Status: Probationary - will auto-promote after 6 months)";
            }
            $stmt = $conn->prepare("INSERT INTO activities (text) VALUES (:text)");
            $stmt->bindParam(':text', $activityText);
            $stmt->execute();
            
            $successMsg = "Employee added successfully";
            if ($status === 'Regular') {
                $successMsg .= ". Employee automatically set to Regular status (6 months completed).";
            } else {
                $successMsg .= ". Employee set to Probationary status. Will automatically promote to Regular after 6 months.";
            }
            header("Location: employees.php?success=" . urlencode($successMsg));
            exit();
        } catch(PDOException $e) {
            $error = "Error adding employee: " . $e->getMessage();
        }
    } elseif (isset($_POST['edit_employee'])) {
        // Update employee
        $id = $_POST['id'];
        $employeeNo = $_POST['employeeNo'];
        $employeeName = $_POST['employeeName'];
        $department = $_POST['department'];
        $dateHired = $_POST['dateHired'];
        $basicPay = $_POST['basicPay'];
        $shift = $_POST['shift'];
        $status = $_POST['status'];

        // Get current employee data to check if status is changing
        try {
            $currentStmt = $conn->prepare("SELECT status, basic_pay FROM employees WHERE id = :id");
            $currentStmt->bindParam(':id', $id);
            $currentStmt->execute();
            $currentEmployee = $currentStmt->fetch(PDO::FETCH_ASSOC);
            $oldStatus = $currentEmployee ? $currentEmployee['status'] : '';
            $oldBasicPay = $currentEmployee ? $currentEmployee['basic_pay'] : $basicPay;
        } catch(PDOException $e) {
            $oldStatus = '';
            $oldBasicPay = $basicPay;
        }

        // Auto-check if employee should be regularized (6 months passed)
        if ($status === 'Probationary' && shouldBeRegularized($dateHired)) {
            $status = 'Regular';
            // Only increase salary if status is actually changing from Probationary
            if ($oldStatus === 'Probationary') {
                $basicPay = calculateRegularSalary($oldBasicPay, 'Probationary');
            }
        }

        try {
            $stmt = $conn->prepare("UPDATE employees SET 
                                  employee_no = :employee_no, 
                                  name = :name, 
                                  department_name = :department, 
                                  date_hired = :date_hired, 
                                  basic_pay = :basic_pay, 
                                  shift = :shift,
                                  status = :status
                                  WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':employee_no', $employeeNo);
            $stmt->bindParam(':name', $employeeName);
            $stmt->bindParam(':department', $department);
            $stmt->bindParam(':date_hired', $dateHired);
            $stmt->bindParam(':basic_pay', $basicPay);
            $stmt->bindParam(':shift', $shift);
            $stmt->bindParam(':status', $status);
            $stmt->execute();
            
            // Log activity
            $activityText = "Updated employee: $employeeName ($employeeNo)";
            if ($status === 'Regular' && $oldStatus === 'Probationary' && shouldBeRegularized($dateHired)) {
                $activityText .= " (Auto-promoted to Regular - 6 months completed, salary increased)";
            }
            $stmt = $conn->prepare("INSERT INTO activities (text) VALUES (:text)");
            $stmt->bindParam(':text', $activityText);
            $stmt->execute();
            
            $successMsg = "Employee updated successfully";
            if ($status === 'Regular' && $oldStatus === 'Probationary' && shouldBeRegularized($dateHired)) {
                $successMsg .= ". Employee automatically promoted to Regular status (6 months completed). Salary increased.";
            }
            header("Location: employees.php?success=" . urlencode($successMsg));
            exit();
        } catch(PDOException $e) {
            $error = "Error updating employee: " . $e->getMessage();
        }
    }
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Get employee info for activity log
        $stmt = $conn->prepare("SELECT employee_no, name FROM employees WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$employee) {
            header("Location: employees.php?error=Employee not found");
            exit();
        }
        
        // Start transaction for atomic deletion
        $conn->beginTransaction();
        
        try {
            // Delete related records first (to avoid foreign key constraint violations)
            
            // 1. Delete user account (if exists) - uses employee_no as foreign key
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE employee_no = :employee_no");
                $stmt->bindParam(':employee_no', $employee['employee_no']);
                $stmt->execute();
            } catch(PDOException $e) {
                // If users table doesn't exist or has issues, continue
                error_log("Warning: Could not delete user account: " . $e->getMessage());
            }
            
            // 2. Delete attendance records
            $stmt = $conn->prepare("DELETE FROM attendance WHERE employee_no = :employee_no");
            $stmt->bindParam(':employee_no', $employee['employee_no']);
            $stmt->execute();
            
            // 3. Soft delete payroll records (if deleted column exists) or delete them
            try {
                // Check if deleted column exists in payroll_records
                $checkStmt = $conn->query("SHOW COLUMNS FROM payroll_records LIKE 'deleted'");
                $hasDeletedColumn = $checkStmt->rowCount() > 0;
                
                if ($hasDeletedColumn) {
                    // Soft delete payroll records
                    $stmt = $conn->prepare("UPDATE payroll_records SET deleted = 1 WHERE employee_no = :employee_no");
                } else {
                    // Hard delete payroll records
                    $stmt = $conn->prepare("DELETE FROM payroll_records WHERE employee_no = :employee_no");
                }
                $stmt->bindParam(':employee_no', $employee['employee_no']);
                $stmt->execute();
            } catch(PDOException $e) {
                // If payroll_records table doesn't exist or has issues, continue
                error_log("Warning: Could not delete payroll records: " . $e->getMessage());
            }
            
            // 4. Delete thirteenth month pay records
            try {
                $stmt = $conn->prepare("DELETE FROM thirteenth_month_pay WHERE employee_no = :employee_no");
                $stmt->bindParam(':employee_no', $employee['employee_no']);
                $stmt->execute();
            } catch(PDOException $e) {
                // If table doesn't exist or has issues, continue
                error_log("Warning: Could not delete thirteenth month pay records: " . $e->getMessage());
            }
            
            // 5. Finally, delete the employee
            $stmt = $conn->prepare("DELETE FROM employees WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Log activity
            $activityText = "Deleted employee: {$employee['name']} ({$employee['employee_no']})";
            $stmt = $conn->prepare("INSERT INTO activities (text) VALUES (:text)");
            $stmt->bindParam(':text', $activityText);
            $stmt->execute();
            
            header("Location: employees.php?success=Employee deleted successfully");
            exit();
            
        } catch(PDOException $e) {
            // Rollback transaction on error
            $conn->rollBack();
            throw $e;
        }
        
    } catch(PDOException $e) {
        $error = "Error deleting employee: " . $e->getMessage();
    }
}

// Get all employees
$employees = [];
$searchTerm = $_GET['search'] ?? '';

try {
    if (!empty($searchTerm)) {
        $stmt = $conn->prepare("SELECT * FROM employees 
                              WHERE employee_no LIKE :search 
                              OR name LIKE :search 
                              OR department_name LIKE :search
                              ORDER BY name");
        $searchParam = "%$searchTerm%";
        $stmt->bindParam(':search', $searchParam);
    } else {
        $stmt = $conn->prepare("SELECT * FROM employees ORDER BY name");
    }
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching employees: " . $e->getMessage();
}

// Get employee analytics counts
$totalEmployees = 0;
$regularCount = 0;
$probationaryCount = 0;
$resignedCount = 0;
$terminatedCount = 0;

try {
    $stmt = $conn->query("SELECT COUNT(*) FROM employees");
    $totalEmployees = $stmt->fetchColumn();

    // Count by status
    $stmt = $conn->query("SELECT status, COUNT(*) as count FROM employees GROUP BY status");
    $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusCounts as $row) {
        $status = strtolower($row['status'] ?? '');
        $count = (int)$row['count'];
        
        switch ($status) {
            case 'regular':
                $regularCount = $count;
                break;
            case 'probationary':
                $probationaryCount = $count;
                break;
            case 'resigned':
                $resignedCount = $count;
                break;
            case 'terminated':
                $terminatedCount = $count;
                break;
        }
    }
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Employees</title>
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

.logo-text {
    font-size: 30px;
    font-weight: bold;
    color: #ffffff;
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



    /* Modal Styles */
    .modal {
      display: none; /* Hidden by default */
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
      animation: fadeIn 0.3s;
    }

    @keyframes fadeIn {
      from {opacity: 0;}
      to {opacity: 1;}
    }

    .modal-content {
      background-color: #fefefe;
      margin: 5% auto;
      padding: 0;
      border: none;
      border-radius: 8px;
      width: 90%;
      max-width: 500px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      animation: slideIn 0.3s;
    }

    @keyframes slideIn {
      from {transform: translateY(-50px); opacity: 0;}
      to {transform: translateY(0); opacity: 1;}
    }

    .modal-header {
      padding: 20px 24px 0 24px;
      border-bottom: 1px solid #e5e7eb;
    }

    .modal-header h2 {
      margin: 0 0 16px 0;
      font-size: 1.5rem;
      font-weight: 600;
      color: #111827;
    }

    .modal-body {
      padding: 24px;
    }

    .close {
      color: #9ca3af;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
      line-height: 1;
      margin-top: -4px;
    }

    .close:hover,
    .close:focus {
      color: #374151;
      text-decoration: none;
    }

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
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-group input:focus,
    .form-group select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .help-text {
      display: block;
      margin-top: 4px;
      font-size: 12px;
      color: #6b7280;
    }

    .error-message {
      display: none;
      margin-top: 4px;
      font-size: 12px;
      color: #dc2626;
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
      transition: all 0.2s;
    }

    .btn-cancel:hover {
      background-color: #f9fafb;
      border-color: #9ca3af;
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
      transition: background-color 0.2s;
    }

    .btn-save:hover {
      background-color: #2563eb;
    }

    .status-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .status-regular {
      background-color: #d1fae5;
      color: #065f46;
    }

    .status-probationary {
      background-color: #fef3c7;
      color: #92400e;
    }

    .status-terminated {
      background-color: #fee2e2;
      color: #991b1b;
    }

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
      transition: background-color 0.2s;
    }

    .btn-view {
      background-color: #e0f2fe;
      color: #0277bd;
    }

    .btn-view:hover {
      background-color: #b3e5fc;
    }

    .btn-edit {
      background-color: #fff3e0;
      color: #f57c00;
    }

    .btn-edit:hover {
      background-color: #ffe0b2;
    }

    .btn-delete {
      background-color: #ffebee;
      color: #d32f2f;
    }

    .btn-delete:hover {
      background-color: #ffcdd2;
    }

    .add-employee-button {
      background-color: #3b82f6;
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: background-color 0.2s;
    }

    .add-employee-button:hover {
      background-color: #2563eb;
    }

    .search-button {
      background-color: #6b7280;
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 6px;
      font-size: 14px;
      cursor: pointer;
    }

    .search-button:hover {
      background-color: #4b5563;
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

    .reload-button:hover {
      color: #374151;
    }

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

    /* View Modal Styles */
    .view-modal .modal-content {
      max-width: 400px;
    }

    .employee-detail {
      margin-bottom: 16px;
    }

    .employee-detail label {
      display: block;
      font-size: 12px;
      font-weight: 500;
      color: #6b7280;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .employee-detail .value {
      font-size: 14px;
      font-weight: 500;
      color: #111827;
    }

    .employee-detail .value.large {
      font-size: 18px;
      color: #059669;
    }
	    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      border: 1px solid #888;
      padding: 10px;
      text-align: left;
    }
    thead {
      background-color: #d3d3d3;
    }
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }
    .section-header h2 {
      color: #003399;
      font-weight: bold;
      font-size: 28px;
      margin: 0;
      padding: 10px 0;
    }
    .action-btn {
      border: none;
      color: white;
      padding: 0;
      margin: 0 2px;
      cursor: pointer;
      border-radius: 4px;
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
    }
    .btn-view {
      background-color: #007bff;
    }
    .btn-edit {
      background-color: #28a745;
    }
    .btn-delete {
      background-color: #dc3545;
    }
    .action-buttons {
      text-align: center;
      white-space: nowrap;
    }
    .action-buttons .action-btn:hover {
      opacity: 0.9;
    }
    
    /* Updated search container styles to match attendance.html */
    .search-container {
      display: flex;
      align-items: center;
      margin-left: auto;
      position: relative;
      gap: 8px;
    }
    .search-input {
      padding: 10px 15px;
      padding-right: 40px;
      border: 1px solid black;
      border-radius: 4px;
      font-size: 18px;
      width: 250px;
      position: relative;
    }

    .reload-button {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      background: #ddd;
      border: none;
      font-size: 18px;
      cursor: pointer;
      color: #007bff;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10;
    }

    .reload-button:hover {
      color: #0056b3;
    }
    
    .search-button, .add-employee-button {
      padding: 10px 15px;
      background-color: #ddd;
      color: black;
      border: 1px solid #ccc;
      border-radius: 4px;
      cursor: pointer;
      font-size: 16px;
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
      margin: 10% auto;
      padding: 20px;
      border-radius: 5px;
      width: 50%;
      max-width: 600px;
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

    /* Simplified date input styling to match other inputs */
    input[type="date"] {
      width: 100%;
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
      appearance: none; /* Remove default styling */
      -webkit-appearance: none;
      background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>');
      background-repeat: no-repeat;
      background-position: right 8px center;
      background-size: 16px;
    }
    
    .form-buttons {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 20px;
    }
    
    .form-buttons button {
      padding: 8px 15px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    
    .btn-cancel {
      background-color: #6c757d;
      color: white;
    }
    
    .btn-save {
      background-color: #28a745;
      color: white;
    }

    /* View Employee Modal */
    .employee-details {
      margin-bottom: 20px;
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
    
    /* Help text for input fields */
    .help-text {
      font-size: 12px;
      color: #666;
      margin-top: 5px;
      display: block;
    }
    
    /* Error message styles */
    .error-message {
      color: #dc3545;
      font-size: 12px;
      margin-top: 5px;
      display: none;
    }
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

.logo-text {
    font-size: 30px;
    font-weight: bold;
    color: #ffffff;
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
        <li data-page="employees.php" class="active"><i class="icon">👤</i><span>Employees</span></li>
        <li data-page="attendance.php"><i class="icon">📋</i><span>Attendance</span></li>
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
      <span class="logo-text-right">Employees</span>
    </div>

    <div class="top-cards">
      <div class="card">
        <h3>Total Employees</h3>
        <p id="total-employees-count"><?= number_format($totalEmployees) ?></p>
      </div>
      <div class="card">
        <h3>Regular Employees</h3>
        <p><?= number_format($regularCount) ?></p>
      </div>
      <div class="card">
        <h3>Probationary Employees</h3>
        <p><?= number_format($probationaryCount) ?></p>
      </div>
      <div class="card" style="background-color: #fff3cd;">
        <h3>Resigned</h3>
        <p><?= number_format($resignedCount) ?></p>
      </div>
      <div class="card" style="background-color: #f8d7da;">
        <h3>Terminated</h3>
        <p><?= number_format($terminatedCount) ?></p>
      </div>
    </div>

    <div class="content" style="width: calc(100% - 40px);">
      <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
          <?= htmlspecialchars($_GET['success']) ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error">
          <?= htmlspecialchars($_GET['error']) ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($error)): ?>
        <div class="alert alert-error">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>
      
      <div class="payroll-section" style="width: 100%;">
        <div class="section-header">
          <h2>Employees</h2>
          <div class="search-container">
            <form method="GET" action="employees.php" style="display: flex; gap: 8px;">
              <div style="position: relative;">
                  <input type="text" name="search" class="search-input" placeholder="Search employees..." 
                         value="<?= htmlspecialchars($searchTerm) ?>">
                  <button type="button" onclick="window.location.href='employees.php'" class="reload-button">↻</button>
              </div>
              <button type="submit" class="search-button">Search</button>
              <button type="button" id="addEmployeeButton" class="add-employee-button">Add Employee</button>
            </form>
          </div>
        </div>
        
        <?php if (empty($employees)): ?>
          <div style="text-align: center; padding: 40px; color: #6b7280;">
            <p style="font-size: 18px; margin-bottom: 8px;">No employees found</p>
            <p style="font-size: 14px;">Click "Add Employee" to get started</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Employee No.</th>
              <th>Employee Name</th>
              <th>Department</th>
              <th>Date Hired</th>
              <th>Basic Pay</th>
              <th>Shift</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="employeeTableBody">
            <?php foreach ($employees as $employee): ?>
              <tr>
                <td><?= htmlspecialchars($employee['employee_no']) ?></td>
                <td><?= htmlspecialchars($employee['name']) ?></td>
                <td><?= htmlspecialchars($employee['department_name']) ?></td>
                <td><?= date('Y-m-d', strtotime($employee['date_hired'])) ?></td>
                <td>₱<?= number_format($employee['basic_pay'], 2) ?></td>
                <td><?= htmlspecialchars($employee['shift']) ?></td>
                <td>
                  <span class="status-badge status-<?= strtolower($employee['status']) ?>">
                    <?= htmlspecialchars($employee['status']) ?>
                  </span>
                </td>
                <td class="action-buttons">
                  <button class="action-btn btn-view" onclick="viewEmployee(<?= $employee['id'] ?>)">👁️</button>
                  <button class="action-btn btn-edit" onclick="editEmployee(<?= $employee['id'] ?>)">✏️</button>
                  <button class="action-btn btn-delete" onclick="if(confirm('Are you sure you want to delete this employee?')) window.location.href='employees.php?delete=<?= $employee['id'] ?>'">🗑️</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Add Employee Modal -->
  <div id="addEmployeeModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <span class="close" id="closeAddModal">&times;</span>
        <h2>Add New Employee</h2>
      </div>
      <div class="modal-body">
        <form id="addEmployeeForm" method="POST" action="employees.php">
          <div class="form-group">
            <label for="employeeNo">Employee No.</label>
            <input type="text" id="employeeNo" name="employeeNo" placeholder="XX-XXXXX" maxlength="8" required>
            <span class="help-text">Format: XX-XXXXX (2 digits, hyphen, 5 digits)</span>
            <span id="employeeNoError" class="error-message">Employee No. must be in format XX-XXXXX</span>
          </div>
          <div class="form-group">
            <label for="employeeName">Employee Name</label>
            <input type="text" id="employeeName" name="employeeName" required>
          </div>
          <div class="form-group">
            <label for="department">Department</label>
            <select id="department" name="department" required>
              <option value="">Select Department</option>
              <option value="Ford 1">Ford 1</option>
              <option value="Ford 2">Ford 2</option>
              <option value="Nissan 1">Nissan 1</option>
              <option value="Nissan 3">Nissan 3</option>
              <option value="Nissan 4">Nissan 4</option>
              <option value="Ytmci">Ytmci</option>
            </select>
          </div>
          <div class="form-group">
            <label for="dateHired">Date Hired</label>
            <input type="date" id="dateHired" name="dateHired" required>
          </div>
          <div class="form-group">
            <label for="basicPay">Basic Monthly Pay (₱)</label>
            <input type="number" id="basicPay" name="basicPay" min="0" step="0.01" required>
            <span class="help-text">Enter the employee's monthly basic salary</span>
          </div>
          <div class="form-group">
            <label for="shift">Shift</label>
            <select id="shift" name="shift" required>
              <option value="">Select Shift</option>
              <option value="Shift A">Shift A</option>
              <option value="Shift B">Shift B</option>
            </select>
          </div>
          <div class="form-group" style="display: none;">
            <input type="hidden" id="status" name="status" value="Probationary">
          </div>
          <div class="form-buttons">
            <button type="button" class="btn-cancel" id="cancelAddEmployee">Cancel</button>
            <button type="submit" class="btn-save" name="add_employee">Save Employee</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit Employee Modal -->
  <div id="editEmployeeModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <span class="close" id="closeEditModal">&times;</span>
        <h2>Edit Employee</h2>
      </div>
      <div class="modal-body">
        <form id="editEmployeeForm" method="POST" action="employees.php">
          <input type="hidden" id="editEmployeeId" name="id">
          <div class="form-group">
            <label for="editEmployeeNo">Employee No.</label>
            <input type="text" id="editEmployeeNo" name="employeeNo" placeholder="XX-XXXXX" maxlength="8" required>
            <span class="help-text">Format: XX-XXXXX (2 digits, hyphen, 5 digits)</span>
            <span id="editEmployeeNoError" class="error-message">Employee No. must be in format XX-XXXXX</span>
          </div>
          <div class="form-group">
            <label for="editEmployeeName">Employee Name</label>
            <input type="text" id="editEmployeeName" name="employeeName" required>
          </div>
          <div class="form-group">
            <label for="editDepartment">Department</label>
            <select id="editDepartment" name="department" required>
              <option value="">Select Department</option>
              <option value="Ford 1">Ford 1</option>
              <option value="Ford 2">Ford 2</option>
              <option value="Nissan 1">Nissan 1</option>
              <option value="Nissan 3">Nissan 3</option>
              <option value="Nissan 4">Nissan 4</option>
              <option value="Ytmci">Ytmci</option>
            </select>
          </div>
          <div class="form-group">
            <label for="editDateHired">Date Hired</label>
            <input type="date" id="editDateHired" name="dateHired" required>
          </div>
          <div class="form-group">
            <label for="editBasicPay">Basic Monthly Pay (₱)</label>
            <input type="number" id="editBasicPay" name="basicPay" min="0" step="0.01" required>
            <span class="help-text">Enter the employee's monthly basic salary</span>
          </div>
          <div class="form-group">
            <label for="editShift">Shift</label>
            <select id="editShift" name="shift" required>
              <option value="">Select Shift</option>
              <option value="Shift A">Shift A</option>
              <option value="Shift B">Shift B</option>
            </select>
          </div>
          <div class="form-group">
            <label for="editStatus">Status</label>
            <select id="editStatus" name="status" required>
              <option value="">Select Status</option>
              <option value="Probationary">Probationary</option>
              <option value="Regular">Regular</option>
              <option value="Terminated">Terminated</option>
            </select>
          </div>
          <div class="form-buttons">
            <button type="button" class="btn-cancel" id="cancelEditEmployee">Cancel</button>
            <button type="submit" class="btn-save" name="edit_employee">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- View Employee Modal -->
  <div id="viewEmployeeModal" class="modal view-modal">
    <div class="modal-content">
      <div class="modal-header">
        <span class="close" id="closeViewModal">&times;</span>
        <h2>Employee Details</h2>
      </div>
      <div class="modal-body">
        <div id="viewEmployeeContent">
          <!-- Content will be populated by JavaScript -->
        </div>
        <div class="form-buttons">
          <button type="button" class="btn-cancel" id="closeViewEmployee" style="flex: none; width: 100%;">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Modal elements
    const addEmployeeModal = document.getElementById('addEmployeeModal');
    const editEmployeeModal = document.getElementById('editEmployeeModal');
    const viewEmployeeModal = document.getElementById('viewEmployeeModal');
    
    // Buttons
    const addEmployeeBtn = document.getElementById('addEmployeeButton');
    
    // Close buttons
    const closeAddModal = document.getElementById('closeAddModal');
    const closeEditModal = document.getElementById('closeEditModal');
    const closeViewModal = document.getElementById('closeViewModal');
    
    // Cancel buttons
    const cancelAddBtn = document.getElementById('cancelAddEmployee');
    const cancelEditBtn = document.getElementById('cancelEditEmployee');
    const closeViewBtn = document.getElementById('closeViewEmployee');

    // Show add employee modal
    addEmployeeBtn.addEventListener('click', function() {
      document.getElementById('addEmployeeForm').reset();
      
      // Set default date to today
      const today = new Date().toISOString().split('T')[0];
      const dateHiredInput = document.getElementById('dateHired');
      if (dateHiredInput) {
        dateHiredInput.value = today;
      }
      
      addEmployeeModal.style.display = 'block';
    });

    // Close modal functions
    function closeModal(modal) {
      modal.style.display = 'none';
    }

    // Close button events
    closeAddModal.addEventListener('click', () => closeModal(addEmployeeModal));
    closeEditModal.addEventListener('click', () => closeModal(editEmployeeModal));
    closeViewModal.addEventListener('click', () => closeModal(viewEmployeeModal));
    
    // Cancel button events
    cancelAddBtn.addEventListener('click', () => closeModal(addEmployeeModal));
    cancelEditBtn.addEventListener('click', () => closeModal(editEmployeeModal));
    closeViewBtn.addEventListener('click', () => closeModal(viewEmployeeModal));

    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
      if (event.target === addEmployeeModal) {
        closeModal(addEmployeeModal);
      }
      if (event.target === editEmployeeModal) {
        closeModal(editEmployeeModal);
      }
      if (event.target === viewEmployeeModal) {
        closeModal(viewEmployeeModal);
      }
    });

    // Validate employee number format (XX-XXXXX)
    function validateEmployeeNo(employeeNo) {
      const regex = /^\d{2}-\d{5}$/;
      return regex.test(employeeNo);
    }

    // Employee number validation for add form
    document.getElementById('employeeNo').addEventListener('input', function() {
      const errorElement = document.getElementById('employeeNoError');
      if (this.value && !validateEmployeeNo(this.value)) {
        errorElement.style.display = 'block';
      } else {
        errorElement.style.display = 'none';
      }
    });

    // Employee number validation for edit form
    document.getElementById('editEmployeeNo').addEventListener('input', function() {
      const errorElement = document.getElementById('editEmployeeNoError');
      if (this.value && !validateEmployeeNo(this.value)) {
        errorElement.style.display = 'block';
      } else {
        errorElement.style.display = 'none';
      }
    });

    // Auto-set status to Probationary for newly hired employees
    function checkDateAndSetStatus(dateInput, statusSelect) {
      const selectedDate = new Date(dateInput.value);
      const today = new Date();
      today.setHours(0, 0, 0, 0); // Reset time to compare dates only
      
      // Calculate 6 months ago
      const sixMonthsAgo = new Date();
      sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);
      sixMonthsAgo.setHours(0, 0, 0, 0);
      
      if (dateInput.value) {
        // If date is today or in the future, or within the last 6 months, set to Probationary
        if (selectedDate >= sixMonthsAgo) {
          statusSelect.value = 'Probationary';
        }
        // If date is more than 6 months ago and status is not manually set, could be Regular
        // But we'll only auto-set to Probationary for recent hires
      }
    }

    // Status is now automatically set to Probationary, no need for date-based status setting in add form

    // Auto-set status based on date hired for edit form
    const editDateHiredInput = document.getElementById('editDateHired');
    const editStatusSelect = document.getElementById('editStatus');
    
    if (editDateHiredInput && editStatusSelect) {
      editDateHiredInput.addEventListener('change', function() {
        // Only auto-set if status is currently empty or Probationary
        // Don't override if user has manually set it to Regular or Terminated
        const currentStatus = editStatusSelect.value;
        if (currentStatus === '' || currentStatus === 'Probationary') {
          checkDateAndSetStatus(this, editStatusSelect);
        }
      });
      
      editDateHiredInput.addEventListener('input', function() {
        const currentStatus = editStatusSelect.value;
        if (currentStatus === '' || currentStatus === 'Probationary') {
          checkDateAndSetStatus(this, editStatusSelect);
        }
      });
    }

    // Form validation before submit
    document.getElementById('addEmployeeForm').addEventListener('submit', function(e) {
      const employeeNo = document.getElementById('employeeNo').value;
      if (!validateEmployeeNo(employeeNo)) {
        e.preventDefault();
        document.getElementById('employeeNoError').style.display = 'block';
        alert('Please enter a valid employee number in format XX-XXXXX');
      }
    });

    document.getElementById('editEmployeeForm').addEventListener('submit', function(e) {
      const employeeNo = document.getElementById('editEmployeeNo').value;
      if (!validateEmployeeNo(employeeNo)) {
        e.preventDefault();
        document.getElementById('editEmployeeNoError').style.display = 'block';
        alert('Please enter a valid employee number in format XX-XXXXX');
      }
    });

    // Edit employee function
    function editEmployee(id) {
      fetch('get_employee.php?id=' + id)
        .then(response => response.json())
        .then(employee => {
          document.getElementById('editEmployeeId').value = employee.id;
          document.getElementById('editEmployeeNo').value = employee.employee_no;
          document.getElementById('editEmployeeName').value = employee.name;
          document.getElementById('editDepartment').value = employee.department_name;
          document.getElementById('editDateHired').value = employee.date_hired;
          document.getElementById('editBasicPay').value = employee.basic_pay;
          document.getElementById('editShift').value = employee.shift;
          document.getElementById('editStatus').value = employee.status;
          
          editEmployeeModal.style.display = 'block';
        })
        .catch(error => {
          console.error('Error fetching employee:', error);
          alert('Error loading employee data');
        });
    }

    // View employee function
    function viewEmployee(id) {
      fetch('get_employee.php?id=' + id)
        .then(response => response.json())
        .then(employee => {
          const statusClass = 'status-' + employee.status.toLowerCase();
          const content = `
            <div class="employee-detail">
              <label>Employee Number</label>
              <div class="value">${employee.employee_no}</div>
            </div>
            <div class="employee-detail">
              <label>Full Name</label>
              <div class="value">${employee.name}</div>
            </div>
            <div class="employee-detail">
              <label>Department</label>
              <div class="value">${employee.department_name}</div>
            </div>
            <div class="employee-detail">
              <label>Date Hired</label>
              <div class="value">${new Date(employee.date_hired).toLocaleDateString()}</div>
            </div>
            <div class="employee-detail">
              <label>Shift</label>
              <div class="value">${employee.shift}</div>
            </div>
            <div class="employee-detail">
              <label>Status</label>
              <div class="value">
                <span class="status-badge ${statusClass}">${employee.status}</span>
              </div>
            </div>
            <div class="employee-detail">
              <label>Basic Monthly Pay</label>
              <div class="value large">₱${parseFloat(employee.basic_pay).toLocaleString()}</div>
            </div>
          `;
          
          document.getElementById('viewEmployeeContent').innerHTML = content;
          viewEmployeeModal.style.display = 'block';
        })
        .catch(error => {
          console.error('Error fetching employee:', error);
          alert('Error loading employee data');
        });
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