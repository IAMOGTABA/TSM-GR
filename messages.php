<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$error = '';
$success = '';

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $recipient_id = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
    $send_to_all = isset($_POST['send_to_all']) && $_POST['send_to_all'] === 'yes';
    $task_id = isset($_POST['task_id']) && !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
    $parent_message_id = isset($_POST['parent_message_id']) && !empty($_POST['parent_message_id']) ? (int)$_POST['parent_message_id'] : null;

    // Validation
    if (empty($subject)) {
        $error = "Subject is required";
    } elseif (empty($message)) {
        $error = "Message content is required";
    } elseif (!$send_to_all && $recipient_id === 0) {
        $error = "Please select a recipient or choose to send to all users";
    } else {
        try {
            $pdo->beginTransaction();
            
            if ($send_to_all && $role === 'admin') {
                // Admin sending to all users
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id != ?");
                $stmt->execute([$user_id]);
                $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($recipients as $recipient) {
                    $insert = $pdo->prepare("
                        INSERT INTO messages (sender_id, recipient_id, subject, message, task_id, parent_message_id, sent_at, read_status)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'unread')
                    ");
                    $insert->execute([$user_id, $recipient, $subject, $message, $task_id, $parent_message_id]);
                }
                
                $success = "Message sent to all users successfully!";
            } else {
                // Single recipient
                $insert = $pdo->prepare("
                    INSERT INTO messages (sender_id, recipient_id, subject, message, task_id, parent_message_id, sent_at, read_status)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 'unread')
                ");
                $insert->execute([$user_id, $recipient_id, $subject, $message, $task_id, $parent_message_id]);
                $success = "Message sent successfully!";
            }
            
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error sending message: " . $e->getMessage();
        }
    }
}

// Mark message as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $message_id = (int)$_GET['mark_read'];
    
    try {
        $stmt = $pdo->prepare("UPDATE messages SET read_status = 'read' WHERE id = ? AND recipient_id = ?");
        $stmt->execute([$message_id, $user_id]);
    } catch (PDOException $e) {
        $error = "Error marking message as read: " . $e->getMessage();
    }
}

// Delete message
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $message_id = (int)$_GET['delete'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
        $stmt->execute([$message_id, $user_id, $user_id]);
        $success = "Message deleted successfully!";
    } catch (PDOException $e) {
        $error = "Error deleting message: " . $e->getMessage();
    }
}

// Get all users for recipient dropdown
$stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id != ? ORDER BY full_name");
$stmt->execute([$user_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tasks for dropdown based on user role (exclude done and archived tasks)
if ($role === 'admin') {
    // Admin can see all active tasks (not done or archived)
    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.status, t.priority, u.full_name as assigned_user 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.status != 'done' AND (t.archived IS NULL OR t.archived = 0)
        ORDER BY t.created_at DESC
    ");
    $stmt->execute();
} else {
    // Employee can only see their assigned active tasks (not done or archived)
    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.status, t.priority, u.full_name as assigned_user 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.assigned_to = ? AND t.status != 'done' AND (t.archived IS NULL OR t.archived = 0)
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$user_id]);
}
$available_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get inbox messages (messages sent to the current user)
$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as sender_name, t.title as task_title, t.status as task_status, t.priority as task_priority
    FROM messages m 
    JOIN users u ON m.sender_id = u.id 
    LEFT JOIN tasks t ON m.task_id = t.id
    WHERE m.recipient_id = ? 
    ORDER BY m.sent_at DESC
");
$stmt->execute([$user_id]);
$inbox_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get sent messages (messages sent by the current user)
$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as recipient_name, t.title as task_title, t.status as task_status, t.priority as task_priority
    FROM messages m 
    JOIN users u ON m.recipient_id = u.id 
    LEFT JOIN tasks t ON m.task_id = t.id
    WHERE m.sender_id = ? 
    ORDER BY m.sent_at DESC
");
$stmt->execute([$user_id]);
$sent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND read_status = 'unread'");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
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
        textarea:focus, 
        select:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        input[type="checkbox"] {
            width: 1.25rem;
            height: 1.25rem;
            accent-color: var(--primary);
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
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #d93426;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
        }
        
        .message-tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab {
            padding: 0.75rem 1.25rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-bottom: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .tab.active {
            border-bottom: 3px solid var(--primary);
            color: var(--primary-light);
        }
        
        .tab.active::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: var(--primary);
            transform: translateX(-100%);
            animation: slide-in 0.4s forwards;
        }
        
        @keyframes slide-in {
            to {
                transform: translateX(0);
            }
        }
        
        .tab:hover {
            background-color: rgba(255, 255, 255, 0.05);
            transform: translateY(-2px);
        }
        
        .tab-content {
            display: none;
            opacity: 0;
            transform: translateY(10px);
            transition: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fade-in 0.5s forwards;
        }
        
        @keyframes fade-in {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message-list {
            list-style: none;
        }
        
        .message-item {
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.35rem;
            background-color: var(--bg-secondary);
            border-left: 4px solid var(--primary);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: left;
            animation: message-appear 0.4s backwards;
        }
        
        @keyframes message-appear {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .message-item:hover {
            transform: translateX(5px) scale(1.01);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .message-item.unread {
            border-left-color: var(--warning);
            position: relative;
        }
        
        .message-item.unread:before {
            content: '';
            display: block;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background-color: var(--warning);
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .message-sender {
            font-weight: 600;
            color: var(--primary-light);
        }
        
        .message-time {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        
        .message-subject {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .message-content {
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .message-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .modal {
            width: 90%;
            max-width: 600px;
            background-color: var(--bg-card);
            border-radius: 0.35rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5);
            overflow: hidden;
            transform: translateY(50px);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            color: var(--primary-light);
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: var(--text-main);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-content {
            white-space: pre-wrap;
        }
        
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .badge-warning {
            background-color: rgba(246, 194, 62, 0.1);
            color: var(--warning);
        }
        
        /* Task-related styles */
        .message-task-info {
            background-color: var(--bg-secondary);
            padding: 0.5rem;
            border-radius: 0.35rem;
            margin: 0.5rem 0;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .message-task-info i {
            color: var(--primary);
        }
        
        .task-label {
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .task-title {
            font-weight: 600;
            color: var(--text-main);
        }
        
        .task-status {
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .task-status.status-pending {
            background-color: rgba(231, 74, 59, 0.2);
            color: var(--danger);
        }
        
        .task-status.status-in_progress {
            background-color: rgba(54, 185, 204, 0.2);
            color: var(--info);
        }
        
        .task-status.status-done {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
        }
        
        .task-priority {
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .task-priority.priority-high {
            background-color: rgba(231, 74, 59, 0.2);
            color: var(--danger);
        }
        
        .task-priority.priority-medium {
            background-color: rgba(246, 194, 62, 0.2);
            color: var(--warning);
        }
        
        .task-priority.priority-low {
            background-color: rgba(28, 200, 138, 0.2);
            color: var(--success);
        }
        
        .form-help {
            display: block;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
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
            
            .message-header {
                flex-direction: column;
                gap: 0.5rem;
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
                <li><a href="analysis.php" class="page-link"><i class="fas fa-chart-line"></i> Analysis</a></li>
                <li><a href="messages.php" class="active page-link"><i class="fas fa-envelope"></i> Messages 
                    <?php if ($unread_count > 0): ?>
                        <span class="badge badge-warning"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a></li>
            <?php else: ?>
                <li><a href="employee-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="my-tasks.php" class="page-link"><i class="fas fa-clipboard-list"></i> My Tasks</a></li>
                <li><a href="messages.php" class="active page-link"><i class="fas fa-envelope"></i> Messages 
                    <?php if ($unread_count > 0): ?>
                        <span class="badge badge-warning"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <div class="content">
        <div class="header">
            <h1 class="page-title">Messages</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="message-tabs">
            <div class="tab" data-tab="compose"><i class="fas fa-pen"></i> Compose</div>
            <div class="tab active" data-tab="inbox"><i class="fas fa-inbox"></i> Inbox 
                <?php if ($unread_count > 0): ?>
                    <span class="badge badge-warning"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </div>
            <div class="tab" data-tab="sent"><i class="fas fa-paper-plane"></i> Sent</div>
        </div>
        
        <!-- Compose Tab -->
        <div class="tab-content" id="compose">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-pen"></i> New Message</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="recipient"><i class="fas fa-user"></i> Recipient</label>
                            <select name="recipient_id" id="recipient" <?php echo $role === 'admin' ? '' : 'required'; ?>>
                                <option value="">-- Select Recipient --</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']) . ' (' . ucfirst($user['role']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <div class="form-group checkbox-group">
                            <input type="checkbox" name="send_to_all" id="send_to_all" value="yes">
                            <label for="send_to_all">Send to all users</label>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="task_id"><i class="fas fa-tasks"></i> Related Task (Optional)</label>
                            <select name="task_id" id="task_id">
                                <option value="">-- No Task Selected --</option>
                                <?php foreach ($available_tasks as $task): ?>
                                    <option value="<?php echo $task['id']; ?>">
                                        <?php 
                                        echo htmlspecialchars($task['title']);
                                        if ($role === 'admin' && $task['assigned_user']) {
                                            echo ' (Assigned to: ' . htmlspecialchars($task['assigned_user']) . ')';
                                        }
                                        echo ' - ' . ucfirst($task['status']);
                                        echo ' [' . ucfirst($task['priority']) . ']';
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">Select a task if this message is related to a specific task</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="subject"><i class="fas fa-heading"></i> Subject</label>
                            <input type="text" name="subject" id="subject" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="message"><i class="fas fa-comment-alt"></i> Message</label>
                            <textarea name="message" id="message" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="send_message" class="btn btn-success">
                                <i class="fas fa-paper-plane"></i> Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Inbox Tab -->
        <div class="tab-content active" id="inbox">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-inbox"></i> Inbox</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($inbox_messages)): ?>
                        <p>Your inbox is empty.</p>
                    <?php else: ?>
                        <ul class="message-list">
                            <?php foreach ($inbox_messages as $message): ?>
                                <li class="message-item <?php echo $message['read_status'] === 'unread' ? 'unread' : ''; ?>" data-id="<?php echo $message['id']; ?>">
                                    <div class="message-header">
                                        <div>
                                            <span class="message-sender">From: <?php echo htmlspecialchars($message['sender_name']); ?></span>
                                        </div>
                                        <span class="message-time"><?php echo date('M d, Y h:i A', strtotime($message['sent_at'])); ?></span>
                                    </div>
                                    <div class="message-subject"><?php echo htmlspecialchars($message['subject']); ?></div>
                                    <?php if (!empty($message['task_title'])): ?>
                                    <div class="message-task-info">
                                        <i class="fas fa-tasks"></i>
                                        <span class="task-label">Related Task:</span>
                                        <span class="task-title"><?php echo htmlspecialchars($message['task_title']); ?></span>
                                        <span class="task-status status-<?php echo $message['task_status']; ?>">
                                            <?php echo ucfirst($message['task_status']); ?>
                                        </span>
                                        <span class="task-priority priority-<?php echo $message['task_priority']; ?>">
                                            <?php echo ucfirst($message['task_priority']); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="message-content"><?php echo htmlspecialchars($message['message']); ?></div>
                                    <div class="message-actions">
                                        <button class="btn btn-sm view-message" data-id="<?php echo $message['id']; ?>" 
                                                data-sender="<?php echo htmlspecialchars($message['sender_name']); ?>"
                                                data-subject="<?php echo htmlspecialchars($message['subject']); ?>"
                                                data-date="<?php echo date('M d, Y h:i A', strtotime($message['sent_at'])); ?>"
                                                data-content="<?php echo htmlspecialchars($message['message']); ?>"
                                                data-task-id="<?php echo $message['task_id'] ?? ''; ?>"
                                                data-task-title="<?php echo htmlspecialchars($message['task_title'] ?? ''); ?>"
                                                data-task-status="<?php echo $message['task_status'] ?? ''; ?>">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if (!empty($message['task_id'])): ?>
                                        <a href="view_task.php?task_id=<?php echo $message['task_id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-tasks"></i> View Task
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($message['read_status'] === 'unread'): ?>
                                            <a href="messages.php?mark_read=<?php echo $message['id']; ?>" class="btn btn-sm">
                                                <i class="fas fa-check"></i> Mark as Read
                                            </a>
                                        <?php endif; ?>
                                        <a href="messages.php?delete=<?php echo $message['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this message?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Sent Tab -->
        <div class="tab-content" id="sent">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-paper-plane"></i> Sent Messages</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($sent_messages)): ?>
                        <p>You haven't sent any messages yet.</p>
                    <?php else: ?>
                        <ul class="message-list">
                            <?php foreach ($sent_messages as $message): ?>
                                <li class="message-item" data-id="<?php echo $message['id']; ?>">
                                    <div class="message-header">
                                        <div>
                                            <span class="message-sender">To: <?php echo htmlspecialchars($message['recipient_name']); ?></span>
                                        </div>
                                        <span class="message-time"><?php echo date('M d, Y h:i A', strtotime($message['sent_at'])); ?></span>
                                    </div>
                                    <div class="message-subject"><?php echo htmlspecialchars($message['subject']); ?></div>
                                    <?php if (!empty($message['task_title'])): ?>
                                    <div class="message-task-info">
                                        <i class="fas fa-tasks"></i>
                                        <span class="task-label">Related Task:</span>
                                        <span class="task-title"><?php echo htmlspecialchars($message['task_title']); ?></span>
                                        <span class="task-status status-<?php echo $message['task_status']; ?>">
                                            <?php echo ucfirst($message['task_status']); ?>
                                        </span>
                                        <span class="task-priority priority-<?php echo $message['task_priority']; ?>">
                                            <?php echo ucfirst($message['task_priority']); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="message-content"><?php echo htmlspecialchars($message['message']); ?></div>
                                    <div class="message-actions">
                                        <button class="btn btn-sm view-message" data-id="<?php echo $message['id']; ?>"
                                                data-sender="To: <?php echo htmlspecialchars($message['recipient_name']); ?>"
                                                data-subject="<?php echo htmlspecialchars($message['subject']); ?>"
                                                data-date="<?php echo date('M d, Y h:i A', strtotime($message['sent_at'])); ?>"
                                                data-content="<?php echo htmlspecialchars($message['message']); ?>"
                                                data-task-id="<?php echo $message['task_id'] ?? ''; ?>"
                                                data-task-title="<?php echo htmlspecialchars($message['task_title'] ?? ''); ?>"
                                                data-task-status="<?php echo $message['task_status'] ?? ''; ?>">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if (!empty($message['task_id'])): ?>
                                        <a href="view_task.php?task_id=<?php echo $message['task_id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-tasks"></i> View Task
                                        </a>
                                        <?php endif; ?>
                                        <a href="messages.php?delete=<?php echo $message['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this message?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Message View Modal -->
        <div class="modal-backdrop" id="messageModal">
            <div class="modal">
                <div class="modal-header">
                    <h3 class="modal-title" id="modalTitle">Message</h3>
                    <button class="close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>From:</strong> <span id="modalSender"></span></p>
                    <p><strong>Date:</strong> <span id="modalDate"></span></p>
                    <p><strong>Subject:</strong> <span id="modalSubject"></span></p>
                    <div id="modalTaskInfo" style="display: none;">
                        <p><strong>Related Task:</strong> 
                            <span id="modalTaskTitle"></span>
                            <span id="modalTaskStatus" class="task-status"></span>
                        </p>
                    </div>
                    <hr style="border-color: var(--border-color); margin: 1rem 0;">
                    <div class="modal-content" id="modalContent"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn close-modal">Close</button>
                    <button class="btn btn-primary" id="replyBtn" style="display: none;">
                        <i class="fas fa-reply"></i> Reply
                    </button>
                    <a href="#" id="viewTaskBtn" class="btn btn-primary" style="display: none;">
                        <i class="fas fa-tasks"></i> View Task
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                    
                    // Apply appear animation to each message item
                    const messageItems = document.querySelectorAll(`#${tabId} .message-item`);
                    messageItems.forEach((item, index) => {
                        item.style.animationDelay = `${index * 0.05}s`;
                    });
                });
            });
            
            // Send to all checkbox
            const sendToAllCheckbox = document.getElementById('send_to_all');
            const recipientSelect = document.getElementById('recipient');
            
            if (sendToAllCheckbox) {
                sendToAllCheckbox.addEventListener('change', function() {
                    recipientSelect.disabled = this.checked;
                    if (this.checked) {
                        recipientSelect.removeAttribute('required');
                    } else {
                        recipientSelect.setAttribute('required', 'required');
                    }
                });
            }
            
            // View message modal
            const viewButtons = document.querySelectorAll('.view-message');
            const modal = document.getElementById('messageModal');
            const modalContent = document.querySelector('.modal');
            const closeButtons = document.querySelectorAll('.close-modal');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const messageId = this.getAttribute('data-id');
                    const sender = this.getAttribute('data-sender');
                    const subject = this.getAttribute('data-subject');
                    const date = this.getAttribute('data-date');
                    const content = this.getAttribute('data-content');
                    const taskId = this.getAttribute('data-task-id');
                    const taskTitle = this.getAttribute('data-task-title');
                    const taskStatus = this.getAttribute('data-task-status');
                    
                    document.getElementById('modalSender').textContent = sender;
                    document.getElementById('modalSubject').textContent = subject;
                    document.getElementById('modalDate').textContent = date;
                    document.getElementById('modalContent').textContent = content;
                    
                    // Handle task information
                    const taskInfo = document.getElementById('modalTaskInfo');
                    const viewTaskBtn = document.getElementById('viewTaskBtn');
                    const replyBtn = document.getElementById('replyBtn');
                    
                    if (taskId && taskTitle) {
                        document.getElementById('modalTaskTitle').textContent = taskTitle;
                        const taskStatusElement = document.getElementById('modalTaskStatus');
                        taskStatusElement.textContent = taskStatus.replace('_', ' ').toUpperCase();
                        taskStatusElement.className = 'task-status status-' + taskStatus;
                        taskInfo.style.display = 'block';
                        viewTaskBtn.href = 'view_task.php?task_id=' + taskId;
                        viewTaskBtn.style.display = 'inline-block';
                    } else {
                        taskInfo.style.display = 'none';
                        viewTaskBtn.style.display = 'none';
                    }
                    
                    // Show reply button for inbox messages
                    if (sender.startsWith('From:')) {
                        replyBtn.style.display = 'inline-block';
                        replyBtn.onclick = function() {
                            // Populate compose form with reply data
                            document.querySelector('[data-tab="compose"]').click();
                            const recipientName = sender.replace('From: ', '');
                            const recipientSelect = document.getElementById('recipient');
                            for (let option of recipientSelect.options) {
                                if (option.text.includes(recipientName)) {
                                    option.selected = true;
                                    break;
                                }
                            }
                            document.getElementById('subject').value = 'RE: ' + subject;
                            if (taskId) {
                                document.getElementById('task_id').value = taskId;
                            }
                            document.getElementById('message').value = '\n\n--- Original Message ---\n' + content;
                            modal.style.opacity = '0';
                            modalContent.style.transform = 'translateY(-20px)';
                            setTimeout(() => modal.style.display = 'none', 300);
                        };
                    } else {
                        replyBtn.style.display = 'none';
                    }
                    
                    modal.style.display = 'flex';
                    setTimeout(() => {
                        modal.style.opacity = '1';
                        modalContent.style.transform = 'translateY(0)';
                    }, 10);
                    
                    // Mark message as read if it was unread
                    const messageItem = document.querySelector(`.message-item[data-id="${messageId}"]`);
                    if (messageItem && messageItem.classList.contains('unread')) {
                        fetch(`messages.php?mark_read=${messageId}`)
                            .then(response => {
                                if (response.ok) {
                                    messageItem.classList.remove('unread');
                                }
                            });
                    }
                });
            });
            
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.style.opacity = '0';
                    modalContent.style.transform = 'translateY(50px)';
                    setTimeout(() => {
                        modal.style.display = 'none';
                    }, 300);
                });
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.opacity = '0';
                    modalContent.style.transform = 'translateY(50px)';
                    setTimeout(() => {
                        modal.style.display = 'none';
                    }, 300);
                }
            });
            

            
            // Apply appear animation to each message item on page load
            const activeTab = document.querySelector('.tab-content.active');
            if (activeTab) {
                const messageItems = activeTab.querySelectorAll('.message-item');
                messageItems.forEach((item, index) => {
                    item.style.animationDelay = `${index * 0.05}s`;
                });
            }
        });
    </script>
</body>
</html> 