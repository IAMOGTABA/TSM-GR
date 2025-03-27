<?php
// create_tables.php - Script to create necessary database tables
require 'config.php';

try {
    // Create tasks table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            status ENUM('to_do', 'in_progress', 'completed') DEFAULT 'to_do',
            priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
            deadline DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            assigned_to INT,
            created_by INT,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    
    // Create subtasks table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subtasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            status ENUM('to_do', 'in_progress', 'done') DEFAULT 'to_do',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )
    ");
    
    echo "<h2>Database tables created successfully!</h2>";
    echo "<p>Created:</p>";
    echo "<ul>";
    echo "<li>tasks - For storing main tasks</li>";
    echo "<li>subtasks - For storing subtasks/checklist items</li>";
    echo "</ul>";
    echo "<p><a href='admin-dashboard.php'>Go to Dashboard</a></p>";
    
} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}
?> 