<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("roles", "ver");

$tab_activa = $_GET['tab'] ?? 'roles';
$usuario_id = $_GET['usuario'] ?? null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Roles y Permisos</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
    <style>
        .tabs-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 20px;
            overflow: hidden;
        }
        
        .tabs-header {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s;
            position: relative;
        }
        
        .tab-button:hover {
            background: #e9ecef;
            color: #495057;
        }
        
        .tab-button.active {
            color: #34a44c;
            background: white;
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: #34a44c;
        }
        
        .tab-content {
            display: none;
            padding: 30px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .quick-actions .btn {
            flex: 1;
            text-align: center;
            padding: 12px;
        }
    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Gestión de Roles y Permisos</h1>
            <a href="dashboard.php" class="btn secondary">Volver al Dashboard</a>
        </header>
        
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button <?= $tab_activa === 'roles' ? 'active' : '' ?>" 
                        onclick="cambiarTab('roles')">
                    Roles
                </button>
                <button class="tab-button <?= $tab_activa === 'permisos' ? 'active' : '' ?>" 
                        onclick="cambiarTab('permisos')">
                    Permisos Específicos
                </button>
            </div>
            
            <!-- Tab Roles -->
            <div id="tab-roles" class="tab-content <?= $tab_activa === 'roles' ? 'active' : '' ?>">
                <?php if(tienePermiso("roles", "editar")): ?>
                    <div class="quick-actions">
                        <a href="gestion_roles.php" class="btn">
                            Gestionar Roles y Permisos
                        </a>
                    </div>
                <?php endif;?>
                
                <h2>Gestión de Roles</h2>
                <?php if(tienePermiso("roles", "editar")): ?>
                    <p>Los roles definen conjuntos de permisos que se pueden asignar a múltiples usuarios. Configura qué puede hacer cada rol en el sistema.</p>
                <?php endif;?>
                
                <div style="margin-top: 20px;">
                    <?php
                    $stmt = $conn->query("SELECT r.*, COUNT(u.id) as usuarios_count 
                                         FROM roles r 
                                         LEFT JOIN usuarios u ON r.id = u.rol_id AND u.activo = 1
                                         WHERE r.activo = 1
                                         GROUP BY r.id
                                         ORDER BY r.nombre");
                    $roles = $stmt->fetchAll();
                    
                    foreach ($roles as $rol):
                    ?>
                        <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 8px; border: 1px solid #e9ecef; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h3 style="margin: 0 0 5px 0;">
                                        <?= htmlspecialchars($rol['nombre']) ?>
                                        <?php if ($rol['es_superusuario']): ?>
                                            <span style="background: #ffc107; color: #000; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 10px;">SUPER</span>
                                        <?php endif; ?>
                                    </h3>
                                    <p style="color: #6c757d; margin: 5px 0;"><?= htmlspecialchars($rol['descripcion']) ?></p>
                                    <p style="color: #6c757d; margin: 5px 0; font-size: 14px;">
                                        <?= $rol['usuarios_count'] ?> usuario(s) asignado(s)
                                    </p>
                                </div>
                                <?php if(tienePermiso("roles", "editar")): ?>
                                    <a href="gestion_roles.php?rol=<?= $rol['id'] ?>" class="btn">
                                        Editar Permisos
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Tab Permisos Específicos -->
            <div id="tab-permisos" class="tab-content <?= $tab_activa === 'permisos' ? 'active' : '' ?>">
                <div class="quick-actions">
                    <a href="permisos_usuario.php<?= $usuario_id ? '?usuario='.$usuario_id : '' ?>" class="btn">
                        Gestionar Permisos Específicos
                    </a>
                </div>
                
                <h2>Usuarios con Permisos Específicos</h2>

                <?php if(tienePermiso("roles", "editar")): ?>
                    <p>Otorga permisos adicionales a usuarios individuales sin cambiar su rol, o revoca permisos específicos que tienen por su rol.</p>
                <?php endif; ?>
                
                <?php
                $stmt = $conn->query("
                    SELECT u.id, u.nombre, u.username, r.nombre as rol_nombre, 
                           COUNT(up.id) as permisos_count,
                           SUM(CASE WHEN up.tipo = 'conceder' THEN 1 ELSE 0 END) as concedidos,
                           SUM(CASE WHEN up.tipo = 'revocar' THEN 1 ELSE 0 END) as revocados
                    FROM usuarios u
                    LEFT JOIN roles r ON u.rol_id = r.id
                    LEFT JOIN usuario_permisos up ON u.id = up.usuario_id
                    WHERE u.activo = 1
                    GROUP BY u.id
                    HAVING permisos_count > 0
                    ORDER BY permisos_count DESC
                ");
                $usuarios_con_permisos = $stmt->fetchAll();
                
                if (empty($usuarios_con_permisos)):
                ?>
                    <p style="color: #6c757d; text-align: center; padding: 40px;">
                        No hay usuarios con permisos específicos asignados todavía.
                    </p>
                <?php else: ?>
                    <div style="margin-top: 20px;">
                        <?php foreach ($usuarios_con_permisos as $usuario): ?>
                            <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 8px; border: 1px solid #e9ecef; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <h4 style="margin: 0 0 5px 0;"><?= htmlspecialchars($usuario['nombre']) ?></h4>
                                        <p style="color: #6c757d; margin: 5px 0; font-size: 14px;">
                                            <?= htmlspecialchars($usuario['username']) ?> • 
                                            Rol: <?= htmlspecialchars($usuario['rol_nombre']) ?>
                                        </p>
                                        <p style="margin: 5px 0; font-size: 14px;">
                                            <span style="color: #28a745;">✓ <?= $usuario['concedidos'] ?> concedido(s)</span> • 
                                            <span style="color: #dc3545;">✗ <?= $usuario['revocados'] ?> revocado(s)</span>
                                        </p>
                                    </div>
                                    <?php if(tienePermiso("roles", "editar")):?>
                                        <a href="permisos_usuario.php?usuario=<?= $usuario['id'] ?>" class="btn">
                                            Ver Detalles
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function cambiarTab(tab) {
            // Actualizar URL sin recargar
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);
            
            // Ocultar todos los tabs
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Mostrar tab seleccionado
            document.getElementById('tab-' + tab).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>