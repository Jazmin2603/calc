<?php
include 'includes/config.php';
include 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_proyecto = intval($_POST['id_proyecto']);
    $estado_id = intval($_POST['estado_id']);

    $stmt = $conn->prepare("UPDATE proyecto_financiero SET estado_id = ? WHERE id = ?");
    $stmt->execute([$estado_id, $id_proyecto]);

    header("Location: finanzas.php");
    exit();
}
?>
