<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
$conn = getConnection();

function hasPermission($role) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }

    return $_SESSION['user_role'] === $role || 
           (is_array($_SESSION['user_role']) && in_array($role, $_SESSION['user_role']));
}

$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];

function setFlashMessage($message, $type = 'success') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $message = $_SESSION['flash'];
        unset($_SESSION['flash']); // Clear after showing
        return $message;
    }
    return null;
}

function redirectWithMessage($location, $message, $type = 'info') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header("Location: {$location}");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add_department' && (hasPermission('hr_manager') || hasPermission('super_admin'))) {
            $name = sanitizeInput($_POST['name']);
            $description = sanitizeInput($_POST['description']);
            
            try {
                $stmt = $conn->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
                $stmt->execute();
                $stmt->close();
                redirectWithMessage('departments.php', 'Department added successfully!', 'success');
            } catch (Exception $e) {
                $error = 'Error adding department: ' . $e->getMessage();
            }
        } elseif ($action === 'edit_department' && (hasPermission('hr_manager') || hasPermission('super_admin'))) {
            $id = (int)$_POST['id'];
            $name = sanitizeInput($_POST['name']);
            $description = sanitizeInput($_POST['description']);
            
            try {
                $stmt = $conn->prepare("UPDATE departments SET name=?, description=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("ssi", $name, $description, $id);
                $stmt->execute();
                $stmt->close();
                redirectWithMessage('departments.php', 'Department updated successfully!', 'success');
            } catch (Exception $e) {
                $error = 'Error updating department: ' . $e->getMessage();
            }
        } elseif ($action === 'add_section' && (hasPermission('hr_manager') || hasPermission('super_admin'))) {
            $name = sanitizeInput($_POST['section_name']);
            $description = sanitizeInput($_POST['section_description']);
            $department_id = (int)$_POST['department_id'];
            
            try {
                $stmt = $conn->prepare("INSERT INTO sections (name, description, department_id) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $name, $description, $department_id);
                $stmt->execute();
                $stmt->close();
                redirectWithMessage('departments.php', 'Section added successfully!', 'success');
            } catch (Exception $e) {
                $error = 'Error adding section: ' . $e->getMessage();
            }
        } elseif ($action === 'edit_section' && (hasPermission('hr_manager') || hasPermission('super_admin'))) {
            $id = (int)$_POST['section_id'];
            $name = sanitizeInput($_POST['section_name']);
            $description = sanitizeInput($_POST['section_description']);
            $department_id = (int)$_POST['department_id'];
            
            try {
                $stmt = $conn->prepare("UPDATE sections SET name=?, description=?, department_id=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("ssii", $name, $description, $department_id, $id);
                $stmt->execute();
                $stmt->close();
                redirectWithMessage('departments.php', 'Section updated successfully!', 'success');
            } catch (Exception $e) {
                $error = 'Error updating section: ' . $e->getMessage();
            }
        } elseif ($action === 'delete_department' && (hasPermission('hr_manager')|| hasPermission('super_admin'))) {
            $id = (int)$_POST['id'];
            
            try {
                // Check if department has employees
                $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE department_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->bind_result($employeeCount);
                $stmt->fetch();
                $stmt->close();
                
                if ($employeeCount > 0) {
                    $error = 'Cannot delete department: It has ' . $employeeCount . ' employees assigned to it.';
                } else {
                    // Delete sections first
                    $stmt = $conn->prepare("DELETE FROM sections WHERE department_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Delete department
                    $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    redirectWithMessage('departments.php', 'Department deleted successfully!', 'success');
                }
            } catch (Exception $e) {
                $error = 'Error deleting department: ' . $e->getMessage();
            }
        } elseif ($action === 'delete_section' && (hasPermission('hr_manager')|| hasPermission('super_admin'))) {
            $id = (int)$_POST['id'];
            
            try {
                // Check if section has employees - Fixed query to check sections table
                $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE section_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->bind_result($employeeCount);
                $stmt->fetch();
                $stmt->close();
                
                if ($employeeCount > 0) {
                    $error = 'Cannot delete section: It has ' . $employeeCount . ' employees assigned to it.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM sections WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    redirectWithMessage('departments.php', 'Section deleted successfully!', 'success');
                }
            } catch (Exception $e) {
                $error = 'Error deleting section: ' . $e->getMessage();
            }
        }
    }
}

// Get departments and sections with proper error handling
try {
    $departments_result = $conn->query("SELECT * FROM departments ORDER BY name");
    $departments = $departments_result ? $departments_result->fetch_all(MYSQLI_ASSOC) : [];
    
    $sections_result = $conn->query("SELECT s.*, d.name as department_name FROM sections s LEFT JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name");
    $sections = $sections_result ? $sections_result->fetch_all(MYSQLI_ASSOC) : [];
} catch (Exception $e) {
    $departments = [];
    $sections = [];
    $error = 'Error fetching data: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - HR Management System</title>
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
            <nav class="nav">
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="employees.php">Employees</a></li>
                    <?php if (hasPermission('hr_manager') || hasPermission('super_admin')): ?>
                    <li><a href="departments.php" class="active">Departments</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('super_admin')|| hasPermission('hr_manage')): ?>
                    <li><a href="admin.php">Admin</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')): ?>
                    <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')||hasPermission('dept_head')): ?>
                    <li><a href="leave_management.php">Leave Management</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Department Management</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                    <span class="badge badge-info"><?php echo ucwords(str_replace('_', ' ', $user['role'])); ?></span>
                    <a href="logout.php" class="btn btn-secondary">Logout</a>
                </div>
            </div>
            
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <!-- Departments Section -->
                <div style="margin-bottom: 40px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2>Departments (<?php echo count($departments); ?>)</h2>
                        <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')): ?>
                            <button onclick="showAddDepartmentModal()" class="btn btn-success">Add New Department</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Sections</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($departments)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No departments found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($departments as $department): ?>
                                    <?php 
                                        $dept_sections = array_filter($sections, function($s) use ($department) {
                                            return $s['department_id'] == $department['id'];
                                        });
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($department['id']); ?></td>
                                        <td><?php echo htmlspecialchars($department['name']); ?></td>
                                        <td><?php echo htmlspecialchars($department['description'] ?? 'N/A'); ?></td>
                                        <td>
                                            <button onclick="showSections(<?php echo $department['id']; ?>)" class="btn btn-sm btn-info">
                                                View (<?php echo count($dept_sections); ?>)
                                            </button>
                                        </td>
                                        <td>
                                            <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')): ?>
                                                <button onclick="showEditDepartmentModal(<?php echo htmlspecialchars(json_encode($department)); ?>)" class="btn btn-sm btn-primary">Edit</button>
                                                <button onclick="confirmDeleteDepartment(<?php echo $department['id']; ?>, '<?php echo htmlspecialchars($department['name']); ?>')" class="btn btn-sm btn-danger ml-1">Delete</button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sections Section -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2>Sections (<?php echo count($sections); ?>)</h2>
                        <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')): ?>
                            <button onclick="showAddSectionModal()" class="btn btn-success">Add New Section</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Section Name</th>
                                    <th>Department</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sections)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No sections found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sections as $section): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($section['id']); ?></td>
                                        <td><?php echo htmlspecialchars($section['name']); ?></td>
                                        <td><?php echo htmlspecialchars($section['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($section['description'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')): ?>
                                                <button onclick="showEditSectionModal(<?php echo htmlspecialchars(json_encode($section)); ?>)" class="btn btn-sm btn-primary">Edit</button>
                                                <button onclick="confirmDeleteSection(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')" class="btn btn-sm btn-danger ml-1">Delete</button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
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

    <!-- Add Department Modal -->
    <?php if (hasPermission('hr_manager')|| hasPermission('super_admin')): ?>
    <div id="addDepartmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Department</h3>
                <span class="close" onclick="hideAddDepartmentModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_department">
                
                <div class="form-group">
                    <label for="dept_name">Department Name</label>
                    <input type="text" class="form-control" id="dept_name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="dept_description">Description</label>
                    <textarea class="form-control" id="dept_description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Add Department</button>
                    <button type="button" class="btn btn-secondary" onclick="hideAddDepartmentModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div id="editDepartmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Department</h3>
                <span class="close" onclick="hideEditDepartmentModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_department">
                <input type="hidden" id="edit_dept_id" name="id">
                
                <div class="form-group">
                    <label for="edit_dept_name">Department Name</label>
                    <input type="text" class="form-control" id="edit_dept_name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_dept_description">Description</label>
                    <textarea class="form-control" id="edit_dept_description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update Department</button>
                    <button type="button" class="btn btn-secondary" onclick="hideEditDepartmentModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Section Modal -->
    <div id="addSectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Section</h3>
                <span class="close" onclick="hideAddSectionModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_section">
                
                <div class="form-group">
                    <label for="section_department_id">Department</label>
                    <select class="form-control" id="section_department_id" name="department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="section_name">Section Name</label>
                    <input type="text" class="form-control" id="section_name" name="section_name" required>
                </div>
                
                <div class="form-group">
                    <label for="section_description">Description</label>
                    <textarea class="form-control" id="section_description" name="section_description" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Add Section</button>
                    <button type="button" class="btn btn-secondary" onclick="hideAddSectionModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div id="editSectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Section</h3>
                <span class="close" onclick="hideEditSectionModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_section">
                <input type="hidden" id="edit_section_id" name="section_id">
                
                <div class="form-group">
                    <label for="edit_section_department_id">Department</label>
                    <select class="form-control" id="edit_section_department_id" name="department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_section_name">Section Name</label>
                    <input type="text" class="form-control" id="edit_section_name" name="section_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_section_description">Description</label>
                    <textarea class="form-control" id="edit_section_description" name="section_description" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update Section</button>
                    <button type="button" class="btn btn-secondary" onclick="hideEditSectionModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Department Modal Functions
        function showAddDepartmentModal() {
            document.getElementById('addDepartmentModal').style.display = 'block';
        }
        
        function hideAddDepartmentModal() {
            document.getElementById('addDepartmentModal').style.display = 'none';
        }
        
        function showEditDepartmentModal(department) {
            document.getElementById('edit_dept_id').value = department.id;
            document.getElementById('edit_dept_name').value = department.name;
            document.getElementById('edit_dept_description').value = department.description || '';
            document.getElementById('editDepartmentModal').style.display = 'block';
        }
        
        function hideEditDepartmentModal() {
            document.getElementById('editDepartmentModal').style.display = 'none';
        }
        
        // Section Modal Functions
        function showAddSectionModal() {
            document.getElementById('addSectionModal').style.display = 'block';
        }
        
        function hideAddSectionModal() {
            document.getElementById('addSectionModal').style.display = 'none';
        }
        
        function showEditSectionModal(section) {
            document.getElementById('edit_section_id').value = section.id;
            document.getElementById('edit_section_name').value = section.name;
            document.getElementById('edit_section_description').value = section.description || '';
            document.getElementById('edit_section_department_id').value = section.department_id;
            document.getElementById('editSectionModal').style.display = 'block';
        }
        
        function hideEditSectionModal() {
            document.getElementById('editSectionModal').style.display = 'none';
        }
        
        function showSections(departmentId) {
            // Scroll to sections table and highlight sections for this department
            const sectionsTable = document.querySelector('.table tbody');
            const rows = sectionsTable.querySelectorAll('tr');
            
            // Remove any existing highlights
            rows.forEach(row => row.classList.remove('highlight'));
            
            // Highlight matching sections
            const sections = <?php echo json_encode($sections); ?>;
            sections.forEach((section, index) => {
                if (section.department_id == departmentId) {
                    const row = rows[index];
                    if (row) {
                        row.classList.add('highlight');
                        setTimeout(() => row.classList.remove('highlight'), 3000);
                    }
                }
            });
            
            // Scroll to sections table
            document.querySelector('h2:last-of-type').scrollIntoView({ behavior: 'smooth' });
        }
        
        function confirmDeleteDepartment(id, name) {
            if (confirm('Are you sure you want to delete the department "' + name + '"?\n\nThis will also delete all sections in this department.\nThis action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_department"><input type="hidden" name="id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function confirmDeleteSection(id, name) {
            if (confirm('Are you sure you want to delete the section "' + name + '"?\n\nThis action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_section"><input type="hidden" name="id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addDepartmentModal', 'editDepartmentModal', 'addSectionModal', 'editSectionModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>

    <style>
        .highlight {
            background-color: #fff3cd !important;
            transition: background-color 0.3s ease;
        }
        .ml-1 {
            margin-left: 5px;
        }
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
    </style>
</body>
</html>