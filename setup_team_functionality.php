<?php
/**
 * Setup script to implement team functionality in TSM
 * This script will execute the database schema updates
 */

require_once 'config.php';

try {
    echo "Starting TSM Team Functionality Setup...\n";
    
    // Read the SQL file
    $sql_file = 'database_schema_update.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: $sql_file");
    }
    
    $sql_content = file_get_contents($sql_file);
    if ($sql_content === false) {
        throw new Exception("Failed to read SQL file");
    }
    
    // Split the SQL content into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql_content)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    echo "Found " . count($statements) . " SQL statements to execute.\n";
    
    // Execute each statement
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $index => $statement) {
        try {
            // Skip comments and empty statements
            if (empty(trim($statement)) || preg_match('/^\s*--/', $statement)) {
                continue;
            }
            
            $stmt = $pdo->prepare($statement);
            $stmt->execute();
            $executed++;
            
            echo "✓ Statement " . ($index + 1) . " executed successfully\n";
            
        } catch (PDOException $e) {
            // Check if it's a "table already exists" or "column already exists" error
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false) {
                echo "⚠ Statement " . ($index + 1) . " skipped (already exists): " . substr($statement, 0, 50) . "...\n";
            } else {
                echo "✗ Error in statement " . ($index + 1) . ": " . $e->getMessage() . "\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n";
                $errors++;
            }
        }
    }
    
    echo "\n=== Setup Summary ===\n";
    echo "Total statements: " . count($statements) . "\n";
    echo "Successfully executed: $executed\n";
    echo "Errors: $errors\n";
    
    if ($errors === 0) {
        echo "\n✅ Team functionality setup completed successfully!\n";
        
        // Verify the setup by checking key tables
        echo "\n=== Verification ===\n";
        
        $tables_to_check = [
            'teams',
            'team_admin_permissions', 
            'notifications',
            'task_templates',
            'kudos',
            'delegations',
            'team_messages',
            'task_time_logs'
        ];
        
        foreach ($tables_to_check as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                echo "✓ Table '$table' exists with $count records\n";
            } catch (PDOException $e) {
                echo "✗ Table '$table' check failed: " . $e->getMessage() . "\n";
            }
        }
        
        // Check if users table has been updated
        try {
            $stmt = $pdo->query("DESCRIBE users");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $required_columns = ['team_id', 'parent_admin_id'];
            foreach ($required_columns as $column) {
                if (in_array($column, $columns)) {
                    echo "✓ Users table has '$column' column\n";
                } else {
                    echo "✗ Users table missing '$column' column\n";
                }
            }
            
            // Check if role enum has been updated
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
            $role_info = $stmt->fetch();
            if ($role_info && strpos($role_info['Type'], 'team_admin') !== false) {
                echo "✓ Users role enum includes 'team_admin'\n";
            } else {
                echo "✗ Users role enum may not include 'team_admin'\n";
            }
            
        } catch (PDOException $e) {
            echo "✗ Users table verification failed: " . $e->getMessage() . "\n";
        }
        
        // Check sample data
        echo "\n=== Sample Data ===\n";
        try {
            $stmt = $pdo->query("SELECT name FROM teams ORDER BY id LIMIT 3");
            $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "Sample teams created: " . implode(', ', $teams) . "\n";
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'team_admin'");
            $team_admin_count = $stmt->fetchColumn();
            echo "Team admins created: $team_admin_count\n";
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM task_templates");
            $template_count = $stmt->fetchColumn();
            echo "Task templates created: $template_count\n";
            
        } catch (PDOException $e) {
            echo "Sample data check failed: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "\n⚠ Setup completed with $errors errors. Please check the error messages above.\n";
    }
    
} catch (Exception $e) {
    echo "Setup failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Next Steps ===\n";
echo "1. Update your PHP application files to use the new team functionality\n";
echo "2. Test the team admin permissions system\n";
echo "3. Verify that team-based task assignment works correctly\n";
echo "4. Test the new notification and messaging features\n";
echo "\nSetup complete!\n";
?>
