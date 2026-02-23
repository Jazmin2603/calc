<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/funciones.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_proyecto = $_POST['id_proyecto'];
    
    $stmt_select = $conn->prepare("SELECT * FROM proyecto WHERE id_proyecto = ?");
    $stmt_select->execute([$id_proyecto]);
    $datos_actuales = $stmt_select->fetch(PDO::FETCH_ASSOC);

    if(empty($_POST['iva'])){
        $iva = $datos_actuales['iva'];
        $it = $datos_actuales['it'];
        $itf = $datos_actuales['itf'];
        $giro = $datos_actuales['giro_exterior'];
        $comAduana = $datos_actuales['com_aduana'];
        $oficial = $datos_actuales['tc_oficial'];
    } else {
        $iva = $_POST['iva'];
        $it = $_POST['it'];
        $itf = $_POST['itf'];
        $giro = $_POST['giro_exterior'];
        $comAduana = $_POST['com_aduana'];
        $oficial = $_POST['tc_oficial'];
    }

    $fechaCierre = !empty($_POST['fecha_cierre']) ? $_POST['fecha_cierre'] : $datos_actuales['fecha_cierre'];

    $monto_adjudicado = isset($_POST['monto_adjudicado']) ? $_POST['monto_adjudicado'] : $datos_actuales['monto_adjudicado'];

    $stmt = $conn->prepare("UPDATE proyecto SET 
        fecha_proyecto = ?, 
        titulo = ?, 
        cliente = ?,
        fecha_cierre = ?, 
        iva = ?, 
        it = ?, 
        giro_exterior = ?, 
        itf = ?, 
        com_aduana = ?, 
        tc_oficial = ?, 
        tc_paralelo_hoy = ?,
        tc_estimado30 = ?,
        tc_estimado60 = ?,
        pago_anticipado_DMC = ?,
        monto_adjudicado = ?
        WHERE id_proyecto = ?");

    $stmt->execute([
        $_POST['fecha_proyecto'],
        $_POST['titulo'],
        $_POST['cliente'],
        $fechaCierre,
        $iva,
        $it,
        $giro,
        $itf,
        $comAduana,
        $oficial,
        $_POST['tc_paralelo_hoy'],
        $_POST['tc_estimado30'],
        $_POST['tc_estimado60'],
        $_POST['pago_anticipado_DMC'],
        $monto_adjudicado, 
        $id_proyecto
    ]);

    actualizar($id_proyecto);

    header("Location: ver_proyecto.php?id=$id_proyecto&guardado=1");
    exit();
}