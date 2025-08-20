<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config.php';
$conn = getConnection();

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id'],
    'employee_id' => $_SESSION['employee_id'] ?? null
];

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

// Get current user's employee record
$userEmployeeQuery = "SELECT e.* FROM employees e 
                     LEFT JOIN users u ON u.employee_id = e.employee_id 
                     WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$currentEmployee = $stmt->get_result()->fetch_assoc();

// Export functions
function exportToPDF($appraisal, $scores, $totalScore) {
    // Generate the HTML content
    $html = generateAppraisalHTML($appraisal, $scores, $totalScore);
    
    // Return the HTML to be processed by JavaScript
    return $html;
}

function exportToWord($appraisal, $scores, $totalScore) {
    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start new output buffer
    ob_start();
    
    // Set proper headers for Word document
    header("Content-Type: application/vnd.ms-word; charset=utf-8");
    header("Content-Disposition: attachment; filename=appraisal_" . $appraisal['emp_id'] . "_" . date('Y-m-d') . ".doc");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo generateAppraisalHTML($appraisal, $scores, $totalScore);
    
    // Flush and clean buffer
    ob_end_flush();
    exit();
}

// Improved HTML generation function
function generateAppraisalHTML($appraisal, $scores, $totalScore) {
    // Ensure all variables are properly escaped
    $employee_name = htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']);
    $employee_id = htmlspecialchars($appraisal['emp_id']);
    $cycle_name = htmlspecialchars($appraisal['cycle_name']);
    $department = htmlspecialchars($appraisal['department_name'] ?? 'N/A');
    $section = htmlspecialchars($appraisal['section_name'] ?? 'N/A');
    $appraiser_name = htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']);
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <title>Performance Appraisal Report</title>
    <meta charset="utf-8">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.4; 
            color: #333;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .employee-info { 
            margin-bottom: 20px; 
        }
        .info-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 10px 0; 
        }
        .info-table th, .info-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        .info-table th { 
            background-color: #f5f5f5; 
            font-weight: bold; 
            width: 30%;
        }
        .scores-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0; 
        }
        .scores-table th, .scores-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        .scores-table th { 
            background-color: #f5f5f5; 
            font-weight: bold; 
        }
        .total-score { 
            background-color: #e8f5e9; 
            font-weight: bold; 
        }
        .comments-section { 
            margin-top: 20px; 
            border: 1px solid #ddd; 
            padding: 15px; 
            background: #f9f9f9; 
        }
        @media print {
            body { margin: 0; }
            .header h1 { color: #333; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Performance Appraisal Report</h1>
        <h3>' . $cycle_name . '</h3>
    </div>
    
    <div class="employee-info">
        <h3>Employee Information</h3>
        <table class="info-table">
            <tr>
                <th>Employee Name</th>
                <td>' . $employee_name . '</td>
            </tr>
            <tr>
                <th>Employee ID</th>
                <td>' . $employee_id . '</td>
            </tr>
            <tr>
                <th>Department</th>
                <td>' . $department . '</td>
            </tr>
            <tr>
                <th>Section</th>
                <td>' . $section . '</td>
            </tr>
            <tr>
                <th>Appraisal Period</th>
                <td>' . date('M d, Y', strtotime($appraisal['start_date'])) . ' - ' . date('M d, Y', strtotime($appraisal['end_date'])) . '</td>
            </tr>
            <tr>
                <th>Appraiser</th>
                <td>' . $appraiser_name . '</td>
            </tr>
            <tr>
                <th>Submitted Date</th>
                <td>' . date('M d, Y H:i', strtotime($appraisal['submitted_at'])) . '</td>
            </tr>
        </table>
    </div>
    
    <h3>Performance Scores</h3>
    <table class="scores-table">
        <thead>
            <tr>
                <th>Performance Indicator</th>
                <th>Weight (%)</th>
                <th>Score</th>
                <th>Max Score</th>
                <th>Percentage</th>
                <th>Comments</th>
            </tr>
        </thead>
        <tbody>';

    // Add scores
    if (!empty($scores)) {
        foreach ($scores as $score) {
            $percentage = ($score['score'] / $score['max_score']) * 100;
            $indicator_name = htmlspecialchars($score['indicator_name'] ?? 'Performance Indicator');
            $comment = htmlspecialchars($score['appraiser_comment'] ?? '');
            
            $html .= '
            <tr>
                <td>' . $indicator_name . '</td>
                <td>' . intval($score['weight']) . '%</td>
                <td>' . intval($score['score']) . '</td>
                <td>' . intval($score['max_score']) . '</td>
                <td>' . number_format($percentage, 1) . '%</td>
                <td>' . $comment . '</td>
            </tr>';
        }
    }
    
    $html .= '
            <tr class="total-score">
                <td colspan="4"><strong>Overall Score</strong></td>
                <td><strong>' . number_format($totalScore, 1) . '%</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>';

    // Add employee comments if available
    if (!empty($appraisal['employee_comment'])) {
        $employee_comment = nl2br(htmlspecialchars($appraisal['employee_comment']));
        $comment_date = date('M d, Y H:i', strtotime($appraisal['employee_comment_date']));
        
        $html .= '
        <div class="comments-section">
            <h3>Employee Comments</h3>
            <p>' . $employee_comment . '</p>
            <p><small>Commented on: ' . $comment_date . '</small></p>
        </div>';
    }

    $html .= '
    <div style="margin-top: 50px; font-size: 12px; color: #666; border-top: 1px solid #ddd; padding-top: 20px;">
        <p>Generated on: ' . date('M d, Y H:i:s') . '</p>
        <p>HR Management System - Performance Appraisal Report</p>
    </div>
</body>
</html>';

    return $html;
}

// Handle export requests
if (isset($_POST['export']) && isset($_POST['appraisal_id'])) {
    $appraisal_id = intval($_POST['appraisal_id']);
    $export_type = $_POST['export_type'];
    
    // Validate inputs
    if ($appraisal_id <= 0 || !in_array($export_type, ['pdf', 'word'])) {
        die('Invalid export parameters');
    }
    
    // Get detailed appraisal data for export
    $exportQuery = "
        SELECT 
            ea.*,
            ac.name as cycle_name,
            ac.start_date,
            ac.end_date,
            e.first_name,
            e.last_name,
            e.employee_id as emp_id,
            d.name as department_name,
            s.name as section_name,
            e_appraiser.first_name as appraiser_first_name,
            e_appraiser.last_name as appraiser_last_name
        FROM employee_appraisals ea
        JOIN employees e ON ea.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN sections s ON e.section_id = s.id
        JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id
        JOIN employees e_appraiser ON ea.appraiser_id = e_appraiser.id
        WHERE ea.id = ? AND ea.status = 'submitted'
    ";
    
    // Check if user has access to this appraisal
    if (!hasPermission('hr_manager')) {
        $exportQuery .= " AND ea.employee_id = ?";
        $exportStmt = $conn->prepare($exportQuery);
        if (!$exportStmt) {
            die('Database error: ' . $conn->error);
        }
        $exportStmt->bind_param("ii", $appraisal_id, $currentEmployee['id']);
    } else {
        $exportStmt = $conn->prepare($exportQuery);
        if (!$exportStmt) {
            die('Database error: ' . $conn->error);
        }
        $exportStmt->bind_param("i", $appraisal_id);
    }
    
    if (!$exportStmt->execute()) {
        die('Query execution failed: ' . $exportStmt->error);
    }
    
    $appraisalData = $exportStmt->get_result()->fetch_assoc();
    
    if (!$appraisalData) {
        die('Appraisal not found or access denied');
    }
    
    // Get scores for this appraisal
    $scoresQuery = "
        SELECT 
            as_.*,
            pi.name as indicator_name,
            pi.description as indicator_description,
            pi.weight,
            pi.max_score
        FROM appraisal_scores as_
        JOIN performance_indicators pi ON as_.performance_indicator_id = pi.id
        WHERE as_.employee_appraisal_id = ?
        ORDER BY pi.weight DESC, pi.name
    ";
    
    $scoresStmt = $conn->prepare($scoresQuery);
    if (!$scoresStmt) {
        die('Database error: ' . $conn->error);
    }
    
    $scoresStmt->bind_param("i", $appraisal_id);
    if (!$scoresStmt->execute()) {
        die('Query execution failed: ' . $scoresStmt->error);
    }
    
    $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate total score
    $total_score = 0;
    $total_weight = 0;
    foreach ($scores as $score) {
        if ($score['max_score'] > 0) {
            $weighted_score = ($score['score'] / $score['max_score']) * $score['weight'];
            $total_score += $weighted_score;
            $total_weight += $score['weight'];
        }
    }
    $final_percentage = $total_weight > 0 ? ($total_score / $total_weight) * 100 : 0;
    
    // Handle different export types
    switch ($export_type) {
        case 'pdf':
            $htmlContent = exportToPDF($appraisalData, $scores, $final_percentage);
            // Return the HTML to be processed by JavaScript
            echo $htmlContent;
            exit();
            break;
        case 'word':
            exportToWord($appraisalData, $scores, $final_percentage);
            break;
        default:
            die('Invalid export type');
    }
}

// Get appraisal cycles for filtering
$cyclesStmt = $conn->prepare("SELECT * FROM appraisal_cycles ORDER BY start_date DESC");
$cyclesStmt->execute();
$cycles = $cyclesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get employees for HR filtering
$employees = [];
if (hasPermission('hr_manager')) {
    $employeesStmt = $conn->prepare("SELECT id, first_name, last_name, employee_id FROM employees ORDER BY first_name, last_name");
    $employeesStmt->execute();
    $employees = $employeesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Filter parameters
$selected_cycle = $_GET['cycle_id'] ?? '';
$selected_employee = $_GET['employee_id'] ?? '';

// Build query based on user permissions and filters
$appraisalsQuery = "
    SELECT 
        ea.*,
        ac.name as cycle_name,
        ac.start_date,
        ac.end_date,
        e.first_name,
        e.last_name,
        e.employee_id as emp_id,
        d.name as department_name,
        s.name as section_name,
        e_appraiser.first_name as appraiser_first_name,
        e_appraiser.last_name as appraiser_last_name
    FROM employee_appraisals ea
    JOIN employees e ON ea.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN sections s ON e.section_id = s.id
    JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id
    JOIN employees e_appraiser ON ea.appraiser_id = e_appraiser.id
    WHERE ea.status = 'submitted'
";

$queryParams = [];
$paramTypes = "";

// Add filters
if ($selected_cycle) {
    $appraisalsQuery .= " AND ea.appraisal_cycle_id = ?";
    $queryParams[] = $selected_cycle;
    $paramTypes .= "i";
}

// Employee filter (for HR) or restrict to current user
if (hasPermission('hr_manager')) {
    if ($selected_employee) {
        $appraisalsQuery .= " AND ea.employee_id = ?";
        $queryParams[] = $selected_employee;
        $paramTypes .= "i";
    }
} else {
    // Non-HR users can only see their own appraisals
    $appraisalsQuery .= " AND ea.employee_id = ?";
    $queryParams[] = $currentEmployee['id'];
    $paramTypes .= "i";
}

$appraisalsQuery .= " ORDER BY ea.submitted_at DESC, ac.start_date DESC";

$appraisalsStmt = $conn->prepare($appraisalsQuery);
if (!empty($queryParams)) {
    $appraisalsStmt->bind_param($paramTypes, ...$queryParams);
}
$appraisalsStmt->execute();
$appraisals = $appraisalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get scores for all appraisals
$scores_by_appraisal = [];
if (!empty($appraisals)) {
    $appraisal_ids = array_column($appraisals, 'id');
    $placeholders = str_repeat('?,', count($appraisal_ids) - 1) . '?';
    
    $scoresQuery = "
        SELECT 
            as_.*,
            pi.weight,
            pi.max_score,
            pi.name as indicator_name
        FROM appraisal_scores as_
        JOIN performance_indicators pi ON as_.performance_indicator_id = pi.id
        WHERE as_.employee_appraisal_id IN ($placeholders)
    ";
    
    $scoresStmt = $conn->prepare($scoresQuery);
    $types = str_repeat('i', count($appraisal_ids));
    $scoresStmt->bind_param($types, ...$appraisal_ids);
    $scoresStmt->execute();
    $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($scores as $score) {
        $scores_by_appraisal[$score['employee_appraisal_id']][] = $score;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Appraisals - HR System</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        .filters-section {
            background: var(--bg-glass);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        .appraisals-table {
            background: var(--bg-glass);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .table th {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border: none;
        }
        
        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .score-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .export-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .btn-export {
            padding: 0.375rem 0.75rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-pdf {
            background: #dc3545;
            color: white;
        }
        
        .btn-word {
            background: #0d6efd;
            color: white;
        }
        
        .btn-print {
            background: #28a745;
            color: white;
        }
        
        .btn-export:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }
        
        .employee-info {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .employee-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
            background: var(--bg-glass);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        
        /* Responsive table */
        @media (max-width: 1200px) {
            .table {
                font-size: 0.875rem;
            }
            
            .table th,
            .table td {
                padding: 0.75rem 0.5rem;
            }
            
            .export-buttons {
                flex-direction: column;
                gap: 0.125rem;
            }
        }
        
        @media (max-width: 768px) {
            .appraisals-table {
                overflow-x: auto;
            }
            
            .table {
                min-width: 800px;
            }
        }

        /* Loading states */
        .btn-export:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .export-loading {
            position: relative;
        }

        .export-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="employees.php"><i class="fas fa-users"></i> Employees</a></li>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="departments.php"><i class="fas fa-building"></i> Departments</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('super_admin')): ?>
                    <li><a href="admin.php?tab=users"><i class="fas fa-cog"></i> Admin</a></li>
                    <?php elseif (hasPermission('hr_manager')): ?>
                    <li><a href="admin.php?tab=financial"><i class="fas fa-cog"></i> Admin</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager') || hasPermission('super_admin') || hasPermission('dept_head') || hasPermission('officer')): ?>
                    <li><a href="leave_management.php"><i class="fas fa-calendar-alt"></i> Leave Management</a></li>
                    <?php endif; ?>
                    <li><a href="employee_appraisal.php"><i class="fas fa-chart-line"></i> Performance Appraisal</a></li>
                    <li><a href="payroll.php"><i class="fas fa-money-check"></i> Payroll</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <button class="sidebar-toggle">☰</button>
                <h1>Completed Appraisals</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <!-- Navigation Tabs -->
                <div class="leave-tabs">
                    <a href="employee_appraisal.php" class="leave-tab">Employee Appraisal</a>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director', 'section_head', 'dept_head'])): ?>
                    <a href="performance_appraisal.php" class="leave-tab">Performance Appraisal</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing director', 'section_head'])): ?>
                    <a href="appraisal_management.php" class="leave-tab">Appraisal Management</a>
                    <?php endif; ?>
                    <a href="completed_appraisals.php" class="leave-tab active">Completed Appraisals</a>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <h3>Filter Appraisals</h3>
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <?php if (hasPermission('hr_manager')): ?>
                            <div class="form-group">
                                <label for="employee_id">Employee</label>
                                <select name="employee_id" id="employee_id" class="form-control">
                                    <option value="">All Employees</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo $employee['id']; ?>" <?php echo ($selected_employee == $employee['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_id'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="cycle_id">Appraisal Cycle</label>
                                <select name="cycle_id" id="cycle_id" class="form-control">
                                    <option value="">All Cycles</option>
                                    <?php foreach ($cycles as $cycle): ?>
                                        <option value="<?php echo $cycle['id']; ?>" <?php echo ($selected_cycle == $cycle['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cycle['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="completed_appraisals.php" class="btn btn-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Appraisals Table -->
                <?php if (!empty($appraisals)): ?>
                    <div class="appraisals-table">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Cycle</th>
                                    <th>Period</th>
                                    <th>Score</th>
                                    <th>Appraiser</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appraisals as $appraisal): 
                                    $appraisal_scores = $scores_by_appraisal[$appraisal['id']] ?? [];
                                    
                                    // Calculate total score
                                    $total_score = 0;
                                    $total_weight = 0;
                                    foreach ($appraisal_scores as $score) {
                                        $weighted_score = ($score['score'] / $score['max_score']) * $score['weight'];
                                        $total_score += $weighted_score;
                                        $total_weight += $score['weight'];
                                    }
                                    $final_percentage = $total_weight > 0 ? ($total_score / $total_weight) * 100 : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="employee-info">
                                                <?php echo htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']); ?>
                                            </div>
                                            <div class="employee-details">
                                                ID: <?php echo htmlspecialchars($appraisal['emp_id']); ?><br>
                                                <?php echo htmlspecialchars($appraisal['department_name'] ?? 'N/A'); ?>
                                                <?php if ($appraisal['section_name']): ?>
                                                    - <?php echo htmlspecialchars($appraisal['section_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($appraisal['cycle_name']); ?></strong>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo date('M d, Y', strtotime($appraisal['start_date'])); ?><br>
                                                <small class="text-muted">to</small><br>
                                                <?php echo date('M d, Y', strtotime($appraisal['end_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="score-badge"><?php echo number_format($final_percentage, 1); ?>%</span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($appraisal['submitted_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="export-buttons">
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="pdf">
                                                    <button type="submit" name="export" class="btn-export btn-pdf" title="Export PDF">PDF</button>
                                                </form>
                                                
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="word">
                                                    <button type="submit" name="export" class="btn-export btn-word" title="Export Word">Word</button>
                                                </form>
                                                
                                                <button onclick="printAppraisal(<?php echo $appraisal['id']; ?>)" class="btn-export btn-print" title="Print">Print</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 1rem; text-align: center; color: var(--text-secondary);">
                        <small>Total: <?php echo count($appraisals); ?> completed appraisal(s)</small>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <h3>No Completed Appraisals Found</h3>
                        <p>There are no completed appraisals matching your current filters.</p>
                        <?php if ($selected_cycle || $selected_employee): ?>
                            <a href="completed_appraisals.php" class="btn btn-primary">View All Appraisals</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Handle PDF export with proper PDF generation
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            form.addEventListener('submit', async function(e) {
                const exportType = this.querySelector('input[name="export_type"]').value;
                
                if (exportType === 'pdf') {
                    e.preventDefault();
                    
                    const button = this.querySelector('button[name="export"]');
                    const originalText = button.innerHTML;
                    button.innerHTML = '⏳ Generating PDF...';
                    button.disabled = true;
                    
                    try {
                        const formData = new FormData(this);
                        const response = await fetch('completed_appraisals.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        if (!response.ok) throw new Error('Export failed');
                        
                        const html = await response.text();
                        
                        // Create a temporary element to hold the HTML
                        const element = document.createElement('div');
                        element.innerHTML = html;
                        document.body.appendChild(element);
                        
                        // PDF generation options
                        const opt = {
                            margin: 10,
                            filename: `appraisal_${formData.get('appraisal_id')}_${new Date().toISOString().slice(0,10)}.pdf`,
                            image: { type: 'jpeg', quality: 0.98 },
                            html2canvas: { scale: 2 },
                            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                        };
                        
                        // Generate PDF
                        await html2pdf().set(opt).from(element).save();
                        
                        // Remove temporary element
                        element.remove();
                    } catch (error) {
                        console.error('PDF generation failed:', error);
                        alert('Failed to generate PDF. Please try again.');
                    } finally {
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }
                }
            });
        });

        function printAppraisal(appraisalId) {
            // Find the appraisal data
            const appraisals = <?php echo json_encode($appraisals); ?>;
            const scoresData = <?php echo json_encode($scores_by_appraisal); ?>;
            
            const appraisal = appraisals.find(a => a.id == appraisalId);
            const scores = scoresData[appraisalId] || [];
            
            if (!appraisal) {
                alert('Appraisal not found');
                return;
            }
            
            // Calculate total score
            let totalScore = 0;
            let totalWeight = 0;
            scores.forEach(score => {
                const weightedScore = (score.score / score.max_score) * score.weight;
                totalScore += weightedScore;
                totalWeight += score.weight;
            });
            const finalPercentage = totalWeight > 0 ? (totalScore / totalWeight) * 100 : 0;
            
            // Create a new window for printing
            const printWindow = window.open('', '_blank');
            
            // Generate print content
            let printHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Performance Appraisal Report - ${appraisal.first_name} ${appraisal.last_name}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.4; color: #333; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        .total-row { background-color: #e8f5e9; font-weight: bold; }
                        .comments-section { margin-top: 20px; border: 1px solid #ddd; padding: 15px; background: #f9f9f9; }
                        @media print {
                            body { margin: 0; padding: 0; }
                            .no-print { display: none !important; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Performance Appraisal Report</h1>
                        <h3>${appraisal.cycle_name}</h3>
                    </div>
                    
                    <div class="employee-info">
                        <h3>Employee Information</h3>
                        <table>
                            <tr>
                                <th>Employee Name</th>
                                <td>${appraisal.first_name} ${appraisal.last_name}</td>
                            </tr>
                            <tr>
                                <th>Employee ID</th>
                                <td>${appraisal.emp_id}</td>
                            </tr>
                            <tr>
                                <th>Department</th>
                                <td>${appraisal.department_name || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Section</th>
                                <td>${appraisal.section_name || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Appraisal Period</th>
                                <td>${new Date(appraisal.start_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'})} - ${new Date(appraisal.end_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'})}</td>
                            </tr>
                            <tr>
                                <th>Appraiser</th>
                                <td>${appraisal.appraiser_first_name} ${appraisal.appraiser_last_name}</td>
                            </tr>
                            <tr>
                                <th>Submitted Date</th>
                                <td>${new Date(appraisal.submitted_at).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'})}</td>
                            </tr>
                        </table>
                    </div>
                    
                    <h3>Performance Scores</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Performance Indicator</th>
                                <th>Weight (%)</th>
                                <th>Score</th>
                                <th>Max Score</th>
                                <th>Percentage</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            // Add score rows
            scores.forEach(score => {
                const percentage = (score.score / score.max_score) * 100;
                printHTML += `
                    <tr>
                        <td>${score.indicator_name || 'Performance Indicator'}</td>
                        <td>${score.weight}%</td>
                        <td>${score.score}</td>
                        <td>${score.max_score}</td>
                        <td>${percentage.toFixed(1)}%</td>
                        <td>${score.appraiser_comment || ''}</td>
                    </tr>`;
            });
            
            printHTML += `
                            <tr class="total-row">
                                <td colspan="4"><strong>Overall Score</strong></td>
                                <td><strong>${finalPercentage.toFixed(1)}%</strong></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>`;
            
            // Add employee comments if available
            if (appraisal.employee_comment) {
                printHTML += `
                    <div class="comments-section">
                        <h3>Employee Comments</h3>
                        <p>${appraisal.employee_comment.replace(/\n/g, '<br>')}</p>
                        <p><small>Commented on: ${new Date(appraisal.employee_comment_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'})}</small></p>
                    </div>`;
            }
            
            printHTML += `
                    <div style="margin-top: 50px; font-size: 12px; color: #666; border-top: 1px solid #ddd; padding-top: 20px; text-align: center;">
                        <p>Generated on: ${new Date().toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'})}</p>
                        <p>HR Management System - Performance Appraisal Report</p>
                    </div>
                    
                    <div class="no-print" style="text-align: center; margin-top: 20px;">
                        <button onclick="window.print()" style="padding: 8px 16px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Print Now</button>
                        <button onclick="window.close()" style="padding: 8px 16px; background: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;">Close</button>
                    </div>
                </body>
                </html>`;
            
            // Write the content to the new window
            printWindow.document.open();
            printWindow.document.write(printHTML);
            printWindow.document.close();
            
            // Focus the window (helps with some browsers)
            printWindow.focus();
        }
        
        // Sidebar toggle functionality
        document.querySelector('.sidebar-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
        });
        
        // Responsive table handling
        function handleResponsiveTable() {
            const table = document.querySelector('.table');
            const container = document.querySelector('.appraisals-table');
            
            if (table && container) {
                if (window.innerWidth <= 768) {
                    container.style.overflowX = 'auto';
                } else {
                    container.style.overflowX = 'visible';
                }
            }
        }
        
        window.addEventListener('resize', handleResponsiveTable);
        window.addEventListener('load', handleResponsiveTable);
        
        // Auto-refresh data every 5 minutes to show new completed appraisals
        setInterval(function() {
            const currentUrl = new URL(window.location);
            const searchParams = currentUrl.searchParams;
            
            // Add a timestamp to prevent caching
            searchParams.set('refresh', Date.now());
            
            // Only auto-refresh if we're still on the same page
            if (window.location.pathname.includes('completed_appraisals.php')) {
                window.location.search = searchParams.toString();
            }
        }, 300000); // 5 minutes
    </script>
</body>
</html>