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

// Initialize $tab with default value BEFORE any output
$tab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'apply';

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

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return false;
}

// Enhanced helper functions for leave management with deduction logic
function calculateBusinessDays($startDate, $endDate, $conn, $includeWeekends = false) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = 0;

    // Get holidays from database
    $holidayQuery = "SELECT date FROM holidays WHERE date BETWEEN ? AND ?";
    $stmt = $conn->prepare($holidayQuery);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $holidays = [];
    while ($row = $result->fetch_assoc()) {
        $holidays[] = $row['date'];
    }

    // Check if leave type counts weekends
    $typeId = $_POST['leave_type_id'] ?? null;
    if ($typeId) {
        $typeStmt = $conn->prepare("SELECT counts_weekends FROM leave_types WHERE id = ?");
        $typeStmt->bind_param("i", $typeId);
        $typeStmt->execute();
        $typeResult = $typeStmt->get_result();
        $type = $typeResult->fetch_assoc();

        $includeWeekends = ($type['counts_weekends'] == 1);
    }

    $current = clone $start;
    while ($current <= $end) {
        $dayOfWeek = $current->format('N'); // 1 = Monday, 7 = Sunday
        $currentDate = $current->format('Y-m-d');

        // Skip weekends if not included
        if (!$includeWeekends && ($dayOfWeek == 6 || $dayOfWeek == 7)) {
            $current->add(new DateInterval('P1D'));
            continue;
        }

        // Skip holidays
        if (!in_array($currentDate, $holidays)) {
            $days++;
        }

        $current->add(new DateInterval('P1D'));
    }

    return $days;
}

function getLeaveTypeDetails($leaveTypeId, $conn) {
    $query = "SELECT * FROM leave_types WHERE id = ? AND is_active = 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $leaveTypeId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
function getLeaveTypeBalance($employeeId, $leaveTypeId, $conn) {
    // First, get leave type details
    $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);
    if (!$leaveType) {
        return [
            'allocated' => 0, 
            'used' => 0, 
            'remaining' => 0,
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => 'Unknown',
            'max_days_per_year' => 0,
            'counts_weekends' => 0,
            'deducted_from_annual' => 0
        ];
    }

    // Initialize default values
    $balance = [
        'allocated' => 0,
        'used' => 0,
        'remaining' => 0,
        'leave_type_id' => $leaveTypeId,
        'leave_type_name' => $leaveType['name'],
        'max_days_per_year' => $leaveType['max_days_per_year'] ?? 0,
        'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
        'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
    ];

    // Get current year
    $currentYear = date('Y');
    
    // First check if we have data in employee_leave_balances table
    $query = "SELECT allocated_days as allocated, used_days as used, remaining_days as remaining
              FROM employee_leave_balances 
              WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("SQL Error in getLeaveTypeBalance: " . $conn->error);
        return $balance;
    }

    $stmt->bind_param("iii", $employeeId, $leaveTypeId, $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        return [
            'allocated' => (int)$row['allocated'],
            'used' => (int)$row['used'],
            'remaining' => (int)$row['remaining'],
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => $leaveType['name'],
            'max_days_per_year' => $leaveType['max_days_per_year'] ?? 0,
            'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
            'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
        ];
    }

    // If not found in employee_leave_balances, check legacy leave_balances table
    $isAnnual = (stripos($leaveType['name'], 'annual') !== false);
    $isSick = (stripos($leaveType['name'], 'sick') !== false);
    
    // Ensure max_days_per_year has a default value
    $maxDaysPerYear = isset($leaveType['max_days_per_year']) && $leaveType['max_days_per_year'] ? 
                      (int)$leaveType['max_days_per_year'] : 0;

    if ($isAnnual) {
        // For annual leave, we have entitled/used/balance columns
        $legacyQuery = "SELECT 
                        annual_leave_entitled as allocated,
                        annual_leave_used as used,
                        annual_leave_balance as remaining
                        FROM leave_balances 
                        WHERE employee_id = ? AND financial_year = ?";
    } elseif ($isSick) {
        // For sick leave, we only have used columns
        $legacyQuery = "SELECT 
                        sick_leave_used as used,
                        {$maxDaysPerYear} as allocated,
                        GREATEST(0, {$maxDaysPerYear} - sick_leave_used) as remaining
                        FROM leave_balances 
                        WHERE employee_id = ? AND financial_year = ?";
    } else {
        // For other leave types, we only have used columns
        $legacyQuery = "SELECT 
                        other_leave_used as used,
                        {$maxDaysPerYear} as allocated,
                        GREATEST(0, {$maxDaysPerYear} - other_leave_used) as remaining
                        FROM leave_balances 
                        WHERE employee_id = ? AND financial_year = ?";
    }

    $legacyStmt = $conn->prepare($legacyQuery);
    if ($legacyStmt) {
        $legacyStmt->bind_param("ii", $employeeId, $currentYear);
        $legacyStmt->execute();
        $legacyResult = $legacyStmt->get_result();

        if ($legacyRow = $legacyResult->fetch_assoc()) {
            return [
                'allocated' => (int)$legacyRow['allocated'],
                'used' => (int)$legacyRow['used'],
                'remaining' => (int)$legacyRow['remaining'],
                'leave_type_id' => $leaveTypeId,
                'leave_type_name' => $leaveType['name'],
                'max_days_per_year' => $leaveType['max_days_per_year'] ?? 0,
                'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
                'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
            ];
        }
    }

    // No record exists anywhere, check if we should create one
    // Only create if this employee actually has an allocation in employee_leave_balances table
    $checkAllocationQuery = "SELECT COUNT(*) as has_allocations 
                            FROM employee_leave_balances 
                            WHERE employee_id = ? AND financial_year_id = ?";
    $checkStmt = $conn->prepare($checkAllocationQuery);
    $checkStmt->bind_param("ii", $employeeId, $currentYear);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $hasAllocations = $checkResult->fetch_assoc()['has_allocations'] > 0;

    if (!$hasAllocations) {
        // Employee has no allocations for this year, return zeros
        return $balance;
    }

    // Check if a record exists in leave_balances for this employee and year
    $checkExistingQuery = "SELECT id FROM leave_balances WHERE employee_id = ? AND financial_year = ?";
    $checkExistingStmt = $conn->prepare($checkExistingQuery);
    $checkExistingStmt->bind_param("ii", $employeeId, $currentYear);
    $checkExistingStmt->execute();
    $existingResult = $checkExistingStmt->get_result();

    if ($existingResult->num_rows == 0) {
        // No record exists, create new one - but use INSERT IGNORE to avoid duplicates
        $allocated = $maxDaysPerYear;
        $used = 0;
        $remaining = $allocated;

        $insertQuery = "INSERT IGNORE INTO leave_balances 
                       (employee_id, leave_type_id, financial_year, 
                        annual_leave_entitled, annual_leave_used, annual_leave_balance,
                        sick_leave_used, other_leave_used, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())";
        
        $insertStmt = $conn->prepare($insertQuery);

        // Only set annual leave values if it's annual leave, others default to 0
        $annualAllocated = $isAnnual ? $allocated : 0;
        $annualUsed = $isAnnual ? $used : 0;
        $annualBalance = $isAnnual ? $remaining : 0;

        $insertStmt->bind_param("iiiiii", $employeeId, $leaveTypeId, $currentYear, $annualAllocated, $annualUsed, $annualBalance);
        $insertStmt->execute();

        return [
            'allocated' => $allocated,
            'used' => $used,
            'remaining' => $remaining,
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => $leaveType['name'],
            'max_days_per_year' => $leaveType['max_days_per_year'] ?? 0,
            'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
            'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
        ];
    }

    // Record exists but doesn't match our leave type, just return default values
    return [
        'allocated' => $maxDaysPerYear,
        'used' => 0,
        'remaining' => $maxDaysPerYear,
        'leave_type_id' => $leaveTypeId,
        'leave_type_name' => $leaveType['name'],
        'max_days_per_year' => $leaveType['max_days_per_year'] ?? 0,
        'counts_weekends' => $leaveType['counts_weekends'] ?? 0,
        'deducted_from_annual' => $leaveType['deducted_from_annual'] ?? 0
    ];
}
function getAnnualLeaveTypeId($conn) {
    $stmt = $conn->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['id'];
    }
    return 1; // Default to ID 1 if not found
}

function getAnnualLeaveBalance($employeeId, $conn) {
    $annualTypeId = getAnnualLeaveTypeId($conn);
    return getLeaveTypeBalance($employeeId, $annualTypeId, $conn);
}

function calculateLeaveDeduction($employeeId, $leaveTypeId, $requestedDays, $conn) {
    $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);
    $leaveBalance = getLeaveTypeBalance($employeeId, $leaveTypeId, $conn);

    $deductionPlan = [
        'primary_deduction' => 0,
        'annual_deduction' => 0,
        'unpaid_days' => 0,
        'warnings' => [],
        'is_valid' => true,
        'total_days' => $requestedDays
    ];

    if (!$leaveType) {
        $deductionPlan['is_valid'] = false;
        $deductionPlan['warnings'][] = "Invalid leave type selected.";
        return $deductionPlan;
    }

    // Check if requested days exceed maximum allowed per year
    if ($leaveType['max_days_per_year'] && $requestedDays > $leaveType['max_days_per_year']) {
        $deductionPlan['warnings'][] = "Requested days ({$requestedDays}) exceed maximum allowed per year ({$leaveType['max_days_per_year']}).";
    }

    $availablePrimaryBalance = $leaveBalance['remaining'];

    if ($requestedDays <= $availablePrimaryBalance) {
        // Sufficient balance in primary leave type
        $deductionPlan['primary_deduction'] = $requestedDays;
        $deductionPlan['warnings'][] = "Will be deducted from {$leaveType['name']} balance.";
    } else {
        // Insufficient balance in primary leave type
        $primaryUsed = $availablePrimaryBalance;
        $remainingDays = $requestedDays - $primaryUsed;

        $deductionPlan['primary_deduction'] = $primaryUsed;

        // Check if fallback to annual leave is allowed
        if ($leaveType['deducted_from_annual'] == 1 && stripos($leaveType['name'], 'maternity') === false && $remainingDays > 0) {
            $annualBalance = getAnnualLeaveBalance($employeeId, $conn);

            if ($annualBalance['remaining'] >= $remainingDays) {
                // Sufficient annual leave balance
                $deductionPlan['annual_deduction'] = $remainingDays;
                $deductionPlan['warnings'][] = "Primary balance insufficient. {$primaryUsed} days from {$leaveType['name']}, {$remainingDays} days from Annual Leave.";
            } else {
                // Insufficient annual leave balance
                $annualUsed = $annualBalance['remaining'];
                $unpaidDays = $remainingDays - $annualUsed;

                $deductionPlan['annual_deduction'] = $annualUsed;
                $deductionPlan['unpaid_days'] = $unpaidDays;
                $deductionPlan['warnings'][] = "Insufficient leave balance. {$primaryUsed} days from {$leaveType['name']}, {$annualUsed} days from Annual Leave, {$unpaidDays} days will be unpaid.";
            }
        } else {
            // No fallback allowed or available
            $deductionPlan['unpaid_days'] = $remainingDays;
            if ($primaryUsed > 0) {
                $deductionPlan['warnings'][] = "{$primaryUsed} days from {$leaveType['name']}, {$remainingDays} days will be unpaid.";
            } else {
                $deductionPlan['warnings'][] = "No available balance. All {$requestedDays} days will be unpaid.";
            }
        }
    }

    return $deductionPlan;
}

function processLeaveDeduction($employeeId, $leaveTypeId, $deductionPlan, $conn) {
    $conn->begin_transaction();

    try {
        // Deduct from primary leave type
        if ($deductionPlan['primary_deduction'] > 0) {
            updateLeaveBalance($employeeId, $leaveTypeId, $deductionPlan['primary_deduction'], $conn, 'use');
        }

        // Deduct from annual leave if applicable
        if ($deductionPlan['annual_deduction'] > 0) {
            $annualBalance = getAnnualLeaveBalance($employeeId, $conn);
            if ($annualBalance['leave_type_id']) {
                updateLeaveBalance($employeeId, $annualBalance['leave_type_id'], $deductionPlan['annual_deduction'], $conn, 'use');
            }
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function updateLeaveBalance($employeeId, $leaveTypeId, $days, $conn, $action = 'use') {
    // First, get leave type details
    $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);
    if (!$leaveType) {
        return false;
    }

    // Determine which columns to update based on leave type
    $isAnnual = (stripos($leaveType['name'], 'annual') !== false);
    $isSick = (stripos($leaveType['name'], 'sick') !== false);

    $balance = getLeaveTypeBalance($employeeId, $leaveTypeId, $conn);

    if ($action == 'use') {
        $newUsed = $balance['used'] + $days;
        $newRemaining = max(0, $balance['allocated'] - $newUsed);
    } else {
        $newUsed = max(0, $balance['used'] - $days);
        $newRemaining = $balance['allocated'] - $newUsed;
    }

    if ($isAnnual) {
        // Update annual leave columns
        $query = "UPDATE leave_balances 
                  SET annual_leave_used = ?, 
                      annual_leave_balance = ?,
                      updated_at = NOW()
                  WHERE employee_id = ? AND leave_type_id = ?";
    } elseif ($isSick) {
        // Update sick leave column
        $query = "UPDATE leave_balances 
                  SET sick_leave_used = ?,
                      updated_at = NOW()
                  WHERE employee_id = ? AND leave_type_id = ?";
    } else {
        // Update other leave column
        $query = "UPDATE leave_balances 
                  SET other_leave_used = ?,
                      updated_at = NOW()
                  WHERE employee_id = ? AND leave_type_id = ?";
    }

    $stmt = $conn->prepare($query);

    if ($isAnnual) {
        $stmt->bind_param("iiii", $newUsed, $newRemaining, $employeeId, $leaveTypeId);
    } else {
        $stmt->bind_param("iii", $newUsed, $employeeId, $leaveTypeId);
    }

    return $stmt->execute();
}

function logLeaveTransaction($applicationId, $employeeId, $leaveTypeId, $days, $deductionPlan, $conn) {
    $transactionData = [
        'primary_leave_type' => $leaveTypeId,
        'primary_days' => $deductionPlan['primary_deduction'],
        'annual_days' => $deductionPlan['annual_deduction'],
        'unpaid_days' => $deductionPlan['unpaid_days'],
        'warnings' => implode('; ', $deductionPlan['warnings'])
    ];

    $query = "INSERT INTO leave_transactions 
              (application_id, employee_id, transaction_date, transaction_type, details) 
              VALUES (?, ?, NOW(), 'deduction', ?)";
    $stmt = $conn->prepare($query);
    $details = json_encode($transactionData);
    $stmt->bind_param("iis", $applicationId, $employeeId, $details);
    return $stmt->execute();
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input ?? '')));
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

// Get user's employee record for auto-filling
$userEmployeeQuery = "SELECT e.* FROM employees e 
                      LEFT JOIN users u ON u.employee_id = e.employee_id 
                      WHERE u.id = ?";
$stmt = $conn->prepare($userEmployeeQuery);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$userEmployee = $stmt->get_result()->fetch_assoc();

// Initialize variables
$success = '';
$error = '';
$employees = [];
$departments = [];
$sections = [];
$leaveTypes = [];
$leaveApplications = [];
$leaveBalances = [];
$pendingLeaves = [];
$approvedLeaves = [];
$rejectedLeaves = [];
$currentLeaves = [];
$allLeaves = [];
$holidays = [];
$employee = null;
$leaveBalance = null;
$leaveHistory = [];

// Handle AJAX requests
// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if (isset($_GET['get_employee_leave_types'])) {
        $employeeId = (int)$_GET['employee_id'];
        $response = [];
        
        // Get leave types for employee
        $leaveTypes = getLeaveTypesForEmployee($employeeId, $conn);
        $response['leaveTypes'] = $leaveTypes;
        
        // Get annual leave balance
        $annualBalance = getAnnualLeaveBalance($employeeId, $conn);
        $response['annualBalance'] = $annualBalance;
        
        echo json_encode($response);
        exit();
    }
}

// Get employees for dropdown (for HR and managers)
if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'managing_director'])) {
    $employeesQuery = "SELECT e.*, d.name as department_name, s.name as section_name 
                      FROM employees e 
                      LEFT JOIN departments d ON e.department_id = d.id 
                      LEFT JOIN sections s ON e.section_id = s.id";

    if ($user['role'] === 'dept_head') {
        $employeesQuery .= " WHERE e.department_id = " . (int)$userEmployee['department_id'];
    } elseif ($user['role'] === 'section_head') {
        $employeesQuery .= " WHERE e.section_id = " . (int)$userEmployee['section_id'];
    }

    $employeesQuery .= " ORDER BY e.first_name, e.last_name";
    $employees = $conn->query($employeesQuery)->fetch_all(MYSQLI_ASSOC);
}

// Get leave types for current user
if ($userEmployee) {
    $leaveTypes = getLeaveTypesForEmployee($userEmployee['id'], $conn);
}
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'apply_leave':
            $employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : ($userEmployee['id'] ?? 0);
            $leaveTypeId = (int)$_POST['leave_type_id'];
            $startDate = $_POST['start_date'];
            $endDate = $_POST['end_date'];
            $reason = sanitizeInput($_POST['reason']);

            // Get leave type details for calculation
            $leaveType = getLeaveTypeDetails($leaveTypeId, $conn);

            if (!$leaveType) {
                $error = "Invalid leave type selected.";
                break;
            }

            // Calculate days based on leave type settings
            $days = calculateBusinessDays($startDate, $endDate, $conn, $leaveType['counts_weekends'] == 0);

            // Calculate deduction plan
            $deductionPlan = calculateLeaveDeduction($employeeId, $leaveTypeId, $days, $conn);

            if (!$deductionPlan['is_valid']) {
                $error = implode(' ', $deductionPlan['warnings']);
                break;
            }

            try {
                $conn->begin_transaction();

                // Get the section head and department head for this employee
                $getManagersQuery = "SELECT
                    e.section_id, e.department_id,
                    (SELECT e2.id FROM employees e2 JOIN users u2 ON u2.employee_id = e2.employee_id WHERE e2.section_id = e.section_id AND u2.role = 'section_head' LIMIT 1) as section_head_emp_id,
                    (SELECT e3.id FROM employees e3 JOIN users u3 ON u3.employee_id = e3.employee_id WHERE e3.department_id = e.department_id AND u3.role = 'dept_head' LIMIT 1) as dept_head_emp_id
                    FROM employees e WHERE e.id = ?";
                $stmt = $conn->prepare($getManagersQuery);
                $stmt->bind_param("i", $employeeId);
                $stmt->execute();
                $managersResult = $stmt->get_result();
                $managers = $managersResult->fetch_assoc();

                $sectionHeadEmpId = $managers['section_head_emp_id'] ?? null;
                $deptHeadEmpId = $managers['dept_head_emp_id'] ?? null;

                // Set initial status
                $initialStatus = 'pending_section_head';

               // Check if applicant is a section head - then needs dept head approval
            if (hasPermission('section_head')) {
              $initialStatus = 'pending_dept_head';
             }

             // Check if applicant is a dept head - then needs managing director approval
             if (hasPermission('dept_head')) {
              $initialStatus = 'pending_managing_director';
          }

              // Check if applicant is managing director - then needs HR approval
               if (hasPermission('managing_director')) {
              $initialStatus = 'pending_hr_manager';
              // For MD applications, we'll set both section_head and dept_head to null
              $sectionHeadEmpId = null;
              $deptHeadEmpId = null;
               }

                // Insert application with deduction details
                $deductionDetails = json_encode($deductionPlan);
                $stmt = $conn->prepare("INSERT INTO leave_applications
                    (employee_id, leave_type_id, start_date, end_date, days_requested, reason,
                     status, applied_at, section_head_emp_id, dept_head_emp_id, deduction_details,
                     primary_days, annual_days, unpaid_days)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)");

                // Count the number of parameters to bind
                $params = [
                    $employeeId, 
                    $leaveTypeId, 
                    $startDate, 
                    $endDate,
                    $days, 
                    $reason, 
                    $initialStatus,
                    $sectionHeadEmpId,
                    $deptHeadEmpId,
                    $deductionDetails,
                    $deductionPlan['primary_deduction'],
                    $deductionPlan['annual_deduction'],
                    $deductionPlan['unpaid_days']
                ];

                // Create type string dynamically
                $types = str_repeat('s', count($params));

                // Replace first 2 with 'i' for integer parameters
                $types[0] = 'i';
                $types[1] = 'i';
                $types[7] = 'i'; // section_head_emp_id
                $types[8] = 'i'; // dept_head_emp_id
                $types[10] = 'i'; // primary_days
                $types[11] = 'i'; // annual_days
                $types[12] = 'i'; // unpaid_days

                $stmt->bind_param($types, ...$params);

                if ($stmt->execute()) {
                    $applicationId = $conn->insert_id;

                    // Log the transaction
                    logLeaveTransaction($applicationId, $employeeId, $leaveTypeId, $days, $deductionPlan, $conn);

                    // Log to leave_history
                    $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                                  (leave_application_id, action, performed_by, comments, performed_at) 
                                                  VALUES (?, 'applied', ?, ?, NOW())");
                    $comment = "Leave application submitted for $days days";
                    $historyStmt->bind_param("iis", $applicationId, $user['id'], $comment);
                    $historyStmt->execute();

                    $conn->commit();
                    $warningMessages = implode('<br>', $deductionPlan['warnings']);
                    $success = "Leave application submitted successfully!<br><strong>Deduction Summary:</strong><br>" . $warningMessages;
                } else {
                    $conn->rollback();
                    $error = "Error submitting application: " . $conn->error;
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Database error: " . $e->getMessage();
            }
            break;

        case 'approve_leave':
            // First check if user has permission to approve this type of leave
            $applicationId = (int)$_POST['application_id'];
            $approverComments = sanitizeInput($_POST['approver_comments']);

            try {
                $conn->begin_transaction();

                // Get application details and applicant info
                $stmt = $conn->prepare("SELECT la.*, lt.*, e.id as emp_id, u.role as applicant_role,
                                       u2.role as approver_role
                                       FROM leave_applications la
                                       JOIN leave_types lt ON la.leave_type_id = lt.id
                                       JOIN employees e ON la.employee_id = e.id
                                       JOIN users u ON u.employee_id = e.id
                                       LEFT JOIN users u2 ON u2.id = ?
                                       WHERE la.id = ?");
                $stmt->bind_param("ii", $user['id'], $applicationId);
                $stmt->execute();
                $application = $stmt->get_result()->fetch_assoc();

                // Check if approver is trying to approve their own leave
                if ($application['employee_id'] == $user['employee_id']) {
                    throw new Exception("You cannot approve your own leave application");
                }

                // Determine required approval based on current status and applicant's role
                $canApprove = false;
                $newStatus = 'approved'; // Default final status

                switch ($application['status']) {
                    case 'pending_section_head':
                        // Can be approved by section head or HR
                        $canApprove = (hasPermission('section_head') && $user['employee_id'] == $application['section_head_emp_id']) 
                                   || hasPermission('hr_manager');
                        // If there's a dept head, move to next level, otherwise approve
                        $newStatus = ($application['dept_head_emp_id'] ? 'pending_dept_head' : 'approved');
                        break;

                    case 'pending_dept_head':
                        // Can be approved by dept head or HR
                        $canApprove = (hasPermission('dept_head') && $user['employee_id'] == $application['dept_head_emp_id']) 
                                   || hasPermission('hr_manager');
                        $newStatus = 'approved';
                        break;

                    case 'pending_managing_director':
                        // Can be approved by managing director or HR
                        $canApprove = hasPermission('managing_director') || hasPermission('hr_manager');
                        $newStatus = 'approved';
                        break;

                    case 'pending_hr_manager':
                        // Only HR can approve managing director's leave
                        $canApprove = hasPermission('hr_manager')|| hasPermission('managing_director');
                        // If this is HR's approval, we finalize the application
                        if (hasPermission('hr_manager')) {  
                            $newStatus = 'approved';
                        } else {
                            // If managing director is approving, we can finalize
                            $newStatus = 'approved';
                        }
                        break;
                        $newStatus = 'approved';
                        break;

                    default:
                        throw new Exception("This application is not in a state that can be approved");
                }

                if (!$canApprove) {
                    throw new Exception("You don't have permission to approve this leave application");
                }

                // Get leave type details
                $leaveType = $application;
                $requestedDays = $application['days_requested'];

                // Get current balance for this leave type
                $balance = getLeaveTypeBalance($application['employee_id'], $application['leave_type_id'], $conn);
                $remaining = $balance['remaining'];

                // Check if deduction from annual leave is needed
                $deductedFromAnnual = 0;
                $fallbackUsed = 0;

                if ($remaining < $requestedDays) {
                    if ($leaveType['deducted_from_annual'] == 1 && stripos($leaveType['name'], 'maternity') === false) {
                        // Get annual leave balance
                        $annualTypeId = getAnnualLeaveTypeId($conn);
                        $annualBalance = getLeaveTypeBalance($application['employee_id'], $annualTypeId, $conn);

                        $fallbackUsed = min($requestedDays - $remaining, $annualBalance['remaining']);
                        $deductedFromAnnual = $fallbackUsed;

                        // Update annual leave balance
                        updateLeaveBalance($application['employee_id'], $annualTypeId, $fallbackUsed, $conn, 'use');
                    } else {
                        throw new Exception("Insufficient leave balance and no fallback available");
                    }
                }

                // Update primary leave balance
                $primaryUsed = min($requestedDays, $remaining);
                updateLeaveBalance($application['employee_id'], $application['leave_type_id'], $primaryUsed, $conn, 'use');

                // Update application status
                $stmt = $conn->prepare("UPDATE leave_applications 
                                       SET status = ?, 
                                           approver_id = ?, 
                                           approver_comments = ?, 
                                           approved_date = NOW(),
                                           days_deducted = ?,
                                           days_from_annual = ?
                                       WHERE id = ?");
                $stmt->bind_param("sisiiii", $newStatus, $user['id'], $approverComments, $requestedDays, $deductedFromAnnual, $applicationId);
                $stmt->execute();

                // Log to leave_history
                $comments = "Approved by " . $user['role'] . ". Deducted $primaryUsed days from " . $leaveType['name'];
                if ($deductedFromAnnual > 0) {
                    $comments .= " and $deductedFromAnnual days from annual leave (fallback)";
                }

                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                              (leave_application_id, action, performed_by, comments, performed_at) 
                                              VALUES (?, 'approved', ?, ?, NOW())");
                $historyStmt->bind_param("iss", $applicationId, $user['id'], $comments);
                $historyStmt->execute();

                $conn->commit();
                $success = "Leave application approved successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error approving leave: " . $e->getMessage();
            }
            break;

        case 'reject_leave':
            $applicationId = (int)$_POST['application_id'];
            $approverComments = sanitizeInput($_POST['approver_comments']);

            try {
                $conn->begin_transaction();

                // Get application details and applicant info
                $stmt = $conn->prepare("SELECT la.*, u.role as applicant_role, 
                                      la.section_head_emp_id, la.dept_head_emp_id
                                      FROM leave_applications la
                                      JOIN employees e ON la.employee_id = e.id
                                      JOIN users u ON u.employee_id = e.id
                                      WHERE la.id = ?");
                $stmt->bind_param("i", $applicationId);
                $stmt->execute();
                $application = $stmt->get_result()->fetch_assoc();

                // Check if approver is trying to reject their own leave
                if ($application['employee_id'] == $user['employee_id']) {
                    throw new Exception("You cannot reject your own leave application");
                }

                // Determine who can reject based on current status
                $canReject = false;
                $validStatuses = ['pending_section_head', 'pending_dept_head', 
                                 'pending_managing_director', 'pending_hr_manager'];

                if (!in_array($application['status'], $validStatuses)) {
                    throw new Exception("This application is not in a state that can be rejected");
                }

                switch ($application['status']) {
                    case 'pending_section_head':
                        // Can be rejected by section head or HR
                        $canReject = (hasPermission('section_head') && $user['employee_id'] == $application['section_head_emp_id'])
                                  || hasPermission('hr_manager');
                        break;

                    case 'pending_dept_head':
                        // Can be rejected by dept head or HR
                        $canReject = (hasPermission('dept_head') && $user['employee_id'] == $application['dept_head_emp_id'])
                                  || hasPermission('hr_manager');
                        break;

                    case 'pending_managing_director':
                        // Can be rejected by managing director or HR
                        $canReject = hasPermission('managing_director') || hasPermission('hr_manager');
                        break;

                    case 'pending_hr_manager':
                        // Only HR can reject managing director's leave
                        $canReject = hasPermission('hr_manager');
                        break;
                }

                if (!$canReject) {
                    throw new Exception("You don't have permission to reject this leave application");
                }

                // Update application status
                $stmt = $conn->prepare("UPDATE leave_applications 
                                      SET status = 'rejected', 
                                          approver_id = ?, 
                                          approver_comments = ?, 
                                          rejected_date = NOW()
                                      WHERE id = ?");
                $stmt->bind_param("isi", $user['id'], $approverComments, $applicationId);
                $stmt->execute();

                // Log to leave_history
                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                             (leave_application_id, action, performed_by, comments, performed_at) 
                                             VALUES (?, 'rejected', ?, ?, NOW())");
                $comments = "Rejected by " . $user['role'] . ": " . $approverComments;
                $historyStmt->bind_param("iss", $applicationId, $user['id'], $comments);
                $historyStmt->execute();

                $conn->commit();
                $success = "Leave application rejected successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error rejecting leave: " . $e->getMessage();
            }
            break;

        case 'add_holiday':
            if (hasPermission('hr_manager')) {
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
            break;
    }
}

// Handle GET actions for approvals
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Section Head Approval
    if ($action === 'section_head_approve' && isset($_GET['id']) && hasPermission('section_head')) {
        $leaveId = (int)$_GET['id'];
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT * FROM leave_applications WHERE id = ?");
            $stmt->bind_param("i", $leaveId);
            $stmt->execute();
            $application = $stmt->get_result()->fetch_assoc();

            $userEmpQuery = "SELECT id FROM employees WHERE employee_id = (SELECT employee_id FROM users WHERE id = ? )";
            $stmt = $conn->prepare($userEmpQuery);
            $stmt->bind_param("s", $user['id']);
            $stmt->execute();
            $userEmpRecord = $stmt->get_result()->fetch_assoc();

            $empSectionQuery = "SELECT section_id FROM employees WHERE id = ?";
            $stmt = $conn->prepare($empSectionQuery);
            $stmt->bind_param("i", $application['employee_id']);
            $stmt->execute();
            $empSectionResult = $stmt->get_result();
            $empSection = $empSectionResult->fetch_assoc();

            if ($userEmpRecord && $application && $application['status'] === 'pending_section_head' &&
                $empSection['section_id'] == $userEmployee['section_id']) {

                $stmt = $conn->prepare("UPDATE leave_applications SET status = 'pending_dept_head', section_head_approval = 'approved', section_head_approved_by = ?, section_head_approved_at = NOW() WHERE id = ?");
                $stmt->bind_param("ii", $userEmpRecord['id'], $leaveId);
                $stmt->execute();

                // Log to leave_history
                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                              (leave_application_id, action, performed_by, comments, performed_at) 
                                              VALUES (?, 'section_head_approved', ?, ?, NOW())");
                $comment = "Approved by section head";
                $historyStmt->bind_param("iis", $leaveId, $user['id'], $comment);
                $historyStmt->execute();

                $conn->commit();
                $_SESSION['flash_message'] = "Leave application approved by section head. Sent to department head.";
                $_SESSION['flash_type'] = "success";
            } else {
                $conn->rollback();
                $_SESSION['flash_message'] = "You are not authorized to approve this leave application.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Database error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: leave_management.php?tab=manage");
        exit();
    }

    // Section Head Reject
    if ($action === 'section_head_reject' && isset($_GET['id']) && hasPermission('section_head')) {
        $leaveId = (int)$_GET['id'];
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT * FROM leave_applications WHERE id = ?");
            $stmt->bind_param("i", $leaveId);
            $stmt->execute();
            $application = $stmt->get_result()->fetch_assoc();

            $userEmpQuery = "SELECT id FROM employees WHERE employee_id = (SELECT employee_id FROM users WHERE id = ? )";
            $stmt = $conn->prepare($userEmpQuery);
            $stmt->bind_param("s", $user['id']);
            $stmt->execute();
            $userEmpRecord = $stmt->get_result()->fetch_assoc();

            $empSectionQuery = "SELECT section_id FROM employees WHERE id = ?";
            $stmt = $conn->prepare($empSectionQuery);
            $stmt->bind_param("i", $application['employee_id']);
            $stmt->execute();
            $empSectionResult = $stmt->get_result();
            $empSection = $empSectionResult->fetch_assoc();

            if ($userEmpRecord && $application && $application['status'] === 'pending_section_head' &&
                $empSection['section_id'] == $userEmployee['section_id']) {

                $stmt = $conn->prepare("UPDATE leave_applications SET status = 'rejected', section_head_approval = 'rejected', section_head_approved_by = ?, section_head_approved_at = NOW() WHERE id = ?");
                $stmt->bind_param("ii", $userEmpRecord['id'], $leaveId);
                $stmt->execute();

                // Log rejection transaction
                logLeaveTransaction($leaveId, $application['employee_id'], $application['leave_type_id'], 
                                  $application['days_requested'], 
                                  ['warnings' => ['Application rejected by section head']], $conn);

                // Log to leave_history
                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                              (leave_application_id, action, performed_by, comments, performed_at) 
                                              VALUES (?, 'section_head_rejected', ?, ?, NOW())");
                $comment = "Rejected by section head";
                $historyStmt->bind_param("iis", $leaveId, $user['id'], $comment);
                $historyStmt->execute();

                $conn->commit();
                $_SESSION['flash_message'] = "Leave application rejected by section head.";
                $_SESSION['flash_type'] = "warning";
            } else {
                $conn->rollback();
                $_SESSION['flash_message'] = "You are not authorized to reject this leave application.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Database error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }
        header("Location: leave_management.php?tab=manage");
        exit();
    }

    // Department Head Approve
    if ($action === 'dept_head_approve' && isset($_GET['id']) && hasPermission('dept_head')) {
        $leaveId = (int)$_GET['id'];

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT * FROM leave_applications WHERE id = ?");
            $stmt->bind_param("i", $leaveId);
            $stmt->execute();
            $application = $stmt->get_result()->fetch_assoc();

            $userEmpQuery = "SELECT id FROM employees WHERE employee_id = (SELECT employee_id FROM users WHERE id = ?)";
            $stmt = $conn->prepare($userEmpQuery);
            $stmt->bind_param("s", $user['id']);
            $stmt->execute();
            $userEmpRecord = $stmt->get_result()->fetch_assoc();

            $empDeptQuery = "SELECT department_id FROM employees WHERE id = ?";
            $stmt = $conn->prepare($empDeptQuery);
            $stmt->bind_param("i", $application['employee_id']);
            $stmt->execute();
            $empDeptResult = $stmt->get_result();
            $empDept = $empDeptResult->fetch_assoc();

            if ($userEmpRecord && $application && $application['status'] === 'pending_dept_head' &&
                $empDept['department_id'] == $userEmployee['department_id']) {

                // Process leave deductions based on stored deduction plan
                if ($application['deduction_details']) {
                    $deductionPlan = json_decode($application['deduction_details'], true);
                    processLeaveDeduction($application['employee_id'], $application['leave_type_id'], $deductionPlan, $conn);
                }

                $stmt = $conn->prepare("UPDATE leave_applications SET status = 'approved', dept_head_approval = 'approved', dept_head_approved_by = ?, dept_head_approved_at = NOW() WHERE id = ?");
                $stmt->bind_param("ii", $userEmpRecord['id'], $leaveId);
                $stmt->execute();

                // Log to leave_history
                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                              (leave_application_id, action, performed_by, comments, performed_at) 
                                              VALUES (?, 'dept_head_approved', ?, ?, NOW())");
                $comment = "Approved by department head";
                $historyStmt->bind_param("iis", $leaveId, $user['id'], $comment);
                $historyStmt->execute();

                $conn->commit();
                $_SESSION['flash_message'] = "Leave application approved by department head. Leave balances updated.";
                $_SESSION['flash_type'] = "success";
            } else {
                $conn->rollback();
                $_SESSION['flash_message'] = "You are not authorized to approve this leave application.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Database error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }

        header("Location: leave_management.php?tab=manage");
        exit();
    }

    // Department Head Reject
    if ($action === 'dept_head_reject' && isset($_GET['id']) && hasPermission('dept_head')) {
        $leaveId = (int)$_GET['id'];

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT * FROM leave_applications WHERE id = ?");
            $stmt->bind_param("i", $leaveId);
            $stmt->execute();
            $application = $stmt->get_result()->fetch_assoc();

            $userEmpQuery = "SELECT id FROM employees WHERE employee_id = (SELECT employee_id FROM users WHERE id = ?)";
            $stmt = $conn->prepare($userEmpQuery);
            $stmt->bind_param("s", $user['id']);
            $stmt->execute();
            $userEmpRecord = $stmt->get_result()->fetch_assoc();

            $empDeptQuery = "SELECT department_id FROM employees WHERE id = ?";
            $stmt = $conn->prepare($empDeptQuery);
            $stmt->bind_param("i", $application['employee_id']);
            $stmt->execute();
            $empDeptResult = $stmt->get_result();
            $empDept = $empDeptResult->fetch_assoc();

            if ($userEmpRecord && $application && $application['status'] === 'pending_dept_head' &&
                $empDept['department_id'] == $userEmployee['department_id']) {

                $stmt = $conn->prepare("UPDATE leave_applications SET status = 'rejected', dept_head_approval = 'rejected', dept_head_approved_by = ?, dept_head_approved_at = NOW() WHERE id = ?");
                $stmt->bind_param("ii", $userEmpRecord['id'], $leaveId);
                $stmt->execute();

                // Log rejection transaction
                logLeaveTransaction($leaveId, $application['employee_id'], $application['leave_type_id'], 
                                  $application['days_requested'], 
                                  ['warnings' => ['Application rejected by department head']], $conn);

                // Log to leave_history
                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                              (leave_application_id, action, performed_by, comments, performed_at) 
                                              VALUES (?, 'dept_head_rejected', ?, ?, NOW())");
                $comment = "Rejected by department head";
                $historyStmt->bind_param("iis", $leaveId, $user['id'], $comment);
                $historyStmt->execute();

                $conn->commit();
                $_SESSION['flash_message'] = "Leave application rejected by department head.";
                $_SESSION['flash_type'] = "warning";
            } else {
                $conn->rollback();
                $_SESSION['flash_message'] = "You are not authorized to reject this leave application.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Database error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }

        header("Location: leave_management.php?tab=manage");
        exit();
    }

    // HR Final Approval with full deduction processing
    if ($action === 'approve_leave' && isset($_GET['id']) && hasPermission('hr_manager')) {
        $leaveId = (int)$_GET['id'];
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT * FROM leave_applications WHERE id = ?");
            $stmt->bind_param("i", $leaveId);
            $stmt->execute();
            $application = $stmt->get_result()->fetch_assoc();

            if ($application) {
                // Process leave deductions if not already processed
                if ($application['deduction_details'] && $application['status'] !== 'approved') {
                    $deductionPlan = json_decode($application['deduction_details'], true);
                    processLeaveDeduction($application['employee_id'], $application['leave_type_id'], $deductionPlan, $conn);
                }

                $stmt = $conn->prepare("UPDATE leave_applications 
                                      SET status = 'approved', approver_id = ?, 
                                          approved_date = NOW() WHERE id = ?");
                $stmt->bind_param("si", $user['id'], $leaveId);
                $stmt->execute();

                // Log to leave_history
                $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                              (leave_application_id, action, performed_by, comments, performed_at) 
                                              VALUES (?, 'hr_approved', ?, ?, NOW())");
                $comment = "Approved by HR";
                $historyStmt->bind_param("iis", $leaveId, $user['id'], $comment);
                $historyStmt->execute();

                $conn->commit();
                $_SESSION['flash_message'] = "Leave application approved by HR. All deductions processed.";
                $_SESSION['flash_type'] = "success";
            } else {
                $conn->rollback();
                $_SESSION['flash_message'] = "Application not found.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Database error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }

        header("Location: leave_management.php?tab=manage");
        exit();
    }

    // HR Rejection
    if ($action === 'reject_leave' && isset($_GET['id']) && hasPermission('hr_manager')) {
        $leaveId = (int)$_GET['id'];
        try {
            $stmt = $conn->prepare("UPDATE leave_applications 
                                  SET status = 'rejected', approver_id = ?, 
                                      approved_date = NOW() WHERE id = ?");
            $stmt->bind_param("si", $user['id'], $leaveId);

            if ($stmt->execute()) {
                // Get application details for logging
                $appStmt = $conn->prepare("SELECT employee_id, leave_type_id, days_requested FROM leave_applications WHERE id = ?");
                $appStmt->bind_param("i", $leaveId);
                $appStmt->execute();
                $appResult = $appStmt->get_result()->fetch_assoc();

                if ($appResult) {
                    logLeaveTransaction($leaveId, $appResult['employee_id'], $appResult['leave_type_id'], 
                                      $appResult['days_requested'], 
                                      ['warnings' => ['Application rejected by HR']], $conn);

                    // Log to leave_history
                    $historyStmt = $conn->prepare("INSERT INTO leave_history 
                                                  (leave_application_id, action, performed_by, comments, performed_at) 
                                                  VALUES (?, 'hr_rejected', ?, ?, NOW())");
                    $comment = "Rejected by HR";
                    $historyStmt->bind_param("iis", $leaveId, $user['id'], $comment);
                    $historyStmt->execute();
                }

                $_SESSION['flash_message'] = "Leave application rejected by HR.";
                $_SESSION['flash_type'] = "warning";
            } else {
                $_SESSION['flash_message'] = "Error rejecting leave application.";
                $_SESSION['flash_type'] = "danger";
            }
        } catch (Exception $e) {
            $_SESSION['flash_message'] = "Database error: " . $e->getMessage();
            $_SESSION['flash_type'] = "danger";
        }

        header("Location: leave_management.php?tab=manage");
        exit();
    }
}

// Fetch data for dropdowns and displays
try {
    // Get departments
    $departmentsResult = $conn->query("SELECT * FROM departments ORDER BY name");
    $departments = $departmentsResult->fetch_all(MYSQLI_ASSOC);

    // Get sections
    $sectionsResult = $conn->query("SELECT s.*, d.name as department_name FROM sections s 
                                   LEFT JOIN departments d ON s.department_id = d.id ORDER BY s.name");
    $sections = $sectionsResult->fetch_all(MYSQLI_ASSOC);

    // Get employees (for managers)
    if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head'])) {
        $employeesQuery = "SELECT e.*, d.name as department_name, s.name as section_name 
                          FROM employees e 
                          LEFT JOIN departments d ON e.department_id = d.id 
                          LEFT JOIN sections s ON e.section_id = s.id";

        if ($user['role'] === 'dept_head') {
            $employeesQuery .= " WHERE e.department_id = " . (int)$userEmployee['department_id'];
        } elseif ($user['role'] === 'section_head') {
            $employeesQuery .= " WHERE e.section_id = " . (int)$userEmployee['section_id'];
        }

        $employeesQuery .= " ORDER BY e.first_name, e.last_name";
        $employees = $conn->query($employeesQuery)->fetch_all(MYSQLI_ASSOC);
    }

    // Get holidays
    $holidaysResult = $conn->query("SELECT * FROM holidays ORDER BY date DESC");
    $holidays = $holidaysResult->fetch_all(MYSQLI_ASSOC);

    // Get leave applications with enhanced deduction details
    if (hasPermission('hr_manager')) {
        $applicationsQuery = "SELECT la.*, e.employee_id, e.first_name, e.last_name, 
                             lt.name as leave_type_name, d.name as department_name, s.name as section_name,
                             u.first_name as approver_first_name, u.last_name as approver_last_name
                             FROM leave_applications la
                             JOIN employees e ON la.employee_id = e.id
                             JOIN leave_types lt ON la.leave_type_id = lt.id
                             LEFT JOIN departments d ON e.department_id = d.id
                             LEFT JOIN sections s ON e.section_id = s.id
                             LEFT JOIN users u ON la.approver_id = u.id
                             ORDER BY la.applied_at DESC";
        $applicationsResult = $conn->query($applicationsQuery);
        $leaveApplications = $applicationsResult->fetch_all(MYSQLI_ASSOC);
    } else {
        if ($userEmployee) {
            $stmt = $conn->prepare("SELECT la.*, lt.name as leave_type_name,
                                   u.first_name as approver_first_name, u.last_name as approver_last_name
                                   FROM leave_applications la
                                   JOIN leave_types lt ON la.leave_type_id = lt.id
                                   LEFT JOIN users u ON la.approver_id = u.id
                                   WHERE la.employee_id = ?
                                   ORDER BY la.applied_at DESC");
            $stmt->bind_param("i", $userEmployee['id']);
            $stmt->execute();
            $leaveApplications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    // Get leave balances for current user with leave type details - only latest financial year
if ($userEmployee) {
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
    $stmt->bind_param("ii", $userEmployee['id'], $latestYear);
    $stmt->execute();
    $leaveBalances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

    // Populate management tabs with enhanced data
    if ($tab === 'manage' && in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'manager', 'managing_director'])) {
        // Role-specific filtering with enhanced deduction information
        if ($user['role'] === 'section_head' && $userEmployee) {
            $sectionId = (int)$userEmployee['section_id'];

            $pendingQuery = "SELECT la.*, e.employee_id as emp_id, e.first_name, e.last_name,
                             lt.name as leave_type_name, d.name as department_name, s.name as section_name,
                             la.primary_days, la.annual_days, la.unpaid_days
                             FROM leave_applications la
                             JOIN employees e ON la.employee_id = e.id
                             JOIN leave_types lt ON la.leave_type_id = lt.id
                             LEFT JOIN departments d ON e.department_id = d.id
                             LEFT JOIN sections s ON e.section_id = s.id
                             WHERE la.status = 'pending_section_head'
                             AND e.section_id = ?
                             ORDER BY la.applied_at DESC";
            $stmt = $conn->prepare($pendingQuery);
            $stmt->bind_param("i", $sectionId);
            $stmt->execute();
            $pendingResult = $stmt->get_result();
            $pendingLeaves = $pendingResult->fetch_all(MYSQLI_ASSOC);
        } 
        elseif ($user['role'] === 'dept_head' && $userEmployee) {
            $deptId = (int)$userEmployee['department_id'];

            $pendingQuery = "SELECT la.*, e.employee_id, e.first_name, e.last_name,
                            lt.name as leave_type_name, d.name as department_name, s.name as section_name,
                            la.primary_days, la.annual_days, la.unpaid_days
                            FROM leave_applications la
                            JOIN employees e ON la.employee_id = e.id
                            JOIN leave_types lt ON la.leave_type_id = lt.id
                            LEFT JOIN departments d ON e.department_id = d.id
                            LEFT JOIN sections s ON e.section_id = s.id
                            WHERE la.status = 'pending_dept_head'
                            AND e.department_id = ?
                            ORDER BY la.applied_at DESC";
            $stmt = $conn->prepare($pendingQuery);
            $stmt->bind_param("i", $deptId);
            $stmt->execute();
            $pendingResult = $stmt->get_result();
            $pendingLeaves = $pendingResult->fetch_all(MYSQLI_ASSOC);
        }
        else {
            // HR and other roles see all pending applications
            $pendingQuery = "SELECT la.*, e.employee_id, e.first_name, e.last_name,
                             lt.name as leave_type_name, d.name as department_name, s.name as section_name,
                             la.primary_days, la.annual_days, la.unpaid_days
                             FROM leave_applications la
                             JOIN employees e ON la.employee_id = e.id
                             JOIN leave_types lt ON la.leave_type_id = lt.id
                             LEFT JOIN departments d ON e.department_id = d.id
                             LEFT JOIN sections s ON e.section_id = s.id
                             WHERE la.status IN ('pending', 'pending_section_head', 'pending_dept_head')
                             ORDER BY la.applied_at DESC";
            $pendingResult = $conn->query($pendingQuery);
            $pendingLeaves = $pendingResult->fetch_all(MYSQLI_ASSOC);
        }
    }

    // Get profile data with enhanced balance information
    if ($tab === 'profile') {
        if ($userEmployee) {
            $employee = $userEmployee;

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
    }

} catch (Exception $e) {
    $error = "Error fetching data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Leave Management - HR Management System</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
                    <li><a href="leave_management.php" class="active">Leave Management</a></li>
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
                <h1>Enhanced Leave Management System</h1>
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
                    <a href="leave_management.php" class="leave-tab active">Apply Leave</a>
                    <?php if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'manager', 'managing_director','super_admin'])): ?>
                    <a href="manage.php" class="leave-tab">Manage Leave</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing director'])): ?>
                    <a href="history.php" class="leave-tab">Leave History</a>
                    <a href="holidays.php" class="leave-tab">Holidays</a>
                    <?php endif; ?>
                    <a href="profile.php" class="leave-tab">My Leave Profile</a>
                </div>

    <!-- Enhanced Apply Leave Tab -->
    <div class="tab-content">
        <h3>Apply for Leave</h3>
        <?php if ($userEmployee): ?>
            <!-- Leave Balance Overview -->
            <div class="leave-balance-card" id="leaveBalanceCard">
                <?php 
                // Get annual leave balance
                $annualBalance = getAnnualLeaveBalance($userEmployee['id'], $conn);
                $remainingClass = ($annualBalance['remaining'] < 0) ? 'balance-negative' : 'balance-remaining';
                ?>
                <div class="balance-header"><?php echo htmlspecialchars($annualBalance['leave_type_name']); ?></div>
                <div class="balance-details">
                    <div class="balance-item balance-allocated">
                        <div>Allocated</div>
                        <strong><?php echo (int)$annualBalance['allocated']; ?></strong>
                    </div>
                    <div class="balance-item balance-used">
                        <div>Used</div>
                        <strong><?php echo (int)$annualBalance['used']; ?></strong>
                    </div>
                    <div class="balance-item <?php echo $remainingClass; ?>">
                        <div>Remaining</div>
                        <strong><?php echo (int)$annualBalance['remaining']; ?></strong>
                    </div>
                </div>
            </div>

            <form method="POST" action="" id="leaveApplicationForm">
                <input type="hidden" name="action" value="apply_leave">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="employee_id">Employee</label>
                        <select id="employee_id" name="employee_id" class="form-control" required>
                            <option value="">Select Employee</option>
                            <?php 
                            if ($userEmployee) {
                                if (!in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'managing_director'])) {
                                    // Regular employees can only apply for themselves
                                    echo '<option value="' . $userEmployee['id'] . '" selected>' . 
                                         htmlspecialchars(
                                             $userEmployee['employee_id'] . ' - ' . 
                                             $userEmployee['first_name'] . ' ' . 
                                             $userEmployee['last_name'] . ' (' . 
                                             ($userEmployee['designation'] ?? '') . ')'
                                         ) . '</option>';
                                } elseif (!empty($employees)) {
                                    // HR and managers can select from their employees
                                    foreach ($employees as $employee) {
                                        $selected = ($employee['id'] == $userEmployee['id']) ? 'selected' : '';
                                        echo '<option value="' . $employee['id'] . '" ' . $selected . '>' . 
                                             htmlspecialchars(
                                                 $employee['employee_id'] . ' - ' . 
                                                 $employee['first_name'] . ' ' . 
                                                 $employee['last_name'] . ' (' . 
                                                 ($employee['designation'] ?? '') . ')'
                                             ) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="leave_type_id">Leave Type</label>
                        <select name="leave_type_id" id="leave_type_id" class="form-control" required>
                            <option value="">Select Leave Type</option>
                            <?php if (!empty($leaveTypes)): ?>
                                <?php foreach ($leaveTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        data-max-days="<?php echo $type['remaining']; ?>"
                                        data-counts-weekends="<?php echo $type['counts_weekends']; ?>"
                                        data-fallback="<?php echo $type['deducted_from_annual']; ?>">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                    (Remaining: <?php echo $type['remaining']; ?> days)
                                </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if (empty($leaveTypes)): ?>
                        <small class="text-danger">No leave types allocated to you. Please contact HR.</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control" required>
                    </div>

               

                <div class="form-grid">
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" name="end_date" id="end_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="calculated_days">Calculated Days</label>
                        <input type="text" id="calculated_days" class="form-control" readonly>
                    </div>
                </div>

                <!-- Enhanced Deduction Preview -->
                <div id="deduction_preview" class="deduction-preview" style="display: none;">
                    <h5>Leave Deduction Preview</h5>
                    <div id="deduction_details"></div>
                </div>

                <div class="form-group">
                    <label for="reason">Reason for Leave</label>
                    <textarea name="reason" id="reason" class="form-control" rows="3" required></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="submit_btn">Submit Application</button>
                    <button type="reset" class="btn btn-secondary">Reset Form</button>
                </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
    $(document).ready(function() {
        // Employee selection change handler
        $('#employee_id').on('change', function() {
            const employeeId = $(this).val();
            if (!employeeId) return;

            // Make AJAX request to get leave types for selected employee
            $.ajax({
                url: '?ajax=1&get_employee_leave_types=1&employee_id=' + employeeId,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    // Update leave types dropdown
                    const leaveTypeSelect = $('#leave_type_id');
                    leaveTypeSelect.empty();
                    leaveTypeSelect.append('<option value="">Select Leave Type</option>');
                    
                    $.each(response.leaveTypes, function(index, type) {
                        leaveTypeSelect.append(
                            $('<option></option>').attr('value', type.id)
                                .text(type.name + ' (Remaining: ' + type.remaining + ' days)')
                                .data('max-days', type.remaining)
                                .data('counts-weekends', type.counts_weekends)
                                .data('fallback', type.deducted_from_annual)
                        );
                    });

                    // Update leave balance card
                    if (response.annualBalance) {
                        const balance = response.annualBalance;
                        const remainingClass = (balance.remaining < 0) ? 'balance-negative' : 'balance-remaining';
                        
                        $('#leaveBalanceCard').html(`
                            <div class="balance-header">${balance.leave_type_name}</div>
                            <div class="balance-details">
                                <div class="balance-item balance-allocated">
                                    <div>Allocated</div>
                                    <strong>${balance.allocated}</strong>
                                </div>
                                <div class="balance-item balance-used">
                                    <div>Used</div>
                                    <strong>${balance.used}</strong>
                                </div>
                                <div class="balance-item ${remainingClass}">
                                    <div>Remaining</div>
                                    <strong>${balance.remaining}</strong>
                                </div>
                            </div>
                        `);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading leave types:', error);
                    alert('Error loading leave types for selected employee');
                }
            });
        });

        /// Enhanced JavaScript for real-time leave deduction calculation
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const leaveTypeInput = document.getElementById('leave_type_id');
    const employeeInput = document.getElementById('employee_id');
    const calculatedDays = document.getElementById('calculated_days');
    const deductionPreview = document.getElementById('deduction_preview');
    const deductionDetails = document.getElementById('deduction_details');
    const submitBtn = document.getElementById('submit_btn');

    // Leave balances data (populated from PHP)
    const leaveBalances = <?php 
        // Get all leave balances for current employee and latest financial year
        $employeeLeaveBalances = [];
        if ($userEmployee && isset($latestYear)) {
            $stmt = $conn->prepare("SELECT elb.*, lt.name as leave_type_name 
                                  FROM employee_leave_balances elb
                                  JOIN leave_types lt ON elb.leave_type_id = lt.id
                                  WHERE elb.employee_id = ? 
                                  AND elb.financial_year_id = ?");
            $stmt->bind_param("ii", $userEmployee['id'], $latestYear);
            $stmt->execute();
            $employeeLeaveBalances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($employeeLeaveBalances); 
    ?>;
    
    const leaveTypes = <?php echo json_encode($leaveTypes); ?>;

    function calculateDays() {
        if (startDateInput.value && endDateInput.value && leaveTypeInput.value) {
            const start = new Date(startDateInput.value);
            const end = new Date(endDateInput.value);
            const leaveTypeId = parseInt(leaveTypeInput.value);

            if (end >= start) {
                const selectedLeaveType = leaveTypes.find(lt => lt.id == leaveTypeId);
                const countsWeekends = selectedLeaveType ? selectedLeaveType.counts_weekends == '1' : false;

                let diffDays = 0;
                let current = new Date(start);

                while (current <= end) {
                    const dayOfWeek = current.getDay(); // 0 = Sunday, 6 = Saturday

                    // Count weekends based on leave type setting
                    if (countsWeekends || (dayOfWeek !== 0 && dayOfWeek !== 6)) {
                        diffDays++;
                    }

                    current.setDate(current.getDate() + 1);
                }

                calculatedDays.value = diffDays + ' days';
                calculateDeduction(leaveTypeId, diffDays);
            } else {
                calculatedDays.value = 'Invalid date range';
                deductionPreview.style.display = 'none';
            }
        } else {
            calculatedDays.value = '';
            deductionPreview.style.display = 'none';
        }
    }

    function calculateDeduction(leaveTypeId, requestedDays) {
        const selectedLeaveType = leaveTypes.find(lt => lt.id == leaveTypeId);
        const leaveBalance = leaveBalances.find(lb => lb.leave_type_id == leaveTypeId);
        const annualBalance = leaveBalances.find(lb => lb.leave_type_name.toLowerCase().includes('annual'));

        if (!selectedLeaveType) {
            deductionPreview.style.display = 'none';
            return;
        }

        let deductionHtml = '';
        let primaryDeduction = 0;
        let annualDeduction = 0;
        let unpaidDays = 0;
        let warnings = [];

        // Get available balance from employee_leave_balances
        const availablePrimaryBalance = leaveBalance ? parseInt(leaveBalance.remaining_days) : 0;

        // Check maximum days per year
        if (selectedLeaveType.max_days_per_year && requestedDays > parseInt(selectedLeaveType.max_days_per_year)) {
            warnings.push(`⚠️ Requested days (${requestedDays}) exceed maximum allowed per year (${selectedLeaveType.max_days_per_year}).`);
        }

        if (requestedDays <= availablePrimaryBalance) {
            // Sufficient balance in primary leave type
            primaryDeduction = requestedDays;
            warnings.push(`✅ Will be deducted from ${selectedLeaveType.name} balance.`);
        } else {
            // Insufficient balance in primary leave type
            primaryDeduction = Math.max(0, availablePrimaryBalance);
            let remainingDays = requestedDays - primaryDeduction;

            // Check if fallback to annual leave is allowed
            if (selectedLeaveType.deducted_from_annual == '1' && remainingDays > 0 && annualBalance) {
                const availableAnnualBalance = parseInt(annualBalance.remaining_days);

                if (availableAnnualBalance >= remainingDays) {
                    // Sufficient annual leave balance
                    annualDeduction = remainingDays;
                    warnings.push(`⚠️ Primary balance insufficient. ${primaryDeduction} days from ${selectedLeaveType.name}, ${annualDeduction} days from Annual Leave.`);
                } else {
                    // Insufficient annual leave balance
                    annualDeduction = Math.max(0, availableAnnualBalance);
                    unpaidDays = remainingDays - annualDeduction;
                    warnings.push(`❌ Insufficient leave balance. ${primaryDeduction} days from ${selectedLeaveType.name}, ${annualDeduction} days from Annual Leave, ${unpaidDays} days will be unpaid.`);
                }
            } else {
                // No fallback allowed
                unpaidDays = remainingDays;
                if (primaryDeduction > 0) {
                    warnings.push(`❌ ${primaryDeduction} days from ${selectedLeaveType.name}, ${unpaidDays} days will be unpaid.`);
                } else {
                    warnings.push(`❌ No available balance. All ${requestedDays} days will be unpaid.`);
                }
            }
        }

        // Build deduction HTML
        deductionHtml += '<div class="deduction-item"><span>Requested Days:</span><span>' + requestedDays + '</span></div>';

        if (primaryDeduction > 0) {
            deductionHtml += '<div class="deduction-item"><span>' + selectedLeaveType.name + ' Deduction:</span><span>' + primaryDeduction + ' days</span></div>';
        }

        if (annualDeduction > 0) {
            deductionHtml += '<div class="deduction-item"><span>Annual Leave Deduction:</span><span>' + annualDeduction + ' days</span></div>';
        }

        if (unpaidDays > 0) {
            deductionHtml += '<div class="deduction-item" style="color: #dc3545;"><span>Unpaid Days:</span><span>' + unpaidDays + ' days</span></div>';
        }

        // Add warnings
        warnings.forEach(function(warning) {
            let warningClass = 'info-text';
            if (warning.includes('❌') || warning.includes('unpaid')) {
                warningClass = 'unpaid-warning';
            } else if (warning.includes('⚠️')) {
                warningClass = 'warning-text';
            }
            deductionHtml += '<div class="' + warningClass + '">' + warning + '</div>';
        });

        deductionDetails.innerHTML = deductionHtml;
        deductionPreview.style.display = 'block';

        // Enable/disable submit button based on unpaid days
        if (unpaidDays > 0) {
            submitBtn.innerHTML = 'Submit Application (Includes Unpaid Leave)';
            submitBtn.className = 'btn btn-warning';
        } else {
            submitBtn.innerHTML = 'Submit Application';
            submitBtn.className = 'btn btn-primary';
        }
    }

    // Event listeners
    startDateInput.addEventListener('change', calculateDays);
    endDateInput.addEventListener('change', calculateDays);
    leaveTypeInput.addEventListener('change', calculateDays);

    // Set minimum date to today
    const today = new Date().toISOString().split('T')[0];
    if (startDateInput) {
        startDateInput.min = today;
    }
    if (endDateInput) {
        endDateInput.min = today;
    }

    // Update end date minimum when start date changes
    startDateInput.addEventListener('change', function() {
        if (endDateInput) {
            endDateInput.min = startDateInput.value;
        }
    });
});
    });
    </script>
</body>
</html>