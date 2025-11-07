<?php
include 'includes/config.php';
include 'includes/auth.php';

if (!esGerente()) {
    header("Location: dashboard.php?error=Acceso no autorizado");
    exit();
}

// Obtener los últimos valores
$stmt = $conn->query("SELECT * FROM datos_variables ORDER BY id DESC LIMIT 1");
$valores = $stmt->fetch(PDO::FETCH_ASSOC);

// Procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="iva">IVA (%):</label>
                        <input type="number" step="0.01" id="iva" name="iva" 
                            value="<?= $valores ? $valores['iva'] : 13.0 ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="it">IT (%):</label>
                        <input type="number" step="0.01" id="it" name="it" 
                            value="<?= $valores ? $valores['it'] : 1.0 ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="giro_exterior">Giro (%):</label>
                        <input type="number" step="0.01" id="giro_exterior" name="giro_exterior" 
                            value="<?= $valores ? $valores['giro_exterior'] : 1.0 ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="itf">ITF (%):</label>
                        <input type="number" step="0.01" id="itf" name="itf" 
                            value="<?= $valores ? $valores['itf'] : 1.0 ?>" required>
                    </div>
                </div>
                
                
                
            </div>
            
            <div class="form-section">
                <h2>Tipos de Cambio</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="tc_oficial">Oficial:</label>
                        <input type="number" step="0.01" id="tc_oficial" name="tc_oficial" 
                            value="<?= $valores ? $valores['tc_oficial'] : 1.00 ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tc_paralelo_hoy">Paralelo Hoy:</label>
                        <input type="number" step="0.01" id="tc_paralelo_hoy" name="tc_paralelo_hoy" 
                            value="<?= $valores ? $valores['tc_paralelo_hoy'] : 1.00 ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tc_estimado30">Estimado 30:</label>
                        <input type="number" step="0.01" id="tc_estimado30" name="tc_estimado30" 
                            value="<?= $valores ? $valores['tc_estimado30'] : 1.00 ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="tc_estimado60">Estimado 60:</label>
                        <input type="number" step="0.01" id="tc_estimado60" name="tc_estimado60" 
                            value="<?= $valores ? $valores['tc_estimado60'] : 1.00 ?>" required>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h2>Otros Parámetros</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="com_aduana">Comisión Aduana (%):</label>
                        <input type="number" step="0.01" id="com_aduana" name="com_aduana" 
                            value="<?= $valores ? $valores['com_aduana'] : 0.50 ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="pago_anticipado_DMC">Pago Anticipado DMC (%):</label>
                        <input type="number" step="0.01" id="pago_anticipado_DMC" name="pago_anticipado_DMC" 
                            value="<?= $valores ? $valores['pago_anticipado_DMC'] : 0.50 ?>" required>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn">Guardar Cambios</button>
        </form>
    </div>
</body>
</html>

