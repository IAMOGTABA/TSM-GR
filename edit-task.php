<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Check if task_id is provided
if (!isset($_GET['task_id'])) {
    header('Location: manage-tasks.php');
    exit;
}

$task_id = (int) $_GET['task_id'];

// Get task information
$stmt = $pdo->prepare("
    SELECT * FROM tasks WHERE id = :task_id
");
$stmt->execute(['task_id' => $task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header('Location: manage-tasks.php');
    exit;
}

// Get users for assignment dropdown
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE status = 'active'");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get existing subtasks
$sub_stmt = $pdo->prepare("SELECT * FROM subtasks WHERE task_id = :task_id ORDER BY id");
$sub_stmt->execute(['task_id' => $task_id]);
$subtasks = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form inputs
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'to_do';
    $priority = $_POST['priority'] ?? 'medium';
    $deadline = $_POST['deadline'] ?? null;
    $assigned_to = $_POST['assigned_to'] ?? null;
    
    // Validate inputs
    $errors = [];
    
    if (empty($title)) {
        $errors[] = "Task title is required";
    }
    
    if (empty($errors)) {
        try {
            // Update task in database
            $stmt = $pdo->prepare("
                UPDATE tasks 
                SET title = :title, 
                    description = :description, 
                    status = :status, 
                    priority = :priority,
                    deadline = :deadline,
                    assigned_to = :assigned_to
                WHERE id = :id
            ");
            
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'deadline' => $deadline,
                'assigned_to' => $assigned_to,
                'id' => $task_id
            ]);
            
            // Process new subtasks if any
            if (!empty($_POST['new_subtasks'])) {
                $subtasks = explode("\n", trim($_POST['new_subtasks']));
                $subtask_stmt = $pdo->prepare("INSERT INTO subtasks (task_id, title) VALUES (:task_id, :title)");
                
                foreach ($subtasks as $subtask_title) {
                    if (!empty(trim($subtask_title))) {
                        $subtask_stmt->execute([
                            'task_id' => $task_id,
                            'title' => trim($subtask_title)
                        ]);
                    }
                }
            }
            
            $message = "Task updated successfully!";
            
            // Refresh task data
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = :task_id");
            $stmt->execute(['task_id' => $task_id]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Refresh subtasks
            $sub_stmt = $pdo->prepare("SELECT * FROM subtasks WHERE task_id = :task_id ORDER BY id");
            $sub_stmt->execute(['task_id' => $task_id]);
            $subtasks = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
        }
    } else {
        $message = "Please fix the following errors:<br>" . implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Task</title>
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
        
        form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        label {
            font-weight: 600;
            color: var(--text-main);
            display: block;
        }
        
        input[type="text"], 
        input[type="date"], 
        textarea, 
        select {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.35rem;
            color: var(--text-main);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus, 
        input[type="date"]:focus, 
        textarea:focus, 
        select:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        input::placeholder, 
        textarea::placeholder {
            color: var(--text-secondary);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
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
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #19b67d;
        }
        
        .section-title {
            color: var(--dark);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
            margin-bottom: 1.25rem;
            font-size: 1.1rem;
        }
        
        .subtasks-section {
            margin-top: 2rem;
            padding-top: 0.5rem;
        }
        
        .existing-subtasks {
            margin-bottom: 1.5rem;
        }
        
        .subtask-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            background-color: var(--bg-secondary);
            padding: 0.75rem;
            border-radius: 0.35rem;
            margin-bottom: 0.5rem;
            border-left: 3px solid var(--primary);
        }
        
        .subtask-item.completed {
            border-left-color: var(--success);
        }
        
        .subtask-controls {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #d93426;
        }
        
        .helper-text {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }
        
        .action-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
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
                <li><a href="reports.php" class="page-link"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <?php else: ?>
                <li><a href="employee-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="my-tasks.php" class="page-link"><i class="fas fa-clipboard-list"></i> My Tasks</a></li>
                <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="content">
        <div class="header">
            <h1 class="page-title">Edit Task</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="<?php echo strpos($message, 'Error') !== false || strpos($message, 'Please fix') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-edit"></i> Task Information</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                    
                    <div class="form-group">
                        <label for="title"><i class="fas fa-heading"></i> Task Title</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($task['title']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description"><i class="fas fa-align-left"></i> Description</label>
                        <textarea name="description" id="description"><?php echo htmlspecialchars($task['description']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="status"><i class="fas fa-tasks"></i> Status</label>
                        <select name="status" id="status">
                            <option value="to_do" <?php echo $task['status'] === 'to_do' ? 'selected' : ''; ?>>To Do</option>
                            <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="priority"><i class="fas fa-flag"></i> Priority</label>
                        <select name="priority" id="priority">
                            <option value="low" <?php echo $task['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $task['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $task['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="deadline"><i class="fas fa-calendar-alt"></i> Deadline</label>
                        <input type="date" name="deadline" id="deadline" value="<?php echo htmlspecialchars($task['deadline']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="assigned_to"><i class="fas fa-user-check"></i> Assign To</label>
                        <select name="assigned_to" id="assigned_to">
                            <option value="">-- Select User --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $task['assigned_to'] == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="subtasks-section">
                        <h3 class="section-title"><i class="fas fa-list-ul"></i> Subtasks / Checklist Items</h3>
                        
                        <div class="existing-subtasks">
                            <h4>Current Subtasks:</h4>
                            <?php if ($subtasks): ?>
                                <?php foreach ($subtasks as $subtask): ?>
                                    <div class="subtask-item <?php echo $subtask['status'] === 'done' ? 'completed' : ''; ?>">
                                        <div><?php echo htmlspecialchars($subtask['title']); ?></div>
                                        <div class="subtask-controls">
                                            <a href="update_subtask.php?id=<?php echo $subtask['id']; ?>&task_id=<?php echo $task_id; ?>&completed=<?php echo $subtask['status'] === 'done' ? '0' : '1'; ?>" class="btn btn-sm page-link">
                                                <?php if ($subtask['status'] === 'done'): ?>
                                                    <i class="fas fa-times"></i> Mark Incomplete
                                                <?php else: ?>
                                                    <i class="fas fa-check"></i> Mark Complete
                                                <?php endif; ?>
                                            </a>
                                            <a href="delete_subtask.php?id=<?php echo $subtask['id']; ?>&task_id=<?php echo $task_id; ?>" class="btn btn-sm btn-danger page-link" onclick="return confirm('Are you sure you want to delete this subtask?');">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No subtasks found for this task.</p>
                            <?php endif; ?>
                        </div>
                        
                        <h4>Add New Subtasks:</h4>
                        <p class="helper-text">Enter one subtask per line. These will be added as new checklist items.</p>
                        <div class="form-group">
                            <textarea name="new_subtasks" id="new_subtasks" rows="5" placeholder="Example:
Design login page
Implement authentication
Create database models"></textarea>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="<?php echo $_SESSION['role'] === 'admin' ? 'manage-tasks.php' : 'my-tasks.php'; ?>" class="btn page-link">
                            <i class="fas fa-arrow-left"></i> Back to Tasks
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
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