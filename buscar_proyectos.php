<?php
include 'includes/config.php';
include 'includes/auth.php';

$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$filtro_sucursal = isset($_GET['sucursal']) ? intval($_GET['sucursal']) : null;
$filtro_usuario = isset($_GET['usuario']) ? intval($_GET['usuario']) : null;
$filtro_estado = isset($_GET['estado']) ? intval($_GET['estado']) : null;

$stmt = $conn->query("SELECT * FROM estados");
$estados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conditions = [];
$params = [];

$query_base = "FROM proyecto p 
JOIN usuarios u ON p.id_usuario = u.id
JOIN sucursales s ON p.sucursal_id = s.id
JOIN estados e ON p.estado_id = e.id";

if ($_SESSION['usuario']['rol'] == ROL_GERENTE) {
    if ($_SESSION['usuario']['sucursal_id'] == 1) {
        if ($filtro_sucursal && $filtro_sucursal != 1) {
            $conditions[] = "p.sucursal_id = ?";
            $params[] = $filtro_sucursal;
        }
        if ($filtro_usuario) {
            $conditions[] = "p.id_usuario = ?";
            $params[] = $filtro_usuario;
        }
    } else {
        $conditions[] = "p.sucursal_id = ?";
        $params[] = $_SESSION['usuario']['sucursal_id'];

        if ($filtro_usuario) {
            $conditions[] = "p.id_usuario = ?";
            $params[] = $filtro_usuario;
        }
    }
} elseif ($_SESSION['usuario']['rol'] == ROL_VENDEDOR) {
    $conditions[] = "p.id_usuario = ?";
    $params[] = $_SESSION['usuario']['id'];
}


if ($filtro_estado) {
    $conditions[] = "p.estado_id = ?";
    $params[] = $filtro_estado;
}

if (!empty($busqueda)) {
    $conditions[] = "(p.titulo LIKE ? OR p.cliente LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$where_clause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

$query = "SELECT p.*, u.nombre as nombre_usuario, s.nombre as sucursal_nombre, e.estado as estado "
       . $query_base
       . $where_clause
       . " ORDER BY p.numero_proyecto DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($proyectos as $proyecto):
?>
    <tr>
        <td style="font-weight: bold; color: #34a44c;">#<?= $proyecto['numero_proyecto'] ?></td>
                <td>
                    <div style="font-weight: 600; color: #2c3e50;"><?= htmlspecialchars($proyecto['titulo']) ?></div>
                    <div style="font-size: 0.8rem; color: #7f8c8d;"><?= htmlspecialchars($proyecto['cliente']) ?></div>
                </td>
                <td><i class="far fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($proyecto['fecha_proyecto'])) ?></td>
                <td><?= htmlspecialchars($proyecto['nombre_usuario']) ?></td>
        <?php if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1): ?>
                <td><span class="tag-sucursal"><?= htmlspecialchars($proyecto['sucursal_nombre']) ?></span></td>
        <?php endif; ?>

        <td>
            <?php if (($proyecto['id_usuario'] == $_SESSION['usuario']['id']) || 
                      ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1)): ?>
                <form method="post" action="cambiar_estado.php">
                    <input type="hidden" name="id_proyecto" value="<?= $proyecto['id_proyecto'] ?>">
                    <select name="estado_id" onchange="this.form.submit()" class="estado-selector">
                        <?php foreach ($estados as $estado): ?>
                            <option value="<?= $estado['id'] ?>" <?= $estado['estado'] == $proyecto['estado'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($estado['estado']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span class="estado-selector"><?= htmlspecialchars($proyecto['estado']) ?></span>
            <?php endif; ?>
        </td>
        <td style="text-align: center;">
            <a href="ver_proyecto.php?id=<?= $proyecto['id_proyecto'] ?>" class="btn" style="padding: 5px 15px;">Abrir</a>
        </td>
    </tr>
<?php endforeach; ?>