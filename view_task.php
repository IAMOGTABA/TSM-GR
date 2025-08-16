<?php
// view_task.php
session_start();
require 'config.php'; // ensure this has your DB connection

// Track where the user came from to return there
$referrer = '';
if (isset($_SERVER['HTTP_REFERER'])) {
    $allowed_referrers = ['admin-dashboard.php', 'manage-tasks.php', 'employee-dashboard.php', 'my-tasks.php'];
    $referer_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    $referer_file = basename($referer_path);
    
    if (in_array($referer_file, $allowed_referrers)) {
        $referrer = $referer_file;
    }
}

// Store referrer in session if it exists
if (!empty($referrer)) {
    $_SESSION['return_to'] = $referrer;
} elseif (!isset($_SESSION['return_to'])) {
    // Default return location based on user role
    $_SESSION['return_to'] = ($_SESSION['role'] === 'admin') ? 'admin-dashboard.php' : 'my-tasks.php';
}

// Check if task_id is provided
if (!isset($_GET['task_id'])) {
    die("No task specified.");
}

// Handle form submission for "Done" button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['done_button'])) {
    // Redirect to the referring page
    $return_url = $_SESSION['return_to'];
    header("Location: $return_url");
    exit;
}

// Fetch the parent task
$task_id = (int) $_GET['task_id'];
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS assigned_user 
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.id = :task_id
");
$stmt->execute(['task_id' => $task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    die("Task not found.");
}

// Authorization check: employees can only view tasks assigned to them
if ($_SESSION['role'] !== 'admin' && $task['assigned_to'] != $_SESSION['user_id']) {
    die("Access denied. You can only view tasks assigned to you.");
}

// Fetch subtasks
$sub_stmt = $pdo->prepare("SELECT * FROM subtasks WHERE task_id = :task_id ORDER BY id");
$sub_stmt->execute(['task_id' => $task_id]);
$subtasks = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate progress
$total_subtasks = count($subtasks);
$completed_subtasks = 0;
$progress_percentage = 0;

if ($total_subtasks > 0) {
    foreach ($subtasks as $sub) {
        if ($sub['status'] === 'done') {
            $completed_subtasks++;
        }
    }
    $progress_percentage = round(($completed_subtasks / $total_subtasks) * 100);
    
    // If all subtasks are completed, update the task status to 'completed'
    if ($progress_percentage === 100 && $task['status'] !== 'completed') {
        $update_task = $pdo->prepare("UPDATE tasks SET status = 'completed' WHERE id = :task_id");
        $result = $update_task->execute(['task_id' => $task_id]);
        
        if ($result) {
            // Refresh the task data
            $stmt->execute(['task_id' => $task_id]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Log the update for debugging
            error_log("Task ID $task_id updated to completed status. All subtasks done.");
        } else {
            error_log("Failed to update Task ID $task_id to done status.");
        }
    }
}

// Handle new subtask creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subtask_title'])) {
    $subtask_title = trim($_POST['subtask_title']);
    if (!empty($subtask_title)) {
        // Insert into subtasks table
        $create_sub = $pdo->prepare("
            INSERT INTO subtasks (task_id, title) 
            VALUES (:task_id, :title)
        ");
        $create_sub->execute([
            'task_id' => $task_id,
            'title' => $subtask_title
        ]);

        // Redirect to avoid form resubmission
        header("Location: view_task.php?task_id=$task_id");
        exit;
    }
}

// Note: Subtask status changes are now handled via AJAX (update_subtask_ajax.php)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Task</title>
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
            font-family: Arial, sans-serif;
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
        
        .task-details {
            background-color: var(--bg-card);
            padding: 1.25rem;
            border-radius: 0.35rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .task-details-row {
            display: flex;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .task-details-label {
            width: 120px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .task-details-value {
            flex: 1;
            min-width: 200px;
        }
        
        .task-description {
            background-color: var(--bg-secondary);
            padding: 1rem;
            border-radius: 0.35rem;
            margin-top: 1rem;
            border: 1px solid var(--border-color);
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .badge-success {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
        }
        
        .badge-info {
            background-color: rgba(54, 185, 204, 0.2);
            color: var(--info);
        }
        
        .badge-danger {
            background-color: rgba(231, 74, 59, 0.2);
            color: var(--danger);
        }
        
        .badge-warning {
            background-color: rgba(246, 194, 62, 0.2);
            color: var(--warning);
        }
        
        .badge-primary {
            background-color: rgba(106, 13, 173, 0.2);
            color: var(--primary);
        }
        
        .progress-bar-container {
            width: 100%;
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            height: 20px;
            margin: 1rem 0;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 20px;
            background-color: var(--success);
            border-radius: 0.35rem;
            text-align: center;
            color: white;
            line-height: 20px;
            font-size: 0.75rem;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .subtask-list {
            margin-top: 1rem;
        }
        
        .subtask-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .subtask-item:hover {
            background-color: rgba(42, 42, 42, 0.8);
        }
        
        .status-to-do {
            color: var(--danger);
        }
        
        .status-in-progress {
            color: var(--info);
        }
        
        .status-completed {
            color: var(--success);
        }
        
        .subtask-title {
            flex-grow: 1;
            margin-left: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .subtask-title.completed {
            text-decoration: line-through;
            opacity: 0.7;
        }
        
        .subtask-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 0.35rem;
            display: inline-block;
            background-color: var(--bg-secondary);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            color: white;
        }
        
        .btn-edit {
            background-color: var(--info);
            color: white;
            border: none;
        }
        
        .btn-edit:hover {
            background-color: #2a98a8;
        }
        
        .btn-delete {
            background-color: var(--danger);
            color: white;
            border: none;
        }
        
        .btn-delete:hover {
            background-color: #c93a2e;
        }
        
        .btn-done {
            background-color: var(--primary);
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
            border: none;
        }
        
        .btn-done:hover {
            background-color: var(--primary-dark);
        }
        
        .checkbox-container {
            display: inline-block;
            position: relative;
            padding-left: 25px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .subtask-status-toggle {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }
        
        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: var(--bg-main);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            transition: all 0.3s ease;
        }
        
        .checkbox-container:hover .checkmark {
            background-color: var(--bg-secondary);
        }
        
        .checkbox-container input:checked ~ .checkmark {
            background-color: var(--success);
            border-color: var(--success);
        }
        
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }
        
        .checkbox-container input:checked ~ .checkmark:after {
            display: block;
        }
        
        .checkbox-container .checkmark:after {
            left: 7px;
            top: 3px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        .add-subtask-form {
            margin-top: 1.5rem;
            padding: 1.25rem;
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            border: 1px solid var(--border-color);
        }
        
        .add-subtask-form input[type="text"] {
            width: 80%;
            padding: 0.5rem 0.75rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            border-radius: 0.35rem;
        }
        
        .add-subtask-form input[type="text"]::placeholder {
            color: var(--text-secondary);
        }
        
        .add-subtask-form button {
            padding: 0.5rem 0.75rem;
            background-color: var(--success);
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 0.35rem;
            margin-left: 0.5rem;
        }
        
        .add-subtask-form button:hover {
            background-color: #19b67d;
        }
        
        .action-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        
        .status-to_do {
            color: var(--text-secondary);
            text-decoration: none;
        }
        
        .status-done {
            color: var(--success);
            text-decoration: line-through;
        }
        
        .no-subtasks {
            padding: 2rem;
            text-align: center;
            color: var(--text-secondary);
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            margin-top: 1rem;
        }
        
        .no-subtasks i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary);
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
            
            .task-details-row {
                flex-direction: column;
            }
            
            .task-details-label {
                width: 100%;
                margin-bottom: 0.25rem;
            }
            
            .add-subtask-form input[type="text"] {
                width: 70%;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 1rem;
            }
        }
        
        /* Status Update Section Styles */
        .status-update-section {
            margin: 1.5rem 0;
            padding: 1.25rem;
            background-color: var(--bg-secondary);
            border-radius: 0.35rem;
            border: 1px solid var(--border-color);
        }
        
        .status-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .status-dropdown {
            background-color: var(--bg-main);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }
        
        .status-dropdown:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(106, 13, 173, 0.2);
        }
        
        .btn-done {
            background-color: var(--success);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            border-radius: 0.35rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .btn-done:hover {
            background-color: #19b67d;
            transform: translateY(-1px);
        }
        
        .btn-done-completed {
            background-color: var(--danger);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            border-radius: 0.35rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .btn-done-completed:hover {
            background-color: #c93a2e;
            transform: translateY(-1px);
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
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <li><a href="admin-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-tasks.php" class="page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
                <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
                <li><a href="manage-users.php" class="page-link"><i class="fas fa-users"></i> Manage Users</a></li>
                <li><a href="analysis.php" class="page-link"><i class="fas fa-chart-line"></i> Analysis</a></li>
                <li><a href="messages.php" class="page-link"><i class="fas fa-envelope"></i> Messages</a></li>
            <?php else: ?>
                <li><a href="employee-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="my-tasks.php" class="page-link"><i class="fas fa-clipboard-list"></i> My Tasks</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="content">
        <div class="header">
            <h1 class="page-title">Task Details</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h2>
                <span class="badge badge-<?php 
                if ($task['status'] === 'completed') echo 'success';
                elseif ($task['status'] === 'in_progress') echo 'info';
                else echo 'danger';
                ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                </span>
            </div>
            <div class="card-body">
                <div class="task-details">
                    <div class="task-details-row">
                        <div class="task-details-label">Assigned To:</div>
                        <div class="task-details-value">
                            <i class="fas fa-user" style="color: var(--primary); margin-right: 0.5rem;"></i>
                            <?php echo $task['assigned_user'] ? htmlspecialchars($task['assigned_user']) : 'Unassigned'; ?>
                        </div>
                        
                        <div class="task-details-label">Priority:</div>
                        <div class="task-details-value">
                            <?php if ($task['priority'] === 'high'): ?>
                                <i class="fas fa-arrow-up" style="color: var(--danger); margin-right: 0.5rem;"></i>
                            <?php elseif ($task['priority'] === 'medium'): ?>
                                <i class="fas fa-equals" style="color: var(--warning); margin-right: 0.5rem;"></i>
                            <?php else: ?>
                                <i class="fas fa-arrow-down" style="color: var(--success); margin-right: 0.5rem;"></i>
                            <?php endif; ?>
                            <span class="<?php echo 'priority-' . $task['priority']; ?>">
                                <?php echo ucfirst($task['priority']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="task-details-row">
                        <div class="task-details-label">Deadline:</div>
                        <div class="task-details-value">
                            <i class="fas fa-calendar-alt" style="color: var(--primary); margin-right: 0.5rem;"></i>
                            <?php 
                            if (!empty($task['deadline'])) {
                                echo date('M j, Y', strtotime($task['deadline']));
                                
                                $deadline_date = new DateTime($task['deadline']);
                                $today = new DateTime();
                                
                                if ($deadline_date < $today && $task['status'] !== 'completed') {
                                    echo ' <span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Overdue</span>';
                                } elseif ($deadline_date->diff($today)->days <= 3 && $task['status'] !== 'completed') {
                                    echo ' <span class="badge badge-warning"><i class="fas fa-clock"></i> Due Soon</span>';
                                }
                            } else {
                                echo 'No deadline';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($task['description'])): ?>
                    <div class="task-details-row">
                        <div class="task-details-label">Description:</div>
                    </div>
                    <div class="task-description">
                        <?php echo nl2br(htmlspecialchars($task['description'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <h3 style="margin-bottom: 1rem;">Subtasks Progress</h3>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: <?php echo $progress_percentage; ?>%">
                        <?php echo $progress_percentage; ?>%
                    </div>
                </div>
                <div style="text-align: right; font-size: 0.85rem; color: var(--text-secondary);">
                    <?php echo $completed_subtasks; ?> of <?php echo $total_subtasks; ?> subtasks completed
                </div>
                
                <h3 style="margin: 1.5rem 0 1rem 0;">Subtasks</h3>
                <?php if (!empty($subtasks)): ?>
                <div class="subtask-list">
                    <?php foreach ($subtasks as $subtask): ?>
                    <div class="subtask-item" data-subtask-id="<?php echo $subtask['id']; ?>">
                        <div style="display: flex; align-items: center; width: 100%;">
                            <label class="checkbox-container">
                                <input type="checkbox" 
                                       class="subtask-status-toggle" 
                                       data-subtask-id="<?php echo $subtask['id']; ?>"
                                       data-task-id="<?php echo $task_id; ?>"
                                       <?php if ($subtask['status'] === 'done') echo 'checked'; ?>>
                                <span class="checkmark"></span>
                            </label>
                            <span class="subtask-title <?php echo 'status-' . $subtask['status']; ?> <?php echo $subtask['status'] === 'done' ? 'completed' : ''; ?>">
                                <?php echo htmlspecialchars($subtask['title']); ?>
                            </span>
                            <div class="subtask-actions">
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                <a href="update_subtask.php?id=<?php echo $subtask['id']; ?>&task_id=<?php echo $task_id; ?>" class="btn btn-edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete_subtask.php?id=<?php echo $subtask['id']; ?>&task_id=<?php echo $task_id; ?>" class="btn btn-delete" 
                                   onclick="return confirm('Are you sure you want to delete this subtask?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="no-subtasks">
                    <i class="fas fa-clipboard-list"></i>
                    <p>No subtasks added yet.</p>
                </div>
                <?php endif; ?>
                
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <div class="add-subtask-form">
                    <h3 style="margin-bottom: 1rem;">Add New Checklist Item</h3>
                    <form method="POST">
                        <input type="text" name="subtask_title" placeholder="Enter subtask title..." required>
                        <button type="submit"><i class="fas fa-plus"></i> Add</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Status Update Section -->
                <div class="status-update-section">
                    <h3 style="margin-bottom: 1rem;">Update Task Status</h3>
                    <div class="status-controls">
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <!-- Admin: Simple Complete Button -->
                            <?php if ($task['status'] !== 'completed'): ?>
                            <form method="POST" action="update_task_status.php" style="display:inline-block;" class="done-form">
                                <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                                <input type="hidden" name="new_status" value="completed">
                                <input type="hidden" name="redirect_to" value="view_task.php?task_id=<?php echo $task_id; ?>">
                                <button type="submit" class="btn btn-done">
                                    <i class="fas fa-check"></i> Mark as Complete
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="archive_task.php" style="display:inline-block;" class="archive-form">
                                <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo $_SESSION['return_to']; ?>">
                                <button type="submit" class="btn-done-completed" onclick="return confirm('Are you sure you want to archive this completed task?')">
                            <i class="fas fa-check-circle"></i> Archive Marking All Done Task
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Employee: Status Dropdown (stays as dropdown even when done) -->
                            <form method="POST" action="update_task_status.php" style="display:inline-block;" class="status-dropdown-form">
                                <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                                <input type="hidden" name="redirect_to" value="view_task.php?task_id=<?php echo $task_id; ?>">
                                <label for="task_status" style="margin-right: 0.5rem; font-weight: 600;">Status:</label>
                                <select name="new_status" id="task_status" onchange="this.form.submit()" class="status-dropdown">
                                    <option value="to_do" <?php echo $task['status'] == 'to_do' ? 'selected' : ''; ?>>To Do</option>
                                    <option value="in_progress" <?php echo $task['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $task['status'] == 'completed' ? 'selected' : ''; ?>>Marking All Done</option>
                                </select>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="<?php echo $_SESSION['return_to']; ?>" class="btn">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Script for smooth page transitions and subtask animations -->
    <script>
        function updateProgressBar(percentage, completed, total) {
            const progressBar = document.querySelector('.progress-bar');
            const progressText = document.querySelector('.progress-bar');
            const subtaskStats = document.querySelector('.progress-bar-container + div');
            
            // Update progress bar width with smooth animation
            progressBar.style.width = percentage + '%';
            progressBar.textContent = percentage + '%';
            
            // Update stats text
            if (subtaskStats) {
                subtaskStats.innerHTML = `${completed} of ${total} subtasks completed`;
            }
        }
        
        function updateTaskStatusBadge(newStatus) {
            const badge = document.querySelector('.card-header .badge');
            if (badge && newStatus) {
                badge.className = 'badge badge-' + (newStatus === 'done' ? 'success' : newStatus === 'in_progress' ? 'info' : 'danger');
                badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1).replace('_', ' ');
                
                // Add a subtle animation
                badge.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    badge.style.transform = 'scale(1)';
                }, 200);
            }
        }
        
        function handleSubtaskToggle(checkbox) {
            const subtaskId = checkbox.dataset.subtaskId;
            const taskId = checkbox.dataset.taskId;
            const newStatus = checkbox.checked ? 'done' : 'to_do';
            const subtaskItem = checkbox.closest('.subtask-item');
            const subtaskTitle = subtaskItem.querySelector('.subtask-title');
            
            // Disable checkbox during request
            checkbox.disabled = true;
            
            // Add visual feedback
            subtaskItem.style.opacity = '0.7';
            
            // Debug logging
            console.log('Sending subtask update:', { subtaskId, taskId, newStatus });
            
            // Make AJAX request
            fetch('update_subtask_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `subtask_id=${subtaskId}&task_id=${taskId}&status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update progress bar smoothly
                    updateProgressBar(data.progress_percentage, data.completed_count, data.total_count);
                    
                    // Update subtask visual state
                    if (newStatus === 'done') {
                        subtaskTitle.classList.add('completed');
                        subtaskTitle.classList.remove('status-to_do');
                        subtaskTitle.classList.add('status-done');
                    } else {
                        subtaskTitle.classList.remove('completed');
                        subtaskTitle.classList.remove('status-done');
                        subtaskTitle.classList.add('status-to_do');
                    }
                    
                    // Update task status badge if needed
                    if (data.task_status_changed) {
                        updateTaskStatusBadge(data.new_task_status);
                    }
                    
                    // Restore visual state
                    subtaskItem.style.opacity = '1';
                    checkbox.disabled = false;
                    
                } else {
                    // Revert checkbox on error
                    checkbox.checked = !checkbox.checked;
                    subtaskItem.style.opacity = '1';
                    checkbox.disabled = false;
                    alert('Error updating subtask: ' + data.message);
                }
            })
            .catch(error => {
                // Revert checkbox on error
                checkbox.checked = !checkbox.checked;
                subtaskItem.style.opacity = '1';
                checkbox.disabled = false;
                alert('Network error occurred');
                console.error('Error:', error);
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Handle subtask checkbox changes
            document.querySelectorAll('.subtask-status-toggle').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    handleSubtaskToggle(this);
                });
            });
            

        });
    </script>
</body>
</html>
