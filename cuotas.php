<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("usuarios", "editar"); 

$stmt = $conn->query("SELECT * FROM gestiones WHERE activa = 1 LIMIT 1");
$gestion_activa = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->query("SELECT * FROM gestiones ORDER BY fecha_inicio DESC");
$gestiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gestion_id = $_GET['gestion'] ?? $gestion_activa['id'];

$stmt = $conn->prepare("
    SELECT 
        u.id, u.nombre, u.username, s.nombre as sucursal, c.cuota, 
        c.id as cuota_id
    FROM usuarios u
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    LEFT JOIN cuotas c ON (u.id = c.usuario_id AND c.gestion_id = ?)
    JOIN roles r ON u.rol_id = r.id AND r.nombre = 'Vendedor'
    ORDER BY s.nombre, u.nombre
");
$stmt->execute([$gestion_id]);

$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar usuarios con y sin cuota asignada
$con_cuota = 0;
$sin_cuota = 0;
foreach ($usuarios as $u) {
    if ($u['cuota'] && $u['cuota'] > 0) {
        $con_cuota++;
    } else {
        $sin_cuota++;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Cuotas</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="organigrama.css">
    <style>
        .organizacion-container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Estadísticas superiores */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid #3498db;
        }

        .stat-card.warning {
            border-left-color: #f39c12;
        }

        .stat-card.success {
            border-left-color: #27ae60;
        }

        .stat-card h4 {
            margin: 0 0 8px 0;
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        /* Botones de acción superiores */
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .btn-asignar-masivo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-asignar-masivo:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        /* Tabla moderna */
        .puestos-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
            margin-top: 20px;
        }

        .puestos-table thead th {
            background: transparent;
            color: #6c757d;
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 15px;
            border: none;
            text-align: left;
            letter-spacing: 0.5px;
        }

        .puestos-table tbody tr {
            background-color: white;
            transition: all 0.3s ease;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .puestos-table tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .puestos-table tbody tr.sin-cuota {
            background-color: #fff9e6;
        }

        .puestos-table td {
            padding: 18px 15px;
            border-top: 1px solid #f1f3f4;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
        }

        .puestos-table td:first-child {
            border-left: 1px solid #f1f3f4;
            border-radius: 10px 0 0 10px;
        }

        .puestos-table td:last-child {
            border-right: 1px solid #f1f3f4;
            border-radius: 0 10px 10px 0;
            text-align: center;
        }

        /* Progress bar */
        .progress-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .progress-bar {
            flex: 1;
            min-width: 120px;
            background: #e0e0e0;
            border-radius: 10px;
            height: 24px;
            overflow: hidden;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8px;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .progress-fill.success {
            background: linear-gradient(90deg, #27ae60, #2ecc71);
        }

        .progress-fill.warning {
            background: linear-gradient(90deg, #f39c12, #f1c40f);
        }

        .progress-fill.danger {
            background: linear-gradient(90deg, #e74c3c, #c0392b);
        }

        .progress-text {
            font-weight: bold;
            font-size: 0.95rem;
            min-width: 60px;
            text-align: right;
        }

        .progress-text.success { color: #27ae60; }
        .progress-text.warning { color: #f39c12; }
        .progress-text.danger { color: #e74c3c; }

        /* Tag sucursal */
        .tag-sucursal {
            display: inline-block;
            padding: 6px 14px;
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            color: #1976d2;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid #bbdefb;
        }

        /* Badge sin cuota */
        .badge-sin-cuota {
            display: inline-block;
            padding: 4px 10px;
            background: #f39c12;
            color: white;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Botón editar */
        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-editar {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }

        .btn-editar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .btn-asignar {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
        }

        .btn-asignar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        /* Modal body */
        .modal-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group input[readonly] {
            background: #f5f5f5;
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            margin: 0 -30px -30px -30px;
        }

        /* Lista de usuarios en modal masivo */
        .usuarios-list {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 10px;
        }

        .usuario-item-modal {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
        }

        .usuario-item-modal:last-child {
            border-bottom: none;
        }

        .usuario-item-modal input {
            width: 150px;
        }

        /* Sin datos */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #dfe6e9;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .puestos-table {
                font-size: 14px;
            }

            .progress-bar {
                min-width: 80px;
            }

            .puestos-table td {
                padding: 12px 10px;
            }
        }
    </style>
</head>
<body>

<div class="organizacion-container">
    <header class="header-section">
        <div style="display: flex; align-items: center; gap: 20px;">
            <img src="assets/logo.png" class="logo">
            <h1>Gestión de Cuotas de Venta</h1>
        </div>
        
        <div class="controls-row">
            <select class="select-custom" onchange="cambiarGestion(this.value)">
                <?php foreach ($gestiones as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $g['id'] == $gestion_id ? 'selected' : '' ?>>
                        Gestión <?= htmlspecialchars($g['nombre']) ?> <?= $g['activa'] ? '(Activa)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
        </div>
    </header>

    <!-- Estadísticas -->
    <div class="stats-container">
        <div class="stat-card success">
            <h4><i class="fas fa-users"></i> Total Usuarios</h4>
            <div class="value"><?= count($usuarios) ?></div>
        </div>
        <div class="stat-card">
            <h4><i class="fas fa-check-circle"></i> Con Cuota Asignada</h4>
            <div class="value"><?= $con_cuota ?></div>
        </div>
        <div class="stat-card warning">
            <h4><i class="fas fa-exclamation-circle"></i> Sin Cuota Asignada</h4>
            <div class="value"><?= $sin_cuota ?></div>
        </div>
    </div>

    <!-- Botones de acción -->
    <?php if ($sin_cuota > 0): ?>
    <div class="action-buttons">
        <button class="btn-asignar-masivo" onclick="asignarMasivo()">
            <i class="fas fa-users-cog"></i>
            Asignar Cuotas a Usuarios sin Cuota (<?= $sin_cuota ?>)
        </button>
    </div>
    <?php endif; ?>

    <?php if (empty($usuarios)): ?>
        <div class="no-data">
            <i class="fas fa-chart-line"></i>
            <p>No hay usuarios activos para asignar cuotas</p>
        </div>
    <?php else: ?>
        <table class="puestos-table">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Sucursal</th>
                    <th style="text-align: right;">Cuota Asignada (Bs)</th>
                    <th style="text-align: right;">Avance Actual (Bs)</th>
                    <th>Cumplimiento</th>
                    <th style="text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <?php
                    // Calcular avance del usuario en la gestión
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(monto_adjudicado), 0) as total
                        FROM proyecto p
                        JOIN estados e ON p.estado_id = e.id
                        WHERE p.id_usuario = ?
                        AND e.estado = 'Ganado'
                        AND p.gestion_id = ?
                    ");
                    $stmt->execute([$u['id'], $gestion_id]);
                    $avance = $stmt->fetch()['total'];
                    $porcentaje = $u['cuota'] > 0 ? ($avance / $u['cuota']) * 100 : 0;
                    
                    // Determinar clase de progreso
                    if ($porcentaje >= 100) {
                        $progressClass = 'success';
                    } elseif ($porcentaje >= 70) {
                        $progressClass = 'warning';
                    } else {
                        $progressClass = 'danger';
                    }

                    $sinCuota = !$u['cuota'] || $u['cuota'] <= 0;
                    ?>
                    <tr class="<?= $sinCuota ? 'sin-cuota' : '' ?>">
                        <td>
                            <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                            <?php if ($sinCuota): ?>
                                <span class="badge-sin-cuota">Sin cuota</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="tag-sucursal"><?= htmlspecialchars($u['sucursal']) ?></span></td>
                        <td style="text-align: right; font-weight: 600;">
                            <?= number_format($u['cuota'] ?? 0, 2, ',', '.') ?>
                        </td>
                        <td style="text-align: right;">
                            <?= number_format($avance, 2, ',', '.') ?>
                        </td>
                        <td>
                            <?php if (!$sinCuota): ?>
                                <div class="progress-container">
                                    <div class="progress-bar">
                                        <div class="progress-fill <?= $progressClass ?>" 
                                             style="width: <?= min($porcentaje, 100) ?>%;">
                                            <?php if ($porcentaje >= 25): ?>
                                                <?= number_format($porcentaje, 0) ?>%
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="progress-text <?= $progressClass ?>">
                                        <?= number_format($porcentaje, 1) ?>%
                                    </span>
                                </div>
                            <?php else: ?>
                                <span style="color: #95a5a6;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sinCuota): ?>
                                <button class="btn-action btn-asignar" 
                                        onclick="editarCuota(<?= $u['id'] ?>, 0, '<?= htmlspecialchars($u['nombre']) ?>')">
                                    <i class="fas fa-plus"></i> Asignar
                                </button>
                            <?php else: ?>
                                <button class="btn-action btn-editar" 
                                        onclick="editarCuota(<?= $u['id'] ?>, <?= $u['cuota'] ?>, '<?= htmlspecialchars($u['nombre']) ?>')">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal para editar cuota individual -->
<div id="modalCuota" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2><i class="fas fa-chart-line"></i> Asignar/Modificar Cuota</h2>
            <button class="close" onclick="cerrarModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formCuota" onsubmit="guardarCuota(event)">
                <input type="hidden" id="usuario_id" name="usuario_id">
                <input type="hidden" id="gestion_id" name="gestion_id" value="<?= $gestion_id ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Usuario</label>
                    <input type="text" id="nombre_usuario" readonly>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-dollar-sign"></i> Cuota Anual (Bs)</label>
                    <input type="number" step="0.01" id="cuota" name="cuota" required min="0" 
                           placeholder="Ingrese el monto de la cuota anual">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-success">
                        <i class="fas fa-save"></i> Guardar Cuota
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal para asignación masiva -->
<div id="modalMasivo" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h2><i class="fas fa-users-cog"></i> Asignación Masiva de Cuotas</h2>
            <button class="close" onclick="cerrarModalMasivo()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formMasivo" onsubmit="guardarMasivo(event)">
                <input type="hidden" name="gestion_id" value="<?= $gestion_id ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-info-circle"></i> Usuarios sin cuota asignada</label>
                    <p style="color: #666; font-size: 0.9rem;">Asigna una cuota a cada usuario o deja en 0 para omitir</p>
                </div>
                
                <div class="usuarios-list">
                    <?php foreach ($usuarios as $u): ?>
                        <?php if (!$u['cuota'] || $u['cuota'] <= 0): ?>
                            <div class="usuario-item-modal">
                                <div>
                                    <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                                    <br>
                                    <small style="color: #666;"><?= htmlspecialchars($u['sucursal']) ?></small>
                                </div>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       name="cuotas[<?= $u['id'] ?>]" 
                                       placeholder="Cuota (Bs)"
                                       class="cuota-input">
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="cerrarModalMasivo()">Cancelar</button>
                    <button type="submit" class="btn-success">
                        <i class="fas fa-save"></i> Guardar Todas las Cuotas
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function cambiarGestion(id) {
        window.location.href = `cuotas.php?gestion=${id}`;
    }

    function editarCuota(usuarioId, cuotaActual, nombreUsuario) {
        document.getElementById('usuario_id').value = usuarioId;
        document.getElementById('nombre_usuario').value = nombreUsuario;
        document.getElementById('cuota').value = cuotaActual || '';
        
        const modal = document.getElementById('modalCuota');
        modal.classList.add('show');
        
        setTimeout(() => {
            document.getElementById('cuota').focus();
        }, 300);
    }

    function cerrarModal() {
        const modal = document.getElementById('modalCuota');
        modal.classList.remove('show');
    }

    function asignarMasivo() {
        const modal = document.getElementById('modalMasivo');
        modal.classList.add('show');
    }

    function cerrarModalMasivo() {
        const modal = document.getElementById('modalMasivo');
        modal.classList.remove('show');
    }

    async function guardarCuota(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        try {
            const response = await fetch('guardar_cuota.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                toast('Cuota guardada correctamente');
                setTimeout(() => location.reload(), 1000);
            } else {
                toast('Error: ' + result.message, false);
            }
        } catch (error) {
            console.error('Error:', error);
            toast('Error al guardar la cuota', false);
        }
    }

    async function guardarMasivo(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        try {
            const response = await fetch('guardar_cuotas_masivo.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                toast(`${result.count} cuota(s) asignada(s) correctamente`);
                setTimeout(() => location.reload(), 1500);
            } else {
                toast('Error: ' + result.message, false);
            }
        } catch (error) {
            console.error('Error:', error);
            toast('Error al guardar las cuotas', false);
        }
    }

    function toast(msg, ok=true) {
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = `
            position: fixed; bottom: 20px; right: 20px; z-index: 10000;
            background: ${ok ? '#27ae60' : '#e74c3c'};
            color: #fff; padding: 12px 20px; border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        `;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2500);
    }

    // Cerrar modales con ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            cerrarModal();
            cerrarModalMasivo();
        }
    });

    // Cerrar modales al hacer clic fuera
    window.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) {
            e.target.classList.remove('show');
        }
    });
</script>

</body>
</html>