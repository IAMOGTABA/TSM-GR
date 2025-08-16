<?php
// config.php

// Database credentials
$host = 'localhost';
$db   = 'task_management';
$user = 'root';      // default XAMPP user is 'root'
$pass = '';          // default XAMPP password is usually empty

// Create a new PDO connection (recommended) or MySQLi
try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);

    // Set Error Mode to Exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
