<?php
include 'includes/config.php';
include 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_proyecto_financiero = intval($_POST['id_proyecto_financiero']);
    $precio_final_venta = floatval($_POST['precio_final_venta']);
    $venta_productos = floatval($_POST['venta_productos']);
    $venta_servicios = floatval($_POST['venta_servicios'] ?? 0);
    $presupuesto_gasto_usd = floatval($_POST['presupuesto_gasto_usd']);
    $presupuesto_gasto_bs = floatval($_POST['presupuesto_gasto_bs']);
    $credito_fiscal_favor = floatval($_POST['credito_fiscal_favor']);

    try {
        // Verificar si existe el registro
        $stmt = $conn->prepare("SELECT COUNT(*) FROM datos_cabecera WHERE id_proyecto = ?");
        $stmt->execute([$id_proyecto_financiero]);
        $existe = $stmt->fetchColumn();

        if ($existe) {
            // Actualizar
            $stmt = $conn->prepare("UPDATE datos_cabecera SET 
                precio_final_venta = ?, 
                venta_productos = ?,
                venta_servicios = ?,
                presupuesto_gasto_usd = ?, 
                presupuesto_gasto_bs = ?, 
                credito_fiscal_favor = ?
                WHERE id_proyecto = ?");
            $stmt->execute([
                $precio_final_venta, 
                $venta_productos,
                $venta_servicios,
                $presupuesto_gasto_usd, 
                $presupuesto_gasto_bs, 
                $credito_fiscal_favor, 
                $id_proyecto_financiero
            ]);
        } else {
            // Insertar
            $stmt = $conn->prepare("INSERT INTO datos_cabecera 
                (id_proyecto, precio_final_venta, venta_productos, venta_servicios, 
                 presupuesto_gasto_usd, presupuesto_gasto_bs, credito_fiscal_favor) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id_proyecto_financiero, 
                $precio_final_venta, 
                $venta_productos,
                $venta_servicios,
                $presupuesto_gasto_usd, 
                $presupuesto_gasto_bs, 
                $credito_fiscal_favor
            ]);
        }

        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&success=Datos guardados correctamente");
        exit();
    } catch (PDOException $e) {
        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&error=Error al guardar: " . $e->getMessage());
        exit();
    }
} else {
    header("Location: finanzas.php");
    exit();
}
?>