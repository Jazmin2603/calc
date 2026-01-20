<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("finanzas", "crear");

$proyecto_id = isset($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : null;
$proyecto_original = null;

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
    
    $stmt = $conn->prepare("SELECT id FROM proyecto_financiero WHERE presupuesto_id = ?");
    $stmt->execute([$proyecto_id]);
    if ($stmt->fetch()) {
        header("Location: finanzas.php?error=Este proyecto ya tiene un proyecto financiero asociado");
        exit();
    }
}

$sucursales = [];
if ($_SESSION['usuario']['sucursal_id'] == 1) {
    $stmt = $conn->query("SELECT * FROM sucursales WHERE id != 1 ORDER BY nombre");
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $conn->query("SELECT id FROM estado_finanzas ORDER BY id LIMIT 1");
$estado_default = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($proyecto_id) {
        $titulo = $proyecto_original['titulo'];
        $cliente = $proyecto_original['cliente'];
        $monto_adjudicado = $proyecto_original['monto_adjudicado'];
    } else {
        $titulo = trim($_POST['titulo']);
        $cliente = trim($_POST['cliente']);
    }
    
    $fecha_inicio = $_POST['fecha_inicio'];
    $presupuesto_id = $proyecto_id ? $proyecto_id : null;

    if ($_SESSION['usuario']['sucursal_id'] == 1) {
        $sucursal = intval($_POST['sucursal']);
    } else {
        $sucursal = $_SESSION['usuario']['sucursal_id'];
    }

    if (!$proyecto_id && (empty($titulo) || empty($cliente))) {
        $error = "Título y cliente son obligatorios";
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT numero_actual FROM contadores WHERE documento = 'finanzas' AND anio = ? FOR UPDATE");
            $stmt->execute([date('Y')]);
            $numero_actual = $stmt->fetchColumn();

            if ($numero_actual === false) {
                $stmt = $conn->prepare("INSERT INTO contadores (documento, anio, numero_actual) VALUES ('finanzas', ?, 1)");
                $stmt->execute([date('Y')]);
                $nuevo_numero = 1;
            } else {
                $nuevo_numero = $numero_actual + 1;
                $stmt = $conn->prepare("UPDATE contadores SET numero_actual = ? WHERE documento = 'finanzas' AND anio = ?");
                $stmt->execute([$nuevo_numero, date('Y')]);
            }

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

            $stmt = $conn->prepare("INSERT INTO datos_cabecera (id_proyecto, precio_final_venta) VALUES (?, ?)");
            
            $stmt->execute([$id_proyecto_financiero, $monto_adjudicado ?? 0 ]);
            
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
        /* Contenedor tipo Tarjeta */
        .form-section {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-top: 20px;
            border-top: 4px solid #27ae60; 
        }

        .formulario-proyecto {
            display: grid;
            grid-template-columns: 1fr 1fr; 
            gap: 20px;
            margin-bottom: 25px;
        }

        .row-3-columns {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            grid-column: 1 / -1; 
            margin: 10px 0;
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
            width: 100%;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #27ae60;
            outline: none;
            box-shadow: 0 0 5px rgba(39, 174, 96, 0.2);
        }

        .btn-submit-container {
            padding-top: 1px;
            text-align: center;
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

        /* Información del proyecto vinculado */
        .proyecto-info {
            background: linear-gradient(135deg, #e8f5e9 0%, #f1f8f4 100%);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 2px solid #34a44c;
            box-shadow: 0 4px 15px rgba(52, 164, 76, 0.1);
        }
        
        .proyecto-info h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #27ae60;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .proyecto-info h3::before {
            font-size: 1.5rem;
        }
        
        .proyecto-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .proyecto-info-item {
            background: white;
            padding: 12px 15px;
            border-radius: 8px;
            border-left: 3px solid #27ae60;
        }

        .proyecto-info-item strong {
            display: block;
            color: #555;
            font-size: 0.85rem;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .proyecto-info-item span {
            color: #2c3e50;
            font-size: 1rem;
            font-weight: 500;
        }
        
        .proyecto-info-note {
            margin-top: 15px;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            color: #555;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .proyecto-info-note::before {
            font-size: 1.2rem;
        }

        /* Error message */
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #c33;
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .formulario-proyecto {
                grid-template-columns: 1fr;
            }

            .row-3-columns {
                grid-template-columns: 1fr;
            }

            .proyecto-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Crear Nuevo Proyecto Financiero</h1>
            <a href="finanzas.php" class="btn-back">Volver a Proyectos</a>
        </header>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($proyecto_original): ?>
            <div class="proyecto-info">
                <h3>Proyecto Vinculado</h3>
                
                <div class="proyecto-info-grid">
                    <div class="proyecto-info-item">
                        <strong>Número</strong>
                        <span>#<?= $proyecto_original['numero_proyecto'] ?></span>
                    </div>
                    
                    <div class="proyecto-info-item">
                        <strong>Título</strong>
                        <span><?= htmlspecialchars($proyecto_original['titulo']) ?></span>
                    </div>
                    
                    <div class="proyecto-info-item">
                        <strong>Cliente</strong>
                        <span><?= htmlspecialchars($proyecto_original['cliente']) ?></span>
                    </div>
                    
                    <div class="proyecto-info-item">
                        <strong>Fecha Proyecto</strong>
                        <span><?= date('d/m/Y', strtotime($proyecto_original['fecha_proyecto'])) ?></span>
                    </div>
                    
                    <div class="proyecto-info-item">
                        <strong>Sucursal</strong>
                        <span><?= htmlspecialchars($proyecto_original['sucursal_nombre']) ?></span>
                    </div>
                </div>
                
                <div class="proyecto-info-note">
                    Los datos del proyecto se usarán automáticamente. Solo necesitas completar la fecha de inicio del proyecto financiero.
                </div>
            </div>
        <?php endif; ?>
        
        <form method="post" class="form-section">
            <div class="formulario-proyecto">
               
                <?php if (!$proyecto_id): ?>
                    <div class="form-group full-width">
                        <label for="titulo">Título del Proyecto: *</label>
                        <input type="text" id="titulo" name="titulo" required 
                               placeholder="Ej: Instalación de equipos">
                    </div>
                
                    <div class="form-group full-width">
                        <label for="cliente">Cliente: *</label>
                        <input type="text" id="cliente" name="cliente" required 
                               placeholder="Ej: Empresa ABC S.A.">
                    </div>
                <?php endif; ?>
                
                <div class="row-3-columns">
                    <div class="form-group">
                        <label for="fecha_inicio">Fecha de Inicio: *</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" required 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                                   
                    <?php if ($_SESSION['usuario']['sucursal_id'] == 1): ?>
                        <div class="form-group">
                            <label for="sucursal">Asignar a Sucursal: *</label>
                            <select name="sucursal" id="sucursal" required>
                                <option value="">Seleccione una sucursal</option>
                                <?php foreach ($sucursales as $sucursal): ?>
                                    <option value="<?= $sucursal['id'] ?>" 
                                            <?= ($proyecto_original && $proyecto_original['sucursal_id'] == $sucursal['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sucursal['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($_SESSION['usuario']['sucursal_id'] != 1): ?>
                    <div class="form-group full-width">
                        <label>Sucursal Asignada:</label>
                        <div style="padding: 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #ddd;">
                            <strong><?= htmlspecialchars($_SESSION['usuario']['sucursal_nombre']) ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
                
            </div>
            
            <div class="btn-submit-container">
                <button type="submit" class="btn-crear">Crear Proyecto Financiero</button>
            </div>
        </form>

    </div>
</body>
</html>