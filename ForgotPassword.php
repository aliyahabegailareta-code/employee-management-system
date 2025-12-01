<?php
// Start session
session_start();

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
    if (isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
        handleForgotPassword($pdo);
    }
}

function handleForgotPassword($pdo) {
    $employee_number = $_POST['employee_number'] ?? '';
    
    // Validate input
    if (empty($employee_number)) {
        $_SESSION['forgot_error'] = 'Employee number is required';
        return;
    }
    
    // Check if employee exists
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_no = ?");
    $stmt->execute([$employee_number]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION['forgot_error'] = 'Employee number not found in system.';
        return;
    }
    
    // Check if user account exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_no = ?");
    $stmt->execute([$employee_number]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['forgot_error'] = 'No account found for this employee number. Please sign up first.';
        return;
    }
    
    // Get employee email
    $employee_email = getEmployeeEmail($pdo, $employee_number);
    $employee_name = $employee['name'] ?? 'Employee';
    
    // Generate OTP
    $otp_code = sprintf("%06d", mt_rand(1, 999999));
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    try {
        // Delete existing OTP for this employee
        $stmt = $pdo->prepare("DELETE FROM password_reset_otps WHERE employee_no = ?");
        $stmt->execute([$employee_number]);
        
        // Insert new OTP
        $stmt = $pdo->prepare("INSERT INTO password_reset_otps (employee_no, otp_code, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$employee_number, $otp_code, $expires_at]);
        
        // Send OTP via email
        if ($employee_email) {
            $email_sent = sendOTPEmail($employee_email, $employee_name, $employee_number, $otp_code);
            
            if ($email_sent) {
                $_SESSION['otp_sent'] = true;
                $_SESSION['otp_email'] = $employee_email; // Store for display (masked)
            } else {
                // Email sending failed, but OTP is still generated
                // Store OTP in session as fallback (for development/testing)
                $_SESSION['otp_fallback'] = $otp_code;
                $_SESSION['otp_email_sent'] = false;
                $_SESSION['forgot_warning'] = 'Unable to send email. Please contact administrator or check your email settings.';
            }
        } else {
            // No email found - store OTP in session as fallback
            $_SESSION['otp_fallback'] = $otp_code;
            $_SESSION['otp_email_sent'] = false;
            $_SESSION['forgot_warning'] = 'No email address found for your account. Please contact administrator.';
        }
        
        // Set session for OTP verification
        $_SESSION['reset_employee_no'] = $employee_number;
        
        // Redirect to OTP verification
        header("Location: VerifyOTP.php");
        exit();
        
    } catch(PDOException $e) {
        $_SESSION['forgot_error'] = 'Error generating OTP. Please try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Yazaki Torres</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            min-height: 100vh;
        }

        .forgot-container {
            width: 100%;
            max-width: 360px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .forgot-form {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid #e2e8f0;
            padding: 35px 25px;
            width: 100%;
            position: relative;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .company-logo {
            width: 260px;
            height: auto;
            margin: 0 auto 15px;
            max-width: 100%;
            display: block;
        }

        .forgot-title {
            font-size: 26px;
            font-weight: bold;
            color: #f10b1e;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        .forgot-subtitle {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
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

        .textbox {
            width: 100%;
            padding: 14px 14px 14px 40px;
            border: 2px solid #d1d5db;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            -webkit-appearance: none;
            appearance: none;
            height: 48px;
        }

        .textbox:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            transform: translateY(-2px);
        }

        .textbox.error {
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

        .send-otp-btn {
            width: 100%;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            font-weight: 700;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.3);
            position: relative;
            overflow: hidden;
            height: 48px;
            margin-top: 10px;
        }

        .send-otp-btn:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            box-shadow: 0 20px 35px -5px rgba(59, 130, 246, 0.4);
            transform: translateY(-3px);
        }

        .send-otp-btn:active {
            transform: translateY(-1px);
        }

        .send-otp-btn:disabled {
            background: #93c5fd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .back-to-login {
            text-align: center;
            margin-top: 25px;
        }

        .back-to-login a {
            color: #3b82f6;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s;
        }

        .back-to-login a:hover {
            color: #2563eb;
            text-decoration: underline;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
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

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .forgot-form {
                padding: 30px 20px;
                border-radius: 14px;
            }
            
            .company-logo {
                width: 200px;
                margin-bottom: 12px;
            }
            
            .forgot-title {
                font-size: 24px;
                letter-spacing: 1px;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-form">
            <div class="logo-section">
                <img src="YAZAKI-TORRES-LOGO.webp" alt="Yazaki Torres Logo" class="company-logo">
                <h1 class="forgot-title">FORGOT PASSWORD?</h1>
                <p class="forgot-subtitle">Enter your employee number to receive an OTP code</p>
            </div>

            <?php if (isset($_SESSION['forgot_error'])): ?>
                <div class="global-error" id="global-error">
                    <?php 
                    echo htmlspecialchars($_SESSION['forgot_error']);
                    unset($_SESSION['forgot_error']);
                    ?>
                </div>
            <?php endif; ?>

            <form id="forgot-form" method="POST" action="">
                <input type="hidden" name="action" value="forgot_password">
                
                <div class="form-group">
                    <label for="employee_number" class="form-label">Employee Number</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <input 
                            type="text" 
                            id="employee_number" 
                            name="employee_number" 
                            placeholder="XX-XXXXX" 
                            class="textbox" 
                            maxlength="8"
                            required
                            value="<?php echo isset($_POST['employee_number']) ? htmlspecialchars($_POST['employee_number']) : ''; ?>"
                        >
                    </div>
                    <div id="employee-error" class="error-message" style="display: none;"></div>
                </div>

                <button type="submit" class="send-otp-btn" id="send-otp-btn" name="send_otp">
                    <span id="otp-text">Send OTP</span>
                    <div class="loading" id="loading">
                        <div class="spinner"></div>
                        Sending...
                    </div>
                </button>
            </form>

            <div class="back-to-login">
                <a href="index.php">Back to Login</a>
            </div>
        </div>

        <div class="footer">
            <p>© 2024 Yazaki Torres. All rights reserved.</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const employeeInput = document.getElementById('employee_number');
            const forgotForm = document.getElementById('forgot-form');
            const sendOtpBtn = document.getElementById('send-otp-btn');
            const otpText = document.getElementById('otp-text');
            const loading = document.getElementById('loading');
            const globalError = document.getElementById('global-error');

            // Format employee number to XX-XXXXX
            function formatEmployeeNumber(value) {
                const numericValue = value.replace(/[^0-9]/g, '');
                const limitedValue = numericValue.slice(0, 7);
                if (limitedValue.length > 2) {
                    return limitedValue.slice(0, 2) + '-' + limitedValue.slice(2);
                }
                return limitedValue;
            }

            employeeInput.addEventListener('input', function(e) {
                const formatted = formatEmployeeNumber(e.target.value);
                e.target.value = formatted;
                clearError('employee');
                if (globalError) globalError.style.display = 'none';
            });

            employeeInput.addEventListener('keypress', function(e) {
                if (!/[0-9]/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab' && e.key !== 'Enter' && e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
                    e.preventDefault();
                }
            });

            function validateForm() {
                let isValid = true;
                const employeeNumber = employeeInput.value.trim();

                if (!employeeNumber) {
                    showError('employee', 'Employee number is required');
                    isValid = false;
                } else if (!/^\d{2}-\d{5}$/.test(employeeNumber)) {
                    showError('employee', 'Employee number must be in XX-XXXXX format');
                    isValid = false;
                }

                return isValid;
            }

            function showError(field, message) {
                const errorElement = document.getElementById(field + '-error');
                const inputElement = document.getElementById('employee_number');
                errorElement.textContent = message;
                errorElement.style.display = 'block';
                inputElement.classList.add('error');
            }

            function clearError(field) {
                const errorElement = document.getElementById(field + '-error');
                const inputElement = document.getElementById('employee_number');
                errorElement.style.display = 'none';
                inputElement.classList.remove('error');
            }

            forgotForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                if (!validateForm()) return;
                
                sendOtpBtn.disabled = true;
                otpText.style.display = 'none';
                loading.style.display = 'flex';
                
                this.submit();
            });
        });
    </script>
</body>
</html>
