<?php
// login_debug.php

require 'config.php'; // Include the database connection

// Query to check user roles in the database
$stmt = $pdo->prepare("SELECT id, email, role, status FROM users");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>User Accounts</h1>";
echo "<table border='1'>
    <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
    </tr>";

foreach ($users as $user) {
    echo "<tr>
        <td>{$user['id']}</td>
        <td>{$user['email']}</td>
        <td>{$user['role']}</td>
        <td>{$user['status']}</td>
    </tr>";
}
echo "</table>";

// Display the session debug info if session is active
echo "<h1>Current Session Information</h1>";
session_start();
if (isset($_SESSION['user_id'])) {
    echo "<p>User ID: {$_SESSION['user_id']}</p>";
    echo "<p>Role: {$_SESSION['role']}</p>";
    echo "<p>Name: {$_SESSION['full_name']}</p>";
} else {
    echo "<p>No active session</p>";
}
?> 