<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/session_manager.php';  // ← Ruta relativa desde admin/ o empresa/
requireValidSession();
// Verificar autenticación y rol
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['rol'] !== 'empresa') {
header('Location: ../login.php');
exit;
}
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$empresa_id = $user['empresa_id'];
// ✅ VARIABLES REQUERIDAS PARA HEADER Y SIDEBAR (ANTES DE LOS INCLUDES)
$page_title = 'Dashboard Empresa';
$page_icon = 'fas fa-tachometer-alt';
$current_page = 'dashboard';
// ==================== OBTENER ESTADO DE TRÁMITES ====================
$tramites_activos = [];
try {
$stmt = $conn->prepare("
SELECT t.*, e.nombre as empresa_nombre
FROM tramites_empresa t
LEFT JOIN empresas e ON t.empresa_id = e.id
WHERE t.empresa_id = :empresa_id
ORDER BY FIELD(estado, 'en_tramite', 'aprobado', 'rechazado', 'fuera_tiempo'), fecha_solicitud DESC
LIMIT 5
");
$stmt->execute([':empresa_id' => $empresa_id]);
$tramites_activos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
// Silencioso si la tabla no existe aún o error temporal
}
// ==================== OBTENER ESTADÍSTICAS DE SUCURSALES ====================
$sucursales_stats = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0, 'total' => 0];
try {
$stmt = $conn->prepare("
SELECT
COUNT(*) as total,
SUM(CASE WHEN estado_aprobacion = 'pendiente' OR estado_aprobacion IS NULL THEN 1 ELSE 0 END) as pendiente,
SUM(CASE WHEN estado_aprobacion = 'aprobado' THEN 1 ELSE 0 END) as aprobado,
SUM(CASE WHEN estado_aprobacion = 'rechazado' THEN 1 ELSE 0 END) as rechazado
FROM sucursales
WHERE empresa_id = :empresa_id
");
$stmt->execute([':empresa_id' => $empresa_id]);
$sucursales_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
// Silencioso
}
// ==================== OBTENER ESTADÍSTICAS DE RECURSOS ====================
$recursos_stats = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0, 'total' => 0];
try {
$stmt = $conn->prepare("
SELECT
COUNT(*) as total,
SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendiente,
SUM(CASE WHEN estado = 'aprobado' THEN 1 ELSE 0 END) as aprobado,
SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) as rechazado
FROM recursos_sucursal
WHERE empresa_id = :empresa_id
");
$stmt->execute([':empresa_id' => $empresa_id]);
$recursos_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
// Silencioso
}
// ==================== OBTENER ESTADÍSTICAS DE SERVICIOS ====================
$servicios_stats = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0, 'total' => 0];
try {
$stmt = $conn->prepare("
SELECT
COUNT(*) as total,
SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendiente,
SUM(CASE WHEN estado = 'aprobado' OR estado = 'activo' THEN 1 ELSE 0 END) as aprobado,
SUM(CASE WHEN estado = 'rechazado' OR estado = 'cancelado' THEN 1 ELSE 0 END) as rechazado
FROM servicios
WHERE empresa_id = :empresa_id
");
$stmt->execute([':empresa_id' => $empresa_id]);
$servicios_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
// Silencioso
}
// ==================== OBTENER ESTADÍSTICAS DE DOCUMENTACIÓN DE PERSONAL ====================
$documentacion_stats = ['pendiente' => 0, 'aprobada' => 0, 'rechazada' => 0, 'total' => 0];
try {
$stmt = $conn->prepare("
SELECT
COUNT(*) as total,
SUM(CASE WHEN estado_documentacion = 'pendiente' OR estado_documentacion IS NULL THEN 1 ELSE 0 END) as pendiente,
SUM(CASE WHEN estado_documentacion = 'aprobada' THEN 1 ELSE 0 END) as aprobada,
SUM(CASE WHEN estado_documentacion = 'rechazada' THEN 1 ELSE 0 END) as rechazada
FROM personal
WHERE empresa_id = :empresa_id
");
$stmt->execute([':empresa_id' => $empresa_id]);
$documentacion_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
// Silencioso
}
// ============================================================================
// ✅ NUEVO: OBTENER ESTADÍSTICAS DE DOCUMENTOS CARGADOS (INFORME SUCURSALES)
// ============================================================================
$documentos_cargados_stats = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0, 'total' => 0];
try {
// Verificar si existe la tabla documentos_sucursales
$tableCheck = $conn->query("SHOW TABLES LIKE 'documentos_sucursales'");
if ($tableCheck->rowCount() > 0) {
$stmt = $conn->prepare("
SELECT
COUNT(*) as total,
SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendiente,
SUM(CASE WHEN estado = 'aprobado' THEN 1 ELSE 0 END) as aprobado,
SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) as rechazado
FROM documentos_sucursales
WHERE empresa_id = :empresa_id
");
$stmt->execute([':empresa_id' => $empresa_id]);
$documentos_cargados_stats = $stmt->fetch(PDO::FETCH_ASSOC);
}
} catch (PDOException $e) {
// Silencioso si la tabla no existe
$documentos_cargados_stats = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0, 'total' => 0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Empresa - Seguridad</title>
<!-- Mantener CDN para Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Pero usar locales para Bootstrap y SweetAlert2 -->
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ✅ ESTILOS ESPECÍFICOS DEL DASHBOARD (sin duplicar header/sidebar) */
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
.stat-card {
padding: 10px 12px;
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
.stat-card {
padding: 15px 20px;
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
.stat-card {
padding: 10px 12px;
margin-bottom: 15px;
}
.stat-number {
font-size: 1.3rem;
}
.action-card {
padding: 20px;
}
.tramites-panel,
.estados-resumen-panel {
padding: 15px 20px;
}
.table-responsive {
font-size: 0.85rem;
}
.urgency-alert {
right: 10px;
left: 10px;
max-width: none;
}
}
/* ✅ CELULARS PEQUEÑOS (< 576px) */
@media (max-width: 576px) {
.main-content-wrapper {
padding-left: 10px;
padding-right: 10px;
padding-top: 70px;
}
.estado-badges {
flex-direction: column;
gap: 8px;
}
.estado-badge {
justify-content: center;
}
.tramitas-header {
flex-direction: column;
gap: 10px;
align-items: flex-start;
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
/* ✅ ESTADÍSTICAS */
.stat-card {
background: white;
border-radius: 8px;
padding: 12px 15px;
box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
transition: all 0.3s ease;
height: 100%;
border: 1px solid rgba(0,0,0,0.03);
}
.stat-card:hover {
transform: translateY(-2px);
box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
border-color: rgba(0,0,0,0.08);
}
.stat-icon {
width: 45px;
height: 45px;
border-radius: 8px;
display: flex;
align-items: center;
justify-content: center;
font-size: 1.1rem;
margin-bottom: 8px;
}
.stat-number {
font-size: 1.5rem;
font-weight: 600;
color: #34495e;
line-height: 1.2;
}
.stat-label {
font-size: 0.75rem;
color: #95a5a6;
text-transform: uppercase;
font-weight: 500;
letter-spacing: 0.5px;
margin-top: 2px;
}
.action-card {
background: white;
border-radius: 15px;
padding: 30px;
box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
transition: all 0.3s ease;
height: 100%;
text-align: center;
border: 2px solid transparent;
}
.action-card:hover {
transform: translateY(-5px);
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
border-color: #4361ee;
}
.action-icon {
width: 80px;
height: 80px;
border-radius: 20px;
display: flex;
align-items: center;
justify-content: center;
font-size: 2.5rem;
margin: 0 auto 20px;
}
.btn-action {
padding: 12px 30px;
border-radius: 10px;
font-weight: 600;
transition: all 0.3s ease;
}
.informes-table {
background: white;
border-radius: 15px;
overflow: hidden;
box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
}
.informes-table thead {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
}
.tramites-panel {
background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
border-radius: 15px;
padding: 25px 30px;
margin-bottom: 30px;
box-shadow: 0 8px 30px rgba(67, 97, 238, 0.15);
border-left: 5px solid #4361ee;
margin-top: 20px;
}
.estados-resumen-panel {
background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
border-radius: 15px;
padding: 25px 30px;
margin-bottom: 30px;
box-shadow: 0 8px 30px rgba(67, 97, 238, 0.15);
border-left: 5px solid #27ae60;
}
.estado-item {
background: white;
border-radius: 12px;
padding: 20px;
margin-bottom: 15px;
box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
border: 1px solid rgba(0,0,0,0.05);
transition: all 0.3s ease;
}
.estado-item:hover {
transform: translateY(-3px);
box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
}
.estado-item h6 {
font-weight: 700;
color: #2c3e50;
margin-bottom: 15px;
display: flex;
align-items: center;
gap: 10px;
}
.estado-badges {
display: flex;
gap: 10px;
flex-wrap: wrap;
}
.estado-badge {
padding: 8px 15px;
border-radius: 20px;
font-size: 0.85rem;
font-weight: 600;
display: flex;
align-items: center;
gap: 8px;
}
.estado-badge-pendiente {
background: linear-gradient(135deg, #fff3cd, #ffeaa7);
color: #856404;
border: 1px solid #f39c12;
}
.estado-badge-aprobado {
background: linear-gradient(135deg, #d4edda, #c3e6cb);
color: #155724;
border: 1px solid #27ae60;
}
.estado-badge-rechazado {
background: linear-gradient(135deg, #f8d7da, #f5c6cb);
color: #721c24;
border: 1px solid #e74c3c;
}
.estado-badge-total {
background: linear-gradient(135deg, #e3f2fd, #bbdefb);
color: #0d47a1;
border: 1px solid #2196f3;
}
.notification-status {
font-size: 0.85em;
margin-left: 5px;
}
.status-notified {
color: #27ae60;
}
.status-notnotified {
color: #e74c3c;
}
.tramite-badge {
padding: 4px 8px;
border-radius: 12px;
font-size: 0.7rem;
font-weight: 700;
}
.tramite-badge-en_tramite {
background: linear-gradient(135deg, #17a2b8, #138496);
color: white;
}
.tramite-badge-aprobado {
background: linear-gradient(135deg, #28a745, #218838);
color: white;
}
.tramite-badge-rechazado {
background: linear-gradient(135deg, #dc3545, #c82333);
color: white;
}
.tramite-badge-fuera_tiempo {
background: linear-gradient(135deg, #ffc107, #e0a800);
color: #212529;
}
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
.urgency-alert i {
font-size: 1.8rem;
}
.urgency-alert-content h6 {
margin: 0;
font-weight: 700;
font-size: 0.95rem;
}
.urgency-alert-content p {
margin: 5px 0 0;
font-size: 0.85rem;
opacity: 0.9;
}
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
.urgency-alert-close:hover {
background: rgba(255,255,255,0.3);
}
@keyframes slideInRight {
from {
transform: translateX(100%);
opacity: 0;
}
to {
transform: translateX(0);
opacity: 1;
}
}
@keyframes pulse {
0%, 100% {
box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4);
}
50% {
box-shadow: 0 10px 60px rgba(220, 53, 69, 0.6);
}
}
.urgencia-badge {
padding: 4px 10px;
border-radius: 12px;
font-size: 0.7rem;
font-weight: 700;
}
.urgencia-si {
background: linear-gradient(135deg, #dc3545, #c82333);
color: white;
}
.urgencia-no {
background: linear-gradient(135deg, #e9ecef, #dee2e6);
color: #6c757d;
}
.fecha-limite {
font-weight: 600;
}
.fecha-limite.vencida {
color: #dc3545;
}
.fecha-limite.proxima {
color: #ffc107;
}
.fecha-limite.ok {
color: #28a745;
}
.tramitas-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 20px;
}
.documentos-pendientes-alert {
background: linear-gradient(135deg, #fff3cd, #ffecb5);
border-left: 5px solid #f39c12;
border-radius: 12px;
padding: 15px 20px;
margin-top: 15px;
display: flex;
align-items: center;
gap: 15px;
}
.documentos-pendientes-alert i {
font-size: 1.5rem;
color: #f39c12;
}
.documentos-pendientes-alert .content {
flex: 1;
}
.documentos-pendientes-alert .content h6 {
margin: 0 0 5px 0;
color: #d35400;
font-weight: 700;
}
.documentos-pendientes-alert .content p {
margin: 0;
color: #856404;
font-size: 0.85rem;
}
.documentos-pendientes-alert .btn-ver {
background: linear-gradient(135deg, #f39c12, #e67e22);
color: white;
border: none;
padding: 8px 20px;
border-radius: 8px;
font-weight: 600;
transition: all 0.3s;
}
.documentos-pendientes-alert .btn-ver:hover {
transform: translateY(-2px);
box-shadow: 0 4px 15px rgba(243, 156, 18, 0.4);
color: white;
}
</style>
</head>
<body>
<!-- ✅ HEADER (primero) -->
<?php include '../includes/header_empresa.php'; ?>
<!-- ✅ SIDEBAR (después del header) -->
<?php include '../includes/sidebar_empresa.php'; ?>
<!-- ✅ PANEL DE ESTADO DE TRÁMITES EMPRESARIALES -->
<?php
// ✅ CORRECCIÓN: Inicializar variable ANTES del condicional
$hay_urgencia = false;
?>
<?php if (!empty($tramites_activos)): ?>
<div class="tramites-panel">
<div class="tramitas-header">
<h5 class="mb-0 text-primary">
<i class="fas fa-exchange-alt me-2"></i>Estado de Movimientos Empresariales
</h5>
<span class="badge bg-primary"><?php echo count($tramites_activos); ?> Trámites Activos</span>
</div>
<div class="table-responsive">
<table class="table table-hover mb-0 align-middle">
<thead class="bg-light">
<tr>
<th class="ps-3">Tipo Movimiento</th>
<th>Fecha Solicitud</th>
<th>Fecha Notificación</th>
<th>Plazo (Días)</th>
<th>Fecha Límite</th>
<th>Urgencia</th>
<th>Estado</th>
<th>Observaciones</th>
<th>Notificada</th>
<th class="text-end pe-3">Acción</th>
</tr>
</thead>
<tbody>
<?php
foreach ($tramites_activos as $tramite):
// Verificar si hay urgencia
if (!empty($tramite['urgente']) && $tramite['urgente'] == 1) {
$hay_urgencia = true;
}
$badge_class = 'bg-secondary';
$icon = 'fa-clock';
if ($tramite['estado'] == 'aprobado') {
$badge_class = 'tramite-badge-aprobado';
$icon = 'fa-check-circle';
}
elseif ($tramite['estado'] == 'rechazado') {
$badge_class = 'tramite-badge-rechazado';
$icon = 'fa-times-circle';
}
elseif ($tramite['estado'] == 'fuera_tiempo') {
$badge_class = 'tramite-badge-fuera_tiempo';
$icon = 'fa-exclamation-triangle';
}
elseif ($tramite['estado'] == 'en_tramite') {
$badge_class = 'tramite-badge-en_tramite';
$icon = 'fa-spinner fa-spin';
}
$tipo_label = str_replace('_', ' ', ucfirst($tramite['tipo_movimiento']));
// Mostrar estado de Notificación
$notif_html = $tramite['notificada'] == 1
? '<span class="notification-status status-notified"><i class="fas fa-check-circle"></i> SÍ</span>'
: '<span class="notification-status status-notnotified"><i class="fas fa-times-circle"></i> NO</span>';
// Fecha de Notificación
$fecha_notificacion = !empty($tramite['fecha_notificacion'])
? date('d/m/Y', strtotime($tramite['fecha_notificacion']))
: '<span class="text-muted">-</span>';
// Plazo en Días
$plazo_dias = !empty($tramite['plazo_dias'])
? '<strong>' . $tramite['plazo_dias'] . '</strong> días'
: '<span class="text-muted">-</span>';
// Fecha Límite con validación de color
$fecha_limite_class = 'ok';
$fecha_limite = '<span class="text-muted">-</span>';
if (!empty($tramite['fecha_limite'])) {
$fecha_limite_ts = strtotime($tramite['fecha_limite']);
$hoy_ts = time();
$dias_restantes = floor(($fecha_limite_ts - $hoy_ts) / 86400);
if ($dias_restantes < 0) {
$fecha_limite_class = 'vencida';
} elseif ($dias_restantes <= 3) {
$fecha_limite_class = 'proxima';
}
$fecha_limite = date('d/m/Y', $fecha_limite_ts);
}
// Urgencia Badge
$urgencia_html = !empty($tramite['urgente']) && $tramite['urgente'] == 1
? '<span class="urgencia-badge urgencia-si"><i class="fas fa-exclamation-circle"></i> SÍ</span>'
: '<span class="urgencia-badge urgencia-no"><i class="fas fa-check"></i> NO</span>';
?>
<tr>
<td class="ps-3 fw-bold"><?php echo $tipo_label; ?></td>
<td><?php echo date('d/m/Y', strtotime($tramite['fecha_solicitud'])); ?></td>
<td><?php echo $fecha_notificacion; ?></td>
<td><?php echo $plazo_dias; ?></td>
<td><span class="fecha-limite <?php echo $fecha_limite_class; ?>"><?php echo $fecha_limite; ?></span></td>
<td><?php echo $urgencia_html; ?></td>
<td>
<span class="badge <?php echo $badge_class; ?> px-3 py-2">
<i class="fas <?php echo $icon; ?> me-1"></i>
<?php echo strtoupper(str_replace('_', ' ', $tramite['estado'])); ?>
</span>
</td>
<td class="text-muted small">
<?php echo !empty($tramite['observaciones_admin']) ? htmlspecialchars($tramite['observaciones_admin']) : 'Sin observaciones'; ?>
</td>
<td><?php echo $notif_html; ?></td>
<td class="text-end pe-3">
<button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#detalleTramite<?php echo $tramite['id']; ?>">
<i class="fas fa-eye"></i> Detalle
</button>
</td>
</tr>
<tr>
<td colspan="10" class="p-0">
<div class="collapse bg-light" id="detalleTramite<?php echo $tramite['id']; ?>">
<div class="p-3 small">
<strong>Datos Anteriores:</strong> <?php echo htmlspecialchars($tramite['datos_anteriores'] ?? 'N/A'); ?><br>
<strong>Nuevos Datos:</strong> <?php echo htmlspecialchars($tramite['datos_nuevos'] ?? 'N/A'); ?>
<?php if (!empty($tramite['pdf_documento'])): ?>
<br><strong>Documento Adjunto:</strong>
<a href="../uploads/tramites_empresariales/<?php echo $tramite['pdf_documento']; ?>" target="_blank" class="text-danger">
<i class="fas fa-file-pdf"></i> Descargar PDF
</a>
<?php endif; ?>
</div>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>
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
<!-- ✅ NUEVO PANEL: RESUMEN DE ESTADOS DE SUCURSALES, RECURSOS, SERVICIOS Y DOCUMENTACIÓN -->
<div class="estados-resumen-panel">
<div class="tramitas-header mb-4">
<h5 class="mb-0 text-success">
<i class="fas fa-chart-pie me-2"></i>Resumen de Estados - Pendientes y Aprobados
</h5>
<span class="badge bg-success">Actualizado en tiempo real</span>
</div>
<div class="row">
<!-- SUCURSALES -->
<div class="col-md-6 col-lg-3">
<div class="estado-item">
<h6>
<i class="fas fa-store text-primary"></i>
Sucursales
</h6>
<div class="estado-badges">
<span class="estado-badge estado-badge-pendiente">
<i class="fas fa-clock"></i>
Pendientes: <strong><?php echo $sucursales_stats['pendiente'] ?? 0; ?></strong>
</span>
<span class="estado-badge estado-badge-aprobado">
<i class="fas fa-check-circle"></i>
Aprobadas: <strong><?php echo $sucursales_stats['aprobado'] ?? 0; ?></strong>
</span>
<?php if (($sucursales_stats['rechazado'] ?? 0) > 0): ?>
<span class="estado-badge estado-badge-rechazado">
<i class="fas fa-times-circle"></i>
Rechazadas: <strong><?php echo $sucursales_stats['rechazado']; ?></strong>
</span>
<?php endif; ?>
<span class="estado-badge estado-badge-total">
<i class="fas fa-list"></i>
Total: <strong><?php echo $sucursales_stats['total'] ?? 0; ?></strong>
</span>
</div>
<div class="alert alert-warning mt-3 mb-0 py-2 small">
<i class="fas fa-info-circle"></i>
<strong>Esta sucursal está pendiente de aprobación</strong> y las aprobadas se muestran arriba.
</div>
</div>
</div>
<!-- RECURSOS -->
<div class="col-md-6 col-lg-3">
<div class="estado-item">
<h6>
<i class="fas fa-boxes text-info"></i>
Solicitud de Recursos
</h6>
<div class="estado-badges">
<span class="estado-badge estado-badge-pendiente">
<i class="fas fa-clock"></i>
Pendientes: <strong><?php echo $recursos_stats['pendiente'] ?? 0; ?></strong>
</span>
<span class="estado-badge estado-badge-aprobado">
<i class="fas fa-check-circle"></i>
Aprobados: <strong><?php echo $recursos_stats['aprobado'] ?? 0; ?></strong>
</span>
<?php if (($recursos_stats['rechazado'] ?? 0) > 0): ?>
<span class="estado-badge estado-badge-rechazado">
<i class="fas fa-times-circle"></i>
Rechazados: <strong><?php echo $recursos_stats['rechazado']; ?></strong>
</span>
<?php endif; ?>
<span class="estado-badge estado-badge-total">
<i class="fas fa-list"></i>
Total: <strong><?php echo $recursos_stats['total'] ?? 0; ?></strong>
</span>
</div>
<div class="alert alert-info mt-3 mb-0 py-2 small">
<i class="fas fa-info-circle"></i>
<strong>Todas las solicitudes de recursos</strong> quedan en estado Pendiente hasta aprobación del administrador.
</div>
</div>
</div>
<!-- SERVICIOS -->
<div class="col-md-6 col-lg-3">
<div class="estado-item">
<h6>
<i class="fas fa-concierge-bell text-warning"></i>
Servicios
</h6>
<div class="estado-badges">
<span class="estado-badge estado-badge-pendiente">
<i class="fas fa-clock"></i>
Pendientes: <strong><?php echo $servicios_stats['pendiente'] ?? 0; ?></strong>
</span>
<span class="estado-badge estado-badge-aprobado">
<i class="fas fa-check-circle"></i>
Aprobados/Activos: <strong><?php echo $servicios_stats['aprobado'] ?? 0; ?></strong>
</span>
<?php if (($servicios_stats['rechazado'] ?? 0) > 0): ?>
<span class="estado-badge estado-badge-rechazado">
<i class="fas fa-times-circle"></i>
Rechazados: <strong><?php echo $servicios_stats['rechazado']; ?></strong>
</span>
<?php endif; ?>
<span class="estado-badge estado-badge-total">
<i class="fas fa-list"></i>
Total: <strong><?php echo $servicios_stats['total'] ?? 0; ?></strong>
</span>
</div>
<div class="alert alert-warning mt-3 mb-0 py-2 small">
<i class="fas fa-info-circle"></i>
<strong>Todos los servicios creados por empresas</strong> quedan en estado Pendiente o aprobados.
</div>
</div>
</div>
<!-- ✅ DOCUMENTACIÓN DE PERSONAL -->
<div class="col-md-6 col-lg-3">
<div class="estado-item">
<h6>
<i class="fas fa-id-card text-danger"></i>
Documentación Personal
</h6>
<div class="estado-badges">
<span class="estado-badge estado-badge-pendiente">
<i class="fas fa-clock"></i>
Pendientes: <strong><?php echo $documentacion_stats['pendiente'] ?? 0; ?></strong>
</span>
<span class="estado-badge estado-badge-aprobado">
<i class="fas fa-check-circle"></i>
Aprobadas: <strong><?php echo $documentacion_stats['aprobada'] ?? 0; ?></strong>
</span>
<?php if (($documentacion_stats['rechazada'] ?? 0) > 0): ?>
<span class="estado-badge estado-badge-rechazado">
<i class="fas fa-times-circle"></i>
Rechazadas: <strong><?php echo $documentacion_stats['rechazada']; ?></strong>
</span>
<?php endif; ?>
<span class="estado-badge estado-badge-total">
<i class="fas fa-list"></i>
Total: <strong><?php echo $documentacion_stats['total'] ?? 0; ?></strong>
</span>
</div>
<div class="alert alert-warning mt-3 mb-0 py-2 small">
<i class="fas fa-info-circle"></i>
<strong>Documentación Pendiente:</strong> La documentación cargada está esperando aprobación del administrador.
</div>
</div>
</div>
<!-- ✅ NUEVO: DOCUMENTOS CARGADOS (INFORME SUCURSALES) -->
<div class="col-12 mt-4">
<div class="estado-item" style="border-left: 5px solid #9b59b6;">
<h6>
<i class="fas fa-file-upload text-purple" style="color: #9b59b6;"></i>
Documentos Cargados (Informe Sucursales)
</h6>
<div class="estado-badges">
<span class="estado-badge estado-badge-pendiente">
<i class="fas fa-clock"></i>
Pendientes: <strong><?php echo $documentos_cargados_stats['pendiente'] ?? 0; ?></strong>
</span>
<span class="estado-badge estado-badge-aprobado">
<i class="fas fa-check-circle"></i>
Aprobados: <strong><?php echo $documentos_cargados_stats['aprobado'] ?? 0; ?></strong>
</span>
<?php if (($documentos_cargados_stats['rechazado'] ?? 0) > 0): ?>
<span class="estado-badge estado-badge-rechazado">
<i class="fas fa-times-circle"></i>
Rechazados: <strong><?php echo $documentos_cargados_stats['rechazado']; ?></strong>
</span>
<?php endif; ?>
<span class="estado-badge estado-badge-total">
<i class="fas fa-list"></i>
Total: <strong><?php echo $documentos_cargados_stats['total'] ?? 0; ?></strong>
</span>
</div>
<!-- ✅ ALERTA DE DOCUMENTOS PENDIENTES -->
<?php if (($documentos_cargados_stats['pendiente'] ?? 0) > 0): ?>
<div class="documentos-pendientes-alert">
<i class="fas fa-exclamation-circle"></i>
<div class="content">
<h6>📄 Documento cargado correctamente. Está pendiente de revisión</h6>
<p>Tiene <strong><?php echo $documentos_cargados_stats['pendiente']; ?></strong> documento(s) esperando aprobación del administrador</p>
</div>
<a href="gestion_documentos_sucursales.php" class="btn-ver">
<i class="fas fa-eye"></i> Ver Documentos
</a>
</div>
<?php else: ?>
<div class="alert alert-success mt-3 mb-0 py-2 small">
<i class="fas fa-check-circle"></i>
<strong>Todos los documentos están aprobados</strong> o no hay documentos cargados.
</div>
<?php endif; ?>
</div>
</div>
<!-- ✅ FIN DOCUMENTOS CARGADOS -->
</div>
</div>
<!-- ✅ FIN PANEL RESUMEN DE ESTADOS -->
<!-- ✅ CONTENIDO PRINCIPAL -->
<div class="main-content-wrapper">
<div class="container-fluid mt-4">
<!-- [Todo el contenido del dashboard igual que antes] -->
<!-- ... -->
</div>
</div>
<!-- ✅ SCRIPT UNIFICADO PARA TOGGLE (al final) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Función para cerrar la alerta de urgencia
function closeUrgencyAlert() {
const alert = document.getElementById('urgencyAlert');
if (alert) {
alert.style.animation = 'slideInRight 0.5s ease reverse';
setTimeout(() => alert.style.display = 'none', 500);
}
}
// Auto-ocultar alerta después de 60 segundos
setTimeout(function() {
const alert = document.getElementById('urgencyAlert');
if (alert) {
alert.style.animation = 'slideInRight 0.5s ease reverse';
setTimeout(() => alert.style.display = 'none', 500);
}
}, 60000);
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
if (!isMobile) {
// Por defecto: menú oculto al ingresar. Solo expandir si usuario guardó explícitamente 'false'
if (savedState !== 'false') {
body.classList.add('sidebar-collapsed');
if (toggleBtn) toggleBtn.classList.add('rotated');
if (toggleIcon) toggleIcon.className = 'fas fa-indent';
} else {
body.classList.remove('sidebar-collapsed');
if (toggleBtn) toggleBtn.classList.remove('rotated');
if (toggleIcon) toggleIcon.className = 'fas fa-bars';
}
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
// ✅ FUNCIÓN PARA OCULTAR SIDEBAR AL CLICKEAR UN ENLACE DEL MENÚ
function hideSidebarOnLinkClick() {
const menuLinks = document.querySelectorAll('.sidebar-moderno a[href]');
menuLinks.forEach(link => {
link.addEventListener('click', function(e) {
// Si es móvil, cerrar el sidebar
if (window.innerWidth <= 991) {
body.classList.remove('sidebar-mobile-open');
body.style.overflow = '';
if (toggleIcon) toggleIcon.className = 'fas fa-bars';
}
// Si es desktop y no está colapsado, colapsarlo
else if (!body.classList.contains('sidebar-collapsed')) {
body.classList.add('sidebar-collapsed');
if (toggleBtn) toggleBtn.classList.add('rotated');
if (toggleIcon) toggleIcon.className = 'fas fa-indent';
localStorage.setItem('sidebarCollapsed', 'true');
}
});
});
}
// Ejecutar después de que el sidebar esté cargado
setTimeout(hideSidebarOnLinkClick, 500);

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