<?php
/**
 * ARCHIVO: guardar_datos_cabecera.php
 * Faltaba este archivo mencionado en proyecto_financiero.php
 */

include 'includes/config.php';
include 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_proyecto_financiero = intval($_POST['id_proyecto_financiero']);
    $precio_final_venta = floatval($_POST['precio_final_venta']);
    $venta_productos = floatval($_POST['venta_productos']);
    $presupuesto_gasto_usd = floatval($_POST['presupuesto_gasto_usd']);
    $presupuesto_gasto_bs = floatval($_POST['presupuesto_gasto_bs']);
    $credito_fiscal_favor = floatval($_POST['credito_fiscal_favor']);

    try {
        // Verificar si existe el registro
        $stmt = $conn->prepare("SELECT COUNT(*) FROM datos_cabecera WHERE id_proyecto = ?");
        $stmt->execute([$id_proyecto_financiero]);
        $existe = $stmt->fetchColumn();

        if ($existe) {
            // Actualizar
            $stmt = $conn->prepare("UPDATE datos_cabecera SET 
                precio_final_venta = ?, 
                venta_productos = ?, 
                presupuesto_gasto_usd = ?, 
                presupuesto_gasto_bs = ?, 
                credito_fiscal_favor = ?
                WHERE id_proyecto = ?");
            $stmt->execute([$precio_final_venta, $venta_productos, $presupuesto_gasto_usd, 
                          $presupuesto_gasto_bs, $credito_fiscal_favor, $id_proyecto_financiero]);
        } else {
            // Insertar
            $stmt = $conn->prepare("INSERT INTO datos_cabecera 
                (id_proyecto, precio_final_venta, venta_productos, presupuesto_gasto_usd, 
                 presupuesto_gasto_bs, credito_fiscal_favor) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id_proyecto_financiero, $precio_final_venta, $venta_productos, 
                          $presupuesto_gasto_usd, $presupuesto_gasto_bs, $credito_fiscal_favor]);
        }

        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&success=Datos guardados correctamente");
        exit();
    } catch (PDOException $e) {
        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&error=Error al guardar: " . $e->getMessage());
        exit();
    }
}
?>

<?php
/**
 * ARCHIVO: guardar_gastos_exterior.php
 * Faltaba este archivo para manejar los gastos en el exterior
 */

include 'includes/config.php';
include 'includes/auth.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id_proyecto']) || !isset($data['gastos'])) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
    exit;
}

$id_proyecto = intval($data['id_proyecto']);
$gastos = $data['gastos'];

try {
    $conn->beginTransaction();

    foreach ($gastos as $gasto) {
        $id = isset($gasto['id']) ? intval($gasto['id']) : null;
        $fecha = $gasto['fecha'] ?? null;
        $tipo_gasto = $gasto['tipo_gasto'] ?? '';
        $descripcion = $gasto['descripcion'] ?? '';
        $total_usd = floatval($gasto['total_usd'] ?? 0);
        $tipo_cambio = floatval($gasto['tipo_cambio'] ?? 0);
        $total_bs = floatval($gasto['total_bs'] ?? 0);
        $anexo = $gasto['anexo'] ?? '';
        $usuario = intval($gasto['usuario'] ?? 0);
        $fecha_pago = $gasto['fecha_pago'] ?? null;

        if ($id) {
            // Actualizar
            $stmt = $conn->prepare("UPDATE gastos_exterior SET 
                fecha = ?, tipo_gasto = ?, descripcion = ?, total_usd = ?, 
                tipo_cambio = ?, total_bs = ?, anexo = ?, usuario = ?, fecha_pago = ?
                WHERE id = ? AND id_proyecto = ?");
            $stmt->execute([$fecha, $tipo_gasto, $descripcion, $total_usd, $tipo_cambio, 
                          $total_bs, $anexo, $usuario, $fecha_pago, $id, $id_proyecto]);
        } else {
            // Insertar
            $stmt = $conn->prepare("INSERT INTO gastos_exterior 
                (id_proyecto, fecha, tipo_gasto, descripcion, total_usd, tipo_cambio, 
                 total_bs, anexo, usuario, fecha_pago) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id_proyecto, $fecha, $tipo_gasto, $descripcion, $total_usd, 
                          $tipo_cambio, $total_bs, $anexo, $usuario, $fecha_pago]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>

<?php
/**
 * ARCHIVO: guardar_gastos_locales.php
 * Faltaba este archivo para manejar los gastos locales
 */

include 'includes/config.php';
include 'includes/auth.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id_proyecto']) || !isset($data['gastos'])) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
    exit;
}

$id_proyecto = intval($data['id_proyecto']);
$gastos = $data['gastos'];

try {
    $conn->beginTransaction();

    foreach ($gastos as $gasto) {
        $id = isset($gasto['id']) ? intval($gasto['id']) : null;
        $fecha = $gasto['fecha'] ?? null;
        $tipo_gasto = $gasto['tipo_gasto'] ?? '';
        $descripcion = $gasto['descripcion'] ?? '';
        $total_bs = floatval($gasto['total_bs'] ?? 0);
        $facturado = $gasto['facturado'] ?? 'no';
        $credito_fiscal = floatval($gasto['credito_fiscal'] ?? 0);
        $neto = floatval($gasto['neto'] ?? 0);
        $anexo = $gasto['anexo'] ?? '';
        $usuario = intval($gasto['usuario'] ?? 0);
        $fecha_pago = $gasto['fecha_pago'] ?? null;

        if ($id) {
            // Actualizar
            $stmt = $conn->prepare("UPDATE gastos_locales SET 
                fecha = ?, tipo_gasto = ?, descripcion = ?, total_bs = ?, 
                facturado = ?, credito_fiscal = ?, neto = ?, anexo = ?, 
                usuario = ?, fecha_pago = ?
                WHERE id = ? AND id_proyecto = ?");
            $stmt->execute([$fecha, $tipo_gasto, $descripcion, $total_bs, $facturado, 
                          $credito_fiscal, $neto, $anexo, $usuario, $fecha_pago, $id, $id_proyecto]);
        } else {
            // Insertar
            $stmt = $conn->prepare("INSERT INTO gastos_locales 
                (id_proyecto, fecha, tipo_gasto, descripcion, total_bs, facturado, 
                 credito_fiscal, neto, anexo, usuario, fecha_pago) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id_proyecto, $fecha, $tipo_gasto, $descripcion, $total_bs, 
                          $facturado, $credito_fiscal, $neto, $anexo, $usuario, $fecha_pago]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>