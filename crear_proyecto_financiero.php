<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php?error=Debes iniciar sesión");
    exit();
}

// Solo gerentes pueden crear proyectos financieros
if ($_SESSION['usuario']['rol'] != ROL_GERENTE) {
    header("Location: finanzas.php?error=No tienes permisos");
    exit();
}

$proyecto_id = isset($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : null;
$proyecto_original = null;

// Si viene con proyecto_id, obtener los datos del proyecto
if ($proyecto_id) {
    $stmt = $conn->prepare("SELECT p.*, s.nombre as sucursal_nombre 
                           FROM proyecto p 
                           LEFT JOIN sucursales s ON p.sucursal_id = s.id 
                           WHERE p.id_proyecto = ?");
    $stmt->execute([$proyecto_id]);
    $proyecto_original = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proyecto_original) {
        header("Location: finanzas.php?error=Proyecto no encontrado");
        exit();
    }
    
    // Verificar que no esté ya vinculado
    $stmt = $conn->prepare("SELECT id FROM proyecto_financiero WHERE presupuesto_id = ?");
    $stmt->execute([$proyecto_id]);
    if ($stmt->fetch()) {
        header("Location: finanzas.php?error=Este proyecto ya tiene un proyecto financiero asociado");
        exit();
    }
}

// Obtener sucursales
$sucursales = [];
if ($_SESSION['usuario']['sucursal_id'] == 1) {
    $stmt = $conn->query("SELECT * FROM sucursales WHERE id != 1 ORDER BY nombre");
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener estado por defecto (primer estado)
$stmt = $conn->query("SELECT id FROM estado_finanzas ORDER BY id LIMIT 1");
$estado_default = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si tiene proyecto vinculado, usar los datos del proyecto original
    if ($proyecto_id) {
        $titulo = $proyecto_original['titulo'];
        $cliente = $proyecto_original['cliente'];
    } else {
        $titulo = trim($_POST['titulo']);
        $cliente = trim($_POST['cliente']);
    }
    
    $fecha_inicio = $_POST['fecha_inicio'];
    $presupuesto_id = $proyecto_id ? $proyecto_id : null;

    // Determinar sucursal
    if ($_SESSION['usuario']['sucursal_id'] == 1) {
        $sucursal = intval($_POST['sucursal']);
    } else {
        $sucursal = $_SESSION['usuario']['sucursal_id'];
    }

    // Validaciones
    if (!$proyecto_id && (empty($titulo) || empty($cliente))) {
        $error = "Título y cliente son obligatorios";
    } else {
        try {
            $conn->beginTransaction();

            // Obtener y actualizar el contador
            $stmt = $conn->prepare("SELECT numero_actual FROM contadores WHERE documento = 'finanzas' AND anio = ? FOR UPDATE");
            $stmt->execute([date('Y')]);
            $numero_actual = $stmt->fetchColumn();

            if ($numero_actual === false) {
                // Si no existe el contador para este año, crearlo
                $stmt = $conn->prepare("INSERT INTO contadores (documento, anio, numero_actual) VALUES ('finanzas', ?, 1)");
                $stmt->execute([date('Y')]);
                $nuevo_numero = 1;
            } else {
                $nuevo_numero = $numero_actual + 1;
                $stmt = $conn->prepare("UPDATE contadores SET numero_actual = ? WHERE documento = 'finanzas' AND anio = ?");
                $stmt->execute([$nuevo_numero, date('Y')]);
            }

            // Insertar proyecto financiero
            $stmt = $conn->prepare("INSERT INTO proyecto_financiero
                                  (id_usuario, fecha_inicio, titulo, cliente, numero_proyectoF, sucursal_id, estado_id, presupuesto_id) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $_SESSION['usuario']['id'],
                $fecha_inicio,
                $titulo,
                $cliente,
                $nuevo_numero,
                $sucursal,
                $estado_default,
                $presupuesto_id
            ]);
            
            $id_proyecto_financiero = $conn->lastInsertId();
            $conn->commit();
            
            header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&success=Proyecto financiero creado correctamente");
            exit();
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error al crear proyecto financiero: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo Proyecto Financiero</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
    <style>
        .proyecto-info {
            background-color: #f0f8ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 2px solid #34a44c;
        }
        
        .proyecto-info h3 {
            margin-top: 0;
            color: #34a44c;
        }
        
        .proyecto-info p {
            margin: 8px 0;
        }
        
        .proyecto-info strong {
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Crear Nuevo Proyecto Financiero</h1>
            <div>
                <a href="finanzas.php" class="btn-back">Volver a Proyectos</a>
            </div>
        </header>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($proyecto_original): ?>
            <div class="proyecto-info">
                <h3>Proyecto Vinculado</h3>
                <p><strong>Número:</strong> #<?= $proyecto_original['numero_proyecto'] ?></p>
                <p><strong>Título:</strong> <?= htmlspecialchars($proyecto_original['titulo']) ?></p>
                <p><strong>Cliente:</strong> <?= htmlspecialchars($proyecto_original['cliente']) ?></p>
                <p><strong>Fecha Proyecto:</strong> <?= date('d/m/Y', strtotime($proyecto_original['fecha_proyecto'])) ?></p>
                <p><strong>Sucursal:</strong> <?= htmlspecialchars($proyecto_original['sucursal_nombre']) ?></p>
                <p style="margin-top: 10px; color: #666; font-size: 14px;">
                    ℹLos datos del proyecto se usarán automáticamente. Solo necesitas completar la fecha de inicio.
                </p>
            </div>
        <?php else: ?>
            <div class="proyecto-info">
                <h3>Proyecto Independiente</h3>
                <p style="color: #666;">
                    Este proyecto financiero no estará vinculado a ningún presupuesto existente. 
                    Deberás ingresar todos los datos manualmente.
                </p>
            </div>
        <?php endif; ?>
        
        <form method="post" class="form-section">
            <div class="formulario-proyecto">
               
                <?php if (!$proyecto_id): ?>
                    <label for="titulo">Título del Proyecto: *</label>
                    <input type="text" id="titulo" name="titulo" required 
                           placeholder="Ej: Instalación de equipos">
                
                    <label for="cliente">Cliente: *</label>
                    <input type="text" id="cliente" name="cliente" required 
                           placeholder="Ej: Empresa ABC S.A.">
                <?php endif; ?>
                
                <label for="fecha_inicio">Fecha de Inicio: *</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" required 
                       value="<?= date('Y-m-d') ?>">
                                   
                <?php if ($_SESSION['usuario']['sucursal_id'] == 1): ?>
                    <label for="sucursal">Sucursal: *</label>
                    <select name="sucursal" id="sucursal" required>
                        <option value="">Seleccione una sucursal</option>
                        <?php foreach ($sucursales as $sucursal): ?>
                            <option value="<?= $sucursal['id'] ?>" 
                                    <?= ($proyecto_original && $proyecto_original['sucursal_id'] == $sucursal['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sucursal['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <p style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                        <strong>Sucursal:</strong> <?= htmlspecialchars($_SESSION['usuario']['sucursal_nombre']) ?>
                    </p>
                <?php endif; ?>
                
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn">Crear Proyecto Financiero</button>
            </div>
        </form>

    </div>
</body>
</html>