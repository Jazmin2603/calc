<?php
include 'includes/config.php';
include 'includes/auth.php';

// Verificar autenticación y permisos
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['rol'] != ROL_GERENTE) {
    header("Location: index.php?error=Acceso no autorizado");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM usuarios WHERE id != ?");
$stmt->execute([$_SESSION['usuario']['id']]);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Procesar formulario para agregar nuevo vendedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar'])) {
    $username = trim($_POST['username']);
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $rol = intval($_POST['rol']);
    $sucursal = intval($_POST['sucursal']);

    if (empty($username) || empty($nombre) || empty($email) || empty($_POST['password'])) {
        $error = "Todos los campos son obligatorios";
    } else { 
        try { 
            $stmt = $conn->prepare("INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $nombre, $email, $rol, $sucursal]);
            header("Location: gestion_vendedores.php?success=Usuario agregado correctamente");
            exit();
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
            $error = "El nombre de usuario ya está en uso. Por favor, elige otro.";
            } else {
                $error = "Error al agregar usuario: " . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    try {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: gestion_vendedores.php?success=Vendedor eliminado correctamente");
        exit();
    } catch(PDOException $e) {
        $error = "Error al eliminar vendedor: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resetear'])) {
    $id = intval($_POST['usuario_id']);
    $nueva_password = trim($_POST['nueva_password']);

    if (empty($nueva_password)) {
        $error = "Debes ingresar una contraseña nueva.";
    } else {
        try {
            $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE usuarios SET password = ?, primer_ingreso = 1 WHERE id = ?");
            $stmt->execute([$password_hash, $id]);
            header("Location: gestion_vendedores.php?success=Contraseña reseteada correctamente");
            exit();
        } catch(PDOException $e) {
            $error = "Error al resetear contraseña: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Gestión de Usuarios</h1>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
        </header>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="form-section">
            <h2>Agregar Nuevo Usuario</h2>
            <form method="post">
                <div class="form-row">
                    <div class="form-grouplo">
                        <label for="username">Usuario:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-grouplo">
                        <label for="password">Contraseña:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-grouplo">
                        <label for="nombre">Nombre Completo:</label>
                        <input type="text" id="nombre" name="nombre" required>
                    </div>
                    <div class="form-grouplo">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                </div>
                <div class="form-row">
                    
                    <div class="form-grouplo">
                        <label for="rol">Rol:</label>
                        <select id="venta-selector" name="rol" required>
                            <option value="">Seleccione un rol</option>
                            <option value="1">Vendedor</option>
                            <option value="2">Gerente</option>
                        </select>
                    </div>
                    

                    <div class="form-grouplo">
                        <label for="sucursal">Sucursal:</label>
                        <select id="venta-selector" name="sucursal" required>
                            <option value="">Seleccione una sucursal</option>
                            <?php 
                            $stmt = $conn->query("SELECT * FROM sucursales");
                            while ($sucursal = $stmt->fetch(PDO::FETCH_ASSOC)): 
                            ?>
                                <option value="<?= $sucursal['id'] ?>">
                                    <?= htmlspecialchars($sucursal['nombre']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" name="agregar" class="btn">Agregar Usuario</button>
            </form>
        </div>
        
        <div class="vendedores-list">
            <h2>Usuarios Registrados</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($usuarios as $usuario): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($usuario['id']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['username']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                        <td>
                            <a href="gestion_vendedores.php?eliminar=<?php echo $usuario['id']; ?>" 
                               class="btn-delete" 
                               onclick="return confirm('¿Estás seguro de eliminar este usuario?')">
                                Eliminar
                            </a>

                            <a class="btn-reset" onclick="abrirModal(<?php echo $usuario['id']; ?>)">Resetear</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="resetModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="cerrarModal()">&times;</span>
            <h3>Resetear Contraseña</h3>
            <form method="post">
                <input type="hidden" id="usuario_id" name="usuario_id">
                <label for="nueva_password">Nueva Contraseña:</label>
                <input type="password" name="nueva_password" required>
                <button type="submit" name="resetear" class="btn">Confirmar</button>
            </form>
        </div>
    </div>

    <script>
    function cerrarModal() {
        document.getElementById('resetModal').style.display = 'none';
    }

    function abrirModal(id) {
        document.getElementById('usuario_id').value = id;
        document.getElementById('resetModal').style.display = 'block';
    }

    function resetearUsuario(id) {
        const nuevaPassword = prompt("Ingrese la nueva contraseña para el usuario:");
        if (nuevaPassword) {
            if (confirm("¿Está seguro de resetear la contraseña?")) {
            window.location.href = `gestion_vendedores.php?resetear=${id}&nuevaPassword=${encodeURIComponent(nuevaPassword)}`;
            }
        }
    }
    </script>
</body>
</html>
