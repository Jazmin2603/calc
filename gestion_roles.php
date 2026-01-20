<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("roles","editar");
// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['crear_rol'])) {
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        
        try {
            $stmt = $conn->prepare("INSERT INTO roles (nombre, descripcion) VALUES (?, ?)");
            $stmt->execute([$nombre, $descripcion]);
            $success = "Rol creado correctamente";
        } catch (PDOException $e) {
            $error = "Error al crear rol: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['actualizar_permisos'])) {
        $rol_id = intval($_POST['rol_id']);
        $permisos = $_POST['permisos'] ?? [];
        
        try {
            $conn->beginTransaction();
            
            // Eliminar permisos actuales
            $stmt = $conn->prepare("DELETE FROM rol_permisos WHERE rol_id = ?");
            $stmt->execute([$rol_id]);
            
            // Insertar nuevos permisos
            foreach ($permisos as $permiso_id) {
                SistemaPermisos::asignarPermisoRol($conn, $rol_id, intval($permiso_id));
            }
            
            $conn->commit();
            $success = "Permisos actualizados correctamente";
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error al actualizar permisos: " . $e->getMessage();
        }
    }
}

// Obtener roles
$roles = SistemaPermisos::obtenerRoles($conn);

// Verificar que hay roles disponibles
if (empty($roles)) {
    $error = "No hay roles disponibles. Por favor, ejecuta el script de migración primero.";
    $rol_seleccionado = null;
} else {
    // Obtener rol seleccionado
    $rol_seleccionado = isset($_GET['rol']) ? intval($_GET['rol']) : null;
    
    // Si no hay rol seleccionado o no existe, usar el primero que NO sea superusuario
    if (!$rol_seleccionado) {
        foreach ($roles as $rol) {
            if (!$rol['es_superusuario']) {
                $rol_seleccionado = $rol['id'];
                break;
            }
        }
        // Si todos son superusuarios, usar el primero
        if (!$rol_seleccionado && !empty($roles)) {
            $rol_seleccionado = $roles[0]['id'];
        }
    }
    
    // Verificar que el rol seleccionado existe
    $rol_existe = false;
    foreach ($roles as $rol) {
        if ($rol['id'] == $rol_seleccionado) {
            $rol_existe = true;
            break;
        }
    }
    
    if (!$rol_existe) {
        $rol_seleccionado = $roles[0]['id'] ?? null;
    }
}

// Obtener permisos agrupados
$permisos_agrupados = SistemaPermisos::obtenerTodosPermisos($conn);

// Obtener permisos del rol seleccionado
$permisos_rol = $rol_seleccionado ? SistemaPermisos::obtenerPermisosRol($conn, $rol_seleccionado) : [];
$permisos_rol_ids = array_column($permisos_rol, 'id');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Roles y Permisos</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
    <style>
        .roles-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .roles-sidebar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .rol-item {
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .rol-item:hover {
            background: #f8f9fa;
        }
        
        .rol-item.active {
            background: #e7f5ff;
            border-color: #34a44c;
        }
        
        .rol-item.superusuario {
            background: #fff3cd;
        }
        
        .permisos-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .modulo-section {
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .modulo-header {
            background: #f8f9fa;
            padding: 15px;
            font-weight: bold;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modulo-permisos {
            display: flex;
            gap: 10px;
            padding: 15px 20px;
            flex-wrap: wrap;
        }


        @media (max-width: 1000px) {
            .modulo-permisos {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 600px) {
            .modulo-permisos {
                grid-template-columns: 1fr;
            }
        }

        .permiso-checkbox {
            background: #f1f3f5;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            user-select: none;
        }

        /* Hover */
        .permiso-checkbox:hover {
            background: #e7f5ff;
            border-color: var(--primary);
        }

        /* Activo */
        .permiso-checkbox:has(input:checked) {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        /* Ocultamos el checkbox nativo */
        .permiso-checkbox input {
            display: none;
        }

        /* Texto */
        .permiso-checkbox label {
            margin: 0;
            font-weight: 500;
            cursor: pointer;
        }

        
        .btn-crear-rol {
            width: 100%;
            margin-bottom: 20px;
        }
        
        .select-all {
            background: #e7f5ff;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            color: #0066cc;
        }
        
        .select-all:hover {
            background: #d0ebff;
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
            margin: 10% auto;
            padding: 30px;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
        }
        
        .form-group-rol{
            margin-bottom: 20px;
        }
        
        .form-group-rol label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        /* Variables de color para consistencia */
        :root {
            --primary: #3498db;
            --success: #2ecc71;
            --warning: #f1c40f;
            --danger: #e74c3c;
            --dark: #2c3e50;
            --gray-light: #f8f9fa;
        }

        /* Mejoras en el Sidebar de Roles */
        .roles-sidebar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }

        .rol-item {
            border: 1px solid #eee;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 10px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .rol-item.active {
            border-left: 5px solid var(--primary);
            background: #ebf5ff;
            transform: translateX(5px);
        }

        .rol-item.superusuario {
            background: #fff9db;
            border-left: 5px solid var(--warning);
        }

        /* Modal Estilizado */
        .modal-content {
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            border: none;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Gestión de Roles y Permisos</h1>
            <a href="gestion_usuarios.php" class="btn-back">Volver</a>
        </header>
        
        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="roles-container">
            <!-- Sidebar de Roles -->
            <div class="roles-sidebar">
                <?php if(tienePermiso("roles", "crear")): ?>
                    <button onclick="mostrarModalCrearRol()" class="btn btn-crear-rol">
                        Crear Nuevo Rol
                    </button>
                <?php endif;?>
                
                <h3 style="margin-top: 0;">Roles del Sistema</h3>
                
                <?php if (empty($roles)): ?>
                    <div style="padding: 20px; text-align: center; color: #666;">
                        <p>No hay roles disponibles</p>
                        <small>Ejecuta el script de migración primero</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($roles as $rol): ?>
                        <div class="rol-item <?= $rol['id'] == $rol_seleccionado ? 'active' : '' ?> <?= $rol['es_superusuario'] ? 'superusuario' : '' ?>"
                             onclick="window.location.href='?rol=<?= $rol['id'] ?>'">
                            <div style="font-weight: bold;"><?= htmlspecialchars($rol['nombre']) ?></div>
                            <?php if ($rol['descripcion']): ?>
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                    <?= htmlspecialchars($rol['descripcion']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($rol['es_superusuario']): ?>
                                <div style="font-size: 11px; color: #856404; margin-top: 4px;">
                                    Acceso Total
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="permisos-content">
                <?php if ($rol_seleccionado): ?>
                    <?php
                    $rol_actual = null;
                    foreach ($roles as $rol) {
                        if ($rol['id'] == $rol_seleccionado) {
                            $rol_actual = $rol;
                            break;
                        }
                    }
                    
                    if (!$rol_actual) {
                        header("Location: gestion_roles.php");
                        exit();
                    }
                    ?>
                    
                    <h2>Permisos de: <?= htmlspecialchars($rol_actual['nombre']) ?></h2>
                    
                    <?php if ($rol_actual['es_superusuario']): ?>
                        <div class="alert" style="background: #fff3cd; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                            Este rol tiene acceso total al sistema. No es necesario asignar permisos individuales.
                        </div>
                    <?php else: ?>
                        <?php if (empty($permisos_agrupados)): ?>
                            <div style="background: #fff3cd; padding: 20px; border-radius: 8px; text-align: center;">
                                <h3>No hay permisos configurados</h3>
                                <p>Los permisos del sistema aún no se han creado.</p>
                                <p>Por favor, ejecuta el script de migración para crear los módulos, acciones y permisos.</p>
                                <a href="migrar_roles.php?clave=migrar123" class="btn" style="margin-top: 10px;">
                                    Ejecutar Migración
                                </a>
                            </div>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="rol_id" value="<?= $rol_seleccionado ?>">
                                
                                <?php foreach ($permisos_agrupados as $modulo_nombre => $modulo_data): ?>
                                    <div class="modulo-section">
                                        <div class="modulo-header">
                                            <span><?= htmlspecialchars($modulo_nombre) ?></span>
                                            <span class="select-all" onclick="toggleModulo('<?= $modulo_data['slug'] ?>')">
                                                Seleccionar Todo
                                            </span>
                                        </div>
                                        <div class="modulo-permisos">
                                            <?php foreach ($modulo_data['permisos'] as $permiso): ?>
                                                <div class="permiso-checkbox">
                                                    <input type="checkbox" 
                                                           name="permisos[]" 
                                                           value="<?= $permiso['id'] ?>"
                                                           id="permiso_<?= $permiso['id'] ?>"
                                                           data-modulo="<?= $modulo_data['slug'] ?>"
                                                           <?= in_array($permiso['id'], $permisos_rol_ids) ? 'checked' : '' ?>>
                                                    <label for="permiso_<?= $permiso['id'] ?>">
                                                        <?= htmlspecialchars($permiso['accion_nombre']) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <button type="submit" name="actualizar_permisos" class="btn" style="margin-top: 20px;">
                                    Guardar Permisos
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Selecciona un rol para gestionar sus permisos</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Crear Rol -->
    <div id="modalCrearRol" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h2 style="margin: 0; color: var(--dark); font-size: 1.5rem;">Nuevo Rol</h2>
                <span onclick="cerrarModal()" style="cursor:pointer; font-size: 28px; color: #aaa;">&times;</span>
            </div>
            
            <form method="post">
                <div class="form-group-rol" style="margin-bottom: 20px;">
                    <label for="nombre" style="display:block; margin-bottom: 8px; color: #555;">Nombre del Rol</label>
                    <input type="text" id="nombre" name="nombre" placeholder="Ej: Administrador, Editor..." 
                        style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;" required>
                </div>
                
                <div class="form-group-rol" style="margin-bottom: 25px;">
                    <label for="descripcion" style="display:block; margin-bottom: 8px; color: #555;">Descripción (Opcional)</label>
                    <textarea id="descripcion" name="descripcion" rows="3" placeholder="¿Qué responsabilidades tiene este rol?"
                            style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; resize: none;"></textarea>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="cerrarModal()" class="btn-cancel">Cancelar</button>
                    <button type="submit" name="crear_rol" class="btn" style="background: var(--success); padding: 10px 25px;">
                        Crear Rol
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function mostrarModalCrearRol() {
            document.getElementById('modalCrearRol').style.display = 'block';
        }
        
        function cerrarModal() {
            document.getElementById('modalCrearRol').style.display = 'none';
        }
        
        function toggleModulo(moduloSlug) {
            const checkboxes = document.querySelectorAll(`input[data-modulo="${moduloSlug}"]`);
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
            });
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('modalCrearRol');
            if (event.target == modal) {
                cerrarModal();
            }
        }
    </script>
</body>
</html>