
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

// Check if user has permission to access this page
if (!hasPermission('hr_manager')) {
    header("Location: leave_management.php");
    exit();
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input ?? '')));
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
}

// Initialize variables
$success = '';
$error = '';
$holidays = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_holiday' && hasPermission('hr_manager')) {
        $name = sanitizeInput($_POST['name']);
        $date = $_POST['date'];
        $description = sanitizeInput($_POST['description']);
        $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;

        try {
            $stmt = $conn->prepare("INSERT INTO holidays (name, date, description, is_recurring) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $name, $date, $description, $isRecurring);

            if ($stmt->execute()) {
                $success = "Holiday added successfully!";
            } else {
                $error = "Error adding holiday.";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle GET actions
if (isset($_GET['action']) && $_GET['action'] === 'delete_holiday' && isset($_GET['id']) && hasPermission('hr_manager')) {
    $holidayId = (int)$_GET['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->bind_param("i", $holidayId);
        
        if ($stmt->execute()) {
            $success = "Holiday deleted successfully!";
        } else {
            $error = "Error deleting holiday.";
        }
    } catch (Exception $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch holidays
try {
    $holidaysResult = $conn->query("SELECT * FROM holidays ORDER BY date DESC");
    $holidays = $holidaysResult->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $error = "Error fetching holidays: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holidays - HR Management System</title>
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
                <h1>Manage Holidays</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary btn-sm">Logout</a>
                </div>
            </div>

            <div class="content">
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="leave-tabs">
                    <a href="leave_management.php" class="leave-tab">Apply Leave</a>
                    <?php if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'manager', 'managing_director','super_admin'])): ?>
                    <a href="manage.php" class="leave-tab">Manage Leave</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing director'])): ?>
                    <a href="history.php" class="leave-tab">Leave History</a>
                    <a href="holidays.php" class="leave-tab active">Holidays</a>
                    <?php endif; ?>
                    <a href="profile.php" class="leave-tab">My Leave Profile</a>
                </div>

                <!-- Holidays Management Content -->
                <div class="tab-content">
                    <h3>Manage Holidays</h3>
                    <form method="POST" action="" class="mb-4">
                        <input type="hidden" name="action" value="add_holiday">
                        <h4>Add New Holiday</h4>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Holiday Name</label>
                                <input type="text" id="name" name="name" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="date">Date</label>
                                <input type="date" id="date" name="date" class="form-control" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control"></textarea>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_recurring"> This is a recurring holiday
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary">Add Holiday</button>
                    </form>

                    <div class="table-container">
                        <h4>Current Holidays</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Recurring</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($holidays)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No holidays found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($holidays as $holiday): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($holiday['name']); ?></td>
                                        <td><?php echo formatDate($holiday['date']); ?></td>
                                        <td><?php echo htmlspecialchars($holiday['description'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge <?php echo $holiday['is_recurring'] ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $holiday['is_recurring'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="holidays.php?action=delete_holiday&id=<?php echo $holiday['id']; ?>" 
                                               class="btn btn-danger btn-sm" 
                                               onclick="return confirm('Are you sure you want to delete this holiday?')">Delete</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
