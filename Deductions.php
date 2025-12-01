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

// Get employee data and benefits information
$employee_no = $_SESSION['employee_no'];
$employee = null;
$benefits_data = [
    'sss' => [],
    'philhealth' => [],
    'pagibig' => []
];

// Yazaki Torres company information
$company_info = [
    'name' => 'YAZAKI TORRES MANUFACTURING INC.',
    'address' => 'Yazaki Torres Manufacturing, Inc.',
    'contact' => 'Yazaki Torres Manufacturing Incorporated'
];

try {
    // Get employee basic info
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_no = ?");
    $stmt->execute([$employee_no]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($employee) {
        // Get benefits data from payroll_records
        $stmt = $pdo->prepare("
            SELECT 
                week_period,
                sss,
                philhealth,
                pagibig,
                created_at
            FROM payroll_records 
            WHERE employee_no = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$employee_no]);
        $payroll_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process benefits data
        foreach ($payroll_records as $record) {
            // Extract year and month from week_period
            preg_match('/(\d{4})[^\d]*(\d{1,2})/', $record['week_period'], $matches);
            $year = $matches[1] ?? date('Y');
            $month = $matches[2] ?? date('m');

            // SSS data
            if ($record['sss'] > 0) {
                $benefits_data['sss'][] = [
                    'date' => $record['week_period'],
                    'amount' => floatval($record['sss']),
                    'status' => 'Paid',
                    'year' => $year,
                    'month' => intval($month)
                ];
            }

            // PhilHealth data
            if ($record['philhealth'] > 0) {
                $benefits_data['philhealth'][] = [
                    'date' => $record['week_period'],
                    'amount' => floatval($record['philhealth']),
                    'status' => 'Paid',
                    'year' => $year,
                    'month' => intval($month)
                ];
            }

            // Pag-IBIG data
            if ($record['pagibig'] > 0) {
                $benefits_data['pagibig'][] = [
                    'date' => $record['week_period'],
                    'amount' => floatval($record['pagibig']),
                    'status' => 'Paid',
                    'year' => $year,
                    'month' => intval($month)
                ];
            }
        }

        // Get available years from benefits data
        $available_years = [];
        foreach ($benefits_data as $benefit_type => $records) {
            foreach ($records as $record) {
                if (!in_array($record['year'], $available_years)) {
                    $available_years[] = $record['year'];
                }
            }
        }
        rsort($available_years);
    }
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
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
    <title>Benefits Dashboard</title>
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

        .dashboard-grid {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-bottom: 0.85rem;
        }

        .benefit-card {
            background: white;
            border-radius: 16px;
            padding: 0.75rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            width: 95%;
            margin: 0 auto;
        }

        .benefit-card:active {
            transform: scale(0.98);
        }

        .benefit-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .benefit-card.sss {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #3b82f6;
            margin-bottom: 10px;
        }

        .benefit-card.philhealth {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            border-color: #ef4444;
            margin-bottom: 10px;
        }

        .benefit-card.pagibig {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            border-color: #22c55e;
            margin-bottom: 10px;
        }

        .card-icon {
            width: 31px;
            height: 31px;
            margin: 0 auto 0.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            font-size: 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .sss .card-icon { color: #3b82f6; }
        .philhealth .card-icon { color: #ef4444; }
        .pagibig .card-icon { color: #22c55e; }

        .card-title {
            font-size: 1.375rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }

        .card-description {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .card-hint {
            color: #94a3b8;
            font-size: 0.75rem;
        }

        .history-section {
            display: none;
        }

        .history-section.active {
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

        .status-pending {
            background: #fef3c7;
            color: #92400e;
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

            .benefit-card,
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
        <a href="Dashboard.php" id="back-arrow-link" class="back-arrow-btn">
            &#8592;
        </a>
        <!-- Dashboard Section -->
        <div id="dashboard" class="dashboard-section">
            <div class="header">
                <h1>Benefits Dashboard</h1>
            </div>

            <div class="dashboard-grid">
                <div class="benefit-card sss" onclick="showHistory('sss')">
                    <div class="card-icon">🛡️</div>
                    <div class="card-title">SSS</div>
                    <div class="card-description">Social Security System</div>
                    <div class="card-hint">Tap to view contribution history</div>
                </div>

                <div class="benefit-card philhealth" onclick="showHistory('philhealth')">
                    <div class="card-icon">❤️</div>
                    <div class="card-title">PhilHealth</div>
                    <div class="card-description">Philippine Health Insurance</div>
                    <div class="card-hint">Tap to view contribution history</div>
                </div>

                <div class="benefit-card pagibig" onclick="showHistory('pagibig')">
                    <div class="card-icon">🏠</div>
                    <div class="card-title">Pag-IBIG</div>
                    <div class="card-description">Home Development Mutual Fund</div>
                    <div class="card-hint">Tap to view contribution history</div>
                </div>
            </div>
        </div>

        <!-- History Section -->
        <div id="history" class="history-section">
            <div class="header">
                <h1 id="historyTitle">Contribution History</h1>
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
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- Data will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <button class="download-btn" onclick="downloadHistory()">
                📥 Download History
            </button>
        </div>
    </div>

    <!-- Add jsPDF libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script>
        // Convert PHP data to JavaScript
        const benefitsData = <?php echo json_encode($benefits_data); ?>;
        const companyInfo = <?php echo json_encode($company_info); ?>;
        let currentBenefit = '';
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

        // Show dashboard
        function showDashboard() {
            document.getElementById('dashboard').style.display = 'block';
            document.getElementById('history').classList.remove('active');
            
            const backArrow = document.getElementById('back-arrow-link');
            backArrow.setAttribute('href', 'Dashboard.php');
            backArrow.onclick = null;
        }

        // Show history for specific benefit
        function showHistory(benefitType) {
            currentBenefit = benefitType;
            
            const titles = {
                'sss': 'SSS Contribution History',
                'philhealth': 'PhilHealth Contribution History',
                'pagibig': 'Pag-IBIG Contribution History'
            };
            document.getElementById('historyTitle').textContent = titles[benefitType];

            document.getElementById('dashboard').style.display = 'none';
            document.getElementById('history').classList.add('active');
            
            const backArrow = document.getElementById('back-arrow-link');
            backArrow.removeAttribute('href');
            backArrow.onclick = function(e) {
                e.preventDefault();
                showDashboard();
            };

            allData = benefitsData[benefitType] || [];
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

            filteredData.sort((a, b) => new Date(b.date) - new Date(a.date));
            displayData();
        }

        // Display data in table
        function displayData() {
            const tableBody = document.getElementById('tableBody');
            
            if (filteredData.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="3" class="no-data">
                            No contribution records found for selected filters
                        </td>
                    </tr>
                `;
                return;
            }

            tableBody.innerHTML = filteredData.map(item => `
                <tr>
                    <td>${formatDate(item.date)}</td>
                    <td>${formatCurrency(item.amount)}</td>
                    <td>
                        <span class="status-badge ${item.status === 'Paid' ? 'status-paid' : 'status-pending'}">
                            ${item.status}
                        </span>
                    </td>
                </tr>
            `).join('');
        }

        // Download history as PDF
        function downloadHistory() {
            if (filteredData.length === 0) {
                alert('No data available to download');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            // Set PDF properties
            doc.setProperties({
                title: `${currentBenefit.toUpperCase()} Contribution History - ${companyInfo.name}`,
                subject: 'Employee Benefits Contribution Record',
                author: companyInfo.name,
                creator: 'Employee Management System'
            });

            // Company Header - YAZAKI TORRES
            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.text(companyInfo.name, 105, 15, { align: 'center' });
            
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text('Employee Benefits Contribution Record', 105, 22, { align: 'center' });
            
            // Add a line separator
            doc.setLineWidth(0.5);
            doc.line(14, 27, 196, 27);

            // Set title
            const titles = {
                'sss': 'SSS CONTRIBUTION HISTORY',
                'philhealth': 'PHILHEALTH CONTRIBUTION HISTORY', 
                'pagibig': 'PAG-IBIG CONTRIBUTION HISTORY'
            };
            
            doc.setFontSize(14);
            doc.setFont('helvetica', 'bold');
            doc.text(titles[currentBenefit] || 'CONTRIBUTION HISTORY', 105, 37, { align: 'center' });

            // Employee Info
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Employee Name: ${'<?php echo htmlspecialchars($employee['name'] ?? 'N/A'); ?>'}`, 14, 47);
            doc.text(`Employee No: ${'<?php echo htmlspecialchars($employee['employee_no'] ?? 'N/A'); ?>'}`, 14, 54);
            doc.text(`Department: ${'<?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?>'}`, 14, 61);

            // Add date range if filters are applied
            const year = document.getElementById('yearFilter').value;
            const month = document.getElementById('monthFilter').value;
            
            let dateRange = '';
            if (year) dateRange += year;
            if (month) dateRange += ` - ${getMonthName(parseInt(month))}`;
            
            if (dateRange) {
                doc.text(`Period: ${dateRange}`, 14, 68);
            }

            // Calculate total
            const totalAmount = filteredData.reduce((sum, item) => sum + item.amount, 0);
            doc.text(`Total Contributions: ${formatCurrencyForPDF(totalAmount)}`, 14, 75);

            // Prepare table data - FIXED PESO SIGN ISSUE
            const tableData = filteredData.map(item => {
                // Create a custom formatted amount with peso sign that works in PDF
                const formattedAmount = '₱' + item.amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                return [
                    formatDate(item.date),
                    formattedAmount, // This will properly show peso sign
                    item.status
                ];
            });

            // Add table
            doc.autoTable({
                startY: 85,
                head: [['Date', 'Amount', 'Status']],
                body: tableData,
                theme: 'grid',
                styles: {
                    fontSize: 9,
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
                    0: { cellWidth: 60, halign: 'center' },
                    1: { cellWidth: 60, halign: 'center' },
                    2: { cellWidth: 40, halign: 'center' }
                },
                alternateRowStyles: {
                    fillColor: [240, 240, 240]
                },
                margin: { top: 85 },
                tableLineColor: [0, 0, 0],
                tableLineWidth: 0.1
            });

            // Get final Y position after table
            const finalY = doc.lastAutoTable.finalY || 85;

            // Add summary
            doc.setFontSize(10);
            doc.setFont('helvetica', 'bold');
            
            // Format total amount for summary with proper peso sign
            const formattedTotal = '₱' + totalAmount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
            
            doc.text(`Total Records: ${filteredData.length}`, 14, finalY + 10);
            doc.text(`Total Amount: ${formattedTotal}`, 14, finalY + 17);

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
            const benefitNames = {
                'sss': 'SSS',
                'philhealth': 'PhilHealth',
                'pagibig': 'PagIBIG'
            };
            
            const dateStamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
            const filename = `${benefitNames[currentBenefit]}_Contributions_${'<?php echo $employee_no; ?>'}_${dateStamp}.pdf`;
            
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
            showDashboard();
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