<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

$id_proyecto_financiero = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Obtener datos del proyecto financiero
$stmt = $conn->prepare("SELECT pf.*, 
                        COALESCE(pf.titulo, p.titulo) as titulo_mostrar,
                        COALESCE(pf.cliente, p.cliente) as cliente_mostrar,
                        p.numero_proyecto, 
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

// Obtener datos de cabecera
$stmt = $conn->prepare("SELECT * FROM datos_cabecera WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto_financiero]);
$datos_cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no existe, crear registro vacío
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

$tipos_gasto_js = [];
foreach ($tipos_gasto as $tipo) {
    $tipos_gasto_js[$tipo['id']] = $tipo['nombre'];
}

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

$total_producto = $total_gastos_exterior_bs; // Gastos de productos importados
$costos_importacion = $total_gastos_exterior_bs; // Total gastado en exterior
$gastos_locales_total = $total_neto_locales; // Neto de gastos locales
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
    <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@5.5.2/dist/css/tabulator.min.css">
    <style>
        .tabulator .tabulator-header .tabulator-col {
            color: #000 !important;
        }

        .tabulator-col .tabulator-col-title {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: unset !important;
            word-wrap: break-word !important;
            text-align: center;
        }

        .resumen-financiero {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 2px solid #34a44c;
        }

        .resumen-financiero h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            border-bottom: 2px solid #34a44c;
            padding-bottom: 10px;
        }

        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .resumen-item {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .resumen-item h4 {
            margin: 0 0 8px 0;
            font-size: 13px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .resumen-item .valor {
            font-size: 22px;
            font-weight: bold;
            color: #2c3e50;
        }

        .utilidad-positiva {
            color: #28a745 !important;
        }

        .utilidad-negativa {
            color: #dc3545 !important;
        }

        .seccion-gastos {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: white;
        }

        .seccion-gastos h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #34a44c;
        }

        .btn-success {
            background-color: #28a745;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .totales-footer {
            background-color: #f8f9fa;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }

        .totales-footer strong {
            color: #2c3e50;
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="proyecto-detalle">
        <header class="proyecto-header">
            <div class="header-left">
                <img src="assets/logo.png" class="logo">
                <div>
                    <h1><?= htmlspecialchars($proyecto_financiero['titulo_mostrar'] ?? 'Sin título') ?></h1>
                </div>
            </div>
            <a href="finanzas.php" class="btn-back">Volver a Proyectos Financieros</a>
        </header>
    </div>
    
    <!-- Resumen Financiero -->
    <div class="resumen-financiero">
        <h2>RESUMEN FINANCIERO</h2>
        <div class="resumen-grid">
            <div class="resumen-item">
                <h4>Total Producto</h4>
                <div class="valor"><?= number_format($total_producto, 2) ?> Bs</div>
            </div>
            <div class="resumen-item">
                <h4>Costos de Importación</h4>
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
                <div class="valor" style="color: #007bff;"><?= number_format($total_ingreso, 2) ?> Bs</div>
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
    <div class="maestro">
        <form action="guardar_datos_cabecera.php" method="POST">
            <input type="hidden" name="id_proyecto_financiero" value="<?= $id_proyecto_financiero ?>">

            <div class="maestro-header">
                <h2>Datos de Cabecera del Proyecto</h2>
                <button type="submit" class="btn-back">Guardar Cambios</button>                      
            </div>

            <div class="maestro-content">
                <div class="maestro-row">
                    <div class="maestro-col">
                        <label>Precio Final de Venta (Bs):</label>
                        <input type="number" step="0.01" name="precio_final_venta" 
                               value="<?= $datos_cabecera['precio_final_venta'] ?>" required>

                        <label>Venta de Productos (Bs):</label>
                        <input type="number" step="0.01" name="venta_productos" 
                               value="<?= $datos_cabecera['venta_productos'] ?>" required>

                        <label>Venta de Servicios (Bs):</label>
                        <input type="number" step="0.01" name="venta_servicios" 
                               value="<?= $datos_cabecera['venta_servicios'] ?? 0 ?>" required>
                    </div>

                    <div class="maestro-col">
                        <label>Presupuesto Gasto en USD:</label>
                        <input type="number" step="0.01" name="presupuesto_gasto_usd" 
                               value="<?= $datos_cabecera['presupuesto_gasto_usd'] ?>" required>

                        <label>Presupuesto Gasto en Bs:</label>
                        <input type="number" step="0.01" name="presupuesto_gasto_bs" 
                               value="<?= $datos_cabecera['presupuesto_gasto_bs'] ?>" required>

                        <label>Crédito Fiscal a Favor (Bs):</label>
                        <input type="number" step="0.01" name="credito_fiscal_favor" 
                               value="<?= $datos_cabecera['credito_fiscal_favor'] ?>" required>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Gastos en el Exterior -->
    <div class="seccion-gastos">
        <h2>Gastos en el Exterior (USD)</h2>
        <div class="form-rowi">
            <div>
                <button id="add-gasto-exterior" class="btn">Agregar Gasto</button>
                <button id="save-gastos-exterior" class="btn btn-success">Guardar Gastos</button>
            </div>
        </div>

        <div id="gastos-exterior-grid"></div>
        
    </div>

    <!-- Gastos Locales -->
    <div class="seccion-gastos">
        <h2>Gastos Locales (Bs)</h2>
        <div class="form-rowi">
            <div>
                <button id="add-gasto-local" class="btn">Agregar Gasto</button>
                <button id="save-gastos-locales" class="btn btn-success">Guardar Gastos</button>
            </div>
        </div>

        <div id="gastos-locales-grid"></div>
        
    </div>

    <script src="https://unpkg.com/tabulator-tables@5.5.2/dist/js/tabulator.min.js"></script>
    
    <script>
    const idProyectoFinanciero = <?= $id_proyecto_financiero ?>;
    const usuarios = <?= json_encode($usuarios) ?>;
    const tiposGasto = <?= json_encode($tipos_gasto) ?>;
    const subGastosPorTipo  = <?= json_encode($sub_gastos_por_tipo) ?>;

    // Configurar valores para select de usuarios
    const usuariosSelect = usuarios.reduce((acc, user) => {
        acc[user.id] = user.nombre;
        return acc;
    }, {});

    // Configurar valores para select de tipos de gasto
    const tiposGastoSelect = {};
    tiposGasto.forEach(tipo => {
        tiposGastoSelect[tipo.id] = tipo.nombre;
    });

    // Función para obtener sub gastos según tipo seleccionado
    function getSubGastosSelect(tipoId) {
        const subGastos = subGastosPorTipo[tipoId] || [];
        const select = {};
        subGastos.forEach(sub => {
            select[sub.id] = sub.nombre;
        });
        return select;
    }

    // Tabla de Gastos en el Exterior
    const tableExterior = new Tabulator("#gastos-exterior-grid", {
        height: "auto",
        layout: "fitDataStretch",
        addRowPos: "top",
        history: true,
        columns: [
            {title: "Fecha", field: "fecha", editor: "date", width: 120, validator: "required"},
            {title: "Tipo Gasto", field: "tipo_gasto", editor: "select", 
             editorParams: {values: {Producto: "Producto", Servicio: "Servicio"}}, 
             width: 120, validator: "required"},
            {title: "Categoría", field: "categoria_id", editor: "select", 
             editorParams: {values: tiposGastoSelect}, 
             formatter: function(cell) {
                 const val = cell.getValue();
                 return tiposGastoSelect[val] || '';
             },
             cellEdited: function(cell) {
                 // Al cambiar categoría, resetear subcategoría
                 const row = cell.getRow();
                 row.update({sub_categoria_id: null});
             },
             width: 150, validator: "required"},
             {title: "Sub Categoría", field: "sub_categoria_id", editor: "select",
             editorParams: function(cell) {
                 const row = cell.getRow();
                 const categoriaId = row.getData().categoria_id;
                 return {values: categoriaId ? getSubGastosSelect(categoriaId) : {}};
             },
             formatter: function(cell) {
                 const val = cell.getValue();
                 const row = cell.getRow();
                 const categoriaId = row.getData().categoria_id;
                 if (!val || !categoriaId) return '';
                 const subGastos = subGastosPorTipo[categoriaId] || [];
                 const subGasto = subGastos.find(s => s.id == val);
                 return subGasto ? subGasto.nombre : '';
             },
             width: 160},
            {title: "Descripción", field: "descripcion", editor: "input", width: 250},
            {title: "Total USD", field: "total_usd", editor: "number", align:"right",
             formatter: "money", formatterParams: {symbol: " $", symbolAfter:true, thousand: ".", decimal:",", precision: 2}, hozAlign: "right",
             bottomCalc: "sum", bottomCalcFormatter: "money", 
             bottomCalcFormatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal:",", precision: 2},
             width: 130, validator: "required"},
            {title: "TC", field: "tipo_cambio", editor: "number", 
             formatter: "money", formatterParams: {precision: 2}, width: 100},
            {title: "Total Bs", field: "total_bs", align: "right", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal:",", precision: 2}, hozAlign: "right",
             bottomCalc: "sum", bottomCalcFormatter: "money",
             bottomCalcFormatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal:",", precision: 2},
             width: 120},
            {title: "Anexos", field: "anexos", editor: "input", width: 150},
            {title: "Usuario", field: "usuario", editor: "select", 
             editorParams: {values: usuariosSelect}, 
             formatter: function(cell) {
                 const val = cell.getValue();
                 return usuariosSelect[val] || val;
             },
             width: 150},
            {title: "Fecha Pago", field: "fecha_pago", editor: "date", width: 100},
            {title: "Acciones", formatter: "buttonCross", width: 60, hozAlign: "center",
             cellClick: function(e, cell) {
                eliminarFila(cell, 'exterior');
            }}
        ],
        data: <?= json_encode($gastos_exterior) ?>,
        cellEdited: function(cell) {
            // Auto-calcular Total Bs cuando cambia USD o TC
            const field = cell.getField();
            if (field === 'total_usd' || field === 'tipo_cambio') {
                const row = cell.getRow();
                const data = row.getData();
                const totalUsd = parseFloat(data.total_usd) || 0;
                const tc = parseFloat(data.tipo_cambio) || 0;
                row.update({total_bs: totalUsd * tc});
            }
        }
    });

    // Tabla de Gastos Locales
    const tableLocales = new Tabulator("#gastos-locales-grid", {
        height: "auto",
        layout: "fitDataStretch",
        addRowPos: "top",
        history: true,
        columns: [
            {title: "Fecha", field: "fecha", editor: "date", width: 120, validator: "required"},
            {title: "Tipo Gasto", field: "tipo_gasto", editor: "select",
             editorParams: {values: {Producto: "Producto", Servicio: "Servicio"}},
             width: 120, validator: "required"},
            {title: "Categoría", field: "categoria_id", editor: "select",
             editorParams: {values: tiposGastoSelect},
             formatter: function(cell) {
                 const val = cell.getValue();
                 return tiposGastoSelect[val] || '';
             },
             cellEdited: function(cell) {
                 // Al cambiar categoría, resetear subcategoría
                 const row = cell.getRow();
                 row.update({sub_categoria_id: null});
             },
             width: 150, validator: "required"},
            {title: "Sub Categoría", field: "sub_categoria_id", editor: "select",
             editorParams: function(cell) {
                 const row = cell.getRow();
                 const categoriaId = row.getData().categoria_id;
                 return {values: categoriaId ? getSubGastosSelect(categoriaId) : {}};
             },
             formatter: function(cell) {
                 const val = cell.getValue();
                 const row = cell.getRow();
                 const categoriaId = row.getData().categoria_id;
                 if (!val || !categoriaId) return '';
                 const subGastos = subGastosPorTipo[categoriaId] || [];
                 const subGasto = subGastos.find(s => s.id == val);
                 return subGasto ? subGasto.nombre : '';
             },
             width: 160},
            {title: "Descripción", field: "descripcion", editor: "input", width: 250},
            {title: "Total Bs", field: "total_bs", editor: "number",
             formatter: "money", formatterParams: {symbol: " Bs", symbolAfter:true, thousand: ".", decimal: ",", precision: 2},
             bottomCalc: "sum", bottomCalcFormatter: "money",
             bottomCalcFormatterParams: {symbol: " Bs", symbolAfter:true, thousand: ".", decimal:",", precision: 2}, hozAlign:"right",
             width: 130, validator: "required"},
            {title: "Facturado", field: "facturado", editor: "select", 
             editorParams: {values: {si: "SI", no: "NO"}}, width: 100},
            {title: "CF", field: "credito_fiscal", editor: "number",
             formatter: "money", formatterParams: {symbol: " Bs", symbolAfter:true, thousand: ".", decimal:",", precision: 2}, hozAlign:"right",
             bottomCalc: "sum", bottomCalcFormatter: "money",
             bottomCalcFormatterParams: {symbol: " Bs", symbolAfter:true, thousand: ".", decimal:",", precision: 2},
             width: 90},
            {title: "Neto", field: "neto",
             formatter: "money", formatterParams: {symbol: " Bs", symbolAfter:true,thousand: ".", decimal:",", precision: 2}, hozAlign:"right",
             bottomCalc: "sum", bottomCalcFormatter: "money",
             bottomCalcFormatterParams: {symbol: " Bs", symbolAfter:true, thousand: ".", decimal:",", precision: 2},
             width: 120},
            {title: "Anexos", field: "anexos", editor: "input", width: 150},
            {title: "Usuario", field: "usuario", editor: "select",
             editorParams: {values: usuariosSelect},
             formatter: function(cell) {
                 const val = cell.getValue();
                 return usuariosSelect[val] || val;
             },
             width: 150},
            {title: "Fecha Pago", field: "fecha_pago", editor: "date", width: 80},
            {title: "Acciones", formatter: "buttonCross", width: 60, hozAlign: "center",
             cellClick: function(e, cell) {
                eliminarFila(cell, 'local');
            }}
        ],
        data: <?= json_encode($gastos_locales) ?>,
        cellEdited: function(cell) {
            // Auto-calcular Neto cuando cambia Total Bs o Crédito Fiscal
            const field = cell.getField();
            if (field === 'total_bs' || field === 'credito_fiscal') {
                const row = cell.getRow();
                const data = row.getData();
                const totalBs = parseFloat(data.total_bs) || 0;
                const creditoFiscal = parseFloat(data.credito_fiscal) || 0;
                row.update({neto: totalBs - creditoFiscal});
            }
        }
    });

    // Event Listeners
    document.getElementById("add-gasto-exterior").addEventListener("click", function() {
        tableExterior.addRow({
            fecha: new Date().toISOString().split('T')[0],
            usuario: <?= $_SESSION['usuario']['id'] ?>,
            tipo_gasto: 'Producto',
            tipo_cambio: 17.0,
            total_usd: 0,
            total_bs: 0
        }, true);
    });

    document.getElementById("add-gasto-local").addEventListener("click", function() {
        tableLocales.addRow({
            fecha: new Date().toISOString().split('T')[0],
            usuario: <?= $_SESSION['usuario']['id'] ?>,
            tipo_gasto: 'Servicio',
            facturado: 'no',
            total_bs: 0,
            credito_fiscal: 0,
            neto: 0
        }, true);
    });

    document.getElementById("save-gastos-exterior").addEventListener("click", function() {
        const datos = tableExterior.getData();
        guardarGastos('guardar_gastos_exterior.php', datos, 'exterior');
    });

    document.getElementById("save-gastos-locales").addEventListener("click", function() {
        const datos = tableLocales.getData();
        guardarGastos('guardar_gastos_locales.php', datos, 'locales');
    });

    function eliminarFila(cell, tipo) {
        const row = cell.getRow();
        const data = row.getData();
        
        if (!confirm("¿Estás seguro de eliminar este gasto?")) return;
        
        if (data.id) {
            const url = tipo === 'exterior' ? 'eliminar_gasto_exterior.php' : 'eliminar_gasto_local.php';
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: data.id })
            }).then(response => response.json())
            .then(res => {
                if (res.success) {
                    row.delete();
                    alert('Gasto eliminado correctamente');
                    location.reload();
                } else {
                    alert("Error al eliminar: " + res.message);
                }
            });
        } else {
            row.delete();
        }
    }

    function guardarGastos(url, datos, tipo) {

    const errores = [];
    const gastosLimpios = [];

    datos.forEach((gasto, index) => {

        // Validaciones comunes
        if (!gasto.fecha) errores.push(`Fila ${index + 1}: Falta fecha`);
        if (!gasto.tipo_gasto) errores.push(`Fila ${index + 1}: Falta tipo de gasto`);
        if (!gasto.categoria_id) errores.push(`Fila ${index + 1}: Falta categoría`);

        // Validaciones específicas
        if (tipo === 'exterior') {
            if (!gasto.total_usd || Number(gasto.total_usd) <= 0) {
                errores.push(`Fila ${index + 1}: Total USD inválido`);
            }
        }

        if (tipo === 'locales') {
            if (!gasto.total_bs || Number(gasto.total_bs) <= 0) {
                errores.push(`Fila ${index + 1}: Total Bs inválido`);
            }
        }

        // Normalizar datos
        gastosLimpios.push({
            id: gasto.id ?? null,
            id_proyecto: idProyectoFinanciero,
            fecha: gasto.fecha,
            tipo_gasto: gasto.tipo_gasto,
            categoria_id: gasto.categoria_id,
            sub_categoria_id: gasto.sub_categoria_id ?? null,
            descripcion: gasto.descripcion ?? '',
            total_usd: parseFloat(gasto.total_usd) || 0,
            tipo_cambio: parseFloat(gasto.tipo_cambio) || 0,
            total_bs: parseFloat(gasto.total_bs) || 0,
            facturado: gasto.facturado ?? 'no',
            credito_fiscal: parseFloat(gasto.credito_fiscal) || 0,
            neto: parseFloat(gasto.neto) || 0,
            anexos: gasto.anexos ?? '',
            usuario: gasto.usuario,
            fecha_pago: gasto.fecha_pago ?? null
        });
    });

    if (errores.length > 0) {
        alert('Errores de validación:\n\n' + errores.join('\n'));
        return;
    }

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id_proyecto: idProyectoFinanciero,
            gastos: gastosLimpios
        })
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            location.reload();
        } else {
            alert('Error al guardar: ' + res.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error de conexión al guardar gastos');
    });
}

    </script>
</body>
</html>