<?php
include 'includes/config.php';

// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function autenticarUsuario($username, $password) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE username = ?");
    $stmt->execute([$username]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuario && password_verify($password, $usuario['password'])) {

        if($usuario['activo'] != 1){
            return 'inactivo';
        }
        
        unset($usuario['password']);
        $_SESSION['usuario'] = $usuario;
        
        if ($usuario['primer_ingreso']) {
            return 'primer_ingreso';
        }
        
        return 'exito';
    }
    
    return 'error';
}

function verificarPrimerIngreso() {
    if (!isset($_SESSION['usuario'])) {
        header("Location: index.php");
        exit();
    }
    
    if ($_SESSION['usuario']['primer_ingreso'] && basename($_SERVER['PHP_SELF']) != 'cambiar_contrasena.php') {
        header("Location: cambiar_contrasena.php?obligatorio=1");
        exit();
    }
}

function esGerente() {
    return isset($_SESSION['usuario']) && $_SESSION['usuario']['rol'] == ROL_GERENTE;
}

function cerrarSesion() {
    session_unset();
    session_destroy();
}

?>