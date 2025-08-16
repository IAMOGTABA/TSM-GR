<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['task_id'])) {
    die("Missing required parameters.");
}

$task_id = (int) $_POST['task_id'];
$redirect_to = isset($_POST['redirect_to']) ? $_POST['redirect_to'] : 'my-tasks.php';

// Get the current user ID and role
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Check if the user is authorized to delete this task
// Only admin users can delete completed tasks
if ($user_role !== 'admin') {
    die("Access denied. Only administrators can delete completed tasks.");
}

try {
    // Start transaction for data integrity
    $pdo->beginTransaction();
    
    // First, delete all subtasks associated with this task
    $delete_subtasks = $pdo->prepare("DELETE FROM subtasks WHERE task_id = :task_id");
    $delete_subtasks->execute(['task_id' => $task_id]);
    
    // Delete any messages related to this task
    $delete_messages = $pdo->prepare("DELETE FROM messages WHERE task_id = :task_id");
    $delete_messages->execute(['task_id' => $task_id]);
    
    // Finally, delete the task itself
    $delete_task = $pdo->prepare("DELETE FROM tasks WHERE id = :task_id");
    $result = $delete_task->execute(['task_id' => $task_id]);
    
    if ($result) {
        // Commit the transaction
        $pdo->commit();
        $_SESSION['success_message'] = "Task deleted successfully!";
    } else {
        // Rollback on failure
        $pdo->rollback();
        $_SESSION['error_message'] = "Failed to delete task.";
    }
} catch (PDOException $e) {
    // Rollback on error
    $pdo->rollback();
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

// Redirect back to the referring page
header("Location: $redirect_to");
exit;
?> 