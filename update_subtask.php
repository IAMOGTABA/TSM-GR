<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Handle form submission for editing subtask
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subtask'])) {
    $subtask_id = $_POST['subtask_id'];
    $task_id = $_POST['task_id'];
    $title = trim($_POST['title']);
    
    if (empty($title)) {
        $_SESSION['error_message'] = "Subtask title cannot be empty";
        header("Location: view_task.php?task_id=$task_id");
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE subtasks SET title = ? WHERE id = ?");
        $stmt->execute([$title, $subtask_id]);
        
        $_SESSION['success_message'] = "Subtask updated successfully";
        header("Location: view_task.php?task_id=$task_id");
        exit;
    } catch (PDOException $e) {
        error_log("Error updating subtask: " . $e->getMessage());
        $_SESSION['error_message'] = "An error occurred while updating the subtask";
        header("Location: view_task.php?task_id=$task_id");
        exit;
    }
}

// Check if subtask ID and task ID are provided for toggling status
if (isset($_GET['id']) && isset($_GET['task_id']) && isset($_GET['completed'])) {
    $subtask_id = $_GET['id'];
    $task_id = $_GET['task_id'];
    $completed = $_GET['completed'];

    try {
        // Update subtask completion status
        $stmt = $pdo->prepare("UPDATE subtasks SET status = ? WHERE id = ?");
        $stmt->execute([$completed == 1 ? 'done' : 'pending', $subtask_id]);
        
        // Get all subtasks for this task to check if all are completed
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed FROM subtasks WHERE task_id = ?");
        $stmt->execute([$task_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate progress percentage
        $total = $result['total'];
        $completed_count = $result['completed'];
        $progress = ($total > 0) ? ($completed_count / $total) * 100 : 0;
        
        // Update task status if all subtasks are completed
        if ($total > 0 && $completed_count == $total) {
            $stmt = $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ?");
            $stmt->execute([$task_id]);
        } 
        // If any subtask is marked as incomplete and task was completed, change to in_progress
        elseif ($completed == 0) {
            $stmt = $pdo->prepare("SELECT status FROM tasks WHERE id = ?");
            $stmt->execute([$task_id]);
            $task_status = $stmt->fetchColumn();
            
            if ($task_status == 'done') {
                $stmt = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ?");
                $stmt->execute([$task_id]);
            }
        }
        
        // Redirect back to the task view
        header("Location: view_task.php?task_id=$task_id");
        exit;
    } catch (PDOException $e) {
        error_log("Error updating subtask: " . $e->getMessage());
        $_SESSION['error_message'] = "An error occurred while updating the subtask";
        header("Location: view_task.php?task_id=$task_id");
        exit;
    }
}

// Display subtask editing form
if (isset($_GET['id']) && isset($_GET['task_id'])) {
    $subtask_id = $_GET['id'];
    $task_id = $_GET['task_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM subtasks WHERE id = ?");
        $stmt->execute([$subtask_id]);
        $subtask = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subtask) {
            $_SESSION['error_message'] = "Subtask not found";
            header("Location: view_task.php?task_id=$task_id");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Error fetching subtask: " . $e->getMessage());
        $_SESSION['error_message'] = "An error occurred while fetching the subtask";
        header("Location: view_task.php?task_id=$task_id");
        exit;
    }
} else {
    header("Location: manage-tasks.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Subtask</title>
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
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }
        
        .card {
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 600px;
        }
        
        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            display: flex;
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
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 0.75rem;
            border-radius: 0.35rem;
            border: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            color: var(--text-main);
            font-size: 1rem;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(106, 13, 173, 0.25);
        }
        
        .buttons {
            display: flex;
            justify-content: space-between;
        }
        
        .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 0.35rem;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--bg-secondary);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background-color: var(--light);
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Edit Subtask</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="update_subtask.php">
                <input type="hidden" name="subtask_id" value="<?php echo $subtask['id']; ?>">
                <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                
                <div class="form-group">
                    <label for="title">Subtask Title</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($subtask['title']); ?>" required>
                </div>
                
                <div class="buttons">
                    <a href="view_task.php?task_id=<?php echo $task_id; ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" name="update_subtask" class="btn btn-primary">Update Subtask</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
