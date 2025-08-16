<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Check for success message in session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying it
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Don't allow admin to delete themselves
    if ($id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Check if user has assigned tasks or created tasks
            $check_assigned = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = :id");
            $check_assigned->execute(['id' => $id]);
            $has_assigned_tasks = $check_assigned->fetchColumn() > 0;
            
            $check_created = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE created_by = :id");
            $check_created->execute(['id' => $id]);
            $has_created_tasks = $check_created->fetchColumn() > 0;
            
            // Handle assigned tasks
            if ($has_assigned_tasks) {
                $update_assigned = $pdo->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = :id");
                $update_assigned->execute(['id' => $id]);
            }
            
            // Handle created tasks
            if ($has_created_tasks) {
                // Option 1: Set created_by to NULL
                $update_created = $pdo->prepare("UPDATE tasks SET created_by = NULL WHERE created_by = :id");
                $update_created->execute(['id' => $id]);
                
                // Alternative Option 2: Transfer ownership to current admin
                // $update_created = $pdo->prepare("UPDATE tasks SET created_by = :admin_id WHERE created_by = :id");
                // $update_created->execute(['admin_id' => $_SESSION['user_id'], 'id' => $id]);
            }
            
            // Now delete the user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            
            // Commit transaction
            $pdo->commit();
            
            $message = "User deleted successfully.";
            if ($has_assigned_tasks || $has_created_tasks) {
                $message .= " All related tasks have been updated.";
            }
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $error = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY id");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
            color: var(--text-main);
        }
        
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background-color: var(--bg-secondary);
            color: var(--primary-light);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.05rem;
        }
        
        tbody tr {
            transition: background-color 0.2s;
        }
        
        tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 0.35rem;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-add {
            background-color: var(--success);
            padding: 0.75rem 1.25rem;
            margin-bottom: 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-add:hover {
            background-color: #19b67d;
        }
        
        .btn-edit {
            background-color: var(--info);
        }
        
        .btn-edit:hover {
            background-color: #2a9fbd;
        }
        
        .btn-delete {
            background-color: var(--danger);
        }
        
        .btn-delete:hover {
            background-color: #d93426;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .user-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success);
        }
        
        .status-inactive {
            background-color: rgba(133, 135, 150, 0.1);
            color: var(--secondary);
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .role-admin {
            background-color: rgba(106, 13, 173, 0.1);
            color: var(--primary-light);
        }
        
        .role-employee {
            background-color: rgba(54, 185, 204, 0.1);
            color: var(--info);
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
            
            .table-responsive {
                overflow-x: auto;
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
            <li><a href="admin-dashboard.php" class="page-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-tasks.php" class="page-link"><i class="fas fa-tasks"></i> Manage Tasks</a></li>
            <li><a href="add-task.php" class="page-link"><i class="fas fa-plus-circle"></i> Add Task</a></li>
            <li><a href="manage-users.php" class="active page-link"><i class="fas fa-users"></i> Manage Users</a></li>
            <li><a href="analysis.php" class="page-link"><i class="fas fa-chart-line"></i> Analysis</a></li>
            <li><a href="messages.php" class="page-link"><i class="fas fa-envelope"></i> Messages</a></li>
        </ul>
        <div class="sidebar-heading">Account</div>
        <ul class="sidebar-menu">
            <li><a href="logout.php" class="page-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="content">
        <div class="header">
            <h1 class="page-title">Manage Users</h1>
            <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-users"></i> User Management</h2>
                <a href="add-user.php" class="btn btn-add page-link"><i class="fas fa-user-plus"></i> Add New User</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo strtolower($user['role']); ?>">
                                        <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="user-status status-<?php echo strtolower($user['status']); ?>">
                                        <?php echo ucfirst(htmlspecialchars($user['status'])); ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="edit-user.php?id=<?php echo $user['id']; ?>" class="btn btn-edit page-link"><i class="fas fa-edit"></i> Edit</a>
                                    <a href="manage-users.php?action=delete&id=<?php echo $user['id']; ?>" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete this user?');"><i class="fas fa-trash"></i> Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


</body>
</html>
