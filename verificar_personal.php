<?php
// verificar_personal.php - Página pública de verificación (NO REQUIERE LOGIN)
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/html; charset=utf-8');
$personal_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$personal_id) {
http_response_code(400);
die('<h1 style="text-align:center;color:#e74c3c;padding:50px;font-family:Arial,sans-serif">❌ ID de personal no válido</h1>');
}
// ==================== VALIDACIÓN DE TOKEN DE SEGURIDAD ====================
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$received_token = $_GET['token'] ?? '';
$expiracion_timestamp = isset($_GET['exp']) && is_numeric($_GET['exp']) ? (int)$_GET['exp'] : 0;
if (empty($received_token) || $expiracion_timestamp <= 0) {
http_response_code(403);
die('<h1 style="text-align:center;color:#e74c3c;padding:50px;font-family:Arial,sans-serif">🔒 Acceso denegado: Parámetros de seguridad incompletos</h1><p style="text-align:center;color:#7f8c8d;font-family:Arial,sans-serif">La URL debe incluir: ?id=XXX&token=YYY&exp=ZZZ</p>');
}
// Verificar que la expiración esté dentro del rango válido (380 días desde ahora)
$now = time();
$min_timestamp = $now; // No aceptar tokens con expiración en el pasado
$max_timestamp = $now + (380 * 24 * 60 * 60); // Máximo 380 días en el futuro
if ($expiracion_timestamp < $min_timestamp || $expiracion_timestamp > $max_timestamp) {
http_response_code(403);
die('<h1 style="text-align:center;color:#e74c3c;padding:50px;font-family:Arial,sans-serif">🔒 Acceso denegado: Token expirado o con fecha inválida</h1><p style="text-align:center;color:#7f8c8d;font-family:Arial,sans-serif">Timestamp: ' . $expiracion_timestamp . ' | Ahora: ' . $now . '</p>');
}
// Validar firma HMAC: payload = personal_id|expiracion_timestamp
$payload = $personal_id . '|' . $expiracion_timestamp;
$expected_token = hash_hmac('sha256', $payload, $secret_key);
if (!hash_equals($expected_token, $received_token)) {
http_response_code(403);
die('<h1 style="text-align:center;color:#e74c3c;padding:50px;font-family:Arial,sans-serif">🔒 Acceso denegado: Firma de seguridad inválida</h1><p style="text-align:center;color:#7f8c8d;font-family:Arial,sans-serif">Token recibido: ' . substr($received_token, 0, 10) . '...</p>');
}
// ==================== FIN VALIDACIÓN DE TOKEN ====================
$conn = getDBConnection();
// Obtener datos públicos del personal
$stmt = $conn->prepare("
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio,
e.localidad as empresa_localidad
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.id = :id
");
$stmt->execute(['id' => $personal_id]);
$personal = $stmt->fetch();
if (!$personal) {
http_response_code(404);
die('<h1 style="text-align:center;color:#e74c3c;padding:50px;font-family:Arial,sans-serif">❌ Personal no encontrado</h1>');
}
// Verificar estado actual
$esta_activo = $personal['activo'] && !$personal['baja'];
$fecha_verificacion = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verificación de Personal - Policía de Chubut</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* {
margin: 0;
padding: 0;
box-sizing: border-box;
}
body {
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
background: linear-gradient(135deg, #1a2a6c, #2c3e50);
color: #333;
line-height: 1.6;
padding: 20px;
min-height: 100vh;
}
.container {
max-width: 900px;
margin: 0 auto;
background: white;
border-radius: 20px;
box-shadow: 0 15px 50px rgba(0, 0, 0, 0.5);
overflow: hidden;
}
.header {
background: linear-gradient(135deg, #2c3e50, #1a2530);
color: white;
text-align: center;
padding: 30px 20px;
position: relative;
}
.header h1 {
font-size: 28px;
margin-bottom: 10px;
letter-spacing: 1px;
text-shadow: 0 2px 5px rgba(0,0,0,0.3);
}
.header h2 {
font-size: 20px;
font-weight: 300;
margin-top: 5px;
opacity: 0.9;
}
.seal {
position: absolute;
top: 20px;
right: 20px;
width: 80px;
height: 80px;
background: rgba(255, 255, 255, 0.15);
border: 3px solid rgba(255, 255, 255, 0.3);
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
font-weight: bold;
font-size: 11px;
text-align: center;
line-height: 1.2;
text-shadow: 0 1px 2px rgba(0,0,0,0.5);
}
.content {
padding: 40px;
}
.status-badge {
text-align: center;
padding: 25px;
border-radius: 15px;
margin: 25px 0;
font-weight: bold;
font-size: 28px;
text-transform: uppercase;
letter-spacing: 2px;
box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
animation: pulse 2s infinite;
}
@keyframes pulse {
0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); }
70% { box-shadow: 0 0 0 10px rgba(255, 255, 255, 0); }
100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
}
.status-activo {
background: linear-gradient(135deg, #27ae60, #219653);
color: white;
border: 3px solid #2ecc71;
}
.status-inactivo {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
border: 3px solid #e74c3c;
}
.section {
margin-bottom: 30px;
padding-bottom: 25px;
border-bottom: 2px dashed #ecf0f1;
}
.section:last-child {
border-bottom: none;
margin-bottom: 0;
padding-bottom: 0;
}
.section-title {
display: flex;
align-items: center;
margin-bottom: 15px;
color: #2c3e50;
font-size: 20px;
font-weight: 700;
}
.section-title i {
margin-right: 10px;
font-size: 24px;
color: #3498db;
}
.info-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
gap: 15px;
}
.info-item {
background: #f8f9fa;
padding: 15px;
border-radius: 10px;
border-left: 4px solid #3498db;
transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.info-item:hover {
transform: translateY(-3px);
box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.info-label {
font-weight: 600;
color: #2c3e50;
margin-bottom: 5px;
font-size: 14px;
text-transform: uppercase;
letter-spacing: 0.5px;
}
.info-value {
font-size: 18px;
color: #2c3e50;
font-weight: 700;
word-break: break-word;
}
.certification-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
gap: 15px;
margin-top: 15px;
}
.certification-item {
text-align: center;
padding: 15px;
border-radius: 10px;
font-weight: 600;
transition: transform 0.3s ease;
}
.certification-item:hover {
transform: scale(1.05);
}
.cert-yes {
background: linear-gradient(135deg, #27ae60, #219653);
color: white;
box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);
}
.cert-no {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
box-shadow: 0 4px 10px rgba(231, 76, 60, 0.3);
}
.equipment-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
gap: 15px;
margin-top: 15px;
}
.equipment-item {
text-align: center;
padding: 12px;
border-radius: 10px;
font-weight: 600;
}
.equipment-yes {
background: linear-gradient(135deg, #27ae60, #219653);
color: white;
}
.equipment-no {
background: linear-gradient(135deg, #95a5a6, #7f8c8d);
color: white;
}
.verification-info {
background: linear-gradient(135deg, #3498db, #2980b9);
color: white;
padding: 25px;
border-radius: 15px;
margin-top: 20px;
text-align: center;
}
.verification-info h3 {
margin-bottom: 10px;
font-size: 22px;
display: flex;
align-items: center;
justify-content: center;
gap: 10px;
}
.verification-info p {
font-size: 18px;
margin: 5px 0;
font-weight: 500;
}
.footer {
text-align: center;
padding: 25px;
background: #f8f9fa;
color: #7f8c8d;
font-size: 14px;
border-top: 2px solid #ecf0f1;
}
.qr-container {
text-align: center;
margin: 30px 0;
background: #f8f9fa;
padding: 25px;
border-radius: 15px;
border: 2px dashed #3498db;
}
.qr-container img {
max-width: 200px;
border: 3px solid #3498db;
border-radius: 15px;
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
background: white;
padding: 10px;
}
.qr-container p {
margin-top: 15px;
color: #7f8c8d;
font-style: italic;
font-size: 16px;
}
.foto-container {
text-align: center;
margin: 20px 0;
}
.foto-container img {
width: 150px;
height: 150px;
border-radius: 50%;
border: 4px solid #3498db;
object-fit: cover;
box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
@media (max-width: 768px) {
.content {
padding: 25px;
}
.header h1 {
font-size: 24px;
}
.status-badge {
font-size: 22px;
padding: 20px;
}
.info-grid {
grid-template-columns: 1fr;
}
.certification-grid {
grid-template-columns: repeat(2, 1fr);
}
}
</style>
</head>
<body>
<div class="container">
<div class="header">
<div class="seal">
VERIFICACIÓN<br>OFICIAL<br>POLICÍA<br>CHUBUT
</div>
<h1><i class="fas fa-user-shield"></i> SISTEMA DE VERIFICACIÓN DE PERSONAL</h1>
<h2>Área Investigaciones (D.S.) - Agencias Privadas de Seguridad</h2>
</div>
<div class="content">
<div class="status-badge <?php echo $esta_activo ? 'status-activo' : 'status-inactivo'; ?>">
<i class="fas fa-<?php echo $esta_activo ? 'user-check' : 'user-times'; ?> fa-2x"></i>
<div style="margin-top: 10px;">
<?php echo $esta_activo ? 'PERSONAL ACTIVO Y HABILITADO' : 'PERSONAL NO HABILITADO'; ?>
</div>
</div>
<?php if (!empty($personal['foto'])): ?>
<div class="foto-container">
<img src="uploads/fotos_personal/<?php echo $personal['foto']; ?>" alt="Foto de <?php echo htmlspecialchars($personal['nombre'] . ' ' . $personal['apellido']); ?>">
</div>
<?php endif; ?>
<div class="section">
<div class="section-title">
<i class="fas fa-id-card"></i>
<span>Datos Personales</span>
</div>
<div class="info-grid">
<div class="info-item">
<div class="info-label">Nombre y Apellido</div>
<div class="info-value"><?php echo htmlspecialchars($personal['nombre'] . ' ' . $personal['apellido']); ?></div>
</div>
<div class="info-item">
<div class="info-label">DNI</div>
<div class="info-value"><?php echo htmlspecialchars($personal['dni']); ?></div>
</div>
<div class="info-item">
<div class="info-label">Cargo</div>
<div class="info-value"><?php echo htmlspecialchars($personal['cargo']); ?></div>
</div>
<div class="info-item">
<div class="info-label">Fecha de Ingreso</div>
<div class="info-value"><?php echo $personal['fecha_ingreso'] ? date('d/m/Y', strtotime($personal['fecha_ingreso'])) : 'No registrada'; ?></div>
</div>
</div>
</div>
<div class="section">
<div class="section-title">
<i class="fas fa-building"></i>
<span>Empresa y Sucursal</span>
</div>
<div class="info-grid">
<div class="info-item">
<div class="info-label">Empresa</div>
<div class="info-value"><?php echo htmlspecialchars($personal['empresa_nombre']); ?></div>
</div>
<div class="info-item">
<div class="info-label">Sucursal</div>
<div class="info-value"><?php echo htmlspecialchars($personal['sucursal_nombre']); ?></div>
</div>
<div class="info-item">
<div class="info-label">CUIT Empresa</div>
<div class="info-value"><?php echo htmlspecialchars($personal['empresa_cuit'] ?? 'No registrado'); ?></div>
</div>
<div class="info-item">
<div class="info-label">Domicilio Empresa</div>
<div class="info-value"><?php echo htmlspecialchars($personal['empresa_domicilio'] . ' - ' . $personal['empresa_localidad']); ?></div>
</div>
</div>
</div>
<div class="section">
<div class="section-title">
<i class="fas fa-heartbeat"></i>
<span>Certificaciones Médicas y Psicológicas</span>
</div>
<div class="certification-grid">
<div class="certification-item <?php echo !empty($personal['apto_fisico']) ? 'cert-yes' : 'cert-no'; ?>">
<i class="fas fa-user-md fa-2x"></i>
<div style="margin-top: 10px; font-size: 16px;">Apto Físico</div>
<div style="font-size: 14px; margin-top: 5px;">
<?php echo !empty($personal['apto_fisico']) ? '<i class="fas fa-check"></i> Sí' : '<i class="fas fa-times"></i> No'; ?>
</div>
</div>
<div class="certification-item <?php echo !empty($personal['apto_psicologico']) ? 'cert-yes' : 'cert-no'; ?>">
<i class="fas fa-brain fa-2x"></i>
<div style="margin-top: 10px; font-size: 16px;">Apto Psicológico</div>
<div style="font-size: 14px; margin-top: 5px;">
<?php echo !empty($personal['apto_psicologico']) ? '<i class="fas fa-check"></i> Sí' : '<i class="fas fa-times"></i> No'; ?>
</div>
</div>
<div class="certification-item <?php echo !empty($personal['tiene_certificado']) ? 'cert-yes' : 'cert-no'; ?>">
<i class="fas fa-certificate fa-2x"></i>
<div style="margin-top: 10px; font-size: 16px;">Certificado</div>
<div style="font-size: 14px; margin-top: 5px;">
<?php echo !empty($personal['tiene_certificado']) ? '<i class="fas fa-check"></i> Presente' : '<i class="fas fa-times"></i> Ausente'; ?>
</div>
</div>
<div class="certification-item <?php echo !empty($personal['tiene_penales']) ? 'cert-yes' : 'cert-no'; ?>">
<i class="fas fa-gavel fa-2x"></i>
<div style="margin-top: 10px; font-size: 16px;">Ant. Penales</div>
<div style="font-size: 14px; margin-top: 5px;">
<?php echo !empty($personal['tiene_penales']) ? '<i class="fas fa-check"></i> Sin Ant.' : '<i class="fas fa-times"></i> Con Ant.'; ?>
</div>
</div>
</div>
</div>
<div class="section">
<div class="section-title">
<i class="fas fa-hard-hat"></i>
<span>Equipamiento Asignado</span>
</div>
<div class="equipment-grid">
<div class="equipment-item <?php echo !empty($personal['chaleco']) ? 'equipment-yes' : 'equipment-no'; ?>">
<i class="fas fa-vest fa-2x"></i>
<div>Chaleco</div>
</div>
<div class="equipment-item <?php echo !empty($personal['armamento']) ? 'equipment-yes' : 'equipment-no'; ?>">
<i class="fas fa-gun fa-2x"></i>
<div>Armamento</div>
</div>
<div class="equipment-item <?php echo !empty($personal['equipo_comunicacion']) ? 'equipment-yes' : 'equipment-no'; ?>">
<i class="fas fa-headset fa-2x"></i>
<div>Comunicación</div>
</div>
</div>
</div>
<div class="verification-info">
<h3><i class="fas fa-sync-alt"></i> Información Actualizada en Tiempo Real</h3>
<p><strong>Última Actualización:</strong> <?php echo $fecha_verificacion; ?></p>
<p style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 10px; border-radius: 8px;">
<i class="fas fa-info-circle"></i> Esta información se sincroniza directamente con la base de datos oficial de la Policía de Chubut
</p>
</div>
<div class="qr-container">
<h3><i class="fas fa-qrcode"></i> Código QR de Verificación</h3>
<img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" alt="QR de verificación">
<p>Escanee este código para acceder a esta página de verificación en cualquier momento</p>
</div>
</div>
<div class="footer">
<p><strong><i class="fas fa-balance-scale"></i> Policía de Chubut - Área Investigaciones (D.S.)</strong></p>
<p><i class="fas fa-user-shield"></i> Sistema de Gestión de Agencias Privadas de Seguridad</p>
<p><i class="fas fa-file-signature"></i> Documento generado electrónicamente - Válido sin firma física</p>
<p style="margin-top: 10px; font-weight: bold; color: #2c3e50;">
<i class="fas fa-copyright"></i> <?php echo date('Y'); ?> Todos los derechos reservados - Ministerio de Seguridad de la Provincia del Chubut
</p>
<p style="margin-top: 8px; font-size: 13px; color: #95a5a6;">
Para consultas: area.investigaciones@policia.chubut.gov.ar | Tel: 280-489658977
</p>
</div>
</div>
<script>
// Auto-refresh cada 5 minutos para mantener datos actualizados
setTimeout(function() {
location.reload(true);
}, 300000);
</script>
</body>
</html>