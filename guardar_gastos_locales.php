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
        $categoria = $gasto['categoria'] ?? '';
        $descripcion = $gasto['descripcion'] ?? '';
        $total_bs = floatval($gasto['total_bs'] ?? 0);
        $facturado = $gasto['facturado'] ?? 'no';
        $credito_fiscal = floatval($gasto['credito_fiscal'] ?? 0);
        $anexo = $gasto['anexos'] ?? '';
        $usuario = intval($gasto['usuario'] ?? $_SESSION['usuario']['id']);
        $fecha_pago = $gasto['fecha_pago'] ?? null;

        // Validaciones
        if (empty($fecha) || empty($tipo_gasto) || empty($categoria)) {
            throw new Exception("Faltan datos obligatorios en uno de los gastos");
        }


        if ($id) {
            $stmt = $conn->prepare("UPDATE gastos_locales SET 
                fecha = ?, 
                tipo_gasto = ?, 
                categoria = ?,
                descripcion = ?, 
                total_bs = ?, 
                facturado = ?, 
                credito_fiscal = ?, 
                anexos = ?, 
                usuario = ?, 
                fecha_pago = ?
                WHERE id = ? AND id_proyecto = ?");
            $stmt->execute([
                $fecha, 
                $tipo_gasto, 
                $categoria,
                $descripcion, 
                $total_bs, 
                $facturado, 
                $credito_fiscal, 
                $anexo, 
                $usuario, 
                $fecha_pago, 
                $id, 
                $id_proyecto
            ]);
        } else {
            $stmt = $conn->prepare("INSERT INTO gastos_locales 
                (id_proyecto, fecha, tipo_gasto, categoria, descripcion, total_bs, 
                 facturado, credito_fiscal, anexos, usuario, fecha_pago) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id_proyecto, 
                $fecha, 
                $tipo_gasto,
                $categoria,
                $descripcion, 
                $total_bs, 
                $facturado, 
                $credito_fiscal, 
                $anexo, 
                $usuario, 
                $fecha_pago
            ]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Gastos guardados correctamente']);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>