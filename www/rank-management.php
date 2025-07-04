<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
} else {
    $user = $_SESSION['user_data'];
}

// Check user permissions for rank management
$user_permissions = [];
try {
    $stmt = $pdo->prepare("SELECT permissions FROM user_ranks WHERE rank_name = ?");
    $stmt->execute([$user['role']]);
    $rank_permissions = $stmt->fetchColumn();
    if ($rank_permissions) {
        $user_permissions = json_decode($rank_permissions, true) ?: [];
    }
} catch (Exception $e) {
    $user_permissions = [];
}

// Check user permissions - only manage_roles permission needed
$has_rank_permission = ($user['role'] === 'super_admin') || 
                  in_array('manage_roles', $user_permissions);

if (!$has_rank_permission) {
    header('Location: dashboard.php?error=access_denied');
    exit();
}

// Get site configuration
$stmt = $pdo->prepare("SELECT * FROM system_config WHERE id = 1");
$stmt->execute();
$config = $stmt->fetch();
$site_title = $config ? $config['site_title'] : 'Habbo Agency';

// Define available permissions (only implemented features)
$available_permissions = [
    'manage_roles' => 'Gestión de Rangos',
    'manage_config' => 'Configuración del Sistema',
    'manage_users' => 'Lista de Pagas',
    'manage_time' => 'Gestionar Tiempos'
];

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_rank') {
            $rank_name = trim($_POST['rank_name']);
            $display_name = trim($_POST['display_name']);
            $level = intval($_POST['level']);
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
            $rank_image = null;



            $credits_time_hours = intval($_POST['credits_time_hours']);
            $credits_time_minutes = intval($_POST['credits_time_minutes']);
            $credits_per_interval = intval($_POST['credits_per_interval']);
            $max_time_hours = intval($_POST['max_time_hours']);
            $max_time_minutes = intval($_POST['max_time_minutes']);
            $auto_complete_enabled = intval($_POST['auto_complete_enabled']);

            // Handle image upload
            if (isset($_FILES['rank_image']) && $_FILES['rank_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $file_type = $_FILES['rank_image']['type'];

                if (in_array($file_type, $allowed_types)) {
                    // Create upload directory if it doesn't exist
                    $upload_dir = 'uploads/rank-images/';
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            $error_message = "Error: No se pudo crear el directorio de subida.";
                        }
                    }

                    if (empty($error_message)) {
                        $file_extension = pathinfo($_FILES['rank_image']['name'], PATHINFO_EXTENSION);
                        $new_filename = 'rank_' . uniqid() . '.' . $file_extension;
                        $upload_path = $upload_dir . $new_filename;

                        if (move_uploaded_file($_FILES['rank_image']['tmp_name'], $upload_path)) {
                            $rank_image = $upload_path;
                        } else {
                            $error_message = "Error al subir la imagen. Verifique los permisos del directorio.";
                        }
                    }
                } else {
                    $error_message = "Solo se permiten imágenes JPG, PNG y GIF.";
                }
            }

            if (empty($rank_name) || empty($display_name) || $level < 1) {
                $error_message = "Todos los campos son obligatorios y el nivel debe ser mayor a 0.";
            } else {
                // Check if rank name already exists (only name needs to be unique)
                $stmt = $pdo->prepare("SELECT id FROM user_ranks WHERE rank_name = ?");
                $stmt->execute([$rank_name]);
                if ($stmt->fetch()) {
                    $error_message = "Ya existe un rango con ese nombre.";
                } else {
                    // Create new rank
                    $permissions_json = json_encode($permissions);
                    try {
                        // Try with rank_image column first
                        $stmt = $pdo->prepare("INSERT INTO user_ranks (rank_name, display_name, level, permissions, rank_image, credits_time_hours, credits_time_minutes, credits_per_interval, max_time_hours, max_time_minutes, auto_complete_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$rank_name, $display_name, $level, $permissions_json, $rank_image, $credits_time_hours, $credits_time_minutes, $credits_per_interval, $max_time_hours, $max_time_minutes, $auto_complete_enabled])) {
                            $success_message = "Rango creado exitosamente.";
                        } else {
                            $error_message = "Error al crear el rango.";
                        }
                    } catch (Exception $e) {
                        // If rank_image column doesn't exist, create without it
                        try {
                            $stmt = $pdo->prepare("INSERT INTO user_ranks (rank_name, display_name, level, permissions, credits_time_hours, credits_time_minutes, credits_per_interval, max_time_hours, max_time_minutes, auto_complete_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($stmt->execute([$rank_name, $display_name, $level, $permissions_json, $credits_time_hours, $credits_time_minutes, $credits_per_interval, $max_time_hours, $max_time_minutes, $auto_complete_enabled])) {
                                $success_message = "Rango creado exitosamente. Nota: Para usar imágenes, agrega la columna rank_image a la tabla.";
                            } else {
                                $error_message = "Error al crear el rango.";
                            }
                        } catch (Exception $e2) {
                            $error_message = "Error al crear el rango: " . $e2->getMessage();
                        }
                    }
                }
            }
        } elseif ($_POST['action'] === 'edit_rank') {
            $rank_id = intval($_POST['rank_id']);
            $rank_name = trim($_POST['rank_name']);
            $display_name = trim($_POST['display_name']);
            $level = intval($_POST['level']);
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];

            $credits_time_hours = intval($_POST['credits_time_hours']);
            $credits_time_minutes = intval($_POST['credits_time_minutes']);
            $credits_per_interval = intval($_POST['credits_per_interval']);
            $max_time_hours = intval($_POST['max_time_hours']);
            $max_time_minutes = intval($_POST['max_time_minutes']);
            $auto_complete_enabled = intval($_POST['auto_complete_enabled']);

            // Get current rank data for image handling
            $rank_image = null; // Default value
            try {
                $stmt = $pdo->prepare("SELECT rank_image FROM user_ranks WHERE id = ?");
                $stmt->execute([$rank_id]);
                $current_rank = $stmt->fetch();
                $rank_image = $current_rank ? $current_rank['rank_image'] : null;
            } catch (Exception $e) {
                // Column might not exist yet, ignore error
                $rank_image = null;
            }

            // Handle new image upload
            if (isset($_FILES['rank_image']) && $_FILES['rank_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $file_type = $_FILES['rank_image']['type'];

                if (in_array($file_type, $allowed_types)) {
                    // Create upload directory if it doesn't exist
                    $upload_dir = 'uploads/rank-images/';
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            $error_message = "Error: No se pudo crear el directorio de subida.";
                        }
                    }

                    if (empty($error_message)) {
                        $file_extension = pathinfo($_FILES['rank_image']['name'], PATHINFO_EXTENSION);
                        $new_filename = 'rank_' . uniqid() . '.' . $file_extension;
                        $upload_path = $upload_dir . $new_filename;

                        if (move_uploaded_file($_FILES['rank_image']['tmp_name'], $upload_path)) {
                            // Delete old image if exists
                            if ($rank_image && file_exists($rank_image)) {
                                unlink($rank_image);
                            }
                            $rank_image = $upload_path;
                        } else {
                            $error_message = "Error al subir la nueva imagen. Verifique los permisos del directorio.";
                        }
                    }
                } else {
                    $error_message = "Solo se permiten imágenes JPG, PNG y GIF.";
                }
            }

            if (empty($rank_name) || empty($display_name) || $level < 1) {
                $error_message = "Todos los campos son obligatorios y el nivel debe ser mayor a 0.";
            } else {
                // Check if another rank has the same name (excluding current) - levels can repeat
                $stmt = $pdo->prepare("SELECT id FROM user_ranks WHERE rank_name = ? AND id != ?");
                $stmt->execute([$rank_name, $rank_id]);
                if ($stmt->fetch()) {
                    $error_message = "Ya existe otro rango con ese nombre.";
                } else {
                    // Update rank
                    $permissions_json = json_encode($permissions);
                    try {
                        // Try with rank_image column first
                        $stmt = $pdo->prepare("UPDATE user_ranks SET rank_name = ?, display_name = ?, level = ?, permissions = ?, rank_image = ?, credits_time_hours = ?, credits_time_minutes = ?, credits_per_interval = ?, max_time_hours = ?, max_time_minutes = ?, auto_complete_enabled = ? WHERE id = ?");
                        if ($stmt->execute([$rank_name, $display_name, $level, $permissions_json, $rank_image, $credits_time_hours, $credits_time_minutes, $credits_per_interval, $max_time_hours, $max_time_minutes, $auto_complete_enabled, $rank_id])) {
                            $success_message = "Rango actualizado exitosamente.";
                        } else {
                            $error_message = "Error al actualizar el rango.";
                        }
                    } catch (Exception $e) {
                        // If rank_image column doesn't exist, update without it
                        try {
                            $stmt = $pdo->prepare("UPDATE user_ranks SET rank_name = ?, display_name = ?, level = ?, permissions = ?, credits_time_hours = ?, credits_time_minutes = ?, credits_per_interval = ?, max_time_hours = ?, max_time_minutes = ?, auto_complete_enabled = ? WHERE id = ?");
                            if ($stmt->execute([$rank_name, $display_name, $level, $permissions_json, $credits_time_hours, $credits_time_minutes, $credits_per_interval, $max_time_hours, $max_time_minutes, $auto_complete_enabled, $rank_id])) {
                                $success_message = "Rango actualizado exitosamente. Nota: Para usar imágenes, agrega la columna rank_image a la tabla.";
                            } else {
                                $error_message = "Error al actualizar el rango.";
                            }
                        } catch (Exception $e2) {
                            $error_message = "Error al actualizar el rango: " . $e2->getMessage();
                        }
                    }
                }
            }
        } elseif ($_POST['action'] === 'delete_rank') {
            $rank_id = intval($_POST['rank_id']);

            // Don't allow deletion of core ranks
            $stmt = $pdo->prepare("SELECT rank_name FROM user_ranks WHERE id = ?");
            $stmt->execute([$rank_id]);
            $rank = $stmt->fetch();

            if ($rank && !in_array($rank['rank_name'], ['super_admin', 'administrador', 'operador', 'usuario'])) {
                $stmt = $pdo->prepare("DELETE FROM user_ranks WHERE id = ?");
                if ($stmt->execute([$rank_id])) {
                    $success_message = "Rango eliminado exitosamente.";
                } else {
                    $error_message = "Error al eliminar el rango.";
                }
            } else {
                $error_message = "No se pueden eliminar los rangos del sistema.";
            }
        }
    }
}

// Get all ranks (hide super_admin from interface)
try {
    if ($user['role'] === 'super_admin') {
        // Super admin can see all ranks
        $stmt = $pdo->prepare("SELECT * FROM user_ranks ORDER BY level ASC");
    } else {
        // Hide super_admin rank from developers
        $stmt = $pdo->prepare("SELECT * FROM user_ranks WHERE rank_name != 'super_admin' ORDER BY level ASC");
    }
    $stmt->execute();
    $ranks = $stmt->fetchAll();
} catch (Exception $e) {
    $error_message = "Error: La tabla de rangos no existe. Ejecuta database_complete.sql en phpMyAdmin primero.";
    $ranks = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Rangos - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .rank-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .rank-modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 40px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            position: relative;
        }

        .modal-close-btn {
            position: absolute;
            top: 15px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s ease;
        }

        .modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        /* Glass-morphism scrollbar like time-manager */
        .rank-modal-content::-webkit-scrollbar {
            width: 12px;
        }

        .rank-modal-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            backdrop-filter: blur(10px);
        }

        .rank-modal-content::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, 
                rgba(156, 39, 176, 0.4), 
                rgba(156, 39, 176, 0.7));
            border-radius: 6px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        }

        .rank-modal-content::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, 
                rgba(156, 39, 176, 0.7), 
                rgba(156, 39, 176, 0.9));
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            transform: scale(1.1);
        }

        .rank-modal-content::-webkit-scrollbar-thumb:active {
            background: linear-gradient(135deg, 
                rgba(156, 39, 176, 0.9), 
                rgba(120, 30, 140, 1));
        }

        .rank-modal h3 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.8em;
            background: linear-gradient(45deg, #9c27b0, #673ab7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .rank-form-group {
            margin-bottom: 25px;
        }

        .rank-form-group label {
            display: block;
            margin-bottom: 8px;
            color: #fff;
            font-weight: bold;
            font-size: 1.1em;
        }

        .rank-form-group input,
        .rank-form-group select {
            width: 100%;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 1em;
            backdrop-filter: blur(10px);
        }

        .rank-form-group input:focus,
        .rank-form-group select:focus {
            outline: none;
            border-color: #9c27b0;
            box-shadow: 0 0 15px rgba(156, 39, 176, 0.3);
        }

        .rank-form-group input::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }

        .permissions-section {
            margin-top: 30px;
        }

        .permissions-section h4 {
            color: #fff;
            margin-bottom: 20px;
            font-size: 1.2em;
        }

        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .permission-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
        }

        .permission-item:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(156, 39, 176, 0.5);
        }

        .permission-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #9c27b0;
            cursor: pointer;
        }

        .permission-item label {
            color: #fff;
            cursor: pointer;
            font-size: 0.95em;
            margin: 0;
        }

        .rank-modal-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 40px;
        }

        .btn-primary {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 1em;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 1em;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateY(-2px);
        }

        .rank-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .rank-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        }

        .rank-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .rank-title-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .rank-image {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.4);
        }

        .rank-image-placeholder {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: 2em;
            margin-bottom: 20px;
        }

        .rank-name {
            font-size: 1.6em;
            font-weight: bold;
            color: #fff;
        }

        .rank-level {
            background: linear-gradient(45deg, #9c27b0, #673ab7);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
        }

        .permissions-display {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }

        .permission-tag {
            background: rgba(156, 39, 176, 0.3);
            color: white;
            padding: 6px 14px;
            border-radius: 15px;
            font-size: 0.85em;
            border: 1px solid rgba(156, 39, 176, 0.5);
        }

        .rank-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn-edit-rank {
            background: linear-gradient(45deg, #2196f3, #1976d2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s ease;
            margin-right: 10px;
        }

        .btn-edit-rank:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.4);
        }

        .btn-delete-rank {
            background: linear-gradient(45deg, #f44336, #d32f2f);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .btn-delete-rank:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(244, 67, 54, 0.4);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
            display: block;
        }

        .empty-state h3 {
            font-size: 1.5em;
            margin-bottom: 10px;
            color: #fff;
        }

        .empty-state p {
            font-size: 1.1em;
            opacity: 0.8;
        }

        .alert {
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 10px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.2);
            color: #ffffff;
            border-color: rgba(34, 197, 94, 0.4);
        }

        .alert-success::before {
            content: "✓";
            font-weight: bold;
            color: #22c55e;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #ffffff;
            border-color: rgba(239, 68, 68, 0.4);
        }

        .alert-error::before {
            content: "✕";
            font-weight: bold;
            color: #ef4444;
        }

        .ranks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .rank-info {
            margin: 15px 0;
        }

        .rank-info p {
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
        }

        .no-permissions {
            opacity: 0.6;
            font-style: italic;
        }

        .system-rank-label {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            border: 1px solid rgba(255, 193, 7, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .credits-config-group {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .credits-config-group h4 {
            color: #fff;
            margin-bottom: 15px;
            font-size: 1.1em;
        }

        .credits-config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
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
                    Gestión de Rangos
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
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

<div class="dashboard-card">
                <div class="section-header">
                    <h3 class="card-title">
                        <i class="fas fa-users-cog"></i>
                        Gestión de Rangos
                    </h3>
                    <div class="section-actions">
                        <button class="btn-primary" onclick="openRankModal()">
                            <i class="fas fa-plus"></i>
                            Agregar Rango
                        </button>
                    </div>
                </div>

                <?php if (empty($ranks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <h3>No hay rangos configurados</h3>
                        <p>Comienza agregando un nuevo rango al sistema</p>
                    </div>
                <?php else: ?>
                    <div class="ranks-grid">
                        <?php foreach ($ranks as $rank): ?>
                            <div class="rank-card">
                                <div class="rank-header">
                                    <div class="rank-title-section">
                                        <?php if (isset($rank['rank_image']) && !empty($rank['rank_image']) && file_exists($rank['rank_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($rank['rank_image']); ?>" alt="Imagen del rango" class="rank-image">
                                        <?php else: ?>
                                            <div class="rank-image-placeholder">
                                                <i class="fas fa-crown"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <h4 class="rank-name"><?php echo htmlspecialchars($rank['display_name']); ?></h4>
                                            <span class="rank-level">Nivel <?php echo $rank['level']; ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="rank-info">
                                    <p><strong>Sistema:</strong> <?php echo htmlspecialchars($rank['rank_name']); ?></p>
                                </div>

                                <div class="permissions-display">
                                    <?php 
                                    $permissions = json_decode($rank['permissions'], true) ?: [];
                                    if (empty($permissions)): ?>
                                        <span class="permission-tag no-permissions">Sin permisos asignados</span>
                                    <?php else: ?>
                                        <?php foreach ($permissions as $perm): ?>
                                            <span class="permission-tag">
                                                <?php echo isset($available_permissions[$perm]) ? $available_permissions[$perm] : $perm; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="rank-actions">
                                    <?php if (!in_array($rank['rank_name'], ['super_admin', 'administrador', 'operador', 'usuario'])): ?>
                                        <button type="button" class="btn-edit-rank" onclick="openEditRankModal(<?php echo $rank['id']; ?>, '<?php echo htmlspecialchars($rank['rank_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rank['display_name'], ENT_QUOTES); ?>', <?php echo $rank['level']; ?>, '<?php echo htmlspecialchars($rank['permissions'], ENT_QUOTES); ?>', <?php echo isset($rank['credits_time_hours']) ? $rank['credits_time_hours'] : 1; ?>, <?php echo isset($rank['credits_time_minutes']) ? $rank['credits_time_minutes'] : 0; ?>, <?php echo isset($rank['credits_per_interval']) ? $rank['credits_per_interval'] : 1; ?>, <?php echo isset($rank['max_time_hours']) ? $rank['max_time_hours'] : 8; ?>, <?php echo isset($rank['max_time_minutes']) ? $rank['max_time_minutes'] : 0; ?>, <?php echo isset($rank['auto_complete_enabled']) ? $rank['auto_complete_enabled'] : 1; ?>)">
                                            <i class="fas fa-edit"></i>
                                            Editar
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de que quieres eliminar este rango?');">
                                            <input type="hidden" name="action" value="delete_rank">
                                            <input type="hidden" name="rank_id" value="<?php echo $rank['id']; ?>">
                                            <button type="submit" class="btn-delete-rank">
                                                <i class="fas fa-trash"></i>
                                                Eliminar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn-edit-rank" onclick="openEditRankModal(<?php echo $rank['id']; ?>, '<?php echo htmlspecialchars($rank['rank_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rank['display_name'], ENT_QUOTES); ?>', <?php echo $rank['level']; ?>, '<?php echo htmlspecialchars($rank['permissions'], ENT_QUOTES); ?>', <?php echo isset($rank['credits_time_hours']) ? $rank['credits_time_hours'] : 1; ?>, <?php echo isset($rank['credits_time_minutes']) ? $rank['credits_time_minutes'] : 0; ?>, <?php echo isset($rank['credits_per_interval']) ? $rank['credits_per_interval'] : 1; ?>, <?php echo isset($rank['max_time_hours']) ? $rank['max_time_hours'] : 8; ?>, <?php echo isset($rank['max_time_minutes']) ? $rank['max_time_minutes'] : 0; ?>, <?php echo isset($rank['auto_complete_enabled']) ? $rank['auto_complete_enabled'] : 1; ?>)">
                                            <i class="fas fa-edit"></i>
                                            Editar
                                        </button>
                                        <span class="system-rank-label">
                                            <i class="fas fa-lock"></i>
                                            Rango del Sistema
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            </div>
        </div>
    </div>

    <!-- Modal para agregar rango -->
    <div id="rankModal" class="rank-modal">
        <div class="rank-modal-content">
            <button class="modal-close-btn" onclick="closeRankModal()">
                <i class="fas fa-times"></i>
            </button>
            <h3 id="modal-title"><i class="fas fa-plus-circle"></i> Agregar Nuevo Rango</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_rank" id="modal-action">
                <input type="hidden" name="rank_id" value="" id="modal-rank-id">

                <div class="rank-form-group">
                    <label for="rank_name">Nombre del Sistema:</label>
                    <input type="text" id="rank_name" name="rank_name" required placeholder="ej: moderador">
                </div>

                <div class="rank-form-group">
                    <label for="display_name">Nombre para Mostrar:</label>
                    <input type="text" id="display_name" name="display_name" required placeholder="ej: Moderador">
                </div>

                <div class="rank-form-group">
                    <label for="level">Nivel (1-999):</label>
                    <input type="number" id="level" name="level" min="1" max="999" required placeholder="ej: 50">
                </div>

                <div class="rank-form-group">
                    <label for="rank_image">Imagen del Rango:</label>
                    <input type="file" id="rank_image" name="rank_image" accept="image/*" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 10px; padding: 12px; color: white; width: 100%;">
                    <small style="color: rgba(255, 255, 255, 0.7); font-size: 0.9em; margin-top: 5px; display: block;">Formatos permitidos: JPG, PNG, GIF. Tamaño recomendado: 64x64px</small>
                </div>



                 <div class="credits-config-group">
                    <h4>Configuración de Créditos</h4>
                    <div class="credits-config-grid">
                        <div class="rank-form-group">
                            <label for="credits_time_hours">Horas:</label>
                            <input type="number" id="credits_time_hours" name="credits_time_hours" min="0" max="23" value="1" required>
                        </div>
                        <div class="rank-form-group">
                            <label for="credits_time_minutes">Minutos:</label>
                            <input type="number" id="credits_time_minutes" name="credits_time_minutes" min="0" max="59" value="0" required>
                        </div>
                        <div class="rank-form-group">
                            <label for="credits_per_interval">Créditos por Intervalo:</label>
                            <input type="number" id="credits_per_interval" name="credits_per_interval" min="1" value="1" required>
                        </div>
                    </div>
                </div>

                <div class="credits-config-group">
                    <h4>Configuración de Tiempo Máximo</h4>
                    <div class="credits-config-grid">
                        <div class="rank-form-group">
                            <label for="max_time_hours">Horas Máximas:</label>
                            <input type="number" id="max_time_hours" name="max_time_hours" min="0" max="999" value="8" required>
                        </div>
                        <div class="rank-form-group">
                            <label for="max_time_minutes">Minutos Máximos:</label>
                            <input type="number" id="max_time_minutes" name="max_time_minutes" min="0" max="59" value="0" required>
                        </div>
                        <div class="rank-form-group">
                            <label for="auto_complete_enabled">Auto-Completar:</label>
                            <select id="auto_complete_enabled" name="auto_complete_enabled">
                                <option value="1">Sí</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="permissions-section">
                    <h4><i class="fas fa-key"></i> Permisos del Rango</h4>
                    <div class="permissions-grid">
                        <?php foreach ($available_permissions as $perm_key => $perm_name): ?>
                            <div class="permission-item">
                                <input type="checkbox" id="perm_<?php echo $perm_key; ?>" name="permissions[]" value="<?php echo $perm_key; ?>">
                                <label for="perm_<?php echo $perm_key; ?>"><?php echo $perm_name; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rank-modal-actions">
                    <button type="submit" class="btn-primary" id="modal-submit-btn">
                        <i class="fas fa-save"></i>
                        Crear Rango
                    </button>
                    <button type="button" class="btn-secondary" onclick="closeRankModal()">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRankModal() {
            // Reset modal for create mode
            document.getElementById('modal-title').innerHTML = '<i class="fas fa-plus-circle"></i> Agregar Nuevo Rango';
            document.getElementById('modal-action').value = 'create_rank';
            document.getElementById('modal-rank-id').value = '';
            document.getElementById('modal-submit-btn').innerHTML = '<i class="fas fa-save"></i> Crear Rango';

            // Clear form
            document.getElementById('rank_name').value = '';
            document.getElementById('display_name').value = '';
            document.getElementById('level').value = '';
            document.getElementById('rank_image').value = '';

            // Clear credit config
            document.getElementById('credits_time_hours').value = '1';
            document.getElementById('credits_time_minutes').value = '0';
            document.getElementById('credits_per_interval').value = '1';

            //Clear max time config
            document.getElementById('max_time_hours').value = '8';
            document.getElementById('max_time_minutes').value = '0';
            document.getElementById('auto_complete_enabled').value = '1';

            // Uncheck all permissions
            const checkboxes = document.querySelectorAll('input[name="permissions[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);

            document.getElementById('rankModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function openEditRankModal(rankId, rankName, displayName, level, permissions, creditsTimeHours, creditsTimeMinutes, creditsPerInterval, maxTimeHours, maxTimeMinutes, autoCompleteEnabled) {
            // Set modal for edit mode
            document.getElementById('modal-title').innerHTML = '<i class="fas fa-edit"></i> Editar Rango';
            document.getElementById('modal-action').value = 'edit_rank';
            document.getElementById('modal-rank-id').value = rankId;
            document.getElementById('modal-submit-btn').innerHTML = '<i class="fas fa-save"></i> Actualizar Rango';

            // Fill form with current values
            document.getElementById('rank_name').value = rankName;
            document.getElementById('display_name').value = displayName;
            document.getElementById('level').value = level;
            document.getElementById('rank_image').value = ''; // Clear image field for new upload

            // Fill credit config
            document.getElementById('credits_time_hours').value = creditsTimeHours;
            document.getElementById('credits_time_minutes').value = creditsTimeMinutes;
            document.getElementById('credits_per_interval').value = creditsPerInterval;

            // Fill max time config
            document.getElementById('max_time_hours').value = maxTimeHours;
            document.getElementById('max_time_minutes').value = maxTimeMinutes;
            document.getElementById('auto_complete_enabled').value = autoCompleteEnabled;

            // Parse and set permissions
            let permissionsArray = [];
            try {
                permissionsArray = JSON.parse(permissions);
            } catch (e) {
                permissionsArray = [];
            }

            // Uncheck all permissions first
            const checkboxes = document.querySelectorAll('input[name="permissions[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);

            // Check the permissions for this rank
            permissionsArray.forEach(permission => {
                const checkbox = document.querySelector(`input[value="${permission}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });

            document.getElementById('rankModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeRankModal() {
            document.getElementById('rankModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rankModal');
            if (event.target == modal) {
                closeRankModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeRankModal();
            }
        });
    </script>
    <script src="assets/js/notifications.js"></script>
</body>
</html>