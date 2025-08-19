<?php
/**
 * Database verification script for TSM
 * Checks current database structure and data
 */

require_once 'config.php';

echo "=== TSM Database Verification ===\n\n";

try {
    // Check existing tables
    echo "1. Current Tables:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "   - $table\n";
    }
    
    echo "\n2. Users Table Structure:\n";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo "   - {$column['Field']} ({$column['Type']}) {$column['Null']} {$column['Key']}\n";
    }
    
    echo "\n3. Current Users:\n";
    $stmt = $pdo->query("SELECT id, full_name, email, role, status FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        echo "   - ID: {$user['id']}, Name: {$user['full_name']}, Email: {$user['email']}, Role: {$user['role']}, Status: {$user['status']}\n";
    }
    
    echo "\n4. Tasks Table Structure:\n";
    $stmt = $pdo->query("DESCRIBE tasks");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo "   - {$column['Field']} ({$column['Type']}) {$column['Null']} {$column['Key']}\n";
    }
    
    echo "\n5. Current Tasks Count:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tasks");
    $task_count = $stmt->fetchColumn();
    echo "   Total tasks: $task_count\n";
    
    // Check if team-related tables exist
    echo "\n6. Team-Related Tables Check:\n";
    $team_tables = [
        'teams',
        'team_admin_permissions',
        'notifications',
        'task_templates',
        'kudos',
        'delegations',
        'team_messages',
        'task_time_logs'
    ];
    
    foreach ($team_tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
            $count = $stmt->fetchColumn();
            echo "   ✓ $table exists ($count records)\n";
        } catch (PDOException $e) {
            echo "   ✗ $table does not exist\n";
        }
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}

echo "\n=== Verification Complete ===\n";
?>
