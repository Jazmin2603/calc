<?php 
session_start();
include 'includes/config.php'; 
include 'includes/auth.php';

// Si ya está autenticado y no es primer ingreso, redirigir al dashboard
if (isset($_SESSION['usuario']) && (!isset($_SESSION['usuario']['primer_ingreso']) || $_SESSION['usuario']['primer_ingreso'] == 0)) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    $resultado = autenticarUsuario($username, $password);
    
    if ($resultado === 'exito') {
        if (esGerente() || esSuperusuario()) {
            header('Location: dashboard.php');
        } else {
            header('Location: oportunidades.php');
        }
        exit();
    } elseif ($resultado === 'primer_ingreso') {
        header('Location: cambiar_contrasena.php?obligatorio=1');
        exit();
    } elseif ($resultado === 'inactivo') {
        $error = "   Usuario inactivo";
    } else {
        $error = "   Credenciales incorrectas";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="login-container">
        <h1>Iniciar Sesión</h1>
        <?php if(isset($error)): ?>
            <div class="error"><i class="fa-solid fa-triangle-exclamation"></i>   <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-login">
                <label for="username">Usuario:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-login">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">Ingresar</button>
        </form>
    </div>
</body>
</html>