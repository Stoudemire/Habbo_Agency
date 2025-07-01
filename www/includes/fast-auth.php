<?php
// Fast authentication check with minimal database operations
function fast_auth_check($required_roles = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit();
    }
    
    // Check if user session has been invalidated (for role updates)
    include_once 'config/database.php';
    try {
        $stmt_check = $pdo->prepare("SELECT invalidated_at FROM session_invalidations WHERE user_id = ?");
        $stmt_check->execute([$_SESSION['user_id']]);
        $invalidation = $stmt_check->fetch();
        
        if ($invalidation) {
            // Session has been invalidated, force refresh user data immediately
            unset($_SESSION['user_data'], $_SESSION['user_role'], $_SESSION['last_validated']);
            
            // Remove invalidation record
            $stmt_remove = $pdo->prepare("DELETE FROM session_invalidations WHERE user_id = ?");
            $stmt_remove->execute([$_SESSION['user_id']]);
            
            // Force immediate reload of user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $_SESSION['user_data'] = $user;
                $_SESSION['user_role'] = $user['role'];
            }
        }
    } catch (Exception $e) {
        // Table might not exist yet, continue
        error_log("Fast auth invalidation check error: " . $e->getMessage());
    }
    
    // Use cached user data if available
    if (!isset($_SESSION['user_data']) || !isset($_SESSION['user_role'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            session_destroy();
            header('Location: index.php');
            exit();
        }
        
        $_SESSION['user_data'] = $user;
        $_SESSION['user_role'] = $user['role'];
    }
    
    // Check role requirements
    if ($required_roles && !in_array($_SESSION['user_role'], $required_roles)) {
        header('Location: dashboard.php?error=access_denied');
        exit();
    }
    
    return $_SESSION['user_data'];
}

// Fast site title with caching
function fast_site_title() {
    static $cached_title = null;
    if ($cached_title !== null) {
        return $cached_title;
    }
    
    if (isset($_SESSION['site_title'])) {
        $cached_title = $_SESSION['site_title'];
        return $cached_title;
    }
    
    include_once 'config/database.php';
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'site_title'");
        $stmt->execute();
        $title = $stmt->fetchColumn();
        $cached_title = $title ? $title : 'Habbo Agency';
        $_SESSION['site_title'] = $cached_title;
        return $cached_title;
    } catch (Exception $e) {
        $cached_title = 'Habbo Agency';
        return $cached_title;
    }
}
?>