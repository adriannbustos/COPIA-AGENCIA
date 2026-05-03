<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ============================================================================
// 1. VERIFICAR AUTENTICACIÓN Y PERMISOS
// ============================================================================
if (!$auth->isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!$auth->hasRole('administrador') && !$auth->hasRole('empresa') && !$auth->hasRole('super_admin')) {
    $_SESSION['error'] = 'Acceso denegado. Se requieren permisos de administrador o empresa.';
    header('Location: ../index.php');
    exit;
}
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// ============================================================================
// 2. OBTENER EMPRESA DEL USUARIO
// ============================================================================
$empresa_id_usuario = isset($user['empresa_id']) ? (int)$user['empresa_id'] : 0;
$es_super_admin = $auth->hasRole('super_admin');
// ============================================================================
// 3. PROCESAR CARGA DE PDF
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cargar_pdf'])) {
    try {
        if (!isset($_FILES['pdf_documento']) || $_FILES['pdf_documento']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se ha seleccionado ningún archivo PDF');
        }
        $file = $_FILES['pdf_documento'];
        $tipo_documento = sanitizeInput($_POST['tipo_documento'] ?? '');
        // ✅ NUEVO: Capturar ID de Sucursal
        $sucursal_id = isset($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : 0;
        $observaciones = sanitizeInput($_POST['observaciones'] ?? '');
        $tipos_validos = ['personal', 'servicios', 'recursos', 'sucursal', 'certificacion', 'informe', 'otro'];
        if (!in_array($tipo_documento, $tipos_validos)) {
            throw new Exception('Tipo de documento no válido');
        }
        $allowed_extensions = ['pdf'];
        $allowed_mime_types = ['application/pdf'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception('Solo se permiten archivos PDF');
        }
        $max_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            throw new Exception('El archivo no puede superar los 10MB');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime_type, $allowed_mime_types)) {
            throw new Exception('El archivo no es un PDF válido');
        }
        $upload_dir = '../uploads/informes_sucursales/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $new_filename = 'DOC_' . $tipo_documento . '_' . $empresa_id_usuario . '_' . date('Ymd_His') . '_' . uniqid() . '.pdf';
        $upload_path = $upload_dir . $new_filename;
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            throw new Exception('Error al subir el archivo');
        }
        // Verificar si existe la tabla documentos_sucursales
        $tableCheck = $conn->query("SHOW TABLES LIKE 'documentos_sucursales'");
        if ($tableCheck->rowCount() > 0) {
            // ✅ MODIFICADO: Insertar sucursal_id
            $stmt = $conn->prepare("
            INSERT INTO documentos_sucursales (
            empresa_id, usuario_id, sucursal_id, tipo_documento, archivo_pdf, observaciones, fecha_carga, estado
            ) VALUES (:empresa_id, :usuario_id, :sucursal_id, :tipo_documento, :archivo_pdf, :observaciones, NOW(), 'pendiente')
            ");
            $stmt->execute([
                ':empresa_id' => $empresa_id_usuario,
                ':usuario_id' => $user['id'],
                ':sucursal_id' => $sucursal_id, // ✅ NUEVO
                ':tipo_documento' => $tipo_documento,
                ':archivo_pdf' => 'uploads/informes_sucursales/' . $new_filename,
                ':observaciones' => $observaciones
            ]);
        }
        logAuditoria($conn, 'CARGA_DOCUMENTO_SUCURSAL', 'documentos_sucursales', $conn->lastInsertId(), [
            'tipo_documento' => $tipo_documento,
            'archivo' => $new_filename,
            'tamano' => $file['size'],
            'empresa_id' => $empresa_id_usuario,
            'sucursal_id' => $sucursal_id, // ✅ NUEVO
            'usuario' => $user['nombre'] ?? 'Sistema'
        ], $user['id']);
        $_SESSION['success'] = 'Documento cargado correctamente. Está pendiente de revisión.';
        header('Location: gestion_documentos_sucursales.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al cargar el documento: ' . $e->getMessage();
        header('Location: gestion_documentos_sucursales.php');
        exit;
    }
}
// ============================================================================
// 4. PROCESAR GENERACIÓN DE PDF (INFORME)
// ============================================================================
if (isset($_GET['generar_pdf'])) {
    try {
        if (!file_exists('../vendor/fpdf/fpdf.php')) {
            throw new Exception('FPDF no instalado');
        }
        require_once '../vendor/fpdf/fpdf.php';
        $filtro_sucursal = isset($_GET['filtro_sucursal']) && !empty($_GET['filtro_sucursal']) ? (int)$_GET['filtro_sucursal'] : 0;
        $fecha_reporte = isset($_GET['fecha_reporte']) && !empty($_GET['fecha_reporte']) ? $_GET['fecha_reporte'] : date('Y-m-d');
        // Obtener sucursales
        $query_sucursales = "
        SELECT s.id, s.nombre, s.domicilio, s.localidad, s.activa, e.nombre as empresa_nombre
        FROM sucursales s
        INNER JOIN empresas e ON s.empresa_id = e.id
        WHERE 1=1
        ";
        $params_sucursales = [];
        if (!$es_super_admin && $empresa_id_usuario > 0) {
            $query_sucursales .= " AND s.empresa_id = :empresa_id_usuario";
            $params_sucursales[':empresa_id_usuario'] = $empresa_id_usuario;
        }
        if ($filtro_sucursal > 0) {
            $query_sucursales .= " AND s.id = :filtro_sucursal";
            $params_sucursales[':filtro_sucursal'] = $filtro_sucursal;
        }
        $query_sucursales .= " ORDER BY e.nombre, s.nombre";
        $stmt = $conn->prepare($query_sucursales);
        $stmt->execute($params_sucursales);
        $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Obtener personal
        $personal_por_sucursal = [];
        $query_personal = "
        SELECT p.id, p.nombre, p.apellido, p.dni, p.cargo, p.activo,
        s.id as sucursal_id, s.nombre as sucursal_nombre
        FROM personal p
        INNER JOIN sucursales s ON p.sucursal_id = s.id
        WHERE 1=1
        ";
        $params_personal = [];
        if (!$es_super_admin && $empresa_id_usuario > 0) {
            $query_personal .= " AND p.empresa_id = :empresa_id_usuario";
            $params_personal[':empresa_id_usuario'] = $empresa_id_usuario;
        }
        if ($filtro_sucursal > 0) {
            $query_personal .= " AND p.sucursal_id = :filtro_sucursal";
            $params_personal[':filtro_sucursal'] = $filtro_sucursal;
        }
        $query_personal .= " ORDER BY s.nombre, p.apellido, p.nombre";
        $stmt = $conn->prepare($query_personal);
        $stmt->execute($params_personal);
        $personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($personal_list as $p) {
            $sucursal_id = $p['sucursal_id'];
            if (!isset($personal_por_sucursal[$sucursal_id])) {
                $personal_por_sucursal[$sucursal_id] = [];
            }
            $personal_por_sucursal[$sucursal_id][] = $p;
        }
        // Obtener servicios
        $servicios_por_sucursal = [];
        $query_servicios = "
        SELECT s.id, s.nombre, s.tipo, s.estado, s.prioridad,
        suc.id as sucursal_id, suc.nombre as sucursal_nombre
        FROM servicios s
        INNER JOIN sucursales suc ON s.sucursal_id = suc.id
        WHERE 1=1
        ";
        $params_servicios = [];
        if (!$es_super_admin && $empresa_id_usuario > 0) {
            $query_servicios .= " AND s.empresa_id = :empresa_id_usuario";
            $params_servicios[':empresa_id_usuario'] = $empresa_id_usuario;
        }
        if ($filtro_sucursal > 0) {
            $query_servicios .= " AND s.sucursal_id = :filtro_sucursal";
            $params_servicios[':filtro_sucursal'] = $filtro_sucursal;
        }
        $query_servicios .= " ORDER BY suc.nombre, s.nombre";
        $stmt = $conn->prepare($query_servicios);
        $stmt->execute($params_servicios);
        $servicios_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($servicios_list as $s) {
            $sucursal_id = $s['sucursal_id'];
            if (!isset($servicios_por_sucursal[$sucursal_id])) {
                $servicios_por_sucursal[$sucursal_id] = [];
            }
            $servicios_por_sucursal[$sucursal_id][] = $s;
        }
        // Obtener recursos
        $recursos_por_sucursal = [];
        $query_recursos = "
        SELECT rs.id, rs.empresa_id, rs.sucursal_id, rs.estado,
        suc.nombre as sucursal_nombre, ri.tipo_recurso, ri.atributos
        FROM recursos_sucursal rs
        INNER JOIN sucursales suc ON rs.sucursal_id = suc.id
        LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
        WHERE rs.estado = 'aprobado'
        ";
        $params_recursos = [];
        if (!$es_super_admin && $empresa_id_usuario > 0) {
            $query_recursos .= " AND rs.empresa_id = :empresa_id_usuario";
            $params_recursos[':empresa_id_usuario'] = $empresa_id_usuario;
        }
        if ($filtro_sucursal > 0) {
            $query_recursos .= " AND rs.sucursal_id = :filtro_sucursal";
            $params_recursos[':filtro_sucursal'] = $filtro_sucursal;
        }
        $query_recursos .= " ORDER BY suc.nombre, ri.tipo_recurso";
        $stmt = $conn->prepare($query_recursos);
        $stmt->execute($params_recursos);
        $recursos_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recursos_list as $r) {
            $sucursal_id = $r['sucursal_id'];
            if (!isset($recursos_por_sucursal[$sucursal_id])) {
                $recursos_por_sucursal[$sucursal_id] = [];
            }
            $recursos_por_sucursal[$sucursal_id][] = $r;
        }
        // Generar PDF
        class PDF_Informe extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 14);
                $this->Cell(0, 10, 'INFORME DE SUCURSALES - SISTEMA DE SEGURIDAD', 0, 1, 'C');
                $this->SetFont('Arial', '', 10);
                $this->Cell(0, 6, 'Fecha del Reporte: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
                $this->Ln(5);
                $this->SetFillColor(200, 220, 255);
                $this->Cell(0, 1, '', 0, 1, 'L', true);
                $this->Ln(3);
            }
            function Footer() {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }
            function SectionTitle($label) {
                $this->SetFont('Arial', 'B', 12);
                $this->SetFillColor(70, 130, 180);
                $this->SetTextColor(255);
                $this->Cell(0, 8, $label, 0, 1, 'L', true);
                $this->SetTextColor(0);
                $this->Ln(2);
            }
            function SubSectionTitle($label) {
                $this->SetFont('Arial', 'B', 10);
                $this->SetFillColor(230, 230, 230);
                $this->Cell(0, 6, $label, 0, 1, 'L', true);
                $this->SetFillColor(255);
            }
            function DataTable($headers, $data, $widths) {
                $this->SetFont('Arial', '', 9);
                $this->SetFillColor(245, 245, 245);
                $this->SetFillColor(200, 200, 200);
                $this->SetFont('Arial', 'B', 9);
                for ($i = 0; $i < count($headers); $i++) {
                    $this->Cell($widths[$i], 7, $headers[$i], 1, 0, 'C', true);
                }
                $this->Ln();
                $this->SetFont('Arial', '', 9);
                $fill = false;
                foreach ($data as $row) {
                    for ($i = 0; $i < count($row); $i++) {
                        $this->Cell($widths[$i], 6, $row[$i], 1, 0, 'C', $fill);
                    }
                    $this->Ln();
                    $fill = !$fill;
                }
                $this->Ln(3);
            }
        }
        $pdf = new PDF_Informe();
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        // Información del reporte
        $pdf->SectionTitle(' INFORMACION DEL REPORTE');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 6, 'Fecha de Generacion:', 0, 0);
        $pdf->Cell(0, 6, date('d/m/Y H:i:s'), 0, 1);
        $pdf->Cell(40, 6, 'Usuario:', 0, 0);
        $pdf->Cell(0, 6, $user['nombre'] ?? 'Administrador', 0, 1);
        $pdf->Cell(40, 6, 'Empresa:', 0, 0);
        if ($empresa_id_usuario > 0) {
            $stmt_empresa = $conn->prepare("SELECT nombre FROM empresas WHERE id = :id");
            $stmt_empresa->execute([':id' => $empresa_id_usuario]);
            $empresa_data = $stmt_empresa->fetch();
            $pdf->Cell(0, 6, $empresa_data['nombre'] ?? 'N/A', 0, 1);
        } else {
            $pdf->Cell(0, 6, 'Todas las empresas (Super Admin)', 0, 1);
        }
        $pdf->Cell(40, 6, 'Total Sucursales:', 0, 0);
        $pdf->Cell(0, 6, count($sucursales), 0, 1);
        $pdf->Ln(5);
        // Reporte por sucursal
        foreach ($sucursales as $sucursal) {
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
            }
            $pdf->SectionTitle(' SUCURSAL: ' . strtoupper($sucursal['nombre']));
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(40, 5, 'Empresa:', 0, 0);
            $pdf->Cell(0, 5, $sucursal['empresa_nombre'], 0, 1);
            $pdf->Cell(40, 5, 'Domicilio:', 0, 0);
            $pdf->Cell(0, 5, $sucursal['domicilio'] ?? 'N/A', 0, 1);
            $pdf->Cell(40, 5, 'Localidad:', 0, 0);
            $pdf->Cell(0, 5, $sucursal['localidad'] ?? 'N/A', 0, 1);
            $pdf->Cell(40, 5, 'Estado:', 0, 0);
            $pdf->Cell(0, 5, $sucursal['activa'] ? ' ACTIVA' : ' INACTIVA', 0, 1);
            $pdf->Ln(3);
            // Personal
            $pdf->SubSectionTitle(' PERSONAL ASIGNADO');
            $personal_data = [];
            $personal_sucursal = $personal_por_sucursal[$sucursal['id']] ?? [];
            if (count($personal_sucursal) > 0) {
                foreach ($personal_sucursal as $p) {
                    $personal_data[] = [
                        utf8_decode($p['apellido']),
                        utf8_decode($p['nombre']),
                        $p['dni'],
                        utf8_decode($p['cargo'] ?? 'N/A'),
                        $p['activo'] ? ' [ ]' : ' NO'
                    ];
                }
                $pdf->DataTable(['Apellido', 'Nombre', 'DNI', 'Cargo', 'Activo'], $personal_data, [40, 40, 25, 50, 25]);
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 5, 'Total Personal: ' . count($personal_sucursal), 0, 1);
            } else {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 6, ' No hay personal asignado', 0, 1);
            }
            $pdf->Ln(3);
            // Servicios
            $pdf->SubSectionTitle(' SERVICIOS ASIGNADOS');
            $servicios_data = [];
            $servicios_sucursal = $servicios_por_sucursal[$sucursal['id']] ?? [];
            if (count($servicios_sucursal) > 0) {
                foreach ($servicios_sucursal as $s) {
                    $servicios_data[] = [
                        utf8_decode($s['nombre']),
                        utf8_decode($s['tipo']),
                        strtoupper($s['estado']),
                        strtoupper($s['prioridad'] ?? 'MEDIA'),
                        ' SI'
                    ];
                }
                $pdf->DataTable(['Servicio', 'Tipo', 'Estado', 'Prioridad', 'Asignado'], $servicios_data, [50, 30, 25, 30, 25]);
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 5, 'Total Servicios: ' . count($servicios_sucursal), 0, 1);
            } else {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 6, ' No hay servicios asignados', 0, 1);
            }
            $pdf->Ln(3);
            // Recursos
            $pdf->SubSectionTitle(' RECURSOS ASIGNADOS');
            $recursos_data = [];
            $recursos_sucursal = $recursos_por_sucursal[$sucursal['id']] ?? [];
            if (count($recursos_sucursal) > 0) {
                foreach ($recursos_sucursal as $r) {
                    $atributos = json_decode($r['atributos'], true);
                    $modelo = $atributos['Modelo'] ?? $atributos['model'] ?? 'N/A';
                    $marca = $atributos['Marca'] ?? $atributos['brand'] ?? 'N/A';
                    $tipo = utf8_decode($r['tipo_recurso']);
                    $recursos_data[] = [$tipo, utf8_decode($marca), utf8_decode($modelo), ' SI'];
                }
                $pdf->DataTable(['Tipo Recurso', 'Marca', 'Modelo', 'Asignado'], $recursos_data, [45, 45, 60, 30]);
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 5, 'Total Recursos: ' . count($recursos_sucursal), 0, 1);
            } else {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 6, ' No hay recursos asignados', 0, 1);
            }
            $pdf->Ln(5);
            $pdf->SetFillColor(200, 200, 200);
            $pdf->Cell(0, 1, '', 0, 1, 'L', true);
            $pdf->Ln(5);
        }
        // Resumen
        $pdf->AddPage();
        $pdf->SectionTitle(' RESUMEN GENERAL');
        $total_personal = count($personal_list);
        $total_servicios = count($servicios_list);
        $total_recursos = count($recursos_list);
        $resumen_data = [
            ['Total Sucursales', count($sucursales)],
            ['Total Personal', $total_personal],
            ['Total Servicios', $total_servicios],
            ['Total Recursos', $total_recursos]
        ];
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        foreach ($resumen_data as $row) {
            $pdf->Cell(80, 8, utf8_decode($row[0]), 1, 0, 'L', true);
            $pdf->Cell(0, 8, $row[1], 1, 1, 'C');
        }
        $pdf->Ln(10);
        $pdf->Ln(20);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 6, 'Este reporte fue generado automaticamente por el Sistema de Gestion de Seguridad', 0, 1, 'C');
        $pdf->Cell(0, 6, '_________________________________________', 0, 1, 'C');
        $pdf->Cell(0, 6, 'Firma del Responsable', 0, 1, 'C');
        // Auditoría
        logAuditoria($conn, 'GENERAR_INFORME_SUCURSALES', 'informes', null, [
            'tipo_informe' => 'sucursales_completo',
            'empresa_id_usuario' => $empresa_id_usuario,
            'total_sucursales' => count($sucursales),
            'total_personal' => $total_personal,
            'total_servicios' => $total_servicios,
            'total_recursos' => $total_recursos,
            'usuario' => $user['nombre'] ?? 'Sistema'
        ], $user['id']);
        $nombre_archivo = 'Informe_Sucursales_' . date('Y-m-d_His') . '.pdf';
        $pdf->Output('D', $nombre_archivo);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al generar el informe: ' . $e->getMessage();
        logAuditoria($conn, 'ERROR_INFORME_SUCURSALES', 'informes', null, [
            'error' => $e->getMessage(),
            'usuario' => $user['nombre'] ?? 'Sistema'
        ], $user['id']);
        header('Location: gestion_documentos_sucursales.php');
        exit;
    }
}
// ============================================================================
// 5. OBTENER DATOS PARA EL FORMULARIO
// ============================================================================
try {
    $stmt = $conn->prepare("
    SELECT id, nombre FROM sucursales
    WHERE empresa_id = :empresa_id AND activa = TRUE
    ORDER BY nombre
    ");
    $stmt->execute([':empresa_id' => $empresa_id_usuario]);
    $sucursales_usuario = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Obtener documentos recientes (con verificación de tabla)
    $documentos_recientes = [];
    $tableCheck = $conn->query("SHOW TABLES LIKE 'documentos_sucursales'");
    if ($tableCheck->rowCount() > 0) {
        // Verificar columna usuario_nombre
        $columnCheck = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'nombre'");
        if ($columnCheck->rowCount() > 0) {
            // ✅ MODIFICADO: Join con sucursales para obtener nombre
            $stmt = $conn->prepare("
            SELECT d.*, u.nombre as usuario_nombre, s.nombre as sucursal_nombre
            FROM documentos_sucursales d
            LEFT JOIN usuarios u ON d.usuario_id = u.id
            LEFT JOIN sucursales s ON d.sucursal_id = s.id
            WHERE d.empresa_id = :empresa_id
            ORDER BY d.fecha_carga DESC
            LIMIT 10
            ");
        } else {
            // ✅ MODIFICADO: Join con sucursales para obtener nombre
            $stmt = $conn->prepare("
            SELECT d.*, 'Sistema' as usuario_nombre, s.nombre as sucursal_nombre
            FROM documentos_sucursales d
            LEFT JOIN sucursales s ON d.sucursal_id = s.id
            WHERE d.empresa_id = :empresa_id
            ORDER BY d.fecha_carga DESC
            LIMIT 10
            ");
        }
        $stmt->execute([':empresa_id' => $empresa_id_usuario]);
        $documentos_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE empresa_id = " . $empresa_id_usuario);
    $total_sucursales = $stmt->fetch()['total'];
    $stmt = $conn->query("SELECT COUNT(*) as total FROM personal WHERE empresa_id = " . $empresa_id_usuario . " AND activo = TRUE");
    $total_personal = $stmt->fetch()['total'];
    $stmt = $conn->query("SELECT COUNT(*) as total FROM servicios WHERE empresa_id = " . $empresa_id_usuario . " AND estado = 'activo'");
    $total_servicios = $stmt->fetch()['total'];
    $stmt = $conn->query("SELECT COUNT(*) as total FROM recursos_sucursal WHERE empresa_id = " . $empresa_id_usuario . " AND estado = 'aprobado'");
    $total_recursos = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $error = "Error al cargar los datos: " . $e->getMessage();
    $sucursales_usuario = [];
    $documentos_recientes = [];
    $total_sucursales = 0;
    $total_personal = 0;
    $total_servicios = 0;
    $total_recursos = 0;
}
$success = $_SESSION['success'] ?? '';
$error_msg = $_SESSION['error'] ?? $error ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Sucursales - Sistema de Seguridad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/sweetalert2.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
    /* ✅ ESTILOS ESPECÍFICOS DE GESTIÓN DOCUMENTOS SUCURSALES */
    .main-content-wrapper {
        margin-left: 280px;
        padding-top: 100px;
        padding-left: 30px;
        padding-right: 30px;
        transition: margin-left 0.35s ease, padding 0.35s ease;
        min-height: calc(100vh - 100px);
        width: calc(100% - 280px);
    }

    body.sidebar-collapsed .main-content-wrapper {
        margin-left: 0;
        width: 100%;
    }

    /* ✅ TABLET (992px - 1199px) */
    @media (min-width: 992px) and (max-width: 1199px) {
        .main-content-wrapper {
            padding-left: 20px;
            padding-right: 20px;
        }
    }

    /* ✅ NOTEBOOK/LAPTOP (1200px - 1440px) */
    @media (min-width: 1200px) and (max-width: 1440px) {
        .main-content-wrapper {
            padding-left: 25px;
            padding-right: 25px;
        }
    }

    /* ✅ PC ESCRITORIO (> 1441px) */
    @media (min-width: 1441px) {
        .main-content-wrapper {
            padding-left: 40px;
            padding-right: 40px;
        }
    }

    /* ✅ CELULAR ANDROID/MOBIL (< 991px) */
    @media (max-width: 991px) {
        .main-content-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
            padding-left: 15px;
            padding-right: 15px;
            padding-top: 80px;
        }
    }

    /* ✅ CELULARS PEQUEÑOS (< 576px) */
    @media (max-width: 576px) {
        .main-content-wrapper {
            padding-left: 10px;
            padding-right: 10px;
            padding-top: 70px;
        }
    }

    /* ✅ TRANSICIONES SUAVES */
    .main-content-wrapper,
    .sidebar-moderno {
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* ✅ PREVENIR SCROLL HORIZONTAL */
    body {
        overflow-x: hidden;
    }

    .container-fluid {
        max-width: 100%;
        padding-left: 15px;
        padding-right: 15px;
    }

    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        background: #f8f9fa; 
    }
    .dashboard { 
        display: flex; 
        min-height: calc(100vh - 80px); 
        padding-top: 20px; 
    }
    .stats-container-modern { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
        gap: 25px; 
        margin: 30px 0 40px; 
    }
    .stat-card-modern { 
        background: white; 
        border-radius: 24px; 
        padding: 28px; 
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); 
        border: 1px solid rgba(0, 0, 0, 0.05); 
        transition: transform 0.3s, box-shadow 0.3s; 
    }
    .stat-card-modern:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18); 
    }
    .stat-icon { 
        width: 60px; 
        height: 60px; 
        border-radius: 15px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 1.8rem; 
        margin-bottom: 15px; 
    }
    .stat-number { 
        font-size: 2rem; 
        font-weight: 700; 
        color: #2c3e50; 
    }
    .stat-label { 
        color: #6c757d; 
        font-size: 0.9rem; 
        text-transform: uppercase; 
    }
    .report-section { 
        background: white; 
        border-radius: 24px; 
        padding: 35px; 
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1); 
        margin-bottom: 30px; 
    }
    .report-header { 
        display: flex; 
        align-items: center; 
        gap: 20px; 
        margin-bottom: 30px; 
        padding-bottom: 20px; 
        border-bottom: 2px solid #e9ecef; 
    }
    .report-icon { 
        width: 70px; 
        height: 70px; 
        background: linear-gradient(135deg, #4361ee, #3a0ca3); 
        border-radius: 20px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 2rem; 
        color: white; 
    }
    .btn-generate { 
        background: linear-gradient(135deg, #4361ee, #3a0ca3); 
        border: none; 
        border-radius: 14px; 
        padding: 14px 32px; 
        font-weight: 700; 
        transition: all 0.3s; 
        box-shadow: 0 6px 20px rgba(67, 97, 238, 0.35); 
    }
    .btn-generate:hover { 
        transform: translateY(-3px); 
        box-shadow: 0 10px 30px rgba(67, 97, 238, 0.5); 
    }
    .form-control-modern { 
        border-radius: 12px; 
        border: 2px solid #e9ecef; 
        padding: 12px 16px; 
        transition: all 0.3s; 
    }
    .form-control-modern:focus { 
        border-color: #4361ee; 
        box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15); 
    }
    .empresa-info-badge { 
        background: linear-gradient(135deg, #4361ee, #3a0ca3); 
        color: white; 
        padding: 15px 25px; 
        border-radius: 15px; 
        margin-bottom: 25px; 
        display: flex; 
        align-items: center; 
        gap: 15px; 
    }
    .empresa-info-badge i { 
        font-size: 2rem; 
    }
    .empresa-info-badge .empresa-nombre { 
        font-size: 1.3rem; 
        font-weight: 700; 
    }
    .empresa-info-badge .empresa-label { 
        font-size: 0.85rem; 
        opacity: 0.9; 
    }
    .upload-section { 
        background: linear-gradient(135deg, #f8f9fa, #e9ecef); 
        border-radius: 20px; 
        padding: 30px; 
        margin-top: 30px; 
        border: 2px dashed #4361ee; 
    }
    .upload-area { 
        border: 3px dashed #4361ee; 
        border-radius: 15px; 
        padding: 40px; 
        text-align: center; 
        background: white; 
        transition: all 0.3s; 
        cursor: pointer; 
    }
    .upload-area:hover { 
        border-color: #3a0ca3; 
        background: linear-gradient(135deg, #e3f2fd, #bbdefb); 
        transform: translateY(-3px); 
    }
    .upload-area i { 
        font-size: 3.5rem; 
        color: #4361ee; 
        margin-bottom: 15px; 
    }
    .upload-area.has-file { 
        border-color: #27ae60; 
        background: linear-gradient(135deg, #e8f8f5, #d5f5e3); 
    }
    .upload-area.has-file i { 
        color: #27ae60; 
    }
    .file-info { 
        background: white; 
        border-radius: 10px; 
        padding: 15px; 
        margin-top: 15px; 
        display: none; 
        align-items: center; 
        gap: 15px; 
        box-shadow: 0 3px 15px rgba(0,0,0,0.1); 
    }
    .file-info.show { 
        display: flex; 
    }
    .file-info i { 
        font-size: 2.5rem; 
        color: #dc3545; 
    }
    .file-name { 
        flex: 1; 
        font-weight: 600; 
        color: #2c3e50; 
    }
    .file-size { 
        font-size: 0.85rem; 
        color: #7f8c8d; 
    }
    .documentos-list { 
        background: white; 
        border-radius: 15px; 
        padding: 20px; 
        margin-top: 20px; 
    }
    .documento-item { 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        padding: 15px; 
        border-bottom: 1px solid #e9ecef; 
        transition: all 0.3s; 
    }
    .documento-item:hover { 
        background: #f8f9fa; 
    }
    .documento-item:last-child { 
        border-bottom: none; 
    }
    .documento-icon { 
        width: 50px; 
        height: 50px; 
        background: linear-gradient(135deg, #dc3545, #c82333); 
        border-radius: 10px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        color: white; 
        font-size: 1.5rem; 
    }
    .documento-info { 
        flex: 1; 
    }
    .documento-nombre { 
        font-weight: 600; 
        color: #2c3e50; 
        margin-bottom: 5px; 
    }
    .documento-meta { 
        font-size: 0.85rem; 
        color: #7f8c8d; 
    }
    .documento-estado { 
        padding: 5px 12px; 
        border-radius: 20px; 
        font-size: 0.75rem; 
        font-weight: 700; 
    }
    .estado-pendiente { 
        background: linear-gradient(135deg, #f39c12, #e67e22); 
        color: white; 
    }
    .estado-aprobado { 
        background: linear-gradient(135deg, #27ae60, #219653); 
        color: white; 
    }
    .estado-rechazado { 
        background: linear-gradient(135deg, #e74c3c, #c0392b); 
        color: white; 
    }

    /* ✅ RESPONSIVE PARA LISTA DE DOCUMENTOS */
    @media (max-width: 768px) {
        .documento-item {
            flex-direction: column;
            align-items: flex-start;
        }
        .documento-info {
            width: 100%;
        }
        .documento-estado {
            margin-top: 10px;
        }
        .stats-container-modern {
            grid-template-columns: 1fr;
        }
        .report-section {
            padding: 20px;
        }
    }
    </style>
</head>
<body>
<!-- ✅ HEADER (primero) -->
<?php include '../includes/header_empresa.php'; ?>
<!-- ✅ SIDEBAR (después del header) -->
<?php include '../includes/sidebar_empresa.php'; ?>

<!-- ✅ CONTENIDO PRINCIPAL WRAPPER -->
<div class="main-content-wrapper">
<div class="dashboard">
<div class="container-fluid mt-4">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_msg); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- INFORMACIÓN DE LA EMPRESA -->
<?php if ($empresa_id_usuario > 0 && !$es_super_admin): ?>
<div class="empresa-info-badge">
    <i class="fas fa-building"></i>
    <div>
        <div class="empresa-label">Empresa Actual</div>
        <div class="empresa-nombre">
            <?php
            $stmt_empresa = $conn->prepare("SELECT nombre FROM empresas WHERE id = :id");
            $stmt_empresa->execute([':id' => $empresa_id_usuario]);
            $empresa_data = $stmt_empresa->fetch();
            echo htmlspecialchars($empresa_data['nombre'] ?? 'N/A');
            ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ESTADÍSTICAS -->
<div class="stats-container-modern">
    <div class="stat-card-modern">
        <div class="stat-icon" style="background: linear-gradient(135deg, #4361ee, #3a0ca3);">
            <i class="fas fa-store"></i>
        </div>
        <div class="stat-number"><?php echo $total_sucursales; ?></div>
        <div class="stat-label">Total Sucursales</div>
    </div>
    <div class="stat-card-modern">
        <div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #219653);">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-number"><?php echo $total_personal; ?></div>
        <div class="stat-label">Personal Activo</div>
    </div>
    <div class="stat-card-modern">
        <div class="stat-icon" style="background: linear-gradient(135deg, #f39c12, #d35400);">
            <i class="fas fa-concierge-bell"></i>
        </div>
        <div class="stat-number"><?php echo $total_servicios; ?></div>
        <div class="stat-label">Servicios Activos</div>
    </div>
    <div class="stat-card-modern">
        <div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-number"><?php echo $total_recursos; ?></div>
        <div class="stat-label">Recursos Aprobados</div>
    </div>
</div>

<!-- FORMULARIO DE GENERACIÓN DE INFORME -->
<div class="report-section">
    <div class="report-header">
        <div class="report-icon">
            <i class="fas fa-file-pdf"></i>
        </div>
        <div>
            <h2 class="mb-1">Generar Informe de Sucursales</h2>
            <p class="text-muted mb-0">Complete los filtros y genere el reporte PDF completo</p>
        </div>
    </div>
    <form method="GET" action="gestion_documentos_sucursales.php">
        <input type="hidden" name="generar_pdf" value="1">
        <div class="row g-4">
            <div class="col-md-6">
                <label class="form-label fw-bold"><i class="fas fa-store me-2"></i>Sucursal</label>
                <select name="filtro_sucursal" class="form-control-modern form-select" id="filtroSucursal">
                    <option value="0">Todas las sucursales</option>
                    <?php foreach ($sucursales_usuario as $sucursal): ?>
                    <option value="<?php echo $sucursal['id']; ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><i class="fas fa-info-circle"></i> Solo sucursales de su empresa</small>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold"><i class="fas fa-calendar me-2"></i>Fecha del Reporte</label>
                <input type="date" name="fecha_reporte" class="form-control-modern form-control" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold"><i class="fas fa-clock me-2"></i>Hora de Generación</label>
                <input type="text" class="form-control-modern form-control" value="<?php echo date('H:i:s'); ?>" readonly>
            </div>
        </div>
        <div class="mt-4 d-flex gap-3">
            <button type="submit" class="btn btn-generate btn-lg">
                <i class="fas fa-file-pdf me-2"></i>Generar Informe PDF
            </button>
            <a href="gestion_documentos_sucursales.php" class="btn btn-secondary btn-lg">
                <i class="fas fa-redo me-2"></i>Limpiar Filtros
            </a>
        </div>
    </form>
</div>

<!-- SECCIÓN DE CARGA DE DOCUMENTOS PDF -->
<div class="report-section upload-section">
    <div class="report-header">
        <div class="report-icon" style="background: linear-gradient(135deg, #27ae60, #219653);">
            <i class="fas fa-cloud-upload-alt"></i>
        </div>
        <div>
            <h2 class="mb-1">Cargar Documento PDF</h2>
            <p class="text-muted mb-0">Suba documentación complementaria para las sucursales</p>
        </div>
    </div>
    <form method="POST" action="gestion_documentos_sucursales.php" enctype="multipart/form-data" id="formCargaPDF">
        <input type="hidden" name="cargar_pdf" value="1">
        <div class="row g-4">
            <div class="col-md-6">
                <label class="form-label fw-bold"><i class="fas fa-file-pdf me-2"></i>Seleccionar PDF</label>
                <div class="upload-area" id="uploadArea" onclick="document.getElementById('pdfInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h5 class="mb-2">Haga clic para seleccionar archivo</h5>
                    <p class="text-muted mb-0">PDF - Máximo 10MB</p>
                    <input type="file" id="pdfInput" name="pdf_documento" class="d-none" accept=".pdf,application/pdf" required onchange="previewFile(this)">
                </div>
                <div class="file-info" id="fileInfo">
                    <i class="fas fa-file-pdf"></i>
                    <div class="file-name" id="fileName"></div>
                    <div class="file-size" id="fileSize"></div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearFile()">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold"><i class="fas fa-tag me-2"></i>Tipo de Documento</label>
                <select name="tipo_documento" class="form-control-modern form-select" required>
                    <option value="">Seleccione el tipo...</option>
                    <option value="personal">👥 Documentación de Personal</option>
                    <option value="servicios">🔔 Documentación de Servicios</option>
                    <option value="recursos">📦 Documentación de Recursos</option>
                    <option value="sucursal">🏢 Documentación de Sucursal</option>
                    <option value="certificacion">✅ Certificaciones</option>
                    <option value="informe">✅ Informes</option>
                    <option value="otro">📄 Otro Documento</option>
                </select>
                <!-- ✅ NUEVO: Selector de Sucursal -->
                <label class="form-label fw-bold mt-3"><i class="fas fa-store me-2"></i>Sucursal</label>
                <select name="sucursal_id" class="form-control-modern form-select" required>
                    <option value="0">General / Todas las Sucursales</option>
                    <?php foreach ($sucursales_usuario as $s): ?>
                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label fw-bold mt-3"><i class="fas fa-sticky-note me-2"></i>Observaciones</label>
                <textarea name="observaciones" class="form-control-modern form-control" rows="3" placeholder="Describa el documento (opcional)..."></textarea>
            </div>
        </div>
        <div class="mt-4 d-flex gap-3">
            <button type="submit" class="btn btn-lg" style="background: linear-gradient(135deg, #27ae60, #219653); color: white; border: none; border-radius: 14px; padding: 14px 32px; font-weight: 700; box-shadow: 0 6px 20px rgba(39, 174, 96, 0.35);">
                <i class="fas fa-upload me-2"></i>Cargar Documento
            </button>
            <button type="reset" class="btn btn-secondary btn-lg" onclick="clearFile()">
                <i class="fas fa-undo me-2"></i>Limpiar
            </button>
        </div>
    </form>

    <!-- DOCUMENTOS RECIENTES -->
    <?php if (count($documentos_recientes) > 0): ?>
    <div class="documentos-list">
        <h5 class="mb-3"><i class="fas fa-history me-2"></i>Documentos Recientes</h5>
        <?php foreach ($documentos_recientes as $doc): ?>
        <div class="documento-item">
            <div class="documento-icon">
                <i class="fas fa-file-pdf"></i>
            </div>
            <div class="documento-info">
                <div class="documento-nombre">
                    <i class="fas fa-file-pdf me-2"></i>
                    <?php echo basename($doc['archivo_pdf']); ?>
                </div>
                <div class="documento-meta">
                    <i class="fas fa-tag me-1"></i>
                    <?php
                    $tipos = [
                        'personal' => 'Personal',
                        'servicios' => 'Servicios',
                        'recursos' => 'Recursos',
                        'sucursal' => 'Sucursal',
                        'certificacion' => 'Certificación',
                        'informes' => 'Informes',
                        'otro' => 'Otro'
                    ];
                    echo $tipos[$doc['tipo_documento']] ?? $doc['tipo_documento'];
                    ?>
                    <!-- ✅ NUEVO: Mostrar Sucursal si existe -->
                    <?php if (!empty($doc['sucursal_nombre'])): ?>
                    <span class="mx-2">|</span>
                    <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($doc['sucursal_nombre']); ?>
                    <?php endif; ?>
                    <span class="mx-2">|</span>
                    <i class="fas fa-calendar me-1"></i><?php echo date('d/m/Y H:i', strtotime($doc['fecha_carga'])); ?>
                </div>
            </div>
            <div>
                <span class="documento-estado estado-<?php echo $doc['estado']; ?>">
                    <?php echo strtoupper($doc['estado']); ?>
                </span>
            </div>
            <div>
                <a href="../<?php echo htmlspecialchars($doc['archivo_pdf']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-eye"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>
</div>
</div>

<!-- ✅ SCRIPT UNIFICADO PARA TOGGLE SIDEBAR -->
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function previewFile(input) {
    const file = input.files[0];
    if (file) {
        if (file.type !== 'application/pdf') {
            Swal.fire({
                icon: 'error',
                title: 'Archivo No Válido',
                text: 'Solo se permiten archivos PDF',
                confirmButtonColor: '#4361ee'
            });
            input.value = '';
            clearFile();
            return;
        }
        const maxSize = 10 * 1024 * 1024;
        if (file.size > maxSize) {
            Swal.fire({
                icon: 'error',
                title: 'Archivo Muy Grande',
                text: 'El archivo no puede superar los 10MB',
                confirmButtonColor: '#4361ee'
            });
            input.value = '';
            clearFile();
            return;
        }
        const uploadArea = document.getElementById('uploadArea');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        uploadArea.classList.add('has-file');
        fileInfo.classList.add('show');
        fileName.textContent = file.name;
        fileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
    }
}

function clearFile() {
    const input = document.getElementById('pdfInput');
    const uploadArea = document.getElementById('uploadArea');
    const fileInfo = document.getElementById('fileInfo');
    input.value = '';
    uploadArea.classList.remove('has-file');
    fileInfo.classList.remove('show');
}

document.getElementById('formCargaPDF')?.addEventListener('submit', function(e) {
    const input = document.getElementById('pdfInput');
    const tipoDoc = this.querySelector('select[name="tipo_documento"]');
    if (!input.files || !input.files[0]) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Archivo Requerido',
            text: 'Por favor seleccione un archivo PDF',
            confirmButtonColor: '#4361ee'
        });
        return false;
    }
    if (!tipoDoc.value) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Tipo Requerido',
            text: 'Por favor seleccione el tipo de documento',
            confirmButtonColor: '#4361ee'
        });
        return false;
    }
    e.preventDefault();
    const form = this;
    Swal.fire({
        title: '<i class="fas fa-upload"></i> ¿Cargar Documento?',
        html: `
        <div class="text-center">
            <p class="mb-2">Se cargará el archivo:</p>
            <h5 class="text-primary fw-bold">${input.files[0].name}</h5>
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle"></i>
                El documento quedará <strong>pendiente de revisión</strong>
            </div>
        </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#95a5a6',
        confirmButtonText: '<i class="fas fa-check"></i> Sí, Cargar',
        cancelButtonText: '<i class="fas fa-times"></i> Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Cargando...',
                html: 'Por favor espere mientras se sube el documento',
                timer: 2000,
                timerProgressBar: true,
                didOpen: () => { Swal.showLoading() },
                allowOutsideClick: false
            }).then(() => {
                form.submit();
            });
        }
    });
});

// ✅ Toggle Sidebar con Persistencia de Estado
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleSidebarBtn');
    const toggleIcon = document.getElementById('toggleIcon');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;
    const sidebar = document.querySelector('.sidebar-moderno');
    
    // ✅ RESTAURAR ESTADO GUARDADO AL CARGAR
    const savedState = localStorage.getItem('sidebarCollapsed');
    const isMobile = window.innerWidth <= 991;
    
    if (!isMobile && savedState === 'true') {
        body.classList.add('sidebar-collapsed');
        if (toggleBtn) toggleBtn.classList.add('rotated');
        if (toggleIcon) toggleIcon.className = 'fas fa-indent';
    } else if (!isMobile) {
        body.classList.remove('sidebar-collapsed');
        if (toggleBtn) toggleBtn.classList.remove('rotated');
        if (toggleIcon) toggleIcon.className = 'fas fa-bars';
    }
    
    function toggleSidebar() {
        if (window.innerWidth <= 991) {
            // Mobile
            body.classList.toggle('sidebar-mobile-open');
            body.style.overflow = body.classList.contains('sidebar-mobile-open') ? 'hidden' : '';
            if (toggleIcon) {
                toggleIcon.className = body.classList.contains('sidebar-mobile-open')
                    ? 'fas fa-times'
                    : 'fas fa-bars';
            }
        } else {
            // Desktop
            body.classList.toggle('sidebar-collapsed');
            if (toggleBtn) toggleBtn.classList.toggle('rotated');
            if (toggleIcon) {
                toggleIcon.className = body.classList.contains('sidebar-collapsed')
                    ? 'fas fa-indent'
                    : 'fas fa-bars';
            }
            // ✅ GUARDAR ESTADO EN LOCALSTORAGE
            localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
        }
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            body.classList.remove('sidebar-mobile-open');
            body.style.overflow = '';
            if (toggleIcon) toggleIcon.className = 'fas fa-bars';
        });
    }
    
    // ✅ DETECTAR CAMBIO DE TAMAÑO DE VENTANA
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const isNowMobile = window.innerWidth <= 991;
            if (isNowMobile) {
                body.classList.remove('sidebar-collapsed');
                body.classList.remove('sidebar-mobile-open');
                body.style.overflow = '';
            }
        }, 250);
    });
});
</script>
</body>
</html>