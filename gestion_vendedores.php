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

// Lógica para Activar/Desactivar (Toggle)
if (isset($_GET['toggle_status'])) {
    $id = intval($_GET['toggle_status']);
    try {
        // Obtenemos el estado actual para invertirlo
        $stmt = $conn->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: gestion_vendedores.php?success=Estado de usuario actualizado");
        exit();
    } catch(PDOException $e) {
        $error = "Error al cambiar estado: " . $e->getMessage();
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
    <style>
        /* Estilo general del contenedor */
        .container-usuario {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Tarjeta para el formulario */
        .form-section {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 40px;
            border-top: 4px solid #27ae60;
        }

        .form-section h2 {
            margin-top: 0;
            color: #2c3e50;
            font-size: 1.4rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        /* Grid para los campos del formulario */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 15px;
        }

        .form-grouplo label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
        }

        .form-grouplo input, .form-grouplo select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box; /* Evita que el input se salga del div */
        }

        /* Contenedor de la lista como tarjeta */
        .vendedores-list {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-top: 20px;
        }

        .vendedores-list h2 {
            background: #f8f9fa;
            margin: 0;
            padding: 20px;
            font-size: 1.2rem;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
        }

        /* Estilo de la Tabla */
        .vendedores-list {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-top: 20px;
            padding: 20px; 
        }

        .vendedores-list h2 {
            margin: 0 0 20px 0; 
            font-size: 1.3rem;
            color: #2c3e50;
            border-bottom: none;
        }

        .btn-action {
            padding: 8px 14px; 
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            color: white; /* Texto siempre blanco para fondos sólidos */
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
            text-align: center;
        }

        /* Colores sólidos para los estados */
        .btn-status-off { 
            background-color: #f39c12; /* Naranja sólido */
        }

        .btn-status-on { 
            background-color: #2ecc71; /* Verde sólido */
        }

        /* Mantenemos el resto igual */
        .btn-reset { background-color: #3498db; }
        .btn-delete { background-color: #e74c3c; }

        .btn-action:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
    </style>
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
                        <input type="text" id="username" name="username" placeholder="Ej: nombre.apellido" required>
                    </div>
                    <div class="form-grouplo">
                        <label for="password">Contraseña:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-grouplo">
                        <label for="rol">Rol:</label>
                        <select name="rol" required>
                            <option value="">Seleccione...</option>
                            <option value="1">Vendedor</option>
                            <option value="2">Gerente</option>
                            <option value="3">Financiero</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-grouplo">
                        <label for="nombre">Nombre Completo:</label>
                        <input type="text" id="nombre" name="nombre" placeholder="Nombre y Apellido" required>
                    </div>
                    <div class="form-grouplo">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" placeholder="correo@empresa.com" required>
                    </div>
                    <div class="form-grouplo">
                        <label for="sucursal">Sucursal:</label>
                        <select name="sucursal" required>
                            <option value="">Seleccione...</option>
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

                <div style="text-align: right; margin-top: 10px;">
                    <button type="submit" name="agregar" class="btn" style="background:#27ae60; padding: 12px 25px; font-weight: 500; font-size:0.85rem;">
                        Crear Usuario
                    </button>
                </div>
            </form>
        </div>
        
        <div class="vendedores-list">
            <h2>Usuarios Registrados</h2>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($usuarios as $usuario): ?>
                    <tr>
                        <td class="col-usuario"><?php echo htmlspecialchars($usuario['username']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                        <td class="col-email"><?php echo htmlspecialchars($usuario['email']); ?></td>
                        
                        <td class="actions-cell">
                            <a href="gestion_vendedores.php?toggle_status=<?php echo $usuario['id']; ?>" 
                            class="btn-action <?php echo $usuario['activo'] ? 'btn-status-off' : 'btn-status-on'; ?>">
                                <?php echo $usuario['activo'] ? 'Desactivar' : 'Activar'; ?>
                            </a>

                            <button class="btn-action btn-reset" onclick="abrirModal(<?php echo $usuario['id']; ?>)">
                                Resetear
                            </button>

                            <a href="gestion_vendedores.php?eliminar=<?php echo $usuario['id']; ?>" 
                            class="btn-action btn-delete" 
                            onclick="return confirm('¿Eliminar permanentemente?')">
                                Eliminar
                            </a>
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
