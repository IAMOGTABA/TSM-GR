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

// Get team admin's teams
$teams_stmt = $pdo->prepare("
    SELECT t.id, t.name 
    FROM teams t
    JOIN team_admin_teams tat ON t.id = tat.team_id
    WHERE tat.team_admin_id = ?
");
$teams_stmt->execute([$_SESSION['user_id']]);
$admin_teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get team admin's permissions for the first team (for UI display)
$permissions = [];
if (!empty($admin_teams)) {
    $perm_stmt = $pdo->prepare("
        SELECT * FROM team_admin_permissions 
        WHERE user_id = ? AND team_id = ?
    ");
    $perm_stmt->execute([$_SESSION['user_id'], $admin_teams[0]['id']]);
    $permissions = $perm_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Get team statistics - ONLY for team admin's team members
$team_stats = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN u.role = 'employee' AND u.status = 'active' THEN 1 END) as team_members,
        COUNT(CASE WHEN t.status IN ('todo', 'in_progress') AND t.archived = 0 THEN 1 END) as active_tasks,
        COUNT(CASE WHEN t.status = 'completed' AND t.archived = 0 THEN 1 END) as completed_tasks,
        COUNT(CASE WHEN t.status = 'needs_approval' AND t.archived = 0 THEN 1 END) as pending_approval
    FROM users u
    LEFT JOIN tasks t ON t.assigned_to = u.id
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    WHERE tat.team_admin_id = ?
");
$team_stats->execute([$_SESSION['user_id']]);
$stats = $team_stats->fetch(PDO::FETCH_ASSOC);

// Get recent team activity - ONLY from this team admin's employees (NOT team admin's own work)
$recent_activities = [];
try {
    $activity_stmt = $pdo->prepare("
        SELECT 
            al.*, 
            u.full_name as user_name, 
            u.role as user_role,
            t.title as task_title, 
            t.id as task_id, 
            'activity' as source_type,
            al.created_at as activity_time
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        LEFT JOIN tasks t ON al.task_id = t.id
        JOIN team_admin_teams tat ON u.team_id = tat.team_id
        WHERE DATE(al.created_at) = CURDATE()
        AND tat.team_admin_id = ?
        AND u.role = 'employee'  -- ONLY employees, NOT team admins
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
    $activity_stmt->execute([$_SESSION['user_id']]);
    $activity_logs = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($activity_logs as $log) {
        $recent_activities[] = [
            'type' => $log['action_type'] ?? 'activity',
            'user' => $log['user_name'],
            'user_role' => $log['user_role'] ?? 'employee',
            'task' => $log['task_title'] ?? 'Unknown Task',
            'details' => $log['details'] ?? '',
            'old_status' => $log['old_status'] ?? null,
            'new_status' => $log['new_status'] ?? null,
            'time' => date('g:i A', strtotime($log['activity_time'])),
            'task_id' => $log['task_id'],
            'priority' => 'normal'
        ];
    }
} catch (PDOException $e) {
    error_log("Team admin activity logs error: " . $e->getMessage());
}

// Get team members for quick overview
$team_members_stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.status,
           COUNT(t.id) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status IN ('todo', 'in_progress') AND t.archived = 0
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    WHERE tat.team_admin_id = ? AND u.role = 'employee'
    GROUP BY u.id, u.full_name, u.email, u.status
    ORDER BY u.full_name
    LIMIT 5
");
$team_members_stmt->execute([$_SESSION['user_id']]);
$team_members = $team_members_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Admin Dashboard - TSM</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }

        .stat-icon.members { background: linear-gradient(135deg, var(--info), #1d4ed8); }
        .stat-icon.tasks { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
        .stat-icon.completed { background: linear-gradient(135deg, var(--success), #059669); }
        .stat-icon.pending { background: linear-gradient(135deg, var(--warning), #d97706); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .card {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: between;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: var(--bg-secondary);
            border-left: 3px solid var(--primary);
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .activity-detail {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            color: var(--text-secondary);
            font-size: 0.75rem;
        }

        .member-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: var(--bg-secondary);
            margin-bottom: 1rem;
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .member-info h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }

        .member-info p {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .member-tasks {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
                }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-main);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .permission-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .permission-badge.enabled {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .permission-badge.disabled {
            background: rgba(107, 114, 128, 0.1);
            color: var(--secondary);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
            <li><a href="team-admin-dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="team-admin-tasks.php"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <?php if (!empty($permissions['can_assign'])): ?>
            <li><a href="team-admin-add-task.php"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <?php endif; ?>
            <li><a href="team-admin-team.php"><i class="fas fa-users"></i> My Team</a></li>
            <?php if (!empty($permissions['can_view_reports'])): ?>
            <li><a href="team-admin-analysis.php"><i class="fas fa-chart-line"></i> Analysis</a></li>
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
            <h1 class="page-title">Team Admin Dashboard</h1>
            <p class="page-subtitle">
                Managing <?php echo count($admin_teams); ?> team(s) - 
                <?php echo implode(', ', array_column($admin_teams, 'name')); ?>
            </p>
            
            <!-- Permission Badges -->
            <div style="margin-top: 1rem;">
                <span class="permission-badge <?php echo !empty($permissions['can_assign']) ? 'enabled' : 'disabled'; ?>">
                    <i class="fas fa-plus"></i> Can Assign
                </span>
                <span class="permission-badge <?php echo !empty($permissions['can_edit']) ? 'enabled' : 'disabled'; ?>">
                    <i class="fas fa-edit"></i> Can Edit
                </span>
                <span class="permission-badge <?php echo !empty($permissions['can_archive']) ? 'enabled' : 'disabled'; ?>">
                    <i class="fas fa-archive"></i> Can Archive
                </span>
                <span class="permission-badge <?php echo !empty($permissions['can_add_members']) ? 'enabled' : 'disabled'; ?>">
                    <i class="fas fa-user-plus"></i> Add Members
                </span>
                <span class="permission-badge <?php echo !empty($permissions['can_view_reports']) ? 'enabled' : 'disabled'; ?>">
                    <i class="fas fa-chart-bar"></i> View Reports
                </span>
                <span class="permission-badge <?php echo !empty($permissions['can_send_messages']) ? 'enabled' : 'disabled'; ?>">
                    <i class="fas fa-envelope"></i> Send Messages
                </span>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['team_members'] ?? 0; ?></div>
                        <div class="stat-label">Team Members</div>
                    </div>
                    <div class="stat-icon members">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['active_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Active Tasks</div>
                    </div>
                    <div class="stat-icon tasks">
                        <i class="fas fa-tasks"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['completed_tasks'] ?? 0; ?></div>
                        <div class="stat-label">Completed Tasks</div>
                    </div>
                    <div class="stat-icon completed">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['pending_approval'] ?? 0; ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                    <div class="stat-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Recent Team Activity -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-clock"></i>
                        Recent Team Activity
                    </h2>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_activities)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-clock" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No recent activity from your team members.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($recent_activities, 0, 3) as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <?php
                                switch ($activity['type']) {
                                    case 'task_completed':
                                    case 'task_complete':
                                        echo '<i class="fas fa-check"></i>';
                                        break;
                                    case 'status_change':
                                    case 'status_update':
                                        echo '<i class="fas fa-sync"></i>';
                                        break;
                                    default:
                                        echo '<i class="fas fa-bell"></i>';
                                }
                                ?>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    <?php
                                    $user_role_badge = "<span style='color: var(--info); font-size: 0.7rem; font-weight: bold; text-transform: uppercase;'>[EMP]</span>";
                                    
                                    switch ($activity['type']) {
                                        case 'task_completed':
                                        case 'task_complete':
                                            echo "{$user_role_badge} {$activity['user']} <span style='color: var(--success); font-weight: bold;'>completed</span> task";
                                            break;
                                        case 'status_change':
                                        case 'status_update':
                                            echo "{$user_role_badge} {$activity['user']} <span style='color: var(--warning); font-weight: bold;'>updated</span> task status";
                                            break;
                                        default:
                                            echo "{$user_role_badge} Activity by {$activity['user']}";
                                            break;
                                    }
                                    ?>
                                </div>
                                <div class="activity-detail">
                                    <strong style="color: var(--text-main);">📋 <?php echo htmlspecialchars($activity['task']); ?></strong>
                                    <?php if (isset($activity['old_status']) && isset($activity['new_status']) && in_array($activity['type'], ['status_change', 'status_update'])): ?>
                                        <br><small>Status changed from <span style="color: var(--warning); font-weight: bold;">"<?php echo htmlspecialchars($activity['old_status']); ?>"</span> 
                                        <span style="color: var(--text-secondary);">to</span> 
                                        <span style="color: var(--success); font-weight: bold;">"<?php echo htmlspecialchars($activity['new_status']); ?>"</span></small>
                                    <?php endif; ?>
                                    <?php if (isset($activity['details']) && !empty($activity['details']) && !in_array($activity['type'], ['status_change', 'status_update'])): ?>
                                        <br><small><?php echo htmlspecialchars($activity['details']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-time"><?php echo $activity['time']; ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($recent_activities) > 3): ?>
                            <div class="view-more-container">
                                <button class="btn-view-more" onclick="showAllTeamActivities()">
                                    <i class="fas fa-chevron-down"></i>
                                    View More Activities (<?php echo count($recent_activities) - 3; ?> more)
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Team Members Overview -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-users"></i>
                        Your Team Members
                    </h2>
                </div>
                <div class="card-body">
                    <?php if (empty($team_members)): ?>
                        <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>No team members assigned yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($team_members as $member): ?>
                        <div class="member-card">
                            <div class="member-avatar">
                                <?php 
                                $names = explode(' ', $member['full_name']);
                                echo strtoupper(substr($names[0], 0, 1));
                                if (isset($names[1])) echo strtoupper(substr($names[1], 0, 1));
                                ?>
                            </div>
                            <div class="member-info">
                                <h4><?php echo htmlspecialchars($member['full_name']); ?></h4>
                                <p><?php echo htmlspecialchars($member['email']); ?></p>
                            </div>
                            <div class="member-tasks">
                                <?php echo $member['active_tasks']; ?> tasks
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <a href="team-admin-team.php" class="btn btn-secondary" style="width: 100%; justify-content: center; margin-top: 1rem;">
                            <i class="fas fa-users"></i>
                            View All Team Members
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <?php if (!empty($permissions['can_assign'])): ?>
            <a href="team-admin-add-task.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                Add New Task
            </a>
            <?php endif; ?>
            
            <a href="team-admin-tasks.php" class="btn btn-secondary">
                <i class="fas fa-tasks"></i>
                Manage Tasks
            </a>
            
            <?php if (!empty($permissions['can_send_messages'])): ?>
            <a href="team-admin-messages.php" class="btn btn-secondary">
                <i class="fas fa-envelope"></i>
                Send Message
            </a>
            <?php endif; ?>
            
            <a href="team-admin-team.php" class="btn btn-secondary">
                <i class="fas fa-users"></i>
                View Team
            </a>
        </div>
    </div>

    <!-- Team Activity Modal -->
    <div id="teamActivityModalOverlay" class="activity-modal-overlay">
        <div class="activity-modal">
            <div class="activity-modal-header">
                <h3 class="activity-modal-title">
                    <i class="fas fa-history"></i>
                    All Team Activities
                </h3>
                <button class="activity-modal-close" onclick="closeAllTeamActivities()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="activity-modal-body" id="allTeamActivitiesContainer">
                <!-- All team activities will be loaded here -->
                <?php foreach ($recent_activities as $index => $activity): ?>
                <div class="modal-activity-item activity-<?php echo $activity['type']; ?>" style="animation-delay: <?php echo ($index * 0.1); ?>s;">
                    <div class="activity-icon">
                        <?php
                        switch ($activity['type']) {
                            case 'task_completed':
                            case 'task_complete':
                                echo '<i class="fas fa-check"></i>';
                                break;
                            case 'status_change':
                            case 'status_update':
                                echo '<i class="fas fa-sync"></i>';
                                break;
                            case 'task_created':
                                echo '<i class="fas fa-plus"></i>';
                                break;
                            case 'task_assigned':
                                echo '<i class="fas fa-user-plus"></i>';
                                break;
                            default:
                                echo '<i class="fas fa-bell"></i>';
                        }
                        ?>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">
                            <?php
                            $user_role_badge = "<span style='color: var(--info); font-size: 0.7rem; font-weight: bold; text-transform: uppercase;'>[EMP]</span> ";
                            
                            switch ($activity['type']) {
                                case 'task_completed':
                                case 'task_complete':
                                    echo "{$user_role_badge}{$activity['user']} <span style='color: var(--success); font-weight: bold;'>completed</span> task";
                                    break;
                                case 'status_change':
                                case 'status_update':
                                    echo "{$user_role_badge}{$activity['user']} <span style='color: var(--warning); font-weight: bold;'>updated</span> task status";
                                    break;
                                case 'task_created':
                                    echo "{$user_role_badge}{$activity['user']} <span style='color: var(--primary); font-weight: bold;'>created</span> a new task";
                                    break;
                                case 'task_assigned':
                                    echo "Task <span style='color: var(--info); font-weight: bold;'>assigned</span> to {$user_role_badge}{$activity['user']}";
                                    break;
                                default:
                                    echo "{$user_role_badge}Activity by {$activity['user']}";
                                    break;
                            }
                            ?>
                        </div>
                        <div class="activity-detail">
                            <strong style="color: var(--text-main);">📋 <?php echo htmlspecialchars($activity['task']); ?></strong>
                            <?php if (isset($activity['old_status']) && isset($activity['new_status']) && in_array($activity['type'], ['status_change', 'status_update'])): ?>
                                <br><small>Status changed from <span style="color: var(--warning); font-weight: bold;">"<?php echo htmlspecialchars($activity['old_status']); ?>"</span> 
                                <span style="color: var(--text-secondary);">to</span> 
                                <span style="color: var(--success); font-weight: bold;">"<?php echo htmlspecialchars($activity['new_status']); ?>"</span></small>
                            <?php endif; ?>
                            <?php if (isset($activity['details']) && !empty($activity['details']) && !in_array($activity['type'], ['status_change', 'status_update'])): ?>
                                <br><small><?php echo htmlspecialchars($activity['details']); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="activity-time"><?php echo $activity['time']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <style>
        /* View More Button Styles */
        .view-more-container {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-view-more {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 123, 255, 0.3);
        }
        
        .btn-view-more:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 123, 255, 0.4);
        }
        
        .btn-view-more i {
            transition: transform 0.3s ease;
        }
        
        .btn-view-more:hover i {
            transform: translateY(2px);
        }
        
        /* Activity Modal Styles */
        .activity-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 9999;
            display: none;
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        
        .activity-modal-overlay.show {
            opacity: 1;
        }
        
        .activity-modal {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background-color: var(--bg-main);
            border-radius: 1rem;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            box-shadow: 0 2rem 4rem rgba(0, 0, 0, 0.3);
            overflow: hidden;
            transition: transform 0.4s ease;
        }
        
        .activity-modal-overlay.show .activity-modal {
            transform: translate(-50%, -50%) scale(1);
        }
        
        .activity-modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-modal-title {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .activity-modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 0.5rem;
            border-radius: 50%;
            width: 2.5rem;
            height: 2.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .activity-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        .activity-modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
            background-color: var(--bg-main);
        }
        
        .modal-activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: var(--bg-secondary);
            border-radius: 0.75rem;
            transition: all 0.3s ease;
            animation: slideInUp 0.5s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        .modal-activity-item:hover {
            background-color: var(--bg-card);
            transform: translateX(5px);
        }
        
        @keyframes slideInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-activity-item .activity-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .modal-activity-item.activity-task_completed .activity-icon,
        .modal-activity-item.activity-task_complete .activity-icon {
            background: linear-gradient(135deg, var(--success), #20c997);
        }
        
        .modal-activity-item.activity-status_change .activity-icon,
        .modal-activity-item.activity-status_update .activity-icon {
            background: linear-gradient(135deg, var(--warning), #f39c12);
        }
        
        .modal-activity-item.activity-task_created .activity-icon {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }
        
        .modal-activity-item.activity-task_assigned .activity-icon {
            background: linear-gradient(135deg, var(--info), #17a2b8);
        }
        
        .modal-activity-item .activity-icon:not([class*="activity-"]) {
            background: linear-gradient(135deg, var(--secondary), #6c757d);
        }
        
        .modal-activity-item .activity-content {
            flex: 1;
        }
        
        .modal-activity-item .activity-title {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        
        .modal-activity-item .activity-detail {
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        
        .modal-activity-item .activity-time {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        /* Page blur effect */
        .page-blur {
            filter: blur(5px);
            transition: filter 0.4s ease;
        }
        
        /* Scrollbar styling for modal */
        .activity-modal-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .activity-modal-body::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 3px;
        }
        
        .activity-modal-body::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 3px;
        }
        
        .activity-modal-body::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .activity-modal {
                width: 95%;
                max-height: 90vh;
            }
            
            .activity-modal-header {
                padding: 1rem;
            }
            
            .activity-modal-title {
                font-size: 1.1rem;
            }
            
            .modal-activity-item {
                padding: 0.75rem;
                gap: 0.75rem;
            }
            
            .modal-activity-item .activity-icon {
                width: 2rem;
                height: 2rem;
                font-size: 0.9rem;
            }
        }
    </style>

    <script>
        // Team Activity Modal Functions
        function showAllTeamActivities() {
            const overlay = document.getElementById('teamActivityModalOverlay');
            const mainContent = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar');
            
            // Add blur effect to the background
            if (mainContent) {
                mainContent.classList.add('page-blur');
            }
            if (sidebar) {
                sidebar.classList.add('page-blur');
            }
            
            // Show the modal with animation
            overlay.style.display = 'block';
            setTimeout(() => {
                overlay.classList.add('show');
            }, 10);
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
            
            // Close modal when clicking on overlay (not on modal content)
            overlay.onclick = function(event) {
                if (event.target === overlay) {
                    closeAllTeamActivities();
                }
            };
        }

        function closeAllTeamActivities() {
            const overlay = document.getElementById('teamActivityModalOverlay');
            const mainContent = document.querySelector('.main-content');
            const sidebar = document.querySelector('.sidebar');
            
            // Remove blur effect
            if (mainContent) {
                mainContent.classList.remove('page-blur');
            }
            if (sidebar) {
                sidebar.classList.remove('page-blur');
            }
            
            // Hide the modal with animation
            overlay.classList.remove('show');
            
            // Hide the overlay after animation completes
            setTimeout(() => {
                overlay.style.display = 'none';
            }, 400);
            
            // Restore body scroll
            document.body.style.overflow = 'auto';
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAllTeamActivities();
            }
        });
    </script>
</body>
</html>
