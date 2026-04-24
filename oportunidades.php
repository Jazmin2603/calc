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
    'usuario' => $_GET['usuario'] ?? null,
    'estado' => $_GET['estado'] ?? null,
    'buscar' => $_GET['buscar'] ?? null,
    'pagina' => $_GET['pagina'] ?? null
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

    <span class="toolbar-meta">
        <?= count($todas) ?> oport. &bull;
        Bs <?= number_format(array_sum(array_column($todas, 'monto_estimado')), 2, ',', '.') ?>
    </span>
</div>

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
</div>

<div class="overlay" id="overlay">
<div class="modal" id="modal">

    <div class="mhead">
        <div class="mhead-info">
            <h2 id="mTitle">Nueva Oportunidad</h2>
            <div class="sub" id="mSub"></div>
        </div>
        <button class="mhead-x" onclick="cerrarModal()"><i class="fas fa-times"></i></button>
    </div>

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

    <div class="tab-panel active" id="tdatos">
    <form id="fOp" autocomplete="off">
    <input type="hidden" id="fId" name="id">

    <div class="g2">
        <div class="fg span2">
            <label>Título *</label>
            <input type="text" name="titulo" id="fTitulo" required
                   placeholder="Ej: Implementación ERP Empresa XYZ">
        </div>

        <div class="fg span2">
            <label>Cliente *</label>
            <input type="hidden" name="cliente_id" id="fCliente" required>
            <div class="client-search-wrap">
                <input type="text" id="clienteSearch"
                       placeholder="Escribir para buscar cliente…"
                       autocomplete="off"
                       onkeydown="clienteKeydown(event)"
                       oninput="filtrarClientes()"
                       onfocus="abrirDropdown()"
                       onblur="ocultarDropdownDelay()">
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
            <input type="text" name="proteccion" id="fProteccion" placeholder="Ej: Exclusividad, Referido…">
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
    </form>
    </div>

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
                    <label>Fecha de la actividad *</label>
                    <input type="datetime-local" id="actFechaProx">
                </div>
                <div class="fg span2">
                    <label>Descripción / Agenda</label>
                    <textarea id="actProximo" style="min-height:44px;" placeholder="¿Qué está planificado?"></textarea>
                </div>
                <div class="fg span2">
                    <label>Resultado <span style="color:var(--ink-3);font-weight:400;">(opcional — si ya ocurrió)</span></label>
                    <textarea id="actResultado" style="min-height:50px;" placeholder="¿Qué ocurrió?"></textarea>
                </div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:4px;">
                <label id="labelOutlookCheck"
                       style="display:none;align-items:center;gap:7px;font-size:.8rem;color:var(--ink-2);cursor:pointer;">
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

    <!-- ── TAB PRESUPUESTOS ── -->
    <div class="tab-panel" id="tpres">

        <?php if($puede_crear_presupuesto): ?>
        <!-- Crear presupuesto nuevo desde la oportunidad -->
        <div class="crear-pres-box" id="boxCrearPres" style="display:none;">
            <div class="cp-info">
                <strong><i class="fas fa-file-plus"></i> Crear nuevo presupuesto</strong>
                <span>Se creará un presupuesto vinculado a esta oportunidad</span>
            </div>
            <button class="btn btn-blue" onclick="crearPresupuestoDesdeOp()">
                <i class="fas fa-plus"></i> Crear presupuesto
            </button>
        </div>
        <?php endif; ?>

        <div class="pres-list" id="listaPres" style="margin-top:10px;">
            <div class="col-empty" style="padding:20px 0;">
                <i class="fas fa-file-alt" style="font-size:1.6rem;display:block;margin-bottom:5px;"></i>
                Sin presupuestos vinculados
            </div>
        </div>
        <div class="link-row" style="margin-top:6px;">
            <select id="selPres">
                <option value=""> Vincular presupuesto existente </option>
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

    <div class="mfoot">
        <?php if ($puede_eliminar): ?>
        <button class="btn btn-red" id="btnDel" style="display:none;margin-right:auto;" onclick="eliminar()">
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

</div>
</div>

<div class="toasts" id="toasts"></div>

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════ -->
<script>
const PUEDE_EDITAR           = <?= json_encode($puede_editar) ?>;
const PUEDE_CREAR            = <?= json_encode($puede_crear) ?>;
const PUEDE_ELIMINAR         = <?= json_encode($puede_eliminar) ?>;
const PUEDE_CREAR_PRESUPUESTO = <?= json_encode($puede_crear_presupuesto) ?>;
let opId = null;
let opClienteId = null;  // para crear presupuesto

/* ── Clientes para búsqueda ────────────────────────────── */
const CLIENTES = <?= json_encode($clientes_list) ?>;
let highlightIdx = -1;

function filtrarClientes() {
    const q = document.getElementById('clienteSearch').value.toLowerCase().trim();
    const dd = document.getElementById('clienteDropdown');
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

/* ── Tabs ──────────────────────────────────────────────── */
function setTab(btn, id) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(id).classList.add('active');
    // Sincronizar visibilidad del checkbox Outlook al entrar al tab de actividades
    if (id === 'tact') {
        const label = document.getElementById('labelOutlookCheck');
        if (label) label.style.display = outlookConectado ? 'flex' : 'none';
        const chk = document.getElementById('chkOutlook');
        if (chk && outlookConectado) chk.checked = true;
    }
}

/* ── Abrir modal nuevo ─────────────────────────────────── */
function abrirNuevo(etapaId = null) {
    opId = null;
    opClienteId = null;
    document.getElementById('fOp').reset();
    document.getElementById('fId').value = '';
    document.getElementById('fCliente').value = '';
    document.getElementById('clienteSearch').value = '';
    document.getElementById('mTitle').textContent = 'Nueva Oportunidad';
    document.getElementById('mSub').textContent   = '';
    if (etapaId) document.getElementById('fEtapa').value = etapaId;

    document.getElementById('btnTabAct').style.display  = 'none';
    document.getElementById('btnTabPres').style.display = 'none';
    const btnDel = document.getElementById('btnDel');
    if (btnDel) btnDel.style.display = 'none';
    const boxCrear = document.getElementById('boxCrearPres');
    if (boxCrear) boxCrear.style.display = 'none';

    setTab(document.querySelector('.tab-btn'), 'tdatos');
    document.getElementById('overlay').classList.add('show');
    document.getElementById('fTitulo').focus();
}

/* ── Cerrar modal ──────────────────────────────────────── */
function cerrarModal() {
    document.getElementById('overlay').classList.remove('show');
    opId = null;
    opClienteId = null;
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
        opClienteId = op.cliente_id;

        document.getElementById('fId').value          = op.id;
        document.getElementById('fTitulo').value       = op.titulo;
        document.getElementById('fCliente').value      = op.cliente_id;
        document.getElementById('clienteSearch').value = d.cliente_nombre;
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

        // Mostrar caja de crear presupuesto (solo si tiene permiso y está en tab pres)
        const boxCrear = document.getElementById('boxCrearPres');
        if (boxCrear) boxCrear.style.display = 'flex';

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
const tipoIcon = { Llamada:'📞', Reunion:'🤝', Correo:'📧', 'Actualización de quote':'📋', Visita:'🏢' };
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
            <div class="act-item" data-id="${a.id}" data-tipo="${esc(a.tipo)}">            <div class="act-row">
                <span>
                    <span class="act-tipo">${tipoIcon[a.tipo] || '•'} ${esc(a.tipo)}</span>
                    <span class="act-who">${esc(a.nombre_usuario)}</span>
                    ${a.ms_event_id ? ' <i class="fas fa-calendar-check" style="color:var(--blue);font-size:.75rem;margin-left:3px;" title="En calendario de Outlook"></i>' : ''}
                </span>
                <span class="act-ts">${fmtDt(a.fecha_creacion)}</span>
            </div>
            <div class="act-prox">
                <i class="fas fa-calendar"></i>
                <strong>Fecha:</strong> ${fmtDt(a.fecha_proximo_paso)}
                ${a.proximo_paso ? `<span style="color:var(--ink-2);margin-left:4px;">· ${esc(a.proximo_paso)}</span>` : ''}
            </div>
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
                            <button class="btn btn-green" style="font-size:.78rem;padding:4px 10px;"
                                    onclick="guardarResultado(${a.id})">
                                <i class="fas fa-save"></i> Guardar
                            </button>
                            <button class="btn btn-ghost" style="font-size:.78rem;padding:4px 10px;"
                                    onclick="mostrarFormResultado(${a.id})">
                                Cancelar
                            </button>
                        </div>
                    </div>
                  </div>`
            }
        </div>
    `).join('');
    document.getElementById('badgeAct').textContent = lista.length || '';
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
        document.getElementById('badgePres').textContent = '';
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
    document.getElementById('badgePres').textContent = lista.length || '';
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
    const form = document.getElementById('fOp');
    // Validar cliente seleccionado
    if (!document.getElementById('fCliente').value) {
        toast('Selecciona un cliente de la lista', 'err');
        document.getElementById('clienteSearch').focus();
        return;
    }
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
    const enviarOutlook = document.getElementById('chkOutlook')?.checked ?? false;
    const payload = {
        oportunidad_id:  opId,
        tipo:            document.getElementById('actTipo').value,
        resultado:       document.getElementById('actResultado').value,
        proximo_paso:    document.getElementById('actProximo').value,
        fecha_proximo_paso: fechaProx,
        enviar_outlook:  enviarOutlook,
    };
    const r = await fetch('crear_oportunidad.php?action=save_actividad', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.success) {
        document.getElementById('actResultado').value = '';
        document.getElementById('actProximo').value   = '';
        document.getElementById('actFechaProx').value = '';
        if (document.getElementById('chkOutlook')) document.getElementById('chkOutlook').checked = false;
        renderActividades(d.actividades);

        if (d.ms_event_creado) {
            toast('✓ Actividad registrada y evento creado en Outlook 📅');
        } else if (d.ms_error) {
            toast('Actividad guardada. Exchange: ' + d.ms_error, 'err');
        } else {
            toast('Actividad registrada ✓');
        }
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

// Inicializar estado Outlook al cargar la página
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
function fmtDt(s) {
    if (!s) return '';
    return new Date(s).toLocaleString('es-BO', {
        day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'
    });
}
function numFmt(n) {
    return parseFloat(n || 0).toLocaleString('es-BO', {minimumFractionDigits:2, maximumFractionDigits:2});
}
</script>
</body>
</html>