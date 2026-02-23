<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("organigrama", "ver");

$stmt = $conn->query("SELECT * FROM gestiones WHERE activa = 1 ORDER BY fecha_inicio DESC LIMIT 1");
$gestion_activa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gestion_activa) {
    $stmt = $conn->query("SELECT * FROM gestiones ORDER BY fecha_inicio DESC LIMIT 1");
    $gestion_activa = $stmt->fetch(PDO::FETCH_ASSOC);
}

$gestion_id = isset($_GET['gestion']) ? intval($_GET['gestion']) : ($gestion_activa['id'] ?? 0);

$stmt = $conn->query("SELECT * FROM gestiones ORDER BY fecha_inicio DESC");
$gestiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT p.*, 
           u.nombre as nombre_usuario, 
           c.nombre as nombre_cargo,
           c.nivel as nivel_cargo, -- Agregado
           jefe_u.nombre as nombre_jefe,
           jefe_c.nombre as cargo_jefe
    FROM puestos p
    JOIN usuarios u ON p.usuario_id = u.id
    JOIN cargos c ON p.cargo_id = c.id
    LEFT JOIN puestos p_jefe ON p.jefe_puesto_id = p_jefe.id
    LEFT JOIN usuarios jefe_u ON p_jefe.usuario_id = jefe_u.id
    LEFT JOIN cargos jefe_c ON p_jefe.cargo_id = jefe_c.id
    WHERE p.gestion_id = ?
    ORDER BY c.nivel ASC, u.nombre ASC -- Ordenar por nivel ayuda
");
$stmt->execute([$gestion_id]);
$puestos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cargos = $conn->query("SELECT id, nombre, nivel FROM cargos ORDER BY nivel ASC")->fetchAll(PDO::FETCH_ASSOC);

$usuarios = $conn->query("SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Organización - Gestión <?= htmlspecialchars($gestion_activa['nombre'] ?? '') ?></title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="organigrama.css">
</head>
<body>
    <div class="organizacion-container">
        <header class="header-section">
            <img src="assets/logo.png" class="logo">
            <h1>Organización y Estructura</h1>
            
            <div class="controls-row">
                <select id="selector-gestion" class="select-custom" onchange="cambiarGestion(this.value)">
                    <?php foreach ($gestiones as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $g['id'] == $gestion_id ? 'selected' : '' ?>>
                            Gestión <?= htmlspecialchars($g['nombre']) ?> 
                            <?= $g['activa'] ? '(Activa)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="btn-group">
                    <button class="btn btn-success" onclick="abrirModalPuesto()">
                        Asignar Puesto
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
                </div>
            </div>
        </header>

        <div class="tabs">
            <div class="tab active" onclick="cambiarTab('organigrama')">Organigrama</div>
            <div class="tab" onclick="cambiarTab('tabla')">Tabla</div>
        </div>


        <main>
            <div id="tab-organigrama" class="tab-content active">
                <div class="organigrama">
                    <div id="organigrama-content"></div>
                </div>
            </div>

            <div id="tab-tabla" class="tab-content">
                <div id="puestos-grid"></div>
            </div>
        </main>
    </div>

    <!-- Modal Asignar Puesto -->
    <div id="modalPuesto" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="fa-solid fa-user-tie"></i> 
                <h2 id="modalTitulo"> Asignar Puesto</h2>
                <button class="close" onclick="cerrarModalPuesto()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formPuesto" onsubmit="guardarPuesto(event)">
                    <input type="hidden" id="puesto_id" name="id">
                    <input type="hidden" name="gestion_id" value="<?= $gestion_id ?>">

                    <div class="form-group">
                        <label>Usuario</label>
                        <select name="usuario_id" id="usuario_select" required>
                            <option value="">Seleccionar usuario...</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cargo</label>
                        <select name="cargo_id" id="cargo_select" required onchange="filtrarJefes()">
                            <option value="">Seleccionar cargo...</option>
                            <?php foreach ($cargos as $c): ?>
                                <option value="<?= $c['id'] ?>" data-nivel="<?= $c['nivel'] ?>">
                                    <?= htmlspecialchars($c['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="optional">Reporta a (Jefe)</label>
                        <select name="jefe_puesto_id" id="jefe_select">
                            <option value="">Sin jefe directo</option>
                            <?php foreach ($puestos as $p): ?>
                                <option value="<?= $p['id'] ?>" data-nivel="<?= $p['nivel_cargo'] ?>">
                                    <?= htmlspecialchars($p['nombre_usuario']) ?> - <?= htmlspecialchars($p['nombre_cargo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="optional">Fecha Inicio</label>
                        <input type="date" name="fecha_inicio" id="fecha_inicio_input">
                    </div>

                    <div class="form-group">
                        <label class="optional">Fecha Fin</label>
                        <input type="date" name="fecha_fin" id="fecha_fin_input">
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="cerrarModalPuesto()">
                            Cancelar
                        </button>
                        <button type="submit" class="btn-success">
                            Guardar Puesto
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Gestiones -->
    <div id="modalGestion" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Gestionar Gestiones</h2>
                <span class="close" onclick="cerrarModalGestion()">&times;</span>
            </div>
            <form id="formGestion" onsubmit="guardarGestion(event)">
                <input type="hidden" id="gestion_id" name="id">

                <div class="form-group">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" placeholder="Ej: 2026" required>
                </div>

                <div class="form-group">
                    <label>Fecha Inicio *</label>
                    <input type="date" name="fecha_inicio" required>
                </div>

                <div class="form-group">
                    <label>Fecha Fin *</label>
                    <input type="date" name="fecha_fin" required>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="activa" value="1">
                        Marcar como gestión activa
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalGestion()">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-success">
                        Guardar Gestión
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const puestos = <?= json_encode($puestos) ?>;
        const gestionId = <?= $gestion_id ?>;

        function crearTablaPuestos() {
            const container = document.getElementById('puestos-grid');
            
            if (puestos.length === 0) {
                container.innerHTML = '<div class="no-data"><p>No hay puestos asignados en esta gestión</p></div>';
                return;
            }
            
            let html = `
                <table class="puestos-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Cargo</th>
                            <th>Reporta a</th>
                            <th>Desde</th>
                            <th>Hasta</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            puestos.forEach(p => {
                html += `
                    <tr>
                        <td><strong>${p.nombre_usuario}</strong></td>
                        <td><span class="badge-cargo">${p.nombre_cargo}</span></td>
                        <td>${p.nombre_jefe ? `<span class="tag-jefe">${p.nombre_jefe}</span>` : '-'}</td>
                        <td>${p.fecha_inicio || '-'}</td>
                        <td>${p.fecha_fin || '-'}</td>
                        <td class="acciones-cell">
                            <button class="btn-action btn-editar" onclick="editarPuesto(${p.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-action btn-eliminar" onclick="eliminarPuesto(${p.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            container.innerHTML = html;
        }

        function crearOrganigrama() {
            const container = document.getElementById('organigrama-content');
            
            if (puestos.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;">No hay puestos asignados en esta gestión</div>';
                return;
            }

            const niveles = {};
            const puestosPorId = {};
            
            puestos.forEach(p => {
                puestosPorId[p.id] = p;
            });

            // Identificar niveles
            puestos.forEach(p => {
                let nivel = 0;
                let actual = p;
                
                while (actual.jefe_puesto_id && puestosPorId[actual.jefe_puesto_id]) {
                    nivel++;
                    actual = puestosPorId[actual.jefe_puesto_id];
                    if (nivel > 10) break; // Prevenir loops infinitos
                }
                
                if (!niveles[nivel]) niveles[nivel] = [];
                niveles[nivel].push(p);
            });

            // Construir HTML
            let html = '<div class="org-chart">';
            
            Object.keys(niveles).sort((a, b) => a - b).forEach(nivel => {
                html += '<div class="org-level">';
                
                niveles[nivel].forEach(p => {
                    const inicial = p.nombre_usuario.charAt(0);
                    html += `
                        <div class="org-node" data-id="${p.id}">
                            <div class="avatar-placeholder">${inicial}</div>
                            <div class="cargo">${p.nombre_cargo}</div>
                            <div class="nombre">${p.nombre_usuario}</div>
                            ${p.nombre_jefe ? `<div style="font-size: 11px; color: var(--primary); margin-top: 8px; border-top: 1px dotted #eee; pt-2">Reporta a: <br><b>${p.nombre_jefe}</b></div>` : ''}
                        </div>
                    `;
                });
                
                html += '</div>';
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        // Funciones
        function cambiarTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            document.querySelector(`[onclick="cambiarTab('${tab}')"]`).classList.add('active');
            document.getElementById(`tab-${tab}`).classList.add('active');

            if (tab === 'organigrama') crearOrganigrama();
            if(tab === 'tabla') crearTablaPuestos();
        }


        function cambiarGestion(id) {
            window.location.href = `organigrama.php?gestion=${id}`;
        }

        function abrirModalPuesto() {
            const modal = document.getElementById('modalPuesto');
            const titulo = document.getElementById('modalTitulo');
            
            modal.classList.add('show');
            document.getElementById('formPuesto').reset();
            document.getElementById('puesto_id').value = '';
            titulo.textContent = 'Asignar Nuevo Puesto';
            
            setTimeout(() => {
                document.getElementById('usuario_select').focus();
                filtrarJefes();
            }, 300);
        }

        function cerrarModalPuesto() {
            const modal = document.getElementById('modalPuesto');
            modal.classList.remove('show');
        }

        function editarPuesto(id) {
            const puesto = puestos.find(p => p.id === id);
            if (!puesto) return;
            
            document.getElementById('modalTitulo').textContent = 'Editar Puesto';
            
            document.getElementById('puesto_id').value = puesto.id;
            document.getElementById('usuario_select').value = puesto.usuario_id;
            document.getElementById('cargo_select').value = puesto.cargo_id;
            document.getElementById('jefe_select').value = puesto.jefe_puesto_id || '';
            document.getElementById('fecha_inicio_input').value = puesto.fecha_inicio || '';
            document.getElementById('fecha_fin_input').value = puesto.fecha_fin || '';
            
            // Abrir modal
            const modal = document.getElementById('modalPuesto');
            modal.classList.add('show');
            
            setTimeout(() => {
                document.getElementById('cargo_select').focus();
            }, 300);
        }

        window.addEventListener('click', function(event) {
            const modal = document.getElementById('modalPuesto');
            if (event.target === modal) {
                cerrarModalPuesto();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                cerrarModalPuesto();
            }
        });

        function filtrarJefes() {
            const cargoSelect = document.getElementById('cargo_select');
            const jefeSelect = document.getElementById('jefe_select');

            const selectedOption = cargoSelect.options[cargoSelect.selectedIndex];
            const nivelSeleccionado = selectedOption.dataset.nivel ? parseInt(selectedOption.dataset.nivel) : null;

            if (!nivelSeleccionado) {
                Array.from(jefeSelect.options).forEach(opt => opt.style.display = 'block');
                return;
            }

            Array.from(jefeSelect.options).forEach(option => {
                const nivelJefe = option.dataset.nivel ? parseInt(option.dataset.nivel) : null;

                if (nivelJefe === null) {
                    option.style.display = 'block';
                    return;
                }

                if (nivelJefe < nivelSeleccionado) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });

            const jefeSeleccionadoActual = jefeSelect.options[jefeSelect.selectedIndex];
            if (jefeSeleccionadoActual.style.display === 'none') {
                jefeSelect.value = '';
            }
        }


        async function guardarPuesto(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            try {
                const response = await fetch('guardar_puesto.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    toast('Puesto guardado correctamente');
                    location.reload();
                } else {
                    toast('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                toast('Error al guardar el puesto');
            }
        }

        async function eliminarPuesto(id) {
            if (!confirm('¿Estás seguro de eliminar este puesto?')) return;
            
            try {
                const response = await fetch('eliminar_puesto.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: id})
                });
                
                const result = await response.json();
                
                if (result.success) {
                    toast('Puesto eliminado correctamente');
                    location.reload();
                } else {
                    toast('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                toast('Error al eliminar el puesto');
            }
        }

        function toast(msg, ok=true){
            const t=document.createElement('div');
            t.textContent=msg;
            t.style.cssText=`
                position:fixed;bottom:20px;right:20px;
                background:${ok?'#2ec4b6':'#e71d36'};
                color:#fff;padding:12px 20px;border-radius:8px;
                box-shadow:0 4px 10px rgba(0,0,0,.2);
            `;
            document.body.appendChild(t);
            setTimeout(()=>t.remove(),2500);
        }


        // Inicializar
        crearOrganigrama();
    </script>
</body>
</html>