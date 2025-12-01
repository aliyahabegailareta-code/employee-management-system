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

// Get all employees
$stmt = $conn->query("SELECT * FROM employees");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate analytics counts based on actual status
$totalCount = count($employees);
$regularCount = 0;
$probationaryCount = 0;
$resignedCount = 0;
$terminatedCount = 0;

foreach ($employees as $employee) {
    $status = $employee['status'] ?? '';
    
    // Count based on actual status in database
    switch (strtolower($status)) {
        case 'regular':
            $regularCount++;
            break;
        case 'probationary':
            $probationaryCount++;
            break;
        case 'resigned':
            $resignedCount++;
            break;
        case 'terminated':
            $terminatedCount++;
            break;
        default:
            // If status is empty or unknown, check based on hire date
            if (!empty($employee['date_hired'])) {
                $hireDate = new DateTime($employee['date_hired']);
                $today = new DateTime();
                $interval = $hireDate->diff($today);
                $monthsDiff = $interval->y * 12 + $interval->m;
                
                if ($monthsDiff >= REGULARIZATION_PERIOD) {
                    $regularCount++;
                } else {
                    $probationaryCount++;
                }
            }
            break;
    }
}

// Handle search and filters
$filteredEmployees = $employees;
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$salaryFilter = isset($_GET['salary']) ? $_GET['salary'] : 'all';

if ($searchTerm || $statusFilter != 'all' || $salaryFilter != 'all') {
    $filteredEmployees = array_filter($employees, function($emp) use ($searchTerm, $statusFilter, $salaryFilter) {
        // Use actual status from database, or calculate based on hire date if status is empty
        $actualStatus = strtolower($emp['status'] ?? '');
        
        if (empty($actualStatus) && !empty($emp['date_hired'])) {
            $hireDate = new DateTime($emp['date_hired']);
            $today = new DateTime();
            $interval = $hireDate->diff($today);
            $monthsDiff = $interval->y * 12 + $interval->m;
            $actualStatus = $monthsDiff >= REGULARIZATION_PERIOD ? 'regular' : 'probationary';
        }
        
        $hireDate = new DateTime($emp['date_hired']);
        $today = new DateTime();
        $interval = $hireDate->diff($today);
        $monthsDiff = $interval->y * 12 + $interval->m;
        $basicPay = $monthsDiff >= SALARY_INCREASE_PERIOD ? BASIC_PAY * 1.2 : BASIC_PAY;
        
        $matchesSearch = empty($searchTerm) || 
                        stripos($emp['employee_no'], $searchTerm) !== false || 
                        stripos($emp['name'], $searchTerm) !== false;
        
        $matchesStatus = $statusFilter == 'all' || $actualStatus == $statusFilter;
        $matchesSalary = $salaryFilter == 'all' || 
                        ($salaryFilter == 'basic' && $basicPay == BASIC_PAY) || 
                        ($salaryFilter == 'increased' && $basicPay > BASIC_PAY);
        
        return $matchesSearch && $matchesStatus && $matchesSalary;
    });
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Format date helper function
function formatDate($dateString) {
    try {
        $date = new DateTime($dateString);
        return $date->format('M d, Y');
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Format tenure helper function
function formatTenure($months) {
    if ($months < 1) {
        return 'Less than 1 month';
    }
    return $months . ' month' . ($months === 1 ? '' : 's');
}

// Get payroll data for line graph (last 6 months)
$payrollData = [];
try {
    // Check if deleted column exists
    $checkStmt = $conn->query("SHOW COLUMNS FROM payroll_records LIKE 'deleted'");
    $hasDeletedColumn = $checkStmt->rowCount() > 0;
    
    // Get payroll records grouped by month for the last 6 months
    if ($hasDeletedColumn) {
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as employee_count,
                SUM(net_salary) as total_payroll,
                SUM(basic_salary) as total_basic,
                SUM(overtime_pay) as total_overtime
            FROM payroll_records 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND deleted = 0
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as employee_count,
                SUM(net_salary) as total_payroll,
                SUM(basic_salary) as total_basic,
                SUM(overtime_pay) as total_overtime
            FROM payroll_records 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
    }
    $stmt->execute();
    $payrollData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // If payroll_records table doesn't exist or has issues, continue with empty data
    $payrollData = [];
}

// Get employee count over time (last 6 months)
$employeeCountData = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(date_hired, '%Y-%m') as month,
            COUNT(*) as new_employees
        FROM employees 
        WHERE date_hired >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(date_hired, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute();
    $employeeCountData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $employeeCountData = [];
}

// Prepare data for Chart.js
$chartMonths = [];
$chartPayroll = [];
$chartEmployees = [];
$chartBasic = [];
$chartOvertime = [];
$chartEmployeeCount = [];

// Generate last 6 months labels
for ($i = 5; $i >= 0; $i--) {
    $date = new DateTime();
    $date->modify("-$i months");
    $monthLabel = $date->format('M Y');
    $chartMonths[] = $monthLabel;
    
    // Find matching data
    $monthKey = $date->format('Y-m');
    $payrollMonth = array_filter($payrollData, function($item) use ($monthKey) {
        return $item['month'] === $monthKey;
    });
    $employeeMonth = array_filter($employeeCountData, function($item) use ($monthKey) {
        return $item['month'] === $monthKey;
    });
    
    $payrollMonth = reset($payrollMonth);
    $employeeMonth = reset($employeeMonth);
    
    $chartPayroll[] = $payrollMonth ? (float)$payrollMonth['total_payroll'] : 0;
    $chartEmployees[] = $employeeMonth ? (int)$employeeMonth['new_employees'] : 0;
    $chartBasic[] = $payrollMonth ? (float)$payrollMonth['total_basic'] : 0;
    $chartOvertime[] = $payrollMonth ? (float)$payrollMonth['total_overtime'] : 0;
    $chartEmployeeCount[] = $payrollMonth ? (int)$payrollMonth['employee_count'] : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Employee Analytics</title>
  <link rel="stylesheet" href="dashboard.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* All your existing CSS styles here */
    .analytics-container {
      padding: 20px;
    }
    .header-logo {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 20px;
        background: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }

    .logo-img {
        height: 50px;
        width: auto;
    }

    .logo-text-right {
        font-size: 28px;
        font-weight: bold;
        color: black;
    }
    /* ... (keep all your existing styles) ... */
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
        <li data-page="analytics.php" class="active"><i class="icon">📈</i><span>Analytics</span></li>
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
      <span class="logo-text-right">Analytics</span>
    </div>

    <div class="top-cards">
      <div class="card">
        <h3>Total Employees</h3>
        <p><?php echo $totalCount; ?></p>
      </div>
      <div class="card">
        <h3>Regular Employees</h3>
        <p><?php echo $regularCount; ?></p>
      </div>
      <div class="card">
        <h3>Probationary Employees</h3>
        <p><?php echo $probationaryCount; ?></p>
      </div>
      <div class="card" style="background-color: #fff3cd;">
        <h3>Resigned</h3>
        <p><?php echo $resignedCount; ?></p>
      </div>
      <div class="card" style="background-color: #f8d7da;">
        <h3>Terminated</h3>
        <p><?php echo $terminatedCount; ?></p>
      </div>
    </div>

    <div class="content">
      <!-- Line Graph Section -->
      <div class="analytics-section" style="margin-bottom: 30px;">
        <div class="section-header">
          <h2>Payroll Trends (Last 6 Months)</h2>
        </div>
        <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
          <canvas id="payrollChart" style="max-height: 400px;"></canvas>
        </div>
      </div>

      <!-- Employee Growth Chart -->
      <div class="analytics-section" style="margin-bottom: 30px;">
        <div class="section-header">
          <h2>New Employee Hires (Last 6 Months)</h2>
        </div>
        <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
          <canvas id="employeeChart" style="max-height: 400px;"></canvas>
        </div>
      </div>

      <div class="analytics-section">
        <div class="section-header">
          <h2>Employee Details</h2>
          <form method="GET" class="search-container">
            <input type="text" name="search" id="searchInput" placeholder="Search by employee name or ID..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            <button type="submit">Search</button>
          </form>
        </div>

        <form method="GET" class="filter-container">
          <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
          <select name="status" id="statusFilter" onchange="this.form.submit()">
            <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All Status</option>
            <option value="regular" <?php echo $statusFilter == 'regular' ? 'selected' : ''; ?>>Regular</option>
            <option value="probationary" <?php echo $statusFilter == 'probationary' ? 'selected' : ''; ?>>Probationary</option>
            <option value="resigned" <?php echo $statusFilter == 'resigned' ? 'selected' : ''; ?>>Resigned</option>
            <option value="terminated" <?php echo $statusFilter == 'terminated' ? 'selected' : ''; ?>>Terminated</option>
          </select>
          <select name="salary" id="salaryFilter" onchange="this.form.submit()">
            <option value="all" <?php echo $salaryFilter == 'all' ? 'selected' : ''; ?>>All Salary Ranges</option>
            <option value="basic" <?php echo $salaryFilter == 'basic' ? 'selected' : ''; ?>>Basic Pay (₱<?php echo BASIC_PAY; ?>)</option>
            <option value="increased" <?php echo $salaryFilter == 'increased' ? 'selected' : ''; ?>>Increased Pay</option>
          </select>
        </form>

        <table class="employee-table">
          <thead>
            <tr>
              <th>Employee ID</th>
              <th>Name</th>
              <th>Department</th>
              <th>Hire Date</th>
              <th>Status</th>
              <th>Basic Pay</th>
              <th>Tenure</th>
            </tr>
          </thead>
          <tbody id="employeeTableBody">
            <?php foreach ($filteredEmployees as $employee): ?>
              <?php
              // Use actual status from database
              $status = $employee['status'] ?? '';
              
              // If status is empty, calculate based on hire date
              if (empty($status) && !empty($employee['date_hired'])) {
                  $hireDate = new DateTime($employee['date_hired']);
                  $today = new DateTime();
                  $interval = $hireDate->diff($today);
                  $monthsDiff = $interval->y * 12 + $interval->m;
                  $status = $monthsDiff >= REGULARIZATION_PERIOD ? 'Regular' : 'Probationary';
              }
              
              $hireDate = new DateTime($employee['date_hired']);
              $today = new DateTime();
              $interval = $hireDate->diff($today);
              $monthsDiff = $interval->y * 12 + $interval->m;
              $basicPay = $monthsDiff >= SALARY_INCREASE_PERIOD ? BASIC_PAY * 1.2 : BASIC_PAY;
              ?>
              <tr>
                <td><?php echo htmlspecialchars($employee['employee_no']); ?></td>
                <td><?php echo htmlspecialchars($employee['name']); ?></td>
                <td><?php echo htmlspecialchars($employee['department_name']); ?></td>
                <td><?php echo formatDate($employee['date_hired']); ?></td>
                <td><span class="status-badge status-<?php echo strtolower($status); ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td>₱<?php echo number_format($basicPay, 2); ?></td>
                <td><?php echo formatTenure($monthsDiff); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    // Chart data from PHP
    const chartMonths = <?php echo json_encode($chartMonths); ?>;
    const chartPayroll = <?php echo json_encode($chartPayroll); ?>;
    const chartEmployees = <?php echo json_encode($chartEmployees); ?>;
    const chartBasic = <?php echo json_encode($chartBasic); ?>;
    const chartOvertime = <?php echo json_encode($chartOvertime); ?>;
    const chartEmployeeCount = <?php echo json_encode($chartEmployeeCount); ?>;
    
    // Debug: Log employee count data
    console.log('Employee Count Data:', chartEmployeeCount);

    // Initialize Payroll Trends Chart
    const payrollCtx = document.getElementById('payrollChart').getContext('2d');
    const payrollChart = new Chart(payrollCtx, {
      type: 'line',
      data: {
        labels: chartMonths,
        datasets: [
          {
            label: 'Total Payroll (₱)',
            data: chartPayroll,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.4,
            fill: true
          },
          {
            label: 'Basic Salary (₱)',
            data: chartBasic,
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.4,
            fill: false
          },
          {
            label: 'Overtime Pay (₱)',
            data: chartOvertime,
            borderColor: 'rgb(255, 99, 132)',
            backgroundColor: 'rgba(255, 99, 132, 0.2)',
            tension: 0.4,
            fill: false
          },
          {
            label: 'Employees Processed',
            data: chartEmployeeCount,
            borderColor: 'rgb(255, 206, 86)',
            backgroundColor: 'rgba(255, 206, 86, 0.2)',
            tension: 0.4,
            fill: false,
            yAxisID: 'y1',
            pointRadius: 5,
            pointHoverRadius: 7,
            borderWidth: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: {
            position: 'top',
          },
          title: {
            display: true,
            text: 'Payroll Trends Over Time'
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                if (context.dataset.label === 'Employees Processed') {
                  return context.dataset.label + ': ' + context.parsed.y + ' employee(s)';
                }
                return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString('en-US', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }
            }
          }
        },
        scales: {
          y: {
            type: 'linear',
            display: true,
            position: 'left',
            beginAtZero: true,
            ticks: {
              callback: function(value) {
                return '₱' + value.toLocaleString();
              }
            },
            title: {
              display: true,
              text: 'Amount (₱)'
            }
          },
          y1: {
            type: 'linear',
            display: true,
            position: 'right',
            beginAtZero: true,
            ticks: {
              stepSize: 1,
              callback: function(value) {
                return value;
              }
            },
            grid: {
              drawOnChartArea: false
            },
            title: {
              display: true,
              text: 'Number of Employees'
            }
          }
        }
      }
    });

    // Initialize Employee Hires Chart
    const employeeCtx = document.getElementById('employeeChart').getContext('2d');
    const employeeChart = new Chart(employeeCtx, {
      type: 'line',
      data: {
        labels: chartMonths,
        datasets: [
          {
            label: 'New Employees Hired',
            data: chartEmployees,
            borderColor: 'rgb(153, 102, 255)',
            backgroundColor: 'rgba(153, 102, 255, 0.2)',
            tension: 0.4,
            fill: true,
            pointRadius: 6,
            pointHoverRadius: 8
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: {
            position: 'top',
          },
          title: {
            display: true,
            text: 'New Employee Hires Over Time'
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                return context.dataset.label + ': ' + context.parsed.y + ' employee(s)';
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              stepSize: 1
            }
          }
        }
      }
    });

    // Client-side search functionality
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('searchInput');
      
      // Add event listener for search input (optional client-side filtering)
      searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#employeeTableBody tr');
        
        rows.forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
      });
    });

    // Sidebar navigation
    document.querySelectorAll('.sidebar nav li, .sidebar .reports li').forEach(item => {
      item.addEventListener('click', function() {
        const page = this.getAttribute('data-page');
        if (page) {
          window.location.href = page;
        }
      });
    });
    
  </script>
</body>
</html>