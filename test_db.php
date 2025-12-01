<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Test</h2>";

// Database connection
$host = 'localhost';
$dbname = 'employee_managements';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✓ Database connected successfully</p>";
    
    // Check table structure
    $stmt = $conn->query("DESCRIBE payroll_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . $column['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if deleted column exists
    $hasDeletedColumn = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'deleted') {
            $hasDeletedColumn = true;
            break;
        }
    }
    
    if ($hasDeletedColumn) {
        echo "<p style='color: green;'>✓ Deleted column exists</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Deleted column does not exist</p>";
        echo "<p>Adding deleted column...</p>";
        $conn->exec("ALTER TABLE payroll_records ADD COLUMN deleted TINYINT(1) DEFAULT 0");
        echo "<p style='color: green;'>✓ Deleted column added</p>";
    }
    
    // Test query
    $stmt = $conn->query("SELECT COUNT(*) as count FROM payroll_records");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total records in payroll_records: " . $result['count'] . "</p>";
    
    // Test with employee_no parameter
    $testEmployeeNo = '22-06554'; // Use an existing employee number
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM payroll_records WHERE employee_no = ?");
    $stmt->execute([$testEmployeeNo]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Records for employee $testEmployeeNo: " . $result['count'] . "</p>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}
?> 