<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/calculos.php';

verificarPermiso("finanzas", "ver");

$stmt = $conn->query("SELECT id, nombre FROM gestiones ORDER BY nombre DESC");
$gestiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($gestiones)) {
    $gestiones[] = (int)date('Y');
}

// Filtros
$gestion_seleccionada = isset($_GET['gestion']) 
    ? (int)$_GET['gestion'] 
    : ($gestiones[0]['id'] ?? null);

$usuario_seleccionado = isset($_GET['usuario']) ? (int)$_GET['usuario'] : null;

$meta_usuario = 0;

if ($usuario_seleccionado) {
    $stmt = $conn->prepare("
        SELECT cuota 
        FROM cuotas 
        WHERE usuario_id = ?
        AND gestion_id = ?
        LIMIT 1
    ");
    $stmt->execute([$usuario_seleccionado, $gestion_seleccionada]);
    $meta_usuario = (float)($stmt->fetchColumn() ?? 0);
}


// Obtener usuarios con proyectos ganados
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.nombre
    FROM usuarios u
    JOIN proyecto p ON u.id = p.id_usuario
    JOIN estados e ON p.estado_id = e.id
    WHERE e.estado = 'Ganado'
    AND p.gestion_id = ?
    ORDER BY u.nombre
");
$stmt->execute([$gestion_seleccionada]);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construir query base
$query = "
    SELECT 
    p.id_proyecto,
    p.numero_proyecto,
    p.titulo,
    p.cliente,
    p.fecha_proyecto,
    p.monto_adjudicado,
    u.nombre as nombre_usuario,
    s.nombre as sucursal_nombre,
    cp.costo_producto,
    cp.costo_servicio,
    cp.margen_producto,
    cp.margen_servicio,
    cp.utilidad_producto,
    cp.utilidad_servicio,
    cp.resultado_total
FROM proyecto p
JOIN estados e ON p.estado_id = e.id
JOIN usuarios u ON p.id_usuario = u.id
JOIN sucursales s ON p.sucursal_id = s.id
INNER JOIN proyecto_financiero pf ON pf.presupuesto_id = p.id_proyecto
LEFT JOIN costos_proyecto cp ON cp.id_proyecto = pf.id
WHERE e.estado = 'Ganado'
AND p.gestion_id = ?
";

$params = [$gestion_seleccionada];

if ($usuario_seleccionado) {
    $query .= " AND p.id_usuario = ?";
    $params[] = $usuario_seleccionado;
}

$query .= " ORDER BY p.fecha_proyecto ASC, p.numero_proyecto ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totales
$total_monto_adjudicado = 0;
$total_costo_producto = 0;
$total_costo_servicio = 0;
$total_utilidad_producto = 0;
$total_utilidad_servicio = 0;
$total_resultado = 0;

foreach ($proyectos as $proyecto) {
    $total_monto_adjudicado += (float)$proyecto['monto_adjudicado'];
    
    $total_costo_producto += (float)$proyecto['costo_producto'];
    $total_costo_servicio += (float)$proyecto['costo_servicio'];
    $total_utilidad_producto += (float)$proyecto['utilidad_producto'];
    $total_utilidad_servicio += (float)$proyecto['utilidad_servicio'];
    $total_resultado += (float)$proyecto['resultado_total'];
}

$margen_global = $total_monto_adjudicado > 0 
    ? ($total_resultado / $total_monto_adjudicado) * 100 
    : 0;

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumen de Costos - Gestión <?= $gestion_seleccionada ?></title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="resumen.css">
</head>
<body>
    <div class="dashboard-container">
        <header>
            <div style="display: flex; align-items: center; gap: 20px;">
                <img src="assets/logo.png" class="logo">
                <h1>Resumen de Costos - Gestión <?= $gestion_seleccionada ?></h1>
            </div>
            
            <div class="header-buttons">
                <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
            </div>
        </header>

        <!-- Filtros -->
        <div class="filtros-container">
            <form method="get" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <div>
                    <label for="gestion">Gestión:</label>
                    <select name="gestion" id="gestion" onchange="this.form.submit()">
                        <?php foreach ($gestiones as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= $g['id'] == $gestion_seleccionada ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div>
                    <label for="usuario">Usuario:</label>
                    <?php $usuario_seleccionado = isset($_GET['usuario']) 
    ? (int)$_GET['usuario'] 
    : ($usuarios[0]['id'] ?? null); ?>
                    <select name="usuario" id="usuario" onchange="this.form.submit()">
                        

                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id'] == $usuario_seleccionado ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="cuota">Cuota:</label>
                    <?php
                        $cuota_usuario = 0;
                        if ($usuario_seleccionado && $gestion_seleccionada) {
                            $stmt = $conn->prepare("
                                SELECT cuota 
                                FROM cuotas 
                                WHERE usuario_id = ?
                                AND gestion_id = ?
                                LIMIT 1
                            ");
                            $stmt->execute([$usuario_seleccionado, $gestion_seleccionada]);
                            $cuota_usuario = (float)($stmt->fetchColumn() ?? 0);
                        }
                    ?>
                    <input type="text" id="cuota" value="Bs <?= number_format($cuota_usuario, 2, ',', '.') ?>" readonly>
                </div>

            </form>
        </div>

        <!-- Tabla de Proyectos -->
        <?php if (empty($proyectos)): ?>
            <div class="no-data">
                <i class="fas fa-inbox"></i>
                <p>No hay proyectos ganados para mostrar con los filtros seleccionados</p>
            </div>
        <?php else: ?>
            <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.08);">
                <table class="costos-table">
                    <thead>
                        <tr>
                            <th>N° Proyecto</th>
                            <th>Cliente</th>
                            <th>Título</th>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th class="num-col">Monto Adjudicado</th>
                            <th class="num-col">Monto Acumulado</th>
                            <th class="num-col">Costo Producto</th>
                            <th class="num-col">Costo Servicio</th>
                            <th class="num-col">Utilidad Producto</th>
                            <th class="num-col">Utilidad Servicio</th>
                            <th class="num-col">Margen Producto %</th>
                            <th class="num-col">Margen Servicio %</th>
                            <th class="num-col">Com. Extra %</th>
                            <th class="num-col">POR PROD %</th>
                            <th class="num-col">POR EXTRA %</th>
                            <th class="num-col">COMISIÓN PROD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $monto_acumulado = 0;
                        foreach ($proyectos as $proyecto): 
                            $monto_acumulado += (float)$proyecto['monto_adjudicado'];
                            $porcentaje_meta = $meta_usuario > 0 ? ($monto_acumulado / $meta_usuario) * 100 : 0;
                            $por_prod = 0;
                            $margen_prod = $proyecto['margen_producto'];
                            if ($margen_prod >= 5 && $margen_prod < 10) {
                                $por_prod = 5;
                            } elseif ($margen_prod >= 10 && $margen_prod < 15) {
                                $por_prod = 6;
                            } elseif ($margen_prod >= 15 && $margen_prod < 20) {
                                $por_prod = 7;
                            } elseif ($margen_prod >= 20 && $margen_prod < 25) {
                                $por_prod = 8;
                            } elseif ($margen_prod >= 25) {
                                $por_prod = 9;
                            }

                            $por_prod_extra = 0;

                            if ($porcentaje_meta >= 140) {
                                $por_prod_extra = 0.7;
                            } elseif ($porcentaje_meta >= 130) {
                                $por_prod_extra = 0.6;
                            } elseif ($porcentaje_meta >= 120) {
                                $por_prod_extra = 0.5;
                            } elseif ($porcentaje_meta >= 110) {
                                $por_prod_extra = 0.4;
                            } elseif ($porcentaje_meta >= 100) {
                                $por_prod_extra = 0.3;
                            } elseif ($porcentaje_meta >= 90) {
                                $por_prod_extra = 0.2;
                            }

                            $comision_producto = 0;

                            if ($por_prod > 0) {
                                $comision_producto = 
                                    (($por_prod + $por_prod_extra) / 100) 
                                    * (float)$proyecto['utilidad_producto'];
                            }
                        ?>
                            <?php
                            $resultado = (float)$proyecto['resultado_total'];
                            $margen_prod = (float)$proyecto['margen_producto'];
                            $margen_serv = (float)$proyecto['margen_servicio'];
                            ?>
                            <tr>
                                <td>#<?= $proyecto['numero_proyecto'] ?></td>
                                <td><?= htmlspecialchars($proyecto['cliente']) ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($proyecto['titulo']) ?></div>
                                    <div style="font-size: 0.8rem; color: #666;">
                                        <?= htmlspecialchars($proyecto['sucursal_nombre']) ?>
                                    </div>
                                </td>
                                <td><?= date('d/m/Y', strtotime($proyecto['fecha_proyecto'])) ?></td>
                                <td><?= htmlspecialchars($proyecto['nombre_usuario']) ?></td>
                                <td class="num-col">
                                    Bs <?= number_format((float)$proyecto['monto_adjudicado'], 2, ',', '.') ?>
                                </td>
                                <td class="num-col">
                                    Bs <?= number_format($monto_acumulado, 2, ',', '.') ?>
                                </td>
                                <td class="num-col">
                                    Bs <?= number_format((float)$proyecto['costo_producto'], 2, ',', '.') ?>
                                </td>
                                <td class="num-col">
                                    Bs <?= number_format((float)$proyecto['costo_servicio'], 2, ',', '.') ?>
                                </td>
                                <td class="num-col <?= $proyecto['utilidad_producto'] >= 0 ? 'valor-positivo' : 'valor-negativo' ?>">
                                    Bs <?= number_format((float)$proyecto['utilidad_producto'], 2, ',', '.') ?>
                                </td>
                                <td class="num-col <?= $proyecto['utilidad_servicio'] >= 0 ? 'valor-positivo' : 'valor-negativo' ?>">
                                    Bs <?= number_format((float)$proyecto['utilidad_servicio'], 2, ',', '.') ?>
                                </td>
                                <td class="num-col">
                                    <span class="porcentaje-badge <?= $margen_prod >= 0 ? 'porcentaje-positivo' : 'porcentaje-negativo' ?>">
                                        <?= number_format($margen_prod, 1) ?>%
                                    </span>
                                </td>
                                <td class="num-col">
                                    <span class="porcentaje-badge <?= $margen_serv >= 0 ? 'porcentaje-positivo' : 'porcentaje-negativo' ?>">
                                        <?= number_format($margen_serv, 1) ?>%
                                    </span>
                                </td>
                                <td class="num-col">
                                    <span class="porcentaje-badge <?= $porcentaje_meta >= 100 ? 'porcentaje-positivo' : 'porcentaje-negativo' ?>">
                                        <?= number_format($porcentaje_meta, 1) ?>%
                                    </span>
                                </td>
                                <td class="num-col">
                                    <?= number_format($por_prod, 1) ?>%
                                </td>

                                <td class="num-col">
                                    <?= number_format($por_prod_extra, 1) ?>%
                                </td>

                                <td class="num-col <?= $comision_producto >= 0 ? 'valor-positivo' : 'valor-negativo' ?>">
                                    Bs <?= number_format($comision_producto, 2, ',', '.') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f8f9fa; font-weight: bold;">
                            <td colspan="5" style="text-align: right; padding: 20px;">TOTALES:</td>
                            <td class="num-col">Bs <?= number_format($total_monto_adjudicado, 2, ',', '.') ?></td>
                            <td class="num-col"></td>
                            <td class="num-col">Bs <?= number_format($total_costo_producto, 2, ',', '.') ?></td>
                            <td class="num-col">Bs <?= number_format($total_costo_servicio, 2, ',', '.') ?></td>
                            <td class="num-col <?= $total_utilidad_producto >= 0 ? 'valor-positivo' : 'valor-negativo' ?>">
                                Bs <?= number_format($total_utilidad_producto, 2, ',', '.') ?>
                            </td>
                            <td class="num-col <?= $total_utilidad_servicio >= 0 ? 'valor-positivo' : 'valor-negativo' ?>">
                                Bs <?= number_format($total_utilidad_servicio, 2, ',', '.') ?>
                            </td>
                            <td class="num-col">-</td>
                            <td class="num-col">-</td>
                            <td class="num-col">-</td>
                            <td class="num-col">-</td>
                            <td class="num-col">-</td>
                            <td class="num-col">-</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>