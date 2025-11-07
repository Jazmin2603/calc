<?php
include 'includes/config.php';
include 'includes/auth.php';
include 'includes/funciones.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_proyecto = $_POST['id_proyecto'];
    

    if(empty($_POST['iva'])){
        $stmt = $conn->prepare("SELECT * FROM proyecto WHERE id_proyecto = ?");
        $stmt->execute([$id_proyecto]);
        $datos_variables = $stmt->fetch(PDO::FETCH_ASSOC);
        $iva =  $datos_variables['iva'];
        $it = $datos_variables['it'];
        $itf = $datos_variables['itf'];
        $giro = $datos_variables['giro_exterior'];
        $comAduana = $datos_variables['com_aduana'];
        $oficial = $datos_variables['tc_oficial'];
    } else {
        $iva =  $_POST['iva'];
        $it = $_POST['it'];
        $itf = $_POST['itf'];
        $giro = $_POST['giro_exterior'];
        $comAduana = $_POST['com_aduana'];
        $oficial = $_POST['tc_oficial'];
    }
    var_dump($_POST);

    $fechaCierre = !empty($_POST['fecha_cierre']) ? $_POST['fecha_cierre'] : $datos_variables['fecha_cierre'];
    
    $stmt = $conn->prepare("UPDATE proyecto SET 
        fecha_proyecto = ?, 
        titulo = ?, 
        cliente = ?,
        fecha_proyecto = ?,
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
        pago_anticipado_DMC = ?
        WHERE id_proyecto = ?");

    $stmt->execute([
        $_POST['fecha_proyecto'],
        $_POST['titulo'],
        $_POST['cliente'],
        $_POST['fecha_proyecto'],
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
        $id_proyecto
    ]);

    actualizar($id_proyecto);



    header("Location: ver_proyecto.php?id=$id_proyecto&guardado=1");
}
?>
