<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_GET['id']) || !isset($_GET['tipo'])) {
    header('Location: configuracion_comisiones.php');
    exit;
}

$id = $_GET['id'];
$tipo = $_GET['tipo'];
$gestion_id = $_GET['gestion'] ?? null;

try {
    if ($tipo === 'prod') {
        $stmt = $conn->prepare("
            UPDATE comision_margen_producto
            SET activo = 0
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    } elseif ($tipo === 'extra') {
        $stmt = $conn->prepare("
            UPDATE comision_meta_extra
            SET activo = 0
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }
    
    // Redirigir con éxito
    $redirect = 'configuracion_comisiones.php?success=delete';
    if ($gestion_id) {
        $redirect .= '&gestion=' . $gestion_id;
    }
    header('Location: ' . $redirect);
    exit;
    
} catch (Exception $e) {
    // Redirigir con error
    $redirect = 'configuracion_comisiones.php?error=delete';
    if ($gestion_id) {
        $redirect .= '&gestion=' . $gestion_id;
    }
    header('Location: ' . $redirect);
    exit;
}
?>