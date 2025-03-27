<?php
require 'config.php';

try {
    // Drop table if it exists (for clean initialization)
    $pdo->exec("DROP TABLE IF EXISTS messages");
    
    // Create messages table
    $sql = "CREATE TABLE messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        sent_at DATETIME NOT NULL,
        read_status ENUM('read', 'unread') DEFAULT 'unread',
        FOREIGN KEY (sender_id) REFERENCES users(id),
        FOREIGN KEY (recipient_id) REFERENCES users(id)
    )";
    
    $pdo->exec($sql);
    echo "Messages table created successfully!";
    
} catch (PDOException $e) {
    die("Error creating messages table: " . $e->getMessage());
}
?> 