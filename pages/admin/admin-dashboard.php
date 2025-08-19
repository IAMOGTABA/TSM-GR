<?php
// admin-dashboard.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Get user data for sidebar
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get task statistics (excluding archived tasks)
$stats = [];

// Total Tasks
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks");
$stmt->execute();
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Completed Tasks
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = 'completed'");
$stmt->execute();
$stats['completed'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending Tasks (to_do + in_progress)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status IN ('to_do', 'in_progress')");
$stmt->execute();
$stats['pending'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Overdue Tasks
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE deadline < :today AND status != 'completed'");
$stmt->execute(['today' => $today]);
$stats['overdue'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent active tasks with proper deadline display (excluding archived tasks)
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS assigned_user,
           CASE 
               WHEN t.deadline IS NULL THEN 'No deadline'
               WHEN t.deadline < CURDATE() AND t.status != 'completed' THEN CONCAT('Overdue: ', DATE_FORMAT(t.deadline, '%M %d, %Y'))
               WHEN t.deadline = CURDATE() THEN 'Due today'
               ELSE DATE_FORMAT(t.deadline, '%M %d, %Y')
           END as deadline_display
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.archived = 0
    ORDER BY 
        CASE WHEN t.status = 'completed' THEN 0 ELSE 1 END,
        t.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch real recent activity data from activity_logs and messages (resets daily at 12 AM)
$recent_activities = [];

try {
    // Get activities from today only (resets at 12 AM)
    $activity_stmt = $pdo->prepare("
        SELECT al.*, u.full_name as user_name, t.title as task_title, t.id as task_id, 'activity' as source_type
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        JOIN tasks t ON al.task_id = t.id
        WHERE DATE(al.created_at) = CURDATE()
        ORDER BY al.created_at DESC
        LIMIT 30
    ");
    $activity_stmt->execute();
    $activity_logs = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get messages from today
    try {
        $message_stmt = $pdo->prepare("
            SELECT m.*, sender.full_name as sender_name, receiver.full_name as receiver_name, 
                   t.title as task_title, t.id as task_id, 'message' as source_type,
                   m.sent_at as created_at
            FROM messages m
            JOIN users sender ON m.sender_id = sender.id
            LEFT JOIN users receiver ON m.recipient_id = receiver.id
            LEFT JOIN tasks t ON m.task_id = t.id
            WHERE DATE(m.sent_at) = CURDATE()
            ORDER BY m.sent_at DESC
            LIMIT 30
        ");
        $message_stmt->execute();
        $message_logs = $message_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine activities and messages
        $all_logs = array_merge($activity_logs, $message_logs);
        
        // Sort by created_at/sent_at
        usort($all_logs, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
    } catch (PDOException $e) {
        // Messages table might not exist, use only activity logs
        $all_logs = $activity_logs;
    }

    foreach ($all_logs as $log) {
        if ($log['source_type'] === 'message') {
            $recent_activities[] = [
                'type' => 'message_sent',
                'user' => $log['sender_name'],
                'task' => $log['task_title'] ?? 'General',
                'details' => $log['subject'],
                'message_content' => $log['message'],
                'receiver' => $log['receiver_name'] ?? 'All Users',
                'time' => date('g:i A', strtotime($log['created_at'])),
                'task_id' => $log['task_id']
            ];
        } else {
            $recent_activities[] = [
                'type' => $log['action_type'],
                'user' => $log['user_name'],
                'task' => $log['task_title'],
                'details' => $log['details'],
                'old_status' => $log['old_status'],
                'new_status' => $log['new_status'],
                'time' => date('g:i A', strtotime($log['created_at'])),
                'task_id' => $log['task_id']
            ];
        }
    }
} catch (PDOException $e) {
    // If activity_logs table doesn't exist yet, fall back to old method
    error_log("Activity logs error: " . $e->getMessage());
}

// If no activities from logs, show some fallback activities
if (empty($recent_activities)) {
    // Fallback: Recently completed tasks from today (excluding archived tasks)
    $fallback_stmt = $pdo->prepare("
        SELECT t.id as task_id, t.title as task_title, u.full_name as user_name, t.created_at
        FROM tasks t 
        JOIN users u ON t.assigned_to = u.id
        WHERE t.status = 'completed' AND DATE(t.created_at) = CURDATE()
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    try {
        $fallback_stmt->execute();
        $fallback_tasks = $fallback_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fallback_tasks as $task) {
            $recent_activities[] = [
                'type' => 'task_completed',
                'user' => $task['user_name'],
                'task' => $task['task_title'],
                'time' => date('g:i A', strtotime($task['created_at'])),
                'task_id' => $task['task_id']
            ];
        }
    } catch (PDOException $e) {
        error_log("Fallback activities error: " . $e->getMessage());
    }
}

// ENHANCED ANALYTICS - NEW ADDITIONS
// 1. Task completion rate over time (last 7 days)
$completion_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE DATE(created_at) = :date AND status = 'completed'");
    $stmt->execute(['date' => $date]);
    $completion_data[] = [
        'date' => date('M j', strtotime($date)),
        'count' => $stmt->fetch(PDO::FETCH_ASSOC)['count']
    ];
}

// 2. Task distribution by priority (excluding archived tasks)
$priority_stmt = $pdo->prepare("
    SELECT priority, COUNT(*) as count 
    FROM tasks 

    GROUP BY priority
");
$priority_stmt->execute();
$priority_data = $priority_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Enhanced Employee performance metrics with detailed task information (excluding archived tasks)
$employee_performance = $pdo->prepare("
    SELECT u.full_name, u.id,
           COUNT(t.id) as total_assigned,
           SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN t.deadline < CURDATE() AND t.status != 'completed' THEN 1 ELSE 0 END) as overdue,
           ROUND(AVG(CASE WHEN t.status = 'completed' THEN 100 ELSE 0 END), 1) as completion_rate
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role = 'employee'
    GROUP BY u.id, u.full_name
    ORDER BY completion_rate DESC
");
$employee_performance->execute();
$employee_stats = $employee_performance->fetchAll(PDO::FETCH_ASSOC);

// Get detailed completed tasks for each employee
$employee_completed_tasks = [];
foreach ($employee_stats as $employee) {
    $completed_tasks_stmt = $pdo->prepare("
        SELECT t.id, t.title, t.description, t.created_at
        FROM tasks t
        WHERE t.assigned_to = :employee_id AND t.status = 'completed'
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $completed_tasks_stmt->execute(['employee_id' => $employee['id']]);
    $employee_completed_tasks[$employee['id']] = $completed_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. Weekly task creation vs completion
$weekly_stats = $pdo->prepare("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as created,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM tasks 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date
");
$weekly_stats->execute();
$weekly_data = $weekly_stats->fetchAll(PDO::FETCH_ASSOC);

// 5. System health metrics
$system_health = [
    'active_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'tasks_this_week' => $pdo->query("SELECT COUNT(*) FROM tasks WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn(),
    'messages_today' => 0
];

try {
    $system_health['messages_today'] = $pdo->query("SELECT COUNT(*) FROM messages WHERE DATE(sent_at) = CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    // Messages table might not exist
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
        
        .welcome-text {
            color: var(--dark);
            font-size: 1.75rem;
            font-weight: 500;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            padding: 1.5rem;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            background-color: var(--bg-card);
            border-left: 0.25rem solid;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card-primary {
            border-left-color: var(--primary);
        }
        
        .stat-card-success {
            border-left-color: var(--success);
        }
        
        .stat-card-warning {
            border-left-color: var(--warning);
        }
        
        .stat-card-danger {
            border-left-color: var(--danger);
        }
        
        .stat-card .label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
        }
        
        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .stat-card .icon {
            font-size: 2rem;
            opacity: 0.3;
            color: var(--text-main);
        }
        
        .stat-card-primary .icon {
            color: var(--primary);
        }
        
        .stat-card-success .icon {
            color: var(--success);
        }
        
        .stat-card-warning .icon {
            color: var(--warning);
        }
        
        .stat-card-danger .icon {
            color: var(--danger);
        }
        
        .card {
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            margin-bottom: 2rem;
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
        
        /* Task Card Styles */
        .task-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .task-card {
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.3);
            transition: transform 0.2s;
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }
        
        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.4);
        }
        
        .task-card.priority-high {
            border-top-color: var(--danger);
        }
        
        .task-card.priority-medium {
            border-top-color: var(--warning);
        }
        
        .task-card.priority-low {
            border-top-color: var(--success);
        }
        
        .task-card-body {
            padding: 1.25rem;
        }
        
        .task-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--dark);
        }
        
        .task-card-info {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
        }
        
        .task-card-info i {
            width: 1.5rem;
            color: var(--primary);
            margin-right: 0.5rem;
        }
        
        .task-card-footer {
            padding: 0.75rem 1.25rem;
            background-color: var(--bg-main);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .task-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Activity Notification Styles */
        .activity-container {
            display: flex;
            flex-direction: column;
        }
        
        .activity-item {
            padding: 1rem;
            border-left: 3px solid;
            margin-bottom: 1rem;
            background-color: var(--bg-secondary);
            border-radius: 0 0.35rem 0.35rem 0;
            transition: transform 0.2s;
            position: relative;
        }
        
        .activity-item:hover {
            transform: translateX(5px);
        }
        
        .activity-item:last-child {
            margin-bottom: 0;
        }
        
        .activity-icon {
            position: absolute;
            left: -12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: var(--bg-main);
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 0 0.15rem 0.5rem 0 rgba(0, 0, 0, 0.5);
        }
        
        .activity-content {
            margin-left: 1rem;
        }
        
        .activity-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--dark);
        }
        
        .activity-detail {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
            font-style: italic;
        }
        
        .activity-task_completed {
            border-left-color: var(--success);
        }
        
        .activity-task_started {
            border-left-color: var(--info);
        }
        
        .activity-subtask_completed {
            border-left-color: var(--primary);
        }
        
        .activity-subtask_started {
            border-left-color: var(--primary-light);
        }
        
        .activity-deadline_approaching {
            border-left-color: var(--warning);
        }
        
        .activity-message_sent {
            border-left-color: var(--info);
        }
        
        .activity-status_change {
            border-left-color: var(--warning);
        }
        
        .activity-task_created {
            border-left-color: var(--primary);
        }
        
        .activity-task_assigned {
            border-left-color: var(--info);
        }
        
        .activity-icon i {
            font-size: 0.8rem;
        }
        
        .activity-task_completed .activity-icon {
            color: var(--success);
        }
        
        .activity-task_started .activity-icon {
            color: var(--info);
        }
        
        .activity-subtask_completed .activity-icon {
            color: var(--primary);
        }
        
        .activity-subtask_started .activity-icon {
            color: var(--primary-light);
        }
        
        .activity-deadline_approaching .activity-icon {
            color: var(--warning);
        }
        
        .activity-message_sent .activity-icon {
            color: var(--info);
        }
        
        .activity-status_change .activity-icon {
            color: var(--warning);
        }
        
        .activity-task_created .activity-icon {
            color: var(--primary);
        }
        
        .activity-task_assigned .activity-icon {
            color: var(--info);
        }
        
        /* View More Activity Styles */
        .view-more-container {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-view-more {
            background: linear-gradient(45deg, var(--primary), var(--primary-light));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 auto;
        }
        
        .btn-view-more:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(106, 13, 173, 0.4);
            background: linear-gradient(45deg, var(--primary-light), var(--primary));
        }
        
        .btn-view-more i {
            transition: transform 0.3s ease;
        }
        
        .btn-view-more:hover i {
            transform: translateY(2px);
        }
        
        /* Activity Modal Styles */
        .activity-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 9999;
            display: none;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .activity-modal-overlay.show {
            display: block;
            opacity: 1;
        }
        
        .activity-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.8);
            background-color: var(--bg-card);
            border-radius: 1rem;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.7);
            max-width: 90vw;
            max-height: 85vh;
            width: 800px;
            z-index: 10000;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border-color);
        }
        
        .activity-modal-overlay.show .activity-modal {
            transform: translate(-50%, -50%) scale(1);
        }
        
        .activity-modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, var(--bg-secondary), var(--bg-card));
            border-radius: 1rem 1rem 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-light);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .activity-modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .activity-modal-close:hover {
            background-color: var(--danger);
            color: white;
            transform: rotate(90deg);
        }
        
        .activity-modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary) var(--bg-secondary);
        }
        
        .activity-modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .activity-modal-body::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 4px;
        }
        
        .activity-modal-body::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }
        
        .activity-modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--primary-light);
        }
        
        .modal-activity-item {
            padding: 1rem;
            border-left: 3px solid;
            margin-bottom: 1rem;
            background-color: var(--bg-secondary);
            border-radius: 0 0.5rem 0.5rem 0;
            transition: all 0.3s ease;
            position: relative;
            opacity: 0;
            transform: translateX(-20px);
            animation: slideInActivity 0.6s ease forwards;
        }
        
        .modal-activity-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.2);
        }
        
        @keyframes slideInActivity {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .page-blur {
            filter: blur(5px);
            transition: filter 0.4s ease;
        }
        
        .tasks-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .tasks-table th {
            background-color: var(--bg-secondary);
            text-align: left;
            padding: 0.75rem;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 1px solid var(--border-color);
        }
        
        .tasks-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .tasks-table tr:hover {
            background-color: var(--bg-secondary);
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending {
            background-color: rgba(231, 74, 59, 0.2);
            color: var(--danger);
        }
        
        .status-in_progress {
            background-color: rgba(54, 185, 204, 0.2);
            color: var(--info);
        }
        
        .status-completed {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
        }
        
        .clickable-completed {
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .clickable-completed:hover {
            background-color: rgba(28, 200, 138, 0.4);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(28, 200, 138, 0.3);
        }
        
        .clickable-completed:active {
            transform: scale(0.95);
        }
        
        .clickable-completed::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .clickable-completed:hover::before {
            left: 100%;
        }
        
        .priority-high {
            color: var(--danger);
            font-weight: 600;
        }
        
        .priority-medium {
            color: var(--warning);
        }
        
        .priority-low {
            color: var(--success);
        }
        
        .overdue {
            color: var(--danger);
            font-weight: 600;
        }
        
        .view-btn {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background-color: var(--primary);
            color: white;
            border-radius: 0.25rem;
            text-decoration: none;
            font-size: 0.8rem;
            transition: background-color 0.2s;
        }
        
        .view-btn:hover {
            background-color: var(--primary-dark);
        }
        
        .logout-btn {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            background-color: var(--bg-secondary);
            color: var(--text-main);
            border-radius: 0.35rem;
            text-decoration: none;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        
        .logout-btn:hover {
            background-color: var(--primary-dark);
            color: white;
        }
        
        /* Dashboard Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                min-height: auto;
                position: relative;
            }
            
            .sidebar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .sidebar-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .sidebar-menu li {
                margin: 0.25rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .task-cards-container {
                grid-template-columns: 1fr;
            }
            
            .analytics-bottom-section {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 300px;
            }
            
            .wide-card {
                grid-column: span 1;
            }
            
            .table-header,
            .table-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .table-header > div:not(:first-child),
            .table-row > div:not(:first-child) {
                display: none;
            }
        }
        
        /* Add CSS for the reply button in the style section */
        .message-actions {
            margin-top: 8px;
        }
        
        .reply-btn {
            display: inline-block;
            padding: 4px 8px;
            font-size: 0.75rem;
            background-color: var(--primary);
            color: white;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        
        .reply-btn:hover {
            background-color: var(--primary-dark);
        }
        
        /* Analytics Section Styles */
        .analytics-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: var(--text-main);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .section-title i {
            color: var(--primary);
        }
        
        .analytics-grid {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .analytics-top-section {
            width: 100%;
        }
        
        .analytics-bottom-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }
        
        .analytics-card {
            background-color: var(--bg-card);
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.3);
        }
        
        .chart-card {
            background-color: var(--bg-card);
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.3);
            width: 100%;
        }
        
        .chart-info {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .chart-container {
            height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        /* Priority Chart Styles */
        .priority-chart {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .priority-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .priority-info {
            display: flex;
            flex-direction: column;
            min-width: 80px;
        }
        
        .priority-label {
            font-weight: 600;
        }
        
        .priority-count {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .priority-bar {
            flex: 1;
            height: 8px;
            background-color: var(--bg-secondary);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .priority-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .priority-high-bg { background-color: var(--danger); }
        .priority-medium-bg { background-color: var(--warning); }
        .priority-low-bg { background-color: var(--success); }
        
        .priority-percentage {
            font-size: 0.9rem;
            font-weight: 600;
            min-width: 40px;
            text-align: right;
        }
        
        /* Performance Table Styles */
        .performance-table {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .table-header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1.5fr;
            gap: 1rem;
            padding: 0.75rem;
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--primary-light);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1.5fr;
            gap: 1rem;
            padding: 0.75rem;
            border-radius: 0.35rem;
            transition: background-color 0.2s;
            border: 1px solid transparent;
        }
        
        .table-row:hover {
            background-color: var(--bg-secondary);
            border: 1px solid var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(106, 13, 173, 0.1);
        }
        
        .employee-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .employee-info i {
            color: var(--primary);
            font-size: 1.5rem;
        }
        
        .employee-info div {
            display: flex;
            flex-direction: column;
        }
        
        .employee-info strong {
            font-size: 1rem;
            color: var(--text-main);
        }
        
        .stat-number {
            display: flex;
            align-items: center;
            font-weight: 600;
        }
        
        .stat-number.success { color: var(--success); }
        .stat-number.danger { color: var(--danger); }
        
        .completion-rate {
            display: flex;
            align-items: center;
            font-weight: 600;
        }
        
        .performance-bar {
            height: 8px;
            background-color: var(--bg-secondary);
            border-radius: 4px;
            overflow: hidden;
            align-self: center;
        }
        
        .performance-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--danger) 0%, var(--warning) 50%, var(--success) 100%);
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fillAnimation 1.5s ease-out;
        }
        
        @keyframes fillAnimation {
            from { width: 0% !important; }
            to { width: var(--final-width); }
        }
        
        /* Responsive Performance Table */
        @media (max-width: 768px) {
            .table-header, .table-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
                text-align: left !important;
            }
            
            .table-header div {
                display: none;
            }
            
            .table-row {
                padding: 1rem;
                border: 1px solid var(--border-color);
                margin-bottom: 0.5rem;
            }
            
            .table-row > div {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 0.5rem;
            }
            
            .table-row > div:last-child {
                margin-bottom: 0;
            }
            
            .table-row > div::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--primary-light);
            }
        }
        
        /* System Health Styles */
        .health-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .health-status.online {
            color: var(--success);
        }
        
        .health-status i {
            font-size: 0.6rem;
        }
        
        .health-metrics {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .health-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .health-item i {
            width: 1.5rem;
            text-align: center;
            color: var(--primary);
        }
        
        .health-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        
        .health-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .health-value {
            font-weight: 600;
        }
        
        .health-value.success {
            color: var(--success);
        }
        
        /* Enhanced Employee Performance Styles */
        .employee-performance-section {
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .employee-header {
            background-color: var(--bg-secondary);
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .employee-header .employee-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .employee-header .employee-info h4 {
            margin: 0;
            color: var(--text-main);
            font-size: 1.1rem;
        }
        
        .employee-header .employee-info i {
            font-size: 1.5rem;
            color: var(--primary);
        }
        
        .employee-stats {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .stat-item.success {
            color: var(--success);
        }
        
        .stat-item.danger {
            color: var(--danger);
        }
        
        .stat-item i {
            font-size: 0.8rem;
        }
        
        .completed-tasks-list {
            padding: 1.25rem;
        }
        
        .completed-tasks-list h5 {
            margin: 0 0 1rem 0;
            color: var(--primary-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .completed-tasks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .completed-task-item {
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            padding: 1rem;
            border-left: 4px solid var(--success);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .completed-task-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.25rem 1rem 0 rgba(0, 0, 0, 0.3);
        }
        
        .task-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .task-name strong {
            color: var(--text-main);
            font-size: 1rem;
        }
        
        .task-metadata {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .completion-time,
        .time-spent {
            font-size: 0.85rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .completion-time i,
        .time-spent i {
            color: var(--primary);
            width: 1rem;
        }
        
        .task-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .details-btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
        }
        
        .details-btn:hover {
            background-color: var(--primary-dark);
        }
        
        .view-task-btn {
            background-color: var(--info);
            color: white;
            text-decoration: none;
            padding: 0.4rem 0.8rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
        }
        
        .view-task-btn:hover {
            background-color: #258aa8;
            color: white;
        }
        
        .no-completed-tasks {
            padding: 1.25rem;
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
        }
        
        .no-completed-tasks i {
            margin-right: 0.5rem;
            color: var(--primary);
        }
        
        .text-success {
            color: var(--success) !important;
        }
        
        /* Modal Styles for Task Details */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background-color: var(--bg-card);
            margin: 5% auto;
            padding: 2rem;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 0.5rem 2rem 0 rgba(0, 0, 0, 0.5);
            animation: slideIn 0.3s ease;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-title {
            color: var(--primary-light);
            margin: 0;
            font-size: 1.25rem;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        
        .close-btn:hover {
            background-color: var(--bg-secondary);
            color: var(--text-main);
        }
        
        .modal-body {
            color: var(--text-main);
            line-height: 1.6;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            <li><a href="admin-dashboard.php" class="active page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php" class="page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="manage-teams.php" class="page-link"><i class="fas fa-users-cog"></i> Manage Teams</a></li>
            <li><a href="manage-users.php" class="page-link"><i class="fas fa-users"></i> Manage Users</a></li>
            <li><a href="analysis.php" class="page-link"><i class="fas fa-chart-line"></i> Analysis</a></li>
            <li><a href="messages.php" class="page-link"><i class="fas fa-envelope"></i> Messages</a></li>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="content">
        <div class="header">
            <h1 class="welcome-text">Welcome, <?php echo $_SESSION['full_name']; ?></h1>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card stat-card-primary">
                <div>
                    <div class="label">Total Tasks</div>
                    <div class="value"><?php echo $stats['total']; ?></div>
                </div>
                <i class="fas fa-clipboard-list icon"></i>
            </div>
            
            <div class="stat-card stat-card-success">
                <div>
                    <div class="label">Marking All Done Tasks</div>
                    <div class="value"><?php echo $stats['completed']; ?></div>
                </div>
                <i class="fas fa-check-circle icon"></i>
            </div>
            
            <div class="stat-card stat-card-warning">
                <div>
                    <div class="label">Pending Tasks</div>
                    <div class="value"><?php echo $stats['pending']; ?></div>
                </div>
                <i class="fas fa-clock icon"></i>
            </div>
            
            <div class="stat-card stat-card-danger">
                <div>
                    <div class="label">Overdue Tasks</div>
                    <div class="value"><?php echo $stats['overdue']; ?></div>
                </div>
                <i class="fas fa-exclamation-triangle icon"></i>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <!-- Recent Tasks Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent Tasks</h2>
                    <a href="manage-tasks.php" class="view-btn page-link">Manage Tasks</a>
                </div>
                <div class="card-body">
                    <?php if (count($recent_tasks) > 0): ?>
                    <div class="task-cards-container">
                        <?php foreach ($recent_tasks as $task): 
                            $is_overdue = !empty($task['deadline']) && strtotime($task['deadline']) < strtotime('today') && $task['status'] != 'completed';
                        ?>
                        <div class="task-card priority-<?php echo $task['priority']; ?>">
                            <div class="task-card-body">
                                <h3 class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></h3>
                                
                                <div class="task-card-info">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo htmlspecialchars($task['assigned_user'] ?? 'Unassigned'); ?></span>
                                </div>
                                
                                <div class="task-card-info">
                                    <i class="fas fa-calendar"></i>
                                    <span class="<?php echo (strpos($task['deadline_display'] ?? '', 'Overdue') !== false) ? 'overdue' : ''; ?>">
                                        <?php echo htmlspecialchars($task['deadline_display'] ?? 'No deadline'); ?>
                                    </span>
                                </div>
                                
                                <div class="task-card-info">
                                    <i class="fas fa-flag"></i>
                                    <span class="priority-<?php echo $task['priority']; ?>">
                                        <?php echo ucfirst($task['priority']); ?> Priority
                                    </span>
                                </div>
                            </div>
                            <div class="task-card-footer">
                                <?php if ($task['status'] === 'completed'): ?>
                                    <span class="status-badge status-<?php echo $task['status']; ?> clickable-completed" 
                                          data-task-id="<?php echo $task['id']; ?>" 
                                          onclick="archiveTask(<?php echo $task['id']; ?>)" 
                                          title="Click to archive this completed task">
                                        <i class="fas fa-check-circle"></i> <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-<?php echo $task['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                <?php endif; ?>
                                <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="view-btn page-link">View Details</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p>No tasks found. <a href="add-task.php" class="page-link">Add a task</a> to get started.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Activity Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-clock"></i> Recent Activity</h2>
                    <span class="badge"><?php echo count($recent_activities); ?> new</span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_activities)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-clock" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                            <p>No recent activity today.</p>
                        </div>
                    <?php else: ?>
                        <div class="activity-container" id="activityContainer">
                            <?php foreach (array_slice($recent_activities, 0, 6) as $index => $activity): ?>
                            <div class="activity-item activity-<?php echo $activity['type']; ?>" data-index="<?php echo $index; ?>">
                                <div class="activity-icon">
                                    <?php
                                    switch ($activity['type']) {
                                        case 'task_completed':
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
                                            echo '<i class="fas fa-sync"></i>';
                                            break;
                                        case 'task_created':
                                            echo '<i class="fas fa-plus"></i>';
                                            break;
                                        case 'task_assigned':
                                            echo '<i class="fas fa-user-plus"></i>';
                                            break;
                                        default:
                                            echo '<i class="fas fa-bell"></i>';
                                    }
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php
                                        switch ($activity['type']) {
                                            case 'task_completed':
                                                echo "{$activity['user']} completed task";
                                                break;
                                            case 'status_change':
                                                echo "{$activity['user']} updated task status";
                                                break;
                                            case 'subtask_completed':
                                                echo "{$activity['user']} completed a subtask";
                                                break;
                                            case 'task_created':
                                                echo "{$activity['user']} created a new task";
                                                break;
                                            case 'task_assigned':
                                                echo "Task assigned to {$activity['user']}";
                                                break;
                                            case 'message_sent':
                                                echo "{$activity['user']} sent a message";
                                                break;
                                            default:
                                                echo "Activity by {$activity['user']}";
                                                break;
                                        }
                                        ?>
                                    </div>
                                    <div class="activity-detail">
                                        <?php if ($activity['type'] === 'message_sent'): ?>
                                            <strong>To: <?php echo htmlspecialchars($activity['receiver']); ?></strong>
                                            <br><small>Subject: <?php echo htmlspecialchars($activity['details']); ?></small>
                                            <?php if ($activity['task'] !== 'General'): ?>
                                                <br><small>Related to: <?php echo htmlspecialchars($activity['task']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <strong><?php echo htmlspecialchars($activity['task']); ?></strong>
                                            <?php if (isset($activity['old_status']) && isset($activity['new_status']) && $activity['type'] === 'status_change'): ?>
                                                <br><small>Changed from "<?php echo htmlspecialchars($activity['old_status']); ?>" to "<?php echo htmlspecialchars($activity['new_status']); ?>"</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-time"><?php echo $activity['time']; ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($recent_activities) > 6): ?>
                            <div class="view-more-container">
                                <button class="btn-view-more" onclick="showAllActivities()">
                                    <i class="fas fa-chevron-down"></i>
                                    View More Activities (<?php echo count($recent_activities) - 6; ?> more)
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Analytics Section -->
        <div class="analytics-section">
            <h2 class="section-title"><i class="fas fa-chart-bar"></i> Analytics Overview</h2>
            
            <div class="analytics-grid">
                <!-- Top Section: 7-Day Task Completion Chart (Full Width) -->
                <div class="analytics-top-section">
                    <div class="card chart-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-line"></i> 7-Day Task Completion Trend</h3>
                            <span class="chart-info">Daily task completion overview with trend analysis</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="completionChart" width="800" height="400"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Bottom Section: Three Cards Grid -->
                <div class="analytics-bottom-section">
                    <!-- Task Priority Distribution -->
                    <div class="card analytics-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-flag"></i> Task Priority Distribution</h3>
                            <span class="chart-info">Active tasks by priority</span>
                        </div>
                        <div class="card-body">
                            <div class="priority-chart">
                                <?php if (empty($priority_data)): ?>
                                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                        <i class="fas fa-tasks" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                        <p>No active tasks to analyze</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($priority_data as $priority): 
                                        $percentage = $stats['total'] > 0 ? round(($priority['count'] / $stats['total']) * 100, 1) : 0;
                                    ?>
                                    <div class="priority-item">
                                        <div class="priority-info">
                                            <span class="priority-label priority-<?php echo $priority['priority']; ?>">
                                                <?php echo ucfirst($priority['priority']); ?>
                                            </span>
                                            <span class="priority-count"><?php echo $priority['count']; ?> tasks</span>
                                        </div>
                                        <div class="priority-bar">
                                            <div class="priority-fill priority-<?php echo $priority['priority']; ?>-bg" 
                                                 style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <span class="priority-percentage"><?php echo $percentage; ?>%</span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Employee Performance -->
                    <div class="card analytics-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-users"></i> Employee Performance</h3>
                            <a href="analysis.php" class="view-btn page-link">View Analysis</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($employee_stats)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                    <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                    <p>No employee data available.</p>
                                </div>
                            <?php else: ?>
                                <div class="performance-table">
                                    <div class="table-header">
                                        <div><i class="fas fa-user"></i> Employee</div>
                                        <div style="text-align: center;"><i class="fas fa-tasks"></i> Assigned</div>
                                        <div style="text-align: center;"><i class="fas fa-check"></i> Marking All Done</div>
                                        <div style="text-align: center;"><i class="fas fa-exclamation-triangle"></i> Overdue</div>
                                        <div style="text-align: center;"><i class="fas fa-chart-line"></i> Performance</div>
                                    </div>
                                    <?php foreach ($employee_stats as $employee): ?>
                                    <div class="table-row">
                                        <div class="employee-info" data-label="Employee:">
                                            <i class="fas fa-user-circle"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                                                <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                                    <?php 
                                                    $total = $employee['total_assigned'];
                                                    if ($total > 0) {
                                                        echo "Active: " . ($total - $employee['completed']);
                                                    } else {
                                                        echo "No tasks assigned";
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="text-align: center;" data-label="Assigned:">
                                            <div class="stat-number" style="color: var(--info); font-size: 1.2rem;">
                                                <?php echo $employee['total_assigned']; ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--text-secondary);">tasks</div>
                                        </div>
                                        <div style="text-align: center;" data-label="Marking All Done:">
                                            <div class="stat-number success" style="font-size: 1.2rem;">
                                                <?php echo $employee['completed']; ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--text-secondary);">done</div>
                                        </div>
                                        <div style="text-align: center;" data-label="Overdue:">
                                            <div class="stat-number <?php echo $employee['overdue'] > 0 ? 'danger' : ''; ?>" style="font-size: 1.2rem;">
                                                <?php echo $employee['overdue']; ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--text-secondary);">overdue</div>
                                        </div>
                                        <div style="text-align: center;" data-label="Performance:">
                                            <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                                <span class="completion-rate" style="font-size: 1.1rem; font-weight: 700;">
                                                    <?php echo $employee['completion_rate']; ?>%
                                                </span>
                                                <div class="performance-bar" style="width: 100px;">
                                                    <div class="performance-fill" style="width: <?php echo $employee['completion_rate']; ?>%"></div>
                                                </div>
                                                <div style="font-size: 0.7rem; color: var(--text-secondary);">
                                                    <?php 
                                                    if ($employee['completion_rate'] >= 80) echo "Excellent";
                                                    elseif ($employee['completion_rate'] >= 60) echo "Good";
                                                    elseif ($employee['completion_rate'] >= 40) echo "Average";
                                                    elseif ($employee['completion_rate'] > 0) echo "Needs Improvement";
                                                    else echo "No Progress";
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- System Health -->
                    <div class="card analytics-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-server"></i> System Health</h3>
                            <span class="health-status online">
                                <i class="fas fa-circle"></i> Online
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="health-metrics">
                                <div class="health-item">
                                    <i class="fas fa-users"></i>
                                    <div class="health-info">
                                        <span class="health-label">Active Users</span>
                                        <span class="health-value">
                                            <?php echo $system_health['active_users']; ?>/<?php echo $system_health['total_users']; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="health-item">
                                    <i class="fas fa-tasks"></i>
                                    <div class="health-info">
                                        <span class="health-label">Tasks This Week</span>
                                        <span class="health-value"><?php echo $system_health['tasks_this_week']; ?></span>
                                    </div>
                                </div>
                                <div class="health-item">
                                    <i class="fas fa-envelope"></i>
                                    <div class="health-info">
                                        <span class="health-label">Messages Today</span>
                                        <span class="health-value"><?php echo $system_health['messages_today']; ?></span>
                                    </div>
                                </div>
                                <div class="health-item">
                                    <i class="fas fa-server"></i>
                                    <div class="health-info">
                                        <span class="health-label">Database</span>
                                        <span class="health-value success">Connected</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Task Details Modal -->
    <div id="taskDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTaskTitle">Task Details</h3>
                <button class="close-btn" onclick="closeTaskDetails()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalTaskDescription">
                    <!-- Task description will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Modal -->
    <div id="activityModalOverlay" class="activity-modal-overlay">
        <div class="activity-modal">
            <div class="activity-modal-header">
                <h3 class="activity-modal-title">
                    <i class="fas fa-history"></i>
                    All Recent Activities
                </h3>
                <button class="activity-modal-close" onclick="closeAllActivities()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="activity-modal-body" id="allActivitiesContainer">
                <!-- All activities will be loaded here -->
                <?php foreach ($recent_activities as $index => $activity): ?>
                <div class="modal-activity-item activity-<?php echo $activity['type']; ?>" style="animation-delay: <?php echo ($index * 0.1); ?>s;">
                    <div class="activity-icon">
                        <?php
                        switch ($activity['type']) {
                            case 'task_completed':
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
                                echo '<i class="fas fa-sync"></i>';
                                break;
                            case 'task_created':
                                echo '<i class="fas fa-plus"></i>';
                                break;
                            case 'task_assigned':
                                echo '<i class="fas fa-user-plus"></i>';
                                break;
                            default:
                                echo '<i class="fas fa-bell"></i>';
                        }
                        ?>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">
                            <?php
                            switch ($activity['type']) {
                                case 'task_completed':
                                    echo "{$activity['user']} completed task";
                                    break;
                                case 'status_change':
                                    echo "{$activity['user']} updated task status";
                                    break;
                                case 'subtask_completed':
                                    echo "{$activity['user']} completed a subtask";
                                    break;
                                case 'task_created':
                                    echo "{$activity['user']} created a new task";
                                    break;
                                case 'task_assigned':
                                    echo "Task assigned to {$activity['user']}";
                                    break;
                                case 'message_sent':
                                    echo "{$activity['user']} sent a message";
                                    break;
                                default:
                                    echo "Activity by {$activity['user']}";
                                    break;
                            }
                            ?>
                        </div>
                        <div class="activity-detail">
                            <?php if ($activity['type'] === 'message_sent'): ?>
                                <strong>To: <?php echo htmlspecialchars($activity['receiver']); ?></strong>
                                <br><small>Subject: <?php echo htmlspecialchars($activity['details']); ?></small>
                                <?php if ($activity['task'] !== 'General'): ?>
                                    <br><small>Related to: <?php echo htmlspecialchars($activity['task']); ?></small>
                                <?php endif; ?>
                                <?php if (isset($activity['message_content'])): ?>
                                    <br><small>Message: <?php echo htmlspecialchars(substr($activity['message_content'], 0, 100)) . (strlen($activity['message_content']) > 100 ? '...' : ''); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <strong><?php echo htmlspecialchars($activity['task']); ?></strong>
                                <?php if (isset($activity['old_status']) && isset($activity['new_status']) && $activity['type'] === 'status_change'): ?>
                                    <br><small>Changed from "<?php echo htmlspecialchars($activity['old_status']); ?>" to "<?php echo htmlspecialchars($activity['new_status']); ?>"</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="activity-time"><?php echo $activity['time']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Chart.js for Analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Script for charts and smooth page transitions -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize completion chart
            const completionCtx = document.getElementById('completionChart').getContext('2d');
            const completionData = <?php echo json_encode($completion_data); ?>;
            
            new Chart(completionCtx, {
                type: 'line',
                data: {
                    labels: completionData.map(item => item.date),
                    datasets: [{
                        label: 'Tasks Completed',
                        data: completionData.map(item => item.count),
                        borderColor: '#6a0dad',
                        backgroundColor: 'rgba(106, 13, 173, 0.15)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#6a0dad',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: '#8e24aa',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                color: '#e0e0e0',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                padding: 20,
                                usePointStyle: true
                            }
                        },
                        title: {
                            display: true,
                            text: 'Daily Task Completion Trend',
                            color: '#e0e0e0',
                            font: {
                                size: 16,
                                weight: 'bold'
                            },
                            padding: {
                                top: 10,
                                bottom: 30
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 30, 30, 0.9)',
                            titleColor: '#e0e0e0',
                            bodyColor: '#e0e0e0',
                            borderColor: '#6a0dad',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            callbacks: {
                                title: function(context) {
                                    return 'Date: ' + context[0].label;
                                },
                                label: function(context) {
                                    return 'Tasks Completed: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Tasks',
                                color: '#e0e0e0',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            },
                            ticks: {
                                stepSize: 1,
                                color: '#bbbbbb',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date',
                                color: '#e0e0e0',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            },
                            ticks: {
                                color: '#bbbbbb',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            }
                        }
                    },
                    elements: {
                        line: {
                            borderJoinStyle: 'round',
                            borderCapStyle: 'round'
                        }
                    }
                }
            });
            
            // Get all links with the page-link class
            const pageLinks = document.querySelectorAll('.page-link');
            

        });

        // Archive Task Function
        function archiveTask(taskId) {
            // Find the task card element
            const taskCard = document.querySelector(`[data-task-id="${taskId}"]`).closest('.task-card');
            
            if (taskCard) {
                // Add fade out animation
                taskCard.style.transition = 'all 0.5s ease-out';
                taskCard.style.transform = 'translateY(-20px)';
                taskCard.style.opacity = '0';
                
                // Remove the task card after animation
                setTimeout(() => {
                    taskCard.remove();
                    
                    // Check if there are any tasks left
                    const taskContainer = document.querySelector('.task-cards-container');
                    if (taskContainer && taskContainer.children.length === 0) {
                        taskContainer.innerHTML = '<p>No active tasks found. <a href="add-task.php" class="page-link">Add a task</a> to get started.</p>';
                    }
                    
                    // Show success message
                    showNotification('Task archived successfully!', 'success');
                }, 500);
                
                // Send AJAX request to server to actually archive the task
                fetch('archive_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ task_id: taskId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Task archived on server');
                    } else {
                        showNotification('Failed to archive task on server: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error archiving task:', error);
                    showNotification('Error archiving task on server', 'error');
                });
            }
        }
        
        // Show notification function
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            
            let icon, backgroundColor;
            switch(type) {
                case 'success':
                    icon = 'fa-check-circle';
                    backgroundColor = 'var(--success)';
                    break;
                case 'error':
                    icon = 'fa-exclamation-circle';
                    backgroundColor = 'var(--danger)';
                    break;
                default:
                    icon = 'fa-info-circle';
                    backgroundColor = 'var(--info)';
            }
            
            notification.innerHTML = `
                <div class="notification-content">
                    <i class="fas ${icon}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            // Add styles
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${backgroundColor};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                z-index: 10000;
                transform: translateX(100%);
                transition: transform 0.3s ease-out;
            `;
            
            // Add to page
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Activity Modal Functions (Global scope)
        function showAllActivities() {
            const overlay = document.getElementById('activityModalOverlay');
            const mainContent = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar');
            
            // Add blur effect to the background
            if (mainContent) {
                mainContent.classList.add('page-blur');
            }
            if (sidebar) {
                sidebar.classList.add('page-blur');
            }
            
            // Show the modal with animation
            overlay.style.display = 'block';
            setTimeout(() => {
                overlay.classList.add('show');
            }, 10);
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
            
            // Close modal when clicking on overlay (not on modal content)
            overlay.onclick = function(event) {
                if (event.target === overlay) {
                    closeAllActivities();
                }
            };
        }

        function closeAllActivities() {
            const overlay = document.getElementById('activityModalOverlay');
            const mainContent = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar');
            
            // Remove blur effect
            if (mainContent) {
                mainContent.classList.remove('page-blur');
            }
            if (sidebar) {
                sidebar.classList.remove('page-blur');
            }
            
            // Hide the modal with animation
            overlay.classList.remove('show');
            
            // Hide the overlay after animation completes
            setTimeout(() => {
                overlay.style.display = 'none';
            }, 400);
            
            // Restore body scroll
            document.body.style.overflow = 'auto';
        }

                // Task Details Modal Functions
        function showTaskDetails(taskId, description, title) {
            const modal = document.getElementById('taskDetailsModal');
            const modalTitle = document.getElementById('modalTaskTitle');
            const modalDescription = document.getElementById('modalTaskDescription');
            
            modalTitle.textContent = title;
            modalDescription.innerHTML = description || '<em>No description provided for this task.</em>';
            
            modal.style.display = 'block';
            
            // Close modal when clicking outside of it
            modal.onclick = function(event) {
                if (event.target === modal) {
                    closeTaskDetails();
                }
            };
        }
        
        function closeTaskDetails() {
            const modal = document.getElementById('taskDetailsModal');
            modal.style.display = 'none';
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeTaskDetails();
                closeAllActivities();
            }
        });


    </script>
</body>
</html>
