<?php
require_once 'config.php';

echo "=== Current Database Structure Check ===\n\n";

try {
    // Check users table structure
    echo "Users table structure:\n";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']} | Null: {$column['Null']} | Key: {$column['Key']} | Default: {$column['Default']}\n";
    }
    
    echo "\nTasks table structure:\n";
    $stmt = $pdo->query("DESCRIBE tasks");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']} | Null: {$column['Null']} | Key: {$column['Key']} | Default: {$column['Default']}\n";
    }
    
    echo "\nExisting tables:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "- $table\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
