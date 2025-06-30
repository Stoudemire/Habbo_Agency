<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get user data and role
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user['role'];

// Check if user can manage schedules (only super_admin and administrador)
$can_manage = in_array($user_role, ['super_admin', 'administrador']);

// Get system configuration
$config = ['site_title' => 'Habbo Agency'];
try {
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['config_key']] = $row['config_value'];
    }
} catch (PDOException $e) {
    // Use default config
}

// Default business hours
$business_hours = [
    0 => ['day_of_week' => 0, 'opening_time' => '00:00:00', 'closing_time' => '00:00:00', 'is_open' => 0],
    1 => ['day_of_week' => 1, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00', 'is_open' => 1],
    2 => ['day_of_week' => 2, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00', 'is_open' => 1],
    3 => ['day_of_week' => 3, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00', 'is_open' => 1],
    4 => ['day_of_week' => 4, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00', 'is_open' => 1],
    5 => ['day_of_week' => 5, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00', 'is_open' => 1],
    6 => ['day_of_week' => 6, 'opening_time' => '10:00:00', 'closing_time' => '14:00:00', 'is_open' => 1]
];

$special_days = [];
$success_message = '';
$error_message = '';

// Handle form submissions (only for admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $success_message = "Los horarios se han actualizado correctamente (función en desarrollo)";
}

// Day names
$day_names = [
    0 => 'Domingo',
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sábado'
];

// Get current date info
$today = date('Y-m-d');
$current_day = date('w'); // 0=Sunday, 6=Saturday
$current_time = date('H:i');

// Check if agency is currently open
$is_currently_open = false;
$current_status = "Cerrado";

if (isset($business_hours[$current_day]) && $business_hours[$current_day]['is_open']) {
    $today_schedule = $business_hours[$current_day];
    $opening = substr($today_schedule['opening_time'], 0, 5);
    $closing = substr($today_schedule['closing_time'], 0, 5);
    if ($current_time >= $opening && $current_time <= $closing) {
        $is_currently_open = true;
        $current_status = "Abierto hasta las " . $closing;
    } else if ($current_time < $opening) {
        $current_status = "Abre a las " . $opening;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['site_title']; ?> - Horarios</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="logo-section">
                    <h1><i class="fas fa-clock"></i> Horarios de Atención</h1>
                </div>
                <div class="user-section">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php if ($user['profile_photo']): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Avatar">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                            <p><?php echo ucfirst($user_role); ?></p>
                        </div>
                    </div>
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>

            <!-- Current Status -->
            <div class="current-status <?php echo $is_currently_open ? 'status-open' : 'status-closed'; ?>">
                <div style="display: flex; align-items: center; justify-content: center; gap: 15px;">
                    <i class="fas fa-<?php echo $is_currently_open ? 'door-open' : 'door-closed'; ?>" style="font-size: 2rem;"></i>
                    <div>
                        <h2 style="margin: 0; color: white; font-size: 1.5rem;">
                            <?php echo $is_currently_open ? 'ABIERTO' : 'CERRADO'; ?>
                        </h2>
                        <p style="margin: 5px 0 0 0; color: rgba(255, 255, 255, 0.8); font-size: 1.1rem;">
                            <?php echo $current_status; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Schedule Display -->
            <div class="schedule-container">
                <div class="business-hours-card">
                    <h3 style="color: white; margin-bottom: 20px;">
                        <i class="fas fa-calendar-week"></i>
                        Horarios de la Semana
                    </h3>
                    
                    <div class="hours-list">
                        <?php foreach ($day_names as $day_num => $day_name): ?>
                        <div class="day-schedule <?php echo $day_num == $current_day ? 'today' : ''; ?>">
                            <div class="day-name">
                                <strong><?php echo $day_name; ?></strong>
                                <?php if ($day_num == $current_day): ?>
                                <span class="today-indicator">HOY</span>
                                <?php endif; ?>
                            </div>
                            <div class="day-hours">
                                <?php if ($business_hours[$day_num]['is_open']): ?>
                                <span class="hours-open">
                                    <?php echo substr($business_hours[$day_num]['opening_time'], 0, 5); ?> - 
                                    <?php echo substr($business_hours[$day_num]['closing_time'], 0, 5); ?>
                                </span>
                                <?php else: ?>
                                <span class="hours-closed">Cerrado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="business-hours-card">
                    <h3 style="color: white; margin-bottom: 20px;">
                        <i class="fas fa-info-circle"></i>
                        Información Adicional
                    </h3>
                    
                    <div style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                        <p><i class="fas fa-clock"></i> <strong>Horario Regular:</strong></p>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Lunes a Viernes: 9:00 AM - 6:00 PM</li>
                            <li>Sábado: 10:00 AM - 2:00 PM</li>
                            <li>Domingo: Cerrado</li>
                        </ul>
                        
                        <p style="margin-top: 20px;"><i class="fas fa-phone"></i> <strong>Contacto:</strong></p>
                        <p>Para emergencias fuera del horario de atención, contacta al administrador.</p>
                        
                        <?php if ($can_manage): ?>
                        <div style="margin-top: 20px; padding: 15px; background: rgba(255, 193, 7, 0.1); border-radius: 8px; border: 1px solid rgba(255, 193, 7, 0.3);">
                            <p style="margin: 0; color: #ffc107;"><i class="fas fa-cog"></i> <strong>Administrador:</strong> Puedes modificar estos horarios desde el panel de configuración.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .current-status {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .status-open {
            border-color: rgba(34, 197, 94, 0.4);
            background: rgba(34, 197, 94, 0.1);
        }

        .status-closed {
            border-color: rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.1);
        }

        .schedule-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .business-hours-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .hours-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .day-schedule {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .day-schedule.today {
            background: rgba(147, 51, 234, 0.2);
            border-color: rgba(147, 51, 234, 0.4);
        }

        .day-name {
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .today-indicator {
            background: rgba(147, 51, 234, 0.8);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .hours-open {
            color: #22c55e;
            font-weight: 500;
        }

        .hours-closed {
            color: #ef4444;
            font-weight: 500;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
    </style>
</body>
</html>