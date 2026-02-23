<?php
include 'includes/config.php';
include 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: configuracion_comisiones.php');
    exit;
}

$gestion_id = $_POST['gestion_id'];
$tipo = $_POST['tipo'];

try {
    if ($tipo === 'prod') {
        if (!empty($_POST['id'])) {
            // Actualizar
            $stmt = $conn->prepare("
                UPDATE comision_margen_producto
                SET margen_desde = ?, margen_hasta = ?, porcentaje = ?
                WHERE id = ? AND gestion_id = ?
            ");
            $stmt->execute([
                $_POST['margen_desde'],
                !empty($_POST['margen_hasta']) ? $_POST['margen_hasta'] : null,
                $_POST['porcentaje'],
                $_POST['id'],
                $gestion_id
            ]);
        } else {
            // Insertar
            $stmt = $conn->prepare("
                INSERT INTO comision_margen_producto
                (gestion_id, margen_desde, margen_hasta, porcentaje, activo)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $gestion_id,
                $_POST['margen_desde'],
                !empty($_POST['margen_hasta']) ? $_POST['margen_hasta'] : null,
                $_POST['porcentaje']
            ]);
        }
    } elseif ($tipo === 'extra') {
        if (!empty($_POST['id'])) {
            // Actualizar
            $stmt = $conn->prepare("
                UPDATE comision_meta_extra
                SET porcentaje_meta_desde = ?, porcentaje_extra = ?
                WHERE id = ? AND gestion_id = ?
            ");
            $stmt->execute([
                $_POST['porcentaje_meta_desde'],
                $_POST['porcentaje_extra'],
                $_POST['id'],
                $gestion_id
            ]);
        } else {
            // Insertar
            $stmt = $conn->prepare("
                INSERT INTO comision_meta_extra
                (gestion_id, porcentaje_meta_desde, porcentaje_extra, activo)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([
                $gestion_id,
                $_POST['porcentaje_meta_desde'],
                $_POST['porcentaje_extra']
            ]);
        }
    }
    
    // Redirigir con éxito
    header('Location: configuracion_comisiones.php?gestion=' . $gestion_id . '&success=1');
    exit;
    
} catch (Exception $e) {
    // Redirigir con error
    header('Location: configuracion_comisiones.php?gestion=' . $gestion_id . '&error=1');
    exit;
}
?>