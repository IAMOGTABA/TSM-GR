<?php
session_start();
require 'config.php';

// Check if this is an AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$is_ajax = $is_ajax || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['task_id']) || !isset($_POST['new_status'])) {
    die("Missing required parameters.");
}

$task_id = (int) $_POST['task_id'];
$new_status_input = $_POST['new_status'];
// Normalize and map common aliases to match DB enum
$normalized = strtolower(trim($new_status_input));
$alias_map = [
    'pending' => 'to_do',
    'todo' => 'to_do',
    'to do' => 'to_do',
    'done' => 'completed',
    'complete' => 'completed',
    'completed' => 'completed',
    'marking all done' => 'completed'
];
$new_status = isset($alias_map[$normalized]) ? $alias_map[$normalized] : $normalized;
$redirect_to = isset($_POST['redirect_to']) ? $_POST['redirect_to'] : 'my-tasks.php';

// Validate the status
    // Valid statuses must match tasks.status enum
    $valid_statuses = ['to_do', 'in_progress', 'completed', 'needs_approval'];
if (!in_array($new_status, $valid_statuses)) {
    die("Invalid status provided.");
}

// Get the current user ID
$user_id = $_SESSION['user_id'];

// Check if the user is authorized to update this task
// For admin, they can update any task
// For employee, they can only update tasks assigned to them
if ($_SESSION['role'] === 'employee') {
    $check_stmt = $pdo->prepare("SELECT assigned_to FROM tasks WHERE id = :task_id");
    $check_stmt->execute(['task_id' => $task_id]);
    $task = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task || $task['assigned_to'] != $user_id) {
        die("You are not authorized to update this task.");
    }
}

try {
    // First, get the current task status for logging
    $current_task_stmt = $pdo->prepare("SELECT status, title FROM tasks WHERE id = :task_id");
    $current_task_stmt->execute(['task_id' => $task_id]);
    $current_task = $current_task_stmt->fetch(PDO::FETCH_ASSOC);
    $old_status = $current_task['status'];
    $task_title = $current_task['title'];
    
    // Keep the status as requested (no conversion for now)
    $actual_status = $new_status;
    
    // Update the task status
    $stmt = $pdo->prepare("UPDATE tasks SET status = :status WHERE id = :task_id");
    
    $result = $stmt->execute([
        'status' => $actual_status,
        'task_id' => $task_id
    ]);
    
    if ($result) {
        // Log the activity
        $activity_type = ($actual_status === 'completed') ? 'task_completed' : 'status_change';
        $details = "Task '$task_title' status changed from '$old_status' to '$actual_status'";
        
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, task_id, action_type, old_status, new_status, details) 
            VALUES (:user_id, :task_id, :action_type, :old_status, :new_status, :details)
        ");
        
        try {
            $log_stmt->execute([
                'user_id' => $user_id,
                'task_id' => $task_id,
                'action_type' => $activity_type,
                'old_status' => $old_status,
                'new_status' => $actual_status,
                'details' => $details
            ]);
        } catch (PDOException $e) {
            // If activity logging fails, continue but note the error
            error_log("Activity logging failed: " . $e->getMessage());
        }
        
        // If the status is set to completed, also check/update subtasks
        if ($actual_status === 'completed') {
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
        
        // Handle response based on request type
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Task status updated successfully']);
            exit;
        } else {
            $_SESSION['success_message'] = "Task status updated successfully!";
        }
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to update task status']);
            exit;
        } else {
            $_SESSION['error_message'] = "Failed to update task status.";
        }
    }
} catch (PDOException $e) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    } else {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    }
}

// Redirect back to the referring page (only for non-AJAX requests)
if (!$is_ajax) {
    header("Location: $redirect_to");
    exit;
}
?> 