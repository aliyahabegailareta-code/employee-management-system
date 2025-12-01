<?php
// department.php
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

// Get all employees
$stmt = $conn->query("SELECT * FROM employees");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department counts
$deptCounts = [];
$departments = ['Ford 1', 'Ford 2', 'Nissan 1', 'Nissan 3', 'Nissan 4', 'Ytmci'];
foreach ($departments as $dept) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE department_name = ?");
    $stmt->execute([$dept]);
    $deptCounts[$dept] = $stmt->fetchColumn();
}

// Get shift counts
$stmt = $conn->query("SELECT shift, COUNT(*) FROM employees GROUP BY shift");
$shiftCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_shift'])) {
        $employeeId = $_POST['employee_id'];
        $newShift = $_POST['shift'];
        
        $stmt = $conn->prepare("UPDATE employees SET shift = ? WHERE employee_no = ?");
        $stmt->execute([$newShift, $employeeId]);
        
        $_SESSION['success'] = "Shift updated successfully!";
        header("Location: department.php");
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
  <title>Departments</title>
  
  <style>
        /* Department table styles */
    .department-table-container {
      display: none;
      margin-top: 20px;
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
      padding: 4px 6px;
      margin: 0 2px;
      cursor: pointer;
    }
    
    .btn-view {
      background-color: #007bff;
    }
    
    .btn-edit {
      background-color: #28a745;
    }
    
    .dept-card {
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .dept-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }
    
    .back-button {
      background-color: #6c757d;
      color: white;
      border: none;
      padding: 8px 15px;
      border-radius: 4px;
      cursor: pointer;
      margin-bottom: 15px;
    }
    
    .back-button:hover {
      background-color: #5a6268;
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
      background: none;
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
    
    .search-button {
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
    
    /* Shift summary styles */
    .shift-summary {
      display: flex;
      gap: 20px;
      margin-bottom: 20px;
    }
    
    .shift-card {
      flex: 1;
      background-color: white;
      border-radius: 8px;
      padding: 15px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
      text-align: center;
    }
    
    .shift-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }
    
    .shift-card h3 {
      margin-top: 0;
      color: #003399;
    }
    
    .shift-card p {
      font-size: 24px;
      font-weight: bold;
      margin: 10px 0;
    }
    
    .shift-a {
      border-left: 5px solid #28a745;
    }
    
    .shift-b {
      border-left: 5px solid #007bff;
    }
    
    /* Shift table container */
    .shift-table-container {
      display: none;
      margin-top: 20px;
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
        <li data-page="employees.php"><i class="icon">👤</i><span>Employees</span></li>
        <li data-page="attendance.php"><i class="icon">📋</i><span>Attendance</span></li>
        <li data-page="payroll.php"><i class="icon">📝</i><span>Payroll Processing</span></li>
        <li data-page="department.php" class="active"><i class="icon">🏢</i><span>Department</span></li>
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
      <span class="logo-text-right">Department</span>
    </div>

    <div class="payroll-section">
      <!-- Shift Summary Section -->
      <div id="shiftSummary" class="shift-summary">
        <div class="shift-card shift-a" id="shiftACard">
          <h3>Shift A</h3>
          <p id="shiftACount"><?= $shiftCounts['Shift A'] ?? 0 ?></p>
          <span>Employees</span>
        </div>
        <div class="shift-card shift-b" id="shiftBCard">
          <h3>Shift B</h3>
          <p id="shiftBCount"><?= $shiftCounts['Shift B'] ?? 0 ?></p>
          <span>Employees</span>
        </div>
      </div>
      
      <h2 class="dept-section-title">Department</h2>
      <div class="department-grid">
        <?php foreach ($departments as $dept): ?>
        <div class="dept-card" data-department="<?= $dept ?>">
          <h3><?= $dept ?></h3>
          <p><strong><?= $deptCounts[$dept] ?? 0 ?></strong> Employees</p>
        </div>
        <?php endforeach; ?>
      </div>
      
      <!-- Department Table Container -->
      <div id="departmentTableContainer" class="department-table-container">
        <button id="backToDepartments" class="back-button">← Back to Departments</button>
        <div class="section-header">
          <h2 id="departmentTableTitle">Department Employees</h2>
          <div class="search-container">
            <div style="position: relative;">
              <input type="text" id="departmentSearch" class="search-input" placeholder="Search employees...">
              <button id="reloadButton" class="reload-button">↻</button>
            </div>
            <button id="searchButton" class="search-button">Search</button>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>Employee No.</th>
              <th>Employee Name</th>
              <th>Department</th>
              <th>Shift</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="departmentTableBody">
            <?php foreach ($employees as $employee): ?>
            <tr>
              <td><?= htmlspecialchars($employee['employee_no']) ?></td>
              <td><?= htmlspecialchars($employee['name']) ?></td>
              <td><?= htmlspecialchars($employee['department_name']) ?></td>
              <td><?= htmlspecialchars($employee['shift'] ?? 'Not Set') ?></td>
              <td>
                <button class="action-btn btn-edit" onclick="editShift('<?= $employee['employee_no'] ?>')">✏️</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- View Employee Modal -->
  <div id="viewEmployeeModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Employee Details</h2>
      <div class="employee-details" id="employeeDetails">
        <!-- Content will be loaded via AJAX -->
      </div>
      <div class="form-buttons">
        <button type="button" class="btn-cancel" id="closeViewEmployee">Close</button>
      </div>
    </div>
  </div>

  <!-- Edit Shift Modal -->
  <div id="editShiftModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Edit Shift</h2>
      <form id="editShiftForm" method="POST">
        <input type="hidden" name="update_shift" value="1">
        <input type="hidden" id="editEmployeeId" name="employee_id">
        <div class="form-group">
          <label for="editEmployeeNoDisplay">Employee No.</label>
          <input type="text" id="editEmployeeNoDisplay" readonly>
        </div>
        <div class="form-group">
          <label for="editEmployeeNameDisplay">Employee Name</label>
          <input type="text" id="editEmployeeNameDisplay" readonly>
        </div>
        <div class="form-group">
          <label for="editDepartmentDisplay">Department</label>
          <input type="text" id="editDepartmentDisplay" readonly>
        </div>
        <div class="form-group">
          <label for="editShift">Shift</label>
          <select id="editShift" name="shift" required>
            <option value="">Select Shift</option>
            <option value="Shift A">Shift A</option>
            <option value="Shift B">Shift B</option>
          </select>
        </div>
        <div class="form-buttons">
          <button type="button" class="btn-cancel" id="cancelEditShift">Cancel</button>
          <button type="submit" class="btn-save">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // View employee details
    function viewEmployee(employeeNo) {
      fetch(`get_employee_details.php?employee_no=${employeeNo}`)
        .then(response => response.json())
        .then(data => {
          if (data.error) {
            throw new Error(data.error);
          }
          
          let html = `
            <div class="employee-details-container">
              <h3>Employee Information</h3>
              <div class="employee-info">
                <p><strong>Employee No:</strong> ${data.employee_no}</p>
                <p><strong>Name:</strong> ${data.name}</p>
                <p><strong>Department:</strong> ${data.department_name}</p>
                <p><strong>Date Hired:</strong> ${data.date_hired}</p>
                <p><strong>Position:</strong> ${data.position || 'N/A'}</p>
                <p><strong>Contact:</strong> ${data.contact || 'N/A'}</p>
                <p><strong>Email:</strong> ${data.email || 'N/A'}</p>
                <p><strong>Address:</strong> ${data.address || 'N/A'}</p>
                <p><strong>Birthday:</strong> ${data.birthday || 'N/A'}</p>
                <p><strong>Gender:</strong> ${data.gender || 'N/A'}</p>
                <p><strong>Civil Status:</strong> ${data.civil_status || 'N/A'}</p>
                <p><strong>SSS No:</strong> ${data.sss_no || 'N/A'}</p>
                <p><strong>PhilHealth No:</strong> ${data.philhealth_no || 'N/A'}</p>
                <p><strong>Pag-IBIG No:</strong> ${data.pagibig_no || 'N/A'}</p>
                <p><strong>Tax ID:</strong> ${data.tax_id || 'N/A'}</p>
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

    // Edit employee shift
    function editShift(employeeNo) {
      fetch(`get_employee_details.php?employee_no=${employeeNo}`)
        .then(response => response.json())
        .then(data => {
          if (!data || data.error) {
            alert('Employee not found or error loading data.');
            return;
          }
          document.getElementById('editEmployeeId').value = data.employee_no || '';
          document.getElementById('editEmployeeNoDisplay').value = data.employee_no || '';
          document.getElementById('editEmployeeNameDisplay').value = data.name || '';
          document.getElementById('editDepartmentDisplay').value = data.department_name || '';
          document.getElementById('editShift').value = data.shift || '';
          document.getElementById('editShiftModal').style.display = 'block';
        })
        .catch(error => {
          alert('Error loading employee data: ' + error.message);
        });
    }

    // Department card click handlers
    document.querySelectorAll('.dept-card').forEach(card => {
      card.addEventListener('click', function() {
        const department = this.getAttribute('data-department');
        document.querySelector('.department-grid').style.display = 'none';
        document.getElementById('shiftSummary').style.display = 'none';
        document.getElementById('departmentTableContainer').style.display = 'block';
        document.getElementById('departmentTableTitle').textContent = `${department} Employees`;
        
        // Filter table rows
        const rows = document.querySelectorAll('#departmentTableBody tr');
        rows.forEach(row => {
          if (row.cells[2].textContent === department) {
            row.style.display = '';
          } else {
            row.style.display = 'none';
          }
        });
      });
    });

    // Shift card click handlers
    document.getElementById('shiftACard').addEventListener('click', function() {
      showShiftEmployees('Shift A');
    });

    document.getElementById('shiftBCard').addEventListener('click', function() {
      showShiftEmployees('Shift B');
    });

    function showShiftEmployees(shift) {
      document.querySelector('.department-grid').style.display = 'none';
      document.getElementById('shiftSummary').style.display = 'none';
      document.getElementById('departmentTableContainer').style.display = 'block';
      document.getElementById('departmentTableTitle').textContent = `${shift} Employees`;
      
      // Filter table rows
      const rows = document.querySelectorAll('#departmentTableBody tr');
      rows.forEach(row => {
        if (row.cells[3].textContent === shift) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    // Back button handler
    document.getElementById('backToDepartments').addEventListener('click', function() {
      document.querySelector('.department-grid').style.display = 'grid';
      document.getElementById('shiftSummary').style.display = 'flex';
      document.getElementById('departmentTableContainer').style.display = 'none';
      
      // Show all rows
      const rows = document.querySelectorAll('#departmentTableBody tr');
      rows.forEach(row => row.style.display = '');
    });

    // Search functionality
    document.getElementById('searchButton').addEventListener('click', function() {
      const searchTerm = document.getElementById('departmentSearch').value.toLowerCase();
      const rows = document.querySelectorAll('#departmentTableBody tr');

      rows.forEach(row => {
        if (row.style.display === 'none') return;
        
        const employeeNo = row.cells[0].textContent.toLowerCase();
        const employeeName = row.cells[1].textContent.toLowerCase();
        const department = row.cells[2].textContent.toLowerCase();

        if (employeeNo.includes(searchTerm) || 
            employeeName.includes(searchTerm) || 
            department.includes(searchTerm)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });

    // Reload button
    document.getElementById('reloadButton').addEventListener('click', function() {
      document.getElementById('departmentSearch').value = '';
      const rows = document.querySelectorAll('#departmentTableBody tr');
      rows.forEach(row => {
        row.style.display = '';
      });
    });

    // Modal close buttons
    document.querySelectorAll('.modal .close').forEach(closeBtn => {
      closeBtn.addEventListener('click', function() {
        this.closest('.modal').style.display = 'none';
      });
    });

    // Close buttons
    document.getElementById('closeViewEmployee')?.addEventListener('click', function() {
      document.getElementById('viewEmployeeModal').style.display = 'none';
    });

    document.getElementById('cancelEditShift')?.addEventListener('click', function() {
      document.getElementById('editShiftModal').style.display = 'none';
    });

    // Close when clicking outside modal
    window.addEventListener('click', function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
      }
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