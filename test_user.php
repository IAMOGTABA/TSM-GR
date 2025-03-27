<?php
// test_user.php
require 'config.php';

// Test finding a user
$testEmail = 'admin@example.com'; // Replace with an actual email to test

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
$stmt->execute(['email' => $testEmail]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h2>Test User Lookup for: {$testEmail}</h2>";
if ($user) {
    echo "<p>User found! Details:</p>";
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    // Test password verification
    echo "<h3>Password Verification Test</h3>";
    echo "<p>This will test both hashed and plain password verification.</p>";
    
    $testPassword = 'admin123'; // Replace with the password you think should work
    $hashedResult = password_verify($testPassword, $user['password']);
    $plainResult = ($testPassword === $user['password']);
    
    echo "<p>Test password: {$testPassword}</p>";
    echo "<p>Stored password: {$user['password']}</p>";
    echo "<p>password_verify() result: " . ($hashedResult ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p>Plain comparison result: " . ($plainResult ? 'TRUE' : 'FALSE') . "</p>";
    
} else {
    echo "<p>No user found with that email.</p>";
}
?> 