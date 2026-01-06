<?php

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
                tipo_cambio = ?, total_bs = ?, anexos = ?, usuario = ?, fecha_pago = ?
                WHERE id = ? AND id_proyecto = ?");
            $stmt->execute([$fecha, $tipo_gasto, $descripcion, $total_usd, $tipo_cambio, 
                          $total_bs, $anexo, $usuario, $fecha_pago, $id, $id_proyecto]);
        } else {
            // Insertar
            $stmt = $conn->prepare("INSERT INTO gastos_exterior 
                (id_proyecto, fecha, tipo_gasto, descripcion, total_usd, tipo_cambio, 
                 total_bs, anexos, usuario, fecha_pago) 
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
