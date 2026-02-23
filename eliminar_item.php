<?php
include 'includes/config.php';

$data = json_decode(file_get_contents("php://input"), true);

if (isset($data['id_item'])) {
    $stmt = $conn->prepare("DELETE FROM items WHERE id_item = ?");
    $success = $stmt->execute([$data['id_item']]);

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el item']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
}


?>
