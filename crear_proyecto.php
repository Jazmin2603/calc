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
            throw new Exception("No se encontró el contador para presupuestos del 2026.");
        }

        $nuevo_numero = $numero_proyecto + 1;

        $stmt = $conn->prepare("UPDATE contadores SET numero_actual = ? WHERE documento = ? AND anio = ?");
        $stmt->execute([$nuevo_numero, 'presupuestos', 2026]);

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
    <style>
        /* Contenedor tipo Tarjeta */
        .form-section {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-top: 20px;
            border-top: 4px solid #27ae60; /* Verde para creación */
        }

        .formulario-proyecto {
            display: grid;
            grid-template-columns: 1fr 1fr; /* Dos columnas iguales */
            gap: 20px;
            margin-bottom: 25px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
            font-size: 0.95rem;
        }

        .form-group input, 
        .form-group select {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            border-color: #27ae60;
            outline: none;
            box-shadow: 0 0 5px rgba(39, 174, 96, 0.2);
        }

        .btn-submit-container {
            padding-top: 1px;
        }

        .btn-crear {
            background-color: #27ae60;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn-crear:hover {
            background-color: #219150;
        }
    </style>
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
                
                <div class="form-group full-width">
                    <label for="titulo">Título del Proyecto:</label>
                    <input type="text" id="titulo" name="titulo" placeholder="Ej: Servidores Dell" required>
                </div>

                <div class="form-group full-width">
                    <label for="cliente">Cliente:</label>
                    <input type="text" id="cliente" name="cliente" placeholder="Nombre de la empresa o persona" required>
                </div>

                <div class="form-group">
                    <label for="fecha_proyecto">Fecha del Proyecto:</label>
                    <input type="date" id="fecha_proyecto" name="fecha_proyecto" required 
                        value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label for="fecha_cierre">Fecha de Cierre (Opcional):</label>
                    <input type="date" id="fecha_cierre" name="fecha_cierre">
                </div>

                <?php if($_SESSION['usuario']['sucursal_id'] == 1): ?>
                <div class="form-group full-width">
                    <label for="sucursal">Asignar a Sucursal:</label>
                    <select name="sucursal" id="venta-selector">
                        <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?= $sucursal['id'] ?>">
                            <?= htmlspecialchars($sucursal['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div class="btn-submit-container">
                <button type="submit" class="btn-crear">Crear Proyecto</button>
            </div>
        </form>

    </div>
</body>
</html>

