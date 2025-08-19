<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Get the logged-in employee's ID
$user_id = $_SESSION['user_id'];

// Get user data for sidebar
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch tasks assigned to this employee (excluding archived)
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS assigned_user,
           COUNT(s.id) AS total_subtasks,
           SUM(CASE WHEN s.status = 'done' THEN 1 ELSE 0 END) AS completed_subtasks
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN subtasks s ON t.id = s.task_id
    WHERE t.assigned_to = :user_id AND t.archived = 0
    GROUP BY t.id
    ORDER BY 
        CASE WHEN t.status = 'completed' THEN 0 ELSE 1 END,
        t.deadline ASC, t.priority DESC
");
$stmt->execute(['user_id' => $user_id]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread message count
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = :user_id AND read_status = 'unread'");
$unread_stmt->execute(['user_id' => $user_id]);
$unread_count = $unread_stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks - TSM</title>
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
        }
        
        .header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }
        
        .task-grid {
            display: grid;
            gap: 1rem;
        }
        
        .task-card {
            background: var(--bg-card);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        
        .task-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .task-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }
        
        .task-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-to_do { background: var(--secondary); color: white; }
        .status-in_progress { background: var(--warning); color: white; }
        .status-completed { background: var(--success); color: white; }
        
        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .priority-high { background: var(--danger); color: white; }
        .priority-medium { background: var(--warning); color: white; }
        .priority-low { background: var(--info); color: white; }
        
        .task-description {
            color: var(--text-secondary);
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        
        .task-progress {
            margin-bottom: 1rem;
        }
        
        .progress-bar {
            background: var(--bg-secondary);
            border-radius: 10px;
            overflow: hidden;
            height: 8px;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--success);
            transition: width 0.3s;
        }
        
        .task-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-info {
            background-color: var(--info);
        }
        
        .btn-info:hover {
            background-color: #2a9fbd;
        }
        
        .no-tasks {
            text-align: center;
            color: var(--text-secondary);
            padding: 3rem;
        }
        
        .no-tasks i {
            font-size: 4rem;
            margin-bottom: 1rem;
            display: block;
        }
        
        .badge {
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            padding: 0.2rem 0.5rem;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }
        
        @media (max-width: 768px) {
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
        
        <div class="sidebar-heading">Navigation</div>
        <ul class="sidebar-menu">
            <li><a href="employee-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="my-tasks.php"><i class="fas fa-clipboard-list"></i> My Tasks</a></li>
            <li><a href="employee-tasks.php" class="active"><i class="fas fa-tasks"></i> All Tasks</a></li>
            <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a></li>
        </ul>
        
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="content">
        <div class="header">
            <h1 class="page-title">My Tasks</h1>
            <p style="color: var(--text-secondary);">All tasks assigned to you</p>
        </div>
        
        <div class="task-grid">
            <?php if (!empty($tasks)): ?>
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <div>
                                <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                <div class="task-meta">
                                    <span><i class="fas fa-calendar"></i> 
                                        <?php 
                                        if ($task['deadline']) {
                                            $deadline = new DateTime($task['deadline']);
                                            echo $deadline->format('M j, Y');
                                        } else {
                                            echo 'No deadline';
                                        }
                                        ?>
                                    </span>
                                    <span><i class="fas fa-clock"></i> Created <?php echo date('M j', strtotime($task['created_at'])); ?></span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <span class="status-badge status-<?php echo $task['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                </span>
                                <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                                    <?php echo ucfirst($task['priority']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if (!empty($task['description'])): ?>
                            <div class="task-description">
                                <?php echo nl2br(htmlspecialchars(substr($task['description'], 0, 150))); ?>
                                <?php if (strlen($task['description']) > 150): ?>...<?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($task['total_subtasks'] > 0): ?>
                            <div class="task-progress">
                                <small>Subtasks: <?php echo $task['completed_subtasks']; ?> / <?php echo $task['total_subtasks']; ?> completed</small>
                                <div class="progress-bar">
                                    <?php $progress = $task['total_subtasks'] > 0 ? ($task['completed_subtasks'] / $task['total_subtasks']) * 100 : 0; ?>
                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="task-actions">
                            <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="btn btn-info">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-tasks">
                    <i class="fas fa-tasks"></i>
                    <h3>No tasks assigned</h3>
                    <p>You don't have any tasks assigned to you yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>