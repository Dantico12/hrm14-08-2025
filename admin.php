<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

// Initialize $tab with default value BEFORE any output
$tab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'users';

$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

function hasPermission($requiredRole) {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    
    // Permission hierarchy - Fixed variable name from $role to $roles
    $roles = [
        'managing_director' => 6,
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];

    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

// Only super admin or HR manager can access this page
if (!(hasPermission('super_admin') || hasPermission('hr_manager'))) {
    header('Location: dashboard.php');
    exit();
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($location, $message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: {$location}");
    exit();
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return false;
}

// Database-aware Financial Year Helper Functions
function getCurrentFinancialYear($current_date = null, $mysqli = null) {
    if ($current_date === null) {
        $current_date = date('Y-m-d');
    }
    
    // If database connection is provided, check what financial year actually exists
    if ($mysqli !== null) {
        return getCurrentFinancialYearFromDatabase($current_date, $mysqli);
    }
    
    // Fallback to date-based calculation if no database connection
    return calculateFinancialYearByDate($current_date);
}

function getCurrentFinancialYearFromDatabase($current_date, $mysqli) {
    // First, try to find a financial year that contains the current date
    $stmt = $mysqli->prepare("SELECT * FROM financial_years 
                             WHERE ? BETWEEN start_date AND end_date 
                             AND is_active = 1 
                             ORDER BY start_date DESC 
                             LIMIT 1");
    
    if ($stmt) {
        $stmt->bind_param("s", $current_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return [
                'id' => $row['id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'year_name' => $row['year_name'],
                'from_database' => true
            ];
        }
    }
    
    // If no matching financial year found, find the most recent active one
    $stmt = $mysqli->prepare("SELECT * FROM financial_years 
                             WHERE is_active = 1 
                             ORDER BY start_date DESC 
                             LIMIT 1");
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return [
                'id' => $row['id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'year_name' => $row['year_name'],
                'from_database' => true,
                'note' => 'Using most recent financial year from database'
            ];
        }
    }
    
    // If no financial years exist in database, fallback to calculation
    return calculateFinancialYearByDate($current_date);
}

function calculateFinancialYearByDate($current_date) {
    $current_year = date('Y', strtotime($current_date));
    $current_month = date('n', strtotime($current_date)); // 1-12
    
    if ($current_month >= 7) {
        // Financial year: July current_year to June next_year
        $start_year = $current_year;
        $end_year = $current_year + 1;
    } else {
        // Financial year: July previous_year to June current_year
        $start_year = $current_year - 1;
        $end_year = $current_year;
    }
    
    return [
        'start_date' => $start_year . '-07-01',
        'end_date' => $end_year . '-06-30',
        'year_name' => $start_year . '/' . substr($end_year, 2),
        'from_database' => false
    ];
}

function getNextFinancialYear($current_date = null, $mysqli = null) {
    $current_fy = getCurrentFinancialYear($current_date, $mysqli);
    
    // Extract the end year from current financial year's end_date
    $current_end_year = (int)explode('-', $current_fy['end_date'])[0];
    
    // Next FY starts the day after current FY ends
    $next_start_year = $current_end_year;
    $next_end_year = $next_start_year + 1;
    
    return [
        'start_date' => $next_start_year . '-07-01',
        'end_date' => $next_end_year . '-06-30',
        'year_name' => $next_start_year . '/' . substr($next_end_year, 2)
    ];
}

function canCreateNewFinancialYear($mysqli) {
    $current_date = date('Y-m-d');
    
    // Get the current financial year from database
    $current_fy = getCurrentFinancialYear($current_date, $mysqli);
    $next_fy = getNextFinancialYear($current_date, $mysqli);
    
    // Check if next financial year already exists in database
    $stmt = $mysqli->prepare("SELECT id FROM financial_years WHERE year_name = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return [
            'can_create' => false,
            'reason' => 'Database error: ' . $mysqli->error,
            'next_fy' => null,
            'current_fy' => $current_fy
        ];
    }
    
    $stmt->bind_param("s", $next_fy['year_name']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->fetch_assoc()) {
        return [
            'can_create' => false,
            'reason' => 'Financial year ' . $next_fy['year_name'] . ' already exists.',
            'next_fy' => null,
            'current_fy' => $current_fy
        ];
    }
    
    // Additional check: ensure we're not too far from the transition period
    $current_fy_end = strtotime($current_fy['end_date']);
    $current_timestamp = strtotime($current_date);
    $days_from_fy_end = ($current_timestamp - $current_fy_end) / (60 * 60 * 24);
    
    // Allow creation if we're within 30 days before or 90 days after the financial year end
    if ($days_from_fy_end < -30) {
        return [
            'can_create' => false,
            'reason' => 'Too early to create next financial year. You can create it 30 days before the current financial year ends (' . date('M d, Y', $current_fy_end) . ').',
            'next_fy' => null,
            'current_fy' => $current_fy,
            'days_until_creation' => abs($days_from_fy_end + 30)
        ];
    }
    
    if ($days_from_fy_end > 90) {
        return [
            'can_create' => false,
            'reason' => 'Too late to create financial year ' . $next_fy['year_name'] . '. Please contact system administrator.',
            'next_fy' => null,
            'current_fy' => $current_fy
        ];
    }
    
    return [
        'can_create' => true,
        'reason' => 'Ready to create next financial year.',
        'next_fy' => $next_fy,
        'current_fy' => $current_fy,
        'creation_window' => $days_from_fy_end <= 0 ? 'Pre-creation window' : 'Post-deadline creation'
    ];
}

function allocateLeaveToAllEmployees($mysqli, $financial_year_id) {
    $debug_info = [];
    $allocated_count = 0;
    
    try {
        // Start transaction for data consistency
        $mysqli->begin_transaction();
        
        // Get new FY start date to identify previous FY
        $stmt = $mysqli->prepare("SELECT start_date, year_name FROM financial_years WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare FY query: " . $mysqli->error);
        }
        
        $stmt->bind_param("i", $financial_year_id);
        $stmt->execute();
        $new_fy = $stmt->get_result()->fetch_assoc();
        
        if (!$new_fy) {
            throw new Exception("Financial year with ID {$financial_year_id} not found");
        }
        
        $new_fy_start = $new_fy['start_date'];
        $debug_info[] = "New FY: {$new_fy['year_name']}, Start: {$new_fy_start}";

        // Get previous financial year ID
        $prev_fy_id = null;
        $prev_stmt = $mysqli->prepare("SELECT id, year_name FROM financial_years 
                                      WHERE end_date < ? 
                                      ORDER BY end_date DESC 
                                      LIMIT 1");
        if ($prev_stmt) {
            $prev_stmt->bind_param("s", $new_fy_start);
            $prev_stmt->execute();
            $prev_result = $prev_stmt->get_result();
            if ($row = $prev_result->fetch_assoc()) {
                $prev_fy_id = $row['id'];
                $debug_info[] = "Previous FY found: {$row['year_name']} (ID: {$prev_fy_id})";
            } else {
                $debug_info[] = "No previous financial year found";
            }
        }

        // Pre-fetch previous annual leave balances [employee_id => remaining_days]
        $prev_balances = [];
        if ($prev_fy_id) {
            $balance_stmt = $mysqli->prepare("SELECT employee_id, remaining_days 
                                             FROM employee_leave_balances 
                                             WHERE leave_type_id = 1 
                                               AND financial_year_id = ?");
            if ($balance_stmt) {
                $balance_stmt->bind_param("i", $prev_fy_id);
                $balance_stmt->execute();
                $balance_result = $balance_stmt->get_result();
                while ($row = $balance_result->fetch_assoc()) {
                    $prev_balances[$row['employee_id']] = (float)$row['remaining_days'];
                }
                $debug_info[] = "Previous balances loaded for " . count($prev_balances) . " employees";
            }
        }

        // Define leave allocation rules
        // Define leave allocation rules
$leave_rules = [
    // Annual Leave - only for permanent employees
    ['leave_type_id' => 1, 'days' => 30,  'gender' => 'all',    'employment' => 'permanent'],
    // Short Leave - for all employees including contract
    ['leave_type_id' => 6, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],
    // Other leave types...
    ['leave_type_id' => 5, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],       // Study
    ['leave_type_id' => 2, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],       // Sick
    ['leave_type_id' => 3, 'days' => 120, 'gender' => 'female', 'employment' => 'all'],       // Maternity
    ['leave_type_id' => 4, 'days' => 10,  'gender' => 'male',   'employment' => 'all'],       // Paternity
    ['leave_type_id' => 7, 'days' => 10,  'gender' => 'all',    'employment' => 'all'],       // Compassionate
];

        // Get all active employees with better debugging
        $employees_query = "SELECT id, gender, employment_type, CONCAT(first_name, ' ', last_name) as full_name 
                           FROM employees 
                           WHERE employee_status = 'active'";
        $employees_result = $mysqli->query($employees_query);
        
        if (!$employees_result) {
            throw new Exception("Failed to fetch employees: " . $mysqli->error);
        }
        
        $employees = $employees_result->fetch_all(MYSQLI_ASSOC);
        $debug_info[] = "Found " . count($employees) . " active employees";
        
        if (count($employees) == 0) {
            throw new Exception("No active employees found in the database");
        }

        // Prepare statements with better error checking
        $check_stmt = $mysqli->prepare("SELECT id FROM employee_leave_balances 
                                       WHERE employee_id = ? 
                                         AND leave_type_id = ? 
                                         AND financial_year_id = ?");
        
        $insert_stmt = $mysqli->prepare("INSERT INTO employee_leave_balances 
                                        (employee_id, leave_type_id, financial_year_id, allocated_days, used_days, remaining_days, total_days, created_at, updated_at) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        if (!$check_stmt || !$insert_stmt) {
            throw new Exception("Failed to prepare statements - Check: " . ($check_stmt ? "OK" : $mysqli->error) . 
                              ", Insert: " . ($insert_stmt ? "OK" : $mysqli->error));
        }

        $employee_count = 0;
        $rule_applications = 0;
        
        foreach ($employees as $employee) {
            $emp_id = $employee['id'];
            $gender = strtolower(trim($employee['gender'] ?? ''));
            $employment = strtolower(trim($employee['employment_type'] ?? ''));
            $employee_count++;
            
            $debug_info[] = "Processing Employee {$employee_count}: {$employee['full_name']} (ID: {$emp_id}, Gender: {$gender}, Employment: {$employment})";

            foreach ($leave_rules as $rule_index => $rule) {
                $rule_applications++;
                
                // Check eligibility based on gender and employment type
                $gender_ok = $rule['gender'] === 'all' || $rule['gender'] === $gender;
                $employment_ok = $rule['employment'] === 'all' || $rule['employment'] === $employment;

                if (!$gender_ok || !$employment_ok) {
                    $debug_info[] = "  Rule {$rule_index} (LT:{$rule['leave_type_id']}): SKIPPED - Gender: {$gender} vs {$rule['gender']}, Employment: {$employment} vs {$rule['employment']}";
                    continue;
                }

                // Check if allocation already exists
                $check_stmt->bind_param("sii", $emp_id, $rule['leave_type_id'], $financial_year_id);
                if (!$check_stmt->execute()) {
                    $debug_info[] = "  Rule {$rule_index}: Check query failed - " . $check_stmt->error;
                    continue;
                }
                
                $existing = $check_stmt->get_result()->fetch_assoc();
                if ($existing) {
                    $debug_info[] = "  Rule {$rule_index}: ALREADY EXISTS";
                    continue;
                }

                // Determine allocated days and total balance
                $allocated_days = (float)$rule['days'];
                $used_days = 0.0;
                
                // Special handling for annual leave (carry over + new allocation)
                if ($rule['leave_type_id'] == 1 && $employment === 'permanent') {
                    $prev_balance = $prev_balances[$emp_id] ?? 0;
                    
                    // Only allocate 30 days, but total available = previous balance + 30
                    $allocated_days = 30; 
                    $remaining_days = $prev_balance + $allocated_days;
                    $total_days = $remaining_days;
                    
                    $debug_info[] = "  Rule {$rule_index}: Annual leave with carryover - New Allocation: 30, Carryover: {$prev_balance}, Total Available: {$remaining_days}";
                } else {
                    // For all other leave types, allocated = total = remaining
                    $total_days = $allocated_days;
                    $remaining_days = $allocated_days;
                    
                    $debug_info[] = "  Rule {$rule_index}: Standard allocation - {$allocated_days} days";
                }

                // Insert allocation
                $insert_stmt->bind_param("siiiddd", $emp_id, $rule['leave_type_id'], $financial_year_id, 
                                        $allocated_days, $used_days, $remaining_days, $total_days);

                if ($insert_stmt->execute()) {
                    $allocated_count++;
                    $debug_info[] = "  Rule {$rule_index}: SUCCESS - Allocated: {$allocated_days}, Total: {$total_days}, Remaining: {$remaining_days}";
                } else {
                    $debug_info[] = "  Rule {$rule_index}: FAILED - " . $insert_stmt->error;
                    error_log("Insert failed for employee {$emp_id}, leave type {$rule['leave_type_id']}: " . $insert_stmt->error);
                }
            }
        }

        // Commit transaction
        $mysqli->commit();
        
        $debug_info[] = "SUMMARY: Processed {$employee_count} employees, {$rule_applications} rule applications, {$allocated_count} successful allocations";
        
        // Log debug info
        error_log("Leave Allocation Debug Info:\n" . implode("\n", $debug_info));
        
        return $allocated_count;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($mysqli->in_transaction()) {
            $mysqli->rollback();
        }
        
        $debug_info[] = "ERROR: " . $e->getMessage();
        error_log("Leave Allocation Error:\n" . implode("\n", $debug_info));
        return 0;
    }
}

$mysqli = getConnection();

// Get financial year status using database-aware functions
$fy_status = canCreateNewFinancialYear($mysqli);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add_financial_year') {
            // Get fresh status before processing
            $fy_status = canCreateNewFinancialYear($mysqli);
            
            if (!$fy_status['can_create']) {
                $error = $fy_status['reason'];
                
                // Add more context to the error message
                if (isset($fy_status['days_until_creation'])) {
                    $error .= ' (Available in ' . ceil($fy_status['days_until_creation']) . ' days)';
                }
            } else {
                $next_fy = $fy_status['next_fy'];
                $start_date = $next_fy['start_date'];
                $end_date = $next_fy['end_date'];
                $year_name = $next_fy['year_name'];
                
                try {
                    // Calculate total days
                    $start_timestamp = strtotime($start_date);
                    $end_timestamp = strtotime($end_date);
                    $total_days = ceil(($end_timestamp - $start_timestamp) / (60 * 60 * 24)) + 1;
                    
                    // Insert new financial year
                    $stmt = $mysqli->prepare("INSERT INTO financial_years (start_date, end_date, year_name, total_days, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                    if (!$stmt) {
                        throw new Exception('Failed to prepare financial year insert: ' . $mysqli->error);
                    }
                    
                    $stmt->bind_param("sssi", $start_date, $end_date, $year_name, $total_days);
                    
                    if ($stmt->execute()) {
                        $financial_year_id = $mysqli->insert_id;
                        error_log("Financial year created with ID: {$financial_year_id}");
                        
                        // Allocate leave to all employees
                        $allocated_count = allocateLeaveToAllEmployees($mysqli, $financial_year_id);
                        
                        redirectWithMessage('admin.php?tab=financial', 
                            "Financial year '{$year_name}' created successfully! Leave allocated to {$allocated_count} employee-leave type combinations.", 
                            'success');
                    } else {
                        throw new Exception('Failed to create financial year: ' . $mysqli->error);
                    }
                } catch (Exception $e) {
                    $error = 'Error creating financial year: ' . $e->getMessage();
                    error_log("Financial year creation error: " . $e->getMessage());
                }
            }
        } elseif ($action === 'debug_leave_allocation') {
            // Debug action to test leave allocation without creating a new FY
            if (isset($_POST['fy_id']) && is_numeric($_POST['fy_id'])) {
                $fy_id = (int)$_POST['fy_id'];
                $allocated_count = allocateLeaveToAllEmployees($mysqli, $fy_id);
                $success = "Debug allocation completed. {$allocated_count} allocations made. Check error log for details.";
            }
        }
        // User management actions (existing code...)
        elseif ($action === 'add_user') {
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $email = sanitizeInput($_POST['email']);
            $password = $_POST['password'];
            $role = $_POST['role'];
            $phone = sanitizeInput($_POST['phone']);
            $address = sanitizeInput($_POST['address']);
            $employee_id = sanitizeInput($_POST['employee_id']);
            
            try {
                // Check if email already exists
                $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->fetch_assoc()) {
                    $error = 'Email already exists in the system.';
                } else {
                    // Generate unique user ID based on role
                    $rolePrefix = substr($role, 0, 3);
                    $timestamp = time();
                    $userId = $rolePrefix . '-' . $timestamp;
                    
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $mysqli->prepare("INSERT INTO users (id, first_name, last_name, email, password, role, phone, address, employee_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->bind_param("sssssssss", $userId, $first_name, $last_name, $email, $hashedPassword, $role, $phone, $address, $employee_id);
                    $stmt->execute();
                    redirectWithMessage('admin.php?tab=users', 'User created successfully!', 'success');
                }
            } catch (Exception $e) {
                $error = 'Error creating user: ' . $mysqli->error;
            }
        }
        // ... other user management actions remain the same
    }
}

// Get all users
$result = $mysqli->query("SELECT * FROM users ORDER BY first_name, last_name");
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Get all financial years
$financial_years_result = $mysqli->query("SELECT * FROM financial_years ORDER BY start_date DESC");
$financial_years = $financial_years_result ? $financial_years_result->fetch_all(MYSQLI_ASSOC) : [];

// Get employee count for debugging
$employee_count_result = $mysqli->query("SELECT COUNT(*) as count FROM employees WHERE employee_status = 'active'");
$employee_count = $employee_count_result ? $employee_count_result->fetch_assoc()['count'] : 0;

// Get leave types for debugging
$leave_types_result = $mysqli->query("SELECT * FROM leave_types ORDER BY id");
$leave_types = $leave_types_result ? $leave_types_result->fetch_all(MYSQLI_ASSOC) : [];

function getRoleBadge($role) {
    switch($role) {
        case 'super_admin': return 'badge-danger';
        case 'hr_manager': return 'badge-warning';
        case 'dept_head': return 'badge-info';
        case 'section_head': return 'badge-secondary';
        case 'manager': return 'badge-primary';
        default: return 'badge-light';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - HR Management System</title>
    <link rel="stylesheet" href="style.css">
    <style>
    .fy-status-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 15px;
    }

    .fy-current, .fy-next {
        padding: 15px;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        background: #f8f9fa;
    }

    .fy-current h5, .fy-next h5 {
        margin-bottom: 10px;
        color: #333;
    }

    .fy-current p, .fy-next p {
        margin-bottom: 5px;
    }

    @media (max-width: 768px) {
        .fy-status-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-brand">
                <h1>HR System</h1>
                <p>Management Portal</p>
            </div>
            <nav class="nav">
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="employees.php">Employees</a></li>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="departments.php">Departments</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('super_admin') || hasPermission('hr_manager')): ?>
                    <li><a href="admin.php" class="active">Admin</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager') || hasPermission('super_admin') || hasPermission('dept_head')): ?>
                    <li><a href="leave_management.php">Leave Management</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Admin Panel</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type']; ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <div class="leave-tabs">
                    <?php if (in_array($user['role'], ['super_admin'])): ?>
                    <a href="admin.php?tab=users" class="leave-tab <?php echo $tab === 'users' ? 'active' : ''; ?>">Users</a>
                    <?php endif; ?>
                    <a href="admin.php?tab=financial" class="leave-tab <?php echo $tab === 'financial' ? 'active' : ''; ?>">Financial Year</a>
                                </div>

                <?php if ($tab === 'users'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2>System Users (<?php echo count($users); ?>)</h2>
                    <button onclick="showAddUserModal()" class="btn btn-success">Add New User</button>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No users found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user_row): ?>
                                <tr>
                                    <td><?php echo $user_row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user_row['first_name'] . ' ' . $user_row['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user_row['email']); ?></td>
                                    <td>
                                        <span class="badge <?php echo getRoleBadge($user_row['role']); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $user_row['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user_row['phone'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-success">Active</span>
                                    </td>
                                    <td><?php echo formatDate($user_row['created_at']); ?></td>
                                    <td>
                                        <button onclick="showEditUserModal(<?php echo htmlspecialchars(json_encode($user_row)); ?>)" class="btn btn-sm btn-primary">Edit</button>
                                        <?php if ($user_row['id'] != $user['id']): ?>
                                            <button onclick="confirmDeleteUser('<?php echo $user_row['id']; ?>', '<?php echo htmlspecialchars($user_row['first_name'] . ' ' . $user_row['last_name']); ?>')" class="btn btn-sm btn-danger ml-1">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php elseif ($tab === 'financial'): ?>
                <div class="tab-content">
                    <h3>Financial Year Management</h3>
                    <p>Current Financial Year: 
                        <?php 
                        $current_fy = getCurrentFinancialYear();
                        echo htmlspecialchars($current_fy['year_name']) . " (" . formatDate($current_fy['start_date']) . " - " . formatDate($current_fy['end_date']) . ")";
                        ?></p>
                    
                    <div class="glass-card">
                        <h4>Add New Financial Year</h4>
                        
                        <?php if (!$fy_status['can_create']): ?>
                            <div class="alert alert-info">
                                <strong>Note:</strong> <?php echo $fy_status['reason']; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_financial_year">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" 
                                           name="start_date" 
                                           id="start_date" 
                                           class="form-control" 
                                           value="<?php echo $fy_status['can_create'] ? $fy_status['next_fy']['start_date'] : ''; ?>"
                                           readonly
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" 
                                           name="end_date" 
                                           id="end_date" 
                                           class="form-control" 
                                           value="<?php echo $fy_status['can_create'] ? $fy_status['next_fy']['end_date'] : ''; ?>"
                                           readonly
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="calculated_days">Financial Year Details</label>
                                    <input type="text" 
                                           id="calculated_days" 
                                           class="form-control" 
                                           readonly 
                                           value="<?php 
                                               if ($fy_status['can_create']) {
                                                   $start = new DateTime($fy_status['next_fy']['start_date']);
                                                   $end = new DateTime($fy_status['next_fy']['end_date']);
                                                   $interval = $start->diff($end);
                                                   $days = $interval->days + 1; // +1 to include both start and end dates
                                                   echo $fy_status['next_fy']['year_name'] . " (" . $days . " days)";
                                               } else {
                                                   echo 'Not available';
                                               }
                                           ?>"
                                           placeholder="Will be calculated automatically">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" <?php echo !$fy_status['can_create'] ? 'disabled' : ''; ?>>
                                    <?php echo $fy_status['can_create'] ? 'Add New Financial Year' : 'Cannot Add Financial Year'; ?>
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="location.reload()">Refresh Status</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Existing Financial Years -->
                    <div class="table-container">
                        <h3>Existing Financial Years</h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Year Name</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Total Days</th>
                                    <th>Status</th>
                                    <th>Current Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($financial_years)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No financial years found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($financial_years as $fy): ?>
                                    <tr>
                                        <td><?php echo $fy['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($fy['year_name']); ?></strong></td>
                                        <td><?php echo formatDate($fy['start_date']); ?></td>
                                        <td><?php echo formatDate($fy['end_date']); ?></td>
                                        <td><?php echo $fy['total_days']; ?> days</td>
                                        <td>
                                            <span class="badge <?php echo $fy['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $fy['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $today = date('Y-m-d');
                                            if ($today < $fy['start_date']) {
                                                echo '<span class="badge badge-info">Future</span>';
                                            } elseif ($today >= $fy['start_date'] && $today <= $fy['end_date']) {
                                                echo '<span class="badge badge-success">Current</span>';
                                            } else {
                                                echo '<span class="badge badge-secondary">Past</span>';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo formatDate($fy['created_at']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- User Management Modals -->
    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New User</h3>
                <span class="close" onclick="hideAddUserModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_user">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="">Select Role</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="hr_manager">HR Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="employee_id">Employee ID</label>
                        <input type="text" class="form-control" id="employee_id" name="employee_id">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Create User</button>
                    <button type="button" class="btn btn-secondary" onclick="hideAddUserModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit User</h3>
                <span class="close" onclick="hideEditUserModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" id="edit_user_id" name="id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_first_name">First Name</label>
                        <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_last_name">Last Name</label>
                        <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_password">New Password</label>
                        <input type="password" class="form-control" id="edit_password" name="password" placeholder="Leave blank to keep current password">
                        <small class="form-text text-muted">Leave blank to keep current password</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_role">Role</label>
                        <select class="form-control" id="edit_role" name="role" required>
                            <option value="">Select Role</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="hr_manager">HR Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_phone">Phone</label>
                        <input type="text" class="form-control" id="edit_phone" name="phone">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_employee_id">Employee ID</label>
                        <input type="text" class="form-control" id="edit_employee_id" name="employee_id" readonly>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_address">Address</label>
                    <textarea class="form-control" id="edit_address" name="address" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="hideEditUserModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // User modal functions
        function showAddUserModal() {
            document.getElementById('addUserModal').style.display = 'block';
        }
        
        function hideAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }
        
        function showEditUserModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_phone').value = user.phone || '';
            document.getElementById('edit_address').value = user.address || '';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_employee_id').value = user.employee_id || '';
            document.getElementById('editUserModal').style.display = 'block';
        }
        
        function hideEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }
        
        function confirmDeleteUser(id, name) {
            if (confirm('Are you sure you want to delete user "' + name + '"?\n\nThis action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addUserModal', 'editUserModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>