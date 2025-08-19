<?php
/**
 * Seed Team Data Script
 * Populates the database with sample team data
 */

require_once 'config.php';

echo "=== Seeding Team Data ===\n\n";

try {
    // Step 1: Insert example teams
    echo "Step 1: Creating teams...\n";
    
    $teams = [
        ['name' => 'Development Team', 'created_by' => 1],
        ['name' => 'Marketing Team', 'created_by' => 1],
        ['name' => 'Support Team', 'created_by' => 1]
    ];
    
    foreach ($teams as $team) {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO teams (name, created_by, status) VALUES (?, ?, 'active')");
            $stmt->execute([$team['name'], $team['created_by']]);
            echo "✓ Created team: {$team['name']}\n";
        } catch (PDOException $e) {
            echo "⚠ Team {$team['name']} may already exist\n";
        }
    }
    
    // Get team IDs
    $stmt = $pdo->query("SELECT id, name FROM teams");
    $team_ids = [];
    while ($row = $stmt->fetch()) {
        $team_ids[$row['name']] = $row['id'];
    }
    
    // Step 2: Create team admin users
    echo "\nStep 2: Creating team admin users...\n";
    
    $team_admins = [
        [
            'name' => 'John Smith',
            'email' => 'john.smith@company.com',
            'team' => 'Development Team'
        ],
        [
            'name' => 'Sarah Johnson', 
            'email' => 'sarah.johnson@company.com',
            'team' => 'Marketing Team'
        ],
        [
            'name' => 'Mike Wilson',
            'email' => 'mike.wilson@company.com', 
            'team' => 'Support Team'
        ]
    ];
    
    $password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // 'password123'
    
    foreach ($team_admins as $admin) {
        try {
            $team_id = $team_ids[$admin['team']] ?? null;
            if ($team_id) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO users (full_name, email, password, role, status, team_id, parent_admin_id) VALUES (?, ?, ?, 'team_admin', 'active', ?, 1)");
                $stmt->execute([$admin['name'], $admin['email'], $password_hash, $team_id]);
                echo "✓ Created team admin: {$admin['name']} for {$admin['team']}\n";
            }
        } catch (PDOException $e) {
            echo "⚠ Team admin {$admin['name']} may already exist\n";
        }
    }
    
    // Step 3: Create employee users
    echo "\nStep 3: Creating employee users...\n";
    
    $employees = [
        ['name' => 'Alice Brown', 'email' => 'alice.brown@company.com', 'team' => 'Development Team'],
        ['name' => 'Bob Davis', 'email' => 'bob.davis@company.com', 'team' => 'Development Team'],
        ['name' => 'Carol White', 'email' => 'carol.white@company.com', 'team' => 'Marketing Team'],
        ['name' => 'David Green', 'email' => 'david.green@company.com', 'team' => 'Marketing Team'],
        ['name' => 'Eva Black', 'email' => 'eva.black@company.com', 'team' => 'Support Team']
    ];
    
    foreach ($employees as $employee) {
        try {
            $team_id = $team_ids[$employee['team']] ?? null;
            if ($team_id) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO users (full_name, email, password, role, status, team_id) VALUES (?, ?, ?, 'employee', 'active', ?)");
                $stmt->execute([$employee['name'], $employee['email'], $password_hash, $team_id]);
                echo "✓ Created employee: {$employee['name']} in {$employee['team']}\n";
            }
        } catch (PDOException $e) {
            echo "⚠ Employee {$employee['name']} may already exist\n";
        }
    }
    
    // Step 4: Set up team admin permissions
    echo "\nStep 4: Setting up team admin permissions...\n";
    
    foreach ($team_admins as $admin) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$admin['email']]);
            $user_id = $stmt->fetchColumn();
            
            $team_id = $team_ids[$admin['team']] ?? null;
            
            if ($user_id && $team_id) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO team_admin_permissions (user_id, team_id, can_assign, can_edit, can_archive, can_add_members, can_view_reports, can_send_messages) VALUES (?, ?, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE)");
                $stmt->execute([$user_id, $team_id]);
                echo "✓ Set permissions for: {$admin['name']}\n";
            }
        } catch (PDOException $e) {
            echo "⚠ Permissions for {$admin['name']} may already exist\n";
        }
    }
    
    // Step 5: Create task templates
    echo "\nStep 5: Creating task templates...\n";
    
    $templates = [
        [
            'name' => 'Bug Fix Template',
            'description' => 'Standard template for bug fixing tasks',
            'team' => 'Development Team',
            'priority' => 'high',
            'hours' => 4.0,
            'subtasks' => [
                'Reproduce the bug',
                'Identify root cause', 
                'Implement fix',
                'Test the fix',
                'Deploy to production'
            ]
        ],
        [
            'name' => 'Feature Development',
            'description' => 'Template for new feature development',
            'team' => 'Development Team',
            'priority' => 'medium',
            'hours' => 16.0,
            'subtasks' => [
                'Requirements analysis',
                'Design mockups',
                'Backend development',
                'Frontend development',
                'Testing',
                'Documentation'
            ]
        ],
        [
            'name' => 'Marketing Campaign',
            'description' => 'Template for marketing campaign tasks',
            'team' => 'Marketing Team',
            'priority' => 'medium',
            'hours' => 8.0,
            'subtasks' => []
        ],
        [
            'name' => 'Customer Support Ticket',
            'description' => 'Template for handling customer support',
            'team' => 'Support Team',
            'priority' => 'high',
            'hours' => 2.0,
            'subtasks' => []
        ]
    ];
    
    foreach ($templates as $template) {
        try {
            $team_id = $team_ids[$template['team']] ?? null;
            if ($team_id) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO task_templates (name, description, created_by, team_id, priority, estimated_hours, is_public) VALUES (?, ?, 1, ?, ?, ?, TRUE)");
                $stmt->execute([$template['name'], $template['description'], $team_id, $template['priority'], $template['hours']]);
                
                echo "✓ Created template: {$template['name']}\n";
                
                // Add subtasks if any
                if (!empty($template['subtasks'])) {
                    $stmt = $pdo->prepare("SELECT id FROM task_templates WHERE name = ? LIMIT 1");
                    $stmt->execute([$template['name']]);
                    $template_id = $stmt->fetchColumn();
                    
                    if ($template_id) {
                        foreach ($template['subtasks'] as $index => $subtask) {
                            $stmt = $pdo->prepare("INSERT IGNORE INTO template_subtasks (template_id, title, order_index) VALUES (?, ?, ?)");
                            $stmt->execute([$template_id, $subtask, $index + 1]);
                        }
                        echo "  └─ Added " . count($template['subtasks']) . " subtasks\n";
                    }
                }
            }
        } catch (PDOException $e) {
            echo "⚠ Template {$template['name']} may already exist\n";
        }
    }
    
    // Step 6: Create sample notifications
    echo "\nStep 6: Creating sample notifications...\n";
    
    $employees_for_notifications = ['alice.brown@company.com', 'bob.davis@company.com', 'carol.white@company.com'];
    
    foreach ($employees_for_notifications as $email) {
        try {
            $stmt = $pdo->prepare("SELECT id, team_id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                $stmt = $pdo->prepare("SELECT name FROM teams WHERE id = ? LIMIT 1");
                $stmt->execute([$user['team_id']]);
                $team_name = $stmt->fetchColumn();
                
                if ($team_name) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, related_type) VALUES (?, 'team_update', 'Welcome to {$team_name}', 'You have been assigned to the {$team_name}', 'team')");
                    $stmt->execute([$user['id']]);
                    echo "✓ Created notification for user: $email\n";
                }
            }
        } catch (PDOException $e) {
            echo "⚠ Notification for $email may already exist\n";
        }
    }
    
    // Step 7: Create sample team messages
    echo "\nStep 7: Creating sample team messages...\n";
    
    foreach ($team_admins as $admin) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$admin['email']]);
            $sender_id = $stmt->fetchColumn();
            
            $team_id = $team_ids[$admin['team']] ?? null;
            
            if ($sender_id && $team_id) {
                $messages = [
                    'Development Team' => 'Welcome to the Development Team! Looking forward to working together on exciting projects.',
                    'Marketing Team' => 'Excited to lead this amazing marketing team. Let\'s create some great campaigns!',
                    'Support Team' => 'Please remember to follow our customer service guidelines for all interactions.'
                ];
                
                $message = $messages[$admin['team']] ?? 'Welcome to the team!';
                $subject = $admin['team'] . ' Welcome Message';
                
                $stmt = $pdo->prepare("INSERT IGNORE INTO team_messages (sender_id, team_id, subject, message, is_announcement) VALUES (?, ?, ?, ?, TRUE)");
                $stmt->execute([$sender_id, $team_id, $subject, $message]);
                echo "✓ Created team message for: {$admin['team']}\n";
            }
        } catch (PDOException $e) {
            echo "⚠ Team message for {$admin['team']} may already exist\n";
        }
    }
    
    echo "\n=== Seed Data Summary ===\n";
    
    // Show counts
    $stmt = $pdo->query("SELECT COUNT(*) FROM teams");
    $team_count = $stmt->fetchColumn();
    echo "Teams created: $team_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'team_admin'");
    $admin_count = $stmt->fetchColumn();
    echo "Team admins created: $admin_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employee'");
    $employee_count = $stmt->fetchColumn();
    echo "Employees created: $employee_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM task_templates");
    $template_count = $stmt->fetchColumn();
    echo "Task templates created: $template_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM notifications");
    $notification_count = $stmt->fetchColumn();
    echo "Notifications created: $notification_count\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM team_messages");
    $message_count = $stmt->fetchColumn();
    echo "Team messages created: $message_count\n";
    
    echo "\n✅ Seed data creation completed successfully!\n";
    echo "\nDefault password for all users: password123\n";
    
} catch (Exception $e) {
    echo "✗ Error during seed data creation: " . $e->getMessage() . "\n";
}
?>
