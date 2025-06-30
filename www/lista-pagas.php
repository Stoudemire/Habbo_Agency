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

// Check if user has administrator or creator privileges
if (!in_array($user_role, ['administrador', 'super_admin'])) {
    header('Location: dashboard.php?access_denied=1');
    exit;
}

// Handle payment status updates
// Debug main query endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'debug_main') {
    header('Content-Type: application/json');
    
    try {
        // Test the exact main query being used
        $stmt = $pdo->query("
            SELECT 
                u.id, 
                u.username, 
                u.email,
                u.role,
                COALESCE(ur.display_name, u.role) as rank_name,
                ur.rank_image,
                COALESCE(ph.status, 'PENDIENTE') as payment_status,
                ph.status as raw_status
            FROM users u
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name
            LEFT JOIN payment_history ph ON u.id = ph.user_id
            WHERE ph.status IS NULL OR ph.status != 'PAGADO'
            ORDER BY u.username ASC
        ");
        $main_users = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'main_users' => $main_users,
            'count' => count($main_users),
            'debug_time' => date('Y-m-d H:i:s')
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Test query endpoint 
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'test_query') {
    header('Content-Type: application/json');
    
    try {
        // Test the corrected query used for history (handles orphaned records)
        $test_query = "
            SELECT 
                COALESCE(u.id, ph.user_id) as id,
                COALESCE(u.username, CONCAT('Usuario_', ph.user_id)) as username,
                COALESCE(u.email, '') as email,
                COALESCE(u.role, 'usuario') as role,
                COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
                COALESCE(ur.rank_image, '') as rank_image,
                ph.status as payment_status,
                ph.updated_at as payment_updated
            FROM payment_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name
            WHERE ph.status = 'PAGADO'
            ORDER BY ph.updated_at DESC
        ";
        
        $result = $pdo->query($test_query)->fetchAll();
        
        // Also test raw payment_history data
        $raw_history = $pdo->query("SELECT * FROM payment_history WHERE status IN ('PAGADO', 'CANCELADO') ORDER BY updated_at DESC")->fetchAll();
        
        // Test user_ranks table
        $ranks_query = $pdo->query("SELECT * FROM user_ranks")->fetchAll();
        
        // Check if user_id 4 exists in users table
        $user_check = $pdo->query("SELECT * FROM users WHERE id = 4")->fetchAll();
        
        // Check table relationships
        $join_test = $pdo->query("
            SELECT u.id, u.username, u.role, ur.display_name, ur.rank_image 
            FROM users u 
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name 
            WHERE u.id IN (SELECT user_id FROM payment_history WHERE status = 'PAGADO')
        ")->fetchAll();
        
        // Test why the main query fails
        $debug_query = $pdo->query("
            SELECT 
                u.id, 
                u.username, 
                u.email,
                u.role,
                'Testing' as debug_marker
            FROM users u
            WHERE u.id = 4
        ")->fetchAll();
        
        $response = [
            'success' => true,
            'query' => $test_query,
            'result_count' => count($result),
            'results' => $result,
            'raw_payment_history' => $raw_history,
            'user_ranks' => $ranks_query,
            'user_check' => $user_check,
            'join_test' => $join_test,
            'debug_query' => $debug_query,
            'debug_timestamp' => date('Y-m-d H:i:s'),
            'debug' => 'Complete test with user existence check'
        ];
        
        echo json_encode($response);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(), 
            'query' => $test_query ?? 'N/A',
            'debug_timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}

// Debug endpoint to check database state
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'debug_db') {
    header('Content-Type: application/json');
    
    try {
        // Check if table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'payment_history'")->fetchAll();
        
        // Get all records
        $all_records = $pdo->query("SELECT * FROM payment_history ORDER BY updated_at DESC")->fetchAll();
        
        // Get table structure
        $structure = $pdo->query("DESCRIBE payment_history")->fetchAll();
        
        // Also get users table for reference
        $users_stmt = $pdo->query("SELECT id, username, role FROM users ORDER BY username");
        $users = $users_stmt->fetchAll();
        
        echo json_encode([
            'table_exists' => count($tables) > 0,
            'total_records' => count($all_records),
            'records' => $all_records,
            'table_structure' => $structure,
            'users_for_reference' => $users,
            'debug_timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle GET request for history data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    
    try {
        // Get fresh history data from database - handle orphaned records
        $stmt = $pdo->query("
            SELECT 
                COALESCE(u.id, ph.user_id) as id,
                COALESCE(u.username, CONCAT('Usuario_', ph.user_id)) as username,
                COALESCE(u.email, '') as email,
                COALESCE(u.role, 'usuario') as role,
                COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
                COALESCE(ur.rank_image, '') as rank_image,
                ph.status as payment_status,
                ph.updated_at as payment_updated
            FROM payment_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name
            WHERE ph.status = 'PAGADO'
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
            $stmt = $pdo->prepare("DELETE FROM payment_history");
            $success = $stmt->execute();
            
            echo json_encode(['success' => $success, 'message' => 'Historial limpiado completamente']);
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
                status ENUM('PAGADO', 'PENDIENTE', 'CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
                amount DECIMAL(10,2) DEFAULT 0.00,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_status (status)
            )
        ");
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert operation
        $stmt = $pdo->prepare("
            INSERT INTO payment_history (user_id, status, created_at, updated_at) 
            VALUES (?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status),
                updated_at = NOW()
        ");
        
        $result = $stmt->execute([$user_id, $status]);
        
        if (!$result) {
            $error = $stmt->errorInfo();
            echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $error[2]]);
            exit;
        }
        
        // Verify the record was actually saved
        $verify_stmt = $pdo->prepare("SELECT status, updated_at FROM payment_history WHERE user_id = ?");
        $verify_stmt->execute([$user_id]);
        $saved_record = $verify_stmt->fetch();
        
        if ($saved_record && $saved_record['status'] === $status) {
            echo json_encode([
                'success' => true, 
                'message' => "Estado actualizado a: $status y guardado en base de datos",
                'timestamp' => $saved_record['updated_at']
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Error: no se pudo verificar el guardado',
                'debug' => [
                    'user_id' => $user_id,
                    'expected_status' => $status,
                    'found_record' => $saved_record
                ]
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
            UNIQUE KEY unique_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        )
    ");
    
    // Main table: exclude users who have PAGADO status in payment_history
    $stmt = $pdo->query("
        SELECT 
            u.id, 
            u.username, 
            u.email,
            u.role,
            COALESCE(ur.display_name, u.role) as rank_name,
            ur.rank_image,
            'PENDIENTE' as payment_status,
            NOW() as payment_updated
        FROM users u
        LEFT JOIN user_ranks ur ON u.role = ur.rank_name
        WHERE NOT EXISTS (
            SELECT 1 FROM payment_history ph 
            WHERE ph.user_id = u.id AND ph.status = 'PAGADO'
        )
        ORDER BY u.username ASC
    ");
    $users = $stmt->fetchAll();
    
    // DEBUG: Log main query results
    error_log("MAIN QUERY DEBUG - Found " . count($users) . " users in main list");
    foreach ($users as $user) {
        error_log("Main list user: " . $user['username'] . " (ID: " . $user['id'] . ") - Status: " . $user['payment_status']);
    }
    
    // History users: Get payment history and try to match with existing users
    // Use LEFT JOIN to handle cases where user_id in payment_history doesn't exist in users table
    $stmt_all = $pdo->query("
        SELECT 
            COALESCE(u.id, ph.user_id) as id,
            COALESCE(u.username, CONCAT('Usuario_', ph.user_id)) as username,
            COALESCE(u.email, '') as email,
            COALESCE(u.role, 'usuario') as role,
            COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
            COALESCE(ur.rank_image, '') as rank_image,
            ph.status as payment_status,
            ph.updated_at as payment_updated
        FROM payment_history ph
        LEFT JOIN users u ON ph.user_id = u.id
        LEFT JOIN user_ranks ur ON u.role = ur.rank_name
        WHERE ph.status = 'PAGADO'
        ORDER BY ph.updated_at DESC
    ");
    $all_users = $stmt_all->fetchAll();
    
    // Clean up orphaned payment_history records (no matching user)
    $cleanup = $pdo->exec("
        DELETE FROM payment_history 
        WHERE user_id NOT IN (SELECT id FROM users) 
        AND status = 'PAGADO'
    ");
    
    if ($cleanup > 0) {
        // Re-run the query after cleanup
        $stmt_all = $pdo->query("
            SELECT 
                COALESCE(u.id, ph.user_id) as id,
                COALESCE(u.username, CONCAT('Usuario_', ph.user_id)) as username,
                COALESCE(u.email, '') as email,
                COALESCE(u.role, 'usuario') as role,
                COALESCE(ur.display_name, u.role, 'Usuario') as rank_name,
                COALESCE(ur.rank_image, '') as rank_image,
                ph.status as payment_status,
                ph.updated_at as payment_updated
            FROM payment_history ph
            LEFT JOIN users u ON ph.user_id = u.id
            LEFT JOIN user_ranks ur ON u.role = ur.rank_name
            WHERE ph.status = 'PAGADO'
            ORDER BY ph.updated_at DESC
        ");
        $all_users = $stmt_all->fetchAll();
    }
    
    // Debug: Log what we're getting
    error_log("History query returned " . count($all_users) . " users");
    error_log("History users: " . json_encode($all_users));
    
} catch (Exception $e) {
    // Fallback if tables don't exist
    $stmt = $pdo->query("
        SELECT 
            u.id, 
            u.username, 
            u.email,
            u.role,
            u.role as rank_name,
            '' as rank_image,
            'PENDIENTE' as payment_status,
            NOW() as payment_updated
        FROM users u
        ORDER BY u.username ASC
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
        
        /* Ensure both form groups have same width */
        .form-row .form-group {
            flex: 1;
            min-width: 0;
        }
        
        .form-row .form-group:first-child {
            margin-right: 15px;
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
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .user-avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.5);
            font-size: 1.2em;
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
            
            <div class="form-row">
                <div class="form-group">
                    <label for="userSelect">Usuario:</label>
                    <select id="userSelect" class="glass-select">
                        <option value="">Selecciona un usuario...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['username']); ?>">
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="button" id="showAllBtn" class="glass-button" style="margin-top: 25px;">
                        <i class="fas fa-eye"></i>
                        Mostrar Todos
                    </button>
                </div>
                
                <div class="form-group">
                    <button type="button" id="debugBtn" class="glass-button" style="margin-top: 25px; background: rgba(255, 0, 0, 0.2);">
                        <i class="fas fa-bug"></i>
                        Debug DB
                    </button>
                </div>
                
                <div class="form-group">
                    <button type="button" id="testQueryBtn" class="glass-button" style="margin-top: 25px; background: rgba(0, 255, 0, 0.2);">
                        <i class="fas fa-search"></i>
                        Test Query
                    </button>
                </div>
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
                            <th>Estado de Pago</th>
                            <th>Última Actualización</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo strtolower($user['username']); ?>">
                                <td>
                                    <div class="user-info">
                                        <?php if (!empty($user['rank_image']) && file_exists($user['rank_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($user['rank_image']); ?>" alt="Rango" class="user-avatar">
                                        <?php else: ?>
                                            <div class="user-avatar-placeholder">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="user-details">
                                            <h4><?php echo htmlspecialchars($user['username']); ?></h4>
                                            <p><?php echo htmlspecialchars($user['email']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['rank_name'] ?? ucfirst($user['role'])); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($user['payment_status']); ?>">
                                        <?php echo $user['payment_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if ($user['payment_updated']) {
                                        echo date('d/m/Y H:i', strtotime($user['payment_updated']));
                                    } else {
                                        echo '<span style="color: rgba(255,255,255,0.5);">Sin registro</span>';
                                    }
                                    ?>
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
                    <button class="tab-button active">
                        <i class="fas fa-check-circle"></i> Historial de Pagados
                    </button>
                    <button class="btn-clear-history" onclick="clearCompleteHistory()">
                        <i class="fas fa-trash-alt"></i> Limpiar Lista
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
                            <th>Estado</th>
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
                            <th>Estado</th>
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
        
        // Debug: Log initial data
        console.log('Initial historyUsers count:', historyUsers.length);
        console.log('Initial historyUsers data:', historyUsers);
        
        document.addEventListener('DOMContentLoaded', function() {
            setupUserSearch();
            populateHistoryTables();
            setupShowAllButton();
            setupDebugButton();
            setupTestQueryButton();
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
        }

        function setupShowAllButton() {
            const showAllBtn = document.getElementById('showAllBtn');
            const userSelect = document.getElementById('userSelect');
            
            showAllBtn.addEventListener('click', function() {
                // Clear the dropdown selection
                $('#userSelect').val('').trigger('change');
                
                // Show all rows in the table
                const tableRows = document.querySelectorAll('#paymentsTable tbody tr');
                tableRows.forEach(row => {
                    row.style.display = '';
                });
                
                // Show success notification
                if (typeof showNotification === 'function') {
                    showNotification('Mostrando todos los usuarios', 'success');
                }
            });
        }

        function setupDebugButton() {
            const debugBtn = document.getElementById('debugBtn');
            
            debugBtn.addEventListener('click', function() {
                fetch('lista-pagas.php?action=debug_db')
                .then(response => response.json())
                .then(data => {
                    console.log('Estado de la base de datos:', data);
                    const debugText = `Base de datos Debug:
Tabla existe: ${data.table_exists}
Total registros: ${data.total_records}
Registros: ${JSON.stringify(data.records, null, 2)}`;
                    
                    // Create copyable text area
                    showCopyableResults('Debug DB Results', debugText);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al obtener debug: ' + error.message);
                });
            });
        }

        function setupTestQueryButton() {
            const testQueryBtn = document.getElementById('testQueryBtn');
            
            testQueryBtn.addEventListener('click', function() {
                fetch('lista-pagas.php?action=test_query')
                .then(response => response.json())
                .then(data => {
                    console.log('Resultado query test:', data);
                    
                    if (!data.success) {
                        alert('Error en test query: ' + (data.error || 'Respuesta inválida'));
                        return;
                    }
                    
                    const queryText = `Test Query Resultado:
Success: ${data.success}
Query: ${data.query || 'No disponible'}
Registros encontrados: ${data.result_count || 0}
Datos: ${JSON.stringify(data.results || [], null, 2)}
Raw Payment History: ${JSON.stringify(data.raw_payment_history || [], null, 2)}
User Check: ${JSON.stringify(data.user_check || [], null, 2)}
User Ranks: ${JSON.stringify(data.user_ranks || [], null, 2)}
Join Test: ${JSON.stringify(data.join_test || [], null, 2)}
Debug Query: ${JSON.stringify(data.debug_query || [], null, 2)}
Debug Timestamp: ${data.debug_timestamp || 'No disponible'}`;
                    
                    // Create copyable text area
                    showCopyableResults('Test Query Results', queryText);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error en test query: ' + error.message);
                });
            });
        }

        function showCopyableResults(title, content) {
            // Create modal with copyable textarea
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                background: rgba(0,0,0,0.8); z-index: 10000; 
                display: flex; align-items: center; justify-content: center;
            `;
            
            const container = document.createElement('div');
            container.style.cssText = `
                background: rgba(255,255,255,0.15); backdrop-filter: blur(15px);
                border: 1px solid rgba(255,255,255,0.3); border-radius: 12px;
                padding: 20px; max-width: 80%; max-height: 80%; overflow: auto;
            `;
            
            container.innerHTML = `
                <h3 style="color: white; margin-bottom: 15px;">${title}</h3>
                <textarea id="debugResults" style="
                    width: 100%; height: 400px; background: rgba(0,0,0,0.5); 
                    color: white; border: 1px solid rgba(255,255,255,0.3); 
                    border-radius: 8px; padding: 10px; font-family: monospace;
                    font-size: 12px; resize: vertical;
                " readonly>${content}</textarea>
                <div style="margin-top: 15px; text-align: center;">
                    <button id="copyBtn" style="
                        background: rgba(156,39,176,0.8); color: white; border: none;
                        padding: 10px 20px; border-radius: 6px; margin-right: 10px;
                        cursor: pointer;
                    ">Copiar</button>
                    <button id="closeBtn" style="
                        background: rgba(255,255,255,0.2); color: white; border: none;
                        padding: 10px 20px; border-radius: 6px; cursor: pointer;
                    ">Cerrar</button>
                </div>
            `;
            
            modal.appendChild(container);
            document.body.appendChild(modal);
            
            // Copy functionality
            document.getElementById('copyBtn').onclick = () => {
                document.getElementById('debugResults').select();
                document.execCommand('copy');
                alert('Copiado al portapapeles!');
            };
            
            // Close functionality
            document.getElementById('closeBtn').onclick = () => {
                document.body.removeChild(modal);
            };
            
            modal.onclick = (e) => {
                if (e.target === modal) document.body.removeChild(modal);
            };
        }

        function populateHistoryTables() {
            const pagadoTbody = document.getElementById('pagado-tbody');
            
            // Clear existing content
            pagadoTbody.innerHTML = '';
            
            // Debug: Log what historyUsers contains
            console.log('historyUsers array:', historyUsers);
            console.log('historyUsers length:', historyUsers.length);
            
            // Always try to fetch fresh data from server to ensure accuracy
            console.log('Fetching fresh history data from server...');
            fetch('lista-pagas.php?action=get_history')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.history && data.history.length > 0) {
                    console.log('Fresh history data from server:', data.history.length, 'records');
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
                    console.log('No fresh history data available, using local data');
                    // Use local PHP data as fallback
                    historyUsers.forEach(user => {
                        console.log('Processing local history user:', user);
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
                    if (user.payment_status === 'PAGADO') {
                        pagadoTbody.appendChild(createHistoryRow(user, 'PAGADO'));
                    } else if (user.payment_status === 'CANCELADO') {
                        canceladoTbody.appendChild(createHistoryRow(user, 'CANCELADO'));
                    }
                });
            });
        }

        function createHistoryRow(user, status) {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="user-info">
                        ${user.rank_image && user.rank_image !== '' ? 
                            `<img src="${user.rank_image}" alt="Rango" class="user-avatar">` :
                            `<div class="user-avatar-placeholder"><i class="fas fa-user"></i></div>`
                        }
                        <div class="user-details">
                            <h4>${user.username}</h4>
                            <p>${user.email}</p>
                        </div>
                    </div>
                </td>
                <td>${user.rank_name || user.role}</td>
                <td>
                    <span class="status-badge status-${status.toLowerCase()}">
                        ${status}
                    </span>
                </td>
                <td>${user.payment_updated ? new Date(user.payment_updated).toLocaleDateString('es-ES') + ' ' + new Date(user.payment_updated).toLocaleTimeString('es-ES', {hour: '2-digit', minute: '2-digit'}) : 'Sin registro'}</td>
            `;
            return row;
        }

        function clearCompleteHistory() {
            if (!confirm('¿Estás seguro de que quieres limpiar completamente el historial? Esta acción no se puede deshacer.')) {
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
                    // Clear the history table
                    document.getElementById('pagado-tbody').innerHTML = '';
                    historyUsers = [];
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
                    
                    if (row && status === 'PAGADO') {
                        // Get username for notification
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