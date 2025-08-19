<?php
/**
 * Session Security Check Middleware
 * 
 * Include this file at the top of protected pages to ensure
 * proper session security and timeout handling.
 */

// Ensure security functions are loaded
if (!function_exists('isAuthenticated')) {
    require_once __DIR__ . '/security.php';
}

// Check if session is active
if (session_status() !== PHP_SESSION_ACTIVE) {
    configureSecureSession();
    session_start();
}

// Check session timeout
if (isset($_SESSION['last_activity'])) {
    $inactive_time = time() - $_SESSION['last_activity'];
    
    if ($inactive_time > SESSION_LIFETIME) {
        // Session expired
        logSecurityEvent('SESSION_TIMEOUT', "Session expired for user " . ($_SESSION['user_id'] ?? 'unknown'));
        
        session_unset();
        session_destroy();
        
        header('Location: login.php?error=session_expired');
        exit;
    }
}

// Update last activity time
if (isAuthenticated()) {
    $_SESSION['last_activity'] = time();
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Every 5 minutes
        regenerateSession();
        $_SESSION['last_regeneration'] = time();
    }
}

// Check for session hijacking (basic check)
if (isAuthenticated()) {
    $expected_user_agent = $_SESSION['user_agent'] ?? '';
    $current_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (empty($expected_user_agent)) {
        $_SESSION['user_agent'] = $current_user_agent;
    } elseif ($expected_user_agent !== $current_user_agent) {
        logSecurityEvent('SESSION_HIJACK_ATTEMPT', 'User agent mismatch detected', 'WARNING');
        
        session_unset();
        session_destroy();
        
        header('Location: login.php?error=security_violation');
        exit;
    }
}
?>
