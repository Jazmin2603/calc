<?php
include 'includes/config.php';
include 'includes/auth.php';

cerrarSesion();
header("Location: index.php?success=Has cerrado sesion correctamente");
exit();
?>