<?php
// create_admin.php
require 'config.php';

// Create a new admin user
$email = 'admin@test.com';
$password = 'admin123';
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$fullName = 'Admin User';
$role = 'admin';
$status = 'active';

try {
    // Check if user already exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $checkStmt->execute(['email' => $email]);
    $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        echo "<p>User with email {$email} already exists.</p>";
    } else {
        // Insert new user
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, full_name, role, status) 
            VALUES (:email, :password, :full_name, :role, :status)
        ");
        
        $result = $stmt->execute([
            'email' => $email,
            'password' => $hashedPassword,
            'full_name' => $fullName,
            'role' => $role,
            'status' => $status
        ]);
        
        if ($result) {
            echo "<p>Admin user created successfully!</p>";
            echo "<p>Email: {$email}</p>";
            echo "<p>Password: {$password}</p>";
        } else {
            echo "<p>Failed to create user.</p>";
        }
    }
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?> 