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

// Get team members assigned to this team admin
$members_stmt = $pdo->prepare("
    SELECT u.*, 
           t.name as team_name,
           (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.id AND archived = 0) as total_tasks,
           (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.id AND status = 'completed' AND archived = 0) as completed_tasks,
           (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.id AND status = 'in_progress' AND archived = 0) as in_progress_tasks,
           (SELECT COUNT(*) FROM tasks WHERE assigned_to = u.id AND status = 'needs_approval' AND archived = 0) as needs_approval_tasks
    FROM users u
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN teams t ON u.team_id = t.id
    WHERE tat.team_admin_id = ? AND u.role = 'employee' AND u.status = 'active'
    ORDER BY u.full_name
");
$members_stmt->execute([$_SESSION['user_id']]);
$team_members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get team statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT u.id) as total_members,
        COUNT(DISTINCT CASE WHEN t.archived = 0 THEN t.id END) as total_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'completed' AND t.archived = 0 THEN t.id END) as completed_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'in_progress' AND t.archived = 0 THEN t.id END) as in_progress_tasks,
        COUNT(DISTINCT CASE WHEN t.status = 'needs_approval' AND t.archived = 0 THEN t.id END) as needs_approval_tasks
    FROM users u
    JOIN team_admin_teams tat ON u.team_id = tat.team_id
    LEFT JOIN tasks t ON t.assigned_to = u.id
    WHERE tat.team_admin_id = ? AND u.role = 'employee' AND u.status = 'active'
");
$stats_stmt->execute([$_SESSION['user_id']]);
$team_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Team - Team Admin</title>
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

        .team-overview {
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
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.members {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        .stat-icon.tasks {
            background: linear-gradient(135deg, var(--info) 0%, #2980b9 100%);
        }

        .stat-icon.completed {
            background: linear-gradient(135deg, var(--success) 0%, #27ae60 100%);
        }

        .stat-icon.progress {
            background: linear-gradient(135deg, var(--warning) 0%, #f39c12 100%);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .team-members {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 1.5rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .member-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }

        .member-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .member-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .member-info h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }

        .member-info p {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .member-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .member-stat {
            text-align: center;
        }

        .member-stat-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .member-stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .member-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
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

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--bg-secondary);
            border-radius: 12px;
            margin: 2rem 0;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .team-overview {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .members-grid {
                grid-template-columns: 1fr;
            }
            
            .member-stats {
                grid-template-columns: repeat(2, 1fr);
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
            <li><a href="team-admin-team.php" class="active"><i class="fas fa-users"></i> My Team</a></li>
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
            <h1 class="page-title">My Team</h1>
            <p class="page-subtitle">
                <?php if ($team_info): ?>
                    Manage and overview your team: <strong><?php echo htmlspecialchars($team_info['team_name']); ?></strong>
                <?php else: ?>
                    Manage and overview your team members
                <?php endif; ?>
            </p>
        </div>

        <!-- Team Overview Stats -->
        <div class="team-overview">
            <div class="stat-card">
                <div class="stat-icon members">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $team_stats['total_members'] ?: 0; ?></div>
                <div class="stat-label">Team Members</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon tasks">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-value"><?php echo $team_stats['total_tasks'] ?: 0; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $team_stats['completed_tasks'] ?: 0; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon progress">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo ($team_stats['in_progress_tasks'] + $team_stats['needs_approval_tasks']) ?: 0; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>

        <!-- Team Members -->
        <div class="team-members">
            <h2 class="section-title">
                <i class="fas fa-users"></i>
                Team Members
            </h2>

            <?php if (empty($team_members)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>No Team Members</h3>
                    <p>You don't have any team members assigned yet. Contact your administrator to add members to your team.</p>
                </div>
            <?php else: ?>
                <div class="members-grid">
                    <?php foreach ($team_members as $member): ?>
                        <div class="member-card">
                            <div class="member-header">
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
                                    <?php if ($member['team_name']): ?>
                                        <p style="color: var(--primary); font-weight: 500;">
                                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($member['team_name']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="member-stats">
                                <div class="member-stat">
                                    <div class="member-stat-value"><?php echo $member['total_tasks']; ?></div>
                                    <div class="member-stat-label">Total</div>
                                </div>
                                <div class="member-stat">
                                    <div class="member-stat-value"><?php echo $member['completed_tasks']; ?></div>
                                    <div class="member-stat-label">Done</div>
                                </div>
                                <div class="member-stat">
                                    <div class="member-stat-value"><?php echo $member['in_progress_tasks']; ?></div>
                                    <div class="member-stat-label">Active</div>
                                </div>
                                <div class="member-stat">
                                    <div class="member-stat-value"><?php echo $member['needs_approval_tasks']; ?></div>
                                    <div class="member-stat-label">Pending</div>
                                </div>
                            </div>

                            <div class="member-actions">
                                <a href="team-admin-tasks.php?member=<?php echo $member['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-tasks"></i>
                                    View Tasks
                                </a>
                                <?php if (!empty($permissions['can_assign'])): ?>
                                    <a href="team-admin-add-task.php?assign_to=<?php echo $member['id']; ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-plus"></i>
                                        Assign Task
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
