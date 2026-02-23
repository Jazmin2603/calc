<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("presupuestos", "ver");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['filtros_presupuestos'] = [
        'sucursal' => $_GET['sucursal'] ?? null,
        'usuario' => $_GET['usuario'] ?? null,
        'estado' => $_GET['estado'] ?? null,
        'buscar' => $_GET['buscar'] ?? '',
        'pagina' => $_GET['pagina'] ?? 1
    ];
}

$filtro_sucursal = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : ($_SESSION['filtros_presupuestos']['sucursal'] ?? null);
$filtro_usuario = isset($_GET['usuario']) ? intval($_GET['usuario']) : ($_SESSION['filtros_presupuestos']['usuario'] ?? null);
$filtro_estado = isset($_GET['estado']) ? intval($_GET['estado']) : ($_SESSION['filtros_presupuestos']['estado'] ?? null);
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : ($_SESSION['filtros_presupuestos']['buscar'] ?? '');

$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : ($_SESSION['filtros_presupuestos']['pagina'] ?? 1);
$por_pagina = 50;
$offset = ($pagina_actual - 1) * $por_pagina;

$conditions = [];
$params = [];

$query_base = "FROM proyecto p
JOIN usuarios u ON p.id_usuario = u.id
JOIN sucursales s ON p.sucursal_id = s.id
JOIN estados e ON p.estado_id = e.id
LEFT JOIN items i ON p.id_proyecto = i.id_proyecto";

if (esGerente() || esSuperusuario()) {
    if (esSuperusuario()) {
        if ($filtro_sucursal && $filtro_sucursal != 1) {
            $conditions[] = "p.sucursal_id = ?";
            $params[] = $filtro_sucursal;
        }
        if ($filtro_usuario) {
            $conditions[] = "p.id_usuario = ?";
            $params[] = $filtro_usuario;
        }
    } else {
        $conditions[] = "p.sucursal_id = ?";
        $params[] = $_SESSION['usuario']['sucursal_id'];

        if ($filtro_usuario) {
            $conditions[] = "p.id_usuario = ?";
            $params[] = $filtro_usuario;
        }
    }
}
if ($_SESSION['usuario']['rol_id'] == 3) {
    $conditions[] = "p.id_usuario = ?";
    $params[] = $_SESSION['usuario']['id'];
}

if ($filtro_estado) {
    $conditions[] = "p.estado_id = ?";
    $params[] = $filtro_estado;
}

if (!empty($busqueda)) {
    $conditions[] = "(p.titulo LIKE ? OR p.cliente LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$where_clause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

$total_query = "
    SELECT COUNT(*) FROM (
        SELECT p.id_proyecto
        $query_base
        $where_clause
        GROUP BY p.id_proyecto
    ) AS total
";

$stmt_count = $conn->prepare($total_query);
$stmt_count->execute($params);
$total_resultados = $stmt_count->fetchColumn();
$total_paginas = ceil($total_resultados / $por_pagina);

$query = "SELECT p.*, u.nombre AS nombre_usuario, s.nombre AS sucursal_nombre, e.estado AS estado, COALESCE(SUM(i.total_hoy), 0) AS monto_total $query_base $where_clause
GROUP BY p.id_proyecto, u.nombre, s.nombre, e.estado ORDER BY p.numero_proyecto DESC LIMIT $por_pagina OFFSET $offset ";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sucursales = [];
$usuarios = [];
if (esSuperusuario()) {
    $stmt = $conn->query("SELECT * FROM sucursales WHERE id != 1");
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filtro_sucursal) {
        $stmt = $conn->prepare("SELECT u.id, u.nombre FROM usuarios u INNER JOIN proyecto p ON p.id_usuario = u.id INNER JOIN sucursales s ON p.sucursal_id = s.id WHERE p.sucursal_id = ? GROUP BY u.id");
        $stmt->execute([$filtro_sucursal]);
    } else {
        $stmt = $conn->query("SELECT * FROM usuarios");
    }
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $conn->query("SELECT * FROM estados");
$estados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rango_paginas = 5;
$inicio_rango = max(1, $pagina_actual - floor($rango_paginas / 2));
$fin_rango = min($total_paginas, $inicio_rango + $rango_paginas - 1);

if ($fin_rango - $inicio_rango < $rango_paginas - 1) {
    $inicio_rango = max(1, $fin_rango - $rango_paginas + 1);
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de Presupuestos</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
    /* --- VARIABLES Y BASES --- */
    :root {
        --primary-color: #34a44c;
        --secondary-color: #2c3e50;
        --bg-light: #f8f9fa;
        --shadow: 0 4px 15px rgba(0,0,0,0.08);
    }

    /* --- CONTENEDOR PRINCIPAL --- */
    .container {
        max-width: 1300px;
        margin: 20px auto;
        padding: 25px;
        background: #fff;
        border-radius: 12px;
        box-shadow: var(--shadow);
    }

    .filtros-container {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: center;
        border: 1px solid #edf2f7;
    }

    .filtros-container select, 
    .filtros-container input[type="text"] {
        padding: 10px 15px;
        border-radius: 8px;
        border: 1px solid #d1d5db;
        background-color: #ffffff;
        color: #4b5563;
        font-size: 0.9rem;
        outline: none;
        transition: all 0.2s ease;
    }

    .filtros-container select:focus, 
    .filtros-container input[type="text"]:focus {
        border-color: #34a44c;
        box-shadow: 0 0 0 3px rgba(52, 164, 76, 0.15);
    }

    .filtros-container input[type="text"] {
        flex-grow: 1;
        min-width: 250px;
    }

    .filtros-container label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #6b7280;
        margin-right: 5px;
    }

    @media (max-width: 768px) {
        .filtros-container {
            flex-direction: column;
            align-items: stretch;
        }
        .filtros-container input[type="text"] {
            min-width: unset;
        }
    }

    /* --- TABLA MODERNA --- */
    .proyectos-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px; 
        margin-top: 10px;
    }

    .proyectos-table thead th {
        background: transparent;
        color: #7f8c8d;
        text-transform: uppercase;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 12px 15px;
        border: none;
    }

    .proyectos-table tbody tr {
        background-color: white;
        transition: transform 0.2s;
    }

    .proyectos-table tbody tr:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }

    .proyectos-table td {
        padding: 18px 15px;
        border-top: 1px solid #f0f0f0;
        border-bottom: 1px solid #f0f0f0;
    }

    .proyectos-table td:first-child { border-left: 1px solid #f0f0f0; border-radius: 10px 0 0 10px; }
    .proyectos-table td:last-child { border-right: 1px solid #f0f0f0; border-radius: 0 10px 10px 0; }

    .estado-selector {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid #eee;
        background: #f8f9fa;
    }

    .paginacion {
        margin-top: 30px;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
    }

    .paginacion a {
        padding: 10px 16px;
        background-color: #fff;
        text-decoration: none;
        color: var(--secondary-color);
        border: 1px solid #ddd;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .paginacion a:hover:not(.pagina-activa) {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }

    .paginacion a.pagina-activa {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
        font-weight: bold;
    }

    .paginacion-info {
        margin-left: 20px;
        font-size: 0.9rem;
        color: #7f8c8d;
    }
    .tag-sucursal {
        display: inline-block;
        padding: 4px 12px;
        background: #e3f2fd;
        color: #1976d2;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    /* --- RESPONSIVE --- */
    @media (max-width: 768px) {
        .filtro-form { flex-direction: column; align-items: stretch; }
        #buscador { width: 100% !important; }
        .proyectos-table { font-size: 14px; }
    }
</style>
</head>
<body>

<div class="container"> 
    <header>
        <img src="assets/logo.png" class="logo">
        <h1>Presupuestos</h1>
        <div class="header-buttons">
            <a href="crear_proyecto.php" class="btn">Nuevo Presupuesto</a>
            <a href="dashboard.php" class="btn secondary">Volver al Dashboard</a>
        </div>
    </header>

    <div class="filtros-container">
        <form method="get" action="" class="filtro-form">
            <div class="filtros-izquierda">
                <?php if (esSuperusuario()): ?>
                    <select name="sucursal" id="selector" onchange="this.form.submit()">
                        <option value="">Todas las sucursales</option>
                        <?php foreach ($sucursales as $sucursal): ?>
                            <option value="<?= $sucursal['id'] ?>" <?= $filtro_sucursal == $sucursal['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sucursal['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="usuario" id="selector" onchange="this.form.submit()">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= $usuario['id'] ?>" <?= $filtro_usuario == $usuario['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usuario['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif (esGerente() || esSuperusuario()): ?>
                    <select name="usuario" id="selector" onchange="this.form.submit()">
                        <option value="">Todos los usuarios</option>
                        <?php
                            $stmt = $conn->prepare("SELECT u.id, u.nombre FROM usuarios u 
                                                    JOIN proyecto p ON p.id_usuario = u.id 
                                                    WHERE p.sucursal_id = ? GROUP BY u.id");
                            $stmt->execute([$_SESSION['usuario']['sucursal_id']]);
                            $usuarios_sucursal = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($usuarios_sucursal as $usuario):
                        ?>
                            <option value="<?= $usuario['id'] ?>" <?= $filtro_usuario == $usuario['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usuario['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <input type="text" id="buscador" placeholder="Buscar por título o cliente..." autocomplete="off" value="<?= htmlspecialchars($busqueda) ?>">
            </div>

            <div class="filtro-derecha">
                <select name="estado" id="selector" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estados as $estado): ?>
                        <option value="<?= $estado['id'] ?>" <?= $filtro_estado == $estado['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($estado['estado']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <table class="proyectos-table">
    <thead>
        <tr>
            <th>Nº</th>
            <th>Proyecto / Cliente</th>
            <th>Fecha</th>
            <th>Responsable</th>
            <?php if (esSuperusuario()): ?>
                <th>Sucursal</th>
            <?php endif; ?>
            <th>Estado</th>
            <th style="text-align:right;">Monto</th>
            <th style="text-align: center;">Acciones</th>
        </tr>
    </thead>
    <tbody id="tabla-proyectos">
        <?php foreach ($proyectos as $proyecto): ?>
            <tr>
                <td style="font-weight: bold; color: #34a44c;">#<?= $proyecto['numero_proyecto'] ?></td>
                <td>
                    <div style="font-weight: 600; color: #2c3e50;"><?= htmlspecialchars($proyecto['titulo']) ?></div>
                    <div style="font-size: 0.8rem; color: #7f8c8d;"><?= htmlspecialchars($proyecto['cliente']) ?></div>
                </td>
                <td><?= date('d/m/Y', strtotime($proyecto['fecha_proyecto'])) ?></td>
                <td><?= htmlspecialchars($proyecto['nombre_usuario']) ?></td>
                
                <?php if (esSuperusuario()): ?>
                    <td><span class="tag-sucursal"><?= htmlspecialchars($proyecto['sucursal_nombre']) ?></span></td>
                <?php endif; ?>

                <td>
                    <?php if (($proyecto['id_usuario'] == $_SESSION['usuario']['id']) || esSuperusuario()): ?>
                        <?php if($proyecto['estado_id'] != 2): ?>
                            <form method="post" action="cambiar_estado.php">
                                <input type="hidden" name="id_proyecto" value="<?= $proyecto['id_proyecto'] ?>">
                                <select name="estado_id" onchange="verificarEstado(this)" class="estado-selector">
                                    <?php foreach ($estados as $estado): ?>
                                        <option value="<?= $estado['id'] ?>" <?= $estado['estado'] == $proyecto['estado'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($estado['estado']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else:?>
                            <span class="estado-selector"><?= htmlspecialchars($proyecto['estado']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="estado-selector"><?= htmlspecialchars($proyecto['estado']) ?></span>
                    <?php endif; ?>
                </td>
                <?php 
                    $proyectoTotal = 0;
                    if($proyecto['monto_adjudicado'] == 0){
                        $proyectoTotal = $proyecto['monto_total'];
                    } else {
                        $proyectoTotal = $proyecto['monto_adjudicado'];
                    }
                ?>
                <td style="text-align:right; color:#2c3e50; min-width:120px;">
                    Bs <?= number_format($proyectoTotal, 2, ',', '.') ?>
                </td>

                <td style="text-align: center;">
                    <a href="ver_proyecto.php?id=<?= $proyecto['id_proyecto'] ?>&<?= http_build_query(array_filter(['sucursal' => $filtro_sucursal, 'usuario' => $filtro_usuario, 'estado' => $filtro_estado, 'buscar' => $busqueda, 'pagina' => $pagina_actual])) ?>" 
                       class="btn" style="padding: 5px 15px;">
                       Abrir
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

    <?php if ($total_paginas > 1): ?>
    <div id="contenedor-paginacion" class="paginacion">
        <?php if ($pagina_actual > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => 1])) ?>" class="paginacion-control" title="Primera página">
                ««
            </a>
        <?php endif; ?>

        <?php if ($pagina_actual > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])) ?>" class="paginacion-control" title="Página anterior">
                «
            </a>
        <?php endif; ?>

        <?php if ($inicio_rango > 1): ?>
            <span class="paginacion-puntos">...</span>
        <?php endif; ?>

        <?php for ($i = $inicio_rango; $i <= $fin_rango; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>" 
               class="<?= $i == $pagina_actual ? 'pagina-activa' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($fin_rango < $total_paginas): ?>
            <span class="paginacion-puntos">...</span>
        <?php endif; ?>

        <?php if ($pagina_actual < $total_paginas): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])) ?>" class="paginacion-control" title="Página siguiente">
                »
            </a>
        <?php endif; ?>

        <?php if ($pagina_actual < $total_paginas): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $total_paginas])) ?>" class="paginacion-control" title="Última página">
                »»
            </a>
        <?php endif; ?>

        <span class="paginacion-info">
            Página <?= $pagina_actual ?> de <?= $total_paginas ?>
        </span>
    </div>
    <?php endif; ?>

</div>

<div id="modalGanado" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; width:400px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); text-align:center;">
        <i class="fa-solid fa-award fa-2xl" style="color:#3b6de3;"></i>
        <h2 style="color:#34a44c;">¡Felicidades!</h2>
        <p style="margin-bottom:20px;">Anota el Monto Adjudicado para este proyecto.</p>
        
        <form id="formMontoAdjudicado">
            <input type="hidden" id="modal_id_proyecto">
            <input type="hidden" id="modal_estado_id">
            
            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:bold; margin-bottom:10px;">Monto (Bs):</label>
                <input type="number" step="0.01" id="modal_monto" required 
                       style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; font-size:1.1rem;">
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:bold; margin-bottom:10px;">
                    Fecha de cierre:
                </label>
                <input type="date" id="modal_fecha_cierre" required
                    style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; font-size:1rem;">
            </div>

            
            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="submit" class="btn" style="background:#34a44c; color:white; border:none; margin-bottom:20px;">Guardar</button>
                <button type="button" class="btn secondary" style="border:none; margin-bottom:20px; margin-top:20px;" onclick="cerrarModal()">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const buscador = document.getElementById("buscador");
    const tablaProyectos = document.getElementById("tabla-proyectos");

    function buscarProyectos() {
        const valor = buscador.value;
        
        const urlParams = new URLSearchParams(window.location.search);
        
        const params = {
            buscar: valor,
            sucursal: urlParams.get('sucursal') || "",
            usuario: urlParams.get('usuario') || "",
            estado: urlParams.get('estado') || "",
            pagina: 1 
        };

        window.history.replaceState({}, '', '?' + new URLSearchParams(params).toString());

        const xhr = new XMLHttpRequest();
        xhr.open("GET", "buscar_proyectos.php?" + new URLSearchParams(params).toString(), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                tablaProyectos.innerHTML = xhr.responseText;
                document.querySelector(".paginacion").innerHTML = "";
            }
        };
        xhr.send();
    }

    buscador.addEventListener("keyup", function () {
        buscarProyectos();
    });
});

function verificarEstado(select) {
    const estadoTexto = select.options[select.selectedIndex].text.toLowerCase();
    const idProyecto = select.form.querySelector('input[name="id_proyecto"]').value;
    const idEstado = select.value;

    if (estadoTexto === "ganado") {
        document.getElementById('modalGanado').style.display = 'flex';
        document.getElementById('modal_id_proyecto').value = idProyecto;
        document.getElementById('modal_estado_id').value = idEstado;
        document.getElementById('modal_monto').focus();
        const hoy = new Date().toISOString().split('T')[0];
        document.getElementById('modal_fecha_cierre').value = hoy;
    } else {
        select.form.submit();
    }

}

function cerrarModal() {
    document.getElementById('modalGanado').style.display = 'none';
    location.reload();
}

document.getElementById('formMontoAdjudicado').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const data = {
        id_proyecto: document.getElementById('modal_id_proyecto').value,
        estado_id: document.getElementById('modal_estado_id').value,
        monto_adjudicado: document.getElementById('modal_monto').value,
        fecha_cierre: document.getElementById('modal_fecha_cierre').value
    };

    fetch('presupuesto_ganado.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(res => {
        if(res.success) {
            location.reload();
        } else {
            alert("Error: " + res.message);
        }
    });
});
</script>

</body>
</html>