<?php
require 'config.php';

try {
    // Query to get the structure of the users table
    $stmt = $pdo->prepare("DESCRIBE users");
    $stmt->execute();
    $tableStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Users Table Structure:</h2>";
    echo "<pre>";
    print_r($tableStructure);
    echo "</pre>";
    
    // Query to get a sample of users to check roles
    $stmt = $pdo->prepare("SELECT id, email, role, status FROM users LIMIT 5");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Sample Users:</h2>";
    echo "<pre>";
    print_r($users);
    echo "</pre>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 