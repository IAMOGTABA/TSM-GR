<?php
// setup.php - Complete installation script for TSM-GR
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>TSM Setup - Task Management System</title>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css'>
    <style>
        :root {
            --primary: #6a0dad;
            --primary-dark: #4a0080;
            --primary-light: #8e24aa;
            --success: #1cc88a;
            --danger: #e74a3b;
            --warning: #f6c23e;
            --bg-main: #121212;
            --bg-card: #1e1e1e;
            --bg-secondary: #2a2a2a;
            --text-main: #e0e0e0;
            --text-secondary: #bbbbbb;
            --border-color: #333333;
            --shadow-color: rgba(0, 0, 0, 0.5);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, var(--bg-main) 0%, #200729 100%);
            min-height: 100vh;
            color: var(--text-main);
            padding: 2rem;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: 0 8px 20px var(--shadow-color);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(to right, var(--primary), var(--primary-light));
            padding: 2rem;
            text-align: center;
            color: white;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .content {
            padding: 2rem;
        }
        
        .step {
            margin-bottom: 2rem;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
        }
        
        .step-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 1rem;
        }
        
        .step-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .success {
            color: var(--success);
            background: rgba(28, 200, 138, 0.1);
            border-color: var(--success);
        }
        
        .error {
            color: var(--danger);
            background: rgba(231, 74, 59, 0.1);
            border-color: var(--danger);
        }
        
        .warning {
            color: var(--warning);
            background: rgba(246, 194, 62, 0.1);
            border-color: var(--warning);
        }
        
        .code {
            background: var(--bg-main);
            padding: 1rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            margin: 1rem 0;
            border-left: 4px solid var(--primary);
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(to right, var(--primary), var(--primary-light));
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
            margin: 0.5rem 0.5rem 0.5rem 0;
        }
        
        .btn:hover {
            background: linear-gradient(to right, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(106, 13, 173, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(to right, var(--success), #17a673);
        }
        
        .btn-success:hover {
            background: linear-gradient(to right, #17a673, var(--success));
        }
        
        ul {
            margin-left: 1.5rem;
            margin-top: 0.5rem;
        }
        
        li {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1><i class='fas fa-tasks'></i> TSM Setup</h1>
            <p>Task Management System Installation</p>
        </div>
        <div class='content'>";

$steps = [];
$hasErrors = false;

// Step 1: Check XAMPP Services
echo "<div class='step'>
    <div class='step-header'>
        <div class='step-number'>1</div>
        <div class='step-title'>XAMPP Services Check</div>
    </div>";

$xamppRunning = false;
if (function_exists('mysqli_connect')) {
    $testConnection = @mysqli_connect('localhost', 'root', '');
    if ($testConnection) {
        $xamppRunning = true;
        mysqli_close($testConnection);
        echo "<p class='success'><i class='fas fa-check-circle'></i> XAMPP MySQL service is running!</p>";
    }
}

if (!$xamppRunning) {
    $hasErrors = true;
    echo "<p class='error'><i class='fas fa-times-circle'></i> XAMPP MySQL service is not running!</p>
          <div class='warning'>
              <p><strong>Please start XAMPP services:</strong></p>
              <ul>
                  <li>Open XAMPP Control Panel</li>
                  <li>Start <strong>Apache</strong> service</li>
                  <li>Start <strong>MySQL</strong> service</li>
                  <li>Refresh this page after starting the services</li>
              </ul>
          </div>";
}
echo "</div>";

// Step 2: Create Database
if ($xamppRunning) {
    echo "<div class='step'>
        <div class='step-header'>
            <div class='step-number'>2</div>
            <div class='step-title'>Database Creation</div>
        </div>";
    
    try {
        $conn = new mysqli('localhost', 'root', '');
        
        // Create database if it doesn't exist
        $result = $conn->query("CREATE DATABASE IF NOT EXISTS task_management");
        if ($result) {
            echo "<p class='success'><i class='fas fa-check-circle'></i> Database 'task_management' created successfully!</p>";
        }
        
        $conn->close();
    } catch (Exception $e) {
        $hasErrors = true;
        echo "<p class='error'><i class='fas fa-times-circle'></i> Error creating database: " . $e->getMessage() . "</p>";
    }
    echo "</div>";
}

// Step 3: Create Users Table First
if ($xamppRunning && !$hasErrors) {
    echo "<div class='step'>
        <div class='step-header'>
            <div class='step-number'>3</div>
            <div class='step-title'>Create Users Table</div>
        </div>";
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=task_management;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create users table first (required for foreign keys)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('admin', 'employee') NOT NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        echo "<p class='success'><i class='fas fa-check-circle'></i> Users table created successfully!</p>";
    } catch (Exception $e) {
        $hasErrors = true;
        echo "<p class='error'><i class='fas fa-times-circle'></i> Error creating users table: " . $e->getMessage() . "</p>";
    }
    echo "</div>";
}

// Step 4: Create Other Tables
if ($xamppRunning && !$hasErrors) {
    echo "<div class='step'>
        <div class='step-header'>
            <div class='step-number'>4</div>
            <div class='step-title'>Create Application Tables</div>
        </div>";
    
    try {
        // Create tasks table
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
        
        // Create subtasks table
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
        
        // Create messages table with task linking
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                subject VARCHAR(200) NOT NULL,
                message TEXT NOT NULL,
                task_id INT NULL,
                parent_message_id INT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_status ENUM('read', 'unread') DEFAULT 'unread',
                FOREIGN KEY (sender_id) REFERENCES users(id),
                FOREIGN KEY (recipient_id) REFERENCES users(id),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
                FOREIGN KEY (parent_message_id) REFERENCES messages(id) ON DELETE SET NULL
            )
        ");
        
        echo "<p class='success'><i class='fas fa-check-circle'></i> All application tables created successfully!</p>
              <ul>
                  <li>Tasks table</li>
                  <li>Subtasks table</li>
                  <li>Messages table (with task linking)</li>
              </ul>";
    } catch (Exception $e) {
        $hasErrors = true;
        echo "<p class='error'><i class='fas fa-times-circle'></i> Error creating tables: " . $e->getMessage() . "</p>";
    }
    echo "</div>";
}

// Step 5: Create Admin User
if ($xamppRunning && !$hasErrors) {
    echo "<div class='step'>
        <div class='step-header'>
            <div class='step-number'>5</div>
            <div class='step-title'>Create Admin User</div>
        </div>";
    
    try {
        // Check if admin already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@test.com'");
        $checkStmt->execute();
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            echo "<p class='warning'><i class='fas fa-info-circle'></i> Admin user already exists!</p>";
        } else {
            // Create admin user
            $email = 'admin@test.com';
            $password = 'admin123';
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (email, password, full_name, role, status) 
                VALUES (:email, :password, :full_name, :role, :status)
            ");
            
            $result = $stmt->execute([
                'email' => $email,
                'password' => $hashedPassword,
                'full_name' => 'System Administrator',
                'role' => 'admin',
                'status' => 'active'
            ]);
            
            if ($result) {
                echo "<p class='success'><i class='fas fa-check-circle'></i> Admin user created successfully!</p>
                      <div class='code'>
                          <strong>Login Credentials:</strong><br>
                          Email: admin@test.com<br>
                          Password: admin123
                      </div>";
            }
        }
        
        // Create a sample employee user
        $checkEmpStmt = $pdo->prepare("SELECT id FROM users WHERE email = 'employee@test.com'");
        $checkEmpStmt->execute();
        $existingEmp = $checkEmpStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingEmp) {
            $empStmt = $pdo->prepare("
                INSERT INTO users (email, password, full_name, role, status) 
                VALUES (:email, :password, :full_name, :role, :status)
            ");
            
            $empResult = $empStmt->execute([
                'email' => 'employee@test.com',
                'password' => password_hash('employee123', PASSWORD_DEFAULT),
                'full_name' => 'Sample Employee',
                'role' => 'employee',
                'status' => 'active'
            ]);
            
            if ($empResult) {
                echo "<p class='success'><i class='fas fa-check-circle'></i> Sample employee user created!</p>
                      <div class='code'>
                          <strong>Employee Login:</strong><br>
                          Email: employee@test.com<br>
                          Password: employee123
                      </div>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p class='error'><i class='fas fa-times-circle'></i> Error creating admin user: " . $e->getMessage() . "</p>";
    }
    echo "</div>";
}

// Final Step: Success and Next Steps
if ($xamppRunning && !$hasErrors) {
    echo "<div class='step success'>
        <div class='step-header'>
            <div class='step-number'><i class='fas fa-check'></i></div>
            <div class='step-title'>Installation Complete!</div>
        </div>
        <p><i class='fas fa-party-horn'></i> TSM-GR has been successfully installed and configured!</p>
        <p><strong>Next Steps:</strong></p>
        <ul>
            <li>Access your application at: <strong>http://localhost/TSM-GR/login.php</strong></li>
            <li>Login with the admin credentials provided above</li>
            <li>Start creating and managing tasks!</li>
        </ul>
        
        <div style='margin-top: 1.5rem;'>
            <a href='login.php' class='btn btn-success'>
                <i class='fas fa-sign-in-alt'></i> Go to Login
            </a>
            <a href='admin-dashboard.php' class='btn'>
                <i class='fas fa-tachometer-alt'></i> Admin Dashboard
            </a>
        </div>
    </div>";
} else {
    echo "<div class='step error'>
        <div class='step-header'>
            <div class='step-number'><i class='fas fa-times'></i></div>
            <div class='step-title'>Installation Incomplete</div>
        </div>
        <p>Please resolve the errors above and refresh this page to continue the installation.</p>
        <a href='setup.php' class='btn'>
            <i class='fas fa-redo'></i> Retry Setup
        </a>
    </div>";
}

echo "        </div>
    </div>
</body>
</html>";
?>
