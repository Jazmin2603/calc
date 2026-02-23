<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/calculos.php';

verificarPermiso("finanzas", "editar");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id_proyecto = intval($data['id_proyecto']);
    $gastos = $data['gastos'];
    
    if (!$id_proyecto) {
        throw new Exception('ID de proyecto no válido');
    }
    
    $conn->beginTransaction();
    
    $ultimo_id = null;
    
    foreach ($gastos as $gasto) {
        $id = isset($gasto['id']) && $gasto['id'] ? intval($gasto['id']) : null;
        $fecha = $gasto['fecha'];
        $tipo_gasto = $gasto['tipo_gasto'];
        $categoria_id = intval($gasto['categoria_id']);
        $sub_categoria_id = !empty($gasto['sub_categoria_id']) ? intval($gasto['sub_categoria_id']) : null;
        $descripcion = $gasto['descripcion'] ?? '';
        $total_bs = floatval($gasto['total_bs']);
        $facturado = $gasto['facturado'] ?? 'no';

        if ($facturado === 'si') {
            $credito_fiscal = round($total_bs * 0.13, 2);
        } else {
            $credito_fiscal = 0;
        }
        $anexos = $gasto['anexos'] ?? '';
        $usuario = intval($gasto['usuario']);
        $fecha_pago = !empty($gasto['fecha_pago']) ? $gasto['fecha_pago'] : null;

        $stmtCat = $conn->prepare("SELECT nombre FROM tipo_gasto WHERE id = ?");
        $stmtCat->execute([$categoria_id]);
        $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);

        if (!$catRow) {
            throw new Exception("Categoría no encontrada");
        }
        $categoria_nombre = $catRow['nombre'];

        $sub_categoria_nombre = null;

        if ($sub_categoria_id) {
            $stmtSub = $conn->prepare("SELECT nombre FROM sub_gasto WHERE id = ?");
            $stmtSub->execute([$sub_categoria_id]);
            $subRow = $stmtSub->fetch(PDO::FETCH_ASSOC);

            if (!$subRow) {
                throw new Exception("Subcategoría no encontrada");
            }

            $sub_categoria_nombre = $subRow['nombre'];
        }
        
        if ($id) {
            // Actualizar gasto existente
            $stmt = $conn->prepare("
                UPDATE gastos_locales 
                SET fecha = ?,
                    tipo_gasto = ?,
                    categoria_id = ?,
                    categoria = ?,
                    sub_categoria_id = ?,
                    sub_categoria = ?,
                    descripcion = ?,
                    total_bs = ?,
                    facturado = ?,
                    credito_fiscal = ?,
                    anexos = ?,
                    usuario = ?,
                    fecha_pago = ?
                WHERE id = ? AND id_proyecto = ?
            ");
            
            $stmt->execute([
                $fecha,
                $tipo_gasto,
                $categoria_id,
                $categoria_nombre,
                $sub_categoria_id,
                $sub_categoria_nombre,
                $descripcion,
                $total_bs,
                $facturado,
                $credito_fiscal,
                $anexos,
                $usuario,
                $fecha_pago,
                $id,
                $id_proyecto
            ]);
            
            $ultimo_id = $id;
        } else {
            // Insertar nuevo gasto
            $stmt = $conn->prepare("
                INSERT INTO gastos_locales (
                    id_proyecto, fecha, tipo_gasto, categoria_id, categoria, sub_categoria_id, sub_categoria,
                    descripcion, total_bs, facturado, credito_fiscal, 
                    anexos, usuario, fecha_pago
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $id_proyecto,
                $fecha,
                $tipo_gasto,
                $categoria_id,
                $categoria_nombre,
                $sub_categoria_id,
                $sub_categoria_nombre,
                $descripcion,
                $total_bs,
                $facturado,
                $credito_fiscal,
                $anexos,
                $usuario,
                $fecha_pago
            ]);
            
            $ultimo_id = $conn->lastInsertId();
        }
    }
    
    $conn->commit();
    recalcularCostosProyecto($conn, $id_proyecto);

    
    echo json_encode([
        'success' => true,
        'message' => 'Gastos guardados correctamente',
        'id' => $ultimo_id
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>