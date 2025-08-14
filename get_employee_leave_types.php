<?php
require_once 'config.php';
$conn = getConnection();

header('Content-Type: application/json');

if (!isset($_GET['employee_id'])) {
    echo json_encode([]);
    exit;
}

$employeeId = (int)$_GET['employee_id'];

// Get latest financial year
$latestYearQuery = "SELECT MAX(financial_year_id) as latest_year FROM employee_leave_balances";
$latestYearResult = $conn->query($latestYearQuery);
$latestYear = $latestYearResult->fetch_assoc()['latest_year'] ?? date('Y');

$stmt = $conn->prepare("SELECT elb.*, lt.name as leave_type_name, lt.max_days_per_year, 
                       lt.counts_weekends, lt.deducted_from_annual
                       FROM employee_leave_balances elb
                       JOIN leave_types lt ON elb.leave_type_id = lt.id
                       WHERE elb.employee_id = ? 
                       AND elb.financial_year_id = ?
                       AND lt.is_active = 1
                       ORDER BY lt.name");
$stmt->bind_param("ii", $employeeId, $latestYear);
$stmt->execute();
$result = $stmt->get_result();

echo json_encode($result->fetch_all(MYSQLI_ASSOC));