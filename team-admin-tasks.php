<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

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

// Handle task status updates (only if team admin has edit permission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status' && !empty($permissions['can_edit'])) {
        $task_id = $_POST['task_id'];
        $new_status = $_POST['status'];
        
        // Verify this task belongs to team admin's team member
        $verify_stmt = $pdo->prepare("
            SELECT t.id FROM tasks t
            JOIN users u ON t.assigned_to = u.id
            JOIN team_admin_teams tat ON u.team_id = tat.team_id
            WHERE t.id = ? AND tat.team_admin_id = ?
        ");
        $verify_stmt->execute([$task_id, $_SESSION['user_id']]);
        
        if ($verify_stmt->fetchColumn()) {
            try {
                $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $task_id]);
                
                // Log activity
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action_type, details, task_id) 
                    VALUES (?, 'status_update', ?, ?)
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Team Admin updated task status to {$new_status}",
                    $task_id
                ]);
                
                $success_message = "Task status updated successfully!";
            } catch (PDOException $e) {
                $error_message = "Error updating task: " . $e->getMessage();
            }
        }
    }
    
    // Handle task approval (team admin can approve completed tasks)
    if ($_POST['action'] === 'approve_task' && !empty($permissions['can_edit'])) {
        $task_id = $_POST['task_id'];
        
        // Verify this task belongs to team admin's team member and needs approval
        $verify_stmt = $pdo->prepare("
            SELECT t.id FROM tasks t
            JOIN users u ON t.assigned_to = u.id
            JOIN team_admin_teams tat ON u.team_id = tat.team_id
            WHERE t.id = ? AND tat.team_admin_id = ? AND t.status = 'needs_approval'
        ");
        $verify_stmt->execute([$task_id, $_SESSION['user_id']]);
        
        if ($verify_stmt->fetchColumn()) {
            try {
                $stmt = $pdo->prepare("UPDATE tasks SET status = 'completed' WHERE id = ?");
                $stmt->execute([$task_id]);
                
                // Log activity
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action_type, details, task_id) 
                    VALUES (?, 'task_approved', ?, ?)
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Team Admin approved completed task",
                    $task_id
                ]);
                
                $success_message = "Task approved successfully!";
            } catch (PDOException $e) {
                $error_message = "Error approving task: " . $e->getMessage();
            }
        }
    }
    
    // Handle task archiving (only if team admin has archive permission)
    if ($_POST['action'] === 'archive_task' && !empty($permissions['can_archive'])) {
        $task_id = $_POST['task_id'];
        
        // Verify this task belongs to team admin's team member
        $verify_stmt = $pdo->prepare("
            SELECT t.id FROM tasks t
            JOIN users u ON t.assigned_to = u.id
            JOIN team_admin_teams tat ON u.team_id = tat.team_id
            WHERE t.id = ? AND tat.team_admin_id = ?
        ");
        $verify_stmt->execute([$task_id, $_SESSION['user_id']]);
        
        if ($verify_stmt->fetchColumn()) {
            try {
                $stmt = $pdo->prepare("UPDATE tasks SET archived = 1 WHERE id = ?");
                $stmt->execute([$task_id]);
                
                // Log activity
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action_type, details, task_id) 
                    VALUES (?, 'task_archived', ?, ?)
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Team Admin archived task",
                    $task_id
                ]);
                
                $success_message = "Task archived successfully!";
            } catch (PDOException $e) {
                $error_message = "Error archiving task: " . $e->getMessage();
            }
        }
    }
    
    // Handle adding new subtasks
    if ($_POST['action'] === 'add_subtask' && !empty($permissions['can_edit'])) {
        $task_id = $_POST['task_id'];
        $subtask_title = trim($_POST['subtask_title']);
        
        // Verify this task belongs to team admin's team member
        $verify_stmt = $pdo->prepare("
            SELECT t.id FROM tasks t
            JOIN users u ON t.assigned_to = u.id
            JOIN team_admin_teams tat ON u.team_id = tat.team_id
            WHERE t.id = ? AND tat.team_admin_id = ?
        ");
        $verify_stmt->execute([$task_id, $_SESSION['user_id']]);
        
        if ($verify_stmt->fetchColumn() && !empty($subtask_title)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO subtasks (task_id, title, status) VALUES (?, ?, 'to_do')");
                $stmt->execute([$task_id, $subtask_title]);
                
                // Log activity
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action_type, details, task_id) 
                    VALUES (?, 'subtask_added', ?, ?)
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Team Admin added subtask: {$subtask_title}",
                    $task_id
                ]);
                
                $success_message = "Subtask added successfully!";
                
                // Redirect to refresh the page
                header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
                exit;
                
            } catch (PDOException $e) {
                $error_message = "Error adding subtask: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$member_filter = $_GET['member'] ?? '';
$priority_filter = $_GET['priority'] ?? '';

// Build WHERE clause for filtering
$where_conditions = ["t.archived = 0"];
$params = [$_SESSION['user_id']];

if ($status_filter) {
    if ($status_filter === 'completed_all') {
        $where_conditions[] = "(t.status = 'completed' OR t.status = 'needs_approval')";
    } else {
        $where_conditions[] = "t.status = ?";
        $params[] = $status_filter;
    }
}

if ($member_filter) {
    $where_conditions[] = "u.id = ?";
    $params[] = $member_filter;
}

if ($priority_filter) {
    $where_conditions[] = "t.priority = ?";
    $params[] = $priority_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Get tasks assigned to team admin's team members ONLY
$tasks_stmt = $pdo->prepare("
    SELECT t.*, 
           u.full_name as assigned_to_name,
           u.email as assigned_to_email,
           creator.full_name as created_by_name,
           (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id) as subtask_count,
           (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND status = 'done') as completed_subtasks
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN users creator ON t.created_by = creator.id
    WHERE tat.team_admin_id = ? AND {$where_clause}
    ORDER BY 
        CASE 
            WHEN t.status = 'needs_approval' THEN 1
            WHEN t.status = 'in_progress' THEN 2
            WHEN t.status = 'todo' THEN 3
            WHEN t.status = 'completed' THEN 4
            ELSE 5
        END,
        t.priority = 'high' DESC,
        t.priority = 'medium' DESC,
        t.deadline ASC
");
$tasks_stmt->execute($params);
$tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get team members for filter dropdown
$members_stmt = $pdo->prepare("
    SELECT u.id, u.full_name 
    FROM users u
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    WHERE tat.team_admin_id = ? AND u.role = 'employee'
    ORDER BY u.full_name
");
$members_stmt->execute([$_SESSION['user_id']]);
$team_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks - Team Admin</title>
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

        .filters {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .filter-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 0.9rem;
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

        .tasks-grid {
            display: grid;
            gap: 1.5rem;
        }

        .task-card {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }

        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .task-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .task-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }

        .task-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-to_do { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }
        .status-todo { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }
        .status-in_progress { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .status-completed { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-needs_approval { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .priority-medium { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .priority-low { background: rgba(107, 114, 128, 0.1); color: var(--secondary); }

        .task-description {
            color: var(--text-secondary);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .task-assignee {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .assignee-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .assignee-info h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .assignee-info p {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .task-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .subtask-info {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
        
        .subtasks-section {
            margin: 1rem 0;
            padding: 1rem;
            background: var(--bg-secondary);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .subtasks-list {
            margin-bottom: 1rem;
        }
        
        .subtask-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            background: var(--bg-card);
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        
        .subtask-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }
        
        .subtask-checkbox-label {
            position: relative;
            padding-left: 30px;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        
        .subtask-checkbox {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        
        .checkmark {
            position: absolute;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: var(--bg-secondary);
            border: 2px solid var(--border-color);
            border-radius: 4px;
            transition: all 0.2s ease;
        }
        
        .subtask-checkbox:checked ~ .checkmark {
            background-color: var(--success);
            border-color: var(--success);
        }
        
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
            left: 6px;
            top: 2px;
            width: 4px;
            height: 8px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        .subtask-checkbox:checked ~ .checkmark:after {
            display: block;
        }
        
        .subtask-checkbox-label:hover .checkmark {
            border-color: var(--success);
            background-color: rgba(28, 200, 138, 0.1);
        }
        
        .subtask-title {
            color: var(--text-main);
            font-size: 0.9rem;
        }
        
        .subtask-title.completed {
            text-decoration: line-through;
            color: var(--text-secondary);
        }
        
        .subtask-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-edit-subtask {
            background: var(--info);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        
        .btn-edit-subtask:hover {
            background: #2a8bb8;
            transform: scale(1.05);
        }
        
        .add-subtask-form {
            margin: 1rem 0;
        }
        
        .subtask-form {
            display: flex;
            gap: 0.5rem;
        }
        
        .subtask-input-group {
            display: flex;
            gap: 0.5rem;
            flex: 1;
        }
        
        .subtask-input {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 0.9rem;
        }
        
                 .subtask-input:focus {
             outline: none;
             border-color: var(--primary);
             box-shadow: 0 0 0 2px rgba(106, 13, 173, 0.2);
         }
         
         /* Modal Styles */
         .modal {
             display: none;
             position: fixed;
             z-index: 2000;
             left: 0;
             top: 0;
             width: 100%;
             height: 100%;
             background-color: rgba(0, 0, 0, 0.7);
             backdrop-filter: blur(5px);
         }
         
         .modal-content {
             background-color: var(--bg-card);
             margin: 5% auto;
             padding: 0;
             border-radius: 15px;
             width: 90%;
             max-width: 500px;
             box-shadow: var(--shadow-lg);
         }
         
         .modal-header {
             background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
             color: white;
             padding: 1.5rem;
             border-radius: 15px 15px 0 0;
             display: flex;
             justify-content: space-between;
             align-items: center;
         }
         
         .modal-header h3 {
             margin: 0;
             font-size: 1.25rem;
         }
         
         .close {
             color: white;
             font-size: 2rem;
             font-weight: bold;
             cursor: pointer;
             opacity: 0.7;
             transition: opacity 0.3s;
         }
         
         .close:hover {
             opacity: 1;
         }
         
         .modal-body {
             padding: 1.5rem;
         }
         
         .form-group {
             margin-bottom: 1.5rem;
         }
         
         .form-group label {
             display: block;
             margin-bottom: 0.5rem;
             color: var(--text-main);
             font-weight: 500;
         }
         
         .form-group input {
             width: 100%;
             padding: 0.75rem;
             border: 1px solid var(--border-color);
             border-radius: 6px;
             background: var(--bg-secondary);
             color: var(--text-main);
             font-size: 0.9rem;
         }
         
         .form-group input:focus {
             outline: none;
             border-color: var(--primary);
             box-shadow: 0 0 0 2px rgba(106, 13, 173, 0.2);
         }
         
         .form-actions {
             display: flex;
             gap: 1rem;
             justify-content: flex-end;
         }
         
         .btn-secondary {
             background: var(--secondary);
             color: white;
         }
         
                 .btn-secondary:hover {
            background: #6c757d;
        }
        
        .status-dropdown {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            outline: none;
        }
        
        .status-dropdown:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(106, 13, 173, 0.2);
        }
        
        .status-dropdown:hover {
            border-color: var(--primary);
            background: var(--bg-secondary);
        }
        
        .status-dropdown option {
            background: var(--bg-card);
            color: var(--text-main);
            padding: 0.5rem;
        }

        /* Archive button smooth transitions */
        .archive-btn {
            transition: all 0.3s ease;
            margin-top: 0.2rem;
        }

        .archive-btn.appearing {
            animation: slideInFromLeft 0.3s ease-out;
        }

        .archive-btn.disappearing {
            animation: slideOutToLeft 0.3s ease-in;
        }

        @keyframes slideInFromLeft {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideOutToLeft {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(-10px);
            }
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

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .task-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
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
            <li><a href="team-admin-tasks.php" class="active"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <?php if (!empty($permissions['can_assign'])): ?>
            <li><a href="team-admin-add-task.php"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <?php endif; ?>
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
            <h1 class="page-title">Manage Team Tasks</h1>
            <p class="page-subtitle">View and manage tasks assigned to your team members</p>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="">All</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="todo" <?php echo $status_filter === 'todo' ? 'selected' : ''; ?>>To Do</option>
                            <option value="completed_all" <?php echo ($status_filter === 'completed' || $status_filter === 'needs_approval' || $status_filter === 'completed_all') ? 'selected' : ''; ?>>Completed (Needs Approval)</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="member">Team Member</label>
                        <select name="member" id="member">
                            <option value="">All Members</option>
                            <?php foreach ($team_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" <?php echo $member_filter == $member['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($member['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="priority">Priority</label>
                        <select name="priority" id="priority">
                            <option value="">All Priorities</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tasks -->
        <div class="tasks-grid">
            <?php if (empty($tasks)): ?>
                <div style="text-align: center; padding: 3rem; background: var(--bg-main); border-radius: 12px; box-shadow: var(--shadow);">
                    <i class="fas fa-tasks" style="font-size: 3rem; color: var(--text-secondary); opacity: 0.5; margin-bottom: 1rem;"></i>
                    <h3 style="color: var(--text-secondary); margin-bottom: 0.5rem;">No tasks found</h3>
                    <p style="color: var(--text-secondary);">No tasks match your current filters, or no tasks have been assigned to your team members yet.</p>
                    <?php if (!empty($permissions['can_assign'])): ?>
                        <a href="team-admin-add-task.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i>
                            Add First Task
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                <div class="task-card">
                    <div class="task-header">
                        <div>
                            <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                            <div class="task-meta">
                                <span class="status-badge status-<?php echo $task['status']; ?>">
                                    <?php 
                                    switch($task['status']) {
                                        case 'needs_approval': echo 'Need Approval'; break;
                                        case 'in_progress': echo 'In Progress'; break;
                                        case 'to_do': echo 'To Do'; break;
                                        case 'completed': echo 'Completed'; break;
                                        default: echo ucfirst(str_replace('_', ' ', $task['status']));
                                    }
                                    ?>
                                </span>
                                <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                    <?php echo ucfirst($task['priority']); ?> Priority
                                </span>
                                <?php if ($task['deadline']): ?>
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($task['deadline'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($task['description']): ?>
                        <div class="task-description">
                            <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="task-assignee">
                        <div class="assignee-avatar">
                            <?php 
                            $names = explode(' ', $task['assigned_to_name']);
                            echo strtoupper(substr($names[0], 0, 1));
                            if (isset($names[1])) echo strtoupper(substr($names[1], 0, 1));
                            ?>
                        </div>
                        <div class="assignee-info">
                            <h4><?php echo htmlspecialchars($task['assigned_to_name']); ?></h4>
                            <p><?php echo htmlspecialchars($task['assigned_to_email']); ?></p>
                        </div>
                    </div>
                    
                                         <?php if ($task['subtask_count'] > 0): ?>
                         <div class="subtask-info">
                             <i class="fas fa-list"></i>
                             <?php echo $task['completed_subtasks']; ?>/<?php echo $task['subtask_count']; ?> subtasks completed
                         </div>
                         
                         <!-- Subtasks List -->
                         <div class="subtasks-section">
                             <h4 style="color: var(--text-main); font-size: 0.9rem; margin-bottom: 0.75rem;">
                                 <i class="fas fa-tasks"></i> Subtasks
                             </h4>
                             <div class="subtasks-list">
                                 <?php
                                 // Fetch subtasks for this task
                                 $subtasks_stmt = $pdo->prepare("
                                     SELECT s.*, 
                                            CASE WHEN s.status = 'done' THEN 1 ELSE 0 END as is_done,
                                            (SELECT COUNT(*) FROM subtasks WHERE task_id = s.task_id AND status = 'done') as completed_count,
                                            (SELECT COUNT(*) FROM subtasks WHERE task_id = s.task_id) as total_count
                                     FROM subtasks s 
                                     WHERE s.task_id = ? 
                                     ORDER BY s.id
                                 ");
                                 $subtasks_stmt->execute([$task['id']]);
                                 $subtasks = $subtasks_stmt->fetchAll(PDO::FETCH_ASSOC);
                                 ?>
                                 
                                 <?php foreach ($subtasks as $subtask): ?>
                                     <div class="subtask-item" data-subtask-id="<?php echo $subtask['id']; ?>">
                                         <div class="subtask-content">
                                             <label class="subtask-checkbox-label">
                                                 <input type="checkbox" 
                                                        class="subtask-checkbox" 
                                                        data-subtask-id="<?php echo $subtask['id']; ?>"
                                                        data-task-id="<?php echo $task['id']; ?>"
                                                        data-completed-count="<?php echo $subtask['completed_count']; ?>"
                                                        data-total-count="<?php echo $subtask['total_count']; ?>"
                                                        <?php echo $subtask['is_done'] ? 'checked' : ''; ?>>
                                                 <span class="checkmark"></span>
                                             </label>
                                             
                                             <span class="subtask-title <?php echo $subtask['is_done'] ? 'completed' : ''; ?>"
                                                   data-subtask-id="<?php echo $subtask['id']; ?>">
                                                 <?php echo htmlspecialchars($subtask['title']); ?>
                                             </span>
                                         </div>
                                         
                                         <div class="subtask-actions">
                                             <button class="btn-edit-subtask" 
                                                     onclick="editSubtask(<?php echo $subtask['id']; ?>, '<?php echo htmlspecialchars(addslashes($subtask['title'])); ?>')"
                                                     title="Edit subtask">
                                                 <i class="fas fa-edit"></i>
                                             </button>
                                         </div>
                                     </div>
                                 <?php endforeach; ?>
                             </div>
                         </div>
                     <?php endif; ?>
                     
                     <!-- Add New Subtask Form -->
                     <div class="add-subtask-form">
                         <form method="POST" class="subtask-form">
                             <input type="hidden" name="action" value="add_subtask">
                             <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                             <div class="subtask-input-group">
                                 <input type="text" 
                                        name="subtask_title" 
                                        placeholder="Add new subtask..." 
                                        class="subtask-input"
                                        required>
                                 <button type="submit" class="btn btn-primary btn-sm">
                                     <i class="fas fa-plus"></i>
                                 </button>
                             </div>
                         </form>
                     </div>
                    
                    <div class="task-actions">
                        <!-- Status Dropdown - Always visible for team admins with edit permission -->
                        <?php if (!empty($permissions['can_edit'])): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                <select name="status" onchange="updateTaskStatusFromDropdown(this, <?php echo $task['id']; ?>)" class="status-dropdown">
                                    <option value="to_do" <?php echo $task['status'] === 'to_do' ? 'selected' : ''; ?>>To Do</option>
                                    <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </form>
                        <?php endif; ?>
                        
                        <!-- Archive Button - Shows dynamically when task is completed -->
                        <?php if (!empty($permissions['can_archive']) && $task['status'] === 'completed'): ?>
                            <form method="POST" style="display: inline;" class="archive-btn">
                                <input type="hidden" name="action" value="archive_task">
                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Archive this task? It will be moved to archived tasks.')">
                                    <i class="fas fa-archive"></i> Archive
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <a href="edit-task.php?task_id=<?php echo $task['id']; ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        
                        <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
                 </div>
     </div>
     
     <!-- Subtask Edit Modal -->
     <div id="subtaskEditModal" class="modal" style="display: none;">
         <div class="modal-content">
             <div class="modal-header">
                 <h3>Edit Subtask</h3>
                 <span class="close" onclick="closeSubtaskModal()">&times;</span>
             </div>
             <div class="modal-body">
                 <form id="editSubtaskForm">
                     <input type="hidden" id="editSubtaskId" name="subtask_id">
                     <div class="form-group">
                         <label for="editSubtaskTitle">Subtask Title</label>
                         <input type="text" id="editSubtaskTitle" name="title" required>
                     </div>
                     <div class="form-actions">
                         <button type="submit" class="btn btn-primary">Save Changes</button>
                         <button type="button" class="btn btn-secondary" onclick="closeSubtaskModal()">Cancel</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>
     
     <script>
         // Handle subtask checkbox changes
         document.addEventListener('DOMContentLoaded', function() {
             const checkboxes = document.querySelectorAll('.subtask-checkbox');
             checkboxes.forEach(checkbox => {
                 checkbox.addEventListener('change', function() {
                     const subtaskId = this.dataset.subtaskId;
                     const taskId = this.dataset.taskId;
                     const isChecked = this.checked;
                     const completedCount = parseInt(this.dataset.completedCount);
                     const totalCount = parseInt(this.dataset.totalCount);
                     
                     updateSubtaskStatus(subtaskId, taskId, isChecked, completedCount, totalCount);
                 });
             });
         });
         
         // Update subtask status via AJAX
         function updateSubtaskStatus(subtaskId, taskId, isChecked, completedCount, totalCount) {
             const status = isChecked ? 'done' : 'to_do';
             
             fetch('update_subtask_status.php', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                 },
                 body: `subtask_id=${subtaskId}&status=${status}&task_id=${taskId}`
             })
             .then(response => response.json())
             .then(data => {
                 if (data.success) {
                     // Update the subtask title styling
                     const subtaskItem = document.querySelector(`[data-subtask-id="${subtaskId}"]`);
                     const titleSpan = subtaskItem.querySelector('.subtask-title');
                     const checkbox = subtaskItem.querySelector('.subtask-checkbox');
                     const taskCard = subtaskItem.closest('.task-card');
                     
                     // Update checkbox state and title styling
                     checkbox.checked = isChecked;
                     if (isChecked) {
                         titleSpan.classList.add('completed');
                     } else {
                         titleSpan.classList.remove('completed');
                     }
                     
                     // Update the subtask info counter INSTANTLY
                     const newCompletedCount = isChecked ? completedCount + 1 : completedCount - 1;
                     const subtaskInfo = taskCard.querySelector('.subtask-info');
                     if (subtaskInfo) {
                         subtaskInfo.innerHTML = `<i class="fas fa-list"></i> ${newCompletedCount}/${totalCount} subtasks completed`;
                     }
                     
                     // Update data attributes for ALL checkboxes in this task
                     const allCheckboxes = taskCard.querySelectorAll('.subtask-checkbox');
                     allCheckboxes.forEach(cb => {
                         cb.dataset.completedCount = newCompletedCount;
                     });
                     
                     // Instantly update task status based on completion
                     if (newCompletedCount === totalCount) {
                         updateTaskStatusInstantly(taskCard, 'completed');
                     } else if (newCompletedCount > 0) {
                         updateTaskStatusInstantly(taskCard, 'in_progress');
                     } else {
                         updateTaskStatusInstantly(taskCard, 'to_do');
                     }
                     
                     // Update database status
                     const newTaskStatus = newCompletedCount === totalCount ? 'completed' : 
                                         newCompletedCount > 0 ? 'in_progress' : 'to_do';
                     updateTaskStatusInDatabase(taskId, newTaskStatus);
                     
                 } else {
                     // Revert checkbox state on error
                     const checkbox = document.querySelector(`[data-subtask-id="${subtaskId}"]`).querySelector('.subtask-checkbox');
                     checkbox.checked = !isChecked;
                     alert('Error updating subtask: ' + data.error);
                 }
             })
             .catch(error => {
                 console.error('Error:', error);
                 // Revert checkbox state on error
                 const checkbox = document.querySelector(`[data-subtask-id="${subtaskId}"]`).querySelector('.subtask-checkbox');
                 checkbox.checked = !isChecked;
                 alert('Error updating subtask status');
             });
         }
         
         // Update task status in database (silent background update)
         function updateTaskStatusInDatabase(taskId, status) {
             fetch('update_task_status.php', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                     'X-Requested-With': 'XMLHttpRequest'
                 },
                 body: `task_id=${taskId}&new_status=${status}&ajax=1`
             })
             .then(response => response.json())
             .then(data => {
                 if (!data.success) {
                     console.error('Error updating task status:', data.error);
                 }
             })
             .catch(error => {
                 console.error('Error updating task status in database:', error);
             });
         }
         
         // Update task status instantly in the UI
         function updateTaskStatusInstantly(taskCard, newStatus) {
             const statusBadge = taskCard.querySelector('.status-badge');
             const statusDropdown = taskCard.querySelector('.status-dropdown');
             
             // Update status badge
             if (statusBadge) {
                 // Remove all status classes first
                 statusBadge.classList.remove('status-to_do', 'status-in_progress', 'status-completed', 'status-needs_approval');
                 
                 // Add new status class
                 statusBadge.classList.add(`status-${newStatus}`);
                 
                 // Update status text
                 let statusText = '';
                 switch(newStatus) {
                     case 'to_do': statusText = 'To Do'; break;
                     case 'in_progress': statusText = 'In Progress'; break;
                     case 'completed': statusText = 'Completed'; break;
                     case 'needs_approval': statusText = 'Need Approval'; break;
                     default: statusText = newStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                 }
                 statusBadge.textContent = statusText;
                 
                 // Force a visual refresh
                 statusBadge.style.opacity = '0.8';
                 setTimeout(() => {
                     statusBadge.style.opacity = '1';
                 }, 100);
             }
             
             // Update dropdown selection
             if (statusDropdown) {
                 statusDropdown.value = newStatus;
                 
                 // Ensure dropdown shows correct selection visually
                 const options = statusDropdown.querySelectorAll('option');
                 options.forEach(option => {
                     option.selected = (option.value === newStatus);
                 });
             }
             
             // Show/hide archive button based on status
             updateArchiveButtonVisibility(taskCard, newStatus);
         }
         
         // Update archive button visibility with smooth transition
         function updateArchiveButtonVisibility(taskCard, status) {
             let archiveButton = taskCard.querySelector('.archive-btn');
             
             if (status === 'completed') {
                 // Show archive button if it doesn't exist
                 if (!archiveButton) {
                     const taskActions = taskCard.querySelector('.task-actions');
                     const taskId = taskCard.querySelector('[name="task_id"]').value;
                     
                     archiveButton = document.createElement('form');
                     archiveButton.method = 'POST';
                     archiveButton.classList.add('archive-btn', 'appearing');
                     archiveButton.innerHTML = `
                         <input type="hidden" name="action" value="archive_task">
                         <input type="hidden" name="task_id" value="${taskId}">
                         <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Archive this task? It will be moved to archived tasks.')">
                             <i class="fas fa-archive"></i> Archive
                         </button>
                     `;
                     taskActions.appendChild(archiveButton);
                     
                     // Remove animation class after animation completes
                     setTimeout(() => {
                         archiveButton.classList.remove('appearing');
                     }, 300);
                 } else {
                     // Show existing button
                     archiveButton.style.display = 'inline';
                     archiveButton.classList.remove('disappearing');
                     archiveButton.classList.add('appearing');
                     
                     setTimeout(() => {
                         archiveButton.classList.remove('appearing');
                     }, 300);
                 }
             } else {
                 // Hide archive button with smooth transition
                 if (archiveButton && archiveButton.style.display !== 'none') {
                     archiveButton.classList.remove('appearing');
                     archiveButton.classList.add('disappearing');
                     
                     setTimeout(() => {
                         archiveButton.style.display = 'none';
                         archiveButton.classList.remove('disappearing');
                     }, 300);
                 }
             }
         }
         
         // Handle status update from dropdown
         function updateTaskStatusFromDropdown(dropdown, taskId) {
             const newStatus = dropdown.value;
             if (!newStatus) return;
             
             const taskCard = dropdown.closest('.task-card');
             
             // Update UI instantly
             updateTaskStatusInstantly(taskCard, newStatus);
             
             // Update database
             updateTaskStatusInDatabase(taskId, newStatus);
             
             // Keep dropdown showing the selected value (no need to reset since we removed "Change Status")
             dropdown.value = newStatus;
         }
         
         // Edit subtask modal
         function editSubtask(subtaskId, currentTitle) {
             document.getElementById('editSubtaskId').value = subtaskId;
             document.getElementById('editSubtaskTitle').value = currentTitle;
             document.getElementById('subtaskEditModal').style.display = 'block';
             document.getElementById('editSubtaskTitle').focus();
             document.getElementById('editSubtaskTitle').select();
         }
         
         function closeSubtaskModal() {
             document.getElementById('subtaskEditModal').style.display = 'none';
         }
         
         // Handle subtask edit form submission
         document.getElementById('editSubtaskForm').addEventListener('submit', function(e) {
             e.preventDefault();
             
             const subtaskId = document.getElementById('editSubtaskId').value;
             const newTitle = document.getElementById('editSubtaskTitle').value.trim();
             
             if (!newTitle) {
                 alert('Subtask title cannot be empty');
                 return;
             }
             
             fetch('update_subtask_title.php', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                 },
                 body: `subtask_id=${subtaskId}&title=${encodeURIComponent(newTitle)}`
             })
             .then(response => response.json())
             .then(data => {
                 if (data.success) {
                     // Update the subtask title in the DOM
                     const titleSpan = document.querySelector(`span.subtask-title[data-subtask-id="${subtaskId}"]`);
                     if (titleSpan) {
                         titleSpan.textContent = newTitle;
                     }
                     closeSubtaskModal();
                 } else {
                     alert('Error updating subtask: ' + data.error);
                 }
             })
             .catch(error => {
                 console.error('Error:', error);
                 alert('Error updating subtask title');
             });
         });
         
         // Close modal when clicking outside
         window.onclick = function(event) {
             const modal = document.getElementById('subtaskEditModal');
             if (event.target === modal) {
                 closeSubtaskModal();
             }
         }
         
         // Close modal with Escape key
         document.addEventListener('keydown', function(event) {
             if (event.key === 'Escape') {
                 closeSubtaskModal();
             }
         });
     </script>
 </body>
 </html>
