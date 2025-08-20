<?php
// get_employee_leave_types.php

// Start session if needed (though might not be necessary here)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Require connection and functions
require_once 'config.php';
$conn = getConnection();

// Sanitize input
$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

if ($employeeId <= 0) {
    echo json_encode([]);
    exit();
}

// Get latest financial year
$latestYearQuery = "SELECT MAX(financial_year_id) as latest_year FROM employee_leave_balances";
$latestYearResult = $conn->query($latestYearQuery);
$latestYear = $latestYearResult->fetch_assoc()['latest_year'] ?? date('Y');

// Fetch allocated leave types for this employee
$stmt = $conn->prepare("SELECT elb.*, lt.name as leave_type_name, lt.max_days_per_year, lt.counts_weekends, lt.deducted_from_annual,
                       elb.remaining_days
                       FROM employee_leave_balances elb
                       JOIN leave_types lt ON elb.leave_type_id = lt.id
                       WHERE elb.employee_id = ? 
                       AND elb.financial_year_id = ?
                       AND lt.is_active = 1
                       ORDER BY lt.name");
$stmt->bind_param("ii", $employeeId, $latestYear);
$stmt->execute();
$employeeLeaveTypes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Find annual leave balance (assuming ID 1 is Annual Leave)
$annualLeaveBalance = 0;
$annualType = null;
foreach ($employeeLeaveTypes as $type) {
    if ($type['leave_type_id'] == 1) { // Annual Leave ID
        $annualLeaveBalance = (int)$type['remaining_days'];
        $annualType = $type;
        break;
    }
}

// Check if Short Leave (ID 6) is already in the list
$hasShortLeave = false;
foreach ($employeeLeaveTypes as $type) {
    if ($type['leave_type_id'] == 6) { // Short Leave ID
        $hasShortLeave = true;
        break;
    }
}

// Add Short Leave if not present and annual balance > 0
if (!$hasShortLeave && $annualLeaveBalance > 0) {
    $employeeLeaveTypes[] = [
        'leave_type_id' => 6,
        'leave_type_name' => 'Short Leave',
        'remaining_days' => $annualLeaveBalance,
        'max_days_per_year' => null, // Or set to appropriate value if needed
        'counts_weekends' => 0,
        'deducted_from_annual' => 1
    ];
}

// Return JSON
header('Content-Type: application/json');
echo json_encode($employeeLeaveTypes);
exit();