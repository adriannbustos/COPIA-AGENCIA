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
<div class="text-center mt-3 mb-3">
<p class="text-muted mb-0">
<i class="fas fa-shield-alt"></i> Configuración segura para generación de credenciales del personal
</p>
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
});
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
</script>
</body>
</html>