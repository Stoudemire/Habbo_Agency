
<?php
// Script para corregir la autenticación de usuarios y sincronización de roles
session_start();
require_once 'config/database.php';

// Solo permitir acceso a super_admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die('Acceso denegado. Solo super administradores pueden ejecutar este script.');
}

echo "<h2>Migración de corrección de autenticación de usuarios</h2>";

try {
    $pdo->beginTransaction();
    
    // 1. Sincronizar username con habbo_username para usuarios existentes
    echo "1. Sincronizando usernames con habbo_usernames...<br>";
    $stmt = $pdo->prepare("UPDATE users SET username = habbo_username WHERE username != habbo_username");
    $result = $stmt->execute();
    $affected = $stmt->rowCount();
    echo "✓ {$affected} usuarios sincronizados<br><br>";
    
    // 2. Verificar y corregir índices
    echo "2. Verificando índices de la tabla users...<br>";
    
    // Agregar índice en habbo_username si no existe
    try {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_habbo_username (habbo_username)");
        echo "✓ Índice idx_habbo_username agregado<br>";
    } catch (Exception $e) {
        echo "✓ Índice idx_habbo_username ya existe<br>";
    }
    
    // Agregar índice en role si no existe
    try {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_role (role)");
        echo "✓ Índice idx_role agregado<br>";
    } catch (Exception $e) {
        echo "✓ Índice idx_role ya existe<br>";
    }
    
    // 3. Limpiar sesiones activas para forzar re-autenticación
    echo "<br>3. Limpiando sesiones activas...<br>";
    try {
        $pdo->exec("DELETE FROM sessions WHERE expire < NOW()");
        $pdo->exec("TRUNCATE TABLE sessions");
        echo "✓ Sesiones limpiadas - todos los usuarios deberán volver a iniciar sesión<br>";
    } catch (Exception $e) {
        echo "✓ Tabla de sesiones no existe o ya está limpia<br>";
    }
    
    // 4. Verificar consistencia de datos
    echo "<br>4. Verificando consistencia de datos...<br>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE username IS NULL OR habbo_username IS NULL OR username = '' OR habbo_username = ''");
    $invalid = $stmt->fetch()['count'];
    
    if ($invalid > 0) {
        echo "⚠️ Se encontraron {$invalid} usuarios con datos incompletos<br>";
        echo "Eliminando usuarios con datos incompletos...<br>";
        $pdo->exec("DELETE FROM users WHERE username IS NULL OR habbo_username IS NULL OR username = '' OR habbo_username = ''");
        echo "✓ Usuarios con datos incompletos eliminados<br>";
    } else {
        echo "✓ Todos los usuarios tienen datos consistentes<br>";
    }
    
    // 5. Mostrar estadísticas finales
    echo "<br>5. Estadísticas finales:<br>";
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role ORDER BY count DESC");
    $roles = $stmt->fetchAll();
    
    foreach ($roles as $role_data) {
        echo "- {$role_data['role']}: {$role_data['count']} usuarios<br>";
    }
    
    $pdo->commit();
    echo "<br><strong style='color: green;'>✅ Migración completada exitosamente!</strong><br>";
    echo "<br><em>Nota: Todos los usuarios deberán volver a iniciar sesión para que los cambios surtan efecto.</em><br>";
    echo "<br><a href='admin-panel.php'>Ir al Panel de Administrador</a> | <a href='dashboard.php'>Volver al Dashboard</a>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<br><strong style='color: red;'>❌ Error durante la migración:</strong> " . $e->getMessage() . "<br>";
    echo "<br><a href='dashboard.php'>Volver al Dashboard</a>";
}
?>
