<?php 

function actualizar($id_proyecto) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM items WHERE id_proyecto = ?");
    $stmt->execute([$id_proyecto]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC); 

    $stmt = $conn->prepare("SELECT * FROM proyecto WHERE id_proyecto = ?");
    $stmt->execute([$id_proyecto]);
    $datos_variables = $stmt->fetch(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
            // Realiza los cálculos necesarios
            $tipo_compra = $item['tipo_compra'];
            $precio_unitario = floatval($item['precio_unitario']);
            $cantidad = floatval($item['cantidad']);
            $flete = floatval($item['flete_estimado']);
            $gravamen_arancelario = floatval($item['gravamen']);
            $margen = floatval($item['margen']);
            $otros_gastos = floatval($item['otros_gastos']);
            
            // Inicializar variables calculadas
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
            $costo_ventaUSD = $gastos_afuera + (($gastos_locales * $datos_variables['tc_oficial']) / $datos_variables['tc_paralelo_hoy']) - $giro_itf;
            $precioUSD = ($costo_ventaUSD / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $costo_paralelo_hoy = $gastos_afuera * $datos_variables['tc_paralelo_hoy'] + $gastos_locales * $datos_variables['tc_oficial'];
            $precio_venta_hoy = ($costo_paralelo_hoy / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $costo_paralelo30 = $gastos_afuera * $datos_variables['tc_estimado30'] + $gastos_locales * $datos_variables['tc_oficial'];
            $precio_venta30 = ($costo_paralelo30 / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $costo_paralelo60 = $gastos_afuera * $datos_variables['tc_estimado60'] + $gastos_locales * $datos_variables['tc_oficial'];
            $precio_venta60 = ($costo_paralelo60 / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));

            // NUEVO
            $costo_usdBo = $gastos_afuera + (($gastos_locales *$datos_variables['tc_oficial']) / $datos_variables['tc_paralelo_hoy']);
            $precio_usdBo =  ($costo_usdBo / (1 - ($margen/100))) / (1 - ($datos_variables['iva']/100) - ($datos_variables['it']/100));
            $total_usdBo = $precio_usdBo * $cantidad;

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
        

    }
}

// Obtener usuarios con más ventas (ganados)
function obtenerUsuariosTopVentas($conn, $limite = 5) {
    $stmt = $conn->prepare("
        SELECT u.id, u.nombre, u.username, s.nombre as sucursal, 
               COUNT(DISTINCT p.id_proyecto) as total_proyectos,
               COALESCE(SUM(i.total_hoy), 0) as total_ventas
        FROM usuarios u
        LEFT JOIN proyecto p ON u.id = p.id_usuario
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        LEFT JOIN estados e ON p.estado_id = e.id
        LEFT JOIN sucursales s ON u.sucursal_id = s.id
        WHERE e.estado = 'Ganado'
        GROUP BY u.id, u.nombre, u.username, s.nombre
        ORDER BY total_ventas DESC
        LIMIT :limite
    ");
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener proyectos por mes del año actual
function obtenerProyectosPorMes($conn) {
    $stmt = $conn->prepare("
        SELECT MONTH(p.fecha_proyecto) as mes,
               COUNT(DISTINCT p.id_proyecto) as total_proyectos,
               COALESCE(SUM(i.total_hoy), 0) as monto_total
        FROM proyecto p
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        WHERE YEAR(p.fecha_proyecto) = YEAR(CURDATE())
        GROUP BY MONTH(p.fecha_proyecto)
        ORDER BY mes
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener estadísticas por sucursal
function obtenerEstadisticasSucursales($conn) {
    $stmt = $conn->prepare("
        SELECT s.id, s.nombre as sucursal,
               COUNT(DISTINCT p.id_proyecto) as total_proyectos,
               COUNT(DISTINCT CASE WHEN e.estado = 'Ganado' THEN p.id_proyecto END) as ganados,
               COUNT(DISTINCT CASE WHEN e.estado = 'Abierto' THEN p.id_proyecto END) as abiertos,
               COUNT(DISTINCT CASE WHEN e.estado = 'Perdido' THEN p.id_proyecto END) as perdidos,
               COUNT(DISTINCT CASE WHEN e.estado = 'Cerrado' THEN p.id_proyecto END) as cancelados,
               COALESCE(SUM(CASE WHEN e.estado = 'Ganado' THEN i.total_hoy ELSE 0 END), 0) as monto_total
        FROM sucursales s
        LEFT JOIN proyecto p ON s.id = p.sucursal_id
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        LEFT JOIN estados e ON p.estado_id = e.id
        WHERE s.id != 1
        GROUP BY s.id, s.nombre
        ORDER BY monto_total DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener distribución de estados de proyectos
function obtenerDistribucionEstados($conn) {
    $stmt = $conn->prepare("
        SELECT e.id, e.estado, 
               COUNT(p.id_proyecto) as cantidad,
               COALESCE(SUM(i.total_hoy), 0) as monto_total
        FROM estados e
        LEFT JOIN proyecto p ON e.id = p.estado_id
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        GROUP BY e.id, e.estado
        ORDER BY cantidad DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener estadísticas generales del sistema
function obtenerEstadisticasGenerales($conn) {
    $stmt = $conn->prepare("
        SELECT 
            (SELECT COUNT(*) FROM usuarios) AS total_usuarios,
            (SELECT COUNT(*) FROM proyecto) AS total_proyectos,
            (SELECT COALESCE(SUM(i.total_hoy), 0)
             FROM items i
             JOIN proyecto p ON i.id_proyecto = p.id_proyecto
             JOIN estados e ON e.id = p.estado_id
             WHERE e.estado = 'Ganado') AS monto_total,
            (SELECT COUNT(*) 
             FROM proyecto p
             INNER JOIN estados e ON p.estado_id = e.id
             WHERE e.estado = 'Ganado') AS proyectos_ganados,
            (SELECT COUNT(*) 
             FROM proyecto p
             INNER JOIN estados e ON p.estado_id = e.id
             WHERE e.estado = 'Abierto') AS proyectos_abiertos,
            (SELECT COUNT(*) FROM sucursales) AS total_sucursales
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener estadísticas del usuario actual
function obtenerEstadisticasUsuario($conn, $id_usuario) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(p.id_proyecto) as total_proyectos,
            SUM(CASE WHEN e.estado = 'Ganado' THEN 1 ELSE 0 END) as ganados,
            SUM(CASE WHEN e.estado = 'Abierto' THEN 1 ELSE 0 END) as abiertos,
            SUM(CASE WHEN e.estado = 'Perdido' THEN 1 ELSE 0 END) as perdidos,
            SUM(CASE WHEN e.estado = 'Cancelado' THEN 1 ELSE 0 END) as cancelados,
            COALESCE(SUM(i.total_hoy), 0) as monto_total
        FROM proyecto p
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        LEFT JOIN estados e ON p.estado_id = e.id
        WHERE p.id_usuario = ?
    ");
    $stmt->execute([$id_usuario]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener proyectos recientes
function obtenerProyectosRecientes($conn, $limite = 10) {
    $stmt = $conn->prepare("
        SELECT p.id_proyecto, p.titulo, p.cliente, p.fecha_proyecto,
               u.nombre as usuario, s.nombre as sucursal, e.estado,
               COALESCE(SUM(i.total_hoy), 0) as monto_total
        FROM proyecto p
        LEFT JOIN usuarios u ON p.id_usuario = u.id
        LEFT JOIN sucursales s ON p.sucursal_id = s.id
        LEFT JOIN estados e ON p.estado_id = e.id
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        GROUP BY p.id_proyecto, p.titulo, p.cliente, p.fecha_proyecto, u.nombre, s.nombre, e.estado
        ORDER BY p.fecha_proyecto DESC
        LIMIT :limite
    ");
    $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función alternativa si sigue dando problemas con LIMIT
function obtenerUsuariosTopVentasAlternativo($conn, $limite = 5) {
    $sql = "
        SELECT u.id, u.nombre, u.username, s.nombre as sucursal, 
               COUNT(p.id_proyecto) as total_proyectos,
               COALESCE(SUM(i.total_hoy), 0) as total_ventas
        FROM usuarios u
        LEFT JOIN proyecto p ON u.id = p.id_usuario
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        LEFT JOIN estados e ON p.estado_id = e.id
        LEFT JOIN sucursales s ON u.sucursal_id = s.id
        WHERE e.estado = 'Ganado'
        GROUP BY u.id, u.nombre, u.username, s.nombre
        ORDER BY total_ventas DESC
    ";
    
    if ($limite > 0) {
        $sql .= " LIMIT " . (int)$limite;
    }
    
    $stmt = $conn->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función alternativa para proyectos recientes
function obtenerProyectosRecientesAlternativo($conn, $limite = 10) {
    $sql = "
        SELECT p.id_proyecto, p.titulo, p.cliente, p.fecha_proyecto,
               u.nombre as usuario, s.nombre as sucursal, e.estado,
               COALESCE(SUM(i.total_hoy), 0) as monto_total
        FROM proyecto p
        LEFT JOIN usuarios u ON p.id_usuario = u.id
        LEFT JOIN sucursales s ON u.sucursal_id = s.id
        LEFT JOIN estados e ON p.estado_id = e.id
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        GROUP BY p.id_proyecto, p.titulo, p.cliente, p.fecha_proyecto, u.nombre, s.nombre, e.estado
        ORDER BY p.fecha_proyecto DESC
    ";
    
    if ($limite > 0) {
        $sql .= " LIMIT " . (int)$limite;
    }
    
    $stmt = $conn->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerResumenAnualUsuario($conn, $id_usuario) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT p.id_proyecto) as total_proyectos,
            COUNT(DISTINCT CASE WHEN e.estado = 'Ganado' THEN p.id_proyecto END) as cantidad_ganados,
            COUNT(DISTINCT CASE WHEN e.estado = 'Abierto' THEN p.id_proyecto END) as cantidad_abiertos,
            COALESCE(SUM(CASE WHEN e.estado = 'Ganado' THEN i.total_hoy ELSE 0 END), 0) as monto_ganado,
            COALESCE(SUM(CASE WHEN e.estado = 'Abierto' THEN i.total_hoy ELSE 0 END), 0) as monto_abierto
        FROM proyecto p
        LEFT JOIN estados e ON p.estado_id = e.id
        LEFT JOIN items i ON p.id_proyecto = i.id_proyecto
        WHERE p.id_usuario = ? 
        AND YEAR(p.fecha_proyecto) = YEAR(CURDATE())
    ");
    $stmt->execute([$id_usuario]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

?>
