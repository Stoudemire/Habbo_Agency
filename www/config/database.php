<?php
// Database configuration for XAMPP/UniformServer (MySQL only)
$host = 'localhost';
$dbname = 'habbo_agency';
$username = 'habbo_agency';
$password = '@m4GHmlzG9I[We9W';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'"
    ]);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>