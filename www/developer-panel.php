<?php
session_start();
date_default_timezone_set('UTC');

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

include 'config/database.php';

// Check developer role with session caching
if (!isset($_SESSION['user_role'])) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['user_role'] = $stmt->fetchColumn();
}
$user_role = $_SESSION['user_role'];

// Get user permissions from rank
$user_permissions = [];
try {
    $stmt = $pdo->prepare("SELECT permissions FROM user_ranks WHERE rank_name = ?");
    $stmt->execute([$user_role]);
    $rank_permissions = $stmt->fetchColumn();
    if ($rank_permissions) {
        $user_permissions = json_decode($rank_permissions, true) ?: [];
    }
} catch (Exception $e) {
    $user_permissions = [];
}

// Check if user has manage_config permission or is super_admin
$has_config_permission = ($user_role === 'super_admin') || in_array('manage_config', $user_permissions);

if (!$has_config_permission) {
    header('Location: dashboard.php?error=access_denied');
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle logo upload
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === 0) {
        $target_dir = "uploads/logos/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $imageFileType = strtolower(pathinfo($_FILES["company_logo"]["name"], PATHINFO_EXTENSION));
        $target_file = $target_dir . "logo_" . time() . "." . $imageFileType;
        
        $allowed_types = array("jpg", "jpeg", "png", "gif", "svg");
        
        if (in_array($imageFileType, $allowed_types)) {
            if (move_uploaded_file($_FILES["company_logo"]["tmp_name"], $target_file)) {
                // Check if config exists
                $stmt = $pdo->prepare("SELECT config_key FROM system_config WHERE config_key = 'company_logo'");
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = 'company_logo'");
                    $result = $stmt->execute([$target_file]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES ('company_logo', ?)");
                    $result = $stmt->execute([$target_file]);
                }
                
                if ($result) {
                    $success_message = "Logo subido correctamente";
                } else {
                    $error_message = "Error al guardar en la base de datos";
                }
            } else {
                $error_message = "Error al subir el archivo";
            }
        } else {
            $error_message = "Solo se permiten archivos JPG, JPEG, PNG, GIF y SVG";
        }
    }
    
    // Handle site title update
    if (isset($_POST['site_title']) && !empty($_POST['site_title'])) {
        $site_title = trim($_POST['site_title']);
        
        // Check if config exists
        $stmt = $pdo->prepare("SELECT config_key FROM system_config WHERE config_key = 'site_title'");
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = 'site_title'");
            $result = $stmt->execute([$site_title]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES ('site_title', ?)");
            $result = $stmt->execute([$site_title]);
        }
        
        if ($result) {
            $success_message = "Título del sitio actualizado correctamente";
        } else {
            $error_message = "Error al actualizar el título";
        }
    }
    
    
}

// Get current configurations (excluding credits which are now per-rank)
$stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('site_title', 'company_logo')");
$stmt->execute();
$configs = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $configs[$row['config_key']] = $row['config_value'];
}

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM time_sessions WHERE status IN ('active', 'paused')");
$stmt->execute();
$active_sessions = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Desarrollador - <?php echo htmlspecialchars($configs['site_title'] ?? 'Habbo Agency'); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-code"></i>
                    Panel de Desarrollador
                </h1>
                
                <div class="header-actions">
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages -->
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

        <!-- System Statistics -->
        <div class="dashboard-card" style="margin-bottom: 30px;">
            <h3 class="card-title">
                <i class="fas fa-chart-bar"></i>
                Estadísticas del Sistema
            </h3>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_users; ?></div>
                    <div class="stat-label">Total Usuarios</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_sessions; ?></div>
                    <div class="stat-label">Sesiones Activas</div>
                </div>
            </div>
        </div>

        <!-- Quick Access -->
        <div class="dashboard-card" style="margin-bottom: 30px;">
            <h3 class="card-title">
                <i class="fas fa-external-link-alt"></i>
                Acceso Rápido
            </h3>
            
            <div class="quick-access-grid">
                <a href="rank-management.php" class="quick-link">
                    <i class="fas fa-crown"></i>
                    <span>Gestión de Rangos</span>
                </a>
                <a href="admin-panel.php" class="quick-link">
                    <i class="fas fa-users-cog"></i>
                    <span>Panel de Administrador</span>
                </a>
                <a href="time-manager.php" class="quick-link">
                    <i class="fas fa-clock"></i>
                    <span>Gestor de Tiempos</span>
                </a>
            </div>
        </div>

        <!-- Site Title Configuration -->
        <div class="dashboard-card" style="margin-bottom: 30px;">
            <h3 class="card-title">
                <i class="fas fa-heading"></i>
                Título del Sitio
            </h3>
            
            <form method="POST" class="config-form">
                <div class="form-group">
                    <label>Título del sitio web:</label>
                    <input type="text" name="site_title" value="<?php echo htmlspecialchars($configs['site_title'] ?? 'Habbo Agency'); ?>" class="glass-input" required>
                </div>
                <button type="submit" class="glass-button">
                    <i class="fas fa-save"></i>
                    Guardar Título
                </button>
            </form>
        </div>

        

        <!-- Logo Upload -->
        <div class="dashboard-card">
            <h3 class="card-title">
                <i class="fas fa-image"></i>
                Logo de la Empresa
            </h3>
            
            <?php if (!empty($configs['company_logo']) && file_exists($configs['company_logo'])): ?>
                <div class="current-logo">
                    <p class="logo-label">Logo actual:</p>
                    <img src="<?php echo htmlspecialchars($configs['company_logo']); ?>" alt="Logo actual" class="logo-preview">
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="config-form">
                <div class="form-group">
                    <label>Seleccionar nuevo logo:</label>
                    <input type="file" name="company_logo" accept="image/*" class="glass-input" required>
                    <small class="form-hint">Formatos permitidos: JPG, JPEG, PNG, GIF, SVG</small>
                </div>
                <button type="submit" class="glass-button">
                    <i class="fas fa-upload"></i>
                    Subir Logo
                </button>
            </form>
        </div>
    </div>

    

    <style>
    .alert-success {
        background: rgba(34, 197, 94, 0.2);
        border: 1px solid rgba(34, 197, 94, 0.4);
        color: #22c55e;
        padding: 15px;
        border-radius: 10px;
        margin: 20px 0;
    }

    .alert-error {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #ef4444;
        padding: 15px;
        border-radius: 10px;
        margin: 20px 0;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .stat-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 15px;
        padding: 20px;
        text-align: center;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        color: #fff;
    }

    .stat-label {
        color: rgba(255, 255, 255, 0.8);
        margin-top: 5px;
    }

    .quick-access-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .quick-link {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        padding: 15px;
        color: white;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: all 0.3s ease;
    }

    .quick-link:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-2px);
    }

    .config-form {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-group label {
        color: rgba(255, 255, 255, 0.9);
        font-weight: 500;
    }

    .form-hint {
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.9em;
    }

    .current-logo {
        margin-bottom: 20px;
    }

    .logo-label {
        color: rgba(255, 255, 255, 0.8);
        margin-bottom: 10px;
    }

    /* Fix dropdown option visibility */
    select.glass-input option {
        background: rgba(0, 0, 0, 0.9) !important;
        color: white !important;
        padding: 8px !important;
    }

    select.glass-input {
        color: white !important;
        background: rgba(255, 255, 255, 0.1) !important;
    }
    }

    .logo-preview {
        max-width: 200px;
        max-height: 100px;
        border-radius: 8px;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }

    
    </style>
    <script src="assets/js/notifications.js"></script>
</body>
</html>