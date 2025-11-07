<?php
include 'includes/config.php';
include 'includes/auth.php';

$data = json_decode(file_get_contents('php://input'), true);

$id_proyecto = $data['id_proyecto'];
$vista = $data['vista'];

$query = "UPDATE proyecto SET vista_precio = ? WHERE id_proyecto = ?";
$stmt = $conn->prepare($query);
$stmt->execute([$vista, $id_proyecto]);

echo json_encode(['status' => 'ok']);
?>
