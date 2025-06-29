<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: login.php');
    exit;
}
require 'config.php';

// Get the logged-in employee's ID
$user_id = $_SESSION['user_id'];

// Fetch tasks assigned to this employee
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS assigned_user
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.assigned_to = :user_id
");
$stmt->execute(['user_id' => $user_id]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>My Tasks</title>
</head>
<body>
    <h1>My Tasks (Employee View)</h1>
    <table border="1">
        <tr>
            <th>Title</th>
            <th>Status</th>
            <th>Deadline</th>
            <th>Subtasks</th>
        </tr>
        <?php foreach($tasks as $task): ?>
        <tr>
            <td><?php echo $task['title']; ?></td>
            <td><?php echo $task['status']; ?></td>
            <td><?php echo $task['due_date']; ?></td>
            <td>
                <!-- Link to view and manage this task's subtasks -->
                <a href="view_task.php?task_id=<?php echo $task['id']; ?>">
                    View / Manage Subtasks
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <!-- Optionally, a link to employee-dashboard or logout -->
    <p>
        <a href="employee-dashboard.php">Back to Dashboard</a> |
        <a href="logout.php">Logout</a>
    </p>
</body>
</html>
