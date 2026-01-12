<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/funciones.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

$usuario = $_SESSION['usuario'];
$es_gerente_general = ($usuario['rol'] == ROL_GERENTE && $usuario['sucursal_id'] == 1);
$es_gerente = ($usuario['rol'] == ROL_GERENTE);
$es_financiero = ($usuario['rol'] == ROL_FINANCIERO);
$es_vendedor = ($usuario['rol'] == ROL_VENDEDOR);
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
            <?php if($es_gerente || $es_financiero): ?>
                <div class="card">
                    <h2>Proyectos Financieros</h2>
                    <p>Administrar los proyectos financieros.</p>
                    <a href="finanzas.php" class="btn">Ver proyectos</a>
                    <?php if($es_gerente_general): ?>
                        <a href="gestion_categorias.php" class="btn">Editar Categorias</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
                
            <?php if($es_gerente_general): ?>
                <div class="card">
                    <h2>Gestión de Usuarios</h2>
                    <p>Agrega, edita o elimina usuarios del sistema.</p>
                    <a href="gestion_vendedores.php" class="btn">Administrar</a>
                </div>
            <?php endif; ?>

            <?php if($es_gerente): ?>
                <div class="card">
                    <h2>Estadísticas Gerenciales</h2>
                    <p>Dashboard completo de métricas y análisis.</p>
                    <a href="estadisticas.php" class="btn">Ver Estadísticas</a>
                </div>
            <?php endif; ?>

            <?php if($es_gerente || $es_vendedor): ?>
                <div class="card">
                    <h2>Presupuestos</h2>
                    <p>Administra presupuestos y sus items.</p>
                    <a href="proyectos.php" class="btn">Ver Presupuestos</a>
                    <?php if($es_gerente_general): ?>
                        <a href="datos_variables.php" class="btn">Datos Variables</a>
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

            
        </div>
    </div>
</body>
</html>