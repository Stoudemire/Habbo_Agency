
<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

$habbo_username = trim($_POST['habbo_username'] ?? '');

if (empty($habbo_username)) {
    echo json_encode(['available' => false, 'error' => 'Nombre de usuario requerido']);
    exit();
}

try {
    include 'config/database.php';
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE habbo_username = ?");
    $stmt->execute([$habbo_username]);
    
    $userExists = $stmt->fetch();
    
    echo json_encode(['available' => !$userExists]);
    
} catch (Exception $e) {
    error_log("Error checking username availability: " . $e->getMessage());
    echo json_encode(['available' => false, 'error' => 'Error del servidor']);
}
?>
