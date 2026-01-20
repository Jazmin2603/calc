<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("categorias", "ver");

// Obtener todas las categorías y subcategorías
$stmt = $conn->query("SELECT * FROM tipo_gasto ORDER BY nombre");
$tipos_gasto = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->query("SELECT sg.*, tg.nombre as tipo_gasto_nombre 
                     FROM sub_gasto sg 
                     JOIN tipo_gasto tg ON sg.id_tipo_gasto = tg.id 
                     ORDER BY tg.nombre, sg.nombre");
$sub_gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Procesar creación de tipo de gasto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_tipo'])) {
    $nombre = trim($_POST['nombre_tipo']);
    $descripcion = trim($_POST['descripcion_tipo']);
    
    if (!empty($nombre)) {
        try {
            $stmt = $conn->prepare("INSERT INTO tipo_gasto (nombre, descripcion) VALUES (?, ?)");
            $stmt->execute([$nombre, $descripcion]);
            header("Location: gestion_categorias.php?success=Categoría creada correctamente");
            exit();
        } catch (PDOException $e) {
            $error = "Error al crear categoría: " . $e->getMessage();
        }
    } else {
        $error = "El nombre de la categoría es obligatorio";
    }
}

// Procesar creación de sub gasto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_sub'])) {
    $nombre = trim($_POST['nombre_sub']);
    $descripcion = trim($_POST['descripcion_sub']);
    $id_tipo = intval($_POST['id_tipo_gasto']);
    
    if (!empty($nombre) && $id_tipo > 0) {
        try {
            $stmt = $conn->prepare("INSERT INTO sub_gasto (nombre, descripcion, id_tipo_gasto) VALUES (?, ?, ?)");
            $stmt->execute([$nombre, $descripcion, $id_tipo]);
            header("Location: gestion_categorias.php?success=Subcategoría creada correctamente");
            exit();
        } catch (PDOException $e) {
            $error = "Error al crear subcategoría: " . $e->getMessage();
        }
    } else {
        $error = "Nombre y categoría principal son obligatorios";
    }
}

// Eliminar tipo de gasto
if (isset($_GET['eliminar_tipo'])) {
    $id = intval($_GET['eliminar_tipo']);
    try {
        // Verificar si tiene subcategorías
        $stmt = $conn->prepare("SELECT COUNT(*) FROM sub_gasto WHERE id_tipo_gasto = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            header("Location: gestion_categorias.php?error=No se puede eliminar: tiene subcategorías asociadas");
        } else {
            $stmt = $conn->prepare("DELETE FROM tipo_gasto WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: gestion_categorias.php?success=Categoría eliminada correctamente");
        }
        exit();
    } catch (PDOException $e) {
        $error = "Error al eliminar categoría: " . $e->getMessage();
    }
}

// Eliminar sub gasto
if (isset($_GET['eliminar_sub'])) {
    $id = intval($_GET['eliminar_sub']);
    try {
        $stmt = $conn->prepare("DELETE FROM sub_gasto WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: gestion_categorias.php?success=Subcategoría eliminada correctamente");
        exit();
    } catch (PDOException $e) {
        $error = "Error al eliminar subcategoría: " . $e->getMessage();
    }
}

// Activar/Desactivar sub gasto
if (isset($_GET['toggle_sub'])) {
    $id = intval($_GET['toggle_sub']);
    try {
        $stmt = $conn->prepare("UPDATE sub_gasto SET activo = NOT activo WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: gestion_categorias.php?success=Estado actualizado correctamente");
        exit();
    } catch (PDOException $e) {
        $error = "Error al actualizar estado: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Categorías</title>
    <link rel="icon" type="image/jpg" href="assets/icono.jpg">
    <link rel="stylesheet" href="styles.css">
    <style>
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #34a44c;
        }
        
        .tab {
            padding: 10px 20px;
            background: #f0f0f0;
            border: none;
            cursor: pointer;
            border-radius: 4px 4px 0 0;
            transition: all 0.2s;
        }
        
        .tab.active {
            background: #34a44c;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .categoria-item {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            border-left: 4px solid #34a44c;
        }
        
        .subcategoria-list {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        .subcategoria-item {
            background: white;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-activo {
            background: #28a745;
            color: white;
        }
        
        .badge-inactivo {
            background: #dc3545;
            color: white;
        }
        
        .actions {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
        }

        .form-grouplo select, 
        .form-grouplo input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box; /* Asegura que el padding no afecte el ancho */
            background-color: white;
        }

        .form-grouplo select:focus {
            border-color: #34a44c;
            outline: none;
            box-shadow: 0 0 5px rgba(52, 164, 76, 0.2);
        }

        .categorias-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* 3 columnas iguales */
            gap: 20px;
            margin-top: 15px;
        }

        /* Contenedor para las 2 columnas de Subcategorías */
        .subcategorias-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr); /* 2 columnas iguales */
            gap: 20px;
            margin-top: 15px;
        }

        .categoria-item {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 0; /* Quitamos el margen inferior ya que usamos gap */
            border-radius: 4px;
            border-left: 4px solid #34a44c;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Responsividad: Si la pantalla es pequeña, volver a 1 columna */
        @media (max-width: 900px) {
            .categorias-grid, .subcategorias-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container-usuario">
        <header>
            <img src="assets/logo.png" class="logo">
            <h1>Gestión de Categorías de Gastos</h1>
            <a href="dashboard.php" class="btn-back">Volver al Dashboard</a>
        </header>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="success"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="error"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('categorias')">Categorías Principales</button>
            <button class="tab" onclick="showTab('subcategorias')">Gestionar Subcategorías</button>
        </div>
        
        <!-- Tab Categorías -->
        <div id="tab-categorias" class="tab-content active">
            <?php if(tienePermiso("categorias", "crear")):?>
                <div class="form-section">
                    <h2>Nueva Categoría</h2>
                    <form method="post">
                        <div class="form-row">
                            <div class="form-grouplo">
                                <label for="nombre_tipo">Nombre de la Categoría: *</label>
                                <input type="text" id="nombre_tipo" name="nombre_tipo" required 
                                    placeholder="Ej: Transporte, Servicios, etc.">
                            </div>
                            <div class="form-grouplo">
                                <label for="descripcion_tipo">Descripción (opcional):</label>
                                <input type="text" id="descripcion_tipo" name="descripcion_tipo"
                                    placeholder="Breve descripción de la categoría">
                            </div>
                        </div>
                        <button type="submit" name="crear_tipo" class="btn">Crear Categoría</button>
                        <br>
                        <br>
                    </form>
                </div>
            <?php endif;?>
            
            <div class="form-section">
                <h2>Categorías Existentes</h2>
                <?php if (empty($tipos_gasto)): ?>
                    <p>No hay categorías creadas todavía.</p>
                <?php else: ?>
                    <div class="categorias-grid"> 
                        <?php foreach ($tipos_gasto as $tipo): ?>
                            <?php
                            // Obtener subcategorías de esta categoría
                            $stmt = $conn->prepare("SELECT COUNT(*) FROM sub_gasto WHERE id_tipo_gasto = ?");
                            $stmt->execute([$tipo['id']]);
                            $count_subs = $stmt->fetchColumn();
                            ?>
                            <div class="categoria-item">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong style="font-size: 18px;"><?= htmlspecialchars($tipo['nombre']) ?></strong>
                                        <?php if ($tipo['descripcion']): ?>
                                            <p style="margin: 5px 0; color: #666;">
                                                <?= htmlspecialchars($tipo['descripcion']) ?>
                                            </p>
                                        <?php endif; ?>
                                        <small style="color: #888;">
                                            <?= $count_subs ?> subcategoría(s)
                                        </small>
                                    </div>
                                    <?php if(tienePermiso("categorias", "eliminar")):?>
                                        <div class="actions">
                                            <a href="?eliminar_tipo=<?= $tipo['id'] ?>" 
                                            class="btn-delete btn-small"
                                            onclick="return confirm('¿Eliminar esta categoría? Solo se puede eliminar si no tiene subcategorías.')">
                                                Eliminar
                                            </a>
                                        </div>
                                    <?php endif;?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?> 
            </div>
        </div>
        
        <!-- Tab Subcategorías -->
        <div id="tab-subcategorias" class="tab-content">
            <?php if(tienePermiso("categorias", "crear")) :?>
                <div class="form-section">
                    <h2>Nueva Subcategoría</h2>
                    <form method="post">
                        <div class="form-row">
                            <div class="form-grouplo">
                                <label for="id_tipo_gasto">Categoría Principal: *</label>
                                <select name="id_tipo_gasto" id="id_tipo_gasto" required>
                                    <option value="">Seleccione una categoría</option>
                                    <?php foreach ($tipos_gasto as $tipo): ?>
                                        <option value="<?= $tipo['id'] ?>">
                                            <?= htmlspecialchars($tipo['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-grouplo">
                                <label for="nombre_sub">Nombre de la Subcategoría: *</label>
                                <input type="text" id="nombre_sub" name="nombre_sub" required
                                    placeholder="Ej: Terrestre, Aéreo, etc.">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-grouplo">
                                <label for="descripcion_sub">Descripción (opcional):</label>
                                <input type="text" id="descripcion_sub" name="descripcion_sub"
                                    placeholder="Breve descripción">
                            </div>
                        </div>
                        <button type="submit" name="crear_sub" class="btn">Crear Subcategoría</button>
                        <br>
                        <br>
                    </form>
                </div>
            <?php endif;?>
            
            <div class="form-section">
                <h2>Subcategorías por Categoría</h2>
                <?php if (empty($tipos_gasto)): ?>
                    <p>Primero debe crear categorías principales.</p>
                <?php else: ?>
                    <?php foreach ($tipos_gasto as $tipo): ?>
                        <?php
                        // Obtener subcategorías de esta categoría
                        $stmt = $conn->prepare("SELECT * FROM sub_gasto WHERE id_tipo_gasto = ? ORDER BY nombre");
                        $stmt->execute([$tipo['id']]);
                        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <div class="categoria-item">
                            <strong style="font-size: 16px;"><?= htmlspecialchars($tipo['nombre']) ?></strong>
                            <div class="subcategoria-list">
                                <?php if (empty($subs)): ?>
                                    <p style="color: #888; font-style: italic;">Sin subcategorías</p>
                                <?php else: ?>
                                    <?php foreach ($subs as $sub): ?>
                                        <div class="subcategoria-item">
                                            <div>
                                                <strong><?= htmlspecialchars($sub['nombre']) ?></strong>
                                                <?php if ($sub['descripcion']): ?>
                                                    <br><small style="color: #666;">
                                                        <?= htmlspecialchars($sub['descripcion']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="actions">
                                                <?php if(tienePermiso("categorias", "editar")): ?>
                                                    <a href="?toggle_sub=<?= $sub['id'] ?>" 
                                                    class="btn btn-small">
                                                        <?= $sub['activo'] ? 'Desactivar' : 'Activar' ?>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if(tienePermiso("categorias", "eliminar")): ?>
                                                    <a href="?eliminar_sub=<?= $sub['id'] ?>" 
                                                    class="btn-delete btn-small"
                                                    onclick="return confirm('¿Eliminar esta subcategoría?')">
                                                        Eliminar
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Desactivar todos los tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar el contenido seleccionado
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Activar el tab seleccionado
            event.target.classList.add('active');
        }
    </script>
</body>
</html>