<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

include 'config/database.php';

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

$site_title = getSiteTitle();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Pagas - <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-money-bill-wave"></i>
                    Lista de Pagas
                </h1>
                <div class="session-actions">
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="dashboard-card">
            <h3 class="card-title">
                <i class="fas fa-construction"></i>
                En Construcción
            </h3>
            <p class="card-description">
                Esta sección está en desarrollo. Aquí podrás gestionar las pagas y pagos del sistema.
            </p>
        </div>
    </div>
</body>
</html>