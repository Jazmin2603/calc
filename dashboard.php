<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/funciones.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

$usuario = $_SESSION['usuario'];
$es_gerente = ($usuario['rol'] == ROL_GERENTE);
$id_usuario = $_SESSION['usuario']['id'];

$stmt = $conn->prepare("SELECT SUM(i.total_hoy) as total_ganado
                        FROM items i
                        INNER JOIN proyecto p ON i.id_proyecto = p.id_proyecto
                        INNER JOIN estados e ON e.id = p.estado_id
                        INNER JOIN usuarios u ON u.id = p.id_usuario
                        WHERE e.estado = 'Ganado' AND u.id = ?");
$stmt->execute([$id_usuario]);
$total_ganado = $stmt->fetch(PDO::FETCH_ASSOC)['total_ganado'] ?? 0;

$stmt = $conn->prepare("SELECT SUM(i.total_hoy) as total_abierto
                        FROM items i
                        INNER JOIN proyecto p ON i.id_proyecto = p.id_proyecto
                        INNER JOIN estados e ON e.id = p.estado_id
                        INNER JOIN usuarios u ON u.id = p.id_usuario
                        WHERE e.estado = 'Abierto' AND u.id = ?");
$stmt->execute([$id_usuario]);
$total_abierto = $stmt->fetch(PDO::FETCH_ASSOC)['total_abierto'] ?? 0;



$stmt = $conn->prepare("SELECT COUNT(p.id_proyecto)
                        FROM proyecto p
                        INNER JOIN estados e ON e.id = p.estado_id
                        WHERE e.estado = 'Ganado' AND p.id_usuario = ?");
$stmt->execute([$id_usuario]);
$cantidad_ganados = $stmt->fetchColumn() ?: 0;

$stmt = $conn->prepare("SELECT COUNT(p.id_proyecto)
                        FROM proyecto p
                        INNER JOIN estados e ON e.id = p.estado_id
                        WHERE e.estado = 'Abierto' AND p.id_usuario = ?");
$stmt->execute([$id_usuario]);
$cantidad_abiertos = $stmt->fetchColumn() ?: 0;


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
            <?php if($es_gerente): ?>
                <!-- Tarjetas de gestión existentes -->
                <div class="card">
                    <h2>Gestión de Usuarios</h2>
                    <p>Agrega, edita o elimina usuarios del sistema.</p>
                    <a href="gestion_vendedores.php" class="btn">Administrar</a>
                </div>
                
                <div class="card">
                    <h2>Datos Variables</h2>
                    <p>Configura los parámetros generales del sistema.</p>
                    <a href="datos_variables.php" class="btn">Modificar</a>
                </div>

                <div class="card">
                    <h2>Proyectos Financieros</h2>
                    <p>Administrar los proyectos financieros.</p>
                    <a href="finanzas.php" class="btn">Ver proyectos</a>
                </div>

                <!-- Nueva tarjeta de Estadísticas -->
                <div class="card">
                    <h2>Estadísticas Gerenciales</h2>
                    <p>Dashboard completo de métricas y análisis.</p>
                    <a href="estadisticas.php" class="btn">Ver Estadísticas</a>
                </div>

                
            <?php endif; ?>
            
            <div class="card">
                <h2>Presupuestos</h2>
                <p>Administra presupuestos y sus items.</p>
                <a href="proyectos.php" class="btn">Ver Presupuestos</a>
            </div>

            <div class="card resumen-card">
                <h2>Presupuestos Abiertos: <?= $cantidad_abiertos ?></h2>
                <p class="monto"><?= number_format($total_abierto, 2, '.', ',') ?> Bs</p>
            </div>

            <div class="card resumen-card">
                <h2>Presupuestos Ganados: <?= $cantidad_ganados ?></h2>
                <p class="monto"><?= number_format($total_ganado, 2, '.', ',') ?> Bs</p>
            </div>
        </div>
    </div>
</body>
</html>