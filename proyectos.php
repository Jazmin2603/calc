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
JOIN estados e ON p.estado_id = e.id";

if ($_SESSION['usuario']['rol'] == ROL_GERENTE) {
    if ($_SESSION['usuario']['sucursal_id'] == 1) {
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
if ($_SESSION['usuario']['rol'] == ROL_VENDEDOR) {
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

$total_query = "SELECT COUNT(*) " . $query_base . $where_clause;
$stmt_count = $conn->prepare($total_query);
$stmt_count->execute($params);
$total_resultados = $stmt_count->fetchColumn();
$total_paginas = ceil($total_resultados / $por_pagina);

$query = "SELECT p.*, u.nombre as nombre_usuario, s.nombre as sucursal_nombre, e.estado as estado "
       . $query_base
       . $where_clause
       . " ORDER BY p.numero_proyecto DESC LIMIT $por_pagina OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sucursales = [];
$usuarios = [];
if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1) {
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

// Lógica de paginación mejorada
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
    <link rel="stylesheet" href="styles.css">
    <style>
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
            cursor: default;
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

        .filtro-derecha {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .filtro-derecha select,
        #buscador {
            padding: 6px 10px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        #buscador {
            width: 250px;
            transition: all 0.3s ease;
        }

        #buscador:focus {
            border-color: #34a44c;
            outline: none;
            box-shadow: 0 0 4px rgba(52, 164, 76, 0.5);
        }

        @media (max-width: 768px) {
            .paginacion {
                gap: 3px;
            }
            
            .paginacion a {
                padding: 6px 10px;
                min-width: 35px;
                font-size: 14px;
            }
            
            .paginacion-info {
                width: 100%;
                margin-top: 10px;
                margin-left: 0;
                text-align: center;
            }
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
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
        </div>
    </header>

    <div class="form-rowf">
        <form method="get" action="" class="filtro-form">
            <div class="filtros-izquierda">
                <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                    <select name="sucursal" id="venta-selector" onchange="this.form.submit()">
                        <option value="">Todas las sucursales</option>
                        <?php foreach ($sucursales as $sucursal): ?>
                            <option value="<?= $sucursal['id'] ?>" <?= $filtro_sucursal == $sucursal['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sucursal['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="usuario" id="venta-selector" onchange="this.form.submit()">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= $usuario['id'] ?>" <?= $filtro_usuario == $usuario['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usuario['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($_SESSION['usuario']['rol'] == ROL_GERENTE): ?>
                    <select name="usuario" id="venta-selector" onchange="this.form.submit()">
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
                <select name="estado" id="venta-selector" onchange="this.form.submit()">
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
                <th>ID</th>
                <th>Título</th>
                <th>Cliente</th>
                <th>Fecha</th>
                <th>Usuario</th>
                <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                    <th>Sucursal</th>
                <?php endif; ?>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla-proyectos">
            <?php foreach ($proyectos as $proyecto): ?>
                <tr>
                    <td><?= $proyecto['numero_proyecto'] ?></td>
                    <td><?= htmlspecialchars($proyecto['titulo']) ?></td>
                    <td><?= htmlspecialchars($proyecto['cliente']) ?></td>
                    <td><?= date('d/m/Y', strtotime($proyecto['fecha_proyecto'])) ?></td>
                    <td><?= htmlspecialchars($proyecto['nombre_usuario']) ?></td>
                    <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                        <td><?= htmlspecialchars($proyecto['sucursal_nombre']) ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if (($proyecto['id_usuario'] == $_SESSION['usuario']['id']) || 
                                  ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1)): ?>
                            <form method="post" action="cambiar_estado.php">
                                <input type="hidden" name="id_proyecto" value="<?= $proyecto['id_proyecto'] ?>">
                                <select name="estado_id" onchange="this.form.submit()">
                                    <?php foreach ($estados as $estado): ?>
                                        <option value="<?= $estado['id'] ?>" <?= $estado['estado'] == $proyecto['estado'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($estado['estado']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <?= htmlspecialchars($proyecto['estado']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="ver_proyecto.php?id=<?= $proyecto['id_proyecto'] ?>&<?= 
                            http_build_query(array_filter([
                                'sucursal' => $filtro_sucursal,
                                'usuario' => $filtro_usuario,
                                'estado' => $filtro_estado,
                                'buscar' => $busqueda,
                                'pagina' => $pagina_actual
                            ])) 
                        ?>" class="btn">Ver</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total_paginas > 1): ?>
    <div id="contenedor-paginacion" class="paginacion">
        <!-- Botón Primera página -->
        <?php if ($pagina_actual > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => 1])) ?>" class="paginacion-control" title="Primera página">
                ««
            </a>
        <?php endif; ?>

        <!-- Botón Anterior -->
        <?php if ($pagina_actual > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])) ?>" class="paginacion-control" title="Página anterior">
                «
            </a>
        <?php endif; ?>

        <!-- Mostrar "..." si hay páginas antes del rango -->
        <?php if ($inicio_rango > 1): ?>
            <span class="paginacion-puntos">...</span>
        <?php endif; ?>

        <!-- Páginas numeradas -->
        <?php for ($i = $inicio_rango; $i <= $fin_rango; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>" 
               class="<?= $i == $pagina_actual ? 'pagina-activa' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <!-- Mostrar "..." si hay páginas después del rango -->
        <?php if ($fin_rango < $total_paginas): ?>
            <span class="paginacion-puntos">...</span>
        <?php endif; ?>

        <!-- Botón Siguiente -->
        <?php if ($pagina_actual < $total_paginas): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])) ?>" class="paginacion-control" title="Página siguiente">
                »
            </a>
        <?php endif; ?>

        <!-- Botón Última página -->
        <?php if ($pagina_actual < $total_paginas): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $total_paginas])) ?>" class="paginacion-control" title="Última página">
                »»
            </a>
        <?php endif; ?>

        <!-- Información de página actual -->
        <span class="paginacion-info">
            Página <?= $pagina_actual ?> de <?= $total_paginas ?>
        </span>
    </div>
    <?php endif; ?>

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
</script>

</body>
</html>