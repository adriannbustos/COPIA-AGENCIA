<?php
// Configuración de la base de datos
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'agencia_seguridad');

// Conexión a la base de datos
function getDBConnection() {
try {
$conn = new PDO(
"mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
DB_USER,
DB_PASS,
[
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
PDO::ATTR_EMULATE_PREPARES => false,
PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]
);
return $conn;
} catch(PDOException $e) {
error_log($e->getMessage(), 3, '/ruta/segura/logs/error.log');
die("Error de conexión: Error interno del servidor.");
}
}
// Función para sanitizar datos
if (!function_exists('sanitizeInput')) {
function sanitizeInput($data) {
$data = trim($data);
$data = stripslashes($data);
$data = strip_tags($data);
$data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
return $data;
}
}
// Iniciar sesión
if (session_status() === PHP_SESSION_NONE) {
session_start();
}
if (!function_exists('getVal')) {
function getVal($array, $key, $default = '') {
return isset($array[$key]) ? $array[$key] : $default;
}
}
?>