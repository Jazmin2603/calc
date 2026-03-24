<?php
include 'includes/config.php';
include 'includes/auth.php';

verificarPermiso("oportunidades", "ver");

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

$uid             = $_SESSION['usuario']['id'];
$puede_ver_todas = esSuperusuario() || esGerente();
$puede_crear     = tienePermiso('oportunidades', 'crear');
$puede_editar    = tienePermiso('oportunidades', 'editar');
$puede_eliminar  = tienePermiso('oportunidades', 'eliminar');

// ═══════════════════════════════════════════════════════════
// GET  action=get  — carga completa de una oportunidad
// ═══════════════════════════════════════════════════════════
if ($action === 'get') {
    $id = intval($_GET['id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT  o.*,
                c.nombre   AS cliente_nombre,
                c.sector   AS cliente_sector,
                c.ciudad   AS cliente_ciudad,
                c.nit      AS cliente_nit,
                c.correo   AS cliente_correo,
                u.nombre   AS nombre_usuario,
                s.nombre   AS sucursal_nombre,
                e.nombre   AS etapa_nombre,
                e.probabilidad AS etapa_probabilidad
        FROM oportunidades o
        JOIN clientes           c ON o.cliente_id  = c.id
        JOIN usuarios           u ON o.usuario_id  = u.id
        JOIN sucursales         s ON o.sucursal_id = s.id
        JOIN oportunidad_etapas e ON o.etapa_id    = e.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$op) {
        echo json_encode(['success' => false, 'message' => 'No encontrado']);
        exit;
    }

    // Restricción de acceso para usuarios normales
    if (!$puede_ver_todas && $op['usuario_id'] != $uid) {
        echo json_encode(['success' => false, 'message' => 'Sin acceso']);
        exit;
    }

    // Actividades ordenadas de más reciente a más antiguo
    $stmt = $conn->prepare("
        SELECT  a.*,
                u.nombre AS nombre_usuario
        FROM oportunidad_actividades a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_creacion DESC
    ");
    $stmt->execute([$id]);
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Presupuestos vinculados
    $stmt = $conn->prepare("
        SELECT  p.id_proyecto,
                p.numero_proyecto,
                p.titulo,
                p.cliente,
                e.estado,
                COALESCE(
                    (SELECT SUM(i.total_hoy)
                     FROM   items i
                     WHERE  i.id_proyecto = p.id_proyecto), 0
                ) AS monto_total
        FROM oportunidad_presupuestos op
        JOIN proyecto p ON op.proyecto_id = p.id_proyecto
        JOIN estados  e ON p.estado_id    = e.id
        WHERE op.oportunidad_id = ?
        ORDER BY p.numero_proyecto DESC
    ");
    $stmt->execute([$id]);
    $presupuestos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'       => true,
        'op'            => $op,
        'cliente_nombre'=> $op['cliente_nombre'],
        'actividades'   => $actividades,
        'presupuestos'  => $presupuestos,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=save  — crear o editar oportunidad
// ═══════════════════════════════════════════════════════════
if ($action === 'save') {
    if (!$puede_crear && !$puede_editar) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $id             = !empty($data['id'])       ? intval($data['id'])             : null;
    $titulo         = trim($data['titulo']      ?? '');
    $cliente_id     = intval($data['cliente_id'] ?? 0);
    $etapa_id       = intval($data['etapa_id']  ?? 1);
    $monto          = floatval($data['monto_estimado'] ?? 0);
    $proteccion     = trim($data['proteccion']  ?? '');
    $notas          = trim($data['notas']        ?? '');
    $fecha_cierre   = !empty($data['fecha_cierre']) ? $data['fecha_cierre'] : null;
    $estado         = in_array($data['estado'] ?? '', ['Activo','Ganado','Perdido'])
                        ? $data['estado'] : 'Activo';

    if (empty($titulo) || $cliente_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Título y cliente son obligatorios']);
        exit;
    }

    try {
        if ($id) {
            // ── Editar ─────────────────────────────────────────────
            if (!$puede_editar) {
                echo json_encode(['success' => false, 'message' => 'Sin permiso de edición']);
                exit;
            }
            $stmt = $conn->prepare("
                UPDATE oportunidades
                SET titulo         = ?,
                    cliente_id     = ?,
                    etapa_id       = ?,
                    monto_estimado = ?,
                    proteccion     = ?,
                    notas          = ?,
                    fecha_cierre   = ?,
                    estado         = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $titulo, $cliente_id, $etapa_id, $monto,
                $proteccion, $notas, $fecha_cierre, $estado,
                $id
            ]);

        } else {
            // ── Crear ──────────────────────────────────────────────
            if (!$puede_crear) {
                echo json_encode(['success' => false, 'message' => 'Sin permiso de creación']);
                exit;
            }

            $conn->beginTransaction();

            // Número correlativo con bloqueo
            $stmt = $conn->prepare(
                "SELECT numero_actual FROM contadores WHERE documento = 'oportunidades' FOR UPDATE"
            );
            $stmt->execute();
            $num_actual = $stmt->fetchColumn();
            if ($num_actual === false) $num_actual = 0;
            $nuevo_num = $num_actual + 1;

            $conn->prepare(
                "UPDATE contadores SET numero_actual = ? WHERE documento = 'oportunidades'"
            )->execute([$nuevo_num]);

            $sucursal_id = $_SESSION['usuario']['sucursal_id'];

            $stmt = $conn->prepare("
                INSERT INTO oportunidades
                    (numero, titulo, cliente_id, usuario_id, sucursal_id,
                     monto_estimado, etapa_id, proteccion, notas,
                     fecha_creacion, fecha_cierre, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
            ");
            $stmt->execute([
                $nuevo_num, $titulo, $cliente_id, $uid, $sucursal_id,
                $monto, $etapa_id, $proteccion, $notas,
                $fecha_cierre, $estado
            ]);
            $id = $conn->lastInsertId();

            $conn->commit();
        }

        echo json_encode(['success' => true, 'id' => $id]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=mover  — drag & drop de etapa
// ═══════════════════════════════════════════════════════════
if ($action === 'mover') {
    if (!$puede_editar) {
        echo json_encode(['success' => false]);
        exit;
    }
    $data     = json_decode(file_get_contents('php://input'), true);
    $id       = intval($data['id']       ?? 0);
    $etapa_id = intval($data['etapa_id'] ?? 0);

    // Verificar acceso si no es manager
    if (!$puede_ver_todas) {
        $stmt = $conn->prepare("SELECT usuario_id FROM oportunidades WHERE id = ?");
        $stmt->execute([$id]);
        $owner = $stmt->fetchColumn();
        if ($owner != $uid) {
            echo json_encode(['success' => false, 'message' => 'Sin acceso']);
            exit;
        }
    }

    $conn->prepare(
        "UPDATE oportunidades SET etapa_id = ? WHERE id = ?"
    )->execute([$etapa_id, $id]);

    // Actualizar estado si es la última etapa (Facturado = Ganado)
    $stmt = $conn->prepare("SELECT orden FROM oportunidad_etapas WHERE id = ?");
    $stmt->execute([$etapa_id]);
    $orden = $stmt->fetchColumn();
    if ($orden >= 7) {
        $conn->prepare("UPDATE oportunidades SET estado = 'Ganado' WHERE id = ?")->execute([$id]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=save_actividad  — registrar actividad
// ═══════════════════════════════════════════════════════════
if ($action === 'save_actividad') {
    if (!$puede_editar && !$puede_crear) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);

    $oid               = intval($data['oportunidad_id']     ?? 0);
    $tipo              = $data['tipo']                       ?? 'Llamada';
    $resultado         = trim($data['resultado']            ?? '');
    $proximo_paso      = trim($data['proximo_paso']         ?? '');
    $fecha_prox        = trim($data['fecha_proximo_paso']   ?? '');

    $tipos_validos = ['Llamada','Reunion','Correo','Actualización de quote','Visita'];
    if (!in_array($tipo, $tipos_validos)) $tipo = 'Llamada';

    if (empty($fecha_prox)) {
        echo json_encode(['success' => false, 'message' => 'La fecha del próximo paso es obligatoria']);
        exit;
    }

    $conn->prepare("
        INSERT INTO oportunidad_actividades
            (oportunidad_id, usuario_id, tipo, resultado, proximo_paso, fecha_proximo_paso)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$oid, $uid, $tipo, $resultado, $proximo_paso, $fecha_prox]);

    // Retornar lista actualizada
    $stmt = $conn->prepare("
        SELECT  a.*,
                u.nombre AS nombre_usuario
        FROM oportunidad_actividades a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_creacion DESC
    ");
    $stmt->execute([$oid]);
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'actividades' => $actividades]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=link_presupuesto  — vincular presupuesto
// ═══════════════════════════════════════════════════════════
if ($action === 'link_presupuesto') {
    if (!$puede_editar) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $oid  = intval($data['oportunidad_id'] ?? 0);
    $pid  = intval($data['proyecto_id']    ?? 0);

    try {
        $conn->prepare(
            "INSERT IGNORE INTO oportunidad_presupuestos (oportunidad_id, proyecto_id) VALUES (?, ?)"
        )->execute([$oid, $pid]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    echo json_encode(array_merge(['success' => true], _presupuestosOp($conn, $oid)));
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=unlink_presupuesto  — desvincular presupuesto
// ═══════════════════════════════════════════════════════════
if ($action === 'unlink_presupuesto') {
    if (!$puede_editar) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $oid  = intval($data['oportunidad_id'] ?? 0);
    $pid  = intval($data['proyecto_id']    ?? 0);

    $conn->prepare(
        "DELETE FROM oportunidad_presupuestos WHERE oportunidad_id = ? AND proyecto_id = ?"
    )->execute([$oid, $pid]);

    echo json_encode(array_merge(['success' => true], _presupuestosOp($conn, $oid)));
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=delete  — eliminar (borrado físico)
// ═══════════════════════════════════════════════════════════
if ($action === 'delete') {
    if (!$puede_eliminar) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);

    try {
        // ON DELETE CASCADE se encarga de actividades y presupuestos vinculados
        $conn->prepare("DELETE FROM oportunidades WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ─── Acción no reconocida ────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Acción no válida']);

// ═══════════════════════════════════════════════════════════
// Helper: obtener presupuestos vinculados a una oportunidad
// ═══════════════════════════════════════════════════════════
function _presupuestosOp(PDO $conn, int $oid): array
{
    $stmt = $conn->prepare("
        SELECT  p.id_proyecto,
                p.numero_proyecto,
                p.titulo,
                p.cliente,
                e.estado,
                COALESCE(
                    (SELECT SUM(i.total_hoy)
                     FROM   items i
                     WHERE  i.id_proyecto = p.id_proyecto), 0
                ) AS monto_total
        FROM oportunidad_presupuestos op
        JOIN proyecto p ON op.proyecto_id = p.id_proyecto
        JOIN estados  e ON p.estado_id    = e.id
        WHERE op.oportunidad_id = ?
        ORDER BY p.numero_proyecto DESC
    ");
    $stmt->execute([$oid]);
    return ['presupuestos' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}