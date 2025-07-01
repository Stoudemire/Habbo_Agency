
<line_number>1</line_number>
<?php
header('Content-Type: text/css');
require_once '../config/database.php';

// Generate CSS for roles from database
try {
    $stmt = $pdo->prepare("SELECT rank_name, role_color FROM user_ranks WHERE role_color IS NOT NULL");
    $stmt->execute();
    $roles = $stmt->fetchAll();
    
    foreach ($roles as $role) {
        $role_name = $role['rank_name'];
        $color = $role['role_color'];
        
        // Convert hex to rgba for background
        $hex = str_replace('#', '', $color);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        echo ".role-{$role_name} { background: rgba({$r}, {$g}, {$b}, 0.3); color: {$color}; }\n";
    }
} catch (Exception $e) {
    // If there's an error, provide fallback styles
    echo "/* Error loading dynamic role colors */\n";
}
?>
