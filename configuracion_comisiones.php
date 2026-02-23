<?php 
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("finanzas", "editar");

$stmt = $conn->query("SELECT id, nombre FROM gestiones ORDER BY nombre DESC");
$gestiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gestion_id = $_GET['gestion'] ?? $gestiones[0]['id'];

// Margen Producto
$stmt = $conn->prepare("
    SELECT *
    FROM comision_margen_producto
    WHERE gestion_id = ? AND activo = 1
    ORDER BY margen_desde ASC
");
$stmt->execute([$gestion_id]);
$margenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Meta Extra
$stmt = $conn->prepare("
    SELECT *
    FROM comision_meta_extra
    WHERE gestion_id = ? AND activo = 1
    ORDER BY porcentaje_meta_desde DESC
");
$stmt->execute([$gestion_id]);
$metas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mensajes de éxito/error
$mensaje = '';
$tipo_mensaje = '';

if (isset($_GET['success'])) {
    if ($_GET['success'] == 'delete') {
        $mensaje = 'Registro eliminado correctamente';
        $tipo_mensaje = 'success';
    } else {
        $mensaje = 'Registro guardado correctamente';
        $tipo_mensaje = 'success';
    }
} elseif (isset($_GET['error'])) {
    $mensaje = 'Ocurrió un error al procesar la operación';
    $tipo_mensaje = 'error';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Comisiones</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="comision.css">
</head>
<body>
    <div class="dashboard-container">
        <header>
            <div style="display: flex; align-items: center; gap: 20px;">
                <img src="assets/logo.png" class="logo" alt="Logo">
                <h1>Configuración Comisiones</h1>
            </div>
            
            <div class="header-buttons">
                <a href="dashboard.php" class="btn btn-secondary">
                    Volver al Dashboard
                </a>
            </div>
        </header>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $tipo_mensaje ?>" id="mensaje">
                <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <div class="filtros-container">
            <form method="get" style="margin-bottom: 20px;">
                <label>Gestión: </label>
                <select name="gestion" class="select-custom" onchange="this.form.submit()">
                    <?php foreach ($gestiones as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $g['id'] == $gestion_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div> 

        <div class="tables-container">
            <div class="table-section">
                <div class="section-header">
                    <h2><i class="fas fa-percentage"></i> Comisión por Producto</h2>
                    <button class="btn btn-success" onclick="openModal('prod')">
                        <i class="fas fa-plus"></i> Nuevo
                    </button>
                </div>

                <?php if (count($margenes) > 0): ?>
                    <table class="costos-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-arrow-up"></i> Desde %</th>
                                <th><i class="fas fa-arrow-down"></i> Hasta %</th>
                                <th>Comisión %</th>
                                <th style="text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($margenes as $r): ?>
                                <tr>
                                    <td><?= number_format($r['margen_desde'], 2) ?>%</td>
                                    <td><?= $r['margen_hasta'] ? number_format($r['margen_hasta'], 2) . '%' : '∞' ?></td>
                                    <td><strong><?= number_format($r['porcentaje'], 2) ?>%</strong></td>
                                    <td>
                                        <button class="btn btn-outline" onclick='editProd(<?= htmlspecialchars(json_encode($r)) ?>)' title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger" onclick="deleteProd(<?= $r['id'] ?>)" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-inbox"></i>
                        <p>No hay rangos configurados</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TABLA DE COMISIÓN POR EXTRA -->
            <div class="table-section">
                <div class="section-header">
                    <h2><i class="fas fa-trophy"></i> Comisión por Extra</h2>
                    <button class="btn btn-success" onclick="openModal('extra')">
                        <i class="fas fa-plus"></i> Nuevo
                    </button>
                </div>

                <?php if (count($metas) > 0): ?>
                    <table class="costos-table">
                        <thead>
                            <tr>
                                <th>Meta Desde %</th>
                                <th><i class="fas fa-star"></i> Extra %</th>
                                <th style="text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($metas as $r): ?>
                                <tr>
                                    <td><?= number_format($r['porcentaje_meta_desde'], 2) ?>%</td>
                                    <td><strong><?= number_format($r['porcentaje_extra'], 2) ?>%</strong></td>
                                    <td>
                                        <button class="btn btn-outline" onclick='editExtra(<?= htmlspecialchars(json_encode($r)) ?>)' title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger" onclick="deleteExtra(<?= $r['id'] ?>)" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-inbox"></i>
                        <p>No hay rangos configurados</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- MODAL -->
        <div class="modal" id="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modalTitle"></h2>
                    <button class="close" onclick="closeModal()">&times;</button>
                </div>

                <form method="post" action="guardar_comision.php">
                    <input type="hidden" name="id" id="id">
                    <input type="hidden" name="tipo" id="tipo">
                    <input type="hidden" name="gestion_id" value="<?= $gestion_id ?>">

                    <div class="modal-body" id="modalBody"></div>

                    <div style="padding: 0 25px 25px 25px;">
                        <button class="btn btn-primary" type="submit">
                            Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Función para abrir modal
        function openModal(tipo) {
            const modal = document.getElementById('modal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            const tipoInput = document.getElementById('tipo');
            const idInput = document.getElementById('id');
            
            tipoInput.value = tipo;
            idInput.value = '';
            
            if (tipo === 'prod') {
                modalTitle.innerHTML = 'Nuevo Rango por Producto';
                modalBody.innerHTML = `
                    <div class="form-group">
                        <label><i class="fas fa-arrow-up"></i> Margen Desde %</label>
                        <input type="number" name="margen_desde" step="0.01" required class="form-control" placeholder="Ej: 10.00">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-arrow-down"></i> Margen Hasta % (opcional)</label>
                        <input type="number" name="margen_hasta" step="0.01" class="form-control" placeholder="Dejar vacío para infinito">
                    </div>
                    <div class="form-group">
                        <label> Porcentaje Comisión %</label>
                        <input type="number" name="porcentaje" step="0.01" required class="form-control" placeholder="Ej: 5.00">
                    </div>
                `;
            } else {
                modalTitle.innerHTML = ' Nuevo Rango por Extra';
                modalBody.innerHTML = `
                    <div class="form-group">
                        <label> Meta Desde %</label>
                        <input type="number" name="porcentaje_meta_desde" step="0.01" required class="form-control" placeholder="Ej: 100.00">
                    </div>
                    <div class="form-group">
                        <label>Porcentaje Extra %</label>
                        <input type="number" name="porcentaje_extra" step="0.01" required class="form-control" placeholder="Ej: 2.00">
                    </div>
                `;
            }
            
            modal.style.display = 'flex';
        }

        // Función para cerrar modal
        function closeModal() {
            document.getElementById('modal').style.display = 'none';
        }

        // Función para editar producto
        function editProd(data) {
            const modal = document.getElementById('modal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            const tipoInput = document.getElementById('tipo');
            const idInput = document.getElementById('id');
            
            tipoInput.value = 'prod';
            idInput.value = data.id;
            modalTitle.innerHTML = 'Editar Rango por Producto';
            
            modalBody.innerHTML = `
                <div class="form-group">
                    <label><i class="fas fa-arrow-up"></i> Margen Desde %</label>
                    <input type="number" name="margen_desde" step="0.01" required class="form-control" value="${data.margen_desde}">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-arrow-down"></i> Margen Hasta % (opcional)</label>
                    <input type="number" name="margen_hasta" step="0.01" class="form-control" value="${data.margen_hasta || ''}">
                </div>
                <div class="form-group">
                    <label>Porcentaje Comisión %</label>
                    <input type="number" name="porcentaje" step="0.01" required class="form-control" value="${data.porcentaje}">
                </div>
            `;
            
            modal.style.display = 'flex';
        }

        // Función para editar extra
        function editExtra(data) {
            const modal = document.getElementById('modal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            const tipoInput = document.getElementById('tipo');
            const idInput = document.getElementById('id');
            
            tipoInput.value = 'extra';
            idInput.value = data.id;
            modalTitle.innerHTML = 'Editar Rango por Extra';
            
            modalBody.innerHTML = `
                <div class="form-group">
                    <label>Meta Desde %</label>
                    <input type="number" name="porcentaje_meta_desde" step="0.01" required class="form-control" value="${data.porcentaje_meta_desde}">
                </div>
                <div class="form-group">
                    <label>Porcentaje Extra %</label>
                    <input type="number" name="porcentaje_extra" step="0.01" required class="form-control" value="${data.porcentaje_extra}">
                </div>
            `;
            
            modal.style.display = 'flex';
        }

        // Función para eliminar producto
        function deleteProd(id) {
            if (confirm('¿Está seguro de eliminar este rango?')) {
                window.location.href = `eliminar_comision.php?id=${id}&tipo=prod&gestion=<?= $gestion_id ?>`;
            }
        }

        // Función para eliminar extra
        function deleteExtra(id) {
            if (confirm('¿Está seguro de eliminar este rango?')) {
                window.location.href = `eliminar_comision.php?id=${id}&tipo=extra&gestion=<?= $gestion_id ?>`;
            }
        }

        // Cerrar modal al hacer click fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Auto-ocultar mensajes después de 5 segundos
        const mensaje = document.getElementById('mensaje');
        if (mensaje) {
            setTimeout(() => {
                mensaje.style.opacity = '0';
                setTimeout(() => {
                    mensaje.style.display = 'none';
                }, 300);
            }, 4000);
        }
    </script>
</body>
</html>