<?php
include 'includes/config.php';
include 'includes/auth.php';

$archivo = $_GET['archivo'] ?? '';

if (empty($archivo)) {
    header("HTTP/1.0 404 Not Found");
    exit('Archivo no especificado');
}

// Sanitizar la ruta para evitar directory traversal
$archivo = str_replace(['..', '\\'], '', $archivo);

$ruta_completa = "/mnt/files/{$archivo}";

if (!file_exists($ruta_completa) || !is_file($ruta_completa)) {
    header("HTTP/1.0 404 Not Found");
    exit('Archivo no encontrado');
}

// Determinar el tipo MIME
$extension = strtolower(pathinfo($ruta_completa, PATHINFO_EXTENSION));
$mime_types = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel'
];

$content_type = $mime_types[$extension] ?? 'application/octet-stream';

// Enviar headers
header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($ruta_completa));
header('Content-Disposition: inline; filename="' . basename($ruta_completa) . '"');

// Enviar el archivo
readfile($ruta_completa);
?>