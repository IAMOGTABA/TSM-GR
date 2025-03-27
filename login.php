<?php
// login.php

session_start();
require 'config.php'; // Include the file where you connect to MySQL via PDO

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form inputs safely
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        // Query the user by email, ensure user is active
        $stmt = $pdo->prepare("
            SELECT * 
            FROM users 
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Check if the user is active
            if ($user['status'] === 'active') {
                // Verify the entered password against the stored password
                // First try with password_verify for hashed passwords
                if (password_verify($password, $user['password']) || $password === $user['password']) {
                    // Login successful
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['role']       = $user['role'];
                    $_SESSION['full_name']  = $user['full_name'];

                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        header('Location: admin-dashboard.php');
                    } else {
                        // If role is 'employee' or 'user' or anything else
                        header('Location: employee-dashboard.php');
                    }
                    exit;
                } else {
                    $error = "Invalid password!";
                }
            } else {
                $error = "Account is inactive. Please contact administrator.";
            }
        } else {
            $error = "User not found!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Task Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #6a0dad;
            --primary-dark: #4a0080;
            --primary-light: #8e24aa;
            --success: #1cc88a;
            --danger: #e74a3b;
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
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--text-main);
            position: relative;
            overflow: hidden;
        }
        
        /* Background animation */
        body::before, body::after {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: var(--primary-dark);
            opacity: 0.1;
            z-index: -1;
            animation: pulse 15s infinite alternate;
        }
        
        body::before {
            top: -300px;
            left: -200px;
            animation-delay: 2s;
        }
        
        body::after {
            bottom: -300px;
            right: -200px;
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.2);
            }
            100% {
                transform: scale(1);
            }
        }
        
        .login-container {
            width: 90%;
            max-width: 400px;
            background-color: var(--bg-card);
            border-radius: 12px;
            box-shadow: 0 8px 20px var(--shadow-color);
            padding: 2.5rem;
            transition: all 0.3s ease;
            transform: translateY(0);
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .login-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }
        
        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        
        .login-logo {
            font-size: 3rem;
            color: var(--primary-light);
            margin-bottom: 1rem;
        }
        
        .login-form .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .login-form label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 500;
            color: var(--text-main);
        }
        
        .login-form .form-control {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 3rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--bg-secondary);
            color: var(--text-main);
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .login-form .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.2);
        }
        
        .login-form .form-icon {
            position: absolute;
            left: 1rem;
            top: calc(50% + 0.4rem);
            transform: translateY(-50%);
            color: var(--primary-light);
        }
        
        .login-btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 8px;
            background: linear-gradient(to right, var(--primary), var(--primary-light));
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .login-btn:hover {
            background: linear-gradient(to right, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(106, 13, 173, 0.3);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .login-btn.clicked {
            animation: moveRight 0.8s forwards;
        }
        
        @keyframes moveRight {
            0% {
                transform: translateX(0);
            }
            50% {
                transform: translateX(100%);
                opacity: 0;
            }
            51% {
                transform: translateX(-100%);
                opacity: 0;
            }
            100% {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .error-message {
            padding: 0.8rem;
            border-radius: 8px;
            background-color: rgba(231, 74, 59, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both;
        }
        
        @keyframes shake {
            10%, 90% {
                transform: translateX(-1px);
            }
            20%, 80% {
                transform: translateX(2px);
            }
            30%, 50%, 70% {
                transform: translateX(-4px);
            }
            40%, 60% {
                transform: translateX(4px);
            }
        }
        
        .error-icon {
            margin-right: 0.5rem;
        }
        
        /* Responsive design */
        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
                width: 95%;
            }
            
            .login-title {
                font-size: 1.5rem;
            }
            
            .login-logo {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <i class="fas fa-tasks"></i>
            </div>
            <h1 class="login-title">TSM</h1>
            <p class="login-subtitle">Task Management System</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle error-icon"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="login.php" class="login-form">
            <div class="form-group">
                <label for="email">Email Address</label>
                <i class="fas fa-envelope form-icon"></i>
                <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <i class="fas fa-lock form-icon"></i>
                <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
            </div>
            
            <button type="submit" class="login-btn">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
    </div>

    <script>
        // Add smooth transitions when focusing on input fields
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        });
        
        // Animation for login container on page load
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.login-container');
            container.style.opacity = '0';
            
            setTimeout(() => {
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
            
            // Add click animation to login button
            const loginBtn = document.querySelector('.login-btn');
            loginBtn.addEventListener('click', function(e) {
                // Only animate if form is valid
                if (document.querySelector('.login-form').checkValidity()) {
                    e.preventDefault(); // Prevent immediate form submission
                    this.classList.add('clicked');
                    
                    // Wait for animation to complete before submitting
                    setTimeout(() => {
                        document.querySelector('.login-form').submit();
                    }, 800);
                }
            });
        });
    </script>
</body>
</html>
