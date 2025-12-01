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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        handleResetPassword($pdo);
    }
}

function handleResetPassword($pdo) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $employee_number = $_SESSION['reset_employee_no'];

    // Validate inputs
    if (empty($new_password)) {
        $_SESSION['reset_error'] = 'New password is required';
        return;
    }

    if (strlen($new_password) < 6) {
        $_SESSION['reset_error'] = 'Password must be at least 6 characters';
        return;
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['reset_error'] = 'Passwords do not match';
        return;
    }

    // Update password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    try {
        // Update password in users table
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE employee_no = ?");
        $stmt->execute([$hashed_password, $employee_number]);
        
        if ($stmt->rowCount() > 0) {
            // Clear session
            unset($_SESSION['reset_employee_no']);
            
            $_SESSION['reset_success'] = 'Password reset successfully! You can now login with your new password.';
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['reset_error'] = 'Failed to update password. Please try again.';
        }
    } catch(PDOException $e) {
        $_SESSION['reset_error'] = 'Error updating password: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
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

        .reset-container {
            width: 100%;
            max-width: 360px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 37px 0;
            min-height: 100vh;
            margin: auto;
        }

        .reset-form {
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

        .reset-title {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
            letter-spacing: 1px;
            margin-bottom: 32px;
            text-align: center;
        }

        .reset-subtitle {
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

        .password-input {
            width: 100%;
            padding: 18px 18px 18px 40px;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
        }

        .password-input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            transform: translateY(-2px);
        }

        .password-input.error {
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

        @media (max-width: 480px) {
            body {
                padding: 35px;
            }
            
            .reset-container {
                padding: 35px 0;
                justify-content: center;
                min-height: 100vh;
            }
            
            .reset-form {
                padding: 45px;
                border-radius: 14px;
                margin: 27px 0;
            }
            
            .company-logo {
                width: 180px;
                margin-bottom: 22px;
            }
            
            .reset-title {
                font-size: 22px;
                letter-spacing: 0.5px;
                margin-bottom: 33px;
            }
            
            .reset-subtitle {
                margin-bottom: 40px;
                font-size: 14px;
            }
            
            .form-group {
                margin-bottom: 37px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-form">
            <div class="logo-section">
                <img src="YAZAKI-TORRES-LOGO.webp" alt="Yazaki Torres Logo" class="company-logo">
                <h1 class="reset-title">SET NEW PASSWORD</h1>
                <p class="reset-subtitle">Enter your new password below</p>
            </div>

            <?php if (isset($_SESSION['reset_error'])): ?>
                <div class="global-error" id="global-error">
                    <?php 
                    echo htmlspecialchars($_SESSION['reset_error']);
                    unset($_SESSION['reset_error']);
                    ?>
                </div>
            <?php endif; ?>

            <form id="reset-form" method="POST" action="">
                <input type="hidden" name="action" value="reset_password">
                
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
            
            <div class="back-to-forgot">
                <a href="ForgotPassword.php">Back to Forgot Password</a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; 2025 Yazaki Torres. All rights reserved.</p>
        </div>
    </div>

    <script>
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('reset-btn');
        const resetText = document.getElementById('reset-text');
        const loading = document.getElementById('loading');

        newPasswordInput.addEventListener('input', function() {
            clearError('password');
            if (this.value.length > 0 && this.value.length < 6) {
                showError('password', 'Password must be at least 6 characters');
            }
            validatePasswordMatch();
        });

        confirmPasswordInput.addEventListener('input', function() {
            clearError('confirm');
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
            const password = newPasswordInput.value.trim();
            const confirm = confirmPasswordInput.value.trim();

            if (password === '') {
                showError('password', 'New password is required');
                isValid = false;
            } else if (password.length < 6) {
                showError('password', 'Password must be at least 6 characters');
                isValid = false;
            }

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
            const inputElement = document.getElementById(field === 'password' ? 'new_password' : 'confirm_password');
            errorElement.textContent = message;
            errorElement.style.display = 'block';
            inputElement.classList.add('error');
        }

        function clearError(field) {
            const errorElement = document.getElementById(field + '-error');
            const inputElement = document.getElementById(field === 'password' ? 'new_password' : 'confirm_password');
            errorElement.style.display = 'none';
            inputElement.classList.remove('error');
        }

        document.getElementById('reset-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            if (!validateForm()) return;
            resetBtn.disabled = true;
            resetText.style.display = 'none';
            loading.style.display = 'flex';
            this.submit();
        });
    </script>
</body>
</html>

