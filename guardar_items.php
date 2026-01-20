<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/funciones.php';

header('Content-Type: application/json');

$data = file_get_contents("php://input");

$id_proyecto = filter_input(INPUT_GET, 'id_proyecto', FILTER_VALIDATE_INT);

if (!$id_proyecto) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de proyecto no válido']);
    exit();
}


if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'No se recibieron datos']);
    exit;
}

$items = json_decode($data, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo 'Error JSON: ' . json_last_error_msg();
    exit();
}


$query = "SELECT p.*, u.nombre as nombre_usuario FROM proyecto p 
          JOIN usuarios u ON p.id_usuario = u.id 
          WHERE p.id_proyecto = ?";
$stmt = $conn->prepare($query);
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proyecto) {
    header("Location: proyectos.php?error=Proyecto no encontrado");
    exit();
}

if ($_SESSION['usuario']['rol_id'] != 2 && $_SESSION['usuario']['id'] != $proyecto['id_usuario']) {
    header("Location: proyectos.php?error=No autorizado");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM proyecto WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto]);
$datos_variables = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos_variables) {
    throw new Exception("No se encontraron los datos.");
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT id_item FROM items WHERE id_proyecto = ?");
    $stmt->execute([$id_proyecto]);
    $items_actuales = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $items_actuales = $items_actuales ?: [];

    $items_recibidos = [];
    
    
        foreach ($items as $item) {
            $id_item = $item['id_item'] ?? null;

            $tipo_compra = $item['tipo_compra'];
            $precio_unitario = floatval($item['precio_unitario']);
            $cantidad = floatval($item['cantidad']);
            $flete = floatval($item['flete_estimado']);
            $gravamen_arancelario = floatval($item['gravamen']);
            $margen = floatval($item['margen']);
            $otros_gastos = floatval($item['otros_gastos']);
            
            $gasto_aduana = 0;
            $iva_recuperado = 0;
            $giro_itf = 0;
            $gastos_afuera = 0;
            $gastos_locales = 0;
            
            switch($tipo_compra) {
                case "FOB": 
                    $iva_recuperado = 0;
                    $gasto_aduana = ($precio_unitario + $flete) * (($datos_variables['com_aduana']/100) + ($gravamen_arancelario/100));
                    $giro_itf = ($precio_unitario + $flete) * (($datos_variables['giro_exterior']/100) + ($datos_variables['itf']/100));
                    $gastos_afuera = $precio_unitario + $flete + $giro_itf;
                    $gastos_locales = $gasto_aduana + $otros_gastos;
                    break;
                case "Local": 
                    $iva_recuperado = $precio_unitario * ($datos_variables['iva']/100);
                    $giro_itf = 0;
                    $gasto_aduana = 0;
                    $gastos_locales = $precio_unitario - $iva_recuperado + $otros_gastos;
                    break;
                case "DMC":
                    $iva_recuperado = $precio_unitario * ($datos_variables['iva']/100);
                    $gasto_aduana = 0;
                    $giro_itf = (($precio_unitario * ($datos_variables['pago_anticipado_DMC']/100)) + $flete) * (($datos_variables['giro_exterior']/100) + ($datos_variables['itf']/100));
                    $gastos_afuera = ($precio_unitario * ($datos_variables['pago_anticipado_DMC']/100)) + $flete + $giro_itf;
                    $gastos_locales = $otros_gastos + $precio_unitario * (1 - ($datos_variables['pago_anticipado_DMC']/100)) - $iva_recuperado;
                    break;
            }
            
            // anterior: $gastos_afuera + $gastos_locales - ($giro_itf * (1 - ($datos_variables['giro_exterior']/100)));
            $costo_ventaUSD = $gastos_afuera + (($gastos_locales*$datos_variables['tc_oficial']) / $datos_variables['tc_paralelo_hoy']) - $giro_itf;
            $precioUSD = ($costo_ventaUSD / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $costo_paralelo_hoy = $gastos_afuera * $datos_variables['tc_paralelo_hoy'] + $gastos_locales * $datos_variables['tc_oficial'];
            $precio_venta_hoy = ($costo_paralelo_hoy / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $costo_paralelo30 = $gastos_afuera * $datos_variables['tc_estimado30'] + $gastos_locales * $datos_variables['tc_oficial'];
            $precio_venta30 = ($costo_paralelo30 / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $costo_paralelo60 = $gastos_afuera * $datos_variables['tc_estimado60'] + $gastos_locales * $datos_variables['tc_oficial'];
            $precio_venta60 = ($costo_paralelo60 / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            
            // NUEVO
            $costo_usdBo = $gastos_afuera + (($gastos_locales*$datos_variables['tc_oficial']) / $datos_variables['tc_paralelo_hoy']);
            $precio_usdBo =  ($costo_usdBo / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $total_usdBo = $precio_usdBo * $cantidad;

            if ($id_item) {

                $stmt = $conn->prepare("UPDATE items SET 
                    codigo = ?, descripcion = ?, cotizacion = ?, precio_unitario = ?, cantidad = ?, flete_estimado = ?, tipo_compra = ?, 
                    gravamen = ?, gasto_aduana = ?, iva_recuperado = ?, giro_itf = ?, otros_gastos = ?, gastos_afuera = ?, gastos_locales = ?, 
                    margen = ?, costo_venta_usd = ?, precio_usd = ?, costo_paralelo_hoy = ?, precio_venta_hoy = ?, costo_paralelo30 = ?,
                    precio_venta30 = ?, costo_paralelo60 = ?, precio_venta60 = ?, costo_usd_bo = ?, precio_usd_bo = ?, total_usd_bo = ?
                    WHERE id_item = ?");

                $stmt->execute([
                    $item['codigo'], $item['descripcion'], $item['cotizacion'], $precio_unitario, $cantidad, $flete, $tipo_compra, $gravamen_arancelario, 
                    $gasto_aduana, $iva_recuperado, $giro_itf, $otros_gastos, $gastos_afuera, $gastos_locales, $margen, $costo_ventaUSD, $precioUSD, 
                    $costo_paralelo_hoy, $precio_venta_hoy, $costo_paralelo30, $precio_venta30, $costo_paralelo60, $precio_venta60, $costo_usdBo, $precio_usdBo, $total_usdBo,
                    $item['id_item']]);

                $items_recibidos[] = $id_item;
            } else {
                $stmt = $conn->prepare("INSERT INTO items 
                    (id_proyecto, codigo, descripcion, cotizacion, precio_unitario, cantidad, 
                    flete_estimado, tipo_compra, gravamen, gasto_aduana, iva_recuperado, 
                    giro_itf, otros_gastos, gastos_afuera, gastos_locales, margen, costo_venta_usd,
                    precio_usd, costo_paralelo_hoy, precio_venta_hoy, costo_paralelo30,
                    precio_venta30, costo_paralelo60, precio_venta60, costo_usd_bo, precio_usd_bo, total_usd_bo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([
                    $id_proyecto, $item['codigo'], $item['descripcion'], $item['cotizacion'], $precio_unitario, $cantidad, $flete, $tipo_compra, $gravamen_arancelario, 
                    $gasto_aduana, $iva_recuperado, $giro_itf, $otros_gastos, $gastos_afuera, $gastos_locales, $margen, $costo_ventaUSD, $precioUSD, 
                    $costo_paralelo_hoy, $precio_venta_hoy, $costo_paralelo30, $precio_venta30, $costo_paralelo60, $precio_venta60, $costo_usdBo, $precio_usdBo, $total_usdBo
                ]);

                $items_recibidos[] = $conn->lastInsertId();
            }
        }
    
    $conn->commit();
    
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>