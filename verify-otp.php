<?php
// Start session
session_start();

// Database configuration
$db_host = 'localhost';
$db_name = 'payroll_system';
$db_user = 'root';
$db_pass = '';

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$error_message = '';
$success_message = '';
$phone_number = '';
$otp_verified = false;

// Check if phone number is stored in session
if (!isset($_SESSION['otp_phone_number'])) {
    // Redirect to forgot password if no phone number
    header("Location: ForgotPassword.php");
    exit();
}

$phone_number = $_SESSION['otp_phone_number'];

// Process OTP verification
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $otp = trim($_POST['otp']);
    
    // Server-side validation
    if (empty($otp)) {
        $error_message = "Please enter the OTP";
    } elseif (!ctype_digit($otp) || strlen($otp) != 6) {
        $error_message = "Invalid OTP format";
    } else {
        // Check OTP in database
        $stmt = $conn->prepare("SELECT * FROM otp_codes WHERE phone_number = ? AND otp_code = ? AND is_used = 0 AND expires_at > NOW()");
        $stmt->bind_param("ss", $phone_number, $otp);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // OTP is valid, mark it as used
            $update_stmt = $conn->prepare("UPDATE otp_codes SET is_used = 1, verified_at = NOW() WHERE phone_number = ? AND otp_code = ?");
            $update_stmt->bind_param("ss", $phone_number, $otp);
            $update_stmt->execute();
            
            // Set session for verified OTP
            $_SESSION['otp_verified'] = true;
            
            // Redirect to reset password page
            header("Location: reset-password.php");
            exit();
        } else {
            $error_message = "Invalid OTP or expired. Please try again.";
        }
        
        $stmt->close();
    }
}

// Generate and store new OTP (for resend functionality)
function generateAndStoreOTP($phone_number, $conn) {
    // Generate 6-digit OTP
    $otp = rand(100000, 999999);
    
    // Store in database
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes')); // 10 minutes expiry
    
    // Delete any existing unverified OTPs for this phone number
    $delete_stmt = $conn->prepare("DELETE FROM otp_codes WHERE phone_number = ? AND is_used = 0");
    $delete_stmt->bind_param("s", $phone_number);
    $delete_stmt->execute();
    
    // Insert new OTP
    $insert_stmt = $conn->prepare("INSERT INTO otp_codes (phone_number, otp_code, expires_at) VALUES (?, ?, ?)");
    $insert_stmt->bind_param("sss", $phone_number, $otp, $expires_at);
    $insert_stmt->execute();
    
    return $otp;
}

// Handle resend OTP
if (isset($_POST['resend_otp'])) {
    $new_otp = generateAndStoreOTP($phone_number, $conn);
    
    // TODO: Send OTP via SMS (using SMS gateway API)
    // For now, just store it in session for demo purposes
    $_SESSION['current_otp'] = $new_otp;
    
    $success_message = "New OTP sent to " . $phone_number;
}

// Close database connection
$conn->close();
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .logo img {
            max-width: 150px;
            height: auto;
        }

        h1 {
            color: #1e293b;
            font-size: 1.5rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: #64748b;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .phone-number {
            color: #0f172a;
            font-weight: 500;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 1rem;
        }

        .otp-container {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .otp-input {
            width: 3rem;
            height: 3.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1.5rem;
            text-align: center;
            font-weight: 600;
            color: #1e293b;
            transition: all 0.2s;
        }

        .otp-input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .otp-input.error {
            border-color: #ef4444;
            background-color: #fef2f2;
        }

        .timer {
            text-align: center;
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .timer span {
            color: #3b82f6;
            font-weight: 600;
        }

        .verify-button {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .verify-button:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.2);
        }

        .verify-button:active:not(:disabled) {
            transform: translateY(0);
        }

        .verify-button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .resend-container {
            text-align: center;
            margin-top: 1rem;
        }

        .resend-link {
            color: #3b82f6;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
        }

        .resend-link:hover {
            text-decoration: underline;
        }

        .resend-link:disabled {
            color: #94a3b8;
            cursor: not-allowed;
            text-decoration: none;
        }

        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            text-align: center;
            margin-top: 0.5rem;
            display: none;
        }

        .success-message {
            color: #10b981;
            font-size: 0.875rem;
            text-align: center;
            margin-top: 0.5rem;
            display: none;
        }

        .back-to-login {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-to-login a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .back-to-login a:hover {
            color: #3b82f6;
        }

        .spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s linear infinite;
            margin-right: 0.5rem;
            vertical-align: middle;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
            .container {
                padding: 1.5rem;
                border-radius: 12px;
            }

            .otp-input {
                width: 2.75rem;
                height: 3.25rem;
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="YAZAKI-TORRES-LOGO.webp" alt="Company Logo">
        </div>

        <h1>Verify OTP</h1>
        <p class="subtitle">Enter the 6-digit code sent to your mobile number</p>
        <p class="phone-number"><?php echo htmlspecialchars($phone_number); ?></p>

        <form id="otp-form" method="POST">
            <div class="otp-container" id="otp-container">
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" name="otp[]" required>
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" name="otp[]" required>
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" name="otp[]" required>
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" name="otp[]" required>
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" name="otp[]" required>
                <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" name="otp[]" required>
            </div>

            <div class="timer" id="timer">
                Resend code in <span id="timer-countdown">5:00</span>
            </div>

            <button type="submit" class="verify-button" id="verify-button">
                Verify OTP
            </button>

            <div class="error-message" id="error-message"><?php echo $error_message; ?></div>
            <div class="success-message" id="success-message"><?php echo $success_message; ?></div>

            <div class="resend-container">
                <button type="submit" class="resend-link" id="resend-link" name="resend_otp" disabled>
                    Resend OTP
                </button>
            </div>
        </form>

        <div class="back-to-login">
            <a href="Login.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Back to Login
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const otpInputs = document.querySelectorAll('.otp-input');
            const verifyButton = document.getElementById('verify-button');
            const resendLink = document.getElementById('resend-link');
            const timerElement = document.getElementById('timer');
            const errorMessage = document.getElementById('error-message');
            const successMessage = document.getElementById('success-message');
            const otpForm = document.getElementById('otp-form');

            let timeLeft = 300; // 5 minutes in seconds
            let timerInterval;
            let isProcessing = false;

            // Handle OTP input
            otpInputs.forEach((input, index) => {
                // Focus next input when a digit is entered
                input.addEventListener('input', function(e) {
                    if (e.target.value.length === 1) {
                        if (index < otpInputs.length - 1) {
                            otpInputs[index + 1].focus();
                        }
                        validateOTP();
                    }
                });

                // Handle backspace
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !e.target.value && index > 0) {
                        otpInputs[index - 1].focus();
                    }
                });

                // Handle paste
                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text').slice(0, 6);
                    if (/^\d+$/.test(pastedData)) {
                        pastedData.split('').forEach((digit, i) => {
                            if (otpInputs[i]) {
                                otpInputs[i].value = digit;
                            }
                        });
                        validateOTP();
                    }
                });
            });

            // Start timer
            function startTimer() {
                clearInterval(timerInterval);
                timeLeft = 300;
                updateTimerDisplay();
                
                timerInterval = setInterval(() => {
                    timeLeft--;
                    updateTimerDisplay();
                    
                    if (timeLeft <= 0) {
                        clearInterval(timerInterval);
                        resendLink.disabled = false;
                        timerElement.style.display = 'none';
                    }
                }, 1000);
            }

            function updateTimerDisplay() {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                document.getElementById('timer-countdown').textContent = 
                    `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }

            // Validate OTP
            function validateOTP() {
                const otp = Array.from(otpInputs).map(input => input.value).join('');
                const isValid = otp.length === 6 && /^\d+$/.test(otp);
                verifyButton.disabled = !isValid;
                return isValid;
            }

            // Handle form submission
            otpForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                if (isProcessing) return;
                
                const otp = Array.from(otpInputs).map(input => input.value).join('');
                
                try {
                    isProcessing = true;
                    verifyButton.disabled = true;
                    verifyButton.innerHTML = '<span class="spinner"></span> Verifying...';
                    errorMessage.style.display = 'none';
                    successMessage.style.display = 'none';

                    // Form will submit to PHP backend
                    // The server will handle the verification

                } catch (error) {
                    console.error('Verification error:', error);
                    errorMessage.textContent = 'An error occurred. Please try again.';
                    errorMessage.style.display = 'block';
                    
                    // Clear OTP inputs
                    otpInputs.forEach(input => {
                        input.value = '';
                        input.classList.add('error');
                    });
                    otpInputs[0].focus();
                    
                    // Remove error class after animation
                    setTimeout(() => {
                        otpInputs.forEach(input => input.classList.remove('error'));
                    }, 1000);

                } finally {
                    isProcessing = false;
                    verifyButton.disabled = false;
                    verifyButton.textContent = 'Verify OTP';
                }
            });

            // Start initial timer
            startTimer();

            // Focus first input on load
            otpInputs[0].focus();
        });
    </script>
</body>
</html>