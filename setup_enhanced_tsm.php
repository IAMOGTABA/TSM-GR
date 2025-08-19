<?php
/**
 * Enhanced TSM Setup Script
 * Sets up team functionality with better error handling
 */

require_once 'config.php';

function executeSQL($pdo, $sql, $description = '') {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        echo "✓ " . ($description ?: "SQL executed successfully") . "\n";
        return true;
    } catch (PDOException $e) {
        // Check for common "already exists" errors
        if (strpos($e->getMessage(), 'already exists') !== false || 
            strpos($e->getMessage(), 'Duplicate') !== false ||
            strpos($e->getMessage(), 'duplicate') !== false) {
            echo "⚠ " . ($description ?: "SQL") . " - already exists, skipping\n";
            return true;
        } else {
            echo "✗ Error in " . ($description ?: "SQL") . ": " . $e->getMessage() . "\n";
            return false;
        }
    }
}

echo "=== TSM Enhanced Team Functionality Setup ===\n\n";

// Step 1: Create new tables
echo "Step 1: Creating new tables...\n";

$tables = [
    [
        'sql' => "CREATE TABLE IF NOT EXISTS team_admin_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            team_id INT NOT NULL,
            can_assign BOOLEAN DEFAULT TRUE,
            can_edit BOOLEAN DEFAULT TRUE,
            can_archive BOOLEAN DEFAULT TRUE,
            can_add_members BOOLEAN DEFAULT TRUE,
            can_view_reports BOOLEAN DEFAULT TRUE,
            can_send_messages BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_team (user_id, team_id)
        )",
        'desc' => 'team_admin_permissions table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type ENUM('task_assigned', 'task_completed', 'task_overdue', 'message_received', 'team_update', 'kudos_received') NOT NULL,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            related_id INT NULL,
            related_type ENUM('task', 'message', 'user', 'team') NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'desc' => 'notifications table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS task_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            created_by INT NOT NULL,
            team_id INT NULL,
            priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
            estimated_hours DECIMAL(5,2) NULL,
            is_public BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
        )",
        'desc' => 'task_templates table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS template_subtasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            order_index INT DEFAULT 0,
            FOREIGN KEY (template_id) REFERENCES task_templates(id) ON DELETE CASCADE
        )",
        'desc' => 'template_subtasks table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS kudos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            giver_id INT NOT NULL,
            receiver_id INT NOT NULL,
            task_id INT NULL,
            message TEXT NOT NULL,
            points INT DEFAULT 1,
            type ENUM('excellent_work', 'helpful', 'creative', 'leadership', 'teamwork', 'problem_solving') DEFAULT 'excellent_work',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (giver_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
        )",
        'desc' => 'kudos table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS delegations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delegator_id INT NOT NULL,
            delegate_id INT NOT NULL,
            task_id INT NOT NULL,
            reason TEXT,
            status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
            delegated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL,
            FOREIGN KEY (delegator_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (delegate_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )",
        'desc' => 'delegations table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS team_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            team_id INT NOT NULL,
            subject VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            priority ENUM('normal', 'high', 'urgent') DEFAULT 'normal',
            is_announcement BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        )",
        'desc' => 'team_messages table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS team_message_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (message_id) REFERENCES team_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_message_user (message_id, user_id)
        )",
        'desc' => 'team_message_reads table'
    ],
    [
        'sql' => "CREATE TABLE IF NOT EXISTS task_time_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NULL,
            duration_minutes INT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'desc' => 'task_time_logs table'
    ]
];

foreach ($tables as $table) {
    executeSQL($pdo, $table['sql'], $table['desc']);
}

// Step 2: Add columns to existing tables
echo "\nStep 2: Adding columns to existing tables...\n";

// Check if team_id exists in tasks table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'team_id'");
    if ($stmt->rowCount() == 0) {
        executeSQL($pdo, "ALTER TABLE tasks ADD COLUMN team_id INT NULL", "Add team_id to tasks");
        executeSQL($pdo, "ALTER TABLE tasks ADD FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL", "Add foreign key for tasks.team_id");
    } else {
        echo "⚠ tasks.team_id column already exists\n";
    }
} catch (PDOException $e) {
    echo "✗ Error checking tasks.team_id: " . $e->getMessage() . "\n";
}

// Check if team_id exists in activity_logs table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM activity_logs LIKE 'team_id'");
    if ($stmt->rowCount() == 0) {
        executeSQL($pdo, "ALTER TABLE activity_logs ADD COLUMN team_id INT NULL", "Add team_id to activity_logs");
        executeSQL($pdo, "ALTER TABLE activity_logs ADD FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL", "Add foreign key for activity_logs.team_id");
    } else {
        echo "⚠ activity_logs.team_id column already exists\n";
    }
} catch (PDOException $e) {
    echo "✗ Error checking activity_logs.team_id: " . $e->getMessage() . "\n";
}

// Rename manager_id to parent_admin_id if needed
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'manager_id'");
    if ($stmt->rowCount() > 0) {
        executeSQL($pdo, "ALTER TABLE users CHANGE COLUMN manager_id parent_admin_id INT NULL", "Rename manager_id to parent_admin_id");
    } else {
        echo "⚠ users.manager_id column doesn't exist or already renamed\n";
    }
} catch (PDOException $e) {
    echo "✗ Error renaming manager_id: " . $e->getMessage() . "\n";
}

// Step 3: Create indexes
echo "\nStep 3: Creating performance indexes...\n";

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_users_team_id ON users(team_id)",
    "CREATE INDEX IF NOT EXISTS idx_tasks_team_id ON tasks(team_id)",
    "CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read)",
    "CREATE INDEX IF NOT EXISTS idx_task_time_logs_task_user ON task_time_logs(task_id, user_id)",
    "CREATE INDEX IF NOT EXISTS idx_team_messages_team_id ON team_messages(team_id)",
    "CREATE INDEX IF NOT EXISTS idx_activity_logs_team_id ON activity_logs(team_id)"
];

foreach ($indexes as $index) {
    executeSQL($pdo, $index, "Creating index");
}

echo "\n=== Setup Summary ===\n";
echo "✅ Database schema setup completed!\n";
echo "\nNext steps:\n";
echo "1. Run seed data: php seed_team_data.php\n";
echo "2. Verify setup: php verify_database.php\n";
echo "3. Update application files to use new team features\n";
?>
