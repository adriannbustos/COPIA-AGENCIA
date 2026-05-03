<?php
session_start();
// Incluir configuración de base de datos y autenticación
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
$user['id'] = $_SESSION['user_id'];
$conn = getDBConnection();
// Verificar autenticación y rol estricto
if (!$auth->isLoggedIn() || !$auth->hasRole('empresa')) {
    logAuditoria($conn, 'ACCESO_NO_AUTORIZADO', 'sucursales', null, [
        'intento_acceso' => 'gestion_sucursales',
        'rol_requerido' => 'empresa',
        'usuario_id' => $auth->getCurrentUser()['id'] ?? null
    ]);
    header('Location: ../login.php');
    exit;
}
$user = $auth->getCurrentUser();
$empresa_id = $user['empresa_id'];
$error = '';
$success = '';
$sucursal_seleccionada = null;

// ==================== GENERACIÓN DE PDF INTEGRADA ====================
if (isset($_GET['generar_pdf']) && isset($_GET['id'])) {
    if (!$auth->isLoggedIn() || !$auth->hasRole('empresa')) {
        die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2><p style="text-align:center"><a href="gestion_sucursales.php">← Volver</a></p>');
    }
    $sucursal_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
    $stmt = $conn->prepare("
    SELECT s.*, e.nombre as empresa_nombre, e.cuit, e.domicilio as empresa_domicilio,
    e.localidad as empresa_localidad, e.telefono as empresa_telefono,
    e.email as empresa_email, e.razon_social
    FROM sucursales s
    LEFT JOIN empresas e ON s.empresa_id = e.id
    WHERE s.id = :id AND s.empresa_id = :empresa_id
    ");
    $stmt->execute(['id' => $sucursal_id, 'empresa_id' => $empresa_id]);
    $sucursal = $stmt->fetch();
    if (!$sucursal) {
        die('<h2 style="color:red;text-align:center;padding:50px">❌ Sucursal no encontrada o no pertenece a su empresa</h2><p style="text-align:center"><a href="gestion_sucursales.php">← Volver</a></p>');
    }
    logAuditoria($conn, 'GENERACION_PDF_SUCURSAL', 'sucursales', $sucursal_id, [
        'sucursal' => $sucursal['nombre'],
        'empresa' => $sucursal['empresa_nombre'],
        'formato' => 'PDF',
        'fecha_generacion' => date('Y-m-d H:i:s'),
        'usuario_tipo' => 'empresa'
    ], $user['id']);
    $esta_habilitada = $sucursal['activa'] && $sucursal['en_funcionamiento'] && $sucursal['pago_arancel'];
    try {
        $stmt = $conn->query("SELECT jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path FROM config_credenciales WHERE id = 1");
        $config_jefe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config_jefe) {
            $config_jefe = [
                'jefe_apellido' => 'Apellido',
                'jefe_nombre' => 'Nombre',
                'jefe_gerarquia' => 'Jerarquía del Jefe',
                'firma_path' => null
            ];
        }
    } catch(PDOException $e) {
        $config_jefe = [
            'jefe_apellido' => 'Apellido',
            'jefe_nombre' => 'Nombre',
            'jefe_gerarquia' => 'Jerarquía del Jefe',
            'firma_path' => null
        ];
    }
    $firma_path = null;
    $escudo_path = '../uploads/fondos_credenciales/escudo.png';
    $firma_valida = false;
    $escudo_valido = false;
    if (!empty($config_jefe['firma_path']) && file_exists('../uploads/firmas_jefe/' . $config_jefe['firma_path'])) {
        $firma_path = '../uploads/firmas_jefe/' . $config_jefe['firma_path'];
        $info = @getimagesize($firma_path);
        if ($info !== false && $info[2] === IMAGETYPE_PNG) {
            $firma_valida = true;
        }
    }
    if (file_exists($escudo_path)) {
        $info = @getimagesize($escudo_path);
        if ($info !== false && $info[2] === IMAGETYPE_PNG) {
            $escudo_valido = true;
        }
    }
    if (!file_exists('../vendor/fpdf/fpdf.php')) {
        die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
    }
    require_once '../vendor/fpdf/fpdf.php';
    class PDF extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(0, 100, 0);
            $this->Cell(0, 10, 'POLICIA DE CHUBUT', 0, 1, 'C');
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 6, 'Area Investigaciones (D.S.) - Agencias Privadas de Seguridad', 0, 1, 'C');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(5);
            $this->SetDrawColor(0, 100, 0);
            $this->Line(10, 30, 200, 30);
            $this->Ln(5);
        }
        function Footer() {
            $this->SetY(-25);
            $this->SetDrawColor(150, 150, 150);
            $this->Rect(10, 270, 190, 20);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 5, 'DOCUMENTO OFICIAL - VALIDADO ELECTRONICAMENTE', 0, 1, 'C');
            $this->Cell(0, 5, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
            $this->Cell(0, 5, 'Sistema de Gestion de Seguridad - Policia de Chubut', 0, 1, 'C');
        }
    }
    $pdf = new PDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, 'Nombre de la Agencia Privada:', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 6, $sucursal['empresa_nombre'], 0, 1);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(30, 6, 'C.U.I.T.:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $sucursal['cuit'] ?? 'No registrado', 0, 1);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, 'Domicilio Legal:', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $sucursal['empresa_domicilio'] . ' - ' . $sucursal['empresa_localidad'], 0, 1);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, 'Domicilio Sucursal:', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $sucursal['domicilio'] . ' - ' . $sucursal['localidad'], 0, 1);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(30, 6, 'Telefono:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(70, 6, $sucursal['telefono'] ?? $sucursal['empresa_telefono'] ?? 'No registrado', 0, 0, 'L');
    $pdf->Cell(30, 6, 'Email:', 0, 0, 'L');
    $pdf->Cell(0, 6, $sucursal['email'] ?? $sucursal['empresa_email'] ?? 'No registrado', 0, 1, 'L');
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(35, 6, 'Jurisdiccion:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(60, 6, $sucursal['jurisdiccion'] ?? 'No registrada', 0, 0, 'L');
    $pdf->Cell(30, 6, 'Localidad:', 0, 0, 'L');
    $pdf->Cell(0, 6, $sucursal['localidad'], 0, 1, 'L');
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 6, 'Resolucion Municipal:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $sucursal['numero_resolucion'] ?? 'No registrada', 0, 1, 'L');
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 6, 'Fecha de Habilitacion:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $sucursal['fecha_habilitacion'] ? date('d/m/Y', strtotime($sucursal['fecha_habilitacion'])) : 'No registrada', 0, 1, 'L');
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(230, 230, 250);
    $pdf->Cell(0, 8, 'Documentos y Certificaciones', 0, 1, 'L', true);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, ' RENAR: ' . ($sucursal['renar'] ? 'Presente ' : 'No Presente '), 0, 1, 'L');
    $pdf->Cell(0, 6, ' Certificado de Cumplimiento: ' . ($sucursal['certificado_cumplimiento'] ? 'Presente ' : 'No Presente '), 0, 1, 'L');
    $pdf->Cell(0, 6, ' Habilitacion Comercial: ' . ($sucursal['habilitacion_comercial'] ? 'Presente ' : 'No Presente '), 0, 1, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(230, 250, 230);
    $pdf->Cell(0, 8, 'Estado de la Sucursal', 0, 1, 'L', true);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 11);
    if ($sucursal['en_funcionamiento']) {
        $pdf->SetTextColor(0, 128, 0);
        $pdf->Cell(0, 6, ' La agencia privada se encuentra en funcionamiento', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(0, 6, ' La agencia privada NO se encuentra en funcionamiento', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Ln(2);
    if ($sucursal['pago_arancel']) {
        $pdf->SetTextColor(0, 128, 0);
        $pdf->Cell(0, 6, ' Se ha registrado el pago correspondiente al arancel de Habilitacion anual', 0, 1, 'L');
        if (!empty($sucursal['fecha_pago_arancel'])) {
            $fecha_pago = new DateTime($sucursal['fecha_pago_arancel']);
            $fecha_actual = new DateTime();
            $diferencia = $fecha_actual->diff($fecha_pago);
            $dias_transcurridos = $diferencia->days;
            if ($dias_transcurridos > 380) {
                $pdf->SetTextColor(255, 0, 0);
                $pdf->Cell(0, 6, ' ARANCEL VENCIDO: Han pasado ' . $dias_transcurridos . ' dias desde el ultimo pago (>380 dias)', 0, 1, 'L');
                $pdf->SetTextColor(0, 0, 0);
            } else {
                $pdf->Cell(0, 6, ' Fecha de pago: ' . date('d/m/Y', strtotime($sucursal['fecha_pago_arancel'])), 0, 1, 'L');
            }
        }
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(0, 6, ' No se han registrado pagos correspondientes al arancel de Habilitacion anual hasta la fecha actual', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Ln(3);
    if (!empty($sucursal['responsable_nombre'])) {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, 'Responsable:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetFillColor(255, 255, 240);
        $pdf->MultiCell(0, 6, $sucursal['responsable_nombre'], 0, 'L', true);
        $pdf->Ln(3);
    }
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Ln(5);
    if ($esta_habilitada) {
        $pdf->SetTextColor(0, 128, 0);
        $pdf->Cell(0, 12, 'LA SUCURSAL SE ENCUENTRA HABILITADA', 0, 1, 'C');
    } else {
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(0, 12, 'LA SUCURSAL NO SE ENCUENTRA HABILITADA', 0, 1, 'C');
        $pdf->Cell(0, 12, 'NO ESTA APROBADA - NO ESTA ACTIVA - NO PAGO EL ARANCEL', 0, 1, 'C');
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(15);
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->Cell(0, 30, 'VERIFICACION ELECTRONICA', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'I', 12);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, 'Escanee el codigo QR para verificar el estado actualizado en tiempo real', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(10);
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domain = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['PHP_SELF']);
    $basePath = rtrim($protocol . $domain . $scriptPath, '/\\');
    $verify_url = $basePath . '/../verificar_sucursal.php?id=' . $sucursal_id;
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($verify_url);
    $qr_temp = sys_get_temp_dir() . '/qr_verify_' . $sucursal_id . '_' . time() . '.png';
    @file_put_contents($qr_temp, @file_get_contents($qr_url));
    if (file_exists($qr_temp)) {
        $pdf->Image($qr_temp, 55, $pdf->GetY(), 100, 100);
        $pdf->Ln(110);
        @unlink($qr_temp);
    } else {
        $pdf->Cell(0, 100, 'QR no disponible', 0, 1, 'C');
        $pdf->Ln(20);
    }
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Escanee para Verificar Estado Actual', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor(0, 100, 0);
    $pdf->Cell(0, 15, strtoupper($sucursal['empresa_nombre']), 0, 1, 'C');
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 10, 'Sucursal: ' . strtoupper($sucursal['nombre']), 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 14);
    if ($esta_habilitada) {
        $pdf->SetTextColor(0, 128, 0);
        $pdf->Cell(0, 10, 'SUCURSAL HABILITADA', 0, 1, 'C');
    } else {
        $pdf->SetTextColor(255, 0, 0);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Output('Certificado_Sucursal_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sucursal['nombre']) . '.pdf', 'I');
    exit;
}

// ==================== VERIFICAR TRÁMITES URGENTES ====================
$hay_urgencia = false;
try {
    $stmt = $conn->prepare("
    SELECT COUNT(*) as urgentes
    FROM tramites_empresa
    WHERE empresa_id = :empresa_id
    AND urgente = 1
    AND estado IN ('en_tramite', 'pendiente')
    ");
    $stmt->execute([':empresa_id' => $empresa_id]);
    $urgentes = $stmt->fetch(PDO::FETCH_ASSOC);
    $hay_urgencia = ($urgentes['urgentes'] > 0);
} catch(PDOException $e) {
    // Silencioso
}

// ==================== FILTRO POR TEXTO ====================
$filtro_texto = isset($_GET['filtro_texto']) && !empty($_GET['filtro_texto']) ? sanitizeInput($_GET['filtro_texto']) : '';

// ==================== FILTRO POR ESTADO DE APROBACIÓN ====================
$filtro_aprobacion = isset($_GET['filtro_aprobacion']) ? $_GET['filtro_aprobacion'] : 'pendiente';

// ==================== DIRECTORIOS DE SUBIDA ====================
$target_dir_cupones = "../uploads/cupones/";
$target_dir_resoluciones = "../uploads/resoluciones/";
$target_dir_pdf_sucursal = "../uploads/pdf_sucursal/";
$target_dir_fotos_sucursal = "../uploads/fotos_sucursal/";
if (!file_exists($target_dir_cupones)) mkdir($target_dir_cupones, 0777, true);
if (!file_exists($target_dir_resoluciones)) mkdir($target_dir_resoluciones, 0777, true);
if (!file_exists($target_dir_pdf_sucursal)) mkdir($target_dir_pdf_sucursal, 0777, true);
if (!file_exists($target_dir_fotos_sucursal)) mkdir($target_dir_fotos_sucursal, 0777, true);

// ==================== OBTENER TODAS LAS SUCURSALES DE LA EMPRESA ====================
try {
    $query = "
    SELECT s.*, e.nombre as empresa_nombre
    FROM sucursales s
    INNER JOIN empresas e ON s.empresa_id = e.id
    WHERE s.empresa_id = :empresa_id
    ";
    $params = [':empresa_id' => $empresa_id];
    if ($filtro_aprobacion === 'pendiente') {
        $query .= " AND (s.estado_aprobacion = 'pendiente' OR s.estado_aprobacion IS NULL)";
    } elseif ($filtro_aprobacion === 'aprobado') {
        $query .= " AND s.estado_aprobacion = 'aprobado'";
    } elseif ($filtro_aprobacion === 'rechazado') {
        $query .= " AND s.estado_aprobacion = 'rechazado'";
    }
    if (!empty($filtro_texto)) {
        $query .= " AND (s.nombre LIKE :filtro_texto OR s.domicilio LIKE :filtro_texto OR s.localidad LIKE :filtro_texto)";
        $params[':filtro_texto'] = "%{$filtro_texto}%";
    }
    $query .= " ORDER BY s.fecha_solicitud DESC, s.nombre";
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $sucursales_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    logAuditoria($conn, 'LISTADO_SUCURSALES', 'sucursales', null, [
        'empresa_id' => $empresa_id,
        'filtro_aprobacion' => $filtro_aprobacion,
        'filtro_texto' => $filtro_texto,
        'total_sucursales' => count($sucursales_list)
    ], $user['id']);
} catch(PDOException $e) {
    $error = 'Error al cargar las sucursales: ' . $e->getMessage();
    $sucursales_list = [];
    logAuditoria($conn, 'ERROR_LISTADO_SUCURSALES', 'sucursales', null, [
        'error' => $e->getMessage(),
        'empresa_id' => $empresa_id
    ], $user['id']);
}

// ==================== OBTENER DATOS DE LA SUCURSAL SELECCIONADA ====================
if (isset($_GET['sucursal_id']) && !empty($_GET['sucursal_id'])) {
    $sucursal_id = (int)$_GET['sucursal_id'];
    try {
        $stmt = $conn->prepare("
        SELECT s.*, e.nombre as empresa_nombre
        FROM sucursales s
        INNER JOIN empresas e ON s.empresa_id = e.id
        WHERE s.id = :sucursal_id AND s.empresa_id = :empresa_id
        ");
        $stmt->execute([':sucursal_id' => $sucursal_id, ':empresa_id' => $empresa_id]);
        $sucursal_seleccionada = $stmt->fetch();
        if (!$sucursal_seleccionada) {
            $error = 'La sucursal seleccionada no pertenece a su empresa';
            $sucursal_seleccionada = null;
            logAuditoria($conn, 'ACCESO_SUCURSAL_DENEGADO', 'sucursales', $sucursal_id, [
                'motivo' => 'No pertenece a la empresa',
                'empresa_id' => $empresa_id
            ], $user['id']);
        } else {
            logAuditoria($conn, 'VISUALIZAR_SUCURSAL', 'sucursales', $sucursal_id, [
                'nombre_sucursal' => $sucursal_seleccionada['nombre'],
                'estado_aprobacion' => $sucursal_seleccionada['estado_aprobacion'] ?? 'pendiente',
                'activa' => $sucursal_seleccionada['activa']
            ], $user['id']);
        }
    } catch(PDOException $e) {
        $error = 'Error al cargar los datos de la sucursal: ' . $e->getMessage();
        logAuditoria($conn, 'ERROR_VISUALIZAR_SUCURSAL', 'sucursales', $sucursal_id, [
            'error' => $e->getMessage()
        ], $user['id']);
    }
}

// ==================== PROCESAR ACTUALIZACIÓN DE DATOS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_sucursal'])) {
    $sucursal_id = (int)$_POST['sucursal_id'];
    $sucursal_data = null;
    $cambios_realizados = [];
    try {
        $check_stmt = $conn->prepare("
        SELECT s.id, s.empresa_id, s.cupon_pago, s.pdf_resolucion, s.pdf_sucursal,
        s.estado_aprobacion, s.telefono, s.email, s.responsable_id,
        s.fotos_uniforme, s.fotos_vehiculos
        FROM sucursales s
        WHERE s.id = :sucursal_id AND s.empresa_id = :empresa_id
        ");
        $check_stmt->execute([':sucursal_id' => $sucursal_id, ':empresa_id' => $empresa_id]);
        $sucursal_data = $check_stmt->fetch();
        if (!$sucursal_data) {
            $error = 'La sucursal seleccionada no pertenece a su empresa';
            $sucursal_seleccionada = null;
            logAuditoria($conn, 'ACTUALIZACION_DENEGADA', 'sucursales', $sucursal_id, [
                'motivo' => 'No pertenece a la empresa'
            ], $user['id']);
        }
    } catch(PDOException $e) {
        $error = 'Error al validar la sucursal: ' . $e->getMessage();
        logAuditoria($conn, 'ERROR_VALIDACION_SUCURSAL', 'sucursales', $sucursal_id, [
            'error' => $e->getMessage()
        ], $user['id']);
    }
    if ($sucursal_data) {
        try {
            $telefono = sanitizeInput($_POST['telefono'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
            $observaciones = sanitizeInput($_POST['observaciones'] ?? '');
            if ($telefono !== $sucursal_data['telefono']) {
                $cambios_realizados['telefono'] = ['anterior' => $sucursal_data['telefono'], 'nuevo' => $telefono];
            }
            if ($email !== $sucursal_data['email']) {
                $cambios_realizados['email'] = ['anterior' => $sucursal_data['email'], 'nuevo' => $email];
            }
            if ($responsable_id !== $sucursal_data['responsable_id']) {
                $cambios_realizados['responsable_id'] = ['anterior' => $sucursal_data['responsable_id'], 'nuevo' => $responsable_id];
            }
            // ==================== SUBIR CUPÓN DE PAGO ====================
            $cupon_pago_file = $sucursal_data['cupon_pago'];
            $cupon_cambiado = false;
            if (isset($_FILES['cupon_pago']) && $_FILES['cupon_pago']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['cupon_pago']['name'], PATHINFO_EXTENSION));
                if (!in_array($file_extension, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    throw new Exception('El cupón debe ser PDF, JPG, JPEG o PNG');
                }
                if ($_FILES['cupon_pago']['size'] > 5000000) {
                    throw new Exception('El cupón no debe superar los 5MB');
                }
                $empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
                $fecha_actual = date('Ymd');
                $new_filename = 'cupon_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $sucursal_data['id'] . '.' . $file_extension;
                $target_file = $target_dir_cupones . $new_filename;
                if (!empty($sucursal_data['cupon_pago']) && file_exists($target_dir_cupones . $sucursal_data['cupon_pago'])) {
                    unlink($target_dir_cupones . $sucursal_data['cupon_pago']);
                }
                if (move_uploaded_file($_FILES['cupon_pago']['tmp_name'], $target_file)) {
                    $cupon_pago_file = $new_filename;
                    $cupon_cambiado = true;
                    $cambios_realizados['cupon_pago'] = ['anterior' => $sucursal_data['cupon_pago'], 'nuevo' => $new_filename];
                    logAuditoria($conn, 'SUBIDA_CUPON_PAGO', 'sucursales', $sucursal_id, [
                        'archivo_anterior' => $sucursal_data['cupon_pago'],
                        'archivo_nuevo' => $new_filename,
                        'tipo_archivo' => $file_extension,
                        'tamano' => $_FILES['cupon_pago']['size']
                    ], $user['id']);
                } else {
                    throw new Exception('Error al subir el cupón');
                }
            }
            // ==================== SUBIR PDF RESOLUCIÓN ====================
            $pdf_resolucion_file = $sucursal_data['pdf_resolucion'];
            $resolucion_cambiada = false;
            if (isset($_FILES['pdf_resolucion']) && $_FILES['pdf_resolucion']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['pdf_resolucion']['name'], PATHINFO_EXTENSION));
                if ($file_extension !== 'pdf') {
                    throw new Exception('El archivo de resolución debe ser PDF');
                }
                if ($_FILES['pdf_resolucion']['size'] > 10000000) {
                    throw new Exception('El PDF no debe superar los 10MB');
                }
                $empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
                $fecha_actual = date('Ymd');
                $new_filename = 'resolucion_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $sucursal_data['id'] . '.pdf';
                $target_file = $target_dir_resoluciones . $new_filename;
                if (!empty($sucursal_data['pdf_resolucion']) && file_exists($target_dir_resoluciones . $sucursal_data['pdf_resolucion'])) {
                    unlink($target_dir_resoluciones . $sucursal_data['pdf_resolucion']);
                }
                if (move_uploaded_file($_FILES['pdf_resolucion']['tmp_name'], $target_file)) {
                    $pdf_resolucion_file = $new_filename;
                    $resolucion_cambiada = true;
                    $cambios_realizados['pdf_resolucion'] = ['anterior' => $sucursal_data['pdf_resolucion'], 'nuevo' => $new_filename];
                    logAuditoria($conn, 'SUBIDA_PDF_RESOLUCION', 'sucursales', $sucursal_id, [
                        'archivo_anterior' => $sucursal_data['pdf_resolucion'],
                        'archivo_nuevo' => $new_filename,
                        'tamano' => $_FILES['pdf_resolucion']['size']
                    ], $user['id']);
                } else {
                    throw new Exception('Error al subir el PDF de resolución');
                }
            }
            // ==================== SUBIR PDF SUCURSAL ====================
            $pdf_sucursal_file = $sucursal_data['pdf_sucursal'];
            $pdf_sucursal_cambiado = false;
            if (isset($_FILES['pdf_sucursal']) && $_FILES['pdf_sucursal']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['pdf_sucursal']['name'], PATHINFO_EXTENSION));
                if ($file_extension !== 'pdf') {
                    throw new Exception('El archivo PDF de la sucursal debe ser PDF');
                }
                if ($_FILES['pdf_sucursal']['size'] > 10000000) {
                    throw new Exception('El PDF no debe superar los 10MB');
                }
                $empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
                $fecha_actual = date('Ymd');
                $new_filename = 'sucursal_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $sucursal_data['id'] . '.pdf';
                $target_file = $target_dir_pdf_sucursal . $new_filename;
                if (!empty($sucursal_data['pdf_sucursal']) && file_exists($target_dir_pdf_sucursal . $sucursal_data['pdf_sucursal'])) {
                    unlink($target_dir_pdf_sucursal . $sucursal_data['pdf_sucursal']);
                }
                if (move_uploaded_file($_FILES['pdf_sucursal']['tmp_name'], $target_file)) {
                    $pdf_sucursal_file = $new_filename;
                    $pdf_sucursal_cambiado = true;
                    $cambios_realizados['pdf_sucursal'] = ['anterior' => $sucursal_data['pdf_sucursal'], 'nuevo' => $new_filename];
                    logAuditoria($conn, 'SUBIDA_PDF_SUCURSAL', 'sucursales', $sucursal_id, [
                        'archivo_anterior' => $sucursal_data['pdf_sucursal'],
                        'archivo_nuevo' => $new_filename,
                        'tamano' => $_FILES['pdf_sucursal']['size']
                    ], $user['id']);
                } else {
                    throw new Exception('Error al subir el PDF de la sucursal');
                }
            }
            // ==================== SUBIR FOTOS DE UNIFORMES ====================
            $fotos_uniforme_file = $sucursal_data['fotos_uniforme'] ?? '';
            $fotos_uniforme_cambiado = false;
            if (isset($_FILES['fotos_uniforme']) && $_FILES['fotos_uniforme']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['fotos_uniforme']['name'], PATHINFO_EXTENSION));
                if (!in_array($file_extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                    throw new Exception('Las fotos deben ser JPG, JPEG, PNG o WebP');
                }
                if ($_FILES['fotos_uniforme']['size'] > 10000000) {
                    throw new Exception('Las fotos no deben superar los 10MB');
                }
                $empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
                $new_filename = 'uniforme_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.' . $file_extension;
                $target_file = $target_dir_fotos_sucursal . $new_filename;
                if (!empty($sucursal_data['fotos_uniforme']) && file_exists($target_dir_fotos_sucursal . $sucursal_data['fotos_uniforme'])) {
                    unlink($target_dir_fotos_sucursal . $sucursal_data['fotos_uniforme']);
                }
                if (move_uploaded_file($_FILES['fotos_uniforme']['tmp_name'], $target_file)) {
                    $fotos_uniforme_file = $new_filename;
                    $fotos_uniforme_cambiado = true;
                    $cambios_realizados['fotos_uniforme'] = ['anterior' => $sucursal_data['fotos_uniforme'] ?? '', 'nuevo' => $new_filename];
                    logAuditoria($conn, 'SUBIDA_FOTOS_UNIFORME', 'sucursales', $sucursal_id, [
                        'archivo_anterior' => $sucursal_data['fotos_uniforme'] ?? '',
                        'archivo_nuevo' => $new_filename,
                        'tipo_archivo' => $file_extension,
                        'tamano' => $_FILES['fotos_uniforme']['size']
                    ], $user['id']);
                } else {
                    throw new Exception('Error al subir las fotos del uniforme');
                }
            }
            // ==================== SUBIR FOTOS DE VEHÍCULOS ====================
            $fotos_vehiculos_file = $sucursal_data['fotos_vehiculos'] ?? '';
            $fotos_vehiculos_cambiado = false;
            if (isset($_FILES['fotos_vehiculos']) && $_FILES['fotos_vehiculos']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['fotos_vehiculos']['name'], PATHINFO_EXTENSION));
                if (!in_array($file_extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                    throw new Exception('Las fotos deben ser JPG, JPEG, PNG o WebP');
                }
                if ($_FILES['fotos_vehiculos']['size'] > 10000000) {
                    throw new Exception('Las fotos no deben superar los 10MB');
                }
                $empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
                $new_filename = 'vehiculos_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.' . $file_extension;
                $target_file = $target_dir_fotos_sucursal . $new_filename;
                if (!empty($sucursal_data['fotos_vehiculos']) && file_exists($target_dir_fotos_sucursal . $sucursal_data['fotos_vehiculos'])) {
                    unlink($target_dir_fotos_sucursal . $sucursal_data['fotos_vehiculos']);
                }
                if (move_uploaded_file($_FILES['fotos_vehiculos']['tmp_name'], $target_file)) {
                    $fotos_vehiculos_file = $new_filename;
                    $fotos_vehiculos_cambiado = true;
                    $cambios_realizados['fotos_vehiculos'] = ['anterior' => $sucursal_data['fotos_vehiculos'] ?? '', 'nuevo' => $new_filename];
                    logAuditoria($conn, 'SUBIDA_FOTOS_VEHICULOS', 'sucursales', $sucursal_id, [
                        'archivo_anterior' => $sucursal_data['fotos_vehiculos'] ?? '',
                        'archivo_nuevo' => $new_filename,
                        'tipo_archivo' => $file_extension,
                        'tamano' => $_FILES['fotos_vehiculos']['size']
                    ], $user['id']);
                } else {
                    throw new Exception('Error al subir las fotos de los vehículos');
                }
            }
            // ==================== ACTUALIZAR EN BASE DE DATOS ====================
            $stmt = $conn->prepare("
            UPDATE sucursales SET
            telefono = :telefono,
            email = :email,
            responsable_id = :responsable_id,
            cupon_pago = :cupon_pago,
            pdf_resolucion = :pdf_resolucion,
            pdf_sucursal = :pdf_sucursal,
            fotos_uniforme = :fotos_uniforme,
            fotos_vehiculos = :fotos_vehiculos,
            fecha_carga_fotos = NOW(),
            estado_aprobacion = 'pendiente',
            fecha_solicitud = NOW(),
            updated_at = NOW()
            WHERE id = :id
            ");
            $stmt->execute([
                ':telefono' => $telefono,
                ':email' => $email,
                ':responsable_id' => $responsable_id,
                ':cupon_pago' => $cupon_pago_file,
                ':pdf_resolucion' => $pdf_resolucion_file,
                ':pdf_sucursal' => $pdf_sucursal_file,
                ':fotos_uniforme' => $fotos_uniforme_file,
                ':fotos_vehiculos' => $fotos_vehiculos_file,
                ':id' => $sucursal_id
            ]);
            logAuditoria($conn, 'ACTUALIZACION_SUCURSAL', 'sucursales', $sucursal_id, [
                'cambios' => $cambios_realizados,
                'estado_aprobacion' => 'pendiente',
                'cupon_cambiado' => $cupon_cambiado,
                'resolucion_cambiada' => $resolucion_cambiada,
                'pdf_sucursal_cambiado' => $pdf_sucursal_cambiado,
                'fotos_uniforme_cambiado' => $fotos_uniforme_cambiado,
                'fotos_vehiculos_cambiado' => $fotos_vehiculos_cambiado,
                'total_cambios' => count($cambios_realizados)
            ], $user['id']);
            $success = 'Datos actualizados. La modificación está pendiente de aprobación por el administrador.';
            $stmt = $conn->prepare("
            SELECT s.*, e.nombre as empresa_nombre
            FROM sucursales s
            INNER JOIN empresas e ON s.empresa_id = e.id
            WHERE s.id = :sucursal_id AND s.empresa_id = :empresa_id
            ");
            $stmt->execute([':sucursal_id' => $sucursal_id, ':empresa_id' => $empresa_id]);
            $sucursal_seleccionada = $stmt->fetch();
        } catch(Exception $e) {
            $error = $e->getMessage();
            logAuditoria($conn, 'ERROR_ACTUALIZACION_SUCURSAL', 'sucursales', $sucursal_id, [
                'error' => $e->getMessage(),
                'cambios_intentados' => $cambios_realizados
            ], $user['id']);
        }
    }
}

try {
    $stmt = $conn->prepare("
    SELECT id, nombre, apellido, dni, cargo
    FROM personal
    WHERE empresa_id = :empresa_id AND activo = 1
    ORDER BY apellido, nombre
    ");
    $stmt->execute([':empresa_id' => $empresa_id]);
    $personal_disponible = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $personal_disponible = [];
}

$pendientes_count = 0;
foreach ($sucursales_list as $s) {
    if (empty($s['estado_aprobacion']) || $s['estado_aprobacion'] === 'pendiente') {
        $pendientes_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Sucursales - Empresa</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ✅ ESTILOS ESPECÍFICOS DE GESTIÓN SUCURSALES */
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

.container {
    max-width: 100%;
    padding-left: 15px;
    padding-right: 15px;
}

/* ✅ ALERTA FLOTANTE DE URGENCIA */
.urgency-alert {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
    padding: 20px 25px;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4);
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 15px;
    max-width: 400px;
    animation: slideInRight 0.5s ease, pulse 2s infinite;
}
.urgency-alert i { font-size: 1.8rem; }
.urgency-alert-content h6 { margin: 0; font-weight: 700; font-size: 0.95rem; }
.urgency-alert-content p { margin: 5px 0 0; font-size: 0.85rem; opacity: 0.9; }
.urgency-alert-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.urgency-alert-close:hover { background: rgba(255,255,255,0.3); }
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4); }
    50% { box-shadow: 0 10px 60px rgba(220, 53, 69, 0.6); }
}
.card-shadow { box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15); }
.form-label { font-weight: 500; }
.upload-area {
    border: 3px dashed #4361ee;
    border-radius: 15px;
    padding: 30px;
    text-align: center;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    transition: all 0.3s ease;
    cursor: pointer;
}
.upload-area:hover {
    border-color: #3a0ca3;
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    transform: translateY(-3px);
}
.upload-area i { font-size: 3rem; color: #4361ee; margin-bottom: 15px; }
.upload-area-fotos {
    border: 3px dashed #4361ee;
    border-radius: 15px;
    padding: 20px;
    text-align: center;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    transition: all 0.3s ease;
    cursor: pointer;
}
.upload-area-fotos:hover {
    border-color: #3a0ca3;
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    transform: translateY(-3px);
}
.upload-area-fotos i { font-size: 2.5rem; color: #4361ee; margin-bottom: 10px; }
.foto-preview { max-width: 200px; max-height: 150px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
.file-status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 6px 15px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
.file-status-exists { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
.file-status-missing { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
.readonly-field { background: #e9ecef !important; cursor: not-allowed; opacity: 0.7; }
.section-card { background: white; border-radius: 15px; padding: 25px; margin-bottom: 20px; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); border-left: 4px solid #4361ee; transition: all 0.3s ease; }
.section-card:hover { box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12); transform: translateY(-2px); }
.section-card.restricted { border-left-color: #e74c3c; background: linear-gradient(135deg, #fdf2f2, #ffeaea); opacity: 0.85; }
.section-title { font-weight: 700; color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e9ecef; display: flex; align-items: center; gap: 10px; font-size: 1.1rem; }
.section-title i { color: #4361ee; width: 30px; height: 30px; background: linear-gradient(135deg, #4361ee, #3a0ca3); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; }
.section-card.restricted .section-title i { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.alert-restricted { background: linear-gradient(135deg, #fff3cd, #ffeaa7); border-left: 4px solid #e74c3c; color: #856404; border-radius: 10px; padding: 15px 20px; }
.lock-icon { color: #e74c3c; margin-right: 5px; }
.list-group-item-action { cursor: pointer; transition: all 0.3s ease; }
.list-group-item-action:hover { background: linear-gradient(135deg, #f8f9fa, #e9ecef); transform: translateX(5px); }
.list-group-item-action.active { background: linear-gradient(135deg, #4361ee, #3a0ca3); border-color: #4361ee; }
.sucursal-selected-indicator { background: linear-gradient(135deg, #27ae60, #219653); color: white; padding: 10px 15px; border-radius: 10px; margin-bottom: 15px; display: none; }
.sucursal-selected-indicator.show { display: block; animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
input[name="filtro_texto"] { font-family: 'Courier New', monospace; font-weight: 600; letter-spacing: 1px; }
input[name="filtro_texto"]:focus { border-color: #f39c12; box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.15); }
.search-box-modern { background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: 12px; padding: 15px; margin-bottom: 15px; }
.estado-badge { padding: 5px 12px; border-radius: 15px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.estado-activo { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
.estado-inactivo { background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: white; }
.aprobacion-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; }
.aprobacion-pendiente { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
.aprobacion-aprobado { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
.aprobacion-rechazado { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
.alert-aprobacion { border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; }
.alert-pendiente { background: linear-gradient(135deg, #fff3cd, #ffeaa7); border-left: 4px solid #f39c12; color: #856404; }
.alert-aprobado { background: linear-gradient(135deg, #d4edda, #c3e6cb); border-left: 4px solid #27ae60; color: #155724; }
.alert-rechazado { background: linear-gradient(135deg, #f8d7da, #f5c6cb); border-left: 4px solid #e74c3c; color: #721c24; }
.swal-popup-modern { border-radius: 20px !important; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3) !important; padding: 30px !important; max-width: 500px !important; }
.swal-title-modern { font-weight: 700 !important; font-size: 1.5rem !important; color: #2c3e50 !important; margin-bottom: 20px !important; display: flex !important; align-items: center !important; gap: 10px !important; }
.swal-title-modern i { color: #4361ee; font-size: 1.8rem; }
.swal-content-modern { text-align: center !important; padding: 10px 0 !important; }
.swal-message { font-size: 1rem !important; color: #555 !important; margin-bottom: 20px !important; display: flex !important; align-items: center !important; justify-content: center !important; gap: 8px !important; }
.swal-message i { color: #3498db; }
.swal-warning-box { background: linear-gradient(135deg, #fff3cd, #ffeaa7); border-left: 4px solid #f39c12; border-radius: 10px; padding: 15px 20px; display: flex; align-items: center; gap: 10px; margin-top: 15px; text-align: left; }
.swal-warning-box i { color: #f39c12; font-size: 1.2rem; flex-shrink: 0; }
.swal-warning-box span { color: #856404; font-size: 0.9rem; font-weight: 500; }
.swal-confirm-modern { background: linear-gradient(135deg, #27ae60, #219653) !important; border: none !important; border-radius: 10px !important; padding: 12px 30px !important; font-weight: 600 !important; font-size: 1rem !important; box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4) !important; transition: all 0.3s ease !important; }
.swal-confirm-modern:hover { transform: translateY(-2px) !important; box-shadow: 0 6px 20px rgba(39, 174, 96, 0.6) !important; }
.swal-cancel-modern { background: linear-gradient(135deg, #95a5a6, #7f8c8d) !important; border: none !important; border-radius: 10px !important; padding: 12px 30px !important; font-weight: 600 !important; font-size: 1rem !important; box-shadow: 0 4px 15px rgba(149, 165, 166, 0.4) !important; transition: all 0.3s ease !important; }
.swal-cancel-modern:hover { transform: translateY(-2px) !important; box-shadow: 0 6px 20px rgba(149, 165, 166, 0.6) !important; }

/* ✅ RESPONSIVE PARA LISTA DE SUCURSALES */
@media (max-width: 768px) {
    .list-group-item-action {
        padding: 10px 15px;
    }
    .list-group-item-action strong {
        font-size: 0.9rem;
    }
    .list-group-item-action small {
        font-size: 0.8rem;
    }
    .section-card {
        padding: 15px;
    }
    .upload-area {
        padding: 20px;
    }
    .upload-area i {
        font-size: 2rem;
    }
}
</style>
</head>
<body class="bg-light">
<?php include '../includes/header_empresa.php'; ?>
<?php include '../includes/sidebar_empresa.php'; ?>

<!-- ✅ CONTENIDO PRINCIPAL WRAPPER -->
<div class="main-content-wrapper">
<div class="container mt-4">
<div class="row">
<div class="col-md-4">
<div class="card card-shadow mb-4">
<div class="card-header bg-primary text-white">
<h5 class="mb-0">
<i class="fas fa-store"></i> Sucursales de la Empresa
<span class="badge bg-light text-primary"><?php echo count($sucursales_list); ?></span>
</h5>
</div>
<div class="card-body p-0">
<?php if ($sucursal_seleccionada): ?>
<div class="sucursal-selected-indicator show">
<i class="fas fa-store-check"></i>
<strong><?php echo htmlspecialchars($sucursal_seleccionada['nombre']); ?></strong>
<br><small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($sucursal_seleccionada['localidad']); ?></small>
</div>
<?php endif; ?>
<div class="p-3 bg-light border-bottom">
<form method="GET" action="" class="d-flex gap-2">
<select name="filtro_aprobacion" class="form-select form-select-sm" onchange="this.form.submit()">
<option value="pendiente" <?php echo $filtro_aprobacion === 'pendiente' ? 'selected' : ''; ?>>⏳ Pendientes (<?php echo $pendientes_count; ?>)</option>
<option value="aprobado" <?php echo $filtro_aprobacion === 'aprobado' ? 'selected' : ''; ?>>✅ Aprobadas</option>
<option value="rechazado" <?php echo $filtro_aprobacion === 'rechazado' ? 'selected' : ''; ?>>❌ Rechazadas</option>
<option value="todos" <?php echo $filtro_aprobacion === 'todos' ? 'selected' : ''; ?>>📋 Todas</option>
</select>
<?php if (!empty($filtro_texto)): ?>
<input type="hidden" name="filtro_texto" value="<?php echo htmlspecialchars($filtro_texto); ?>">
<?php endif; ?>
</form>
</div>
<div class="search-box-modern m-3">
<form method="GET" action="">
<label class="form-label small"><i class="fas fa-search me-1"></i> Buscar Sucursal</label>
<div class="input-group">
<span class="input-group-text"><i class="fas fa-search"></i></span>
<input type="text" name="filtro_texto" class="form-control" placeholder="Nombre, domicilio..." value="<?php echo htmlspecialchars($filtro_texto); ?>" maxlength="100">
<button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
<?php if (!empty($filtro_texto)): ?>
<a href="gestion_sucursales.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
<?php endif; ?>
</div>
<input type="hidden" name="filtro_aprobacion" value="<?php echo htmlspecialchars($filtro_aprobacion); ?>">
</form>
</div>
<div class="list-group list-group-flush">
<?php if (count($sucursales_list) > 0): ?>
<?php foreach ($sucursales_list as $s): ?>
<a href="?sucursal_id=<?php echo $s['id']; ?><?php echo !empty($filtro_texto) ? '&filtro_texto=' . urlencode($filtro_texto) : ''; ?>&filtro_aprobacion=<?php echo htmlspecialchars($filtro_aprobacion); ?>"
class="list-group-item list-group-item-action <?php echo $sucursal_seleccionada && $sucursal_seleccionada['id'] == $s['id'] ? 'active' : ''; ?>">
<div class="d-flex w-100 justify-content-between align-items-center">
<div>
<strong><?php echo htmlspecialchars($s['nombre']); ?></strong>
<br><small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($s['localidad']); ?></small>
</div>
<div class="text-end">
<?php $estado_aprobacion = $s['estado_aprobacion'] ?? 'pendiente'; ?>
<?php if ($estado_aprobacion === 'pendiente'): ?>
<span class="badge aprobacion-badge aprobacion-pendiente mb-1"><i class="fas fa-clock"></i> Pendiente</span>
<?php elseif ($estado_aprobacion === 'aprobado'): ?>
<span class="badge aprobacion-badge aprobacion-aprobado mb-1"><i class="fas fa-check"></i> Aprobado</span>
<?php elseif ($estado_aprobacion === 'rechazado'): ?>
<span class="badge aprobacion-badge aprobacion-rechazado mb-1"><i class="fas fa-times"></i> Rechazado</span>
<?php endif; ?>
<br>
<?php if ($s['activa']): ?>
<span class="badge bg-success">Activa</span>
<?php else: ?>
<span class="badge bg-secondary">Inactiva</span>
<?php endif; ?>
</div>
</div>
</a>
<?php endforeach; ?>
<?php else: ?>
<div class="list-group-item text-center py-4">
<i class="fas fa-inbox fa-2x text-muted"></i>
<p class="text-muted mb-0">No hay sucursales registradas</p>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
<div class="col-md-8">
<?php if ($sucursal_seleccionada): ?>
<div class="card card-shadow">
<div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
<h4 class="mb-0"><i class="fas fa-store-alt"></i> Gestionar Datos de la Sucursal</h4>
<div>
<span class="badge bg-light text-success me-2"><?php echo $sucursal_seleccionada['activa'] ? 'Activa' : 'Inactiva'; ?></span>
<a href="?generar_pdf=1&id=<?php echo $sucursal_seleccionada['id']; ?><?php echo !empty($filtro_texto) ? '&filtro_texto=' . urlencode($filtro_texto) : ''; ?>&filtro_aprobacion=<?php echo htmlspecialchars($filtro_aprobacion); ?>"
class="btn btn-light btn-sm"
target="_blank"
title="Generar Certificado PDF">
<i class="fas fa-file-pdf"></i> Descargar PDF
</a>
</div>
</div>
<div class="card-body">
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
<i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
<i class="fas fa-check-circle"></i> <?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php $estado_aprobacion = $sucursal_seleccionada['estado_aprobacion'] ?? 'pendiente'; ?>
<?php if ($estado_aprobacion === 'pendiente'): ?>
<div class="alert alert-aprobacion alert-pendiente alert-dismissible fade show">
<i class="fas fa-clock"></i> <strong>Esta sucursal está pendiente de aprobación.</strong> Los cambios realizados serán revisados por el administrador.
<?php if (!empty($sucursal_seleccionada['fecha_solicitud'])): ?>
<br><small>Solicitud enviada: <?php echo date('d/m/Y H:i', strtotime($sucursal_seleccionada['fecha_solicitud'])); ?></small>
<?php endif; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif ($estado_aprobacion === 'rechazado'): ?>
<div class="alert alert-aprobacion alert-rechazado alert-dismissible fade show">
<i class="fas fa-times-circle"></i> <strong>Solicitud Rechazada.</strong>
<?php if (!empty($sucursal_seleccionada['observaciones_aprobacion'])): ?>
<br><small>Motivo: <?php echo htmlspecialchars($sucursal_seleccionada['observaciones_aprobacion']); ?></small>
<?php endif; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif ($estado_aprobacion === 'aprobado'): ?>
<div class="alert alert-aprobacion alert-aprobado alert-dismissible fade show">
<i class="fas fa-check-circle"></i> <strong>Sucursal Aprobada.</strong>
<?php if (!empty($sucursal_seleccionada['fecha_aprobacion'])): ?>
<br><small>Aprobada el: <?php echo date('d/m/Y H:i', strtotime($sucursal_seleccionada['fecha_aprobacion'])); ?></small>
<?php endif; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<div class="alert alert-restricted mb-4">
<i class="fas fa-lock lock-icon"></i>
<strong>Campos Restringidos:</strong> Los campos de <strong>Nombre, Domicilio, Localidad, Jurisdicción y Estado</strong> solo pueden ser modificados por el <strong>Administrador del Sistema</strong> desde el panel de administración.
</div>
<form method="POST" action="?sucursal_id=<?php echo $sucursal_seleccionada['id']; ?><?php echo !empty($filtro_texto) ? '&filtro_texto=' . urlencode($filtro_texto) : ''; ?>&filtro_aprobacion=<?php echo htmlspecialchars($filtro_aprobacion); ?>" enctype="multipart/form-data" id="formGestionSucursal">
<input type="hidden" name="actualizar_sucursal" value="1">
<input type="hidden" name="sucursal_id" value="<?php echo $sucursal_seleccionada['id']; ?>">
<div class="section-card">
<h6 class="section-title"><i class="fas fa-info-circle"></i> Datos Básicos (Solo Lectura)</h6>
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Nombre de la Sucursal</label>
<input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($sucursal_seleccionada['nombre']); ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Empresa</label>
<input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($sucursal_seleccionada['empresa_nombre']); ?>" readonly>
</div>
<div class="col-md-8">
<label class="form-label">Domicilio</label>
<input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($sucursal_seleccionada['domicilio']); ?>" readonly>
</div>
<div class="col-md-4">
<label class="form-label">Localidad</label>
<input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($sucursal_seleccionada['localidad']); ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Jurisdicción</label>
<input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($sucursal_seleccionada['jurisdiccion'] ?? 'N/A'); ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Estado</label>
<div class="mt-2">
<span class="estado-badge <?php echo $sucursal_seleccionada['activa'] ? 'estado-activo' : 'estado-inactivo'; ?>">
<i class="fas fa-<?php echo $sucursal_seleccionada['activa'] ? 'check-circle' : 'times-circle'; ?>"></i>
<?php echo $sucursal_seleccionada['activa'] ? 'Activa' : 'Inactiva'; ?>
</span>
</div>
</div>
</div>
</div>
<div class="section-card">
<h6 class="section-title"><i class="fas fa-address-book"></i> Datos de Contacto</h6>
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($sucursal_seleccionada['telefono'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($sucursal_seleccionada['email'] ?? ''); ?>">
</div>
</div>
</div>
<div class="section-card">
<h6 class="section-title"><i class="fas fa-file-upload"></i> Documentación de la Sucursal</h6>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Cupón de Pago</label>
<div class="upload-area" onclick="document.getElementById('cupon_input').click()">
<i class="fas fa-cloud-upload-alt"></i>
<p class="mb-0">Haga clic para subir cupón</p>
<p class="text-muted small">PDF/JPG/PNG - Máx 5MB</p>
<input type="file" id="cupon_input" name="cupon_pago" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
</div>
<?php if (!empty($sucursal_seleccionada['cupon_pago'])): ?>
<div class="text-center mt-2">
<i class="fas fa-file-invoice-dollar fa-3x text-warning mb-2"></i>
<div class="file-status-badge file-status-exists"><i class="fas fa-check-circle"></i> Cupón Existente</div>
<div class="mt-2">
<a href="../uploads/cupones/<?php echo htmlspecialchars($sucursal_seleccionada['cupon_pago']); ?>" target="_blank" class="btn btn-sm btn-warning"><i class="fas fa-eye"></i> Ver</a>
</div>
</div>
<?php else: ?>
<div class="text-center mt-2">
<div class="file-status-badge file-status-missing"><i class="fas fa-exclamation-circle"></i> Sin Cupón</div>
</div>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">PDF de la Sucursal</label>
<div class="upload-area" onclick="document.getElementById('pdf_sucursal_input').click()">
<i class="fas fa-cloud-upload-alt"></i>
<p class="mb-0">Haga clic para subir PDF</p>
<p class="text-muted small">PDF - Máx 10MB</p>
<input type="file" id="pdf_sucursal_input" name="pdf_sucursal" class="d-none" accept=".pdf">
</div>
<?php if (!empty($sucursal_seleccionada['pdf_sucursal'])): ?>
<div class="text-center mt-2">
<i class="fas fa-file-pdf fa-3x text-primary mb-2"></i>
<div class="file-status-badge file-status-exists"><i class="fas fa-check-circle"></i> PDF Existente</div>
<div class="mt-2">
<a href="../uploads/pdf_sucursal/<?php echo htmlspecialchars($sucursal_seleccionada['pdf_sucursal']); ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> Ver</a>
</div>
</div>
<?php else: ?>
<div class="text-center mt-2">
<div class="file-status-badge file-status-missing"><i class="fas fa-exclamation-circle"></i> Sin PDF</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
<div class="section-card">
<h6 class="section-title"><i class="fas fa-camera"></i> Fotos de Uniformes y Vehículos</h6>
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">👕 Fotos del Uniforme</label>
<div class="upload-area-fotos" onclick="document.getElementById('uniforme_input').click()">
<i class="fas fa-user-shield"></i>
<p class="mb-0">Haga clic para subir fotos del uniforme</p>
<p class="text-muted small">JPG/PNG/WebP - Máx 10MB</p>
<input type="file" id="uniforme_input" name="fotos_uniforme" class="d-none" accept=".jpg,.jpeg,.png,.webp">
</div>
<?php if (!empty($sucursal_seleccionada['fotos_uniforme'])): ?>
<div class="text-center mt-2">
<img src="../uploads/fotos_sucursal/<?php echo htmlspecialchars($sucursal_seleccionada['fotos_uniforme']); ?>" class="foto-preview" alt="Uniforme">
<div class="mt-2">
<a href="../uploads/fotos_sucursal/<?php echo htmlspecialchars($sucursal_seleccionada['fotos_uniforme']); ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-eye"></i> Ver</a>
</div>
</div>
<?php endif; ?>
</div>
<div class="col-md-6">
<label class="form-label">🚗 Fotos de Vehículos</label>
<div class="upload-area-fotos" onclick="document.getElementById('vehiculos_input').click()">
<i class="fas fa-car"></i>
<p class="mb-0">Haga clic para subir fotos de vehículos</p>
<p class="text-muted small">JPG/PNG/WebP - Máx 10MB</p>
<input type="file" id="vehiculos_input" name="fotos_vehiculos" class="d-none" accept=".jpg,.jpeg,.png,.webp">
</div>
<?php if (!empty($sucursal_seleccionada['fotos_vehiculos'])): ?>
<div class="text-center mt-2">
<img src="../uploads/fotos_sucursal/<?php echo htmlspecialchars($sucursal_seleccionada['fotos_vehiculos']); ?>" class="foto-preview" alt="Vehículos">
<div class="mt-2">
<a href="../uploads/fotos_sucursal/<?php echo htmlspecialchars($sucursal_seleccionada['fotos_vehiculos']); ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-eye"></i> Ver</a>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
<div class="d-grid gap-2">
<button type="submit" class="btn btn-success btn-lg" id="btnGuardarCambios">
<i class="fas fa-save"></i> Guardar Cambios
</button>
<a href="dashboard.php" class="btn btn-secondary btn-lg">
<i class="fas fa-arrow-left"></i> Volver al Panel
</a>
</div>
</form>
</div>
</div>
<?php else: ?>
<div class="card card-shadow">
<div class="card-body text-center py-5">
<i class="fas fa-store-alt fa-4x text-muted mb-3"></i>
<h4>Seleccione una sucursal</h4>
<p class="text-muted">Seleccione una sucursal de la lista para gestionar sus datos.</p>
<div class="alert alert-info mt-3">
<i class="fas fa-info-circle"></i> Puede usar el buscador para encontrar más rápido la sucursal.
</div>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>

<!-- ✅ ALERTA FLOTANTE DE URGENCIA -->
<?php if ($hay_urgencia): ?>
<div class="urgency-alert" id="urgencyAlert">
<i class="fas fa-exclamation-triangle"></i>
<div class="urgency-alert-content">
<h6>⚠️ TRÁMITE URGENTE</h6>
<p>Debe presentarse en las oficinas de forma URGENTE</p>
</div>
<button class="urgency-alert-close" onclick="closeUrgencyAlert()">
<i class="fas fa-times"></i>
</button>
</div>
<?php endif; ?>

<!-- ✅ SCRIPT UNIFICADO PARA TOGGLE SIDEBAR -->
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Validaciones de archivos
document.getElementById('cupon_input')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const maxSize = 5 * 1024 * 1024;
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        if (file.size > maxSize) {
            Swal.fire({ icon: 'warning', title: 'Archivo Muy Grande', text: 'El cupón no debe superar los 5MB.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
        if (!allowedTypes.includes(file.type)) {
            Swal.fire({ icon: 'warning', title: 'Tipo No Permitido', text: 'Tipo de archivo no permitido. Solo PDF, JPG, JPEG, PNG.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
    }
});
document.getElementById('pdf_sucursal_input')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const maxSize = 10 * 1024 * 1024;
        const allowedTypes = ['application/pdf'];
        if (file.size > maxSize) {
            Swal.fire({ icon: 'warning', title: 'Archivo Muy Grande', text: 'El PDF no debe superar los 10MB.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
        if (!allowedTypes.includes(file.type)) {
            Swal.fire({ icon: 'warning', title: 'Tipo No Permitido', text: 'Tipo de archivo no permitido. Solo PDF.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
    }
});
document.getElementById('uniforme_input')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const maxSize = 10 * 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        if (file.size > maxSize) {
            Swal.fire({ icon: 'warning', title: 'Archivo Muy Grande', text: 'Las fotos no deben superar los 10MB.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
        if (!allowedTypes.includes(file.type)) {
            Swal.fire({ icon: 'warning', title: 'Tipo No Permitido', text: 'Tipo de archivo no permitido. Solo JPG, JPEG, PNG, WebP.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
    }
});
document.getElementById('vehiculos_input')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const maxSize = 10 * 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        if (file.size > maxSize) {
            Swal.fire({ icon: 'warning', title: 'Archivo Muy Grande', text: 'Las fotos no deben superar los 10MB.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
        if (!allowedTypes.includes(file.type)) {
            Swal.fire({ icon: 'warning', title: 'Tipo No Permitido', text: 'Tipo de archivo no permitido. Solo JPG, JPEG, PNG, WebP.', confirmButtonColor: '#4361ee' });
            this.value = ''; return;
        }
    }
});

// ✅ CORRECCIÓN PRINCIPAL: Guardar referencia del formulario ANTES del SweetAlert
document.getElementById('formGestionSucursal')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formulario = this;
    if (!formulario.checkValidity()) {
        formulario.classList.add('was-validated');
        Swal.fire({
            icon: 'warning',
            title: 'Campos Requeridos',
            text: 'Por favor complete todos los campos obligatorios',
            confirmButtonColor: '#4361ee'
        });
        return;
    }
    Swal.fire({
        title: '<i class="fas fa-save"></i> ¿Guardar Cambios?',
        html: `
        <div class="swal-content-modern">
        <p class="swal-message">
        <i class="fas fa-info-circle"></i>
        Se actualizarán los datos de la sucursal en el sistema
        </p>
        <div class="swal-warning-box">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Los cambios quedarán pendientes de aprobación del administrador</span>
        </div>
        </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        cancelButtonColor: '#95a5a6',
        confirmButtonText: '<i class="fas fa-check-circle"></i> Sí, Guardar',
        cancelButtonText: '<i class="fas fa-times-circle"></i> Cancelar',
        reverseButtons: true,
        focusCancel: false,
        customClass: {
            popup: 'swal-popup-modern',
            title: 'swal-title-modern',
            confirmButton: 'swal-confirm-modern',
            cancelButton: 'swal-cancel-modern'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Guardando...',
                html: 'Por favor espere mientras se actualizan los datos',
                timer: 1500,
                timerProgressBar: true,
                didOpen: () => { Swal.showLoading() },
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                customClass: { popup: 'swal-popup-modern' }
            }).then(() => {
                console.log('✅ Enviando formulario...');
                formulario.submit();
            });
        }
    });
});

// ✅ Funciones para alerta de urgencia
function closeUrgencyAlert() {
    const alert = document.getElementById('urgencyAlert');
    if (alert) {
        alert.style.animation = 'slideInRight 0.5s ease reverse';
        setTimeout(() => alert.style.display = 'none', 500);
    }
}
setTimeout(function() {
    const alert = document.getElementById('urgencyAlert');
    if (alert) {
        alert.style.animation = 'slideInRight 0.5s ease reverse';
        setTimeout(() => alert.style.display = 'none', 500);
    }
}, 50000);

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