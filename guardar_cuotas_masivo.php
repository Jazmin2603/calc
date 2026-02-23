<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("usuarios", "editar");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $gestion_id = intval($_POST['gestion_id']);
    $cuotas = $_POST['cuotas'] ?? [];
    
    if (!$gestion_id) {
        throw new Exception('Gestión no válida');
    }
    
    $contador = 0;
    
    foreach ($cuotas as $usuario_id => $cuota) {
        $usuario_id = intval($usuario_id);
        $cuota = floatval($cuota);
        
        // Solo guardar si la cuota es mayor a 0
        if ($cuota <= 0) {
            continue;
        }
        
        // Verificar si existe
        $stmt = $conn->prepare("SELECT id FROM cuotas WHERE usuario_id = ? AND gestion_id = ?");
        $stmt->execute([$usuario_id, $gestion_id]);
        
        if ($stmt->fetch()) {
            // Actualizar
            $stmt = $conn->prepare("UPDATE cuotas SET cuota = ? WHERE usuario_id = ? AND gestion_id = ?");
            $stmt->execute([$cuota, $usuario_id, $gestion_id]);
        } else {
            // Insertar
            $stmt = $conn->prepare("INSERT INTO cuotas (usuario_id, gestion_id, cuota) VALUES (?, ?, ?)");
            $stmt->execute([$usuario_id, $gestion_id, $cuota]);
        }
        
        $contador++;
    }
    
    echo json_encode([
        'success' => true, 
        'message' => "$contador cuotas asignadas correctamente",
        'count' => $contador
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>