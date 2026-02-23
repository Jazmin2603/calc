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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

    <!-- KPIs -->
    <?php if(tienePermiso("presupuestos", "crear")): ?>
    <div class="kpi">
        <h3><i class="fa-solid fa-chart-line"></i> Presupuestos Abiertos</h3>
        <div class="num"><?= $cantidad_abiertos ?></div>
        <small><?= number_format($total_abierto,2,'.',',') ?> Bs</small>
      </div>

      <div class="kpi success" style="margin: 0px 0px 0px;">
        <h3><i class="fa-solid fa-sack-dollar"></i> Presupuestos Ganados</h3>
        <div class="num"><?= $cantidad_ganados ?></div>
        <small><?= number_format($total_ganado,2,'.',',') ?> Bs</small>
      </div>

      <div class="kpi">
        <h3><i class="fa-solid fa-percent"></i> Conversión</h3>
        <div class="num">
          <?= $cantidad_abiertos>0 ? round(($cantidad_ganados/$cantidad_abiertos)*100) : 0 ?>%
        </div>
      </div>
    <?php endif; ?>

    <?php if(esSuperusuario() || $puede_gestionar_usuarios): ?>
      <div class="card">
        <h2>Administración</h2>
        <p class="desc">Control de usuarios, permisos y jerarquías</p>
        <div class="actions">
          <?php if($puede_gestionar_usuarios): ?>
            <a href="gestion_vendedores.php" class="btn">Usuarios</a>
          <?php endif; ?>
          <?php if(esSuperusuario()): ?>
            <a href="gestion_usuarios.php" class="btn secondary">Roles</a>
            <a href="organigrama.php" class="btn ghost">Organigrama</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if($puede_ver_estadisticas || $puede_ver_finanzas): ?>
      <div class="card">
        <h2>Dirección</h2>
        <p class="desc">Métricas, finanzas y categorías</p>
        <div class="actions">
          <?php if($puede_ver_estadisticas): ?>
            <a href="estadisticas.php" class="btn">Estadísticas</a>
          <?php endif; ?>
          <?php if($puede_ver_finanzas): ?>
            <a href="finanzas.php" class="btn">Finanzas</a>
          <?php endif; ?>
          <?php if($puede_ver_categorias): ?>
            <a href="gestion_categorias.php" class="btn secondary">Categorías</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if($puede_ver_presupuestos): ?>
      <div class="card">
        <h2>Presupuestos</h2>
        <p class="desc">Gestión de presupuestos y datos</p>
        <div class="actions">
          <a href="proyectos.php" class="btn">Presupuestos</a>
          <?php if($puede_ver_datos): ?>
            <a href="datos_variables.php" class="btn secondary">Datos</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if(esSuperusuario()): ?>
      <div class="card">
        <h2>Comisiones</h2>
        <p class="desc">Control de comisiones y cuotas</p>
        <div class="actions">
          <a href="cuotas.php" class="btn">Cuotas</a>
          <a href="resumen_costos.php" class="btn secondary">Comisiones</a>
          <a href="configuracion_comisiones.php" class="btn ghost">Configuración</a>
        </div>
      </div>
    <?php endif; ?>

      <!-- SIN PERMISOS -->
      <?php if(!$puede_ver_finanzas && !$puede_ver_presupuestos && !$puede_ver_estadisticas && !$puede_gestionar_usuarios): ?>
      <div class="warn">
        <h3>Sin permisos asignados</h3>
        <p>Contacta al administrador.</p>
      </div>
      <?php endif; ?>

  </div>
</div>
</body>

</html>