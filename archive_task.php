<?php
session_start();
require 'config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    if (isset($_POST['redirect_to'])) {
        header("Location: " . $_POST['redirect_to'] . "?error=unauthorized");
        exit;
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Check if request is POST and contains task_id
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if this is a regular form submission or AJAX
$is_form_request = isset($_POST['task_id']);
$redirect_to = isset($_POST['redirect_to']) ? $_POST['redirect_to'] : null;

if ($is_form_request) {
    // Regular form submission
    if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
        if ($redirect_to) {
            header("Location: $redirect_to?error=invalid_task_id");
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
            exit;
        }
    }
    $task_id = (int)$_POST['task_id'];
} else {
    // AJAX request - get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['task_id']) || !is_numeric($input['task_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
        exit;
    }
    $task_id = (int)$input['task_id'];
}

try {
    // First, verify the task exists and is completed
    $stmt = $pdo->prepare("SELECT status FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        if ($is_form_request && $redirect_to) {
            header("Location: $redirect_to?error=task_not_found");
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit;
        }
    }
    
    if ($task['status'] !== 'done') {
        if ($is_form_request && $redirect_to) {
            header("Location: $redirect_to?error=task_not_completed");
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Task is not completed']);
            exit;
        }
    }
    
    // Archive the task
    $stmt = $pdo->prepare("UPDATE tasks SET archived = 1, archived_at = NOW() WHERE id = ?");
    $stmt->execute([$task_id]);
    
    // Log the archiving action
    $stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, task_id, action_type, details) 
        VALUES (?, ?, 'task_archived', 'Task archived from dashboard')
    ");
    $stmt->execute([$_SESSION['user_id'], $task_id]);
    
    if ($is_form_request && $redirect_to) {
        header("Location: $redirect_to?success=task_archived");
        exit;
    } else {
        echo json_encode(['success' => true, 'message' => 'Task archived successfully']);
    }
    
} catch (PDOException $e) {
    if ($is_form_request && $redirect_to) {
        header("Location: $redirect_to?error=database_error");
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?> 