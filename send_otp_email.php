<?php
/**
 * Send OTP via Email
 * 
 * This function sends an OTP code to the user's email address
 * 
 * @param string $to_email Recipient email address
 * @param string $employee_name Employee name
 * @param string $employee_number Employee number
 * @param string $otp_code The 6-digit OTP code
 * @return bool True if email sent successfully, false otherwise
 */
function sendOTPEmail($to_email, $employee_name, $employee_number, $otp_code) {
    // Validate email
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Email configuration
    $from_email = "noreply@yazakitorres.com"; // Change this to your company email
    $from_name = "Yazaki Torres Manufacturing";
    $subject = "Password Reset OTP - Yazaki Torres";
    
    // Email body
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                background-color: #f10b1e;
                color: white;
                padding: 20px;
                text-align: center;
                border-radius: 5px 5px 0 0;
            }
            .content {
                background-color: #f9f9f9;
                padding: 30px;
                border: 1px solid #ddd;
            }
            .otp-box {
                background-color: #fff;
                border: 2px solid #f10b1e;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
                margin: 20px 0;
            }
            .otp-code {
                font-size: 32px;
                font-weight: bold;
                color: #f10b1e;
                letter-spacing: 5px;
                font-family: 'Courier New', monospace;
            }
            .footer {
                background-color: #333;
                color: white;
                padding: 15px;
                text-align: center;
                font-size: 12px;
                border-radius: 0 0 5px 5px;
            }
            .warning {
                background-color: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 15px;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>Yazaki Torres Manufacturing</h2>
            <p>Password Reset Request</p>
        </div>
        <div class='content'>
            <p>Dear " . htmlspecialchars($employee_name) . ",</p>
            
            <p>You have requested to reset your password for your employee account.</p>
            
            <p>Your Employee Number: <strong>" . htmlspecialchars($employee_number) . "</strong></p>
            
            <div class='otp-box'>
                <p style='margin: 0 0 10px 0; color: #666;'>Your OTP Code is:</p>
                <div class='otp-code'>" . htmlspecialchars($otp_code) . "</div>
            </div>
            
            <div class='warning'>
                <strong>⚠ Important:</strong>
                <ul style='margin: 10px 0; padding-left: 20px;'>
                    <li>This OTP code will expire in <strong>10 minutes</strong></li>
                    <li>Do not share this code with anyone</li>
                    <li>If you did not request this password reset, please ignore this email</li>
                </ul>
            </div>
            
            <p>Enter this code on the password reset page to complete the process.</p>
            
            <p>If you have any questions or concerns, please contact your system administrator.</p>
        </div>
        <div class='footer'>
            <p>&copy; " . date('Y') . " Yazaki Torres Manufacturing Incorporated. All rights reserved.</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </body>
    </html>
    ";
    
    // Email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . $from_name . " <" . $from_email . ">" . "\r\n";
    $headers .= "Reply-To: " . $from_email . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // Send email
    try {
        $mail_sent = mail($to_email, $subject, $message, $headers);
        return $mail_sent;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get employee email address
 * 
 * @param PDO $pdo Database connection
 * @param string $employee_number Employee number
 * @return string|false Email address or false if not found
 */
function getEmployeeEmail($pdo, $employee_number) {
    try {
        // Try to get email from employees table
        // Check if email column exists by trying to select it
        try {
            $stmt = $pdo->prepare("SELECT email FROM employees WHERE employee_no = ?");
            $stmt->execute([$employee_number]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['email'])) {
                return $result['email'];
            }
        } catch (PDOException $e) {
            // Email column might not exist, try alternative column names
            // Continue to alternative columns
        }
        
        // If email column doesn't exist or is empty, try alternative column names
        $alternative_columns = ['email_address', 'e_mail', 'contact_email', 'work_email'];
        
        foreach ($alternative_columns as $column) {
            try {
                $stmt = $pdo->prepare("SELECT $column FROM employees WHERE employee_no = ?");
                $stmt->execute([$employee_number]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && !empty($result[$column])) {
                    return $result[$column];
                }
            } catch (PDOException $e) {
                // Column doesn't exist, try next one
                continue;
            }
        }
        
        return false;
    } catch (Exception $e) {
        // Any other error
        error_log("Error getting employee email: " . $e->getMessage());
        return false;
    }
}
?>

