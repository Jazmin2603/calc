<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("finanzas", "ver");

$id_proyecto_financiero = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Obtener datos del proyecto financiero
$stmt = $conn->prepare("SELECT pf.*, 
                        COALESCE(pf.titulo, p.titulo) as titulo_mostrar,
                        COALESCE(pf.cliente, p.cliente) as cliente_mostrar,
                        u.nombre as nombre_usuario, 
                        s.nombre as sucursal_nombre,
                        ef.estado as estado_financiero
                        FROM proyecto_financiero pf
                        LEFT JOIN proyecto p ON pf.presupuesto_id = p.id_proyecto
                        JOIN usuarios u ON pf.id_usuario = u.id
                        JOIN sucursales s ON pf.sucursal_id = s.id
                        JOIN estado_finanzas ef ON pf.estado_id = ef.id
                        WHERE pf.id = ?");
$stmt->execute([$id_proyecto_financiero]);
$proyecto_financiero = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proyecto_financiero) {
    header("Location: finanzas.php");
    exit();
}

// Obtener el año para la ruta de archivos
$numero_proyecto = $proyecto_financiero['numero_proyectoF'];
$stmt = $conn->prepare("SELECT anio FROM contadores WHERE ? BETWEEN numero_inicio AND numero_fin AND documento = 'finanzas'");
$stmt->execute([$numero_proyecto]);
$anio_data = $stmt->fetch(PDO::FETCH_ASSOC);
$anio = $anio_data['anio'];

// Obtener datos de cabecera
$stmt = $conn->prepare("SELECT * FROM datos_cabecera WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto_financiero]);
$datos_cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos_cabecera) {
    $stmt = $conn->prepare("INSERT INTO datos_cabecera (id_proyecto, precio_final_venta, venta_productos, venta_servicios, presupuesto_gasto_usd, presupuesto_gasto_bs, credito_fiscal_favor) 
                           VALUES (?, 0, 0, 0, 0, 0, 0)");
    $stmt->execute([$id_proyecto_financiero]);
    $datos_cabecera = [
        'precio_final_venta' => 0,
        'venta_productos' => 0,
        'venta_servicios' => 0,
        'presupuesto_gasto_usd' => 0,
        'presupuesto_gasto_bs' => 0,
        'credito_fiscal_favor' => 0
    ];
}

// Obtener gastos en el exterior
$stmt = $conn->prepare("SELECT ge.*, u.nombre as nombre_usuario 
                       FROM gastos_exterior ge 
                       LEFT JOIN usuarios u ON ge.usuario = u.id 
                       WHERE ge.id_proyecto = ? 
                       ORDER BY ge.fecha DESC");
$stmt->execute([$id_proyecto_financiero]);
$gastos_exterior = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener gastos locales
$stmt = $conn->prepare("SELECT gl.*, u.nombre as nombre_usuario 
                       FROM gastos_locales gl 
                       LEFT JOIN usuarios u ON gl.usuario = u.id 
                       WHERE gl.id_proyecto = ? 
                       ORDER BY gl.fecha DESC");
$stmt->execute([$id_proyecto_financiero]);
$gastos_locales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener lista de usuarios para los select
$stmt = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener tipos de gasto (categorías principales)
$stmt = $conn->query("SELECT id, nombre FROM tipo_gasto ORDER BY nombre");
$tipos_gasto = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener todos los sub gastos agrupados por tipo
$stmt = $conn->query("SELECT sg.id, sg.nombre, sg.id_tipo_gasto 
                     FROM sub_gasto sg 
                     WHERE sg.activo = 1 
                     ORDER BY sg.id_tipo_gasto, sg.nombre");
$sub_gastos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar sub gastos por tipo_gasto
$sub_gastos_por_tipo = [];
foreach ($sub_gastos_raw as $sub) {
    $sub_gastos_por_tipo[$sub['id_tipo_gasto']][] = [
        'id' => $sub['id'],
        'nombre' => $sub['nombre']
    ];
}

// Calcular totales
$total_gastos_exterior_usd = array_sum(array_column($gastos_exterior, 'total_usd'));
$total_gastos_exterior_bs = array_sum(array_column($gastos_exterior, 'total_bs'));
$total_gastos_locales_bs = array_sum(array_column($gastos_locales, 'total_bs'));
$total_credito_fiscal = array_sum(array_column($gastos_locales, 'credito_fiscal'));
$total_neto_locales = array_sum(array_column($gastos_locales, 'neto'));

// Cálculos del resumen financiero
$precio_venta = floatval($datos_cabecera['precio_final_venta']);
$venta_productos = floatval($datos_cabecera['venta_productos']);
$venta_servicios = floatval($datos_cabecera['venta_servicios']);

$total_producto = $total_gastos_exterior_bs;
$costos_importacion = $total_gastos_exterior_bs;
$gastos_locales_total = $total_neto_locales;
$total_costo = $total_producto + $gastos_locales_total;
$total_ingreso = $precio_venta;
$utilidad_neta = $total_ingreso - $total_costo;
$utilidad_porcentaje = $total_ingreso > 0 ? ($utilidad_neta / $total_ingreso) * 100 : 0;

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proyecto Financiero: <?= htmlspecialchars($proyecto_financiero['titulo_mostrar'] ?? 'Sin título') ?></title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="organigrama.css">
    <style>
        :root {
            --primary:#2d8f3d;
            --success: #2ec4b6;
            --danger: #e71d36;
            --bg: #f8f9fa;
            --text-main: #2b2d42;
            --text-light: #8d99ae;
        }
        .resumen-financiero {
            background: var(--primary);
            padding: 30px;
            border-radius: 12px;
            margin: 20px 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .resumen-financiero h2 {
            color: white;
            margin-bottom: 20px;
            font-size: 24px;
            text-align: center;
        }

        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .resumen-item {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            transition: transform 0.3s;
        }

        .resumen-item:hover {
            transform: translateY(-5px);
        }

        .resumen-item h4 {
            margin: 0 0 10px 0;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .resumen-item .valor {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }

        .utilidad-positiva {
            color: #27ae60 !important;
        }

        .utilidad-negativa {
            color: #e74c3c !important;
        }

        .datos-cabecera {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .datos-cabecera h2 {
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .form-grid .form-group {
            margin-bottom: 0;
        }

        .seccion-gastos {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .seccion-gastos h2 {
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }

        .gastos-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .gastos-table thead th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
        }

        .gastos-table tbody tr {
            border-bottom: 1px solid #e0e0e0;
            transition: background 0.2s;
        }

        .gastos-table tbody tr:hover {
            background: #f8f9fa;
        }

        .gastos-table tbody td {
            padding: 12px;
            font-size: 13px;
        }

        .gastos-table tfoot {
            font-weight: bold;
            background: #f8f9fa;
        }

        .gastos-table tfoot td {
            padding: 12px;
            border-top: 2px solid var(--primary);
        }

        .badge-tipo {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-producto {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-servicio {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .anexos-list {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .anexo-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: var(--primary);
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 11px;
            transition: background 0.2s;
        }

        .anexo-link:hover {
            background: var(--primary-dark);
        }

        .btn-accion {
            padding: 5px 10px;
            margin: 2px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .btn-editar {
            background: #3498db;
            color: white;
        }

        .btn-editar:hover {
            background: #2980b9;
        }

        .btn-eliminar {
            background: #e74c3c;
            color: white;
        }

        .btn-eliminar:hover {
            background: #c0392b;
        }

        .btn-anexo {
            background: #27ae60;
            color: white;
        }

        .btn-anexo:hover {
            background: #229954;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .modal-anexos {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .modal-anexos h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #555;
        }

        .anexos-actuales {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .anexo-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .btn-eliminar-anexo {
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 2px 6px;
            cursor: pointer;
            font-size: 11px;
        }

        .file-upload-zone {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .file-upload-zone:hover {
            border-color: var(--primary);
            background: #f8f9fa;
        }

        .file-upload-zone.dragover {
            border-color: var(--primary);
            background: #e3f2fd;
        }
        .textarea {
            border-radius: 10px;
            border-color: var(--primary);
            max-width: 100%;
            min-width: 100%;
            min-height: 30px;
            resize: vertical;
        }
    </style>
</head>
<body>
    <div class="organizacion-container">
        <header class="header-section">
            <diV style="display: flex; align-items: center;">
                <img src="assets/logo.png" class="logo">
                <?php $titulo = $proyecto_financiero['titulo_mostrar'] ?? 'Sin título'; $numero = $proyecto_financiero['numero_proyectoF'] ?? null;?>
                <h1 style="margin-left: 20px;"><?= htmlspecialchars($titulo) ?></h1>
            </diV>
            <a href="finanzas.php" class="btn btn-secondary">Volver</a>
        </header>

        <!-- Resumen Financiero -->
        <div class="resumen-financiero">
            <h2>RESUMEN FINANCIERO</h2>
            <div class="resumen-grid">
                <div class="resumen-item">
                    <h4>Total Producto</h4>
                    <div class="valor"><?= number_format($total_producto, 2) ?> Bs</div>
                </div>
                <div class="resumen-item">
                    <h4>Costos Importación</h4>
                    <div class="valor"><?= number_format($costos_importacion, 2) ?> Bs</div>
                </div>
                <div class="resumen-item">
                    <h4>Gastos Locales</h4>
                    <div class="valor"><?= number_format($gastos_locales_total, 2) ?> Bs</div>
                </div>
                <div class="resumen-item">
                    <h4>Total Costo</h4>
                    <div class="valor"><?= number_format($total_costo, 2) ?> Bs</div>
                </div>
                <div class="resumen-item">
                    <h4>Total Ingreso</h4>
                    <div class="valor" style="color: #3498db;"><?= number_format($total_ingreso, 2) ?> Bs</div>
                </div>
                <div class="resumen-item">
                    <h4>Utilidad NETA</h4>
                    <div class="valor <?= $utilidad_neta >= 0 ? 'utilidad-positiva' : 'utilidad-negativa' ?>">
                        <?= number_format($utilidad_neta, 2) ?> Bs
                    </div>
                </div>
                <div class="resumen-item">
                    <h4>Utilidad (%)</h4>
                    <div class="valor <?= $utilidad_porcentaje >= 0 ? 'utilidad-positiva' : 'utilidad-negativa' ?>">
                        <?= number_format($utilidad_porcentaje, 1) ?>%
                    </div>
                </div>
            </div>
        </div>

        <!-- Datos de Cabecera -->
        <div class="datos-cabecera">
            <form action="guardar_datos_cabecera.php" method="POST">
                <input type="hidden" name="id_proyecto_financiero" value="<?= $id_proyecto_financiero ?>">
                
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2>Datos de Cabecera</h2>
                    <button type="submit" class="btn btn-success">Guardar Cambios</button>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Precio Final de Venta (Bs)</label>
                        <input type="number" step="0.01" name="precio_final_venta" 
                               value="<?= $datos_cabecera['precio_final_venta'] ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Venta de Productos (Bs)</label>
                        <input type="number" step="0.01" name="venta_productos" 
                               value="<?= $datos_cabecera['venta_productos'] ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Venta de Servicios (Bs)</label>
                        <input type="number" step="0.01" name="venta_servicios" 
                               value="<?= $datos_cabecera['venta_servicios'] ?? 0 ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Presupuesto Gasto en Bs</label>
                        <input type="number" step="0.01" name="presupuesto_gasto_bs" 
                               value="<?= $datos_cabecera['presupuesto_gasto_bs'] ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Crédito Fiscal a Favor (Bs)</label>
                        <input type="number" step="0.01" name="credito_fiscal_favor" 
                               value="<?= $datos_cabecera['credito_fiscal_favor'] ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="optional">Impuestos de Productos (Bs)</label>
                        <input value="<?= $datos_cabecera['gasto_impuestos_prod'] ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="optional">Impuestos de Servicios (Bs)</label>
                        <input value="<?= $datos_cabecera['gasto_impuestos_serv'] ?? 0 ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Presupuesto Gasto en USD</label>
                        <input type="number" step="0.01" name="presupuesto_gasto_usd" 
                               value="<?= $datos_cabecera['presupuesto_gasto_usd'] ?>" required>
                    </div>
                    
                </div>
            </form>
        </div>

        <!-- Gastos en el Exterior -->
        <div class="seccion-gastos">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2>Gastos en el Exterior (USD)</h2>
                <button class="btn btn-success" onclick="abrirModalGasto('exterior')">
                    Agregar Gasto
                </button>
            </div>

            <?php if (count($gastos_exterior) > 0): ?>
                <table class="gastos-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Categoría</th>
                            <th>Descripción</th>
                            <th style="text-align: right;">Total USD</th>
                            <th style="text-align: right;">TC</th>
                            <th style="text-align: right;">Total Bs</th>
                            <th>Anexos</th>
                            <th>Usuario</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gastos_exterior as $gasto): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($gasto['fecha'])) ?></td>
                                <td>
                                    <span class="badge-tipo badge-<?= strtolower($gasto['tipo_gasto']) ?>">
                                        <?= $gasto['tipo_gasto'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($gasto['categoria']) ?></td>
                                <td><?= htmlspecialchars($gasto['descripcion']) ?></td>
                                <td style="text-align: right;"><?= number_format($gasto['total_usd'], 2) ?></td>
                                <td style="text-align: right;"><?= number_format($gasto['tipo_cambio'], 2) ?></td>
                                <td style="text-align: right;"><?= number_format($gasto['total_bs'], 2) ?></td>
                                <td>
                                    <?php if ($gasto['anexos']): ?>
                                        <div class="anexos-list">
                                            <?php 
                                            $anexos = explode(',', $gasto['anexos']);
                                            foreach ($anexos as $anexo): 
                                                $anexo = trim($anexo);
                                                if ($anexo):
                                            ?>
                                                <a href="ver_anexo.php?archivo=<?= urlencode($anexo) ?>" 
                                                   class="anexo-link" target="_blank">
                                                    Ver
                                                </a>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($gasto['nombre_usuario']) ?></td>
                                <td style="text-align: center;">
                                    <?php if(tienePermiso("finanzas", "editar")): ?>
                                        <button class="btn-accion btn-editar" 
                                                onclick='editarGasto("exterior", <?= json_encode($gasto) ?>)'>
                                            Editar
                                        </button>
                                        <button class="btn-accion btn-eliminar" 
                                                onclick="eliminarGasto('exterior', <?= $gasto['id'] ?>)">
                                            Eliminar
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #95a5a6; font-size: 0.85rem; padding: 8px;">
                                            <i class="fas fa-lock"></i> Sin permisos
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong>TOTALES:</strong></td>
                            <td style="text-align: right;"><strong><?= number_format($total_gastos_exterior_usd, 2) ?> $</strong></td>
                            <td></td>
                            <td style="text-align: right;"><strong><?= number_format($total_gastos_exterior_bs, 2) ?> Bs</strong></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <p>No hay gastos registrados en el exterior</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Gastos Locales -->
        <div class="seccion-gastos">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2>Gastos Locales (Bs)</h2>
                <button class="btn btn-success" onclick="abrirModalGasto('local')">
                    Agregar Gasto
                </button>
            </div>

            <?php if (count($gastos_locales) > 0): ?>
                <table class="gastos-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Categoría</th>
                            <th>Descripción</th>
                            <th style="text-align: right;">Total Bs</th>
                            <th>Facturado</th>
                            <th style="text-align: right;">Crédito Fiscal</th>
                            <th style="text-align: right;">Neto</th>
                            <th>Anexos</th>
                            <th>Usuario</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gastos_locales as $gasto): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($gasto['fecha'])) ?></td>
                                <td>
                                    <span class="badge-tipo badge-<?= strtolower($gasto['tipo_gasto']) ?>">
                                        <?= $gasto['tipo_gasto'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($gasto['categoria']) ?></td>
                                <td><?= htmlspecialchars($gasto['descripcion']) ?></td>
                                <td style="text-align: right;"><?= number_format($gasto['total_bs'], 2) ?></td>
                                <td><?= strtoupper($gasto['facturado']) ?></td>
                                <td style="text-align: right;"><?= number_format($gasto['credito_fiscal'], 2) ?></td>
                                <td style="text-align: right;"><?= number_format($gasto['neto'], 2) ?></td>
                                <td>
                                    <?php if ($gasto['anexos']): ?>
                                        <div class="anexos-list">
                                            <?php 
                                            $anexos = explode(',', $gasto['anexos']);
                                            foreach ($anexos as $anexo): 
                                                $anexo = trim($anexo);
                                                if ($anexo):
                                            ?>
                                                <a href="ver_anexo.php?archivo=<?= urlencode($anexo) ?>" 
                                                   class="anexo-link" target="_blank">
                                                    Ver
                                                </a>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($gasto['nombre_usuario']) ?></td>
                                <td>
                                    <?php if(tienePermiso("finanzas", "editar")): ?>
                                        <button class="btn-accion btn-editar" 
                                                onclick='editarGasto("local", <?= json_encode($gasto) ?>)'>
                                            Editar
                                        </button>
                                        <button class="btn-accion btn-eliminar" 
                                                onclick="eliminarGasto('local', <?= $gasto['id'] ?>)">
                                            Eliminar
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #95a5a6; font-size: 0.85rem; padding: 8px;">
                                            <i class="fas fa-lock"></i> Sin permisos
                                        </span>
                                    <?php endif; ?>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong>TOTALES:</strong></td>
                            <td style="text-align: right;"><strong><?= number_format($total_gastos_locales_bs, 2) ?> Bs</strong></td>
                            <td></td>
                            <td style="text-align: right;"><strong><?= number_format($total_credito_fiscal, 2) ?> Bs</strong></td>
                            <td style="text-align: right;"><strong><?= number_format($total_neto_locales, 2) ?> Bs</strong></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <p>No hay gastos locales registrados</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para Gastos -->
    <div id="modalGasto" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2 id="modalTituloGasto">Agregar Gasto</h2>
                <button class="close" onclick="cerrarModalGasto()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formGasto" onsubmit="guardarGasto(event)">
                    <input type="hidden" id="gasto_id" name="id">
                    <input type="hidden" id="tipo_modal" name="tipo_modal">
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">

                        <div class="form-group">
                            <label>Tipo de Gasto</label>
                            <select name="tipo_gasto" id="tipo_gasto" required>
                                <option value="">Seleccionar...</option>
                                <option value="Producto">Producto</option>
                                <option value="Servicio">Servicio</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Categoría</label>
                            <select name="categoria_id" id="categoria_gasto" required onchange="cargarSubCategorias()">
                                <option value="">Seleccionar...</option>
                                <?php foreach ($tipos_gasto as $tipo): ?>
                                    <option value="<?= $tipo['id'] ?>"><?= htmlspecialchars($tipo['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="optional">Sub Categoría</label>
                            <select name="sub_categoria_id" id="sub_categoria_gasto">
                                <option value="">Seleccionar...</option>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Descripción</label>
                            <textarea name="descripcion" id="descripcion_gasto" rows="2" class="textarea" maxlength="200"></textarea>
                        </div>

                        <!-- Campos para gastos exterior -->
                        <div id="campos_exterior" style="display: none; grid-column: 1 / -1;">
                            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                                <div class="form-group">
                                    <label>Total USD</label>
                                    <input type="number" step="0.01" name="total_usd" id="total_usd" 
                                           onchange="calcularTotalBs()">
                                </div>
                                <div class="form-group">
                                    <label>Tipo de Cambio</label>
                                    <input type="number" step="0.01" name="tipo_cambio" id="tipo_cambio" 
                                           value="17.0" onchange="calcularTotalBs()">
                                </div>
                                <div class="form-group">
                                    <label>Total Bs (Calculado)</label>
                                    <input type="number" step="0.01" name="total_bs_ext" id="total_bs_ext" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- Campos para gastos locales -->
                        <div id="campos_locales" style="display: none; grid-column: 1 / -1;">
                            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                                <div class="form-group">
                                    <label>Total Bs</label>
                                    <input type="number" step="0.01" name="total_bs" id="total_bs" 
                                        onchange="calcularNeto()">
                                </div>
                                <div class="form-group">
                                    <label>Facturado</label>
                                    <select name="facturado" id="facturado" onchange="calcularNeto()">
                                        <option value="si">SI</option>
                                        <option value="no" selected>NO</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Crédito Fiscal</label>
                                    <input type="number" step="0.01" name="credito_fiscal" id="credito_fiscal" 
                                        readonly class="readonly-field">
                                </div>
                                <div class="form-group">
                                    <label>Neto (Calculado)</label>
                                    <input type="number" step="0.01" name="neto" id="neto" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="optional">Fecha de Pago</label>
                            <input type="date" name="fecha_pago" id="fecha_pago">
                        </div>
                    </div>

                    <!-- Sección de Anexos -->
                    <div class="modal-anexos" id="seccion_anexos" style="display: none;">
                        <h4>Anexos</h4>
                        <div class="anexos-actuales" id="anexos_actuales"></div>
                        
                        <div class="file-upload-zone" id="dropZone" onclick="document.getElementById('file_input').click()">
                            <i class="fa-regular fa-file-lines"></i>
                            <p>Arrastra archivos aquí o haz clic para seleccionar</p>
                            <p style="font-size: 11px; color: #999;">PDF, PNG, JPEG, Excel (Max 10MB)</p>
                        </div>
                        <input type="file" id="file_input" style="display: none;" 
                               accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls" multiple>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="cerrarModalGasto()">
                            Cancelar
                        </button>
                        <button type="submit" class="btn-success">
                            Guardar Gasto
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const idProyectoFinanciero = <?= $id_proyecto_financiero ?>;
        const subGastosPorTipo = <?= json_encode($sub_gastos_por_tipo) ?>;
        let gastoActual = null;
        let anexosTemporales = [];

        function abrirModalGasto(tipo) {
            gastoActual = { tipo: tipo, id: null };
            anexosTemporales = [];
            
            document.getElementById('modalTituloGasto').textContent = 
                tipo === 'exterior' ? 'Agregar Gasto en el Exterior' : 'Agregar Gasto Local';
            document.getElementById('tipo_modal').value = tipo;
            document.getElementById('formGasto').reset();
            document.getElementById('gasto_id').value = '';
            //document.getElementById('fecha_gasto').value = new Date().toISOString().split('T')[0];
            //document.getElementById('usuario_gasto').value = <?= $_SESSION['usuario']['id'] ?>;
            
            // Mostrar campos según el tipo
            document.getElementById('campos_exterior').style.display = tipo === 'exterior' ? 'block' : 'none';
            document.getElementById('campos_locales').style.display = tipo === 'local' ? 'block' : 'none';
            document.getElementById('seccion_anexos').style.display = 'none';
            
            document.getElementById('modalGasto').classList.add('show');
        }

        function cerrarModalGasto() {
            document.getElementById('modalGasto').classList.remove('show');
            gastoActual = null;
            anexosTemporales = [];
            window.location.reload();
        }

        function editarGasto(tipo, gasto) {
            gastoActual = { tipo: tipo, id: gasto.id };
            anexosTemporales = gasto.anexos ? gasto.anexos.split(',').map(a => a.trim()).filter(a => a) : [];
            
            document.getElementById('modalTituloGasto').textContent = 
                tipo === 'exterior' ? 'Editar Gasto en el Exterior' : 'Editar Gasto Local';
            document.getElementById('tipo_modal').value = tipo;
            document.getElementById('gasto_id').value = gasto.id;
            //document.getElementById('fecha_gasto').value = gasto.fecha;
            document.getElementById('tipo_gasto').value = gasto.tipo_gasto;
            document.getElementById('categoria_gasto').value = gasto.categoria_id;
            document.getElementById('descripcion_gasto').value = gasto.descripcion || '';
            //document.getElementById('usuario_gasto').value = gasto.usuario;
            document.getElementById('fecha_pago').value = gasto.fecha_pago || '';
            
            cargarSubCategorias();
            setTimeout(() => {
                document.getElementById('sub_categoria_gasto').value = gasto.sub_categoria_id || '';
            }, 100);
            
            if (tipo === 'exterior') {
                document.getElementById('total_usd').value = gasto.total_usd;
                document.getElementById('tipo_cambio').value = gasto.tipo_cambio;
                document.getElementById('total_bs_ext').value = gasto.total_bs;
                document.getElementById('campos_exterior').style.display = 'block';
                document.getElementById('campos_locales').style.display = 'none';
            } else {
                // Primero mostrar el bloque
                document.getElementById('campos_exterior').style.display = 'none';
                document.getElementById('campos_locales').style.display = 'block';

                // Luego setear valores
                document.getElementById('total_bs').value = gasto.total_bs;
                document.getElementById('facturado').value = gasto.facturado;

                calcularNeto();

                document.getElementById('credito_fiscal').readOnly = true;
                document.getElementById('neto').readOnly = true;
            }

            
            // Mostrar anexos existentes
            mostrarAnexosActuales();
            document.getElementById('seccion_anexos').style.display = 'block';
            
            document.getElementById('modalGasto').classList.add('show');
        }

        function cargarSubCategorias() {
            const categoriaId = document.getElementById('categoria_gasto').value;
            const subCategoriaSelect = document.getElementById('sub_categoria_gasto');
            
            subCategoriaSelect.innerHTML = '<option value="">Seleccionar...</option>';
            
            if (categoriaId && subGastosPorTipo[categoriaId]) {
                subGastosPorTipo[categoriaId].forEach(sub => {
                    const option = document.createElement('option');
                    option.value = sub.id;
                    option.textContent = sub.nombre;
                    subCategoriaSelect.appendChild(option);
                });
            }
        }

        function calcularTotalBs() {
            const usd = parseFloat(document.getElementById('total_usd').value) || 0;
            const tc = parseFloat(document.getElementById('tipo_cambio').value) || 0;
            document.getElementById('total_bs_ext').value = (usd * tc).toFixed(2);
        }

        function calcularNeto() {
            const total = parseFloat(document.getElementById('total_bs').value) || 0;
            const facturado = document.getElementById('facturado').value;

            let credito = 0;
            if (facturado === 'si') {
                credito = total * 0.13;
            }

            document.getElementById('credito_fiscal').value = credito.toFixed(2);
            document.getElementById('neto').value = (total - credito).toFixed(2);
        }


        function mostrarAnexosActuales() {
            const container = document.getElementById('anexos_actuales');
            container.innerHTML = '';
            
            anexosTemporales.forEach((anexo, index) => {
                const div = document.createElement('div');
                div.className = 'anexo-item';
                div.innerHTML = `
                    <a href="ver_anexo.php?archivo=${encodeURIComponent(anexo)}" target="_blank" style="color: var(--primary);">
                         ${getFileName(anexo)}
                    </a>
                    <button type="button" class="btn-eliminar-anexo" onclick="eliminarAnexoTemporal(${index})">×</button>
                `;
                container.appendChild(div);
            });
        }

        function getFileName(ruta) {
            const partes = ruta.split('/');
            const nombreCompleto = partes[partes.length - 1];
            const nombreSinPrefijo = nombreCompleto.replace(/^(exterior|local)_\d+_\d+_/, '');
            return nombreSinPrefijo.length > 30 ? nombreSinPrefijo.substring(0, 27) + '...' : nombreSinPrefijo;
        }

        function eliminarAnexoTemporal(index) {
            if (!confirm('¿Eliminar este anexo?')) return;
            
            const archivo = anexosTemporales[index];
            
            fetch('eliminar_anexo.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ archivo: archivo, id_gasto: gastoActual.id })
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    anexosTemporales.splice(index, 1);
                    mostrarAnexosActuales();
                    toast('Anexo eliminado correctamente');
                } else {
                    toast('Error al eliminar anexo', false);
                }
            });
        }

        // Manejo de archivos
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('file_input');

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            subirArchivos(files);
        });

        fileInput.addEventListener('change', (e) => {
            subirArchivos(e.target.files);
        });

        async function subirArchivos(files) {
            if (!gastoActual || !gastoActual.id) {
                toast('Debes guardar el gasto primero antes de subir archivos', false);
                return;
            }
            
            for (let file of files) {
                const formData = new FormData();
                formData.append('archivo', file);
                formData.append('id_proyecto', idProyectoFinanciero);
                formData.append('tipo_gasto', gastoActual.tipo);
                formData.append('id_gasto', gastoActual.id);
                
                try {
                    const response = await fetch('subir_anexo.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        anexosTemporales.push(result.url);
                        mostrarAnexosActuales();
                        toast('Archivo subido correctamente');
                    } else {
                        toast('Error al subir archivo: ' + result.message, false);
                    }
                } catch (error) {
                    console.error('Error:', error);
                    toast('Error al subir el archivo', false);
                }
            }
            
            fileInput.value = '';
        }

        async function guardarGasto(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const tipo = formData.get('tipo_modal');
            
            const datos = {
                id: formData.get('id') || null,
                id_proyecto: idProyectoFinanciero,
                fecha: new Date().toISOString().split('T')[0],
                tipo_gasto: formData.get('tipo_gasto'),
                categoria_id: formData.get('categoria_id'),
                sub_categoria_id: formData.get('sub_categoria_id') || null,
                descripcion: formData.get('descripcion'),
                usuario: <?= $_SESSION['usuario']['id'] ?>,
                fecha_pago: formData.get('fecha_pago') || null,
                anexos: anexosTemporales.join(', ')
            };
            
            if (tipo === 'exterior') {
                datos.total_usd = parseFloat(formData.get('total_usd')) || 0;
                datos.tipo_cambio = parseFloat(formData.get('tipo_cambio')) || 0;
                datos.total_bs = parseFloat(formData.get('total_bs_ext')) || 0;
            } else {
                datos.total_bs = parseFloat(formData.get('total_bs')) || 0;
                datos.facturado = formData.get('facturado');
                datos.credito_fiscal = parseFloat(formData.get('credito_fiscal')) || 0;
                datos.neto = parseFloat(formData.get('neto')) || 0;
            }
            
            const url = tipo === 'exterior' ? 'guardar_gastos_exterior.php' : 'guardar_gastos_locales.php';
            
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id_proyecto: idProyectoFinanciero,
                        gastos: [datos]
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    toast('Gasto guardado correctamente');
                    
                    if (!datos.id && result.id) {
                        gastoActual.id = result.id;
                        document.getElementById('gasto_id').value = result.id;
                        document.getElementById('seccion_anexos').style.display = 'block';
                    } else {
                        setTimeout(() => location.reload(), 1000);
                    }
                } else {
                    toast('Error al guardar: ' + result.message, false);
                }
            } catch (error) {
                console.error('Error:', error);
                toast('Error de conexión al guardar', false);
            }
        }

        async function eliminarGasto(tipo, id) {
            if (!confirm('¿Estás seguro de eliminar este gasto?')) return;
            
            const url = tipo === 'exterior' ? 'eliminar_gasto_exterior.php' : 'eliminar_gasto_local.php';
            
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id: id })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    toast('Gasto eliminado correctamente');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    toast('Error al eliminar: ' + result.message, false);
                }
            } catch (error) {
                console.error('Error:', error);
                toast('Error al eliminar el gasto', false);
            }
        }

        function toast(msg, ok=true) {
            const t = document.createElement('div');
            t.textContent = msg;
            t.style.cssText = `
                position: fixed; bottom: 20px; right: 20px; z-index: 10000;
                background: ${ok ? '#27ae60' : '#e74c3c'};
                color: #fff; padding: 12px 20px; border-radius: 8px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.2);
                animation: slideIn 0.3s ease;
            `;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 2500);
        }

        // Cerrar modal con ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') cerrarModalGasto();
        });

        // Cerrar modal al hacer clic fuera
        window.addEventListener('click', (e) => {
            const modal = document.getElementById('modalGasto');
            if (e.target === modal) cerrarModalGasto();
        });
    </script>
</body>
</html>