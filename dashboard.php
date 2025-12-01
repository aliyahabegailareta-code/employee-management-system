<?php
// Start session and check if user is logged in
session_start();
if (!isset($_SESSION['employee_no'])) {
    header("Location: Login.php");
    exit();
}

// Database configuration
$host = 'localhost';
$dbname = 'employee_managements';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get employee data
$employee_no = $_SESSION['employee_no'];
$employee = null;
$latest_payslip = null;
$attendance_stats = null;

try {
    // Get employee basic info
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_no = ?");
    $stmt->execute([$employee_no]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($employee) {
        // Get latest payslip
        $stmt = $pdo->prepare("SELECT * FROM payroll_records WHERE employee_no = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$employee_no]);
        $latest_payslip = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get attendance statistics (you'll need to adjust this based on your attendance table structure)
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as days_present,
                SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))/3600) as hours_worked
            FROM attendance 
            WHERE employee_no = ? 
            AND status = 'Present'
            AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$employee_no]);
        $attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {
    // Handle error gracefully
    error_log("Database error: " . $e->getMessage());
}

// Format currency function
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

// Calculate attendance rate
if ($attendance_stats && $attendance_stats['days_present'] > 0) {
    $days_present = $attendance_stats['days_present'];
    $hours_worked = round($attendance_stats['hours_worked'] ?? 0);
    $attendance_rate = round(($days_present / 22) * 100); // Assuming 22 working days in a month
} else {
    // Default values if no attendance data
    $days_present = 22;
    $hours_worked = 176;
    $attendance_rate = 98;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Employee Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 50%, #e0e7ff 100%);
            color: #334155;
        }

        .dashboard {
            max-width: 360px;
            min-height: 100vh;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            border-radius: 0 0 18px 18px;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 10px;
            padding-top: 10px;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 5px;
        }

        .nav-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .nav-icon:hover {
            background: rgba(148, 163, 184, 0.1);
        }

        .nav-icon i {
            font-size: 18px;
            color: #475569;
        }

        .title {
            text-align: center;
            flex: 1;
        }

        .title h1 {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #0f172a, #475569);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .time-display {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 13px;
            color: #64748b;
            font-family: 'Courier New', monospace;
        }

        /* Main Content */
        .main-content {
            padding: 16px 8px 24px 8px;
        }

        .date-display {
            text-align: center;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 2px;
        }

        /* Profile Card */
        .profile-card {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
            transition: box-shadow 0.3s;
        }

        .profile-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .profile-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .profile-avatar {
            position: relative;
        }

        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            object-fit: cover;
        }

        .status-indicator {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 15px;
            height: 15px;
            background: #10b981;
            border: 2px solid white;
            border-radius: 50%;
        }

        .profile-info {
            flex: 1;
            padding: 0px;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .profile-role {
            color: #64748b;
            margin-bottom: 12px;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .badge-blue {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-green {
            background: #dcfce7;
            color: #166534;
        }

        .edit-btn {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid #e2e8f0;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .edit-btn:hover {
            background: rgba(255, 255, 255, 0.8);
            border-color: #cbd5e1;
        }

        /* Action Buttons */
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .action-card {
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .history-card {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .deductions-card {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }

        .action-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            transition: background-color 0.2s;
        }

        .action-card:hover .action-icon {
            background: rgba(255, 255, 255, 0.3);
        }

        .action-icon i {
            font-size: 16px;
        }

        .action-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .action-subtitle {
            font-size: 11px;
            opacity: 0.9;
        }

        /* Salary Section */
        .salary-card {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .salary-card.payslip-bg {
            background: #fff;
            border: 1.5px solid #e5e7eb;
            box-shadow: 0 4px 16px rgba(30, 41, 59, 0.06);
            border-radius: 16px;
            margin-bottom: 24px;
        }

        .salary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            color: #065f46;
        }

        .salary-header i {
            font-size: 20px;
        }

        .salary-header h2 {
            font-size: 18px;
            font-weight: 600;
        }

        .salary-amount {
            text-align: center;
            margin-bottom: 16px;
        }

        .salary-main {
            font-size: 40px;
            font-weight: 700;
            background: linear-gradient(135deg, #059669, #0d9488);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }

        .salary-label {
            font-size: 14px;
            color: #059669;
            font-weight: 500;
        }

        .salary-breakdown {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 20px;
        }

        .breakdown-item {
            background: rgba(255, 255, 255, 0.6);
            padding: 16px;
            border-radius: 12px;
            text-align: center;
        }

        .breakdown-label {
            font-size: 13px;
            color: #475569;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .breakdown-value {
            font-size: 16px;
            color: #059669;
            font-weight: 700;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 4px;
        }

        .stat-value.green {
            color: #059669;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
        }

        /* New separator design */
        .section-separator {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 24px 0 20px 0;
            position: relative;
        }

        .separator-line {
            flex: 1;
            height: 2px;
            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
            position: relative;
        }

        .separator-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 16px;
            position: relative;
            z-index: 2;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .separator-icon i {
            color: white;
            font-size: 16px;
        }

        .separator-text {
            position: absolute;
            top: -8px;
            background: white;
            padding: 0 12px;
            color: #3b82f6;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Enhanced download button */
        .download-btn-wrapper {
            position: relative;
        }

        .download-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 18px;
            height: 18px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: white;
            font-weight: bold;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            }
            50% {
                transform: scale(1.1);
                box-shadow: 0 3px 12px rgba(239, 68, 68, 0.6);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            }
        }

        /* Enhanced salary card header */
        .salary-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }

        .salary-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .salary-title i {
            font-size: 20px;
            color: #3b82f6;
            background: #dbeafe;
            padding: 8px;
            border-radius: 10px;
        }

        .salary-title h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .new-badge {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-2px);
            }
        }

        /* Responsive adjustments for very small screens */
        @media (max-width: 360px) {
            .profile-content {
                flex-direction: column;
                text-align: center;
                gap: 16px;
            }

            .salary-main {
                font-size: 32px;
            }

            .action-buttons {
                grid-template-columns: 1fr;
            }

            .separator-icon {
                width: 35px;
                height: 35px;
                margin: 0 12px;
            }

            .separator-text {
                font-size: 12px;
            }
        }

        @media (max-width: 480px) {
            .dashboard {
                max-width: 100vw;
                min-height: 100vh;
                border-radius: 0;
                margin: 0;
                box-shadow: none;
            }
            .main-content {
                padding: 8px 2px 16px 2px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <button class="nav-icon" onclick="confirmLogout()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                
                <div class="title">
                    <h1>Employee Dashboard</h1>
                    <div class="time-display">
                        <i class="fas fa-clock"></i>
                        <span id="current-time">12:02:00</span>
                    </div>
                </div>
                
                <div class="download-btn-wrapper">
                    <button class="nav-icon" id="download-payslip-btn">
                        <i class="fas fa-download"></i>
                    </button>
                    <div class="download-badge">!</div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Date Display -->
            <div class="date-display" id="current-date">
                Monday, January 15, 2024
            </div>

            <!-- Profile Card -->
            <div class="profile-card">
                <div class="profile-content">
                    <div class="profile-avatar">
                        <img id="profile-avatar" src="<?php echo $employee['profile_picture'] ?? 'https://images.pexels.com/photos/2379004/pexels-photo-2379004.jpeg?auto=compress&cs=tinysrgb&w=150&h=150&fit=crop'; ?>" 
                             alt="Profile" class="avatar">
                        <div class="status-indicator"></div>
                    </div>
                    
                    <div class="profile-info">
                        <h2 class="profile-name" id="profile-name"><?php echo htmlspecialchars($employee['name'] ?? 'Employee Name'); ?></h2>
                        <p class="profile-role"><?php echo htmlspecialchars($employee['department_name'] ?? 'Department'); ?></p>
                        <div class="badges">
                            <span class="badge badge-blue">
                                <i class="fas fa-hashtag"></i>
                                <span id="employee-number"><?php echo htmlspecialchars($employee['employee_no'] ?? 'EMP-XXXX-XXX'); ?></span>
                            </span>
                            <span class="badge badge-green">
                                <i class="fas fa-building"></i>
                                <span id="department"><?php echo htmlspecialchars($employee['status'] ?? 'Status'); ?></span>
                            </span>
                        </div>
                        
                        <button class="edit-btn" onclick="window.location.href='ChangeProfile.php'">
                            <i class="fas fa-edit"></i>
                            Edit Profile
                        </button>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="action-card history-card" onclick="window.location.href='History.php'">
                    <div class="action-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <h3 class="action-title">History</h3>
                    <p class="action-subtitle">View past payslips</p>
                </button>

                <button class="action-card deductions-card" onclick="window.location.href='Deductions.php'">
                    <div class="action-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <h3 class="action-title">Deductions</h3>
                    <p class="action-subtitle">View deductions</p>
                </button>
            </div>

            <!-- Decorative Separator -->
            <div class="section-separator">
                <div class="separator-line"></div>
                <div class="separator-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="separator-line"></div>
                <div class="separator-text">Current Payslip</div>
            </div>

            <!-- Salary Section -->
            <div class="salary-card payslip-bg" style="padding: 0; overflow-x: auto;">
                <div class="salary-card-header">
                    <div class="salary-title">
                        <i class="fas fa-file-invoice"></i>
                        <h2>Weekly Payslip</h2>
                    </div>
                    <div class="new-badge">
                        <i class="fas fa-star"></i>
                        NEW
                    </div>
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:15px; background:transparent;">
                    <tr>
                        <th colspan="2" style="text-align:center; font-size:18px; padding:8px 0 16px 0; color:#0f172a;">
                            YAZAKI TORRES MANUFACTURING INCORPORATED
                        </th>
                    </tr>
                    <tr><td style="width:50%;">Employee Name:</td><td id="payslip-employee-name"><b><?php echo htmlspecialchars($employee['name'] ?? 'Employee Name'); ?></b></td></tr>
                    <tr><td>Employee ID:</td><td id="payslip-employee-id"><?php echo htmlspecialchars($employee['employee_no'] ?? 'EMP-XXXX-XXX'); ?></td></tr>
                    <tr><td>Position:</td><td id="payslip-position"><?php echo htmlspecialchars($employee['department_name'] ?? 'Position'); ?></td></tr>
                    <tr><td>Pay Week:</td><td id="payslip-payweek"><?php echo htmlspecialchars($latest_payslip['week_period'] ?? 'YYYY-MM-DD to YYYY-MM-DD'); ?></td></tr>
                    <tr><td>Basic Salary:</td><td id="payslip-basic-salary"><?php echo formatCurrency($latest_payslip['basic_salary'] ?? 0); ?></td></tr>
                    <tr><td>Overtime Pay:</td><td id="payslip-overtime"><?php echo formatCurrency($latest_payslip['overtime_pay'] ?? 0); ?></td></tr>
                    <tr><td>Allowances:</td><td id="payslip-allowances"><?php echo formatCurrency($latest_payslip['allowances'] ?? 0); ?></td></tr>
                    <tr><td>Gross Pay:</td><td id="payslip-gross"><?php echo formatCurrency($latest_payslip['gross_pay'] ?? 0); ?></td></tr>
                    <tr><td>Pag-ibig:</td><td id="payslip-pagibig"><?php echo formatCurrency($latest_payslip['pagibig'] ?? 0); ?></td></tr>
                    <tr><td>SSS:</td><td id="payslip-sss"><?php echo formatCurrency($latest_payslip['sss'] ?? 0); ?></td></tr>
                    <tr><td>PhilHealth:</td><td id="payslip-philhealth"><?php echo formatCurrency($latest_payslip['philhealth'] ?? 0); ?></td></tr>
                    <tr>
                        <td colspan="2"><hr style="border:1px solid #2563eb; margin:8px 0;"></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;">Net Pay:</td>
                        <td id="payslip-netpay" style="font-weight:bold; color:#059669; font-size:17px;"><?php echo formatCurrency($latest_payslip['net_salary'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <td>Generated on:</td>
                        <td id="payslip-generated"><?php echo date('m/d/Y'); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $days_present; ?></div>
                    <div class="stat-label">Days Present</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo $hours_worked; ?></div>
                    <div class="stat-label">Hours Worked</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value green"><?php echo $attendance_rate; ?>%</div>
                    <div class="stat-label">Attendance Rate</div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add jsPDF libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script>
        // Update profile picture from localStorage
        function updateProfilePicture() {
            const savedImage = localStorage.getItem('profilePicture');
            const profileAvatar = document.getElementById('profile-avatar');
            
            if (savedImage) {
                profileAvatar.src = savedImage;
            }
        }

        // Confirm logout
        function confirmLogout() {
            if (confirm('Are you sure you want to log out?')) {
                window.location.href = 'logout.php';
            }
        }

        // Download salary as PDF
        function downloadSalaryPDF() {
            try {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();

                // Get current date and time
                const now = new Date();
                const dateStr = now.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                const timeStr = now.toLocaleTimeString('en-US', {
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit'
                });

                // Header (company and payslip title)
                doc.setFontSize(16);
                doc.text('YAZAKI TORRES MANUFACTURING INCORPORATED', 105, 18, { align: 'center' });
                doc.setFontSize(13);
                doc.text('Weekly Payslip', 105, 28, { align: 'center' });

                // Gather payslip data
                const getVal = id => {
                    const el = document.getElementById(id);
                    if (!el) throw new Error(`Missing element: ${id}`);
                    return el.textContent;
                };
                const details = [
                    ['Employee Name:', getVal('payslip-employee-name')],
                    ['Employee ID:', getVal('payslip-employee-id')],
                    ['Position:', getVal('payslip-position')],
                    ['Pay Week:', getVal('payslip-payweek')],
                    ['Basic Salary:', getVal('payslip-basic-salary')],
                    ['Overtime Pay:', getVal('payslip-overtime')],
                    ['Allowances:', getVal('payslip-allowances')],
                    ['Gross Pay:', getVal('payslip-gross')],
                    ['Pag-ibig:', getVal('payslip-pagibig')],
                    ['SSS:', getVal('payslip-sss')],
                    ['PhilHealth:', getVal('payslip-philhealth')]
                ];

                let y = 42;
                doc.setFontSize(12);
                details.forEach(([label, value]) => {
                    doc.text(label, 14, y);
                    doc.text(value, 70, y);
                    y += 9;
                });

                // Draw a horizontal line before Net Pay
                y += 4;
                doc.setDrawColor(37, 99, 235);
                doc.setLineWidth(0.8);
                doc.line(14, y, 196, y);
                y += 10;

                // Net Pay (bold, green)
                doc.setFontSize(13);
                doc.setFont(undefined, 'bold');
                doc.setTextColor(5, 150, 105);
                doc.text('Net Pay:', 14, y);
                doc.text(getVal('payslip-netpay'), 70, y);
                doc.setFont(undefined, 'normal');
                doc.setTextColor(33, 37, 41);
                y += 12;

                // Generated on
                doc.setFontSize(11);
                doc.text('Generated on:', 14, y);
                doc.text(getVal('payslip-generated'), 70, y);
                y += 10;

                // Footer
                const pageCount = doc.internal.getNumberOfPages();
                for (let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(8);
                    doc.text(
                        'This is a computer-generated document. No signature is required.',
                        14,
                        doc.internal.pageSize.height - 10
                    );
                    doc.text(
                        `Page ${i} of ${pageCount}`,
                        doc.internal.pageSize.width - 20,
                        doc.internal.pageSize.height - 10,
                        { align: 'right' }
                    );
                }

                // Download the PDF
                doc.save(`weekly_payslip_${dateStr.replace(/,/g, '')}.pdf`);
            } catch (err) {
                alert('Error generating PDF: ' + err.message);
            }
        }

        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('current-time').textContent = timeString;
        }

        // Update current date
        function updateDate() {
            const now = new Date();
            const dateString = now.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            document.getElementById('current-date').textContent = dateString;
        }

        // Initialize time and date
        updateTime();
        updateDate();
        setInterval(updateTime, 1000);

        // Enhanced download button with visual feedback
        document.getElementById('download-payslip-btn').addEventListener('click', async function() {
            try {
                // Show loading state with animation
                const originalHTML = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                this.style.transform = 'scale(0.9)';
                
                // Add click animation
                this.style.background = 'rgba(59, 130, 246, 0.1)';
                
                // Generate and download PDF
                await new Promise(resolve => setTimeout(resolve, 500)); // Simulate processing
                downloadSalaryPDF();
                
                // Reset button state with success feedback
                setTimeout(() => {
                    this.innerHTML = '<i class="fas fa-check"></i>';
                    this.style.background = 'rgba(16, 185, 129, 0.1)';
                    
                    // Remove badge after download
                    const badge = document.querySelector('.download-badge');
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    
                    // Reset to original state after 2 seconds
                    setTimeout(() => {
                        this.innerHTML = originalHTML;
                        this.style.background = '';
                        this.style.transform = '';
                    }, 2000);
                }, 1000);
                
            } catch (error) {
                console.error('Error downloading payslip:', error);
                this.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                this.style.background = 'rgba(239, 68, 68, 0.1)';
                
                setTimeout(() => {
                    this.innerHTML = '<i class="fas fa-download"></i>';
                    this.style.background = '';
                    this.style.transform = '';
                }, 2000);
                
                alert('Failed to download payslip. Please try again later.');
            }
        });

        // History button functionality
        document.querySelector('.history-card').addEventListener('click', function() {
            window.location.href = 'History.php';
        });

        // Deductions button functionality
        document.querySelector('.deductions-card').addEventListener('click', function() {
            window.location.href = 'Deductions.php';
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Update profile picture
            updateProfilePicture();
            
            // Add click handler to download button
            const downloadBtn = document.getElementById('download-payslip-btn');
            if (downloadBtn) {
                downloadBtn.addEventListener('click', downloadSalaryPDF);
            }
            
            // Add touch feedback for mobile
            document.querySelectorAll('.action-card, .nav-icon, .edit-btn').forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.95)';
                });
                element.addEventListener('touchend', function() {
                    this.style.transform = '';
                });
            });

            // Add hover effects for desktop
            document.querySelectorAll('.action-card, .nav-icon, .edit-btn').forEach(element => {
                element.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                element.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });
        });

        // Add smooth scrolling for better UX
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Prevent zoom on double-tap for mobile
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function (event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // Handle orientation changes
        window.addEventListener('orientationchange', function() {
            // Force a resize to ensure proper layout
            setTimeout(function() {
                window.dispatchEvent(new Event('resize'));
            }, 100);
        });
    </script>
</body>
</html>