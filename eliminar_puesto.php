<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("organizacion", "eliminar");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? intval($data['id']) : 0;

    if (!$id) {
        throw new Exception('ID de puesto no válido');
    }

    // Verificar si hay otros puestos que dependen de este (subordinados)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM puestos WHERE jefe_puesto_id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        throw new Exception('No se puede eliminar este puesto porque tiene ' . $result['count'] . ' subordinado(s). Primero reasigne o elimine los puestos subordinados.');
    }

    // Eliminar el puesto
    $stmt = $conn->prepare("DELETE FROM puestos WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Puesto no encontrado');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Puesto eliminado correctamente'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>