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
try {
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config");
    $stmt->execute();
    $config = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['config_key']] = $row['config_value'];
    }
} catch (PDOException $e) {
    $config = ['site_title' => 'Habbo Agency'];
}

// Initialize database tables for schedule
try {
    // Create business_hours table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS business_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        day_of_week TINYINT NOT NULL,
        opening_time TIME DEFAULT '09:00:00',
        closing_time TIME DEFAULT '18:00:00',
        is_open TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_day (day_of_week)
    )");
    
    // Create special_days table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS special_days (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL UNIQUE,
        opening_time TIME,
        closing_time TIME,
        is_open TINYINT(1) DEFAULT 1,
        description TEXT,
        color VARCHAR(7) DEFAULT '#9333ea',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Check if business_hours has data
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM business_hours");
    $count = $stmt->fetch()['count'];
    
    if ($count == 0) {
        // Insert default business hours
        $default_hours = [
            [0, '00:00:00', '00:00:00', 0], // Domingo cerrado
            [1, '09:00:00', '18:00:00', 1], // Lunes
            [2, '09:00:00', '18:00:00', 1], // Martes
            [3, '09:00:00', '18:00:00', 1], // Miércoles
            [4, '09:00:00', '18:00:00', 1], // Jueves
            [5, '09:00:00', '18:00:00', 1], // Viernes
            [6, '10:00:00', '14:00:00', 1]  // Sábado
        ];
        
        $stmt = $pdo->prepare("INSERT INTO business_hours (day_of_week, opening_time, closing_time, is_open) VALUES (?, ?, ?, ?)");
        foreach ($default_hours as $hours) {
            $stmt->execute($hours);
        }
    }
    
    // Load business hours from database
    $stmt = $pdo->prepare("SELECT * FROM business_hours ORDER BY day_of_week");
    $stmt->execute();
    $business_hours = [];
    while ($row = $stmt->fetch()) {
        $business_hours[$row['day_of_week']] = $row;
    }
    
    // Load special days from database
    $stmt = $pdo->prepare("SELECT * FROM special_days WHERE date >= CURRENT_DATE ORDER BY date");
    $stmt->execute();
    $special_days = [];
    while ($row = $stmt->fetch()) {
        $special_days[$row['date']] = $row;
    }
    
} catch (PDOException $e) {
    die("Error al configurar la página de horarios: " . $e->getMessage() . "<br><br>Por favor asegúrate de que:<br>1. MySQL esté ejecutándose<br>2. La base de datos 'habbo_agency' exista<br>3. El usuario 'root' tenga permisos");
}

$success_message = '';
$error_message = '';

// Handle form submissions (only for admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_business_hours':
                try {
                    // Update business hours for each day
                    for ($day = 0; $day <= 6; $day++) {
                        $is_open = isset($_POST["is_open_$day"]) ? 1 : 0;
                        $opening_time = $_POST["opening_time_$day"] ?? '09:00';
                        $closing_time = $_POST["closing_time_$day"] ?? '18:00';
                        
                        // Check if record exists
                        $stmt = $pdo->prepare("SELECT id FROM business_hours WHERE day_of_week = ?");
                        $stmt->execute([$day]);
                        
                        if ($stmt->rowCount() > 0) {
                            // Update existing
                            $stmt = $pdo->prepare("UPDATE business_hours SET opening_time = ?, closing_time = ?, is_open = ? WHERE day_of_week = ?");
                            $stmt->execute([$opening_time, $closing_time, $is_open, $day]);
                        } else {
                            // Insert new
                            $stmt = $pdo->prepare("INSERT INTO business_hours (day_of_week, opening_time, closing_time, is_open) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$day, $opening_time, $closing_time, $is_open]);
                        }
                    }
                    $success_message = "Horarios actualizados correctamente";
                } catch (Exception $e) {
                    $error_message = "Error al actualizar horarios: " . $e->getMessage();
                }
                break;
                
            case 'add_special_day':
                try {
                    $date = $_POST['special_date'];
                    $is_open = isset($_POST['special_is_open']) ? 1 : 0;
                    $opening_time = $is_open ? $_POST['special_opening_time'] : null;
                    $closing_time = $is_open ? $_POST['special_closing_time'] : null;
                    $description = $_POST['special_description'] ?? '';
                    $color = $_POST['special_color'] ?? '#ff0000';
                    
                    // Check if special day already exists
                    $stmt = $pdo->prepare("SELECT id FROM special_days WHERE date = ?");
                    $stmt->execute([$date]);
                    
                    if ($stmt->rowCount() > 0) {
                        // Update existing
                        $stmt = $pdo->prepare("UPDATE special_days SET opening_time = ?, closing_time = ?, is_open = ?, description = ?, color = ? WHERE date = ?");
                        $stmt->execute([$opening_time, $closing_time, $is_open, $description, $color, $date]);
                    } else {
                        // Insert new
                        $stmt = $pdo->prepare("INSERT INTO special_days (date, opening_time, closing_time, is_open, description, color, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$date, $opening_time, $closing_time, $is_open, $description, $color, $_SESSION['user_id']]);
                    }
                    
                    $success_message = "Día especial agregado/actualizado correctamente";
                } catch (Exception $e) {
                    $error_message = "Error al agregar día especial: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get business hours
$stmt = $pdo->prepare("SELECT * FROM business_hours ORDER BY day_of_week");
$stmt->execute();
$business_hours = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $business_hours[$row['day_of_week']] = $row;
}

// Get special days for current month
$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT * FROM special_days WHERE date >= ? AND date < ? ORDER BY date");
$stmt->execute([$current_month . '-01', date('Y-m-t', strtotime($current_month . '-01'))]);
$special_days = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $special_days[$row['date']] = $row;
}

// Day names in Spanish
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

if (isset($special_days[$today])) {
    // Special day takes precedence
    $today_schedule = $special_days[$today];
    if ($today_schedule['is_open'] && $today_schedule['opening_time'] && $today_schedule['closing_time']) {
        $opening = $today_schedule['opening_time'];
        $closing = $today_schedule['closing_time'];
        if ($current_time >= $opening && $current_time <= $closing) {
            $is_currently_open = true;
            $current_status = "Abierto hasta las " . date('H:i', strtotime($closing));
        } else if ($current_time < $opening) {
            $current_status = "Abre a las " . date('H:i', strtotime($opening));
        }
    }
} else if (isset($business_hours[$current_day]) && $business_hours[$current_day]['is_open']) {
    // Regular business hours
    $today_schedule = $business_hours[$current_day];
    $opening = $today_schedule['opening_time'];
    $closing = $today_schedule['closing_time'];
    if ($current_time >= $opening && $current_time <= $closing) {
        $is_currently_open = true;
        $current_status = "Abierto hasta las " . date('H:i', strtotime($closing));
    } else if ($current_time < $opening) {
        $current_status = "Abre a las " . date('H:i', strtotime($opening));
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['site_title'] ?? 'Habbo Agency'; ?> - Horarios</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .schedule-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

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

        .status-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .status-text {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            margin-bottom: 10px;
        }

        .status-details {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
        }

        .business-hours-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .day-schedule {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .day-schedule:last-child {
            border-bottom: none;
        }

        .day-name {
            font-weight: bold;
            color: white;
            width: 100px;
        }

        .day-hours {
            color: rgba(255, 255, 255, 0.8);
        }

        .day-open {
            color: #22c55e;
        }

        .day-closed {
            color: #ef4444;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: white;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .time-input {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .admin-controls {
            margin-top: 30px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
        }

        .calendar-header {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px;
            text-align: center;
            font-weight: bold;
            color: white;
        }

        .calendar-day {
            background: rgba(255, 255, 255, 0.05);
            padding: 10px;
            text-align: center;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .calendar-day:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .calendar-day.open {
            background: rgba(34, 197, 94, 0.3);
            border: 1px solid rgba(34, 197, 94, 0.5);
        }

        .calendar-day.closed {
            background: rgba(239, 68, 68, 0.3);
            border: 1px solid rgba(239, 68, 68, 0.5);
        }

        .calendar-day.special {
            background: rgba(147, 51, 234, 0.3);
            border: 1px solid rgba(147, 51, 234, 0.5);
        }

        .calendar-day.today {
            border: 2px solid white;
            font-weight: bold;
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <div class="glass-panel">
            <div class="dashboard-header">
                <div class="header-content">
                    <h1 class="dashboard-title">
                        <i class="fas fa-calendar-alt"></i>
                        Horarios de la Agencia
                    </h1>
                    
                    <div class="header-actions">
                        <a href="dashboard.php" class="back-btn">
                            <i class="fas fa-arrow-left"></i>
                            Volver al Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($success_message): ?>
            <div style="background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.4); color: #22c55e; padding: 15px; border-radius: 10px; margin: 20px 0;">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.4); color: #ef4444; padding: 15px; border-radius: 10px; margin: 20px 0;">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>

            <!-- Current Status -->
            <div class="current-status <?php echo $is_currently_open ? 'status-open' : 'status-closed'; ?>">
                <div class="status-icon">
                    <i class="fas fa-<?php echo $is_currently_open ? 'door-open' : 'door-closed'; ?>" style="color: <?php echo $is_currently_open ? '#22c55e' : '#ef4444'; ?>;"></i>
                </div>
                <div class="status-text"><?php echo $is_currently_open ? 'ABIERTO' : 'CERRADO'; ?></div>
                <div class="status-details"><?php echo $current_status; ?></div>
            </div>

            <div class="schedule-container">
                <!-- Business Hours Display -->
                <div class="business-hours-card">
                    <h3 style="color: white; margin-bottom: 20px;">
                        <i class="fas fa-clock"></i>
                        Horarios Regulares
                    </h3>
                    
                    <?php foreach ($day_names as $day_num => $day_name): ?>
                    <div class="day-schedule">
                        <span class="day-name"><?php echo $day_name; ?></span>
                        <span class="day-hours <?php echo isset($business_hours[$day_num]) && $business_hours[$day_num]['is_open'] ? 'day-open' : 'day-closed'; ?>">
                            <?php 
                            if (isset($business_hours[$day_num]) && $business_hours[$day_num]['is_open']) {
                                echo date('H:i', strtotime($business_hours[$day_num]['opening_time'])) . ' - ' . 
                                     date('H:i', strtotime($business_hours[$day_num]['closing_time']));
                            } else {
                                echo 'Cerrado';
                            }
                            ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar View -->
                <div class="business-hours-card">
                    <h3 style="color: white; margin-bottom: 20px;">
                        <i class="fas fa-calendar"></i>
                        Calendario - <?php echo strftime('%B %Y', strtotime($current_month . '-01')); ?>
                    </h3>
                    
                    <div class="calendar-grid">
                        <!-- Calendar headers -->
                        <?php foreach (['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] as $header): ?>
                        <div class="calendar-header"><?php echo $header; ?></div>
                        <?php endforeach; ?>
                        
                        <?php
                        // Generate calendar days
                        $first_day = date('w', strtotime($current_month . '-01'));
                        $days_in_month = date('t', strtotime($current_month . '-01'));
                        
                        // Empty cells for days before month starts
                        for ($i = 0; $i < $first_day; $i++) {
                            echo '<div class="calendar-day"></div>';
                        }
                        
                        // Days of the month
                        for ($day = 1; $day <= $days_in_month; $day++) {
                            $current_date = $current_month . '-' . sprintf('%02d', $day);
                            $day_of_week = date('w', strtotime($current_date));
                            
                            $classes = ['calendar-day'];
                            
                            // Check if today
                            if ($current_date === $today) {
                                $classes[] = 'today';
                            }
                            
                            // Check if special day
                            if (isset($special_days[$current_date])) {
                                $classes[] = 'special';
                            } else if (isset($business_hours[$day_of_week]) && $business_hours[$day_of_week]['is_open']) {
                                $classes[] = 'open';
                            } else {
                                $classes[] = 'closed';
                            }
                            
                            echo '<div class="' . implode(' ', $classes) . '">' . $day . '</div>';
                        }
                        ?>
                    </div>
                    
                    <div style="margin-top: 15px; font-size: 0.9rem; color: rgba(255, 255, 255, 0.7);">
                        <div><span style="display: inline-block; width: 15px; height: 15px; background: rgba(34, 197, 94, 0.3); margin-right: 8px;"></span>Días abiertos</div>
                        <div><span style="display: inline-block; width: 15px; height: 15px; background: rgba(239, 68, 68, 0.3); margin-right: 8px;"></span>Días cerrados</div>
                        <div><span style="display: inline-block; width: 15px; height: 15px; background: rgba(147, 51, 234, 0.3); margin-right: 8px;"></span>Días especiales</div>
                    </div>
                </div>
            </div>

            <?php if ($can_manage): ?>
            <!-- Admin Controls -->
            <div class="admin-controls">
                <div class="business-hours-card">
                    <h3 style="color: white; margin-bottom: 20px;">
                        <i class="fas fa-cog"></i>
                        Configuración de Horarios
                    </h3>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_business_hours">
                        
                        <?php foreach ($day_names as $day_num => $day_name): ?>
                        <div class="form-group">
                            <label><?php echo $day_name; ?></label>
                            <div class="checkbox-container">
                                <input type="checkbox" name="is_open_<?php echo $day_num; ?>" id="is_open_<?php echo $day_num; ?>" 
                                       <?php echo (isset($business_hours[$day_num]) && $business_hours[$day_num]['is_open']) ? 'checked' : ''; ?>>
                                <label for="is_open_<?php echo $day_num; ?>">Abierto</label>
                            </div>
                            <div class="time-input">
                                <input type="time" name="opening_time_<?php echo $day_num; ?>" class="glass-input" 
                                       value="<?php echo isset($business_hours[$day_num]) ? $business_hours[$day_num]['opening_time'] : '09:00'; ?>">
                                <span style="color: white;">a</span>
                                <input type="time" name="closing_time_<?php echo $day_num; ?>" class="glass-input"
                                       value="<?php echo isset($business_hours[$day_num]) ? $business_hours[$day_num]['closing_time'] : '18:00'; ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="glass-button">
                            <i class="fas fa-save"></i>
                            Guardar Horarios
                        </button>
                    </form>
                </div>

                <div class="business-hours-card">
                    <h3 style="color: white; margin-bottom: 20px;">
                        <i class="fas fa-star"></i>
                        Agregar Día Especial
                    </h3>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="add_special_day">
                        
                        <div class="form-group">
                            <label for="special_date">Fecha:</label>
                            <input type="date" name="special_date" id="special_date" class="glass-input" required>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-container">
                                <input type="checkbox" name="special_is_open" id="special_is_open" checked>
                                <label for="special_is_open">Abierto</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Horario:</label>
                            <div class="time-input">
                                <input type="time" name="special_opening_time" class="glass-input" value="09:00">
                                <span style="color: white;">a</span>
                                <input type="time" name="special_closing_time" class="glass-input" value="18:00">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="special_description">Descripción:</label>
                            <input type="text" name="special_description" id="special_description" class="glass-input" 
                                   placeholder="Ej: Feriado nacional, Evento especial">
                        </div>
                        
                        <div class="form-group">
                            <label for="special_color">Color en calendario:</label>
                            <input type="color" name="special_color" id="special_color" value="#9333ea" class="glass-input" style="width: 60px; height: 40px;">
                        </div>
                        
                        <button type="submit" class="glass-button">
                            <i class="fas fa-plus"></i>
                            Agregar Día Especial
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>