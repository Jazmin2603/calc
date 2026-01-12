<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['filtros_presupuestos'] = [
        'sucursal' => $_GET['sucursal'] ?? null,
        'usuario' => $_GET['usuario'] ?? null,
        'estado_finanzas' => $_GET['estado_finanzas'] ?? null,
        'buscar' => $_GET['buscar'] ?? '',
        'pagina' => $_GET['pagina'] ?? 1
    ];
}

$filtro_sucursal = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : ($_SESSION['filtros_presupuestos']['sucursal'] ?? null);
$filtro_usuario = isset($_GET['usuario']) ? intval($_GET['usuario']) : ($_SESSION['filtros_presupuestos']['usuario'] ?? null);
$filtro_estado_finanzas = isset($_GET['estado_finanzas']) ? intval($_GET['estado_finanzas']) : ($_SESSION['filtros_presupuestos']['estado_finanzas'] ?? null);
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : ($_SESSION['filtros_presupuestos']['buscar'] ?? '');

$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : ($_SESSION['filtros_presupuestos']['pagina'] ?? 1);
$por_pagina = 10;
$offset = ($pagina_actual - 1) * $por_pagina;

$conditions = [];
$params = [];

// Query base CORREGIDO (sin punto y coma)
$query_base = "FROM proyecto_financiero pf 
               LEFT JOIN proyecto p ON pf.presupuesto_id = p.id_proyecto
               JOIN usuarios u ON pf.id_usuario = u.id 
               JOIN sucursales s ON pf.sucursal_id = s.id 
               JOIN estado_finanzas ef ON pf.estado_id = ef.id";

// Filtros por rol
if ($_SESSION['usuario']['rol'] == ROL_GERENTE) {
    if ($_SESSION['usuario']['sucursal_id'] == 1) {
        if ($filtro_sucursal && $filtro_sucursal != 1) {
            $conditions[] = "pf.sucursal_id = ?";
            $params[] = $filtro_sucursal;
        }
        if ($filtro_usuario) {
            $conditions[] = "pf.id_usuario = ?";
            $params[] = $filtro_usuario;
        }
    } else {
        $conditions[] = "pf.sucursal_id = ?";
        $params[] = $_SESSION['usuario']['sucursal_id'];

        if ($filtro_usuario) {
            $conditions[] = "pf.id_usuario = ?";
            $params[] = $filtro_usuario;
        }
    }
} elseif ($_SESSION['usuario']['rol'] == ROL_VENDEDOR) {
    $conditions[] = "pf.id_usuario = ?";
    $params[] = $_SESSION['usuario']['id'];
}

if ($filtro_estado_finanzas) {
    $conditions[] = "pf.estado_id = ?";
    $params[] = $filtro_estado_finanzas;
}

// Búsqueda CORREGIDA - buscar en ambos: pf y p
if (!empty($busqueda)) {
    $conditions[] = "(COALESCE(pf.titulo, p.titulo) LIKE ? OR COALESCE(pf.cliente, p.cliente) LIKE ? OR pf.numero_proyectoF LIKE ? OR p.numero_proyecto LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$where_clause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

// Contar total
$total_query = "SELECT COUNT(DISTINCT pf.id) " . $query_base . $where_clause;
$stmt_count = $conn->prepare($total_query);
$stmt_count->execute($params);
$total_resultados = $stmt_count->fetchColumn();
$total_paginas = ceil($total_resultados / $por_pagina);

// Query principal CORREGIDA con COALESCE
$query = "SELECT pf.*, 
                 COALESCE(pf.titulo, p.titulo) as titulo_mostrar,
                 COALESCE(pf.cliente, p.cliente) as cliente_mostrar,
                 p.numero_proyecto as numero_proyecto_original, 
                 u.nombre as nombre_usuario, s.nombre as sucursal_nombre, 
                 ef.estado as estado_financiero
          " . $query_base . $where_clause . "
          ORDER BY pf.fecha_inicio DESC, pf.numero_proyectoF DESC 
          LIMIT $por_pagina OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$proyectos_financieros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener sucursales y usuarios para filtros
$sucursales = [];
$usuarios = [];
if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1) {
    $stmt = $conn->query("SELECT * FROM sucursales WHERE id != 1 ORDER BY nombre");
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filtro_sucursal) {
        $stmt = $conn->prepare("SELECT DISTINCT u.id, u.nombre 
                               FROM usuarios u 
                               JOIN proyecto_financiero pf ON pf.id_usuario = u.id 
                               WHERE pf.sucursal_id = ? 
                               ORDER BY u.nombre");
        $stmt->execute([$filtro_sucursal]);
    } else {
        $stmt = $conn->query("SELECT DISTINCT u.id, u.nombre 
                             FROM usuarios u 
                             JOIN proyecto_financiero pf ON pf.id_usuario = u.id 
                             ORDER BY u.nombre");
    }
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($_SESSION['usuario']['rol'] == ROL_GERENTE) {
    $stmt = $conn->prepare("SELECT DISTINCT u.id, u.nombre 
                           FROM usuarios u 
                           JOIN proyecto_financiero pf ON pf.id_usuario = u.id 
                           WHERE pf.sucursal_id = ? 
                           ORDER BY u.nombre");
    $stmt->execute([$_SESSION['usuario']['sucursal_id']]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener estados financieros
$stmt = $conn->query("SELECT * FROM estado_finanzas ORDER BY estado");
$estados_finanzas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener proyectos ganados para el modal
$proyectos_ganados = [];
if ($_SESSION['usuario']['rol'] == ROL_GERENTE) {
    $query_ganados = "SELECT p.id_proyecto, p.numero_proyecto, p.titulo, p.cliente 
                     FROM proyecto p 
                     JOIN estados e ON p.estado_id = e.id
                     WHERE e.estado IN ('Ganado', 'Aprobado')
                     AND p.id_proyecto NOT IN (SELECT presupuesto_id FROM proyecto_financiero WHERE presupuesto_id IS NOT NULL)";
    
    if ($_SESSION['usuario']['sucursal_id'] != 1) {
        $query_ganados .= " AND p.sucursal_id = ?";
        $stmt = $conn->prepare($query_ganados . " ORDER BY p.numero_proyecto DESC");
        $stmt->execute([$_SESSION['usuario']['sucursal_id']]);
    } else {
        $stmt = $conn->prepare($query_ganados . " ORDER BY p.numero_proyecto DESC");
        $stmt->execute();
    }
    $proyectos_ganados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Lógica de paginación
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
    <title>Proyectos Financieros</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .btn-modal {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-primary {
            background-color: #34a44c;
            color: white;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .proyecto-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
        }

        .proyecto-item {
            padding: 8px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
        }

        .proyecto-item:hover {
            background-color: #f8f9fa;
        }

        .proyecto-item.selected {
            background-color: #34a44c;
            color: white;
        }

        .hidden {
            display: none;
        }

        .paginacion {
            margin-top: 20px;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .paginacion a {
            padding: 8px 12px;
            background-color: #fff;
            text-decoration: none;
            color: #34a44c;
            border: 1px solid #ddd;
            border-radius: 4px;
            transition: all 0.2s ease;
            min-width: 40px;
            text-align: center;
        }

        .paginacion a:hover:not(.pagina-activa) {
            background-color: #f0f0f0;
            border-color: #34a44c;
        }

        .paginacion a.pagina-activa {
            font-weight: bold;
            background-color: #34a44c;
            color: white;
            border-color: #34a44c;
        }

        .paginacion-control {
            font-weight: bold;
            font-size: 16px;
        }

        .paginacion-puntos {
            padding: 8px 4px;
            color: #666;
        }

        .paginacion-info {
            margin-left: 15px;
            padding: 8px 12px;
            color: #666;
            font-size: 14px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<div class="container"> 
    <header>
        <img src="assets/logo.png" class="logo">
        <h1>Proyectos Financieros</h1>
        <div>
            <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE): ?>
                <a href="#" onclick="mostrarModal(); return false;" class="btn">Nuevo Proyecto</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
        </div>
    </header>

    <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE): ?>
    <div id="modalNuevoProyecto" class="modal">
        <div class="modal-content">
            <h3>Crear Nuevo Proyecto Financiero</h3>
            <p>¿Cómo deseas crear el proyecto financiero?</p>
            
            <div class="modal-buttons">
                <button type="button" onclick="seleccionarTipo('con_proyecto')" class="btn-modal btn-primary">
                    Con Proyecto Existente
                </button>
                <button type="button" onclick="seleccionarTipo('sin_proyecto')" class="btn-modal btn-secondary">
                    Sin Proyecto
                </button>
                <button type="button" onclick="cerrarModal()" class="btn-modal">Cancelar</button>
            </div>

            <div id="formConProyecto" class="hidden">
                <h4>Seleccionar Proyecto Ganado</h4>
                <div class="proyecto-list">
                    <?php if (empty($proyectos_ganados)): ?>
                        <p>No hay proyectos ganados disponibles</p>
                    <?php else: ?>
                        <?php foreach ($proyectos_ganados as $proyecto): ?>
                            <div class="proyecto-item" onclick="seleccionarProyecto(<?= $proyecto['id_proyecto'] ?>, this)">
                                <strong>#<?= $proyecto['numero_proyecto'] ?></strong> - 
                                <?= htmlspecialchars($proyecto['titulo']) ?> - 
                                <?= htmlspecialchars($proyecto['cliente']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="crearProyectoConProyecto()" class="btn-modal btn-primary" disabled id="btnCrearConProyecto">
                        Crear Proyecto Financiero
                    </button>
                    <button type="button" onclick="volverSeleccion()" class="btn-modal">Volver</button>
                </div>
            </div>

            <div id="formSinProyecto" class="hidden">
                <h4>Crear Proyecto Financiero Independiente</h4>
                <p>Este proyecto financiero no estará vinculado a ningún proyecto existente.</p>
                <div class="modal-buttons">
                    <button type="button" onclick="crearProyectoSinProyecto()" class="btn-modal btn-primary">
                        Crear Proyecto Financiero
                    </button>
                    <button type="button" onclick="volverSeleccion()" class="btn-modal">Volver</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-rowf">
        <form method="get" action="" class="filtro-form">
            <div class="filtros-izquierda">
                <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                    <select name="sucursal" onchange="this.form.submit()">
                        <option value="">Todas las sucursales</option>
                        <?php foreach ($sucursales as $sucursal): ?>
                            <option value="<?= $sucursal['id'] ?>" <?= $filtro_sucursal == $sucursal['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sucursal['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE): ?>
                    <select name="usuario" onchange="this.form.submit()">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= $usuario['id'] ?>" <?= $filtro_usuario == $usuario['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usuario['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

            </div>

            <div class="filtro-derecha">
                <select name="estado_finanzas" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estados_finanzas as $estado): ?>
                        <option value="<?= $estado['id'] ?>" <?= $filtro_estado_finanzas == $estado['id'] ? 'selected' : '' ?>>
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
                <th>N° Financiero</th>
                <th>Título</th>
                <th>Cliente</th>
                <th>Fecha Inicio</th>
                <th>Usuario</th>
                <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                    <th>Sucursal</th>
                <?php endif; ?>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla-proyectos">
            <?php if (empty($proyectos_financieros)): ?>
                <tr>
                    <td colspan="<?= ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1) ? '9' : '8' ?>" style="text-align: center;">
                        No se encontraron proyectos financieros
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($proyectos_financieros as $proyecto): ?>
                    <tr>
                        <td><?= $proyecto['numero_proyectoF'] ?></td>
                        <td><?= htmlspecialchars($proyecto['titulo_mostrar'] ?? 'Sin título') ?></td>
                        <td><?= htmlspecialchars($proyecto['cliente_mostrar'] ?? 'Sin cliente') ?></td>
                        <td><?= date('d/m/Y', strtotime($proyecto['fecha_inicio'])) ?></td>
                        <td><?= htmlspecialchars($proyecto['nombre_usuario']) ?></td>
                        <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                            <td><?= htmlspecialchars($proyecto['sucursal_nombre']) ?></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($proyecto['estado_financiero']) ?></td>
                        <td>
                            <a href="proyecto_financiero.php?id=<?= $proyecto['id'] ?>" class="btn-view">Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_paginas > 1): ?>
    <div id="contenedor-paginacion" class="paginacion">
        <?php if ($pagina_actual > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => 1])) ?>" class="paginacion-control" title="Primera página">««</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])) ?>" class="paginacion-control" title="Anterior">«</a>
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
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])) ?>" class="paginacion-control" title="Siguiente">»</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $total_paginas])) ?>" class="paginacion-control" title="Última página">»»</a>
        <?php endif; ?>

        <span class="paginacion-info">Página <?= $pagina_actual ?> de <?= $total_paginas ?></span>
    </div>
    <?php endif; ?>

</div>

<script>
let proyectoSeleccionado = null;

function mostrarModal() {
    document.getElementById('modalNuevoProyecto').style.display = 'block';
}

function cerrarModal() {
    document.getElementById('modalNuevoProyecto').style.display = 'none';
    resetModal();
}

function seleccionarTipo(tipo) {
    if (tipo === 'con_proyecto') {
        document.getElementById('formConProyecto').classList.remove('hidden');
    } else {
        document.getElementById('formSinProyecto').classList.remove('hidden');
    }
    document.querySelectorAll('.modal-buttons')[0].classList.add('hidden');
}

function volverSeleccion() {
    document.querySelectorAll('.modal-buttons')[0].classList.remove('hidden');
    document.getElementById('formConProyecto').classList.add('hidden');
    document.getElementById('formSinProyecto').classList.add('hidden');
    proyectoSeleccionado = null;
    document.querySelectorAll('.proyecto-item').forEach(item => {
        item.classList.remove('selected');
    });
    if (document.getElementById('btnCrearConProyecto')) {
        document.getElementById('btnCrearConProyecto').disabled = true;
    }
}

function seleccionarProyecto(id, element) {
    proyectoSeleccionado = id;
    document.querySelectorAll('.proyecto-item').forEach(item => {
        item.classList.remove('selected');
    });
    element.classList.add('selected');
    document.getElementById('btnCrearConProyecto').disabled = false;
}

function crearProyectoConProyecto() {
    if (proyectoSeleccionado) {
        window.location.href = 'crear_proyecto_financiero.php?proyecto_id=' + proyectoSeleccionado;
    }
}

function crearProyectoSinProyecto() {
    window.location.href = 'crear_proyecto_financiero.php';
}

function resetModal() {
    volverSeleccion();
}

window.onclick = function(event) {
    const modal = document.getElementById('modalNuevoProyecto');
    if (event.target == modal) {
        cerrarModal();
    }
}
</script>

</body>
</html>