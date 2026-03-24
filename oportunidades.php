<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("oportunidades", "ver");

$uid             = $_SESSION['usuario']['id'];
$puede_ver_todas = esSuperusuario();
$puede_crear     = tienePermiso('oportunidades', 'crear');
$puede_editar    = tienePermiso('oportunidades', 'editar');
$puede_eliminar  = tienePermiso('oportunidades', 'eliminar');

$filtro_usuario  = isset($_GET['usuario'])  ? intval($_GET['usuario'])  : null;
$filtro_sucursal = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : null;
$busqueda        = isset($_GET['buscar'])   ? trim($_GET['buscar'])     : '';

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

// Clientes para el modal
$clientes_list = $conn->query(
    "SELECT id, nombre, ciudad, sector FROM clientes ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// Presupuestos disponibles para vincular
$cond_pres = $puede_ver_todas ? '' : " AND p.id_usuario = $uid";
$presupuestos_disponibles = $conn->query("
    SELECT p.id_proyecto, p.numero_proyecto, p.titulo, p.cliente, e.estado
    FROM proyecto p
    JOIN estados e ON p.estado_id = e.id
    WHERE 1=1 $cond_pres
    ORDER BY p.numero_proyecto DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);
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
<style>
/* ─── TOKENS ────────────────────────────────────────────── */
:root {
    --green:     #16a34a;
    --green-d:   #15803d;
    --green-bg:  #f0fdf4;
    --ink:       #0f172a;
    --ink-2:     #334155;
    --ink-3:     #64748b;
    --border:    #e2e8f0;
    --bg:        #f1f5f9;
    --white:     #ffffff;
    --red:       #dc2626;
    --amber:     #d97706;
    --blue:      #2563eb;
    --font:      'Inter', sans-serif;
    --mono:      'JetBrains Mono', monospace;
    --radius:    10px;
    --sh-sm:     0 1px 3px rgba(0,0,0,.07);
    --sh-md:     0 4px 14px rgba(0,0,0,.09);
    --sh-lg:     0 20px 60px rgba(0,0,0,.16);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body { font-family: var(--font); background: var(--bg); color: var(--ink); font-size: 14px; }

/* ─── SHELL ─────────────────────────────────────────────── */
.shell {
    display: grid;
    grid-template-rows: 52px auto 1fr;
    height: 100vh;
    overflow: hidden;
}

/* ─── TOP BAR ───────────────────────────────────────────── */
.topbar {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    padding: 0 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.topbar img  { height: 100px; }
.topbar h1 {
    font-size: .95rem;
    font-weight: 700;
    color: var(--ink);
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
}
.topbar h1 i { color: var(--green); }

/* ─── TOOLBAR ───────────────────────────────────────────── */
.toolbar {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    padding: 7px 20px;
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}
.toolbar input[type="text"],
.toolbar select {
    font-family: var(--font);
    font-size: .8rem;
    padding: 5px 10px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--bg);
    color: var(--ink);
    outline: none;
    transition: border-color .15s;
}
.toolbar input[type="text"] { min-width: 200px; }
.toolbar input:focus,
.toolbar select:focus { border-color: var(--green); background: #fff; }

/* Botones genéricos */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 13px;
    border-radius: 7px;
    font-family: var(--font);
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: filter .15s, transform .1s;
    text-decoration: none;
    white-space: nowrap;
}
.btn:active    { transform: scale(.97); }
.btn-green     { background: var(--green);  color: #fff; }
.btn-green:hover { filter: brightness(1.08); }
.btn-ghost     { background: transparent; color: var(--ink-3); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg); color: var(--ink); }
.btn-red       { background: var(--red);    color: #fff; }
.btn-red:hover { filter: brightness(1.08); }
.btn-outline   { background: transparent; color: var(--green); border: 1px solid var(--green); }
.btn-outline:hover { background: var(--green-bg); }

.toolbar-meta {
    margin-left: auto;
    font-family: var(--mono);
    font-size: .72rem;
    color: var(--ink-3);
}

/* ─── KANBAN ────────────────────────────────────────────── */
.kanban-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    padding: 14px 20px 16px;
    height: 100%;
}
.kanban-board {
    display: flex;
    gap: 10px;
    height: 100%;
    min-width: max-content;
    align-items: flex-start;
}

/* Columna */
.k-col {
    width: 256px;
    flex-shrink: 0;
    background: #e8edf2;
    border-radius: var(--radius);
    border: 1px solid #d0d9e4;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 128px);
}

.k-col-head {
    padding: 10px 12px 8px;
    border-radius: var(--radius) var(--radius) 0 0;
    flex-shrink: 0;
}
.k-col-head .hrow {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2px;
}
.k-col-head .hname {
    font-weight: 700;
    font-size: .8rem;
    color: #fff;
    letter-spacing: .2px;
}
.k-badge {
    background: rgba(255,255,255,.2);
    color: #fff;
    border-radius: 20px;
    padding: 0 7px;
    font-size: .7rem;
    font-weight: 700;
    font-family: var(--mono);
}
.k-col-head .htotal {
    font-size: .72rem;
    color: rgba(255,255,255,.78);
    font-family: var(--mono);
}

/* Cards scroll */
.k-cards {
    flex: 1;
    overflow-y: auto;
    padding: 7px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    scrollbar-width: thin;
    scrollbar-color: #b0bec5 transparent;
}
.k-cards::-webkit-scrollbar { width: 3px; }
.k-cards::-webkit-scrollbar-thumb { background: #b0bec5; border-radius: 3px; }
.k-cards.drag-over {
    background: rgba(22,163,74,.06);
    border-radius: 7px;
    outline: 2px dashed #86efac;
    outline-offset: -2px;
}

/* Tarjeta */
.op-card {
    background: var(--white);
    border-radius: 8px;
    padding: 10px 12px;
    box-shadow: var(--sh-sm);
    cursor: pointer;
    transition: box-shadow .15s, transform .12s;
    border-left: 3px solid transparent;
    user-select: none;
}
.op-card:hover { box-shadow: var(--sh-md); transform: translateY(-1px); }
.op-card.dragging { opacity: .4; cursor: grabbing; transform: rotate(1deg) scale(.98); }

.card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4px;
}
.card-num {
    font-family: var(--mono);
    font-size: .65rem;
    color: var(--ink-3);
}
.card-estado-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 3px;
}
.dot-Activo  { background: var(--green); }
.dot-Ganado  { background: var(--amber); }
.dot-Perdido { background: var(--red); }

.card-titulo {
    font-weight: 700;
    font-size: .83rem;
    color: var(--ink);
    line-height: 1.3;
    margin-bottom: 3px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.card-cliente {
    font-size: .76rem;
    color: var(--ink-3);
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.card-monto {
    font-family: var(--mono);
    font-size: .88rem;
    font-weight: 700;
    color: var(--green);
    margin-bottom: 6px;
}
.prob-bar {
    height: 3px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 6px;
}
.prob-fill {
    height: 100%;
    border-radius: 3px;
}
.card-chips {
    display: flex;
    gap: 3px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: .65rem;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 20px;
}
.chip-pres   { background: #eff6ff; color: #1d4ed8; }
.chip-act    { background: #fefce8; color: #854d0e; }
.chip-sector { background: #f5f3ff; color: #5b21b6; }
.chip-prox-ok   { background: #f0fdf4; color: #166534; }
.chip-prox-hoy  { background: #fffbeb; color: #92400e; }
.chip-prox-venc { background: #fef2f2; color: #991b1b; }

.card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.avatar {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: var(--green);
    color: #fff;
    font-size: .58rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-transform: uppercase;
    flex-shrink: 0;
}
.card-prot {
    font-size: .65rem;
    color: var(--amber);
    display: flex;
    align-items: center;
    gap: 3px;
}

.k-add {
    margin: 3px 7px 7px;
    padding: 5px 9px;
    border: 1px dashed #94a3b8;
    border-radius: 7px;
    background: transparent;
    color: #94a3b8;
    font-family: var(--font);
    font-size: .75rem;
    cursor: pointer;
    transition: all .15s;
    flex-shrink: 0;
    text-align: left;
}
.k-add:hover { border-color: var(--green); color: var(--green); background: var(--green-bg); }

.col-empty {
    text-align: center;
    color: #cbd5e1;
    font-size: .75rem;
    padding: 18px 8px;
    pointer-events: none;
}

/* ─── MODAL ─────────────────────────────────────────────── */
.overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.48);
    z-index: 1000;
    align-items: flex-start;
    justify-content: center;
    padding: 20px 16px;
    overflow-y: auto;
}
.overlay.show { display: flex; }

.modal {
    background: var(--white);
    border-radius: 14px;
    width: 100%;
    max-width: 720px;
    box-shadow: var(--sh-lg);
    animation: mUp .2s ease;
    flex-shrink: 0;
    margin: auto;
}
@keyframes mUp {
    from { opacity:0; transform: translateY(16px) scale(.98); }
    to   { opacity:1; transform: none; }
}

.mhead {
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.mhead-info { flex:1; min-width:0; }
.mhead-info h2 {
    font-size: .97rem;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mhead-info .sub {
    font-size: .72rem;
    color: var(--ink-3);
    font-family: var(--mono);
    margin-top: 2px;
}
.mhead-x {
    background: none;
    border: none;
    font-size: 1.2rem;
    color: var(--ink-3);
    cursor: pointer;
    padding: 0;
    line-height: 1;
    flex-shrink: 0;
}
.mhead-x:hover { color: var(--red); }

/* Tabs */
.mtabs {
    display: flex;
    border-bottom: 1px solid var(--border);
    padding: 0 22px;
}
.tab-btn {
    font-family: var(--font);
    font-size: .8rem;
    font-weight: 600;
    color: var(--ink-3);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 12px;
    cursor: pointer;
    transition: color .13s, border-color .13s;
    margin-bottom: -1px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.tab-btn:hover { color: var(--ink); }
.tab-btn.active { color: var(--green); border-bottom-color: var(--green); }

.tab-panel { display: none; padding: 18px 22px; }
.tab-panel.active { display: block; }

/* Formulario */
.fg { margin-bottom: 12px; }
.fg label {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    color: var(--ink-3);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 4px;
}
.fg input, .fg select, .fg textarea {
    font-family: var(--font);
    font-size: .83rem;
    width: 100%;
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: #fff;
    color: var(--ink);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.fg input:focus, .fg select:focus, .fg textarea:focus {
    border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(22,163,74,.1);
}
.fg textarea { min-height: 68px; resize: vertical; }

.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.span2 { grid-column: 1 / -1; }

/* Actividades */
.act-list { display: flex; flex-direction: column; gap: 7px; margin-bottom: 12px; }
.act-item {
    border-radius: 7px;
    padding: 9px 12px;
    font-size: .8rem;
    border-left: 3px solid var(--border);
    background: var(--bg);
}
.act-item[data-tipo="Llamada"]               { border-color: #3b82f6; }
.act-item[data-tipo="Reunion"]               { border-color: #7c3aed; }
.act-item[data-tipo="Correo"]                { border-color: #f59e0b; }
.act-item[data-tipo="Actualización de quote"]{ border-color: #10b981; }
.act-item[data-tipo="Visita"]                { border-color: #dc2626; }

.act-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 3px; }
.act-tipo { font-size: .68rem; font-weight: 700; background: #e2e8f0; color: var(--ink-2); padding: 1px 7px; border-radius: 20px; margin-right: 5px; }
.act-who  { font-weight: 600; font-size: .8rem; }
.act-ts   { font-size: .68rem; color: var(--ink-3); font-family: var(--mono); }
.act-body { color: var(--ink-2); line-height: 1.4; }
.act-prox {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 4px;
    font-size: .72rem;
    color: var(--green);
    background: var(--green-bg);
    padding: 2px 8px;
    border-radius: 6px;
}

.act-form {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 13px;
    margin-top: 6px;
}
.act-form .form-label {
    font-size: .72rem;
    font-weight: 700;
    color: var(--ink-3);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 9px;
}

/* Presupuestos */
.pres-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
.pres-item {
    display: flex;
    align-items: center;
    gap: 9px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 7px;
    padding: 8px 11px;
    font-size: .8rem;
}
.pres-info { flex:1; min-width:0; }
.pres-info strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pres-info span   { font-size: .72rem; color: var(--ink-3); }
.pres-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

.link-row { display: flex; gap: 7px; align-items: center; }
.link-row select { flex: 1; font-family: var(--font); font-size: .8rem; padding: 6px 9px; border: 1px solid var(--border); border-radius: 7px; outline: none; }
.link-row select:focus { border-color: var(--green); }

.badge-estado {
    display: inline-block;
    font-size: .65rem;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 20px;
}
.badge-Activo  { background: #dcfce7; color: #166534; }
.badge-Ganado  { background: #fef9c3; color: #854d0e; }
.badge-Perdido { background: #fee2e2; color: #991b1b; }

/* Footer modal */
.mfoot {
    padding: 12px 22px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 7px;
    justify-content: flex-end;
    align-items: center;
}

/* Toast */
.toasts {
    position: fixed;
    bottom: 18px;
    right: 18px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 7px;
    pointer-events: none;
}
.toast {
    background: var(--ink);
    color: #fff;
    padding: 9px 16px;
    border-radius: 9px;
    font-size: .8rem;
    box-shadow: var(--sh-md);
    animation: tIn .22s ease;
    pointer-events: auto;
}
.toast.ok  { background: var(--green); }
.toast.err { background: var(--red); }
@keyframes tIn { from { opacity:0; transform:translateX(14px); } to { opacity:1; transform:none; } }

/* Scrollbar global */
* { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

@media(max-width:580px) {
    .g2,.g3 { grid-template-columns:1fr; }
    .span2  { grid-column:1; }
    .topbar h1 span { display:none; }
}
</style>
</head>
<body>
<div class="shell">

<!-- ─── TOP BAR ─────────────────────────────────────────── -->
<div class="topbar">
    <img src="assets/logo.png" alt="Logo">
    <h1>
        <i class="fas fa-chart-line"></i>
        <span>Pipeline de Oportunidades</span>
    </h1>
    <a href="dashboard.php" class="btn btn-ghost" style="font-size:.76rem;padding:4px 11px;">
        <i class="fas fa-arrow-left"></i> Dashboard
    </a>
</div>

<!-- ─── TOOLBAR ─────────────────────────────────────────── -->
<div class="toolbar">
    <form method="get" style="display:contents;">

        <?php if ($puede_crear): ?>
        <button type="button" class="btn btn-green" onclick="abrirNuevo()">
            <i class="fas fa-plus"></i> Nueva
        </button>
        <?php endif; ?>

        <input type="text" name="buscar"
               placeholder="&#128269; Buscar título o cliente…"
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

    <span class="toolbar-meta">
        <?= count($todas) ?> oport. &bull;
        Bs <?= number_format(array_sum(array_column($todas, 'monto_estimado')), 2, ',', '.') ?>
    </span>
</div>

<!-- ─── KANBAN ───────────────────────────────────────────── -->
<div class="kanban-scroll">
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
            // Iniciales del usuario
            $parts = explode(' ', $op['nombre_usuario']);
            $ini   = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

            // Próximo paso
            $chip_prox = '';
            if ($op['proximo_paso_fecha']) {
                $diff = (strtotime($op['proximo_paso_fecha']) - time()) / 86400;
                if ($diff < 0)  $chip_prox = 'chip-prox-venc';
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
                <span class="card-estado-dot dot-<?= $op['estado'] ?>"
                      title="<?= $op['estado'] ?>"></span>
            </div>

            <div class="card-titulo"><?= htmlspecialchars($op['titulo']) ?></div>

            <div class="card-cliente">
                <i class="fas fa-building" style="font-size:.7rem;flex-shrink:0;"></i>
                <?= htmlspecialchars($op['cliente_nombre']) ?>
            </div>

            <div class="card-monto">
                Bs <?= number_format($op['monto_estimado'], 2, ',', '.') ?>
            </div>

            <div class="prob-bar">
                <div class="prob-fill"
                     style="width:<?= $etapa['probabilidad'] ?>%;background:<?= $color ?>;"></div>
            </div>

            <div class="card-chips">
                <?php if ($op['num_presupuestos'] > 0): ?>
                <span class="chip chip-pres">
                    <i class="fas fa-file-alt"></i><?= $op['num_presupuestos'] ?>
                </span>
                <?php endif; ?>

                <?php if ($op['num_actividades'] > 0): ?>
                <span class="chip chip-act">
                    <i class="fas fa-comments"></i><?= $op['num_actividades'] ?>
                </span>
                <?php endif; ?>

                <?php if ($op['cliente_sector']): ?>
                <span class="chip chip-sector">
                    <?= htmlspecialchars(mb_substr($op['cliente_sector'], 0, 12)) ?>
                </span>
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
                    <span style="font-family:var(--mono);font-size:.65rem;color:var(--ink-3);">
                        <?= $etapa['probabilidad'] ?>%
                    </span>
                </div>
            </div>

        </div><!-- /op-card -->
        <?php endforeach; ?>
    </div><!-- /k-cards -->

    <?php if ($puede_crear): ?>
    <button class="k-add" onclick="abrirNuevo(<?= $eid ?>)">
        <i class="fas fa-plus"></i> Agregar
    </button>
    <?php endif; ?>

</div><!-- /k-col -->
<?php endforeach; ?>

</div><!-- /kanban-board -->
</div><!-- /kanban-scroll -->
</div><!-- /shell -->


<!-- ════════════════════════════════════════════════════════
     MODAL
════════════════════════════════════════════════════════ -->
<div class="overlay" id="overlay">
<div class="modal" id="modal">

    <div class="mhead">
        <div class="mhead-info">
            <h2 id="mTitle">Nueva Oportunidad</h2>
            <div class="sub" id="mSub"></div>
        </div>
        <button class="mhead-x" onclick="cerrarModal()"><i class="fas fa-times"></i></button>
    </div>

    <!-- Tabs -->
    <div class="mtabs" id="mTabs">
        <button class="tab-btn active" data-tab="tdatos" onclick="setTab(this,'tdatos')">
            <i class="fas fa-edit"></i> Datos
        </button>
        <button class="tab-btn" data-tab="tact" onclick="setTab(this,'tact')" id="btnTabAct" style="display:none;">
            <i class="fas fa-comments"></i> Actividades
            <span id="badgeAct" class="k-badge" style="background:#d97706;"></span>
        </button>
        <button class="tab-btn" data-tab="tpres" onclick="setTab(this,'tpres')" id="btnTabPres" style="display:none;">
            <i class="fas fa-file-invoice"></i> Presupuestos
            <span id="badgePres" class="k-badge" style="background:#2563eb;"></span>
        </button>
    </div>

    <!-- ── TAB DATOS ── -->
    <div class="tab-panel active" id="tdatos">
    <form id="fOp" autocomplete="off">
    <input type="hidden" id="fId" name="id">

    <div class="g2">
        <div class="fg span2">
            <label>Título *</label>
            <input type="text" name="titulo" id="fTitulo" required
                   placeholder="Ej: Implementación ERP Empresa XYZ">
        </div>

        <div class="fg">
            <label>Cliente *</label>
            <select name="cliente_id" id="fCliente" required>
                <option value="">— seleccionar —</option>
                <?php foreach ($clientes_list as $cl): ?>
                <option value="<?= $cl['id'] ?>"
                        data-sector="<?= htmlspecialchars($cl['sector']) ?>"
                        data-ciudad="<?= htmlspecialchars($cl['ciudad']) ?>">
                    <?= htmlspecialchars($cl['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
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
            <input type="number" step="0.01" min="0" name="monto_estimado"
                   id="fMonto" placeholder="0.00">
        </div>

        <div class="fg">
            <label>Fecha de Cierre</label>
            <input type="date" name="fecha_cierre" id="fCierre">
        </div>

        <div class="fg">
            <label>Protección</label>
            <input type="text" name="proteccion" id="fProteccion"
                   placeholder="Ej: Exclusividad, Referido…">
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
            <textarea name="notas" id="fNotas"
                      placeholder="Notas internas sobre esta oportunidad…"></textarea>
        </div>
    </div>
    </form>
    </div><!-- /tdatos -->

    <!-- ── TAB ACTIVIDADES ── -->
    <div class="tab-panel" id="tact">
        <div class="act-list" id="listaAct">
            <div class="col-empty" style="padding:24px 0;">
                <i class="fas fa-comments" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
                Sin actividades
            </div>
        </div>

        <div class="act-form">
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
                    <label>Fecha próximo paso *</label>
                    <input type="datetime-local" id="actFechaProx">
                </div>
                <div class="fg span2">
                    <label>Resultado</label>
                    <textarea id="actResultado" style="min-height:50px;"
                              placeholder="¿Qué ocurrió en esta actividad?"></textarea>
                </div>
                <div class="fg span2">
                    <label>Próximo paso</label>
                    <textarea id="actProximo" style="min-height:44px;"
                              placeholder="¿Qué se acordó hacer a continuación?"></textarea>
                </div>
            </div>
            <button class="btn btn-green" onclick="guardarActividad()">
                <i class="fas fa-paper-plane"></i> Registrar
            </button>
        </div>
    </div><!-- /tact -->

    <!-- ── TAB PRESUPUESTOS ── -->
    <div class="tab-panel" id="tpres">
        <div class="pres-list" id="listaPres">
            <div class="col-empty" style="padding:24px 0;">
                <i class="fas fa-file-alt" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
                Sin presupuestos vinculados
            </div>
        </div>
        <div class="link-row">
            <select id="selPres">
                <option value="">— Vincular presupuesto —</option>
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
    </div><!-- /tpres -->

    <div class="mfoot">
        <?php if ($puede_eliminar): ?>
        <button class="btn btn-red" id="btnDel" style="display:none;margin-right:auto;"
                onclick="eliminar()">
            <i class="fas fa-trash"></i> Eliminar
        </button>
        <?php endif; ?>
        <button class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
        <?php if ($puede_crear || $puede_editar): ?>
        <button class="btn btn-green" onclick="guardar()">
            <i class="fas fa-save"></i> Guardar
        </button>
        <?php endif; ?>
    </div>

</div><!-- /modal -->
</div><!-- /overlay -->

<div class="toasts" id="toasts"></div>

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════ -->
<script>
const PUEDE_EDITAR   = <?= json_encode($puede_editar) ?>;
const PUEDE_CREAR    = <?= json_encode($puede_crear) ?>;
const PUEDE_ELIMINAR = <?= json_encode($puede_eliminar) ?>;
let opId = null;

/* ── Toast ─────────────────────────────────────────────── */
function toast(msg, tipo = 'ok') {
    const el = document.createElement('div');
    el.className = `toast ${tipo}`;
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 3200);
}

/* ── Tabs ──────────────────────────────────────────────── */
function setTab(btn, id) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(id).classList.add('active');
}

/* ── Abrir modal nuevo ─────────────────────────────────── */
function abrirNuevo(etapaId = null) {
    opId = null;
    document.getElementById('fOp').reset();
    document.getElementById('fId').value = '';
    document.getElementById('mTitle').textContent = 'Nueva Oportunidad';
    document.getElementById('mSub').textContent   = '';
    if (etapaId) document.getElementById('fEtapa').value = etapaId;

    document.getElementById('btnTabAct').style.display  = 'none';
    document.getElementById('btnTabPres').style.display = 'none';
    const btnDel = document.getElementById('btnDel');
    if (btnDel) btnDel.style.display = 'none';

    setTab(document.querySelector('.tab-btn'), 'tdatos');
    document.getElementById('overlay').classList.add('show');
    document.getElementById('fTitulo').focus();
}

/* ── Cerrar modal ──────────────────────────────────────── */
function cerrarModal() {
    document.getElementById('overlay').classList.remove('show');
    opId = null;
}
document.getElementById('overlay').addEventListener('click', e => {
    if (e.target.id === 'overlay') cerrarModal();
});

/* ── Ver / editar oportunidad ──────────────────────────── */
async function verOp(id) {
    try {
        const r = await fetch(`crear_oportunidad.php?action=get&id=${id}`);
        const d = await r.json();
        if (!d.success) { toast(d.message || 'Error al cargar', 'err'); return; }

        const op = d.op;
        opId = op.id;

        document.getElementById('fId').value          = op.id;
        document.getElementById('fTitulo').value       = op.titulo;
        document.getElementById('fCliente').value      = op.cliente_id;
        document.getElementById('fEtapa').value        = op.etapa_id;
        document.getElementById('fMonto').value        = op.monto_estimado;
        document.getElementById('fCierre').value       = op.fecha_cierre  || '';
        document.getElementById('fProteccion').value   = op.proteccion    || '';
        document.getElementById('fEstado').value       = op.estado;
        document.getElementById('fNotas').value        = op.notas         || '';

        const titulo = op.titulo.length > 52 ? op.titulo.slice(0, 50) + '…' : op.titulo;
        document.getElementById('mTitle').textContent = titulo;
        document.getElementById('mSub').textContent   =
            `#${String(op.numero).padStart(4,'0')} · ${d.cliente_nombre}`;

        document.getElementById('btnTabAct').style.display  = '';
        document.getElementById('btnTabPres').style.display = '';
        document.getElementById('badgeAct').textContent     = d.actividades.length  || '';
        document.getElementById('badgePres').textContent    = d.presupuestos.length || '';

        const btnDel = document.getElementById('btnDel');
        if (btnDel) btnDel.style.display = 'inline-flex';

        renderActividades(d.actividades);
        renderPresupuestos(d.presupuestos);

        setTab(document.querySelector('.tab-btn[data-tab="tdatos"]'), 'tdatos');
        document.getElementById('overlay').classList.add('show');
    } catch (e) {
        toast('Error de conexión', 'err');
        console.error(e);
    }
}

/* ── Render actividades ────────────────────────────────── */
const tipoIcon = {
    Llamada:'📞', Reunion:'🤝', Correo:'📧',
    'Actualización de quote':'📋', Visita:'🏢'
};
function renderActividades(lista) {
    const div = document.getElementById('listaAct');
    if (!lista?.length) {
        div.innerHTML = `<div class="col-empty" style="padding:20px 0;">
            <i class="fas fa-comments" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
            Sin actividades</div>`;
        document.getElementById('badgeAct').textContent = '';
        return;
    }
    div.innerHTML = lista.map(a => `
        <div class="act-item" data-tipo="${esc(a.tipo)}">
            <div class="act-row">
                <span>
                    <span class="act-tipo">${tipoIcon[a.tipo] || '•'} ${esc(a.tipo)}</span>
                    <span class="act-who">${esc(a.nombre_usuario)}</span>
                </span>
                <span class="act-ts">${fmtDt(a.fecha_creacion)}</span>
            </div>
            ${a.resultado
                ? `<div class="act-body"><strong>Resultado:</strong> ${esc(a.resultado)}</div>`
                : ''}
            ${a.proximo_paso
                ? `<div class="act-prox">
                    <i class="fas fa-arrow-right"></i>
                    <strong>Próximo:</strong> ${esc(a.proximo_paso)}
                    <span style="color:var(--ink-3);margin-left:4px;">· ${fmtDt(a.fecha_proximo_paso)}</span>
                  </div>`
                : ''}
        </div>
    `).join('');
    document.getElementById('badgeAct').textContent = lista.length || '';
}

/* ── Render presupuestos ───────────────────────────────── */
function renderPresupuestos(lista) {
    const div = document.getElementById('listaPres');
    if (!lista?.length) {
        div.innerHTML = `<div class="col-empty" style="padding:20px 0;">
            <i class="fas fa-file-alt" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
            Sin presupuestos vinculados</div>`;
        document.getElementById('badgePres').textContent = '';
        return;
    }
    div.innerHTML = lista.map(p => `
        <div class="pres-item">
            <div class="pres-info">
                <strong>#${p.numero_proyecto} — ${esc(p.titulo)}</strong>
                <span>${esc(p.cliente)} · Bs ${numFmt(p.monto_total)}</span>
            </div>
            <div class="pres-actions">
                <span class="badge-estado badge-${p.estado}">${p.estado}</span>
                <a href="ver_proyecto.php?id=${p.id_proyecto}" target="_blank"
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
    document.getElementById('badgePres').textContent = lista.length || '';
}

/* ── Guardar oportunidad ───────────────────────────────── */
async function guardar() {
    const form = document.getElementById('fOp');
    if (!form.reportValidity()) return;
    const payload = Object.fromEntries(new FormData(form));
    try {
        const r = await fetch('crear_oportunidad.php?action=save', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.success) { toast('Guardado correctamente ✓'); setTimeout(() => location.reload(), 700); }
        else toast(d.message || 'Error al guardar', 'err');
    } catch (e) { toast('Error de conexión', 'err'); }
}

/* ── Eliminar ──────────────────────────────────────────── */
async function eliminar() {
    if (!opId || !confirm('¿Eliminar esta oportunidad? Esta acción no se puede deshacer.')) return;
    const r = await fetch('crear_oportunidad.php?action=delete', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: opId})
    });
    const d = await r.json();
    if (d.success) { toast('Eliminado'); setTimeout(() => location.reload(), 700); }
    else toast(d.message || 'Error', 'err');
}

/* ── Guardar actividad ─────────────────────────────────── */
async function guardarActividad() {
    if (!opId) return;
    const fechaProx = document.getElementById('actFechaProx').value;
    if (!fechaProx) { toast('La fecha del próximo paso es obligatoria', 'err'); return; }

    const payload = {
        oportunidad_id:     opId,
        tipo:               document.getElementById('actTipo').value,
        resultado:          document.getElementById('actResultado').value,
        proximo_paso:       document.getElementById('actProximo').value,
        fecha_proximo_paso: fechaProx,
    };
    const r = await fetch('crear_oportunidad.php?action=save_actividad', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.success) {
        document.getElementById('actResultado').value = '';
        document.getElementById('actProximo').value   = '';
        document.getElementById('actFechaProx').value = '';
        renderActividades(d.actividades);
        toast('Actividad registrada ✓');
    } else toast(d.message || 'Error', 'err');
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
    if (d.success) { renderPresupuestos(d.presupuestos); toast('Presupuesto vinculado ✓'); }
    else toast(d.message || 'Error', 'err');
}

async function desvincular(pid) {
    if (!confirm('¿Desvincular este presupuesto?') || !opId) return;
    const r = await fetch('crear_oportunidad.php?action=unlink_presupuesto', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({oportunidad_id: opId, proyecto_id: pid})
    });
    const d = await r.json();
    if (d.success) { renderPresupuestos(d.presupuestos); toast('Desvinculado'); }
    else toast(d.message || 'Error', 'err');
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
        day:'2-digit', month:'short', year:'numeric',
        hour:'2-digit', minute:'2-digit'
    });
}
function numFmt(n) {
    return parseFloat(n || 0).toLocaleString('es-BO', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}
</script>
</body>
</html>