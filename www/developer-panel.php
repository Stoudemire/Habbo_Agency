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
    
    // Handle credits configuration update
    if (isset($_POST['credits_calculation_type'])) {
        $calculation_type = $_POST['credits_calculation_type'];
        
        try {
            // Save calculation type
            $stmt = $pdo->prepare("SELECT config_key FROM system_config WHERE config_key = 'credits_calculation_type'");
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = 'credits_calculation_type'");
                $stmt->execute([$calculation_type]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES ('credits_calculation_type', ?)");
                $stmt->execute([$calculation_type]);
            }
            
            if ($calculation_type === 'hour') {
                // Handle time-based configuration
                $time_hours = isset($_POST['time_hours']) ? intval($_POST['time_hours']) : 1;
                $time_minutes = isset($_POST['time_minutes']) ? intval($_POST['time_minutes']) : 0;
                $credits_per_interval = isset($_POST['credits_per_interval']) ? intval($_POST['credits_per_interval']) : 1;
                
                // Convert total time to minutes for calculation
                $total_minutes = ($time_hours * 60) + $time_minutes;
                if ($total_minutes <= 0) $total_minutes = 60; // Default to 1 hour if invalid
                
                // Calculate credits per minute based on interval
                $credits_per_minute = $credits_per_interval / $total_minutes;
                
                // Save all time configuration values
                $time_configs = [
                    'time_hours' => $time_hours,
                    'time_minutes' => $time_minutes,
                    'credits_per_interval' => $credits_per_interval,
                    'credits_per_minute' => $credits_per_minute,
                    'credits_per_hour' => $credits_per_minute * 60
                ];
                
            } elseif ($calculation_type === 'minute') {
                // Handle minute-based configuration
                $credits_per_minute = isset($_POST['credits_per_minute']) ? intval($_POST['credits_per_minute']) : 1;
                
                $time_configs = [
                    'credits_per_minute' => $credits_per_minute,
                    'credits_per_hour' => $credits_per_minute * 60
                ];
            }
            
            // Save all configurations
            foreach ($time_configs as $key => $value) {
                $stmt = $pdo->prepare("SELECT config_key FROM system_config WHERE config_key = ?");
                $stmt->execute([$key]);
                
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
            }
            
            $success_message = "Configuración de créditos actualizada correctamente";
        } catch (Exception $e) {
            $error_message = "Error al actualizar créditos: " . $e->getMessage();
        }
    }
}

// Get current configurations
$stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('site_title', 'company_logo', 'credits_per_hour', 'credits_per_minute', 'credits_calculation_type', 'time_hours', 'time_minutes', 'credits_per_interval')");
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

        <!-- Credits Configuration -->
        <div class="dashboard-card" style="margin-bottom: 30px;">
            <h3 class="card-title">
                <i class="fas fa-coins"></i>
                Configuración de Créditos
            </h3>
            
            <form method="POST" class="config-form">
                <div class="form-group">
                    <label>Tipo de cálculo:</label>
                    <select name="credits_calculation_type" class="glass-input" id="calculationType" onchange="toggleCreditsInput()">
                        <option value="hour" <?php echo ($configs['credits_calculation_type'] ?? 'hour') === 'hour' ? 'selected' : ''; ?>>Por tiempo específico (horas y minutos)</option>
                        <option value="minute" <?php echo ($configs['credits_calculation_type'] ?? 'hour') === 'minute' ? 'selected' : ''; ?>>Por minuto trabajado</option>
                    </select>
                </div>
                
                <div class="form-group" id="hourlyInput" style="<?php echo ($configs['credits_calculation_type'] ?? 'hour') === 'minute' ? 'display: none;' : ''; ?>">
                    <label>Configurar intervalo de tiempo:</label>
                    <div class="time-config-container">
                        <div class="time-input-group">
                            <label class="sub-label">Horas:</label>
                            <input type="number" min="0" max="23" step="1" name="time_hours" value="<?php echo htmlspecialchars($configs['time_hours'] ?? '1'); ?>" class="glass-input time-input">
                        </div>
                        <div class="time-input-group">
                            <label class="sub-label">Minutos:</label>
                            <input type="number" min="0" max="59" step="1" name="time_minutes" value="<?php echo htmlspecialchars($configs['time_minutes'] ?? '0'); ?>" class="glass-input time-input">
                        </div>
                    </div>
                    <div class="time-config-container" style="margin-top: 15px;">
                        <div class="time-input-group">
                            <label class="sub-label">Créditos por cada intervalo:</label>
                            <input type="number" step="1" name="credits_per_interval" value="<?php echo htmlspecialchars($configs['credits_per_interval'] ?? '1'); ?>" class="glass-input">
                        </div>
                    </div>
                    <small class="form-hint">
                        Ejemplo: Si configuras 1 hora y 30 minutos con 5 créditos, el usuario recibirá 5 créditos cada 90 minutos trabajados.
                    </small>
                </div>
                
                <div class="form-group" id="minuteInput" style="<?php echo ($configs['credits_calculation_type'] ?? 'hour') === 'hour' ? 'display: none;' : ''; ?>">
                    <label>Créditos por minuto trabajado:</label>
                    <input type="number" step="1" name="credits_per_minute" value="<?php echo htmlspecialchars($configs['credits_per_minute'] ?? '1'); ?>" class="glass-input">
                    <small class="form-hint">
                        El usuario recibirá estos créditos por cada minuto trabajado.
                    </small>
                </div>
                
                <button type="submit" class="glass-button">
                    <i class="fas fa-save"></i>
                    Guardar Configuración
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

    <script>
    function toggleCreditsInput() {
        const calculationType = document.getElementById('calculationType').value;
        const hourlyInput = document.getElementById('hourlyInput');
        const minuteInput = document.getElementById('minuteInput');
        
        if (calculationType === 'hour') {
            hourlyInput.style.display = 'block';
            minuteInput.style.display = 'none';
        } else {
            hourlyInput.style.display = 'none';
            minuteInput.style.display = 'block';
        }
    }
    </script>

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

    .time-config-container {
        display: flex;
        gap: 15px;
        align-items: end;
        flex-wrap: wrap;
    }

    .time-input-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 120px;
    }

    .sub-label {
        color: rgba(255, 255, 255, 0.8);
        font-size: 0.9em;
        font-weight: 400;
    }

    .time-input {
        width: 100%;
        max-width: 120px;
    }

    @media (max-width: 768px) {
        .time-config-container {
            flex-direction: column;
            align-items: stretch;
        }
        
        .time-input-group {
            min-width: auto;
        }
        
        .time-input {
            max-width: none;
        }
    }
    </style>
    <script src="assets/js/notifications.js"></script>
</body>
</html>