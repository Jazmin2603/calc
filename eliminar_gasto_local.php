
<?php
include 'includes/config.php';
include 'includes/auth.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
    exit;
}

$id = intval($data['id']);

try {
    $stmt = $conn->prepare("DELETE FROM gastos_locales WHERE id = ?");
    $success = $stmt->execute([$id]);

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el gasto']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>