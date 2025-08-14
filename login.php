<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once 'config.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

/**
 * Authenticate user credentials
 */
function authenticateUser($email, $password) {
    $mysqli = getConnection();
    
    // Prepare statement to prevent SQL injection
    $stmt = $mysqli->prepare("SELECT id, email, password, role, first_name, last_name FROM users WHERE email = ?");
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user) {
        // Verify password (supports both hashed and plain text for migration)
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            // If password is plain text, hash it for future use
            if ($password === $user['password']) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->bind_param("ss", $hashedPassword, $user['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            return $user;
        }
    }
    
    return false;
}

/**
 * Set session variables for logged in user
 */
function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['login_time'] = time();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($password)) {
        $error = 'Password is required.';
    } else {
        // Attempt authentication
        $user = authenticateUser($email, $password);
        
        if ($user) {
            setUserSession($user);
            
            // Redirect to dashboard or intended page
            $redirect = $_GET['redirect'] ?? 'dashboard.php';
            header("Location: " . $redirect);
            exit();
        } else {
            $error = 'Invalid email or password. Please try again.';
            
        }}}

// Get any flash messages
$flash = '';
if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    $flash_type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - HR Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h2>MUWASCO HR Management System</h2>
            <p class="text-center" style="color: #666; margin-bottom: 30px;">Please sign in to your account</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash_type); ?>">
                    <?php echo htmlspecialchars($flash); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" 
                           class="form-control" 
                           id="email" 
                           name="email" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                           required
                           autocomplete="email"
                           placeholder="Enter your email address">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           required
                           autocomplete="current-password"
                           placeholder="Enter your password">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    Sign In
                </button>
            </form>
            
            <div class="text-center" style="margin-top: 20px;">
                <p style="color: #666; font-size: 14px;">
                    Forgot your password? Contact your system administrator.
                </p>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus on email field
        document.getElementById('email').focus();
        
        // Clear any error messages after 5 seconds
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>