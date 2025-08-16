<?php
// index.php - Entry point for TSM-GR
session_start();

// Function to check if the system is set up
function isSystemSetup() {
    try {
        // Try to connect to the database
        $pdo = new PDO("mysql:host=localhost;dbname=task_management;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if users table exists and has at least one admin user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $stmt->execute();
        $adminCount = $stmt->fetchColumn();
        
        return $adminCount > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Check if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Redirect to appropriate dashboard based on role
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin-dashboard.php');
    } else {
        header('Location: employee-dashboard.php');
    }
    exit;
}

// Check if system is set up
if (!isSystemSetup()) {
    // Redirect to setup page
    header('Location: setup.php');
    exit;
}

// System is set up, redirect to login
header('Location: login.php');
exit;
?>
