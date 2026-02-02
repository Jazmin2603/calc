
<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/calculos.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
    exit;
}

$id = intval($data['id']);

try {
    // 1. Obtener proyecto
    $stmt = $conn->prepare("SELECT id_proyecto FROM gastos_exterior WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Gasto no encontrado']);
        exit;
    }

    $id_proyecto = intval($row['id_proyecto']);

    // 2. Borrar
    $stmt = $conn->prepare("DELETE FROM gastos_exterior WHERE id = ?");
    $stmt->execute([$id]);

    // 3. Recalcular
    recalcularCostosProyecto($conn, $id_proyecto);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

?>