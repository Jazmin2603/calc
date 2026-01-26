<?php
include 'includes/config.php';
include 'includes/auth.php';

$id_proyecto = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$query = "SELECT p.*, u.nombre as nombre_usuario, e.estado as nombre_estado 
          FROM proyecto p 
          JOIN usuarios u ON p.id_usuario = u.id 
          LEFT JOIN estados e ON p.estado_id = e.id
          WHERE p.id_proyecto = ?";
$stmt = $conn->prepare($query);
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$proyecto) {
    header("Location: proyectos.php?error=Proyecto no encontrado");
    exit();
}

if (esGerente() 
    && $_SESSION['usuario']['id'] != $proyecto['id_usuario'] 
    && !esSuperusuario()) {
    header("Location: proyectos.php?error=No autorizado");
    exit();
}

$numero_proyecto = $proyecto['numero_proyecto'];

$stmt = $conn->prepare("SELECT anio FROM contadores WHERE ? BETWEEN numero_inicio AND numero_fin AND documento = 'presupuestos'");
$stmt->execute([$numero_proyecto]);
$anio = $stmt->fetch(PDO::FETCH_ASSOC);


$items = $conn->prepare("SELECT * FROM items WHERE id_proyecto = ? ORDER BY id_item");
$items->execute([$id_proyecto]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// Al inicio de ver_proyecto.php
$params = array_filter([
    'sucursal' => $_GET['sucursal'] ?? null,
    'usuario' => $_GET['usuario'] ?? null,
    'estado' => $_GET['estado'] ?? null,
    'buscar' => $_GET['buscar'] ?? null,
    'pagina' => $_GET['pagina'] ?? null
]);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Presupuesto: <?= htmlspecialchars($proyecto['titulo']) ?></title>
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

    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="proyecto-detalle">
        <header class="proyecto-header">
            <div class="header-left">
                <img src="assets/logo.png" class="logo">
                <h1><?= htmlspecialchars($proyecto['titulo']) ?></h1>
            </div>
            <a href="proyectos.php?<?= http_build_query($params) ?>" class="btn-back">Volver a Presupuestos</a>
        </header>
    </div>
        
    <div>
        <div class="maestro">
        <form action="guardar_proyecto.php" method="POST">
        <input type="hidden" name="id_proyecto" value="<?= $proyecto['id_proyecto'] ?>">

        <div class="maestro-header">
            <div class="header-title-section">
                <h2>Presupuesto - <?= $proyecto['numero_proyecto'] ?></h2>
                <?php if (strtolower($proyecto['nombre_estado']) == 'ganado'): ?>
                    <div class="monto-adjudicado-header">
                        <label style="color: white; font-weight: bold; margin-right: 8px;">Monto Adjudicado:</label>
                        <input type="text"
                            id="monto_adjudicado"
                            name="monto_adjudicado"
                            value="<?= number_format((float)$proyecto['monto_adjudicado'], 2, ',', '.') ?>"
                            style="font-weight: bold; color: #1b5e20; padding: 4px 8px; border-radius: 4px; border: 1px solid #a5d6a7; background-color: #e8f5e9;">
                    </div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-back">Guardar Cambios</button>                      
        </div>
            
        <div class="maestro-content">
                <div class="maestro-row">
                    <div class="maestro-col">
                        <label>Titulo:</label>
                        <input type="text" name="titulo" value="<?= $proyecto['titulo'] ?>">

                        <label>Fecha Inicio:</label>
                        <input type="date" name="fecha_proyecto" value="<?= $proyecto['fecha_proyecto'] ?>">

                        <label>Cliente:</label>
                        <input type="text" name="cliente" value="<?= htmlspecialchars($proyecto['cliente']) ?>">

                        <label>Fecha Cierre:</label>
                        <input type="date" name="fecha_cierre" value="<?= $proyecto['fecha_cierre'] ?>">

                        <label>Creado por:</label>
                        <input type="text" value="<?= htmlspecialchars($proyecto['nombre_usuario']) ?>" disabled>
                    </div>

                    <div class="maestro-col">
                        <label>Giro:</label>
                        <input type="number" step="0.01" name="giro_exterior" value="<?= $proyecto['giro_exterior'] ?>" <?= ($_SESSION['usuario']['rol_id'] != 2) ? 'readonly' : '' ?>>

                        <label>TC Oficial:</label>
                        <input type="number" step = "0.01" name="tc_oficial" value="<?= $proyecto['tc_oficial'] ?>" <?= ($_SESSION['usuario']['rol_id'] != 2) ? 'readonly' : '' ?>>

                        <label>TC Paralelo Hoy:</label>
                        <input type="number" step="0.01" name="tc_paralelo_hoy" value="<?= $proyecto['tc_paralelo_hoy'] ?>">

                        <label>TC Estimado 30:</label>
                        <input type="number" step="0.01" name="tc_estimado30" value="<?= $proyecto['tc_estimado30'] ?>">

                        <label>TC Estimado 60:</label>
                        <input type="number" step="0.01" name="tc_estimado60" value="<?= $proyecto['tc_estimado60'] ?>">
                    </div>

                    <div class="maestro-col">
                        <?php if(esGerente() || esSuperusuario()):?>
                            <label>IVA:</label>
                            <input type="number" step="0.01" name="iva" value="<?= $proyecto['iva'] ?>">

                            <label>IT:</label>
                            <input type="number" step="0.01" name="it" value="<?= $proyecto['it'] ?>">

                            <label>ITF:</label>
                            <input type="number" step="0.01" name="itf" value="<?= $proyecto['itf'] ?>">

                            <label>Comisión Aduana:</label>
                            <input type="number" step="0.01" name="com_aduana" value="<?= $proyecto['com_aduana'] ?>">
                        <?php endif; ?>
                        <label>Pago anticipado a DMC:</label>
                        <input type="number" step="0.01" name="pago_anticipado_DMC" value="<?= $proyecto['pago_anticipado_DMC'] ?>">  
                        
                    </div>
                </div>
            </div>            
        </form>
       </div>
    </div>
    

    </div>    
        
    <div class="detalle">
        <h2>Items del Presupuesto</h2>
        <div class="form-rowi">
            <div>
                <button id="add-row" class="btn">Agregar Item</button>
                <button id="save-all" class="btn btn-success">Guardar</button>
            </div>    
            <select id="venta-selector">
                <option value="usd" <?= $proyecto['vista_precio'] == 'usd' ? 'selected' : '' ?>>Precio USD - Exterior</option>
                <option value="usdBo" <?= $proyecto['vista_precio'] == 'usdBo' ? 'selected' : '' ?>>Precio USD - Bolivia</option>
                <option value="hoy" <?= $proyecto['vista_precio'] == 'hoy' ? 'selected' : '' ?>>Precio venta Hoy</option>
                <option value="30" <?= $proyecto['vista_precio'] == '30' ? 'selected' : '' ?>>Precio de venta a 30 días</option>
                <option value="60" <?= $proyecto['vista_precio'] == '60' ? 'selected' : '' ?>>Precio de venta a 60 días</option>
            </select>

        </div>
            
        <div id="items-grid"></div>
    </div>

    <script src="https://unpkg.com/tabulator-tables@5.5.2/dist/js/tabulator.min.js"></script>
    
    <script>

    const vistaPrecio = "<?= $proyecto['vista_precio'] ?>";

    //Configuración del DataGrid
    const table = new Tabulator("#items-grid", {
        height: "auto",
        layout: "fitColumns",
        addRowPos: "top",
        history: true,
        columns: [
            {title: "Código", field: "codigo", editor: "input",width:100},
            {title: "Descripción", field: "descripcion", editor: "input", autoColumns:true},
            {title: "Cotización", field: "cotizacion", editor: "input"},
            {title: "Precio Unitario", field: "precio_unitario", editor: "number", validator: ["required", "numeric"], formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", width:130},
            {title: "Cantidad", field: "cantidad", editor: "number", validator: ["required", "numeric"]},
            {title: "Total", field: "total", formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", 
            bottomCalc: "sum",
            bottomCalcFormatter: "money",
            bottomCalcFormatterParams: {
                symbol: " $",
                symbolAfter: true,
                thousand: ".",
                decimal: ",",
                precision: 2
            }},
            {title: "Flete", field: "flete_estimado", editor: "number", validator: ["required", "numeric"], formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right"},
            {title: "Tipo de Compra", field: "tipo_compra", editor: "select", editorParams: {
                values: ["FOB", "Local", "DMC"]
            }, width:100},
            {title: "Gravamen %", field: "gravamen", editor: "number", align:"center", width: 120},
            {title: "Otros Gastos", field: "otros_gastos", editor: "number", validator: ["required", "numeric"], formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right"},
            {title: "Margen %", field: "margen", editor: "number", align:"center", width:120},
            {title: "Precio Venta USD", field: "precio_usd", visible: vistaPrecio === "usd", align: "right", formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", width:150},
            {title: "Total USD", field: "total_usd", visible: vistaPrecio === "usd", align: "right", formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", 
            bottomCalc: "sum",
            bottomCalcFormatter: "money",
            bottomCalcFormatterParams: {
                symbol: " $",
                symbolAfter: true,
                thousand: ".",
                decimal: ",",
                precision: 2
            }, width: 150},


            {title: "Precio USD Bolivia", field: "precio_usd_bo", visible: vistaPrecio === "usdBo", align: "right", formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", width:150},
            {title: "Total USD Bolivia", field: "total_usd_bo", visible: vistaPrecio === "usdBo", align: "right", formatter: "money", formatterParams: {symbol: " $", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", 
            bottomCalc: "sum",
            bottomCalcFormatter: "money",
            bottomCalcFormatterParams: {
                symbol: " $",
                symbolAfter: true,
                thousand: ".",
                decimal: ",",
                precision: 2
            }, width: 150},


            {title: "Precio Venta Hoy", field: "precio_venta_hoy", visible: vistaPrecio === "hoy", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", width:150},
            {title: "Total Hoy", field: "total_hoy", visible: vistaPrecio === "hoy", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", 
            bottomCalc: "sum",
            bottomCalcFormatter: "money",
            bottomCalcFormatterParams: {
                symbol: " Bs.",
                symbolAfter: true,
                thousand: ".",
                decimal: ",",
                precision: 2
            },  width: 150},
            {title: "Precio Venta 30", field: "precio_venta30", visible: vistaPrecio === "30", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", width:150},
            {title: "Total a 30", field: "total_30", visible: vistaPrecio === "30", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", 
            bottomCalc: "sum",
            bottomCalcFormatter: "money",
            bottomCalcFormatterParams: {
                symbol: " Bs.",
                symbolAfter: true,
                thousand: ".",
                decimal: ",",
                precision: 2
            }, width:150},
            {title: "Precio Venta 60", field: "precio_venta60", visible: vistaPrecio === "60", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign:"right", width:150},
            {title: "Total a 60", field: "total_60", visible: vistaPrecio === "60", formatter: "money", formatterParams: {symbol: " Bs", symbolAfter: true, thousand: ".", decimal: ",", precision: 2}, hozAlign: "right", 
            bottomCalc: "sum",
            bottomCalcFormatter: "money",
            bottomCalcFormatterParams: {
                symbol: " Bs.",
                symbolAfter: true,
                thousand: ".",
                decimal: ",",
                precision: 2
            }, width: 150},
            {title: "Acciones", formatter: "buttonCross", align: "center", 
             cellClick: function(e, cell) {
                const row = cell.getRow();
                const data = row.getData();

                if (data.id_item) {
                    if (confirm("¿Estás seguro de eliminar este item?")) {
                        fetch('eliminar_item.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ id_item: data.id_item })
                        })
                        .then(response => response.json())
                        .then(res => {
                            if (res.success) {
                                row.delete();
                            } else {
                                alert("Error al eliminar: " + (res.message || 'Desconocido'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert("Error al eliminar el item");
                        });
                    }
                } else {
                    row.delete();
                }
            }, width:90
            
            }
        ],

        data: <?= json_encode($items) ?>,
        cellEdited: function(cell) {
            const row = cell.getRow();
            const data = row.getData();

        }

    });

    document.getElementById("venta-selector").addEventListener("change", function() {
        const value = this.value;

        // Ocultar todos los precios y totales
        table.getColumn("precio_usd").hide();
        table.getColumn("total_usd").hide();
        table.getColumn("precio_usd_bo").hide();
        table.getColumn("total_usd_bo").hide();
        table.getColumn("precio_venta_hoy").hide();
        table.getColumn("total_hoy").hide();
        table.getColumn("precio_venta30").hide();
        table.getColumn("total_30").hide();
        table.getColumn("precio_venta60").hide();
        table.getColumn("total_60").hide();

        // Mostrar según la opción seleccionada
        if(value === "usd") {
            table.getColumn("precio_usd").show();
            table.getColumn("total_usd").show();
        }
        if(value === "usdBo") {
            table.getColumn("precio_usd_bo").show();
            table.getColumn("total_usd_bo").show();
        }
        if(value === "hoy") {
            table.getColumn("precio_venta_hoy").show();
            table.getColumn("total_hoy").show();
        }
        if(value === "30") {
            table.getColumn("precio_venta30").show();
            table.getColumn("total_30").show();
        }
        if(value === "60") {
            table.getColumn("precio_venta60").show();
            table.getColumn("total_60").show();
        }
    });

    document.getElementById('venta-selector').addEventListener('change', function() {
        const vista = this.value;
        const id_proyecto = <?= $id_proyecto ?>;

        fetch('vista_precio.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id_proyecto: id_proyecto, vista: vista})
        });
    
    });

    
    // Botón para agregar fila
    document.getElementById("add-row").addEventListener("click", function() {
        table.addRow({}, true);
    });

    // Botón para guardar todos los items
    document.getElementById("save-all").addEventListener("click", function() {
        const allData = table.getData();
        const id_proyecto = <?= $id_proyecto ?>;

        const cleanData = allData.map(item => {
            Object.keys(item).forEach(key => {
                if (item[key] === undefined || item[key] === null) item[key] = "";
            });
            return item;
        });

        fetch('guardar_items.php?id_proyecto=<?= $id_proyecto ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(cleanData)
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Error desconocido al guardar');
            }
            location.reload();
        })
        .catch(error => {
            console.error('Error al guardar:', error);
            alert("Error al guardar: " + error.message);
        });


    });

     document.querySelector("form").addEventListener("submit", function () {
        const input = document.getElementById("monto_adjudicado");
        input.value = input.value
            .replace(/\./g, '')   // quita miles
            .replace(',', '.');   // coma → punto
    });
    </script>
    <?php
        $archivoExcel = "/mnt/files/adjuntos_presupuestos/{$anio['anio']}/hoja_{$numero_proyecto}.xlsx";


        if (!file_exists($archivoExcel)) {
            copy("/mnt/files/adjuntos_presupuestos/hoja_.xlsx", $archivoExcel);
        }

        $keyDocumento = md5($numero_proyecto . time());

        require 'vendor/autoload.php';
        use Firebase\JWT\JWT;

        $documentoUrl = "https://calc.fils.bo/documentos/adjuntos_presupuestos/{$anio['anio']}/hoja_{$numero_proyecto}.xlsx";

        $payload = [
            "document" => [
                "fileType" => "xlsx",
                "key" => $keyDocumento,
                "title" => "hoja_{$numero_proyecto}.xlsx",
                "url" => $documentoUrl
            ],
            "documentType" => "cell",
            "editorConfig" => [
                "callbackUrl" => "https://calc.fils.bo/excel.php?numero_proyecto={$numero_proyecto}",
                "user" => [
                    "id" => $_SESSION['usuario']['id'],
                    "name" => $_SESSION['usuario']['nombre']
                ]
            ]
        ];

        //$token = JWT::encode($payload, 'IyawrmF4wLur9tJhehaieI4Vi6ALTsTO', 'HS256');
    ?>


    <button id="open-editor" class="btn">Calculos Auxiliares</button>
    <div id="editor-container" style="display:none;">
        <div id="editor" style="width: 100%; height: 800px; min-height: 800px;"></div>
    </div>

    
    <script type="text/javascript" src="https://only.fils.bo/web-apps/apps/api/documents/api.js"></script>


    <script>
    document.getElementById('open-editor').addEventListener('click', function() {
        const container = document.getElementById('editor-container');
        const editorDiv = document.getElementById('editor');

        if (container.style.display === 'none') {
            container.style.display = 'block';
            editorDiv.style.height = '800px';  

            const docEditor = new DocsAPI.DocEditor("editor", {
                height: "800px",
                token: "<?= $token ?>",
                document: {
                    fileType: "xlsx",
                    key: "<?= $keyDocumento ?>",
                    title: "hoja_<?= $numero_proyecto ?>.xlsx",
                    url: "<?= $documentoUrl ?>"
                },
                documentType: "cell",
                editorConfig: {
                    callbackUrl: "https://calc.fils.bo/excel.php?numero_proyecto=<?= $numero_proyecto?>",
                    user: {
                        id: "<?= $_SESSION['usuario']['id'] ?>",
                        name: "<?= $_SESSION['usuario']['nombre'] ?>"
                    }
                }
            });
        } else {
            container.style.display = 'none';
        }
    });

  </script>
</body>
</html>