<?php
include 'includes/config.php';
include 'includes/auth.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    try {
<<<<<<< HEAD
        $stmt = $conn->prepare("UPDATE proyecto SET estado_id = ?, monto_adjudicado = ?, fecha_cierre = ? WHERE id_proyecto = ?");
        $result = $stmt->execute([
            $data['estado_id'],
            $data['monto_adjudicado'],
            $data['fecha_cierre'],
=======
        $stmt = $conn->prepare("UPDATE proyecto SET estado_id = ?, monto_adjudicado = ? WHERE id_proyecto = ?");
        $result = $stmt->execute([
            $data['estado_id'],
            $data['monto_adjudicado'],
>>>>>>> 0850aed84fb37fc17115e59d49ab3c61010c1c17
            $data['id_proyecto']
        ]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
}