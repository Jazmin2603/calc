<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("datos", "ver");

$puede_editar = tienePermiso("datos", "editar");
$readonly = !$puede_editar ? 'readonly' : '';
$disabled_class = !$puede_editar ? 'input-disabled' : '';

// Obtener los últimos valores
$stmt = $conn->query("SELECT * FROM datos_variables ORDER BY id DESC LIMIT 1");
$valores = $stmt->fetch(PDO::FETCH_ASSOC);

// Procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!tienePermiso("datos", "editar")) {
        header("Location: datos_variables.php?error=No tienes permiso para realizar esta acción");
        exit();
    }

    $iva = filter_input(INPUT_POST, 'iva', FILTER_VALIDATE_FLOAT);
    $it = filter_input(INPUT_POST, 'it', FILTER_VALIDATE_FLOAT);
    $giro_exterior = filter_input(INPUT_POST, 'giro_exterior', FILTER_VALIDATE_FLOAT);
    $tc_oficial = filter_input(INPUT_POST, 'tc_oficial', FILTER_VALIDATE_FLOAT);
    $tc_paralelo_hoy = filter_input(INPUT_POST, 'tc_paralelo_hoy', FILTER_VALIDATE_FLOAT);
    $tc_estimado30 = filter_input(INPUT_POST, 'tc_estimado30', FILTER_VALIDATE_FLOAT);
    $com_aduana = filter_input(INPUT_POST, 'com_aduana', FILTER_VALIDATE_FLOAT);
    $itf = filter_input(INPUT_POST, 'itf', FILTER_VALIDATE_FLOAT);
    $tc_estimado60 = filter_input(INPUT_POST, 'tc_estimado60', FILTER_VALIDATE_FLOAT);
    $pago_anticipado_DMC = filter_input(INPUT_POST, 'pago_anticipado_DMC', FILTER_VALIDATE_FLOAT);

    try {
        $stmt = $conn->prepare("INSERT INTO datos_variables 
                              (iva, it, giro_exterior, tc_oficial, tc_paralelo_hoy, 
                              tc_estimado30, com_aduana, itf, tc_estimado60, pago_anticipado_DMC) 
                              VALUES (?, ?, ?, ?, ?, ?, ?,?, ?, ?)");
        $stmt->execute([$iva, $it, $giro_exterior, $tc_oficial, $tc_paralelo_hoy, $tc_estimado30, $com_aduana, $itf, $tc_estimado60, $pago_anticipado_DMC]);
        
        header("Location: datos_variables.php?success=Valores actualizados correctamente");
        exit();
    } catch (PDOException $e) {
        $error = "Error al actualizar valores: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Datos Variables</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Configuración de Datos Variables</h1>
            <link rel="icon" type="image/jpg" href="assets/icono.jpg">
            <a href="dashboard.php" class="btn secondary">Volver al Dashboard</a>
            <style>
                /* Contenedor de las tarjetas */
                .datos-form {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 20px;
                    margin-top: 20px;
                }

                /* Estilo de cada sección (Card) */
                .form-section {
                    background: #ffffff;
                    padding: 20px;
                    border-radius: 12px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                    border-top: 4px solid #2d8f3d;;
                }

                .form-section h2 {
                    font-size: 1.2rem;
                    color: #2c3e50;
                    margin-bottom: 20px;
                    border-bottom: 1px solid #eee;
                    padding-bottom: 10px;
                    display: flex;
                    align-items: center;
                }

                .form-group {
                    margin-bottom: 15px;
                    display: flex;
                    flex-direction: column;
                }

                .form-group label {
                    font-weight: 600;
                    font-size: 0.9rem;
                    color: #555;
                    margin-bottom: 5px;
                }

                .form-group input {
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    font-size: 1rem;
                    transition: border-color 0.3s;
                }

                .form-group input:focus {
                    border-color: #3498db;
                    outline: none;
                }

                .btn-submit-container {
                    grid-column: 1 / -1;
                    text-align: center;
                    margin-top: 20px;
                }
            </style>
        </header>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="success"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="post" class="datos-form">
            <div class="form-section">
                <h2>Impuestos</h2>
                <div class="form-group">
                    <label for="iva">IVA (%):</label>
                    <input type="number" step="0.01" id="iva" name="iva" value="<?= $valores['iva'] ?? 13.0 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="it">IT (%):</label>
                    <input type="number" step="0.01" id="it" name="it" value="<?= $valores['it'] ?? 3.0 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="giro_exterior">Giro (%):</label>
                    <input type="number" step="0.01" id="giro_exterior" name="giro_exterior" value="<?= $valores['giro_exterior'] ?? 5.0 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="itf">ITF (%):</label>
                    <input type="number" step="0.01" id="itf" name="itf" value="<?= $valores['itf'] ?? 0.3 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
            </div>

            <div class="form-section">
                <h2>Tipos de Cambio</h2>
                <div class="form-group">
                    <label for="tc_oficial">Oficial:</label>
                    <input type="number" step="0.01" id="tc_oficial" name="tc_oficial" value="<?= $valores['tc_oficial'] ?? 6.96 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="tc_paralelo_hoy">Paralelo Hoy:</label>
                    <input type="number" step="0.01" id="tc_paralelo_hoy" name="tc_paralelo_hoy" value="<?= $valores['tc_paralelo_hoy'] ?? 14.0 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="tc_estimado30">Estimado 30 días:</label>
                    <input type="number" step="0.01" id="tc_estimado30" name="tc_estimado30" value="<?= $valores['tc_estimado30'] ?? 15.0 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="tc_estimado60">Estimado 60 días:</label>
                    <input type="number" step="0.01" id="tc_estimado60" name="tc_estimado60" value="<?= $valores['tc_estimado60'] ?? 16.0 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
            </div>

            <div class="form-section">
                <h2>Otros Parámetros</h2>
                <div class="form-group">
                    <label for="com_aduana">Comisión Aduana (%):</label>
                    <input type="number" step="0.01" id="com_aduana" name="com_aduana" value="<?= $valores['com_aduana'] ?? 1.10 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
                <div class="form-group">
                    <label for="pago_anticipado_DMC">Pago Anticipado DMC (%):</label>
                    <input type="number" step="0.01" id="pago_anticipado_DMC" name="pago_anticipado_DMC" value="<?= $valores['pago_anticipado_DMC'] ?? 66.32 ?>" <?= $readonly ?> class="<?= $disabled_class ?>" required>
                </div>
            </div>

            <?php if(tienePermiso("datos", "editar")): ?>
                <div class="btn-submit-container">
                    <button type="submit" class="btn">Guardar Todos los Cambios</button>
                </div>
            <?php endif;?>
        </form>
    </div>
</body>
</html>

