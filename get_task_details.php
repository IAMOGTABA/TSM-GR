<?php
session_start();
require 'config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'team_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
    exit;
}

$task_id = (int) $_POST['task_id'];

try {
    // Build query based on user role
    if ($_SESSION['role'] === 'admin') {
        // Admin can see all tasks
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                u.full_name AS assigned_user,
                creator.full_name AS created_by_name,
                (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id) AS total_subtasks,
                (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND status = 'done') AS completed_subtasks
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN users creator ON t.created_by = creator.id
            WHERE t.id = :task_id
        ");
        $stmt->execute(['task_id' => $task_id]);
    } else {
        // Team admin can only see tasks assigned to their team members
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                u.full_name AS assigned_user,
                creator.full_name AS created_by_name,
                (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id) AS total_subtasks,
                (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND status = 'done') AS completed_subtasks
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN users creator ON t.created_by = creator.id
            JOIN team_admin_teams tat ON u.team_id = tat.team_id
            WHERE t.id = :task_id 
            AND tat.team_admin_id = :team_admin_id
            AND u.role = 'employee'
        ");
        $stmt->execute(['task_id' => $task_id, 'team_admin_id' => $_SESSION['user_id']]);
    }
    
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }
    
    // Get subtasks for this task
    $subtasks_stmt = $pdo->prepare("
        SELECT id, title, status
        FROM subtasks 
        WHERE task_id = :task_id
        ORDER BY id ASC
    ");
    $subtasks_stmt->execute(['task_id' => $task_id]);
    $subtasks = $subtasks_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add subtasks to task data
    $task['subtasks'] = $subtasks;
    
    // Return success response with task details
    echo json_encode([
        'success' => true,
        'task' => $task
    ]);
    
} catch (PDOException $e) {
    error_log("Task Details Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
