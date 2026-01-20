<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("finanzas", "ver");

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
$por_pagina = 50;
$offset = ($pagina_actual - 1) * $por_pagina;

$conditions = [];
$params = [];

// Query base
$query_base = "FROM proyecto_financiero pf 
               LEFT JOIN proyecto p ON pf.presupuesto_id = p.id_proyecto
               JOIN usuarios u ON pf.id_usuario = u.id 
               JOIN sucursales s ON pf.sucursal_id = s.id 
               JOIN estado_finanzas ef ON pf.estado_id = ef.id";

// Filtros por rol
if ($_SESSION['usuario']['rol_id'] == 2 || esSuperusuario()) {
    if (esSuperusuario()) {
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
} elseif ($_SESSION['usuario']['rol_id'] == 4) {
    $conditions[] = "pf.id_usuario = ?";
    $params[] = $_SESSION['usuario']['id'];
}

if ($filtro_estado_finanzas) {
    $conditions[] = "pf.estado_id = ?";
    $params[] = $filtro_estado_finanzas;
}

// Búsqueda
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

// Query principal
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
if (esSuperusuario()) {
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
} elseif ($_SESSION['usuario']['rol_id'] == 2) {
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
if ($_SESSION['usuario']['rol_id'] == 2) {
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

        /* --- FILTROS --- */
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
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52,164,76,.15);
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

        .filtro-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            width: 100%;
        }

        .filtros-izquierda {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filtro-derecha {
            display: flex;
            gap: 15px;
            margin-left: auto;
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
            text-align: left;
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

        .proyectos-table td:first-child {
            border-left: 1px solid #f0f0f0;
            border-radius: 10px 0 0 10px;
            font-weight: bold;
            color: #34a44c;
        }

        .proyectos-table td:last-child {
            border-right: 1px solid #f0f0f0;
            border-radius: 0 10px 10px 0;
            text-align: center;
        }

        .estado-selector {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid #eee;
            background: #f8f9fa;
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

        /* --- PAGINACIÓN --- */
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

        .paginacion-puntos {
            padding: 0 5px;
            color: #7f8c8d;
        }

        /* --- MODAL --- */
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
            background-color: #fff;
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
        }

        .modal-content h3 {
            margin-top: 0;
            color: var(--secondary-color);
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn-modal {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-modal.btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-modal.btn-primary:hover {
            background-color: #2d8f42;
        }

        .btn-modal.btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-modal.btn-secondary:hover {
            background-color: #5a6268;
        }

        .hidden {
            display: none !important;
        }

        .proyecto-list {
            max-height: 300px;
            overflow-y: auto;
            margin: 15px 0;
            border: 1px solid #eee;
            border-radius: 8px;
        }

        .proyecto-item {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .proyecto-item:hover {
            background-color: #f8f9fa;
        }

        .proyecto-item.selected {
            background-color: #e8f5e9;
            border-left: 4px solid var(--primary-color);
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 768px) {
            .filtro-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filtros-izquierda,
            .filtro-derecha {
                flex-direction: column;
                width: 100%;
                margin-left: 0;
            }

            .filtros-container input[type="text"] {
                min-width: unset;
                width: 100%;
            }

            .proyectos-table {
                font-size: 14px;
            }

            .proyectos-table td {
                padding: 12px 10px;
            }
        }
    </style>
</head>
<body>

<div class="container"> 
    <header>
        <img src="assets/logo.png" class="logo">
        <h1>Proyectos Financieros</h1>
        <div class="header-buttons">
            <?php if (tienePermiso("finanzas", "crear")): ?>
                <a href="#" onclick="mostrarModal(); return false;" class="btn">Nuevo Proyecto</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
        </div>
    </header>

    <?php if (tienePermiso("finanzas", "crear")): ?>
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
                        <p style="padding: 12px;">No hay proyectos ganados disponibles</p>
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

    <div class="filtros-container">
        <form method="get" class="filtro-form">
            <div class="filtros-izquierda">
                <?php if (esSuperusuario()): ?>
                    <select name="sucursal" onchange="this.form.submit()">
                        <option value="">Todas las sucursales</option>
                        <?php foreach ($sucursales as $sucursal): ?>
                            <option value="<?= $sucursal['id'] ?>" <?= $filtro_sucursal == $sucursal['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sucursal['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($_SESSION['usuario']['rol_id'] == 2 || esSuperusuario()): ?>
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
                <th>Proyecto / Cliente</th>
                <th>Fecha Inicio</th>
                <th>Responsable</th>
                <?php if (esSuperusuario()): ?>
                    <th>Sucursal</th>
                <?php endif; ?>
                <th>Estado</th>
                <th style="text-align: center;">Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla-proyectos">
            <?php if (empty($proyectos_financieros)): ?>
                <tr>
                    <td colspan="<?= esSuperusuario() ? '7' : '6' ?>" style="text-align: center;">
                        No se encontraron proyectos financieros
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($proyectos_financieros as $proyecto): ?>
                    <tr>
                        <td>#<?= $proyecto['numero_proyectoF'] ?></td>
                        <td>
                            <div style="font-weight: 600; color: #2c3e50;"><?= htmlspecialchars($proyecto['titulo_mostrar'] ?? 'Sin título') ?></div>
                            <div style="font-size: 0.8rem; color: #7f8c8d;"><?= htmlspecialchars($proyecto['cliente_mostrar'] ?? 'Sin cliente') ?></div>
                        </td>
                        <td><i class="far fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($proyecto['fecha_inicio'])) ?></td>
                        <td><?= htmlspecialchars($proyecto['nombre_usuario']) ?></td>
                        <?php if (esSuperusuario()): ?>
                            <td><span class="tag-sucursal"><?= htmlspecialchars($proyecto['sucursal_nombre']) ?></span></td>
                        <?php endif; ?>
                        <td>
                            <span class="estado-selector"><?= htmlspecialchars($proyecto['estado_financiero']) ?></span>
                        </td>
                        <td>
                            <a href="proyecto_financiero.php?id=<?= $proyecto['id'] ?>" class="btn" style="padding: 5px 15px;">Abrir</a>
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