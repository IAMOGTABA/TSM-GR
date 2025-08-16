<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Handle status filter
$status_filter = "";
$params = [];

if (isset($_GET['status_filter']) && !empty($_GET['status_filter'])) {
    $status_filter = " WHERE t.status = :status";
    $params['status'] = $_GET['status_filter'];
}

// Get all non-archived tasks with subtask counts
$sql = "
    SELECT 
        t.*, 
        u.full_name AS assigned_user,
        COUNT(s.id) AS total_subtasks,
        SUM(CASE WHEN s.status = 'done' THEN 1 ELSE 0 END) AS completed_subtasks
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN subtasks s ON t.id = s.task_id
    WHERE t.archived = 0
    $status_filter
    GROUP BY t.id
            ORDER BY t.deadline ASC, t.priority DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks</title>
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
        
        .task-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 1rem;
        }
        
        .task-table th, .task-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .task-table th {
            background-color: var(--bg-secondary);
            color: var(--dark);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .task-table tbody tr {
            transition: background-color 0.2s;
        }
        
        .task-table tbody tr:hover {
            background-color: var(--bg-secondary);
        }
        
        .task-table th:first-child {
            border-top-left-radius: 0.35rem;
        }
        
        .task-table th:last-child {
            border-top-right-radius: 0.35rem;
        }
        
        .status-pending {
            color: var(--danger);
        }
        
        .status-in_progress {
            color: var(--info);
        }
        
        .status-completed {
            color: var(--success);
        }
        
        .priority-high {
            font-weight: bold;
            color: var(--danger);
        }
        
        .priority-medium {
            color: var(--warning);
        }
        
        .priority-low {
            color: var(--success);
        }
        
        .progress-bar-container {
            width: 100%;
            background-color: var(--bg-secondary);
            border-radius: 4px;
            height: 10px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 10px;
            background-color: var(--success);
            border-radius: 4px;
        }
        
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background-color: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 0.35rem;
            transition: background-color 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-add {
            background-color: var(--success);
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        
        .btn-add i {
            margin-right: 0.5rem;
        }
        
        .btn-add:hover {
            background-color: #19b67d;
        }
        
        .filter-container {
            background-color: var(--bg-card);
            padding: 1.25rem;
            border-radius: 0.35rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.3);
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }
        
        .filter-form label {
            font-weight: 600;
            color: var(--text-main);
        }
        
        .filter-form select, .filter-form button {
            padding: 0.5rem 1rem;
            border-radius: 0.35rem;
            border: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            color: var(--text-main);
        }
        
        .filter-form select {
            min-width: 150px;
        }
        
        .deadline-approaching {
            background-color: rgba(246, 194, 62, 0.1);
        }
        
        .deadline-overdue {
            background-color: rgba(231, 74, 59, 0.1);
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
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
        }
        
        .action-buttons .btn i {
            margin-right: 0.3rem;
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
            cursor: default;
            opacity: 0.8;
        }
        
        .btn-archive {
            background-color: var(--success);
            color: white;
            border: none;
            padding: 0.4rem 0.75rem;
            font-size: 1rem;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            min-width: 2.5rem;
        }
        
        .btn-archive:hover {
            background-color: #19b67d;
            transform: scale(1.1);
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
            
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .task-table {
                display: block;
                overflow-x: auto;
            }
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
            <li><a href="admin-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php" class="active page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
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
            <h1 class="welcome-text">Manage Tasks</h1>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <a href="add-task.php" class="btn btn-add page-link"><i class="fas fa-plus"></i> Add New Task</a>
        
        <div class="filter-container">
            <form method="GET" action="" class="filter-form">
                <label for="status_filter"><i class="fas fa-filter"></i> Filter by Status:</label>
                <select name="status_filter" id="status_filter">
                    <option value="">All Active Tasks</option>
                    <option value="to_do" <?php echo isset($_GET['status_filter']) && $_GET['status_filter'] === 'to_do' ? 'selected' : ''; ?>>To Do</option>
                    <option value="in_progress" <?php echo isset($_GET['status_filter']) && $_GET['status_filter'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                </select>
                <small style="color: var(--text-secondary); margin-left: 0.5rem;">
                    <i class="fas fa-info-circle"></i> Marking All Done tasks moved to <a href="analysis.php" style="color: var(--primary-light);">Analysis</a>
                </small>
                <button type="submit" class="btn"><i class="fas fa-search"></i> Apply Filter</button>
                <?php if (isset($_GET['status_filter']) && $_GET['status_filter'] !== ''): ?>
                    <a href="manage-tasks.php" class="btn"><i class="fas fa-times"></i> Clear Filter</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Task List</h2>
                <span><?php echo count($tasks); ?> tasks found</span>
            </div>
            <div class="card-body">
                <?php if (!empty($tasks)): ?>
                <div class="table-responsive">
                    <table class="task-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Deadline</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($tasks as $task): 
                                // Calculate deadline class
                                $deadline_class = '';
                                                if (!empty($task['deadline'])) {
                    $deadline_date = new DateTime($task['deadline']);
                    $today = new DateTime();
                    $interval = $today->diff($deadline_date);
                    
                    if ($deadline_date < $today && $task['status'] !== 'completed') {
                        $deadline_class = 'deadline-overdue';
                    } elseif ($interval->days <= 3 && $task['status'] !== 'completed') {
                        $deadline_class = 'deadline-approaching';
                    }
                }
                                
                                // Calculate progress percentage
                                $progress = 0;
                                if ($task['total_subtasks'] > 0) {
                                    $progress = round(($task['completed_subtasks'] / $task['total_subtasks']) * 100);
                                }
                            ?>
                            <tr class="<?php echo $deadline_class; ?>">
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($task['title']); ?></div>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <i class="fas fa-user" style="margin-right: 0.5rem; color: var(--primary);"></i>
                                        <?php echo htmlspecialchars($task['assigned_user'] ?? 'Unassigned'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $task['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                    </span>
                                </td>
                                <td class="priority-<?php echo $task['priority']; ?>">
                                    <div style="display: flex; align-items: center;">
                                        <?php if ($task['priority'] === 'high'): ?>
                                            <i class="fas fa-arrow-up" style="margin-right: 0.5rem;"></i>
                                        <?php elseif ($task['priority'] === 'medium'): ?>
                                            <i class="fas fa-equals" style="margin-right: 0.5rem;"></i>
                                        <?php else: ?>
                                            <i class="fas fa-arrow-down" style="margin-right: 0.5rem;"></i>
                                        <?php endif; ?>
                                        <?php echo ucfirst($task['priority']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <i class="fas fa-calendar-alt" style="margin-right: 0.5rem; color: var(--primary);"></i>
                                        <?php 
                                        if (!empty($task['deadline'])) {
                                            echo date('M j, Y', strtotime($task['deadline']));
                                            if ($deadline_class === 'deadline-overdue') {
                                                echo ' <span style="color: var(--danger); font-size: 0.85rem;"><i class="fas fa-exclamation-circle"></i> Overdue</span>';
                                            } elseif ($deadline_class === 'deadline-approaching') {
                                                echo ' <span style="color: var(--warning); font-size: 0.85rem;"><i class="fas fa-clock"></i> Soon</span>';
                                            }
                                        } else {
                                            echo 'No deadline';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <div style="font-size: 0.8rem; margin-top: 0.25rem; color: var(--text-secondary);">
                                        <i class="fas fa-list-check"></i> 
                                        <?php echo $task['completed_subtasks']; ?>/<?php echo $task['total_subtasks']; ?> subtasks
                                        (<?php echo $progress; ?>%)
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="btn page-link">
                                            <i class="fas fa-list-check"></i> Subtasks
                                        </a>
                                        <a href="edit-task.php?task_id=<?php echo $task['id']; ?>" class="btn page-link">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        
                                        <!-- Admin: Complete Button | Employee: Status Dropdown -->
                                        <?php if ($_SESSION['role'] === 'admin'): ?>
                                            <?php if ($task['status'] !== 'completed'): ?>
                                            <form method="POST" action="update_task_status.php" style="display:inline-block;" class="done-form">
                                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                <input type="hidden" name="new_status" value="completed">
                                                <input type="hidden" name="redirect_to" value="analysis.php">
                                                <button type="submit" class="btn btn-done" onclick="return markTaskDone(this)">
                                                    <i class="fas fa-check"></i> Marking All Done
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <form method="POST" action="archive_task.php" style="display:inline-block;" class="archive-form">
                                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                <input type="hidden" name="redirect_to" value="<?php echo $_SERVER['PHP_SELF']; ?>">
                                                <button type="submit" class="btn-archive" onclick="return archiveTask(this)" title="Archive Task">
                                                    ✓
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <!-- Employee Status Controls -->
                                            <div class="employee-status-container" data-task-id="<?php echo $task['id']; ?>">
                                                <?php if ($task['status'] !== 'completed'): ?>
                                                <form method="POST" action="update_task_status.php" style="display:inline-block;" class="status-dropdown-form">
                                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="<?php echo $_SERVER['PHP_SELF']; ?>">
                                                    <select name="new_status" onchange="handleEmployeeStatusChange(this)" class="status-dropdown">
                                                        <option value="to_do" <?php echo $task['status'] == 'to_do' ? 'selected' : ''; ?>>To Do</option>
                                                        <option value="in_progress" <?php echo $task['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="completed" <?php echo $task['status'] == 'completed' ? 'selected' : ''; ?>>Marking All Done</option>
                                                    </select>
                                                </form>
                                                <?php else: ?>
                                                <span class="btn-done-completed-readonly">
                                                    <i class="fas fa-check-circle"></i> Marking All Done
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-tasks" style="font-size: 3rem; color: var(--primary); margin-bottom: 1rem;"></i>
                        <p>No tasks found. Click "Add New Task" to create one.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Script for smooth page transitions and task completion -->
    <script>
        function markTaskDone(button) {
            // Get the task row
            const taskRow = button.closest('tr');
            
            // Add fade out animation
            taskRow.classList.add('task-fadeout');
            
            // Show loading state on button
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking All Done...';
            button.disabled = true;
            
            // Submit the form after animation starts
            setTimeout(() => {
                button.closest('form').submit();
            }, 200);
            
            return false; // Prevent immediate form submission
        }
        
        function archiveTask(button) {
            // Get the task row
            const taskRow = button.closest('tr');
            
            // Add fade out animation
            taskRow.classList.add('task-fadeout');
            
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
                // Change redirect to analysis.php for completed status
                const redirectInput = form.querySelector('input[name="redirect_to"]');
                if (redirectInput) {
                    redirectInput.value = 'analysis.php';
                }
                
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
            // Page loaded successfully
        });
    </script>
</body>
</html>
