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

// Handle export requests
if (isset($_POST['export']) && isset($_POST['appraisal_id'])) {
    $appraisal_id = $_POST['appraisal_id'];
    $export_type = $_POST['export_type'];
    
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
    }
    
    $exportStmt = $conn->prepare($exportQuery);
    if (!hasPermission('hr_manager')) {
        $exportStmt->bind_param("ii", $appraisal_id, $currentEmployee['id']);
    } else {
        $exportStmt->bind_param("i", $appraisal_id);
    }
    $exportStmt->execute();
    $appraisalData = $exportStmt->get_result()->fetch_assoc();
    
    if ($appraisalData) {
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
        $scoresStmt->bind_param("i", $appraisal_id);
        $scoresStmt->execute();
        $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Calculate total score
        $total_score = 0;
        $total_weight = 0;
        foreach ($scores as $score) {
            $weighted_score = ($score['score'] / $score['max_score']) * $score['weight'];
            $total_score += $weighted_score;
            $total_weight += $score['weight'];
        }
        $final_percentage = $total_weight > 0 ? ($total_score / $total_weight) * 100 : 0;
        
        // Handle different export types
        switch ($export_type) {
            case 'pdf':
                exportToPDF($appraisalData, $scores, $final_percentage);
                break;
            case 'word':
                exportToWord($appraisalData, $scores, $final_percentage);
                break;
            case 'print':
                $print_data = [
                    'appraisal' => $appraisalData,
                    'scores' => $scores,
                    'total_score' => $final_percentage
                ];
                break;
        }
    }
}

// Export functions
function exportToPDF($appraisal, $scores, $totalScore) {
    // Simple HTML to PDF export
    ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="appraisal_' . $appraisal['emp_id'] . '_' . date('Y-m-d') . '.pdf"');
    
    echo generateAppraisalHTML($appraisal, $scores, $totalScore);
    exit();
}

function exportToWord($appraisal, $scores, $totalScore) {
    ob_clean();
    header("Content-Type: application/vnd.ms-word");
    header("Content-Disposition: attachment; filename=appraisal_" . $appraisal['emp_id'] . "_" . date('Y-m-d') . ".doc");
    
    echo generateAppraisalHTML($appraisal, $scores, $totalScore);
    exit();
}

function generateAppraisalHTML($appraisal, $scores, $totalScore) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Performance Appraisal Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .employee-info { margin-bottom: 20px; }
            .scores-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .scores-table th, .scores-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .scores-table th { background-color: #f5f5f5; }
            .total-score { background-color: #e8f5e9; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Performance Appraisal Report</h1>
            <h3>' . htmlspecialchars($appraisal['cycle_name']) . '</h3>
        </div>
        
        <div class="employee-info">
            <h3>Employee Information</h3>
            <p><strong>Name:</strong> ' . htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']) . '</p>
            <p><strong>Employee ID:</strong> ' . htmlspecialchars($appraisal['emp_id']) . '</p>
            <p><strong>Department:</strong> ' . htmlspecialchars($appraisal['department_name'] ?? 'N/A') . '</p>
            <p><strong>Section:</strong> ' . htmlspecialchars($appraisal['section_name'] ?? 'N/A') . '</p>
            <p><strong>Appraisal Period:</strong> ' . date('M d, Y', strtotime($appraisal['start_date'])) . ' - ' . date('M d, Y', strtotime($appraisal['end_date'])) . '</p>
            <p><strong>Appraiser:</strong> ' . htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']) . '</p>
            <p><strong>Submitted Date:</strong> ' . date('M d, Y', strtotime($appraisal['submitted_at'])) . '</p>
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
    
    foreach ($scores as $score) {
        $percentage = ($score['score'] / $score['max_score']) * 100;
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($score['indicator_name']) . '</td>
                    <td>' . $score['weight'] . '%</td>
                    <td>' . $score['score'] . '</td>
                    <td>' . $score['max_score'] . '</td>
                    <td>' . number_format($percentage, 1) . '%</td>
                    <td>' . htmlspecialchars($score['appraiser_comment']) . '</td>
                </tr>';
    }
    
    $html .= '
                <tr class="total-score">
                    <td colspan="4"><strong>Overall Score</strong></td>
                    <td><strong>' . number_format($totalScore, 1) . '%</strong></td>
                    <td></td>
                </tr>
            </tbody>
        </table>';
    
    if ($appraisal['employee_comment']) {
        $html .= '
        <h3>Employee Comments</h3>
        <p>' . nl2br(htmlspecialchars($appraisal['employee_comment'])) . '</p>
        <p><small>Commented on: ' . date('M d, Y H:i', strtotime($appraisal['employee_comment_date'])) . '</small></p>';
    }
    
    $html .= '
        <div style="margin-top: 50px; font-size: 12px; color: #666;">
            <p>Generated on: ' . date('M d, Y H:i:s') . '</p>
        </div>
    </body>
    </html>';
    
    return $html;
}

// Get appraisal cycles for filtering
$cyclesStmt = $conn->prepare("SELECT * FROM appraisal_cycles ORDER BY start_date DESC");
$cyclesStmt->execute();
$cycles = $cyclesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Filter parameters
$selected_cycle = $_GET['cycle_id'] ?? '';
$selected_quarter = $_GET['quarter'] ?? '';

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

// Add quarter filter if specified
if ($selected_quarter) {
    switch ($selected_quarter) {
        case 'Q1':
            $appraisalsQuery .= " AND MONTH(ac.start_date) IN (1,2,3)";
            break;
        case 'Q2':
            $appraisalsQuery .= " AND MONTH(ac.start_date) IN (4,5,6)";
            break;
        case 'Q3':
            $appraisalsQuery .= " AND MONTH(ac.start_date) IN (7,8,9)";
            break;
        case 'Q4':
            $appraisalsQuery .= " AND MONTH(ac.start_date) IN (10,11,12)";
            break;
    }
}

// Restrict access based on user role
if (!hasPermission('hr_manager')) {
    $appraisalsQuery .= " AND ea.employee_id = ?";
    $queryParams[] = $currentEmployee['id'];
    $paramTypes .= "i";
}

$appraisalsQuery .= " ORDER BY ea.submitted_at DESC";

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
            pi.max_score
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
    <link rel="stylesheet" href="style.css">
    <style>
        .appraisal-card {
            background: var(--bg-glass);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
        }
        
        .appraisal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
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
        
        .score-display {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 20px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .export-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-export {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s ease;
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
        
        .employee-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .quarter-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(0, 212, 255, 0.2);
            color: var(--primary-color);
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        /* Print Modal Styles */
        .print-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .print-modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .print-modal-header {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .print-modal-body {
            padding: 2rem;
            color: #333;
        }

        .close-modal {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .print-content {
            font-family: Arial, sans-serif;
        }

        @media print {
            .print-modal-header,
            .no-print {
                display: none !important;
            }
            
            .print-modal-content {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                box-shadow: none !important;
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
                    <li><a href="employee_appraisal.php" class="active">Performance Appraisal</a></li>
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
                    <a href="appraisal_management.php" class="leave-tab active">Appraisal Management</a>
                    <?php endif; ?>
                       <a href="completed_appraisals.php" class="leave-tab active">Completed Appraisals</a>
                </div>
                <!-- Filters Section -->
                <div class="filters-section">
                    <h3>Filter Appraisals</h3>
                    <form method="GET" action="">
                        <div class="filters-grid">
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
                                <label for="quarter">Quarter</label>
                                <select name="quarter" id="quarter" class="form-control">
                                    <option value="">All Quarters</option>
                                    <option value="Q1" <?php echo ($selected_quarter == 'Q1') ? 'selected' : ''; ?>>Q1 (Jan-Mar)</option>
                                    <option value="Q2" <?php echo ($selected_quarter == 'Q2') ? 'selected' : ''; ?>>Q2 (Apr-Jun)</option>
                                    <option value="Q3" <?php echo ($selected_quarter == 'Q3') ? 'selected' : ''; ?>>Q3 (Jul-Sep)</option>
                                    <option value="Q4" <?php echo ($selected_quarter == 'Q4') ? 'selected' : ''; ?>>Q4 (Oct-Dec)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="completed_appraisals.php" class="btn btn-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Appraisals List -->
                <?php if (!empty($appraisals)): ?>
                    <div class="appraisals-list">
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
                            
                            // Determine quarter
                            $quarter = 'Q' . ceil(date('n', strtotime($appraisal['start_date'])) / 3);
                        ?>
                            <div class="appraisal-card">
                                <div class="appraisal-header">
                                    <div class="employee-info">
                                        <h4><?php echo htmlspecialchars($appraisal['first_name'] . ' ' . $appraisal['last_name']); ?></h4>
                                        <div class="employee-details">
                                            <strong>Employee ID:</strong> <?php echo htmlspecialchars($appraisal['emp_id']); ?><br>
                                            <strong>Department:</strong> <?php echo htmlspecialchars($appraisal['department_name'] ?? 'N/A'); ?><br>
                                            <strong>Section:</strong> <?php echo htmlspecialchars($appraisal['section_name'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="appraisal-summary">
                                        <div class="score-display"><?php echo number_format($final_percentage, 1); ?>%</div>
                                        <div style="margin-top: 0.5rem;">
                                            <span class="quarter-badge"><?php echo $quarter; ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="appraisal-details">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 1rem;">
                                        <div>
                                            <h5>Appraisal Information</h5>
                                            <p><strong>Cycle:</strong> <?php echo htmlspecialchars($appraisal['cycle_name']); ?></p>
                                            <p><strong>Period:</strong> <?php echo date('M d, Y', strtotime($appraisal['start_date'])); ?> - <?php echo date('M d, Y', strtotime($appraisal['end_date'])); ?></p>
                                            <p><strong>Appraiser:</strong> <?php echo htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']); ?></p>
                                            <p><strong>Submitted:</strong> <?php echo date('M d, Y', strtotime($appraisal['submitted_at'])); ?></p>
                                        </div>
                                        
                                        <div>
                                            <h5>Export Options</h5>
                                            <div class="export-buttons">
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="pdf">
                                                    <button type="submit" name="export" class="btn-export btn-pdf">📄 PDF</button>
                                                </form>
                                                
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                                    <input type="hidden" name="export_type" value="word">
                                                    <button type="submit" name="export" class="btn-export btn-word">📝 Word</button>
                                                </form>
                                                
                                                <button onclick="showPrintModal(<?php echo $appraisal['id']; ?>)" class="btn-export btn-print">🖨️ Print</button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($appraisal['employee_comment']): ?>
                                        <div style="background: rgba(255, 255, 255, 0.05); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--primary-color);">
                                            <h6>Employee Comment:</h6>
                                            <p><?php echo nl2br(htmlspecialchars($appraisal['employee_comment'])); ?></p>
                                            <small class="text-muted">Commented on <?php echo date('M d, Y H:i', strtotime($appraisal['employee_comment_date'])); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <h3>No Completed Appraisals Found</h3>
                        <p>There are no completed appraisals matching your current filters.</p>
                        <?php if ($selected_cycle || $selected_quarter): ?>
                            <a href="completed_appraisals.php" class="btn btn-primary">View All Appraisals</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Print Modal -->
    <div id="printModal" class="print-modal">
        <div class="print-modal-content">
            <div class="print-modal-header no-print">
                <h3>Print Preview</h3>
                <span class="close-modal" onclick="closePrintModal()">&times;</span>
            </div>
            <div class="print-modal-body">
                <div id="printContent" class="print-content">
                    <!-- Print content will be loaded here -->
                </div>
                <div style="text-align: center; margin-top: 20px;" class="no-print">
                    <button onclick="printAppraisal()" class="btn btn-primary">🖨️ Print</button>
                    <button onclick="closePrintModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showPrintModal(appraisalId) {
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
            
            // Generate print content
            let printHTML = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1>Performance Appraisal Report</h1>
                    <h3>${appraisal.cycle_name}</h3>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>Employee Information</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 30%;"><strong>Name:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${appraisal.first_name} ${appraisal.last_name}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Employee ID:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${appraisal.emp_id}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Department:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${appraisal.department_name || 'N/A'}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Section:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${appraisal.section_name || 'N/A'}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Appraisal Period:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${new Date(appraisal.start_date).toLocaleDateString()} - ${new Date(appraisal.end_date).toLocaleDateString()}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Appraiser:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${appraisal.appraiser_first_name} ${appraisal.appraiser_last_name}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>Submitted Date:</strong></td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${new Date(appraisal.submitted_at).toLocaleDateString()}</td>
                        </tr>
                    </table>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>Performance Scores</h3>
                    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                        <thead>
                            <tr>
                                <th style="border: 1px solid #ddd; padding: 12px; background: #f5f5f5; text-align: left;">Performance Indicator</th>
                                <th style="border: 1px solid #ddd; padding: 12px; background: #f5f5f5; text-align: center;">Weight (%)</th>
                                <th style="border: 1px solid #ddd; padding: 12px; background: #f5f5f5; text-align: center;">Score</th>
                                <th style="border: 1px solid #ddd; padding: 12px; background: #f5f5f5; text-align: center;">Max Score</th>
                                <th style="border: 1px solid #ddd; padding: 12px; background: #f5f5f5; text-align: center;">Percentage</th>
                                <th style="border: 1px solid #ddd; padding: 12px; background: #f5f5f5; text-align: left;">Comments</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            // Add score rows
            scores.forEach(score => {
                const percentage = (score.score / score.max_score) * 100;
                printHTML += `
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px;">${score.indicator_name || 'Performance Indicator'}</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${score.weight}%</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${score.score}</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${score.max_score}</td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${percentage.toFixed(1)}%</td>
                        <td style="border: 1px solid #ddd; padding: 8px;">${score.appraiser_comment || ''}</td>
                    </tr>`;
            });
            
            printHTML += `
                            <tr style="background: #e8f5e9; font-weight: bold;">
                                <td style="border: 1px solid #ddd; padding: 8px;" colspan="4"><strong>Overall Score</strong></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><strong>${finalPercentage.toFixed(1)}%</strong></td>
                                <td style="border: 1px solid #ddd; padding: 8px;"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>`;
            
            // Add employee comments if available
            if (appraisal.employee_comment) {
                printHTML += `
                    <div style="margin-bottom: 20px;">
                        <h3>Employee Comments</h3>
                        <div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                            <p>${appraisal.employee_comment.replace(/\n/g, '<br>')}</p>
                            <p style="font-size: 12px; color: #666; margin-top: 10px;">
                                <strong>Commented on:</strong> ${new Date(appraisal.employee_comment_date).toLocaleString()}
                            </p>
                        </div>
                    </div>`;
            }
            
            printHTML += `
                <div style="margin-top: 50px; font-size: 12px; color: #666;">
                    <p><strong>Generated on:</strong> ${new Date().toLocaleString()}</p>
                </div>`;
            
            document.getElementById('printContent').innerHTML = printHTML;
            document.getElementById('printModal').style.display = 'block';
        }
        
        function closePrintModal() {
            document.getElementById('printModal').style.display = 'none';
        }
        
        function printAppraisal() {
            window.print();
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('printModal');
            if (event.target == modal) {
                closePrintModal();
            }
        }
        
        // Auto-submit form when filter changes
        document.getElementById('cycle_id').addEventListener('change', function() {
            if (this.value !== '') {
                this.form.submit();
            }
        });
        
        document.getElementById('quarter').addEventListener('change', function() {
            if (this.value !== '') {
                this.form.submit();
            }
        });
        
        // Add smooth scrolling for better UX
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>