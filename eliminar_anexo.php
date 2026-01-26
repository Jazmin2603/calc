<?php
include 'includes/config.php';
include 'includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $archivo   = $data['archivo']   ?? '';
    $id_gasto  = intval($data['id_gasto'] ?? 0);

    if (empty($archivo) || !$id_gasto) {
        throw new Exception('Datos incompletos');
    }

    // Extraer el tipo (exterior o local) de la ruta
    $partes_ruta = explode('/', $archivo);
    if (count($partes_ruta) < 4) {
        throw new Exception('Ruta de archivo inválida');
    }
    
    // La parte que contiene "exterior" o "local" está en la posición 3 (ej: "exterior_1_1769192463...")
    $nombre_archivo = $partes_ruta[3];
    
    // Determinar si es exterior o local
    if (strpos($nombre_archivo, 'exterior') === 0) {
        $tipo = 'exterior';
    } elseif (strpos($nombre_archivo, 'local') === 0) {
        $tipo = 'local';
    } else {
        throw new Exception('Tipo de archivo no reconocido (debe ser exterior o local)');
    }

    // Limpiar ruta
    $archivo = str_replace(['..', '\\'], '', $archivo);
    $ruta_completa = "/mnt/files/{$archivo}";

    if (file_exists($ruta_completa)) {
        if (!unlink($ruta_completa)) {
            throw new Exception('No se pudo eliminar el archivo físico');
        }
    }

    // Obtener lista actual de anexos
    if ($tipo === 'exterior') {
        $stmt = $conn->prepare("SELECT anexos FROM gastos_exterior WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT anexos FROM gastos_locales WHERE id = ?");
    }
    $stmt->execute([$id_gasto]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Gasto no encontrado');
    }

    // Filtrar el archivo eliminado de la lista
    $lista = array_filter(array_map('trim', explode(',', $row['anexos'] ?? '')));
    $lista = array_values(array_filter($lista, fn($a) => $a !== $archivo));
    $nuevo = implode(', ', $lista);

    // Actualizar base de datos
    if ($tipo === 'exterior') {
        $stmt = $conn->prepare("UPDATE gastos_exterior SET anexos = ? WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE gastos_locales SET anexos = ? WHERE id = ?");
    }
    $stmt->execute([$nuevo, $id_gasto]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>