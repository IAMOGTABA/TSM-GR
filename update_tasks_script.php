<?php
// Script to update task status from 'completed' to 'done'
require 'config.php';

try {
    // Update tasks table
    $stmt = $pdo->prepare("UPDATE tasks SET status = 'done' WHERE status = 'completed'");
    $stmt->execute();
    $taskCount = $stmt->rowCount();
    
    echo "Updated $taskCount tasks from 'completed' to 'done' status.\n";
    
    // Ensure success message is shown in browser
    if (php_sapi_name() !== 'cli') {
        echo "<p>Database update successful! <a href='my-tasks.php'>Return to My Tasks</a></p>";
    }
    
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
}
?> 