<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Get user data for sidebar
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt_user->execute(['id' => $_SESSION['user_id']]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

$message = '';



// Get teams for dropdown
$teams = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM teams ORDER BY name");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading teams: " . $e->getMessage();
}

// Get team admins for dropdown (only users with team_admin role)
$team_admins = [];
try {
    $stmt = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'team_admin' AND status = 'active' ORDER BY full_name");
    $team_admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading team admins: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form inputs safely
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? 'active';
    $team_id = $_POST['team_id'] ?? null;
    $parent_admin_id = $_POST['parent_admin_id'] ?? null;

    // Validation
    $errors = [];
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (empty($full_name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($role)) {
        $errors[] = "Role is required";
    } elseif (!in_array($role, ['admin', 'team_admin', 'employee'])) {
        $errors[] = "Invalid role selected";
    }
    
    // Validate team assignment if provided
    if (!empty($team_id)) {
        // Verify team exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = "Selected team does not exist";
        }
    } else {
        // Set team_id to NULL if not provided
        $team_id = null;
    }
    
    // Validate parent_admin_id if provided
    if (!empty($parent_admin_id)) {
        // Verify the parent admin exists and is a team_admin
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'team_admin' AND status = 'active'");
        $stmt->execute([$parent_admin_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = "Selected team admin does not exist or is not active";
        }
    } else {
        // Set parent_admin_id to NULL if not provided
        $parent_admin_id = null;
    }
    

    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $emailExists = $stmt->fetchColumn();
    
    if ($emailExists) {
        $errors[] = "Email already exists";
    }
    
    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        

        
        // Insert user into database
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, full_name, role, status, team_id, parent_admin_id) 
            VALUES (:email, :password, :full_name, :role, :status, :team_id, :parent_admin_id)
        ");
        
        try {
            $stmt->execute([
                'email' => $email,
                'password' => $hashed_password,
                'full_name' => $full_name,
                'role' => $role,
                'status' => $status,
                'team_id' => $team_id,
                'parent_admin_id' => $parent_admin_id,

            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // If creating a team admin, set up their permissions
            if ($role === 'team_admin' && !empty($team_id)) {
                $perm_stmt = $pdo->prepare("
                    INSERT INTO team_admin_permissions 
                    (user_id, team_id, can_assign, can_edit, can_archive, can_add_members, can_view_reports, can_send_messages) 
                    VALUES (:user_id, :team_id, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE)
                ");
                $perm_stmt->execute([
                    'user_id' => $user_id,
                    'team_id' => $team_id
                ]);
            }
            
            // Set success message in session and redirect to manage-users.php
            $_SESSION['success_message'] = "User added successfully!";
            header('Location: manage-users.php');
            exit;
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
        }
    } else {
        $message = "Please fix the following errors:<br>" . implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New User</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #6a0dad;
            --primary-dark: #4a0080;
            --primary-light: #8e24aa;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --secondary: #858796;
            --light: #333333;
            --dark: #e0e0e0;
            --bg-main: #121212;
            --bg-card: #1e1e1e;
            --bg-secondary: #2a2a2a;
            --text-main: #e0e0e0;
            --text-secondary: #bbbbbb;
            --border-color: #333333;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: var(--bg-main);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        

        
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            transition: all 0.3s;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .sidebar-header {
            padding: 2rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
        }
        
        .logo-section {
            margin-bottom: 1.5rem;
        }
        
        .logo-section h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            letter-spacing: 2px;
        }
        
        .logo-section .tagline {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 0.25rem;
        }
        
        .user-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 1rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .user-avatar {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.8rem;
            color: white;
            font-weight: 900;
            text-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4), inset 0 2px 4px rgba(255, 255, 255, 0.2);
            border: 3px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .user-avatar::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
            animation: avatarShine 3s ease-in-out infinite;
        }
        
        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 48px rgba(102, 126, 234, 0.6), inset 0 3px 6px rgba(255, 255, 255, 0.3);
        }
        
        @keyframes avatarShine {
            0% { left: -100%; }
            50% { left: 100%; }
            100% { left: -100%; }
        }
        
        .user-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.5rem;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }
        
        .user-role {
            font-size: 0.8rem;
            color: #4ecdc4;
            background: rgba(78, 205, 196, 0.2);
            padding: 0.4rem 1rem;
            border-radius: 20px;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
            border: 1px solid rgba(78, 205, 196, 0.3);
        }
        
        .sidebar-heading {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05rem;
            padding: 1rem;
            color: rgba(255, 255, 255, 0.6);
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-menu a {
            display: block;
            padding: 0.8rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.2s;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            font-weight: 600;
        }
        
        .sidebar-menu i {
            margin-right: 0.5rem;
            width: 1.5rem;
            text-align: center;
        }
        
        .content {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            opacity: 1;
            transition: opacity 0.3s ease-in;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .page-title {
            color: var(--dark);
            font-size: 1.75rem;
            font-weight: 500;
        }
        
        .error {
            color: var(--danger);
            background-color: rgba(231, 74, 59, 0.1);
            border-left: 4px solid var(--danger);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.35rem;
        }
        
        .success {
            color: var(--success);
            background-color: rgba(28, 200, 138, 0.1);
            border-left: 4px solid var(--success);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.35rem;
        }
        
        .card {
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            color: var(--primary-light);
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        label {
            font-weight: 600;
            color: var(--text-main);
            display: block;
        }
        
        input[type="text"], 
        input[type="email"], 
        input[type="password"], 
        select {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            color: var(--text-main);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus, 
        input[type="email"]:focus, 
        input[type="password"]:focus, 
        select:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 1.25rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 0.35rem;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #19b67d;
        }
        
        .password-info {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        
        .action-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            gap: 1rem;
        }
        
        @media (max-width: 992px) {
            body {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                min-height: auto;
            }
            
            .content {
                padding: 1rem;
            }
        }
        
        .form-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
            font-style: italic;
        }
        
        #team-selection {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg-secondary);
            border-radius: 8px;
            border-left: 3px solid var(--primary);
        }
        
        #team-selection label {
            color: var(--primary-light);
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-section">
                <h1>TSM</h1>
                <div class="tagline">Task Management</div>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <?php 
                    $name_parts = explode(' ', $user['full_name']);
                    $initials = strtoupper(substr($name_parts[0], 0, 1));
                    if (count($name_parts) > 1) {
                        $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
                    }
                    echo $initials;
                    ?>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></div>
            </div>
        </div>
        <div class="sidebar-heading">Main</div>
        <ul class="sidebar-menu">
            <li><a href="admin-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php" class="page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="manage-teams.php" class="page-link"><i class="fas fa-users-cog"></i> Manage Teams</a></li>
            <li><a href="manage-users.php" class="page-link"><i class="fas fa-users"></i> Manage Users</a></li>
            <li><a href="analysis.php" class="page-link"><i class="fas fa-chart-line"></i> Analysis</a></li>
            <li><a href="messages.php" class="page-link"><i class="fas fa-envelope"></i> Messages</a></li>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="content">
        <div class="header">
            <h1 class="page-title">Add New User</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="<?php echo strpos($message, 'Error') !== false || strpos($message, 'Please fix') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-user-plus"></i> User Information</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="add-user.php">
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" name="email" id="email" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password</label>
                        <input type="password" name="password" id="password" required>
                        <span class="password-info">Password must be at least 6 characters</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-check-circle"></i> Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="full_name"><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" name="full_name" id="full_name" value="<?php echo isset($full_name) ? htmlspecialchars($full_name) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="role"><i class="fas fa-user-tag"></i> Role</label>
                        <select name="role" id="role" required onchange="toggleTeamSelection()">
                            <option value="">Select Role</option>
                            <option value="admin" <?php echo (isset($role) && $role === 'admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="team_admin" <?php echo (isset($role) && $role === 'team_admin') ? 'selected' : ''; ?>>Team Admin</option>
                            <option value="employee" <?php echo (isset($role) && $role === 'employee') ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="team-selection" style="display: none;">
                        <label for="team_id"><i class="fas fa-users"></i> Team</label>
                        <select name="team_id" id="team_id">
                            <option value="">Select Team</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?php echo $team['id']; ?>" <?php echo (isset($team_id) && $team_id == $team['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($team['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">Optional - Leave blank if user doesn't belong to a specific team</small>
                    </div>
                    
                    <div class="form-group" id="team-admin-selection" style="display: none;">
                        <label for="parent_admin_id"><i class="fas fa-user-shield"></i> Assign to Team Admin</label>
                        <select name="parent_admin_id" id="parent_admin_id">
                            <option value="">Select Team Admin (Optional)</option>
                            <?php foreach ($team_admins as $admin): ?>
                                <option value="<?php echo $admin['id']; ?>" <?php echo (isset($parent_admin_id) && $parent_admin_id == $admin['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($admin['full_name']); ?> (<?php echo htmlspecialchars($admin['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">For employees: Select which Team Admin will supervise this user</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="status"><i class="fas fa-toggle-on"></i> Status</label>
                        <select name="status" id="status" required>
                            <option value="active" <?php echo (!isset($status) || $status === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo (isset($status) && $status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="manage-users.php" class="btn page-link"><i class="fas fa-arrow-left"></i> Back to Users</a>
                        <button type="submit" class="btn btn-success"><i class="fas fa-user-plus"></i> Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
        function toggleTeamSelection() {
            const roleSelect = document.getElementById('role');
            const teamSelection = document.getElementById('team-selection');
            const teamAdminSelection = document.getElementById('team-admin-selection');
            const teamSelect = document.getElementById('team_id');
            const adminSelect = document.getElementById('parent_admin_id');
            
            if (roleSelect.value) {
                // Show team selection for all roles as it's now optional
                teamSelection.style.display = 'block';
                teamSelect.required = false; // Team selection is always optional
                
                // Show team admin selection only for employees
                if (roleSelect.value === 'employee') {
                    teamAdminSelection.style.display = 'block';
                    adminSelect.required = false; // Team admin selection is optional
                } else {
                    teamAdminSelection.style.display = 'none';
                    adminSelect.required = false;
                    adminSelect.value = '';
                }
            } else {
                teamSelection.style.display = 'none';
                teamAdminSelection.style.display = 'none';
                teamSelect.required = false;
                adminSelect.required = false;
                teamSelect.value = '';
                adminSelect.value = '';
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleTeamSelection();
        });
    </script>
</body>
</html> 