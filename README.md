# HR Management System

## Quick Deployment Guide

### Step 1: Database Setup
1. Create a MySQL database named `maggie_hr`
2. Import the SQL file: `hr_management_mysql.sql`

### Step 2: Configuration
Update database credentials in `config.php`:
```php
$host = 'localhost';          // Your MySQL host
$username = 'root';           // Your MySQL username  
$password = '';               // Your MySQL password
$database = 'maggie_hr';      // Your database name
```

### Step 3: Upload Files
Upload these files to your web server:
- All `.php` files
- `hr_management_mysql.sql` (for database import)
- `style.css`

### Default Login Credentials
- **Super Admin**: admin@company.com / admin123
- **HR Manager**: hr@company.com / hr123
- **Department Head**: depthead@company.com / dept123

## Features
- User management with edit/delete functionality
- Department and section management
- Employee management
- Role-based access control
- Sky blue theme design
- No PDO dependencies - uses standard mysqli

## Requirements
- PHP with MySQL support
- MySQL database
- Standard web hosting environment