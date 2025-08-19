<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Check if team admin has permission to assign tasks
$perm_stmt = $pdo->prepare("
    SELECT can_assign FROM team_admin_permissions 
    WHERE user_id = ? 
    LIMIT 1
");
$perm_stmt->execute([$_SESSION['user_id']]);
$can_assign = $perm_stmt->fetchColumn();

if (!$can_assign) {
    header('Location: team-admin-dashboard.php?error=no_permission');
    exit;
}

// Get user data for sidebar
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt_user->execute(['id' => $_SESSION['user_id']]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

// Get team admin's permissions
$perm_stmt = $pdo->prepare("
    SELECT * FROM team_admin_permissions 
    WHERE user_id = ? 
    LIMIT 1
");
$perm_stmt->execute([$_SESSION['user_id']]);
$permissions = $perm_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$message = '';
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assigned_to = $_POST['assigned_to'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';
    $deadline = $_POST['deadline'] ?? '';
    $subtasks = $_POST['subtasks'] ?? [];
    
    // Validation
    if (empty($title)) {
        $errors[] = "Task title is required";
    }
    
    if (empty($assigned_to)) {
        $errors[] = "Please assign the task to a team member";
    } else {
        // Verify the assigned user is in team admin's team
        $verify_stmt = $pdo->prepare("
            SELECT u.id FROM users u
            JOIN team_admin_teams tat ON u.team_id = tat.team_id
            WHERE u.id = ? AND tat.team_admin_id = ? AND u.role = 'employee'
        ");
        $verify_stmt->execute([$assigned_to, $_SESSION['user_id']]);
        if (!$verify_stmt->fetchColumn()) {
            $errors[] = "You can only assign tasks to your team members";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Get team_id from the assigned user
            $team_stmt = $pdo->prepare("SELECT team_id FROM users WHERE id = ?");
            $team_stmt->execute([$assigned_to]);
            $team_id = $team_stmt->fetchColumn();
            
            // Insert task
            $stmt = $pdo->prepare("
                INSERT INTO tasks (title, description, assigned_to, created_by, priority, deadline, team_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'to_do')
            ");
            $stmt->execute([
                $title, 
                $description, 
                $assigned_to, 
                $_SESSION['user_id'], 
                $priority, 
                $deadline ?: null,
                $team_id
            ]);
            
            $task_id = $pdo->lastInsertId();
            
            // Insert subtasks if any
            if (!empty($subtasks)) {
                $subtask_stmt = $pdo->prepare("
                    INSERT INTO subtasks (task_id, title, status) 
                    VALUES (?, ?, 'pending')
                ");
                
                foreach ($subtasks as $subtask) {
                    $subtask = trim($subtask);
                    if (!empty($subtask)) {
                        $subtask_stmt->execute([$task_id, $subtask]);
                    }
                }
            }
            
            // Log activity
            $log_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action_type, details, task_id) 
                VALUES (?, 'task_created', ?, ?)
            ");
            $log_stmt->execute([
                $_SESSION['user_id'], 
                "Team Admin created and assigned task to team member",
                $task_id
            ]);
            
            $pdo->commit();
            $message = "Task created and assigned successfully!";
            
            // Reset form
            $title = $description = $assigned_to = $deadline = '';
            $priority = 'medium';
            $subtasks = [];
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Error creating task: " . $e->getMessage();
        }
    }
}

// Get team members for assignment dropdown - ONLY team admin's team members
$team_members_stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email 
    FROM users u
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    WHERE tat.team_admin_id = ? AND u.role = 'employee' AND u.status = 'active'
    ORDER BY u.full_name
");
$team_members_stmt->execute([$_SESSION['user_id']]);
$team_members = $team_members_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Task - Team Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-main);
            line-height: 1.6;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-lg);
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
            color: #4ecdc4;
            font-size: 0.9rem;
            font-weight: 500;
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
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4), inset 0 2px 4px rgba(255, 255, 255, 0.2);
            border: 3px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .user-avatar::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg);
            animation: avatarShine 3s ease-in-out infinite;
        }
        
        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.6), inset 0 2px 4px rgba(255, 255, 255, 0.3);
        }
        
        @keyframes avatarShine {
            0%, 100% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            50% { transform: translateX(100%) translateY(100%) rotate(45deg); }
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
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
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

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
        }

        .page-header {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .form-container {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            max-width: 800px;
        }

        .form-grid {
            display: grid;
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .subtasks-container {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            background: var(--bg-secondary);
        }

        .subtask-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .subtask-item input {
            flex: 1;
            margin-bottom: 0;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
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
                <div class="user-role">Team Admin</div>
            </div>
        </div>
        
        <div class="sidebar-heading">Main</div>
        <ul class="sidebar-menu">
            <li><a href="team-admin-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="team-admin-tasks.php"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="team-admin-add-task.php" class="active"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="team-admin-team.php"><i class="fas fa-users"></i> My Team</a></li>
            <?php if (!empty($permissions['can_view_reports'])): ?>
            <li><a href="team-admin-analysis.php"><i class="fas fa-chart-line"></i> Analysis</a></li>
            <?php endif; ?>
            <?php if (!empty($permissions['can_send_messages'])): ?>
            <li><a href="team-admin-messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Add New Task</h1>
            <p class="page-subtitle">Create and assign a new task to your team members</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($team_members)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                You don't have any team members to assign tasks to. Please contact your administrator to add team members.
            </div>
        <?php else: ?>

        <div class="form-container">
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="title"><i class="fas fa-heading"></i> Task Title</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($title ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="description"><i class="fas fa-align-left"></i> Description</label>
                        <textarea name="description" id="description" placeholder="Describe the task details, requirements, and expectations..."><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="assigned_to"><i class="fas fa-user"></i> Assign To</label>
                            <select name="assigned_to" id="assigned_to" required>
                                <option value="">Select Team Member</option>
                                <?php foreach ($team_members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>" <?php echo (isset($assigned_to) && $assigned_to == $member['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['full_name']); ?> (<?php echo htmlspecialchars($member['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="priority"><i class="fas fa-flag"></i> Priority</label>
                            <select name="priority" id="priority">
                                <option value="low" <?php echo (isset($priority) && $priority === 'low') ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo (!isset($priority) || $priority === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo (isset($priority) && $priority === 'high') ? 'selected' : ''; ?>>High</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="deadline"><i class="fas fa-calendar"></i> Due Date (Optional)</label>
                        <input type="date" name="deadline" id="deadline" value="<?php echo htmlspecialchars($deadline ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-list"></i> Subtasks (Optional)</label>
                        <div class="subtasks-container">
                            <div id="subtasks-list">
                                <?php if (!empty($subtasks)): ?>
                                    <?php foreach ($subtasks as $index => $subtask): ?>
                                        <div class="subtask-item">
                                            <input type="text" name="subtasks[]" placeholder="Enter subtask..." value="<?php echo htmlspecialchars($subtask); ?>">
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeSubtask(this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="subtask-item">
                                        <input type="text" name="subtasks[]" placeholder="Enter subtask...">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeSubtask(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addSubtask()" style="margin-top: 0.5rem;">
                                <i class="fas fa-plus"></i> Add Subtask
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="team-admin-tasks.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Create Task
                    </button>
                </div>
            </form>
        </div>

        <?php endif; ?>
    </div>

    <script>
        function addSubtask() {
            const subtasksList = document.getElementById('subtasks-list');
            const subtaskItem = document.createElement('div');
            subtaskItem.className = 'subtask-item';
            subtaskItem.innerHTML = `
                <input type="text" name="subtasks[]" placeholder="Enter subtask...">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeSubtask(this)">
                    <i class="fas fa-times"></i>
                </button>
            `;
            subtasksList.appendChild(subtaskItem);
        }

        function removeSubtask(button) {
            const subtasksList = document.getElementById('subtasks-list');
            if (subtasksList.children.length > 1) {
                button.parentElement.remove();
            }
        }

        // Set minimum date to today
        document.getElementById('deadline').min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>
