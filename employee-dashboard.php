<?php
// employee-dashboard.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit;
}
require 'config.php';

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get comprehensive task statistics (excluding archived tasks)
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_tasks,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
    SUM(CASE WHEN status = 'to_do' THEN 1 ELSE 0 END) as pending_tasks,
    SUM(CASE WHEN deadline < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue_tasks,
    SUM(CASE WHEN deadline = CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as due_today,
    SUM(CASE WHEN deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status != 'completed' THEN 1 ELSE 0 END) as due_this_week,
    SUM(CASE WHEN priority = 'high' AND status != 'completed' THEN 1 ELSE 0 END) as high_priority_pending
FROM tasks WHERE assigned_to = :user_id AND archived = 0");
$stmt->execute(['user_id' => $user_id]);
$task_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate performance metrics
$completion_rate = $task_stats['total_tasks'] > 0 ? round(($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100, 1) : 0;
$efficiency_score = ($task_stats['completed_tasks'] * 100) - ($task_stats['overdue_tasks'] * 20);
$efficiency_score = max(0, min(100, $efficiency_score));

// Get task completion trend (last 7 days) - only count completed but not yet archived tasks
$completion_trend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = :user_id AND DATE(created_at) = :date AND status = 'completed' AND archived = 0");
    $stmt->execute(['user_id' => $user_id, 'date' => $date]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $completion_trend[] = [
        'date' => date('M j', strtotime($date)),
        'count' => $count
    ];
}

// Get priority distribution of active tasks (excluding archived)
$stmt = $pdo->prepare("
    SELECT priority, COUNT(*) as count 
    FROM tasks 
    WHERE assigned_to = :user_id AND archived = 0
    GROUP BY priority
");
$stmt->execute(['user_id' => $user_id]);
$priority_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active tasks for the user (excluding archived)
$stmt = $pdo->prepare("SELECT id, title, deadline, status, priority, 
    CASE 
        WHEN deadline < CURDATE() THEN 'Overdue'
        WHEN deadline = CURDATE() THEN 'Due Today'
        WHEN deadline = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'Due Tomorrow'
        WHEN deadline <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN CONCAT('Due ', DATE_FORMAT(deadline, '%M %d'))
        ELSE CONCAT('Due ', DATE_FORMAT(deadline, '%M %d, %Y'))
    END as due_display
    FROM tasks 
    WHERE assigned_to = :user_id 
    AND status != 'completed'
    AND archived = 0
    ORDER BY 
        CASE 
            WHEN deadline < CURDATE() THEN 1
            WHEN deadline = CURDATE() THEN 2
            WHEN status = 'in_progress' THEN 3
            ELSE 4
        END,
        deadline ASC, 
        priority DESC
    LIMIT 8");
$stmt->execute(['user_id' => $user_id]);
$my_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activities and messages combined - what the user RECEIVED
$recent_activities = [];

try {
    // Get tasks assigned TO this user (what they received) - excluding archived
    $task_stmt = $pdo->prepare("
        SELECT 'task_assigned' as type, t.title as task_title, t.id as task_id,
               u.full_name as assigned_by, t.created_at as activity_time,
               t.status, t.priority, t.deadline
        FROM tasks t
        JOIN users u ON t.created_by = u.id
        WHERE t.assigned_to = :user_id
        AND t.archived = 0
        AND DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $task_stmt->execute(['user_id' => $user_id]);
    $task_activities = $task_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get task status updates FOR this user (what they received) - excluding archived tasks
    $update_stmt = $pdo->prepare("
        SELECT 'task_updated' as type, t.title as task_title, t.id as task_id,
               u.full_name as updated_by, al.created_at as activity_time,
               al.old_status, al.new_status, al.details
        FROM activity_logs al
        JOIN tasks t ON al.task_id = t.id
        JOIN users u ON al.user_id = u.id
        WHERE t.assigned_to = :user_id
        AND t.archived = 0
        AND al.user_id != :user_id  -- Exclude updates done by the user themselves
        AND al.action_type IN ('status_update', 'task_updated', 'task_modified')
        AND DATE(al.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $update_stmt->execute(['user_id' => $user_id]);
    $update_activities = $update_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get messages sent TO this user (what they received)
    $message_stmt = $pdo->prepare("
        SELECT 'message' as type, m.subject as title, m.read_status as status, 
               m.sent_at as activity_time, u.full_name as sender_name, m.id as message_id
        FROM messages m 
        JOIN users u ON m.sender_id = u.id 
        WHERE m.recipient_id = :user_id
        AND DATE(m.sent_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY m.sent_at DESC
        LIMIT 10
    ");
    $message_stmt->execute(['user_id' => $user_id]);
    $message_activities = $message_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process task assignments (what user received)
    foreach ($task_activities as $task) {
        $recent_activities[] = [
            'type' => 'task_assigned',
            'user' => $task['assigned_by'],
            'task' => $task['task_title'],
            'details' => 'New task assigned to you',
            'status' => $task['status'],
            'priority' => $task['priority'],
            'deadline' => $task['deadline'],
            'activity_time' => $task['activity_time'],
            'task_id' => $task['task_id']
        ];
    }

    // Process task updates (what user received)
    foreach ($update_activities as $update) {
        $status_text = '';
        if ($update['old_status'] && $update['new_status']) {
            $status_text = "Status changed from {$update['old_status']} to {$update['new_status']}";
        } elseif ($update['details']) {
            $status_text = $update['details'];
        } else {
            $status_text = 'Task updated';
        }
        
        $recent_activities[] = [
            'type' => 'task_updated',
            'user' => $update['updated_by'],
            'task' => $update['task_title'],
            'details' => $status_text,
            'old_status' => $update['old_status'],
            'new_status' => $update['new_status'],
            'activity_time' => $update['activity_time'],
            'task_id' => $update['task_id']
        ];
    }

    // Process messages (what user received)
    foreach ($message_activities as $msg) {
        $recent_activities[] = [
            'type' => 'message',
            'user' => $msg['sender_name'],
            'task' => 'Message',
            'details' => $msg['title'],
            'status' => $msg['status'],
            'activity_time' => $msg['activity_time'],
            'message_id' => $msg['message_id']
        ];
    }

    // Sort all activities by time
    usort($recent_activities, function($a, $b) {
        return strtotime($b['activity_time']) - strtotime($a['activity_time']);
    });
    
    $recent_activities = array_slice($recent_activities, 0, 8);
} catch (PDOException $e) {
    // Fallback if there are issues
    error_log("Employee activity logs error: " . $e->getMessage());
    $recent_activities = [];
}

// Count unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND read_status = 'unread'");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();

// Get recent completed tasks for motivation
$stmt = $pdo->prepare("
    SELECT title, created_at, 
           DATEDIFF(CURDATE(), created_at) as days_to_complete
    FROM tasks 
    WHERE assigned_to = :user_id AND status = 'completed'
    ORDER BY created_at DESC
    LIMIT 3
");
$stmt->execute(['user_id' => $user_id]);
$recent_completions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate average completion time
$avg_completion_time = 0;
if (!empty($recent_completions)) {
    $total_days = array_sum(array_column($recent_completions, 'days_to_complete'));
    $avg_completion_time = round($total_days / count($recent_completions), 1);
}

// Get daily productivity score (based on tasks completed vs assigned today)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as today_completed,
        (SELECT COUNT(*) FROM tasks WHERE assigned_to = :user_id AND DATE(created_at) = CURDATE()) as today_assigned
    FROM tasks 
    WHERE assigned_to = :user_id AND DATE(created_at) = CURDATE() AND status = 'completed'
");
$stmt->execute(['user_id' => $user_id]);
$daily_stats = $stmt->fetch(PDO::FETCH_ASSOC);
$daily_productivity = $daily_stats['today_assigned'] > 0 ? 
    round(($daily_stats['today_completed'] / $daily_stats['today_assigned']) * 100) : 
    ($daily_stats['today_completed'] > 0 ? 100 : 0);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - TSM</title>
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
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 2rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
        }
        
        .logo-section {
            margin-bottom: 1.5rem;
        }
        
        .logo-section h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            letter-spacing: 2px;
        }
        
        .logo-section .tagline {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 0.25rem;
        }
        
        .user-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 1rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .user-avatar {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.8rem;
            color: white;
            font-weight: 900;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4), inset 0 2px 4px rgba(255, 255, 255, 0.2);
            border: 3px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .user-avatar::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg);
            animation: avatarShine 3s ease-in-out infinite;
        }
        
        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.6), inset 0 2px 4px rgba(255, 255, 255, 0.3);
        }
        
        @keyframes avatarShine {
            0%, 100% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            50% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        .user-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.5rem;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }
        
        .user-role {
            font-size: 0.8rem;
            color: #4ecdc4;
            background: rgba(78, 205, 196, 0.2);
            padding: 0.4rem 1rem;
            border-radius: 20px;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
            border: 1px solid rgba(78, 205, 196, 0.3);
        }
        
        .sidebar-heading {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05rem;
            padding: 1rem;
            color: rgba(255, 255, 255, 0.6);
            margin-top: 1rem;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-menu a {
            display: block;
            padding: 0.875rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(8px);
        }
        
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            font-weight: 600;
        }
        
        .sidebar-menu a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: white;
        }
        
        .sidebar-menu i {
            margin-right: 0.75rem;
            width: 1.5rem;
            text-align: center;
            font-size: 1rem;
        }
        
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: auto;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .badge-danger {
            background-color: var(--danger);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 1.5rem;
            overflow-y: auto;
            transition: all 0.3s ease;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title {
            color: var(--dark);
            font-size: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        

        
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-light));
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(106, 13, 173, 0.3);
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }
        
        .welcome-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-text h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: white;
        }
        
        .welcome-text p {
            font-size: 1.1rem;
            opacity: 0.9;
            color: white;
        }
        
        .welcome-stats {
            display: flex;
            gap: 2rem;
            color: white;
        }
        
        .welcome-stat {
            text-align: center;
        }
        
        .welcome-stat .stat-number {
            font-size: 2rem;
            font-weight: bold;
            display: block;
        }
        
        .welcome-stat .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .metric-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: linear-gradient(45deg, rgba(255,255,255,0.1), transparent);
            border-radius: 0 0 0 80px;
        }
        
        .metric-card.primary { border-left-color: var(--primary); }
        .metric-card.success { border-left-color: var(--success); }
        .metric-card.info { border-left-color: var(--info); }
        .metric-card.warning { border-left-color: var(--warning); }
        .metric-card.danger { border-left-color: var(--danger); }
        
        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .metric-title {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .metric-icon {
            font-size: 2rem;
            opacity: 0.3;
        }
        
        .metric-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }
        
        .metric-subtitle {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, var(--bg-secondary), var(--bg-card));
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            color: var(--primary-light);
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, var(--primary), var(--primary-light));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.4);
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .analytics-section {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .progress-ring {
            transform: rotate(-90deg);
        }
        
        .progress-ring-circle {
            transition: stroke-dashoffset 1s ease-in-out;
            fill: transparent;
            stroke-width: 8;
            r: 45;
            cx: 50;
            cy: 50;
        }
        
        .task-list {
            list-style: none;
        }
        
        .task-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .task-item:hover {
            background: var(--bg-secondary);
            transform: translateX(5px);
        }
        
        .task-priority {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 1rem;
        }
        
        .priority-high { background: var(--danger); }
        .priority-medium { background: var(--warning); }
        .priority-low { background: var(--success); }
        
        .task-content {
            flex: 1;
        }
        
        .task-title {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }
        
        .task-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.3s ease;
        }
        
        .activity-item:hover {
            background: var(--bg-secondary);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 0.9rem;
        }
        
        .activity-icon.task { background: rgba(106, 13, 173, 0.2); color: var(--primary); }
        .activity-icon.message { background: rgba(54, 185, 204, 0.2); color: var(--info); }
        
        /* Enhanced activity icon styles */
        .activity-item .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 0.9rem;
        }
        
        .activity-item .activity-icon i {
            font-size: 1rem;
        }
        
        /* Activity type specific colors */
        .activity-completed .activity-icon {
            background: rgba(28, 200, 138, 0.2);
            color: var(--success);
        }
        
        .activity-status-change .activity-icon {
            background: rgba(246, 194, 62, 0.2);
            color: var(--warning);
        }
        
        .activity-subtask .activity-icon {
            background: rgba(106, 13, 173, 0.2);
            color: var(--primary);
        }
        
        .activity-created .activity-icon {
            background: rgba(54, 185, 204, 0.2);
            color: var(--info);
        }
        
        .activity-message .activity-icon {
            background: rgba(54, 185, 204, 0.2);
            color: var(--info);
        }
        
        /* New activity type styles for received activities */
        .activity-task-assigned .activity-icon {
            background: rgba(54, 185, 204, 0.2);
            color: var(--info);
        }
        
        .activity-task-updated .activity-icon {
            background: rgba(246, 194, 62, 0.2);
            color: var(--warning);
        }
        
        .activity-task-assigned {
            border-left: 4px solid var(--info);
            background-color: rgba(54, 185, 204, 0.05);
        }
        
        .activity-task-updated {
            border-left: 4px solid var(--warning);
            background-color: rgba(246, 194, 62, 0.05);
        }
        
        .activity-message {
            border-left: 4px solid var(--primary);
            background-color: rgba(106, 13, 173, 0.05);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }
        
        .activity-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .unread-badge {
            background-color: var(--danger);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .analytics-section {
                grid-template-columns: 1fr;
            }
            
            .welcome-content {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
            }
            
            .welcome-stats {
                justify-content: center;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-light);
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-section">
                <h1>TSM</h1>
                <div class="tagline">Task Management</div>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <?php 
                    $name_parts = explode(' ', $user['full_name']);
                    $initials = strtoupper(substr($name_parts[0], 0, 1));
                    if (count($name_parts) > 1) {
                        $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
                    }
                    echo $initials;
                    ?>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></div>
            </div>
        </div>
        
        <div class="sidebar-heading">Navigation</div>
        <ul class="sidebar-menu">
            <li><a href="employee-dashboard.php" class="active page-link"><i class="fas fa-tachometer-alt"></i>Dashboard</a></li>
            <li><a href="my-tasks.php" class="page-link"><i class="fas fa-tasks"></i>My Tasks</a></li>
            <li><a href="messages.php" class="page-link">
                <i class="fas fa-envelope"></i>Messages
                <?php if ($unread_count > 0): ?>
                    <span class="badge badge-danger"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a></li>
        </ul>
        
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i>Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header">
            <h1 class="page-title">
                <i class="fas fa-chart-line"></i>
                Dashboard
            </h1>
            <div class="user-actions">
                <div class="user-avatar">
                    <?php 
                    $name_parts = explode(' ', $user['full_name']);
                    $initials = strtoupper(substr($name_parts[0], 0, 1));
                    if (count($name_parts) > 1) {
                        $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
                    }
                    echo $initials;
                    ?>
                </div>
            </div>
        </div>

        <div class="welcome-banner">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Welcome back, <?php echo explode(' ', $user['full_name'])[0]; ?>!</h2>
                    <p>Here's your productivity overview for today</p>
                </div>
                <div class="welcome-stats">
                    <div class="welcome-stat">
                        <span class="stat-number"><?php echo $daily_stats['today_completed']; ?></span>
                        <span class="stat-label">Tasks Completed Today</span>
                    </div>
                    <div class="welcome-stat">
                        <span class="stat-number"><?php echo $daily_productivity; ?>%</span>
                        <span class="stat-label">Daily Productivity</span>
                    </div>
                    <div class="welcome-stat">
                        <span class="stat-number"><?php echo $efficiency_score; ?></span>
                        <span class="stat-label">Efficiency Score</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="metric-card primary">
                <div class="metric-header">
                    <div class="metric-title">Total Tasks</div>
                    <i class="fas fa-tasks metric-icon"></i>
                </div>
                <div class="metric-value"><?php echo $task_stats['total_tasks']; ?></div>
                <div class="metric-subtitle">All assigned tasks</div>
            </div>

            <div class="metric-card success">
                <div class="metric-header">
                    <div class="metric-title">Marking All Done</div>
                    <i class="fas fa-check-circle metric-icon"></i>
                </div>
                <div class="metric-value"><?php echo $task_stats['completed_tasks']; ?></div>
                <div class="metric-subtitle"><?php echo $completion_rate; ?>% completion rate</div>
            </div>

            <div class="metric-card info">
                <div class="metric-header">
                    <div class="metric-title">In Progress</div>
                    <i class="fas fa-spinner metric-icon"></i>
                </div>
                <div class="metric-value"><?php echo $task_stats['in_progress_tasks']; ?></div>
                <div class="metric-subtitle">Active tasks</div>
            </div>

            <div class="metric-card warning">
                <div class="metric-header">
                    <div class="metric-title">Due Today</div>
                    <i class="fas fa-calendar-day metric-icon"></i>
                </div>
                <div class="metric-value"><?php echo $task_stats['due_today']; ?></div>
                <div class="metric-subtitle">Requires attention</div>
            </div>

            <div class="metric-card danger">
                <div class="metric-header">
                    <div class="metric-title">Overdue</div>
                    <i class="fas fa-exclamation-triangle metric-icon"></i>
                </div>
                <div class="metric-value"><?php echo $task_stats['overdue_tasks']; ?></div>
                <div class="metric-subtitle">Past due date</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-tasks"></i>
                        My Tasks
                    </h3>
                    <a href="my-tasks.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($my_tasks)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tasks"></i>
                            <p>No active tasks assigned</p>
                        </div>
                    <?php else: ?>
                        <ul class="task-list">
                            <?php foreach ($my_tasks as $task): ?>
                            <li class="task-item">
                                <div class="task-priority priority-<?php echo $task['priority']; ?>"></div>
                                <div class="task-content">
                                    <div class="task-title">
                                        <a href="view_task.php?task_id=<?php echo $task['id']; ?>" class="page-link" style="color: inherit; text-decoration: none;">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                        </a>
                                    </div>
                                    <div class="task-meta">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo $task['due_display']; ?>
                                        <?php if ($task['status'] === 'in_progress'): ?>
                                            <span style="margin-left: 0.5rem; padding: 0.2rem 0.5rem; background: rgba(54, 185, 204, 0.2); color: var(--info); border-radius: 12px; font-size: 0.7rem;">In Progress</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        Recent Activity
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_activities)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell"></i>
                            <p>No recent activity</p>
                        </div>
                    <?php else: ?>
                        <div class="activity-feed">
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item activity-<?php echo str_replace('_', '-', $activity['type']); ?>">
                                <div class="activity-icon">
                                    <?php
                                    switch ($activity['type']) {
                                        case 'task_assigned':
                                            echo '<i class="fas fa-user-plus" style="color: var(--info);"></i>';
                                            break;
                                        case 'task_updated':
                                            echo '<i class="fas fa-edit" style="color: var(--warning);"></i>';
                                            break;
                                        case 'message':
                                            echo '<i class="fas fa-envelope" style="color: var(--primary);"></i>';
                                            break;
                                        default:
                                            echo '<i class="fas fa-bell" style="color: var(--text-secondary);"></i>';
                                    }
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php
                                        switch ($activity['type']) {
                                            case 'task_assigned':
                                                echo "New task assigned to you";
                                                break;
                                            case 'task_updated':
                                                echo "Task updated for you";
                                                break;
                                            case 'message':
                                                echo "Message received from " . htmlspecialchars($activity['user']);
                                                break;
                                            default:
                                                echo "Activity";
                                                break;
                                        }
                                        ?>
                                    </div>
                                    <div class="activity-detail">
                                        <?php if ($activity['type'] === 'message'): ?>
                                            <strong><?php echo htmlspecialchars($activity['details']); ?></strong>
                                            <?php if ($activity['status'] === 'unread'): ?>
                                                <span class="unread-badge">New</span>
                                            <?php endif; ?>
                                        <?php elseif ($activity['type'] === 'task_assigned'): ?>
                                            <strong><?php echo htmlspecialchars($activity['task']); ?></strong>
                                            <br><small style="color: var(--text-secondary);">
                                                Priority: <span style="color: var(--<?php echo $activity['priority']; ?>);"><?php echo ucfirst($activity['priority']); ?></span>
                                                • Deadline: <?php echo date('M j, Y', strtotime($activity['deadline'])); ?>
                                            </small>
                                        <?php elseif ($activity['type'] === 'task_updated'): ?>
                                            <strong><?php echo htmlspecialchars($activity['task']); ?></strong>
                                            <?php if (isset($activity['old_status']) && isset($activity['new_status'])): ?>
                                                <br><small style="color: var(--text-secondary);">
                                                    Status changed from "<span style="color: var(--warning);"><?php echo htmlspecialchars($activity['old_status']); ?></span>" 
                                                    to "<span style="color: var(--success);"><?php echo htmlspecialchars($activity['new_status']); ?></span>"
                                                </small>
                                            <?php else: ?>
                                                <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($activity['details']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <strong><?php echo htmlspecialchars($activity['task']); ?></strong>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-meta">
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('M j, g:i A', strtotime($activity['activity_time'])); ?>
                                        <?php if (isset($activity['user'])): ?>
                                            <?php if ($activity['type'] === 'task_assigned'): ?>
                                                • assigned by <?php echo htmlspecialchars($activity['user']); ?>
                                            <?php elseif ($activity['type'] === 'task_updated'): ?>
                                                • updated by <?php echo htmlspecialchars($activity['user']); ?>
                                            <?php elseif ($activity['type'] === 'message'): ?>
                                                • from <?php echo htmlspecialchars($activity['user']); ?>
                                            <?php else: ?>
                                                • by <?php echo htmlspecialchars($activity['user']); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="analytics-section">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        7-Day Completion Trend
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="completionChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-flag"></i>
                        Priority Distribution
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (empty($priority_distribution)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tasks"></i>
                            <p>No active tasks</p>
                        </div>
                    <?php else: ?>
                        <div class="priority-breakdown">
                            <?php foreach ($priority_distribution as $priority): 
                                $total_active = array_sum(array_column($priority_distribution, 'count'));
                                $percentage = $total_active > 0 ? round(($priority['count'] / $total_active) * 100) : 0;
                            ?>
                            <div class="priority-item" style="margin-bottom: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <span style="text-transform: capitalize; font-weight: 600;">
                                        <?php echo $priority['priority']; ?> Priority
                                    </span>
                                    <span style="font-weight: 600;"><?php echo $priority['count']; ?> tasks</span>
                                </div>
                                <div style="background: var(--bg-secondary); height: 8px; border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; width: <?php echo $percentage; ?>%; background: var(--<?php echo $priority['priority'] === 'high' ? 'danger' : ($priority['priority'] === 'medium' ? 'warning' : 'success'); ?>); transition: width 1s ease;"></div>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                    <?php echo $percentage; ?>% of active tasks
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>



        <?php if (!empty($recent_completions)): ?>
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-trophy"></i>
                    Recent Achievements
                </h3>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <?php foreach ($recent_completions as $completion): ?>
                    <div style="background: var(--bg-secondary); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--success);">
                        <div style="font-weight: 600; margin-bottom: 0.5rem;">
                            <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                            <?php echo htmlspecialchars($completion['title']); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                            Completed on <?php echo date('M j, Y', strtotime($completion['created_at'])); ?>
                            • Took <?php echo $completion['days_to_complete']; ?> days
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Completion trend chart
            const ctx = document.getElementById('completionChart').getContext('2d');
            const completionData = <?php echo json_encode($completion_trend); ?>;
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: completionData.map(item => item.date),
                    datasets: [{
                        label: 'Tasks Completed',
                        data: completionData.map(item => item.count),
                        borderColor: '#6a0dad',
                        backgroundColor: 'rgba(106, 13, 173, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#6a0dad',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#e0e0e0'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#bbbbbb',
                                stepSize: 1
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#bbbbbb'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    }
                }
            });



            // Animate metric cards on load
            const metricCards = document.querySelectorAll('.metric-card');
            metricCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
