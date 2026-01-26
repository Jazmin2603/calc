<?php
include 'includes/config.php';
include 'includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $id_proyecto = intval($_POST['id_proyecto'] ?? 0);
    $tipo_gasto = $_POST['tipo_gasto'] ?? ''; 
    $id_gasto = intval($_POST['id_gasto'] ?? 0);

    if (!$id_proyecto) {
        throw new Exception('ID de proyecto no válido');
    }

    // Obtener el número de proyecto financiero
    $stmt = $conn->prepare("SELECT pf.numero_proyectoF 
                           FROM proyecto_financiero pf
                           WHERE pf.id = ?");
    $stmt->execute([$id_proyecto]);
    $proyecto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proyecto) {
        throw new Exception('Proyecto no encontrado');
    }

    // Obtener el año del contador
    $numero_proyecto = $proyecto['numero_proyectoF'];
    $stmt = $conn->prepare("SELECT anio FROM contadores WHERE ? BETWEEN numero_inicio AND numero_fin AND documento = 'presupuestos'");
    $stmt->execute([$numero_proyecto]);
    $anio_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $anio = $anio_data['anio'] ?? date('Y');

    // Crear la ruta de almacenamiento
    $ruta_base = "/mnt/files/adjuntos_finanzas/{$anio}";

    if (!file_exists($ruta_base)) {
        mkdir($ruta_base, 0755, true);
    }

    $carpeta_proyecto = "{$ruta_base}/proyecto_{$id_proyecto}";

    if (!file_exists($carpeta_proyecto)) {
        mkdir($carpeta_proyecto, 0755, true);
    }

    if (!is_dir($carpeta_proyecto) || !is_writable($carpeta_proyecto)) {
        throw new Exception("No se puede escribir en: $carpeta_proyecto");
    }


    // Validar archivo
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo');
    }

    $archivo = $_FILES['archivo'];
    $nombre_original = $archivo['name'];
    $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    
    // Validar extensiones permitidas
    $extensiones_permitidas = ['pdf', 'png', 'jpg', 'jpeg', 'xlsx', 'xls'];
    if (!in_array($extension, $extensiones_permitidas)) {
        throw new Exception('Tipo de archivo no permitido. Solo se permiten: PDF, PNG, JPEG, Excel');
    }

    $tamano_maximo = 10 * 1024 * 1024; // 10MB
    if ($archivo['size'] > $tamano_maximo) {
        throw new Exception('El archivo es demasiado grande. Máximo 10MB');
    }

    $timestamp = time();
    $nombre_seguro = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($nombre_original, PATHINFO_FILENAME));
    $nombre_archivo = "{$tipo_gasto}_{$id_gasto}_{$timestamp}_{$nombre_seguro}.{$extension}";
    $ruta_completa = "{$carpeta_proyecto}/{$nombre_archivo}";

    if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
        throw new Exception("move_uploaded_file falló");
    }


    $url_archivo = "adjuntos_finanzas/{$anio}/proyecto_{$id_proyecto}/{$nombre_archivo}";

    if ($tipo_gasto === 'exterior') {
        $stmt = $conn->prepare("UPDATE gastos_exterior SET anexos = 
            CASE 
                WHEN anexos IS NULL OR anexos = '' THEN ?
                ELSE CONCAT(anexos, ', ', ?)
            END
            WHERE id_proyecto = ?");
        $stmt->execute([$url_archivo, $url_archivo, $id_gasto]);
    } else {
        $stmt = $conn->prepare("UPDATE gastos_locales SET anexos = 
            CASE 
                WHEN anexos IS NULL OR anexos = '' THEN ?
                ELSE CONCAT(anexos, ', ', ?)
            END
            WHERE id_proyecto = ?");
        $stmt->execute([$url_archivo, $url_archivo, $id_gasto]);
    }

    echo json_encode([
        'success' => true,
        'url' => $url_archivo,
        'nombre' => $nombre_original
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>