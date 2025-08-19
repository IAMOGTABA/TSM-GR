<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require 'config.php';

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Initialize filter variables
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';



// Build the query based on filters
$params = ['user_id' => $user_id];
$where_clauses = ['t.assigned_to = :user_id', 't.archived = 0'];

if ($status_filter !== 'all') {
    if ($status_filter === 'overdue') {
        $where_clauses[] = 't.deadline < CURDATE() AND t.status != "completed"';
    } else {
        $where_clauses[] = 't.status = :status';
        $params['status'] = $status_filter;
    }
}

if ($priority_filter !== 'all') {
    $where_clauses[] = 't.priority = :priority';
    $params['priority'] = $priority_filter;
}

if (!empty($search_term)) {
    $where_clauses[] = '(t.title LIKE :search OR t.description LIKE :search)';
    $params['search'] = "%{$search_term}%";
}

$where_clause = implode(' AND ', $where_clauses);

// Count total tasks (for pagination)
$count_sql = "SELECT COUNT(*) FROM tasks t WHERE $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_tasks = $stmt->fetchColumn();

// Pagination settings
$tasks_per_page = 10;
$total_pages = ceil($total_tasks / $tasks_per_page);
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $tasks_per_page;

// Get tasks with pagination
$sql = "SELECT t.*, 
        COALESCE(
            (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id), 0
        ) as subtask_count,
        COALESCE(
            (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND status = 'done'), 0
        ) as completed_subtasks,
        u.full_name as created_by_name
        FROM tasks t
        LEFT JOIN users u ON t.created_by = u.id
        WHERE $where_clause
        ORDER BY 
        CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END,
        CASE WHEN t.deadline < CURDATE() AND t.status != 'completed' THEN 0 ELSE 1 END,
        t.priority = 'high' DESC, 
        t.deadline ASC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':limit', $tasks_per_page, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue(":$key", $value);
}
$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>My Tasks</title>
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
        
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .filter-select, .filter-input {
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            color: var(--text-main);
            min-width: 150px;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .search-group {
            display: flex;
            gap: 0.5rem;
            flex: 1;
            max-width: 400px;
        }
        
        .search-input {
            flex: 1;
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            color: var(--text-main);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
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
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .task-list {
            list-style: none;
        }
        
        .task-item {
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: relative;
            transition: all 0.2s ease-in-out;
        }
        
        .task-item:hover {
            background-color: var(--bg-secondary);
        }
        
        .task-item:last-child {
            border-bottom: none;
        }
        
        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .task-title-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }
        
        .priority-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
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
        
        .task-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .task-title:hover {
            color: var(--primary-light);
        }
        
        .task-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 90px;
            text-align: center;
        }
        
        .status-completed {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success);
        }
        
        .status-in-progress {
            background-color: rgba(54, 185, 204, 0.1);
            color: var(--info);
        }
        
        .status-to-do {
            background-color: rgba(133, 135, 150, 0.1);
            color: var(--secondary);
        }
        
        .status-overdue {
            background-color: rgba(231, 74, 59, 0.1);
            color: var(--danger);
        }
        
        .task-meta {
            display: flex;
            gap: 1.5rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .task-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .task-meta i {
            font-size: 0.9rem;
            width: 16px;
            text-align: center;
        }
        
        .task-description {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            max-height: 4.5em;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        
        .task-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .task-subtasks {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .subtask-progress {
            width: 100px;
            height: 6px;
            background-color: var(--bg-secondary);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .subtask-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-dark), var(--primary-light));
            transition: width 0.3s ease;
        }
        
        .task-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .no-tasks {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
            font-style: italic;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .pagination-item {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.35rem;
            background-color: var(--bg-secondary);
            color: var(--text-main);
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .pagination-item:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .pagination-item.active {
            background-color: var(--primary);
            color: white;
            font-weight: 600;
        }
        
        .pagination-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .task-overdue {
            border-left: 3px solid var(--danger);
        }
        
        .task-completed {
            border-left: 3px solid var(--success);
        }
        
        .task-in-progress {
            border-left: 3px solid var(--info);
        }
        
        .task-to-do {
            border-left: 3px solid var(--secondary);
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
            
            .filter-container {
                flex-direction: column;
            }
            
            .search-group {
                max-width: none;
            }
            
            .task-header {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .task-meta {
                flex-wrap: wrap;
                gap: 1rem;
            }
        }
        
        .btn-done {
            background-color: var(--success);
            color: white;
            border: none;
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            border-radius: 0.35rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-left: 0.5rem;
        }
        
        .btn-done:hover {
            background-color: #19b67d;
            transform: translateY(-1px);
        }
        
        .btn-done-completed {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            border-radius: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 600;
            margin-left: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-done-completed:hover {
            background-color: rgba(231, 74, 59, 0.2);
            color: var(--danger);
            border-color: var(--danger);
            transform: translateY(-1px);
        }
        
        .btn-done-completed-readonly {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            border-radius: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 600;
            margin-left: 0.5rem;
            cursor: default;
            opacity: 0.8;
        }
        
        .task-fadeout {
            opacity: 0;
            transform: translateX(100px);
            transition: all 0.5s ease;
        }
        
        .status-dropdown {
            background-color: var(--bg-secondary);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 0.5rem;
        }
        
        .status-dropdown:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .employee-status-container {
            display: inline-block;
            position: relative;
        }
        
        .status-transition {
            opacity: 0;
            transform: scale(0.8);
            transition: all 0.5s ease;
        }
        
        .status-transition.show {
            opacity: 1;
            transform: scale(1);
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
        
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="sidebar-heading">Main</div>
            <ul class="sidebar-menu">
                <li><a href="admin-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-tasks.php"><i class="fas fa-tasks"></i> Manage Tasks</a></li>

                <li><a href="manage-users.php"><i class="fas fa-users"></i> Manage Users</a></li>

                <li><a href="analysis.php"><i class="fas fa-chart-line"></i> Analysis</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages
                    <?php if ($unread_count > 0): ?>
                        <span class="badge badge-warning"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a></li>
            </ul>

        <?php else: ?>
            <div class="sidebar-heading">Navigation</div>
            <ul class="sidebar-menu">
                <li><a href="employee-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="my-tasks.php" class="active"><i class="fas fa-clipboard-list"></i> My Tasks</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages
                    <?php if ($unread_count > 0): ?>
                        <span class="badge badge-warning"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a></li>
            </ul>
        <?php endif; ?>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
    
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="content">
        <div class="header">
            <h1 class="page-title">My Tasks</h1>
            <?php if ($_SESSION['role'] === 'admin'): ?>

            <?php endif; ?>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-filter"></i> Filter Tasks</h2>
            </div>
            <div class="card-body">
                <form method="GET" action=""  id="filter-form">
                    <div class="filter-container">
                        <div class="filter-group">
                            <label class="filter-label" for="status">Status</label>
                            <select name="status" id="status" class="filter-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="to_do" <?php echo $status_filter === 'to_do' ? 'selected' : ''; ?>>To Do</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="priority">Priority</label>
                            <select name="priority" id="priority" class="filter-select">
                                <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div class="search-group">
                            <input 
                                type="text" 
                                name="search" 
                                class="search-input" 
                                placeholder="Search tasks..." 
                                value="<?php echo htmlspecialchars($search_term); ?>"
                            >
                            <button type="submit" class="btn btn-sm">Search</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-tasks"></i> Task List</h2>
                <span><?php echo $total_tasks; ?> tasks found</span>

            </div>
            <div class="card-body">
                <?php if (empty($tasks)): ?>
                    <div class="no-tasks">No tasks found matching your criteria.</div>
                <?php else: ?>
                    <ul class="task-list">
                        <?php foreach ($tasks as $task): ?>
                            <?php 
                                $status_class = '';
                                if ($task['status'] === 'completed') {
                                    $status_class = 'task-completed';
                                } elseif ($task['status'] === 'in_progress') {
                                    $status_class = 'task-in-progress';
                                } elseif ($task['status'] === 'to_do') {
                                    $status_class = 'task-to-do';
                                }
                                
                                // Check if overdue
                                if ($task['status'] !== 'completed' && strtotime($task['deadline']) < strtotime(date('Y-m-d'))) {
                                    $status_class = 'task-overdue';
                                }
                                
                                // Calculate subtask progress
                                $subtask_progress = 0;
                                if ($task['subtask_count'] > 0) {
                                    $subtask_progress = round(($task['completed_subtasks'] / $task['subtask_count']) * 100);
                                }
                            ?>
                            <li class="task-item <?php echo $status_class; ?>">
                                <div class="task-header">
                                    <div class="task-title-container">
                                        <span class="priority-indicator priority-<?php echo $task['priority']; ?>"></span>
                                        <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="task-title page-link">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                        </a>
                                    </div>
                                    <span class="task-status status-<?php echo $task['status'] === 'to_do' && strtotime($task['deadline']) < strtotime(date('Y-m-d')) ? 'overdue' : str_replace('_', '-', $task['status']); ?>">
                                        <?php
                                            if ($task['status'] === 'to_do' && strtotime($task['deadline']) < strtotime(date('Y-m-d'))) {
                                                echo 'Overdue';
                                            } else if ($task['status'] === 'completed') {
                                                echo 'Completed';
                                            } else {
                                                echo ucwords(str_replace('_', ' ', $task['status']));
                                            }
                                        ?>
                                    </span>
                                </div>
                                <div class="task-meta">
                                    <div class="task-meta-item">
                                        <i class="fas fa-calendar"></i> 
                                        <span>Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                                    </div>
                                    <div class="task-meta-item">
                                        <i class="fas fa-user"></i> 
                                        <span>Created by: <?php echo htmlspecialchars($task['created_by_name'] ?? 'Unknown'); ?></span>
                                    </div>
                                    <div class="task-meta-item">
                                        <i class="fas fa-clock"></i> 
                                        <span>Created: <?php echo date('M d, Y', strtotime($task['created_at'])); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($task['description'])): ?>
                                    <div class="task-description">
                                        <?php echo htmlspecialchars($task['description']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="task-footer">
                                    <div class="task-subtasks">
                                        <span>Subtasks: <?php echo $task['completed_subtasks']; ?>/<?php echo $task['subtask_count']; ?></span>
                                        <div class="subtask-progress">
                                            <div class="subtask-progress-bar" style="width: <?php echo $subtask_progress; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="task-actions">
                                        <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="btn btn-sm page-link">View Details</a>
                                        <!-- Admin: Complete Button | Employee: Status Dropdown -->
                                        <?php if ($_SESSION['role'] === 'admin'): ?>
                                            <?php if ($task['status'] !== 'completed'): ?>
                                            <form method="POST" action="update_task_status.php" class="done-form">
                                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                <input type="hidden" name="new_status" value="completed">
                                                <input type="hidden" name="redirect_to" value="my-tasks.php">
                                                <button type="submit" class="btn btn-done" onclick="return markTaskDone(this)">
                                                    <i class="fas fa-check"></i> Complete
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <form method="POST" action="archive_task.php" class="archive-form">
                                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="my-tasks.php">
                                                <button type="submit" class="btn-done-completed" onclick="return archiveCompletedTask(this)">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <!-- Employee Status Controls -->
                                            <div class="employee-status-container" data-task-id="<?php echo $task['id']; ?>">
                                                <?php if ($task['status'] !== 'completed'): ?>
                                                <form method="POST" action="update_task_status.php" class="status-dropdown-form">
                                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="my-tasks.php">
                                                    <select name="new_status" onchange="handleEmployeeStatusChange(this)" class="status-dropdown">
                                                        <option value="to_do" <?php echo $task['status'] == 'to_do' ? 'selected' : ''; ?>>To Do</option>
                                                        <option value="in_progress" <?php echo $task['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="completed" <?php echo $task['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                    </select>
                                                </form>
                                                <?php else: ?>
                                                <span class="btn-done-completed-readonly">
                                                    <i class="fas fa-check-circle"></i> Completed
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=1" class="pagination-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo max(1, $current_page - 1); ?>" class="pagination-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                            
                            <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<span class="pagination-item disabled">...</span>';
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    $active_class = $i == $current_page ? 'active' : '';
                                    echo "<a href='?status=$status_filter&priority=$priority_filter&search=" . urlencode($search_term) . "&page=$i' class='pagination-item $active_class'>$i</a>";
                                }
                                
                                if ($end_page < $total_pages) {
                                    echo '<span class="pagination-item disabled">...</span>';
                                }
                            ?>
                            
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo min($total_pages, $current_page + 1); ?>" class="pagination-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?status=<?php echo $status_filter; ?>&priority=<?php echo $priority_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $total_pages; ?>" class="pagination-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer-note">
            <p>Task Management System &copy; <?php echo date('Y'); ?></p>
        </div>
    </div>
    
    <!-- Script for smooth page transitions and task completion -->
    <script>
        function markTaskDone(button) {
            // Get the task item
            const taskItem = button.closest('.task-item');
            
            // Add fade out animation
            taskItem.classList.add('task-fadeout');
            
            // Show loading state on button
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Completing...';
            button.disabled = true;
            
            // Submit the form after animation starts
            setTimeout(() => {
                button.closest('form').submit();
            }, 200);
            
            return false; // Prevent immediate form submission
        }
        
        function archiveCompletedTask(button) {
            // Get the task item
            const taskItem = button.closest('.task-item');
            
            // Add fade out animation
            taskItem.classList.add('task-fadeout');
            
            // Show loading state on button
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
            button.disabled = true;
            
            // Submit the form after animation starts
            setTimeout(() => {
                button.closest('form').submit();
            }, 200);
            
            return false; // Prevent immediate form submission
        }
        
        function handleEmployeeStatusChange(selectElement) {
            const selectedValue = selectElement.value;
            const container = selectElement.closest('.employee-status-container');
            const form = selectElement.closest('.status-dropdown-form');
            
            if (selectedValue === 'completed') {
                // Add transition class to dropdown form
                form.classList.add('status-transition');
                
                // Submit form after a brief delay for visual feedback
                setTimeout(() => {
                    form.submit();
                }, 300);
            } else {
                // For other statuses, submit immediately
                form.submit();
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit form when filters change
            document.querySelectorAll('.filter-select').forEach(select => {
                select.addEventListener('change', function() {
                    // Add small delay to ensure value is set
                    setTimeout(() => {
                        this.form.submit();
                    }, 100);
                });
            });
            

        });
    </script>
</body>
</html>
