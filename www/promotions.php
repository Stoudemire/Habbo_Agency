
<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user data
if (!isset($_SESSION['user_data'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: index.php');
        exit();
    }
    $_SESSION['user_data'] = $user;
} else {
    $user = $_SESSION['user_data'];
}

$user_role = $user['role'];

// Check if user has permission to manage promotions (administrador or superior)
$can_manage_promotions = in_array($user_role, ['super_admin', 'administrador']);

if (!$can_manage_promotions) {
    header('Location: dashboard.php?error=access_denied');
    exit();
}

// Get site configuration
$stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'site_title'");
$stmt->execute();
$site_title = $stmt->fetchColumn() ?: 'Habbo Agency';

// Handle promotion action
$success_message = '';
$error_message = '';

// Check for session messages (from POST-redirect-GET pattern)
if (isset($_SESSION['promotion_success'])) {
    $success_message = $_SESSION['promotion_success'];
    unset($_SESSION['promotion_success']);
}

if (isset($_SESSION['promotion_error'])) {
    $error_message = $_SESSION['promotion_error'];
    unset($_SESSION['promotion_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'promote_user') {
        $target_user_id = intval($_POST['user_id']);
        $new_role = trim($_POST['new_role']);
        $promotion_reason = trim($_POST['reason']);

        // Validate the new role
        $valid_roles = ['usuario', 'operador', 'administrador'];
        if ($user_role === 'super_admin') {
            $valid_roles[] = 'super_admin';
        }

        if (!in_array($new_role, $valid_roles)) {
            $_SESSION['promotion_error'] = "Rol no válido seleccionado.";
            header('Location: promotions.php');
            exit();
        } else {
            // Get target user info
            $stmt = $pdo->prepare("SELECT id, habbo_username, role FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $target_user = $stmt->fetch();

            if (!$target_user) {
                $_SESSION['promotion_error'] = "Usuario no encontrado.";
                header('Location: promotions.php');
                exit();
            } elseif ($target_user['id'] == $_SESSION['user_id']) {
                $_SESSION['promotion_error'] = "No puedes ascenderte a ti mismo.";
                header('Location: promotions.php');
                exit();
            } elseif ($target_user['role'] === $new_role) {
                $_SESSION['promotion_error'] = "El usuario ya tiene ese rol.";
                header('Location: promotions.php');
                exit();
            } else {
                // Check permission hierarchy
                $role_hierarchy = ['usuario' => 1, 'operador' => 2, 'administrador' => 3, 'super_admin' => 4];
                $current_user_level = $role_hierarchy[$user_role] ?? 0;
                $target_current_level = $role_hierarchy[$target_user['role']] ?? 0;
                $new_role_level = $role_hierarchy[$new_role] ?? 0;

                if ($current_user_level <= $target_current_level && $user_role !== 'super_admin') {
                    $_SESSION['promotion_error'] = "No tienes permisos para ascender a este usuario.";
                    header('Location: promotions.php');
                    exit();
                } elseif ($new_role_level >= $current_user_level && $user_role !== 'super_admin') {
                    $_SESSION['promotion_error'] = "No puedes ascender a un usuario a un rol igual o superior al tuyo.";
                    header('Location: promotions.php');
                    exit();
                } else {
                    try {
                        $pdo->beginTransaction();

                        // Update user role
                        $stmt = $pdo->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $update_success = $stmt->execute([$new_role, $target_user_id]);

                        if ($update_success) {
                            // Log the promotion
                            $log_stmt = $pdo->prepare("INSERT INTO promotion_log (promoted_user_id, promoted_by_user_id, old_role, new_role, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            
                            // Create promotion_log table if it doesn't exist
                            try {
                                $create_table = $pdo->exec("
                                    CREATE TABLE IF NOT EXISTS promotion_log (
                                        id INT AUTO_INCREMENT PRIMARY KEY,
                                        promoted_user_id INT NOT NULL,
                                        promoted_by_user_id INT NOT NULL,
                                        old_role VARCHAR(50) NOT NULL,
                                        new_role VARCHAR(50) NOT NULL,
                                        reason TEXT,
                                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                        FOREIGN KEY (promoted_user_id) REFERENCES users(id) ON DELETE CASCADE,
                                        FOREIGN KEY (promoted_by_user_id) REFERENCES users(id) ON DELETE CASCADE
                                    )
                                ");
                            } catch (Exception $e) {
                                // Table might already exist, continue
                            }

                            $log_stmt->execute([
                                $target_user_id,
                                $_SESSION['user_id'],
                                $target_user['role'],
                                $new_role,
                                $promotion_reason
                            ]);

                            // Invalidate the promoted user's session for immediate role update
                            try {
                                // Ensure session_invalidations table exists
                                $pdo->exec("
                                    CREATE TABLE IF NOT EXISTS session_invalidations (
                                        user_id INT PRIMARY KEY,
                                        invalidated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                                    )
                                ");
                                
                                // Mark user session for invalidation
                                $stmt_invalidate = $pdo->prepare("INSERT INTO session_invalidations (user_id) VALUES (?) ON DUPLICATE KEY UPDATE invalidated_at = CURRENT_TIMESTAMP");
                                $stmt_invalidate->execute([$target_user_id]);
                                
                                // Also clear any cached user data in PHP sessions
                                if (session_id()) {
                                    // If we can access the session storage, clear user data for this user
                                    $session_path = session_save_path() ?: sys_get_temp_dir();
                                    if (is_dir($session_path)) {
                                        $files = glob($session_path . '/sess_*');
                                        foreach ($files as $file) {
                                            $content = file_get_contents($file);
                                            if (strpos($content, '"user_id";i:' . $target_user_id . ';') !== false) {
                                                // Remove the session file to force re-login
                                                @unlink($file);
                                            }
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                // Session invalidation failed, but promotion was successful
                                error_log("Session invalidation error: " . $e->getMessage());
                            }

                            $pdo->commit();
                            
                            // Use session to pass success message and redirect to avoid POST resubmission
                            $_SESSION['promotion_success'] = "Usuario '{$target_user['habbo_username']}' ascendido exitosamente de '{$target_user['role']}' a '{$new_role}'. Los cambios se aplicarán inmediatamente.";
                            header('Location: promotions.php');
                            exit();
                        } else {
                            $pdo->rollBack();
                            $error_message = "Error al actualizar el rol del usuario.";
                        }
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $_SESSION['promotion_error'] = "Error de base de datos: " . $e->getMessage();
                        header('Location: promotions.php');
                        exit();
                    }
                }
            }
        }
    }
}

// Get users that can be promoted (excluding super_admin from view unless current user is super_admin)
if ($user_role === 'super_admin') {
    $stmt = $pdo->prepare("SELECT id, habbo_username, role, created_at FROM users WHERE id != ? ORDER BY role, habbo_username");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT id, habbo_username, role, created_at FROM users WHERE id != ? AND role != 'super_admin' ORDER BY role, habbo_username");
    $stmt->execute([$_SESSION['user_id']]);
}
$promotable_users = $stmt->fetchAll();

// Get recent promotions log
try {
    $stmt = $pdo->prepare("
        SELECT pl.*, 
               promoted.habbo_username as promoted_username,
               promoter.habbo_username as promoter_username
        FROM promotion_log pl
        LEFT JOIN users promoted ON pl.promoted_user_id = promoted.id
        LEFT JOIN users promoter ON pl.promoted_by_user_id = promoter.id
        ORDER BY pl.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_promotions = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_promotions = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Ascensos - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .promotion-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .user-select-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .user-card {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .user-card:hover {
            border-color: rgba(156, 39, 176, 0.6);
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .user-card.selected {
            border-color: #9c27b0;
            background: rgba(156, 39, 176, 0.2);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #9c27b0, #673ab7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5em;
            font-weight: bold;
        }

        .user-details h4 {
            color: white;
            margin: 0 0 5px 0;
            font-size: 1.2em;
        }

        .user-role {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.9em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .role-usuario { background: rgba(34, 197, 94, 0.3); color: #22c55e; }
        .role-operador { background: rgba(59, 130, 246, 0.3); color: #3b82f6; }
        .role-administrador { background: rgba(239, 68, 68, 0.3); color: #ef4444; }
        .role-super_admin { background: rgba(236, 72, 153, 0.3); color: #ec4899; }

        .promotion-form {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
            display: none;
        }

        .promotion-form.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .role-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 15px 0;
        }

        .role-option {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
            color: white;
        }

        .role-option:hover {
            border-color: rgba(156, 39, 176, 0.6);
            background: rgba(255, 255, 255, 0.15);
        }

        .role-option.selected {
            border-color: #9c27b0;
            background: rgba(156, 39, 176, 0.3);
        }

        .role-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.05);
        }

        .promotions-log {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
        }

        .log-entry {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            border-left: 4px solid #9c27b0;
        }

        .log-entry:last-child {
            margin-bottom: 0;
        }

        .alert {
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 10px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.2);
            color: #ffffff;
            border-color: rgba(34, 197, 94, 0.4);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #ffffff;
            border-color: rgba(239, 68, 68, 0.4);
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-level-up-alt"></i>
                    Sistema de Ascensos
                </h1>
                <div class="header-actions">
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="dashboard-content">
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Promotion System -->
            <div class="promotion-card">
                <h3 class="card-title">
                    <i class="fas fa-user-plus"></i>
                    Ascender Usuario
                </h3>
                <p style="color: rgba(255,255,255,0.8); margin-bottom: 20px;">
                    Selecciona un usuario para ascenderlo a un nuevo rango. Solo puedes ascender usuarios a rangos inferiores al tuyo.
                </p>

                <!-- User Selection -->
                <div class="form-group">
                    <label style="color: white; font-weight: bold; margin-bottom: 15px; display: block;">
                        <i class="fas fa-users"></i>
                        Seleccionar Usuario:
                    </label>
                    <div class="user-select-grid">
                        <?php foreach ($promotable_users as $promotable_user): ?>
                            <div class="user-card" onclick="selectUser(<?php echo $promotable_user['id']; ?>, '<?php echo htmlspecialchars($promotable_user['habbo_username']); ?>', '<?php echo $promotable_user['role']; ?>')">
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($promotable_user['habbo_username'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details">
                                        <h4><?php echo htmlspecialchars($promotable_user['habbo_username']); ?></h4>
                                        <span class="user-role role-<?php echo $promotable_user['role']; ?>">
                                            <?php echo ucfirst($promotable_user['role']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Promotion Form -->
                <form method="POST" class="promotion-form" id="promotionForm">
                    <input type="hidden" name="action" value="promote_user">
                    <input type="hidden" name="user_id" id="selectedUserId" value="">

                    <h4 style="color: white; margin-bottom: 15px;">
                        <i class="fas fa-crown"></i>
                        Ascender a: <span id="selectedUserName"></span>
                    </h4>

                    <div class="form-group">
                        <label style="color: white; font-weight: bold; margin-bottom: 10px; display: block;">Nuevo Rol:</label>
                        <div class="role-selector">
                            <div class="role-option" onclick="selectRole('usuario')" data-role="usuario">
                                <i class="fas fa-user"></i><br>
                                <strong>Usuario</strong><br>
                                <small>Acceso básico</small>
                            </div>
                            <div class="role-option" onclick="selectRole('operador')" data-role="operador">
                                <i class="fas fa-cog"></i><br>
                                <strong>Operador</strong><br>
                                <small>Gestión de tiempos</small>
                            </div>
                            <div class="role-option" onclick="selectRole('administrador')" data-role="administrador">
                                <i class="fas fa-shield-alt"></i><br>
                                <strong>Administrador</strong><br>
                                <small>Gestión completa</small>
                            </div>
                            <?php if ($user_role === 'super_admin'): ?>
                            <div class="role-option" onclick="selectRole('super_admin')" data-role="super_admin">
                                <i class="fas fa-crown"></i><br>
                                <strong>Super Admin</strong><br>
                                <small>Acceso total</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="new_role" id="selectedRole" value="">
                    </div>

                    <div class="form-group">
                        <label for="reason" style="color: white; font-weight: bold;">Motivo del Ascenso:</label>
                        <textarea name="reason" id="reason" class="glass-input" rows="3" placeholder="Explica el motivo del ascenso..." required style="margin-top: 8px;"></textarea>
                    </div>

                    <div style="text-align: center; margin-top: 25px;">
                        <button type="submit" class="glass-button" style="background: linear-gradient(45deg, #9c27b0, #673ab7);">
                            <i class="fas fa-level-up-alt"></i>
                            Confirmar Ascenso
                        </button>
                    </div>
                </form>
            </div>

            <!-- Recent Promotions Log -->
            <?php if (!empty($recent_promotions)): ?>
            <div class="promotions-log">
                <h3 class="card-title">
                    <i class="fas fa-history"></i>
                    Historial de Ascensos Recientes
                </h3>
                <?php foreach ($recent_promotions as $promotion): ?>
                    <div class="log-entry">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <strong style="color: white;">
                                    <?php echo htmlspecialchars($promotion['promoted_username'] ?? 'Usuario eliminado'); ?>
                                </strong>
                                ascendido de 
                                <span class="user-role role-<?php echo $promotion['old_role']; ?>">
                                    <?php echo ucfirst($promotion['old_role']); ?>
                                </span>
                                a 
                                <span class="user-role role-<?php echo $promotion['new_role']; ?>">
                                    <?php echo ucfirst($promotion['new_role']); ?>
                                </span>
                                <br>
                                <small style="color: rgba(255,255,255,0.7);">
                                    Por: <?php echo htmlspecialchars($promotion['promoter_username'] ?? 'Usuario eliminado'); ?>
                                </small>
                                <?php if ($promotion['reason']): ?>
                                    <br>
                                    <small style="color: rgba(255,255,255,0.8);">
                                        <em>"<?php echo htmlspecialchars($promotion['reason']); ?>"</em>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <small style="color: rgba(255,255,255,0.6);">
                                <?php echo date('d/m/Y H:i', strtotime($promotion['created_at'])); ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let selectedUserId = null;
        let selectedUserCurrentRole = null;
        let selectedRole = null;

        function selectUser(userId, username, currentRole) {
            // Remove previous selection
            document.querySelectorAll('.user-card').forEach(card => {
                card.classList.remove('selected');
            });

            // Select current card
            event.currentTarget.classList.add('selected');

            selectedUserId = userId;
            selectedUserCurrentRole = currentRole;
            document.getElementById('selectedUserId').value = userId;
            document.getElementById('selectedUserName').textContent = username;

            // Show promotion form
            document.getElementById('promotionForm').classList.add('active');

            // Reset role selection
            selectedRole = null;
            document.getElementById('selectedRole').value = '';
            document.querySelectorAll('.role-option').forEach(option => {
                option.classList.remove('selected');
                option.classList.remove('disabled');
            });

            // Disable current role option
            const currentRoleOption = document.querySelector(`[data-role="${currentRole}"]`);
            if (currentRoleOption) {
                currentRoleOption.classList.add('disabled');
            }

            // Disable roles based on permissions
            <?php if ($user_role !== 'super_admin'): ?>
            const adminOption = document.querySelector('[data-role="administrador"]');
            if (adminOption && '<?php echo $user_role; ?>' === 'administrador') {
                adminOption.classList.add('disabled');
            }
            <?php endif; ?>
        }

        function selectRole(role) {
            if (event.currentTarget.classList.contains('disabled')) {
                return;
            }

            if (role === selectedUserCurrentRole) {
                return;
            }

            // Remove previous selection
            document.querySelectorAll('.role-option').forEach(option => {
                option.classList.remove('selected');
            });

            // Select current role
            event.currentTarget.classList.add('selected');
            selectedRole = role;
            document.getElementById('selectedRole').value = role;
        }

        // Form validation and submission
        document.getElementById('promotionForm').addEventListener('submit', function(e) {
            if (!selectedUserId || !selectedRole) {
                e.preventDefault();
                alert('Por favor selecciona un usuario y un nuevo rol.');
                return false;
            }

            if (selectedRole === selectedUserCurrentRole) {
                e.preventDefault();
                alert('El usuario ya tiene ese rol.');
                return false;
            }

            const reason = document.getElementById('reason').value.trim();
            if (!reason) {
                e.preventDefault();
                alert('Por favor proporciona un motivo para el ascenso.');
                return false;
            }

            if (!confirm(`¿Estás seguro de ascender al usuario a ${selectedRole}?`)) {
                e.preventDefault();
                return false;
            }

            // Let the form submit normally - no AJAX needed
            return true;
        });
    </script>
    <script src="assets/js/notifications.js"></script>
</body>
</html>
