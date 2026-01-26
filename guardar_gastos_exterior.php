<?php
include 'includes/config.php';
include 'includes/auth.php';

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
        $total_usd = floatval($gasto['total_usd']);
        $tipo_cambio = floatval($gasto['tipo_cambio']);
        $anexos = $gasto['anexos'] ?? '';
        $usuario = intval($gasto['usuario']);
        $fecha_pago = !empty($gasto['fecha_pago']) ? $gasto['fecha_pago'] : null;
        
        if ($id) {
            // Actualizar gasto existente
            $stmt = $conn->prepare("
                UPDATE gastos_exterior 
                SET fecha = ?,
                    tipo_gasto = ?,
                    categoria_id = ?,
                    sub_categoria_id = ?,
                    descripcion = ?,
                    total_usd = ?,
                    tipo_cambio = ?,
                    anexos = ?,
                    usuario = ?,
                    fecha_pago = ?
                WHERE id = ? AND id_proyecto = ?
            ");
            
            $stmt->execute([
                $fecha,
                $tipo_gasto,
                $categoria_id,
                $sub_categoria_id,
                $descripcion,
                $total_usd,
                $tipo_cambio,
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
                INSERT INTO gastos_exterior (
                    id_proyecto, fecha, tipo_gasto, categoria_id, sub_categoria_id,
                    descripcion, total_usd, tipo_cambio, anexos, usuario, fecha_pago
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $id_proyecto,
                $fecha,
                $tipo_gasto,
                $categoria_id,
                $sub_categoria_id,
                $descripcion,
                $total_usd,
                $tipo_cambio,
                $anexos,
                $usuario,
                $fecha_pago
            ]);
            
            $ultimo_id = $conn->lastInsertId();
        }
    }
    
    $conn->commit();
    
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