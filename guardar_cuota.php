<?php
include 'includes/config.php';
include 'includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $usuario_id = intval($_POST['usuario_id']);
    $gestion_id = intval($_POST['gestion_id']);
    $cuota = floatval($_POST['cuota']);
    
    if (!$usuario_id || !$gestion_id) {
        throw new Exception('Datos incompletos');
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
    
    echo json_encode(['success' => true, 'message' => 'Cuota guardada correctamente']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>