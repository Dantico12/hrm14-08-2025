<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Permission checking function
function hasPermission($requiredRole) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $roleHierarchy = [
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];
    
    $userLevel = $roleHierarchy[$_SESSION['user_role']] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

// Check if user is logged in and has HR/Admin privileges
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['hr_manager', 'super_admin', 'dept_head'])) {
    header("Location: login.php");
    exit();
}

require_once 'config.php';
$conn = getConnection();

// Get current user
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_cycle':
                $name = trim($_POST['cycle_name']);
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $status = 'active';
                
                if (!empty($name) && $start_date && $end_date && $start_date < $end_date) {
                    $stmt = $conn->prepare("INSERT INTO appraisal_cycles (name, start_date, end_date, status) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $name, $start_date, $end_date, $status);
                    
                    if ($stmt->execute()) {
                        $_SESSION['flash_message'] = 'Appraisal cycle created successfully!';
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Error creating appraisal cycle: ' . $conn->error;
                        $_SESSION['flash_type'] = 'danger';
                    }
                } else {
                    $_SESSION['flash_message'] = 'Invalid cycle dates or empty name';
                    $_SESSION['flash_type'] = 'warning';
                }
                break;
                
            case 'create_indicator':
                $name = trim($_POST['indicator_name']);
                $description = trim($_POST['description']);
                $weight = (float)$_POST['weight'];
                $max_score = (int)$_POST['max_score'];
                $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
                $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
                $role = !empty($_POST['role']) ? $_POST['role'] : null;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (!empty($name) && $weight > 0 && $max_score > 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO performance_indicators 
                        (name, description, weight, max_score, section_id, department_id, role, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssdiissi", $name, $description, $weight, $max_score, $section_id, $department_id, $role, $is_active);
                    
                    if ($stmt->execute()) {
                        $_SESSION['flash_message'] = 'Performance indicator created successfully!';
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Error creating indicator: ' . $conn->error;
                        $_SESSION['flash_type'] = 'danger';
                    }
                } else {
                    $_SESSION['flash_message'] = 'Please fill all required fields with valid values';
                    $_SESSION['flash_type'] = 'warning';
                }
                break;
                
            case 'update_indicator_status':
                $id = (int)$_POST['id'];
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt = $conn->prepare("
                    UPDATE performance_indicators 
                    SET is_active = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->bind_param("ii", $is_active, $id);
                
                if ($stmt->execute()) {
                    $_SESSION['flash_message'] = 'Indicator status updated!';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Error updating indicator: ' . $conn->error;
                    $_SESSION['flash_type'] = 'danger';
                }
                break;
                
            case 'update_cycle_status':
                $id = (int)$_POST['id'];
                $status = in_array($_POST['status'], ['active', 'inactive', 'completed']) ? $_POST['status'] : 'inactive';
                
                $stmt = $conn->prepare("
                    UPDATE appraisal_cycles 
                    SET status = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->bind_param("si", $status, $id);
                
                if ($stmt->execute()) {
                    $_SESSION['flash_message'] = 'Cycle status updated!';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Error updating cycle: ' . $conn->error;
                    $_SESSION['flash_type'] = 'danger';
                }
                break;
                
            case 'delete_indicator':
                $id = (int)$_POST['id'];
                
                $stmt = $conn->prepare("DELETE FROM performance_indicators WHERE id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $_SESSION['flash_message'] = 'Indicator deleted successfully!';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = 'Error deleting indicator: ' . $conn->error;
                    $_SESSION['flash_type'] = 'danger';
                }
                break;
                
            case 'update_indicator':
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $weight = (float)$_POST['weight'];
                $max_score = (int)$_POST['max_score'];
                $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
                $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
                $role = !empty($_POST['role']) ? $_POST['role'] : null;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                if (!empty($name) && $weight > 0 && $max_score > 0) {
                    $stmt = $conn->prepare("
                        UPDATE performance_indicators 
                        SET name = ?, description = ?, weight = ?, max_score = ?, 
                            section_id = ?, department_id = ?, role = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssdiissii", $name, $description, $weight, $max_score, $section_id, $department_id, $role, $is_active, $id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['flash_message'] = 'Performance indicator updated successfully!';
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Error updating indicator: ' . $conn->error;
                        $_SESSION['flash_type'] = 'danger';
                    }
                } else {
                    $_SESSION['flash_message'] = 'Please fill all required fields with valid values';
                    $_SESSION['flash_type'] = 'warning';
                }
                break;
        }
        
        header("Location: appraisal_management.php");
        exit();
    }
}

// Get all appraisal cycles with status counts
$cycles = $conn->query("
    SELECT ac.*, 
           COUNT(DISTINCT ea.id) as appraisal_count,
           SUM(CASE WHEN ea.status = 'submitted' THEN 1 ELSE 0 END) as submitted_count
    FROM appraisal_cycles ac
    LEFT JOIN employee_appraisals ea ON ac.id = ea.appraisal_cycle_id
    GROUP BY ac.id
    ORDER BY ac.start_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Get all performance indicators with section and department info
$indicators = $conn->query("
    SELECT pi.*, 
           s.name as section_name,
           d.name as department_name
    FROM performance_indicators pi
    LEFT JOIN sections s ON pi.section_id = s.id
    LEFT JOIN departments d ON pi.department_id = d.id
    ORDER BY pi.is_active DESC, pi.weight DESC, pi.name
")->fetch_all(MYSQLI_ASSOC);

// Get unique roles from employees
$roles = $conn->query("
    SELECT DISTINCT employee_type 
    FROM employees 
    WHERE employee_status = 'active'
    ORDER BY employee_type
")->fetch_all(MYSQLI_ASSOC);
$roles = array_column($roles, 'employee_type');

// Get sections with department info for dropdown
$sections = $conn->query("
    SELECT s.*, d.name as department_name, d.id as department_id 
    FROM sections s
    LEFT JOIN departments d ON s.department_id = d.id
    ORDER BY d.name, s.name
")->fetch_all(MYSQLI_ASSOC);

// Get departments for dropdown
$departments = $conn->query("
    SELECT id, name
    FROM departments
    ORDER BY name
")->fetch_all(MYSQLI_ASSOC);

// Prepare sections data for JavaScript
$sectionsByDepartment = [];
foreach ($sections as $section) {
    $deptId = $section['department_id'] ?? 0;
    if (!isset($sectionsByDepartment[$deptId])) {
        $sectionsByDepartment[$deptId] = [];
    }
    $sectionsByDepartment[$deptId][] = $section;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appraisal Management - HR System</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .management-section {
            background: var(--bg-glass);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .table-responsive {
            overflow-x: auto;
            margin-top: 1.5rem;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th, .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }
        
        .data-table th {
            background: var(--bg-glass-dark);
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .badge {
            display: inline-block;
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }
        
        .badge-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-warning {
            background-color: var(--warning-color);
            color: #212529;
        }
        
        .badge-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .badge-info {
            background-color: var(--info-color);
            color: white;
        }
        
        .badge-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .status-select {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background: var(--bg-glass);
            color: var(--text-primary);
        }
        
        .progress-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .progress-bar {
            flex-grow: 1;
            height: 8px;
            background: var(--bg-glass-dark);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary-color);
        }
        
        .progress-text {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        .optgroup-label {
            font-weight: bold;
            font-style: italic;
            padding: 0.5rem 0;
            background: rgba(0,0,0,0.05);
        }
        
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }
        
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
            border: none;
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
            border: none;
        }
        
        .btn-secondary:hover, .btn-danger:hover {
            opacity: 0.8;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--success-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: var(--bg-glass);
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            width: 60%;
            max-width: 800px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .close {
            color: var(--text-secondary);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
        }

        .indicator-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .detail-row {
            margin-bottom: 10px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .detail-value {
            padding: 8px;
            background: var(--bg-glass-dark);
            border-radius: 4px;
        }

        .edit-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-full-width {
            grid-column: span 2;
        }

        /* Tab styles */
        .tab-nav {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .tab-nav a {
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            color: var(--text-secondary);
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-nav a.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
        }

        .tab-nav a:hover {
            color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group.hidden {
            display: none;
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
                    <?php if (hasPermission('super_admin')): ?>
                    <li><a href="admin.php?tab=users">Admin</a></li>
                    <?php elseif (hasPermission('hr_manager')): ?>
                    <li><a href="admin.php?tab=financial">Admin</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager') || hasPermission('super_admin') || hasPermission('dept_head') || hasPermission('officer')): ?>
                    <li><a href="leave_management.php">Leave Management</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('section_head')): ?>
                    <li><a href="employee_appraisal.php" class="active">Performance Appraisal</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <button class="sidebar-toggle">☰</button>
                <h1>Appraisal Management</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['flash_type']; ?>">
                        <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                    </div>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                <?php endif; ?>

                <div class="leave-tabs">
                    <a href="employee_appraisal.php" class="leave-tab">Employee Appraisal</a>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director', 'section_head', 'dept_head'])): ?>
                    <a href="performance_appraisal.php" class="leave-tab">Performance Appraisal</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director', 'section_head'])): ?>
                    <a href="appraisal_management.php" class="leave-tab active">Appraisal Management</a>
                    <?php endif; ?>
                    <a href="completed_appraisals.php" class="leave-tab">Completed Appraisals</a>
                </div>

                <!-- Tab Navigation -->
                <div class="tab-nav">
                    <a href="#cycles" class="tab-link active" onclick="showTab('cycles')">Appraisal Cycles</a>
                    <a href="#indicators" class="tab-link" onclick="showTab('indicators')">Performance Indicators</a>
                </div>

                <!-- Cycles Tab Content -->
                <div id="cycles" class="tab-content active">
                    <div class="management-section">
                        <h2>Create New Appraisal Cycle</h2>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_cycle">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="cycle_name">Cycle Name</label>
                                    <input type="text" id="cycle_name" name="cycle_name" class="form-control" required placeholder="Q1 2025/2026 Performance Review">
                                </div>
                                
                                <div class="form-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" id="end_date" name="end_date" class="form-control" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Create Cycle</button>
                        </form>
                    </div>
                    
                    <div class="management-section">
                        <h2>Appraisal Cycles</h2>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cycles as $cycle): 
                                        $progress = $cycle['appraisal_count'] > 0 ? 
                                            round(($cycle['submitted_count'] / $cycle['appraisal_count']) * 100) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cycle['name']); ?></td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($cycle['start_date'])); ?> - 
                                                <?php echo date('M j, Y', strtotime($cycle['end_date'])); ?>
                                            </td>
                                            <td>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="update_cycle_status">
                                                    <input type="hidden" name="id" value="<?php echo $cycle['id']; ?>">
                                                    <select name="status" class="status-select" onchange="this.form.submit()">
                                                        <option value="active" <?php echo $cycle['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="inactive" <?php echo $cycle['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                        <option value="completed" <?php echo $cycle['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="progress-container">
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                                    </div>
                                                    <span class="progress-text">
                                                        <?php echo $cycle['submitted_count']; ?>/<?php echo $cycle['appraisal_count']; ?> submitted
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <button onclick="showCycleDetails(<?php echo $cycle['id']; ?>)" class="btn btn-sm btn-secondary">View</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Indicators Tab Content -->
                <div id="indicators" class="tab-content">
                    <div class="management-section">
                        <h2>Create New Performance Indicator</h2>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_indicator">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="indicator_name">Indicator Name</label>
                                    <input type="text" id="indicator_name" name="indicator_name" class="form-control" required placeholder="Team Collaboration">
                                </div>
                                
                                <div class="form-group">
                                    <label for="description">Description</label>
                                    <textarea id="description" name="description" class="form-control" rows="2" placeholder="Measures how well the employee works with team members"></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="weight">Weight (%)</label>
                                    <input type="number" id="weight" name="weight" class="form-control" min="1" max="100" step="0.1" required value="20">
                                </div>
                                
                                <div class="form-group">
                                    <label for="role">Role (Optional)</label>
                                    <select id="role" name="role" class="form-control">
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo htmlspecialchars($role); ?>">
                                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $role))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group" id="department-group">
                                    <label for="department_id">Department (Optional)</label>
                                    <select id="department_id" name="department_id" class="form-control">
                                        <option value="">-- Select Department --</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>">
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group" id="section-group">
                                    <label for="section_id">Section (Optional)</label>
                                    <select id="section_id" name="section_id" class="form-control">
                                        <option value="">-- Select Section --</option>
                                        <?php foreach ($sections as $section): ?>
                                            <option value="<?php echo $section['id']; ?>" data-department-id="<?php echo $section['department_id']; ?>">
                                                <?php echo htmlspecialchars($section['name'] . ' (' . $section['department_name'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="max_score">Max Score</label>
                                    <input type="number" id="max_score" name="max_score" class="form-control" min="1" required value="5">
                                </div>
                                
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="is_active" checked> Active
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Create Indicator</button>
                        </form>
                    </div>
                    
                    <div class="management-section">
                        <h2>Performance Indicators</h2>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Weight</th>
                                        <th>Role</th>
                                        <th>Department</th>
                                        <th>Section</th>
                                        <th>Max</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($indicators as $indicator): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($indicator['name']); ?></td>
                                            <td><?php echo htmlspecialchars($indicator['description']); ?></td>
                                            <td><?php echo $indicator['weight']; ?>%</td>
                                            <td><?php echo $indicator['role'] ? htmlspecialchars(ucwords(str_replace('_', ' ', $indicator['role']))) : 'N/A'; ?></td>
                                            <td><?php echo $indicator['department_name'] ? htmlspecialchars($indicator['department_name']) : 'N/A'; ?></td>
                                            <td><?php echo $indicator['section_name'] ? htmlspecialchars($indicator['section_name']) : 'N/A'; ?></td>
                                            <td><?php echo $indicator['max_score']; ?></td>
                                            <td>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="action" value="update_indicator_status">
                                                    <input type="hidden" name="id" value="<?php echo $indicator['id']; ?>">
                                                    <label class="switch">
                                                        <input type="checkbox" name="is_active" <?php echo $indicator['is_active'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                                        <span class="slider round"></span>
                                                    </label>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <button onclick="showIndicatorDetails(<?php echo $indicator['id']; ?>)" class="btn btn-sm btn-secondary">View</button>
                                                    <button onclick="showEditIndicator(<?php echo $indicator['id']; ?>)" class="btn btn-sm btn-secondary">Edit</button>
                                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this indicator?');">
                                                        <input type="hidden" name="action" value="delete_indicator">
                                                        <input type="hidden" name="id" value="<?php echo $indicator['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Indicator Modal -->
    <div id="indicatorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Indicator Details</h2>
                <span class="close" onclick="closeModal('indicatorModal')">&times;</span>
            </div>
            <div class="modal-body" id="indicatorModalBody">
                <div class="indicator-details">
                    <div class="detail-row">
                        <div class="detail-label">Name</div>
                        <div class="detail-value" id="indicator-name"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Description</div>
                        <div class="detail-value" id="indicator-description"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Weight</div>
                        <div class="detail-value" id="indicator-weight"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Role</div>
                        <div class="detail-value" id="indicator-role"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Department</div>
                        <div class="detail-value" id="indicator-department"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Section</div>
                        <div class="detail-value" id="indicator-section"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Max Score</div>
                        <div class="detail-value" id="indicator-max-score"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status</div>
                        <div class="detail-value" id="indicator-status"></div>
                    </div>
                    <div class="detail-row form-full-width">
                        <div class="detail-label">Created At</div>
                        <div class="detail-value" id="indicator-created"></div>
                    </div>
                    <div class="detail-row form-full-width">
                        <div class="detail-label">Last Updated</div>
                        <div class="detail-value" id="indicator-updated"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('indicatorModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Indicator Modal -->
    <div id="editIndicatorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit Indicator</h2>
                <span class="close" onclick="closeModal('editIndicatorModal')">&times;</span>
            </div>
            <form id="editIndicatorForm" method="POST" action="">
                <input type="hidden" name="action" value="update_indicator">
                <input type="hidden" name="id" id="edit-indicator-id">
                <div class="modal-body">
                    <div class="edit-form">
                        <div class="form-group">
                            <label for="edit-name">Indicator Name</label>
                            <input type="text" id="edit-name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit-description">Description</label>
                            <textarea id="edit-description" name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="edit-weight">Weight (%)</label>
                            <input type="number" id="edit-weight" name="weight" class="form-control" min="1" max="100" step="0.1" required>
                        </div>
                        <div class="form-group" id="edit-department-group">
                            <label for="edit-role">Role (Optional)</label>
                            <select id="edit-role" name="role" class="form-control">
                                <option value="">-- Select Role --</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $role))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="edit-department-group">
                            <label for="edit-department-id">Department (Optional)</label>
                            <select id="edit-department-id" name="department_id" class="form-control">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="edit-section-group">
                            <label for="edit-section-id">Section (Optional)</label>
                            <select id="edit-section-id" name="section_id" class="form-control">
                                <option value="">-- Select Section --</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['id']; ?>" data-department-id="<?php echo $section['department_id']; ?>">
                                        <?php echo htmlspecialchars($section['name'] . ' (' . $section['department_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit-max-score">Max Score</label>
                            <input type="number" id="edit-max-score" name="max_score" class="form-control" min="1" required>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="edit-is-active" name="is_active"> Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editIndicatorModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Cycle Modal -->
    <div id="cycleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Cycle Details</h2>
                <span class="close" onclick="closeModal('cycleModal')">&times;</span>
            </div>
            <div class="modal-body" id="cycleModalBody">
                <div class="indicator-details">
                    <div class="detail-row">
                        <div class="detail-label">Name</div>
                        <div class="detail-value" id="cycle-name"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Start Date</div>
                        <div class="detail-value" id="cycle-start-date"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">End Date</div>
                        <div class="detail-value" id="cycle-end-date"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status</div>
                        <div class="detail-value" id="cycle-status"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Total Appraisals</div>
                        <div class="detail-value" id="cycle-appraisal-count"></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Submitted Appraisals</div>
                        <div class="detail-value" id="cycle-submitted-count"></div>
                    </div>
                    <div class="detail-row form-full-width">
                        <div class="detail-label">Progress</div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" id="cycle-progress-bar"></div>
                            </div>
                            <span class="progress-text" id="cycle-progress-text"></span>
                        </div>
                    </div>
                    <div class="detail-row form-full-width">
                        <div class="detail-label">Created At</div>
                        <div class="detail-value" id="cycle-created"></div>
                    </div>
                    <div class="detail-row form-full-width">
                        <div class="detail-label">Last Updated</div>
                        <div class="detail-value" id="cycle-updated"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('cycleModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Initialize date inputs with reasonable defaults
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, 1);
            const endOfNextMonth = new Date(today.getFullYear(), today.getMonth() + 2, 0);
            
            document.getElementById('start_date').valueAsDate = nextMonth;
            document.getElementById('end_date').valueAsDate = endOfNextMonth;

            // Initialize tabs
            showTab('cycles');

            // Initialize role-based visibility
            updateFieldVisibility('role', 'department-group', 'section-group');
            updateFieldVisibility('edit-role', 'edit-department-group', 'edit-section-group');

            // Initialize section filtering
            updateSectionOptions('department_id', 'section_id');
            updateSectionOptions('edit-department-id', 'edit-section-id');
        });

        // Tab switching function
        function showTab(tabId) {
            // Remove active class from all tabs and content
            document.querySelectorAll('.tab-link').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            // Add active class to clicked tab and corresponding content
            document.querySelector(`a[href="#${tabId}"]`).classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        // View Indicator Details
        function showIndicatorDetails(indicatorId) {
            const indicators = <?php echo json_encode($indicators); ?>;
            const indicator = indicators.find(i => i.id == indicatorId);
            
            if (indicator) {
                document.getElementById('indicator-name').textContent = indicator.name;
                document.getElementById('indicator-description').textContent = indicator.description || 'N/A';
                document.getElementById('indicator-weight').textContent = indicator.weight + '%';
                document.getElementById('indicator-role').textContent = indicator.role ? indicator.role.replace(/_/g, ' ') : 'N/A';
                document.getElementById('indicator-department').textContent = indicator.department_name || 'N/A';
                document.getElementById('indicator-section').textContent = indicator.section_name || 'N/A';
                document.getElementById('indicator-max-score').textContent = indicator.max_score;
                document.getElementById('indicator-status').textContent = 
                    indicator.is_active ? 'Active' : 'Inactive';
                document.getElementById('indicator-created').textContent = 
                    new Date(indicator.created_at).toLocaleString();
                document.getElementById('indicator-updated').textContent = 
                    indicator.updated_at ? new Date(indicator.updated_at).toLocaleString() : 'N/A';
                
                showModal('indicatorModal');
            } else {
                alert('Indicator not found');
            }
        }

        // Edit Indicator
        function showEditIndicator(indicatorId) {
            const indicators = <?php echo json_encode($indicators); ?>;
            const indicator = indicators.find(i => i.id == indicatorId);
            
            if (indicator) {
                document.getElementById('edit-indicator-id').value = indicator.id;
                document.getElementById('edit-name').value = indicator.name;
                document.getElementById('edit-description').value = indicator.description || '';
                document.getElementById('edit-weight').value = indicator.weight;
                document.getElementById('edit-role').value = indicator.role || '';
                document.getElementById('edit-department-id').value = indicator.department_id || '';
                document.getElementById('edit-section-id').value = indicator.section_id || '';
                document.getElementById('edit-max-score').value = indicator.max_score;
                document.getElementById('edit-is-active').checked = indicator.is_active == 1;
                
                updateSectionOptions('edit-department-id', 'edit-section-id', indicator.section_id);
                updateFieldVisibility('edit-role', 'edit-department-group', 'edit-section-group');
                
                showModal('editIndicatorModal');
            } else {
                alert('Indicator not found');
            }
        }

        // View Cycle Details
        function showCycleDetails(cycleId) {
            const cycles = <?php echo json_encode($cycles); ?>;
            const cycle = cycles.find(c => c.id == cycleId);
            
            if (cycle) {
                const progress = cycle.appraisal_count > 0 ? 
                    Math.round((cycle.submitted_count / cycle.appraisal_count) * 100) : 0;
                
                document.getElementById('cycle-name').textContent = cycle.name;
                document.getElementById('cycle-start-date').textContent = 
                    new Date(cycle.start_date).toLocaleDateString();
                document.getElementById('cycle-end-date').textContent = 
                    new Date(cycle.end_date).toLocaleDateString();
                document.getElementById('cycle-status').textContent = 
                    cycle.status.charAt(0).toUpperCase() + cycle.status.slice(1);
                document.getElementById('cycle-appraisal-count').textContent = 
                    cycle.appraisal_count || '0';
                document.getElementById('cycle-submitted-count').textContent = 
                    cycle.submitted_count || '0';
                document.getElementById('cycle-progress-bar').style.width = progress + '%';
                document.getElementById('cycle-progress-text').textContent = 
                    `${cycle.submitted_count || '0'}/${cycle.appraisal_count || '0'} submitted (${progress}%)`;
                document.getElementById('cycle-created').textContent = 
                    new Date(cycle.created_at).toLocaleString();
                document.getElementById('cycle-updated').textContent = 
                    cycle.updated_at ? new Date(cycle.updated_at).toLocaleString() : 'N/A';
                
                showModal('cycleModal');
            } else {
                alert('Cycle not found');
            }
        }

        // Update field visibility based on role selection
        function updateFieldVisibility(roleId, departmentGroupId, sectionGroupId) {
            const roleSelect = document.getElementById(roleId);
            const departmentGroup = document.getElementById(departmentGroupId);
            const sectionGroup = document.getElementById(sectionGroupId);
            
            roleSelect.addEventListener('change', function() {
                const role = this.value;
                if (role === 'managing_director') {
                    departmentGroup.classList.add('hidden');
                    sectionGroup.classList.add('hidden');
                    document.getElementById(departmentGroupId.replace('group', 'id')).value = '';
                    document.getElementById(sectionGroupId.replace('group', 'id')).value = '';
                } else if (role === 'dept_head') {
                    departmentGroup.classList.remove('hidden');
                    sectionGroup.classList.add('hidden');
                    document.getElementById(sectionGroupId.replace('group', 'id')).value = '';
                } else {
                    departmentGroup.classList.remove('hidden');
                    sectionGroup.classList.remove('hidden');
                }
                updateSectionOptions(departmentGroupId.replace('group', 'id'), sectionGroupId.replace('group', 'id'));
            });
        }

        // Update section options based on selected department
        function updateSectionOptions(departmentId, sectionId, selectedSectionId = null) {
            const departmentSelect = document.getElementById(departmentId);
            const sectionSelect = document.getElementById(sectionId);
            const sectionsByDepartment = <?php echo json_encode($sectionsByDepartment); ?>;
            
            departmentSelect.addEventListener('change', function() {
                const deptId = this.value;
                sectionSelect.innerHTML = '<option value="">-- Select Section --</option>';
                
                if (deptId && sectionsByDepartment[deptId]) {
                    sectionsByDepartment[deptId].forEach(section => {
                        const option = document.createElement('option');
                        option.value = section.id;
                        option.textContent = `${section.name} (${section.department_name})`;
                        option.setAttribute('data-department-id', section.department_id);
                        if (selectedSectionId && section.id == selectedSectionId) {
                            option.selected = true;
                        }
                        sectionSelect.appendChild(option);
                    });
                }
            });
            
            // Trigger change event to initialize section options
            if (departmentSelect.value) {
                const event = new Event('change');
                departmentSelect.dispatchEvent(event);
            }
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>