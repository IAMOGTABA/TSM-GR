<?php
session_start();
require 'config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['subtask_id']) || !isset($_POST['task_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$subtask_id = (int) $_POST['subtask_id'];
$task_id = (int) $_POST['task_id'];
$new_status = isset($_POST['status']) ? $_POST['status'] : '';

// Validate status
if (!in_array($new_status, ['pending', 'done'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    // Check if user has permission to update this subtask (task must be assigned to them or they must be admin)
    if ($_SESSION['role'] !== 'admin') {
        $check_stmt = $pdo->prepare("SELECT assigned_to FROM tasks WHERE id = :task_id");
        $check_stmt->execute(['task_id' => $task_id]);
        $task = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$task || $task['assigned_to'] != $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
    }
    
    // Get subtask and task info for logging
    $info_stmt = $pdo->prepare("
        SELECT s.title as subtask_title, s.status as old_status, t.title as task_title 
        FROM subtasks s 
        JOIN tasks t ON s.task_id = t.id 
        WHERE s.id = :subtask_id
    ");
    $info_stmt->execute(['subtask_id' => $subtask_id]);
    $info = $info_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update subtask status
    $update_stmt = $pdo->prepare("UPDATE subtasks SET status = :status WHERE id = :id");
    $update_stmt->execute([
        'status' => $new_status,
        'id' => $subtask_id
    ]);
    
    // Log the subtask activity
    if ($info && $info['old_status'] !== $new_status) {
        $activity_type = ($new_status === 'done') ? 'subtask_completed' : 'status_change';
        $details = "Subtask '{$info['subtask_title']}' in task '{$info['task_title']}' status changed from '{$info['old_status']}' to '$new_status'";
        
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, task_id, action_type, old_status, new_status, details) 
            VALUES (:user_id, :task_id, :action_type, :old_status, :new_status, :details)
        ");
        
        try {
            $log_stmt->execute([
                'user_id' => $_SESSION['user_id'],
                'task_id' => $task_id,
                'action_type' => $activity_type,
                'old_status' => $info['old_status'],
                'new_status' => $new_status,
                'details' => $details
            ]);
        } catch (PDOException $e) {
            // Continue if logging fails
            error_log("Subtask activity logging failed: " . $e->getMessage());
        }
    }
    
    // Get updated subtask counts and calculate progress
    $count_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed 
        FROM subtasks 
        WHERE task_id = :task_id
    ");
    $count_stmt->execute(['task_id' => $task_id]);
    $counts = $count_stmt->fetch(PDO::FETCH_ASSOC);
    
    $total = $counts['total'];
    $completed = $counts['completed'];
    $progress_percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
    
    // Check if we need to update parent task status
    $task_status_changed = false;
    $new_task_status = '';
    
    if ($total > 0 && $completed == $total) {
        // All subtasks completed, mark task as done
        $task_update = $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = :task_id AND status != 'done'");
        $result = $task_update->execute(['task_id' => $task_id]);
        if ($task_update->rowCount() > 0) {
            $task_status_changed = true;
            $new_task_status = 'done';
        }
    } elseif ($new_status === 'pending') {
        // If a subtask was unmarked and task was completed, revert to in_progress
        $check_task = $pdo->prepare("SELECT status FROM tasks WHERE id = :task_id");
        $check_task->execute(['task_id' => $task_id]);
        $current_task_status = $check_task->fetchColumn();
        
        if ($current_task_status === 'done') {
            $task_update = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = :task_id");
            $task_update->execute(['task_id' => $task_id]);
            $task_status_changed = true;
            $new_task_status = 'in_progress';
        }
    }
    
    // Return success response with updated data
    echo json_encode([
        'success' => true,
        'progress_percentage' => $progress_percentage,
        'completed_count' => $completed,
        'total_count' => $total,
        'task_status_changed' => $task_status_changed,
        'new_task_status' => $new_task_status
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?> 