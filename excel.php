<?php
include 'includes/config.php';
include 'includes/auth.php';

$numero_proyecto = filter_input(INPUT_GET, 'numero_proyecto', FILTER_VALIDATE_INT);
$data = json_decode(file_get_contents('php://input'), true);

if (!$numero_proyecto || !$data) {
    header("HTTP/1.1 400 Bad Request");
    exit;
}

$stmt = $conn->prepare("SELECT id_proyecto FROM proyecto WHERE numero_proyecto = ?");
$stmt->execute([$numero_proyecto]);
$proyecto = $stmt->fetch();



if (!$proyecto) {
    header("HTTP/1.1 404 Not Found");
    exit;
}

$getAnio = "SELECT anio FROM `contadores` WHERE numero_fin < ? AND documento = 'presupuestos'";
$stmt = $conn->prepare($getAnio);
$stmt->execute([$numero_proyecto]);
$anio = $stmt->fetch(PDO::FETCH_ASSOC);

$filePath = "/mnt/files/adjuntos_presupuestos/{$anio}/hoja_{$numero_proyecto}.xlsx";

if (isset($data['url'])) {
    $fileContent = @file_get_contents($data['url']);

    if ($fileContent === false) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(["error" => 1, "message" => "No se pudo descargar el archivo desde OnlyOffice"]);
        exit;
    }

    if (file_put_contents($filePath, $fileContent) === false) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(["error" => 1, "message" => "No se pudo guardar el archivo"]);
        exit;
    }
}

header("HTTP/1.1 200 OK");
echo json_encode(["error" => 0]);
