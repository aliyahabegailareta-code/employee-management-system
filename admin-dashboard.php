<?php
// Start session and check if user is logged in
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

// Get data for dashboard
try {
    // Get total employees count (including all statuses)
    $stmt = $conn->query("SELECT COUNT(*) FROM employees");
    $totalEmployees = $stmt->fetchColumn();

    // Get total 13th month pay
    $stmt = $conn->query("SELECT SUM(thirteenth_pay) FROM thirteenth_month_pay WHERE status = 'Approved'");
    $total13thMonthPay = $stmt->fetchColumn() ?? 0;

    // Get pending approvals count
    $stmt = $conn->query("SELECT COUNT(*) FROM thirteenth_month_pay WHERE status = 'Approved'");
    $Approved = $stmt->fetchColumn();

    // Get recent activities
    $stmt = $conn->query("SELECT * FROM activities ORDER BY created_at DESC LIMIT 5");
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent employees with status
    $stmt = $conn->query("SELECT id, employee_no, name, department_name, date_hired, status FROM employees ORDER BY created_at DESC LIMIT 5");
    $recentEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payroll Dashboard</title>
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



    /* Additional styles for employee status badges */
    .badge-probationary {
      background-color: #FFF3CD;
      color: #856404;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.8rem;
    }
    
    .badge-regular {
      background-color: #D4EDDA;
      color: #155724;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.8rem;
    }
    
    .badge-terminated {
      background-color: #F8D7DA;
      color: #721C24;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.8rem;
    }
    
    .employee-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
    }
    
    .employee-table th, .employee-table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #eee;
    }
    
    .employee-table tr:hover {
      background-color: #f5f5f5;
      cursor: pointer;
    }
    
    .view-all-btn {
      display: inline-block;
      margin-top: 15px;
      padding: 8px 15px;
      background-color: #2c3e50;
      color: white;
      border: none;
      border-radius: 4px;
      text-decoration: none;
    }
    
    .view-all-btn:hover {
      background-color: #34495e;
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
        <li data-page="admin-dashboard.php" class="active"><i class="icon">🏠</i><span>Dashboard</span></li>
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
      <span class="logo-text-right">Dashboard</span>
    </div>

    <div class="top-cards">
      <div class="card" id="total-employees-card" onclick="window.location.href='employees.php'">
        <h3>Total Employees</h3>
        <p id="total-employees-count"><?php echo number_format($totalEmployees); ?></p>
      </div>
      <div class="card green" onclick="window.location.href='13th-month.php'">
        <h3>Total 13th Month Pay</h3>
        <p id="this-month-payroll">₱<?php echo number_format($total13thMonthPay, 2); ?></p>
      </div>
      <div class="card" onclick="window.location.href='13th-month.php'">
        <h3>Approved 13th Month Pay</h3>
        <p id="pending-approvals-count"><?php echo number_format($Approved); ?></p>
      </div>
    </div>

    <div class="content">
      <div class="recent-activities-section">
        <div class="section-header">
          <h2>Recent Activities</h2>
        </div>
        <div id="activities-list" class="activities-list">
          <?php if (empty($activities)): ?>
            <p class="no-activities">No recent activities</p>
          <?php else: ?>
            <?php foreach ($activities as $activity): ?>
              <div class="activity-item">
                <div class="activity-icon"><?php echo htmlspecialchars($activity['icon']); ?></div>
                <div class="activity-details">
                  <p class="activity-text"><?php echo htmlspecialchars($activity['text']); ?></p>
                  <p class="activity-date"><?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Recent Employees Section -->
      <div class="recent-activities-section" style="margin-top: 30px;">
        <div class="section-header">
          <h2>Recent Employees</h2>
        </div>
        <?php if (empty($recentEmployees)): ?>
          <p class="no-activities">No employee records found</p>
        <?php else: ?>
          <table class="employee-table">
            <thead>
              <tr>
                <th>Employee No</th>
                <th>Name</th>
                <th>Department</th>
                <th>Date Hired</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentEmployees as $employee): ?>
                <tr onclick="window.location.href='employee-details.php?id=<?php echo $employee['id']; ?>'">
                  <td><?php echo htmlspecialchars($employee['employee_no']); ?></td>
                  <td><?php echo htmlspecialchars($employee['name']); ?></td>
                  <td><?php echo htmlspecialchars($employee['department_name']); ?></td>
                  <td><?php echo date('M j, Y', strtotime($employee['date_hired'])); ?></td>
                  <td>
                    <span class="badge-<?php echo strtolower($employee['status']); ?>">
                      <?php echo htmlspecialchars($employee['status']); ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <a href="employees.php" class="view-all-btn">View All Employees</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    // Navigation functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Handle sidebar navigation
      document.querySelectorAll('.sidebar nav li').forEach(item => {
        item.addEventListener('click', function() {
          const page = this.getAttribute('data-page');
          if (page) window.location.href = page;
        });
      });

      // Handle reports navigation
      document.querySelectorAll('.reports li').forEach(item => {
        item.addEventListener('click', function() {
          const page = this.getAttribute('data-page');
          if (page) window.location.href = page;
        });
      });
    });

    navItems.forEach((item) => {
    item.addEventListener("click", function () {
      navItems.forEach((i) => i.classList.remove("active"))
      this.classList.add("active")
      const targetPage = this.getAttribute("data-page")
      if (targetPage) window.location.href = targetPage
    })
  })
  </script>
</body>
</html>