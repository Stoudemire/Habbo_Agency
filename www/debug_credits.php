
<?php
session_start();
include 'config/database.php';

// Verificar configuración actual en la base de datos
echo "<h2>Configuración actual en la base de datos:</h2>";
$stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('time_hours', 'time_minutes', 'credits_per_interval', 'credits_calculation_type')");
$stmt->execute();
$configs = $stmt->fetchAll();

foreach ($configs as $config) {
    echo "<strong>{$config['config_key']}:</strong> {$config['config_value']}<br>";
}

// Calcular créditos por minuto
$time_hours = 0;
$time_minutes = 0;
$credits_per_interval = 1;

foreach ($configs as $config) {
    switch ($config['config_key']) {
        case 'time_hours':
            $time_hours = intval($config['config_value']);
            break;
        case 'time_minutes':
            $time_minutes = intval($config['config_value']);
            break;
        case 'credits_per_interval':
            $credits_per_interval = intval($config['config_value']);
            break;
    }
}

$total_minutes = ($time_hours * 60) + $time_minutes;
if ($total_minutes <= 0) $total_minutes = 60;

$credits_per_minute = $credits_per_interval / $total_minutes;

echo "<br><h2>Cálculo de créditos:</h2>";
echo "<strong>Total minutos del intervalo:</strong> {$total_minutes}<br>";
echo "<strong>Créditos por intervalo:</strong> {$credits_per_interval}<br>";
echo "<strong>Créditos por minuto calculados:</strong> {$credits_per_minute}<br>";

echo "<br><h2>Ejemplo práctico:</h2>";
echo "Si un usuario trabaja 60 segundos (1 minuto):<br>";
echo "Créditos que debería recibir: " . round(1 * $credits_per_minute) . "<br>";

echo "<br>Si un usuario trabaja 30 segundos:<br>";
echo "Créditos que debería recibir: " . round(0.5 * $credits_per_minute) . "<br>";
?>
