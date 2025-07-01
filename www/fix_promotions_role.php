
<?php
session_start();
require_once 'config/database.php';

// Verificar que solo super_admin pueda ejecutar este script
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die('Acceso denegado. Solo super administradores pueden ejecutar este script.');
}

echo "<h2>Corrección de estructura de roles para rangos personalizados</h2>";

try {
    $pdo->beginTransaction();
    
    // 1. Cambiar la columna role de ENUM a VARCHAR
    echo "1. Modificando estructura de la tabla users...<br>";
    
    // Primero verificamos la estructura actual
    $stmt = $pdo->prepare("DESCRIBE users");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    $roleColumnFound = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'role') {
            $roleColumnFound = true;
            echo "✓ Columna 'role' encontrada: {$column['Type']}<br>";
            break;
        }
    }
    
    if ($roleColumnFound) {
        // Cambiar ENUM a VARCHAR(50)
        $stmt = $pdo->prepare("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'usuario'");
        $stmt->execute();
        echo "✓ Columna 'role' cambiada de ENUM a VARCHAR(50)<br>";
    } else {
        echo "⚠️ Columna 'role' no encontrada<br>";
    }
    
    // 2. Verificar que todos los usuarios tengan roles válidos
    echo "<br>2. Verificando consistencia de roles...<br>";
    
    // Obtener todos los rangos disponibles
    $stmt = $pdo->prepare("SELECT rank_name FROM user_ranks");
    $stmt->execute();
    $valid_ranks = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "✓ Rangos válidos encontrados: " . implode(', ', $valid_ranks) . "<br>";
    
    // Verificar usuarios con roles inválidos
    $placeholders = str_repeat('?,', count($valid_ranks) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE role NOT IN ($placeholders)");
    $stmt->execute($valid_ranks);
    $invalid_users = $stmt->fetchAll();
    
    if (!empty($invalid_users)) {
        echo "⚠️ Se encontraron " . count($invalid_users) . " usuarios con roles inválidos:<br>";
        foreach ($invalid_users as $user) {
            echo "- Usuario ID {$user['id']} ({$user['username']}): rol '{$user['role']}'<br>";
        }
        
        // Actualizar usuarios con roles inválidos al rol 'usuario'
        $stmt = $pdo->prepare("UPDATE users SET role = 'usuario' WHERE role NOT IN ($placeholders)");
        $stmt->execute($valid_ranks);
        echo "✓ Usuarios con roles inválidos actualizados a 'usuario'<br>";
    } else {
        echo "✓ Todos los usuarios tienen roles válidos<br>";
    }
    
    // 3. Agregar índice en la nueva columna role
    echo "<br>3. Optimizando índices...<br>";
    try {
        $stmt = $pdo->prepare("ALTER TABLE users ADD INDEX idx_role_varchar (role)");
        $stmt->execute();
        echo "✓ Índice idx_role_varchar agregado<br>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "✓ Índice idx_role_varchar ya existe<br>";
        } else {
            throw $e;
        }
    }
    
    // 4. Limpiar sesiones para forzar recarga de roles
    echo "<br>4. Limpiando sesiones activas...<br>";
    try {
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE expire < NOW()");
        $stmt->execute();
        echo "✓ Sesiones expiradas limpiadas<br>";
    } catch (Exception $e) {
        echo "✓ Tabla de sesiones no existe o ya está limpia<br>";
    }
    
    // 5. Mostrar estadísticas finales
    echo "<br>5. Estadísticas finales de roles:<br>";
    $stmt = $pdo->prepare("SELECT role, COUNT(*) as count FROM users GROUP BY role ORDER BY count DESC");
    $stmt->execute();
    $role_stats = $stmt->fetchAll();
    
    foreach ($role_stats as $stat) {
        echo "- {$stat['role']}: {$stat['count']} usuarios<br>";
    }
    
    $pdo->commit();
    echo "<br><strong style='color: green;'>✅ Corrección completada exitosamente!</strong><br>";
    echo "<br><em>Nota: Ahora puedes asignar cualquier rango personalizado a los usuarios.</em><br>";
    echo "<br><a href='promotions.php'>Ir a Gestión de Ascensos</a> | <a href='dashboard.php'>Volver al Dashboard</a>";
    
} catch (Exception $e) {
    $pdo->rollback();
    echo "<br><strong style='color: red;'>❌ Error durante la corrección:</strong><br>";
    echo $e->getMessage() . "<br>";
    echo "<br><a href='dashboard.php'>Volver al Dashboard</a>";
}
?>
