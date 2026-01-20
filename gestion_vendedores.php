<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso('usuarios', 'ver');

$query_usuarios = "
    SELECT u.*, r.nombre as rol_nombre, s.nombre as sucursal_nombre, r.es_superusuario
    FROM usuarios u
    LEFT JOIN roles r ON u.rol_id = r.id
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    WHERE u.id != ?
";

if (!esSuperusuario()) {
    $query_usuarios .= " AND (r.es_superusuario = 0 OR r.es_superusuario IS NULL)";
}

$query_usuarios .= " ORDER BY u.nombre";

$stmt = $conn->prepare($query_usuarios);
$stmt->execute([$_SESSION['usuario']['id']]);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (esSuperusuario()) {
    $stmt = $conn->query("SELECT * FROM roles WHERE activo = 1 ORDER BY nombre");
} else {
    $stmt = $conn->query("SELECT * FROM roles WHERE activo = 1 AND es_superusuario = 0 ORDER BY nombre");
}
$roles_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->query("SELECT * FROM sucursales ORDER BY nombre");
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar'])) {
    if (!tienePermiso('usuarios', 'crear')) {
        $error = "No tienes permisos para crear usuarios";
    } else {
        $username = trim($_POST['username']);
        $nombre = trim($_POST['nombre']);
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $rol_id = intval($_POST['rol_id']);
        $sucursal = intval($_POST['sucursal']);

        if (empty($username) || empty($nombre) || empty($email) || empty($_POST['password'])) {
            $error = "Todos los campos son obligatorios";
        } else {
            if (!esSuperusuario()) {
                $stmt = $conn->prepare("SELECT es_superusuario FROM roles WHERE id = ?");
                $stmt->execute([$rol_id]);
                $rol_info = $stmt->fetch();
                
                if ($rol_info && $rol_info['es_superusuario']) {
                    $error = "No tienes permisos para crear usuarios con rol de Superusuario";
                }
            }
            
            if (!isset($error)) {
                try { 
                    $stmt = $conn->prepare("INSERT INTO usuarios (username, password, nombre, email, rol_id, sucursal_id, primer_ingreso) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$username, $password, $nombre, $email, $rol_id, $sucursal]);
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
    }
}

if (isset($_GET['eliminar'])) {
    if (!tienePermiso('usuarios', 'eliminar')) {
        header("Location: gestion_vendedores.php?error=No tienes permisos para eliminar usuarios");
        exit();
    }
    
    $id = intval($_GET['eliminar']);
    
    if (!esSuperusuario()) {
        $stmt = $conn->prepare("
            SELECT r.es_superusuario 
            FROM usuarios u 
            JOIN roles r ON u.rol_id = r.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $usuario_info = $stmt->fetch();
        
        if ($usuario_info && $usuario_info['es_superusuario']) {
            header("Location: gestion_vendedores.php?error=No tienes permisos para eliminar usuarios superusuarios");
            exit();
        }
    }
    
    try {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: gestion_vendedores.php?success=Usuario eliminado correctamente");
        exit();
    } catch(PDOException $e) {
        $error = "Error al eliminar usuario: " . $e->getMessage();
    }
}

if (isset($_GET['toggle_status'])) {
    if (!tienePermiso('usuarios', 'editar')) {
        header("Location: gestion_vendedores.php?error=No tienes permisos para editar usuarios");
        exit();
    }
    
    $id = intval($_GET['toggle_status']);
    
    if (!esSuperusuario()) {
        $stmt = $conn->prepare("
            SELECT r.es_superusuario 
            FROM usuarios u 
            JOIN roles r ON u.rol_id = r.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $usuario_info = $stmt->fetch();
        
        if ($usuario_info && $usuario_info['es_superusuario']) {
            header("Location: gestion_vendedores.php?error=No tienes permisos para modificar usuarios superusuarios");
            exit();
        }
    }
    
    try {
        $stmt = $conn->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: gestion_vendedores.php?success=Estado de usuario actualizado");
        exit();
    } catch(PDOException $e) {
        $error = "Error al cambiar estado: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resetear'])) {
    if (!tienePermiso('usuarios', 'editar')) {
        $error = "No tienes permisos para resetear contraseñas";
    } else {
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
}

// Permisos del usuario actual
$puede_crear = tienePermiso('usuarios', 'crear');
$puede_editar = tienePermiso('usuarios', 'editar');
$puede_eliminar = tienePermiso('usuarios', 'eliminar');
$puede_ver_permisos = esSuperusuario();
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
    /* --- VARIABLES Y BASES --- */
    :root {
        --primary-color: #3498db;
        --secondary-color: #2c3e50;
        --danger-color: #e74c3c;
        --warning-color: #f39c12;
        --success-color: #27ae60;
        --info-color: #3498db;
        --bg-light: #f8f9fa;
        --shadow: 0 4px 15px rgba(0,0,0,0.08);
    }

    /* --- CONTENEDOR PRINCIPAL --- */
    .container-usuario {
        max-width: 1300px;
        margin: 20px auto;
        padding: 25px;
        background: #fff;
        border-radius: 12px;
        box-shadow: var(--shadow);
    }

    /* --- HEADER MEJORADO --- */
    header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }

    header h1 {
        color: #2c3e50;
        margin: 0;
        font-size: 1.8rem;
    }

    .header-buttons {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    /* --- FORM SECTION MODERNO --- */
    .form-section {
        background: linear-gradient(to right, #f8f9fa, #ffffff);
        padding: 25px;
        border-radius: 12px;
        margin-bottom: 30px;
        border: 1px solid #e9ecef;
        position: relative;
        overflow: hidden;
    }

    .form-section:before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 5px;
        height: 100%;
        background: linear-gradient(to bottom, #27ae60, #2ecc71);
    }

    .form-section h2 {
        margin-top: 0;
        color: #2c3e50;
        font-size: 1.4rem;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e9ecef;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        position: relative;
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #495057;
        font-size: 0.9rem;
    }

    .form-group input, .form-group select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #ced4da;
        border-radius: 8px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: white;
    }

    .form-group input:focus, .form-group select:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
    }

    .form-group input[type="password"] {
        font-family: monospace;
    }

    /* --- TABLA MODERNA (similar a proyectos) --- */
    .usuarios-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 12px;
        margin-top: 10px;
    }

    .usuarios-table thead th {
        background: transparent;
        color: #6c757d;
        text-transform: uppercase;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 15px;
        border: none;
        text-align: left;
        letter-spacing: 0.5px;
    }

    .usuarios-table tbody tr {
        background-color: white;
        transition: all 0.3s ease;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }

    .usuarios-table tbody tr:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .usuarios-table td {
        padding: 18px 15px;
        border-top: 1px solid #f1f3f4;
        border-bottom: 1px solid #f1f3f4;
        vertical-align: middle;
    }

    .usuarios-table td:first-child {
        border-left: 1px solid #f1f3f4;
        border-radius: 10px 0 0 10px;
    }

    .usuarios-table td:last-child {
        border-right: 1px solid #f1f3f4;
        border-radius: 0 10px 10px 0;
    }

    /* --- ESTILOS PARA ESTADO DE USUARIO --- */
    .estado-usuario {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        min-width: 90px;
        justify-content: center;
    }

    .estado-activo {
        background-color: #d1f7c4;
        color: #27ae60;
    }

    .estado-inactivo {
        background-color: #ffeaa7;
        color: #f39c12;
    }

    /* --- BADGES DE ROL MEJORADOS --- */
    .badge-rol {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-left: 8px;
    }

    .badge-superusuario {
        background: linear-gradient(135deg, #ffc107, #ff9800);
        color: #000;
        box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
    }

    .badge-gerente {
        background: linear-gradient(135deg, #17a2b8, #138496);
        color: #fff;
        box-shadow: 0 2px 4px rgba(23, 162, 184, 0.3);
    }

    .badge-normal {
        background: linear-gradient(135deg, #6c757d, #495057);
        color: #fff;
        box-shadow: 0 2px 4px rgba(108, 117, 125, 0.3);
    }

    .badge-admin {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: #fff;
        box-shadow: 0 2px 4px rgba(52, 152, 219, 0.3);
    }

    /* --- BOTONES DE ACCIÓN MEJORADOS --- */
    .acciones-cell {
        white-space: nowrap;
    }

    .btn-action {
        padding: 8px 10px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin: 2px;
        min-width: 90px;
    }

    .btn-action i {
        font-size: 0.9rem;
    }

    .btn-status-off {
        background: linear-gradient(135deg, #f39c12, #e67e22);
        color: white;
        border: 1px solid #f39c12;
    }

    .btn-status-on {
        background: linear-gradient(135deg, #2ecc71, #27ae60);
        color: white;
        border: 1px solid #2ecc71;
    }

    .btn-reset {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: white;
        border: 1px solid #3498db;
    }

    .btn-delete {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        border: 1px solid #e74c3c;
    }

    .btn-permisos {
        background: linear-gradient(135deg, #9b59b6, #8e44ad);
        color: white;
        border: 1px solid #9b59b6;
    }

    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        opacity: 0.95;
    }

    .btn-action:active {
        transform: translateY(0);
    }

    /* --- SUCURSAL TAG --- */
    .tag-sucursal {
        display: contents;
        padding: 6px 14px;
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        color: #1976d2;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid #bbdefb;
    }

    /* --- MODAL MEJORADO --- */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        background: white;
        padding: 30px;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        position: relative;
        animation: modalAppear 0.3s ease;
    }

    @keyframes modalAppear {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .close {
        position: absolute;
        right: 20px;
        top: 20px;
        font-size: 24px;
        cursor: pointer;
        color: #7f8c8d;
        transition: color 0.3s;
    }

    .close:hover {
        color: #e74c3c;
    }

    /* --- MENSAJES DE ALERTA --- */
    .success {
        background: linear-gradient(to right, #d4edda, #c3e6cb);
        color: #155724;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 4px solid #28a745;
    }

    .error {
        background: linear-gradient(to right, #f8d7da, #f5c6cb);
        color: #721c24;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 4px solid #dc3545;
    }

    /* --- RESPONSIVE --- */
    @media (max-width: 768px) {
        .container-usuario {
            padding: 15px;
            margin: 10px;
        }
        
        header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .usuarios-table {
            font-size: 14px;
        }
        
        .btn-action {
            min-width: 70px;
            padding: 6px 8px;
            font-size: 0.75rem;
        }
        
        .acciones-cell {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            justify-content: center;
        }
    }

    /* --- SIN DATOS --- */
    .no-data {
        text-align: center;
        padding: 60px 20px;
        color: #7f8c8d;
        font-size: 1.1rem;
    }

    .no-data i {
        font-size: 48px;
        margin-bottom: 20px;
        color: #dfe6e9;
    }
    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Gestión de Usuarios</h1>
            <div class="header-buttons">
                <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
            </div>
        </header>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($puede_crear): ?>
        <div class="form-section">
            <h2><i class="fas fa-user-plus"></i> Agregar Nuevo Usuario</h2>
            <form method="post">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username"><i class="fas fa-user"></i> Usuario:</label>
                        <input type="text" id="username" name="username" placeholder="Ej: nombre.apellido" required>
                    </div>
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Contraseña:</label>
                        <input type="password" id="password" name="password" required minlength="8" placeholder="Mínimo 8 caracteres">
                    </div>
                    <div class="form-group">
                        <label for="rol_id"><i class="fas fa-user-tag"></i> Rol:</label>
                        <select name="rol_id" id="rol_id" required>
                            <option value="">Seleccione un rol...</option>
                            <?php foreach ($roles_disponibles as $rol): ?>
                                <option value="<?= $rol['id'] ?>">
                                    <?= htmlspecialchars($rol['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre"><i class="fas fa-id-card"></i> Nombre Completo:</label>
                        <input type="text" id="nombre" name="nombre" placeholder="Nombre y Apellido" required>
                    </div>
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email:</label>
                        <input type="email" id="email" name="email" placeholder="correo@empresa.com" required>
                    </div>
                    <div class="form-group">
                        <label for="sucursal"><i class="fas fa-building"></i> Sucursal:</label>
                        <select name="sucursal" id="sucursal" required>
                            <option value="">Seleccione una sucursal...</option>
                            <?php foreach ($sucursales as $sucursal): ?>
                                <option value="<?= $sucursal['id'] ?>">
                                    <?= htmlspecialchars($sucursal['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="submit" name="agregar" class="btn" style="background: linear-gradient(135deg, #27ae60, #2ecc71); padding: 12px 30px; font-weight: 600; border: none;">
                        <i class="fas fa-user-plus"></i> Crear Usuario
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <div class="vendedores-list">
            <h2><i class="fas fa-users"></i> Usuarios Registrados</h2>
            
            <?php if (empty($usuarios)): ?>
                <div class="no-data">
                    <i class="fas fa-user-slash"></i>
                    <p>No hay otros usuarios registrados en el sistema.</p>
                </div>
            <?php else: ?>
            <table class="usuarios-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Sucursal</th>
                        <th>Estado</th>
                        <th style="text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($usuarios as $usuario): 
                        // Determinar clase de badge según rol
                        $badge_class = 'badge-normal';
                        if (isset($usuario['es_superusuario']) && $usuario['es_superusuario']) {
                            $badge_class = 'badge-superusuario';
                        } elseif (strpos(strtolower($usuario['rol_nombre'] ?? ''), 'gerente') !== false) {
                            $badge_class = 'badge-gerente';
                        } elseif (strpos(strtolower($usuario['rol_nombre'] ?? ''), 'admin') !== false) {
                            $badge_class = 'badge-admin';
                        }
                    ?>
                    <tr>
                        <td class="col-usuario">
                            <?php echo htmlspecialchars($usuario['username']); ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($usuario['nombre']); ?></strong>
                        </td>
                        <td class="col-email"><?php echo htmlspecialchars($usuario['email']); ?></td>
                        <td>
                            <div style="display: flex; align-items: center;">
                                <?php echo htmlspecialchars($usuario['rol_nombre'] ?? 'Sin rol'); ?>
                                <?php if (isset($usuario['es_superusuario']) && $usuario['es_superusuario']): ?>
                                    <span class="badge-rol <?php echo $badge_class; ?>">SUPER</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="tag-sucursal"><?php echo htmlspecialchars($usuario['sucursal_nombre'] ?? 'Sin sucursal'); ?></span>
                        </td>
                        <td>
                            <span class="estado-usuario <?php echo $usuario['activo'] ? 'estado-activo' : 'estado-inactivo'; ?>">
                                <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td class="acciones-cell">
                            <?php if ($puede_editar): ?>
                                <a href="gestion_vendedores.php?toggle_status=<?php echo $usuario['id']; ?>" 
                                   class="btn-action <?php echo $usuario['activo'] ? 'btn-status-off' : 'btn-status-on'; ?>"
                                   title="<?php echo $usuario['activo'] ? 
                                   'Desactivar usuario: El usuario no podrá iniciar sesión pero sus datos se conservan.' : 
                                   'Activar usuario: Permitir que el usuario pueda iniciar sesión nuevamente.'; ?>">
                                    <?php echo $usuario['activo'] ? '<i class="fas fa-ban"></i> Desactivar' : '<i class="fas fa-check"></i> Activar'; ?>
                                </a>

                                <button class="btn-action btn-reset" 
                                        onclick="abrirModal(<?php echo $usuario['id']; ?>)" 
                                        title="Resetear contraseña: Establece una nueva contraseña temporal. El usuario deberá cambiarla en su próximo inicio de sesión.">
                                    <i class="fas fa-key"></i> Resetear
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($puede_eliminar): ?>
                                <a href="gestion_vendedores.php?eliminar=<?php echo $usuario['id']; ?>" 
                                   class="btn-action btn-delete" 
                                   onclick="return confirm('¿Eliminar permanentemente a <?= htmlspecialchars($usuario['nombre']) ?>? Esta acción no se puede deshacer.')"
                                   title="Eliminar usuario: Elimina permanentemente al usuario y todos sus datos del sistema.">
                                    <i class="fas fa-trash"></i> Eliminar
                                </a>
                            <?php endif; ?>
                            
                            <?php if (!$puede_editar && !$puede_eliminar && !$puede_ver_permisos): ?>
                                <span style="color: #95a5a6; font-size: 0.85rem; padding: 8px;">
                                    <i class="fas fa-lock"></i> Sin permisos
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($puede_editar): ?>
    <div id="resetModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="cerrarModal()">&times;</span>
            <h3><i class="fas fa-key"></i> Resetear Contraseña</h3>
            <p style="color: #666; margin-bottom: 20px;">
                El usuario deberá cambiar su contraseña en el primer inicio de sesión después del reset.
            </p>
            <form method="post">
                <input type="hidden" id="usuario_id" name="usuario_id">
                <div class="form-group">
                    <label for="nueva_password"><i class="fas fa-lock"></i> Nueva Contraseña:</label>
                    <input type="password" name="nueva_password" id="nueva_password" required minlength="8" 
                           placeholder="Ingrese la nueva contraseña" style="width: 100%; padding: 12px;">
                    <small style="color: #666; display: block; margin-top: 8px;">
                        <i class="fas fa-info-circle"></i> Mínimo 8 caracteres. Recomendado usar letras, números y símbolos.
                    </small>
                </div>
                <div style="margin-top: 25px; display: flex; gap: 15px; justify-content: flex-end;">
                    <button type="button" onclick="cerrarModal()" class="btn-cancel" 
                            style="padding: 12px 25px; background: #95a5a6; color: white; border: none; border-radius: 8px; cursor: pointer;">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="submit" name="resetear" class="btn" 
                            style="padding: 12px 25px; background: linear-gradient(135deg, #3498db, #2980b9); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-check"></i> Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function cerrarModal() {
        document.getElementById('resetModal').style.display = 'none';
    }

    function abrirModal(id) {
        document.getElementById('usuario_id').value = id;
        document.getElementById('resetModal').style.display = 'flex';
        document.getElementById('nueva_password').focus();
    }
    
    // Cerrar modal al hacer clic fuera
    window.onclick = function(event) {
        const modal = document.getElementById('resetModal');
        if (event.target == modal) {
            cerrarModal();
        }
    }
    
    // Cerrar modal con ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            cerrarModal();
        }
    });
    </script>
</body>
</html>