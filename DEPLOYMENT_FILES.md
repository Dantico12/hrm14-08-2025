# Files Ready for Deployment

## Essential Files You Need:

### Core PHP Files:
- `config.php` - Database configuration (update credentials)
- `login.php` - Login page
- `dashboard.php` - Main dashboard
- `employees.php` - Employee management
- `departments.php` - Department/section management
- `users.php` - User management (super admin only)
- `logout.php` - Logout functionality
- `index.php` - Redirect to login

### Styling:
- `style.css` - Complete styling with sky blue theme

### Database:
- `hr_management_mysql.sql` - Complete database schema and default data

### Documentation:
- `README.md` - Deployment instructions

## Deployment Steps:

1. **Create MySQL database** named `hr_management`
2. **Import SQL file**: `hr_management_mysql.sql`
3. **Update config.php** with your database credentials:
   ```php
   $host = 'your_mysql_host';
   $username = 'your_mysql_username';
   $password = 'your_mysql_password';
   $database = 'hr_management';
   ```
4. **Upload all PHP files and style.css** to your web server
5. **Access login page** and use default credentials

## Default Login:
- admin@company.com / admin123
- hr@company.com / hr123
- depthead@company.com / dept123

All files are now using standard mysqli (no PDO required) and will work on any standard PHP hosting environment.