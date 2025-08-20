<?php
session_start();
require_once 'config.php'; // Include database configuration
$conn = getConnection();
$current_user_id = $_SESSION['user_id'] ?? 0;
// Role-based access control
function hasPermission($requiredRole) {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    
    // Permission hierarchy
    $roles = [
        'managing_director' => 6,
        'super_admin' => 5,
        'hr_manager' => 4,
        'dept_head' => 3,
        'section_head' => 2,
        'manager' => 1,
        'employee' => 0
    ];
    
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}

// Get user details
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

// Check if user has permission to access payroll
if (!hasPermission('hr_manager') && !hasPermission('accountant') && !hasPermission('super_admin')) {
    header('Location: dashboard.php');
    exit();
}

/**
 * Payroll Manager Class with Database Integration
 */
class PayrollManager {
    private $conn;
    private $user_id;
    private $user_role;
    
    public function __construct($connection, $user_id, $user_role) {
        $this->conn = $connection;
        $this->user_id = $user_id;
        $this->user_role = $user_role;
    }
    
    // Get payroll periods from database
    public function getPayrollPeriods() {
        $query = "SELECT * FROM payroll_periods ORDER BY start_date DESC";
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get employees based on user role
    public function getEmployees() {
        $query = "SELECT e.id, e.employee_id, CONCAT(e.first_name, ' ', e.last_name) AS full_name 
                  FROM employees e";
        
        // Add role-based restrictions
        if ($this->user_role === 'employee') {
            $query .= " WHERE e.user_id = " . (int)$this->user_id;
        } elseif (in_array($this->user_role, ['dept_head', 'section_head', 'manager'])) {
            $query .= " WHERE e.department_id IN (
                SELECT department_id FROM employees WHERE user_id = " . (int)$this->user_id . "
            )";
        }
        
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get salary details for an employee
    public function getEmployeeSalary($employee_id) {
        $query = "SELECT es.basic_salary 
                  FROM employee_salaries es
                  WHERE es.employee_id = ? 
                  AND es.is_active = 1
                  ORDER BY es.effective_date DESC 
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    // Get allowance types
    public function getAllowanceTypes() {
        $query = "SELECT * FROM allowance_types WHERE is_active = 1";
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get deduction types
    public function getDeductionTypes() {
        $query = "SELECT * FROM deduction_types WHERE is_active = 1";
        $result = $this->conn->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Calculate PAYE tax using tax brackets from database
    public function calculatePAYETax($taxableIncome) {
        $query = "SELECT * FROM tax_brackets 
                  WHERE is_active = 1 
                  AND effective_date <= CURDATE()
                  ORDER BY min_amount";
        
        $result = $this->conn->query($query);
        $brackets = $result->fetch_all(MYSQLI_ASSOC);
        
        $annualTaxableIncome = $taxableIncome * 12;
        $tax = 0;
        
        foreach ($brackets as $bracket) {
            if ($annualTaxableIncome <= $bracket['min_amount']) continue;
            
            $bracketMax = $bracket['max_amount'] ?? PHP_INT_MAX;
            $bracketMin = $bracket['min_amount'];
            $bracketIncome = min($annualTaxableIncome, $bracketMax) - $bracketMin;
            
            if ($bracketIncome > 0) {
                $tax += $bracketIncome * ($bracket['tax_rate'] / 100);
            }
        }
        
        // Apply personal relief
        $tax = max(0, $tax - 2400);
        
        return round($tax / 12, 2);
    }
    
    // Calculate NSSF contribution
    public function calculateNSSF($basicSalary) {
        // Tier I: 6% of first KES 7,000
        $tierOneContribution = min($basicSalary, 7000) * 0.06;
        
        // Tier II: 6% of amount exceeding KES 7,000 up to KES 36,000
        $tierTwoContribution = 0;
        if ($basicSalary > 7000) {
            $tierTwoBase = min($basicSalary - 7000, 29000);
            $tierTwoContribution = $tierTwoBase * 0.06;
        }
        
        return round($tierOneContribution + $tierTwoContribution, 2);
    }
    
    // Calculate NHIF based on salary bands
    public function calculateNHIF($grossSalary) {
        $nhifBands = [
            [0, 5999, 150],
            [6000, 7999, 300],
            [8000, 11999, 400],
            [12000, 14999, 500],
            [15000, 19999, 600],
            [20000, 24999, 750],
            [25000, 29999, 850],
            [30000, 34999, 900],
            [35000, 39999, 950],
            [40000, 44999, 1000],
            [45000, 49999, 1100],
            [50000, 59999, 1200],
            [60000, 69999, 1300],
            [70000, 79999, 1400],
            [80000, 89999, 1500],
            [90000, 99999, 1600],
            [100000, PHP_INT_MAX, 1700]
        ];
        
        foreach ($nhifBands as $band) {
            if ($grossSalary >= $band[0] && $grossSalary <= $band[1]) {
                return $band[2];
            }
        }
        return 0;
    }
    
    // Calculate Housing Levy (1.5% of basic salary, max KES 2500)
    public function calculateHousingLevy($basicSalary) {
        $levy = $basicSalary * 0.015;
        return round(min($levy, 2500), 2);
    }
    
    // Process payroll for an employee
    public function processEmployeePayroll($employee_id, $period_id, $working_days, $days_worked) {
        // Get employee salary
        $salaryData = $this->getEmployeeSalary($employee_id);
        if (!$salaryData) {
            return ['success' => false, 'error' => 'Salary data not found for employee'];
        }
        
        $basicSalary = $salaryData['basic_salary'];
        
        // Prorate salary if needed
        if ($days_worked < $working_days) {
            $basicSalary = ($basicSalary / $working_days) * $days_worked;
        }
        
        // Calculate allowances (simplified for demo)
        $allowances = 0;
        $allowanceDetails = [];
        
        // Calculate deductions
        $payeTax = $this->calculatePAYETax($basicSalary);
        $nssfDeduction = $this->calculateNSSF($basicSalary);
        $nhifDeduction = $this->calculateNHIF($basicSalary);
        $housingLevy = $this->calculateHousingLevy($basicSalary);
        
        // Other deductions (simplified for demo)
        $otherDeductions = 0;
        
        $grossSalary = $basicSalary + $allowances;
        $totalDeductions = $payeTax + $nssfDeduction + $nhifDeduction + $housingLevy + $otherDeductions;
        $netSalary = $grossSalary - $totalDeductions;
        
        return [
            'success' => true,
            'employee_id' => $employee_id,
            'period_id' => $period_id,
            'basic_salary' => $basicSalary,
            'allowances' => $allowances,
            'gross_salary' => $grossSalary,
            'paye_tax' => $payeTax,
            'nssf' => $nssfDeduction,
            'nhif' => $nhifDeduction,
            'housing_levy' => $housingLevy,
            'other_deductions' => $otherDeductions,
            'total_deductions' => $totalDeductions,
            'net_salary' => $netSalary,
            'working_days' => $working_days,
            'days_worked' => $days_worked
        ];
    }
}

// Initialize PayrollManager
$payrollManager = new PayrollManager($conn, $current_user_id, $current_user_role);

// Get data from database
$payrollPeriods = $payrollManager->getPayrollPeriods();
$employees = $payrollManager->getEmployees();
$allowanceTypes = $payrollManager->getAllowanceTypes();
$deductionTypes = $payrollManager->getDeductionTypes();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    switch ($_POST['action']) {
        case 'process_payroll':
            $result = $payrollManager->processEmployeePayroll(
                $_POST['employee_id'],
                $_POST['period_id'],
                $_POST['working_days'],
                $_POST['days_worked']
            );
            $response = $result;
            break;
    }
    
    echo json_encode($response);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management System</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
       /* Base Styles */
:root {
  --primary-color: #4361ee;
  --secondary-color: #3f37c9;
  --accent-color: #4895ef;
  --dark-color: #1b1e1f;
  --light-color: #f8f9fa;
  --success-color: #4cc9f0;
  --warning-color: #f8961e;
  --danger-color: #f94144;
  --glass-bg: rgba(255, 255, 255, 0.15);
  --glass-border: rgba(255, 255, 255, 0.18);
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  font-family: 'Segoe UI', 'Roboto', sans-serif;
}

body {
  background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
  color: var(--light-color);
  min-height: 100vh;
  line-height: 1.6;
}

/* Glassmorphism Effect */
.glass {
  background: var(--glass-bg);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-radius: 12px;
  border: 1px solid var(--glass-border);
  box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.36);
}

/* Layout */
.payroll-container {
  display: flex;
  min-height: 100vh;
}

.sidebar {
  width: 280px;
  padding: 20px;
  background: rgba(27, 30, 31, 0.7);
  backdrop-filter: blur(10px);
  border-right: 1px solid rgba(255, 255, 255, 0.1);
  transition: all 0.3s ease;
}

.sidebar-brand {
  padding: 20px 0;
  margin-bottom: 30px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar-brand h1 {
  font-size: 1.5rem;
  font-weight: 700;
  color: white;
  margin-bottom: 5px;
}

.sidebar-brand p {
  font-size: 0.8rem;
  color: rgba(255, 255, 255, 0.7);
}

.nav ul {
  list-style: none;
}

.nav li {
  margin-bottom: 10px;
}

.nav a {
  display: flex;
  align-items: center;
  padding: 12px 15px;
  color: rgba(255, 255, 255, 0.8);
  text-decoration: none;
  border-radius: 8px;
  transition: all 0.3s ease;
}

.nav a i {
  margin-right: 10px;
  width: 20px;
  text-align: center;
}

.nav a:hover {
  background: rgba(67, 97, 238, 0.2);
  color: white;
  transform: translateX(5px);
}

.nav a.active {
  background: var(--primary-color);
  color: white;
  box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
}

.main-content {
  flex: 1;
  padding: 30px;
  overflow-y: auto;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
  padding-bottom: 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.header h1 {
  font-size: 1.8rem;
  font-weight: 600;
  display: flex;
  align-items: center;
}

.header h1 i {
  margin-right: 15px;
  color: var(--accent-color);
}

.user-info {
  display: flex;
  align-items: center;
  gap: 15px;
}

.user-info span {
  font-size: 0.9rem;
}

.badge {
  padding: 5px 10px;
  border-radius: 20px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
}

.badge-info {
  background: rgba(72, 149, 239, 0.2);
  color: var(--accent-color);
  border: 1px solid var(--accent-color);
}

/* Cards */
.card {
  composes: glass;
  margin-bottom: 25px;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.card:hover {
  transform: translateY(-5px);
  box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.4);
}

.card-header {
  padding: 18px 25px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.card-header h2, .card-header h3 {
  font-size: 1.2rem;
  font-weight: 600;
  display: flex;
  align-items: center;
}

.card-header h2 i, .card-header h3 i {
  margin-right: 10px;
  color: var(--accent-color);
}

.card-body {
  padding: 25px;
}

/* Stats Cards */
.dashboard-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.stat-card {
  composes: glass;
  padding: 20px;
  display: flex;
  align-items: center;
  transition: all 0.3s ease;
  cursor: pointer;
}

.stat-card:hover {
  background: rgba(67, 97, 238, 0.3);
  transform: translateY(-3px);
}

.stat-icon {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  margin-right: 15px;
  flex-shrink: 0;
  background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
  box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
}

.stat-content {
  flex: 1;
}

.stat-value {
  font-size: 1.8rem;
  font-weight: 700;
  margin-bottom: 5px;
  background: linear-gradient(to right, white, #e0e0e0);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.stat-label {
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.9rem;
}

/* Forms */
.form-group {
  margin-bottom: 20px;
}

.form-control {
  width: 100%;
  padding: 12px 18px;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  color: white;
  font-size: 1rem;
  transition: all 0.3s ease;
}

.form-control:focus {
  outline: none;
  border-color: var(--accent-color);
  box-shadow: 0 0 0 3px rgba(72, 149, 239, 0.3);
  background: rgba(255, 255, 255, 0.15);
}

.form-control::placeholder {
  color: rgba(255, 255, 255, 0.5);
}

/* Buttons */
.btn {
  padding: 12px 24px;
  border: none;
  border-radius: 8px;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.3s ease;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
  color: white;
  box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(67, 97, 238, 0.6);
}

.btn-success {
  background: linear-gradient(135deg, #4cc9f0, #4895ef);
  color: white;
  box-shadow: 0 4px 15px rgba(76, 201, 240, 0.4);
}

.btn-success:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(76, 201, 240, 0.6);
}

.btn-sm {
  padding: 8px 16px;
  font-size: 0.875rem;
}

/* Tables */
.table {
  width: 100%;
  border-collapse: collapse;
}

.table th {
  padding: 15px;
  text-align: left;
  font-weight: 600;
  color: var(--accent-color);
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(27, 30, 31, 0.5);
}

.table td {
  padding: 15px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.table tr:last-child td {
  border-bottom: none;
}

.table tr:hover td {
  background: rgba(67, 97, 238, 0.1);
}

/* Notifications */
.notification {
  position: fixed;
  top: 20px;
  right: 20px;
  padding: 15px 20px;
  border-radius: 8px;
  color: white;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  z-index: 1000;
  max-width: 350px;
  transform: translateX(120%);
  transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.1);
}

.notification.show {
  transform: translateX(0);
}

.notification-success {
  background: rgba(76, 201, 240, 0.2);
  border-left: 4px solid var(--success-color);
}

.notification-error {
  background: rgba(249, 65, 68, 0.2);
  border-left: 4px solid var(--danger-color);
}

.notification-close {
  background: none;
  border: none;
  color: white;
  font-size: 1.2rem;
  cursor: pointer;
  margin-left: 15px;
  opacity: 0.7;
  transition: opacity 0.2s ease;
}

.notification-close:hover {
  opacity: 1;
}

/* Payroll Preview */
.preview-grid, .totals-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 20px;
  margin: 20px 0;
}

.preview-card, .total-item {
  composes: glass;
  padding: 20px;
  transition: all 0.3s ease;
}

.preview-card:hover, .total-item:hover {
  transform: translateY(-5px);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
}

.highlight {
  background: rgba(67, 97, 238, 0.2);
  border-left: 3px solid var(--primary-color);
}

.detail-row {
  display: flex;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.detail-row:last-child {
  border-bottom: none;
}

/* Alerts */
.alert {
  padding: 15px 20px;
  margin-bottom: 20px;
  border-radius: 8px;
  border-left: 4px solid;
}

.alert-success {
  background: rgba(76, 201, 240, 0.15);
  border-color: var(--success-color);
  color: rgba(255, 255, 255, 0.9);
}

.alert-danger {
  background: rgba(249, 65, 68, 0.15);
  border-color: var(--danger-color);
  color: rgba(255, 255, 255, 0.9);
}

.alert-warning {
  background: rgba(248, 150, 30, 0.15);
  border-color: var(--warning-color);
  color: rgba(255, 255, 255, 0.9);
}

/* Responsive */
@media (max-width: 992px) {
  .sidebar {
    width: 80px;
    padding: 15px 10px;
  }
  
  .sidebar-brand h1, 
  .sidebar-brand p,
  .nav a span {
    display: none;
  }
  
  .nav a {
    justify-content: center;
    padding: 15px 0;
  }
  
  .nav a i {
    margin-right: 0;
    font-size: 1.2rem;
  }
}

@media (max-width: 768px) {
  .dashboard-stats {
    grid-template-columns: 1fr;
  }
  
  .header {
    flex-direction: column;
    align-items: flex-start;
    gap: 15px;
  }
  
  .user-info {
    width: 100%;
    justify-content: space-between;
  }
}

/* Animations */
@keyframes float {
  0%, 100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-10px);
  }
}

.floating {
  animation: float 3s ease-in-out infinite;
}
    </style>
</head>
<body>
    <div class="payroll-container">
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
                    <li><a href="payroll.php" class="active"><i class="fas fa-money-check"></i> Payroll</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-money-check"></i> Payroll Management</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary">Logout</a>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= count($employees) ?></div>
                        <div class="stat-label">Total Employees</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-success">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= count($payrollPeriods) ?></div>
                        <div class="stat-label">Payroll Periods</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= count($allowanceTypes) ?></div>
                        <div class="stat-label">Allowance Types</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-info">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= count($deductionTypes) ?></div>
                        <div class="stat-label">Deduction Types</div>
                    </div>
                </div>
            </div>

            <!-- Payroll Processing Section -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-calculator"></i> Process Payroll</h2>
                    <button class="btn btn-sm btn-primary" onclick="calculatePreview()">
                        <i class="fas fa-calculator"></i> Calculate Preview
                    </button>
                </div>
                <div class="card-body">
                    <form id="payroll-process-form">
                        <div class="form-group">
                            <label for="period-select">Payroll Period</label>
                            <select class="form-control" id="period-select" required>
                                <option value="">Select a period</option>
                                <?php foreach ($payrollPeriods as $period): ?>
                                    <option value="<?= $period['id'] ?>">
                                        <?= $period['name'] ?> (<?= date('d M Y', strtotime($period['start_date'])) ?> - <?= date('d M Y', strtotime($period['end_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="employee-select">Employee</label>
                            <select class="form-control" id="employee-select" required>
                                <option value="">Select an employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= $employee['id'] ?>">
                                        <?= $employee['full_name'] ?> (<?= $employee['employee_id'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label for="working-days">Working Days in Period</label>
                                <input type="number" class="form-control" id="working-days" value="22" min="1" max="31" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="days-worked">Days Worked</label>
                                <input type="number" class="form-control" id="days-worked" value="22" min="0" max="31" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-cogs"></i> Process Payroll
                            </button>
                        </div>
                    </form>
                    
                    <!-- Payroll Preview -->
                    <div id="payroll-preview" class="card" style="display: none; margin-top: 20px;">
                        <div class="card-header">
                            <h3><i class="fas fa-eye"></i> Payroll Preview</h3>
                        </div>
                        <div class="card-body" id="preview-content">
                            <!-- Preview content will be loaded here -->
                        </div>
                    </div>
                    
                    <!-- Payroll Results -->
                    <div id="payroll-results" class="card" style="display: none; margin-top: 20px;">
                        <div class="card-header">
                            <h3><i class="fas fa-check-circle"></i> Payroll Results</h3>
                        </div>
                        <div class="card-body" id="results-content">
                            <!-- Results content will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Payroll Periods -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> Recent Payroll Periods</h2>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Period Name</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($payrollPeriods, 0, 5) as $period): ?>
                                <tr>
                                    <td><?= $period['name'] ?></td>
                                    <td><?= date('d M Y', strtotime($period['start_date'])) ?></td>
                                    <td><?= date('d M Y', strtotime($period['end_date'])) ?></td>
                                    <td>
                                        <span class="badge badge-<?= 
                                            $period['status'] === 'draft' ? 'secondary' : 
                                            ($period['status'] === 'processing' ? 'warning' : 
                                            ($period['status'] === 'approved' ? 'success' : 
                                            ($period['status'] === 'paid' ? 'info' : 'primary'))) 
                                        ?>">
                                            <?= ucfirst($period['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Notifications Container -->
    <div id="notification-container"></div>

    <script>
        // Payroll processing functions
        document.getElementById('payroll-process-form').addEventListener('submit', function(e) {
            e.preventDefault();
            processPayroll();
        });

        function processPayroll() {
            const periodId = document.getElementById('period-select').value;
            const employeeId = document.getElementById('employee-select').value;
            const workingDays = document.getElementById('working-days').value;
            const daysWorked = document.getElementById('days-worked').value;
            
            if (!periodId) {
                showNotification('Please select a payroll period', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'process_payroll');
            formData.append('period_id', periodId);
            formData.append('employee_id', employeeId);
            formData.append('working_days', workingDays);
            formData.append('days_worked', daysWorked);
            
            const submitBtn = document.querySelector('#payroll-process-form button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Payroll processed successfully!', 'success');
                    displayPayrollResults(data);
                } else {
                    showNotification('Error: ' + (data.error || 'Failed to process payroll'), 'error');
                }
            })
            .catch(error => {
                console.error('Error processing payroll:', error);
                showNotification('Error processing payroll', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        function calculatePreview() {
            const periodId = document.getElementById('period-select').value;
            const employeeId = document.getElementById('employee-select').value;
            const workingDays = document.getElementById('working-days').value;
            const daysWorked = document.getElementById('days-worked').value;
            
            if (!periodId) {
                showNotification('Please select a payroll period', 'error');
                return;
            }
            
            if (!employeeId) {
                showNotification('Please select an employee', 'error');
                return;
            }
            
            const employeeSelect = document.getElementById('employee-select');
            const employeeName = employeeSelect.options[employeeSelect.selectedIndex].text;
            
            // Get employee's basic salary
            fetch(`get_employee_salary.php?employee_id=${employeeId}`)
                .then(response => response.json())
                .then(salaryData => {
                    if (!salaryData.success) {
                        showNotification('Error: ' + salaryData.error, 'error');
                        return;
                    }
                    
                    const basicSalary = salaryData.basic_salary;
                    
                    // Prorate salary if needed
                    const proratedSalary = daysWorked < workingDays ? 
                        (basicSalary / workingDays) * daysWorked : 
                        basicSalary;
                    
                    // Calculate deductions (simplified for demo)
                    const payeTax = calculatePAYETax(proratedSalary);
                    const nssfDeduction = calculateNSSF(proratedSalary);
                    const nhifDeduction = calculateNHIF(proratedSalary);
                    const housingLevy = calculateHousingLevy(proratedSalary);
                    
                    // Other calculations
                    const allowances = 0; // For demo
                    const otherDeductions = 0; // For demo
                    
                    const grossSalary = proratedSalary + allowances;
                    const totalDeductions = payeTax + nssfDeduction + nhifDeduction + housingLevy + otherDeductions;
                    const netSalary = grossSalary - totalDeductions;
                    
                    // Display preview
                    displayPayrollPreview({
                        employeeName,
                        basicSalary: proratedSalary,
                        grossSalary,
                        totalDeductions,
                        netSalary,
                        payeTax,
                        nssfDeduction,
                        nhifDeduction,
                        housingLevy
                    });
                })
                .catch(error => {
                    console.error('Error fetching salary data:', error);
                    showNotification('Error fetching employee salary', 'error');
                });
        }

        // Kenya governmental deduction calculations
        function calculatePAYETax(taxableIncome) {
            // Simplified PAYE calculation
            const annualIncome = taxableIncome * 12;
            let tax = 0;
            
            if (annualIncome <= 288000) {
                tax = annualIncome * 0.10;
            } else if (annualIncome <= 388000) {
                tax = 28800 + ((annualIncome - 288000) * 0.25);
            } else if (annualIncome <= 6000000) {
                tax = 53800 + ((annualIncome - 388000) * 0.30);
            } else if (annualIncome <= 9600000) {
                tax = 1737400 + ((annualIncome - 6000000) * 0.325);
            } else {
                tax = 2907400 + ((annualIncome - 9600000) * 0.35);
            }
            
            // Apply personal relief
            tax = Math.max(0, tax - 2400);
            return tax / 12;
        }

        function calculateNSSF(basicSalary) {
            // Tier I: 6% of first KES 7,000
            const tierOne = Math.min(basicSalary, 7000) * 0.06;
            
            // Tier II: 6% of amount between 7,001 and 36,000
            let tierTwo = 0;
            if (basicSalary > 7000) {
                const tierTwoBase = Math.min(basicSalary - 7000, 29000);
                tierTwo = tierTwoBase * 0.06;
            }
            
            return tierOne + tierTwo;
        }

        function calculateNHIF(grossSalary) {
            // NHIF rates based on salary bands
            if (grossSalary <= 5999) return 150;
            if (grossSalary <= 7999) return 300;
            if (grossSalary <= 11999) return 400;
            if (grossSalary <= 14999) return 500;
            if (grossSalary <= 19999) return 600;
            if (grossSalary <= 24999) return 750;
            if (grossSalary <= 29999) return 850;
            if (grossSalary <= 34999) return 900;
            if (grossSalary <= 39999) return 950;
            if (grossSalary <= 44999) return 1000;
            if (grossSalary <= 49999) return 1100;
            if (grossSalary <= 59999) return 1200;
            if (grossSalary <= 69999) return 1300;
            if (grossSalary <= 79999) return 1400;
            if (grossSalary <= 89999) return 1500;
            if (grossSalary <= 99999) return 1600;
            return 1700;
        }

        function calculateHousingLevy(basicSalary) {
            const levy = basicSalary * 0.015;
            return Math.min(levy, 2500);
        }

        function displayPayrollPreview(data) {
            const previewDiv = document.getElementById('payroll-preview');
            const contentDiv = document.getElementById('preview-content');
            
            let html = `
                <div class="preview-summary">
                    <h4>Payroll Preview for ${data.employeeName}</h4>
                    <div class="preview-details">
                        <div class="detail-row">
                            <span>Basic Salary:</span>
                            <span>KES ${formatNumber(data.basicSalary)}</span>
                        </div>
                        <div class="detail-row">
                            <span>Gross Salary:</span>
                            <span>KES ${formatNumber(data.grossSalary)}</span>
                        </div>
                        <div class="detail-row">
                            <span>PAYE Tax:</span>
                            <span>KES ${formatNumber(data.payeTax)}</span>
                        </div>
                        <div class="detail-row">
                            <span>NSSF Deduction:</span>
                            <span>KES ${formatNumber(data.nssfDeduction)}</span>
                        </div>
                        <div class="detail-row">
                            <span>NHIF Deduction:</span>
                            <span>KES ${formatNumber(data.nhifDeduction)}</span>
                        </div>
                        <div class="detail-row">
                            <span>Housing Levy:</span>
                            <span>KES ${formatNumber(data.housingLevy)}</span>
                        </div>
                        <div class="detail-row">
                            <span>Total Deductions:</span>
                            <span>KES ${formatNumber(data.totalDeductions)}</span>
                        </div>
                        <div class="detail-row highlight">
                            <span><strong>Net Salary:</strong></span>
                            <span><strong>KES ${formatNumber(data.netSalary)}</strong></span>
                        </div>
                    </div>
                </div>
            `;
            
            contentDiv.innerHTML = html;
            previewDiv.style.display = 'block';
        }

        function displayPayrollResults(data) {
            const resultsDiv = document.getElementById('payroll-results');
            const contentDiv = document.getElementById('results-content');
            
            let html = `
                <div class="results-summary">
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle"></i> Payroll Processed Successfully</h5>
                        <p>Payroll for employee ID ${data.employee_id} has been processed</p>
                    </div>
                    
                    <div class="preview-details">
                        <div class="detail-row">
                            <span>Basic Salary:</span>
                            <span>KES ${formatNumber(data.basic_salary)}</span>
                        </div>
                        <div class="detail-row">
                            <span>Gross Salary:</span>
                            <span>KES ${formatNumber(data.gross_salary)}</span>
                        </div>
                        <div class="detail-row">
                            <span>PAYE Tax:</span>
                            <span>KES ${formatNumber(data.paye_tax)}</span>
                        </div>
                        <div class="detail-row">
                            <span>NSSF Deduction:</span>
                            <span>KES ${formatNumber(data.nssf)}</span>
                        </div>
                        <div class="detail-row">
                            <span>NHIF Deduction:</span>
                            <span>KES ${formatNumber(data.nhif)}</span>
                        </div>
                        <div class="detail-row">
                            <span>Housing Levy:</span>
                            <span>KES ${formatNumber(data.housing_levy)}</span>
                        </div>
                        <div class="detail-row">
                            <span>Total Deductions:</span>
                            <span>KES ${formatNumber(data.total_deductions)}</span>
                        </div>
                        <div class="detail-row highlight">
                            <span><strong>Net Salary:</strong></span>
                            <span><strong>KES ${formatNumber(data.net_salary)}</strong></span>
                        </div>
                    </div>
                </div>
            `;
            
            contentDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }

        // Utility functions
        function formatNumber(num) {
            return Number(num).toLocaleString('en-KE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function showNotification(message, type = 'info') {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div class="notification-content">
                    <span class="notification-message">${message}</span>
                    <button class="notification-close" onclick="this.parentElement.parentElement.remove()">&times;</button>
                </div>
            `;
            
            container.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
    </script>
</body>
</html>