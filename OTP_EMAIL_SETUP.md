# OTP Email Setup Guide

This guide explains how to set up email functionality for the OTP password reset feature.

## Prerequisites

1. PHP mail() function enabled on your server
2. Email column in the `employees` table (see SQL script below)
3. Valid email addresses for employees

## Database Setup

### Step 1: Add Email Column to Employees Table

If your `employees` table doesn't have an `email` column, run this SQL script:

```sql
-- Add email column to employees table
ALTER TABLE `employees` 
ADD COLUMN `email` VARCHAR(255) NULL AFTER `name`;

-- Optional: Add index for faster lookups
CREATE INDEX `idx_employee_email` ON `employees`(`email`);
```

### Step 2: Update Employee Email Addresses

Update your employees table with their email addresses:

```sql
-- Example: Update employee email
UPDATE `employees` 
SET `email` = 'employee@example.com' 
WHERE `employee_no` = '22-06554';
```

## Email Configuration

### Option 1: Using PHP mail() Function (Default)

The system uses PHP's built-in `mail()` function by default. This works if your server has mail configured.

**Configuration in `send_otp_email.php`:**

```php
$from_email = "noreply@yazakitorres.com"; // Change to your company email
$from_name = "Yazaki Torres Manufacturing";
```

### Option 2: Using SMTP (Recommended for Production)

For better reliability, you can configure SMTP. You'll need to modify `send_otp_email.php` to use PHPMailer or similar library.

#### Installing PHPMailer (if using SMTP):

1. Download PHPMailer from: https://github.com/PHPMailer/PHPMailer
2. Extract to your project folder
3. Update `send_otp_email.php` to use PHPMailer instead of mail()

Example PHPMailer configuration:

```php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'path/to/PHPMailer/src/Exception.php';
require 'path/to/PHPMailer/src/PHPMailer.php';
require 'path/to/PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-app-password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
```

## Testing

1. Make sure an employee has an email address in the database
2. Go to the Forgot Password page
3. Enter the employee number
4. Check the employee's email inbox for the OTP code

## Troubleshooting

### Email Not Sending

1. **Check PHP mail() configuration:**
   - Verify `php.ini` has mail settings configured
   - Check server logs for mail errors

2. **Check email addresses:**
   - Ensure employees have valid email addresses in the database
   - Verify email format is correct

3. **Check spam folder:**
   - OTP emails might be filtered as spam
   - Check spam/junk folder

4. **Fallback Mode:**
   - If email sending fails, the system will display the OTP on screen as a fallback
   - This is for development/testing purposes only

### Email Column Not Found

If you get errors about the email column not existing:
1. Run the SQL script above to add the column
2. Update employee records with email addresses

## Security Notes

1. **Production Environment:**
   - Remove the fallback OTP display in production
   - Use SMTP with authentication
   - Use secure email providers

2. **Email Privacy:**
   - Email addresses are masked when displayed to users
   - Only the domain and partial username are shown

3. **OTP Expiration:**
   - OTP codes expire after 10 minutes
   - Each OTP can only be used once

## Support

For issues or questions, contact your system administrator.

