
<?php
// Script de migración para eliminar la columna de email y ajustar usuarios existentes
session_start();

// Solo permitir acceso a super_admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
    die('Acceso denegado. Solo super_admin puede ejecutar esta migración.');
}

include 'config/database.php';

try {
    // Primero, actualizamos los usuarios que no tienen habbo_username configurado
    $stmt = $pdo->prepare("UPDATE users SET habbo_username = username WHERE habbo_username IS NULL OR habbo_username = ''");
    $stmt->execute();
    
    // Verificamos si la columna email existe antes de eliminarla
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'email'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        // Eliminar la columna email
        $stmt = $pdo->prepare("ALTER TABLE users DROP COLUMN email");
        $stmt->execute();
        echo "✓ Columna 'email' eliminada correctamente<br>";
    } else {
        echo "✓ La columna 'email' ya no existe<br>";
    }
    
    // Verificamos si la columna habbo_username existe y tiene el índice único
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'habbo_username'");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Agregar columna habbo_username si no existe
        $stmt = $pdo->prepare("ALTER TABLE users ADD COLUMN habbo_username VARCHAR(50) NOT NULL UNIQUE AFTER password");
        $stmt->execute();
        echo "✓ Columna 'habbo_username' agregada<br>";
    }
    
    // Asegurar que habbo_username tenga índice único
    try {
        $stmt = $pdo->prepare("ALTER TABLE users ADD UNIQUE INDEX idx_habbo_username (habbo_username)");
        $stmt->execute();
        echo "✓ Índice único agregado a habbo_username<br>";
    } catch (Exception $e) {
        echo "✓ Índice único ya existe en habbo_username<br>";
    }
    
    // Cambiar profile_photo a profile_image si existe
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'profile_photo'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("ALTER TABLE users CHANGE profile_photo profile_image VARCHAR(255)");
        $stmt->execute();
        echo "✓ Columna 'profile_photo' renombrada a 'profile_image'<br>";
    }
    
    echo "<br><strong>Migración completada exitosamente!</strong><br>";
    echo "<a href='dashboard.php'>Volver al Dashboard</a>";
    
} catch (Exception $e) {
    echo "Error durante la migración: " . $e->getMessage();
}
?>
