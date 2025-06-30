<?php
// Fast authentication check with minimal database operations
function fast_auth_check($required_roles = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit();
    }
    
    // Use cached user data if available
    if (!isset($_SESSION['user_data']) || !isset($_SESSION['user_role'])) {
        include_once 'config/database.php';
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