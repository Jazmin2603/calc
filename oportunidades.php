<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("oportunidades", "ver");

$uid             = $_SESSION['usuario']['id'];
$puede_ver_todas = esSuperusuario() || esGerente();
$puede_crear     = tienePermiso('oportunidades', 'crear');
$puede_editar    = tienePermiso('oportunidades', 'editar');
$puede_eliminar  = tienePermiso('oportunidades', 'eliminar');
$puede_crear_presupuesto = tienePermiso('presupuestos', 'crear');

$params_js = http_build_query(array_filter([
    'sucursal' => $_GET['sucursal'] ?? null,
    'usuario'  => $_GET['usuario']  ?? null,
    'estado'   => $_GET['estado']   ?? null,
    'buscar'   => $_GET['buscar']   ?? null,
    'pagina'   => $_GET['pagina']   ?? null
]));

$params_url = !empty($params_js) ? "&" . $params_js : "";


// ── Filtros ────────────────────────────────────────────────
$filtro_usuario  = isset($_GET['usuario'])  ? intval($_GET['usuario'])  : null;
$filtro_sucursal = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : null;
$busqueda        = isset($_GET['buscar'])   ? trim($_GET['buscar'])     : '';

// ── Etapas ─────────────────────────────────────────────────
$etapas = $conn->query(
    "SELECT * FROM oportunidad_etapas WHERE activo = 1 ORDER BY orden"
)->fetchAll(PDO::FETCH_ASSOC);

$colores = ['#475569','#3b82f6','#7c3aed','#d97706','#dc2626','#059669','#0891b2'];

// ── Query de oportunidades ─────────────────────────────────
$conditions = ["o.estado = 'Activo'"];
$params     = [];

if ($puede_ver_todas) {
    if ($filtro_sucursal) { $conditions[] = "o.sucursal_id = ?"; $params[] = $filtro_sucursal; }
    if ($filtro_usuario)  { $conditions[] = "o.usuario_id = ?";  $params[] = $filtro_usuario; }
} else {
    $conditions[] = "o.usuario_id = ?";
    $params[] = $uid;
}

if (!empty($busqueda)) {
    $conditions[] = "(o.titulo LIKE ? OR c.nombre LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $conn->prepare("
    SELECT  o.*,
            c.nombre            AS cliente_nombre,
            c.sector            AS cliente_sector,
            c.ciudad            AS cliente_ciudad,
            u.nombre            AS nombre_usuario,
            s.nombre            AS sucursal_nombre,
            e.nombre            AS etapa_nombre,
            e.probabilidad      AS etapa_probabilidad,
            (SELECT COUNT(*) FROM oportunidad_presupuestos op WHERE op.oportunidad_id = o.id) AS num_presupuestos,
            (SELECT COUNT(*) FROM oportunidad_actividades  oa WHERE oa.oportunidad_id = o.id) AS num_actividades,
            (SELECT MAX(fecha_proximo_paso)
             FROM   oportunidad_actividades
             WHERE  oportunidad_id = o.id) AS proximo_paso_fecha
    FROM  oportunidades o
    JOIN  clientes           c ON o.cliente_id  = c.id
    JOIN  usuarios           u ON o.usuario_id  = u.id
    JOIN  sucursales         s ON o.sucursal_id = s.id
    JOIN  oportunidad_etapas e ON o.etapa_id    = e.id
    $where
    ORDER BY o.id DESC
");
$stmt->execute($params);
$todas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por etapa
$por_etapa = [];
foreach ($etapas as $idx => $etapa) {
    $por_etapa[$etapa['id']] = [
        'etapa' => $etapa,
        'color' => $colores[$idx % count($colores)],
        'items' => [],
        'total' => 0,
    ];
}
foreach ($todas as $op) {
    $eid = $op['etapa_id'];
    if (isset($por_etapa[$eid])) {
        $por_etapa[$eid]['items'][] = $op;
        $por_etapa[$eid]['total'] += floatval($op['monto_estimado']);
    }
}

// ── Listas auxiliares ──────────────────────────────────────
$sucursales_list = [];
$usuarios_list   = [];
if ($puede_ver_todas) {
    $sucursales_list = $conn->query(
        "SELECT id, nombre FROM sucursales WHERE id != 1 ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
    $usuarios_list = $conn->query(
        "SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Clientes para el modal — solo id + nombre para el buscador JS
$clientes_list = $conn->query(
    "SELECT id, nombre, ciudad, sector FROM clientes ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$usuarios_invitables = $conn->query(
    "SELECT id, nombre, email FROM usuarios WHERE activo = 1 ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// Presupuestos disponibles para vincular
$cond_pres = $puede_ver_todas ? '' : " AND p.id_usuario = $uid";
$presupuestos_disponibles = $conn->query("
    SELECT p.id_proyecto, p.numero_proyecto, p.titulo, p.cliente, e.estado
    FROM proyecto p
    JOIN estados e ON p.estado_id = e.id
    WHERE p.id_proyecto NOT IN (
        SELECT proyecto_id FROM oportunidad_presupuestos
    )
    $cond_pres
    ORDER BY p.numero_proyecto DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);

// ── Datos para vistas Lista y Gráficos ─────────────────────
$conditions_g = [];
$params_g     = [];
if ($puede_ver_todas) {
    if ($filtro_sucursal) { $conditions_g[] = "o.sucursal_id = ?"; $params_g[] = $filtro_sucursal; }
    if ($filtro_usuario)  { $conditions_g[] = "o.usuario_id = ?";  $params_g[] = $filtro_usuario; }
} else {
    $conditions_g[] = "o.usuario_id = ?";
    $params_g[] = $uid;
}
if (!empty($busqueda)) {
    $conditions_g[] = "(o.titulo LIKE ? OR c.nombre LIKE ?)";
    $params_g[] = "%$busqueda%";
    $params_g[] = "%$busqueda%";
}
$where_g = $conditions_g ? 'WHERE ' . implode(' AND ', $conditions_g) : '';
$stmt_g  = $conn->prepare("
    SELECT o.id, o.numero, o.titulo, o.monto_estimado, o.estado, o.fecha_cierre,
           o.fecha_creacion,
           c.nombre AS cliente_nombre,
           u.nombre AS nombre_usuario,
           s.nombre AS sucursal_nombre,
           e.nombre AS etapa_nombre,
           e.probabilidad AS etapa_probabilidad
    FROM oportunidades o
    JOIN clientes c ON o.cliente_id = c.id
    JOIN usuarios u ON o.usuario_id = u.id
    JOIN sucursales s ON o.sucursal_id = s.id
    JOIN oportunidad_etapas e ON o.etapa_id = e.id
    $where_g
    ORDER BY o.id DESC
");
$stmt_g->execute($params_g);
$datos_vistas      = $stmt_g->fetchAll(PDO::FETCH_ASSOC);
$datos_vistas_json = json_encode($datos_vistas);

// Etapas en JSON para el JS
$etapas_json = json_encode($etapas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pipeline de Oportunidades</title>
<link rel="icon" type="image/jpg" href="assets/icono.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="oportunidades.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── View Toggle ── */
.view-toggle{display:flex;gap:2px;background:var(--surface-2,#f1f5f9);border-radius:8px;padding:2px;border:1px solid var(--border,#e2e8f0);}
.vtb{background:none;border:none;cursor:pointer;padding:5px 11px;border-radius:6px;color:var(--ink-2,#64748b);font-size:.88rem;transition:all .15s;line-height:1;}
.vtb:hover{background:var(--surface-3,#e2e8f0);}
.vtb.active{background:#fff;color:var(--blue,#2563eb);box-shadow:0 1px 3px rgba(0,0,0,.12);}
/* ── Lista ── */
#vistaLista{padding:16px 20px;overflow:auto;flex:1;min-height:0;}
.op-table{width:100%;border-collapse:collapse;font-size:.84rem;}
.op-table th{background:var(--surface-2,#f1f5f9);padding:9px 12px;text-align:left;font-weight:600;color:var(--ink-2,#64748b);border-bottom:2px solid var(--border,#e2e8f0);white-space:nowrap;user-select:none;position:sticky;top:0;z-index:1;}
.op-table th.sortable{cursor:pointer;}
.op-table th.sortable:hover{color:var(--blue,#2563eb);}
.op-table td{padding:9px 12px;border-bottom:1px solid var(--border,#e2e8f0);color:var(--ink-1,#1e293b);vertical-align:middle;}
.op-table tr:hover td{background:var(--surface-2,#f1f5f9);cursor:pointer;}
.td-num{font-family:var(--mono,'JetBrains Mono',monospace);color:var(--ink-3,#94a3b8);font-size:.76rem;}
.td-titulo{font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.td-monto{font-family:var(--mono,'JetBrains Mono',monospace);text-align:right;white-space:nowrap;}
.etapa-pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600;color:#fff;white-space:nowrap;}
.estado-pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600;white-space:nowrap;}
.estado-Activo{background:#dbeafe;color:#1d4ed8;}
.estado-Ganado{background:#dcfce7;color:#166534;}
.estado-Perdido{background:#fee2e2;color:#991b1b;}
/* ── Gráficos ── */
#vistaGraficos{padding:14px 18px;overflow:auto;flex:1;min-height:0;background:#f8fafc;}

/* KPI cards */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px;}
.kpi-card{background:#fff;border-radius:10px;border:1px solid var(--border,#e2e8f0);padding:12px 14px;display:flex;flex-direction:column;gap:3px;border-left:3px solid var(--blue,#2563eb);}
.kpi-card.k-purple{border-left-color:#7c3aed;}
.kpi-card.k-green{border-left-color:#059669;}
.kpi-card.k-amber{border-left-color:#d97706;}
.kpi-label{font-size:.7rem;color:var(--ink-3,#94a3b8);text-transform:uppercase;letter-spacing:.04em;font-weight:600;}
.kpi-value{font-size:1.35rem;font-weight:700;color:var(--ink-1,#1e293b);font-family:var(--mono,'JetBrains Mono',monospace);line-height:1.1;}
.kpi-sub{font-size:.7rem;color:var(--ink-3,#94a3b8);}

/* Chart grid 4x2 */
.chart-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.chart-card{background:#fff;border-radius:10px;border:1px solid var(--border,#e2e8f0);padding:10px 12px 8px;display:flex;flex-direction:column;min-width:0;}
.chart-ttl{font-weight:600;font-size:.76rem;color:var(--ink-1,#1e293b);margin-bottom:6px;display:flex;align-items:center;gap:5px;line-height:1.2;}
.chart-ttl i{font-size:.72rem;color:var(--ink-3,#94a3b8);}
.chart-sub{font-size:.65rem;color:var(--ink-3,#94a3b8);font-weight:400;margin-left:auto;}
.chart-body{position:relative;height:180px;width:100%;}
.chart-body canvas{max-width:100%;}
.chart-empty{display:flex;align-items:center;justify-content:center;height:180px;color:var(--ink-3,#94a3b8);font-size:.78rem;}

@media(max-width:1280px){
    .chart-grid{grid-template-columns:repeat(2,1fr);}
    .kpi-grid{grid-template-columns:repeat(4,1fr);}
}
@media(max-width:768px){
    .chart-grid{grid-template-columns:1fr;}
    .kpi-grid{grid-template-columns:repeat(2,1fr);}
}
</style>
</head>
<body>
<div class="shell">

<div class="topbar">
    <img src="assets/logo.png" alt="Logo">
    <h1>
        <i class="fas fa-chart-line"></i>
        <span>Pipeline de Oportunidades</span>
    </h1>
    <div id="outlookStatus" style="display:flex;align-items:center;gap:7px;"></div>
    <?php if($puede_ver_todas): ?>
    <a href="dashboard.php" class="btn btn-ghost" style="font-size:.76rem;padding:4px 11px;">
        <i class="fas fa-arrow-left"></i> Dashboard
    </a>
    <?php endif; ?>
    <a href="gestion_clientes.php" class="btn btn-ghost" style="font-size:.76rem;padding:4px 11px;">
        Clientes
    </a>
</div>

<div class="toolbar">
    <form method="get" style="display:contents;">

        <?php if ($puede_crear): ?>
        <button type="button" class="btn btn-green" onclick="abrirNuevo()">
            <i class="fas fa-plus"></i> Nueva
        </button>
        <?php endif; ?>

        <input type="text" name="buscar"
               placeholder="Buscar título o cliente…"
               value="<?= htmlspecialchars($busqueda) ?>" autocomplete="off">

        <?php if ($puede_ver_todas): ?>
        <select name="sucursal" onchange="this.form.submit()">
            <option value="">Todas las sucursales</option>
            <?php foreach ($sucursales_list as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filtro_sucursal == $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="usuario" onchange="this.form.submit()">
            <option value="">Todos los usuarios</option>
            <?php foreach ($usuarios_list as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $filtro_usuario == $u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <button type="submit" class="btn btn-ghost">
            <i class="fas fa-filter"></i> Filtrar
        </button>

        <?php if ($busqueda || $filtro_sucursal || $filtro_usuario): ?>
        <a href="oportunidades.php" class="btn btn-ghost" style="color:var(--red);border-color:var(--red);">
            <i class="fas fa-times"></i> Limpiar
        </a>
        <?php endif; ?>

    </form>

    <div class="view-toggle">
        <button type="button" class="vtb" id="vtbKanban"      onclick="setVista('kanban')"      title="Kanban"><i class="fas fa-th-large"></i></button>
        <button type="button" class="vtb" id="vtbLista"       onclick="setVista('lista')"       title="Lista"><i class="fas fa-list"></i></button>
        <button type="button" class="vtb" id="vtbCalendario"  onclick="setVista('calendario')"  title="Calendario"><i class="fas fa-calendar-alt"></i></button>
        <button type="button" class="vtb" id="vtbGraficos"    onclick="setVista('graficos')"    title="Gráficos"><i class="fas fa-chart-bar"></i></button>
    </div>

    <span class="toolbar-meta">
        <?= count($todas) ?> oport. &bull;
        Bs <?= number_format(array_sum(array_column($todas, 'monto_estimado')), 2, ',', '.') ?>
    </span>
</div>

<!-- ═══════════════════════════════════════════════════════
     VISTA KANBAN
═══════════════════════════════════════════════════════ -->
<div class="kanban-scroll" id="vistaKanban">
    <div class="kanban-board" id="kanbanBoard">

    <?php foreach ($por_etapa as $eid => $grupo):
        $etapa = $grupo['etapa'];
        $color = $grupo['color'];
        $items = $grupo['items'];
        $count = count($items);
        $total = $grupo['total'];
    ?>
    <div class="k-col" data-etapa="<?= $eid ?>">

        <div class="k-col-head" style="background:<?= $color ?>;">
            <div class="hrow">
                <span class="hname"><?= htmlspecialchars($etapa['nombre']) ?></span>
                <span class="k-badge"><?= $count ?></span>
            </div>
            <div class="htotal">
                Bs <?= number_format($total, 2, ',', '.') ?> &middot; <?= $etapa['probabilidad'] ?>%
            </div>
        </div>

        <div class="k-cards" data-etapa="<?= $eid ?>">
            <?php if (empty($items)): ?>
            <div class="col-empty">
                <i class="fas fa-inbox" style="display:block;font-size:1.3rem;margin-bottom:4px;"></i>
                Vacío
            </div>
            <?php endif; ?>

            <?php foreach ($items as $op):
                $parts = explode(' ', $op['nombre_usuario']);
                $ini   = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                $chip_prox = '';
                if ($op['proximo_paso_fecha']) {
                    $diff = (strtotime($op['proximo_paso_fecha']) - time()) / 86400;
                    if ($diff < 0)     $chip_prox = 'chip-prox-venc';
                    elseif ($diff < 1) $chip_prox = 'chip-prox-hoy';
                    else               $chip_prox = 'chip-prox-ok';
                }
            ?>
            <div class="op-card"
                 data-id="<?= $op['id'] ?>"
                 draggable="true"
                 style="border-left-color:<?= $color ?>;"
                 onclick="verOp(<?= $op['id'] ?>)">

                <div class="card-top">
                    <span class="card-num">#<?= str_pad($op['numero'], 4, '0', STR_PAD_LEFT) ?></span>
                    <span class="card-estado-dot dot-<?= $op['estado'] ?>" title="<?= $op['estado'] ?>"></span>
                </div>
                <div class="card-titulo"><?= htmlspecialchars($op['titulo']) ?></div>
                <div class="card-cliente">
                    <i class="fas fa-building" style="font-size:.7rem;flex-shrink:0;"></i>
                    <?= htmlspecialchars($op['cliente_nombre']) ?>
                </div>
                <div class="card-monto">Bs <?= number_format($op['monto_estimado'], 2, ',', '.') ?></div>
                <div class="prob-bar">
                    <div class="prob-fill" style="width:<?= $etapa['probabilidad'] ?>%;background:<?= $color ?>;"></div>
                </div>
                <div class="card-chips">
                    <?php if ($op['num_presupuestos'] > 0): ?>
                    <span class="chip chip-pres"><i class="fas fa-file-alt"></i><?= $op['num_presupuestos'] ?></span>
                    <?php endif; ?>
                    <?php if ($op['num_actividades'] > 0): ?>
                    <span class="chip chip-act"><i class="fas fa-comments"></i><?= $op['num_actividades'] ?></span>
                    <?php endif; ?>
                    <?php if ($op['cliente_sector']): ?>
                    <span class="chip chip-sector"><?= htmlspecialchars(mb_substr($op['cliente_sector'], 0, 12)) ?></span>
                    <?php endif; ?>
                    <?php if ($chip_prox): ?>
                    <span class="chip <?= $chip_prox ?>">
                        <i class="fas fa-calendar-check"></i>
                        <?= date('d/m', strtotime($op['proximo_paso_fecha'])) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="avatar" title="<?= htmlspecialchars($op['nombre_usuario']) ?>"><?= $ini ?></div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <?php if ($op['proteccion']): ?>
                        <span class="card-prot" title="Protección: <?= htmlspecialchars($op['proteccion']) ?>">
                            <i class="fas fa-shield-alt"></i>
                        </span>
                        <?php endif; ?>
                        <span style="font-family:var(--mono);font-size:.65rem;color:var(--ink-3);"><?= $etapa['probabilidad'] ?>%</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($puede_crear): ?>
        <button class="k-add" onclick="abrirNuevo(<?= $eid ?>)">
            <i class="fas fa-plus"></i> Agregar
        </button>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VISTA LISTA
═══════════════════════════════════════════════════════ -->
<div id="vistaLista" style="display:none;">
    <table class="op-table">
        <thead>
            <tr>
                <th class="sortable" onclick="sortTabla(0)">N°<span class="si" id="si0"></span></th>
                <th class="sortable" onclick="sortTabla(1)">Título<span class="si" id="si1"></span></th>
                <th class="sortable" onclick="sortTabla(2)">Cliente<span class="si" id="si2"></span></th>
                <th class="sortable" onclick="sortTabla(3)">Etapa<span class="si" id="si3"></span></th>
                <th class="sortable" onclick="sortTabla(4)" style="text-align:right;">Monto<span class="si" id="si4"></span></th>
                <th class="sortable" onclick="sortTabla(5)">Vendedor<span class="si" id="si5"></span></th>
                <th class="sortable" onclick="sortTabla(6)">Cierre<span class="si" id="si6"></span></th>
                <th class="sortable" onclick="sortTabla(7)">Estado<span class="si" id="si7"></span></th>
            </tr>
        </thead>
        <tbody id="opTableBody"></tbody>
    </table>
</div>

<!-- VISTA CALENDARIO -->
<div id="vistaCalendario" style="display:none;">
    <div class="cal-toolbar">
        <button class="cal-nav-btn" onclick="calNav(-1)"><i class="fas fa-chevron-left"></i></button>
        <button class="cal-nav-btn" onclick="calHoy()" title="Hoy" style="width:auto;padding:0 10px;font-size:.78rem;">Hoy</button>
        <button class="cal-nav-btn" onclick="calNav(1)"><i class="fas fa-chevron-right"></i></button>
        <h3 id="calTitulo">—</h3>
        <div class="cal-mode-toggle">
            <button class="cal-mode-btn active" id="calModeMes"    onclick="setCalMode('mes')">Mes</button>
            <button class="cal-mode-btn"        id="calModeSemana" onclick="setCalMode('semana')">Semana</button>
        </div>
    </div>
    <div id="calContenido"></div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VISTA GRÁFICOS
═══════════════════════════════════════════════════════ -->
<div id="vistaGraficos" style="display:none;">

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <span class="kpi-label">Pipeline Activo</span>
            <span class="kpi-value" id="kpiPipeline">Bs 0</span>
            <span class="kpi-sub" id="kpiPipelineSub">0 oportunidades</span>
        </div>
        <div class="kpi-card k-purple">
            <span class="kpi-label">Pipeline Ponderado</span>
            <span class="kpi-value" id="kpiPonderado">Bs 0</span>
            <span class="kpi-sub">monto × probabilidad</span>
        </div>
        <div class="kpi-card k-green">
            <span class="kpi-label">Win Rate</span>
            <span class="kpi-value" id="kpiWinRate">0%</span>
            <span class="kpi-sub" id="kpiWinRateSub">0 ganadas / 0 cerradas</span>
        </div>
        <div class="kpi-card k-amber">
            <span class="kpi-label">Ticket Promedio</span>
            <span class="kpi-value" id="kpiTicket">Bs 0</span>
            <span class="kpi-sub">por oportunidad activa</span>
        </div>
    </div>

    <!-- Charts 4x2 -->
    <div class="chart-grid">
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-filter"></i> Embudo por Etapa</div>
            <div class="chart-body"><canvas id="chartEmbudo"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-users"></i> Por Vendedor <span class="chart-sub">cant.</span></div>
            <div class="chart-body"><canvas id="chartVendedor"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-coins"></i> Monto / Vendedor <span class="chart-sub">Bs</span></div>
            <div class="chart-body"><canvas id="chartMonto"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-chart-pie"></i> Por Estado</div>
            <div class="chart-body"><canvas id="chartEstado"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-trophy"></i> Top 5 Clientes <span class="chart-sub">Bs</span></div>
            <div class="chart-body"><canvas id="chartTopClientes"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-calendar-alt"></i> Cierres Próximos <span class="chart-sub">6 meses</span></div>
            <div class="chart-body"><canvas id="chartCierres"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-store"></i> Por Sucursal</div>
            <div class="chart-body"><canvas id="chartSucursal"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-ttl"><i class="fas fa-hourglass-half"></i> Antigüedad <span class="chart-sub">días en pipeline</span></div>
            <div class="chart-body"><canvas id="chartAntiguedad"></canvas></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VISTA DETALLE
═══════════════════════════════════════════════════════ -->
<div id="vistaDetalle" style="display:none;">
    <div class="det-head">
        <button class="det-back" onclick="volverAlListado()" title="Volver"><i class="fas fa-arrow-left"></i></button>
        <div class="det-titulo-wrap">
            <div class="det-num" id="detNum">—</div>
            <div class="det-titulo" id="detTitulo">—</div>
            <div class="det-sub" id="detSub">—</div>
        </div>
        <div class="det-actions">
            <?php if ($puede_editar): ?>
            <button class="btn btn-ghost" onclick="editarDesdeDetalle()"><i class="fas fa-edit"></i> Editar</button>
            <?php endif; ?>
            <?php if ($puede_eliminar): ?>
            <button class="btn btn-red" onclick="eliminarDesdeDetalle()"><i class="fas fa-trash"></i> Eliminar</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="det-split">

    <!-- ════ COLUMNA IZQUIERDA: Datos / Presupuestos / Archivos ════ -->
    <div class="det-side det-side-l">
        <div class="det-tabs">
            <button class="det-tab active" data-dt="datos" onclick="setDetTab('datos')"><i class="fas fa-info-circle"></i> Datos</button>
            <button class="det-tab" data-dt="presupuestos" onclick="setDetTab('presupuestos')"><i class="fas fa-file-invoice"></i> Presupuestos <span class="det-tab-count" id="detCntPres">0</span></button>
            <button class="det-tab" data-dt="archivos" onclick="setDetTab('archivos')"><i class="fas fa-paperclip"></i> Archivos <span class="det-tab-count" id="detCntArch">0</span></button>
            <!-- En móvil mostrar también los de la otra columna -->
            <button class="det-tab tab-mobile-only" data-dt="actividades" onclick="setDetTab('actividades')"><i class="fas fa-comments"></i> Actividades <span class="det-tab-count" id="detCntActMob">0</span></button>
            <button class="det-tab tab-mobile-only" data-dt="movimientos" onclick="setDetTab('movimientos')"><i class="fas fa-history"></i> Movimientos <span class="det-tab-count" id="detCntLogMob">0</span></button>
        </div>

        <div class="det-body">

            <!-- TAB DATOS -->
            <div class="det-panel active" id="detPanelDatos">
                <div class="det-grid" id="detDatosGrid"></div>
                <div id="detNotasWrap" style="margin-top:14px;"></div>
            </div>

            <!-- TAB PRESUPUESTOS -->
            <div class="det-panel" id="detPanelPresupuestos">
                <?php if($puede_crear_presupuesto): ?>
                <div class="crear-pres-box" id="boxCrearPres" style="display:flex;margin-bottom:12px;">
                    <div class="cp-info">
                        <strong><i class="fas fa-file-plus"></i> Crear nuevo presupuesto</strong>
                        <span>Se creará un presupuesto vinculado a esta oportunidad</span>
                    </div>
                    <button class="btn btn-blue" onclick="crearPresupuestoDesdeOp()">
                        <i class="fas fa-plus"></i> Crear presupuesto
                    </button>
                </div>
                <?php endif; ?>
                <div class="pres-list" id="listaPres"></div>
                <div class="link-row" style="margin-top:8px;">
                    <select id="selPres">
                        <option value="">— Vincular presupuesto existente —</option>
                        <?php foreach ($presupuestos_disponibles as $p): ?>
                        <option value="<?= $p['id_proyecto'] ?>">
                            #<?= $p['numero_proyecto'] ?> — <?= htmlspecialchars($p['titulo']) ?>
                            (<?= htmlspecialchars($p['cliente']) ?>) [<?= $p['estado'] ?>]
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline" onclick="vincular()">
                        <i class="fas fa-link"></i> Vincular
                    </button>
                </div>
            </div>

            <!-- TAB ARCHIVOS -->
            <div class="det-panel" id="detPanelArchivos">
                <div class="arch-zone" id="archZone" onclick="document.getElementById('archInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <div class="arch-zone-text">Click o arrastra archivos aquí</div>
                    <div class="arch-zone-sub">Tamaño máximo: 10 MB por archivo</div>
                    <input type="file" id="archInput" style="display:none;" onchange="subirArchivos(this.files)">
                    <div class="arch-progress" id="archProgress"><div class="arch-progress-bar" id="archProgressBar"></div></div>
                </div>
                <div class="arch-list" id="listaArch"></div>
            </div>

            <!-- TABS móviles: paneles que solo se usan cuando colapsa -->
            <div class="det-panel panel-mobile-only" id="detPanelActividadesMob"></div>
            <div class="det-panel panel-mobile-only" id="detPanelMovimientosMob"></div>

        </div>
    </div>

    <!-- ════ COLUMNA DERECHA: Actividades / Movimientos ════ -->
    <div class="det-side det-side-r">
        <div class="det-tabs">
            <button class="det-tab active" data-dt-r="actividades" onclick="setDetTabR('actividades')"><i class="fas fa-comments"></i> Actividades <span class="det-tab-count" id="detCntAct">0</span></button>
            <button class="det-tab" data-dt-r="movimientos" onclick="setDetTabR('movimientos')"><i class="fas fa-history"></i> Movimientos <span class="det-tab-count" id="detCntLog">0</span></button>
        </div>

        <div class="det-body">

            <!-- TAB ACTIVIDADES -->
            <div class="det-panel-r active" id="detPanelActividades">
                <div class="act-list" id="listaAct"></div>
                <div class="act-form" style="margin-top:14px;">
                    <div class="form-label"><i class="fas fa-plus"></i> Registrar actividad</div>
                    <div class="g2">
                        <div class="fg">
                            <label>Tipo</label>
                            <select id="actTipo">
                                <option value="Llamada">📞 Llamada</option>
                                <option value="Reunion">🤝 Reunión</option>
                                <option value="Correo">📧 Correo</option>
                                <option value="Actualización de quote">📋 Actualización de quote</option>
                                <option value="Visita">🏢 Visita</option>
                            </select>
                        </div>
                        <div class="fg">
                            <label>Inicio *</label>
                            <input type="datetime-local" id="actFechaIni">
                        </div>
                        <div class="fg">
                            <label>Fin *</label>
                            <input type="datetime-local" id="actFechaFin">
                        </div>
                        <div class="fg">
                            <label>Duración rápida</label>
                            <select id="actDurPreset" onchange="aplicarPresetDuracion()">
                                <option value="">Personalizada</option>
                                <option value="15">15 min</option>
                                <option value="30" selected>30 min</option>
                                <option value="45">45 min</option>
                                <option value="60">1 hora</option>
                                <option value="90">1.5 horas</option>
                                <option value="120">2 horas</option>
                            </select>
                        </div>
                        <div class="fg span2">
                            <label>Invitados <span style="color:var(--ink-3);font-weight:400;">(además de ti)</span></label>
                            <div class="invitados-wrap">
                                <div class="invitados-chips" id="invitadosChips"></div>
                                <input type="text" id="invitadosSearch" placeholder="Escribe para agregar usuarios…"
                                       onkeydown="invitadosKeydown(event)" oninput="filtrarInvitados()"
                                       onfocus="filtrarInvitados()" onblur="setTimeout(()=>document.getElementById('invitadosDropdown').classList.remove('open'),180)">
                                <div class="invitados-dropdown" id="invitadosDropdown"></div>
                            </div>
                        </div>
                        <div class="fg span2">
                            <label>Descripción / Agenda</label>
                            <textarea id="actProximo" style="min-height:44px;" placeholder="¿Qué está planificado?"></textarea>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:6px;">
                        <label id="labelOutlookCheck" style="display:none;align-items:center;gap:7px;font-size:.8rem;color:var(--ink-2);cursor:pointer;">
                            <input type="checkbox" id="chkOutlook" style="accent-color:var(--blue);width:15px;height:15px;">
                            <i class="fas fa-calendar-check" style="color:var(--blue);"></i>
                            Agregar al calendario de Outlook
                        </label>
                        <button class="btn btn-green" onclick="guardarActividad()">
                            <i class="fas fa-paper-plane"></i> Registrar
                        </button>
                    </div>
                </div>
            </div>

            <!-- TAB MOVIMIENTOS -->
            <div class="det-panel-r" id="detPanelMovimientos">
                <div class="log-list" id="listaLog"></div>
            </div>

        </div>
    </div>

</div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VISTA FORMULARIO (nueva / editar)
═══════════════════════════════════════════════════════ -->
<div id="vistaForm" style="display:none;">
    <div class="det-head">
        <button class="det-back" onclick="volverDesdeForm()" title="Volver"><i class="fas fa-arrow-left"></i></button>
        <div class="det-titulo-wrap">
            <div class="det-titulo" id="formTitulo">Nueva Oportunidad</div>
            <div class="det-sub" id="formSub"></div>
        </div>
    </div>

    <div class="det-body">
        <div class="form-page">
            <form id="fOp" autocomplete="off">
                <input type="hidden" id="fId" name="id">
                <div class="g2">
                    <div class="fg span2">
                        <label>Título *</label>
                        <input type="text" name="titulo" id="fTitulo" required placeholder="Ej: Implementación ERP Empresa XYZ">
                    </div>
                    <div class="fg span2">
                        <label>Cliente *</label>
                        <input type="hidden" name="cliente_id" id="fCliente" required>
                        <div class="client-search-wrap">
                            <input type="text" id="clienteSearch" placeholder="Escribir para buscar cliente…" autocomplete="off"
                                   onkeydown="clienteKeydown(event)" oninput="filtrarClientes()"
                                   onfocus="abrirDropdown()" onblur="ocultarDropdownDelay()">
                            <div class="client-dropdown" id="clienteDropdown"></div>
                        </div>
                    </div>
                    <div class="fg">
                        <label>Etapa</label>
                        <select name="etapa_id" id="fEtapa">
                            <?php foreach ($etapas as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Monto Estimado (Bs)</label>
                        <input type="number" step="0.01" min="0" name="monto_estimado" id="fMonto" placeholder="0.00">
                    </div>
                    <div class="fg">
                        <label>Fecha de Cierre</label>
                        <input type="date" name="fecha_cierre" id="fCierre">
                    </div>
                    <div class="fg">
                        <label>Protección ID</label>
                        <input type="text" name="proteccion" id="fProteccion" placeholder="Ej: Dell ID">
                    </div>
                    <div class="fg">
                        <label>Estado</label>
                        <select name="estado" id="fEstado">
                            <option value="Activo">Activo</option>
                            <option value="Ganado">Ganado</option>
                            <option value="Perdido">Perdido</option>
                        </select>
                    </div>
                    <div class="fg span2">
                        <label>Notas</label>
                        <textarea name="notas" id="fNotas" placeholder="Notas internas…"></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" onclick="volverDesdeForm()">Cancelar</button>
                    <?php if ($puede_crear || $puede_editar): ?>
                    <button type="button" class="btn btn-green" onclick="guardar()"><i class="fas fa-save"></i> Guardar</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

</div><!-- /.shell -->

<div class="toasts" id="toasts"></div>

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════ -->
<script>
const PUEDE_EDITAR            = <?= json_encode($puede_editar) ?>;
const PUEDE_CREAR             = <?= json_encode($puede_crear) ?>;
const PUEDE_ELIMINAR          = <?= json_encode($puede_eliminar) ?>;
const PUEDE_CREAR_PRESUPUESTO = <?= json_encode($puede_crear_presupuesto) ?>;
let opId = null;
let opClienteId = null;

let detalleData = null;

const USUARIOS_INVITABLES = <?= json_encode($usuarios_invitables) ?>;
const MI_USUARIO_ID = <?= json_encode($uid) ?>;

/* ── Datos para lista y gráficos ── */
const VISTAS_DATA = <?= $datos_vistas_json ?>;
const ETAPA_COLORES = {<?php foreach ($etapas as $i => $e): ?>
    <?= json_encode($e['nombre']) ?>: '<?= $colores[$i % count($colores)] ?>',
<?php endforeach; ?>};

/* ═══════════════════════════════════════════════════════════
   CAMBIO DE VISTA  (kanban / lista / graficos)
═══════════════════════════════════════════════════════════ */
let vistaActual = localStorage.getItem('op_vista') || 'kanban';
let chartInst   = {};
let sortDir     = {};

let calMode    = 'mes';
let calCursor  = new Date();
let calData    = [];
let invitadosSel = [];
let invHighlight = -1;

function setVista(v) {
    // Si estábamos en detalle/form, salir de ahí
    document.getElementById('vistaDetalle').style.display = 'none';
    document.getElementById('vistaForm').style.display    = 'none';

    vistaActual = v;
    localStorage.setItem('op_vista', v);
    const elK = document.getElementById('vistaKanban');
    const elL = document.getElementById('vistaLista');
    const elC = document.getElementById('vistaCalendario');
    const elG = document.getElementById('vistaGraficos');
    if (elK) elK.style.display = v === 'kanban'      ? '' : 'none';
    if (elL) elL.style.display = v === 'lista'       ? '' : 'none';
    if (elC) elC.style.display = v === 'calendario'  ? '' : 'none';
    if (elG) elG.style.display = v === 'graficos'    ? '' : 'none';

    // Mostrar toolbar (ocultable cuando estamos en detalle)
    document.querySelector('.toolbar').style.display = '';

    document.querySelectorAll('.vtb').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('vtb' + v.charAt(0).toUpperCase() + v.slice(1));
    if (btn) btn.classList.add('active');
    if (v === 'lista')       renderLista();
    if (v === 'calendario')  cargarCalendario();
    if (v === 'graficos')    renderGraficos();
}

function ocultarVistasListado() {
    ['vistaKanban','vistaLista','vistaCalendario','vistaGraficos'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    document.querySelector('.toolbar').style.display = 'none';
}

function volverAlListado() {
    document.getElementById('vistaDetalle').style.display = 'none';
    document.getElementById('vistaForm').style.display    = 'none';
    setVista(vistaActual);
}

function volverDesdeForm() {
    document.getElementById('vistaForm').style.display = 'none';
    if (opId) {
        // Estábamos editando → volver al detalle
        verOp(opId);
    } else {
        volverAlListado();
    }
}

/* ── Lista ── */
function renderLista() {
    const tbody = document.getElementById('opTableBody');
    if (!tbody) return;
    tbody.innerHTML = VISTAS_DATA.map(o => `
        <tr onclick="verOp(${o.id})">
            <td class="td-num">#${String(o.numero).padStart(4,'0')}</td>
            <td class="td-titulo" title="${esc(o.titulo)}">${esc(o.titulo)}</td>
            <td>${esc(o.cliente_nombre)}</td>
            <td><span class="etapa-pill" style="background:${ETAPA_COLORES[o.etapa_nombre]||'#475569'};">${esc(o.etapa_nombre)}</span></td>
            <td class="td-monto">${numFmt(o.monto_estimado)}</td>
            <td>${esc(o.nombre_usuario)}</td>
            <td>${o.fecha_cierre ? fmtFecha(o.fecha_cierre) : '—'}</td>
            <td><span class="estado-pill estado-${o.estado}">${o.estado}</span></td>
        </tr>`).join('');
}

function sortTabla(col) {
    const tbody = document.getElementById('opTableBody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const asc  = sortDir[col] = !sortDir[col];
    rows.sort((a, b) => {
        const av = a.cells[col].textContent.trim();
        const bv = b.cells[col].textContent.trim();
        const an = parseFloat(av.replace(/[^0-9.-]/g, ''));
        const bn = parseFloat(bv.replace(/[^0-9.-]/g, ''));
        if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
        return asc ? av.localeCompare(bv, 'es') : bv.localeCompare(av, 'es');
    });
    rows.forEach(r => tbody.appendChild(r));
    document.querySelectorAll('.si').forEach(s => s.textContent = '');
    const si = document.getElementById('si' + col);
    if (si) si.textContent = asc ? ' ↑' : ' ↓';
}

/* ── KPIs y Gráficos ── */
function aggBy(campo) {
    const m = {};
    VISTAS_DATA.forEach(o => {
        const k = o[campo] || '—';
        if (!m[k]) m[k] = { count: 0, monto: 0, Activo: 0, Ganado: 0, Perdido: 0 };
        m[k].count++;
        m[k].monto += parseFloat(o.monto_estimado || 0);
        if (o.estado in m[k]) m[k][o.estado]++;
    });
    return m;
}

function bsCompact(n) {
    n = parseFloat(n) || 0;
    if (n >= 1e6) return 'Bs ' + (n/1e6).toFixed(1).replace('.0','') + 'M';
    if (n >= 1e3) return 'Bs ' + (n/1e3).toFixed(1).replace('.0','') + 'K';
    return 'Bs ' + Math.round(n).toLocaleString('es-BO');
}

function renderKPIs() {
    const activos  = VISTAS_DATA.filter(o => o.estado === 'Activo');
    const ganados  = VISTAS_DATA.filter(o => o.estado === 'Ganado');
    const perdidos = VISTAS_DATA.filter(o => o.estado === 'Perdido');

    const totalActivo = activos.reduce((s, o) => s + parseFloat(o.monto_estimado || 0), 0);
    const ponderado   = activos.reduce((s, o) =>
        s + parseFloat(o.monto_estimado || 0) * (parseFloat(o.etapa_probabilidad || 0) / 100), 0);
    const cerradas    = ganados.length + perdidos.length;
    const winRate     = cerradas > 0 ? Math.round(ganados.length * 100 / cerradas) : 0;
    const ticket      = activos.length > 0 ? totalActivo / activos.length : 0;

    document.getElementById('kpiPipeline').textContent     = bsCompact(totalActivo);
    document.getElementById('kpiPipelineSub').textContent  = `${activos.length} oportunidad${activos.length !== 1 ? 'es' : ''}`;
    document.getElementById('kpiPonderado').textContent    = bsCompact(ponderado);
    document.getElementById('kpiWinRate').textContent      = winRate + '%';
    document.getElementById('kpiWinRateSub').textContent   = `${ganados.length} ganadas / ${cerradas} cerradas`;
    document.getElementById('kpiTicket').textContent       = bsCompact(ticket);
}

/* Opciones comunes para charts compactos */
const CHART_BASE_OPTS = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { titleFont:{size:11}, bodyFont:{size:11}, padding:6 }
    },
    scales: {
        x: { ticks:{ font:{size:10}, color:'#64748b' }, grid:{ color:'#f1f5f9' } },
        y: { ticks:{ font:{size:10}, color:'#64748b' }, grid:{ color:'#f1f5f9' } }
    }
};

function makeChart(canvasId, config) {
    const cv = document.getElementById(canvasId);
    if (!cv) return null;
    return new Chart(cv, config);
}

function renderGraficos() {
    Object.values(chartInst).forEach(c => { try { c.destroy(); } catch(_){} });
    chartInst = {};

    renderKPIs();

    const PAL = ['#3b82f6','#7c3aed','#d97706','#059669','#0891b2','#dc2626','#475569','#f59e0b','#10b981'];

    /* ── 1. Embudo por Etapa (horizontal, ordenado) ── */
    const de   = aggBy('etapa_nombre');
    const etNm = Object.keys(de);
    chartInst.embudo = makeChart('chartEmbudo', {
        type: 'bar',
        data: {
            labels: etNm,
            datasets: [{
                data: etNm.map(e => de[e].count),
                backgroundColor: etNm.map(e => ETAPA_COLORES[e] || '#475569'),
                borderRadius: 4
            }]
        },
        options: { ...CHART_BASE_OPTS, indexAxis:'y',
            scales: {
                x:{ ...CHART_BASE_OPTS.scales.x, beginAtZero:true, ticks:{ ...CHART_BASE_OPTS.scales.x.ticks, stepSize:1, precision:0 } },
                y:{ ...CHART_BASE_OPTS.scales.y, ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, font:{size:9} } }
            },
            plugins: { ...CHART_BASE_OPTS.plugins,
                tooltip: { callbacks: { label: ctx =>
                    `${ctx.raw} oport. · ${bsCompact(de[ctx.label].monto)}` } } }
        }
    });

    /* ── 2. Por Vendedor (stacked horizontal) ── */
    const dv   = aggBy('nombre_usuario');
    const vend = Object.keys(dv);
    chartInst.vendedor = makeChart('chartVendedor', {
        type: 'bar',
        data: {
            labels: vend,
            datasets: [
                { label:'Activo',  data: vend.map(v => dv[v].Activo),  backgroundColor:'#3b82f6' },
                { label:'Ganado',  data: vend.map(v => dv[v].Ganado),  backgroundColor:'#059669' },
                { label:'Perdido', data: vend.map(v => dv[v].Perdido), backgroundColor:'#dc2626' },
            ]
        },
        options: { ...CHART_BASE_OPTS, indexAxis:'y',
            plugins: { ...CHART_BASE_OPTS.plugins, legend:{ display:true, position:'bottom', labels:{ font:{size:10}, boxWidth:10, padding:6 } } },
            scales: {
                x: { ...CHART_BASE_OPTS.scales.x, stacked:true, beginAtZero:true, ticks:{ ...CHART_BASE_OPTS.scales.x.ticks, stepSize:1, precision:0 } },
                y: { ...CHART_BASE_OPTS.scales.y, stacked:true, ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, font:{size:9} } }
            }
        }
    });

    /* ── 3. Monto por Vendedor (horizontal) ── */
    chartInst.monto = makeChart('chartMonto', {
        type: 'bar',
        data: {
            labels: vend,
            datasets: [{
                data: vend.map(v => Math.round(dv[v].monto)),
                backgroundColor: vend.map((_, i) => PAL[i % PAL.length]),
                borderRadius: 4
            }]
        },
        options: { ...CHART_BASE_OPTS, indexAxis:'y',
            scales: {
                x: { ...CHART_BASE_OPTS.scales.x, ticks:{ ...CHART_BASE_OPTS.scales.x.ticks, callback: v => bsCompact(v) } },
                y: { ...CHART_BASE_OPTS.scales.y, ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, font:{size:9} } }
            },
            plugins: { ...CHART_BASE_OPTS.plugins,
                tooltip: { callbacks: { label: ctx => bsCompact(ctx.raw) } } }
        }
    });

    /* ── 4. Por Estado (donut) ── */
    const est = { Activo:0, Ganado:0, Perdido:0 };
    VISTAS_DATA.forEach(o => { if (o.estado in est) est[o.estado]++; });
    chartInst.estado = makeChart('chartEstado', {
        type: 'doughnut',
        data: {
            labels: ['Activo','Ganado','Perdido'],
            datasets: [{
                data:[est.Activo, est.Ganado, est.Perdido],
                backgroundColor:['#3b82f6','#059669','#dc2626'], borderWidth:2
            }]
        },
        options: {
            responsive:true, maintainAspectRatio:false, cutout:'62%',
            plugins:{
                legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10, padding:6 } },
                tooltip:{ callbacks:{ label: ctx => {
                    const total = est.Activo + est.Ganado + est.Perdido;
                    const pct   = total ? Math.round(ctx.raw*100/total) : 0;
                    return ` ${ctx.label}: ${ctx.raw} (${pct}%)`;
                } } }
            }
        }
    });

    /* ── 5. Top 5 Clientes ── */
    const dc = aggBy('cliente_nombre');
    const topClientes = Object.entries(dc)
        .sort((a, b) => b[1].monto - a[1].monto).slice(0, 5);
    chartInst.topCli = makeChart('chartTopClientes', {
        type: 'bar',
        data: {
            labels: topClientes.map(([n]) => n.length > 18 ? n.slice(0,16) + '…' : n),
            datasets: [{
                data: topClientes.map(([, d]) => Math.round(d.monto)),
                backgroundColor: topClientes.map((_, i) => PAL[i % PAL.length]),
                borderRadius: 4
            }]
        },
        options: { ...CHART_BASE_OPTS, indexAxis:'y',
            scales: {
                x: { ...CHART_BASE_OPTS.scales.x, ticks:{ ...CHART_BASE_OPTS.scales.x.ticks, callback: v => bsCompact(v) } },
                y: { ...CHART_BASE_OPTS.scales.y, ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, font:{size:9} } }
            },
            plugins: { ...CHART_BASE_OPTS.plugins,
                tooltip: { callbacks: {
                    title: ctx => topClientes[ctx[0].dataIndex][0],
                    label: ctx => `${bsCompact(ctx.raw)} · ${topClientes[ctx.dataIndex][1].count} oport.`
                } } }
        }
    });

    /* ── 6. Cierres Próximos (6 meses) ── */
    const meses = [];
    const hoy = new Date();
    for (let i = 0; i < 6; i++) {
        const d = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
        meses.push({
            key:   d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0'),
            label: d.toLocaleDateString('es-BO', {month:'short', year:'2-digit'}),
            count: 0, monto: 0
        });
    }
    VISTAS_DATA.forEach(o => {
        if (!o.fecha_cierre || o.estado !== 'Activo') return;
        const k = o.fecha_cierre.slice(0,7);
        const m = meses.find(x => x.key === k);
        if (m) { m.count++; m.monto += parseFloat(o.monto_estimado || 0); }
    });
    chartInst.cierres = makeChart('chartCierres', {
        type: 'bar',
        data: {
            labels: meses.map(m => m.label),
            datasets: [{
                data: meses.map(m => Math.round(m.monto)),
                backgroundColor:'#3b82f6', borderRadius:4
            }]
        },
        options: { ...CHART_BASE_OPTS,
            scales: {
                x: { ...CHART_BASE_OPTS.scales.x },
                y: { ...CHART_BASE_OPTS.scales.y, beginAtZero:true,
                     ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, callback: v => bsCompact(v) } }
            },
            plugins: { ...CHART_BASE_OPTS.plugins,
                tooltip: { callbacks: { label: ctx =>
                    `${bsCompact(ctx.raw)} · ${meses[ctx.dataIndex].count} oport.` } } }
        }
    });

    /* ── 7. Por Sucursal ── */
    const ds  = aggBy('sucursal_nombre');
    const suc = Object.keys(ds);
    chartInst.sucursal = makeChart('chartSucursal', {
        type: 'bar',
        data: {
            labels: suc,
            datasets: [{
                data: suc.map(s => Math.round(ds[s].monto)),
                backgroundColor: suc.map((_, i) => PAL[i % PAL.length]),
                borderRadius: 4
            }]
        },
        options: { ...CHART_BASE_OPTS, indexAxis:'y',
            scales: {
                x: { ...CHART_BASE_OPTS.scales.x, ticks:{ ...CHART_BASE_OPTS.scales.x.ticks, callback: v => bsCompact(v) } },
                y: { ...CHART_BASE_OPTS.scales.y, ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, font:{size:9} } }
            },
            plugins: { ...CHART_BASE_OPTS.plugins,
                tooltip: { callbacks: { label: ctx =>
                    `${bsCompact(ctx.raw)} · ${ds[suc[ctx.dataIndex]].count} oport.` } } }
        }
    });

    /* ── 8. Antigüedad (días en pipeline) ── */
    const buckets = { '0-30':0, '31-60':0, '61-90':0, '90+':0 };
    const now = Date.now();
    VISTAS_DATA.filter(o => o.estado === 'Activo' && o.fecha_creacion).forEach(o => {
        const dias = Math.floor((now - new Date(o.fecha_creacion).getTime()) / 86400000);
        if      (dias <= 30) buckets['0-30']++;
        else if (dias <= 60) buckets['31-60']++;
        else if (dias <= 90) buckets['61-90']++;
        else                 buckets['90+']++;
    });
    chartInst.antig = makeChart('chartAntiguedad', {
        type: 'bar',
        data: {
            labels: Object.keys(buckets),
            datasets: [{
                data: Object.values(buckets),
                backgroundColor: ['#059669','#3b82f6','#d97706','#dc2626'],
                borderRadius: 4
            }]
        },
        options: { ...CHART_BASE_OPTS,
            scales: {
                x: { ...CHART_BASE_OPTS.scales.x },
                y: { ...CHART_BASE_OPTS.scales.y, beginAtZero:true,
                     ticks:{ ...CHART_BASE_OPTS.scales.y.ticks, stepSize:1, precision:0 } }
            },
            plugins: { ...CHART_BASE_OPTS.plugins,
                tooltip: { callbacks: { label: ctx => `${ctx.raw} oport.` } } }
        }
    });
}

function fmtFecha(s) {
    if (!s) return '—';
    return new Date(s + 'T12:00:00').toLocaleDateString('es-BO', {day:'2-digit', month:'short', year:'numeric'});
}

/* ── Clientes para búsqueda ────────────────────────────── */
const CLIENTES = <?= json_encode($clientes_list) ?>;
let highlightIdx = -1;

function filtrarClientes() {
    const q = document.getElementById('clienteSearch').value.toLowerCase().trim();
    highlightIdx = -1;

    if (!q) {
        renderDropdown(CLIENTES.slice(0, 40));
        return;
    }
    const matches = CLIENTES.filter(c =>
        c.nombre.toLowerCase().includes(q) ||
        (c.ciudad && c.ciudad.toLowerCase().includes(q)) ||
        (c.sector && c.sector.toLowerCase().includes(q))
    ).slice(0, 30);
    renderDropdown(matches);
}

function renderDropdown(lista) {
    const dd = document.getElementById('clienteDropdown');
    if (!lista.length) {
        dd.innerHTML = '<div class="client-option no-results">Sin resultados</div>';
    } else {
        dd.innerHTML = lista.map((c, i) => `
            <div class="client-option" data-id="${c.id}" data-nombre="${esc(c.nombre)}"
                 onmousedown="seleccionarCliente(${c.id}, '${esc(c.nombre)}')"
                 data-idx="${i}">
                ${esc(c.nombre)}
                <div class="c-sub">${[c.ciudad, c.sector].filter(Boolean).join(' · ')}</div>
            </div>
        `).join('');
    }
    dd.classList.add('open');
}

function abrirDropdown() {
    filtrarClientes();
}

function ocultarDropdownDelay() {
    setTimeout(() => {
        document.getElementById('clienteDropdown').classList.remove('open');
    }, 180);
}

function seleccionarCliente(id, nombre) {
    document.getElementById('fCliente').value = id;
    document.getElementById('clienteSearch').value = nombre;
    document.getElementById('clienteDropdown').classList.remove('open');
    opClienteId = id;
}

function clienteKeydown(e) {
    const dd = document.getElementById('clienteDropdown');
    const opts = dd.querySelectorAll('.client-option:not(.no-results)');
    if (!opts.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightIdx = Math.min(highlightIdx + 1, opts.length - 1);
        opts.forEach((o, i) => o.classList.toggle('highlighted', i === highlightIdx));
        opts[highlightIdx]?.scrollIntoView({block:'nearest'});
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightIdx = Math.max(highlightIdx - 1, 0);
        opts.forEach((o, i) => o.classList.toggle('highlighted', i === highlightIdx));
        opts[highlightIdx]?.scrollIntoView({block:'nearest'});
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlightIdx >= 0 && opts[highlightIdx]) {
            const opt = opts[highlightIdx];
            seleccionarCliente(parseInt(opt.dataset.id), opt.dataset.nombre);
        }
    } else if (e.key === 'Escape') {
        document.getElementById('clienteDropdown').classList.remove('open');
    }
}

/* ── Toast ─────────────────────────────────────────────── */
function toast(msg, tipo = 'ok') {
    const el = document.createElement('div');
    el.className = `toast ${tipo}`;
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 3200);
}

/* ── Abrir modal nuevo ─────────────────────────────────── */
function abrirNuevo(etapaId = null) {
    opId = null;
    opClienteId = null;
    document.getElementById('fOp').reset();
    document.getElementById('fId').value = '';
    document.getElementById('fCliente').value = '';
    document.getElementById('clienteSearch').value = '';
    if (etapaId) document.getElementById('fEtapa').value = etapaId;

    document.getElementById('formTitulo').textContent = 'Nueva Oportunidad';
    document.getElementById('formSub').textContent = '';

    ocultarVistasListado();
    document.getElementById('vistaDetalle').style.display = 'none';
    document.getElementById('vistaForm').style.display = '';
    setTimeout(() => document.getElementById('fTitulo').focus(), 100);
}

/* ── Ver / editar oportunidad ──────────────────────────── */
async function verOp(id) {
    try {
        const r = await fetch(`crear_oportunidad.php?action=detalle&id=${id}`);
        const d = await r.json();
        if (!d.success) { toast(d.message || 'Error al cargar', 'err'); return; }

        opId = d.op.id;
        opClienteId = d.op.cliente_id;
        detalleData = d;  // global para usar en editarDesdeDetalle

        ocultarVistasListado();
        document.getElementById('vistaForm').style.display = 'none';
        document.getElementById('vistaDetalle').style.display = '';

        renderDetalle(d);
    } catch (e) {
        toast('Error de conexión', 'err');
        console.error(e);
    }
}

function renderDetalle(d) {
    const op = d.op;
    document.getElementById('detNum').textContent     = `#${String(op.numero).padStart(4,'0')} · ${op.sucursal_nombre}`;
    document.getElementById('detTitulo').textContent  = op.titulo;
    document.getElementById('detSub').innerHTML       =
        `<span><i class="fas fa-building"></i>${esc(op.cliente_nombre)}</span>` +
        `<span><i class="fas fa-user"></i>${esc(op.nombre_usuario)}</span>` +
        `<span><i class="fas fa-tag"></i><span class="estado-pill estado-${op.estado}">${op.estado}</span></span>`;

    // Tab Datos
    const color = ETAPA_COLORES[op.etapa_nombre] || '#475569';
    document.getElementById('detDatosGrid').innerHTML = `
        <div class="det-card">
            <h4><i class="fas fa-info-circle"></i> Información general</h4>
            <div class="det-row"><span class="lbl">N° Oportunidad</span><span class="val mono">#${String(op.numero).padStart(4,'0')}</span></div>
            <div class="det-row"><span class="lbl">Estado</span><span class="val"><span class="estado-pill estado-${op.estado}">${op.estado}</span></span></div>
            <div class="det-row"><span class="lbl">Etapa</span><span class="val"><span class="det-pill" style="background:${color};">${esc(op.etapa_nombre)}</span></span></div>
            <div class="det-row"><span class="lbl">Probabilidad</span><span class="val mono">${op.etapa_probabilidad}%</span></div>
            <div class="det-row"><span class="lbl">Sucursal</span><span class="val">${esc(op.sucursal_nombre)}</span></div>
            ${op.proteccion ? `<div class="det-row"><span class="lbl">Protección</span><span class="val">${esc(op.proteccion)}</span></div>` : ''}
        </div>
        <div class="det-card">
            <h4><i class="fas fa-coins"></i> Económico</h4>
            <div class="det-row"><span class="lbl">Monto estimado</span><span class="val mono big">Bs ${numFmt(op.monto_estimado)}</span></div>
            <div class="det-row"><span class="lbl">Monto ponderado</span><span class="val mono">Bs ${numFmt(op.monto_estimado * op.etapa_probabilidad / 100)}</span></div>
            <div class="det-row"><span class="lbl">Fecha de cierre</span><span class="val">${op.fecha_cierre ? fmtFecha(op.fecha_cierre) : '—'}</span></div>
            <div class="det-row"><span class="lbl">Fecha de creación</span><span class="val">${op.fecha_creacion ? fmtFecha(op.fecha_creacion) : '—'}</span></div>
        </div>
        <div class="det-card">
            <h4><i class="fas fa-building"></i> Cliente</h4>
            <div class="det-row"><span class="lbl">Nombre</span><span class="val">${esc(op.cliente_nombre)}</span></div>
            ${op.cliente_nit ? `<div class="det-row"><span class="lbl">NIT</span><span class="val mono">${esc(op.cliente_nit)}</span></div>` : ''}
            ${op.cliente_ciudad ? `<div class="det-row"><span class="lbl">Ciudad</span><span class="val">${esc(op.cliente_ciudad)}</span></div>` : ''}
            ${op.cliente_sector ? `<div class="det-row"><span class="lbl">Sector</span><span class="val">${esc(op.cliente_sector)}</span></div>` : ''}
            ${op.cliente_correo ? `<div class="det-row"><span class="lbl">Correo</span><span class="val">${esc(op.cliente_correo)}</span></div>` : ''}
        </div>
        <div class="det-card">
            <h4><i class="fas fa-user-tie"></i> Responsable</h4>
            <div class="det-row"><span class="lbl">Vendedor</span><span class="val">${esc(op.nombre_usuario)}</span></div>
            ${op.email_usuario ? `<div class="det-row"><span class="lbl">Email</span><span class="val">${esc(op.email_usuario)}</span></div>` : ''}
        </div>
    `;
    document.getElementById('detNotasWrap').innerHTML = op.notas
        ? `<div class="det-card"><h4><i class="fas fa-sticky-note"></i> Notas</h4><div class="det-notas">${esc(op.notas)}</div></div>`
        : '';

    // Contadores
    document.getElementById('detCntAct').textContent  = d.actividades.length;
    document.getElementById('detCntPres').textContent = d.presupuestos.length;
    document.getElementById('detCntArch').textContent = d.archivos.length;
    document.getElementById('detCntLog').textContent  = d.logs.length;

    // Renderizar contenidos
    renderActividades(d.actividades);
    renderPresupuestos(d.presupuestos);
    renderArchivos(d.archivos);
    renderLogs(d.logs);
    setInvitadosIniciales([]);
    // Sincronizar contadores móviles
    const mobAct = document.getElementById('detCntActMob');
    const mobLog = document.getElementById('detCntLogMob');
    if (mobAct) mobAct.textContent = d.actividades.length;
    if (mobLog) mobLog.textContent = d.logs.length;

    setDetTab('datos');
}

function setDetTab(t) {
    // Tabs y paneles del lado izquierdo
    document.querySelectorAll('.det-side-l .det-tab[data-dt]').forEach(b => b.classList.toggle('active', b.dataset.dt === t));
    document.querySelectorAll('.det-side-l .det-panel').forEach(p => p.classList.remove('active'));

    // Si es un tab que está físicamente en la columna derecha (móvil), redirigir
    const idIzq = 'detPanel' + t.charAt(0).toUpperCase() + t.slice(1);
    const elIzq = document.getElementById(idIzq);
    if (elIzq && !elIzq.classList.contains('panel-mobile-only')) {
        elIzq.classList.add('active');
        return;
    }

    // Tabs móviles (actividades / movimientos): clonar contenido al panel mobile
    const mob = document.getElementById('detPanel' + t.charAt(0).toUpperCase() + t.slice(1) + 'Mob');
    if (mob) {
        const original = document.getElementById('detPanel' + t.charAt(0).toUpperCase() + t.slice(1));
        if (original) {
            mob.innerHTML = '';
            mob.appendChild(original.cloneNode(true));
        }
        mob.classList.add('active');
    }
}

function setDetTabR(t) {
    document.querySelectorAll('.det-side-r .det-tab[data-dt-r]').forEach(b => b.classList.toggle('active', b.dataset.dtR === t));
    document.querySelectorAll('.det-side-r .det-panel-r').forEach(p => p.classList.remove('active'));
    const el = document.getElementById('detPanel' + t.charAt(0).toUpperCase() + t.slice(1));
    if (el) el.classList.add('active');

    // Outlook checkbox solo en actividades
    if (t === 'actividades') {
        const label = document.getElementById('labelOutlookCheck');
        if (label) label.style.display = outlookConectado ? 'flex' : 'none';
        const chk = document.getElementById('chkOutlook');
        if (chk && outlookConectado) chk.checked = true;
    }
}

/* ── Render actividades ────────────────────────────────── */
const tipoIcon = { Llamada:'📞', Reunion:'🤝', Correo:'📧', 'Actualización de quote':'📋', Visita:'🏢' };

function renderActividades(lista) {
    const div = document.getElementById('listaAct');
    if (!lista?.length) {
        div.innerHTML = `<div class="col-empty" style="padding:20px 0;">
            <i class="fas fa-comments" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
            Sin actividades</div>`;
        const cnt = document.getElementById('detCntAct');
        if (cnt) cnt.textContent = lista.length || 0;
        return;
    }
    div.innerHTML = lista.map(a => {
        const horario = a.fecha_fin
            ? `${fmtDt(a.fecha_proximo_paso)} → ${new Date(a.fecha_fin).toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'})}`
            : fmtDt(a.fecha_proximo_paso);
        const inv = a.invitados_nombres
            ? `<div style="font-size:.74rem;color:var(--ink-2);margin-top:3px;"><i class="fas fa-users" style="font-size:.7rem;"></i> ${esc(a.invitados_nombres)}</div>`
            : '';
        const evtChip = a.eventos_creados > 0
            ? ` <i class="fas fa-calendar-check" style="color:var(--blue);font-size:.75rem;margin-left:3px;" title="${a.eventos_creados} evento(s) en Outlook"></i>`
            : '';
        return `
        <div class="act-item" data-id="${a.id}" data-tipo="${esc(a.tipo)}">
            <div class="act-row">
                <span>
                    <span class="act-tipo">${tipoIcon[a.tipo] || '•'} ${esc(a.tipo)}</span>
                    <span class="act-who">${esc(a.nombre_usuario)}</span>${evtChip}
                </span>
                <span class="act-ts">${fmtDt(a.fecha_creacion)}</span>
            </div>
            <div class="act-prox">
                <i class="fas fa-calendar"></i> <strong>Cuándo:</strong> ${horario}
                ${a.proximo_paso ? `<span style="color:var(--ink-2);margin-left:4px;">· ${esc(a.proximo_paso)}</span>` : ''}
            </div>
            ${inv}
            ${a.resultado
                ? `<div class="act-body"><strong>Resultado:</strong> ${esc(a.resultado)}</div>`
                : `<div style="margin-top:4px;">
                    <button onclick="mostrarFormResultado(${a.id})"
                            style="background:none;border:none;cursor:pointer;color:var(--blue);font-size:.78rem;padding:2px 0;display:inline-flex;align-items:center;gap:4px;">
                        <i class="fas fa-plus-circle"></i> Registrar resultado
                    </button>
                    <div id="resultForm_${a.id}" style="display:none;margin-top:6px;">
                        <textarea id="resultText_${a.id}"
                                  style="width:100%;min-height:60px;box-sizing:border-box;border:1px solid var(--border);border-radius:6px;padding:6px;font-size:.82rem;resize:vertical;"
                                  placeholder="¿Qué ocurrió en esta actividad?"></textarea>
                        <div style="display:flex;gap:8px;margin-top:4px;">
                            <button class="btn btn-green" style="font-size:.78rem;padding:4px 10px;" onclick="guardarResultado(${a.id})">
                                <i class="fas fa-save"></i> Guardar
                            </button>
                            <button class="btn btn-ghost" style="font-size:.78rem;padding:4px 10px;" onclick="mostrarFormResultado(${a.id})">
                                Cancelar
                            </button>
                        </div>
                    </div>
                  </div>`
            }
        </div>`;
    }).join('');
    const cnt = document.getElementById('detCntAct');
    if (cnt) cnt.textContent = lista.length || 0;
}

function mostrarFormResultado(actId) {
    const form = document.getElementById(`resultForm_${actId}`);
    if (!form) return;
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

async function guardarResultado(actId) {
    const texto = document.getElementById(`resultText_${actId}`)?.value?.trim();
    if (!texto) { toast('Escribe qué ocurrió en la actividad', 'err'); return; }
    const r = await fetch('crear_oportunidad.php?action=update_actividad', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ actividad_id: actId, resultado: texto })
    });
    const d = await r.json();
    if (d.success) {
        renderActividades(d.actividades);
        toast('Resultado registrado ✓');
    } else {
        toast(d.message || 'Error', 'err');
    }
}

/* ── Render presupuestos ───────────────────────────────── */
function renderPresupuestos(lista) {
    const div = document.getElementById('listaPres');
    if (!lista?.length) {
        div.innerHTML = `<div class="col-empty" style="padding:20px 0;">
            <i class="fas fa-file-alt" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
            Sin presupuestos vinculados</div>`;
        const cnt = document.getElementById('detCntPres');
        if (cnt) cnt.textContent = lista.length || 0;
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const filtros = {};
    ['sucursal', 'usuario', 'estado', 'buscar', 'pagina'].forEach(key => {
        if (params.get(key)) filtros[key] = params.get(key);
    });
    const queryString = Object.keys(filtros).length > 0 ? '&' + new URLSearchParams(filtros).toString() : '';

    div.innerHTML = lista.map(p => `
        <div class="pres-item">
            <div class="pres-info">
                <strong>#${p.numero_proyecto} — ${esc(p.titulo)}</strong>
                <span>${esc(p.cliente)} · Bs ${numFmt(p.monto_total)}</span>
            </div>
            <div class="pres-actions">
                <span class="badge-estado badge-${p.estado}">${p.estado}</span>
                <a href="ver_proyecto.php?id=${p.id_proyecto}&from=oportunidades${queryString}"
                   style="color:var(--blue);" title="Abrir presupuesto">
                    <i class="fas fa-external-link-alt"></i>
                </a>
                <button onclick="desvincular(${p.id_proyecto})"
                        style="background:none;border:none;cursor:pointer;color:var(--red);"
                        title="Desvincular">
                    <i class="fas fa-unlink"></i>
                </button>
            </div>
        </div>
    `).join('');
    const cnt = document.getElementById('detCntPres');
    if (cnt) cnt.textContent = lista.length || 0;
}

/* ── Crear presupuesto desde oportunidad ───────────────── */
function renderSelectDisponibles(lista) {
    const sel = document.getElementById('selPres');
    sel.innerHTML = '<option value="">— Vincular presupuesto existente —</option>';
    (lista || []).forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id_proyecto;
        opt.textContent = `#${p.numero_proyecto} — ${p.titulo} (${p.cliente}) [${p.estado}]`;
        sel.appendChild(opt);
    });
    // Mostrar u ocultar el row de vincular según si hay disponibles
    const row = sel.closest('.link-row');
    if (row) row.style.display = lista?.length ? 'flex' : 'none';
}

async function crearPresupuestoDesdeOp() {
    if (!opId) { toast('Guarda la oportunidad primero', 'err'); return; }

    const r = await fetch('crear_oportunidad.php?action=crear_presupuesto', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ oportunidad_id: opId })
    });
    const d = await r.json();
    if (d.success) {
        toast('Presupuesto creado');
        const params = new URLSearchParams(window.location.search);
        const filtros = {};
        ['sucursal', 'usuario', 'estado', 'buscar', 'pagina'].forEach(key => {
            if (params.get(key)) filtros[key] = params.get(key);
        });
        const queryString = Object.keys(filtros).length > 0 ? '&' + new URLSearchParams(filtros).toString() : '';

        window.location.href = `ver_proyecto.php?id=${d.id_proyecto}&from=oportunidades${queryString}`;
    } else {
        toast(d.message || 'Error al crear presupuesto', 'err');
    }
}

/* ── Guardar oportunidad ───────────────────────────────── */
async function guardar() {
    if (!document.getElementById('fCliente').value) {
        toast('Selecciona un cliente de la lista', 'err');
        document.getElementById('clienteSearch').focus(); return;
    }
    const form = document.getElementById('fOp');
    if (!form.reportValidity()) return;
    const payload = Object.fromEntries(new FormData(form));
    try {
        const r = await fetch('crear_oportunidad.php?action=save', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.success) {
            toast('Guardado correctamente ✓');
            // Si era nueva, ir al detalle. Si era edición, volver al detalle.
            const id = d.id || opId;
            opId = id;
            setTimeout(() => verOp(id), 400);
        } else toast(d.message || 'Error al guardar', 'err');
    } catch (e) { toast('Error de conexión', 'err'); }
}

/* ── Guardar actividad ─────────────────────────────────── */
async function guardarActividad() {
    if (!opId) return;
    const fechaIni = document.getElementById('actFechaIni').value;
    const fechaFin = document.getElementById('actFechaFin').value;
    if (!fechaIni) { toast('La fecha/hora de inicio es obligatoria', 'err'); return; }
    if (!fechaFin) { toast('La fecha/hora de fin es obligatoria', 'err'); return; }
    if (new Date(fechaFin) <= new Date(fechaIni)) {
        toast('La hora fin debe ser posterior a la de inicio', 'err'); return;
    }
    const payload = {
        oportunidad_id:     opId,
        tipo:               document.getElementById('actTipo').value,
        proximo_paso:       document.getElementById('actProximo').value,
        fecha_proximo_paso: fechaIni,
        fecha_fin:          fechaFin,
        enviar_outlook:     document.getElementById('chkOutlook')?.checked ?? false,
        invitados:          invitadosSel,
    };
    const r = await fetch('crear_oportunidad.php?action=save_actividad', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.success) {
        document.getElementById('actProximo').value   = '';
        document.getElementById('actFechaIni').value  = '';
        document.getElementById('actFechaFin').value  = '';
        if (document.getElementById('chkOutlook')) document.getElementById('chkOutlook').checked = false;
        setInvitadosIniciales([]);
        renderActividades(d.actividades);
        refrescarLogs();
        if (detalleData) {
            document.getElementById('detCntAct').textContent = d.actividades.length;
        }
        toast(d.ms_resumen || 'Actividad registrada', d.ms_errores?.length ? 'err' : 'ok');
    } else toast(d.message || 'Error', 'err');
}

let outlookConectado = false;

async function cargarEstadoOutlook() {
    try {
        const r = await fetch('crear_oportunidad.php?action=ms_status');
        const d = await r.json();
        outlookConectado = d.conectado;
        renderOutlookStatus(d);
    } catch(e) {
        // Silenciar — no crítico
    }
}

function renderOutlookStatus(d) {
    const wrap = document.getElementById('outlookStatus');
    if (!wrap) return;
    const label = document.getElementById('labelOutlookCheck');

    if (d.conectado) {
        wrap.innerHTML = `
            <span class="outlook-chip connected" title="Calendario Exchange activo">
                <i class="fas fa-calendar-check"></i>
                ${esc(d.email)}
            </span>`;
        if (label) label.style.display = 'flex';
        const chk = document.getElementById('chkOutlook');
        if (chk) chk.checked = true;
    } else {
        // Sin email corporativo en el perfil
        wrap.innerHTML = `
            <span class="outlook-chip disconnected"
                  title="Agrega tu correo @fils.bo en tu perfil para habilitar la integración con el calendario">
                <i class="fas fa-calendar-times"></i>
                Sin calendario vinculado
            </span>`;
        if (label) label.style.display = 'none';
    }
}

/* ── Vincular presupuesto ──────────────────────────────── */
async function vincular() {
    const pid = document.getElementById('selPres').value;
    if (!pid || !opId) { toast('Selecciona un presupuesto', 'err'); return; }
    const r = await fetch('crear_oportunidad.php?action=link_presupuesto', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({oportunidad_id: opId, proyecto_id: pid})
    });
    const d = await r.json();
    if (d.success) {
        renderPresupuestos(d.presupuestos);
        renderSelectDisponibles(d.disponibles);
        toast('Presupuesto vinculado');
    } else toast(d.message || 'Error', 'err');
}

async function desvincular(pid) {
    if (!confirm('¿Desvincular este presupuesto?') || !opId) return;
    const r = await fetch('crear_oportunidad.php?action=unlink_presupuesto', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({oportunidad_id: opId, proyecto_id: pid})
    });
    const d = await r.json();
    if (d.success) {
        renderPresupuestos(d.presupuestos);
        renderSelectDisponibles(d.disponibles);
        toast('Desvinculado');
    } else toast(d.message || 'Error', 'err');
}

/* ── Drag & drop ───────────────────────────────────────── */
let dragId = null;
document.querySelectorAll('.op-card').forEach(card => {
    card.addEventListener('dragstart', e => {
        dragId = card.dataset.id;
        setTimeout(() => card.classList.add('dragging'), 0);
        e.stopPropagation();
    });
    card.addEventListener('dragend', () => card.classList.remove('dragging'));
});
document.querySelectorAll('.k-cards').forEach(zone => {
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', async e => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (!dragId) return;
        const nuevaEtapa = zone.dataset.etapa;
        const card = document.querySelector(`.op-card[data-id="${dragId}"]`);
        if (card) {
            const empty = zone.querySelector('.col-empty');
            if (empty) empty.remove();
            zone.appendChild(card);
        }
        await fetch('crear_oportunidad.php?action=mover', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({id: dragId, etapa_id: nuevaEtapa})
        });
        dragId = null;
        setTimeout(() => location.reload(), 800);
    });
});

/* ── Helpers ───────────────────────────────────────────── */
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function fmtDt(s) {
    if (!s) return '';
    return new Date(s).toLocaleString('es-BO', {
        day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'
    });
}

function numFmt(n) {
    return parseFloat(n || 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// Inicializar vista y estado Outlook al cargar la página
setVista(vistaActual);
cargarEstadoOutlook();

// Mostrar mensaje si venimos del callback de Outlook
(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('ms_ok')) {
        toast('✓ Outlook conectado correctamente 📅');
        history.replaceState({}, '', 'oportunidades.php');
    } else if (params.get('ms_error')) {
        toast('Error Outlook: ' + params.get('ms_error'), 'err');
        history.replaceState({}, '', 'oportunidades.php');
    }
})();

/* ════════════════════════════════════════════════════════════
   INVITADOS — chips y dropdown
════════════════════════════════════════════════════════════ */

function setInvitadosIniciales(ids) {
    // Asegurar que el creador está siempre incluido y no removible
    invitadosSel = Array.from(new Set([MI_USUARIO_ID, ...(ids || [])]));
    renderInvitadosChips();
}

function renderInvitadosChips() {
    const cont = document.getElementById('invitadosChips');
    cont.innerHTML = invitadosSel.map(id => {
        const u = USUARIOS_INVITABLES.find(x => x.id == id);
        if (!u) return '';
        const esCreador = id === MI_USUARIO_ID;
        return `<span class="inv-chip">${esc(u.nombre)}${esCreador ? '' :
            `<span class="inv-chip-x" onclick="quitarInvitado(${id})">×</span>`}</span>`;
    }).join('');
}

function quitarInvitado(id) {
    if (id === MI_USUARIO_ID) return;
    invitadosSel = invitadosSel.filter(x => x !== id);
    renderInvitadosChips();
}

function filtrarInvitados() {
    const q = document.getElementById('invitadosSearch').value.toLowerCase().trim();
    const dd = document.getElementById('invitadosDropdown');
    invHighlight = -1;
    const disponibles = USUARIOS_INVITABLES.filter(u =>
        !invitadosSel.includes(u.id) &&
        (!q || u.nombre.toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q))
    ).slice(0, 20);
    if (!disponibles.length) {
        dd.innerHTML = '<div class="inv-option" style="color:var(--ink-3);cursor:default;">Sin resultados</div>';
    } else {
        dd.innerHTML = disponibles.map((u, i) => `
            <div class="inv-option" data-id="${u.id}" data-idx="${i}"
                 onmousedown="agregarInvitado(${u.id})">
                ${esc(u.nombre)}<div class="ie">${esc(u.email || '— sin email')}</div>
            </div>`).join('');
    }
    dd.classList.add('open');
}

function agregarInvitado(id) {
    if (!invitadosSel.includes(id)) {
        invitadosSel.push(id);
        renderInvitadosChips();
    }
    document.getElementById('invitadosSearch').value = '';
    document.getElementById('invitadosDropdown').classList.remove('open');
}

function invitadosKeydown(e) {
    const dd = document.getElementById('invitadosDropdown');
    const opts = dd.querySelectorAll('.inv-option[data-id]');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        invHighlight = Math.min(invHighlight + 1, opts.length - 1);
        opts.forEach((o, i) => o.classList.toggle('highlighted', i === invHighlight));
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        invHighlight = Math.max(invHighlight - 1, 0);
        opts.forEach((o, i) => o.classList.toggle('highlighted', i === invHighlight));
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (invHighlight >= 0 && opts[invHighlight]) {
            agregarInvitado(parseInt(opts[invHighlight].dataset.id));
        }
    } else if (e.key === 'Backspace' && !e.target.value && invitadosSel.length > 1) {
        const last = invitadosSel[invitadosSel.length - 1];
        if (last !== MI_USUARIO_ID) quitarInvitado(last);
    }
}

/* ── Preset de duración ── */
function aplicarPresetDuracion() {
    const min = parseInt(document.getElementById('actDurPreset').value);
    const ini = document.getElementById('actFechaIni').value;
    if (!min || !ini) return;
    const d = new Date(ini);
    d.setMinutes(d.getMinutes() + min);
    document.getElementById('actFechaFin').value = d.toISOString().slice(0,16);
}

// Cuando cambia inicio, ajustar fin si hay preset
document.addEventListener('DOMContentLoaded', () => {
    const ini = document.getElementById('actFechaIni');
    if (ini) ini.addEventListener('change', aplicarPresetDuracion);
});

/* ════════════════════════════════════════════════════════════
   CALENDARIO
════════════════════════════════════════════════════════════ */

function setCalMode(m) {
    cerrarPopoverActividad();
    calMode = m;
    document.getElementById('calModeMes').classList.toggle('active', m === 'mes');
    document.getElementById('calModeSemana').classList.toggle('active', m === 'semana');
    cargarCalendario();
}

function calNav(dir) {
    cerrarPopoverActividad();
    if (calMode === 'mes') {
        calCursor.setMonth(calCursor.getMonth() + dir);
    } else {
        calCursor.setDate(calCursor.getDate() + dir * 7);
    }
    cargarCalendario();
}

function calHoy() {
    cerrarPopoverActividad();
    calCursor = new Date();
    cargarCalendario();
}

async function cargarCalendario() {
    let desde, hasta;
    if (calMode === 'mes') {
        desde = new Date(calCursor.getFullYear(), calCursor.getMonth(), 1);
        hasta = new Date(calCursor.getFullYear(), calCursor.getMonth() + 1, 0);
    } else {
        const dow = calCursor.getDay(); // 0=dom
        const offsetMon = dow === 0 ? -6 : 1 - dow;
        desde = new Date(calCursor); desde.setDate(calCursor.getDate() + offsetMon);
        hasta = new Date(desde);     hasta.setDate(desde.getDate() + 6);
    }
    const fmt = d => d.toISOString().slice(0,10);
    const r = await fetch(`crear_oportunidad.php?action=calendario&desde=${fmt(desde)} 00:00:00&hasta=${fmt(hasta)} 23:59:59`);
    const d = await r.json();
    calData = d.actividades || [];
    renderCalendario();
}

function renderCalendario() {
    const tit = document.getElementById('calTitulo');
    if (calMode === 'mes') {
        tit.textContent = calCursor.toLocaleDateString('es-BO', {month:'long', year:'numeric'});
        renderCalMes();
    } else {
        renderCalSemana(tit);
    }
}

function tipoClass(t) {
    if (t === 'Actualización de quote') return 'cal-quote';
    return 'cal-' + t;
}

function renderCalMes() {
    const año = calCursor.getFullYear(), mes = calCursor.getMonth();
    const primer = new Date(año, mes, 1);
    const ultimo = new Date(año, mes + 1, 0);
    const dowIni = primer.getDay() === 0 ? 6 : primer.getDay() - 1; // lunes=0
    const totalDias = ultimo.getDate();
    const hoy = new Date(); hoy.setHours(0,0,0,0);

    // Indexar eventos por fecha YYYY-MM-DD
    const porDia = {};
    calData.forEach(a => {
        const k = a.fecha_inicio.slice(0,10);
        (porDia[k] = porDia[k] || []).push(a);
    });

    let html = `<div class="cal-month"><div class="cal-month-grid">`;
    ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'].forEach(d => html += `<div class="cal-dow">${d}</div>`);

    // Días del mes anterior para llenar la primera fila
    const prevUlt = new Date(año, mes, 0).getDate();
    for (let i = dowIni; i > 0; i--) {
        html += `<div class="cal-day cal-other"><div class="cal-daynum">${prevUlt - i + 1}</div></div>`;
    }

    for (let d = 1; d <= totalDias; d++) {
        const fecha = new Date(año, mes, d);
        const k = fecha.toISOString().slice(0,10);
        const isToday = fecha.getTime() === hoy.getTime();
        const evs = porDia[k] || [];
        html += `<div class="cal-day${isToday ? ' cal-today' : ''}">
            <div class="cal-daynum">${d}</div>
            ${evs.slice(0,3).map(e => `
                <div class="cal-evt ${tipoClass(e.tipo)}" data-evt-id="${e.id}" title="${esc(e.tipo)} — ${esc(e.oportunidad_titulo)}">
                    ${new Date(e.fecha_inicio).toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'})}
                    ${esc(e.oportunidad_titulo)}
                </div>`).join('')}
            ${evs.length > 3 ? `<div style="font-size:.65rem;color:var(--ink-3);padding:0 5px;">+${evs.length - 3} más</div>` : ''}
        </div>`;
    }

    // Rellenar días sobrantes
    const totalCeldas = dowIni + totalDias;
    const restantes   = (7 - totalCeldas % 7) % 7;
    for (let i = 1; i <= restantes; i++) {
        html += `<div class="cal-day cal-other"><div class="cal-daynum">${i}</div></div>`;
    }

    html += `</div></div>`;
    document.getElementById('calContenido').innerHTML = html;

    // Bind clicks de eventos del mes al popover
    document.querySelectorAll('#calContenido .cal-evt[data-evt-id]').forEach(el => {
        el.addEventListener('click', e => {
            e.stopPropagation();
            const id = parseInt(el.dataset.evtId);
            const evt = calData.find(x => x.id === id);
            if (evt) abrirPopoverActividad(evt, el);
        });
    });
}

function renderCalSemana(tit) {
    const dow = calCursor.getDay();
    const offsetMon = dow === 0 ? -6 : 1 - dow;
    const lun = new Date(calCursor); lun.setDate(calCursor.getDate() + offsetMon); lun.setHours(0,0,0,0);
    const dom = new Date(lun); dom.setDate(lun.getDate() + 6);
    tit.textContent = `${lun.toLocaleDateString('es-BO',{day:'numeric',month:'short'})} – ${dom.toLocaleDateString('es-BO',{day:'numeric',month:'short',year:'numeric'})}`;

    const hoy = new Date(); hoy.setHours(0,0,0,0);
    const dowsLbl = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

    let html = `<div class="cal-week">
        <div class="cal-week-header">
            <div class="cal-week-dow"></div>`;
    for (let i = 0; i < 7; i++) {
        const f = new Date(lun); f.setDate(lun.getDate() + i);
        const isToday = f.getTime() === hoy.getTime();
        html += `<div class="cal-week-dow${isToday ? ' cal-today' : ''}">${dowsLbl[i]}<span class="dnum">${f.getDate()}</span></div>`;
    }
    html += `</div><div class="cal-week-grid">`;

    // Rejilla 7am-9pm (14 horas, 30min cada celda)
    const horaIni = 7, horaFin = 21;
    for (let h = horaIni; h < horaFin; h++) {
        html += `<div class="cal-hour-row">
            <div class="cal-hour-label">${String(h).padStart(2,'0')}:00</div>`;
        for (let d = 0; d < 7; d++) html += `<div class="cal-hour-cell" data-d="${d}" data-h="${h}"></div>`;
        html += `</div>`;
    }
    html += `</div></div>`;
    document.getElementById('calContenido').innerHTML = html;

    // Posicionar eventos sobre la rejilla
    const grid = document.querySelector('.cal-week-grid');
    if (!grid) return;
    const cellH = 30; // px

    calData.forEach(e => {
        const d = new Date(e.fecha_inicio);
        const dayIdx = (d.getDay() + 6) % 7; // lunes=0
        const fIni = new Date(lun); fIni.setHours(0,0,0,0);
        const fEvt = new Date(d);  fEvt.setHours(0,0,0,0);
        if (fEvt < fIni || (fEvt - fIni) / 86400000 > 6) return;

        const horas = d.getHours() + d.getMinutes() / 60;
        if (horas < horaIni || horas >= horaFin) return;

        const finDate = e.fecha_fin ? new Date(e.fecha_fin) : new Date(d.getTime() + 1800000);
        const durMin  = Math.max(15, (finDate - d) / 60000);
        const top     = (horas - horaIni) * 2 * cellH;
        const height  = (durMin / 30) * cellH;

        const cell = grid.querySelector(`.cal-hour-cell[data-d="${dayIdx}"][data-h="${horaIni}"]`);
        if (!cell) return;
        const colLeft = cell.offsetLeft;
        const colW    = cell.offsetWidth;

        const ev = document.createElement('div');
        ev.className = 'cal-week-evt ' + tipoClass(e.tipo);
        ev.style.left   = (colLeft + 2) + 'px';
        ev.style.width  = (colW - 4) + 'px';
        ev.style.top    = top + 'px';
        ev.style.height = (height - 2) + 'px';
        ev.title = `${e.tipo} — ${e.oportunidad_titulo} (${e.cliente_nombre})`;
        ev.innerHTML = `<strong>${d.toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'})}</strong> ${esc(e.oportunidad_titulo)}`;
        ev.addEventListener('click', evClick => {
            evClick.stopPropagation();
            abrirPopoverActividad(e, ev);
        });
        grid.appendChild(ev);
    });
}

/* ── Popover de actividad ── */
function abrirPopoverActividad(evt, anchor) {
    cerrarPopoverActividad();

    const tipoIcons = {
        'Llamada':                '📞',
        'Reunion':                '🤝',
        'Correo':                 '📧',
        'Actualización de quote': '📋',
        'Visita':                 '🏢'
    };
    const tipoCls = evt.tipo === 'Actualización de quote' ? 't-quote' : 't-' + evt.tipo;

    const ini = new Date(evt.fecha_inicio);
    const fin = evt.fecha_fin ? new Date(evt.fecha_fin) : null;
    const fechaStr = ini.toLocaleDateString('es-BO', {weekday:'long', day:'numeric', month:'long', year:'numeric'});
    const horaStr  = fin
        ? `${ini.toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'})} – ${fin.toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'})}`
        : ini.toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'});

    const pop = document.createElement('div');
    pop.className = 'evt-popover';
    pop.id = 'evtPopover';
    pop.innerHTML = `
        <div class="evt-pop-head ${tipoCls}">
            <span class="evt-pop-tipo">${tipoIcons[evt.tipo] || '•'} ${esc(evt.tipo)}</span>
            <button class="evt-pop-x" onclick="cerrarPopoverActividad()" title="Cerrar">×</button>
        </div>
        <div class="evt-pop-body">
            <div class="evt-pop-title">${esc(evt.oportunidad_titulo)}</div>
            <div class="evt-pop-row"><i class="fas fa-building"></i><span>${esc(evt.cliente_nombre)} <span style="color:var(--ink-3);">· #${String(evt.oportunidad_numero).padStart(4,'0')}</span></span></div>
            <div class="evt-pop-row"><i class="fas fa-calendar"></i><span><strong style="text-transform:capitalize;">${fechaStr}</strong><br>${horaStr}</span></div>
            ${evt.proximo_paso ? `<div class="evt-pop-row"><i class="fas fa-list-ul"></i><span>${esc(evt.proximo_paso)}</span></div>` : ''}
            ${evt.invitados ? `<div class="evt-pop-row"><i class="fas fa-users"></i><span>${esc(evt.invitados)}</span></div>` : `<div class="evt-pop-row"><i class="fas fa-user"></i><span>${esc(evt.nombre_usuario)}</span></div>`}
            ${evt.resultado ? `<div class="evt-pop-row"><i class="fas fa-check-circle"></i><span><strong>Resultado:</strong> ${esc(evt.resultado)}</span></div>` : ''}
        </div>
        <div class="evt-pop-foot">
            <button class="btn btn-ghost" onclick="cerrarPopoverActividad()">Cerrar</button>
            <button class="btn btn-blue" onclick="cerrarPopoverActividad();verOp(${evt.oportunidad_id})">
                <i class="fas fa-external-link-alt"></i> Ver oportunidad
            </button>
        </div>`;
    document.body.appendChild(pop);

    // Posicionar cerca del elemento clickeado
    const rect = anchor.getBoundingClientRect();
    const pw = 340, ph = pop.offsetHeight;
    const margin = 8;
    let left = rect.right + margin;
    let top  = rect.top;
    if (left + pw > window.innerWidth - margin) {
        left = rect.left - pw - margin;          // mostrar a la izquierda
        if (left < margin) left = Math.max(margin, (window.innerWidth - pw) / 2);
    }
    if (top + ph > window.innerHeight - margin)  top = window.innerHeight - ph - margin;
    if (top < margin) top = margin;
    pop.style.left = left + 'px';
    pop.style.top  = top + 'px';

    // Cerrar al hacer click afuera o con Escape
    setTimeout(() => {
        document.addEventListener('mousedown', popoverOutsideHandler);
        document.addEventListener('keydown',   popoverEscHandler);
    }, 0);
}

function cerrarPopoverActividad() {
    const pop = document.getElementById('evtPopover');
    if (pop) pop.remove();
    document.removeEventListener('mousedown', popoverOutsideHandler);
    document.removeEventListener('keydown',   popoverEscHandler);
}

function popoverOutsideHandler(e) {
    const pop = document.getElementById('evtPopover');
    if (pop && !pop.contains(e.target)) cerrarPopoverActividad();
}

function popoverEscHandler(e) {
    if (e.key === 'Escape') cerrarPopoverActividad();
}

function editarDesdeDetalle() {
    if (!detalleData) return;
    const op = detalleData.op;

    document.getElementById('fId').value          = op.id;
    document.getElementById('fTitulo').value      = op.titulo;
    document.getElementById('fCliente').value     = op.cliente_id;
    document.getElementById('clienteSearch').value = op.cliente_nombre;
    document.getElementById('fEtapa').value       = op.etapa_id;
    document.getElementById('fMonto').value       = op.monto_estimado;
    document.getElementById('fCierre').value      = op.fecha_cierre || '';
    document.getElementById('fProteccion').value  = op.proteccion || '';
    document.getElementById('fEstado').value      = op.estado;
    document.getElementById('fNotas').value       = op.notas || '';

    document.getElementById('formTitulo').textContent = 'Editar oportunidad';
    document.getElementById('formSub').textContent    = `#${String(op.numero).padStart(4,'0')} · ${op.cliente_nombre}`;

    document.getElementById('vistaDetalle').style.display = 'none';
    document.getElementById('vistaForm').style.display = '';
}

async function eliminarDesdeDetalle() {
    if (!opId || !confirm('¿Eliminar esta oportunidad? Esta acción no se puede deshacer.')) return;
    const r = await fetch('crear_oportunidad.php?action=delete', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: opId})
    });
    const d = await r.json();
    if (d.success) { toast('Eliminado'); setTimeout(() => location.reload(), 700); }
    else toast(d.message || 'Error', 'err');
}

/* ════════════════════════════════════════════════════════
   ARCHIVOS
════════════════════════════════════════════════════════ */
function renderArchivos(lista) {
    const div = document.getElementById('listaArch');
    if (!lista?.length) {
        div.innerHTML = '<div class="col-empty" style="padding:20px 0;"><i class="fas fa-folder-open" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>Sin archivos</div>';
        document.getElementById('detCntArch').textContent = 0;
        return;
    }
    div.innerHTML = lista.map(a => {
        const ext = (a.nombre_original.split('.').pop() || '').toLowerCase();
        let cls = '', icon = 'fa-file';
        if (['pdf'].includes(ext))                                      { cls = 'pdf'; icon = 'fa-file-pdf'; }
        else if (['jpg','jpeg','png','gif','webp','bmp'].includes(ext)) { cls = 'img'; icon = 'fa-file-image'; }
        else if (['doc','docx'].includes(ext))                          { cls = 'doc'; icon = 'fa-file-word'; }
        else if (['xls','xlsx','csv'].includes(ext))                    { cls = 'xls'; icon = 'fa-file-excel'; }
        else if (['zip','rar','7z'].includes(ext))                      { cls = 'zip'; icon = 'fa-file-archive'; }

        const tam = a.tamano_bytes < 1024*1024
            ? Math.round(a.tamano_bytes/1024) + ' KB'
            : (a.tamano_bytes/1024/1024).toFixed(1) + ' MB';

        const puedeEliminar = detalleData?.es_dueno;

        return `
        <div class="arch-item">
            <div class="arch-icon ${cls}"><i class="fas ${icon}"></i></div>
            <div class="arch-info">
                <div class="arch-name" title="${esc(a.nombre_original)}">${esc(a.nombre_original)}</div>
                <div class="arch-meta">${esc(a.nombre_usuario)} · ${fmtDt(a.fecha_subida)} · ${tam}</div>
            </div>
            <div class="arch-actions">
                <a href="crear_oportunidad.php?action=download_archivo&id=${a.id}" title="Descargar"><i class="fas fa-download"></i></a>
                ${puedeEliminar ? `<button onclick="eliminarArchivo(${a.id})" title="Eliminar"><i class="fas fa-trash"></i></button>` : ''}
            </div>
        </div>`;
    }).join('');
    document.getElementById('detCntArch').textContent = lista.length;
}

async function subirArchivos(files) {
    if (!opId) { toast('Guarda la oportunidad primero', 'err'); return; }
    if (!files?.length) return;

    const prog = document.getElementById('archProgress');
    const bar  = document.getElementById('archProgressBar');
    prog.classList.add('active');

    let i = 0;
    for (const f of files) {
        i++;
        if (f.size > 10 * 1024 * 1024) { toast(`"${f.name}" excede 10 MB`, 'err'); continue; }
        const fd = new FormData();
        fd.append('archivo', f);
        fd.append('oportunidad_id', opId);
        bar.style.width = (i / files.length * 100) + '%';
        try {
            const r = await fetch('crear_oportunidad.php?action=upload_archivo', {method:'POST', body: fd});
            const d = await r.json();
            if (d.success) {
                renderArchivos(d.archivos);
                toast(`✓ "${f.name}" subido`);
            } else toast(d.message || 'Error al subir', 'err');
        } catch(e) { toast('Error de red al subir ' + f.name, 'err'); }
    }
    setTimeout(() => { prog.classList.remove('active'); bar.style.width = '0'; }, 600);
    document.getElementById('archInput').value = '';
    // Refrescar logs por si hay nuevos
    refrescarLogs();
}

async function eliminarArchivo(aid) {
    if (!confirm('¿Eliminar este archivo? Esta acción no se puede deshacer.')) return;
    const r = await fetch('crear_oportunidad.php?action=delete_archivo', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: aid})
    });
    const d = await r.json();
    if (d.success) {
        renderArchivos(d.archivos);
        toast('Archivo eliminado');
        refrescarLogs();
    } else toast(d.message || 'Error', 'err');
}

async function refrescarLogs() {
    if (!opId) return;
    try {
        const r = await fetch(`crear_oportunidad.php?action=detalle&id=${opId}`);
        const d = await r.json();
        if (d.success) renderLogs(d.logs);
    } catch(e) {}
}

// Drag & drop sobre la zona de archivos
document.addEventListener('DOMContentLoaded', () => {
    const zone = document.getElementById('archZone');
    if (!zone) return;
    ['dragenter','dragover'].forEach(ev => {
        zone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); zone.classList.add('dragging'); });
    });
    ['dragleave','drop'].forEach(ev => {
        zone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); zone.classList.remove('dragging'); });
    });
    zone.addEventListener('drop', e => subirArchivos(e.dataTransfer.files));
});

/* ════════════════════════════════════════════════════════
   LOGS / MOVIMIENTOS
════════════════════════════════════════════════════════ */
const LOG_ICONS = {
    'creada':              'fa-plus',
    'estado_cambiado':     'fa-exchange-alt',
    'etapa_cambiada':      'fa-arrows-alt-h',
    'monto_cambiado':      'fa-coins',
    'archivo_subido':      'fa-cloud-upload-alt',
    'archivo_eliminado':   'fa-trash',
};

function renderLogs(lista) {
    const div = document.getElementById('listaLog');
    if (!lista?.length) {
        div.innerHTML = '<div class="col-empty" style="padding:20px 0;"><i class="fas fa-history" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>Sin movimientos registrados</div>';
        document.getElementById('detCntLog').textContent = 0;
        return;
    }
    div.innerHTML = lista.map(l => `
        <div class="log-item">
            <div class="log-icon l-${l.accion}"><i class="fas ${LOG_ICONS[l.accion] || 'fa-circle'}"></i></div>
            <div class="log-content">
                <div class="log-desc">${esc(l.descripcion || l.accion)}</div>
                <div class="log-meta">
                    <span><i class="fas fa-user"></i> ${esc(l.nombre_usuario)}</span>
                    <span><i class="fas fa-clock"></i> ${fmtDt(l.fecha_creacion)}</span>
                </div>
            </div>
        </div>
    `).join('');
    document.getElementById('detCntLog').textContent = lista.length;
}
</script>
</body>
</html>