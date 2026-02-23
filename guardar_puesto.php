<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("organizacion", "editar");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? intval($_POST['id']) : null;
    $gestion_id = intval($_POST['gestion_id']);
    $usuario_id = intval($_POST['usuario_id']);
    $cargo_id = intval($_POST['cargo_id']);
    $jefe_puesto_id = !empty($_POST['jefe_puesto_id']) ? intval($_POST['jefe_puesto_id']) : null;
    $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
    $fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;

    // Validaciones
    if (!$gestion_id || !$usuario_id || !$cargo_id) {
        throw new Exception('Faltan datos requeridos');
    }

    // Verificar que el usuario existe
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Usuario no encontrado');
    }

    // Verificar que el cargo existe
    $stmt = $conn->prepare("SELECT id FROM cargos WHERE id = ?");
    $stmt->execute([$cargo_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Cargo no encontrado');
    }

    // Verificar que la gestión existe
    $stmt = $conn->prepare("SELECT id FROM gestiones WHERE id = ?");
    $stmt->execute([$gestion_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Gestión no encontrada');
    }

    // Evitar auto-referencia en jefe
    if ($id && $jefe_puesto_id && $id == $jefe_puesto_id) {
        throw new Exception('Un puesto no puede ser su propio jefe');
    }

    // Verificar que no haya loops jerárquicos
    if ($jefe_puesto_id) {
        $stmt = $conn->prepare("SELECT jefe_puesto_id FROM puestos WHERE id = ?");
        $stmt->execute([$jefe_puesto_id]);
        $jefe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nivel = 0;
        $actual_jefe_id = $jefe_puesto_id;
        
        while ($actual_jefe_id && $nivel < 10) {
            if ($id && $actual_jefe_id == $id) {
                throw new Exception('No se puede crear un loop jerárquico');
            }
            
            $stmt = $conn->prepare("SELECT jefe_puesto_id FROM puestos WHERE id = ?");
            $stmt->execute([$actual_jefe_id]);
            $temp = $stmt->fetch(PDO::FETCH_ASSOC);
            $actual_jefe_id = $temp ? $temp['jefe_puesto_id'] : null;
            $nivel++;
        }
    }

    $conn->beginTransaction();

    if ($id) {
        // Actualizar puesto existente
        $stmt = $conn->prepare("
            UPDATE puestos 
            SET usuario_id = ?, 
                cargo_id = ?, 
                jefe_puesto_id = ?, 
                fecha_inicio = ?, 
                fecha_fin = ?
            WHERE id = ? AND gestion_id = ?
        ");
        $stmt->execute([
            $usuario_id, 
            $cargo_id, 
            $jefe_puesto_id, 
            $fecha_inicio, 
            $fecha_fin, 
            $id,
            $gestion_id
        ]);
    } else {
        // Verificar si ya existe el puesto (mismo usuario, cargo y gestión)
        $stmt = $conn->prepare("
            SELECT id FROM puestos 
            WHERE gestion_id = ? AND usuario_id = ? AND cargo_id = ?
        ");
        $stmt->execute([$gestion_id, $usuario_id, $cargo_id]);
        
        if ($stmt->fetch()) {
            throw new Exception('Ya existe un puesto con este usuario y cargo en esta gestión');
        }

        // Insertar nuevo puesto
        $stmt = $conn->prepare("
            INSERT INTO puestos (gestion_id, usuario_id, cargo_id, jefe_puesto_id, fecha_inicio, fecha_fin)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $gestion_id, 
            $usuario_id, 
            $cargo_id, 
            $jefe_puesto_id, 
            $fecha_inicio, 
            $fecha_fin
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Puesto guardado correctamente'
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