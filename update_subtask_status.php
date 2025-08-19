<?php
session_start();
require 'config.php';

// Check if user is logged in and is a team admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$subtask_id = $_POST['subtask_id'] ?? null;
$status = $_POST['status'] ?? null;
$task_id = $_POST['task_id'] ?? null;

if (!$subtask_id || !$status || !$task_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    // Verify this subtask belongs to a task assigned to team admin's team member
    $verify_stmt = $pdo->prepare("
        SELECT s.id FROM subtasks s
        JOIN tasks t ON s.task_id = t.id
        JOIN users u ON t.assigned_to = u.id
        JOIN team_admin_teams tat ON u.team_id = tat.team_id
        WHERE s.id = ? AND t.id = ? AND tat.team_admin_id = ?
    ");
    $verify_stmt->execute([$subtask_id, $task_id, $_SESSION['user_id']]);
    
    if (!$verify_stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Access denied to this subtask']);
        exit;
    }
    
    // Update subtask status
    $update_stmt = $pdo->prepare("UPDATE subtasks SET status = ? WHERE id = ?");
    $result = $update_stmt->execute([$status, $subtask_id]);
    
    if ($result) {
        // Log activity
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action_type, details, task_id) 
            VALUES (?, 'subtask_status_updated', ?, ?)
        ");
        $log_stmt->execute([
            $_SESSION['user_id'], 
            "Team Admin updated subtask status to {$status}",
            $task_id
        ]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update subtask']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
