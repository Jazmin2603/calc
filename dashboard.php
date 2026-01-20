<?php
    include 'includes/config.php';
    include 'includes/auth.php';
    include 'includes/funciones.php';

    if (!isset($_SESSION['usuario'])) {
        header("Location: index.php");
        exit();
    }

    $usuario = $_SESSION['usuario'];

    // Verificar permisos específicos en lugar de roles
    $puede_gestionar_roles = esSuperusuario(); // Solo superusuarios
    $puede_ver_finanzas = tienePermiso('finanzas', 'ver');
    $puede_gestionar_usuarios = tienePermiso('usuarios', 'ver');
    $puede_ver_estadisticas = tienePermiso('estadisticas', 'ver');
    $puede_ver_presupuestos = tienePermiso('presupuestos', 'ver');
    $puede_ver_datos = tienePermiso('datos', 'ver');
    $puede_ver_categorias= tienePermiso('categorias', 'ver');
    
    $id_usuario = $_SESSION['usuario']['id'];

    // Llamada a la nueva función
    $stats = obtenerResumenAnualUsuario($conn, $id_usuario);
    $cantidad_abiertos = $stats['cantidad_abiertos'];
    $total_abierto = $stats['monto_abierto'];
    $cantidad_ganados = $stats['cantidad_ganados'];
    $total_ganado = $stats['monto_ganado'];


?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Presupuestos</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="dashboard-container">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Bienvenido, <?php echo htmlspecialchars($usuario['nombre']); ?></h1>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </header>
        
        <div class="dashboard-content">
            
            <!-- Gestión de Usuarios y Roles (Solo Superusuarios) -->
            <?php if(esSuperusuario()): ?>
                <div class="card">
                    <h2>Gestión de Usuarios y Permisos</h2>
                    <p>Control total del sistema de seguridad</p>
                    <a href="gestion_vendedores.php" class="btn">
                        Ver Usuarios
                    </a>
                    <a href="gestion_usuarios.php" class="btn" style="background-color: #6e7f97ff;">Gestionar Roles</a>
                </div>
            <?php elseif($puede_gestionar_usuarios): ?>
                <!-- Gerentes que no son superusuarios solo ven gestión básica -->
                <div class="card">
                    <h2>Gestión de Usuarios</h2>
                    <p>Administrar usuarios del sistema.</p>
                    <a href="gestion_vendedores.php" class="btn">Ver Usuarios</a>
                </div>
            <?php endif; ?>

            <!-- Estadísticas -->
            <?php if($puede_ver_estadisticas): ?>
                <div class="card">
                    <h2>Estadísticas Gerenciales</h2>
                    <p>Dashboard completo de métricas y análisis.</p>
                    <a href="estadisticas.php" class="btn">Ver Estadísticas</a>
                </div>
            <?php endif; ?>

            <!-- Proyectos Financieros -->
            <?php if($puede_ver_finanzas): ?>
                <div class="card">
                    <h2>Proyectos Financieros</h2>
                    <p>Administrar los proyectos financieros.</p>
                    <a href="finanzas.php" class="btn">Ver proyectos</a>
                    <?php if($puede_ver_categorias): ?>
                        <a href="gestion_categorias.php" class="btn" style="background-color: #6e7f97ff;">Gestionar Categorías</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Presupuestos -->
            <?php if($puede_ver_presupuestos): ?>
                <div class="card">
                    <h2>Presupuestos</h2>
                    <p>Administra presupuestos y sus items.</p>
                    <a href="proyectos.php" class="btn">Ver Presupuestos</a>
                    <?php if($puede_ver_datos): ?>
                        <a href="datos_variables.php" class="btn" style="background-color: #6e7f97ff;">Datos Variables</a>
                    <?php endif; ?>
                </div>

                <div class="card resumen-card">
                    <h2>Presupuestos Abiertos: <?= $cantidad_abiertos ?></h2>
                    <p class="monto"><?= number_format($total_abierto, 2, '.', ',') ?> Bs</p>
                </div>

                <div class="card resumen-card">
                    <h2>Presupuestos Ganados: <?= $cantidad_ganados ?></h2>
                    <p class="monto"><?= number_format($total_ganado, 2, '.', ',') ?> Bs</p>
                </div>
            <?php endif; ?>

            <!-- Mensaje si no tiene permisos -->
            <?php if(!$puede_ver_finanzas && !$puede_ver_presupuestos && !$puede_ver_estadisticas && !$puede_gestionar_usuarios): ?>
                <div class="card" style="background: #fff3cd; border-left: 4px solid #ffc107;">
                    <h2>Sin permisos asignados</h2>
                    <p>No tienes permisos para acceder a ningún módulo del sistema.</p>
                    <p>Por favor, contacta al administrador para que te asigne los permisos necesarios.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>