<?php
session_start();
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($_SESSION['usuario']) && !isset($_SESSION['usuario_temp'])) {
    header("Location: index.php");
    exit();
}

$usuario = $_SESSION['usuario'] ?? $_SESSION['usuario_temp'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password_actual   = trim($_POST['password_actual'] ?? '');
    $nueva_password    = trim($_POST['nueva_password'] ?? '');
    $confirmar_password= trim($_POST['confirmar_password'] ?? '');

    if (!isset($_GET['obligatorio']) && !password_verify($password_actual, $usuario['password'])) {
        $error = "La contraseña actual es incorrecta";
    } elseif ($nueva_password !== $confirmar_password) {
        $error = "Las nuevas contraseñas no coinciden";
    } elseif (strlen($nueva_password) < 8) {
        $error = "La contraseña debe tener al menos 8 caracteres";
    } else {
        $nueva_password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE usuarios SET password = ?, primer_ingreso = FALSE WHERE id = ?");
        $stmt->execute([$nueva_password_hash, $usuario['id']]);

        if (isset($_SESSION['usuario_temp'])) {
            unset($usuario['password']);
            $_SESSION['usuario'] = $usuario;
            unset($_SESSION['usuario_temp']);
        }
        $_SESSION['usuario']['primer_ingreso'] = false;

        $_SESSION['mensaje'] = "Contraseña cambiada exitosamente";
        header("Location: dashboard.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="login-container">
    <div class="password-change-box">
        <h1><?= isset($_GET['obligatorio']) ? 'Cambio de Contraseña Obligatorio' : 'Cambiar Contraseña' ?></h1>

        <?php if (isset($_GET['obligatorio'])): ?>
            <div class="error">
                Por seguridad, debe cambiar su contraseña.
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php if (!isset($_GET['obligatorio'])): ?>
                <div class="form-login">
                    <label for="password_actual">Contraseña Actual:</label>
                    <input type="password" id="password_actual" name="password_actual" required>
                </div>
            <?php endif; ?>

            <div class="form-login">
                <label for="nueva_password">Nueva Contraseña:</label>
                <input type="password" id="nueva_password" name="nueva_password" required minlength="8">
            </div>

            <div class="form-login">
                <label for="confirmar_password">Confirmar Nueva Contraseña:</label>
                <input type="password" id="confirmar_password" name="confirmar_password" required minlength="8">
            </div>

            <button type="submit" class="btn btn-primary">Cambiar Contraseña</button>
        </form>
    </div>
</div>
</body>
</html>
