<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Handle task deletion
if ($_POST && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_task' && isset($_POST['task_id'])) {
        $task_id = (int)$_POST['task_id'];
        try {
            // Delete task and related data
            $pdo->beginTransaction();
            
            $delete_activity = $pdo->prepare("DELETE FROM activity_logs WHERE task_id = :task_id");
            $delete_activity->execute(['task_id' => $task_id]);
            
            $delete_subtasks = $pdo->prepare("DELETE FROM subtasks WHERE task_id = :task_id");
            $delete_subtasks->execute(['task_id' => $task_id]);
            
            $delete_task = $pdo->prepare("DELETE FROM tasks WHERE id = :task_id");
            $delete_task->execute(['task_id' => $task_id]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Task deleted successfully!";
        } catch (PDOException $e) {
            $pdo->rollback();
            $_SESSION['error_message'] = "Error deleting task: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'clear_all_tasks') {
        try {
            // Delete all completed tasks
            $pdo->beginTransaction();
            
            $get_completed = $pdo->prepare("SELECT id FROM tasks WHERE status = 'done'");
            $get_completed->execute();
            $completed_tasks = $get_completed->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($completed_tasks)) {
                $task_ids = implode(',', $completed_tasks);
                
                $pdo->exec("DELETE FROM activity_logs WHERE task_id IN ($task_ids)");
                $pdo->exec("DELETE FROM subtasks WHERE task_id IN ($task_ids)");
                $pdo->exec("DELETE FROM tasks WHERE id IN ($task_ids)");
            }
            
            $pdo->commit();
            $_SESSION['success_message'] = count($completed_tasks) . " completed tasks cleared successfully!";
        } catch (PDOException $e) {
            $pdo->rollback();
            $_SESSION['error_message'] = "Error clearing tasks: " . $e->getMessage();
        }
    }
    header('Location: analysis.php');
    exit;
}

// Get detailed employee performance data
$employee_performance = $pdo->prepare("
    SELECT u.full_name, u.id, u.email,
           COUNT(t.id) as total_assigned,
           SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN t.due_date < CURDATE() AND t.status != 'done' THEN 1 ELSE 0 END) as overdue,
           SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
           SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending,
           ROUND(AVG(CASE WHEN t.status = 'done' THEN 100 ELSE 0 END), 1) as completion_rate,
           AVG(CASE WHEN t.status = 'done' AND t.completion_date IS NOT NULL 
               THEN DATEDIFF(t.completion_date, t.created_at) END) as avg_completion_days
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role = 'employee'
    GROUP BY u.id, u.full_name, u.email
    ORDER BY completion_rate DESC, completed DESC
");
$employee_performance->execute();
$employees = $employee_performance->fetchAll(PDO::FETCH_ASSOC);

// Get completed tasks for analysis
$completed_tasks_stmt = $pdo->prepare("
    SELECT t.*, u.full_name as assigned_user, creator.full_name as created_by_name,
           DATEDIFF(t.completion_date, t.created_at) as days_to_complete,
           (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id) as total_subtasks,
           (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND status = 'done') as completed_subtasks
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN users creator ON t.created_by = creator.id
    WHERE t.status = 'done'
    ORDER BY t.completion_date DESC
");
$completed_tasks_stmt->execute();
$completed_tasks = $completed_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get task completion trends (last 30 days)
$trends_stmt = $pdo->prepare("
    SELECT DATE(completion_date) as completion_date,
           COUNT(*) as tasks_completed,
           AVG(DATEDIFF(completion_date, created_at)) as avg_days
    FROM tasks 
    WHERE status = 'done' 
    AND completion_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(completion_date)
    ORDER BY completion_date DESC
");
$trends_stmt->execute();
$completion_trends = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get overall statistics
$stats = [
    'total_completed' => count($completed_tasks),
    'avg_completion_time' => 0,
    'total_employees' => count($employees),
    'active_employees' => 0
];

if (!empty($completed_tasks)) {
    $total_days = array_sum(array_column($completed_tasks, 'days_to_complete'));
    $stats['avg_completion_time'] = round($total_days / count($completed_tasks), 1);
}

foreach ($employees as $employee) {
    if ($employee['total_assigned'] > 0) {
        $stats['active_employees']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Analysis</title>
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
        
        .btn {
            background-color: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.35rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #c92a2a;
        }
        
        .btn-warning {
            background-color: var(--warning);
            color: #000;
        }
        
        .btn-warning:hover {
            background-color: #e6a800;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            padding: 1.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            border-left: 4px solid;
        }
        
        .stat-card-primary { border-left-color: var(--primary); }
        .stat-card-success { border-left-color: var(--success); }
        .stat-card-info { border-left-color: var(--info); }
        .stat-card-warning { border-left-color: var(--warning); }
        
        .stat-card .label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
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
        
        .employee-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        
        .employee-table th,
        .employee-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .employee-table th {
            background-color: var(--bg-secondary);
            font-weight: 600;
            color: var(--primary-light);
        }
        
        .employee-table tr:hover {
            background-color: var(--bg-secondary);
        }
        
        .performance-bar {
            width: 100px;
            height: 8px;
            background-color: var(--bg-secondary);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .performance-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--danger) 0%, var(--warning) 50%, var(--success) 100%);
            transition: width 0.3s ease;
        }
        
        .task-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .task-table th,
        .task-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .task-table th {
            background-color: var(--bg-secondary);
            font-weight: 600;
            color: var(--primary-light);
        }
        
        .priority-high { color: var(--danger); }
        .priority-medium { color: var(--warning); }
        .priority-low { color: var(--success); }
        
        .success-message {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
            padding: 1rem;
            border-radius: 0.35rem;
            border-left: 4px solid var(--success);
            margin-bottom: 1rem;
        }
        
        .error-message {
            background-color: rgba(231, 74, 59, 0.2);
            color: var(--danger);
            padding: 1rem;
            border-radius: 0.35rem;
            border-left: 4px solid var(--danger);
            margin-bottom: 1rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: var(--bg-card);
            margin: 15% auto;
            padding: 2rem;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
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
            <li><a href="admin-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="manage-users.php"><i class="fas fa-users"></i> Manage Users</a></li>
            <li><a href="analysis.php" class="active"><i class="fas fa-chart-line"></i> Analysis</a></li>
            <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="content">
        <div class="header">
            <h1 class="page-title">Performance Analysis</h1>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card stat-card-primary">
                <div class="label">Total Completed Tasks</div>
                <div class="value"><?php echo $stats['total_completed']; ?></div>
            </div>
            
            <div class="stat-card stat-card-success">
                <div class="label">Average Completion Time</div>
                <div class="value"><?php echo $stats['avg_completion_time']; ?> days</div>
            </div>
            
            <div class="stat-card stat-card-info">
                <div class="label">Active Employees</div>
                <div class="value"><?php echo $stats['active_employees']; ?>/<?php echo $stats['total_employees']; ?></div>
            </div>
            
            <div class="stat-card stat-card-warning">
                <div class="label">Tasks This Month</div>
                <div class="value"><?php echo count($completion_trends); ?></div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-users"></i> Employee Performance Overview</h2>
            </div>
            <div class="card-body">
                <table class="employee-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Email</th>
                            <th>Total Assigned</th>
                            <th>Completed</th>
                            <th>In Progress</th>
                            <th>Pending</th>
                            <th>Overdue</th>
                            <th>Completion Rate</th>
                            <th>Avg. Days</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($employee['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($employee['email']); ?></td>
                            <td><?php echo $employee['total_assigned']; ?></td>
                            <td style="color: var(--success);"><?php echo $employee['completed']; ?></td>
                            <td style="color: var(--info);"><?php echo $employee['in_progress']; ?></td>
                            <td style="color: var(--warning);"><?php echo $employee['pending']; ?></td>
                            <td style="color: var(--danger);"><?php echo $employee['overdue']; ?></td>
                            <td><?php echo $employee['completion_rate']; ?>%</td>
                            <td><?php echo $employee['avg_completion_days'] ? round($employee['avg_completion_days'], 1) : 'N/A'; ?></td>
                            <td>
                                <div class="performance-bar">
                                    <div class="performance-fill" style="width: <?php echo $employee['completion_rate']; ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-check-circle"></i> Completed Tasks Archive</h2>
                <div class="action-buttons">
                    <button class="btn btn-warning" onclick="showClearModal()">
                        <i class="fas fa-broom"></i> Clear All Tasks
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($completed_tasks)): ?>
                    <p>No completed tasks found.</p>
                <?php else: ?>
                    <table class="task-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Assigned To</th>
                                <th>Priority</th>
                                <th>Completed Date</th>
                                <th>Days to Complete</th>
                                <th>Subtasks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_tasks as $task): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                    <?php if ($task['description']): ?>
                                        <br><small style="color: var(--text-secondary);">
                                            <?php echo htmlspecialchars(substr($task['description'], 0, 100)) . (strlen($task['description']) > 100 ? '...' : ''); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($task['assigned_user'] ?? 'Unassigned'); ?></td>
                                <td class="priority-<?php echo $task['priority']; ?>">
                                    <?php echo ucfirst($task['priority']); ?>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($task['completion_date'])); ?></td>
                                <td>
                                    <?php 
                                    if ($task['days_to_complete'] == 0) {
                                        echo 'Same day';
                                    } elseif ($task['days_to_complete'] == 1) {
                                        echo '1 day';
                                    } else {
                                        echo $task['days_to_complete'] . ' days';
                                    }
                                    ?>
                                </td>
                                <td><?php echo $task['completed_subtasks']; ?>/<?php echo $task['total_subtasks']; ?></td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this task? This action cannot be undone.')">
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Clear All Modal -->
    <div id="clearModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Clear All Completed Tasks</h3>
                <button class="close-btn" onclick="closeClearModal()">&times;</button>
            </div>
            <div>
                <p>Are you sure you want to delete all completed tasks? This action cannot be undone.</p>
                <br>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button class="btn" onclick="closeClearModal()">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="clear_all_tasks">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-broom"></i> Clear All
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showClearModal() {
            document.getElementById('clearModal').style.display = 'block';
        }
        
        function closeClearModal() {
            document.getElementById('clearModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('clearModal');
            if (event.target === modal) {
                closeClearModal();
            }
        }
    </script>
</body>
</html> 