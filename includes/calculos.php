<?php
/**
 * Obtiene los costos totales por tipo de gasto (Producto/Servicio)
 * Suma gastos_exterior.total_bs + gastos_locales.neto
 */
function obtenerCostos($conn, $id_proyecto) {
    $sql = "
        SELECT 
            tipo_gasto,
            SUM(total_bs) as total_bs
        FROM (
            SELECT tipo_gasto, total_bs 
            FROM gastos_exterior 
            WHERE id_proyecto = ?

            UNION ALL

            SELECT tipo_gasto, neto as total_bs
            FROM gastos_locales 
            WHERE id_proyecto = ?
        ) t
        GROUP BY tipo_gasto
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_proyecto, $id_proyecto]);

    $result = [
        'Producto' => 0,
        'Servicio' => 0
    ];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['tipo_gasto']] = floatval($row['total_bs']);
    }

    return $result;
}

/**
 * Recalcula y guarda los costos, márgenes y utilidades del proyecto
 * 
 * Fórmulas:
 * - Utilidad = Ingreso - Costo
 * - Margen % = (Utilidad / Ingreso) * 100
 * - Resultado Total = Utilidad Producto + Utilidad Servicio
 */
function recalcularCostosProyecto($conn, $id_proyecto) {

    $costos = obtenerCostos($conn, $id_proyecto);
    $costo_producto = $costos['Producto'];
    $costo_servicio = $costos['Servicio'];

    $stmt = $conn->prepare("
        SELECT venta_productos, venta_servicios 
        FROM datos_cabecera 
        WHERE id_proyecto = ?
    ");
    $stmt->execute([$id_proyecto]);
    $ingresos = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ingresos) {
        // Si no hay datos de cabecera, salir sin error
        return;
    }

    $ing_prod = floatval($ingresos['venta_productos'] ?? 0);
    $ing_serv = floatval($ingresos['venta_servicios'] ?? 0);

    $utilidad_producto = $ing_prod - $costo_producto;
    $utilidad_servicio = $ing_serv - $costo_servicio;

    $margen_producto = $ing_prod > 0 
        ? ($utilidad_producto / $ing_prod) * 100 
        : 0;

    $margen_servicio = $ing_serv > 0 
        ? ($utilidad_servicio / $ing_serv) * 100 
        : 0;

    // Resultado total = suma de utilidades
    $resultado_total = $utilidad_producto + $utilidad_servicio;

    // Guardar en la base de datos
    $stmt = $conn->prepare("
        INSERT INTO costos_proyecto (
            id_proyecto,
            costo_producto,
            costo_servicio,
            margen_producto,
            margen_servicio,
            utilidad_producto,
            utilidad_servicio,
            resultado_total,
            fecha_actualizada
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            costo_producto = VALUES(costo_producto),
            costo_servicio = VALUES(costo_servicio),
            margen_producto = VALUES(margen_producto),
            margen_servicio = VALUES(margen_servicio),
            utilidad_producto = VALUES(utilidad_producto),
            utilidad_servicio = VALUES(utilidad_servicio),
            resultado_total = VALUES(resultado_total),
            fecha_actualizada = NOW()
    ");

    $stmt->execute([
        $id_proyecto,
        $costo_producto,
        $costo_servicio,
        $margen_producto,
        $margen_servicio,
        $utilidad_producto,
        $utilidad_servicio,
        $resultado_total
    ]);
}

/**
 * Obtiene el resumen de costos de un proyecto
 * Útil para mostrar en reportes
 */
function obtenerResumenCostos($conn, $id_proyecto) {
    $stmt = $conn->prepare("
        SELECT * FROM costos_proyecto 
        WHERE id_proyecto = ?
    ");
    $stmt->execute([$id_proyecto]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

?>