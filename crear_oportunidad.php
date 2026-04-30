<?php
include 'includes/config.php';
include 'includes/auth.php';
require_once 'includes/ews_helper.php';

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

    if (!$puede_ver_todas && $op['usuario_id'] != $uid) {
        echo json_encode(['success' => false, 'message' => 'Sin acceso']);
        exit;
    }

    // Actividades
    // Actividades con sus invitados
    $stmt = $conn->prepare("
        SELECT a.*,
            u.nombre AS nombre_usuario,
            (SELECT GROUP_CONCAT(uu.nombre SEPARATOR ', ')
                FROM oportunidad_actividad_invitados i
                JOIN usuarios uu ON uu.id = i.usuario_id
                WHERE i.actividad_id = a.id) AS invitados_nombres,
            (SELECT GROUP_CONCAT(i.usuario_id)
                FROM oportunidad_actividad_invitados i
                WHERE i.actividad_id = a.id) AS invitados_ids,
            (SELECT COUNT(*)
                FROM oportunidad_actividad_invitados i
                WHERE i.actividad_id = a.id AND i.ms_event_id IS NOT NULL) AS eventos_creados
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
                    (SELECT SUM(i.total_hoy) FROM items i WHERE i.id_proyecto = p.id_proyecto), 0
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
        'success'        => true,
        'op'             => $op,
        'cliente_nombre' => $op['cliente_nombre'],
        'actividades'    => $actividades,
        'presupuestos'   => $presupuestos,
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

    $id           = !empty($data['id'])         ? intval($data['id'])          : null;
    $titulo       = trim($data['titulo']         ?? '');
    $cliente_id   = intval($data['cliente_id']   ?? 0);
    $etapa_id     = intval($data['etapa_id']     ?? 1);
    $monto        = floatval($data['monto_estimado'] ?? 0);
    $proteccion   = trim($data['proteccion']     ?? '');
    $notas        = trim($data['notas']          ?? '');
    $fecha_cierre = !empty($data['fecha_cierre']) ? $data['fecha_cierre'] : null;
    $estado       = in_array($data['estado'] ?? '', ['Activo','Ganado','Perdido'])
                    ? $data['estado'] : 'Activo';

    if (empty($titulo) || $cliente_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Título y cliente son obligatorios']);
        exit;
    }

    try {
        if ($id) {
            if (!$puede_editar) {
                echo json_encode(['success' => false, 'message' => 'Sin permiso de edición']);
                exit;
            }

            // Cargar valores anteriores para comparar
            $stmt_old = $conn->prepare("
                SELECT o.estado, o.etapa_id, o.monto_estimado, o.usuario_id,
                       e.nombre AS etapa_nombre
                FROM oportunidades o
                JOIN oportunidad_etapas e ON o.etapa_id = e.id
                WHERE o.id = ?
            ");
            $stmt_old->execute([$id]);
            $old = $stmt_old->fetch(PDO::FETCH_ASSOC);

            $conn->prepare("
                UPDATE oportunidades
                SET titulo=?, cliente_id=?, etapa_id=?, monto_estimado=?,
                    proteccion=?, notas=?, fecha_cierre=?, estado=?
                WHERE id=?
            ")->execute([$titulo,$cliente_id,$etapa_id,$monto,$proteccion,$notas,$fecha_cierre,$estado,$id]);

            // Loguear cambios importantes
            if ($old) {
                if ($old['estado'] !== $estado) {
                    _logOportunidad($conn, $id, $uid, 'estado_cambiado', 'estado',
                        $old['estado'], $estado, "Cambió estado de {$old['estado']} a $estado");
                }
                if (intval($old['etapa_id']) !== $etapa_id) {
                    $stmt_e = $conn->prepare("SELECT nombre FROM oportunidad_etapas WHERE id=?");
                    $stmt_e->execute([$etapa_id]);
                    $nueva_etapa = $stmt_e->fetchColumn();
                    _logOportunidad($conn, $id, $uid, 'etapa_cambiada', 'etapa_id',
                        $old['etapa_nombre'], $nueva_etapa, "Cambió etapa de {$old['etapa_nombre']} a $nueva_etapa");
                }
                if (floatval($old['monto_estimado']) !== $monto) {
                    _logOportunidad($conn, $id, $uid, 'monto_cambiado', 'monto_estimado',
                        number_format($old['monto_estimado'], 2, '.', ''),
                        number_format($monto, 2, '.', ''),
                        'Cambió el monto estimado de Bs ' . number_format($old['monto_estimado'], 2, ',', '.') .
                        ' a Bs ' . number_format($monto, 2, ',', '.'));
                }
            }

        } else {
            if (!$puede_crear) {
                echo json_encode(['success' => false, 'message' => 'Sin permiso de creación']);
                exit;
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT numero_actual FROM contadores WHERE documento='oportunidades' FOR UPDATE");
            $stmt->execute();
            $num_actual = $stmt->fetchColumn();
            if ($num_actual === false) $num_actual = 0;
            $nuevo_num = $num_actual + 1;

            $conn->prepare("UPDATE contadores SET numero_actual=? WHERE documento='oportunidades'")
                 ->execute([$nuevo_num]);

            $sucursal_id = $_SESSION['usuario']['sucursal_id'];

            $stmt = $conn->prepare("
                INSERT INTO oportunidades
                    (numero,titulo,cliente_id,usuario_id,sucursal_id,
                     monto_estimado,etapa_id,proteccion,notas,
                     fecha_creacion,fecha_cierre,estado)
                VALUES (?,?,?,?,?,?,?,?,?,CURDATE(),?,?)
            ");
            $stmt->execute([
                $nuevo_num,$titulo,$cliente_id,$uid,$sucursal_id,
                $monto,$etapa_id,$proteccion,$notas,$fecha_cierre,$estado
            ]);
            $id = $conn->lastInsertId();
            $conn->commit();

            _logOportunidad($conn, $id, $uid, 'creada', null, null, null,
                "Oportunidad creada — \"$titulo\"");
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
    if (!$puede_editar) { echo json_encode(['success' => false]); exit; }
    $data     = json_decode(file_get_contents('php://input'), true);
    $id       = intval($data['id']       ?? 0);
    $etapa_id = intval($data['etapa_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT o.usuario_id, o.etapa_id, o.estado, e.nombre AS etapa_nombre
        FROM oportunidades o
        JOIN oportunidad_etapas e ON o.etapa_id = e.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) { echo json_encode(['success' => false, 'message' => 'No encontrada']); exit; }

    if (!$puede_ver_todas && $old['usuario_id'] != $uid) {
        echo json_encode(['success' => false, 'message' => 'Sin acceso']); exit;
    }

    $conn->prepare("UPDATE oportunidades SET etapa_id=? WHERE id=?")->execute([$etapa_id,$id]);

    // Log del cambio de etapa
    if (intval($old['etapa_id']) !== $etapa_id) {
        $stmt_e = $conn->prepare("SELECT nombre, orden FROM oportunidad_etapas WHERE id=?");
        $stmt_e->execute([$etapa_id]);
        $nueva = $stmt_e->fetch(PDO::FETCH_ASSOC);

        _logOportunidad($conn, $id, $uid, 'etapa_cambiada', 'etapa_id',
            $old['etapa_nombre'], $nueva['nombre'],
            "Movió de {$old['etapa_nombre']} a {$nueva['nombre']}");

        // Si pasa a etapa final → estado Ganado
        if ($nueva['orden'] >= 7 && $old['estado'] !== 'Ganado') {
            $conn->prepare("UPDATE oportunidades SET estado='Ganado' WHERE id=?")->execute([$id]);
            _logOportunidad($conn, $id, $uid, 'estado_cambiado', 'estado',
                $old['estado'], 'Ganado',
                "Cambió estado de {$old['estado']} a Ganado (etapa final)");
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=save_actividad  — registrar actividad
// ═══════════════════════════════════════════════════════════

if ($action === 'save_actividad') {
    if (!$puede_editar && !$puede_crear) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);

    $oid          = intval($data['oportunidad_id']   ?? 0);
    $tipo         = $data['tipo']                    ?? 'Llamada';
    $proximo_paso = trim($data['proximo_paso']       ?? '');
    $fecha_ini    = trim($data['fecha_proximo_paso'] ?? '');
    $fecha_fin    = trim($data['fecha_fin']          ?? '');
    $send_outlook = !empty($data['enviar_outlook']);
    $invitados    = is_array($data['invitados'] ?? null) ? array_map('intval', $data['invitados']) : [];

    $tipos_validos = ['Llamada','Reunion','Correo','Actualización de quote','Visita'];
    if (!in_array($tipo, $tipos_validos)) $tipo = 'Llamada';

    if (empty($fecha_ini)) {
        echo json_encode(['success' => false, 'message' => 'La fecha/hora de inicio es obligatoria']); exit;
    }
    if (empty($fecha_fin)) {
        // Default: 30 minutos después de inicio
        $fecha_fin = date('Y-m-d H:i:s', strtotime($fecha_ini) + 1800);
    }
    if (strtotime($fecha_fin) <= strtotime($fecha_ini)) {
        echo json_encode(['success' => false, 'message' => 'La hora fin debe ser posterior a la de inicio']); exit;
    }

    // Datos de la oportunidad y del creador
    $stmt_op = $conn->prepare("
        SELECT o.titulo, c.nombre AS cliente_nombre, u.email AS email_creador
        FROM oportunidades o
        JOIN clientes c ON o.cliente_id = c.id
        JOIN usuarios u ON u.id = ?
        WHERE o.id = ?
    ");
    $stmt_op->execute([$uid, $oid]);
    $op_data = $stmt_op->fetch(PDO::FETCH_ASSOC);

    // Asegurar que el creador siempre esté en la lista de invitados
    if (!in_array($uid, $invitados)) array_unshift($invitados, $uid);

    try {
        $conn->beginTransaction();

        // Insertar la actividad
        $conn->prepare("
            INSERT INTO oportunidad_actividades
                (oportunidad_id, usuario_id, tipo, proximo_paso, fecha_proximo_paso, fecha_fin)
            VALUES (?,?,?,?,?,?)
        ")->execute([$oid, $uid, $tipo, $proximo_paso, $fecha_ini, $fecha_fin]);
        $actividad_id = $conn->lastInsertId();

        // Insertar invitados (sin ms_event_id por ahora)
        $stmt_inv = $conn->prepare("
            INSERT INTO oportunidad_actividad_invitados (actividad_id, usuario_id)
            VALUES (?, ?)
        ");
        foreach ($invitados as $inv_uid) {
            $stmt_inv->execute([$actividad_id, $inv_uid]);
        }

        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }

    // ── Crear eventos en Exchange si el usuario lo pidió ────
    $eventos_creados = 0;
    $eventos_error   = [];
    $ews_resumen     = null;

    if ($send_outlook) {
        $tipo_labels = [
            'Llamada'                => '📞 Llamada',
            'Reunion'                => '🤝 Reunión',
            'Correo'                 => '📧 Correo',
            'Actualización de quote' => '📋 Act. de quote',
            'Visita'                 => '🏢 Visita',
        ];
        $subject = ($tipo_labels[$tipo] ?? $tipo)
                 . ' — ' . ($op_data['titulo'] ?? 'Oportunidad')
                 . ' (' . ($op_data['cliente_nombre'] ?? '') . ')';

        $body_text = $proximo_paso ?: '';

        // Cargar emails de los invitados
        $placeholders = implode(',', array_fill(0, count($invitados), '?'));
        $stmt = $conn->prepare("SELECT id, email, nombre FROM usuarios WHERE id IN ($placeholders)");
        $stmt->execute($invitados);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt_upd = $conn->prepare("
            UPDATE oportunidad_actividad_invitados
            SET ms_event_id = ?, ms_error = ?
            WHERE actividad_id = ? AND usuario_id = ?
        ");

        foreach ($emails as $u) {
            if (empty($u['email']) || !str_contains($u['email'], '@')) {
                $stmt_upd->execute([null, 'Sin email corporativo', $actividad_id, $u['id']]);
                $eventos_error[] = $u['nombre'] . ': sin email';
                continue;
            }
            try {
                $event_ref = ewsCrearEvento($u['email'], [
                    'subject'  => $subject,
                    'body'     => $body_text,
                    'start'    => $fecha_ini,
                    'end'      => $fecha_fin,
                    'location' => $op_data['cliente_nombre'] ?? '',
                ]);
                $stmt_upd->execute([$event_ref, null, $actividad_id, $u['id']]);
                $eventos_creados++;
            } catch (RuntimeException $e) {
                $err = substr($e->getMessage(), 0, 490);
                $stmt_upd->execute([null, $err, $actividad_id, $u['id']]);
                $eventos_error[] = $u['nombre'] . ': ' . $err;
            }
        }

        if ($eventos_creados > 0 && empty($eventos_error)) {
            $ews_resumen = "Evento creado en $eventos_creados calendario(s)";
        } elseif ($eventos_creados > 0) {
            $ews_resumen = "$eventos_creados creado(s), errores: " . implode('; ', $eventos_error);
        } else {
            $ews_resumen = 'Errores: ' . implode('; ', $eventos_error);
        }
    }

    // Retornar lista actualizada
    $stmt = $conn->prepare("
        SELECT a.*, u.nombre AS nombre_usuario,
               (SELECT GROUP_CONCAT(uu.nombre SEPARATOR ', ')
                FROM oportunidad_actividad_invitados i
                JOIN usuarios uu ON uu.id = i.usuario_id
                WHERE i.actividad_id = a.id) AS invitados_nombres,
               (SELECT COUNT(*)
                FROM oportunidad_actividad_invitados i
                WHERE i.actividad_id = a.id AND i.ms_event_id IS NOT NULL) AS eventos_creados
        FROM oportunidad_actividades a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_creacion DESC
    ");
    $stmt->execute([$oid]);

    echo json_encode([
        'success'         => true,
        'actividades'     => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'ms_event_creado' => $eventos_creados > 0,
        'ms_resumen'      => $ews_resumen,
        'ms_errores'      => $eventos_error,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// GET  action=calendario  — lista de actividades para vista calendario
// ═══════════════════════════════════════════════════════════
if ($action === 'calendario') {
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-t', strtotime('+1 month'));

    $cond_user = $puede_ver_todas ? '' : ' AND (a.usuario_id = ? OR i.usuario_id = ?)';
    $params    = [$desde, $hasta];
    if (!$puede_ver_todas) { $params[] = $uid; $params[] = $uid; }

    $stmt = $conn->prepare("
        SELECT DISTINCT a.id, a.oportunidad_id, a.usuario_id, a.tipo, a.resultado, a.proximo_paso,
               a.fecha_proximo_paso AS fecha_inicio, a.fecha_fin,
               u.nombre AS nombre_usuario,
               o.titulo AS oportunidad_titulo, o.numero AS oportunidad_numero,
               c.nombre AS cliente_nombre,
               (SELECT GROUP_CONCAT(uu.nombre SEPARATOR ', ')
                FROM oportunidad_actividad_invitados i2
                JOIN usuarios uu ON uu.id = i2.usuario_id
                WHERE i2.actividad_id = a.id) AS invitados
        FROM oportunidad_actividades a
        JOIN usuarios u      ON a.usuario_id     = u.id
        JOIN oportunidades o ON a.oportunidad_id = o.id
        JOIN clientes c      ON o.cliente_id     = c.id
        LEFT JOIN oportunidad_actividad_invitados i ON i.actividad_id = a.id
        WHERE a.fecha_proximo_paso >= ? AND a.fecha_proximo_paso <= ?
        $cond_user
        ORDER BY a.fecha_proximo_paso ASC
    ");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'actividades' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=update_actividad  — registrar resultado de actividad existente
// ═══════════════════════════════════════════════════════════
if ($action === 'update_actividad') {
    if (!$puede_editar && !$puede_crear) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit;
    }
    $data      = json_decode(file_get_contents('php://input'), true);
    $act_id    = intval($data['actividad_id'] ?? 0);
    $resultado = trim($data['resultado']      ?? '');

    $stmt = $conn->prepare("
        SELECT a.oportunidad_id
        FROM oportunidad_actividades a
        JOIN oportunidades o ON a.oportunidad_id = o.id
        WHERE a.id = ? AND (? OR o.usuario_id = ?)
    ");
    $stmt->execute([$act_id, $puede_ver_todas ? 1 : 0, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Sin acceso']); exit;
    }

    $conn->prepare("UPDATE oportunidad_actividades SET resultado = ? WHERE id = ?")
         ->execute([$resultado, $act_id]);

    $stmt = $conn->prepare("
        SELECT a.*, u.nombre AS nombre_usuario
        FROM oportunidad_actividades a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_creacion DESC
    ");
    $stmt->execute([$row['oportunidad_id']]);

    echo json_encode(['success' => true, 'actividades' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// GET  action=ms_status  — verifica si el usuario tiene email
//      corporativo registrado (necesario para EWS)
// ═══════════════════════════════════════════════════════════
if ($action === 'ms_status') {
    $stmt = $conn->prepare("SELECT email FROM usuarios WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $email = trim($row['email'] ?? '');
    // Considerar "conectado" si tiene un email @fils.bo registrado
    $conectado = !empty($email) && str_contains($email, '@');

    echo json_encode([
        'conectado' => $conectado,
        'email'     => $email ?: null,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=link_presupuesto  — vincular presupuesto
// ═══════════════════════════════════════════════════════════
if ($action === 'link_presupuesto') {
    if (!$puede_editar) { echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $oid  = intval($data['oportunidad_id'] ?? 0);
    $pid  = intval($data['proyecto_id']    ?? 0);

    try {
        $conn->prepare(
            "INSERT IGNORE INTO oportunidad_presupuestos (oportunidad_id,proyecto_id) VALUES (?,?)"
        )->execute([$oid,$pid]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
    }

    echo json_encode(array_merge(['success' => true], _presupuestosOp($conn, $oid, $uid, $puede_ver_todas)));
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=unlink_presupuesto  — desvincular presupuesto
// ═══════════════════════════════════════════════════════════
if ($action === 'unlink_presupuesto') {
    if (!$puede_editar) { echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $oid  = intval($data['oportunidad_id'] ?? 0);
    $pid  = intval($data['proyecto_id']    ?? 0);

    $conn->prepare(
        "DELETE FROM oportunidad_presupuestos WHERE oportunidad_id=? AND proyecto_id=?"
    )->execute([$oid,$pid]);

    echo json_encode(array_merge(['success' => true], _presupuestosOp($conn, $oid, $uid, $puede_ver_todas)));
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=crear_presupuesto — crear presupuesto nuevo
//       vinculado a la oportunidad (y con datos del cliente)
// ═══════════════════════════════════════════════════════════
if ($action === 'crear_presupuesto') {
    if (!tienePermiso('presupuestos', 'crear')) {
        echo json_encode(['success' => false, 'message' => 'Sin permiso para crear presupuestos']); exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $oid  = intval($data['oportunidad_id'] ?? 0);

    // Cargar datos de la oportunidad
    $stmt = $conn->prepare("
        SELECT o.*, c.nombre AS cliente_nombre
        FROM oportunidades o
        JOIN clientes c ON o.cliente_id=c.id
        WHERE o.id=?
    ");
    $stmt->execute([$oid]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$op) {
        echo json_encode(['success' => false, 'message' => 'Oportunidad no encontrada']); exit;
    }

    try {
        $conn->beginTransaction();

        // Obtener año actual y contador de presupuestos
        $anio_actual = date('Y');
        $stmt = $conn->prepare(
            "SELECT id, numero_actual, numero_fin FROM contadores
             WHERE documento='presupuestos' AND anio=? FOR UPDATE"
        );
        $stmt->execute([$anio_actual]);
        $contador = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contador) {
            throw new Exception("No existe un contador de presupuestos para el año $anio_actual.");
        }
        if ($contador['numero_actual'] >= $contador['numero_fin']) {
            throw new Exception("Se alcanzó el límite de presupuestos para el año $anio_actual.");
        }

        $nuevo_num = $contador['numero_actual'] + 1;
        $conn->prepare(
            "UPDATE contadores SET numero_actual=? WHERE id=?"
        )->execute([$nuevo_num, $contador['id']]);

        // Obtener datos variables más recientes
        $stmt = $conn->query("SELECT * FROM datos_variables ORDER BY id DESC LIMIT 1");
        $dv   = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dv) throw new Exception("No se encontraron datos variables.");

        // Obtener gestión para la fecha actual
        $hoy = date('Y-m-d');
        $stmt = $conn->prepare(
            "SELECT id FROM gestiones WHERE fecha_inicio<=? AND fecha_fin>=? LIMIT 1"
        );
        $stmt->execute([$hoy, $hoy]);
        $gestion_id = $stmt->fetchColumn() ?: null;

        $sucursal_id = $_SESSION['usuario']['sucursal_id'];

        // Insertar proyecto/presupuesto
        $stmt = $conn->prepare("
            INSERT INTO proyecto
                (id_usuario, fecha_proyecto, titulo, cliente, fecha_cierre,
                 iva, it, giro_exterior, tc_oficial, tc_paralelo_hoy, tc_estimado30,
                 com_aduana, itf, tc_estimado60, pago_anticipado_DMC,
                 sucursal_id, numero_proyecto, gestion_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $uid,
            $hoy,
            $op['titulo'],            // Título de la oportunidad
            $op['cliente_nombre'],    // Nombre del cliente
            $op['fecha_cierre'],      // Fecha cierre de la oportunidad
            $dv['iva'],
            $dv['it'],
            $dv['giro_exterior'],
            $dv['tc_oficial'],
            $dv['tc_paralelo_hoy'],
            $dv['tc_estimado30'],
            $dv['com_aduana'],
            $dv['itf'],
            $dv['tc_estimado60'],
            $dv['pago_anticipado_DMC'],
            $sucursal_id,
            $nuevo_num,
            $gestion_id
        ]);
        $id_proyecto = $conn->lastInsertId();

        // Vincular el presupuesto a la oportunidad
        $conn->prepare(
            "INSERT IGNORE INTO oportunidad_presupuestos (oportunidad_id,proyecto_id) VALUES (?,?)"
        )->execute([$oid, $id_proyecto]);

        $conn->commit();

        // Retornar lista actualizada de presupuestos
        $result = _presupuestosOp($conn, $oid, $uid, $puede_ver_todas);

        echo json_encode(array_merge([
            'success'    => true,
            'id_proyecto' => $id_proyecto,
            'numero'     => $nuevo_num,
        ], $result));

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=delete  — eliminar oportunidad
// ═══════════════════════════════════════════════════════════
if ($action === 'delete') {
    if (!$puede_eliminar) { echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = intval($data['id'] ?? 0);
    try {
        $conn->prepare("DELETE FROM oportunidades WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// GET  action=detalle  — info completa para vista detalle
// ═══════════════════════════════════════════════════════════
if ($action === 'detalle') {
    $id = intval($_GET['id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT o.*,
               c.nombre AS cliente_nombre, c.sector AS cliente_sector,
               c.ciudad AS cliente_ciudad, c.nit AS cliente_nit, c.correo AS cliente_correo,
               u.nombre AS nombre_usuario, u.email AS email_usuario,
               s.nombre AS sucursal_nombre,
               e.nombre AS etapa_nombre, e.probabilidad AS etapa_probabilidad
        FROM oportunidades o
        JOIN clientes c ON o.cliente_id = c.id
        JOIN usuarios u ON o.usuario_id = u.id
        JOIN sucursales s ON o.sucursal_id = s.id
        JOIN oportunidad_etapas e ON o.etapa_id = e.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$op) { echo json_encode(['success' => false, 'message' => 'No encontrada']); exit; }
    if (!$puede_ver_todas && $op['usuario_id'] != $uid) {
        echo json_encode(['success' => false, 'message' => 'Sin acceso']); exit;
    }

    // Actividades con invitados
    $stmt = $conn->prepare("
        SELECT a.*, u.nombre AS nombre_usuario,
               (SELECT GROUP_CONCAT(uu.nombre SEPARATOR ', ')
                FROM oportunidad_actividad_invitados i
                JOIN usuarios uu ON uu.id = i.usuario_id
                WHERE i.actividad_id = a.id) AS invitados_nombres,
               (SELECT GROUP_CONCAT(i.usuario_id)
                FROM oportunidad_actividad_invitados i
                WHERE i.actividad_id = a.id) AS invitados_ids,
               (SELECT COUNT(*) FROM oportunidad_actividad_invitados i
                WHERE i.actividad_id = a.id AND i.ms_event_id IS NOT NULL) AS eventos_creados
        FROM oportunidad_actividades a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_creacion DESC
    ");
    $stmt->execute([$id]);
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Presupuestos vinculados
    $stmt = $conn->prepare("
        SELECT p.id_proyecto, p.numero_proyecto, p.titulo, p.cliente, e.estado,
               COALESCE((SELECT SUM(i.total_hoy) FROM items i WHERE i.id_proyecto = p.id_proyecto), 0) AS monto_total
        FROM oportunidad_presupuestos op
        JOIN proyecto p ON op.proyecto_id = p.id_proyecto
        JOIN estados e ON p.estado_id = e.id
        WHERE op.oportunidad_id = ?
        ORDER BY p.numero_proyecto DESC
    ");
    $stmt->execute([$id]);
    $presupuestos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Archivos
    $stmt = $conn->prepare("
        SELECT a.*, u.nombre AS nombre_usuario
        FROM oportunidad_archivos a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_subida DESC
    ");
    $stmt->execute([$id]);
    $archivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Logs
    $stmt = $conn->prepare("
        SELECT l.*, u.nombre AS nombre_usuario
        FROM oportunidad_logs l
        JOIN usuarios u ON l.usuario_id = u.id
        WHERE l.oportunidad_id = ?
        ORDER BY l.fecha_creacion DESC
    ");
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'op'           => $op,
        'actividades'  => $actividades,
        'presupuestos' => $presupuestos,
        'archivos'     => $archivos,
        'logs'         => $logs,
        'es_dueno'     => intval($op['usuario_id']) === $uid,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=upload_archivo  — subir archivo
// ═══════════════════════════════════════════════════════════
if ($action === 'upload_archivo') {
    if (!$puede_editar && !$puede_crear) {
        echo json_encode(['success' => false, 'message' => 'Sin permisos']); exit;
    }
    $oid = intval($_POST['oportunidad_id'] ?? 0);
    if (!$oid || !isset($_FILES['archivo'])) {
        echo json_encode(['success' => false, 'message' => 'Falta oportunidad o archivo']); exit;
    }
    $f = $_FILES['archivo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Error al subir (código ' . $f['error'] . ')']); exit;
    }
    $MAX = 10 * 1024 * 1024;  // 10 MB
    if ($f['size'] > $MAX) {
        echo json_encode(['success' => false, 'message' => 'Archivo excede 10 MB']); exit;
    }

    // Generar nombre seguro
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    $ext_clean = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $nombre_alm = bin2hex(random_bytes(12)) . ($ext_clean ? '.' . $ext_clean : '');

    $dir = __DIR__ . "/uploads/oportunidades/$oid";
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo crear el directorio']); exit;
    }
    $dest = "$dir/$nombre_alm";
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo guardar el archivo']); exit;
    }

    $mime = mime_content_type($dest) ?: $f['type'];

    $conn->prepare("
        INSERT INTO oportunidad_archivos
            (oportunidad_id, usuario_id, nombre_original, nombre_almacenado, mime_type, tamano_bytes)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$oid, $uid, $f['name'], $nombre_alm, $mime, $f['size']]);

    _logOportunidad($conn, $oid, $uid, 'archivo_subido', null, null, $f['name'],
        "Subió el archivo \"{$f['name']}\"");

    // Devolver lista actualizada
    $stmt = $conn->prepare("
        SELECT a.*, u.nombre AS nombre_usuario
        FROM oportunidad_archivos a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_subida DESC
    ");
    $stmt->execute([$oid]);
    echo json_encode(['success' => true, 'archivos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ═══════════════════════════════════════════════════════════
// GET  action=download_archivo  — descargar archivo
// ═══════════════════════════════════════════════════════════
if ($action === 'download_archivo') {
    $aid = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("
        SELECT a.*, o.usuario_id AS op_usuario_id
        FROM oportunidad_archivos a
        JOIN oportunidades o ON a.oportunidad_id = o.id
        WHERE a.id = ?
    ");
    $stmt->execute([$aid]);
    $arch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$arch) { http_response_code(404); exit('No encontrado'); }
    if (!$puede_ver_todas && $arch['op_usuario_id'] != $uid) {
        http_response_code(403); exit('Sin acceso');
    }

    $path = __DIR__ . "/uploads/oportunidades/{$arch['oportunidad_id']}/{$arch['nombre_almacenado']}";
    if (!file_exists($path)) { http_response_code(404); exit('Archivo no existe en disco'); }

    header('Content-Type: ' . ($arch['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($arch['nombre_original']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST  action=delete_archivo  — eliminar archivo
//      Solo puede eliminar quien creó la oportunidad o quien la tiene asignada
// ═══════════════════════════════════════════════════════════
if ($action === 'delete_archivo') {
    $data = json_decode(file_get_contents('php://input'), true);
    $aid = intval($data['id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT a.*, o.usuario_id AS op_usuario_id
        FROM oportunidad_archivos a
        JOIN oportunidades o ON a.oportunidad_id = o.id
        WHERE a.id = ?
    ");
    $stmt->execute([$aid]);
    $arch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$arch) { echo json_encode(['success' => false, 'message' => 'No encontrado']); exit; }

    // Solo el dueño de la oportunidad puede eliminar
    if ($arch['op_usuario_id'] != $uid && !esSuperusuario()) {
        echo json_encode(['success' => false, 'message' => 'Solo el responsable de la oportunidad puede eliminar archivos']);
        exit;
    }

    $path = __DIR__ . "/uploads/oportunidades/{$arch['oportunidad_id']}/{$arch['nombre_almacenado']}";
    if (file_exists($path)) @unlink($path);

    $conn->prepare("DELETE FROM oportunidad_archivos WHERE id = ?")->execute([$aid]);

    _logOportunidad($conn, $arch['oportunidad_id'], $uid, 'archivo_eliminado',
        null, $arch['nombre_original'], null,
        "Eliminó el archivo \"{$arch['nombre_original']}\"");

    // Devolver lista actualizada
    $stmt = $conn->prepare("
        SELECT a.*, u.nombre AS nombre_usuario
        FROM oportunidad_archivos a
        JOIN usuarios u ON a.usuario_id = u.id
        WHERE a.oportunidad_id = ?
        ORDER BY a.fecha_subida DESC
    ");
    $stmt->execute([$arch['oportunidad_id']]);
    echo json_encode(['success' => true, 'archivos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acción no válida']);

// ─── Helper ─────────────────────────────────────────────
// Devuelve los presupuestos vinculados a $oid y los disponibles
// (no vinculados a ninguna oportunidad, filtrados por usuario si aplica)
function _presupuestosOp(PDO $conn, int $oid, int $uid = 0, bool $puede_ver_todas = false): array
{
    // Vinculados a esta oportunidad
    $stmt = $conn->prepare("
        SELECT  p.id_proyecto, p.numero_proyecto, p.titulo, p.cliente, e.estado,
                COALESCE(
                    (SELECT SUM(i.total_hoy) FROM items i WHERE i.id_proyecto=p.id_proyecto), 0
                ) AS monto_total
        FROM oportunidad_presupuestos op
        JOIN proyecto p ON op.proyecto_id=p.id_proyecto
        JOIN estados  e ON p.estado_id=e.id
        WHERE op.oportunidad_id=?
        ORDER BY p.numero_proyecto DESC
    ");
    $stmt->execute([$oid]);
    $vinculados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Disponibles: no vinculados a NINGUNA oportunidad
    $cond_usuario = (!$puede_ver_todas && $uid > 0) ? " AND p.id_usuario = $uid" : '';
    $stmt = $conn->prepare("
        SELECT p.id_proyecto, p.numero_proyecto, p.titulo, p.cliente, e.estado
        FROM proyecto p
        JOIN estados e ON p.estado_id = e.id
        WHERE p.id_proyecto NOT IN (
            SELECT proyecto_id FROM oportunidad_presupuestos
        )
        $cond_usuario
        ORDER BY p.numero_proyecto DESC
        LIMIT 300
    ");
    $stmt->execute();
    $disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'presupuestos' => $vinculados,
        'disponibles'  => $disponibles,
    ];
}

// ─── Helper para logging de cambios ─────────────────────
function _logOportunidad(PDO $conn, int $oid, int $uid, string $accion, ?string $campo = null,
                        ?string $vAnt = null, ?string $vNuevo = null, ?string $desc = null): void {
    $conn->prepare("
        INSERT INTO oportunidad_logs
            (oportunidad_id, usuario_id, accion, campo, valor_anterior, valor_nuevo, descripcion)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$oid, $uid, $accion, $campo,
                 $vAnt !== null ? mb_substr($vAnt, 0, 500) : null,
                 $vNuevo !== null ? mb_substr($vNuevo, 0, 500) : null,
                 $desc !== null ? mb_substr($desc, 0, 500) : null]);
}