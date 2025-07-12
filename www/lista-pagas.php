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

// Check if user has manage_users permission or is super_admin
$has_payment_permission = ($user_role === 'super_admin') || in_array('manage_users', $user_permissions);

if (!$has_payment_permission) {
    header('Location: dashboard.php?access_denied=1');
    exit;
}

// Handle payment status updates


// Handle GET request for history data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');

    try {
        // Get fresh history data from database - handle orphaned records
        // Mostrar PAGADOS y PROCESADOS para preservar historial completo
        $stmt = $pdo->query("
            SELECT 
                COALESCE(u.id, ph.user_id) as id,
                COALESCE(u.habbo_username, CONCAT('Usuario_', ph.user_id)) as username,
                COALESCE(u.habbo_username, '') as habbo_username,
                COALESCE(u.role, 'usuario') as role,
                COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
                COALESCE(ur.rank_image, '') as rank_image,
                ph.status as payment_status,
                ph.amount,
                ph.updated_at as payment_updated,
                COALESCE(processor.habbo_username, 'Sistema') as processed_by_username
            FROM payment_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            LEFT JOIN users processor ON ph.processed_by_user_id = processor.id
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name
            WHERE ph.status IN ('PAGADO', 'PROCESADO')
            ORDER BY ph.updated_at DESC
        ");

        $history_data = $stmt->fetchAll();
        echo json_encode(['success' => true, 'history' => $history_data]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'];

        // Handle clear_history separately (no status validation needed)
        if ($action === 'clear_history') {
            $pdo->beginTransaction();
            try {
                // Borrar COMPLETAMENTE todo el historial de pagos
                $stmt = $pdo->prepare("DELETE FROM payment_history");
                $success_payments = $stmt->execute();

                // Borrar TODAS las sesiones de tiempo (activas, pausadas, completadas)
                $stmt_sessions = $pdo->prepare("DELETE FROM time_sessions");
                $success_sessions = $stmt_sessions->execute();

                if ($success_payments && $success_sessions) {
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Lista completamente limpiada - todo comienza de 0']);
                } else {
                    $pdo->rollback();
                    echo json_encode(['success' => false, 'message' => 'Error al limpiar la lista completamente']);
                }
            } catch (Exception $e) {
                $pdo->rollback();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
        }

        // For update_status, validate status
        $user_id = intval($_POST['user_id']);
        $status = $_POST['status'];

        if ($status !== 'PAGADO') {
            echo json_encode(['success' => false, 'message' => 'Estado inválido']);
            exit;
        }

        // Ensure payment_history table exists with proper constraints
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status ENUM('PAGADO', 'PENDIENTE', 'CANCELADO', 'PROCESADO') NOT NULL DEFAULT 'PENDIENTE',
                amount DECIMAL(10,2) DEFAULT 0.00,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                processed_by_user_id INT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_status (status),
                INDEX idx_user_id (user_id),
                INDEX idx_user_status (user_id, status)
            )
        ");

        // Remover constraint UNIQUE si existe para permitir múltiples registros por usuario
        try {
            $pdo->exec("ALTER TABLE payment_history DROP INDEX unique_user");
        } catch (Exception $e) {
            // Índice no existe, continuar
        }

        // Actualizar tabla existente para agregar estado PROCESADO si no existe
        $pdo->exec("ALTER TABLE payment_history MODIFY status ENUM('PAGADO', 'PENDIENTE', 'CANCELADO', 'PROCESADO') NOT NULL DEFAULT 'PENDIENTE'");

        // Begin transaction to ensure both operations succeed
        $pdo->beginTransaction();

        try {
            // Get the exact credits from the pending payment record
            $stmt_get_pending = $pdo->prepare("
                SELECT amount FROM payment_history 
                WHERE user_id = ? AND status = 'PENDIENTE' 
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt_get_pending->execute([$user_id]);
            $pending_record = $stmt_get_pending->fetch();

            // Use the amount from the pending record, or calculate if not found
            if ($pending_record && $pending_record['amount'] > 0) {
                $credits_earned = $pending_record['amount'];
            } else {
                // Fallback calculation if amount is not set
                $stmt_credits = $pdo->prepare("
                    SELECT SUM(total_time) as total_seconds
                    FROM time_sessions 
                    WHERE user_id = ? AND status = 'completed'
                ");
                $stmt_credits->execute([$user_id]);
                $credits_result = $stmt_credits->fetch();
                $total_seconds = $credits_result['total_seconds'] ?? 0;

                // Get user's credits configuration
                $stmt_config = $pdo->prepare("
                    SELECT ur.credits_time_hours, ur.credits_time_minutes, ur.credits_per_interval
                    FROM users u
                    LEFT JOIN user_ranks ur ON u.role = ur.rank_name
                    WHERE u.id = ?
                ");
                $stmt_config->execute([$user_id]);
                $config = $stmt_config->fetch();

                $credits_time_hours = intval($config['credits_time_hours'] ?? 1);
                $credits_time_minutes = intval($config['credits_time_minutes'] ?? 0);
                $credits_per_interval = intval($config['credits_per_interval'] ?? 1);

                // Calculate credits using interval-based system
                $total_minutes = $total_seconds / 60;
                $interval_minutes = ($credits_time_hours * 60) + $credits_time_minutes;
                if ($interval_minutes <= 0) $interval_minutes = 60;

                // Only give credits for completed intervals
                $completed_intervals = floor($total_minutes / $interval_minutes);
                $credits_earned = $completed_intervals * $credits_per_interval;
            }

            // Update the existing PENDIENTE record to PAGADO instead of creating new one
            $stmt = $pdo->prepare("
                UPDATE payment_history 
                SET status = ?, amount = ?, processed_by_user_id = ?, updated_at = NOW() 
                WHERE user_id = ? AND status = 'PENDIENTE'
                ORDER BY created_at DESC LIMIT 1
            ");
            $result = $stmt->execute([$status, round($credits_earned), $_SESSION['user_id'], $user_id]);

            if ($result && $stmt->rowCount() > 0) {
                // Reset credits by deleting all completed time sessions for this user
                // This ensures credits start from 0 for the next work cycle
                $stmt_delete_sessions = $pdo->prepare("
                    DELETE FROM time_sessions 
                    WHERE user_id = ? AND status = 'completed'
                ");
                $sessions_deleted = $stmt_delete_sessions->execute([$user_id]);

                if ($sessions_deleted) {
                    $pdo->commit();
                    echo json_encode([
                        'success' => true, 
                        'message' => "Usuario marcado como pagado y créditos reiniciados correctamente"
                    ]);
                } else {
                    $pdo->rollback();
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Error al reiniciar créditos del usuario'
                    ]);
                }
            } else {
                $pdo->rollback();
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error: no se encontró registro pendiente para actualizar'
                ]);
            }
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode([
                'success' => false, 
                'message' => 'Error en la transacción: ' . $e->getMessage()
            ]);
        }
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// Get users for main table (only PENDIENTES) and all users for history
try {
    // First ensure payment_history table exists
    $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status ENUM('PAGADO', 'PENDIENTE', 'CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
                amount DECIMAL(10,2) DEFAULT 0.00,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            )
        ");

    // Pending users: Get only users who completed max time (in payment_history with PENDIENTE status)
    // Obtener solo el registro PENDIENTE más reciente por usuario con cálculo de créditos
    $stmt_pending = $pdo->query("
        SELECT 
            u.id, 
            u.habbo_username as username, 
            u.habbo_username,
            u.role,
            COALESCE(ur.display_name, u.role) as rank_name,
            ur.rank_image,
            ph.status as payment_status,
            ph.updated_at as payment_updated,
            ur.credits_time_hours,
            ur.credits_time_minutes,
            ur.credits_per_interval
        FROM users u
        JOIN payment_history ph ON u.id = ph.user_id
        LEFT JOIN user_ranks ur ON u.role = ur.rank_name
        WHERE ph.status = 'PENDIENTE' 
        AND ph.id = (
            SELECT MAX(ph2.id) 
            FROM payment_history ph2 
            WHERE ph2.user_id = u.id 
            AND ph2.status = 'PENDIENTE'
        )
        ORDER BY u.habbo_username ASC
    ");
    $users = $stmt_pending->fetchAll();

    // Calcular créditos para cada usuario PENDIENTE y actualizar la tabla payment_history
    foreach ($users as &$user) {
        // Obtener el total de tiempo de todas las sesiones completadas del usuario
        $stmt_time = $pdo->prepare("
            SELECT SUM(total_time) as total_seconds
            FROM time_sessions 
            WHERE user_id = ? AND status = 'completed'
        ");
        $stmt_time->execute([$user['id']]);
        $time_result = $stmt_time->fetch();
        $total_seconds = $time_result['total_seconds'] ?? 0;

        // Configuración de créditos del usuario
        $credits_time_hours = intval($user['credits_time_hours'] ?? 1);
        $credits_time_minutes = intval($user['credits_time_minutes'] ?? 0);
        $credits_per_interval = intval($user['credits_per_interval'] ?? 1);

        // Calcular créditos usando sistema basado en intervalos
        $total_minutes = $total_seconds / 60;
        $interval_minutes = ($credits_time_hours * 60) + $credits_time_minutes;
        if ($interval_minutes <= 0) $interval_minutes = 60;

        // Solo dar créditos por intervalos completados
        $completed_intervals = floor($total_minutes / $interval_minutes);
        $credits_earned = round($completed_intervals * $credits_per_interval);
        $user['credits_earned'] = $credits_earned;

        // Actualizar el amount en payment_history para preservar el valor exacto
        $stmt_update_amount = $pdo->prepare("
            UPDATE payment_history 
            SET amount = ? 
            WHERE user_id = ? AND status = 'PENDIENTE'
        ");
        $stmt_update_amount->execute([$credits_earned, $user['id']]);
    }



    // History users: Get payment history and try to match with existing users
    // Use LEFT JOIN to handle cases where user_id in payment_history doesn't exist in users table
    // Mostrar tanto PAGADO como PROCESADO para preservar historial completo
    $stmt_all = $pdo->query("
        SELECT 
            COALESCE(u.id, ph.user_id) as id,
            COALESCE(u.habbo_username, CONCAT('Usuario_', ph.user_id)) as username,
            COALESCE(u.habbo_username, '') as habbo_username,
            COALESCE(u.role, 'usuario') as role,
            COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
            COALESCE(ur.rank_image, '') as rank_image,
            ph.status as payment_status,
            ph.amount,
            ph.updated_at as payment_updated,
            COALESCE(processor.habbo_username, 'Sistema') as processed_by_username
        FROM payment_history ph
        LEFT JOIN users u ON ph.user_id = u.id
        LEFT JOIN users processor ON ph.processed_by_user_id = processor.id
        LEFT JOIN user_ranks ur ON u.role = ur.rank_name
        WHERE ph.status IN ('PAGADO', 'PROCESADO')
        ORDER BY ph.updated_at DESC
    ");
    $all_users = $stmt_all->fetchAll();

    // Clean up orphaned payment_history records (no matching user)
    $cleanup = $pdo->exec("
        DELETE FROM payment_history 
        WHERE user_id NOT IN (SELECT id FROM users) 
        AND status IN ('PAGADO', 'PROCESADO')
    ");

    if ($cleanup > 0) {
        // Re-run the query after cleanup
        $stmt_all = $pdo->query("
            SELECT 
                COALESCE(u.id, ph.user_id) as id,
                COALESCE(u.habbo_username, CONCAT('Usuario_', ph.user_id)) as username,
                COALESCE(u.habbo_username, '') as habbo_username,
                COALESCE(u.role, 'usuario') as role,
                COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
                COALESCE(ur.rank_image, '') as rank_image,
                ph.status as payment_status,
                ph.amount,
                ph.updated_at as payment_updated,
                COALESCE(processor.habbo_username, 'Sistema') as processed_by_username
            FROM payment_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            LEFT JOIN users processor ON ph.processed_by_user_id = processor.id
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name
            WHERE ph.status IN ('PAGADO', 'PROCESADO')
            ORDER BY ph.updated_at DESC
        ");
        $all_users = $stmt_all->fetchAll();
    }



} catch (Exception $e) {
    // Fallback if tables don't exist
    $stmt = $pdo->query("
        SELECT 
            u.id, 
            u.habbo_username as username, 
            u.habbo_username,
            u.role,
            u.role as rank_name,
            '' as rank_image,
            'PENDIENTE' as payment_status,
            NOW() as payment_updated
        FROM users u
        ORDER BY u.habbo_username ASC
    ");
    $users = $stmt->fetchAll();
    $all_users = [];
}

function getSiteTitle() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'site_title'");
        $stmt->execute();
        return $stmt->fetchColumn() ?: 'Habbo Agency';
    } catch (Exception $e) {
        return 'Habbo Agency';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Pagas - <?php echo getSiteTitle(); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/time-manager.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Select2 Glass-morphism styling - Match time-manager 100% */
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

        /* Dropdown styling with glass effect - EXACT copy from time-manager */
        .select2-dropdown {
            background: rgba(255, 255, 255, 0.15) !important;
            backdrop-filter: blur(15px) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3) !important;
        }

        /* Results container glass background */
        .select2-results {
            background: transparent !important;
        }

        .select2-results__options {
            background: transparent !important;
        }

        /* Search field in dropdown with magnifying glass - EXACT copy */
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

        /* Add magnifying glass icon to search field - EXACT copy */
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

        /* User options styling - better visibility - EXACT copy */
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

        /* Focus state - EXACT copy */
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: rgba(156, 39, 176, 0.6);
            box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
        }

        /* Custom Glass-morphism Scrollbar - EXACT copy */
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

        /* History table container custom scrollbar - match time-manager exactly */
        .history-table-container::-webkit-scrollbar {
            width: 8px;
        }

        .history-table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            backdrop-filter: blur(5px);
        }

        .history-table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.6), 
                rgba(156, 39, 176, 0.8));
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .history-table-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.8), 
                rgba(156, 39, 176, 1));
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
        }

        .history-table-container::-webkit-scrollbar-thumb:active {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 1), 
                rgba(120, 30, 140, 1));
        }

        /* History section styling */
        .history-section {
            margin-top: 40px;
        }

        .history-header {
            color: white;
            font-size: 1.5em;
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .history-tabs {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .tab-button {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .tab-button.active {
            background: rgba(156, 39, 176, 0.3);
            border-color: rgba(156, 39, 176, 0.6);
            color: #9c27b0;
        }

        .tab-button:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
        }

        .history-table-container {
            max-height: 400px;
            overflow-y: auto;
        }

        .history-table {
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
            border-collapse: collapse;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .history-table thead {
            background: rgba(255, 255, 255, 0.2);
        }

        .history-table th,
        .history-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }

        .history-table th {
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
        }

        .history-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        /* Scrollbar styling */
        .history-table-container::-webkit-scrollbar {
            width: 12px;
        }

        .history-table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .history-table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, rgba(156, 39, 176, 0.6), rgba(103, 58, 183, 0.6));
            border-radius: 10px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .history-table-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, rgba(156, 39, 176, 0.8), rgba(103, 58, 183, 0.8));
        }

        .history-table-container::-webkit-scrollbar-thumb:active{
            background: linear-gradient(45deg, rgba(156, 39, 176, 1), rgba(120, 30, 140, 1));
        }

        .user-search {
            width: 100%;
            height: 45px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 1em;
            padding: 0 15px;
        }

        .payments-table {
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
            border-collapse: collapse;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .table-container {
            max-height: 600px;
            overflow-y: auto;
            border-radius: 15px;
        }

        /* Custom scrollbar for main table */
        .table-container::-webkit-scrollbar {
            width: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            backdrop-filter: blur(5px);
        }

        .table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.6), 
                rgba(156, 39, 176, 0.8));
            border-radius: 4px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 0.8), 
                rgba(156, 39, 176, 1));
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
        }

        .table-container::-webkit-scrollbar-thumb:active {
            background: linear-gradient(45deg, 
                rgba(156, 39, 176, 1), 
                rgba(120, 30, 140, 1));
        }

        .payments-table thead {
            background: rgba(255, 255, 255, 0.2);
        }

        .payments-table th,
        .payments-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }

        .payments-table th {
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
        }

        .payments-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);
        }

        .user-avatar-placeholder {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgb(30, 20, 60) 0%, rgb(60, 20, 40) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.5);
            font-size: 1.5em;
        }

        .user-details h4 {
            margin: 0;
            font-size: 1em;
            color: white;
        }

        .user-details p {
            margin: 0;
            font-size: 0.85em;
            color: rgba(255, 255, 255, 0.7);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid;
        }

        .status-pagado {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
            border-color: rgba(76, 175, 80, 0.4);
        }

        .status-pendiente {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border-color: rgba(255, 193, 7, 0.4);
        }

        .status-cancelado {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
            border-color: rgba(244, 67, 54, 0.4);
        }

        .status-procesado {
            background: rgba(156, 39, 176, 0.2);
            color: #9c27b0;
            border-color: rgba(156, 39, 176, 0.4);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-action {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8em;
            font-weight: 500;
            text-transform: uppercase;
            transition: all 0.3s ease;
            min-width: 80px;
        }

        .btn-pagado {
            background: rgba(76, 175, 80, 0.2);
            border-color: rgba(76, 175, 80, 0.4);
            color: #4caf50;
        }

        .btn-pagado:hover {
            background: rgba(76, 175, 80, 0.3);
            transform: translateY(-1px);
        }

        .btn-pendiente {
            background: rgba(255, 193, 7, 0.2);
            border-color: rgba(255, 193, 7, 0.4);
            color: #ffc107;
        }

        .btn-pendiente:hover {
            background: rgba(255, 193, 7, 0.3);
            transform: translateY(-1px);
        }

        .btn-clear-history {
            background: rgba(244, 67, 54, 0.2);
            border: 1px solid rgba(244, 67, 54, 0.4);
            color: #f44336;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-clear-history:hover {
            background: rgba(244, 67, 54, 0.3);
            transform: translateY(-1px);
        }

        /* Highlighting for search results */
        .select2-results mark {
            background: rgba(255, 255, 0, 0.3);
            color: white;
            padding: 1px 2px;
            border-radius: 2px;
        }

        .btn-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Select2 Glass-morphism styling - EXACT COPY from time-manager */
        .select2-container {
            width: 100% !important;
        }

        .select2-container--default .select2-selection--single {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            height: 45px !important;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            min-height: 45px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #FFFFFF !important;
            padding: 12px 16px !important;
            line-height: 21px !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3) !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: rgba(255, 255, 255, 0.7) !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px !important;
            right: 12px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: rgba(255, 255, 255, 0.8) transparent transparent transparent !important;
            border-width: 6px 6px 0 6px !important;
        }

        /* Dropdown styling with glass effect - EXACT COPY */
        .select2-dropdown {
            background: rgba(255, 255, 255, 0.15) !important;
            backdrop-filter: blur(15px) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3) !important;
        }

        /* Results container glass background */
        .select2-results {
            background: transparent !important;
        }

        .select2-results__options {
            background: transparent !important;
        }

        /* Search field in dropdown with magnifying glass - EXACT COPY */
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

        /* Add magnifying glass icon to search field - EXACT COPY */
        .select2-search--dropdown::before {
            content: '\f002';
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

        /* User options styling - better visibility - EXACT COPY */
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

        /* Focus state - EXACT COPY */
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: rgba(156, 39, 176, 0.6);
            box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
        }

        /* Custom Glass-morphism Scrollbar - EXACT COPY */
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

        @media (max-width: 768px) {
            .payments-table {
                font-size: 0.9em;
            }

            .payments-table th,
            .payments-table td {
                padding: 10px 8px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }

            .btn-action {
                font-size: 0.75em;
                padding: 6px 8px;
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
                    <i class="fas fa-money-bill-wave"></i>
                    Lista de Pagas
                </h1>

                <div class="header-actions">
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- User Selection Section -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-search"></i>
                    Buscar Usuario
                </h3>
            </div>

            <div class="form-group">
                <label for="userSelect">Usuario:</label>
                <select id="userSelect" class="glass-select">
                    <option value="">Selecciona un usuario...</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['username']); ?>">
                            <?php echo htmlspecialchars($user['username']); ?> (Habbo)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Main Payments Table -->
        <div class="dashboard-card">
            <div class="card-header">
                <!-- Título eliminado según solicitud del usuario -->
            </div>

            <div class="table-container">
                <table class="sessions-table" id="paymentsTable">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rango</th>
                            <th>Créditos</th>
                            <th>Estado de Pago</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo strtolower($user['username']); ?>">
                                <td>
                                    <div class="user-info">
                                        <img src="https://www.habbo.es/habbo-imaging/avatarimage?img_format=png&user=<?php echo urlencode($user['habbo_username']); ?>&direction=2&head_direction=3&size=l&gesture=std&action=std&headonly=1" 
                                             alt="Avatar de <?php echo htmlspecialchars($user['habbo_username']); ?>" 
                                             class="user-avatar"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="user-avatar-placeholder" style="display: none;">
                                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                        </div>
                                        <div class="user-details">
                                            <h4><?php echo htmlspecialchars($user['username']); ?></h4>
                                            <p>Habbo: <?php echo htmlspecialchars($user['username']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['rank_name'] ?? ucfirst($user['role'])); ?></td>
                                <td>
                                    <span class="credits-display">
                                        <?php echo $user['credits_earned'] ?? 0; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($user['payment_status']); ?>">
                                        <?php echo $user['payment_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-action btn-pagado" onclick="updatePaymentStatus(<?php echo $user['id']; ?>, 'PAGADO')" data-user-id="<?php echo $user['id']; ?>" data-status="PAGADO">
                                            <i class="fas fa-check-circle"></i> Pagado
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- History Section -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-history"></i>
                    Historial de Pagos
                </h3>

                <div class="history-tabs">
                    <button class="btn-clear-history" onclick="clearCompleteHistory()">
                        <i class="fas fa-trash"></i> Limpiar Lista
                    </button>
                </div>
            </div>

            <!-- Pagados Table -->
            <div class="table-container history-table-container" id="history-pagado">
                <table class="sessions-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rango</th>
                            <th>Créditos Pagados</th>
                            <th>Estado</th>
                            <th>Procesado por</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody id="pagado-tbody">
                        <!-- Will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Cancelados Table -->
            <div class="table-container history-table-container" id="history-cancelado" style="display: none;">
                <table class="sessions-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rango</th>
                            <th>Créditos Pagados</th>
                            <th>Estado</th>
                            <th>Procesado por</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody id="cancelado-tbody">
                        <!-- Will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
    <script src="assets/js/notifications.js"></script>
    <script>
        // Store main table users (PENDIENTES) and history users (PAGADOS/CANCELADOS)
        const mainUsers = <?php echo json_encode($users); ?>;
        let historyUsers = <?php echo json_encode($all_users); ?>;



        document.addEventListener('DOMContentLoaded', function() {
            setupUserSearch();
            populateHistoryTables();
        });

        function setupUserSearch() {
            // Initialize Select2 with dynamic search
            $('#userSelect').select2({
                placeholder: 'Selecciona un usuario...',
                allowClear: true,
                width: '100%',
                minimumInputLength: 0,
                templateResult: function(state) {
                    if (!state.id) {
                        return state.text;
                    }
                    // Highlight matching text
                    const searchTerm = $('.select2-search__field').val();
                    if (searchTerm && searchTerm.length > 0) {
                        const regex = new RegExp('(' + searchTerm + ')', 'gi');
                        const highlightedText = state.text.replace(regex, '<mark>$1</mark>');
                        return $('<span>').html(highlightedText);
                    }
                    return state.text;
                },
                matcher: function(params, data) {
                    // If there are no search terms, return all data
                    if ($.trim(params.term) === '') {
                        return data;
                    }

                    // Search in username (case insensitive)
                    if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
                        return data;
                    }

                    // Return null if no match
                    return null;
                }
            });

            // Real-time table filtering while typing in search field
            $(document).on('input', '.select2-search__field', function() {
                const searchTerm = $(this).val().toLowerCase().trim();
                filterTableBySearch(searchTerm);
            });

            // Filter table by selected user
            $('#userSelect').on('change', function() {
                const selectedUserId = $(this).val();
                const tableRows = document.querySelectorAll('#paymentsTable tbody tr');

                if (selectedUserId) {
                    // Filter by username instead of ID
                    tableRows.forEach(row => {
                        const userCell = row.cells[0];
                        const userNameInRow = userCell.querySelector('h4').textContent.toLowerCase();

                        if (userNameInRow.includes(selectedUserId.toLowerCase())) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                } else {
                    // Show all users when cleared or no selection
                    tableRows.forEach(row => row.style.display = '');
                }
            });

            // Clear table filter when dropdown is closed without selection
            $('#userSelect').on('select2:close', function() {
                const selectedValue = $(this).val();
                if (!selectedValue) {
                    // If no selection, show all users
                    const tableRows = document.querySelectorAll('#paymentsTable tbody tr');
                    tableRows.forEach(row => row.style.display = '');
                }
            });
        }

        function filterTableBySearch(searchTerm) {
            const tableRows = document.querySelectorAll('#paymentsTable tbody tr');

            if (searchTerm === '') {
                // Show all rows when search is empty
                tableRows.forEach(row => {
                    row.style.display = '';
                });
            } else {
                // Filter rows based on search term
                tableRows.forEach(row => {
                    const userCell = row.cells[0];
                    const userNameInRow = userCell.querySelector('h4').textContent.toLowerCase();
                    const userEmailInRow = userCell.querySelector('p').textContent.toLowerCase();
                    const userRank = row.cells[1].textContent.toLowerCase();
                    const userCredits = row.cells[2].textContent.toLowerCase();

                    // Check if search term matches username, rank, or credits
                    const matchesUsername = userNameInRow.includes(searchTerm);
                    const matchesRank = userRank.includes(searchTerm);
                    const matchesCredits = userCredits.includes(searchTerm);

                    if (matchesUsername || matchesRank || matchesCredits) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        }

        function populateHistoryTables() {
            const pagadoTbody = document.getElementById('pagado-tbody');

            // Clear existing content
            pagadoTbody.innerHTML = '';

            // Always try to fetch fresh data from server to ensure accuracy
            fetch('lista-pagas.php?action=get_history')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.history && data.history.length > 0) {
                    // Update historyUsers with fresh data
                    historyUsers = data.history;

                    // Clear and repopulate table
                    pagadoTbody.innerHTML = '';

                    data.history.forEach(user => {
                        if (user.payment_status === 'PAGADO') {
                            pagadoTbody.appendChild(createHistoryRow(user, 'PAGADO'));
                        }
                    });
                } else {
                    // Use local PHP data as fallback
                    historyUsers.forEach(user => {
                        if (user.payment_status === 'PAGADO') {
                            pagadoTbody.appendChild(createHistoryRow(user, 'PAGADO'));
                        } else if (user.payment_status === 'CANCELADO') {
                            canceladoTbody.appendChild(createHistoryRow(user, 'CANCELADO'));
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error fetching fresh history, using local data:', error);
                // Use local PHP data as fallback
                historyUsers.forEach(user => {
                    if (user.payment_status === 'PAGADO' || user.payment_status === 'PROCESADO') {
                        pagadoTbody.appendChild(createHistoryRow(user, user.payment_status));
                    } else if (user.payment_status === 'CANCELADO') {
                        canceladoTbody.appendChild(createHistoryRow(user, 'CANCELADO'));
                    }
                });
            });
        }

        function createHistoryRow(user, status) {
            const row = document.createElement('tr');
            const userInitial = user.username.charAt(0).toUpperCase();
            row.innerHTML = `
                <td>
                    <div class="user-info">
                        <img src="https://www.habbo.es/habbo-imaging/avatarimage?img_format=png&user=${encodeURIComponent(user.habbo_username || user.username)}&direction=2&head_direction=3&size=l&gesture=std&action=std&headonly=1" 
                             alt="Avatar de ${user.username}" 
                             class="user-avatar"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="user-avatar-placeholder" style="display: none;">
                            ${userInitial}
                        </div>
                        <div class="user-details">
                            <h4>${user.username}</h4>
                            <p>Habbo: ${user.username}</p>
                        </div>
                    </div>
                </td>
                <td>${user.rank_name || user.role}</td>
                <td>
                            <span class="credits-display">
                                ${Math.round(user.amount) || 0}
                            </span>
                        </td>
                <td>
                    <span class="status-badge status-${status.toLowerCase()}">
                        ${status}
                    </span>
                </td>
                <td>${user.processed_by_username || 'Sistema'}</td>
                <td>${user.payment_updated ? new Date(user.payment_updated).toLocaleDateString('es-ES') + ' ' + new Date(user.payment_updated).toLocaleTimeString('es-ES', {hour: '2-digit', minute: '2-digit'}) : 'Sin registro'}</td>
            `;
            return row;
        }

        function clearCompleteHistory() {
            if (!confirm('¿Estás seguro de que quieres BORRAR COMPLETAMENTE todo el historial y todas las sesiones? Esta acción NO se puede deshacer y todo comenzará de 0.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'clear_history');

            fetch('lista-pagas.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');

                    // Recargar la página completamente para mostrar que todo está limpio
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);

                } else {
                    showNotification('Error al limpiar historial: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al limpiar historial', 'error');
            });
        }

        function updatePaymentStatus(userId, status) {
            // Only allow PAGADO status
            if (status !== 'PAGADO') {
                showNotification('Estado inválido', 'error');
                return;
            }

            // Disable all buttons for this user
            const userButtons = document.querySelectorAll(`[data-user-id="${userId}"]`);
            userButtons.forEach(btn => btn.disabled = true);

            // Create form data
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('user_id', userId);
            formData.append('status', status);

            fetch('lista-pagas.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Find the user row in the main table
                    const row = document.querySelector(`tr[data-user-id="${userId}"]`);

                    if (row && status === 'PAGADO') {                        // Get username for notification
                        const usernameElement = row.querySelector('.user-details h4');
                        const username = usernameElement ? usernameElement.textContent : 'Usuario';

                        // Show success notification
                        if (window.notifications) {
                            window.notifications.success(`${username} marcado como pagado`);
                        }

                        // Add smooth fade out animation
                        row.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-20px)';

                        // Remove row after animation and refresh history
                        setTimeout(() => {
                            row.remove();
                            // Refresh history table to show the newly paid user
                            populateHistoryTables();
                        }, 500);
                    }
                } else {
                    // Show error notification
                    if (window.notifications) {
                        window.notifications.error(data.message || 'Error al actualizar estado');
                    } else {
                        alert('Error: ' + (data.message || 'Error desconocido'));
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (window.notifications) {
                    window.notifications.error('Error de conexión');
                } else {
                    alert('Error de conexión');
                }
            })
            .finally(() => {
                // Re-enable buttons
                userButtons.forEach(btn => btn.disabled = false);
            });
        }
    </script>
</body>
</html>