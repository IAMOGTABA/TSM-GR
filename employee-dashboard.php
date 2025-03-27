<?php
// employee-dashboard.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit;
}
require 'config.php';

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get task counts
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_tasks,
    SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed_tasks,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
    SUM(CASE WHEN deadline < CURDATE() AND status != 'done' THEN 1 ELSE 0 END) as overdue_tasks
FROM tasks WHERE assigned_to = :user_id");
$stmt->execute(['user_id' => $user_id]);
$task_counts = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all tasks for the user
$stmt = $pdo->prepare("SELECT id, title, status, deadline, priority FROM tasks 
    WHERE assigned_to = :user_id 
    ORDER BY deadline ASC");
$stmt->execute(['user_id' => $user_id]);
$all_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate tasks by status
$total_tasks = $all_tasks;
$completed_tasks = array_filter($all_tasks, function($task) {
    return $task['status'] === 'done';
});
$in_progress_tasks = array_filter($all_tasks, function($task) {
    return $task['status'] === 'in_progress';
});
$overdue_tasks = array_filter($all_tasks, function($task) {
    return $task['status'] !== 'done' && strtotime($task['deadline']) < strtotime(date('Y-m-d'));
});

// Calculate completion percentage
$completion_percentage = 0;
if ($task_counts['total_tasks'] > 0) {
    $completion_percentage = round(($task_counts['completed_tasks'] / $task_counts['total_tasks']) * 100);
}

// Get upcoming tasks (next 7 days)
$stmt = $pdo->prepare("SELECT id, title, deadline, status, priority FROM tasks 
    WHERE assigned_to = :user_id 
    AND deadline >= CURDATE() 
    AND deadline <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND status != 'done'
    ORDER BY deadline ASC
    LIMIT 5");
$stmt->execute(['user_id' => $user_id]);
$upcoming_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent messages
$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name 
    FROM messages m 
    JOIN users u ON m.sender_id = u.id 
    WHERE m.recipient_id = ? 
    ORDER BY m.sent_at DESC
    LIMIT 3
");
$stmt->execute([$user_id]);
$recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activities (new task assignments, etc.)
$stmt = $pdo->prepare("
    SELECT t.id, t.title, t.created_at, u.full_name as creator_name
    FROM tasks t
    JOIN users u ON t.created_by = u.id
    WHERE t.assigned_to = :user_id
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute(['user_id' => $user_id]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND read_status = 'unread'");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard</title>
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
            transform: translateY(-15px);
            transition: opacity 0.4s ease-out, transform 0.4s ease-out;
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
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
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .badge-warning {
            background-color: rgba(246, 194, 62, 0.1);
            color: var(--warning);
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
        
        .welcome-message {
            background: linear-gradient(45deg, var(--primary-dark), var(--primary-light));
            border-radius: 0.35rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-text h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: white;
        }
        
        .welcome-text p {
            font-size: 1.1rem;
            opacity: 0.9;
            color: white;
        }
        
        .date-display {
            background-color: rgba(255, 255, 255, 0.2);
            padding: 0.75rem 1rem;
            border-radius: 0.35rem;
            text-align: center;
            color: white;
        }
        
        .date-display .day {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .date-display .month-year {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .card {
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 2rem 0 rgba(0, 0, 0, 0.6);
        }
        
        .dashboard-card {
            display: flex;
            flex-direction: column;
            border-left: 4px solid;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .dashboard-card .card-body {
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dashboard-card .card-title {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        
        .dashboard-card .card-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-main);
        }
        
        .dashboard-card .card-icon {
            font-size: 2.5rem;
            opacity: 0.3;
        }
        
        .dashboard-card.total {
            border-left-color: var(--primary);
        }
        
        .dashboard-card.completed {
            border-left-color: var(--success);
        }
        
        .dashboard-card.in-progress {
            border-left-color: var(--info);
        }
        
        .dashboard-card.overdue {
            border-left-color: var(--danger);
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .task-list {
            list-style: none;
        }
        
        .task-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }
        
        .task-item:last-child {
            border-bottom: none;
        }
        
        .task-item:hover {
            background-color: var(--bg-secondary);
        }
        
        .task-info {
            display: flex;
            flex-direction: column;
        }
        
        .task-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .task-date {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .task-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-to-do {
            background-color: rgba(133, 135, 150, 0.1);
            color: var(--secondary);
        }
        
        .status-in-progress {
            background-color: rgba(54, 185, 204, 0.1);
            color: var(--info);
        }
        
        .status-completed {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success);
        }
        
        .priority-high {
            color: var(--danger);
        }
        
        .priority-medium {
            color: var(--warning);
        }
        
        .priority-low {
            color: var(--success);
        }
        
        .progress-container {
            width: 100%;
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            margin: 1rem 0;
            height: 1.5rem;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 0.35rem;
            background: linear-gradient(90deg, var(--primary-dark), var(--primary-light));
            transition: width 1s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .message-list {
            list-style: none;
        }
        
        .message-item {
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.35rem;
            background-color: var(--bg-secondary);
            border-left: 4px solid var(--primary);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: left;
            animation: message-appear 0.4s backwards;
        }
        
        @keyframes message-appear {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .message-item:hover {
            transform: translateX(5px) scale(1.01);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .message-item.unread {
            border-left-color: var(--warning);
            position: relative;
        }
        
        .message-item.unread:before {
            content: '';
            display: block;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background-color: var(--warning);
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .message-sender {
            font-weight: 600;
            color: var(--primary-light);
        }
        
        .message-time {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .message-subject {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .message-content {
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .activity-list {
            list-style: none;
        }
        
        .activity-item {
            position: relative;
            padding: 1rem 1rem 1rem 3rem;
            border-left: 1px solid var(--primary);
            margin-left: 1rem;
            margin-bottom: 1rem;
        }
        
        .activity-item:last-child {
            border-left: none;
        }
        
        .activity-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 1.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
        }
        
        .activity-time {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        
        .activity-content {
            font-weight: 500;
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
        }
        
        .footer-note {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
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
            
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .welcome-message {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }
        
        .card-tasks {
            padding: 0 1rem 1rem 1rem;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .tasks-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .tasks-list li {
            padding: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        
        .tasks-list li:last-child {
            border-bottom: none;
        }
        
        .tasks-list li:hover {
            background-color: var(--bg-secondary);
        }
        
        .task-link {
            color: var(--text-main);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .priority-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .priority-high {
            background-color: var(--danger);
        }
        
        .priority-medium {
            background-color: var(--warning);
        }
        
        .priority-low {
            background-color: var(--success);
        }
        
        .more-tasks {
            text-align: center;
            font-style: italic;
            font-size: 0.85rem;
        }
        
        .no-tasks {
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
            padding: 0.5rem;
            font-size: 0.9rem;
        }
        
        .dashboard-card {
            display: flex;
            flex-direction: column;
            border-left: 4px solid;
            animation: fadeIn 0.5s ease-out;
            height: 100%;
        }
        
        .dashboard-card .card-body {
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
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
            <li><a href="employee-dashboard.php" class="active page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="my-tasks.php" class="page-link"><i class="fas fa-clipboard-list"></i> My Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="messages.php" class="page-link"><i class="fas fa-envelope"></i> Messages 
                <?php if ($unread_count > 0): ?>
                    <span class="badge badge-warning"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a></li>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="profile-settings.php" class="page-link"><i class="fas fa-user-cog"></i> Profile Settings</a></li>
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="content">
        <div class="header">
            <h1 class="page-title">Employee Dashboard</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="welcome-message">
            <div class="welcome-text">
                <h2>Welcome, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
                <p>Here's an overview of your tasks and recent activities.</p>
            </div>
            <div class="date-display">
                <div class="day"><?php echo date('d'); ?></div>
                <div class="month-year"><?php echo date('M Y'); ?></div>
            </div>
        </div>
        
        <div class="dashboard-cards">
            <div class="card dashboard-card total">
                <div class="card-body">
                    <div>
                        <div class="card-title">My Total Tasks</div>
                        <div class="card-value"><?php echo $task_counts['total_tasks']; ?></div>
                    </div>
                    <i class="fas fa-tasks card-icon"></i>
                </div>
                <div class="card-tasks">
                    <?php if (empty($total_tasks)): ?>
                        <p class="no-tasks">No tasks found.</p>
                    <?php else: ?>
                        <ul class="tasks-list">
                            <?php foreach(array_slice($total_tasks, 0, 5) as $task): ?>
                                <li>
                                    <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="task-link page-link">
                                        <span class="priority-indicator priority-<?php echo $task['priority']; ?>"></span>
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($total_tasks) > 5): ?>
                                <li class="more-tasks"><a href="my-tasks.php" class="page-link">View all tasks...</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card dashboard-card completed">
                <div class="card-body">
                    <div>
                        <div class="card-title">Completed Tasks</div>
                        <div class="card-value"><?php echo $task_counts['completed_tasks']; ?></div>
                    </div>
                    <i class="fas fa-check-circle card-icon"></i>
                </div>
                <div class="card-tasks">
                    <?php if (empty($completed_tasks)): ?>
                        <p class="no-tasks">No completed tasks.</p>
                    <?php else: ?>
                        <ul class="tasks-list">
                            <?php foreach(array_slice($completed_tasks, 0, 5) as $task): ?>
                                <li>
                                    <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="task-link page-link">
                                        <span class="priority-indicator priority-<?php echo $task['priority']; ?>"></span>
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($completed_tasks) > 5): ?>
                                <li class="more-tasks"><a href="my-tasks.php?status=completed" class="page-link">View all completed...</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card dashboard-card in-progress">
                <div class="card-body">
                    <div>
                        <div class="card-title">In Progress</div>
                        <div class="card-value"><?php echo $task_counts['in_progress_tasks']; ?></div>
                    </div>
                    <i class="fas fa-spinner card-icon"></i>
                </div>
                <div class="card-tasks">
                    <?php if (empty($in_progress_tasks)): ?>
                        <p class="no-tasks">No tasks in progress.</p>
                    <?php else: ?>
                        <ul class="tasks-list">
                            <?php foreach(array_slice($in_progress_tasks, 0, 5) as $task): ?>
                                <li>
                                    <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="task-link page-link">
                                        <span class="priority-indicator priority-<?php echo $task['priority']; ?>"></span>
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($in_progress_tasks) > 5): ?>
                                <li class="more-tasks"><a href="my-tasks.php?status=in_progress" class="page-link">View all in progress...</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card dashboard-card overdue">
                <div class="card-body">
                    <div>
                        <div class="card-title">Overdue Tasks</div>
                        <div class="card-value"><?php echo $task_counts['overdue_tasks']; ?></div>
                    </div>
                    <i class="fas fa-clock card-icon"></i>
                </div>
                <div class="card-tasks">
                    <?php if (empty($overdue_tasks)): ?>
                        <p class="no-tasks">No overdue tasks.</p>
                    <?php else: ?>
                        <ul class="tasks-list">
                            <?php foreach(array_slice($overdue_tasks, 0, 5) as $task): ?>
                                <li>
                                    <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="task-link page-link">
                                        <span class="priority-indicator priority-<?php echo $task['priority']; ?>"></span>
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($overdue_tasks) > 5): ?>
                                <li class="more-tasks"><a href="my-tasks.php?status=overdue" class="page-link">View all overdue...</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-line"></i> Completion Progress</h2>
            </div>
            <div class="card-body">
                <div class="progress-container">
                    <div class="progress-bar" style="width: <?php echo $completion_percentage; ?>%"><?php echo $completion_percentage; ?>%</div>
                </div>
                <p>You have completed <?php echo $task_counts['completed_tasks']; ?> out of <?php echo $task_counts['total_tasks']; ?> tasks.</p>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-envelope"></i> Recent Messages</h2>
                    <a href="messages.php" class="btn btn-sm page-link">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_messages)): ?>
                        <p>You don't have any messages yet.</p>
                    <?php else: ?>
                        <ul class="message-list">
                            <?php foreach ($recent_messages as $index => $message): ?>
                                <li class="message-item <?php echo $message['read_status'] === 'unread' ? 'unread' : ''; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s">
                                    <div class="message-header">
                                        <span class="message-sender">From: <?php echo htmlspecialchars($message['sender_name']); ?></span>
                                        <span class="message-time"><?php echo date('M d, Y h:i A', strtotime($message['sent_at'])); ?></span>
                                    </div>
                                    <div class="message-subject"><?php echo htmlspecialchars($message['subject']); ?></div>
                                    <a href="messages.php" class="btn btn-sm page-link">Read Message</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-history"></i> Recent Activities</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_activities)): ?>
                        <p>No recent activities found.</p>
                    <?php else: ?>
                        <ul class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                                <li class="activity-item">
                                    <div class="activity-time"><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></div>
                                    <div class="activity-content">
                                        <strong><?php echo htmlspecialchars($activity['creator_name']); ?></strong> assigned you a new task: 
                                        <a href="view_task.php?task_id=<?php echo $activity['id']; ?>" class="page-link">
                                            <strong><?php echo htmlspecialchars($activity['title']); ?></strong>
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="footer-note">
            <p>Task Management System &copy; <?php echo date('Y'); ?></p>
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
                        }, 400); // Match this with the CSS transition time
                    }
                });
            });
            
            // When page loads, ensure it fades in
            document.body.classList.remove('fade-out');
            
            // Animate progress bar
            setTimeout(function() {
                const progressBar = document.querySelector('.progress-bar');
                progressBar.style.width = progressBar.getAttribute('data-width') + '%';
            }, 500);
        });
    </script>
</body>
</html>
