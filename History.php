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

// Get employee data and salary history
$employee_no = $_SESSION['employee_no'];
$employee = null;
$salary_data = [];

// Yazaki Torres company information
$company_info = [
    'name' => 'YAZAKI TORRES MANUFACTURING INC.',
    'address' => 'Yazaki Torres Manufacturing, Inc.',
    'contact' => 'Yazaki Torres Manufacturing Incorporated'
];

try {
    // Get employee basic info - including department
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_no = ?");
    $stmt->execute([$employee_no]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($employee) {
        // Get salary data from payroll_records
        $stmt = $pdo->prepare("
            SELECT 
                week_period,
                gross_pay,
                total_deductions,
                net_salary,
                created_at
            FROM payroll_records 
            WHERE employee_no = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$employee_no]);
        $payroll_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process salary data
        foreach ($payroll_records as $record) {
            // Extract year and month from week_period
            preg_match('/(\d{4})[^\d]*(\d{1,2})/', $record['week_period'], $matches);
            $year = $matches[1] ?? date('Y');
            $month = $matches[2] ?? date('m');

            $salary_data[] = [
                'date' => $record['week_period'],
                'gross_pay' => floatval($record['gross_pay']),
                'deductions' => floatval($record['total_deductions']),
                'net_pay' => floatval($record['net_salary']),
                'status' => 'Paid',
                'year' => $year,
                'month' => intval($month)
            ];
        }

        // Get available years from salary data
        $available_years = [];
        foreach ($salary_data as $record) {
            if (!in_array($record['year'], $available_years)) {
                $available_years[] = $record['year'];
            }
        }
        rsort($available_years);
    }
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Get department name - check different possible column names
$department = 'N/A';
if ($employee) {
    // Check for department in different possible column names
    if (isset($employee['department']) && !empty($employee['department'])) {
        $department = $employee['department'];
    } elseif (isset($employee['departments']) && !empty($employee['departments'])) {
        $department = $employee['departments'];
    } elseif (isset($employee['dept']) && !empty($employee['dept'])) {
        $department = $employee['dept'];
    } elseif (isset($employee['department_name']) && !empty($employee['department_name'])) {
        $department = $employee['department_name'];
    }
}

// Format currency function
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Salary History</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #1e293b;
            color: #334155;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            width: 360px;
            max-width: 100vw;
            min-height: 735px;
            height: 735px;
            margin: 0 auto;
            padding: 0.75rem 0.25rem 1.35rem 0.25rem;
            background: #f8fafc;
            position: relative;
            box-shadow: 0 0 20px rgba(0,0,0,0.12);
            border-radius: 18px;
        }

        .header {
            text-align: center;
            margin-bottom: 0.85rem;
            padding-top: 0.1rem;
        }

        .header h1 {
            font-size: 1.375rem;
            font-weight: bold;
            color: #1e293b;
            margin-top: 10px;
            margin-bottom: 20px;
            padding: 20px;
        }

        .back-button {
            display: none;
        }

        .history-section {
            display: block;
        }

        .filters {
            background: white;
            border-radius: 16px;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            width: 95%;
            margin-left: auto;
            margin-right: auto;
        }

        .filter-row {
            display: flex;
            flex-direction: row;
            gap: 0.5rem;
            justify-content: space-between;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.1rem;
            color: #374151;
        }

        .filter-group select {
            padding: 0.25rem;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.875rem;
            background: white;
            color: #374151;
            font-weight: 500;
            width: 100%;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 0.5rem;
            width: 95%;
            margin-left: auto;
            margin-right: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .table th {
            background: #f8fafc;
            padding: 0.375rem 0.1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            color: #64748b;
        }

        .table td {
            padding: 0.375rem 0.1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.75rem;
            color: #374151;
        }

        .table tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .gross-pay {
            color: #059669;
            font-weight: 600;
        }

        .deductions {
            color: #dc2626;
            font-weight: 600;
        }

        .net-pay {
            color: #2563eb;
            font-weight: 600;
        }

        .download-btn {
            width: 95%;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 16px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.1rem;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            transition: all 0.2s ease;
            margin: 0 auto;
        }

        .download-btn:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }

        .download-btn:active {
            transform: translateY(0);
        }

        .no-data {
            text-align: center;
            padding: 2rem 1rem;
            color: #64748b;
            font-style: italic;
        }

        /* Mobile phone specific optimizations */
        @media (max-width: 480px) {
            .container {
                width: 100vw;
                max-width: 100vw;
                min-height: 100vh;
                height: 100vh;
                border-radius: 0;
                margin: 0;
                box-shadow: none;
                padding: 0.1rem 0.1rem 0.5rem 0.1rem;
            }

            .filters,
            .table-container,
            .download-btn {
                width: 92%;
            }

            .filter-row {
                flex-direction: column;
                gap: 0.25rem;
            }
        }

        /* Prevent zoom on input focus */
        @media (max-width: 768px) {
            select {
                font-size: 16px;
            }
        }

        /* Back arrow button */
        .back-arrow-btn {
            position: absolute;
            top: 2px;
            left: 2px;
            z-index: 10;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 1.25rem;
            transition: color 0.2s;
            width: 16px;
            height: 16px;
            cursor: pointer;
            padding: 20px;
        }

        .back-arrow-btn:hover {
            color: #1e293b;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Back Arrow -->
        <a href="Dashboard.php" class="back-arrow-btn">
            &#8592;
        </a>

        <!-- History Section -->
        <div class="history-section">
            <div class="header">
                <h1>Salary History</h1>
            </div>

            <div class="filters">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="yearFilter">Year</label>
                        <select id="yearFilter" onchange="filterData()">
                            <option value="">All Years</option>
                            <?php if (isset($available_years)): ?>
                                <?php foreach ($available_years as $year): ?>
                                    <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="2025">2025</option>
                                <option value="2024">2024</option>
                                <option value="2023">2023</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="monthFilter">Month</label>
                        <select id="monthFilter" onchange="filterData()">
                            <option value="">All Months</option>
                            <option value="1">January</option>
                            <option value="2">February</option>
                            <option value="3">March</option>
                            <option value="4">April</option>
                            <option value="5">May</option>
                            <option value="6">June</option>
                            <option value="7">July</option>
                            <option value="8">August</option>
                            <option value="9">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Pay Period</th>
                            <th>Gross Pay</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- Data will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <button class="download-btn" onclick="downloadHistory()">
                📥 Download Salary History
            </button>
        </div>
    </div>

    <!-- Add jsPDF libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script>
        // Convert PHP data to JavaScript
        const salaryData = <?php echo json_encode($salary_data); ?>;
        const companyInfo = <?php echo json_encode($company_info); ?>;
        const employeeDepartment = '<?php echo htmlspecialchars($department); ?>';
        let allData = [];
        let filteredData = [];

        // Format date for mobile
        function formatDate(dateString) {
            try {
                const date = new Date(dateString);
                if (isNaN(date.getTime())) {
                    return dateString;
                }
                return date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: '2-digit'
                });
            } catch (e) {
                return dateString;
            }
        }

        // Format currency with peso sign
        function formatCurrency(amount) {
            return '₱' + numberFormat(amount);
        }

        // Format number with commas and 2 decimal places
        function numberFormat(amount) {
            return amount.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Format currency for PDF (with proper peso sign)
        function formatCurrencyForPDF(amount) {
            return '₱' + numberFormat(amount);
        }

        // Initialize data
        function initializeData() {
            allData = salaryData || [];
            filterData();
        }

        // Filter data based on selected filters
        function filterData() {
            const year = document.getElementById('yearFilter').value;
            const month = document.getElementById('monthFilter').value;

            filteredData = [...allData];

            if (year) {
                filteredData = filteredData.filter(item => item.year == year);
            }

            if (month) {
                filteredData = filteredData.filter(item => item.month == parseInt(month));
            }

            // Sort by date (newest first)
            filteredData.sort((a, b) => new Date(b.date) - new Date(a.date));

            displayData();
        }

        // Display data in table
        function displayData() {
            const tableBody = document.getElementById('tableBody');
            
            if (filteredData.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="4" class="no-data">
                            No salary records found for selected filters
                        </td>
                    </tr>
                `;
                return;
            }

            tableBody.innerHTML = filteredData.map(item => `
                <tr>
                    <td>${formatDate(item.date)}</td>
                    <td class="gross-pay">${formatCurrency(item.gross_pay)}</td>
                    <td class="deductions">${formatCurrency(item.deductions)}</td>
                    <td class="net-pay">${formatCurrency(item.net_pay)}</td>
                </tr>
            `).join('');
        }

        // Download history as PDF
        function downloadHistory() {
            if (filteredData.length === 0) {
                alert('No salary data available to download');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            // Set PDF properties
            doc.setProperties({
                title: `Salary History - ${companyInfo.name}`,
                subject: 'Employee Salary History Record',
                author: companyInfo.name,
                creator: 'Employee Management System'
            });

            // Company Header - YAZAKI TORRES
            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text(companyInfo.name, 105, 15, { align: 'center' });
            
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text('Employee Salary History Record', 105, 22, { align: 'center' });
            
            // Add a line separator
            doc.setLineWidth(0.5);
            doc.line(14, 27, 196, 27);

            // Set title
            doc.setFontSize(14);
            doc.setFont('helvetica', 'bold');
            doc.text('SALARY HISTORY', 105, 37, { align: 'center' });

            // Employee Info
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Employee Name: ${'<?php echo htmlspecialchars($employee['name'] ?? 'N/A'); ?>'}`, 14, 47);
            doc.text(`Employee No: ${'<?php echo htmlspecialchars($employee['employee_no'] ?? 'N/A'); ?>'}`, 14, 54);
            
            // Only show department if it's not "N/A"
            if (employeeDepartment !== 'N/A') {
                doc.text(`Department: ${employeeDepartment}`, 14, 61);
                
                // Adjust Y positions for the rest of the content
                var currentY = 68;
            } else {
                // Skip department line if N/A
                var currentY = 61;
            }

            // Add date range if filters are applied
            const year = document.getElementById('yearFilter').value;
            const month = document.getElementById('monthFilter').value;
            
            let dateRange = '';
            if (year) dateRange += year;
            if (month) dateRange += ` - ${getMonthName(parseInt(month))}`;
            
            if (dateRange) {
                doc.text(`Period: ${dateRange}`, 14, currentY);
                currentY += 7;
            }

            // Calculate totals
            const totalGross = filteredData.reduce((sum, item) => sum + item.gross_pay, 0);
            const totalDeductions = filteredData.reduce((sum, item) => sum + item.deductions, 0);
            const totalNet = filteredData.reduce((sum, item) => sum + item.net_pay, 0);

            doc.text(`Total Records: ${filteredData.length}`, 14, currentY);
            currentY += 7;

            // Prepare table data - FIXED PESO SIGN ISSUE
            const tableData = filteredData.map(item => {
                // Create custom formatted amounts with peso sign that works in PDF
                // Use function-based replacement to avoid $& ampersand issue
                const formatNumber = (num) => {
                    return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, (match) => match + ',');
                };
                const formattedGross = '₱' + formatNumber(item.gross_pay);
                const formattedDeductions = '₱' + formatNumber(item.deductions);
                const formattedNet = '₱' + formatNumber(item.net_pay);
                
                return [
                    formatDate(item.date),
                    formattedGross,
                    formattedDeductions,
                    formattedNet
                ];
            });

            // Add table
            doc.autoTable({
                startY: currentY + 10,
                head: [['Pay Period', 'Gross Pay', 'Deductions', 'Net Pay']],
                body: tableData,
                theme: 'grid',
                styles: {
                    fontSize: 8,
                    cellPadding: 3,
                    font: 'helvetica',
                    textColor: [0, 0, 0],
                    lineColor: [0, 0, 0],
                    lineWidth: 0.1
                },
                headStyles: {
                    fillColor: [59, 130, 246],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold',
                    textAlign: 'center',
                    font: 'helvetica',
                    lineWidth: 0.1
                },
                bodyStyles: {
                    textAlign: 'center',
                    font: 'helvetica',
                    lineWidth: 0.1
                },
                columnStyles: {
                    0: { cellWidth: 45, halign: 'center' },
                    1: { cellWidth: 40, halign: 'center' },
                    2: { cellWidth: 40, halign: 'center' },
                    3: { cellWidth: 40, halign: 'center' }
                },
                alternateRowStyles: {
                    fillColor: [240, 240, 240]
                },
                margin: { top: currentY + 10 },
                tableLineColor: [0, 0, 0],
                tableLineWidth: 0.1
            });

            // Get final Y position after table
            const finalY = doc.lastAutoTable.finalY || currentY + 10;

            // Add summary
            doc.setFontSize(10);
            doc.setFont('helvetica', 'bold');
            
            // Format totals with proper peso sign - use function to avoid $& issue
            const formatNumberForPDF = (num) => {
                return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, (match) => match + ',');
            };
            const formattedTotalGross = '₱' + formatNumberForPDF(totalGross);
            const formattedTotalDeductions = '₱' + formatNumberForPDF(totalDeductions);
            const formattedTotalNet = '₱' + formatNumberForPDF(totalNet);
            
            doc.text('SUMMARY:', 14, finalY + 10);
            doc.text(`Total Gross Pay: ${formattedTotalGross}`, 14, finalY + 17);
            doc.text(`Total Deductions: ${formattedTotalDeductions}`, 14, finalY + 24);
            doc.text(`Total Net Pay: ${formattedTotalNet}`, 14, finalY + 31);

            // Add footer on all pages
            const pageCount = doc.internal.getNumberOfPages();
            for (let i = 1; i <= pageCount; i++) {
                doc.setPage(i);
                
                // Footer line
                doc.setLineWidth(0.3);
                doc.line(14, doc.internal.pageSize.height - 20, 196, doc.internal.pageSize.height - 20);
                
                doc.setFontSize(8);
                doc.setFont('helvetica', 'normal');
                
                // Left footer - generation date
                doc.text(
                    `Generated: <?php echo date('M d, Y h:i A'); ?>`,
                    14,
                    doc.internal.pageSize.height - 15
                );
                
                // Center footer - company name
                doc.text(
                    companyInfo.name,
                    105,
                    doc.internal.pageSize.height - 15,
                    { align: 'center' }
                );
                
                // Right footer - page number
                doc.text(
                    `Page ${i} of ${pageCount}`,
                    196,
                    doc.internal.pageSize.height - 15,
                    { align: 'right' }
                );
            }

            // Save PDF with proper filename
            const dateStamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
            const filename = `Salary_History_${'<?php echo $employee_no; ?>'}_${dateStamp}.pdf`;
            
            // Use multiple methods to ensure download works
            try {
                // Method 1: Direct save
                doc.save(filename);
            } catch (error) {
                try {
                    // Method 2: Blob download
                    const pdfBlob = doc.output('blob');
                    const downloadUrl = URL.createObjectURL(pdfBlob);
                    
                    const downloadLink = document.createElement('a');
                    downloadLink.href = downloadUrl;
                    downloadLink.download = filename;
                    document.body.appendChild(downloadLink);
                    downloadLink.click();
                    document.body.removeChild(downloadLink);
                    URL.revokeObjectURL(downloadUrl);
                } catch (fallbackError) {
                    // Method 3: Data URL
                    const pdfDataUri = doc.output('datauristring');
                    const link = document.createElement('a');
                    link.href = pdfDataUri;
                    link.download = filename;
                    link.click();
                }
            }
        }

        // Helper function to get month name
        function getMonthName(monthNumber) {
            const months = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            return months[monthNumber - 1] || '';
        }

        // Initialize the app
        document.addEventListener('DOMContentLoaded', function() {
            initializeData();
        });

        // Prevent zoom on double tap
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function (event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
    </script>
</body>
</html>