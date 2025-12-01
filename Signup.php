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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'signup') {
        handleSignup($pdo);
    }
}

function handleSignup($pdo) {
    $employee_number = $_POST['employee_number'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate input
    if (empty($employee_number) || empty($password) || empty($confirm_password)) {
        $_SESSION['signup_error'] = 'Please fill in all fields';
        return;
    }
    
    if ($password !== $confirm_password) {
        $_SESSION['signup_error'] = 'Passwords do not match';
        return;
    }
    
    if (strlen($password) < 6) {
        $_SESSION['signup_error'] = 'Password must be at least 6 characters long';
        return;
    }
    
    // Check if employee exists in employees table (from payroll)
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_no = ?");
    $stmt->execute([$employee_number]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION['signup_error'] = 'Employee number not found in system. Please contact HR.';
        return;
    }
    
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_no = ?");
    $stmt->execute([$employee_number]);
    $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_user) {
        $_SESSION['signup_error'] = 'Account already exists for this employee number';
        return;
    }
    
    // Create new user account
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (employee_no, password) VALUES (?, ?)");
    
    try {
        $stmt->execute([$employee_number, $hashed_password]);
        $_SESSION['signup_success'] = 'Account created successfully! You can now login.';
        header("Location: Login.php");
        exit();
    } catch(PDOException $e) {
        $_SESSION['signup_error'] = 'Error creating account: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yazaki Torres Payslip Sign Up</title>
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
        }

        .signup-container {
            width: 100%;
            max-width: 360px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 25px 0;
        }

        .signup-form {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid #e2e8f0;
            padding: 35px 25px;
            width: 100%;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .company-logo {
            width: 260px;
            height: auto;
            margin-bottom: 15px;
            max-width: 100%;
        }

        .payslip-title {
            font-size: 26px;
            font-weight: bold;
            color: #f10b1e;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        .subtitle {
            font-size: 16px;
            color: #64748b;
            font-weight: 500;
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

        .textbox1, .textbox2 {
            width: 100%;
            padding: 14px 14px 14px 40px;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            -webkit-appearance: none;
            appearance: none;
        }

        .textbox1:focus, .textbox2:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            transform: translateY(-2px);
        }

        .textbox1.error, .textbox2.error {
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

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #9ca3af;
            padding: 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .password-toggle:hover {
            color: #6b7280;
            background: #f3f4f6;
        }

        .success-icon {
            position: absolute;
            right: 40px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: #10b981;
            display: none;
        }

        .signup-button {
            width: 100%;
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            font-weight: 700;
            padding: 16px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px -5px rgba(30, 64, 175, 0.3);
            position: relative;
            overflow: hidden;
        }

        .signup-button:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            box-shadow: 0 20px 35px -5px rgba(30, 64, 175, 0.4);
            transform: translateY(-3px);
        }

        .signup-button:active {
            transform: translateY(-1px);
        }

        .signup-button:disabled {
            background: #93c5fd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .divider {
            position: relative;
            margin: 25px 0;
            text-align: center;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #d1d5db;
        }

        .divider-text {
            background: white;
            padding: 0 20px;
            color: #6b7280;
            font-weight: 600;
            font-size: 15px;
        }

        .login-link {
            display: block;
            text-align: center;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
            margin-top: 20px;
        }

        .login-link a {
            color: #1e40af;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .login-link a:hover {
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

        .global-success {
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

        .global-success::before {
            content: '✓';
            font-size: 16px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobile optimizations */
        @media (max-width: 480px) {
            body {
                padding: 10px;
                min-height: 100vh;
                display: flex;
                align-items: center;
            }
            
            .signup-container {
                padding: 0;
                justify-content: center;
            }
            
            .signup-form {
                padding: 30px 20px;
                border-radius: 14px;
            }
            
            .company-logo {
                width: 160px;
                margin-bottom: 12px;
            }
            
            .payslip-title {
                font-size: 24px;
                letter-spacing: 1px;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
            
            .textbox1, .textbox2 {
                padding: 14px 14px 14px 40px;
                font-size: 14px;
            }
            
            .signup-button {
                padding: 15px;
                font-size: 15px;
            }

            .footer {
                margin-top: 18px;
                font-size: 11px;
            }
        }

        /* Very small screens */
        @media (max-width: 360px) {
            .signup-form {
                padding: 25px 15px;
            }
            
            .company-logo {
                width: 140px;
            }
            
            .payslip-title {
                font-size: 22px;
            }

            .form-group {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="signup-container">
        <div class="signup-form">
            <!-- Logo Section -->
            <div class="logo-section">
                <img src="YAZAKI-TORRES-LOGO.webp" alt="Yazaki Torres Logo" class="company-logo">
                <h1 class="payslip-title">CREATE ACCOUNT</h1>
                <p class="subtitle">Employee Portal</p>
            </div>

            <!-- Display error message if exists -->
            <?php if (isset($_SESSION['signup_error'])): ?>
                <div class="global-error" id="global-error">
                    <?php 
                    echo htmlspecialchars($_SESSION['signup_error']);
                    unset($_SESSION['signup_error']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Display success message if exists -->
            <?php if (isset($_SESSION['signup_success'])): ?>
                <div class="global-success" id="global-success">
                    <?php 
                    echo htmlspecialchars($_SESSION['signup_success']);
                    unset($_SESSION['signup_success']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Signup Form -->
            <form id="signup-form" method="POST" action="">
                <input type="hidden" name="action" value="signup">
                
                <!-- Employee Number Input -->
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
                            class="textbox1" 
                            maxlength="8"
                            required
                            value="<?php echo isset($_POST['employee_number']) ? htmlspecialchars($_POST['employee_number']) : ''; ?>"
                        >
                        <svg class="success-icon" id="employee_success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div id="employee-error" class="error-message"></div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <circle cx="12" cy="16" r="1"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter password" 
                            class="textbox2" 
                            required
                        >
                        <button type="button" class="password-toggle" id="password-toggle">
                            <svg id="password-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <div id="password-error" class="error-message"></div>
                </div>

                <!-- Confirm Password Input -->
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
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
                            placeholder="Confirm password" 
                            class="textbox2" 
                            required
                        >
                        <button type="button" class="password-toggle" id="confirm-password-toggle">
                            <svg id="confirm-password-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                        <svg class="success-icon" id="confirm_success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div id="confirm-error" class="error-message"></div>
                </div>

                <!-- Sign Up Button -->
                <button type="submit" class="signup-button" id="signup-btn">
                    <span id="signup-text">Create Account</span>
                    <div class="loading" id="loading">
                        <div class="spinner"></div>
                        Creating Account...
                    </div>
                </button>
            </form>

            <!-- Divider -->
            <div class="divider">
                <span class="divider-text">or</span>
            </div>

            <!-- Login Link -->
            <div class="login-link">
                Already have an account? <a href="Login.php">Login</a>
            </div>
        </div>
    </div>

    <script>
        // Form elements
        const signupForm = document.getElementById('signup-form');
        const employeeInput = document.getElementById('employee_number');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const signupBtn = document.getElementById('signup-btn');
        const signupText = document.getElementById('signup-text');
        const loading = document.getElementById('loading');
        const passwordToggle = document.getElementById('password-toggle');
        const confirmPasswordToggle = document.getElementById('confirm-password-toggle');
        const passwordEyeIcon = document.getElementById('password-eye-icon');
        const confirmPasswordEyeIcon = document.getElementById('confirm-password-eye-icon');
        const globalError = document.getElementById('global-error');
        const globalSuccess = document.getElementById('global-success');

        // Format employee number to XX-XXXXX
        function formatEmployeeNumber(value) {
            // Remove all non-numeric characters
            const numericValue = value.replace(/[^0-9]/g, '');
            
            // Limit to 7 digits maximum (XX-XXXXX format)
            const limitedValue = numericValue.slice(0, 7);
            
            // Add dash after 2 digits if there are more than 2 digits
            if (limitedValue.length > 2) {
                return limitedValue.slice(0, 2) + '-' + limitedValue.slice(2);
            }
            
            return limitedValue;
        }

        // Employee number input handling
        employeeInput.addEventListener('input', function(e) {
            const formatted = formatEmployeeNumber(e.target.value);
            e.target.value = formatted;
            clearError('employee');
            if (globalError) globalError.style.display = 'none';
        });

        // Prevent non-numeric characters from being typed (except dash)
        employeeInput.addEventListener('keypress', function(e) {
            if (!/[0-9]/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab' && e.key !== 'Enter' && e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
                e.preventDefault();
            }
        });

        // Password input validation
        passwordInput.addEventListener('input', function() {
            clearError('password');
            if (globalError) globalError.style.display = 'none';
            validateConfirmPassword();
        });

        confirmPasswordInput.addEventListener('input', function() {
            clearError('confirm');
            if (globalError) globalError.style.display = 'none';
            validateConfirmPassword();
        });

        // Password toggle functionality
        function togglePasswordVisibility(inputId, toggleId, eyeIconId) {
            const input = document.getElementById(inputId);
            const eyeIcon = document.getElementById(eyeIconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                `;
            } else {
                input.type = 'password';
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                `;
            }
        }

        passwordToggle.addEventListener('click', () => togglePasswordVisibility('password', 'password-toggle', 'password-eye-icon'));
        confirmPasswordToggle.addEventListener('click', () => togglePasswordVisibility('confirm_password', 'confirm-password-toggle', 'confirm-password-eye-icon'));

        // Form validation
        function validateForm() {
            let isValid = true;
            const employeeNumber = employeeInput.value.trim();
            const password = passwordInput.value.trim();
            const confirmPassword = confirmPasswordInput.value.trim();

            // Validate employee number
            if (!employeeNumber) {
                showError('employee', 'Employee number is required');
                isValid = false;
            } else if (!/^\d{2}-\d{5}$/.test(employeeNumber)) {
                showError('employee', 'Employee number must be in XX-XXXXX format');
                isValid = false;
            } else {
                document.getElementById('employee_success').style.display = 'block';
            }

            // Validate password
            if (!password) {
                showError('password', 'Password is required');
                isValid = false;
            } else if (password.length < 6) {
                showError('password', 'Password must be at least 6 characters');
                isValid = false;
            }

            // Validate confirm password
            if (!confirmPassword) {
                showError('confirm', 'Please confirm your password');
                isValid = false;
            } else if (password !== confirmPassword) {
                showError('confirm', 'Passwords do not match');
                isValid = false;
            } else {
                document.getElementById('confirm_success').style.display = 'block';
            }

            return isValid;
        }

        function validateConfirmPassword() {
            const password = passwordInput.value.trim();
            const confirmPassword = confirmPasswordInput.value.trim();
            
            if (confirmPassword && password === confirmPassword) {
                document.getElementById('confirm_success').style.display = 'block';
                clearError('confirm');
            } else {
                document.getElementById('confirm_success').style.display = 'none';
            }
        }

        function showError(field, message) {
            const errorElement = document.getElementById(field + '-error');
            const inputElement = document.getElementById(field === 'employee' ? 'employee_number' : field === 'confirm' ? 'confirm_password' : 'password');
            
            errorElement.textContent = message;
            errorElement.style.display = 'block';
            inputElement.classList.add('error');
            
            if (field === 'employee') {
                document.getElementById('employee_success').style.display = 'none';
            } else if (field === 'confirm') {
                document.getElementById('confirm_success').style.display = 'none';
            }
        }

        function clearError(field) {
            const errorElement = document.getElementById(field + '-error');
            const inputElement = document.getElementById(field === 'employee' ? 'employee_number' : field === 'confirm' ? 'confirm_password' : 'password');
            
            errorElement.style.display = 'none';
            inputElement.classList.remove('error');
        }

        // Form submission
        signupForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!validateForm()) return;
            
            // Show loading state
            signupBtn.disabled = true;
            signupText.style.display = 'none';
            loading.style.display = 'flex';
            
            // Submit the form
            signupForm.submit();
        });
    </script>
</body>
</html>