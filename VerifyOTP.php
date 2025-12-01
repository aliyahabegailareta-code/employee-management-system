<?php
// Start session
session_start();

// Redirect if no reset session
if (!isset($_SESSION['reset_employee_no'])) {
    header("Location: ForgotPassword.php");
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

// Include email sending function
require_once 'send_otp_email.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
        handleVerifyOTP($pdo);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
        handleResendOTP($pdo);
    }
}

function handleVerifyOTP($pdo) {
    $otp_code = $_POST['otp_code'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $employee_number = $_SESSION['reset_employee_no'];

    // Validate inputs
    if (empty($otp_code)) {
        $_SESSION['otp_error'] = 'OTP code is required';
        return;
    }

    if (empty($new_password)) {
        $_SESSION['otp_error'] = 'New password is required';
        return;
    }

    if (strlen($new_password) < 6) {
        $_SESSION['otp_error'] = 'Password must be at least 6 characters';
        return;
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['otp_error'] = 'Passwords do not match';
        return;
    }

    // Check OTP from database
    $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE employee_no = ? AND otp_code = ? AND expires_at > NOW()");
    $stmt->execute([$employee_number, $otp_code]);
    $otp_record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otp_record) {
        $_SESSION['otp_error'] = 'Invalid or expired OTP code';
        return;
    }

    // OTP is valid - update password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    try {
        // Update password in users table
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE employee_no = ?");
        $stmt->execute([$hashed_password, $employee_number]);
        
        if ($stmt->rowCount() > 0) {
            // Delete used OTP
            $stmt = $pdo->prepare("DELETE FROM password_reset_otps WHERE employee_no = ?");
            $stmt->execute([$employee_number]);
            
            // Clear session
            unset($_SESSION['reset_employee_no']);
            
            $_SESSION['reset_success'] = 'Password reset successfully! You can now login with your new password.';
            header("Location: Login.php");
            exit();
        } else {
            $_SESSION['otp_error'] = 'Failed to update password. Please try again.';
        }
    } catch(PDOException $e) {
        $_SESSION['otp_error'] = 'Error updating password: ' . $e->getMessage();
    }
}

function handleResendOTP($pdo) {
    $employee_number = $_SESSION['reset_employee_no'];
    
    // Get employee information
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_no = ?");
    $stmt->execute([$employee_number]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION['otp_error'] = 'Employee not found.';
        return;
    }
    
    $employee_name = $employee['name'] ?? 'Employee';
    $employee_email = getEmployeeEmail($pdo, $employee_number);
    
    // Generate new OTP
    $otp_code = sprintf("%06d", mt_rand(1, 999999));
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    try {
        // Delete existing OTP
        $stmt = $pdo->prepare("DELETE FROM password_reset_otps WHERE employee_no = ?");
        $stmt->execute([$employee_number]);
        
        // Insert new OTP
        $stmt = $pdo->prepare("INSERT INTO password_reset_otps (employee_no, otp_code, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$employee_number, $otp_code, $expires_at]);
        
        // Send OTP via email
        if ($employee_email) {
            $email_sent = sendOTPEmail($employee_email, $employee_name, $employee_number, $otp_code);
            
            if ($email_sent) {
                $_SESSION['otp_success'] = 'New OTP has been sent to your email address!';
                $_SESSION['otp_email'] = $employee_email;
            } else {
                // Email sending failed, store as fallback
                $_SESSION['otp_fallback'] = $otp_code;
                $_SESSION['otp_success'] = 'New OTP generated. Email sending failed. Please contact administrator.';
            }
        } else {
            // No email found - store as fallback
            $_SESSION['otp_fallback'] = $otp_code;
            $_SESSION['otp_success'] = 'New OTP generated. No email address found. Please contact administrator.';
        }
        
    } catch(PDOException $e) {
        $_SESSION['otp_error'] = 'Error generating new OTP. Please try again.';
    }
}

// Get employee email for display (masked)
$employee_number = $_SESSION['reset_employee_no'];
$employee_email = getEmployeeEmail($pdo, $employee_number);
$masked_email = $employee_email ? maskEmail($employee_email) : null;

// Function to mask email address
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    
    $username = $parts[0];
    $domain = $parts[1];
    
    // Mask username (show first 2 and last 2 characters)
    if (strlen($username) <= 4) {
        $masked_username = str_repeat('*', strlen($username));
    } else {
        $masked_username = substr($username, 0, 2) . str_repeat('*', strlen($username) - 4) . substr($username, -2);
    }
    
    return $masked_username . '@' . $domain;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 37px;
        }

        .verify-container {
            width: 100%;
            max-width: 360px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 37px 0;
            min-height: 100vh;
            margin: auto;
        }

        .verify-form {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 47px;
            width: 100%;
            margin: auto;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 42px;
        }

        .company-logo {
            width: 280px;
            height: auto;
            margin-bottom: 27px;
            max-width: 100%;
        }

        .verify-title {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
            letter-spacing: 1px;
            margin-bottom: 32px;
            text-align: center;
        }

        .verify-subtitle {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 42px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 37px;
        }

        .form-label {
            display: block;
            font-size: 15px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #9ca3af;
        }

        .otp-input, .password-input {
            width: 100%;
            padding: 18px 18px 18px 40px;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
        }

        .otp-input:focus, .password-input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            transform: translateY(-2px);
        }

        .otp-input.error, .password-input.error {
            border-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .error-message {
            color: #ef4444;
            font-size: 14px;
            margin-top: 6px;
            font-weight: 500;
            display: none;
        }

        .success-message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #16a34a;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .success-message::before {
            content: '✓';
            font-size: 16px;
        }

        .global-error {
            background: #fef3f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #dc2626;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .global-error::before {
            content: '⚠';
            font-size: 16px;
        }

        .otp-display {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #0369a1;
            font-size: 14px;
            text-align: center;
            font-weight: 600;
        }

        .reset-btn {
            width: 100%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            font-weight: 700;
            padding: 20px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.3);
            position: relative;
            overflow: hidden;
            margin-top: 12px;
        }

        .reset-btn:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 20px 35px -5px rgba(16, 185, 129, 0.4);
            transform: translateY(-3px);
        }

        .reset-btn:active {
            transform: translateY(-1px);
        }

        .reset-btn:disabled {
            background: #93c5fd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .resend-section {
            text-align: center;
            margin-top: 32px;
            padding-top: 32px;
            border-top: 1px solid #e5e7eb;
        }

        .resend-text {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 16px;
        }

        .resend-btn {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            font-weight: 600;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px -2px rgba(245, 158, 11, 0.3);
        }

        .resend-btn:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            box-shadow: 0 6px 16px -2px rgba(245, 158, 11, 0.4);
            transform: translateY(-2px);
        }

        .back-to-forgot {
            text-align: center;
            margin-top: 32px;
        }

        .back-to-forgot a {
            color: #1e40af;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            transition: color 0.2s;
        }

        .back-to-forgot a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .footer {
            text-align: center;
            margin-top: 32px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }

        .loading {
            display: none;
            align-items: center;
            justify-content: center;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobile optimizations */
        @media (max-width: 480px) {
            body {
                padding: 35px;
            }
            
            .verify-container {
                padding: 35px 0;
                justify-content: center;
                min-height: 100vh;
            }
            
            .verify-form {
                padding: 45px;
                border-radius: 14px;
                margin: 27px 0;
            }
            
            .company-logo {
                width: 180px;
                margin-bottom: 22px;
            }
            
            .verify-title {
                font-size: 22px;
                letter-spacing: 0.5px;
                margin-bottom: 33px;
            }
            
            .verify-subtitle {
                margin-bottom: 40px;
                font-size: 14px;
            }
            
            .form-group {
                margin-bottom: 37px;
            }
        }

        /* Very small screens */
        @media (max-width: 360px) {
            body {
                padding: 33px;
            }
            
            .verify-form {
                padding: 40px;
                margin: 22px 0;
            }
            
            .company-logo {
                width: 160px;
                margin-bottom: 17px;
            }
            
            .verify-title {
                font-size: 20px;
                margin-bottom: 31px;
            }

            .verify-subtitle {
                margin-bottom: 37px;
            }

            .form-group {
                margin-bottom: 35px;
            }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-form">
            <!-- Logo Section -->
            <div class="logo-section">
                <img src="YAZAKI-TORRES-LOGO.webp" alt="Yazaki Torres Logo" class="company-logo">
                <h1 class="verify-title">VERIFY OTP</h1>
                <p class="verify-subtitle">Enter the OTP below and set your new password</p>
            </div>

            <!-- Email Sent Message -->
            <?php if (isset($_SESSION['otp_sent']) && $_SESSION['otp_sent']): ?>
                <div class="success-message" id="email-sent-message">
                    ✓ OTP has been sent to your email address<?php echo $masked_email ? ' (' . htmlspecialchars($masked_email) . ')' : ''; ?>
                </div>
                <?php unset($_SESSION['otp_sent']); ?>
            <?php endif; ?>
            
            <!-- Fallback OTP Display (only if email sending failed) -->
            <?php if (isset($_SESSION['otp_fallback'])): ?>
                <div class="otp-display" style="background: #fff3cd; border-color: #ffc107;">
                    <strong>⚠ Email sending failed. Your OTP Code:</strong><br>
                    <span style="font-size: 24px; color: #f10b1e; font-weight: bold; letter-spacing: 3px;">
                        <?php echo htmlspecialchars($_SESSION['otp_fallback']); ?>
                    </span>
                    <br><small style="color: #856404;">Please contact administrator to set up email properly.</small>
                </div>
                <?php unset($_SESSION['otp_fallback']); ?>
            <?php endif; ?>
            
            <!-- Warning if no email found -->
            <?php if (isset($_SESSION['forgot_warning'])): ?>
                <div class="global-error" id="email-warning">
                    <?php 
                    echo htmlspecialchars($_SESSION['forgot_warning']);
                    unset($_SESSION['forgot_warning']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Display error message if exists -->
            <?php if (isset($_SESSION['otp_error'])): ?>
                <div class="global-error" id="global-error">
                    <?php 
                    echo htmlspecialchars($_SESSION['otp_error']);
                    unset($_SESSION['otp_error']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Display success message if exists -->
            <?php if (isset($_SESSION['otp_success'])): ?>
                <div class="success-message" id="success-message">
                    <?php 
                    echo htmlspecialchars($_SESSION['otp_success']);
                    unset($_SESSION['otp_success']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- OTP Verification Form -->
            <form id="verify-form" method="POST" action="">
                <input type="hidden" name="action" value="verify_otp">
                
                <div class="form-group">
                    <label for="otp_code" class="form-label">OTP Code</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input 
                            type="text" 
                            id="otp_code" 
                            name="otp_code" 
                            placeholder="Enter 6-digit OTP" 
                            class="otp-input" 
                            maxlength="6"
                            required
                            pattern="[0-9]{6}"
                        >
                    </div>
                    <div id="otp-error" class="error-message">
                        Please enter a valid 6-digit OTP
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <circle cx="12" cy="16" r="1"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            placeholder="Enter new password" 
                            class="password-input" 
                            required
                            minlength="6"
                        >
                    </div>
                    <div id="password-error" class="error-message">
                        Password must be at least 6 characters
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <circle cx="12" cy="16" r="1"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            placeholder="Confirm new password" 
                            class="password-input" 
                            required
                            minlength="6"
                        >
                    </div>
                    <div id="confirm-error" class="error-message">
                        Passwords do not match
                    </div>
                </div>
                
                <button type="submit" id="reset-btn" class="reset-btn">
                    <span id="reset-text">Reset Password</span>
                    <div class="loading" id="loading">
                        <div class="spinner"></div>
                        Resetting...
                    </div>
                </button>
            </form>

            <!-- Resend OTP Section -->
            <div class="resend-section">
                <p class="resend-text">Need a new OTP?</p>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" class="resend-btn">
                        Generate New OTP
                    </button>
                </form>
            </div>
            
            <div class="back-to-forgot">
                <a href="ForgotPassword.php">Back to Forgot Password</a>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; 2025 Yazaki Torres. All rights reserved.</p>
        </div>
    </div>

    <script>
        const otpInput = document.getElementById('otp_code');
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('reset-btn');
        const resetText = document.getElementById('reset-text');
        const loading = document.getElementById('loading');
        const globalError = document.getElementById('global-error');
        const successMessage = document.getElementById('success-message');

        // OTP input validation
        otpInput.addEventListener('input', function(e) {
            // Only allow numbers and limit to 6 digits
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            clearError('otp');
            hideMessages();
        });

        // Password validation
        newPasswordInput.addEventListener('input', function() {
            clearError('password');
            hideMessages();
            
            // Validate password length
            if (this.value.length > 0 && this.value.length < 6) {
                showError('password', 'Password must be at least 6 characters');
            }
            
            // Check password confirmation
            validatePasswordMatch();
        });

        confirmPasswordInput.addEventListener('input', function() {
            clearError('confirm');
            hideMessages();
            validatePasswordMatch();
        });

        function validatePasswordMatch() {
            const password = newPasswordInput.value;
            const confirm = confirmPasswordInput.value;
            
            if (password && confirm && password !== confirm) {
                showError('confirm', 'Passwords do not match');
            }
        }

        function validateForm() {
            let isValid = true;
            const otp = otpInput.value.trim();
            const password = newPasswordInput.value.trim();
            const confirm = confirmPasswordInput.value.trim();

            // Validate OTP
            if (otp === '') {
                showError('otp', 'OTP code is required');
                isValid = false;
            } else if (otp.length !== 6) {
                showError('otp', 'OTP must be 6 digits');
                isValid = false;
            }

            // Validate password
            if (password === '') {
                showError('password', 'New password is required');
                isValid = false;
            } else if (password.length < 6) {
                showError('password', 'Password must be at least 6 characters');
                isValid = false;
            }

            // Validate password confirmation
            if (confirm === '') {
                showError('confirm', 'Please confirm your password');
                isValid = false;
            } else if (password !== confirm) {
                showError('confirm', 'Passwords do not match');
                isValid = false;
            }

            return isValid;
        }

        function showError(field, message) {
            const errorElement = document.getElementById(field + '-error');
            const inputElement = document.getElementById(field === 'otp' ? 'otp_code' : 
                                                        field === 'password' ? 'new_password' : 'confirm_password');
            
            errorElement.textContent = message;
            errorElement.style.display = 'block';
            inputElement.classList.add('error');
        }

        function clearError(field) {
            const errorElement = document.getElementById(field + '-error');
            const inputElement = document.getElementById(field === 'otp' ? 'otp_code' : 
                                                        field === 'password' ? 'new_password' : 'confirm_password');
            
            errorElement.style.display = 'none';
            inputElement.classList.remove('error');
        }

        function hideMessages() {
            if (globalError) globalError.style.display = 'none';
            if (successMessage) successMessage.style.display = 'none';
        }

        // Form submission
        document.getElementById('verify-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!validateForm()) return;
            
            // Show loading state
            resetBtn.disabled = true;
            resetText.style.display = 'none';
            loading.style.display = 'flex';
            
            // Submit the form
            this.submit();
        });

        // Clear errors when inputs gain focus
        const inputs = [otpInput, newPasswordInput, confirmPasswordInput];
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                const field = this.id === 'otp_code' ? 'otp' : 
                             this.id === 'new_password' ? 'password' : 'confirm';
                clearError(field);
                hideMessages();
            });
        });
    </script>
</body>
</html>