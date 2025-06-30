<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_GET['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include '../config/database.php';

// Use user_id from GET parameter or session
$user_id = $_GET['user_id'] ?? $_SESSION['user_id'];

// Format time function
function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

try {
    // Get user's current session status
    $stmt = $pdo->prepare("SELECT * FROM time_sessions WHERE user_id = ? AND status IN ('active', 'paused') ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $current_session = $stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate current session time
    $current_time = 0;
    $paused_time = 0;
    $running_time = 0;
    $status = 'offline';

    if ($current_session) {
        $status = $current_session['status'];
        
        if ($current_session['status'] === 'active') {
            $current_time = time() - strtotime($current_session['start_time']);
            $paused_time = $current_session['paused_duration'] ?? 0;
            $running_time = $current_time - $paused_time;
        } elseif ($current_session['status'] === 'paused') {
            $current_time = strtotime($current_session['pause_time']) - strtotime($current_session['start_time']);
            $paused_time = $current_session['paused_duration'] ?? 0;
            $running_time = $current_time - $paused_time;
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'running_time' => formatTime($running_time),
        'paused_time' => formatTime($paused_time),
        'total_time' => formatTime($current_time),
        'start_time' => $current_session ? $current_session['start_time'] : null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>