<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

$id_proyecto_financiero = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Obtener datos del proyecto financiero
$stmt = $conn->prepare("SELECT pf.*, p.titulo, p.cliente, p.numero_proyecto, 
                        u.nombre as nombre_usuario, s.nombre as sucursal_nombre,
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
    $stmt = $conn->prepare("INSERT INTO datos_cabecera (id_proyecto) VALUES (?)");
    $stmt->execute([$id_proyecto_financiero]);
    $datos_cabecera = [
        'precio_final_venta' => 0,
        'venta_productos' => 0,
        'presupuesto_gasto_usd' => 0,
        'presupuesto_gasto_bs' => 0,
        'credito_fiscal_favor' => 0
    ];
}

// Obtener gastos en el exterior
$stmt = $conn->prepare("SELECT ge.*, u.nombre as nombre_usuario 
                       FROM gastos_exterior ge 
                       JOIN usuarios u ON ge.usuario = u.id 
                       WHERE ge.id_proyecto = ? 
                       ORDER BY ge.fecha DESC");
$stmt->execute([$id_proyecto_financiero]);
$gastos_exterior = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener gastos locales
$stmt = $conn->prepare("SELECT gl.*, u.nombre as nombre_usuario 
                       FROM gastos_locales gl 
                       JOIN usuarios u ON gl.usuario = u.id 
                       WHERE gl.id_proyecto = ? 
                       ORDER BY gl.fecha DESC");
$stmt->execute([$id_proyecto_financiero]);
$gastos_locales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener lista de usuarios para los select
$stmt = $conn->query("SELECT id, nombre FROM usuarios");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totales
$total_gastos_exterior = array_sum(array_column($gastos_exterior, 'total_bs'));
$total_gastos_locales = array_sum(array_column($gastos_locales, 'total_bs'));
$total_gastos = $total_gastos_exterior + $total_gastos_locales;
$precio_venta = $datos_cabecera['precio_final_venta'];
$utilidad = $precio_venta - $total_gastos;
$utilidad_porcentaje = $precio_venta > 0 ? ($utilidad / $precio_venta) * 100 : 0;

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proyecto Financiero: <?= htmlspecialchars($proyecto_financiero['titulo'] ?? 'Proyecto Independiente') ?></title>
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
            border: 1px solid #dee2e6;
        }

        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .resumen-item {
            text-align: center;
            padding: 10px;
            background: white;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }

        .resumen-item h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: #6c757d;
        }

        .resumen-item .valor {
            font-size: 18px;
            font-weight: bold;
            color: #000;
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
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="proyecto-detalle">
        <header class="proyecto-header">
            <div class="header-left">
                <img src="assets/logo.png" class="logo">
                <h1><?= htmlspecialchars($proyecto_financiero['titulo'] ?? 'Proyecto Independiente') ?></h1>
                <p><strong>Proyecto Financiero:</strong> PF-<?= $proyecto_financiero['numero_proyectoF'] ?></p>
                <?php if ($proyecto_financiero['numero_proyecto']): ?>
                    <p><strong>Proyecto Original:</strong> #<?= $proyecto_financiero['numero_proyecto'] ?></p>
                <?php endif; ?>
                <p><strong>Estado:</strong> <?= $proyecto_financiero['estado_financiero'] ?></p>
            </div>
            <a href="finanzas.php" class="btn-back">Volver a Proyectos Financieros</a>
        </header>
    </div>
    
    <!-- Resumen Financiero -->
    <div class="resumen-financiero">
        <h2>Resumen Financiero</h2>
        <div class="resumen-grid">
            <div class="resumen-item">
                <h4>Precio Final de Venta</h4>
                <div class="valor"><?= number_format($precio_venta, 2) ?> Bs</div>
            </div>
            <div class="resumen-item">
                <h4>Total Gastos</h4>
                <div class="valor"><?= number_format($total_gastos, 2) ?> Bs</div>
            </div>
            <div class="resumen-item">
                <h4>Utilidad Neta</h4>
                <div class="valor <?= $utilidad >= 0 ? 'utilidad-positiva' : 'utilidad-negativa' ?>">
                    <?= number_format($utilidad, 2) ?> Bs
                </div>
            </div>
            <div class="resumen-item">
                <h4>Margen de Utilidad</h4>
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
                <h2>Datos Principales</h2>
                <button type="submit" class="btn-back">Guardar Cambios</button>                      
            </div>

            <div class="maestro-content">
                <div class="maestro-row">
                    <div class="maestro-col">
                        <label>Precio Final de Venta (Bs):</label>
                        <input type="number" step="0.01" name="precio_final_venta" value="<?= $datos_cabecera['precio_final_venta'] ?>">

                        <label>Venta de Productos (Bs):</label>
                        <input type="number" step="0.01" name="venta_productos" value="<?= $datos_cabecera['venta_productos'] ?>">
                    </div>

                    <div class="maestro-col">
                        <label>Presupuesto Gasto en USD:</label>
                        <input type="number" step="0.01" name="presupuesto_gasto_usd" value="<?= $datos_cabecera['presupuesto_gasto_usd'] ?>">

                        <label>Presupuesto Gasto en Bs:</label>
                        <input type="number" step="0.01" name="presupuesto_gasto_bs" value="<?= $datos_cabecera['presupuesto_gasto_bs'] ?>">

                        <label>Crédito Fiscal a Favor (Bs):</label>
                        <input type="number" step="0.01" name="credito_fiscal_favor" value="<?= $datos_cabecera['credito_fiscal_favor'] ?>">
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Gastos en el Exterior -->
    <div class="seccion-gastos">
        <h2>Gastos en el Exterior</h2>
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
        <h2>Gastos Locales</h2>
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

    // Tabla de Gastos Locales
    const tableLocales = new Tabulator("#gastos-locales-grid", {
        height: "auto",
        layout: "fitColumns",
        addRowPos: "top",
        history: true,
        columns: [
            {title: "Fecha", field: "fecha", editor: "input", width: 120, validator: "required"},
            {title: "Tipo de Gasto", field: "tipo_gasto", editor: "input", validator: "required"},
            {title: "Descripción", field: "descripcion", editor: "input", width: 200},
            {title: "Total Bs", field: "total_bs", editor: "number",
             formatter: "money", formatterParams: {symbol: "Bs", symbolAfter: false, precision: 2},
             bottomCalc: "sum", bottomCalcFormatter: "money", width: 120},
            {title: "Facturado", field: "facturado", editor: "select", 
             editorParams: {values: {"si": "Sí", "no": "No"}}, width: 100},
            {title: "Crédito Fiscal", field: "credito_fiscal", editor: "number", width: 120},
            {title: "Neto", field: "neto", editor: "number", width: 120},
            {title: "Anexos", field: "anexo", editor: "input", width: 150},
            {title: "Usuario", field: "usuario", editor: "select", editorParams: {
                values: usuarios.reduce((acc, user) => {
                    acc[user.id] = user.nombre;
                    return acc;
                }, {})
            }, width: 150},
            {title: "Fecha Pago", field: "fecha_pago", editor: "input", width: 120},
            {title: "Acciones", formatter: "buttonCross", width: 80, hozAlign: "center",
             cellClick: function(e, cell) {
                const row = cell.getRow();
                const data = row.getData();
                
                if (confirm("¿Estás seguro de eliminar este gasto?")) {
                    if (data.id) {
                        fetch('eliminar_gasto_local.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ id: data.id })
                        }).then(response => response.json())
                        .then(res => {
                            if (res.success) {
                                row.delete();
                            } else {
                                alert("Error al eliminar: " + res.message);
                            }
                        });
                    } else {
                        row.delete();
                    }
                }
            }}
        ],
        data: <?= json_encode($gastos_locales) ?>
    });

    // Tabla de Gastos en el Exterior
    const tableExterior = new Tabulator("#gastos-exterior-grid", {
        height: "auto",
        layout: "fitColumns",
        addRowPos: "top",
        history: true,
        columns: [
            {title: "Fecha", field: "fecha", editor: "input", width: 120, validator: "required"},
            {title: "Tipo de Gasto", field: "tipo_gasto", editor: "input", validator: "required"},
            {title: "Descripción", field: "descripcion", editor: "input", width: 200},
            {title: "Total USD", field: "total_usd", editor: "number", 
             formatter: "money", formatterParams: {symbol: "$", symbolAfter: false, precision: 2},
             bottomCalc: "sum", bottomCalcFormatter: "money", width: 120},
            {title: "Tipo de Cambio", field: "tipo_cambio", editor: "number", width: 120},
            {title: "Total Bs", field: "total_bs", editor: "number",
             formatter: "money", formatterParams: {symbol: "Bs", symbolAfter: false, precision: 2},
             bottomCalc: "sum", bottomCalcFormatter: "money", width: 120},
            {title: "Anexos", field: "anexo", editor: "input", width: 150},
            {title: "Usuario", field: "usuario", editor: "select", editorParams: {
                values: usuarios.reduce((acc, user) => {
                    acc[user.id] = user.nombre;
                    return acc;
                }, {})
            }, width: 150},
            {title: "Fecha Pago", field: "fecha_pago", editor: "input", width: 120},
            {title: "Acciones", formatter: "buttonCross", width: 80, hozAlign: "center",
             cellClick: function(e, cell) {
                const row = cell.getRow();
                const data = row.getData();
                
                if (confirm("¿Estás seguro de eliminar este gasto?")) {
                    if (data.id) {
                        // Eliminar de la base de datos
                        fetch('eliminar_gasto_exterior.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ id: data.id })
                        }).then(response => response.json())
                        .then(res => {
                            if (res.success) {
                                row.delete();
                            } else {
                                alert("Error al eliminar: " + res.message);
                            }
                        });
                    } else {
                        row.delete();
                    }
                }
            }}
        ],
        data: <?= json_encode($gastos_exterior) ?>
    });


    // Event Listeners
    document.getElementById("add-gasto-exterior").addEventListener("click", function() {
        tableExterior.addRow({
            fecha: new Date().toISOString().split('T')[0],
            usuario: <?= $_SESSION['usuario']['id'] ?>
        }, true);
    });

    document.getElementById("add-gasto-local").addEventListener("click", function() {
        tableLocales.addRow({
            fecha: new Date().toISOString().split('T')[0],
            usuario: <?= $_SESSION['usuario']['id'] ?>,
            facturado: 'no'
        }, true);
    });

    document.getElementById("save-gastos-exterior").addEventListener("click", function() {
        const datos = tableExterior.getData();
        guardarGastos('guardar_gastos_exterior.php', datos);
    });

    document.getElementById("save-gastos-locales").addEventListener("click", function() {
        const datos = tableLocales.getData();
        guardarGastos('guardar_gastos_locales.php', datos);
    });

    function guardarGastos(url, datos) {
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id_proyecto: idProyectoFinanciero,
                gastos: datos
            })
        }).then(response => response.json())
        .then(res => {
            if (res.success) {
                alert('Gastos guardados correctamente');
                location.reload();
            } else {
                alert('Error al guardar: ' + res.message);
            }
        }).catch(error => {
            console.error('Error:', error);
            alert('Error al guardar los gastos');
        });
    }
    </script>
</body>
</html>