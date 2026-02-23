<?php
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'filsdb');

//if (!defined('ROL_VENDEDOR')) define('ROL_VENDEDOR', 1);
//if (!defined('ROL_GERENTE')) define('ROL_GERENTE', 2);
//if (!defined('ROL_FINANCIERO')) define('ROL_FINANCIERO', 3);

// Conexión a la base de datos
try {
    $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cargar el sistema de permisos automáticamente (solo una vez)
if (!class_exists('SistemaPermisos') && file_exists(__DIR__ . '/permisos.php')) {
    require_once __DIR__ . '/permisos.php';
}
?>