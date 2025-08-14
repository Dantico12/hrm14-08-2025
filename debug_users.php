<?php
require_once 'config.php';

echo "<h2>Database Users Debug</h2>";

$mysqli = getConnection();
$result = $mysqli->query("SELECT id, email, password, role, first_name, last_name FROM users");

if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th style='padding: 10px;'>ID</th>";
    echo "<th style='padding: 10px;'>Email</th>";
    echo "<th style='padding: 10px;'>Name</th>";
    echo "<th style='padding: 10px;'>Role</th>";
    echo "<th style='padding: 10px;'>Password Status</th>";
    echo "<th style='padding: 10px;'>Password Value</th>";
    echo "</tr>";
    
    while ($user = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='padding: 10px;'>" . $user['id'] . "</td>";
        echo "<td style='padding: 10px;'>" . $user['email'] . "</td>";
        echo "<td style='padding: 10px;'>" . $user['first_name'] . ' ' . $user['last_name'] . "</td>";
        echo "<td style='padding: 10px;'>" . $user['role'] . "</td>";
        echo "<td style='padding: 10px;'>" . (empty($user['password']) ? 'EMPTY' : 'SET') . "</td>";
        echo "<td style='padding: 10px; font-family: monospace; font-size: 12px;'>" . 
             (empty($user['password']) ? 'NULL/EMPTY' : htmlspecialchars($user['password'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No users found in database.</p>";
}

echo "<h3>Quick Password Hash Generator</h3>";
echo "<p>Use this to generate hashed passwords for your database:</p>";

if (isset($_POST['plain_password'])) {
    $plain = $_POST['plain_password'];
    $hashed = password_hash($plain, PASSWORD_DEFAULT);
    echo "<div style='background: #e8f5e8; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<strong>Plain Password:</strong> " . htmlspecialchars($plain) . "<br>";
    echo "<strong>Hashed Password:</strong> " . $hashed . "<br>";
    echo "<strong>SQL Update Example:</strong><br>";
    echo "<code>UPDATE users SET password = '" . $hashed . "' WHERE email = 'user@example.com';</code>";
    echo "</div>";
}

echo "<form method='post' style='margin: 20px 0;'>";
echo "<input type='text' name='plain_password' placeholder='Enter plain password' style='padding: 8px; margin-right: 10px;'>";
echo "<button type='submit' style='padding: 8px 15px;'>Generate Hash</button>";
echo "</form>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { width: 100%; }
th { background: #f0f0f0; }
td, th { text-align: left; }
</style>