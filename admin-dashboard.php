<?php
// admin-dashboard.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Get task statistics
$stats = [];

// Total Tasks
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks");
$stmt->execute();
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Completed Tasks
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = 'done'");
$stmt->execute();
$stats['completed'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending Tasks (to_do + in_progress)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status IN ('to_do', 'in_progress')");
$stmt->execute();
$stats['pending'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Overdue Tasks
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE deadline < :today AND status != 'done'");
$stmt->execute(['today' => $today]);
$stats['overdue'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent tasks
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS assigned_user 
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    ORDER BY t.deadline ASC
    LIMIT 10
");
$stmt->execute();
$recent_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch real recent activity data
$recent_activities = [];

// 1. Recently completed tasks
$completed_tasks_stmt = $pdo->prepare("
    SELECT t.id as task_id, t.title as task_title, u.full_name as user_name
    FROM tasks t 
    JOIN users u ON t.assigned_to = u.id
    WHERE t.status = 'done' 
    ORDER BY t.id DESC
    LIMIT 5
");
$completed_tasks_stmt->execute();
$completed_tasks = $completed_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($completed_tasks as $task) {
    $recent_activities[] = [
        'type' => 'task_completed',
        'user' => $task['user_name'],
        'task' => $task['task_title'],
        'time' => date('M j, g:i A'),
        'task_id' => $task['task_id']
    ];
}

// 2. Recently updated tasks that changed to in_progress
$started_tasks_stmt = $pdo->prepare("
    SELECT t.id as task_id, t.title as task_title, u.full_name as user_name
    FROM tasks t 
    JOIN users u ON t.assigned_to = u.id
    WHERE t.status = 'in_progress' 
    ORDER BY t.id DESC
    LIMIT 5
");
$started_tasks_stmt->execute();
$started_tasks = $started_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($started_tasks as $task) {
    $recent_activities[] = [
        'type' => 'task_started',
        'user' => $task['user_name'],
        'task' => $task['task_title'],
        'time' => date('M j, g:i A'),
        'task_id' => $task['task_id']
    ];
}

// 3. Recent subtask completions
$subtask_stmt = $pdo->prepare("
    SELECT s.id as subtask_id, s.title as subtask_title, t.id as task_id, t.title as task_title, 
           u.full_name as user_name
    FROM subtasks s
    JOIN tasks t ON s.task_id = t.id
    JOIN users u ON t.assigned_to = u.id
    WHERE s.status = 'done'
    ORDER BY s.id DESC
    LIMIT 5
");
$subtask_stmt->execute();
$completed_subtasks = $subtask_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($completed_subtasks as $subtask) {
    $recent_activities[] = [
        'type' => 'subtask_completed',
        'user' => $subtask['user_name'],
        'task' => $subtask['task_title'],
        'subtask' => $subtask['subtask_title'],
        'time' => date('M j, g:i A'),
        'task_id' => $subtask['task_id']
    ];
}

// 4. Approaching deadlines (next 3 days)
$deadline_stmt = $pdo->prepare("
    SELECT t.id as task_id, t.title as task_title, t.deadline, u.full_name as user_name
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    WHERE t.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    AND t.status != 'done'
    ORDER BY t.deadline ASC
    LIMIT 5
");
$deadline_stmt->execute();
$approaching_deadlines = $deadline_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($approaching_deadlines as $deadline_task) {
    $days_remaining = ceil((strtotime($deadline_task['deadline']) - time()) / (60 * 60 * 24));
    $deadline_text = $days_remaining <= 0 ? "Today" : ($days_remaining == 1 ? "Tomorrow" : "In $days_remaining days");
    
    $recent_activities[] = [
        'type' => 'deadline_approaching',
        'task' => $deadline_task['task_title'],
        'deadline' => date('M j', strtotime($deadline_task['deadline'])),
        'time' => $deadline_text,
        'task_id' => $deadline_task['task_id']
    ];
}

// 5. Recent messages
$message_stmt = $pdo->prepare("
    SELECT m.id, m.subject, m.message, m.sent_at, 
           u.full_name as sender_name, m.recipient_id,
           t.id as task_id, t.title as task_title
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN tasks t ON t.id = m.task_id
    ORDER BY m.sent_at DESC
    LIMIT 5
");
try {
    $message_stmt->execute();
    $recent_messages = $message_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recent_messages as $message) {
        $recent_activities[] = [
            'type' => 'message',
            'user' => $message['sender_name'],
            'message' => $message['subject'],
            'time' => date('M j, g:i A', strtotime($message['sent_at'])),
            'task_id' => $message['task_id'] ?? null
        ];
    }
} catch (PDOException $e) {
    // If messages table doesn't exist yet, just continue without messages
}

// Sort activities in a consistent way (by task_id, then type)
usort($recent_activities, function($a, $b) {
    // First compare by task_id if both have it
    if (isset($a['task_id']) && isset($b['task_id']) && $a['task_id'] != $b['task_id']) {
        return $b['task_id'] - $a['task_id']; // Higher IDs first (newer)
    }
    
    // Then by activity type
    if ($a['type'] != $b['type']) {
        // Priority order: completed tasks, started tasks, subtasks, deadlines, messages
        $type_priority = [
            'task_completed' => 1,
            'task_started' => 2,
            'subtask_completed' => 3,
            'deadline_approaching' => 4,
            'message' => 5
        ];
        
        return $type_priority[$a['type']] - $type_priority[$b['type']];
    }
    
    return 0;
});

// Take only the most recent 10 activities
$recent_activities = array_slice($recent_activities, 0, 10);

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
        
        /* Page Transition Effect */
        body.fade-out {
            opacity: 0;
            transition: opacity 0.3s ease-out;
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
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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
        
        .activity-message {
            border-left-color: var(--danger);
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
        
        .activity-message .activity-icon {
            color: var(--danger);
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
        
        .status-to_do {
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
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h1>TSM</h1>
        </div>
        <div class="sidebar-heading">Main</div>
        <ul class="sidebar-menu">
            <li><a href="admin-dashboard.php" class="active page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php" class="page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="manage-users.php" class="page-link"><i class="fas fa-users"></i> Manage Users</a></li>
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
                    <div class="label">Completed Tasks</div>
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
                    <a href="manage-tasks.php" class="view-btn page-link">View All</a>
                </div>
                <div class="card-body">
                    <?php if (count($recent_tasks) > 0): ?>
                    <div class="task-cards-container">
                        <?php foreach ($recent_tasks as $task): 
                            $is_overdue = !empty($task['deadline']) && strtotime($task['deadline']) < strtotime('today') && $task['status'] != 'done';
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
                                    <span class="<?php echo $is_overdue ? 'overdue' : ''; ?>">
                                        <?php 
                                        if (!empty($task['deadline'])) {
                                            echo date('M d, Y', strtotime($task['deadline']));
                                            if ($is_overdue) {
                                                echo ' <span class="overdue">(Overdue)</span>';
                                            }
                                        } else {
                                            echo 'No deadline';
                                        }
                                        ?>
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
                                <span class="status-badge status-<?php echo $task['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                </span>
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
                    <h2 class="card-title">Recent Activity</h2>
                    <span class="badge"><?php echo count($recent_activities); ?> new</span>
                </div>
                <div class="card-body">
                    <div class="activity-container">
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item activity-<?php echo $activity['type']; ?>">
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
                                    case 'message':
                                        echo '<i class="fas fa-envelope"></i>';
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
                                        case 'task_started':
                                            echo "{$activity['user']} started working on task";
                                            break;
                                        case 'subtask_completed':
                                            echo "{$activity['user']} completed a subtask";
                                            break;
                                        case 'subtask_started':
                                            echo "{$activity['user']} started a subtask";
                                            break;
                                        case 'deadline_approaching':
                                            echo "Deadline approaching";
                                            break;
                                        case 'message':
                                            echo "New message from {$activity['user']}";
                                            break;
                                    }
                                    ?>
                                </div>
                                <div class="activity-detail">
                                    <?php
                                    switch ($activity['type']) {
                                        case 'task_completed':
                                        case 'task_started':
                                            echo "<strong>{$activity['task']}</strong>";
                                            break;
                                        case 'subtask_completed':
                                        case 'subtask_started':
                                            echo "<strong>{$activity['task']}</strong>: {$activity['subtask']}";
                                            break;
                                        case 'deadline_approaching':
                                            echo "<strong>{$activity['task']}</strong> is due {$activity['deadline']}";
                                            break;
                                        case 'message':
                                            echo "\"{$activity['message']}\"";
                                            // Add reply button for messages
                                            if (isset($activity['user'])) {
                                                echo "<div class='message-actions'>";
                                                echo "<a href='messages.php?reply_to=" . urlencode($activity['user']) . "&subject=RE: " . urlencode($activity['message']) . "' class='reply-btn page-link'>";
                                                echo "<i class='fas fa-reply'></i> Reply</a>";
                                                echo "</div>";
                                            }
                                            break;
                                    }
                                    ?>
                                </div>
                                <div class="activity-time"><?php echo $activity['time']; ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Script for smooth page transitions -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get all links with the page-link class
            const pageLinks = document.querySelectorAll('.page-link');
            
            // Add click event listeners to each link
            pageLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Only if it's not the current active page
                    if (!this.classList.contains('active')) {
                        e.preventDefault();
                        const targetPage = this.getAttribute('href');
                        
                        // Fade out effect
                        document.body.classList.add('fade-out');
                        
                        // After transition completes, navigate to the new page
                        setTimeout(function() {
                            window.location.href = targetPage;
                        }, 300); // Match this with the CSS transition time
                    }
                });
            });
            
            // When page loads, ensure it fades in
            document.body.classList.remove('fade-out');
        });
    </script>
</body>
</html>
