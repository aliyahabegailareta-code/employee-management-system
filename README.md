# Employee Management System

A comprehensive employee management and payroll system for Yazaki Torres Manufacturing Incorporated.

## Features

### Admin Portal
- Employee Management (Add, Edit, Delete, View)
- Attendance Tracking
- Payroll Processing
- 13th Month Pay Calculation
- Department Management
- Analytics Dashboard

### Employee Portal
- View Payslip History
- View Deductions
- Change Profile
- Password Reset (OTP-based)

## Technology Stack

- **Backend:** PHP 8.2+
- **Database:** MySQL/MariaDB
- **Frontend:** HTML, CSS, JavaScript
- **Server:** Apache (XAMPP)

## Installation

### Prerequisites
- XAMPP (Apache + MySQL + PHP)
- PHP 8.2 or higher
- MySQL/MariaDB

### Setup Instructions

1. **Clone or download this repository**
   ```bash
   git clone <your-repo-url>
   ```

2. **Copy files to XAMPP htdocs**
   - Copy `admin` folder to `C:\xampp\htdocs\admin`
   - Copy `user` folder to `C:\xampp\htdocs\user`

3. **Create Database**
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Import the SQL file: `employee_managements.sql`
   - Or run the SQL commands from the file

4. **Configure Database Connection**
   - Default settings (already configured):
     - Host: `localhost`
     - Database: `employee_managements`
     - Username: `root`
     - Password: `` (empty)

5. **Start Services**
   - Start Apache and MySQL from XAMPP Control Panel

6. **Access the System**
   - Admin Portal: http://localhost/admin/
   - User Portal: http://localhost/user/

## Default Login Credentials

### Admin Portal
- Username: `admin`
- Password: `admin123`

### Employee Portal
- Employee Number: `22-06554`
- Password: `password123`

## Database Schema

The system uses the following main tables:
- `employees` - Employee information
- `users` - User login credentials
- `admin_users` - Admin login credentials
- `attendance` - Attendance records
- `payroll_records` - Payroll history
- `departments` - Department information
- `thirteenth_month_pay` - 13th month pay records
- `password_reset_otps` - OTP for password reset

## Features Details

### Payroll System
- Automatic calculation based on attendance
- Support for overtime hours
- SSS, PhilHealth, and Pag-IBIG deductions
- Weekly payroll processing

### Attendance System
- Time in/Time out tracking
- Status tracking (Present, Absent, Late)
- Weekly attendance summary

### Security Features
- Password hashing (bcrypt)
- OTP-based password reset
- Session management
- SQL injection prevention (PDO prepared statements)

## File Structure

```
system-main/
├── admin/              # Admin portal files
│   ├── index.php       # Admin login
│   ├── admin-dashboard.php
│   ├── employees.php
│   ├── payroll.php
│   ├── attendance.php
│   └── ...
├── user/               # Employee portal files
│   ├── index.php       # Employee login
│   ├── dashboard.php
│   ├── History.php
│   ├── Deductions.php
│   └── ...
└── employee_managements.sql  # Database schema
```

## Recent Updates

- Fixed forgot password functionality
- Fixed payroll processing SQL errors (employee_id → employee_no)
- Fixed PDF export formatting issues
- Improved number formatting in reports

## License

Copyright © 2024 Yazaki Torres Manufacturing Incorporated. All rights reserved.

## Support

For issues or questions, please contact the development team.

