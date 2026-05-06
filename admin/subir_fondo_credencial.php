<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ==================== VERIFICAR AUTENTICACIÓN ====================
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
header('Location: ../login.php');
exit;
}
$current_page = 'subir_fondo_credencial';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ==================== ✅ NUEVO: REINICIAR PAGOS DE SUCURSALES ====================
if (isset($_POST['action']) && $_POST['action'] === 'reiniciar_pagos_sucursales') {
header('Content-Type: application/json');
try {
$conn->beginTransaction();
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM sucursales WHERE pago_arancel = 1");
$stmt->execute();
$count = $stmt->fetch()['total'];
$stmt = $conn->prepare("
UPDATE sucursales SET
pago_arancel = 0,
fecha_pago_arancel = NULL,
updated_at = NOW()
");
$stmt->execute();
$detalles = [
'accion' => 'REINICIAR_PAGOS_SUCURSALES',
'tabla' => 'sucursales',
'registros_afectados' => $count,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'campos_reiniciados' => ['pago_arancel', 'fecha_pago_arancel']
];
logAuditoria($conn, 'REINICIAR_PAGOS_SUCURSALES', 'sucursales', null, $detalles, $user['id']);
$conn->commit();
echo json_encode(['success' => true, 'message' => "Se reiniciaron $count pagos de sucursales"]);
} catch(Exception $e) {
$conn->rollBack();
error_log("Error al reiniciar pagos sucursales: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
}
// ==================== ✅ NUEVO: REINICIAR PAGOS DE PERSONAL ====================
if (isset($_POST['action']) && $_POST['action'] === 'reiniciar_pagos_personal') {
header('Content-Type: application/json');
try {
$conn->beginTransaction();
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE pago_credencial = 1");
$stmt->execute();
$count = $stmt->fetch()['total'];
$stmt = $conn->prepare("
UPDATE personal SET
pago_credencial = 0,
fecha_pago_credencial = NULL,
fecha_vencimiento = NULL,
updated_at = NOW()
");
$stmt->execute();
$detalles = [
'accion' => 'REINICIAR_PAGOS_PERSONAL',
'tabla' => 'personal',
'registros_afectados' => $count,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'campos_reiniciados' => ['pago_credencial', 'fecha_pago_credencial', 'fecha_vencimiento']
];
logAuditoria($conn, 'REINICIAR_PAGOS_PERSONAL', 'personal', null, $detalles, $user['id']);
$conn->commit();
echo json_encode(['success' => true, 'message' => "Se reiniciaron $count pagos de personal"]);
} catch(Exception $e) {
$conn->rollBack();
error_log("Error al reiniciar pagos personal: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
}
// ==================== CREAR TABLA DE CONFIGURACIÓN SI NO EXISTE ====================
try {
$stmt = $conn->query("SHOW TABLES LIKE 'config_credenciales'");
if ($stmt->rowCount() == 0) {
$conn->exec("
CREATE TABLE config_credenciales (
id INT AUTO_INCREMENT PRIMARY KEY,
jefe_apellido VARCHAR(100) NOT NULL DEFAULT '',
jefe_nombre VARCHAR(100) NOT NULL DEFAULT '',
jefe_gerarquia VARCHAR(150) NOT NULL DEFAULT '',
firma_path VARCHAR(255) NULL,
updated_by INT NOT NULL DEFAULT 1,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
$user_id = isset($user['id']) ? (int)$user['id'] : 1;
$conn->exec("INSERT INTO config_credenciales (id, jefe_apellido, jefe_nombre, jefe_gerarquia, updated_by)
VALUES (1, '', '', '', $user_id)");
logAuditoria($conn, 'tabla_creada', 'config_credenciales', null, ['mensaje' => 'Tabla config_credenciales creada']);
}
} catch(PDOException $e) {
error_log("Error creando tabla config_credenciales: " . $e->getMessage());
}
// ==================== ✅ NUEVO: CREAR TABLA PARA CREDENCIALES DE INSPECTORES ====================
try {
$stmt = $conn->query("SHOW TABLES LIKE 'inspectores_credenciales'");
if ($stmt->rowCount() == 0) {
$conn->exec("
CREATE TABLE inspectores_credenciales (
id INT AUTO_INCREMENT PRIMARY KEY,
foto_path VARCHAR(255) NOT NULL,
apellido VARCHAR(100) NOT NULL,
nombre VARCHAR(100) NOT NULL,
gerarquia VARCHAR(150) NOT NULL,
token_verificacion VARCHAR(64) UNIQUE NOT NULL,
fecha_vencimiento_token DATETIME NOT NULL,
estado ENUM('activo', 'inactivo', 'vencido') DEFAULT 'activo',
created_by INT NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
INDEX idx_token (token_verificacion),
INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
logAuditoria($conn, 'tabla_creada', 'inspectores_credenciales', null, ['mensaje' => 'Tabla inspectores_credenciales creada']);
}
} catch(PDOException $e) {
error_log("Error creando tabla inspectores_credenciales: " . $e->getMessage());
}
// ==================== ✅ NUEVO: GENERAR CREDENCIAL INSPECTOR PDF ====================
if (isset($_GET['action']) && $_GET['action'] === 'generar_qr_inspector') {
require_once '../config/auth.php';
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga'))) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2>');
}
ob_clean();
$conn = getDBConnection();
$inspector_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$inspector_id) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ ID de inspector no válido</h2>');
}
try {
$stmt = $conn->prepare("SELECT * FROM inspectores_credenciales WHERE id = :id");
$stmt->execute(['id' => $inspector_id]);
$inspector = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inspector) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Inspector no encontrado</h2>');
}
} catch(PDOException $e) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Error al cargar los datos del inspector</h2>');
}
try {
$stmt = $conn->query("SELECT jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path FROM config_credenciales WHERE id = 1");
$config_jefe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$config_jefe) {
$config_jefe = ['jefe_apellido' => 'Apellido', 'jefe_nombre' => 'Nombre', 'jefe_gerarquia' => 'Jerarquía del Jefe', 'firma_path' => null];
}
} catch(PDOException $e) {
$config_jefe = ['jefe_apellido' => 'Apellido', 'jefe_nombre' => 'Nombre', 'jefe_gerarquia' => 'Jerarquía del Jefe', 'firma_path' => null];
}
$firma_path = null;
$escudo_path = '../uploads/fondos_credenciales/escudo.png';
$firma_valida = false;
$escudo_valido = false;
if (!empty($config_jefe['firma_path']) && file_exists('../uploads/firmas_jefe/' . $config_jefe['firma_path'])) {
$firma_path = '../uploads/firmas_jefe/' . $config_jefe['firma_path'];
$info = @getimagesize($firma_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) $firma_valida = true;
}
if (file_exists($escudo_path)) {
$info = @getimagesize($escudo_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) $escudo_valido = true;
}
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
class PDF_Credencial_Inspector extends FPDF {
function Header() {}
function Footer() {}
}
$pdf = new PDF_Credencial_Inspector();
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage('L', array(85.6, 54));
$fondo_frente = '../uploads/fondos_credenciales/fondo_frente.jpg';
if (file_exists($fondo_frente)) $pdf->Image($fondo_frente, 0, 0, 85.6, 54, 'JPEG');
$watermark_path = '../uploads/fondos_credenciales/logo-policia-chubut.png';
if (file_exists($watermark_path)) $pdf->Image($watermark_path, 42.8, 10, 25, 25, 'PNG');
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(0, 2);
$pdf->Cell(85.6, 6, 'INSPECTOR DE SEGURIDAD', 0, 0, 'C');
$foto_path = '../uploads/fotos_inspectores/' . $inspector['foto_path'];
if (!empty($inspector['foto_path']) && file_exists($foto_path)) {
$pdf->Image($foto_path, 4, 10, 28, 32, 'JPEG');
} else {
$pdf->SetDrawColor(200, 200, 200);
$pdf->Rect(4, 10, 28, 32);
$pdf->SetXY(4, 19);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(200, 200, 200);
$pdf->Cell(28, 8, 'SIN', 0, 0, 'C');
$pdf->SetXY(4, 24);
$pdf->Cell(28, 8, 'FOTO', 0, 0, 'C');
$pdf->SetTextColor(0, 0, 0);
}
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(4, 43);
$pdf->Cell(28, 4, 'ID:', 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetXY(4, 47);
$pdf->Cell(28, 6, $inspector_id, 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(34, 12);
$pdf->Cell(50, 5, 'Jerarquía: ' . strtoupper(utf8_decode($inspector['gerarquia'] ?? 'SIN JERARQUÍA')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 17);
$pdf->Cell(50, 5, 'Apellido: ' . strtoupper(utf8_decode($inspector['apellido'] ?? '')), 0, 0, 'L');
$pdf->SetXY(34, 22);
$pdf->Cell(50, 5, 'Nombre: ' . strtoupper(utf8_decode($inspector['nombre'] ?? '')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 27);
$pdf->Cell(50, 5, 'Estado: ' . strtoupper(utf8_decode($inspector['estado'] ?? 'ACTIVO')), 0, 0, 'L');
$fecha_inicio = date('d/m/Y', strtotime($inspector['created_at']));
$fecha_fin = $inspector['fecha_vencimiento_token'] ? date('d/m/Y', strtotime($inspector['fecha_vencimiento_token'])) : date('d/m/Y', strtotime('+1 year'));
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 32);
$pdf->Cell(50, 5, 'Vigencia: ' . $fecha_inicio . ' al ' . $fecha_fin, 0, 0, 'L');
$bloque_y = 36;
$margen_derecho = -5;
$ancho_escudo = 12;
$ancho_firma = 37.5;
$alto_firma = 10;
$gap = 2;
$ancho_total_bloque = $ancho_escudo + $gap + $ancho_firma;
$bloque_x = 85.6 - $margen_derecho - $ancho_total_bloque;
if ($escudo_valido) $pdf->Image($escudo_path, $bloque_x, $bloque_y, $ancho_escudo, $ancho_escudo, 'PNG');
$firma_x = $bloque_x + $ancho_escudo + $gap;
if ($firma_valida) $pdf->Image($firma_path, $firma_x, $bloque_y, $ancho_firma, $alto_firma, 'PNG');
$texto_y = $bloque_y + $alto_firma + 0.8;
$line_height = 1.6;
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetXY(25, $texto_y);
$pdf->Cell(85.6, $line_height, strtoupper(utf8_decode($config_jefe['jefe_apellido'] . ', ' . $config_jefe['jefe_nombre'])), 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetXY(25, $texto_y + $line_height);
$pdf->Cell(85.6, $line_height, strtoupper(utf8_decode($config_jefe['jefe_gerarquia'])), 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetXY(25, $texto_y + (2 * $line_height));
$pdf->Cell(85.6, $line_height, 'JEFE AREA INVESTIGACION (A-2)', 0, 0, 'C');
$pdf->AddPage('L', array(85.6, 54));
$fondo_reverso = '../uploads/fondos_credenciales/fondo_reverso.jpg';
if (file_exists($fondo_reverso)) $pdf->Image($fondo_reverso, 0, 0, 85.6, 54, 'JPEG');
$pdf->SetXY(0, 2);
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(85.6, 6, 'INSPECTOR DE SEGURIDAD', 0, 0, 'C');
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
$verify_url = $protocol . $domain . '/dashboard/Sistema_de_gestion/verificar_credencial.php?token=' . $inspector['token_verificacion'];
$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($verify_url);
$qr_temp = sys_get_temp_dir() . '/qr_inspector_' . $inspector_id . '_' . time() . '.png';
file_put_contents($qr_temp, file_get_contents($qr_api));
if (file_exists($qr_temp)) {
$pdf->Image($qr_temp, 26.8, 8, 32, 32);
unlink($qr_temp);
}
$pdf->SetXY(0, 42);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(85.6, 5, 'AREA INVESTIGACIONES(A2)', 0, 0, 'C');
$pdf->SetXY(0, 47);
$pdf->SetFont('Arial', '', 6);
$pdf->Cell(85.6, 4, utf8_decode('De hallarse esta tarjeta, se agradecerá su devolución'), 0, 0, 'C');
$pdf->SetXY(0, 50.5);
$pdf->Cell(85.6, 4, utf8_decode('a la Comisaría o Dependencia policial más próxima.'), 0, 0, 'C');
$nombre_archivo = 'Credencial_Inspector_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $inspector['nombre'] . '_' . $inspector['apellido']) . '_' . $inspector_id . '.pdf';
$pdf->Output('D', $nombre_archivo);
exit;
}
// ==================== ✅ NUEVO: PROCESAR CREACIÓN DE CREDENCIAL DE INSPECTOR ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_credencial_inspector') {
header('Content-Type: application/json');
try {
$apellido = trim($_POST['inspector_apellido'] ?? '');
$nombre = trim($_POST['inspector_nombre'] ?? '');
$gerarquia = trim($_POST['inspector_gerarquia'] ?? '');
if (empty($apellido) || empty($nombre) || empty($gerarquia)) {
throw new Exception('Apellido, Nombre y Jerarquía son obligatorios');
}
// Procesar foto del inspector
$foto_path = null;
if (isset($_FILES['inspector_foto']) && $_FILES['inspector_foto']['error'] === UPLOAD_ERR_OK) {
$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $_FILES['inspector_foto']['tmp_name']);
finfo_close($finfo);
if (!in_array($mimeType, $allowed_types)) {
throw new Exception('Tipo de archivo no permitido. Solo JPG, PNG o WebP');
}
$target_dir = '../uploads/fotos_inspectores/';
if (!file_exists($target_dir)) {
mkdir($target_dir, 0777, true);
}
$extension = pathinfo($_FILES['inspector_foto']['name'], PATHINFO_EXTENSION);
$new_filename = 'inspector_' . uniqid() . '_' . time() . '.' . $extension;
$target_file = $target_dir . $new_filename;
if (move_uploaded_file($_FILES['inspector_foto']['tmp_name'], $target_file)) {
$foto_path = $new_filename;
} else {
throw new Exception('Error al guardar la foto del inspector');
}
} else {
throw new Exception('La foto del inspector es obligatoria');
}
// Generar token único de verificación
$token = bin2hex(random_bytes(32));
// Fecha de vencimiento del token (por defecto 365 días, configurable)
$dias_vencimiento = isset($_POST['dias_vencimiento']) ? (int)$_POST['dias_vencimiento'] : 365;
$fecha_vencimiento = date('Y-m-d H:i:s', strtotime("+{$dias_vencimiento} days"));
$user_id = isset($user['id']) ? (int)$user['id'] : 1;
$stmt = $conn->prepare("
INSERT INTO inspectores_credenciales
(foto_path, apellido, nombre, gerarquia, token_verificacion, fecha_vencimiento_token, estado, created_by)
VALUES (?, ?, ?, ?, ?, ?, 'activo', ?)
");
$stmt->execute([$foto_path, $apellido, $nombre, $gerarquia, $token, $fecha_vencimiento, $user_id]);
$inspector_id = $conn->lastInsertId();
// Generar URL de verificación con token
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$verification_url = "{$base_url}/verificar_credencial.php?token={$token}";
// Generar QR usando API pública (sin dependencias externas)
$qr_size = 300;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size={$qr_size}x{$qr_size}&data=" . urlencode($verification_url);
logAuditoria($conn, 'credencial_inspector_creada', 'inspectores_credenciales', $inspector_id, [
'apellido' => $apellido,
'nombre' => $nombre,
'gerarquia' => $gerarquia,
'token' => $token,
'url_verificacion' => $verification_url
], $user['id']);
echo json_encode([
'success' => true,
'message' => 'Credencial de inspector creada exitosamente',
'qr_url' => $qr_url,
'verification_url' => $verification_url,
'token' => $token,
'fecha_vencimiento' => $fecha_vencimiento
]);
} catch(Exception $e) {
logAuditoria($conn, 'error_credencial_inspector', 'inspectores_credenciales', null, ['error' => $e->getMessage()]);
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
}
// ==================== ✅ NUEVO: RENOVAR TOKEN DE INSPECTOR ====================
if (isset($_POST['action']) && $_POST['action'] === 'renovar_token_inspector') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$inspector_id = isset($_POST['inspector_id']) ? (int)$_POST['inspector_id'] : 0;
$dias_vencimiento = isset($_POST['dias_vencimiento']) ? (int)$_POST['dias_vencimiento'] : 365;
if ($inspector_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID inválido']);
exit;
}
try {
$conn->beginTransaction();
// Generar nuevo token único
$nuevo_token = bin2hex(random_bytes(32));
$nueva_fecha_vencimiento = date('Y-m-d H:i:s', strtotime("+{$dias_vencimiento} days"));
$stmt = $conn->prepare("
UPDATE inspectores_credenciales SET
token_verificacion = :token,
fecha_vencimiento_token = :fecha_vencimiento,
estado = 'activo',
updated_at = NOW()
WHERE id = :id
");
$stmt->execute([
'token' => $nuevo_token,
'fecha_vencimiento' => $nueva_fecha_vencimiento,
'id' => $inspector_id
]);
// Generar nueva URL de verificación
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$verification_url = "{$base_url}/verificar_credencial.php?token={$nuevo_token}";
$qr_size = 300;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size={$qr_size}x{$qr_size}&data=" . urlencode($verification_url);
$user = $auth->getCurrentUser();
$detalles = [
'accion' => 'RENOVAR_TOKEN_INSPECTOR',
'tabla' => 'inspectores_credenciales',
'registro_id' => $inspector_id,
'token_anterior' => '***OCULTO***',
'nuevo_token' => $nuevo_token,
'fecha_vencimiento_anterior' => '***OCULTO***',
'nueva_fecha_vencimiento' => $nueva_fecha_vencimiento,
'dias_vencimiento' => $dias_vencimiento,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'RENOVAR_TOKEN_INSPECTOR', 'inspectores_credenciales', $inspector_id, $detalles);
$conn->commit();
echo json_encode([
'success' => true,
'message' => 'Token renovado exitosamente',
'qr_url' => $qr_url,
'verification_url' => $verification_url,
'token' => $nuevo_token,
'fecha_vencimiento' => $nueva_fecha_vencimiento
]);
} catch(Exception $e) {
$conn->rollBack();
error_log("Error al renovar token inspector: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
}
// ==================== ✅ NUEVO: CAMBIAR ESTADO DE INSPECTOR ====================
if (isset($_POST['action']) && $_POST['action'] === 'cambiar_estado_inspector') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$inspector_id = isset($_POST['inspector_id']) ? (int)$_POST['inspector_id'] : 0;
$nuevo_estado = isset($_POST['estado']) ? $_POST['estado'] : 'activo';
if ($inspector_id <= 0 || !in_array($nuevo_estado, ['activo', 'inactivo', 'vencido'])) {
echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
exit;
}
try {
$conn->beginTransaction();
$stmt = $conn->prepare("
UPDATE inspectores_credenciales SET
estado = :estado,
updated_at = NOW()
WHERE id = :id
");
$stmt->execute(['estado' => $nuevo_estado, 'id' => $inspector_id]);
$user = $auth->getCurrentUser();
$detalles = [
'accion' => 'CAMBIAR_ESTADO_INSPECTOR',
'tabla' => 'inspectores_credenciales',
'registro_id' => $inspector_id,
'estado_anterior' => '***',
'estado_nuevo' => $nuevo_estado,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'CAMBIAR_ESTADO_INSPECTOR', 'inspectores_credenciales', $inspector_id, $detalles);
$conn->commit();
echo json_encode(['success' => true, 'message' => 'Estado actualizado correctamente']);
} catch(Exception $e) {
$conn->rollBack();
error_log("Error al cambiar estado inspector: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
}
// ==================== PROCESAR FORMULARIO ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
try {
$jefe_apellido = trim($_POST['jefe_apellido'] ?? '');
$jefe_nombre = trim($_POST['jefe_nombre'] ?? '');
$jefe_gerarquia = trim($_POST['jefe_gerarquia'] ?? '');
if (empty($jefe_apellido) || empty($jefe_nombre) || empty($jefe_gerarquia)) {
throw new Exception('Todos los campos del jefe son obligatorios');
}
// ==================== PROCESAR FIRMA DIBUJADA (BASE64) ====================
$firma_path = null;
if (!empty($_POST['signature_data'])) {
$signature_data = $_POST['signature_data'];
$signature_data = str_replace('data:image/png;base64,', '', $signature_data);
$signature_data = str_replace(' ', '+', $signature_data);
$image_data = base64_decode($signature_data);
if ($image_data === false) {
throw new Exception('Error al decodificar la firma');
}
$target_dir = '../uploads/firmas_jefe/';
if (!file_exists($target_dir)) {
mkdir($target_dir, 0777, true);
}
$new_filename = 'firma_jefe_' . date('YmdHis') . '.png';
$target_file = $target_dir . $new_filename;
if (file_put_contents($target_file, $image_data)) {
$info = @getimagesize($target_file);
if ($info === false || $info[2] !== IMAGETYPE_PNG) {
unlink($target_file);
$img = imagecreatetruecolor(400, 150);
imagesavealpha($img, true);
$transparency = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefill($img, 0, 0, $transparency);
$temp_img = imagecreatefromstring($image_data);
if ($temp_img) {
imagecopyresampled($img, $temp_img, 0, 0, 0, 0, 400, 150, imagesx($temp_img), imagesy($temp_img));
imagedestroy($temp_img);
}
imagepng($img, $target_file);
imagedestroy($img);
}
$firma_path = $new_filename;
} else {
throw new Exception('Error al guardar la firma');
}
}
$user_id = isset($user['id']) ? (int)$user['id'] : 1;
$stmt = $conn->prepare("
INSERT INTO config_credenciales (id, jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path, updated_by)
VALUES (1, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
jefe_apellido = VALUES(jefe_apellido),
jefe_nombre = VALUES(jefe_nombre),
jefe_gerarquia = VALUES(jefe_gerarquia),
firma_path = IFNULL(VALUES(firma_path), firma_path),
updated_by = VALUES(updated_by),
updated_at = CURRENT_TIMESTAMP
");
$stmt->execute([$jefe_apellido, $jefe_nombre, $jefe_gerarquia, $firma_path, $user_id]);
logAuditoria($conn, 'config_credenciales_actualizada', 'config_credenciales', null, [
'jefe_apellido' => $jefe_apellido,
'jefe_nombre' => $jefe_nombre,
'jefe_gerarquia' => $jefe_gerarquia
]);
$_SESSION['success'] = '¡Configuración de credenciales actualizada exitosamente!';
header('Location: subir_fondo_credencial.php');
exit;
} catch(Exception $e) {
logAuditoria($conn, 'error_config_credenciales', 'config_credenciales', null, ['error' => $e->getMessage()]);
$_SESSION['error'] = 'Error: ' . $e->getMessage();
}
}
// ==================== OBTENER CONFIGURACIÓN ACTUAL ====================
try {
$stmt = $conn->query("SELECT * FROM config_credenciales WHERE id = 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$config) {
$user_id = isset($user['id']) ? (int)$user['id'] : 1;
$conn->exec("INSERT INTO config_credenciales (id, jefe_apellido, jefe_nombre, jefe_gerarquia, updated_by)
VALUES (1, '', '', '', $user_id)");
$config = ['jefe_apellido' => '', 'jefe_nombre' => '', 'jefe_gerarquia' => '', 'firma_path' => null];
}
} catch(PDOException $e) {
$config = ['jefe_apellido' => '', 'jefe_nombre' => '', 'jefe_gerarquia' => '', 'firma_path' => null];
error_log("Error cargando configuración: " . $e->getMessage());
}
// ==================== ✅ OBTENER CONTADORES DE PAGOS ====================
try {
$stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE pago_arancel = 1");
$sucursales_con_pago = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM personal WHERE pago_credencial = 1");
$personal_con_pago = $stmt->fetch()['total'];
} catch(PDOException $e) {
$sucursales_con_pago = 0;
$personal_con_pago = 0;
}
// ==================== ✅ OBTENER LISTA DE INSPECTORES ====================
try {
$stmt = $conn->query("
SELECT ic.*, 
CASE 
WHEN ic.fecha_vencimiento_token < NOW() THEN 'vencido'
ELSE ic.estado
END as estado_real
FROM inspectores_credenciales ic
ORDER BY ic.apellido, ic.nombre
");
$inspectores_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
$inspectores_list = [];
error_log("Error cargando lista de inspectores: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<meta name="theme-color" content="#9b59b6">
<title>Configurar Recursos para Credenciales - Sistema de Seguridad</title>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Bootstrap Local -->
<link href="../css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
--primary-color: #9b59b6;
--secondary-color: #8e44ad;
--sidebar-width: 280px;
--header-height: 80px;
--header-height-mobile: 65px;
}
* {
box-sizing: border-box;
-webkit-tap-highlight-color: transparent;
}
body {
padding-top: var(--header-height);
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
background: linear-gradient(135deg, #1a2a6c, #2c3e50);
color: #ecf0f1;
min-height: 100vh;
overflow-x: hidden;
}
.dashboard {
display: flex;
min-height: calc(100vh - var(--header-height));
}
.main-content {
padding: 20px 25px !important;
margin-left: var(--sidebar-width) !important;
width: calc(100% - var(--sidebar-width));
transition: margin-left 0.35s ease;
}
.container {
max-width: 900px;
margin: 0 auto;
}
/* ===== CARDS ===== */
.card {
border-radius: 20px;
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
border: none;
margin-bottom: 20px;
background: rgba(44, 62, 80, 0.9);
transition: transform 0.3s;
}
.card:hover {
transform: translateY(-3px);
}
.card-header {
background: linear-gradient(135deg, #9b59b6, #8e44ad);
border-radius: 20px 20px 0 0 !important;
padding: 20px;
text-align: center;
border-bottom: none;
font-weight: 700;
font-size: 1.4rem;
color: white;
}
.card-body {
padding: 25px;
}
.section-title {
font-size: 1.5rem;
font-weight: 700;
color: #9b59b6;
text-align: center;
margin: 20px 0 15px;
position: relative;
}
.section-title:after {
content: '';
display: block;
width: 45px;
height: 3px;
background: #9b59b6;
margin: 8px auto;
border-radius: 3px;
}
.form-label {
font-weight: 600;
color: #f1c40f;
margin-bottom: 8px;
display: block;
}
.form-control {
background: rgba(255, 255, 255, 0.1);
border: 1px solid #9b59b6;
color: white;
padding: 12px 15px;
border-radius: 12px;
margin-bottom: 15px;
transition: all 0.3s;
}
.form-control:focus {
background: rgba(255, 255, 255, 0.15);
border-color: #8e44ad;
box-shadow: 0 0 0 0.25rem rgba(155, 89, 182, 0.25);
color: white;
}
.form-control::placeholder {
color: #bdc3c7;
}
/* ===== ALERTS ===== */
.alert-custom {
border-radius: 15px;
margin-bottom: 20px;
border: none;
box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
}
/* ===== BUTTONS ===== */
.btn-guardar {
background: linear-gradient(135deg, #27ae60, #219653);
border: none;
padding: 16px 25px;
font-size: 1.25rem;
font-weight: 700;
border-radius: 15px;
width: 100%;
transition: all 0.3s;
box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4);
margin-top: 5px;
color: white;
}
.btn-guardar:hover {
transform: translateY(-2px);
box-shadow: 0 6px 20px rgba(39, 174, 96, 0.6);
background: linear-gradient(135deg, #219653, #1e8449);
color: white;
}
.btn-guardar i {
margin-right: 8px;
}
/* ✅ BOTONES DE REINICIO */
.btn-reiniciar {
background: linear-gradient(135deg, #e74c3c, #c0392b);
border: none;
padding: 14px 20px;
font-size: 1rem;
font-weight: 600;
border-radius: 12px;
transition: all 0.3s;
box-shadow: 0 4px 15px rgba(231, 76, 60, 0.4);
color: white;
}
.btn-reiniciar:hover {
transform: translateY(-2px);
box-shadow: 0 6px 20px rgba(231, 76, 60, 0.6);
background: linear-gradient(135deg, #c0392b, #a93226);
color: white;
}
.btn-reiniciar i {
margin-right: 8px;
}
/* ===== SIGNATURE CANVAS ===== */
.signature-container {
background: rgba(0, 0, 0, 0.2);
border-radius: 15px;
padding: 20px;
margin: 15px 0;
text-align: center;
border: 2px dashed #9b59b6;
}
#signatureCanvas {
background: transparent;
border: 2px solid #3498db;
border-radius: 10px;
cursor: crosshair;
touch-action: none;
max-width: 100%;
height: auto;
display: block;
margin: 0 auto;
box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}
.signature-actions {
display: flex;
gap: 10px;
margin-top: 15px;
justify-content: center;
flex-wrap: wrap;
}
.btn-signature {
padding: 8px 20px;
border-radius: 50px;
font-weight: 600;
transition: all 0.3s;
border: none;
}
.btn-clear {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
}
.btn-clear:hover {
background: linear-gradient(135deg, #c0392b, #a93226);
transform: scale(1.05);
color: white;
}
.btn-undo {
background: linear-gradient(135deg, #3498db, #2980b9);
color: white;
}
.btn-undo:hover {
background: linear-gradient(135deg, #2980b9, #2472a4);
transform: scale(1.05);
color: white;
}
/* ===== PREVIEW ===== */
.preview-container {
background: rgba(0, 0, 0, 0.3);
border-radius: 15px;
padding: 20px;
margin-top: 20px;
text-align: center;
border-left: 4px solid #f1c40f;
}
.preview-title {
font-weight: 700;
color: #f1c40f;
margin-bottom: 15px;
font-size: 1.2rem;
}
.preview-name {
font-size: 1.6rem;
font-weight: 700;
color: white;
margin: 8px 0;
line-height: 1.3;
}
.preview-hierarchy {
font-size: 1.3rem;
color: #3498db;
font-weight: 600;
margin-top: 5px;
}
.signature-preview {
max-width: 250px;
margin: 15px auto;
padding: 15px;
background:
linear-gradient(45deg, #3498db 25%, transparent 25%),
linear-gradient(-45deg, #3498db 25%, transparent 25%),
linear-gradient(45deg, transparent 75%, #3498db 75%),
linear-gradient(-45deg, transparent 75%, #3498db 75%);
background-size: 20px 20px;
background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
border-radius: 12px;
border: 2px dashed #9b59b6;
}
.signature-preview img {
max-width: 100%;
max-height: 80px;
display: block;
margin: 0 auto;
border-radius: 8px;
box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4);
}
/* ===== INSTRUCTIONS ===== */
.instructions {
background: rgba(155, 89, 182, 0.15);
border-left: 4px solid #9b59b6;
padding: 15px;
border-radius: 0 10px 10px 0;
margin: 15px 0;
font-size: 0.95rem;
text-align: left;
}
.instructions ol {
padding-left: 20px;
margin-bottom: 0;
margin-top: 8px;
}
.instructions li {
margin-bottom: 6px;
line-height: 1.4;
color: #ecf0f1;
}
.mobile-tip {
background: rgba(52, 152, 219, 0.2);
border: 1px solid #3498db;
border-radius: 12px;
padding: 12px;
margin-top: 10px;
font-size: 0.9rem;
color: #ecf0f1;
}
.mobile-tip i {
color: #3498db;
margin-right: 5px;
}
/* ✅ PANEL DE REINICIO */
.reiniciar-panel {
background: rgba(231, 76, 60, 0.1);
border: 2px solid #e74c3c;
border-radius: 15px;
padding: 20px;
margin-bottom: 20px;
}
.reiniciar-panel h5 {
color: #e74c3c;
font-weight: 700;
margin-bottom: 15px;
}
.reiniciar-stats {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
gap: 15px;
margin-bottom: 20px;
}
.stat-reiniciar {
background: rgba(0, 0, 0, 0.3);
border-radius: 10px;
padding: 15px;
text-align: center;
}
.stat-reiniciar .number {
font-size: 2rem;
font-weight: 700;
color: #f1c40f;
}
.stat-reiniciar .label {
font-size: 0.85rem;
color: #bdc3c7;
text-transform: uppercase;
}
/* ✅ NUEVO: ESTILOS PARA CREDENCIALES DE INSPECTORES */
.inspector-card {
border: 2px solid #3498db;
}
.inspector-card .card-header {
background: linear-gradient(135deg, #3498db, #2980b9);
}
.photo-upload-container {
background: rgba(0, 0, 0, 0.2);
border-radius: 15px;
padding: 20px;
margin: 15px 0;
text-align: center;
border: 2px dashed #3498db;
cursor: pointer;
transition: all 0.3s;
}
.photo-upload-container:hover {
border-color: #2980b9;
background: rgba(52, 152, 219, 0.1);
}
.photo-upload-container i {
font-size: 3rem;
color: #3498db;
margin-bottom: 10px;
}
.photo-upload-container input[type="file"] {
display: none;
}
.photo-preview {
max-width: 200px;
max-height: 200px;
border-radius: 12px;
margin: 15px auto;
display: none;
box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}
.qr-result-container {
background: rgba(46, 204, 113, 0.1);
border: 2px solid #2ecc71;
border-radius: 15px;
padding: 20px;
margin-top: 20px;
text-align: center;
display: none;
}
.qr-result-container img {
max-width: 200px;
margin: 15px auto;
display: block;
border-radius: 12px;
}
.qr-token-info {
background: rgba(0, 0, 0, 0.3);
border-radius: 10px;
padding: 15px;
margin-top: 15px;
text-align: left;
font-family: monospace;
font-size: 0.9rem;
}
.qr-token-info code {
color: #2ecc71;
word-break: break-all;
}
.btn-crear-inspector {
background: linear-gradient(135deg, #3498db, #2980b9);
border: none;
padding: 16px 25px;
font-size: 1.25rem;
font-weight: 700;
border-radius: 15px;
width: 100%;
transition: all 0.3s;
box-shadow: 0 4px 15px rgba(52, 152, 219, 0.4);
margin-top: 5px;
color: white;
}
.btn-crear-inspector:hover {
transform: translateY(-2px);
box-shadow: 0 6px 20px rgba(52, 152, 219, 0.6);
background: linear-gradient(135deg, #2980b9, #2472a4);
color: white;
}
.btn-crear-inspector i {
margin-right: 8px;
}
/* ✅ NUEVO: ESTILOS PARA LISTA DE INSPECTORES */
.inspectores-list-card {
border: 2px solid #2ecc71;
}
.inspectores-list-card .card-header {
background: linear-gradient(135deg, #2ecc71, #27ae60);
}
.inspector-item {
background: rgba(0, 0, 0, 0.2);
border-radius: 12px;
padding: 15px;
margin-bottom: 10px;
border-left: 4px solid #3498db;
transition: all 0.3s;
}
.inspector-item:hover {
background: rgba(52, 152, 219, 0.1);
transform: translateX(5px);
}
.inspector-item.vencido {
border-left-color: #e74c3c;
}
.inspector-item.inactivo {
border-left-color: #95a5a6;
}
.inspector-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 10px;
}
.inspector-name {
font-weight: 700;
font-size: 1.1rem;
color: white;
}
.inspector-gerarquia {
font-size: 0.9rem;
color: #3498db;
}
.inspector-badge {
padding: 4px 12px;
border-radius: 20px;
font-size: 0.75rem;
font-weight: 600;
}
.badge-activo { background: #2ecc71; color: white; }
.badge-inactivo { background: #95a5a6; color: white; }
.badge-vencido { background: #e74c3c; color: white; }
.inspector-actions {
display: flex;
gap: 8px;
flex-wrap: wrap;
}
.btn-inspector-action {
padding: 6px 12px;
border-radius: 8px;
font-size: 0.85rem;
font-weight: 600;
transition: all 0.3s;
border: none;
}
.btn-descargar-pdf {
background: linear-gradient(135deg, #9b59b6, #8e44ad);
color: white;
}
.btn-descargar-pdf:hover {
background: linear-gradient(135deg, #8e44ad, #7d3c98);
color: white;
transform: scale(1.05);
}
.btn-renovar {
background: linear-gradient(135deg, #f39c12, #e67e22);
color: white;
}
.btn-renovar:hover {
background: linear-gradient(135deg, #e67e22, #d35400);
color: white;
transform: scale(1.05);
}
.btn-estado {
background: linear-gradient(135deg, #3498db, #2980b9);
color: white;
}
.btn-estado:hover {
background: linear-gradient(135deg, #2980b9, #2472a4);
color: white;
transform: scale(1.05);
}
.inspector-info {
font-size: 0.85rem;
color: #bdc3c7;
margin-top: 8px;
}
.inspector-info i {
margin-right: 5px;
color: #3498db;
}
/* ===== RESPONSIVE ===== */
@media (max-width: 991px) {
:root { --sidebar-width: 0px; }
.main-content { margin-left: 0 !important; width: 100%; padding: 15px 20px !important; }
}
@media (max-width: 767px) {
body { padding-top: var(--header-height-mobile); }
:root { --header-height: var(--header-height-mobile); }
.card-body { padding: 20px; }
.section-title { font-size: 1.3rem; }
.btn-guardar { font-size: 1.15rem; padding: 14px; }
#signatureCanvas { height: 180px !important; }
}
@media (max-width: 576px) {
.preview-name { font-size: 1.4rem; }
.preview-hierarchy { font-size: 1.1rem; }
}
</style>
</head>
<body>
<?php $page_title = 'Configurar Credenciales'; $page_icon = 'fas fa-id-card-clip'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<div class="container">
<div class="text-center mb-3 mt-2">
<h1 class="text-white"><i class="fas fa-id-card"></i> Configurar Recursos para Credenciales</h1>
<p class="text-muted">Complete los datos del jefe y firme directamente desde su dispositivo móvil</p>
</div>
<?php if ($success): ?>
<div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
<i class="fas fa-check-circle fa-lg me-2"></i> <?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
<i class="fas fa-exclamation-triangle fa-lg me-2"></i> <?php echo htmlspecialchars($error); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- ✅ PANEL DE REINICIO DE PAGOS -->
<div class="reiniciar-panel">
<h5><i class="fas fa-sync-alt"></i> Reinicio Masivo de Pagos</h5>
<div class="reiniciar-stats">
<div class="stat-reiniciar">
<div class="number"><?php echo $sucursales_con_pago; ?></div>
<div class="label">Sucursales con Pago</div>
</div>
<div class="stat-reiniciar">
<div class="number"><?php echo $personal_con_pago; ?></div>
<div class="label">Personal con Pago</div>
</div>
</div>
<div class="row g-3">
<div class="col-md-6">
<button type="button" class="btn btn-reiniciar w-100" onclick="reiniciarPagosSucursales()">
<i class="fas fa-building"></i> Reiniciar Pagos de Sucursales
</button>
<small class="text-muted d-block mt-2 text-center">
<i class="fas fa-info-circle"></i> Setea pago_arancel = 0 y limpia fecha_pago_arancel
</small>
</div>
<div class="col-md-6">
<button type="button" class="btn btn-reiniciar w-100" onclick="reiniciarPagosPersonal()">
<i class="fas fa-users"></i> Reiniciar Pagos de Personal
</button>
<small class="text-muted d-block mt-2 text-center">
<i class="fas fa-info-circle"></i> Setea pago_credencial = 0 y limpia fechas de pago y vencimiento
</small>
</div>
</div>
</div>
<!-- ==================== TARJETA: DATOS DEL JEFE ==================== -->
<div class="card">
<div class="card-header">
<i class="fas fa-user-tie"></i> Datos del Jefe
</div>
<div class="card-body">
<form method="POST" action="" id="configForm">
<div class="row g-3">
<div class="col-md-6">
<label for="jefe_apellido" class="form-label">
<i class="fas fa-id-badge"></i> Apellido del Jefe <span class="text-danger">*</span>
</label>
<input type="text"
class="form-control"
id="jefe_apellido"
name="jefe_apellido"
value="<?php echo htmlspecialchars($config['jefe_apellido']); ?>"
placeholder="Ingrese apellido"
required>
</div>
<div class="col-md-6">
<label for="jefe_nombre" class="form-label">
<i class="fas fa-user"></i> Nombre del Jefe <span class="text-danger">*</span>
</label>
<input type="text"
class="form-control"
id="jefe_nombre"
name="jefe_nombre"
value="<?php echo htmlspecialchars($config['jefe_nombre']); ?>"
placeholder="Ingrese nombre"
required>
</div>
<div class="col-12">
<label for="jefe_gerarquia" class="form-label">
<i class="fas fa-sitemap"></i> Jerarquía del Jefe <span class="text-danger">*</span>
</label>
<input type="text"
class="form-control"
id="jefe_gerarquia"
name="jefe_gerarquia"
value="<?php echo htmlspecialchars($config['jefe_gerarquia']); ?>"
placeholder="Ej: Director de Seguridad, Jefe de Seguridad"
required>
<div class="form-text text-center text-white-50 mt-1">
<i class="fas fa-info-circle"></i> Ingrese la jerarquía completa que aparecerá en las credenciales
</div>
</div>
</div>
<!-- Vista previa en tiempo real -->
<div class="preview-container mt-4">
<div class="preview-title"><i class="fas fa-eye"></i> Vista previa en credencial:</div>
<div class="preview-name" id="previewName">
<?php echo htmlspecialchars($config['jefe_apellido'] ?: 'Apellido'); ?>,
<?php echo htmlspecialchars($config['jefe_nombre'] ?: 'Nombre'); ?>
</div>
<div class="preview-hierarchy" id="previewHierarchy">
<?php echo htmlspecialchars($config['jefe_gerarquia'] ?: 'Jerarquía del Jefe'); ?>
</div>
</div>
</form>
</div>
</div>
<!-- ==================== TARJETA: FIRMA DIGITAL ==================== -->
<div class="card">
<div class="card-header">
<i class="fas fa-signature"></i> Firma Digital del Jefe
</div>
<div class="card-body">
<div class="section-title">
<i class="fas fa-mobile-alt"></i> Firme Directamente Aquí
</div>
<div class="instructions">
<i class="fas fa-pen-nib"></i> <strong>Instrucciones para firmar:</strong>
<ol>
<li>Toque y arrastre su dedo sobre el recuadro para firmar</li>
<li>En dispositivos móviles: use su dedo directamente en la pantalla</li>
<li>En computadoras: use el mouse para dibujar su firma</li>
<li>La firma se guardará automáticamente con <strong>fondo transparente</strong></li>
</ol>
</div>
<div class="signature-container">
<canvas id="signatureCanvas" width="600" height="200"></canvas>
<div class="signature-actions">
<button type="button" class="btn btn-signature btn-clear" id="clearSignature">
<i class="fas fa-eraser"></i> Limpiar
</button>
<button type="button" class="btn btn-signature btn-undo" id="undoSignature">
<i class="fas fa-undo"></i> Deshacer
</button>
</div>
<div class="mobile-tip">
<i class="fas fa-hand-point-up"></i> <strong>Consejo móvil:</strong> Firme con movimientos suaves y lentos para mejor precisión
</div>
</div>
<!-- Vista previa de firma -->
<?php if (!empty($config['firma_path']) && file_exists('../uploads/firmas_jefe/' . $config['firma_path'])): ?>
<div class="signature-preview mt-3">
<div class="fw-bold mb-2 text-white"><i class="fas fa-image"></i> Firma actual:</div>
<img src="../uploads/firmas_jefe/<?php echo htmlspecialchars($config['firma_path']); ?>" alt="Firma del jefe">
</div>
<?php endif; ?>
<!-- Campo oculto para datos de la firma -->
<input type="hidden" name="signature_data" id="signatureData" form="configForm">
<button type="submit" form="configForm" class="btn btn-guardar">
<i class="fas fa-save"></i> Guardar Configuración para Credenciales
</button>
</div>
</div>
<!-- ✅ NUEVA SECCIÓN: CREAR CREDENCIALES PARA INSPECTORES -->
<div class="card inspector-card">
<div class="card-header">
<i class="fas fa-user-shield"></i> Crear Credencial para Inspector
</div>
<div class="card-body">
<div class="section-title">
<i class="fas fa-id-card"></i> Datos del Inspector
</div>
<form id="inspectorForm" enctype="multipart/form-data">
<input type="hidden" name="action" value="crear_credencial_inspector">
<div class="row g-3">
<!-- Foto Carnet -->
<div class="col-12">
<label class="form-label">
<i class="fas fa-image"></i> Foto Carnet del Inspector <span class="text-danger">*</span>
</label>
<div class="photo-upload-container" id="photoDropZone">
<i class="fas fa-cloud-upload-alt"></i>
<p class="mb-2">Haga clic o arrastre una foto aquí</p>
<small class="text-muted">Formatos: JPG, PNG, WebP - Máx. 5MB</small>
<input type="file" id="inspector_foto" name="inspector_foto" accept="image/jpeg,image/png,image/webp" required>
</div>
<img id="photoPreview" class="photo-preview" alt="Vista previa">
</div>
<!-- Apellido y Nombre -->
<div class="col-md-6">
<label for="inspector_apellido" class="form-label">
<i class="fas fa-id-badge"></i> Apellido <span class="text-danger">*</span>
</label>
<input type="text"
class="form-control"
id="inspector_apellido"
name="inspector_apellido"
placeholder="Ingrese apellido"
required>
</div>
<div class="col-md-6">
<label for="inspector_nombre" class="form-label">
<i class="fas fa-user"></i> Nombre <span class="text-danger">*</span>
</label>
<input type="text"
class="form-control"
id="inspector_nombre"
name="inspector_nombre"
placeholder="Ingrese nombre"
required>
</div>
<!-- Jerarquía -->
<div class="col-12">
<label for="inspector_gerarquia" class="form-label">
<i class="fas fa-sitemap"></i> Jerarquía <span class="text-danger">*</span>
</label>
<input type="text"
class="form-control"
id="inspector_gerarquia"
name="inspector_gerarquia"
placeholder="Ej: Inspector Principal, Inspector de Zona"
required>
</div>
<!-- Días de vencimiento del token -->
<div class="col-12">
<label for="dias_vencimiento" class="form-label">
<i class="fas fa-calendar-alt"></i> Vencimiento del Token de Verificación
</label>
<select class="form-control" id="dias_vencimiento" name="dias_vencimiento">
<option value="30">30 días</option>
<option value="90">90 días</option>
<option value="180">180 días</option>
<option value="365" selected>365 días (1 año)</option>
<option value="730">730 días (2 años)</option>
</select>
<div class="form-text text-white-50">
<i class="fas fa-info-circle"></i> Período de validez del enlace de verificación QR
</div>
</div>
</div>
<!-- Vista previa de credencial de inspector -->
<div class="preview-container mt-4">
<div class="preview-title"><i class="fas fa-eye"></i> Vista previa de credencial:</div>
<div class="preview-name" id="inspectorPreviewName">APELLIDO, Nombre</div>
<div class="preview-hierarchy" id="inspectorPreviewHierarchy">Jerarquía del Inspector</div>
<div id="inspectorPhotoPreviewSmall" class="signature-preview mt-3" style="display:none;">
<img id="inspectorPhotoPreviewImg" src="" alt="Foto">
</div>
</div>
<!-- Resultado QR (oculto inicialmente) -->
<div class="qr-result-container" id="qrResult">
<div class="preview-title"><i class="fas fa-qrcode"></i> QR Generado Exitosamente</div>
<img id="qrImage" src="" alt="Código QR">
<p class="text-white mb-2">Escanea este código para verificar la credencial</p>
<div class="qr-token-info">
<strong>Token:</strong> <code id="qrToken"></code><br>
<strong>Vencimiento:</strong> <code id="qrVencimiento"></code><br>
<strong>URL:</strong> <code id="qrUrl"></code>
</div>
<button type="button" class="btn btn-signature btn-undo mt-3" onclick="descargarQR()">
<i class="fas fa-download"></i> Descargar QR
</button>
<button type="button" class="btn btn-signature btn-clear mt-2" onclick="limpiarFormInspector()">
<i class="fas fa-plus"></i> Nueva Credencial
</button>
</div>
<button type="submit" class="btn btn-crear-inspector" id="btnCrearInspector">
<i class="fas fa-id-card"></i> Crear Credencial con QR
</button>
</form>
</div>
</div>
<!-- ✅ NUEVA SECCIÓN: LISTA DE INSPECTORES PARA GESTIÓN Y RENOVACIÓN -->
<div class="card inspectores-list-card">
<div class="card-header">
<i class="fas fa-list"></i> Gestión de Credenciales de Inspectores
</div>
<div class="card-body">
<div class="section-title">
<i class="fas fa-users"></i> Listado de Inspectores Registrados
</div>
<?php if (empty($inspectores_list)): ?>
<div class="text-center py-4">
<i class="fas fa-user-shield fa-3x text-muted mb-3"></i>
<p class="text-muted">No hay inspectores registrados aún.</p>
</div>
<?php else: ?>
<div class="row">
<?php foreach ($inspectores_list as $inspector):
$estado_real = $inspector['estado_real'] ?? $inspector['estado'];
$fecha_venc = new DateTime($inspector['fecha_vencimiento_token']);
$hoy = new DateTime();
$dias_restantes = $hoy->diff($fecha_venc)->days;
$es_vencido = $fecha_venc < $hoy;
?>
<div class="col-md-6 col-lg-4">
<div class="inspector-item <?php echo $es_vencido ? 'vencido' : ($estado_real === 'inactivo' ? 'inactivo' : ''); ?>">
<div class="inspector-header">
<div>
<div class="inspector-name"><?php echo htmlspecialchars($inspector['apellido'] . ', ' . $inspector['nombre']); ?></div>
<div class="inspector-gerarquia"><?php echo htmlspecialchars($inspector['gerarquia']); ?></div>
</div>
<span class="inspector-badge badge-<?php echo $estado_real; ?>">
<?php echo ucfirst($estado_real); ?>
</span>
</div>
<div class="inspector-info">
<i class="fas fa-key"></i> Token: <code><?php echo substr($inspector['token_verificacion'], 0, 8); ?>...</code><br>
<i class="fas fa-calendar-alt"></i> Vence: <?php echo date('d/m/Y H:i', strtotime($inspector['fecha_vencimiento_token'])); ?><br>
<?php if (!$es_vencido): ?>
<i class="fas fa-clock"></i> Restan: <?php echo $dias_restantes; ?> días
<?php else: ?>
<i class="fas fa-exclamation-triangle text-danger"></i> <span class="text-danger">VENCIDO</span>
<?php endif; ?>
</div>
<div class="inspector-actions mt-3">
<a href="subir_fondo_credencial.php?action=generar_qr_inspector&id=<?php echo $inspector['id']; ?>" class="btn-inspector-action btn-descargar-pdf" target="_blank" title="Descargar Credencial PDF">
<i class="fas fa-file-pdf"></i> PDF
</a>
<button type="button" class="btn-inspector-action btn-renovar" onclick="abrirModalRenovar(<?php echo $inspector['id']; ?>, '<?php echo htmlspecialchars($inspector['apellido'] . ', ' . $inspector['nombre']); ?>')" title="Renovar Token">
<i class="fas fa-sync-alt"></i> Renovar
</button>
<button type="button" class="btn-inspector-action btn-estado" onclick="cambiarEstadoInspector(<?php echo $inspector['id']; ?>, '<?php echo $estado_real; ?>')" title="Cambiar Estado">
<i class="fas fa-toggle-on"></i> Estado
</button>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<div class="text-center mt-3 mb-3">
<p class="text-muted mb-0">
<i class="fas fa-shield-alt"></i> Configuración segura para generación de credenciales del personal
</p>
</div>
</div>
</div>
</div>
<!-- ==================== MODAL RENOVAR TOKEN ==================== -->
<div class="modal fade" id="modalRenovarToken" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background: rgba(44, 62, 80, 0.95); border: 2px solid #f39c12; border-radius: 20px;">
<div class="modal-header" style="border-bottom: 1px solid #f39c12;">
<h5 class="modal-title text-white"><i class="fas fa-sync-alt me-2"></i>Renovar Token de Verificación</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" id="inspectorIdRenovar">
<div class="text-center mb-3">
<strong id="inspectorNombreRenovar" class="text-white fs-5"></strong>
</div>
<div class="mb-3">
<label class="form-label text-warning">Período de validez del nuevo token:</label>
<select class="form-control" id="diasVencimientoRenovar">
<option value="30">30 días</option>
<option value="90">90 días</option>
<option value="180">180 días</option>
<option value="365" selected>365 días (1 año)</option>
<option value="730">730 días (2 años)</option>
</select>
</div>
<div class="alert alert-warning" style="background: rgba(243, 156, 18, 0.2); border-color: #f39c12;">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>Atención:</strong> Al renovar el token, el anterior quedará invalidado inmediatamente.
</div>
<div id="resultadoRenovacion" class="qr-result-container" style="display: none; background: rgba(46, 204, 113, 0.15); border-color: #2ecc71;">
<div class="preview-title"><i class="fas fa-check-circle"></i> ¡Token Renovado!</div>
<img id="qrRenovado" src="" alt="Nuevo QR" style="max-width: 180px; margin: 10px auto; display: block; border-radius: 12px;">
<div class="qr-token-info">
<strong>Nuevo Token:</strong> <code id="nuevoToken"></code><br>
<strong>Nuevo Vencimiento:</strong> <code id="nuevoVencimiento"></code>
</div>
<button type="button" class="btn btn-signature btn-undo mt-2" onclick="descargarQRRenovado()">
<i class="fas fa-download"></i> Descargar Nuevo QR
</button>
</div>
</div>
<div class="modal-footer" style="border-top: 1px solid #f39c12;">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-renovar" id="btnConfirmarRenovacion" onclick="confirmarRenovacion()">
<i class="fas fa-sync-alt"></i> Confirmar Renovación
</button>
</div>
</div>
</div>
</div>
<!-- ==================== SCRIPTS ==================== -->
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="../js/responsive.js?v=<?php echo time(); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// ==================== CONFIGURAR CANVAS PARA FIRMA ====================
const canvas = document.getElementById('signatureCanvas');
const ctx = canvas.getContext('2d');
const clearButton = document.getElementById('clearSignature');
const undoButton = document.getElementById('undoSignature');
const signatureDataInput = document.getElementById('signatureData');
// Ajustar canvas para pantallas retina
const ratio = Math.max(window.devicePixelRatio || 1, 1);
canvas.width = canvas.offsetWidth * ratio;
canvas.height = canvas.offsetHeight * ratio;
ctx.scale(ratio, ratio);
// Estilo del trazo
ctx.strokeStyle = '#000000';
ctx.lineWidth = 2.5;
ctx.lineCap = 'round';
ctx.lineJoin = 'round';
ctx.fillStyle = 'transparent';
// Variables para el dibujo
let isDrawing = false;
let lastX = 0;
let lastY = 0;
const strokes = [];
let currentStroke = [];
// Inicializar canvas transparente
ctx.clearRect(0, 0, canvas.width, canvas.height);
// ==================== FUNCIONES DE DIBUJO ====================
function startDrawing(e) {
isDrawing = true;
const rect = canvas.getBoundingClientRect();
const x = (e.clientX || e.touches[0].clientX) - rect.left;
const y = (e.clientY || e.touches[0].clientY) - rect.top;
[lastX, lastY] = [x, y];
currentStroke = [{x, y}];
}
function draw(e) {
if (!isDrawing) return;
e.preventDefault();
const rect = canvas.getBoundingClientRect();
const x = (e.clientX || (e.touches && e.touches[0].clientX)) - rect.left;
const y = (e.clientY || (e.touches && e.touches[0].clientY)) - rect.top;
ctx.beginPath();
ctx.moveTo(lastX, lastY);
ctx.lineTo(x, y);
ctx.stroke();
[lastX, lastY] = [x, y];
currentStroke.push({x, y});
}
function stopDrawing() {
if (isDrawing && currentStroke.length > 1) {
strokes.push([...currentStroke]);
}
isDrawing = false;
currentStroke = [];
}
// ==================== EVENTOS PARA MOUSE ====================
canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseout', stopDrawing);
// ==================== EVENTOS PARA TOUCH (MÓVILES) ====================
canvas.addEventListener('touchstart', (e) => {
e.preventDefault();
startDrawing(e.touches[0]);
});
canvas.addEventListener('touchmove', (e) => {
e.preventDefault();
draw(e);
});
canvas.addEventListener('touchend', stopDrawing);
// ==================== LIMPIAR CANVAS ====================
clearButton.addEventListener('click', function() {
ctx.clearRect(0, 0, canvas.width, canvas.height);
strokes.length = 0;
currentStroke = [];
signatureDataInput.value = '';
});
// ==================== DESHACER ÚLTIMO TRAZO ====================
undoButton.addEventListener('click', function() {
if (strokes.length === 0) return;
ctx.clearRect(0, 0, canvas.width, canvas.height);
strokes.pop();
strokes.forEach(stroke => {
if (stroke.length > 1) {
ctx.beginPath();
ctx.moveTo(stroke[0].x, stroke[0].y);
for (let i = 1; i < stroke.length; i++) {
ctx.lineTo(stroke[i].x, stroke[i].y);
}
ctx.stroke();
}
});
});
// ==================== ACTUALIZAR VISTA PREVIA DE DATOS DEL JEFE ====================
['jefe_apellido', 'jefe_nombre', 'jefe_gerarquia'].forEach(id => {
document.getElementById(id)?.addEventListener('input', function() {
document.getElementById('previewName').innerHTML =
`${document.getElementById('jefe_apellido').value || 'Apellido'},<br>${document.getElementById('jefe_nombre').value || 'Nombre'}`;
document.getElementById('previewHierarchy').textContent =
document.getElementById('jefe_gerarquia').value || 'Jerarquía del Jefe';
});
});
// ==================== AL ENVIAR EL FORMULARIO ====================
document.getElementById('configForm').addEventListener('submit', function(e) {
const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
let isBlank = true;
for (let i = 3; i < imageData.data.length; i += 4) {
if (imageData.data[i] !== 0) {
isBlank = false;
break;
}
}
if (!isBlank) {
signatureDataInput.value = canvas.toDataURL('image/png');
} else if (!signatureDataInput.value && !<?php echo !empty($config['firma_path']) ? 'true' : 'false'; ?>) {
e.preventDefault();
alert('Por favor, firme en el recuadro antes de guardar');
return false;
}
});
// ==================== OPTIMIZACIÓN PARA MÓVILES ====================
if (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
setTimeout(() => {
canvas.scrollIntoView({behavior: 'smooth', block: 'center'});
}, 800);
}
// ==================== ✅ NUEVO: FUNCIONALIDAD PARA CREDENCIALES DE INSPECTORES ====================
const photoDropZone = document.getElementById('photoDropZone');
const photoInput = document.getElementById('inspector_foto');
const photoPreview = document.getElementById('photoPreview');
const inspectorForm = document.getElementById('inspectorForm');
const btnCrearInspector = document.getElementById('btnCrearInspector');
const qrResult = document.getElementById('qrResult');
// Click en zona de upload
photoDropZone.addEventListener('click', () => photoInput.click());
// Drag & drop
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
photoDropZone.addEventListener(eventName, preventDefaults, false);
});
function preventDefaults(e) {
e.preventDefault();
e.stopPropagation();
}
['dragenter', 'dragover'].forEach(eventName => {
photoDropZone.addEventListener(eventName, () => photoDropZone.style.borderColor = '#2980b9', false);
});
['dragleave', 'drop'].forEach(eventName => {
photoDropZone.addEventListener(eventName, () => photoDropZone.style.borderColor = '#3498db', false);
});
photoDropZone.addEventListener('drop', handleDrop, false);
function handleDrop(e) {
const dt = e.dataTransfer;
const files = dt.files;
if (files[0]) {
photoInput.files = files;
previewPhoto(files[0]);
}
}
photoInput.addEventListener('change', function(e) {
if (this.files[0]) {
previewPhoto(this.files[0]);
}
});
function previewPhoto(file) {
if (!file.type.match('image.*')) {
Swal.fire('Error', 'Por favor seleccione un archivo de imagen válido', 'error');
return;
}
if (file.size > 5 * 1024 * 1024) {
Swal.fire('Error', 'La imagen no debe superar los 5MB', 'error');
return;
}
const reader = new FileReader();
reader.onload = function(e) {
photoPreview.src = e.target.result;
photoPreview.style.display = 'block';
document.getElementById('inspectorPhotoPreviewImg').src = e.target.result;
document.getElementById('inspectorPhotoPreviewSmall').style.display = 'block';
};
reader.readAsDataURL(file);
}
// Actualizar vista previa de inspector
['inspector_apellido', 'inspector_nombre', 'inspector_gerarquia'].forEach(id => {
document.getElementById(id)?.addEventListener('input', function() {
const apellido = document.getElementById('inspector_apellido').value || 'APELLIDO';
const nombre = document.getElementById('inspector_nombre').value || 'Nombre';
const gerarquia = document.getElementById('inspector_gerarquia').value || 'Jerarquía del Inspector';
document.getElementById('inspectorPreviewName').textContent = `${apellido.toUpperCase()}, ${nombre}`;
document.getElementById('inspectorPreviewHierarchy').textContent = gerarquia;
});
});
// Submit del formulario de inspector
inspectorForm.addEventListener('submit', function(e) {
e.preventDefault();
if (!photoInput.files[0]) {
Swal.fire('Atención', 'Debe seleccionar una foto para el inspector', 'warning');
return;
}
const formData = new FormData(inspectorForm);
btnCrearInspector.disabled = true;
btnCrearInspector.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
Swal.fire({
title: 'Creando Credencial',
text: 'Generando token de verificación y código QR...',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading() }
});
fetch('subir_fondo_credencial.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
// Mostrar resultado QR
document.getElementById('qrImage').src = data.qr_url;
document.getElementById('qrToken').textContent = data.token;
document.getElementById('qrVencimiento').textContent = data.fecha_vencimiento;
document.getElementById('qrUrl').textContent = data.verification_url;
qrResult.style.display = 'block';
// Scroll al resultado
qrResult.scrollIntoView({behavior: 'smooth', block: 'center'});
Swal.fire({
icon: 'success',
title: '¡Credencial Creada!',
text: 'El código QR ha sido generado exitosamente',
confirmButtonText: 'Aceptar'
});
} else {
Swal.fire('Error', data.message, 'error');
}
})
.catch(error => {
Swal.fire('Error de conexión', error.message, 'error');
})
.finally(() => {
btnCrearInspector.disabled = false;
btnCrearInspector.innerHTML = '<i class="fas fa-id-card"></i> Crear Credencial con QR';
});
});
});
// Función para descargar QR
function descargarQR() {
const qrImage = document.getElementById('qrImage');
const link = document.createElement('a');
link.download = 'credencial_inspector_qr.png';
link.href = qrImage.src;
link.click();
}
// Limpiar formulario de inspector para nueva carga
function limpiarFormInspector() {
document.getElementById('inspectorForm').reset();
document.getElementById('photoPreview').style.display = 'none';
document.getElementById('inspectorPhotoPreviewSmall').style.display = 'none';
document.getElementById('inspectorPreviewName').textContent = 'APELLIDO, Nombre';
document.getElementById('inspectorPreviewHierarchy').textContent = 'Jerarquía del Inspector';
qrResult.style.display = 'none';
photoDropZone.style.borderColor = '#3498db';
}
// ==================== ✅ FUNCIONES DE REINICIO DE PAGOS ====================
function reiniciarPagosSucursales() {
Swal.fire({
title: '⚠️ ¿Reiniciar Pagos de Sucursales?',
html: `
<div class="text-start">
<p>Esta acción <strong>NO se puede deshacer</strong>.</p>
<p>Se reiniciarán los siguientes campos en TODAS las sucursales:</p>
<ul>
<li><code>pago_arancel = 0</code></li>
<li><code>fecha_pago_arancel = NULL</code></li>
</ul>
<p class="text-danger"><i class="fas fa-exclamation-triangle"></i> ¡Asegúrese de tener un respaldo antes de continuar!</p>
</div>
`,
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#e74c3c',
cancelButtonColor: '#6c757d',
confirmButtonText: '<i class="fas fa-sync-alt"></i> Sí, Reiniciar',
cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
reverseButtons: true
}).then((result) => {
if (result.isConfirmed) {
Swal.fire({
title: 'Procesando...',
text: 'Reiniciando pagos de sucursales',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading() }
});
const formData = new FormData();
formData.append('action', 'reiniciar_pagos_sucursales');
fetch('subir_fondo_credencial.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({
icon: 'success',
title: '¡Reiniciado!',
text: data.message,
confirmButtonText: 'Aceptar'
}).then(() => {
location.reload();
});
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.message
});
}
})
.catch(error => {
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: error.message
});
});
}
});
}
function reiniciarPagosPersonal() {
Swal.fire({
title: '⚠️ ¿Reiniciar Pagos de Personal?',
html: `
<div class="text-start">
<p>Esta acción <strong>NO se puede deshacer</strong>.</p>
<p>Se reiniciarán los siguientes campos en TODO el personal:</p>
<ul>
<li><code>pago_credencial = 0</code></li>
<li><code>fecha_pago_credencial = NULL</code></li>
<li><code>fecha_vencimiento = NULL</code></li>
</ul>
<p class="text-danger"><i class="fas fa-exclamation-triangle"></i> ¡Asegúrese de tener un respaldo antes de continuar!</p>
</div>
`,
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#e74c3c',
cancelButtonColor: '#6c757d',
confirmButtonText: '<i class="fas fa-sync-alt"></i> Sí, Reiniciar',
cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
reverseButtons: true
}).then((result) => {
if (result.isConfirmed) {
Swal.fire({
title: 'Procesando...',
text: 'Reiniciando pagos de personal',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading() }
});
const formData = new FormData();
formData.append('action', 'reiniciar_pagos_personal');
fetch('subir_fondo_credencial.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({
icon: 'success',
title: '¡Reiniciado!',
text: data.message,
confirmButtonText: 'Aceptar'
}).then(() => {
location.reload();
});
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.message
});
}
})
.catch(error => {
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: error.message
});
});
}
});
}
// ==================== ✅ NUEVAS FUNCIONES PARA GESTIÓN DE INSPECTORES ====================
function abrirModalRenovar(inspectorId, inspectorNombre) {
document.getElementById('inspectorIdRenovar').value = inspectorId;
document.getElementById('inspectorNombreRenovar').textContent = inspectorNombre;
document.getElementById('resultadoRenovacion').style.display = 'none';
document.getElementById('btnConfirmarRenovacion').disabled = false;
document.getElementById('btnConfirmarRenovacion').innerHTML = '<i class="fas fa-sync-alt"></i> Confirmar Renovación';
const modal = new bootstrap.Modal(document.getElementById('modalRenovarToken'));
modal.show();
}
function confirmarRenovacion() {
const inspectorId = document.getElementById('inspectorIdRenovar').value;
const diasVencimiento = document.getElementById('diasVencimientoRenovar').value;
const btnConfirmar = document.getElementById('btnConfirmarRenovacion');
btnConfirmar.disabled = true;
btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
Swal.fire({
title: 'Renovando Token',
text: 'Generando nuevo token de verificación...',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading() }
});
const formData = new FormData();
formData.append('action', 'renovar_token_inspector');
formData.append('inspector_id', inspectorId);
formData.append('dias_vencimiento', diasVencimiento);
fetch('subir_fondo_credencial.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
// Mostrar resultado de renovación
document.getElementById('qrRenovado').src = data.qr_url;
document.getElementById('nuevoToken').textContent = data.token;
document.getElementById('nuevoVencimiento').textContent = data.fecha_vencimiento;
document.getElementById('resultadoRenovacion').style.display = 'block';
btnConfirmar.innerHTML = '<i class="fas fa-check"></i> Renovado';
Swal.fire({
icon: 'success',
title: '¡Token Renovado!',
text: 'El nuevo código QR ha sido generado exitosamente',
confirmButtonText: 'Aceptar'
}).then(() => {
setTimeout(() => location.reload(), 1000);
});
} else {
Swal.fire('Error', data.message, 'error');
btnConfirmar.disabled = false;
btnConfirmar.innerHTML = '<i class="fas fa-sync-alt"></i> Confirmar Renovación';
}
})
.catch(error => {
Swal.fire('Error de conexión', error.message, 'error');
btnConfirmar.disabled = false;
btnConfirmar.innerHTML = '<i class="fas fa-sync-alt"></i> Confirmar Renovación';
});
}
function descargarQRRenovado() {
const qrImage = document.getElementById('qrRenovado');
const link = document.createElement('a');
link.download = 'credencial_inspector_nuevo_qr.png';
link.href = qrImage.src;
link.click();
}
function cambiarEstadoInspector(inspectorId, estadoActual) {
const nuevoEstado = estadoActual === 'activo' ? 'inactivo' : 'activo';
const textoConfirmacion = `¿Está seguro de cambiar el estado a "${nuevoEstado.toUpperCase()}"?`;
Swal.fire({
title: 'Cambiar Estado',
text: textoConfirmacion,
icon: 'question',
showCancelButton: true,
confirmButtonText: 'Sí, cambiar',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#3498db',
cancelButtonColor: '#6c757d'
}).then((result) => {
if (result.isConfirmed) {
const formData = new FormData();
formData.append('action', 'cambiar_estado_inspector');
formData.append('inspector_id', inspectorId);
formData.append('estado', nuevoEstado);
fetch('subir_fondo_credencial.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({
icon: 'success',
title: 'Estado Actualizado',
text: data.message,
timer: 2000,
showConfirmButton: false
}).then(() => {
location.reload();
});
} else {
Swal.fire('Error', data.message, 'error');
}
})
.catch(error => {
Swal.fire('Error de conexión', error.message, 'error');
});
}
});
}
</script>
</body>
</html>