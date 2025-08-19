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

// Check for success message in session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying it
}

// Handle team creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $team_admin_id = $_POST['team_admin_id'] ?? null;
    
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Team name is required";
    }
    
    // Team admin is required
    if (empty($team_admin_id)) {
        $errors[] = "Team admin is required";
    } else {
        // Verify the selected user is a team admin
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'team_admin' AND status = 'active'");
        $stmt->execute([$team_admin_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = "Selected team admin is not valid or not active";
        }
    }
    
    // Check if team name already exists
    if (!empty($name)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Team name already exists";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Create team with primary team admin
            $stmt = $pdo->prepare("INSERT INTO teams (name, description, primary_team_admin_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $team_admin_id]);
            $team_id = $pdo->lastInsertId();
            
            // Add team admin to the junction table (required)
            // Add to team_admin_teams junction table
            $stmt = $pdo->prepare("INSERT INTO team_admin_teams (team_admin_id, team_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$team_admin_id, $team_id, $_SESSION['user_id']]);
            
            // Create default permissions for team admin
            $stmt = $pdo->prepare("
                INSERT INTO team_admin_permissions 
                (user_id, team_id, can_assign, can_edit, can_archive, can_add_members, can_view_reports, can_send_messages) 
                VALUES (?, ?, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE)
            ");
            $stmt->execute([$team_admin_id, $team_id]);
            
            // Optionally assign the team admin to this team (if they don't have a team yet)
            $stmt = $pdo->prepare("UPDATE users SET team_id = COALESCE(team_id, ?), parent_admin_id = COALESCE(parent_admin_id, ?) WHERE id = ?");
            $stmt->execute([$team_id, $_SESSION['user_id'], $team_admin_id]);
            
            $pdo->commit();
            $message = "Team created successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error creating team: " . $e->getMessage();
        }
    } else {
        $error = implode(", ", $errors);
    }
}

// Handle AJAX request for team members (before session check)
if (isset($_GET['action']) && $_GET['action'] === 'get_team_members' && isset($_GET['team_id'])) {
    $team_id = $_GET['team_id'];
    
    // Get team members
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, role, status
        FROM users 
        WHERE team_id = ?
        ORDER BY FIELD(role, 'team_admin', 'employee'), full_name
    ");
    $stmt->execute([$team_id]);
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['members' => $team_members]);
    exit();
}

// Handle AJAX request for user tasks (before session check)
if (isset($_GET['action']) && $_GET['action'] === 'get_user_tasks' && isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
    $user_data = getUserTasksAndActivity($pdo, $user_id);
    
    header('Content-Type: application/json');
    echo json_encode($user_data);
    exit();
}

// Function to get user tasks and activity
function getUserTasksAndActivity($pdo, $user_id) {
    // Get user's tasks (excluding archived tasks)
    $tasks_stmt = $pdo->prepare("
        SELECT t.*, 
               u1.full_name as created_by_name,
               u2.full_name as assigned_to_name
        FROM tasks t
        LEFT JOIN users u1 ON t.created_by = u1.id
        LEFT JOIN users u2 ON t.assigned_to = u2.id
        WHERE t.assigned_to = ? AND t.archived = 0
        ORDER BY t.created_at DESC
    ");
    $tasks_stmt->execute([$user_id]);
    $tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get subtasks for each task
    foreach ($tasks as &$task) {
        $subtasks_stmt = $pdo->prepare("
            SELECT id, title, status
            FROM subtasks 
            WHERE task_id = ?
            ORDER BY id ASC
        ");
        $subtasks_stmt->execute([$task['id']]);
        $task['subtasks'] = $subtasks_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get user's recent activity
    $activity_stmt = $pdo->prepare("
        SELECT al.*, t.title as task_title
        FROM activity_logs al
        LEFT JOIN tasks t ON al.task_id = t.id
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 20
    ");
    $activity_stmt->execute([$user_id]);
    $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return ['tasks' => $tasks, 'activities' => $activities];
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $team_id = $_GET['id'];
    
    try {
        // Check if team has members
        $check_members = $pdo->prepare("SELECT COUNT(*) FROM users WHERE team_id = ?");
        $check_members->execute([$team_id]);
        $member_count = $check_members->fetchColumn();
        
        if ($member_count > 0) {
            $error = "Cannot delete team with existing members. Please reassign or remove members first.";
        } else {
            $pdo->beginTransaction();
            
            // Delete team admin permissions
            $stmt = $pdo->prepare("DELETE FROM team_admin_permissions WHERE team_id = ?");
            $stmt->execute([$team_id]);
            
            // Delete team
            $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->execute([$team_id]);
            
            $pdo->commit();
            $message = "Team deleted successfully.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error deleting team: " . $e->getMessage();
    }
}

// Fetch teams with statistics
$stmt = $pdo->query("
    SELECT t.*, 
           u.full_name as team_admin_name,
           (SELECT COUNT(*) FROM users WHERE team_id = t.id) as member_count,
           (SELECT COUNT(*) FROM tasks WHERE (team_id = t.id OR assigned_to IN (SELECT id FROM users WHERE team_id = t.id)) AND status != 'archived') as task_count,
           (SELECT COUNT(*) FROM team_admin_teams tat WHERE tat.team_id = t.id) as admin_count
    FROM teams t 
    LEFT JOIN users u ON u.id = t.primary_team_admin_id
    ORDER BY t.id
");
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available users for team admin assignment (only team_admin role users)
$stmt = $pdo->query("
    SELECT id, full_name, email, role 
    FROM users 
    WHERE role = 'team_admin' AND status = 'active'
    ORDER BY full_name
");
$available_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// COMPREHENSIVE TEAM MANAGEMENT ACTIVITY SYSTEM WITH PERMISSION FILTERING
$recent_activities = [];

try {
    // Admin sees ALL team activities - all employee activities, task updates, and messages
    if ($_SESSION['role'] === 'admin') {
        // Get all team-related activities from today
        $activity_stmt = $pdo->prepare("
            SELECT 
                al.*, 
                u.full_name as user_name, 
                u.role as user_role,
                u.team_id as user_team_id,
                t.title as task_title, 
                t.id as task_id, 
                teams.name as team_name,
                'activity' as source_type,
                al.created_at as activity_time
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            LEFT JOIN tasks t ON al.task_id = t.id
            LEFT JOIN teams ON u.team_id = teams.id
            WHERE DATE(al.created_at) = CURDATE()
            AND u.role IN ('employee', 'team_admin')
            ORDER BY al.created_at DESC
            LIMIT 30
        ");
        $activity_stmt->execute();
        $activity_logs = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all team messages from today
        try {
            $message_stmt = $pdo->prepare("
                SELECT 
                    m.*, 
                    sender.full_name as sender_name, 
                    sender.role as sender_role,
                    sender.team_id as sender_team_id,
                    receiver.full_name as receiver_name,
                    receiver.role as receiver_role,
                    receiver.team_id as receiver_team_id,
                    t.title as task_title, 
                    t.id as task_id,
                    sender_team.name as sender_team_name,
                    receiver_team.name as receiver_team_name,
                    'message' as source_type,
                    m.sent_at as activity_time
                FROM messages m
                JOIN users sender ON m.sender_id = sender.id
                LEFT JOIN users receiver ON m.recipient_id = receiver.id
                LEFT JOIN tasks t ON m.task_id = t.id
                LEFT JOIN teams sender_team ON sender.team_id = sender_team.id
                LEFT JOIN teams receiver_team ON receiver.team_id = receiver_team.id
                WHERE DATE(m.sent_at) = CURDATE()
                AND (sender.role IN ('employee', 'team_admin') OR receiver.role IN ('employee', 'team_admin'))
                ORDER BY m.sent_at DESC
                LIMIT 20
            ");
            $message_stmt->execute();
            $message_logs = $message_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $message_logs = [];
        }

        // Get team member login activities (if tracked)
        try {
            $login_stmt = $pdo->prepare("
                SELECT 
                    'user_login' as action_type,
                    u.id as user_id,
                    u.full_name as user_name,
                    u.role as user_role,
                    u.team_id as user_team_id,
                    teams.name as team_name,
                    'System Access' as task_title,
                    NULL as task_id,
                    'login' as source_type,
                    ul.login_time as activity_time,
                    'User logged in' as details,
                    NULL as old_status,
                    NULL as new_status
                FROM user_logins ul
                JOIN users u ON ul.user_id = u.id
                LEFT JOIN teams ON u.team_id = teams.id
                WHERE DATE(ul.login_time) = CURDATE()
                AND u.role IN ('employee', 'team_admin')
                ORDER BY ul.login_time DESC
                LIMIT 15
            ");
            $login_stmt->execute();
            $login_logs = $login_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $login_logs = [];
        }

        // Combine all activities
        $all_logs = array_merge($activity_logs, $message_logs, $login_logs);

        // Sort by activity time
        usort($all_logs, function($a, $b) {
            return strtotime($b['activity_time']) - strtotime($a['activity_time']);
        });

    } elseif ($_SESSION['role'] === 'team_admin') {
        // Team Admin sees only their team members' activities
        $team_admin_id = $_SESSION['user_id'];
        
        // Get team members' activities
        $activity_stmt = $pdo->prepare("
            SELECT 
                al.*, 
                u.full_name as user_name, 
                u.role as user_role,
                u.team_id as user_team_id,
                t.title as task_title, 
                t.id as task_id,
                teams.name as team_name,
                'activity' as source_type,
                al.created_at as activity_time
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            LEFT JOIN tasks t ON al.task_id = t.id
            LEFT JOIN teams ON u.team_id = teams.id
            WHERE DATE(al.created_at) = CURDATE()
            AND u.team_id = (SELECT team_id FROM users WHERE id = :team_admin_id)
            ORDER BY al.created_at DESC
            LIMIT 20
        ");
        $activity_stmt->execute(['team_admin_id' => $team_admin_id]);
        $activity_logs = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get team messages
        try {
            $message_stmt = $pdo->prepare("
                SELECT 
                    m.*, 
                    sender.full_name as sender_name, 
                    sender.role as sender_role,
                    sender.team_id as sender_team_id,
                    receiver.full_name as receiver_name,
                    receiver.role as receiver_role,
                    receiver.team_id as receiver_team_id,
                    t.title as task_title, 
                    t.id as task_id,
                    sender_team.name as sender_team_name,
                    receiver_team.name as receiver_team_name,
                    'message' as source_type,
                    m.sent_at as activity_time
                FROM messages m
                JOIN users sender ON m.sender_id = sender.id
                LEFT JOIN users receiver ON m.recipient_id = receiver.id
                LEFT JOIN tasks t ON m.task_id = t.id
                LEFT JOIN teams sender_team ON sender.team_id = sender_team.id
                LEFT JOIN teams receiver_team ON receiver.team_id = receiver_team.id
                WHERE DATE(m.sent_at) = CURDATE()
                AND (sender.team_id = (SELECT team_id FROM users WHERE id = :team_admin_id)
                     OR receiver.team_id = (SELECT team_id FROM users WHERE id = :team_admin_id))
                ORDER BY m.sent_at DESC
                LIMIT 15
            ");
            $message_stmt->execute(['team_admin_id' => $team_admin_id]);
            $message_logs = $message_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $message_logs = [];
        }

        $all_logs = array_merge($activity_logs, $message_logs);

        // Sort by activity time
        usort($all_logs, function($a, $b) {
            return strtotime($b['activity_time']) - strtotime($a['activity_time']);
        });
    }

    // Process all logs into standardized format
    foreach ($all_logs as $log) {
        if ($log['source_type'] === 'message') {
            $recent_activities[] = [
                'type' => 'message_sent',
                'user' => $log['sender_name'],
                'user_role' => $log['sender_role'] ?? 'employee',
                'team_name' => $log['sender_team_name'] ?? 'No Team',
                'task' => $log['task_title'] ?? 'General Communication',
                'details' => $log['subject'],
                'message_content' => $log['message'] ?? '',
                'receiver' => $log['receiver_name'] ?? 'All Users',
                'receiver_role' => $log['receiver_role'] ?? '',
                'receiver_team' => $log['receiver_team_name'] ?? 'No Team',
                'time' => date('g:i A', strtotime($log['activity_time'])),
                'task_id' => $log['task_id'],
                'priority' => 'normal'
            ];
        } elseif ($log['source_type'] === 'login') {
            $recent_activities[] = [
                'type' => 'user_login',
                'user' => $log['user_name'],
                'user_role' => $log['user_role'],
                'team_name' => $log['team_name'] ?? 'No Team',
                'task' => 'System Access',
                'details' => $log['details'],
                'time' => date('g:i A', strtotime($log['activity_time'])),
                'task_id' => null,
                'priority' => 'low'
            ];
        } else {
            // Task activities
            $activity_type = $log['action_type'] ?? 'activity';
            $priority = 'normal';
            
            // Determine priority based on activity type
            if (in_array($activity_type, ['task_completed', 'status_update'])) {
                $priority = 'high';
            } elseif (in_array($activity_type, ['task_created', 'task_assigned'])) {
                $priority = 'medium';
            }

            $recent_activities[] = [
                'type' => $activity_type,
                'user' => $log['user_name'],
                'user_role' => $log['user_role'] ?? 'employee',
                'team_name' => $log['team_name'] ?? 'No Team',
                'task' => $log['task_title'] ?? 'Unknown Task',
                'details' => $log['details'] ?? '',
                'old_status' => $log['old_status'] ?? null,
                'new_status' => $log['new_status'] ?? null,
                'time' => date('g:i A', strtotime($log['activity_time'])),
                'task_id' => $log['task_id'],
                'priority' => $priority
            ];
        }
    }

} catch (PDOException $e) {
    error_log("Team management activity logs error: " . $e->getMessage());
    $recent_activities = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teams</title>
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
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-main);
            font-weight: 600;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            color: var(--text-main);
            font-size: 1rem;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
            color: var(--text-main);
        }
        
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background-color: var(--bg-secondary);
            color: var(--primary-light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.05rem;
        }
        
        tbody tr {
            transition: background-color 0.2s;
        }
        
        tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 0.35rem;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-add {
            background-color: var(--success);
            padding: 0.75rem 1.25rem;
            margin-bottom: 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-add:hover {
            background-color: #19b67d;
        }
        
        .btn-edit {
            background-color: var(--info);
        }
        
        .btn-edit:hover {
            background-color: #2a9fbd;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(106, 13, 173, 0.3);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            cursor: pointer;
            margin-right: 0.5rem;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.4);
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
        }
        
        .btn-delete {
            background-color: var(--danger);
        }
        
        .btn-delete:hover {
            background-color: #d93426;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .team-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success);
        }
        
        .status-inactive {
            background-color: rgba(133, 135, 150, 0.1);
            color: var(--secondary);
        }
        
        .stats-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.8rem;
            font-weight: 600;
            background-color: rgba(54, 185, 204, 0.1);
            color: var(--info);
        }
        
        /* Team Members Modal Styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background-color: var(--bg-card);
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .close:hover {
            color: #ddd;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .member-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .member-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        }
        
        .member-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border-color: var(--primary);
        }
        
        .member-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(106, 13, 173, 0.3);
        }
        
        .member-details h4 {
            margin: 0 0 0.25rem 0;
            color: var(--text-main);
            font-size: 1rem;
            font-weight: 600;
        }
        
        .member-details p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .member-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-admin {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(106, 13, 173, 0.3);
        }
        
        .role-team_admin {
            background: linear-gradient(135deg, #ffd700 0%, var(--warning) 100%);
            color: #8b6914;
            box-shadow: 0 2px 8px rgba(246, 194, 62, 0.3);
        }
        
        .role-employee {
            background: linear-gradient(135deg, var(--info) 0%, #2c8aa6 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(54, 185, 204, 0.3);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active {
            background: rgba(28, 200, 138, 0.15);
            color: var(--success);
            border: 1px solid rgba(28, 200, 138, 0.3);
        }
        
        .status-active::before {
            content: '●';
            color: var(--success);
            animation: pulse 2s infinite;
        }
        
        .status-inactive {
            background: rgba(133, 135, 150, 0.15);
            color: var(--secondary);
            border: 1px solid rgba(133, 135, 150, 0.3);
        }
        
                 .status-inactive::before {
             content: '●';
             color: var(--secondary);
         }
         
         .member-actions {
             display: flex;
             gap: 0.5rem;
         }
         
         .btn-view-tasks {
             background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
             color: white;
             border: none;
             padding: 0.5rem 1rem;
             border-radius: 20px;
             font-size: 0.75rem;
             font-weight: 600;
             text-transform: uppercase;
             letter-spacing: 0.5px;
             transition: all 0.3s ease;
             box-shadow: 0 2px 8px rgba(106, 13, 173, 0.3);
             text-decoration: none;
             display: inline-flex;
             align-items: center;
             gap: 0.3rem;
             cursor: pointer;
         }
         
         .btn-view-tasks:hover {
             transform: translateY(-2px);
             box-shadow: 0 4px 15px rgba(106, 13, 173, 0.4);
             background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
         }
         
         .btn-approve-task {
             background: linear-gradient(135deg, var(--warning) 0%, #e6a800 100%);
             color: white;
             border: none;
             padding: 0.4rem 0.8rem;
             border-radius: 15px;
             font-size: 0.7rem;
             font-weight: 600;
             text-transform: uppercase;
             letter-spacing: 0.3px;
             transition: all 0.3s ease;
             box-shadow: 0 2px 6px rgba(246, 194, 62, 0.3);
             cursor: pointer;
             margin-left: 0.5rem;
         }
         
         .btn-approve-task:hover {
             transform: translateY(-1px);
             box-shadow: 0 3px 10px rgba(246, 194, 62, 0.4);
             background: linear-gradient(135deg, #e6a800 0%, var(--warning) 100%);
         }
         
         .empty-members {
             text-align: center;
             padding: 3rem 2rem;
             color: var(--text-secondary);
         }
        
        .empty-members i {
            font-size: 4rem;
            color: var(--text-secondary);
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        
                 @keyframes pulse {
             0%, 100% { opacity: 1; }
             50% { opacity: 0.5; }
         }
         
         /* User Tasks Modal Styles */
         .user-tasks-modal {
             max-width: 1000px !important;
             width: 95% !important;
         }
         
         .user-tasks-container {
             display: grid;
             grid-template-columns: 1fr 1fr;
             gap: 2rem;
             max-height: 70vh;
             overflow-y: auto;
         }
         
         .tasks-section, .activity-section {
             background: var(--bg-secondary);
             border-radius: 8px;
             padding: 1.5rem;
         }
         
         .tasks-section h4, .activity-section h4 {
             color: var(--primary-light);
             margin-bottom: 1rem;
             display: flex;
             align-items: center;
             gap: 0.5rem;
             font-size: 1.1rem;
         }
         
         .tasks-list, .activity-list {
             max-height: 400px;
             overflow-y: auto;
         }
         
         .task-item {
             background: var(--bg-card);
             border: 1px solid var(--border-color);
             border-radius: 12px;
             padding: 1.5rem;
             margin-bottom: 1.5rem;
             transition: all 0.3s ease;
             min-height: 180px;
         }
         
         .task-item:hover {
             border-color: var(--primary);
             transform: translateY(-1px);
         }
         
         .task-title {
             font-weight: 600;
             color: var(--text-main);
             margin-bottom: 0.75rem;
             font-size: 1.1rem;
             line-height: 1.3;
         }
         
         .task-meta {
             display: flex;
             justify-content: space-between;
             align-items: center;
             margin-bottom: 0.5rem;
             font-size: 0.85rem;
         }
         
         .task-status {
             padding: 0.25rem 0.5rem;
             border-radius: 12px;
             font-size: 0.75rem;
             font-weight: 600;
             text-transform: uppercase;
         }
         
         .status-pending {
             background: rgba(246, 194, 62, 0.2);
             color: var(--warning);
         }
         
         .status-in-progress {
             background: rgba(54, 185, 204, 0.2);
             color: var(--info);
         }
         
                   .status-completed {
              background: rgba(28, 200, 138, 0.2);
              color: var(--success);
          }
          
          .status-needs-approval {
              background: rgba(246, 194, 62, 0.2);
              color: var(--warning);
          }
         
         .task-description {
             color: var(--text-secondary);
             font-size: 0.9rem;
             line-height: 1.5;
             margin-bottom: 1rem;
             max-height: 60px;
             overflow: hidden;
             text-overflow: ellipsis;
             display: -webkit-box;
             -webkit-line-clamp: 3;
             -webkit-box-orient: vertical;
         }
         
         .task-dates {
             font-size: 0.8rem;
             color: var(--text-secondary);
             margin-bottom: 1rem;
         }
         
         .subtasks-section {
             margin-top: 1rem;
             padding-top: 1rem;
             border-top: 1px solid var(--border-color);
         }
         
         .subtasks-title {
             font-size: 0.85rem;
             font-weight: 600;
             color: var(--primary-light);
             margin-bottom: 0.75rem;
             display: flex;
             align-items: center;
             gap: 0.5rem;
         }
         
         .subtasks-list {
             max-height: 120px;
             overflow-y: auto;
         }
         
         .subtask-item {
             background: var(--bg-secondary);
             border: 1px solid var(--border-color);
             border-radius: 6px;
             padding: 0.75rem;
             margin-bottom: 0.5rem;
             font-size: 0.8rem;
             display: flex;
             align-items: center;
             gap: 0.5rem;
         }
         
         .subtask-checkbox {
             width: 16px;
             height: 16px;
             accent-color: var(--primary);
         }
         
         .subtask-text {
             flex: 1;
             color: var(--text-main);
         }
         
         .subtask-completed {
             text-decoration: line-through;
             color: var(--text-secondary);
         }
         
         .no-subtasks {
             color: var(--text-secondary);
             font-style: italic;
             font-size: 0.8rem;
             text-align: center;
             padding: 0.5rem;
         }
         
         .activity-item {
             background: var(--bg-card);
             border-left: 3px solid var(--primary);
             padding: 1rem;
             margin-bottom: 1rem;
             border-radius: 0 8px 8px 0;
         }
         
         .activity-action {
             font-weight: 600;
             color: var(--text-main);
             margin-bottom: 0.25rem;
             line-height: 1.4;
         }
         
         .activity-action.status-change {
             color: var(--primary-light);
             background: rgba(142, 36, 170, 0.1);
             padding: 0.5rem;
             border-radius: 6px;
             border-left: 3px solid var(--primary-light);
         }
         
         .status-to {
             color: var(--warning);
             font-weight: 700;
             font-style: italic;
             padding: 0 0.3rem;
         }
         
         .activity-task {
             color: var(--primary-light);
             font-size: 0.85rem;
             margin-bottom: 0.25rem;
         }
         
         .activity-time {
             font-size: 0.75rem;
             color: var(--text-secondary);
         }
         
         .no-tasks, .no-activity {
             text-align: center;
             padding: 2rem;
             color: var(--text-secondary);
             font-style: italic;
         }
         
         @media (max-width: 768px) {
             .user-tasks-container {
                 grid-template-columns: 1fr;
                 gap: 1rem;
             }
             
             .user-tasks-modal {
                 width: 98% !important;
                 margin: 5% auto !important;
             }
         }
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
            
            .table-responsive {
                overflow-x: auto;
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
                <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></div>
            </div>
        </div>
        <div class="sidebar-heading">Main</div>
        <ul class="sidebar-menu">
            <li><a href="admin-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php" class="page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="manage-teams.php" class="active page-link"><i class="fas fa-users-cog"></i> Manage Teams</a></li>
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
            <h1 class="page-title">Manage Teams</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Create Team Form -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-plus-circle"></i> Create New Team</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="form-group">
                        <label for="name"><i class="fas fa-users"></i> Team Name</label>
                        <input type="text" name="name" id="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description"><i class="fas fa-info-circle"></i> Description</label>
                        <textarea name="description" id="description" placeholder="Optional team description"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="team_admin_id"><i class="fas fa-user-tie"></i> Team Admin</label>
                        <select name="team_admin_id" id="team_admin_id" required>
                            <option value="">Select Team Admin</option>
                            <?php foreach ($available_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name']); ?> 
                                    (<?php echo htmlspecialchars($user['email']); ?>) 
                                    - <?php echo ucfirst($user['role']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-add">
                        <i class="fas fa-plus"></i> Create Team
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Teams List -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-users-cog"></i> Team Management</h2>
            </div>
            <div class="card-body">
                <?php if (empty($teams)): ?>
                    <p>No teams found. Create your first team above.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Team Name</th>
                                    <th>Description</th>
                                    <th>Team Admin</th>
                                    <th>Members</th>
                                    <th>Tasks</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $team): ?>
                                <tr>
                                    <td><?php echo $team['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($team['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($team['description'] ?: 'No description'); ?></td>
                                    <td>
                                        <?php if (!empty($team['team_admin_name'])): ?>
                                            <?php echo htmlspecialchars($team['team_admin_name']); ?>
                                        <?php else: ?>
                                            <em>No admin assigned</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="stats-badge"><?php echo $team['member_count']; ?> members</span>
                                    </td>
                                    <td>
                                        <span class="stats-badge"><?php echo $team['task_count']; ?> tasks</span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($team['created_at'])); ?></td>
                                    <td class="actions">
                                        <button type="button" class="btn btn-view" onclick="showTeamMembersModal(<?php echo $team['id']; ?>, '<?php echo htmlspecialchars($team['name']); ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <a href="edit-team.php?id=<?php echo $team['id']; ?>" class="btn btn-edit"><i class="fas fa-edit"></i> Edit</a>
                                        <a href="manage-teams.php?action=delete&id=<?php echo $team['id']; ?>" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete this team?');"><i class="fas fa-trash"></i> Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Team Activity Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-clock"></i> Recent Team Activity</h2>
                <span class="stats-badge"><?php echo count($recent_activities); ?> activities today</span>
            </div>
            <div class="card-body">
                <?php if (empty($recent_activities)): ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <i class="fas fa-clock" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>No team activity today.</p>
                        <p style="font-size: 0.9rem; margin-top: 0.5rem;">Employee activities will appear here when they update tasks, send messages, or interact with the system.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-container" style="max-height: 400px; overflow-y: auto;">
                        <?php foreach (array_slice($recent_activities, 0, 10) as $index => $activity): ?>
                        <div class="activity-item activity-<?php echo $activity['type']; ?>" style="display: flex; align-items: flex-start; gap: 1rem; padding: 1rem; margin-bottom: 1rem; background-color: var(--bg-secondary); border-radius: 8px; border-left: 4px solid var(--primary);">
                            <div class="activity-icon" style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; flex-shrink: 0;">
                                <?php
                                switch ($activity['type']) {
                                    case 'task_completed':
                                    case 'task_complete':
                                        echo '<i class="fas fa-check"></i>';
                                        break;
                                    case 'task_started':
                                        echo '<i class="fas fa-play"></i>';
                                        break;
                                    case 'subtask_completed':
                                        echo '<i class="fas fa-tasks"></i>';
                                        break;
                                    case 'subtask_started':
                                        echo '<i class="fas fa-list"></i>';
                                        break;
                                    case 'deadline_approaching':
                                        echo '<i class="fas fa-calendar-alt"></i>';
                                        break;
                                    case 'message_sent':
                                        echo '<i class="fas fa-envelope"></i>';
                                        break;
                                    case 'status_change':
                                    case 'status_update':
                                        echo '<i class="fas fa-sync"></i>';
                                        break;
                                    case 'task_created':
                                        echo '<i class="fas fa-plus"></i>';
                                        break;
                                    case 'task_assigned':
                                        echo '<i class="fas fa-user-plus"></i>';
                                        break;
                                    case 'user_login':
                                        echo '<i class="fas fa-sign-in-alt"></i>';
                                        break;
                                    default:
                                        echo '<i class="fas fa-bell"></i>';
                                }
                                ?>
                            </div>
                            <div class="activity-content" style="flex: 1;">
                                <div class="activity-title" style="font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem; line-height: 1.3;">
                                    <?php
                                    $user_role_badge = '';
                                    if (isset($activity['user_role'])) {
                                        $role_color = '';
                                        $role_abbreviation = '';
                                        switch ($activity['user_role']) {
                                            case 'admin':
                                                $role_color = 'var(--danger)';
                                                $role_abbreviation = 'Admin';
                                                break;
                                            case 'team_admin':
                                                $role_color = 'var(--warning)';
                                                $role_abbreviation = 'TA';
                                                break;
                                            case 'employee':
                                                $role_color = 'var(--info)';
                                                $role_abbreviation = 'EMP';
                                                break;
                                        }
                                        $user_role_badge = "<span style='color: {$role_color}; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; background: rgba(255,255,255,0.1); padding: 0.2rem 0.5rem; border-radius: 10px; margin-right: 0.5rem;'>[{$role_abbreviation}]</span>";
                                    }

                                    switch ($activity['type']) {
                                        case 'task_completed':
                                        case 'task_complete':
                                            echo "{$user_role_badge}{$activity['user']} <span style='color: var(--success); font-weight: bold;'>completed</span> task";
                                            break;
                                        case 'status_change':
                                        case 'status_update':
                                            echo "{$user_role_badge}{$activity['user']} <span style='color: var(--warning); font-weight: bold;'>updated</span> task status";
                                            break;
                                        case 'subtask_completed':
                                            echo "{$user_role_badge}{$activity['user']} <span style='color: var(--success); font-weight: bold;'>completed</span> a subtask";
                                            break;
                                        case 'task_created':
                                            echo "{$user_role_badge}{$activity['user']} <span style='color: var(--primary); font-weight: bold;'>created</span> a new task";
                                            break;
                                        case 'task_assigned':
                                            echo "Task <span style='color: var(--info); font-weight: bold;'>assigned</span> to {$user_role_badge}{$activity['user']}";
                                            break;
                                        case 'message_sent':
                                            echo "{$user_role_badge}{$activity['user']} <span style='color: var(--info); font-weight: bold;'>sent</span> a message";
                                            break;
                                        case 'user_login':
                                            echo "{$user_role_badge}{$activity['user']} <span style='color: var(--success); font-weight: bold;'>logged in</span>";
                                            break;
                                        default:
                                            echo "{$user_role_badge}Activity by {$activity['user']}";
                                            break;
                                    }
                                    ?>
                                </div>
                                <div class="activity-detail" style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                                    <?php if ($activity['type'] === 'message_sent'): ?>
                                        <div style="background: rgba(54, 185, 204, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--info);">
                                            <strong style="color: var(--primary);">📧 To: <?php echo htmlspecialchars($activity['receiver']); ?></strong>
                                            <?php if (isset($activity['receiver_role']) && !empty($activity['receiver_role'])): ?>
                                                <span style="color: var(--text-secondary); font-size: 0.7rem; text-transform: uppercase; margin-left: 0.5rem;">[<?php echo $activity['receiver_role']; ?>]</span>
                                            <?php endif; ?>
                                            <?php if (isset($activity['receiver_team']) && $activity['receiver_team'] !== 'No Team'): ?>
                                                <span style="color: var(--text-secondary); font-size: 0.7rem; margin-left: 0.5rem;">(<?php echo htmlspecialchars($activity['receiver_team']); ?>)</span>
                                            <?php endif; ?>
                                            <br><small><strong>Subject:</strong> <?php echo htmlspecialchars($activity['details']); ?></small>
                                            <?php if ($activity['task'] !== 'General Communication' && !empty($activity['task'])): ?>
                                                <br><small><strong>Related to:</strong> <?php echo htmlspecialchars($activity['task']); ?></small>
                                            <?php endif; ?>
                                            <?php if (isset($activity['message_content']) && !empty($activity['message_content'])): ?>
                                                <br><small><strong>Preview:</strong> <?php echo htmlspecialchars(substr($activity['message_content'], 0, 80)) . (strlen($activity['message_content']) > 80 ? '...' : ''); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($activity['type'] === 'user_login'): ?>
                                        <div style="background: rgba(28, 200, 138, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--success);">
                                            <strong style="color: var(--success);">🔐 System Access</strong>
                                            <?php if (isset($activity['team_name']) && $activity['team_name'] !== 'No Team'): ?>
                                                <span style="color: var(--text-secondary); font-size: 0.8rem; margin-left: 0.5rem;">Team: <?php echo htmlspecialchars($activity['team_name']); ?></span>
                                            <?php endif; ?>
                                            <br><small>User successfully logged into the system</small>
                                        </div>
                                    <?php else: ?>
                                        <div style="background: rgba(142, 36, 170, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--primary);">
                                            <strong style="color: var(--text-main);">📋 <?php echo htmlspecialchars($activity['task']); ?></strong>
                                            <?php if (isset($activity['team_name']) && $activity['team_name'] !== 'No Team'): ?>
                                                <span style="color: var(--text-secondary); font-size: 0.8rem; margin-left: 0.5rem;">Team: <?php echo htmlspecialchars($activity['team_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if (isset($activity['old_status']) && isset($activity['new_status']) && in_array($activity['type'], ['status_change', 'status_update'])): ?>
                                                <br><small>Status changed from <span style="color: var(--warning); font-weight: bold;">"<?php echo htmlspecialchars($activity['old_status']); ?>"</span> 
                                                <span style="color: var(--text-secondary); font-weight: bold; font-style: italic;">to</span> 
                                                <span style="color: var(--success); font-weight: bold;">"<?php echo htmlspecialchars($activity['new_status']); ?>"</span></small>
                                            <?php endif; ?>
                                            <?php if (isset($activity['details']) && !empty($activity['details']) && !in_array($activity['type'], ['status_change', 'status_update'])): ?>
                                                <br><small><?php echo htmlspecialchars($activity['details']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta" style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: var(--text-secondary);">
                                    <span class="activity-time"><?php echo $activity['time']; ?></span>
                                    <?php if (isset($activity['priority'])): ?>
                                        <span class="activity-priority" style="padding: 0.2rem 0.5rem; border-radius: 10px; font-weight: 600; text-transform: uppercase; 
                                            <?php 
                                            switch($activity['priority']) {
                                                case 'high': echo 'background: rgba(231, 74, 59, 0.2); color: var(--danger);'; break;
                                                case 'medium': echo 'background: rgba(246, 194, 62, 0.2); color: var(--warning);'; break;
                                                default: echo 'background: rgba(54, 185, 204, 0.2); color: var(--info);'; break;
                                            }
                                            ?>">
                                            <?php echo $activity['priority']; ?> Priority
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($recent_activities) > 10): ?>
                            <div style="text-align: center; padding: 1rem; border-top: 1px solid var(--border-color); margin-top: 1rem;">
                                <p style="color: var(--text-secondary); font-size: 0.9rem;">
                                    <i class="fas fa-info-circle"></i> 
                                    Showing latest 10 activities. <?php echo count($recent_activities) - 10; ?> more activities occurred today.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Team Members Modal -->
    <div id="teamMembersModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-users"></i> <span id="teamMembersTitle">Team Members</span></h3>
                <span class="close" onclick="closeTeamMembersModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="teamMembersList">
                    <!-- Team members will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- User Tasks Modal -->
    <div id="userTasksModal" class="modal" style="display: none;">
        <div class="modal-content user-tasks-modal">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> <span id="userTasksTitle">User Tasks</span></h3>
                <span class="close" onclick="closeUserTasksModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="user-tasks-container">
                    <div class="tasks-section">
                        <h4><i class="fas fa-list-check"></i> Assigned Tasks</h4>
                        <div id="userTasksList" class="tasks-list">
                            <!-- Tasks will be loaded here -->
                        </div>
                    </div>
                    
                    <div class="activity-section">
                        <h4><i class="fas fa-history"></i> Recent Activity</h4>
                        <div id="userActivityList" class="activity-list">
                            <!-- Activity will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTeamMembersModal(teamId, teamName) {
            document.getElementById('teamMembersTitle').textContent = teamName + ' - Team Members';
            document.getElementById('teamMembersModal').style.display = 'block';
            
            // Show loading state
            document.getElementById('teamMembersList').innerHTML = '<div class="empty-members"><i class="fas fa-spinner fa-spin"></i><p>Loading team members...</p></div>';
            
            // Fetch team members
            fetch(`manage-teams.php?action=get_team_members&team_id=${teamId}`)
                .then(response => response.json())
                .then(data => {
                    displayTeamMembers(data.members);
                })
                .catch(error => {
                    console.error('Error fetching team members:', error);
                    document.getElementById('teamMembersList').innerHTML = '<div class="empty-members"><i class="fas fa-exclamation-triangle"></i><p>Error loading team members</p></div>';
                });
        }
        
        function closeTeamMembersModal() {
            document.getElementById('teamMembersModal').style.display = 'none';
        }
        
        function displayTeamMembers(members) {
            const membersContainer = document.getElementById('teamMembersList');
            
            if (members.length === 0) {
                membersContainer.innerHTML = `
                    <div class="empty-members">
                        <i class="fas fa-users-slash"></i>
                        <h3>No Team Members</h3>
                        <p>No members have been assigned to this team yet.</p>
                    </div>
                `;
                return;
            }
            
                         const membersHTML = members.map(member => `
                 <div class="member-card">
                     <div class="member-info">
                         <div class="member-avatar">
                             ${member.full_name.charAt(0).toUpperCase()}
                         </div>
                         <div class="member-details">
                             <h4>${escapeHtml(member.full_name)}</h4>
                             <p>${escapeHtml(member.email)}</p>
                         </div>
                     </div>
                     
                     <div class="member-meta">
                         <span class="role-badge role-${member.role.toLowerCase()}">
                             ${member.role === 'team_admin' ? '<i class="fas fa-crown"></i>' : 
                               member.role === 'admin' ? '<i class="fas fa-user-shield"></i>' : 
                               '<i class="fas fa-user"></i>'}
                             ${member.role.replace('_', ' ').toUpperCase()}
                         </span>
                         
                         <span class="status-badge status-${member.status.toLowerCase()}">
                             ${member.status.toUpperCase()}
                         </span>
                     </div>
                     
                     <div class="member-actions">
                         <button type="button" class="btn-view-tasks" onclick="showUserTasksModal(${member.id}, '${escapeHtml(member.full_name)}')">
                             <i class="fas fa-tasks"></i> View Tasks
                         </button>
                     </div>
                 </div>
             `).join('');
            
            membersContainer.innerHTML = `<div class="members-grid">${membersHTML}</div>`;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('teamMembersModal');
            if (event.target === modal) {
                closeTeamMembersModal();
            }
        }
        
                 // Close modal with Escape key
         document.addEventListener('keydown', function(event) {
             if (event.key === 'Escape') {
                 closeTeamMembersModal();
                 closeUserTasksModal();
             }
         });
         
         // User Tasks Modal Functions
         function showUserTasksModal(userId, userName) {
             document.getElementById('userTasksTitle').textContent = userName + "'s Tasks";
             document.getElementById('userTasksModal').style.display = 'block';
             
             // Show loading state
             document.getElementById('userTasksList').innerHTML = '<div class="no-tasks">Loading tasks...</div>';
             document.getElementById('userActivityList').innerHTML = '<div class="no-activity">Loading activity...</div>';
             
             // Fetch user tasks and activity
             fetch(`manage-teams.php?action=get_user_tasks&user_id=${userId}`)
                 .then(response => response.json())
                 .then(data => {
                     displayUserTasks(data.tasks);
                     displayUserActivity(data.activities);
                 })
                 .catch(error => {
                     console.error('Error fetching user data:', error);
                     document.getElementById('userTasksList').innerHTML = '<div class="no-tasks">Error loading tasks</div>';
                     document.getElementById('userActivityList').innerHTML = '<div class="no-activity">Error loading activity</div>';
                 });
         }
         
         function closeUserTasksModal() {
             document.getElementById('userTasksModal').style.display = 'none';
         }
         
         function displayUserTasks(tasks) {
             const tasksContainer = document.getElementById('userTasksList');
             
             if (tasks.length === 0) {
                 tasksContainer.innerHTML = '<div class="no-tasks">No tasks assigned to this user</div>';
                 return;
             }
             
             const tasksHTML = tasks.map(task => {
                 const subtasksHTML = task.subtasks && task.subtasks.length > 0 
                     ? task.subtasks.map(subtask => `
                         <div class="subtask-item">
                             <input type="checkbox" class="subtask-checkbox" ${subtask.status === 'done' ? 'checked' : ''} disabled>
                             <span class="subtask-text ${subtask.status === 'done' ? 'subtask-completed' : ''}">
                                 ${escapeHtml(subtask.title || 'Untitled Subtask')}
                             </span>
                         </div>
                     `).join('')
                     : '<div class="no-subtasks">No subtasks</div>';
                 
                 return `
                     <div class="task-item">
                         <div class="task-title">${escapeHtml(task.title)}</div>
                                                   <div class="task-meta">
                              <span class="task-status status-${getStatusClass(task.status)}">
                                  ${task.status === 'needs_approval' ? 'Need Approval' : (task.status || 'Unknown')}
                              </span>
                              <span>Priority: ${task.priority || 'Normal'}</span>
                              ${(task.status === 'needs_approval' || task.status === 'completed') ? 
                                  `<button type="button" class="btn-approve-task" onclick="approveTask(${task.id})">Need Approval</button>` : 
                                  ''}
                          </div>
                         <div class="task-description">${escapeHtml(task.description || 'No description')}</div>
                         <div class="task-dates">
                             Created: ${formatDate(task.created_at)} | 
                             Due: ${task.deadline ? formatDate(task.deadline) : 'No due date'}
                         </div>
                         <div class="subtasks-section">
                             <div class="subtasks-title">
                                 <i class="fas fa-list-ul"></i>
                                 Subtasks (${task.subtasks ? task.subtasks.length : 0})
                             </div>
                             <div class="subtasks-list">
                                 ${subtasksHTML}
                             </div>
                         </div>
                     </div>
                 `;
             }).join('');
             
             tasksContainer.innerHTML = tasksHTML;
         }
         
         function displayUserActivity(activities) {
             const activityContainer = document.getElementById('userActivityList');
             
             if (activities.length === 0) {
                 activityContainer.innerHTML = '<div class="no-activity">No recent activity found</div>';
                 return;
             }
             
             const activityHTML = activities.map(activity => {
                 let actionText = activity.action_type || activity.action || 'Activity';
                 
                 let isStatusChange = false;
                 
                 // Format status change activities
                 if (activity.old_status && activity.new_status && activity.old_status !== activity.new_status) {
                     const oldStatusFormatted = formatStatusForDisplay(activity.old_status);
                     const newStatusFormatted = formatStatusForDisplay(activity.new_status);
                     actionText = `Status changed from ${oldStatusFormatted} <span class="status-to">to</span> ${newStatusFormatted}`;
                     isStatusChange = true;
                 }
                 
                 return `
                     <div class="activity-item">
                         <div class="activity-action ${isStatusChange ? 'status-change' : ''}">${isStatusChange ? actionText : escapeHtml(actionText)}</div>
                         ${activity.task_title ? `<div class="activity-task">Task: ${escapeHtml(activity.task_title)}</div>` : ''}
                         <div class="activity-time">${formatDate(activity.created_at)}</div>
                     </div>
                 `;
             }).join('');
             
             activityContainer.innerHTML = activityHTML;
         }
         
         function formatStatusForDisplay(status) {
             if (!status) return 'Unknown';
             switch (status.toLowerCase()) {
                 case 'to_do': return 'To Do';
                 case 'in_progress': return 'In Progress';
                 case 'completed': return 'Completed';
                 case 'needs_approval': return 'Need Approval';
                 case 'archived': return 'Archived';
                 default: return status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
             }
         }
         
                   function getStatusClass(status) {
              if (!status) return 'pending';
              const statusLower = status.toLowerCase();
              if (statusLower.includes('complete')) return 'completed';
              if (statusLower.includes('needs_approval') || statusLower.includes('approval')) return 'needs-approval';
              if (statusLower.includes('progress')) return 'in-progress';
              return 'pending';
          }
         
         function formatDate(dateString) {
             if (!dateString) return 'Unknown';
             const date = new Date(dateString);
             return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
         }
         
         function approveTask(taskId) {
             if (!confirm('Are you sure you want to approve and complete this task?')) {
                 return;
             }
             
             // Create a form to submit the approval
             const form = document.createElement('form');
             form.method = 'POST';
             form.action = 'update_task_status.php';
             form.style.display = 'none';
             
             const taskIdInput = document.createElement('input');
             taskIdInput.type = 'hidden';
             taskIdInput.name = 'task_id';
             taskIdInput.value = taskId;
             
             const statusInput = document.createElement('input');
             statusInput.type = 'hidden';
             statusInput.name = 'new_status';
             statusInput.value = 'completed';
             
             const redirectInput = document.createElement('input');
             redirectInput.type = 'hidden';
             redirectInput.name = 'redirect_to';
             redirectInput.value = window.location.href;
             
             form.appendChild(taskIdInput);
             form.appendChild(statusInput);
             form.appendChild(redirectInput);
             document.body.appendChild(form);
             form.submit();
         }
     </script>

</body>
</html>
