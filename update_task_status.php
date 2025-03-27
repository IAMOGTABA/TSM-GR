<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['task_id']) || !isset($_POST['new_status'])) {
    die("Missing required parameters.");
}

$task_id = (int) $_POST['task_id'];
$new_status = $_POST['new_status'];
$redirect_to = isset($_POST['redirect_to']) ? $_POST['redirect_to'] : 'my-tasks.php';

// Validate the status
$valid_statuses = ['to_do', 'in_progress', 'done'];
if (!in_array($new_status, $valid_statuses)) {
    die("Invalid status provided.");
}

// Get the current user ID
$user_id = $_SESSION['user_id'];

// Check if the user is authorized to update this task
// For admin, they can update any task
// For employee, they can only update tasks assigned to them
if ($_SESSION['role'] !== 'admin') {
    $check_stmt = $pdo->prepare("SELECT assigned_to FROM tasks WHERE id = :task_id");
    $check_stmt->execute(['task_id' => $task_id]);
    $task = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task || $task['assigned_to'] != $user_id) {
        die("You are not authorized to update this task.");
    }
}

try {
    // Update the task status
    $stmt = $pdo->prepare("UPDATE tasks SET status = :status WHERE id = :task_id");
    $result = $stmt->execute([
        'status' => $new_status,
        'task_id' => $task_id
    ]);
    
    if ($result) {
        // If the status is set to done, also check/update subtasks
        if ($new_status === 'done') {
            // Check if this task has any subtasks
            $check_subtasks = $pdo->prepare("SELECT COUNT(*) FROM subtasks WHERE task_id = :task_id");
            $check_subtasks->execute(['task_id' => $task_id]);
            $has_subtasks = $check_subtasks->fetchColumn() > 0;
            
            if ($has_subtasks) {
                // Mark all subtasks as done
                $update_subtasks = $pdo->prepare("UPDATE subtasks SET status = 'done' WHERE task_id = :task_id");
                $update_subtasks->execute(['task_id' => $task_id]);
            }
        }
        
        // Add success message to session if needed
        $_SESSION['success_message'] = "Task status updated successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to update task status.";
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

// Redirect back to the referring page
header("Location: $redirect_to");
exit;
?> 