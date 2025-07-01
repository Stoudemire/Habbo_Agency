
<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include '../config/database.php';

try {
    // Get current user data with role information
    $stmt = $pdo->prepare("
        SELECT u.role, ur.display_name, ur.role_color, ur.rank_image 
        FROM users u 
        LEFT JOIN user_ranks ur ON u.role = ur.rank_name 
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch();

    if ($user_data) {
        // Update session data
        $_SESSION['user_data']['role'] = $user_data['role'];
        
        echo json_encode([
            'success' => true,
            'role' => $user_data['role'],
            'display_name' => $user_data['display_name'] ?? ucfirst($user_data['role']),
            'role_color' => $user_data['role_color'],
            'rank_image' => $user_data['rank_image']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
