<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/funciones.php';

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] != ROL_GERENTE) {
    header("Location: dashboard.php");
    exit();
}

// Obtener todas las estadísticas
$usuariosTopVentas = obtenerUsuariosTopVentas($conn, 5);
$proyectosPorMes = obtenerProyectosPorMes($conn);
$estadisticasSucursales = obtenerEstadisticasSucursales($conn);
$distribucionEstados = obtenerDistribucionEstados($conn);
$estadisticasGenerales = obtenerEstadisticasGenerales($conn);
$proyectosRecientes = obtenerProyectosRecientes($conn, 10);

// Preparar datos para gráficos
$meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$proyectosMensuales = array_fill(0, 12, 0);
$montosMensuales = array_fill(0, 12, 0);

foreach ($proyectosPorMes as $dato) {
    $mesIndex = $dato['mes'] - 1;
    if ($mesIndex >= 0 && $mesIndex < 12) {
        $proyectosMensuales[$mesIndex] = (int)$dato['total_proyectos'];
        $montosMensuales[$mesIndex] = (float)$dato['monto_total'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas Gerenciales</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="estadisticas.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="dashboard-container">
        <header>
            <div class="header-left">
                <img src="assets/logo.png" class="logo" alt="Logo">
                <h1>Estadísticas Gerenciales</h1>
            </div>
            <div class="header-buttons">
                <a href="dashboard.php" class="back-btn">Volver al Dashboard</a>
            </div>
        </header>
        
        <div class="dashboard-content">
            <!-- Estadísticas generales -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Proyectos</h3>
                    <p class="monto"><?= $estadisticasGenerales['total_proyectos'] ?></p>
                </div>
                <div class="stat-card">
                    <h3>Proyectos Ganados</h3>
                    <p class="monto"><?= $estadisticasGenerales['proyectos_ganados'] ?></p>
                </div>
                <div class="stat-card">
                    <h3>Ventas Totales</h3>
                    <p class="monto"><?= number_format($estadisticasGenerales['monto_total'], 2, '.', ',') ?> Bs</p>
                </div>
                <div class="stat-card">
                    <h3>Tasa de Éxito</h3>
                    <p class="monto">
                        <?= $estadisticasGenerales['total_proyectos'] > 0 ? 
                            round(($estadisticasGenerales['proyectos_ganados'] / $estadisticasGenerales['total_proyectos']) * 100, 2) : 0 ?>%
                    </p>
                </div>
            </div>

            <div class="chart-container chart-large">
                <h3>Proyectos por Mes (<?= date('Y') ?>)</h3>
                <canvas id="proyectosMesChart" height="200"></canvas>
            </div>

            <!-- Top vendedores -->
            <div class="chart-container">
                <h3>Top 5 Vendedores</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Usuario</th>
                                <th>Sucursal</th>
                                <th>Proyectos Ganados</th>
                                <th>Total Ventas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuariosTopVentas as $index => $vendedor): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($vendedor['nombre']) ?></td>
                                <td><?= htmlspecialchars($vendedor['sucursal']) ?></td>
                                <td><?= $vendedor['total_proyectos'] ?></td>
                                <td><?= number_format($vendedor['total_ventas'], 2, '.', ',') ?> Bs</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Estadísticas por sucursal -->
            <div class="chart-container">
                <h3>Estadísticas por Sucursal</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Sucursal</th>
                                <th>Total Proyectos</th>
                                <th>Ganados</th>
                                <th>Abiertos</th>
                                <th>Perdidos</th>
                                <th>Cancelados</th>
                                <th>Monto Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estadisticasSucursales as $sucursal): ?>
                            <tr>
                                <td><?= htmlspecialchars($sucursal['sucursal']) ?></td>
                                <td><?= $sucursal['total_proyectos'] ?></td>
                                <td><?= $sucursal['ganados'] ?></td>
                                <td><?= $sucursal['abiertos'] ?></td>
                                <td><?= $sucursal['perdidos'] ?></td>
                                <td><?= $sucursal['cancelados'] ?></td>
                                <td><?= number_format($sucursal['monto_total'], 2, '.', ',') ?> Bs</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Proyectos recientes -->
            <div class="chart-container">
                <h3>Proyectos Recientes</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Proyecto</th>
                                <th>Cliente</th>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Sucursal</th>
                                <th>Estado</th>
                                <th>Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proyectosRecientes as $proyecto): ?>
                            <tr>
                                <td><?= htmlspecialchars($proyecto['titulo']) ?></td>
                                <td><?= htmlspecialchars($proyecto['cliente']) ?></td>
                                <td><?= date('d/m/Y', strtotime($proyecto['fecha_proyecto'])) ?></td>
                                <td><?= htmlspecialchars($proyecto['usuario']) ?></td>
                                <td><?= htmlspecialchars($proyecto['sucursal']) ?></td>
                                <td><?= htmlspecialchars($proyecto['estado']) ?></td>
                                <td><?= number_format($proyecto['monto_total'], 2, '.', ',') ?> Bs</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Gráfico de proyectos por mes
        const proyectosMesCtx = document.getElementById('proyectosMesChart').getContext('2d');
        new Chart(proyectosMesCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($meses) ?>,
                datasets: [
                    {
                        label: 'Número de Proyectos',
                        data: <?= json_encode($proyectosMensuales) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Monto Total (Bs)',
                        data: <?= json_encode($montosMensuales) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.5)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Número de Proyectos'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Monto (Bs)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // Gráfico de distribución de estados
        const estadosCtx = document.getElementById('estadosChart').getContext('2d');
        new Chart(estadosCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($distribucionEstados, 'estado')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($distribucionEstados, 'cantidad')) ?>,
                    backgroundColor: [
                        '#4CAF50', '#2196F3', '#FF9800', '#F44336', '#9C27B0',
                        '#FFEB3B', '#795548', '#607D8B', '#00BCD4', '#8BC34A'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>