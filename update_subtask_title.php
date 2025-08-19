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
$title = trim($_POST['title'] ?? '');

if (!$subtask_id || empty($title)) {
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
        WHERE s.id = ? AND tat.team_admin_id = ?
    ");
    $verify_stmt->execute([$subtask_id, $_SESSION['user_id']]);
    
    if (!$verify_stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Access denied to this subtask']);
        exit;
    }
    
    // Update subtask title
    $update_stmt = $pdo->prepare("UPDATE subtasks SET title = ? WHERE id = ?");
    $result = $update_stmt->execute([$title, $subtask_id]);
    
    if ($result) {
        // Get task ID for logging
        $task_stmt = $pdo->prepare("SELECT task_id FROM subtasks WHERE id = ?");
        $task_stmt->execute([$subtask_id]);
        $task_id = $task_stmt->fetchColumn();
        
        // Log activity
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action_type, details, task_id) 
            VALUES (?, 'subtask_title_updated', ?, ?)
        ");
        $log_stmt->execute([
            $_SESSION['user_id'], 
            "Team Admin updated subtask title to: {$title}",
            $task_id
        ]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update subtask title']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
