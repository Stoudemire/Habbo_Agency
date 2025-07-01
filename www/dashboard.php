<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

include 'config/database.php';

// Function to get site title safely with caching
function getSiteTitle() {
    static $cached_title = null;
    if ($cached_title !== null) {
        return $cached_title;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'site_title'");
        $stmt->execute();
        $title = $stmt->fetchColumn();
        $cached_title = $title ? $title : 'Habbo Agency';
        return $cached_title;
    } catch (Exception $e) {
        $cached_title = 'Habbo Agency';
        return $cached_title;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Check if user session has been invalidated (for role updates)
try {
    $stmt_check = $pdo->prepare("SELECT invalidated_at FROM session_invalidations WHERE user_id = ?");
    $stmt_check->execute([$_SESSION['user_id']]);
    $invalidation = $stmt_check->fetch();

    if ($invalidation && (!isset($_SESSION['last_validated']) || $_SESSION['last_validated'] < $invalidation['invalidated_at'])) {
        // Session has been invalidated, force refresh user data
        unset($_SESSION['user_data']);
        $_SESSION['last_validated'] = date('Y-m-d H:i:s');

        // Remove invalidation record
        $stmt_remove = $pdo->prepare("DELETE FROM session_invalidations WHERE user_id = ?");
        $stmt_remove->execute([$_SESSION['user_id']]);
    }
} catch (Exception $e) {
    // Table might not exist yet, continue
}

// Always fetch fresh user data to get real-time role updates
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Update session data with fresh user info
$_SESSION['user_data'] = $user;

$username = $user['username'];
$user_role = $user['role'];
$userInitial = strtoupper(substr($username, 0, 1));

// Get user rank information with permissions
$rank_info = null;
$user_permissions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM user_ranks WHERE rank_name = ?");
    $stmt->execute([$user_role]);
    $rank_info = $stmt->fetch();

    if ($rank_info && !empty($rank_info['permissions'])) {
        $user_permissions = json_decode($rank_info['permissions'], true) ?: [];
    }
} catch (Exception $e) {
    // If no rank found, create default info
    $rank_info = [
        'display_name' => ucfirst($user_role),
        'rank_image' => null,
        'description' => 'Usuario del sistema'
    ];
    $user_permissions = [];
}

// Function to check if user has specific permission
function hasPermission($permission, $user_permissions, $user_role) {
    // Super admin always has all permissions
    if ($user_role === 'super_admin') {
        return true;
    }

    return in_array($permission, $user_permissions);
}

// Handle access denied error
$access_denied = isset($_GET['error']) && $_GET['error'] === 'access_denied';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo getSiteTitle(); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .rank-display-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .rank-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rank-logo {
            flex-shrink: 0;
            position: relative;
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .rank-logo .user-avatar-container {
            width: 150px;
            height: 150px;
        }

        .rank-image-beside {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            margin-left: -5px;
        }

        .rank-details {
            flex: 1;
        }

        .rank-title {
            color: #ffffff;
            font-size: 1.8em;
            margin: 0 0 15px 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            font-weight: 600;
        }

        .rank-mission, .rank-role {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 8px 0;
            width: 100%;
        }

        .mission-label, .role-label {
            font-weight: 600;
            color: rgba(156, 39, 176, 0.9);
            min-width: 60px;
            font-size: 0.95em;
            flex-shrink: 0;
        }

        .mission-text, .role-text {
            color: rgba(255, 255, 255, 0.9);
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            font-size: 0.95em;
            line-height: 1.4;
            min-height: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            box-sizing: border-box;
            flex: 1;
            max-width: none;
            min-width: 0;
        }

        .copy-btn {
            background: rgba(156, 39, 176, 0.2);
            border: 1px solid rgba(156, 39, 176, 0.4);
            color: #9c27b0;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            flex-shrink: 0;
        }

        .copy-btn:hover {
            background: rgba(156, 39, 176, 0.3);
            border-color: rgba(156, 39, 176, 0.6);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(156, 39, 176, 0.2);
        }

        .copy-btn:active {
            transform: translateY(0);
        }

        .copy-btn i {
            font-size: 0.9em;
        }

        .user-avatar-container {
            position: relative;
            width: 150px;
            height: 150px;
            border-radius: 12px;
            overflow: hidden;            border: 2px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);
        }

        .habbo-avatar {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-avatar-fallback {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.5em;
        }

        .rank-badge {
            position: absolute;
            top: 15px;
            right: -8px;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 8px;
            border: 3px solid rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }

        .rank-badge-img {
            width: 28px;
            height: 28px;
            object-fit: contain;
            border-radius: 4px;
        }

        /* Static role colors for dashboard */
        .user-role-badge, .role-text {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            border: 1px solid rgba(255, 255, 255, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        /* Static role colors - Core roles */
        .role-super_admin { background: rgba(239, 68, 68, 0.3) !important; color: #ef4444 !important; }
        .role-administrador { background: rgba(59, 130, 246, 0.3) !important; color: #3b82f6 !important; }
        .role-admin { background: rgba(59, 130, 246, 0.3) !important; color: #3b82f6 !important; }
        .role-operador { background: rgba(147, 51, 234, 0.3) !important; color: #9333ea !important; }
        .role-usuario { background: rgba(156, 163, 175, 0.3) !important; color: #9ca3af !important; }

        /* Default color for unmapped roles */
        .role-text:not(.role-super_admin):not(.role-administrador):not(.role-admin):not(.role-operador):not(.role-usuario) {
            background: rgba(156, 163, 175, 0.3) !important;
            color: #9ca3af !important;
        }

        @media (max-width: 768px) {
            .rank-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .rank-mission, .rank-role {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .mission-label, .role-label {
                min-width: auto;
                text-align: center;
            }

            .user-avatar-container {
                width: 50px;
                height: 50px;
            }

            .rank-badge {
                width: 20px;
                height: 20px;
                right: -4px;
                top: 8px;
            }

            .rank-badge-img {
                width: 14px;
                height: 14px;
            }
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </h1>

                <div class="user-section">
                    <div class="user-info">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <?php if (isset($rank_info['rank_image']) && !empty($rank_info['rank_image']) && file_exists($rank_info['rank_image'])): ?>
                                <div style="width: 70px; height: 70px; border-radius: 8px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.3); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                                    <img src="<?php echo htmlspecialchars($rank_info['rank_image']); ?>" alt="Imagen del rango" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;">
                                </div>
                            <?php endif; ?>
                            <div class="user-avatar-container" style="width: 70px; height: 70px; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                            <img src="https://www.habbo.es/habbo-imaging/avatarimage?img_format=png&user=<?php echo urlencode($user['habbo_username']); ?>&direction=2&head_direction=3&size=l&gesture=std&action=std&headonly=1" 
                                 alt="Avatar de <?php echo htmlspecialchars($user['habbo_username']); ?>" 
                                 class="habbo-avatar"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="user-avatar-fallback" style="display: none;">
                                <?php echo $userInitial; ?>
                            </div>
                            </div>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($user['habbo_username']); ?></h3>
                            <p><?php echo $user_role === 'super_admin' ? 'Desarrollador' : htmlspecialchars($rank_info['display_name'] ?? ucfirst($user_role)); ?></p>
                        </div>
                    </div>

                    <a href="?logout=1" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        Salir
                    </a>
                </div>
            </div>
        </div>

        <?php if ($access_denied): ?>
        <div class="alert alert-error">
            Acceso denegado. No tienes permisos para acceder a esa sección.
        </div>
        <?php endif; ?>

        <!-- User Rank Information Card -->
        <div class="rank-display-card">
            <div class="rank-header">
                <div class="rank-logo">
                    <div class="user-avatar-container">
                        <img src="https://www.habbo.es/habbo-imaging/avatarimage?img_format=png&user=<?php echo urlencode($user['habbo_username']); ?>&direction=2&head_direction=3&size=l&gesture=std&action=std" 
                             alt="Avatar de <?php echo htmlspecialchars($user['habbo_username']); ?>" 
                             class="habbo-avatar"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="user-avatar-fallback" style="display: none;">
                            <?php echo $userInitial; ?>
                        </div>
                    </div>
                </div>
                <div class="rank-details">
                    <h2 class="rank-title"><?php echo htmlspecialchars($user['habbo_username']); ?></h2>
                    <div class="rank-mission">
                        <span class="mission-label">Misión:</span>
                        <span class="mission-text" id="mission-text"><?php echo htmlspecialchars($rank_info['description'] ?? 'Miembro del equipo'); ?></span>
                        <button class="copy-btn" onclick="copyMissionText()" title="Copiar misión">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <div class="rank-role">
                        <span class="role-label">Rol:</span>
                        <span class="role-text" id="role-text"><?php echo htmlspecialchars($rank_info['display_name'] ?? ucfirst($user_role)); ?></span>
                        <button class="copy-btn" onclick="copyRoleText()" title="Copiar rol">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Content Based on Permissions -->
        <div class="dashboard-grid">
            <!-- Time Manager Card - Available to users with manage_time permission or ALL users can view -->
            <div class="dashboard-card" onclick="window.location.href='time-manager.php'" style="cursor: pointer;">
                <h3 class="card-title">
                    <i class="fas fa-<?php echo hasPermission('manage_time', $user_permissions, $user_role) ? 'clock' : 'eye'; ?>"></i>
                    <?php echo hasPermission('manage_time', $user_permissions, $user_role) ? 'Gestor de Tiempos' : 'Mis Tiempos Activos'; ?>
                </h3>
                <p class="card-description">
                    <?php if (hasPermission('manage_time', $user_permissions, $user_role)): ?>
                        Controla los tiempos de trabajo de todos los usuarios. Inicia, pausa y gestiona cronómetros del equipo.
                    <?php else: ?>
                        Consulta tus tiempos activos y el estado de tu sesión de trabajo actual.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Schedule Card - Available to ALL users -->
            <div class="dashboard-card" onclick="window.location.href='schedule.php'" style="cursor: pointer;">
                <h3 class="card-title">
                    <i class="fas fa-calendar-alt"></i>
                    Horarios de Apertura
                </h3>
                <p class="card-description">
                    Consulta los horarios de funcionamiento de la agencia, días especiales y estados de apertura en tiempo real.
                </p>
            </div>



            <?php if (in_array($user_role, ['super_admin', 'administrador'])): ?>
            <!-- Promotions System Card - Only for administrators and super_admin -->
            <div class="dashboard-card" onclick="window.location.href='promotions.php'" style="cursor: pointer;">
                <h3 class="card-title">
                    <i class="fas fa-level-up-alt"></i>
                    Sistema de Ascensos
                </h3>
                <p class="card-description">
                    Ascender usuarios entre rangos. Promueve empleados de usuario a operador, o de operador a administrador según su desempeño.
                </p>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_roles', $user_permissions, $user_role)): ?>
            <!-- Rank Management Card - Only for users with manage_roles permission -->
            <div class="dashboard-card" onclick="window.location.href='rank-management.php'" style="cursor: pointer;">
                <h3 class="card-title">
                    <i class="fas fa-crown"></i>
                    Gestión de Rangos
                </h3>
                <p class="card-description">
                    Administra los rangos del sistema. Crea nuevos roles, define permisos y configura niveles de acceso.
                </p>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_config', $user_permissions, $user_role)): ?>
            <!-- System Configuration Card - Only for users with manage_config permission -->
            <div class="dashboard-card" onclick="window.location.href='developer-panel.php'" style="cursor: pointer;">
                <h3 class="card-title">
                    <i class="fas fa-tools"></i>
                    Panel de Desarrollador
                </h3>
                <p class="card-description">
                    Configuración avanzada del sistema. Personaliza la apariencia, logos, títulos y parámetros técnicos.
                </p>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_users', $user_permissions, $user_role)): ?>
            <!-- User Management Card - Only for users with manage_users permission -->
            <div class="dashboard-card" onclick="window.location.href='lista-pagas.php'" style="cursor: pointer;">
                <h3 class="card-title">
                    <i class="fas fa-money-bill-wave"></i>
                    Lista de Pagas
                </h3>
                <p class="card-description">
                    Gestiona los pagos del personal. Marca usuarios como pagados y mantén un historial de transacciones.
                </p>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <script src="assets/js/notifications.js"></script>
    <script>
        function copyMissionText() {
            const missionText = document.getElementById('mission-text').textContent;
            navigator.clipboard.writeText(missionText).then(function() {
                window.notifications.success('Misión copiada al portapapeles');
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = missionText;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                window.notifications.success('Misión copiada al portapapeles');
            });
        }

        function copyRoleText() {
            const roleText = document.getElementById('role-text').textContent;
            navigator.clipboard.writeText(roleText).then(function() {
                window.notifications.success('Rol copiado al portapapeles');
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = roleText;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                window.notifications.success('Rol copiado al portapapeles');
            });
        }

        // Real-time role update function
        function checkRoleUpdate() {
            fetch('api/get_user_role_update.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update role display
                        const roleTextElements = document.querySelectorAll('.role-text');
                        roleTextElements.forEach(element => {
                            element.textContent = data.display_name;
                        });

                        // Update mission text instead of title (title should remain as character name)
                        const missionText = document.querySelector('.mission-text');
                        if (missionText) {
                            missionText.textContent = data.description || 'Miembro del equipo';
                        }

                        // Update header role
                        const headerRole = document.querySelector('.user-details p');
                        if (headerRole) {
                            headerRole.textContent = data.role === 'super_admin' ? 'Desarrollador' : data.display_name;
                        }

                        // Update rank image if exists
                        if (data.rank_image) {
                            const rankImages = document.querySelectorAll('.rank-image');
                            rankImages.forEach(img => {
                                img.src = data.rank_image;
                                img.style.display = 'block';
                            });
                        }

                        // Apply role colors dynamically
                        applyRoleColors(data.role);
                    }
                })
                .catch(error => {
                    console.log('Role update check failed:', error);
                });
        }

        // Check for role updates every 3 seconds
        setInterval(checkRoleUpdate, 3000);

        // Function to apply role colors based on role name (only for color, not width)
        function applyRoleColors(roleName) {
            const roleElements = document.querySelectorAll('.role-text, .user-role-badge');
            
            roleElements.forEach(element => {
                // Only update the color style with !important to override CSS
                const roleColorMap = {
                    'super_admin': { bg: 'rgba(239, 68, 68, 0.3)', color: '#ef4444' },
                    'administrador': { bg: 'rgba(59, 130, 246, 0.3)', color: '#3b82f6' },
                    'admin': { bg: 'rgba(59, 130, 246, 0.3)', color: '#3b82f6' },
                    'operador': { bg: 'rgba(147, 51, 234, 0.3)', color: '#9333ea' },
                    'usuario': { bg: 'rgba(156, 163, 175, 0.3)', color: '#9ca3af' }
                };
                
                const colors = roleColorMap[roleName] || { bg: 'rgba(156, 163, 175, 0.3)', color: '#9ca3af' };
                element.style.setProperty('background', colors.bg, 'important');
                element.style.setProperty('color', colors.color, 'important');
            });
        }

        // Apply initial role color on page load
        document.addEventListener('DOMContentLoaded', function() {
            const currentRole = '<?php echo $user_role; ?>';
            applyRoleColors(currentRole);
        });
    </script>
</body>
</html>