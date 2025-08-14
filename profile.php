
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

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
    'id' => $_SESSION['user_id']
];

// Permission checking function
function hasPermission($required_role) {
    global $user;
    $role_hierarchy = [
        'managing_director' => 6,
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];

    $user_level = $role_hierarchy[$user['role']] ?? 0;
    $required_level = $role_hierarchy[$required_role] ?? 0;

    return $user_level >= $required_level;
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'approved': return 'badge-success';
        case 'rejected': return 'badge-danger';
        case 'pending': return 'badge-warning';
        case 'pending_section_head': return 'badge-info';
        case 'pending_dept_head': return 'badge-primary';
        case 'pending_hr': return 'badge-warning';
        default: return 'badge-secondary';
    }
}

function getStatusDisplayName($status) {
    switch ($status) {
        case 'approved': return 'Approved';
        case 'rejected': return 'Rejected';
        case 'pending': return 'Pending';
        case 'pending_section_head': return 'Pending Section Head Approval';
        case 'pending_dept_head': return 'Pending Department Head Approval';
        case 'pending_hr': return 'Pending HR Approval';
        default: return ucfirst($status);
    }
}

// Get user's employee record
$userEmployeeQuery = "SELECT e.* FROM employees e 
                      LEFT JOIN users u ON u.employee_id = e.employee_id 
                      WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$userEmployee = $stmt->get_result()->fetch_assoc();

// Initialize variables
$employee = null;
$leaveBalances = [];
$leaveHistory = [];

// Get profile data with enhanced balance information
if ($userEmployee) {
    $employee = $userEmployee;

    // Get leave balances for current user with leave type details - only latest financial year
    // First get the latest financial_year_id
    $latestYearQuery = "SELECT MAX(financial_year_id) as latest_year FROM employee_leave_balances";
    $latestYearResult = $conn->query($latestYearQuery);
    $latestYear = $latestYearResult->fetch_assoc()['latest_year'];

    $stmt = $conn->prepare("SELECT elb.*, lt.name as leave_type_name, lt.max_days_per_year, lt.counts_weekends,
                           lt.deducted_from_annual
                           FROM employee_leave_balances elb
                           JOIN leave_types lt ON elb.leave_type_id = lt.id
                           WHERE elb.employee_id = ? 
                           AND elb.financial_year_id = ?
                           AND lt.is_active = 1
                           ORDER BY lt.name");
    $stmt->bind_param("ii", $employee['id'], $latestYear);
    $stmt->execute();
    $leaveBalances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get comprehensive leave history with deduction details
    $historyQuery = "SELECT la.*, lt.name as leave_type_name,
                     la.primary_days, la.annual_days, la.unpaid_days
                     FROM leave_applications la
                     JOIN leave_types lt ON la.leave_type_id = lt.id
                     WHERE la.employee_id = ?
                     ORDER BY la.applied_at DESC";
    $stmt = $conn->prepare($historyQuery);
    $stmt->bind_param("i", $employee['id']);
    $stmt->execute();
    $historyResult = $stmt->get_result();
    $leaveHistory = $historyResult->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leave Profile - HR Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-brand">
                <h1>HR System</h1>
                <p>Management Portal</p>
            </div>
            <div class="nav">
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="employees.php">Employees</a></li>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="departments.php">Departments</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('super_admin')|| hasPermission('hr_manager')): ?>
                    <li><a href="admin.php">Admin</a></li>
                    <?php endif; ?>
                    <li><a href="leave_management.php">Leave Management</a></li>
                    <?php if (hasPermission('hr_manager')): ?>
                    <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>My Leave Profile</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>

            <div class="content">
                <div class="leave-tabs">
                    <a href="leave_management.php" class="leave-tab">Apply Leave</a>
                    <?php if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'manager', 'managing_director','super_admin'])): ?>
                    <a href="manage.php" class="leave-tab">Manage Leave</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing director'])): ?>
                    <a href="history.php" class="leave-tab">Leave History</a>
                    <a href="holidays.php" class="leave-tab">Holidays</a>
                    <?php endif; ?>
                    <a href="profile.php" class="leave-tab active">My Leave Profile</a>
                </div>

                <!-- Enhanced My Leave Profile Tab -->
                <div class="tab-content">
                    <h3>My Leave Profile</h3>

                    <?php if ($employee): ?>
                    <!-- Employee Information -->
                    <div class="employee-info mb-4">
                        <div class="form-grid">
                            <div>
                                <h4>Employee Information</h4>
                                <p><strong>Employee ID:</strong> <?php echo htmlspecialchars($employee['employee_id']); ?></p>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></p>
                                <p><strong>Employment Type:</strong> <?php echo htmlspecialchars($employee['employment_type']); ?></p>
                                <p><strong>Department:</strong> <?php echo htmlspecialchars($employee['department_id'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Leave Balance Display -->
                    <div class="leave-balance-section mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Leave Balances</h4>
                            <?php if (isset($latestYear)): ?>
                            <span class="badge badge-info">Financial Year ID: <?php echo $latestYear; ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($leaveBalances)): ?>
                            <div class="alert alert-info">No leave balances found for the current financial year.</div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($leaveBalances as $balance): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card h-100">
                                        <div class="card-header bg-primary text-white">
                                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($balance['leave_type_name']); ?></h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="progress mb-3" style="height: 20px;">
                                                <?php 
                                                $percentage = ($balance['used_days'] / $balance['allocated_days']) * 100;
                                                $progressClass = $percentage > 80 ? 'bg-danger' : ($percentage > 50 ? 'bg-warning' : 'bg-success');
                                                ?>
                                                <div class="progress-bar <?php echo $progressClass; ?>" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $percentage; ?>%" 
                                                     aria-valuenow="<?php echo $percentage; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                    <?php echo round($percentage, 1); ?>%
                                                </div>
                                            </div>

                                            <div class="balance-details">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Allocated:</span>
                                                    <strong><?php echo $balance['allocated_days']; ?> days</strong>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Used:</span>
                                                    <strong><?php echo $balance['used_days']; ?> days</strong>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Remaining:</span>
                                                    <strong class="<?php echo $balance['remaining_days'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo $balance['remaining_days']; ?> days
                                                    </strong>
                                                </div>
                                                <?php if ($balance['total_days']): ?>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Total Entitlement:</span>
                                                    <strong><?php echo $balance['total_days']; ?> days</strong>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-light">
                                            <small class="text-muted">
                                                <?php if ($balance['counts_weekends'] == 0): ?>
                                                <i class="fas fa-calendar-week"></i> Excludes weekends
                                                <?php else: ?>
                                                <i class="fas fa-calendar-alt"></i> Includes weekends
                                                <?php endif; ?>

                                                <?php if ($balance['deducted_from_annual']): ?>
                                                <span class="ml-2"><i class="fas fa-exchange-alt"></i> Falls back to Annual Leave</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Enhanced Leave History -->
                    <div class="table-container">
                        <h4>My Leave History</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days</th>
                                    <th>Deduction Breakdown</th>
                                    <th>Applied Date</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($leaveHistory)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No leave applications found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leaveHistory as $leave): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                                        <td><?php echo formatDate($leave['start_date']); ?></td>
                                        <td><?php echo formatDate($leave['end_date']); ?></td>
                                        <td><?php echo $leave['days_requested']; ?></td>
                                        <td>
                                            <?php if (isset($leave['primary_days'], $leave['annual_days'], $leave['unpaid_days'])): ?>
                                            <small>
                                                <?php if ($leave['primary_days'] > 0): ?>
                                                Primary: <?php echo $leave['primary_days']; ?><br>
                                                <?php endif; ?>
                                                <?php if ($leave['annual_days'] > 0): ?>
                                                Annual: <?php echo $leave['annual_days']; ?><br>
                                                <?php endif; ?>
                                                <?php if ($leave['unpaid_days'] > 0): ?>
                                                <span style="color: #dc3545;">Unpaid: <?php echo $leave['unpaid_days']; ?></span>
                                                <?php endif; ?>
                                            </small>
                                            <?php else: ?>
                                            <small class="text-muted">Not specified</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo formatDate($leave['applied_at']); ?></td>
                                        <td>
                                            <span class="badge <?php echo getStatusBadgeClass($leave['status']); ?>">
                                                <?php echo getStatusDisplayName($leave['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($leave['reason'], 0, 50) . (strlen($leave['reason']) > 50 ? '...' : '')); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Quick Actions -->
                    <div class="action-buttons mt-4">
                        <a href="leave_management.php" class="btn btn-primary">Apply for New Leave</a>
                    </div>

                    <?php else: ?>
                    <div class="alert alert-warning">
                        Employee record not found. Please contact HR to resolve this issue.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
