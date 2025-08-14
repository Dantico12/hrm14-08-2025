# HR Management System

## Overview

This is a comprehensive HR Management System built with PHP and MySQL, featuring employee management, organizational hierarchy, and role-based access control. The application provides a clean, professional interface for managing human resources in an organization.

## User Preferences

Preferred communication style: Simple, everyday language.
Technology stack: PHP and PostgreSQL (user explicitly requested this over Node.js)
Theme color: Sky blue (#0ea5e9) - implemented throughout the application
UI preferences: Small buttons for better spacing, role-based access controls

## System Architecture

### Backend Architecture
- **Language**: PHP 8.x
- **Database**: MySQL with prepared statements for security
- **Authentication**: Session-based authentication with email/password
- **Security**: Password hashing with PHP's password_hash() function

### Frontend Architecture
- **UI**: HTML5 with Bootstrap for responsive design
- **JavaScript**: Vanilla JavaScript for interactive elements
- **Styling**: Bootstrap CSS framework with custom styles

### Project Structure
```
├── index.php           # Landing page with login form
├── dashboard.php       # Main dashboard after login
├── employees.php       # Employee management page
├── config/
│   ├── database.php    # Database connection
│   └── auth.php        # Authentication functions
├── includes/
│   ├── header.php      # Common header
│   ├── footer.php      # Common footer
│   └── sidebar.php     # Navigation sidebar
├── assets/
│   ├── css/           # Custom stylesheets
│   ├── js/            # JavaScript files
│   └── images/        # Image assets
└── sql/
    └── schema.sql      # Database schema
```

## Database Schema

### Core Tables
- **users**: System users with login credentials
- **employees**: Employee records with personal and employment details
- **departments**: Organizational departments
- **sections**: Sub-departmental sections
- **employee_roles**: Role assignments and permissions

### Key Relationships
- Employees belong to departments and sections
- Users can be linked to employee records
- Role-based access control determines permissions

## Authentication System

### Default Login Credentials
- **Super Admin**: admin@company.com / admin123
- **HR Manager**: hr@company.com / hr123
- **Department Head**: depthead@company.com / dept123

### Role Hierarchy
1. **Super Admin**: Full system access
2. **HR Manager**: Employee management, reports
3. **Department Head**: View department employees
4. **Section Head**: View section employees
5. **Manager**: Section-level management
6. **Officer**: Basic employee access

## Employee Management Features

### Employee Types and Access Control
- **Officer**: Basic employee, can view own profile
- **Section Head**: Manages specific section employees
- **Manager**: Similar to section head with additional privileges
- **Department Head**: Manages entire department
- **Managing Director**: Oversees all operations
- **BOD Chairman**: Highest level access

### Form Field Logic
- **MD/BOD Chairman**: No department/section fields required
- **Department Head**: Department required, section hidden
- **Others**: Both department and section fields available

## Recent Changes

- 2025-01-17: Converted from Node.js/React to PHP/SQLite per user request
- 2025-01-17: Implemented session-based authentication with default credentials
- 2025-01-17: Created responsive Bootstrap-based UI with professional gradient design
- 2025-01-17: Set up SQLite database with automatic initialization
- 2025-01-17: Created complete PHP application with login, dashboard, and employee management
- 2025-01-17: Database successfully initialized with 3 users, 5 departments, 11 sections, and 3 employees
- 2025-01-17: Created static PHP/MySQL version per user request with SQL import file
- 2025-01-17: Generated complete standalone files: hr_management.sql, config.php, login.php, dashboard.php, employees.php, style.css, README.md
- 2025-01-17: Fixed all database compatibility issues (PostgreSQL syntax, session management, column requirements)
- 2025-01-17: Added employee edit functionality with full form modals for HR managers and super admins
- 2025-01-17: Created departments.php page with department and section management capabilities
- 2025-01-17: Implemented dropdown-based section creation under specific departments
- 2025-01-17: Replaced hire date column with actions column containing edit buttons
- 2025-01-17: Removed salary field from employee forms as per user request for payroll module handling
- 2025-01-17: Added delete functionality for departments and sections with safety checks
- 2025-01-17: Created comprehensive user management system for super admins
- 2025-01-17: Implemented sky blue theme throughout the application per user request
- 2025-01-17: Added proper role-based navigation menus and access controls
- 2025-01-17: Reduced button sizes for better UI spacing using .btn-sm class
- 2025-07-18: Fixed authentication system to support both hashed passwords and legacy default passwords
- 2025-07-18: Added missing phone and address columns to users table
- 2025-07-18: Created MySQL deployment version and comprehensive deployment guide

## Development Notes

The system is designed to be simple and maintainable, using standard PHP practices and MySQL for reliability. All forms include proper validation and security measures.