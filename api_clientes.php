<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso('clientes', 'ver');

header('Content-Type: application/json');

$action        = $_GET['action'] ?? '';
$puede_crear   = tienePermiso('clientes', 'crear');
$puede_editar  = tienePermiso('clientes', 'editar');
$puede_eliminar= tienePermiso('clientes', 'eliminar');

// ═══════════════════════════════════════════════════════════
// GET action=get  — carga un cliente con sus contactos
// ═══════════════════════════════════════════════════════════
if ($action === 'get') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) { echo json_encode(['success' => false, 'message' => 'Cliente no encontrado']); exit; }

    $stmt = $conn->prepare(
        "SELECT * FROM contactos WHERE cliente_id = ? AND activo = 1 ORDER BY nombre ASC"
    );
    $stmt->execute([$id]);
    $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'cliente' => $cliente, 'contactos' => $contactos]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// GET action=search  — búsqueda rápida de clientes (para el modal de oportunidades)
// Devuelve id, nombre, ciudad, sector
// ═══════════════════════════════════════════════════════════
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['success' => true, 'clientes' => []]); exit; }

    $stmt = $conn->prepare("
        SELECT id, nombre, ciudad, sector
        FROM clientes
        WHERE nombre LIKE ? OR `código` LIKE ? OR sector LIKE ?
        ORDER BY nombre
        LIMIT 30
    ");
    $like = "%$q%";
    $stmt->execute([$like, $like, $like]);
    echo json_encode(['success' => true, 'clientes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST action=save  — crear o editar cliente
// ═══════════════════════════════════════════════════════════
if ($action === 'save') {
    if (!$puede_crear && !$puede_editar) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true);
    $id       = !empty($data['id'])       ? intval($data['id'])         : null;
    $codigo   = trim($data['codigo']      ?? '');
    $tipo     = trim($data['tipo']        ?? '');
    $nombre   = trim($data['nombre']      ?? '');
    $nit      = intval($data['nit']       ?? 0);
    $correo   = trim($data['correo']      ?? '') ?: null;
    $ciudad   = trim($data['ciudad']      ?? '') ?: null;
    $direccion= trim($data['direccion']   ?? '') ?: null;
    $sector   = trim($data['sector']      ?? '');

    if (!$codigo || !$nombre || !$sector || !$tipo) {
        echo json_encode(['success' => false, 'message' => 'Código, nombre, tipo y sector son obligatorios']); exit;
    }

    try {
        if ($id) {
            if (!$puede_editar) { echo json_encode(['success' => false, 'message' => 'Sin permiso de edición']); exit; }
            $conn->prepare("
                UPDATE clientes
                SET `código`=?, tipo=?, nombre=?, nit=?, correo=?, ciudad=?, `dirección`=?, sector=?
                WHERE id=?
            ")->execute([$codigo, $tipo, $nombre, $nit, $correo, $ciudad, $direccion, $sector, $id]);
        } else {
            if (!$puede_crear) { echo json_encode(['success' => false, 'message' => 'Sin permiso de creación']); exit; }
            $conn->prepare("
                INSERT INTO clientes (`código`, tipo, nombre, nit, correo, ciudad, `dirección`, sector)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$codigo, $tipo, $nombre, $nit, $correo, $ciudad, $direccion, $sector]);
            $id = $conn->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST action=delete  — eliminar cliente
// ═══════════════════════════════════════════════════════════
if ($action === 'delete') {
    if (!$puede_eliminar) { echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    // Verificar que no tenga oportunidades asociadas
    $stmt = $conn->prepare("SELECT COUNT(*) FROM oportunidades WHERE cliente_id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'No se puede eliminar: el cliente tiene oportunidades asociadas']); exit;
    }

    try {
        $conn->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// GET action=get_contacto  — obtener un contacto por id
// ═══════════════════════════════════════════════════════════
if ($action === 'get_contacto') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM contactos WHERE id = ?");
    $stmt->execute([$id]);
    $contacto = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contacto) { echo json_encode(['success' => false, 'message' => 'Contacto no encontrado']); exit; }
    echo json_encode(['success' => true, 'contacto' => $contacto]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST action=save_contacto  — crear o editar contacto
// ═══════════════════════════════════════════════════════════
if ($action === 'save_contacto') {
    if (!$puede_crear && !$puede_editar) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit;
    }

    $data       = json_decode(file_get_contents('php://input'), true);
    $id         = !empty($data['id'])         ? intval($data['id'])         : null;
    $cliente_id = intval($data['cliente_id']  ?? 0);
    $nombre     = trim($data['nombre']        ?? '');
    $cargo      = trim($data['cargo']         ?? '') ?: null;
    $telefono   = trim($data['telefono']      ?? '') ?: null;
    $correo     = trim($data['correo']        ?? '') ?: null;
    $notas      = trim($data['notas']         ?? '') ?: null;

    if (!$nombre || !$cliente_id) {
        echo json_encode(['success' => false, 'message' => 'Nombre y cliente son obligatorios']); exit;
    }

    try {
        if ($id) {
            $conn->prepare("
                UPDATE contactos SET nombre=?, cargo=?, telefono=?, correo=?, notas=?
                WHERE id=?
            ")->execute([$nombre, $cargo, $telefono, $correo, $notas, $id]);
        } else {
            $conn->prepare("
                INSERT INTO contactos (cliente_id, nombre, cargo, telefono, correo, notas)
                VALUES (?,?,?,?,?,?)
            ")->execute([$cliente_id, $nombre, $cargo, $telefono, $correo, $notas]);
        }

        // Retornar lista actualizada
        $stmt = $conn->prepare(
            "SELECT * FROM contactos WHERE cliente_id = ? AND activo = 1 ORDER BY nombre ASC"
        );
        $stmt->execute([$cliente_id]);
        $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'contactos' => $contactos]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST action=delete_contacto  — eliminar (desactivar) contacto
// ═══════════════════════════════════════════════════════════
if ($action === 'delete_contacto') {
    if (!$puede_eliminar) { echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    // Obtener el cliente_id antes de borrar
    $stmt = $conn->prepare("SELECT cliente_id FROM contactos WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Contacto no encontrado']); exit; }
    $cliente_id = $row['cliente_id'];

    $conn->prepare("UPDATE contactos SET activo = 0 WHERE id = ?")->execute([$id]);

    $stmt = $conn->prepare(
        "SELECT * FROM contactos WHERE cliente_id = ? AND activo = 1 ORDER BY nombre ASC"
    );
    $stmt->execute([$cliente_id]);
    $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'contactos' => $contactos]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acción no válida']);