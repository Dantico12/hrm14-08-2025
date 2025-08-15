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

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id'],
    'employee_id' => $_SESSION['employee_id'] ?? null
];

// Permission check function
function hasPermission($requiredRole) {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    
    // Permission hierarchy
    $roles = [
        'super_admin' => 4,
        'hr_manager' => 3,
        'dept_head' => 2,
        'section_head' => 1,
        'employee' => 0
    ];
    
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
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

$conn = getConnection();

// Get current user's employee record
$userEmployeeQuery = "SELECT e.* FROM employees e 
                     LEFT JOIN users u ON u.employee_id = e.employee_id 
                     WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$currentEmployee = $stmt->get_result()->fetch_assoc();

if (!$currentEmployee) {
    $_SESSION['flash_message'] = 'Employee record not found.';
    $_SESSION['flash_type'] = 'danger';
    header("Location: dashboard.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_comment') {
        $appraisal_id = $_POST['appraisal_id'];
        $employee_comment = trim($_POST['employee_comment']);
        
        if (!empty($employee_comment)) {
            // Update appraisal with employee comment
            $updateStmt = $conn->prepare("
                UPDATE employee_appraisals 
                SET employee_comment = ?, employee_comment_date = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND employee_id = ?
            ");
            $updateStmt->bind_param("sii", $employee_comment, $appraisal_id, $currentEmployee['id']);
            $updateStmt->execute();
            
            $_SESSION['flash_message'] = 'Your comment has been added successfully.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Please enter a comment.';
            $_SESSION['flash_type'] = 'warning';
        }
        
        header("Location: employee_appraisal.php");
        exit();
    }
}

// Get employee's appraisals
$appraisalsStmt = $conn->prepare("
    SELECT ea.*, ac.name as cycle_name, ac.start_date, ac.end_date,
           e_appraiser.first_name as appraiser_first_name, e_appraiser.last_name as appraiser_last_name
    FROM employee_appraisals ea
    JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id
    JOIN employees e_appraiser ON ea.appraiser_id = e_appraiser.id
    WHERE ea.employee_id = ?
    ORDER BY ac.start_date DESC, ea.created_at DESC
");
$appraisalsStmt->bind_param("i", $currentEmployee['id']);
$appraisalsStmt->execute();
$appraisals = $appraisalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get performance indicators
$indicatorsStmt = $conn->prepare("SELECT * FROM performance_indicators WHERE is_active = 1 ORDER BY weight DESC, name");
$indicatorsStmt->execute();
$indicators = $indicatorsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get scores for all appraisals
$scores_by_appraisal = [];
if (!empty($appraisals)) {
    $appraisal_ids = array_column($appraisals, 'id');
    $placeholders = str_repeat('?,', count($appraisal_ids) - 1) . '?';
    
    $scoresQuery = "
        SELECT as_.*
        FROM appraisal_scores as_
        WHERE as_.employee_appraisal_id IN ($placeholders)
    ";
    
    $scoresStmt = $conn->prepare($scoresQuery);
    $types = str_repeat('i', count($appraisal_ids));
    $scoresStmt->bind_param($types, ...$appraisal_ids);
    $scoresStmt->execute();
    $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Group scores by appraisal ID and indicator ID
    foreach ($scores as $score) {
        $scores_by_appraisal[$score['employee_appraisal_id']][$score['performance_indicator_id']] = $score;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Performance Appraisals - HR Management System</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .appraisal-card {
            background: var(--bg-glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .appraisal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .cycle-info h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }
        
        .cycle-details {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .appraisal-status {
            text-align: right;
        }
        
        .indicators-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 1.5rem;
        }
        
        .indicators-table th,
        .indicators-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .indicators-table th {
            background: var(--bg-glass);
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .indicators-table td {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .score-display {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .comment-section {
            background: rgba(255, 255, 255, 0.03);
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-top: 1.5rem;
        }
        
        .comment-textarea {
            width: 100%;
            min-height: 120px;
            padding: 1rem;
            background: var(--bg-glass);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            resize: vertical;
            font-family: inherit;
            line-height: 1.6;
        }
        
        .readonly-comment {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            line-height: 1.6;
        }
        
        .weight-badge {
            background: rgba(0, 212, 255, 0.2);
            color: var(--primary-color);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .total-score {
            background: var(--bg-glass);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .total-score h5 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .total-score .score {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
                    <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')||hasPermission('dept_head')||hasPermission('officer')): ?>
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
                <h1>My Performance Appraisals</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type']; ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>

                 <div class="leave-tabs">                    
                    <a href="employee_appraisal.php" class="leave-tab active">Employee Appraisal</a>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director', 'section_head', 'dept_head'])): ?>
                    <a href="performance_appraisal.php" class="leave-tab">Performance Appraisal</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing director'])): ?>
                    <a href="appraisal_management.php" class="leave-tab">Appraisal Management</a>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($appraisals)): ?>
                    <!-- Employee Appraisals -->
                    <?php foreach ($appraisals as $appraisal): 
                        $employee_scores = $scores_by_appraisal[$appraisal['id']] ?? [];
                        $has_scores = !empty($employee_scores);
                        
                        // Calculate total score
                        $total_score = 0;
                        $total_weight = 0;
                        foreach ($indicators as $indicator) {
                            if (isset($employee_scores[$indicator['id']])) {
                                $score = $employee_scores[$indicator['id']]['score'];
                                $weight = $indicator['weight'];
                                $total_score += ($score * $weight / 100);
                                $total_weight += $weight;
                            }
                        }
                        $weighted_average = $total_weight > 0 ? ($total_score / $total_weight * 100) : 0;
                    ?>
                   
                        <div class="appraisal-card">
                            <div class="appraisal-header">
                                <div class="cycle-info">
                                    <h4><?php echo htmlspecialchars($appraisal['cycle_name']); ?></h4>
                                    <div class="cycle-details">
                                        <strong>Period:</strong> <?php echo date('M d, Y', strtotime($appraisal['start_date'])); ?> - <?php echo date('M d, Y', strtotime($appraisal['end_date'])); ?><br>
                                        <strong>Appraiser:</strong> <?php echo htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']); ?>
                                    </div>
                                </div>
                                <div class="appraisal-status">
                                    <?php if ($appraisal['submitted_at']): ?>
                                        <span class="badge badge-success">Completed</span>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">
                                            Submitted on <?php echo date('M d, Y', strtotime($appraisal['submitted_at'])); ?>
                                        </div>
                                    <?php elseif ($appraisal['status'] === 'awaiting_employee'): ?>
                                        <span class="badge badge-warning">Action Required</span>
                                        <div style="font-size: 0.75rem; color: var(--warning-color); margin-top: 0.5rem;">
                                            Please add your comment
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">In Progress</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($has_scores): ?>
                                <!-- Total Score Display -->
                                <div class="total-score">
                                    <h5>Overall Score</h5>
                                    <div class="score"><?php echo number_format($score, 1); ?>%</div>
                                </div>

                                <!-- Performance Indicators Table -->
                                <table class="indicators-table">
                                    <thead>
                                        <tr>
                                            <th>Performance Indicator</th>
                                            <th>Weight</th>
                                            <th>Score</th>
                                            <th>Appraiser Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($indicators as $indicator): 
                                            $score_data = $employee_scores[$indicator['id']] ?? null;
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($indicator['name']); ?></strong>
                                                    <?php if ($indicator['description']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($indicator['description']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="weight-badge"><?php echo $indicator['weight']; ?>%</span>
                                                </td>
                                                <td>
                                                    <?php if ($score_data): ?>
                                                        <span class="score-display"><?php echo htmlspecialchars($score_data['score']); ?>/<?php echo $indicator['max_score']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not scored</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($score_data && $score_data['appraiser_comment']): ?>
                                                        <?php echo nl2br(htmlspecialchars($score_data['appraiser_comment'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">No comment</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    This appraisal is still in progress. Your appraiser has not yet completed the scoring.
                                </div>
                            <?php endif; ?>

                            <!-- Employee Comment Section -->
                            <div class="comment-section">
                                <h5>Your Comment</h5>
                                <?php if ($appraisal['employee_comment']): ?>
                                    <div class="readonly-comment">
                                        <?php echo nl2br(htmlspecialchars($appraisal['employee_comment'])); ?>
                                    </div>
                                    <small class="text-muted">
                                        Added on <?php echo date('M d, Y H:i', strtotime($appraisal['employee_comment_date'])); ?>
                                    </small>
                                <?php elseif ($appraisal['status'] === 'awaiting_employee' && $has_scores): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="add_comment">
                                        <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>">
                                        
                                        <div class="form-group">
                                            <label for="employee_comment">Please provide your feedback on this appraisal:</label>
                                            <textarea name="employee_comment" 
                                                      id="employee_comment" 
                                                      class="comment-textarea" 
                                                      placeholder="Share your thoughts on the appraisal, any achievements you'd like to highlight, areas for development, or goals for the next period..."
                                                      required></textarea>
                                        </div>
                                        
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary">Submit Comment</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        You will be able to add your comment once your appraiser completes the scoring.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                    <div class="alert alert-info">
                        No performance appraisals found. Your supervisor will create appraisals during review periods.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
