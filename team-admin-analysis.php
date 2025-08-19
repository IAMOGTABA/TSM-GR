<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'team_admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Get user data for sidebar
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt_user->execute(['id' => $_SESSION['user_id']]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

// Get team admin's permissions
$perm_stmt = $pdo->prepare("
    SELECT * FROM team_admin_permissions 
    WHERE user_id = ? 
    LIMIT 1
");
$perm_stmt->execute([$_SESSION['user_id']]);
$permissions = $perm_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Check if team admin has view reports permission
if (empty($permissions['can_view_reports'])) {
    header('Location: team-admin-dashboard.php');
    exit;
}

// Get team information
$team_info_stmt = $pdo->prepare("
    SELECT DISTINCT t.name as team_name, t.id as team_id
    FROM teams t
    JOIN team_admin_teams tat ON t.id = tat.team_id
    WHERE tat.team_admin_id = ?
    LIMIT 1
");
$team_info_stmt->execute([$_SESSION['user_id']]);
$team_info = $team_info_stmt->fetch(PDO::FETCH_ASSOC);

// Get overall team statistics
$overall_stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT u.id) as total_members,
        COUNT(DISTINCT CASE WHEN t.archived = 0 THEN t.id END) as active_tasks,
        COUNT(DISTINCT CASE WHEN t.archived = 1 THEN t.id END) as archived_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'completed' AND t.archived = 0 THEN t.id END) as completed_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'in_progress' AND t.archived = 0 THEN t.id END) as in_progress_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'needs_approval' AND t.archived = 0 THEN t.id END) as needs_approval_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'to_do' AND t.archived = 0 THEN t.id END) as todo_tasks,
        AVG(CASE WHEN t.archived = 1 AND t.status = 'completed' THEN 
            DATEDIFF(NOW(), t.created_at) END) as avg_completion_days
    FROM users u
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN tasks t ON t.assigned_to = u.id
    WHERE tat.team_admin_id = ? AND u.role = 'employee' AND u.status = 'active'
");
$overall_stats_stmt->execute([$_SESSION['user_id']]);
$overall_stats = $overall_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get task completion by priority
$priority_stats_stmt = $pdo->prepare("
    SELECT 
        t.priority,
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN t.status = 'completed' OR t.archived = 1 THEN 1 END) as completed_tasks
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    WHERE tat.team_admin_id = ? AND u.role = 'employee'
    GROUP BY t.priority
    ORDER BY FIELD(t.priority, 'high', 'medium', 'low')
");
$priority_stats_stmt->execute([$_SESSION['user_id']]);
$priority_stats = $priority_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get individual member performance
$member_performance_stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.full_name,
        u.email,
        COUNT(DISTINCT t.id) as total_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'completed' OR t.archived = 1 THEN t.id END) as completed_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'in_progress' THEN t.id END) as in_progress_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'needs_approval' THEN t.id END) as needs_approval_tasks,
        COUNT(DISTINCT CASE WHEN t.archived = 1 THEN t.id END) as archived_tasks,
        AVG(CASE WHEN t.archived = 1 AND t.status = 'completed' THEN 
            DATEDIFF(NOW(), t.created_at) END) as avg_completion_days
    FROM users u
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN tasks t ON t.assigned_to = u.id
    WHERE tat.team_admin_id = ? AND u.role = 'employee' AND u.status = 'active'
    GROUP BY u.id, u.full_name, u.email
    ORDER BY completed_tasks DESC, total_tasks DESC
");
$member_performance_stmt->execute([$_SESSION['user_id']]);
$member_performance = $member_performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity for team members only
$recent_activity_stmt = $pdo->prepare("
    SELECT 
        al.created_at,
        al.action_type,
        al.details,
        u.full_name as user_name,
        t.title as task_title
    FROM activity_logs al
    JOIN users u ON al.user_id = u.id
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN tasks t ON al.task_id = t.id
    WHERE tat.team_admin_id = ? AND u.role = 'employee'
    ORDER BY al.created_at DESC
    LIMIT 10
");
$recent_activity_stmt->execute([$_SESSION['user_id']]);
$recent_activity = $recent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate completion rate
$completion_rate = $overall_stats['active_tasks'] > 0 ? 
    round(($overall_stats['completed_tasks'] / ($overall_stats['active_tasks'] + $overall_stats['archived_tasks'])) * 100, 1) : 0;

// Calculate productivity score (completed + archived tasks per member)
$productivity_score = $overall_stats['total_members'] > 0 ? 
    round(($overall_stats['completed_tasks'] + $overall_stats['archived_tasks']) / $overall_stats['total_members'], 1) : 0;

// Handle date filtering for archived tasks
$date_filter_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_filter_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build date filter conditions
$date_conditions = [];
$date_params = [$_SESSION['user_id']];

if (!empty($date_filter_from)) {
    $date_conditions[] = "DATE(t.created_at) >= ?";
    $date_params[] = $date_filter_from;
}

if (!empty($date_filter_to)) {
    $date_conditions[] = "DATE(t.created_at) <= ?";
    $date_params[] = $date_filter_to;
}

$date_where_clause = '';
if (!empty($date_conditions)) {
    $date_where_clause = ' AND ' . implode(' AND ', $date_conditions);
}

// Get detailed archived tasks for the team
$archived_tasks_stmt = $pdo->prepare("
    SELECT t.*, u.full_name as assigned_user, creator.full_name as created_by_name,
           DATEDIFF(CURDATE(), t.created_at) as days_to_complete,
           (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id) as total_subtasks,
           (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND status = 'done') as completed_subtasks,
           (SELECT COUNT(*) FROM activity_logs WHERE task_id = t.id) as activity_count
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN users creator ON t.created_by = creator.id
    WHERE t.archived = 1 AND tat.team_admin_id = ? AND u.role = 'employee' $date_where_clause
    ORDER BY t.created_at DESC
");
$archived_tasks_stmt->execute($date_params);
$archived_tasks = $archived_tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Analysis - Team Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-main);
            line-height: 1.6;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-lg);
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
            color: #4ecdc4;
            font-size: 0.9rem;
            font-weight: 500;
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
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
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

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
        }

        .page-header {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 1rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 1.25rem;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 20px 20px 0 0;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 60px rgba(106, 13, 173, 0.15);
        }

        .stat-card.success::before {
            background: linear-gradient(90deg, var(--success) 0%, #2ecc71 100%);
        }

        .stat-card.info::before {
            background: linear-gradient(90deg, var(--info) 0%, #3498db 100%);
        }

        .stat-card.warning::before {
            background: linear-gradient(90deg, var(--warning) 0%, #f1c40f 100%);
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, var(--danger) 0%, #e67e22 100%);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.3rem;
            color: white;
            position: relative;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-icon::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 50%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            z-index: -1;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 50%, #8e24aa 100%);
            box-shadow: 0 8px 32px rgba(106, 13, 173, 0.4);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, var(--success) 0%, #27ae60 50%, #2ecc71 100%);
            box-shadow: 0 8px 32px rgba(28, 200, 138, 0.4);
        }

        .stat-icon.info {
            background: linear-gradient(135deg, var(--info) 0%, #2980b9 50%, #3498db 100%);
            box-shadow: 0 8px 32px rgba(54, 185, 204, 0.4);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f39c12 50%, #f1c40f 100%);
            box-shadow: 0 8px 32px rgba(246, 194, 62, 0.4);
        }

        .stat-icon.danger {
            background: linear-gradient(135deg, var(--danger) 0%, #c0392b 50%, #e67e22 100%);
            box-shadow: 0 8px 32px rgba(231, 76, 60, 0.4);
        }

        .stat-card.high::before {
            background: linear-gradient(90deg, var(--danger) 0%, #e74c3c 100%);
        }

        .stat-card.medium::before {
            background: linear-gradient(90deg, var(--warning) 0%, #f39c12 100%);
        }

        .stat-card.low::before {
            background: linear-gradient(90deg, var(--secondary) 0%, #95a5a6 100%);
        }

        .stat-icon.high {
            background: linear-gradient(135deg, var(--danger) 0%, #c0392b 50%, #e74c3c 100%);
            box-shadow: 0 8px 32px rgba(231, 76, 60, 0.4);
        }

        .stat-icon.medium {
            background: linear-gradient(135deg, var(--warning) 0%, #f39c12 50%, #f1c40f 100%);
            box-shadow: 0 8px 32px rgba(246, 194, 62, 0.4);
        }

        .stat-icon.low {
            background: linear-gradient(135deg, var(--secondary) 0%, #7f8c8d 50%, #95a5a6 100%);
            box-shadow: 0 8px 32px rgba(133, 135, 150, 0.4);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 0.25rem;
            background: linear-gradient(135deg, var(--text-main) 0%, var(--primary-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            line-height: 1.2;
        }

        .section {
            background: var(--bg-card);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            padding: 2.5rem;
            margin-bottom: 3rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }

        .section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--info) 50%, var(--success) 100%);
            border-radius: 20px 20px 0 0;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
        }

        .section-title::after {
            content: '';
            flex: 1;
            height: 2px;
            background: linear-gradient(90deg, var(--primary) 0%, transparent 100%);
            border-radius: 1px;
        }

        .priority-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .priority-item {
            background: var(--bg-secondary);
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            position: relative;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.05);
            overflow: hidden;
        }

        .priority-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 16px 16px 0 0;
        }

        .priority-item.high::before {
            background: linear-gradient(90deg, var(--danger) 0%, #e74c3c 100%);
        }

        .priority-item.medium::before {
            background: linear-gradient(90deg, var(--warning) 0%, #f39c12 100%);
        }

        .priority-item.low::before {
            background: linear-gradient(90deg, var(--secondary) 0%, #95a5a6 100%);
        }

        .priority-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .priority-label {
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 1rem;
            font-size: 1rem;
            letter-spacing: 1px;
        }

        .priority-label.high {
            color: var(--danger);
        }

        .priority-label.medium {
            color: var(--warning);
        }

        .priority-label.low {
            color: var(--secondary);
        }

        .priority-stats-text {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .performance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 1.5rem;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .performance-table th,
        .performance-table td {
            padding: 1.5rem 1.25rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .performance-table th {
            background: linear-gradient(135deg, var(--bg-secondary) 0%, rgba(42, 42, 42, 0.8) 100%);
            font-weight: 700;
            color: var(--text-main);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
        }

        .performance-table th:first-child {
            border-radius: 12px 0 0 0;
        }

        .performance-table th:last-child {
            border-radius: 0 12px 0 0;
        }

        .performance-table td {
            color: var(--text-secondary);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .performance-table tr:hover {
            background: rgba(106, 13, 173, 0.05);
            transform: scale(1.01);
        }

        .performance-table tr:hover td {
            color: var(--text-main);
        }

        .performance-table tr:last-child td:first-child {
            border-radius: 0 0 0 12px;
        }

        .performance-table tr:last-child td:last-child {
            border-radius: 0 0 12px 0;
        }

        .member-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            margin-right: 0.5rem;
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            overflow: hidden;
            margin-top: 1rem;
            position: relative;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success) 0%, #2ecc71 50%, var(--info) 100%);
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 20px;
            position: relative;
            box-shadow: 0 2px 8px rgba(28, 200, 138, 0.3);
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.2) 0%, transparent 100%);
            border-radius: 20px 20px 0 0;
        }

        .activity-list {
            max-height: 500px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .activity-list::-webkit-scrollbar {
            width: 6px;
        }

        .activity-list::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 3px;
        }

        .activity-list::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 3px;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
            border-radius: 12px;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .activity-item:hover {
            background: rgba(106, 13, 173, 0.05);
            transform: translateX(8px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .activity-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%);
            border-radius: 0 3px 3px 0;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .activity-item:hover::before {
            opacity: 1;
        }

        /* Timeline Activity Styles */
        .timeline-container {
            position: relative;
            padding: 1rem 0;
        }

        .timeline-item {
            position: relative;
            display: flex;
            align-items: flex-start;
            margin-bottom: 2rem;
            padding-left: 60px;
        }

        .timeline-marker {
            position: absolute;
            left: 0;
            top: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
            z-index: 2;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 3px solid var(--bg-main);
        }

        .timeline-marker.primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        .timeline-marker.success {
            background: linear-gradient(135deg, var(--success) 0%, #27ae60 100%);
        }

        .timeline-marker.info {
            background: linear-gradient(135deg, var(--info) 0%, #2980b9 100%);
        }

        .timeline-marker.warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f39c12 100%);
        }

        .timeline-marker.secondary {
            background: linear-gradient(135deg, var(--secondary) 0%, #7f8c8d 100%);
        }

        .timeline-line {
            position: absolute;
            left: 19px;
            top: 40px;
            width: 2px;
            height: calc(100% + 2rem);
            background: linear-gradient(to bottom, var(--border-color) 0%, transparent 100%);
            z-index: 1;
        }

        .timeline-content {
            flex: 1;
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
            position: relative;
        }

        .timeline-content::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 20px;
            width: 0;
            height: 0;
            border-top: 8px solid transparent;
            border-bottom: 8px solid transparent;
            border-right: 8px solid var(--bg-card);
        }

        .timeline-content:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .activity-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .user-badge {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .action-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .action-badge.completed {
            background: rgba(28, 200, 138, 0.15);
            color: var(--success);
            border: 1px solid rgba(28, 200, 138, 0.3);
        }

        .action-badge.created {
            background: rgba(54, 185, 204, 0.15);
            color: var(--info);
            border: 1px solid rgba(54, 185, 204, 0.3);
        }

        .action-badge.updated {
            background: rgba(246, 194, 62, 0.15);
            color: var(--warning);
            border: 1px solid rgba(246, 194, 62, 0.3);
        }

        .action-badge.archived {
            background: rgba(133, 135, 150, 0.15);
            color: var(--secondary);
            border: 1px solid rgba(133, 135, 150, 0.3);
        }

        .action-badge.general {
            background: rgba(106, 13, 173, 0.15);
            color: var(--primary);
            border: 1px solid rgba(106, 13, 173, 0.3);
        }

        .time-ago {
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 500;
            margin-left: auto;
        }

        .activity-body {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .activity-description {
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }

        .task-preview {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.75rem;
        }

        .task-title {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .task-meta {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .task-meta span {
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .priority {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .priority.high {
            background: rgba(231, 76, 60, 0.15);
            color: var(--danger);
        }

        .priority.medium {
            background: rgba(246, 194, 62, 0.15);
            color: var(--warning);
        }

        .priority.low {
            background: rgba(133, 135, 150, 0.15);
            color: var(--secondary);
        }

        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 16px rgba(106, 13, 173, 0.3);
            transition: all 0.3s ease;
        }

        .activity-item:hover .activity-icon {
            transform: scale(1.1);
            box-shadow: 0 6px 24px rgba(106, 13, 173, 0.4);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            font-size: 1.05rem;
        }

        .activity-description {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        .activity-time {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            opacity: 0.8;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        @media (max-width: 1200px) {
            .analytics-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.7rem;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .analytics-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }
            
            .stat-card {
                padding: 0.75rem;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
                margin-bottom: 0.75rem;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .stat-label {
                font-size: 0.65rem;
            }
            
            .priority-stats {
                grid-template-columns: 1fr;
            }
            
            .performance-table {
                font-size: 0.8rem;
            }
            
            .performance-table th,
            .performance-table td {
                padding: 0.5rem;
            }
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
                <div class="user-role">Team Admin</div>
            </div>
        </div>
        
        <div class="sidebar-heading">Main</div>
        <ul class="sidebar-menu">
            <li><a href="team-admin-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="team-admin-tasks.php"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <?php if (!empty($permissions['can_assign'])): ?>
            <li><a href="team-admin-add-task.php"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <?php endif; ?>
            <li><a href="team-admin-team.php"><i class="fas fa-users"></i> My Team</a></li>
            <?php if (!empty($permissions['can_view_reports'])): ?>
            <li><a href="team-admin-analysis.php" class="active"><i class="fas fa-chart-line"></i> Analysis</a></li>
            <?php endif; ?>
            <?php if (!empty($permissions['can_send_messages'])): ?>
            <li><a href="team-admin-messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Team Analysis</h1>
            <p class="page-subtitle">
                <?php if ($team_info): ?>
                    Performance analytics and insights for team: <strong><?php echo htmlspecialchars($team_info['team_name']); ?></strong>
                <?php else: ?>
                    Performance analytics and insights for your team members
                <?php endif; ?>
            </p>
        </div>

        <!-- Key Metrics -->
        <div class="analytics-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $overall_stats['total_members'] ?: 0; ?></div>
                <div class="stat-label">Team Members</div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo ($overall_stats['completed_tasks'] + $overall_stats['archived_tasks']) ?: 0; ?></div>
                <div class="stat-label">Tasks Completed</div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-icon info">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-value"><?php echo $overall_stats['active_tasks'] ?: 0; ?></div>
                <div class="stat-label">Active Tasks</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-icon warning">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value"><?php echo $completion_rate; ?>%</div>
                <div class="stat-label">Completion Rate</div>
            </div>

            <div class="stat-card info">
                <div class="stat-icon info">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $overall_stats['in_progress_tasks'] ?: 0; ?></div>
                <div class="stat-label">In Progress</div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon warning">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-value"><?php echo $overall_stats['needs_approval_tasks'] ?: 0; ?></div>
                <div class="stat-label">Needs Approval</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-archive"></i>
                </div>
                <div class="stat-value"><?php echo $overall_stats['archived_tasks'] ?: 0; ?></div>
                <div class="stat-label">Archived Tasks</div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon success">
                    <i class="fas fa-trophy"></i>
                </div>
                <div class="stat-value"><?php echo $productivity_score; ?></div>
                <div class="stat-label">Productivity Score</div>
            </div>
        </div>

        <!-- Priority Analysis -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-chart-bar"></i>
                Task Priority Analysis
            </h2>
            
            <?php if (empty($priority_stats)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <h3>No Priority Data</h3>
                    <p>No tasks found to analyze priority distribution.</p>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 2rem;">
                    <?php 
                    // Show only first 2 priority items for compact display
                    $displayed_priorities = array_slice($priority_stats, 0, 2);
                    foreach ($displayed_priorities as $priority): ?>
                        <div class="stat-card <?php echo $priority['priority']; ?>">
                            <div class="stat-icon <?php echo $priority['priority']; ?>">
                                <?php
                                switch($priority['priority']) {
                                    case 'high': echo '<i class="fas fa-exclamation-triangle"></i>'; break;
                                    case 'medium': echo '<i class="fas fa-minus-circle"></i>'; break;
                                    case 'low': echo '<i class="fas fa-arrow-down"></i>'; break;
                                    default: echo '<i class="fas fa-tasks"></i>';
                                }
                                ?>
                            </div>
                            <div class="stat-value"><?php echo $priority['total_tasks']; ?></div>
                            <div class="stat-label"><?php echo ucfirst($priority['priority']); ?> Priority Tasks</div>
                            <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-secondary);">
                                <?php echo $priority['completed_tasks']; ?> completed 
                                (<?php echo $priority['total_tasks'] > 0 ? round(($priority['completed_tasks'] / $priority['total_tasks']) * 100, 1) : 0; ?>%)
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Archived Tasks Section -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-archive"></i>
                Archive
            </h2>
            
            <!-- Date Filter Form -->
            <div style="background-color: var(--bg-secondary); padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; border: 1px solid var(--border-color);">
                <form method="GET" action="team-admin-analysis.php" style="display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label for="date_from" style="font-weight: 600; color: var(--text-main); font-size: 0.9rem;">
                            <i class="fas fa-calendar-alt"></i> From Date
                        </label>
                        <input type="date" 
                               id="date_from" 
                               name="date_from" 
                               value="<?php echo htmlspecialchars($date_filter_from); ?>"
                               style="padding: 0.5rem; background-color: var(--bg-main); border: 1px solid var(--border-color); border-radius: 0.35rem; color: var(--text-main); font-size: 0.9rem;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label for="date_to" style="font-weight: 600; color: var(--text-main); font-size: 0.9rem;">
                            <i class="fas fa-calendar-alt"></i> To Date
                        </label>
                        <input type="date" 
                               id="date_to" 
                               name="date_to" 
                               value="<?php echo htmlspecialchars($date_filter_to); ?>"
                               style="padding: 0.5rem; background-color: var(--bg-main); border: 1px solid var(--border-color); border-radius: 0.35rem; color: var(--text-main); font-size: 0.9rem;">
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn" style="padding: 0.5rem 1rem; font-size: 0.9rem; background-color: var(--primary); color: white; border: none; border-radius: 0.35rem; cursor: pointer; font-weight: 600; transition: all 0.2s;">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="team-admin-analysis.php" style="padding: 0.5rem 1rem; font-size: 0.9rem; background-color: var(--secondary); color: white; text-decoration: none; border-radius: 0.35rem; font-weight: 600; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.25rem;">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
                <?php if (!empty($date_filter_from) || !empty($date_filter_to)): ?>
                    <div style="margin-top: 1rem; padding: 0.75rem; background-color: rgba(54, 185, 204, 0.1); border-left: 4px solid var(--info); border-radius: 0.35rem;">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Filter Active:</strong> 
                        Showing archived tasks 
                        <?php if (!empty($date_filter_from)): ?>
                            from <?php echo date('M j, Y', strtotime($date_filter_from)); ?>
                        <?php endif; ?>
                        <?php if (!empty($date_filter_to)): ?>
                            to <?php echo date('M j, Y', strtotime($date_filter_to)); ?>
                        <?php endif; ?>
                        (<?php echo count($archived_tasks); ?> tasks found)
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (empty($archived_tasks)): ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <h3>No Archived Tasks</h3>
                    <p>No archived tasks found for your team members.</p>
                </div>
            <?php else: ?>
                <table class="performance-table">
                    <thead>
                        <tr>
                            <th>Task Details</th>
                            <th>Assignment Info</th>
                            <th>Priority</th>
                            <th>Timeline</th>
                            <th>Progress</th>
                            <th>Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archived_tasks as $task): ?>
                        <tr>
                            <td data-label="Task Details">
                                <div style="margin-bottom: 0.5rem;">
                                    <strong style="color: var(--primary-light);"><?php echo htmlspecialchars($task['title']); ?></strong>
                                </div>
                                <?php if ($task['description']): ?>
                                    <div style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.3;">
                                        <?php echo htmlspecialchars(substr($task['description'], 0, 100)) . (strlen($task['description']) > 100 ? '...' : ''); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($task['deadline']): ?>
                                    <div style="margin-top: 0.25rem;">
                                        <small style="color: var(--info); background: rgba(54, 185, 204, 0.1); padding: 0.2rem 0.4rem; border-radius: 0.2rem;">
                                            <i class="fas fa-calendar"></i> Deadline: <?php echo date('M j, Y', strtotime($task['deadline'])); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Assignment Info">
                                <div style="margin-bottom: 0.5rem;">
                                    <strong>Assigned to:</strong><br>
                                    <span style="color: var(--success);"><?php echo htmlspecialchars($task['assigned_user'] ?? 'Unassigned'); ?></span>
                                </div>
                                <div>
                                    <strong>Created by:</strong><br>
                                    <span style="color: var(--text-secondary);"><?php echo htmlspecialchars($task['created_by_name'] ?? 'Unknown'); ?></span>
                                </div>
                            </td>
                            <td data-label="Priority">
                                <span class="priority <?php echo $task['priority']; ?>" style="font-weight: 600; text-transform: uppercase; font-size: 0.85rem;">
                                    <?php echo ucfirst($task['priority']); ?>
                                </span>
                            </td>
                            <td data-label="Timeline">
                                <div style="margin-bottom: 0.5rem;">
                                    <strong>Created:</strong><br>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem;"><?php echo date('M j, Y', strtotime($task['created_at'])); ?></span>
                                </div>
                                <div style="margin-bottom: 0.5rem;">
                                    <strong>Archived:</strong><br>
                                    <span style="color: var(--success); font-size: 0.85rem;"><?php echo date('M j, Y', strtotime($task['created_at'])); ?></span>
                                </div>
                                <div>
                                    <strong>Duration:</strong><br>
                                    <span style="color: var(--info); font-size: 0.85rem; font-weight: 600;">
                                        <?php echo $task['days_to_complete'] ?? 0; ?> days
                                    </span>
                                </div>
                            </td>
                            <td data-label="Progress">
                                <div style="margin-bottom: 0.5rem;">
                                    <strong>Subtasks:</strong>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                                        <span style="color: var(--success); font-weight: 600;"><?php echo $task['completed_subtasks']; ?>/<?php echo $task['total_subtasks']; ?></span>
                                        <?php if ($task['total_subtasks'] > 0): ?>
                                            <div class="progress-bar" style="width: 60px;">
                                                <div class="progress-fill" style="width: <?php echo $task['total_subtasks'] > 0 ? round(($task['completed_subtasks'] / $task['total_subtasks']) * 100) : 0; ?>%"></div>
                                            </div>
                                            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                                <?php echo $task['total_subtasks'] > 0 ? round(($task['completed_subtasks'] / $task['total_subtasks']) * 100) : 0; ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <strong>Status:</strong><br>
                                    <span style="color: var(--success); background: rgba(28, 200, 138, 0.1); padding: 0.2rem 0.4rem; border-radius: 0.2rem; font-size: 0.8rem; font-weight: 600;">
                                        <i class="fas fa-check-circle"></i> ARCHIVED
                                    </span>
                                </div>
                            </td>
                            <td data-label="Activity" style="text-align: center;">
                                <div style="margin-bottom: 0.5rem;">
                                    <span style="color: var(--info); font-weight: 600; font-size: 1.1rem;"><?php echo $task['activity_count'] ?? 0; ?></span><br>
                                    <small style="color: var(--text-secondary);">Activity Logs</small>
                                </div>
                                <button class="btn btn-info" onclick="viewTaskDetails(<?php echo $task['id']; ?>)" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: var(--info); color: white; border: none; border-radius: 0.25rem; cursor: pointer;">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Team Member Performance -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-users"></i>
                Team Member Performance
            </h2>
            
            <?php if (empty($member_performance)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Team Members</h3>
                    <p>No team members found to display performance data.</p>
                </div>
            <?php else: ?>
                <table class="performance-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Total Tasks</th>
                            <th>Completed</th>
                            <th>In Progress</th>
                            <th>Needs Approval</th>
                            <th>Archived</th>
                            <th>Avg. Days</th>
                            <th>Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <div class="member-avatar-small">
                                            <?php 
                                            $names = explode(' ', $member['full_name']);
                                            echo strtoupper(substr($names[0], 0, 1));
                                            if (isset($names[1])) echo strtoupper(substr($names[1], 0, 1));
                                            ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo htmlspecialchars($member['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo $member['total_tasks']; ?></td>
                                <td style="color: var(--success);"><?php echo $member['completed_tasks']; ?></td>
                                <td style="color: var(--info);"><?php echo $member['in_progress_tasks']; ?></td>
                                <td style="color: var(--warning);"><?php echo $member['needs_approval_tasks']; ?></td>
                                <td style="color: var(--secondary);"><?php echo $member['archived_tasks']; ?></td>
                                <td><?php echo $member['avg_completion_days'] ? round($member['avg_completion_days'], 1) : '-'; ?></td>
                                <td>
                                    <?php 
                                    $rate = $member['total_tasks'] > 0 ? round(($member['completed_tasks'] / $member['total_tasks']) * 100, 1) : 0;
                                    echo $rate . '%';
                                    ?>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $rate; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Team Activity -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-history"></i>
                Recent Team Activity
            </h2>
            
            <?php if (empty($recent_activity)): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>No Recent Activity</h3>
                    <p>No recent activity found for your team members.</p>
                </div>
            <?php else: ?>
                <div class="timeline-container">
                    <?php foreach ($recent_activity as $index => $activity): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker <?php 
                                switch($activity['action_type']) {
                                    case 'task_completed': 
                                    case 'task_approved': echo 'success'; break;
                                    case 'task_created': echo 'info'; break;
                                    case 'task_updated': 
                                    case 'status_update': echo 'warning'; break;
                                    case 'task_archived': echo 'secondary'; break;
                                    default: echo 'primary';
                                }
                            ?>">
                                <?php
                                switch($activity['action_type']) {
                                    case 'task_created': echo '<i class="fas fa-plus"></i>'; break;
                                    case 'task_updated': echo '<i class="fas fa-edit"></i>'; break;
                                    case 'status_update': echo '<i class="fas fa-exchange-alt"></i>'; break;
                                    case 'task_completed': echo '<i class="fas fa-check"></i>'; break;
                                    case 'task_approved': echo '<i class="fas fa-thumbs-up"></i>'; break;
                                    case 'task_archived': echo '<i class="fas fa-archive"></i>'; break;
                                    case 'subtask_added': echo '<i class="fas fa-list"></i>'; break;
                                    default: echo '<i class="fas fa-bell"></i>';
                                }
                                ?>
                            </div>
                            <?php if ($index < count($recent_activity) - 1): ?>
                                <div class="timeline-line"></div>
                            <?php endif; ?>
                            <div class="timeline-content">
                                <div class="activity-header">
                                    <span class="user-badge"><?php echo htmlspecialchars($activity['user_name']); ?></span>
                                    <span class="action-badge <?php 
                                        switch($activity['action_type']) {
                                            case 'task_completed': 
                                            case 'task_approved': echo 'completed'; break;
                                            case 'task_created': echo 'created'; break;
                                            case 'task_updated': 
                                            case 'status_update': echo 'updated'; break;
                                            case 'task_archived': echo 'archived'; break;
                                            default: echo 'general';
                                        }
                                    ?>"><?php 
                                        switch($activity['action_type']) {
                                            case 'task_created': echo 'Task Created'; break;
                                            case 'task_updated': echo 'Task Updated'; break;
                                            case 'status_update': echo 'Status Changed'; break;
                                            case 'task_completed': echo 'Task Completed'; break;
                                            case 'task_approved': echo 'Task Approved'; break;
                                            case 'task_archived': echo 'Task Archived'; break;
                                            case 'subtask_added': echo 'Subtask Added'; break;
                                            default: echo 'Activity';
                                        }
                                    ?></span>
                                    <span class="time-ago"><?php 
                                        $time_diff = time() - strtotime($activity['created_at']);
                                        if ($time_diff < 60) {
                                            echo 'Just now';
                                        } elseif ($time_diff < 3600) {
                                            echo floor($time_diff / 60) . 'm ago';
                                        } elseif ($time_diff < 86400) {
                                            echo floor($time_diff / 3600) . 'h ago';
                                        } else {
                                            echo floor($time_diff / 86400) . 'd ago';
                                        }
                                    ?></span>
                                </div>
                                <div class="activity-body">
                                    <p class="activity-description"><?php echo htmlspecialchars($activity['details']); ?></p>
                                    <?php if ($activity['task_title']): ?>
                                        <div class="task-preview">
                                            <div class="task-title"><?php echo htmlspecialchars($activity['task_title']); ?></div>
                                            <div class="task-meta">
                                                <span class="priority medium">📋 Task</span>
                                                <span class="completion-time">📅 <?php echo date('M j', strtotime($activity['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Task Details Modal -->
    <div id="taskDetailsModal" class="modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5);">
        <div class="modal-content" style="background-color: var(--bg-card); position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 800px; min-height: 400px; max-height: 80vh; overflow-y: auto; display: flex; flex-direction: column;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0;">
                <h3>Task Details</h3>
                <button class="close-btn" onclick="closeTaskDetailsModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-secondary); cursor: pointer;">&times;</button>
            </div>
            <div id="taskDetailsContent">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <script>
        function viewTaskDetails(taskId) {
            // Fetch task details via AJAX
            fetch('get_task_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `task_id=${taskId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showTaskDetailsModal(data.task);
                } else {
                    alert('Error loading task details: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred');
            });
        }
        
        function showTaskDetailsModal(task) {
            const modal = document.getElementById('taskDetailsModal');
            const content = document.getElementById('taskDetailsContent');
            
            // Populate modal content
            content.innerHTML = `
                <div class="task-details">
                    <h4 style="color: var(--primary); margin-bottom: 1.5rem; text-align: center; font-size: 1.5rem; padding: 0.5rem; background-color: var(--bg-secondary); border-radius: 0.35rem; border: 1px solid var(--border-color);">${task.title}</h4>
                    <div class="task-info-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Description:</strong>
                            <p style="margin: 0; color: var(--text-main); white-space: pre-wrap; line-height: 1.5;">${task.description || 'No description provided'}</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Assigned To:</strong>
                            <p style="margin: 0; color: var(--text-main);">${task.assigned_user || 'Unassigned'}</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Created By:</strong>
                            <p style="margin: 0; color: var(--text-main);">${task.created_by_name || 'Unknown'}</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Priority:</strong>
                            <p style="margin: 0; color: var(--text-main);" class="priority-${task.priority}">${task.priority.charAt(0).toUpperCase() + task.priority.slice(1)}</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Deadline:</strong>
                            <p style="margin: 0; color: var(--text-main);">${task.deadline ? new Date(task.deadline).toLocaleDateString() : 'No deadline'}</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Created:</strong>
                            <p style="margin: 0; color: var(--text-main);">${new Date(task.created_at).toLocaleDateString()}</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Status:</strong>
                            <p style="margin: 0; color: var(--success);">ARCHIVED</p>
                        </div>
                        <div class="info-item" style="background-color: var(--bg-secondary); padding: 1rem; border-radius: 0.35rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--primary-light); display: block; margin-bottom: 0.5rem;">Subtasks:</strong>
                            <p style="margin: 0; color: var(--text-main);">${task.completed_subtasks}/${task.total_subtasks} completed</p>
                        </div>
                    </div>
                    ${task.subtasks && task.subtasks.length > 0 ? `
                        <div class="subtasks-section" style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                            <h4 style="color: var(--primary-light); margin-bottom: 1rem; text-align: center;">Subtask Details</h4>
                            <div class="subtasks-list" style="display: flex; flex-direction: column; gap: 0.75rem;">
                                ${task.subtasks.map(subtask => `
                                    <div class="subtask-item ${subtask.status === 'done' ? 'completed' : ''}" style="display: flex; align-items: center; gap: 0.75rem; padding: 1rem; background-color: var(--bg-secondary); border-radius: 0.35rem; border: 1px solid var(--border-color); transition: all 0.3s ease; ${subtask.status === 'done' ? 'background-color: rgba(28, 200, 138, 0.1); border-color: var(--success);' : ''}">
                                        <span class="subtask-status" style="font-size: 1.2rem; color: var(--success); min-width: 1.5rem; text-align: center;">${subtask.status === 'done' ? '✓' : '○'}</span>
                                        <span class="subtask-title" style="flex: 1; color: var(--text-main); font-weight: 500;">${subtask.title}</span>
                                        <span class="subtask-status-text" style="color: var(--text-secondary); font-size: 0.85rem; padding: 0.25rem 0.5rem; background-color: var(--bg-main); border-radius: 0.25rem; border: 1px solid var(--border-color);">${subtask.status === 'done' ? 'Completed' : 'To Do'}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : '<div class="subtasks-section" style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);"><p>No subtasks found for this task.</p></div>'}
                </div>
            `;
            
            modal.style.display = 'block';
        }
        
        function closeTaskDetailsModal() {
            document.getElementById('taskDetailsModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('taskDetailsModal');
            if (event.target === modal) {
                closeTaskDetailsModal();
            }
        }
    </script>
</body>
</html>
