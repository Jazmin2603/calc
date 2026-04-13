<?php
    include 'includes/config.php';
    include 'includes/auth.php';
    include 'includes/funciones.php';

    if (!isset($_SESSION['usuario'])) {
        header("Location: index.php");
        exit();
    }

    $usuario = $_SESSION['usuario'];

    $puede_gestionar_roles       = esSuperusuario();
    $puede_ver_finanzas          = tienePermiso('finanzas', 'ver');
    $puede_gestionar_usuarios    = tienePermiso('usuarios', 'ver');
    $puede_ver_estadisticas      = tienePermiso('estadisticas', 'ver');
    $puede_ver_presupuestos      = tienePermiso('presupuestos', 'ver');
    $puede_ver_datos             = tienePermiso('datos', 'ver');
    $puede_ver_categorias        = tienePermiso('categorias', 'ver');
    $puede_ver_clientes          = tienePermiso('clientes', 'ver');

    $id_usuario = $_SESSION['usuario']['id'];

    $stats = obtenerResumenAnualUsuario($conn, $id_usuario);
    $cantidad_abiertos = $stats['cantidad_abiertos'];
    $total_abierto     = $stats['monto_abierto'];
    $cantidad_ganados  = $stats['cantidad_ganados'];
    $total_ganado      = $stats['monto_ganado'];
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

    <!-- KPIs — visibles a quienes tienen acceso a presupuestos -->
    <?php if(tienePermiso("presupuestos", "crear") || tienePermiso("presupuestos", "ver")): ?>
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

    <!-- ADMINISTRACIÓN -->
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

    <!-- DIRECCIÓN -->
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

    <!-- PRESUPUESTOS -->
    <?php if($puede_ver_presupuestos): ?>
    <div class="card">
        <h2>Presupuestos</h2>
        <p class="desc">
            <?= (esSuperusuario() || esGerente()) ? 'Gestión de presupuestos y datos' : 'Ver tus presupuestos' ?>
        </p>
        <div class="actions">
            <a href="proyectos.php" class="btn">Presupuestos</a>
            <!-- Solo gerentes/superusuarios pueden crear directamente -->
            <?php if(esSuperusuario() || esGerente()): ?>
            <a href="crear_proyecto.php" class="btn secondary">Nuevo</a>
            <?php endif; ?>
            <?php if($puede_ver_datos): ?>
            <a href="datos_variables.php" class="btn secondary">Datos</a>
            <?php endif; ?>
        </div>
        <?php if(!esSuperusuario() && !esGerente()): ?>
        <p class="desc" style="margin-top:10px;font-size:.78rem;color:#64748b;">
            <i class="fas fa-info-circle"></i>
            Los presupuestos se crean desde el
            <a href="oportunidades.php" style="color:#16a34a;font-weight:600;">Pipeline de Oportunidades</a>.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- CLIENTES -->
    <?php if($puede_ver_clientes): ?>
    <div class="card">
        <h2>Clientes</h2>
        <p class="desc">Directorio de clientes y contactos</p>
        <div class="actions">
            <a href="gestion_clientes.php" class="btn">Ver Clientes</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- COMISIONES -->
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

    <!-- TARJETA PIPELINE (snippet CRM — visible para quienes tienen permiso oportunidades) -->
    <?php
    if (tienePermiso('oportunidades', 'ver')):
        $uid_dash        = $_SESSION['usuario']['id'];
        $es_manager_dash = esSuperusuario() || esGerente();
        $cond_dash = $es_manager_dash ? "" : "AND o.usuario_id = $uid_dash";

        $stmt = $conn->query("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(o.monto_estimado), 0) AS monto_total,
                   SUM(o.estado = 'Ganado')  AS ganadas,
                   SUM(o.estado = 'Perdido') AS perdidas,
                   SUM(o.estado = 'Activo')  AS activas
            FROM oportunidades o WHERE 1=1 $cond_dash
        ");
        $stats_op = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $conn->query("
            SELECT e.nombre, e.probabilidad, COUNT(o.id) AS cnt,
                   COALESCE(SUM(o.monto_estimado), 0) AS monto
            FROM oportunidad_etapas e
            LEFT JOIN oportunidades o ON o.etapa_id = e.id AND o.estado = 'Activo' $cond_dash
            WHERE e.activo = 1
            GROUP BY e.id ORDER BY e.orden
        ");
        $por_etapa_dash = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->query("
            SELECT a.tipo, a.proximo_paso, a.fecha_proximo_paso,
                   o.titulo AS op_titulo, o.id AS op_id, u.nombre AS usuario
            FROM oportunidad_actividades a
            JOIN oportunidades o ON a.oportunidad_id = o.id
            JOIN usuarios u ON a.usuario_id = u.id
            WHERE a.fecha_proximo_paso BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
              AND o.estado = 'Activo' $cond_dash
            ORDER BY a.fecha_proximo_paso ASC LIMIT 5
        ");
        $proximas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colores_etapa = ['#475569','#3b82f6','#7c3aed','#d97706','#dc2626','#059669','#0891b2'];
    ?>
    <!-- ─── TARJETA CRM / OPORTUNIDADES ─────────────────── -->
    <div class="card" style="grid-column: span 2;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <h3 style="font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:7px;">
                <i class="fas fa-chart-line" style="color:#16a34a;"></i>
                Pipeline de Oportunidades
            </h3>
            <a href="oportunidades.php"
               style="font-size:.78rem;color:#16a34a;text-decoration:none;font-weight:600;">
                Ver Kanban <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;">
            <?php
            $kpis = [
                ['Activas',   $stats_op['activas'],  '#2563eb', 'fa-spinner'],
                ['Ganadas',   $stats_op['ganadas'],  '#16a34a', 'fa-trophy'],
                ['Perdidas',  $stats_op['perdidas'], '#dc2626', 'fa-times-circle'],
                ['Monto total','Bs '.number_format($stats_op['monto_total'],0,',','.'), '#7c3aed', 'fa-coins'],
            ];
            foreach ($kpis as [$label,$valor,$color,$icon]): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 12px;text-align:center;">
                <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:1.1rem;margin-bottom:4px;display:block;"></i>
                <div style="font-family:'JetBrains Mono',monospace;font-size:.95rem;font-weight:700;color:#0f172a;">
                    <?= $valor ?>
                </div>
                <div style="font-size:.7rem;color:#64748b;margin-top:2px;"><?= $label ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Mini pipeline -->
        <div style="margin-bottom:14px;">
            <div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px;">
                Estado del pipeline (oportunidades activas)
            </div>
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <?php foreach ($por_etapa_dash as $idx => $ep): if ($ep['cnt'] == 0) continue; ?>
                <div style="background:<?= $colores_etapa[$idx%7] ?>18;border:1px solid <?= $colores_etapa[$idx%7] ?>44;border-radius:7px;padding:6px 10px;min-width:90px;flex:1;">
                    <div style="font-size:.68rem;font-weight:700;color:<?= $colores_etapa[$idx%7] ?>;"><?= htmlspecialchars($ep['nombre']) ?></div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:.82rem;font-weight:700;color:#0f172a;"><?= $ep['cnt'] ?></div>
                    <div style="font-size:.68rem;color:#64748b;">Bs <?= number_format($ep['monto'],0,',','.') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Próximas actividades -->
        <?php if (!empty($proximas)): ?>
        <div>
            <div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px;">
                <i class="fas fa-calendar-check" style="color:#d97706;margin-right:4px;"></i>
                Próximas actividades (7 días)
            </div>
            <?php foreach ($proximas as $prox): ?>
            <div style="display:flex;align-items:flex-start;gap:9px;padding:7px 0;border-bottom:1px solid #f1f5f9;">
                <div style="min-width:70px;font-family:'JetBrains Mono',monospace;font-size:.7rem;color:#64748b;padding-top:1px;">
                    <?= date('d/m H:i', strtotime($prox['fecha_proximo_paso'])) ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.8rem;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($prox['op_titulo']) ?>
                    </div>
                    <?php if ($prox['proximo_paso']): ?>
                    <div style="font-size:.74rem;color:#475569;margin-top:1px;">
                        <?= htmlspecialchars(mb_substr($prox['proximo_paso'], 0, 70)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <a href="oportunidades.php" onclick="event.preventDefault();"
                   style="font-size:.7rem;color:#2563eb;white-space:nowrap;text-decoration:none;">
                    Ver <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <!-- ─── FIN TARJETA CRM ──────────────────────────────── -->
    <?php endif; ?>

    <!-- SIN PERMISOS -->
    <?php if(!$puede_ver_finanzas && !$puede_ver_presupuestos && !$puede_ver_estadisticas && !$puede_gestionar_usuarios && !tienePermiso('oportunidades','ver')): ?>
    <div class="warn">
        <h3>Sin permisos asignados</h3>
        <p>Contacta al administrador.</p>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>