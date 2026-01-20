<?php
include 'includes/config.php';
include 'includes/auth.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    try {
        $stmt = $conn->prepare("UPDATE proyecto SET estado_id = ?, monto_adjudicado = ? WHERE id_proyecto = ?");
        $result = $stmt->execute([
            $data['estado_id'],
            $data['monto_adjudicado'],
            $data['id_proyecto']
        ]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
}