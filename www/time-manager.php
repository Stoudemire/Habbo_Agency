<?php
session_start();

// Set timezone to avoid server timezone issues
date_default_timezone_set('UTC');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

include 'config/database.php';

// Get user role for interface control with session caching
if (!isset($_SESSION['user_role'])) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['user_role'] = $stmt->fetchColumn();
}
$user_role = $_SESSION['user_role'];

// Function to get credits configuration for a specific user by their rank
function getCreditsConfigForUser($pdo, $user_id) {
    static $credits_cache = [];

    if (!isset($credits_cache[$user_id])) {
        // Get user's role and credits configuration from rank
        $stmt = $pdo->prepare("
            SELECT ur.credits_time_hours, ur.credits_time_minutes, ur.credits_per_interval,
                   ur.max_time_hours, ur.max_time_minutes, ur.auto_complete_enabled
            FROM users u 
            JOIN user_ranks ur ON u.role = ur.rank_name 
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $config = $stmt->fetch();

        if ($config) {
            $credits_config = [
                'time_hours' => intval($config['credits_time_hours'] ?? 1),
                'time_minutes' => intval($config['credits_time_minutes'] ?? 0),
                'credits_per_interval' => intval($config['credits_per_interval'] ?? 1),
                'max_time_hours' => intval($config['max_time_hours'] ?? 8),
                'max_time_minutes' => intval($config['max_time_minutes'] ?? 0),
                'auto_complete_enabled' => intval($config['auto_complete_enabled'] ?? 1)
            ];
        } else {
            // Default configuration if no rank found
            $credits_config = [
                'time_hours' => 1,
                'time_minutes' => 0,
                'credits_per_interval' => 1,
                'max_time_hours' => 8,
                'max_time_minutes' => 0,
                'auto_complete_enabled' => 1
            ];
        }

        // Calculate total minutes and credits per minute
        $total_minutes = ($credits_config['time_hours'] * 60) + $credits_config['time_minutes'];
        if ($total_minutes <= 0) $total_minutes = 60;

        $credits_config['credits_per_minute'] = $credits_config['credits_per_interval'] / $total_minutes;

        // Calculate max time in seconds
        $credits_config['max_time_seconds'] = ($credits_config['max_time_hours'] * 3600) + ($credits_config['max_time_minutes'] * 60);

        $credits_cache[$user_id] = $credits_config;
    }

    return $credits_cache[$user_id];
}

// Define what interface to show based on role
$can_manage_timers = in_array($user_role, ['super_admin', 'desarrollador', 'administrador', 'operador']);
$is_readonly_view = !$can_manage_timers;

// Handle AJAX requests for getting sessions (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_sessions') {
    header('Content-Type: application/json');

    try {
        // Get active sessions with calculated current totals
        // If user can't manage timers, only show their own sessions
        if ($can_manage_timers) {
            $stmt = $pdo->prepare("
                SELECT ts.*, u.username 
                FROM time_sessions ts 
                JOIN users u ON ts.user_id = u.id 
                WHERE ts.status IN ('active', 'paused') 
                ORDER BY u.username ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT ts.*, u.username 
                FROM time_sessions ts 
                JOIN users u ON ts.user_id = u.id 
                WHERE ts.status IN ('active', 'paused') AND ts.user_id = ?
                ORDER BY u.username ASC
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        $active_sessions = $stmt->fetchAll();

        // Calculate current totals and credits for JavaScript
        foreach ($active_sessions as &$session) {
            $total_seconds = $session['total_time'];
            if ($session['status'] === 'active') {
                $start_timestamp = strtotime($session['start_time']);
                $now_timestamp = time();
                $elapsed = $now_timestamp - $start_timestamp;
                $total_seconds += $elapsed;
            }
            $session['current_total'] = $total_seconds;

            // Add server timestamp for accurate client synchronization
            $session['server_timestamp'] = time();
            $session['start_timestamp'] = strtotime($session['start_time']);

            // Get credits configuration for this user's rank
            $user_credits_config = getCreditsConfigForUser($pdo, $session['user_id']);

            // Calculate credits earned using interval-based system (not proportional)
            $total_minutes = $total_seconds / 60;
            $interval_minutes = ($user_credits_config['time_hours'] * 60) + $user_credits_config['time_minutes'];
            if ($interval_minutes <= 0) $interval_minutes = 60;

            // Only give credits for completed intervals
            $completed_intervals = floor($total_minutes / $interval_minutes);
            $session['credits_earned'] = $completed_intervals * $user_credits_config['credits_per_interval'];

            // Add credits config to session for JavaScript
            $session['credits_config'] = $user_credits_config;
        }

        // Add debug info
        $response = [
            'success' => true,
            'count' => count($active_sessions),
            'timestamp' => date('Y-m-d H:i:s'),
            'server_timestamp' => time(),
            'sessions' => $active_sessions,
            'credits_per_minute' => $credits_per_minute
        ];

        echo json_encode($response);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Function to auto-complete users who reached max time limit
function autoCompleteUsersAtMaxTime($pdo) {
    try {
        // Get all active sessions with their user configs, excluding users already in payment history
        $stmt = $pdo->prepare("
            SELECT ts.*, ur.max_time_hours, ur.max_time_minutes, ur.auto_complete_enabled, u.username
            FROM time_sessions ts 
            JOIN users u ON ts.user_id = u.id
            JOIN user_ranks ur ON u.role = ur.rank_name 
            LEFT JOIN payment_history ph ON ts.user_id = ph.user_id
            WHERE ts.status = 'active' AND ur.auto_complete_enabled = 1 AND ph.user_id IS NULL
        ");
        $stmt->execute();
        $active_sessions = $stmt->fetchAll();

        foreach ($active_sessions as $session) {
            $max_time_seconds = ($session['max_time_hours'] * 3600) + ($session['max_time_minutes'] * 60);

            // Calculate current total time
            $total_seconds = $session['total_time'];
            $start_timestamp = strtotime($session['start_time']);
            $now_timestamp = time();
            $elapsed = $now_timestamp - $start_timestamp;
            $current_total = $total_seconds + $elapsed;

            // Check if user has reached max time limit
            if ($current_total >= $max_time_seconds) {
                // Auto-complete the session (same logic as stop_timer)
                $stmt_update = $pdo->prepare("UPDATE time_sessions SET status = 'completed', total_time = ?, end_time = NOW() WHERE id = ?");
                $stmt_update->execute([$current_total, $session['id']]);

                // Add user to payment list when they complete max time - prevent duplicates
                $stmt_check = $pdo->prepare("SELECT id FROM payment_history WHERE user_id = ?");
                $stmt_check->execute([$session['user_id']]);

                if (!$stmt_check->fetch()) {
                    // Only add if not already in payment history
                    $stmt_payment = $pdo->prepare("
                        INSERT INTO payment_history (user_id, status, created_at, updated_at) 
                        VALUES (?, 'PENDIENTE', NOW(), NOW())
                    ");
                    $stmt_payment->execute([$session['user_id']]);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error in autoCompleteUsersAtMaxTime: " . $e->getMessage());
    }
}

// Handle AJAX requests for actions (POST requests) - only for authorized users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Check if user has permission to manage timers
    if (!$can_manage_timers) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos para realizar esta acción']);
        exit();
    }

    switch ($_POST['action']) {
        case 'start_timer':
            $username = $_POST['username'];
            $description = $_POST['description'] ?? '';

            // Find user by username
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
                exit();
            }

            $user_id = $user['id'];

            // Check if user already has active timer
            $stmt = $pdo->prepare("SELECT id FROM time_sessions WHERE user_id = ? AND status IN ('active', 'paused')");
            $stmt->execute([$user_id]);

            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'El usuario ya tiene un tiempo activo']);
                exit();
            }

            // Check if user is in payment list with PENDIENTE status - don't allow new work
            $stmt = $pdo->prepare("SELECT status FROM payment_history WHERE user_id = ? AND status = 'PENDIENTE'");
            $stmt->execute([$user_id]);
            $pending_payment = $stmt->fetch();

            if ($pending_payment) {
                echo json_encode(['success' => false, 'message' => 'El usuario tiene un pago pendiente. Debe ser procesado antes de poder trabajar nuevamente.']);
                exit();
            }

            // If user was marked as PAGADO, remove them from payment_history and clear completed sessions to allow new work cycle
            $stmt = $pdo->prepare("DELETE FROM payment_history WHERE user_id = ? AND status = 'PAGADO'");
            $stmt->execute([$user_id]);

            // Also clear all completed sessions for this user to reset their time tracking completely
            $stmt = $pdo->prepare("DELETE FROM time_sessions WHERE user_id = ? AND status = 'completed'");
            $stmt->execute([$user_id]);

            // Get accumulated time from the user's most recent completed session (should be 0 now)
            $stmt = $pdo->prepare("SELECT total_time FROM time_sessions WHERE user_id = ? AND status = 'completed' ORDER BY end_time DESC LIMIT 1");
            $stmt->execute([$user_id]);
            $last_session = $stmt->fetch();
            $accumulated_seconds = $last_session ? $last_session['total_time'] : 0;

            // Start new timer with accumulated time from previous completed sessions
            $stmt = $pdo->prepare("INSERT INTO time_sessions (user_id, username, start_time, description, status, total_time) VALUES (?, ?, UTC_TIMESTAMP(), ?, 'active', ?)");
            $success = $stmt->execute([$user_id, $username, $description, $accumulated_seconds]);

            echo json_encode(['success' => $success]);
            break;

        case 'pause_timer':
            $session_id = $_POST['session_id'];

            // Get current session
            $stmt = $pdo->prepare("SELECT * FROM time_sessions WHERE id = ? AND status = 'active'");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            if ($session) {
                // Calculate elapsed time and add to total - use simple time calculation
                $start_timestamp = strtotime($session['start_time']);
                $now_timestamp = time();
                $elapsed = $now_timestamp - $start_timestamp;
                $new_total = $session['total_time'] + $elapsed;

                $stmt = $pdo->prepare("UPDATE time_sessions SET status = 'paused', total_time = ? WHERE id = ?");
                $success = $stmt->execute([$new_total, $session_id]);

                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Sesión no encontrada']);
            }
            break;

        case 'resume_timer':
            $session_id = $_POST['session_id'];

            // Get current session to preserve accumulated time
            $stmt = $pdo->prepare("SELECT * FROM time_sessions WHERE id = ? AND status = 'paused'");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            if ($session) {
                // Resume timer: change status to active and reset start_time for new timing period
                // The accumulated time is already stored in total_time
                $stmt = $pdo->prepare("UPDATE time_sessions SET status = 'active', start_time = UTC_TIMESTAMP() WHERE id = ?");
                $success = $stmt->execute([$session_id]);

                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Sesión no encontrada o no está pausada']);
            }
            break;

        case 'stop_timer':
            $session_id = $_POST['session_id'];

            // Get current session
            $stmt = $pdo->prepare("SELECT * FROM time_sessions WHERE id = ? AND status IN ('active', 'paused')");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            if ($session) {
                $total_seconds = $session['total_time'];

                // If it's active, add current elapsed time
                if ($session['status'] === 'active') {
                    $start_timestamp = strtotime($session['start_time']);
                    $now_timestamp = time();
                    $elapsed = $now_timestamp - $start_timestamp;
                    $total_seconds += $elapsed;
                }

                $stmt = $pdo->prepare("UPDATE time_sessions SET status = 'completed', total_time = ?, end_time = NOW() WHERE id = ?");
                $success = $stmt->execute([$total_seconds, $session_id]);

                // Check if user completed max time and add to payment list
                $user_credits_config = getCreditsConfigForUser($pdo, $session['user_id']);
                $max_time_seconds = ($user_credits_config['max_time_hours'] * 3600) + ($user_credits_config['max_time_minutes'] * 60);

                // Only add to payment list if auto_complete is enabled AND max time is reached AND user not already in payment history
                if ($user_credits_config['auto_complete_enabled'] && $total_seconds >= $max_time_seconds) {
                    // Check if user is already in payment_history to prevent duplicates
                    $stmt_check = $pdo->prepare("SELECT id FROM payment_history WHERE user_id = ?");
                    $stmt_check->execute([$session['user_id']]);

                    if (!$stmt_check->fetch()) {
                        // Only add if not already in payment history
                        $stmt_payment = $pdo->prepare("
                            INSERT INTO payment_history (user_id, status, created_at, updated_at) 
                            VALUES (?, 'PENDIENTE', NOW(), NOW())
                        ");
                        $stmt_payment->execute([$session['user_id']]);
                    }
                }

                echo json_encode(['success' => $success, 'total_time' => $total_seconds, 'max_time_reached' => $total_seconds >= $max_time_seconds]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Sesión no encontrada']);
            }
            break;

        case 'cancel_timer':
            $session_id = $_POST['session_id'];

            // Get the session to cancel
            $stmt = $pdo->prepare("SELECT user_id FROM time_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            if ($session) {
                // Only delete the current session, NOT all user sessions
                // This preserves completed sessions and payment history
                $stmt = $pdo->prepare("DELETE FROM time_sessions WHERE id = ?");
                $success = $stmt->execute([$session_id]);

                echo json_encode(['success' => $success, 'message' => 'Tiempo cancelado correctamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Sesión no encontrada']);
            }
            break;

        case 'get_active_sessions':
            // If user can't manage timers, only show their own sessions
            if ($can_manage_timers) {
                $stmt = $pdo->prepare("
                    SELECT ts.*, u.username 
                    FROM time_sessions ts 
                    JOIN users u ON ts.user_id = u.id 
                    WHERE ts.status IN ('active', 'paused') 
                    ORDER BY u.username ASC
                ");
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare("
                    SELECT ts.*, u.username 
                    FROM time_sessions ts 
                    JOIN users u ON ts.user_id = u.id 
                    WHERE ts.status IN ('active', 'paused') AND ts.user_id = ?
                    ORDER BY u.username ASC
                ");
                $stmt->execute([$_SESSION['user_id']]);
            }
            $sessions = $stmt->fetchAll();

            // Calculate current elapsed time for active sessions
            foreach ($sessions as &$session) {
                if ($session['status'] === 'active') {
                    $start_timestamp = strtotime($session['start_time']);
                    $now_timestamp = time();
                    $elapsed = $now_timestamp - $start_timestamp;
                    $session['current_total'] = $session['total_time'] + $elapsed;
                } else {
                    $session['current_total'] = $session['total_time'];
                }
            }

            echo json_encode($sessions);
            break;
        case 'clear_history':
            // Esta funcionalidad debe ser manejada en lista-pagas.php, no aquí
            echo json_encode(['success' => false, 'message' => 'Funcionalidad movida a Lista de Pagas']);
            exit;
    }
    exit();
}

// Get all users for dropdown - simplified using existing PDO connection
$stmt = $pdo->prepare("SELECT id, username FROM users ORDER BY username ASC");
$stmt->execute();
$users = $stmt->fetchAll();

// Get active sessions with calculated current totals
// If user can't manage timers, only show their own sessions
if ($can_manage_timers) {
    $stmt = $pdo->prepare("
        SELECT ts.*, u.username 
        FROM time_sessions ts 
        JOIN users u ON ts.user_id = u.id 
        WHERE ts.status IN ('active', 'paused') 
        ORDER BY u.username ASC
    ");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("
        SELECT ts.*, u.username 
        FROM time_sessions ts 
        JOIN users u ON ts.user_id = u.id 
        WHERE ts.status IN ('active', 'paused') AND ts.user_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
}
$active_sessions = $stmt->fetchAll();

// Calculate current totals for JavaScript
foreach ($active_sessions as &$session) {
    $total_seconds = $session['total_time'];
    if ($session['status'] === 'active') {
        $start_timestamp = strtotime($session['start_time']);
        $now_timestamp = time();
        $elapsed = $now_timestamp - $start_timestamp;
        $total_seconds += $elapsed;
    }
    $session['current_total'] = $total_seconds;

    // Get and add credits configuration for this user
    $user_credits_config = getCreditsConfigForUser($pdo, $session['user_id']);
    $session['credits_config'] = $user_credits_config;
}

$current_user = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Tiempos - Habbo Agency</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/time-manager.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet" />

    <style>
        /* Select2 Glass-morphism styling - Match Description field */
        .select2-container {
            width: 100% !important;
        }

        .select2-container--default .select2-selection--single {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            height: 45px !important; /* Same height as glass-input */
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            min-height: 45px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #FFFFFF !important; /* Exact same as glass-input text color */
            padding: 12px 16px !important; /* Exact same padding as glass-input */
            line-height: normal !important; /* Let it align naturally like glass-input */
            font-size: 1rem !important; /* Same as glass-input */
            display: flex !important;
            align-items: center !important;
            height: 21px !important; /* Natural text height */
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: rgba(255, 255, 255, 0.6) !important; /* Exact same as glass-input placeholder */
            padding: 12px 16px !important; /* Exact same padding as glass-input */
            margin: 0 !important;
            font-size: 1rem !important; /* Same as glass-input */
            line-height: normal !important; /* Natural line height like glass-input */
            display: flex !important;
            align-items: center !important;
            height: 21px !important; /* Same natural height as text */
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px !important;
            right: 8px !important;
            top: 1px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: rgba(255, 255, 255, 0.8) transparent transparent transparent;
            margin-top: -2px;
        }

        /* Ensure both form groups have same width */
        .form-row .form-group {
            flex: 1;
            min-width: 0;
        }

        .form-row .form-group:first-child {
            margin-right: 15px;
        }

        /* Dropdown styling with glass effect */
        .select2-dropdown {
            background: rgba(255, 255, 255, 0.15) !important;
            backdrop-filter: blur(15px) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3) !important;
        }

        /* Search field in dropdown with magnifying glass */
        .select2-search--dropdown {
            position: relative;
            padding: 8px;
        }

        .select2-search--dropdown .select2-search__field {
            background: rgba(255, 255, 255, 0.2) !important;
            backdrop-filter: blur(10px) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 6px !important;
            color: white !important;
            padding: 8px 12px 8px 35px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .select2-search--dropdown .select2-search__field::placeholder {
            color: rgba(255, 255, 255, 0.7) !important;
        }

        /* Add magnifying glass icon to search field */
        .select2-search--dropdown::before {
            content: '\f002'; /* FontAwesome search icon */
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.8);
            z-index: 10;
            pointer-events: none;
        }

        /* User options styling - better visibility */
        .select2-container--default .select2-results__option {
            color: white !important;
            background: transparent !important;
            padding: 10px 15px !important;
            font-weight: 500 !important;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3) !important;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background: rgba(156, 39, 176, 0.4) !important;
            color: white !important;
        }

        .select2-container--default .select2-results__option[aria-selected=true] {
            background: rgba(156, 39, 176, 0.6) !important;
            color: white !important;
        }

        /* Focus state */
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: rgba(156, 39, 176, 0.6);
            box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
        }

        /* Custom Glass-morphism Scrollbar */
        .select2-results__options::-webkit-scrollbar {
            width: 8px;
        }

        .select2-results__options::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            backdrop-filter: blur(5px);
        }

        .select2-results__options::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.6), 
                rgba(156, 39, 176, 0.8));
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .select2-results__options::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.8), 
                rgba(156, 39, 176, 1));
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
        }

        .select2-results__options::-webkit-scrollbar-thumb:active {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 1), 
                rgba(120, 30, 140, 1));
        }

        /* Global glass scrollbar for entire page */
        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            backdrop-filter: blur(10px);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, 
                rgba(156, 39, 176, 0.4), 
                rgba(156, 39, 176, 0.7));
            border-radius: 6px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, 
                rgba(156, 39, 176, 0.7), 
                rgba(156, 39, 176, 0.9));
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            transform: scale(1.1);
        }

        ::-webkit-scrollbar-thumb:active {
            background: linear-gradient(135deg, 
                rgba(156, 39, 176, 0.9), 
                rgba(120, 30, 140, 1));
        }

        /* Firefox scrollbar support */
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(156, 39, 176, 0.6) rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-clock"></i>
                    <?php echo $can_manage_timers ? 'Gestor de Tiempos' : 'Mis Tiempos Activos - ' . htmlspecialchars($_SESSION['username']); ?>
                </h1>

                <div class="header-actions">
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <?php if ($can_manage_timers): ?>
        <!-- Start Timer Section - Only for Operators and Admins -->
        <div class="dashboard-card">
            <h3 class="card-title">
                <i class="fas fa-play"></i>
                Iniciar Nuevo Tiempo
            </h3>

            <form id="startTimerForm" class="timer-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="user_id">Usuario:</label>
                        <select id="user_id" name="user_id" class="glass-input user-select" required>
                            <option disabled selected>Selecciona un usuario...</option>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option disabled>No hay usuarios disponibles</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="description">Descripción (opcional):</label>
                        <input type="text" id="description" name="description" 
                               placeholder="Ej: Atención al cliente" class="glass-input">
                    </div>

                    <button type="submit" class="glass-button start-btn">
                        <i class="fas fa-play"></i>
                        Iniciar Tiempo
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Active Sessions Table -->
        <div class="dashboard-card">
            <h3 class="card-title">
                <i class="fas fa-list"></i>
                Tiempos Activos
                <span class="refresh-btn" onclick="refreshSessions()">
                    <i class="fas fa-sync-alt"></i>
                </span>
            </h3>

            <div class="table-container">
                <table id="sessionsTable" class="sessions-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <?php if ($can_manage_timers): ?>
                            <th>Descripción</th>
                            <?php endif; ?>
                            <th>Tiempo Transcurrido</th>
                            <th>Créditos</th>
                            <th>Estado</th>
                            <?php if ($can_manage_timers): ?>
                            <th>Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="activeSessions">
                        <?php if (empty($active_sessions)): ?>
                            <!-- No sessions message will be shown by JavaScript -->
                        <?php else: ?>
                            <?php foreach ($active_sessions as $session): ?>
                                <?php
                                // Calculate current total time
                                $total_seconds = $session['total_time'];
                                if ($session['status'] === 'active') {
                                    $start_timestamp = strtotime($session['start_time']);
                                    $now_timestamp = time();
                                    $elapsed = $now_timestamp - $start_timestamp;
                                    $total_seconds += $elapsed;
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <img src="https://www.habbo.es/habbo-imaging/avatarimage?img_format=png&user=<?php echo urlencode($session['username']); ?>&direction=2&head_direction=3&size=l&gesture=std&action=std&headonly=1" 
                                                 alt="Avatar de <?php echo htmlspecialchars($session['username']); ?>" 
                                                 class="user-avatar"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="user-avatar-placeholder" style="display: none;">
                                                <?php echo strtoupper(substr($session['username'], 0, 1)); ?>
                                            </div>
                                            <div class="user-details">
                                                <h4><?php echo htmlspecialchars($session['username']); ?></h4>
                                                <p>Habbo: <?php echo htmlspecialchars($session['username']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <?php if ($can_manage_timers): ?>
                                    <td><?php echo htmlspecialchars($session['description'] ?: 'Sin descripción'); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span id="timer-<?php echo $session['id']; ?>" class="timer-display">
                                            <?php 
                                            $hours = floor($total_seconds / 3600);
                                            $minutes = floor(($total_seconds % 3600) / 60);
                                            $seconds = $total_seconds % 60;
                                            printf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span id="credits-<?php echo $session['id']; ?>" class="credits-display">
                                            <?php 
                                            $user_credits_config = getCreditsConfigForUser($pdo, $session['user_id']);
                                            $total_minutes = $total_seconds / 60;
                                            $interval_minutes = ($user_credits_config['time_hours'] * 60) + $user_credits_config['time_minutes'];
                                            if ($interval_minutes <= 0) $interval_minutes = 60;
                                            $completed_intervals = floor($total_minutes / $interval_minutes);
                                            $credits_earned = $completed_intervals * $user_credits_config['credits_per_interval'];
                                            echo $credits_earned;
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $session['status']; ?>">
                                            <?php echo strtoupper($session['status']); ?>
                                        </span>
                                    </td>
                                    <?php if ($can_manage_timers): ?>
                                    <td class="session-actions">
                                        <?php 
                                        $user_config = getCreditsConfigForUser($pdo, $session['user_id']);
                                        $auto_complete_enabled = $user_config['auto_complete_enabled'];
                                        ?>
                                        <?php if ($session['status'] === 'active'): ?>
                                            <button class="action-btn pause-btn" onclick="timeManager.pauseTimer(<?php echo $session['id']; ?>)">⏸ Pausar</button>
                                            <?php if ($auto_complete_enabled): ?>
                                                <button class="action-btn stop-btn disabled" disabled title="Auto-completar habilitado - el tiempo se detendrá automáticamente">⏹ Detener</button>
                                            <?php else: ?>
                                                <button class="action-btn stop-btn" onclick="timeManager.stopTimer(<?php echo $session['id']; ?>)">⏹ Detener</button>
                                            <?php endif; ?>
                                            <button class="action-btn cancel-btn" onclick="timeManager.cancelTimer(<?php echo $session['id']; ?>)">✕ Cancelar</button>
                                        <?php elseif ($session['status'] === 'paused'): ?>
                                            <button class="action-btn resume-btn" onclick="timeManager.resumeTimer(<?php echo $session['id']; ?>)">▶ Reanudar</button>
                                            <?php if ($auto_complete_enabled): ?>
                                                <button class="action-btn stop-btn disabled" disabled title="Auto-completar habilitado - el tiempo se detendrá automáticamente">⏹ Detener</button>
                                            <?php else: ?>
                                                <button class="action-btn stop-btn" onclick="timeManager.stopTimer(<?php echo $session['id']; ?>)">⏹ Detener</button>
                                            <?php endif; ?>
                                            <button class="action-btn cancel-btn" onclick="timeManager.cancelTimer(<?php echo $session['id']; ?>)">✕ Cancelar</button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div id="noSessions" class="no-sessions" style="display: <?php echo empty($active_sessions) ? 'block' : 'none'; ?>;">
                    <i class="fas fa-clock"></i>
                    <p><?php echo $can_manage_timers ? 'No hay tiempos activos en este momento' : 'No tienes tiempos activos en este momento'; ?></p>
                </div>
            </div>
        </div>
    </div>


    <script>
        // Pass PHP data to JavaScript
        window.usersData = <?php echo json_encode($users); ?>;
        window.activeSessionsData = <?php echo json_encode($active_sessions); ?>;
        window.canManageTimers = <?php echo json_encode($can_manage_timers); ?>;
        window.userRole = <?php echo json_encode($user_role); ?>;

        window.currentUserId = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.currentUsername = <?php echo json_encode($_SESSION['username']); ?>;

        console.log('Data loaded:', {
            users: window.usersData,
            sessions: window.activeSessionsData,
            canManage: window.canManageTimers,
            role: window.userRole,
            currentUser: window.currentUsername
        });
    </script>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

    <!-- Initialize Select2 -->
    <script>
        $(document).ready(function() {
            // Initialize Select2 with search functionality
            $('#user_id').select2({
                placeholder: 'Selecciona un usuario...',
                allowClear: false,
                width: '100%',
                theme: 'default',
                language: {
                    noResults: function() {
                        return "No se encontraron usuarios";
                    },
                    searching: function() {
                        return "Buscando...";
                    }
                }
            });

            // Custom styling for Select2 to match glass-morphism
            $('.select2-container').addClass('glass-select2');
        });
    </script>

    <script src="assets/js/scripts.js"></script>
    <script src="assets/js/notifications.js"></script>
    <script src="assets/js/time-manager.js"></script>
</body>
</html>