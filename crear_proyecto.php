<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php?error=Debes iniciar sesión");
    exit();
}

$sucursales = [];
if ($_SESSION['usuario']['rol'] == ROL_GERENTE && $_SESSION['usuario']['sucursal_id'] == 1) {
    $stmt = $conn->query("SELECT * FROM sucursales WHERE id != 1");
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo']);
    $cliente = trim($_POST['cliente']);
    $fecha_proyecto = $_POST['fecha_proyecto'];
    $fecha_cierre = !empty($_POST['fecha_cierre']) ? $_POST['fecha_cierre'] : null;

    if ($_SESSION['usuario']['sucursal_id'] == 1) {
        $sucursal = $_POST['sucursal'];
    } else {
        $sucursal = $_SESSION['usuario']['sucursal_id'];
    }

    
    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT numero_actual FROM contadores WHERE documento = ? AND anio = ? FOR UPDATE");
        $stmt->execute(['presupuestos', 2026]);
        $numero_proyecto = $stmt->fetchColumn();

        if ($numero_proyecto === false) {
            throw new Exception("No se encontró el contador para presupuestos del 2025.");
        }

        $nuevo_numero = $numero_proyecto + 1;

        $stmt = $conn->prepare("UPDATE contadores SET numero_actual = ? WHERE documento = ? AND anio = ?");
        $stmt->execute([$nuevo_numero, 'presupuestos', 2025]);

        // Obtener los datos variables
        $stmt = $conn->query("SELECT * FROM datos_variables ORDER BY id DESC LIMIT 1");
        $datos_variables = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $conn->prepare("INSERT INTO proyecto
                              (id_usuario, fecha_proyecto, titulo, cliente, fecha_cierre,
                               iva, it, giro_exterior, tc_oficial, tc_paralelo_hoy, tc_estimado30, com_aduana,
                               itf, tc_estimado60, pago_anticipado_DMC, sucursal_id, numero_proyecto) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_SESSION['usuario']['id'],
            $fecha_proyecto,
            $titulo,
            $cliente,
            $fecha_cierre,
            $datos_variables['iva'],
            $datos_variables['it'],
            $datos_variables['giro_exterior'],
            $datos_variables['tc_oficial'],
            $datos_variables['tc_paralelo_hoy'],
            $datos_variables['tc_estimado30'],
            $datos_variables['com_aduana'],
            $datos_variables['itf'],
            $datos_variables['tc_estimado60'],
            $datos_variables['pago_anticipado_DMC'],
            $sucursal,
            $nuevo_numero
        
        ]);
        
        $id_proyecto = $conn->lastInsertId();
        $conn->commit();
        
        header("Location: ver_proyecto.php?id=$id_proyecto&success=Proyecto creado correctamente");
        exit();
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Error al crear proyecto: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo Proyecto</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Crear Nuevo Proyecto</h1>
            <link rel="icon" type="image/jpg" href="assets/icono.jpg">
            <a href="proyectos.php" class="btn-back">Volver a Proyectos</a>
        </header>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        
        <form method="post" class="form-section">
            <div class="formulario-proyecto">
               
                <label for="titulo">Título:</label>
                <input type="text" id="titulo" name="titulo" required>
            
                <label for="cliente">Cliente:</label>
                <input type="text" id="cliente" name="cliente" required>
                
                <label for="fecha_proyecto">Fecha del Proyecto:</label>
                <input type="date" id="fecha_proyecto" name="fecha_proyecto" required 
                        value="<?= date('Y-m-d') ?>">
                
                   
                <label for="fecha_cierre">Fecha de Cierre (opcional):</label>
                <input type="date" id="fecha_cierre" name="fecha_cierre">
                <?php if($_SESSION['usuario']['sucursal_id'] == 1):?>
                    <label for="sucursal">Sucursal:</label>
                    <select name="sucursal" id="venta-selector">
                        <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?= $sucursal['id'] ?>" >
                            <?= htmlspecialchars($sucursal['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                
            </div>
            
            
            <button type="submit" class="btn">Crear Proyecto</button>
        </form>

    </div>
</body>
</html>

