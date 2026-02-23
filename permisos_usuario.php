<?php
include 'includes/config.php';
include 'includes/auth.php';

// Solo superusuarios pueden acceder
verificarPermiso("roles", "editar");

$usuario_id = isset($_GET['usuario']) ? intval($_GET['usuario']) : null;

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['asignar_permiso'])) {
        $usuario_id = intval($_POST['usuario_id']);
        $permisos_ids = $_POST['permiso_ids'] ?? [];
        $tipo = $_POST['tipo'];
        $contador = 0;
        
        foreach ($permisos_ids as $p_id) {
            if (SistemaPermisos::asignarPermisoUsuario($conn, $usuario_id, intval($p_id), $tipo, $_SESSION['usuario']['id'])) {
                $contador++;
            }
        }
        
        if ($contador > 0) {
            $success = "Se aplicaron $contador permiso(s) correctamente.";
        } else {
            $error = "No se seleccionó ningún permiso o hubo un error.";
        }
    }
    
    if (isset($_POST['eliminar_permiso'])) {
        $usuario_id = intval($_POST['usuario_id']);
        $permiso_id = intval($_POST['permiso_id']);
        
        if (SistemaPermisos::eliminarPermisoUsuario($conn, $usuario_id, $permiso_id)) {
            $success = "Permiso eliminado correctamente";
        } else {
            $error = "Error al eliminar permiso";
        }
    }
}

// Obtener usuarios
$stmt = $conn->query("
    SELECT u.*, r.nombre as rol_nombre 
    FROM usuarios u 
    LEFT JOIN roles r ON u.rol_id = r.id 
    WHERE u.activo = 1 
    ORDER BY u.nombre
");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si hay un usuario seleccionado, obtener su información
$usuario_seleccionado = null;
$permisos_usuario = [];
$permisos_rol = [];


if ($usuario_id) {
    $stmt = $conn->prepare("
        SELECT u.*, r.nombre as rol_nombre, r.id as rol_id
        FROM usuarios u 
        LEFT JOIN roles r ON u.rol_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$usuario_id]);
    $usuario_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuario_seleccionado) {
        // Permisos del rol
        if ($usuario_seleccionado['rol_id']) {
            $permisos_rol = SistemaPermisos::obtenerPermisosRol($conn, $usuario_seleccionado['rol_id']);
            
            $permisos_agrupados = [];
            foreach ($permisos_rol as $p) {
                $modulo = $p['modulo_nombre'];
                
                if (!isset($permisos_agrupados[$modulo])) {
                    $permisos_agrupados[$modulo] = [];
                }                
                $permisos_agrupados[$modulo][] = [
                    'id' => $p['id'],
                    'accion' => $p['accion_nombre'],
                ];
            }
        }
        
        // Permisos específicos del usuario
        $stmt = $conn->prepare("
            SELECT up.*, p.nombre, p.descripcion, 
                   m.nombre as modulo_nombre, a.nombre as accion_nombre,
                   u_created.nombre as otorgado_por
            FROM usuario_permisos up
            JOIN permisos p ON up.permiso_id = p.id
            JOIN modulos m ON p.modulo_id = m.id
            JOIN acciones a ON p.accion_id = a.id
            LEFT JOIN usuarios u_created ON up.created_by = u_created.id
            WHERE up.usuario_id = ?
            ORDER BY m.orden, a.slug
        ");
        $stmt->execute([$usuario_id]);
        $permisos_usuario = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ids_rol = array_column($permisos_rol, 'id');

        $ids_usuario = array_column($permisos_usuario, 'permiso_id');

        $ids_permisos_existentes = array_unique(
            array_merge($ids_rol, $ids_usuario)
        );

        $todos = SistemaPermisos::obtenerTodosPermisos($conn);

        $permisos_para_conceder = [];
        $permisos_para_revocar  = [];

        foreach ($todos as $modulo => $datos) {
            foreach ($datos['permisos'] as $permiso) {

                if (in_array($permiso['id'], $ids_permisos_existentes)) {
                    // 🔴 YA TIENE → se puede REVOCAR
                    $permisos_para_revocar[$modulo]['permisos'][] = $permiso;
                } else {
                    // 🟢 NO TIENE → se puede CONCEDER
                    $permisos_para_conceder[$modulo]['permisos'][] = $permiso;
                }
            }
        }

    }
    
}


?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Permisos Específicos de Usuario</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
    <style>
        .usuarios-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .usuarios-sidebar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .usuario-item {
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .usuario-item:hover {
            background: #f8f9fa;
        }
        
        .usuario-item.active {
            background: #e7f5ff;
            border-color: #34a44c;
        }
        
        .permisos-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .permisos-section {
            margin-bottom: 30px;
        }
        
        .permisos-section h3 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #34a44c;
        }
        
        .permiso-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        
        .permiso-item.conceder {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        
        .permiso-item.revocar {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        
        .permiso-info {
            flex: 1;
        }
        
        .permiso-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .badge-conceder {
            background: #28a745;
            color: white;
        }
        
        .badge-revocar {
            background: #dc3545;
            color: white;
        }
        
        .btn-agregar {
            background: #17a2b8;
            color: white;
        }
        
        .btn-agregar:hover {
            background: #138496;
        }
        
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
            margin: 5% auto;
            padding: 30px;
            border-radius: 8px;
            width: 600px;
            max-width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .search-box {
            margin-bottom: 15px;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .permisos-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
        }
        
        .permiso-option {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .permiso-option:last-child {
            border-bottom: none;
        }
        
        .modulo-title {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .permiso-radio {
            margin-left: 20px;
            margin-top: 5px;
        }
        
        .permiso-radio label {
            cursor: pointer;
            font-weight: normal;
            display: inline-block;
            margin-bottom: 3px;
        }
        
        .permiso-radio input[type="radio"] {
            margin-right: 5px;
        }
        .modal-permisos-modern {
            width: 650px;
            max-width: 95%;
            border-radius: 14px;
            padding: 0;
        }

        /* Header */
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
        }

        .modal-close {
        font-size: 28px;
        cursor: pointer;
        color: #aaa;
        }

        /* Tipo toggle */
        .tipo-toggle {
        display: flex;
        gap: 12px;
        padding: 20px 25px;
        }

        .tipo-toggle input {
        display: none;
        }

        .toggle {
        padding: 8px 18px;
        border-radius: 20px;
        border: 1px solid #ddd;
        cursor: pointer;
        font-weight: 500;
        }

        input[value="conceder"]:checked + .conceder {
        background: #d4edda;
        border-color: #28a745;
        }

        input[value="revocar"]:checked + .revocar {
        background: #f8d7da;
        border-color: #dc3545;
        }

        /* Buscar */
        .input-search {
        margin: 0 25px 15px;
        padding: 10px;
        width: calc(100% - 50px);
        border-radius: 8px;
        border: 1px solid #ddd;
        }

        /* Permisos */
        .permisos-grid {
        padding: 0 25px;
        max-height: 300px;
        overflow-y: auto;
        }

        .permiso-modulo {
        margin-bottom: 20px;
        }

        .permiso-modulo h4 {
        margin-bottom: 8px;
        font-size: 14px;
        color: #2c3e50;
        }

        /* Chips */
        .acciones-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        }

        .accion-chip {
        cursor: pointer;
        }

        .accion-chip input {
        display: none;
        }

        .accion-chip span {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 16px;
        border: 1px solid #ccc;
        font-size: 13px;
        background: #f8f9fa;
        }

        .accion-chip input:checked + span {
        background: #e7f5ff;
        border-color: #3498db;
        }

        /* Footer */
        .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 15px 25px;
        border-top: 1px solid #eee;
        }

        .btn-cancel {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .permisos-lista-lineal {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #eee;
        }

        .modulo-linea {
            margin-bottom: 8px;
            font-size: 14px;
            color: #333;
            display: block; /* Asegura que cada módulo empiece en su propia fila */
        }

        .modulo-linea strong {
            color: #2c3e50;
            margin-right: 5px;
        }

        .acciones-texto {
            color: #666;
            font-weight: 500;
        }

        /* Opcional: una línea divisoria sutil entre módulos */
        .modulo-linea:not(:last-child) {
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 8px;
        }

    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Permisos Específicos de Usuario</h1>
            <a href="gestion_usuarios.php?tab=permisos" class="btn-back">Volver</a>
        </header>
        
        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="usuarios-container">
            <!-- Sidebar de Usuarios -->
            <div class="usuarios-sidebar">
                <h3 style="margin-top: 0;">Usuarios</h3>
                <div class="search-box">
                    <input type="text" id="searchUsuarios" placeholder="Buscar usuario...">
                </div>
                
                <?php if (empty($usuarios)): ?>
                    <p style="text-align: center; color: #666;">No hay usuarios disponibles</p>
                <?php else: ?>
                    <?php foreach ($usuarios as $usuario): ?>
                        <div class="usuario-item <?= $usuario['id'] == $usuario_id ? 'active' : '' ?>"
                             data-nombre="<?= htmlspecialchars($usuario['nombre']) ?> <?= htmlspecialchars($usuario['username']) ?>"
                             onclick="window.location.href='?usuario=<?= $usuario['id'] ?>'">
                            <div style="font-weight: bold;"><?= htmlspecialchars($usuario['nombre']) ?></div>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                <?= htmlspecialchars($usuario['username']) ?>
                            </div>
                            <?php if ($usuario['rol_nombre']): ?>
                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                    Rol: <?= htmlspecialchars($usuario['rol_nombre']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Contenido de Permisos -->
            <div class="permisos-content">
                <?php if ($usuario_seleccionado): ?>
                    <h2>Permisos de: <?= htmlspecialchars($usuario_seleccionado['nombre']) ?></h2>
                    
                    <div class="info-section">
                        <strong>Rol Base:</strong> <?= htmlspecialchars($usuario_seleccionado['rol_nombre'] ?? 'Sin rol asignado') ?>
                    </div>
                    <!--- Permisos por rol --->
                    <div class="permisos-section">
                        <h3>Permisos del Rol (<?= htmlspecialchars($usuario_seleccionado['rol_nombre'] ?? 'Sin rol') ?>)</h3>
                        
                        <?php if (empty($permisos_agrupados)): ?>
                            <p style="color: #666;">Este usuario no tiene permisos asignados por su rol.</p>
                        <?php else: ?>
                            <div class="permisos-lista-lineal">
                                <?php foreach ($permisos_agrupados as $modulo => $acciones): ?>
                                    <div class="modulo-linea">
                                        <strong><?= htmlspecialchars($modulo) ?>:</strong> 
                                        <span class="acciones-texto">
                                            <?php 
                                            $nombres_acciones = array_column($acciones, 'accion');
                                            echo htmlspecialchars(implode(' - ', $nombres_acciones)); 
                                            ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Permisos Específicos del Usuario -->
                    <div class="permisos-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0;">Permisos Específicos</h3>
                            <button onclick="mostrarModalPermisos()" class="btn btn-agregar">
                                Agregar Permiso
                            </button>
                        </div>
                        
                        <?php if (empty($permisos_usuario)): ?>
                            <p style="color: #666;">No hay permisos específicos asignados a este usuario.</p>
                        <?php else: ?>
                            <?php foreach ($permisos_usuario as $permiso): ?>
                                <div class="permiso-item <?= $permiso['tipo'] ?>">
                                    <div class="permiso-info">
                                        <strong><?= htmlspecialchars($permiso['modulo_nombre']) ?></strong>
                                        - <?= htmlspecialchars($permiso['accion_nombre']) ?>
                                        <span class="permiso-badge badge-<?= $permiso['tipo'] ?>">
                                            <?= $permiso['tipo'] === 'conceder' ? 'CONCEDIDO' : 'REVOCADO' ?>
                                        </span>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                            Otorgado por: <?= htmlspecialchars($permiso['otorgado_por'] ?? 'Sistema') ?>
                                            el <?= date('d/m/Y H:i', strtotime($permiso['created_at'])) ?>
                                        </div>
                                    </div>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                                        <input type="hidden" name="permiso_id" value="<?= $permiso['permiso_id'] ?>">
                                        <button type="submit" name="eliminar_permiso" class="btn-delete" 
                                                onclick="return confirm('¿Eliminar este permiso específico?')">
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div style="text-align: center; padding: 60px 20px; color: #666;">
                        <h3>Selecciona un usuario</h3>
                        <p>Elige un usuario del panel izquierdo para gestionar sus permisos específicos</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Agregar Permiso -->
    <?php if ($usuario_seleccionado): ?>
    <div id="modalPermisos" class="modal">
        <div class="modal-content modal-permisos-modern">
            
            <!-- Header -->
            <div class="modal-header">
            <div>
                <h2>Agregar Permiso</h2>
                <small>Usuario: <strong><?= htmlspecialchars($usuario_seleccionado['nombre']) ?></strong></small>
            </div>
            <span class="modal-close" onclick="cerrarModal()">&times;</span>
            </div>

            <form method="post">
            <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">

            <!-- Tipo -->
            <div class="tipo-toggle">
                <label>
                <input type="radio" name="tipo" value="conceder" checked>
                <span class="toggle conceder">Conceder</span>
                </label>

                <label>
                <input type="radio" name="tipo" value="revocar">
                <span class="toggle revocar">Revocar</span>
                </label>
            </div>

            <!-- Permisos -->
            <div class="permisos-grid">
                <!-- LISTA CONCEDER -->
                <div id="lista-conceder">
                <?php foreach ($permisos_para_conceder as $modulo_nombre => $modulo_data): ?>
                    <div class="permiso-modulo">
                        <h4><?= htmlspecialchars($modulo_nombre) ?></h4>
                        <div class="acciones-row">
                            <?php foreach ($modulo_data['permisos'] as $permiso): ?>
                                <label class="accion-chip">
                                    <input type="checkbox" name="permiso_ids[]" value="<?= $permiso['id'] ?>">
                                    <span><?= htmlspecialchars($permiso['accion_nombre']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <!-- LISTA REVOCAR -->
                <div id="lista-revocar" style="display:none">
                <?php foreach ($permisos_para_revocar as $modulo_nombre => $modulo_data): ?>
                    <div class="permiso-modulo">
                        <h4><?= htmlspecialchars($modulo_nombre) ?></h4>
                        <div class="acciones-row">
                            <?php foreach ($modulo_data['permisos'] as $permiso): ?>
                                <label class="accion-chip">
                                    <input type="checkbox" name="permiso_ids[]" value="<?= $permiso['id'] ?>">
                                    <span><?= htmlspecialchars($permiso['accion_nombre']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

            </div>

            <!-- Footer -->
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" name="asignar_permiso" class="btn">
                Guardar Permiso
                </button>
            </div>
            </form>
        </div>
        </div>

    <?php endif; ?>
    
    <script>
        function mostrarModalPermisos() {
            document.getElementById('modalPermisos').style.display = 'block';
        }
        
        function cerrarModal() {
            document.getElementById('modalPermisos').style.display = 'none';
        }
        
        // Búsqueda de usuarios
        document.getElementById('searchUsuarios').addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const usuarios = document.querySelectorAll('.usuario-item');
            
            usuarios.forEach(usuario => {
                const text = usuario.getAttribute('data-nombre').toLowerCase();
                usuario.style.display = text.includes(searchTerm) ? 'block' : 'none';
            });
        });
        
        // Búsqueda de permisos en el modal
        const searchPermisos = document.getElementById('searchPermisos');
        if (searchPermisos) {
            searchPermisos.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const permisos = document.querySelectorAll('.permiso-option');
                
                permisos.forEach(permiso => {
                    const moduloText = permiso.getAttribute('data-modulo');
                    const radios = permiso.querySelectorAll('.permiso-radio');
                    let hasMatch = false;
                    
                    radios.forEach(radio => {
                        const permisoText = radio.getAttribute('data-permiso');
                        const matches = moduloText.includes(searchTerm) || permisoText.includes(searchTerm);
                        radio.style.display = matches ? 'block' : 'none';
                        if (matches) hasMatch = true;
                    });
                    
                    permiso.style.display = hasMatch ? 'block' : 'none';
                });
            });
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('modalPermisos');
            if (event.target == modal) {
                cerrarModal();
            }
        }
    </script>
    <script>
        document.querySelectorAll('input[name="tipo"]').forEach(radio => {
            radio.addEventListener('change', function () {
                const conceder = document.getElementById('lista-conceder');
                const revocar  = document.getElementById('lista-revocar');

                if (this.value === 'revocar') {
                    conceder.style.display = 'none';
                    revocar.style.display  = 'block';
                } else {
                    conceder.style.display = 'block';
                    revocar.style.display  = 'none';
                }
            });
        });
    </script>

</body>
</html>
?>
