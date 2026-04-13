<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso('clientes', 'ver');

$puede_crear  = tienePermiso('clientes', 'crear');
$puede_editar = tienePermiso('clientes', 'editar');
$puede_elim   = tienePermiso('clientes', 'eliminar');

// ── Mensajes flash ─────────────────────────────────────────
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

// ── Filtros y paginación ───────────────────────────────────
$busqueda = trim($_GET['buscar'] ?? '');
$filtro_sector = trim($_GET['sector'] ?? '');
$filtro_ciudad = trim($_GET['ciudad'] ?? '');
$pag = max(1, intval($_GET['pag'] ?? 1));
$por_pag = 25;
$offset  = ($pag - 1) * $por_pag;

// ── Construir query ────────────────────────────────────────
$conditions = [];
$params = [];

if ($busqueda) {
    $conditions[] = "(c.nombre LIKE ? OR c.`código` LIKE ? OR c.nit LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}
if ($filtro_sector) {
    $conditions[] = "c.sector = ?";
    $params[] = $filtro_sector;
}
if ($filtro_ciudad) {
    $conditions[] = "c.ciudad = ?";
    $params[] = $filtro_ciudad;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Total
$stmt = $conn->prepare("SELECT COUNT(*) FROM clientes c $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_pags = ceil($total / $por_pag);

// Clientes paginados con conteo de contactos
$stmt = $conn->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM contactos ct WHERE ct.cliente_id = c.id AND ct.activo = 1) AS num_contactos
    FROM clientes c
    $where
    ORDER BY c.nombre ASC
    LIMIT $por_pag OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Listas para filtros
$sectores = $conn->query("SELECT DISTINCT sector FROM clientes WHERE sector IS NOT NULL AND sector <> '' ORDER BY sector")->fetchAll(PDO::FETCH_COLUMN);
$ciudades = $conn->query("SELECT DISTINCT ciudad FROM clientes WHERE ciudad IS NOT NULL AND ciudad <> '' ORDER BY ciudad")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestión de Clientes</title>
<link rel="icon" type="image/jpg" href="assets/icono.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --green:   #16a34a;
    --green-d: #15803d;
    --green-bg:#f0fdf4;
    --ink:     #0f172a;
    --ink-2:   #334155;
    --ink-3:   #64748b;
    --border:  #e2e8f0;
    --bg:      #f1f5f9;
    --white:   #ffffff;
    --red:     #dc2626;
    --blue:    #2563eb;
    --amber:   #d97706;
    --font:    'Inter', sans-serif;
    --radius:  10px;
    --sh-sm:   0 1px 3px rgba(0,0,0,.07);
    --sh-md:   0 4px 14px rgba(0,0,0,.09);
    --sh-lg:   0 20px 60px rgba(0,0,0,.16);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font); background: var(--bg); color: var(--ink); font-size: 14px; min-height: 100vh; }

/* ─── TOPBAR ─── */
.topbar {
    background: var(--white);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    height: 52px;
    display: flex;
    align-items: center;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 100;
}
.topbar img { height: 120px; padding: 8px;}
.topbar h1 { font-size: .95rem; font-weight: 700; flex: 1; display: flex; align-items: center; gap: 8px; }
.topbar h1 i { color: var(--green); }

/* ─── MAIN ─── */
.main { max-width: 1340px; margin: 0 auto; padding: 20px 24px 40px; }

/* ─── TOOLBAR ─── */
.toolbar {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.toolbar input[type="text"],
.toolbar select {
    font-family: var(--font);
    font-size: .82rem;
    padding: 6px 11px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--bg);
    color: var(--ink);
    outline: none;
    transition: border-color .15s;
}
.toolbar input[type="text"] { min-width: 220px; }
.toolbar input:focus, .toolbar select:focus { border-color: var(--green); background: #fff; }
.toolbar-meta { margin-left: auto; font-size: .72rem; color: var(--ink-3); }

/* ─── BUTTONS ─── */
.btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 7px;
    font-family: var(--font); font-size: .8rem; font-weight: 600;
    cursor: pointer; border: none; transition: filter .15s, transform .1s;
    text-decoration: none; white-space: nowrap;
}
.btn:active { transform: scale(.97); }
.btn-green  { background: var(--green); color: #fff; }
.btn-green:hover { filter: brightness(1.08); }
.btn-ghost  { background: transparent; color: var(--ink-3); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg); color: var(--ink); }
.btn-red    { background: var(--red); color: #fff; }
.btn-red:hover { filter: brightness(1.08); }
.btn-blue   { background: var(--blue); color: #fff; }
.btn-blue:hover { filter: brightness(1.08); }
.btn-sm { padding: 4px 9px; font-size: .73rem; }

/* ─── GRID CLIENTES ─── */
.clientes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 12px;
}
.cliente-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    box-shadow: var(--sh-sm);
    transition: box-shadow .15s, transform .12s;
    cursor: pointer;
}
.cliente-card:hover { box-shadow: var(--sh-md); transform: translateY(-2px); }
.cliente-card.selected { border-color: var(--green); box-shadow: 0 0 0 2px #bbf7d0; }
.cc-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 10px; }
.cc-avatar {
    width: 42px; height: 42px; border-radius: 10px;
    background: var(--green); color: #fff;
    font-size: .95rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; text-transform: uppercase;
}
.cc-info { flex: 1; min-width: 0; }
.cc-nombre { font-weight: 700; font-size: .88rem; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cc-codigo { font-size: .7rem; color: var(--ink-3); font-family: monospace; }
.cc-chips { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 10px; }
.chip {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: .65rem; font-weight: 600; padding: 2px 7px; border-radius: 20px;
}
.chip-sector { background: #eff6ff; color: #1d4ed8; }
.chip-ciudad { background: #f5f3ff; color: #5b21b6; }
.chip-tipo   { background: #fefce8; color: #854d0e; }
.chip-cont   { background: #f0fdf4; color: #166534; }
.cc-meta { display: flex; gap: 12px; font-size: .72rem; color: var(--ink-3); }
.cc-meta span { display: flex; align-items: center; gap: 4px; }
.cc-actions { display: flex; gap: 6px; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); }

/* ─── PANEL DETALLE ─── */
.panel-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 1000;
    align-items: flex-start;
    justify-content: flex-end;
}
.panel-overlay.show { display: flex; }
.panel {
    background: var(--white);
    width: min(560px, 100vw);
    height: 100vh;
    overflow-y: auto;
    box-shadow: var(--sh-lg);
    animation: slideIn .22s ease;
    display: flex;
    flex-direction: column;
}
@keyframes slideIn { from { transform: translateX(30px); opacity: 0; } to { transform: none; opacity: 1; } }
.panel-head {
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
    position: sticky; top: 0; background: var(--white); z-index: 10;
}
.panel-head h2 { font-size: .97rem; font-weight: 700; flex: 1; }
.panel-close { background: none; border: none; font-size: 1.2rem; color: var(--ink-3); cursor: pointer; }
.panel-close:hover { color: var(--red); }
.panel-body { padding: 20px; flex: 1; }
.panel-section { margin-bottom: 22px; }
.panel-section h3 {
    font-size: .72rem; font-weight: 700; color: var(--ink-3);
    text-transform: uppercase; letter-spacing: .4px;
    margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}
.panel-section h3 i { color: var(--green); }

/* ─── FORMULARIO ─── */
.fg { margin-bottom: 12px; }
.fg label { display: block; font-size: .72rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
.fg input, .fg select, .fg textarea {
    font-family: var(--font); font-size: .83rem; width: 100%; padding: 7px 10px;
    border: 1px solid var(--border); border-radius: 7px; background: #fff;
    color: var(--ink); outline: none; transition: border-color .15s, box-shadow .15s;
}
.fg input:focus, .fg select:focus, .fg textarea:focus {
    border-color: var(--green); box-shadow: 0 0 0 3px rgba(22,163,74,.1);
}
.fg textarea { min-height: 60px; resize: vertical; }
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.span2 { grid-column: 1/-1; }

/* ─── CONTACTOS ─── */
.contacto-item {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 8px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.cont-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: var(--blue); color: #fff;
    font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; text-transform: uppercase;
}
.cont-info { flex: 1; min-width: 0; }
.cont-nombre { font-weight: 700; font-size: .84rem; }
.cont-cargo  { font-size: .72rem; color: var(--ink-3); margin-bottom: 4px; }
.cont-datos  { display: flex; flex-wrap: wrap; gap: 8px; font-size: .72rem; color: var(--ink-2); }
.cont-datos span { display: flex; align-items: center; gap: 4px; }
.cont-actions { display: flex; gap: 4px; flex-shrink: 0; }
.cont-form {
    background: var(--green-bg, #f0fdf4);
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 14px;
    margin-top: 8px;
}
.cont-form-title { font-size: .75rem; font-weight: 700; color: var(--green); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px; }

/* ─── MODAL ─── */
.overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,23,42,.48); z-index: 2000;
    align-items: center; justify-content: center;
    padding: 20px;
}
.overlay.show { display: flex; }
.modal {
    background: var(--white); border-radius: 14px;
    width: 100%; max-width: 560px;
    box-shadow: var(--sh-lg);
    animation: mUp .2s ease;
    max-height: 90vh; overflow-y: auto;
}
@keyframes mUp { from { opacity:0; transform: translateY(12px); } to { opacity:1; transform: none; } }
.mhead { padding: 18px 22px 14px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.mhead h2 { font-size: .97rem; font-weight: 700; flex: 1; }
.mhead-x { background: none; border: none; font-size: 1.2rem; color: var(--ink-3); cursor: pointer; }
.mhead-x:hover { color: var(--red); }
.mbody { padding: 20px 22px; }
.mfoot { padding: 12px 22px; border-top: 1px solid var(--border); display: flex; gap: 7px; justify-content: flex-end; }

/* ─── PAGINACIÓN ─── */
.paginacion { display: flex; gap: 6px; justify-content: center; margin-top: 20px; align-items: center; }
.paginacion a, .paginacion span.current {
    padding: 6px 12px; border-radius: 7px; font-size: .8rem; text-decoration: none;
    border: 1px solid var(--border); color: var(--ink-2);
}
.paginacion a:hover { border-color: var(--green); color: var(--green); }
.paginacion span.current { background: var(--green); color: #fff; border-color: var(--green); font-weight: 700; }
.paginacion span.dots { padding: 6px 4px; color: var(--ink-3); border: none; }

/* ─── EMPTY STATE ─── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--ink-3); }
.empty-state i { font-size: 3rem; margin-bottom: 12px; display: block; }
.empty-state p { font-size: .9rem; }

/* ─── TOAST ─── */
.toasts { position: fixed; bottom: 18px; right: 18px; z-index: 9999; display: flex; flex-direction: column; gap: 7px; pointer-events: none; }
.toast { background: var(--ink); color: #fff; padding: 9px 16px; border-radius: 9px; font-size: .8rem; box-shadow: var(--sh-md); animation: tIn .22s ease; pointer-events: auto; }
.toast.ok  { background: var(--green); }
.toast.err { background: var(--red); }
@keyframes tIn { from { opacity:0; transform:translateX(14px); } to { opacity:1; transform:none; } }

@media(max-width:600px) { .clientes-grid { grid-template-columns: 1fr; } .g2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <img src="assets/logo.png" alt="Logo">
    <h1>Gestión de Clientes</h1>
    <?php if ($puede_crear): ?>
    <button class="btn btn-green" onclick="abrirNuevoCliente()">
        <i class="fas fa-plus"></i> Nuevo Cliente
    </button>
    <?php endif; ?>
    <a href="oportunidades.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Oportunidades</a>
</div>

<div class="main">

<!-- TOOLBAR -->
<div class="toolbar">
    <form method="get" style="display:contents;">
        <input type="text" name="buscar" placeholder="Buscar por nombre, código o NIT…"
               value="<?= htmlspecialchars($busqueda) ?>" autocomplete="off">
        <select name="sector" onchange="this.form.submit()">
            <option value="">Todos los sectores</option>
            <?php foreach ($sectores as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $filtro_sector === $s ? 'selected' : '' ?>>
                <?= htmlspecialchars($s) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="ciudad" onchange="this.form.submit()">
            <option value="">Todas las ciudades</option>
            <?php foreach ($ciudades as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $filtro_ciudad === $c ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost"><i class="fas fa-search"></i> Buscar</button>
        <?php if ($busqueda || $filtro_sector || $filtro_ciudad): ?>
        <a href="gestion_clientes.php" class="btn btn-ghost" style="color:var(--red);border-color:var(--red);">
            <i class="fas fa-times"></i> Limpiar
        </a>
        <?php endif; ?>
        <span class="toolbar-meta"><?= $total ?> cliente<?= $total !== 1 ? 's' : '' ?></span>
    </form>
</div>

<!-- MENSAJES FLASH -->
<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:.84rem;color:#065f46;display:flex;align-items:center;gap:8px;">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>
<?php if ($err): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:.84rem;color:#991b1b;display:flex;align-items:center;gap:8px;">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?>
</div>
<?php endif; ?>

<!-- GRID DE CLIENTES -->
<?php if (empty($clientes)): ?>
<div class="empty-state">
    <i class="fas fa-building"></i>
    <p>No se encontraron clientes<?= $busqueda ? ' con "'.htmlspecialchars($busqueda).'"' : '' ?></p>
</div>
<?php else: ?>
<div class="clientes-grid">
<?php foreach ($clientes as $c):
    $ini = strtoupper(substr($c['nombre'], 0, 2));
?>
<div class="cliente-card" onclick="verCliente(<?= $c['id'] ?>)" id="card-<?= $c['id'] ?>">
    <div class="cc-head">
        <div class="cc-avatar"><?= $ini ?></div>
        <div class="cc-info">
            <div class="cc-nombre"><?= htmlspecialchars($c['nombre']) ?></div>
            <div class="cc-codigo"><?= htmlspecialchars($c['código']) ?> · NIT <?= htmlspecialchars($c['nit']) ?></div>
        </div>
    </div>
    <div class="cc-chips">
        <?php if ($c['sector']): ?><span class="chip chip-sector"><?= htmlspecialchars($c['sector']) ?></span><?php endif; ?>
        <?php if ($c['ciudad']): ?><span class="chip chip-ciudad"><i class="fas fa-map-marker-alt" style="font-size:.6rem;"></i> <?= htmlspecialchars($c['ciudad']) ?></span><?php endif; ?>
        <?php if ($c['tipo']): ?><span class="chip chip-tipo"><?= htmlspecialchars($c['tipo']) ?></span><?php endif; ?>
        <?php if ($c['num_contactos'] > 0): ?><span class="chip chip-cont"><i class="fas fa-users" style="font-size:.6rem;"></i> <?= $c['num_contactos'] ?> contacto<?= $c['num_contactos'] !== 1 ? 's' : '' ?></span><?php endif; ?>
    </div>
    <div class="cc-meta">
        <?php if ($c['correo']): ?><span><i class="fas fa-envelope"></i> <?= htmlspecialchars($c['correo']) ?></span><?php endif; ?>
        <?php if ($c['dirección']): ?><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><i class="fas fa-map-pin"></i> <?= htmlspecialchars($c['dirección']) ?></span><?php endif; ?>
    </div>
    <div class="cc-actions" onclick="event.stopPropagation()">
        <button class="btn btn-ghost btn-sm" onclick="verCliente(<?= $c['id'] ?>)">
            <i class="fas fa-eye"></i> Ver
        </button>
        <?php if ($puede_editar): ?>
        <button class="btn btn-ghost btn-sm" onclick="editarCliente(<?= $c['id'] ?>)">
            <i class="fas fa-edit"></i> Editar
        </button>
        <?php endif; ?>
        <?php if ($puede_elim): ?>
        <button class="btn btn-red btn-sm" onclick="eliminarCliente(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nombre'])) ?>')">
            <i class="fas fa-trash"></i>
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- PAGINACIÓN -->
<?php if ($total_pags > 1): ?>
<div class="paginacion">
    <?php if ($pag > 1): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['pag' => $pag - 1])) ?>">«</a>
    <?php endif; ?>
    <?php for ($i = max(1, $pag-2); $i <= min($total_pags, $pag+2); $i++): ?>
        <?php if ($i === $pag): ?>
        <span class="current"><?= $i ?></span>
        <?php else: ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['pag' => $i])) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($pag < $total_pags): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['pag' => $pag + 1])) ?>">»</a>
    <?php endif; ?>
    <span class="dots">Página <?= $pag ?> de <?= $total_pags ?></span>
</div>
<?php endif; ?>
<?php endif; ?>

</div><!-- /main -->

<!-- ══════════════════════════════════════════════════════
     PANEL LATERAL — DETALLE DEL CLIENTE
═══════════════════════════════════════════════════════ -->
<div class="panel-overlay" id="panelOverlay" onclick="cerrarPanel(event)">
<div class="panel" id="panel">
    <div class="panel-head">
        <div style="flex:1;min-width:0;">
            <h2 id="pNombre">Cliente</h2>
            <div id="pCodigo" style="font-size:.72rem;color:var(--ink-3);margin-top:1px;"></div>
        </div>
        <button class="btn btn-ghost btn-sm" id="btnEditarPanel" style="display:none;" onclick="editarClienteDesdePanel()">
            <i class="fas fa-edit"></i> Editar
        </button>
        <button class="panel-close" onclick="cerrarPanelDirecto()"><i class="fas fa-times"></i></button>
    </div>
    <div class="panel-body" id="panelBody">
        <div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Cargando…</p></div>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════
     MODAL — CREAR / EDITAR CLIENTE
═══════════════════════════════════════════════════════ -->
<div class="overlay" id="overlayCliente">
<div class="modal">
    <div class="mhead">
        <h2 id="mClienteTitulo">Nuevo Cliente</h2>
        <button class="mhead-x" onclick="cerrarModalCliente()"><i class="fas fa-times"></i></button>
    </div>
    <div class="mbody">
    <form id="fCliente" autocomplete="off">
    <input type="hidden" id="fCId" name="id">
    <div class="g2">
        <div class="fg">
            <label>Código *</label>
            <input type="text" name="codigo" id="fCodigo" required maxlength="10" placeholder="Ej: CLI-001">
        </div>
        <div class="fg">
            <label>Tipo *</label>
            <select name="tipo" id="fTipo" required>
                <option value="">Seleccionar…</option>
                <option value="Empresa">Empresa</option>
                <option value="Gobierno">Gobierno</option>
                <option value="ONG">ONG</option>
                <option value="Persona Natural">Persona Natural</option>
                <option value="Otro">Otro</option>
            </select>
        </div>
        <div class="fg span2">
            <label>Nombre / Razón Social *</label>
            <input type="text" name="nombre" id="fNombre" required maxlength="200" placeholder="Nombre completo de la empresa">
        </div>
        <div class="fg">
            <label>NIT *</label>
            <input type="number" name="nit" id="fNit" required placeholder="Número de NIT">
        </div>
        <div class="fg">
            <label>Correo</label>
            <input type="email" name="correo" id="fCorreo" placeholder="correo@empresa.com">
        </div>
        <div class="fg">
            <label>Ciudad</label>
            <input type="text" name="ciudad" id="fCiudad" placeholder="Ej: La Paz">
        </div>
        <div class="fg">
            <label>Sector *</label>
            <input type="text" name="sector" id="fSector" required placeholder="Ej: Minería, Salud…" list="sectores-list">
            <datalist id="sectores-list">
                <?php foreach ($sectores as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="fg span2">
            <label>Dirección</label>
            <input type="text" name="direccion" id="fDireccion" maxlength="200" placeholder="Dirección completa">
        </div>
    </div>
    </form>
    </div>
    <div class="mfoot">
        <button class="btn btn-ghost" onclick="cerrarModalCliente()">Cancelar</button>
        <button class="btn btn-green" onclick="guardarCliente()"><i class="fas fa-save"></i> Guardar</button>
    </div>
</div>
</div>

<div class="toasts" id="toasts"></div>

<script>
const PUEDE_EDITAR  = <?= json_encode($puede_editar) ?>;
const PUEDE_CREAR   = <?= json_encode($puede_crear) ?>;
const PUEDE_ELIMINAR= <?= json_encode($puede_elim) ?>;
let clienteActivoId = null;

/* ─── TOAST ────────────────────────────────────────────── */
function toast(msg, tipo = 'ok') {
    const el = document.createElement('div');
    el.className = `toast ${tipo}`;
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 3200);
}

/* ─── PANEL DETALLE ────────────────────────────────────── */
async function verCliente(id) {
    clienteActivoId = id;
    document.getElementById('panelBody').innerHTML =
        '<div class="empty-state"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
    document.getElementById('panelOverlay').classList.add('show');

    const r = await fetch(`api_clientes.php?action=get&id=${id}`);
    const d = await r.json();
    if (!d.success) { toast(d.message || 'Error', 'err'); return; }

    const c = d.cliente;
    document.getElementById('pNombre').textContent = c.nombre;
    document.getElementById('pCodigo').textContent =
        `${c.código} · NIT ${c.nit} · ${c.tipo}`;
    if (PUEDE_EDITAR)
        document.getElementById('btnEditarPanel').style.display = 'inline-flex';

    // Renderizar cuerpo
    document.getElementById('panelBody').innerHTML = renderDetalle(d);
}

function renderDetalle(d) {
    const c = d.cliente;
    const contactos = d.contactos || [];
    return `
    <div class="panel-section">
        <h3><i class="fas fa-info-circle"></i> Información general</h3>
        <div class="g2" style="gap:8px;">
            ${campo('Sector',  c.sector)}
            ${campo('Ciudad',  c.ciudad)}
            ${campo('Correo',  c.correo ? `<a href="mailto:${esc(c.correo)}" style="color:var(--blue)">${esc(c.correo)}</a>` : '—', true)}
            ${campo('Dirección', c.dirección || c.direccion || '—')}
        </div>
    </div>

    <div class="panel-section">
        <h3><i class="fas fa-users"></i> Contactos <span style="font-weight:400;color:var(--ink-3);text-transform:none;letter-spacing:0;">(${contactos.length})</span></h3>
        <div id="listaContactos">
            ${contactos.length ? contactos.map(renderContacto).join('') : '<p style="color:var(--ink-3);font-size:.8rem;">Sin contactos registrados</p>'}
        </div>
        ${PUEDE_CREAR ? `
        <button class="btn btn-ghost" style="width:100%;margin-top:8px;justify-content:center;" onclick="toggleFormContacto()">
            <i class="fas fa-plus"></i> Agregar contacto
        </button>
        <div id="formContactoWrap" style="display:none;">
            ${formContacto()}
        </div>` : ''}
    </div>
    `;
}

function campo(label, val, raw = false) {
    const display = raw ? val : esc(val || '—');
    return `<div style="margin-bottom:6px;">
        <div style="font-size:.68rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;">${label}</div>
        <div style="font-size:.82rem;color:var(--ink);">${display}</div>
    </div>`;
}

function renderContacto(ct) {
    const ini = (ct.nombre || '?').substring(0, 2).toUpperCase();
    return `
    <div class="contacto-item" id="cont-${ct.id}">
        <div class="cont-avatar">${ini}</div>
        <div class="cont-info">
            <div class="cont-nombre">${esc(ct.nombre)}</div>
            <div class="cont-cargo">${esc(ct.cargo || '')}</div>
            <div class="cont-datos">
                ${ct.telefono ? `<span><i class="fas fa-phone"></i> ${esc(ct.telefono)}</span>` : ''}
                ${ct.correo    ? `<span><i class="fas fa-envelope"></i> <a href="mailto:${esc(ct.correo)}" style="color:var(--blue)">${esc(ct.correo)}</a></span>` : ''}
                ${ct.notas     ? `<span style="font-style:italic;color:var(--ink-3);">${esc(ct.notas)}</span>` : ''}
            </div>
        </div>
        <div class="cont-actions">
            ${PUEDE_EDITAR   ? `<button class="btn btn-ghost btn-sm" onclick="editarContacto(${ct.id})" title="Editar"><i class="fas fa-edit"></i></button>` : ''}
            ${PUEDE_ELIMINAR ? `<button class="btn btn-red btn-sm" onclick="eliminarContacto(${ct.id})" title="Eliminar"><i class="fas fa-trash"></i></button>` : ''}
        </div>
    </div>`;
}

function formContacto(ct = null) {
    return `
    <div class="cont-form" id="contForm">
        <div class="cont-form-title">${ct ? 'Editar contacto' : 'Nuevo contacto'}</div>
        <input type="hidden" id="contId" value="${ct ? ct.id : ''}">
        <div class="g2">
            <div class="fg span2"><label>Nombre *</label><input id="contNombre" type="text" value="${ct ? esc(ct.nombre) : ''}" placeholder="Nombre completo"></div>
            <div class="fg"><label>Cargo</label><input id="contCargo" type="text" value="${ct ? esc(ct.cargo||'') : ''}" placeholder="Ej: Jefe de Compras"></div>
            <div class="fg"><label>Teléfono</label><input id="contTel" type="text" value="${ct ? esc(ct.telefono||'') : ''}" placeholder="+591 7..."></div>
            <div class="fg span2"><label>Correo</label><input id="contEmail" type="email" value="${ct ? esc(ct.correo||'') : ''}" placeholder="correo@empresa.com"></div>
            <div class="fg span2"><label>Notas</label><textarea id="contNotas" placeholder="Observaciones…">${ct ? esc(ct.notas||'') : ''}</textarea></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px;">
            <button class="btn btn-ghost btn-sm" onclick="cancelarFormContacto()">Cancelar</button>
            <button class="btn btn-green btn-sm" onclick="guardarContacto()"><i class="fas fa-save"></i> Guardar</button>
        </div>
    </div>`;
}

function toggleFormContacto() {
    const wrap = document.getElementById('formContactoWrap');
    if (!wrap) return;
    wrap.style.display = wrap.style.display === 'none' ? 'block' : 'none';
}

function cancelarFormContacto() {
    const wrap = document.getElementById('formContactoWrap');
    if (wrap) { wrap.innerHTML = formContacto(); wrap.style.display = 'none'; }
}

function editarContacto(id) {
    fetch(`api_clientes.php?action=get_contacto&id=${id}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) { toast(d.message, 'err'); return; }
            const wrap = document.getElementById('formContactoWrap');
            if (wrap) {
                wrap.innerHTML = formContacto(d.contacto);
                wrap.style.display = 'block';
                wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
}

async function guardarContacto() {
    const id      = document.getElementById('contId')?.value || '';
    const nombre  = document.getElementById('contNombre')?.value?.trim();
    const cargo   = document.getElementById('contCargo')?.value?.trim();
    const tel     = document.getElementById('contTel')?.value?.trim();
    const email   = document.getElementById('contEmail')?.value?.trim();
    const notas   = document.getElementById('contNotas')?.value?.trim();

    if (!nombre) { toast('El nombre es obligatorio', 'err'); return; }

    const r = await fetch('api_clientes.php?action=save_contacto', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, cliente_id: clienteActivoId, nombre, cargo, telefono: tel, correo: email, notas })
    });
    const d = await r.json();
    if (d.success) {
        toast(id ? 'Contacto actualizado ✓' : 'Contacto agregado ✓');
        // Refrescar lista de contactos
        const lista = document.getElementById('listaContactos');
        if (lista) lista.innerHTML = d.contactos.length
            ? d.contactos.map(renderContacto).join('')
            : '<p style="color:var(--ink-3);font-size:.8rem;">Sin contactos registrados</p>';
        // Actualizar chip del card
        actualizarChipContactos(clienteActivoId, d.contactos.length);
        cancelarFormContacto();
    } else {
        toast(d.message || 'Error', 'err');
    }
}

async function eliminarContacto(id) {
    if (!confirm('¿Eliminar este contacto?')) return;
    const r = await fetch('api_clientes.php?action=delete_contacto', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    });
    const d = await r.json();
    if (d.success) {
        toast('Contacto eliminado');
        const lista = document.getElementById('listaContactos');
        if (lista) lista.innerHTML = d.contactos.length
            ? d.contactos.map(renderContacto).join('')
            : '<p style="color:var(--ink-3);font-size:.8rem;">Sin contactos registrados</p>';
        actualizarChipContactos(clienteActivoId, d.contactos.length);
    } else toast(d.message || 'Error', 'err');
}

function actualizarChipContactos(id, count) {
    const card = document.getElementById(`card-${id}`);
    if (!card) return;
    const chips = card.querySelector('.cc-chips');
    if (!chips) return;
    // Eliminar chip anterior de contactos
    chips.querySelectorAll('.chip-cont').forEach(ch => ch.remove());
    if (count > 0) {
        const chip = document.createElement('span');
        chip.className = 'chip chip-cont';
        chip.innerHTML = `<i class="fas fa-users" style="font-size:.6rem;"></i> ${count} contacto${count !== 1 ? 's' : ''}`;
        chips.appendChild(chip);
    }
}

/* ─── CERRAR PANEL ─────────────────────────────────────── */
function cerrarPanelDirecto() {
    document.getElementById('panelOverlay').classList.remove('show');
    clienteActivoId = null;
    document.getElementById('btnEditarPanel').style.display = 'none';
}
function cerrarPanel(e) {
    if (e.target.id === 'panelOverlay') cerrarPanelDirecto();
}

/* ─── MODAL CLIENTE ────────────────────────────────────── */
function abrirNuevoCliente() {
    document.getElementById('fCliente').reset();
    document.getElementById('fCId').value = '';
    document.getElementById('mClienteTitulo').textContent = 'Nuevo Cliente';
    document.getElementById('overlayCliente').classList.add('show');
    document.getElementById('fCodigo').focus();
}

async function editarCliente(id) {
    const r = await fetch(`api_clientes.php?action=get&id=${id}`);
    const d = await r.json();
    if (!d.success) { toast(d.message || 'Error', 'err'); return; }
    const c = d.cliente;
    document.getElementById('fCId').value      = c.id;
    document.getElementById('fCodigo').value   = c['código'];
    document.getElementById('fTipo').value     = c.tipo;
    document.getElementById('fNombre').value   = c.nombre;
    document.getElementById('fNit').value      = c.nit;
    document.getElementById('fCorreo').value   = c.correo || '';
    document.getElementById('fCiudad').value   = c.ciudad || '';
    document.getElementById('fSector').value   = c.sector || '';
    document.getElementById('fDireccion').value= c['dirección'] || c.direccion || '';
    document.getElementById('mClienteTitulo').textContent = 'Editar Cliente';
    document.getElementById('overlayCliente').classList.add('show');
}

function editarClienteDesdePanel() {
    cerrarPanelDirecto();
    editarCliente(clienteActivoId);
}

function cerrarModalCliente() {
    document.getElementById('overlayCliente').classList.remove('show');
}

async function guardarCliente() {
    const form = document.getElementById('fCliente');
    if (!form.reportValidity()) return;
    const payload = {
        id:        document.getElementById('fCId').value,
        codigo:    document.getElementById('fCodigo').value.trim(),
        tipo:      document.getElementById('fTipo').value,
        nombre:    document.getElementById('fNombre').value.trim(),
        nit:       document.getElementById('fNit').value.trim(),
        correo:    document.getElementById('fCorreo').value.trim(),
        ciudad:    document.getElementById('fCiudad').value.trim(),
        sector:    document.getElementById('fSector').value.trim(),
        direccion: document.getElementById('fDireccion').value.trim(),
    };
    const r = await fetch('api_clientes.php?action=save', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.success) {
        toast(payload.id ? 'Cliente actualizado ✓' : 'Cliente creado ✓');
        cerrarModalCliente();
        setTimeout(() => location.reload(), 700);
    } else toast(d.message || 'Error', 'err');
}

async function eliminarCliente(id, nombre) {
    if (!confirm(`¿Eliminar el cliente "${nombre}"? También se eliminarán sus contactos.`)) return;
    const r = await fetch('api_clientes.php?action=delete', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    });
    const d = await r.json();
    if (d.success) { toast('Cliente eliminado'); setTimeout(() => location.reload(), 600); }
    else toast(d.message || 'Error', 'err');
}

/* ─── HELPERS ──────────────────────────────────────────── */
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

// Cerrar overlay con ESC
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarPanelDirecto();
        cerrarModalCliente();
    }
});
document.getElementById('overlayCliente').addEventListener('click', e => {
    if (e.target.id === 'overlayCliente') cerrarModalCliente();
});

<?php if ($msg || $err): ?>
// Auto-limpiar parámetros de URL
history.replaceState({}, '', 'gestion_clientes.php');
<?php endif; ?>
</script>
</body>
</html>