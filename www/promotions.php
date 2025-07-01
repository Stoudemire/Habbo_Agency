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

// Handle AJAX requests for getting fresh history data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_history') {
    // Clean any output buffer
    ob_clean();
    header('Content-Type: application/json');

    try {
        $stmt = $pdo->prepare("
            SELECT pl.*, 
                   promoted.habbo_username as promoted_username,
                   promoted.id as promoted_user_id,
                   promoter.habbo_username as promoter_username
            FROM promotion_log pl
            LEFT JOIN users promoted ON pl.promoted_user_id = promoted.id
            LEFT JOIN users promoter ON pl.promoted_by_user_id = promoter.id
            ORDER BY pl.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $history_data = $stmt->fetchAll();

        echo json_encode(['success' => true, 'history' => $history_data]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle AJAX requests for getting fresh user data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_users') {
    // Clean any output buffer
    ob_clean();
    header('Content-Type: application/json');

    try {
        if ($user_role === 'super_admin') {
            $stmt = $pdo->prepare("
                SELECT u.id, u.habbo_username, u.role, u.created_at, COALESCE(r.level, 999) as role_level
                FROM users u 
                LEFT JOIN user_ranks r ON u.role = r.rank_name 
                WHERE u.id != ? 
                ORDER BY role_level DESC, u.habbo_username ASC 
                LIMIT 5
            ");
            $stmt->execute([$_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("
                SELECT u.id, u.habbo_username, u.role, u.created_at, COALESCE(r.level, 999) as role_level
                FROM users u 
                LEFT JOIN user_ranks r ON u.role = r.rank_name 
                WHERE u.id != ? AND u.role != 'super_admin' 
                ORDER BY role_level DESC, u.habbo_username ASC 
                LIMIT 5
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        $users_data = $stmt->fetchAll();

        echo json_encode(['success' => true, 'users' => $users_data]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// Get all available ranks from database
$available_ranks = [];
$rank_hierarchy = [];
try {
    if ($user_role === 'super_admin') {
        $stmt = $pdo->prepare("SELECT rank_name, display_name, level FROM user_ranks ORDER BY level ASC");
    } else {
        $stmt = $pdo->prepare("SELECT rank_name, display_name, level FROM user_ranks WHERE rank_name != 'super_admin' ORDER BY level ASC");
    }
    $stmt->execute();
    $ranks_data = $stmt->fetchAll();

    foreach ($ranks_data as $rank) {
        $available_ranks[] = $rank['rank_name'];
        $rank_hierarchy[$rank['rank_name']] = $rank['level'];
    }
} catch (Exception $e) {
    // Fallback to default roles if there's an error
    $available_ranks = ['usuario', 'operador', 'administrador'];
    $rank_hierarchy = ['usuario' => 1, 'operador' => 2, 'administrador' => 3, 'super_admin' => 4];
    if ($user_role === 'super_admin') {
        $available_ranks[] = 'super_admin';
    }
}

// Handle promotion action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clean any output buffer and return JSON for AJAX requests
    ob_clean();
    header('Content-Type: application/json');

    if ($_POST['action'] === 'promote_user') {
        try {
            $target_user_id = intval($_POST['user_id']);
            $new_role = trim($_POST['new_role']);
            $promotion_reason = trim($_POST['reason'] ?? '');
            $reason_type = $_POST['reason_type'] ?? 'manual';

            // Auto-generate reason if automatic
            if ($reason_type === 'automatic') {
                $target_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $target_stmt->execute([$target_user_id]);
                $current_role = $target_stmt->fetchColumn();

                $current_level = $rank_hierarchy[$current_role] ?? 0;
                $new_level = $rank_hierarchy[$new_role] ?? 0;

                if ($new_level > $current_level) {
                    $promotion_reason = "Ascenso por buen desempeño y cumplimiento de responsabilidades";
                } else {
                    $promotion_reason = "Degradación de rango por decisión administrativa";
                }
            }

            // Validate the new role
            if (!in_array($new_role, $available_ranks)) {
                echo json_encode(['success' => false, 'message' => 'Rol no válido seleccionado.']);
                exit();
            }

            // Get target user info
            $stmt = $pdo->prepare("SELECT id, habbo_username, role FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $target_user = $stmt->fetch();

            if (!$target_user) {
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
                exit();
            }

            if ($target_user['id'] == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'No puedes modificar tu propio rango.']);
                exit();
            }

            if ($target_user['role'] === $new_role) {
                echo json_encode(['success' => false, 'message' => 'El usuario ya tiene ese rol.']);
                exit();
            }

            // Check permission hierarchy
            $current_user_level = $rank_hierarchy[$user_role] ?? 0;
            $target_current_level = $rank_hierarchy[$target_user['role']] ?? 0;
            $new_role_level = $rank_hierarchy[$new_role] ?? 0;

            if ($current_user_level <= $target_current_level && $user_role !== 'super_admin') {
                echo json_encode(['success' => false, 'message' => 'No tienes permisos para modificar a este usuario.']);
                exit();
            }

            if ($new_role_level >= $current_user_level && $user_role !== 'super_admin') {
                echo json_encode(['success' => false, 'message' => 'No puedes asignar un rol igual o superior al tuyo.']);
                exit();
            }

            // Create promotion_log table if it doesn't exist (outside transaction)
            try {
                $pdo->exec("
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

            // Start transaction for the actual operations
            $pdo->beginTransaction();

            // Update user role
            $stmt = $pdo->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update_success = $stmt->execute([$new_role, $target_user_id]);

            if (!$update_success) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error al actualizar el rol del usuario.']);
                exit();
            }

            // Log the promotion
            $log_stmt = $pdo->prepare("INSERT INTO promotion_log (promoted_user_id, promoted_by_user_id, old_role, new_role, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $log_success = $log_stmt->execute([
                $target_user_id,
                $_SESSION['user_id'],
                $target_user['role'],
                $new_role,
                $promotion_reason
            ]);

            if (!$log_success) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error al registrar el cambio en el historial.']);
                exit();
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Usuario '{$target_user['habbo_username']}' modificado exitosamente de '{$target_user['role']}' a '{$new_role}'.",
                'user_id' => $target_user_id,
                'new_role' => $new_role,
                'username' => $target_user['habbo_username']
            ]);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Error al procesar la solicitud: ' . $e->getMessage()]);
            exit();
        }
    }
}

// Get users that can be promoted (excluding super_admin from view unless current user is super_admin) - Limited to 5
if ($user_role === 'super_admin') {
    $stmt = $pdo->prepare("
        SELECT u.id, u.habbo_username, u.role, u.created_at, COALESCE(r.level, 999) as role_level
        FROM users u 
        LEFT JOIN user_ranks r ON u.role = r.rank_name 
        WHERE u.id != ? 
        ORDER BY role_level DESC, u.habbo_username ASC 
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT u.id, u.habbo_username, u.role, u.created_at, COALESCE(r.level, 999) as role_level
        FROM users u 
        LEFT JOIN user_ranks r ON u.role = r.rank_name 
        WHERE u.id != ? AND u.role != 'super_admin' 
        ORDER BY role_level DESC, u.habbo_username ASC 
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
}
$promotable_users = $stmt->fetchAll();

// Get recent promotions log - Limited to 5
try {
    $stmt = $pdo->prepare("
        SELECT pl.*, 
               promoted.habbo_username as promoted_username,
               promoter.habbo_username as promoter_username
        FROM promotion_log pl
        LEFT JOIN users promoted ON pl.promoted_user_id = promoted.id
        LEFT JOIN users promoter ON pl.promoted_by_user_id = promoter.id
        ORDER BY pl.created_at DESC
        LIMIT 5
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
    <title>Gestión de Ascensos - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .promotions-container {
            display: flex;
            flex-direction: column;
            gap: 25px;
            max-width: 100%;
        }

        .main-table-section {
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .search-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            padding: 12px 20px 12px 45px;
            color: #ffffff;
            font-size: 16px;
        }

        .search-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
        }

        .users-table-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            overflow: hidden;
            height: 500px;
            display: flex;
            flex-direction: column;
        }

        .table-header {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
            height: 70px;
            box-sizing: border-box;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            color: #ffffff;
        }

        .table-scroll {
            flex: 1;
            overflow-y: auto;
            height: 430px;
            max-height: 430px;
        }

        .users-table th {
            background: rgba(255, 255, 255, 0.15);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #ffffff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            height: 50px;
            box-sizing: border-box;
        }

        .users-table td {
            padding: 12px 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            height: 82px;
            box-sizing: border-box;
        }

        .users-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .user-role-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }

        /* Static role colors */
        .role-super_admin { background: rgba(239, 68, 68, 0.3); color: #ef4444; }
        .role-administrador { background: rgba(59, 130, 246, 0.3); color: #3b82f6; }
        .role-admin { background: rgba(59, 130, 246, 0.3); color: #3b82f6; }
        .role-operador { background: rgba(147, 51, 234, 0.3); color: #9333ea; }
        
        /* All other roles get gray color */
        .user-role-badge {
            background: rgba(156, 163, 175, 0.3);
            color: #9ca3af;
        }
        
        /* Override for specific roles */
        .user-role-badge.role-super_admin { background: rgba(239, 68, 68, 0.3); color: #ef4444; }
        .user-role-badge.role-administrador { background: rgba(59, 130, 246, 0.3); color: #3b82f6; }
        .user-role-badge.role-admin { background: rgba(59, 130, 246, 0.3); color: #3b82f6; }
        .user-role-badge.role-operador { background: rgba(147, 51, 234, 0.3); color: #9333ea; }

        .action-btn {
            background: linear-gradient(45deg, #9c27b0, #673ab7);
            border: none;
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(156, 39, 176, 0.4);
        }

        .history-section {
            display: flex;
            flex-direction: column;
            width: 100%;
            margin-top: 20px;
        }

        .history-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px;
            height: 500px;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            width: 100%;
        }

        .history-scroll {
            flex: 1;
            overflow-y: auto;
            margin-top: 15px;
            height: 360px;
            max-height: 360px;
        }

        .history-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 8px;
            border-left: 4px solid #9c27b0;
            min-height: 70px;
            box-sizing: border-box;
        }

        .history-search {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            padding: 10px;
            color: #ffffff;
            width: 100%;
            margin-bottom: 15px;
        }

        .history-search::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        /* Modal Styles */
        .promotion-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-title {
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close-modal {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
        }

        .close-modal:hover {
            color: #ffffff;
        }

        .role-selection {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .role-card {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
            color: #ffffff;
        }

        .role-card:hover {
            border-color: rgba(156, 39, 176, 0.6);
            background: rgba(255, 255, 255, 0.15);
        }

        .role-card.selected {
            border-color: #9c27b0;
            background: rgba(156, 39, 176, 0.3);
        }

        .role-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .reason-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }

        .reason-type-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .reason-type-btn.active {
            border-color: #9c27b0;
            background: rgba(156, 39, 176, 0.3);
        }

        .manual-reason {
            display: none;
            margin-top: 15px;
        }

        .manual-reason.show {
            display: block;
        }

        .submit-promotion {
            background: linear-gradient(45deg, #9c27b0, #673ab7);
            border: none;
            color: #ffffff;
            padding: 12px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 20px;
        }

        /* Custom Glass-morphism Scrollbars */
        .table-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .table-scroll::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            backdrop-filter: blur(5px);
        }

        .table-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.6), 
                rgba(156, 39, 176, 0.8));
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .table-scroll::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.8), 
                rgba(156, 39, 176, 1));
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
        }

        .table-scroll::-webkit-scrollbar-thumb:active {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 1), 
                rgba(120, 30, 140, 1));
        }

        /* History scroll custom scrollbar */
        .history-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .history-scroll::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            backdrop-filter: blur(5px);
        }

        .history-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.6), 
                rgba(156, 39, 176, 0.8));
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .history-scroll::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.8), 
                rgba(156, 39, 176, 1));
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
        }

        .history-scroll::-webkit-scrollbar-thumb:active {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 1), 
                rgba(120, 30, 140, 1));
        }

        @media (max-width: 1024px) {
            .promotions-container {
                gap: 20px;
            }

            .users-table-container {
                height: 400px;
            }

            .table-scroll {
                height: 330px;
                max-height: 330px;
            }

            .history-container {
                height: 400px;
            }

            .history-scroll {
                height: 260px;
                max-height: 260px;
            }
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-users-cog"></i>
                    Gestión de Ascensos y Degradaciones
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
            <div class="promotions-container">
                <!-- Main Table Section -->
                <div class="main-table-section">
                    <!-- Search -->
                    <div class="search-container">
                        <div style="position: relative;">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="userSearch" class="search-input" placeholder="Buscar usuario por nombre...">
                        </div>
                    </div>

                    <!-- Users Table Container -->
                    <div class="users-table-container">
                        <div class="table-header">
                            <h3 class="card-title">
                                <i class="fas fa-users"></i>
                                Lista de Usuarios
                            </h3>
                        </div>
                        <div class="table-scroll">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Rol Actual</th>
                                        <th>Fecha de Registro</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTableBody">
                                    <?php foreach ($promotable_users as $promotable_user): ?>
                                        <tr data-user-id="<?php echo $promotable_user['id']; ?>" data-username="<?php echo htmlspecialchars($promotable_user['habbo_username']); ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 15px;">
                                                    <div style="width: 70px; height: 70px; border-radius: 12px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.2); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                                                        <img src="https://www.habbo.es/habbo-imaging/avatarimage?user=<?php echo urlencode($promotable_user['habbo_username']); ?>&action=std&direction=2&head_direction=3&img_format=png&gesture=std&headonly=1&size=l" 
                                                             alt="Avatar de <?php echo htmlspecialchars($promotable_user['habbo_username']); ?>"
                                                             style="width: 100%; height: 100%; object-fit: cover; display: block;"
                                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                        <div style="width: 100%; height: 100%; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%); display: none; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.5em;">
                                                            <?php echo strtoupper(substr($promotable_user['habbo_username'], 0, 1)); ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div style="color: #ffffff; font-weight: 500;">
                                                            <?php echo htmlspecialchars($promotable_user['habbo_username']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="user-role-badge role-<?php echo $promotable_user['role']; ?>">
                                                    <?php echo ucfirst($promotable_user['role']); ?>
                                                </span>
                                            </td>
                                            <td style="color: rgba(255,255,255,0.8);">
                                                <?php echo date('d/m/Y', strtotime($promotable_user['created_at'])); ?>
                                            </td>
                                            <td>
                                                <button class="action-btn" onclick="openPromotionModal(<?php echo $promotable_user['id']; ?>, '<?php echo htmlspecialchars($promotable_user['habbo_username']); ?>', '<?php echo $promotable_user['role']; ?>')">
                                                    <i class="fas fa-edit"></i>
                                                    Gestionar
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- History Section -->
                <div class="history-section">
                    <div class="history-container">
                        <h3 class="card-title">
                            <i class="fas fa-history"></i>
                            Historial de Cambios
                        </h3>
                        <input type="text" id="historySearch" class="history-search" placeholder="Buscar en historial...">

                        <div class="history-scroll" id="historyContainer">
                            <?php foreach ($recent_promotions as $promotion): ?>
                                <div class="history-item" data-username="<?php echo htmlspecialchars($promotion['promoted_username'] ?? ''); ?>" data-promoter="<?php echo htmlspecialchars($promotion['promoter_username'] ?? ''); ?>" data-reason="<?php echo htmlspecialchars($promotion['reason'] ?? ''); ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <?php if ($promotion['promoted_username']): ?>
                                                <div style="width: 70px; height: 70px; border-radius: 12px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.2); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                                                    <img src="https://www.habbo.es/habbo-imaging/avatarimage?user=<?php echo urlencode($promotion['promoted_username']); ?>&action=std&direction=2&head_direction=3&img_format=png&gesture=std&headonly=1&size=l" 
                                                         alt="Avatar de <?php echo htmlspecialchars($promotion['promoted_username']); ?>"
                                                         style="width: 100%; height: 100%; object-fit: cover; display: block;"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div style="width: 100%; height: 100%; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%); display: none; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.5em;">
                                                        <?php echo strtoupper(substr($promotion['promoted_username'], 0, 1)); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <strong style="color: #ffffff;">
                                                <?php echo htmlspecialchars($promotion['promoted_username'] ?? 'Usuario eliminado'); ?>
                                            </strong>
                                        </div>
                                        <small style="color: rgba(255,255,255,0.6);">
                                            <?php echo date('d/m/Y H:i', strtotime($promotion['created_at'])); ?>
                                        </small>
                                    </div>
                                    <div style="color: rgba(255,255,255,0.9); margin-bottom: 5px;">
                                        <span class="user-role-badge role-<?php echo $promotion['old_role']; ?>" style="font-size: 0.7em;">
                                            <?php echo ucfirst($promotion['old_role']); ?>
                                        </span>
                                        <i class="fas fa-arrow-right" style="margin: 0 8px; color: rgba(255,255,255,0.6);"></i>
                                        <span class="user-role-badge role-<?php echo $promotion['new_role']; ?>" style="font-size: 0.7em;">
                                            <?php echo ucfirst($promotion['new_role']); ?>
                                        </span>
                                    </div>
                                    <small style="color: rgba(255,255,255,0.7);">
                                        Por: <?php echo htmlspecialchars($promotion['promoter_username'] ?? 'Usuario eliminado'); ?>
                                    </small>
                                    <?php if ($promotion['reason']): ?>
                                        <div style="margin-top: 8px; padding: 8px; background: rgba(255,255,255,0.05); border-radius: 6px;">
                                            <small style="color: rgba(255,255,255,0.8); font-style: italic;">
                                                "<?php echo htmlspecialchars($promotion['reason']); ?>"
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Promotion Modal -->
    <div id="promotionModal" class="promotion-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Gestionar Usuario</h3>
                <button class="close-modal" onclick="closePromotionModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="promotionForm">
                <input type="hidden" id="modalUserId" name="user_id">
                <input type="hidden" id="modalAction" name="action" value="promote_user">

                <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 70px; height: 70px; border-radius: 12px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.2); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                            <img id="modalUserAvatarImg" style="width: 100%; height: 100%; object-fit: cover; display: block;" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div id="modalUserAvatar" style="width: 100%; height: 100%; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%); display: none; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.5em;"></div>
                        </div>
                        <div>
                            <h4 id="modalUserName" style="color: #ffffff; margin: 0;"></h4>
                            <p id="modalCurrentRole" style="color: rgba(255,255,255,0.7); margin: 5px 0 0 0; font-size: 14px;"></p>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label style="color: #ffffff; font-weight: bold; margin-bottom: 10px; display: block;">
                        Nuevo Rol:
                    </label>
                    <div class="role-selection">
                        <?php foreach ($ranks_data as $rank): ?>
                            <?php if ($user_role !== 'super_admin' && $rank['rank_name'] === 'super_admin') continue; ?>
                            <div class="role-card" data-role="<?php echo $rank['rank_name']; ?>" onclick="selectModalRole('<?php echo $rank['rank_name']; ?>')">
                                <?php
                                // Asignar iconos según el tipo de rol
                                $role_icons = [
                                    'usuario' => 'fas fa-user',
                                    'operador' => 'fas fa-cog',
                                    'administrador' => 'fas fa-shield-alt',
                                    'super_admin' => 'fas fa-crown'
                                ];
                                // Los rangos personalizados usan el mismo icono que usuario
                                $icon = $role_icons[$rank['rank_name']] ?? 'fas fa-user';
                                ?>
                                <i class="<?php echo $icon; ?>" style="font-size: 24px; margin-bottom: 8px;"></i>
                                <div style="font-weight: bold;"><?php echo htmlspecialchars($rank['display_name']); ?></div>
                                <small>Nivel <?php echo $rank['level']; ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="modalSelectedRole" name="new_role">
                </div>

                <div class="form-group">
                    <label style="color: #ffffff; font-weight: bold; margin-bottom: 10px; display: block;">
                        Tipo de Motivo:
                    </label>
                    <div class="reason-type-selector">
                        <button type="button" class="reason-type-btn active" id="autoReasonBtn" onclick="selectReasonType('automatic')">
                            <i class="fas fa-magic"></i><br>
                            Automático
                        </button>
                        <button type="button" class="reason-type-btn" id="manualReasonBtn" onclick="selectReasonType('manual')">
                            <i class="fas fa-edit"></i><br>
                            Manual
                        </button>
                    </div>
                    <input type="hidden" id="modalReasonType" name="reason_type" value="automatic">
                </div>

                <div class="manual-reason" id="manualReasonContainer">
                    <label style="color: #ffffff; font-weight: bold; margin-bottom: 8px; display: block;">
                        Motivo Personalizado:
                    </label>
                    <textarea id="modalReason" name="reason" class="glass-input" rows="3" placeholder="Explica el motivo del cambio de rango..."></textarea>
                </div>

                <button type="submit" class="submit-promotion">
                    <i class="fas fa-save"></i>
                    Confirmar Cambio de Rango
                </button>
            </form>
        </div>
    </div>

    <script src="assets/js/notifications.js"></script>
    <script>
        let currentModalUser = null;
        let selectedModalRole = null;
        let currentReasonType = 'automatic';

        // Search functionality for users
        document.getElementById('userSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#usersTableBody tr');

            rows.forEach(row => {
                const username = row.dataset.username.toLowerCase();
                if (username.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // History search functionality (fixed like userSearch)
        document.getElementById('historySearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const items = document.querySelectorAll('.history-item');

            items.forEach(item => {
                const username = (item.dataset.username || '').toLowerCase();
                const promoter = (item.dataset.promoter || '').toLowerCase();
                const reason = (item.dataset.reason || '').toLowerCase();

                if (username.includes(searchTerm) || promoter.includes(searchTerm) || reason.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });

        function openPromotionModal(userId, username, currentRole) {
            currentModalUser = { id: userId, username: username, role: currentRole };

            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalUserName').textContent = username;

            // Set avatar image
            const avatarImg = document.getElementById('modalUserAvatarImg');
            const avatarFallback = document.getElementById('modalUserAvatar');
            avatarImg.src = `https://www.habbo.es/habbo-imaging/avatarimage?user=${encodeURIComponent(username)}&action=std&direction=2&head_direction=3&img_format=png&gesture=std&headonly=1&size=l`;
            avatarImg.style.display = 'block';
            avatarFallback.style.display = 'none';
            avatarFallback.textContent = username.charAt(0).toUpperCase();

            document.getElementById('modalCurrentRole').textContent = `Rol actual: ${currentRole.charAt(0).toUpperCase() + currentRole.slice(1)}`;

            // Reset form
            selectedModalRole = null;
            document.getElementById('modalSelectedRole').value = '';
            document.querySelectorAll('.role-card').forEach(card => {
                card.classList.remove('selected', 'disabled');
            });

            // Disable current role
            const currentRoleCard = document.querySelector(`[data-role="${currentRole}"]`);
            if (currentRoleCard) {
                currentRoleCard.classList.add('disabled');
            }

            // Reset reason type
            selectReasonType('automatic');

            document.getElementById('promotionModal').style.display = 'flex';
        }

        function closePromotionModal() {
            document.getElementById('promotionModal').style.display = 'none';
            currentModalUser = null;
            selectedModalRole = null;
        }

        function selectModalRole(role) {
            if (currentModalUser && role === currentModalUser.role) return;

            const roleCard = document.querySelector(`[data-role="${role}"]`);
            if (roleCard.classList.contains('disabled')) return;

            document.querySelectorAll('.role-card').forEach(card => {
                card.classList.remove('selected');
            });

            roleCard.classList.add('selected');
            selectedModalRole = role;
            document.getElementById('modalSelectedRole').value = role;
        }

        function selectReasonType(type) {
            currentReasonType = type;
            document.getElementById('modalReasonType').value = type;

            // Update buttons
            document.querySelectorAll('.reason-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            if (type === 'automatic') {
                document.getElementById('autoReasonBtn').classList.add('active');
                document.getElementById('manualReasonContainer').classList.remove('show');
            } else {
                document.getElementById('manualReasonBtn').classList.add('active');
                document.getElementById('manualReasonContainer').classList.add('show');
            }
        }

        // Form submission (simplified and fixed)
        document.getElementById('promotionForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!selectedModalRole) {
                showNotification('Por favor selecciona un nuevo rol.', 'error');
                return;
            }

            if (currentReasonType === 'manual' && !document.getElementById('modalReason').value.trim()) {
                showNotification('Por favor proporciona un motivo personalizado.', 'error');
                return;
            }

            const formData = new FormData(this);

            // Disable submit button to prevent double submission
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

            fetch('promotions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closePromotionModal();

                    // Update table row in real time
                    updateUserTableRow(data.user_id, data.new_role);

                    // Refresh both users and history sections immediately
                    setTimeout(() => {
                        refreshUsersTable();
                        refreshHistorySection();
                    }, 100);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al procesar la solicitud: ' + error.message, 'error');
            })
            .finally(() => {
                // Re-enable submit button
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Close modal on outside click
        document.getElementById('promotionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePromotionModal();
            }
        });

        // Function to update user table row in real time
        function updateUserTableRow(userId, newRole) {
            const row = document.querySelector(`tr[data-user-id="${userId}"]`);
            if (row) {
                // Update role badge
                const roleBadge = row.querySelector('.user-role-badge');
                if (roleBadge) {
                    roleBadge.className = `user-role-badge role-${newRole}`;
                    roleBadge.textContent = newRole.charAt(0).toUpperCase() + newRole.slice(1);
                }

                // Update onclick attribute
                const actionBtn = row.querySelector('.action-btn');
                if (actionBtn && currentModalUser) {
                    const username = currentModalUser.username;
                    actionBtn.setAttribute('onclick', `openPromotionModal(${userId}, '${username}', '${newRole}')`);
                }
            }
        }

        // Function to refresh users table (like lista-pagas.php)
        function refreshUsersTable() {
            fetch('promotions.php?action=get_users')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        const tbody = document.getElementById('usersTableBody');
                        tbody.innerHTML = '';

                        data.users.forEach(user => {
                            const row = createUserTableRow(user);
                            tbody.appendChild(row);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error refreshing users table:', error);
                });
        }

        // Function to refresh history section (like lista-pagas.php)
        function refreshHistorySection() {
            fetch('promotions.php?action=get_history')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.history) {
                        const historyContainer = document.getElementById('historyContainer');
                        historyContainer.innerHTML = '';

                        data.history.forEach(promotion => {
                            const historyItem = createHistoryItem(promotion);
                            historyContainer.appendChild(historyItem);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error refreshing history:', error);
                });
        }

        // Function to create user table row element
        function createUserTableRow(user) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-user-id', user.id);
            tr.setAttribute('data-username', user.habbo_username);

            const userInitial = user.habbo_username.charAt(0).toUpperCase();
            const createdAt = new Date(user.created_at).toLocaleDateString('es-ES');

            tr.innerHTML = `
                <td>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 70px; height: 70px; border-radius: 12px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.2); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                            <img src="https://www.habbo.es/habbo-imaging/avatarimage?user=${encodeURIComponent(user.habbo_username)}&action=std&direction=2&head_direction=3&img_format=png&gesture=std&headonly=1&size=l" 
                                 alt="Avatar de ${user.habbo_username}"
                                 style="width: 100%; height: 100%; object-fit: cover; display: block;"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div style="width: 100%; height: 100%; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%); display: none; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.5em;">
                                ${userInitial}
                            </div>
                        </div>
                        <div>
                            <div style="color: #ffffff; font-weight: 500;">
                                ${user.habbo_username}
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="user-role-badge role-${user.role}">
                        ${user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                    </span>
                </td>
                <td style="color: rgba(255,255,255,0.8);">
                    ${createdAt}
                </td>
                <td>
                    <button class="action-btn" onclick="openPromotionModal(${user.id}, '${user.habbo_username}', '${user.role}')">
                        <i class="fas fa-edit"></i>
                        Gestionar
                    </button>
                </td>
            `;

            return tr;
        }

        // Function to create history item element
        function createHistoryItem(promotion) {
            const div = document.createElement('div');
            div.className = 'history-item';
            div.setAttribute('data-username', promotion.promoted_username || '');
            div.setAttribute('data-promoter', promotion.promoter_username || '');
            div.setAttribute('data-reason', promotion.reason || '');

            const userInitial = promotion.promoted_username ? promotion.promoted_username.charAt(0).toUpperCase() : 'U';
            const promotedUsername = promotion.promoted_username || 'Usuario eliminado';
            const promoterUsername = promotion.promoter_username || 'Usuario eliminado';
            const createdAt = new Date(promotion.created_at).toLocaleDateString('es-ES') + ' ' + 
                              new Date(promotion.created_at).toLocaleTimeString('es-ES', {hour: '2-digit', minute: '2-digit'});

            div.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        ${promotion.promoted_username ? `
                            <div style="width: 70px; height: 70px; border-radius: 12px; overflow: hidden; border: 2px solid rgba(255, 255, 255, 0.2); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);">
                                <img src="https://www.habbo.es/habbo-imaging/avatarimage?user=${encodeURIComponent(promotion.promoted_username)}&action=std&direction=2&head_direction=3&img_format=png&gesture=std&headonly=1&size=l" 
                                     alt="Avatar de ${promotedUsername}"
                                     style="width: 100%; height: 100%; object-fit: cover; display: block;"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div style="width: 100%; height: 100%; background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%); display: none; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.5em;">
                                    ${userInitial}
                                </div>
                            </div>
                        ` : ''}
                        <strong style="color: #ffffff;">
                            ${promotedUsername}
                        </strong>
                    </div>
                    <small style="color: rgba(255,255,255,0.6);">
                        ${createdAt}
                    </small>
                </div>
                <div style="color: rgba(255,255,255,0.9); margin-bottom: 5px;">
                    <span class="user-role-badge role-${promotion.old_role}" style="font-size: 0.7em;">
                        ${promotion.old_role.charAt(0).toUpperCase() + promotion.old_role.slice(1)}
                    </span>
                    <i class="fas fa-arrow-right" style="margin: 0 8px; color: rgba(255,255,255,0.6);"></i>
                    <span class="user-role-badge role-${promotion.new_role}" style="font-size: 0.7em;">
                        ${promotion.new_role.charAt(0).toUpperCase() + promotion.new_role.slice(1)}
                    </span>
                </div>
                <small style="color: rgba(255,255,255,0.7);">
                    Por: ${promoterUsername}
                </small>
                ${promotion.reason ? `
                    <div style="margin-top: 8px; padding: 8px; background: rgba(255,255,255,0.05); border-radius: 6px;">
                        <small style="color: rgba(255,255,255,0.8); font-style: italic;">
                            "${promotion.reason}"
                        </small>
                    </div>
                ` : ''}
            `;

            return div;
        }

        // Auto-refresh history every 30 seconds (like lista-pagas.php)
        setInterval(() => {
            refreshHistorySection();
            refreshUsersTable();
        }, 30000);
    </script>
</body>
</html>