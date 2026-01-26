<?php
include 'includes/config.php';
include 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_proyecto_financiero = intval($_POST['id_proyecto_financiero']);
    $precio_final_venta = floatval($_POST['precio_final_venta']);
    $venta_productos = floatval($_POST['venta_productos'] ?? 0);
    $venta_servicios = floatval($_POST['venta_servicios'] ?? 0);
    $presupuesto_gasto_usd = floatval($_POST['presupuesto_gasto_usd']);
    $presupuesto_gasto_bs = floatval($_POST['presupuesto_gasto_bs']);
    $credito_fiscal_favor = floatval($_POST['credito_fiscal_favor']);

    // Validar que el ID del proyecto sea válido
    if ($id_proyecto_financiero <= 0) {
        header("Location: finanzas.php?error=ID de proyecto inválido");
        exit();
    }

    try {
        // Obtener datos variables (IVA e IT)
        $stmt = $conn->query("SELECT * FROM datos_variables ORDER BY id DESC LIMIT 1");
        $datos_variables = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$datos_variables) {
            throw new Exception("No se encontraron datos variables (IVA/IT)");
        }

        // Calcular impuestos
        $iva_it_total = ($datos_variables['iva']/100) + ($datos_variables['it']/100);
        $gastos_impuestos_prod = $venta_productos * $iva_it_total;
        $gastos_impuestos_serv = $venta_servicios * $iva_it_total;

        // Verificar si existe el registro
        $stmt = $conn->prepare("SELECT COUNT(*) FROM datos_cabecera WHERE id_proyecto = ?");
        $stmt->execute([$id_proyecto_financiero]);
        $existe = $stmt->fetchColumn();

        if ($existe) {
            // Actualizar
            $stmt = $conn->prepare("UPDATE datos_cabecera SET 
                precio_final_venta = ?, 
                venta_productos = ?,
                gasto_impuestos_prod = ?,
                venta_servicios = ?,
                gasto_impuestos_serv = ?,
                presupuesto_gasto_usd = ?, 
                presupuesto_gasto_bs = ?, 
                credito_fiscal_favor = ?
                WHERE id_proyecto = ?");
            
            $stmt->execute([
                $precio_final_venta, 
                $venta_productos,
                $gastos_impuestos_prod,
                $venta_servicios,
                $gastos_impuestos_serv,
                $presupuesto_gasto_usd, 
                $presupuesto_gasto_bs, 
                $credito_fiscal_favor, 
                $id_proyecto_financiero
            ]);
        } else {
            // Insertar - CORREGIDO
            $stmt = $conn->prepare("INSERT INTO datos_cabecera 
                (id_proyecto, precio_final_venta, venta_productos, gasto_impuestos_prod, 
                 venta_servicios, gasto_impuestos_serv, presupuesto_gasto_usd, 
                 presupuesto_gasto_bs, credito_fiscal_favor) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $id_proyecto_financiero, 
                $precio_final_venta, 
                $venta_productos,
                $gastos_impuestos_prod,
                $venta_servicios,
                $gastos_impuestos_serv,
                $presupuesto_gasto_usd, 
                $presupuesto_gasto_bs, 
                $credito_fiscal_favor
            ]);
        }

        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&success=Datos%20guardados%20correctamente");
        exit();
    } catch (PDOException $e) {
        error_log("Error en guardar_cabecera.php: " . $e->getMessage());
        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&error=Error%20al%20guardar%20los%20datos:" . urlencode($e->getMessage()));
        exit();
    } catch (Exception $e) {
        error_log("Error en guardar_cabecera.php: " . $e->getMessage());
        header("Location: proyecto_financiero.php?id=$id_proyecto_financiero&error=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    header("Location: finanzas.php");
    exit();
}
?>