<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// Verificar autenticación y rol empresa (SOLO LECTURA)
if (!$auth->isLoggedIn() || !$auth->hasRole('empresa')) {
header('Location: ../login.php');
exit;
}
$user = $auth->getCurrentUser();
$empresa_id = $user['empresa_id'];
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ==================== FILTROS (GET = SOLO LECTURA) ====================
$filtro_tipo = $_GET['filtro_tipo'] ?? 'todos';
$filtro_estado = $_GET['filtro_estado'] ?? 'todos';
$filtro_fecha_desde = $_GET['filtro_fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['filtro_fecha_hasta'] ?? '';
// ==================== OBTENER TODOS LOS TRÁMITES ====================
$tramites = [];
// 1. SERVICIOS
if ($filtro_tipo === 'todos' || $filtro_tipo === 'servicio') {
try {
$where = "WHERE s.empresa_id = ?";
$params = [$empresa_id];
if ($filtro_estado !== 'todos') { $where .= " AND s.estado = ?"; $params[] = $filtro_estado; }
if (!empty($filtro_fecha_desde)) { $where .= " AND s.created_at >= ?"; $params[] = $filtro_fecha_desde . ' 00:00:00'; }
if (!empty($filtro_fecha_hasta)) { $where .= " AND s.created_at <= ?"; $params[] = $filtro_fecha_hasta . ' 23:59:59'; }
$stmt = $conn->prepare("SELECT 'Servicio' as tipo_tramite, s.id, s.nombre as descripcion, s.created_at as fecha_envio, s.estado, NULL as fecha_aprobacion, NULL as fecha_rechazo, NULL as observaciones_aprobacion, suc.nombre as sucursal_nombre, NULL as usuario_aprobacion, NULL as usuario_rechazo, NULL as fecha_movimiento FROM servicios s LEFT JOIN sucursales suc ON s.sucursal_id = suc.id $where ORDER BY s.created_at DESC");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) { error_log("Error servicios: " . $e->getMessage()); }
}
// 2. SUCURSALES
if ($filtro_tipo === 'todos' || $filtro_tipo === 'sucursal') {
try {
$where = "WHERE s.empresa_id = ?";
$params = [$empresa_id];
// Usar COALESCE para manejar NULLs como pendientes
if ($filtro_estado !== 'todos') { $where .= " AND COALESCE(s.estado_aprobacion, 'pendiente') = ?"; $params[] = $filtro_estado; }
if (!empty($filtro_fecha_desde)) { $where .= " AND s.fecha_solicitud >= ?"; $params[] = $filtro_fecha_desde . ' 00:00:00'; }
if (!empty($filtro_fecha_hasta)) { $where .= " AND s.fecha_solicitud <= ?"; $params[] = $filtro_fecha_hasta . ' 23:59:59'; }
$stmt = $conn->prepare("SELECT 'Sucursal' as tipo_tramite, s.id, s.nombre as descripcion, s.fecha_solicitud as fecha_envio, COALESCE(s.estado_aprobacion, 'pendiente') as estado, NULL as fecha_aprobacion, NULL as fecha_rechazo, s.observaciones_aprobacion, s.domicilio as sucursal_nombre, NULL as usuario_aprobacion, NULL as usuario_rechazo, s.fecha_aprobacion as fecha_movimiento FROM sucursales s $where ORDER BY s.fecha_solicitud DESC");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) { error_log("Error sucursales: " . $e->getMessage()); }
}
// 3. PERSONAL (DOCUMENTACIÓN) - Desde cargar_documentacion.php
// Nota: cargar_documentacion.php gestiona personal inactivo, por eso se filtra por p.activo = 0
if ($filtro_tipo === 'todos' || $filtro_tipo === 'personal') {
try {
$where = "WHERE p.empresa_id = ? AND p.activo = 0";
$params = [$empresa_id];
// Usar COALESCE para filtrar correctamente si el estado es NULL
if ($filtro_estado !== 'todos') {
$where .= " AND COALESCE(p.estado_documentacion, 'pendiente') = ?";
$params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) { $where .= " AND p.updated_at >= ?"; $params[] = $filtro_fecha_desde . ' 00:00:00'; }
if (!empty($filtro_fecha_hasta)) { $where .= " AND p.updated_at <= ?"; $params[] = $filtro_fecha_hasta . ' 23:59:59'; }
$stmt = $conn->prepare("SELECT 'Personal' as tipo_tramite, p.id, CONCAT(p.nombre, ' ', p.apellido) as descripcion, p.updated_at as fecha_envio, p.estado_documentacion as estado, p.fecha_revision_documentacion as fecha_movimiento, NULL as fecha_rechazo, p.observaciones as observaciones_aprobacion, suc.nombre as sucursal_nombre, NULL as usuario_aprobacion, NULL as usuario_rechazo FROM personal p LEFT JOIN sucursales suc ON p.sucursal_id = suc.id $where ORDER BY p.updated_at DESC");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) { error_log("Error personal: " . $e->getMessage()); }
}
// 4. RECURSOS
if ($filtro_tipo === 'todos' || $filtro_tipo === 'recursos') {
try {
$where = "WHERE rs.empresa_id = ?";
$params = [$empresa_id];
if ($filtro_estado !== 'todos') { $where .= " AND rs.estado = ?"; $params[] = $filtro_estado; }
if (!empty($filtro_fecha_desde)) { $where .= " AND rs.created_at >= ?"; $params[] = $filtro_fecha_desde . ' 00:00:00'; }
if (!empty($filtro_fecha_hasta)) { $where .= " AND rs.created_at <= ?"; $params[] = $filtro_fecha_hasta . ' 23:59:59'; }
$stmt = $conn->prepare("SELECT 'Recursos' as tipo_tramite, rs.id, CONCAT('Recursos - ', s.nombre) as descripcion, rs.created_at as fecha_envio, rs.estado, NULL as fecha_aprobacion, NULL as fecha_rechazo, rs.motivo_rechazo as observaciones_aprobacion, s.nombre as sucursal_nombre, NULL as usuario_aprobacion, NULL as usuario_rechazo, NULL as fecha_movimiento FROM recursos_sucursal rs LEFT JOIN sucursales s ON rs.sucursal_id = s.id $where ORDER BY rs.created_at DESC");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) { error_log("Error recursos: " . $e->getMessage()); }
}
// 5. DOCUMENTOS SUCURSALES
if ($filtro_tipo === 'todos' || $filtro_tipo === 'documento') {
try {
$tableCheck = $conn->query("SHOW TABLES LIKE 'documentos_sucursales'");
if ($tableCheck->rowCount() > 0) {
$where = "WHERE d.empresa_id = ?";
$params = [$empresa_id];
if ($filtro_estado !== 'todos') { $where .= " AND d.estado = ?"; $params[] = $filtro_estado; }
if (!empty($filtro_fecha_desde)) { $where .= " AND d.fecha_carga >= ?"; $params[] = $filtro_fecha_desde . ' 00:00:00'; }
if (!empty($filtro_fecha_hasta)) { $where .= " AND d.fecha_carga <= ?"; $params[] = $filtro_fecha_hasta . ' 23:59:59'; }
// Usar SUBSTRING_INDEX para obtener solo el nombre del archivo de forma segura
$stmt = $conn->prepare("SELECT 'Documento' as tipo_tramite, d.id, CONCAT(d.tipo_documento, ' - ', SUBSTRING_INDEX(d.archivo_pdf, '/', -1)) as descripcion, d.fecha_carga as fecha_envio, d.estado, NULL as fecha_aprobacion, NULL as fecha_rechazo, d.observaciones as observaciones_aprobacion, COALESCE(s.nombre, 'General') as sucursal_nombre, NULL as usuario_aprobacion, NULL as usuario_rechazo, d.fecha_revision as fecha_movimiento FROM documentos_sucursales d LEFT JOIN sucursales s ON d.sucursal_id = s.id $where ORDER BY d.fecha_carga DESC");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
} catch(PDOException $e) { error_log("Error documentos: " . $e->getMessage()); }
}
// Ordenar por fecha de envío (más reciente primero)
usort($tramites, function($a, $b) {
return strtotime($b['fecha_envio']) - strtotime($a['fecha_envio']);
});
// ==================== PAGINACIÓN ====================
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$total_registros = count($tramites);
$total_paginas = ceil($total_registros / $registros_por_pagina);
$inicio = ($pagina_actual - 1) * $registros_por_pagina;
$tramites_pagina = array_slice($tramites, $inicio, $registros_por_pagina);
// ==================== ESTADÍSTICAS ====================
$stats = ['total' => count($tramites), 'pendientes' => 0, 'aprobados' => 0, 'rechazados' => 0];
foreach ($tramites as $t) {
$estado = strtolower($t['estado'] ?? 'pendiente');
if ($estado === 'pendiente') $stats['pendientes']++;
elseif (in_array($estado, ['aprobado', 'aprobada', 'activo'])) $stats['aprobados']++;
elseif (in_array($estado, ['rechazado', 'rechazada'])) $stats['rechazados']++;
}
// ==================== VERIFICAR TRÁMITES URGENTES ====================
$hay_urgencia = false;
try {
$stmt = $conn->prepare("SELECT COUNT(*) as urgentes FROM tramites_empresa WHERE empresa_id = :empresa_id AND urgente = 1 AND estado IN ('en_tramite', 'pendiente')");
$stmt->execute([':empresa_id' => $empresa_id]);
$urgentes = $stmt->fetch(PDO::FETCH_ASSOC);
$hay_urgencia = ($urgentes['urgentes'] > 0);
} catch(PDOException $e) {}
// Función para construir URL con filtros y paginación
function construirUrlPaginacion($pagina) {
$params = [];
if (!empty($_GET['filtro_tipo']) && $_GET['filtro_tipo'] !== 'todos') $params[] = 'filtro_tipo=' . urlencode($_GET['filtro_tipo']);
if (!empty($_GET['filtro_estado']) && $_GET['filtro_estado'] !== 'todos') $params[] = 'filtro_estado=' . urlencode($_GET['filtro_estado']);
if (!empty($_GET['filtro_fecha_desde'])) $params[] = 'filtro_fecha_desde=' . urlencode($_GET['filtro_fecha_desde']);
if (!empty($_GET['filtro_fecha_hasta'])) $params[] = 'filtro_fecha_hasta=' . urlencode($_GET['filtro_fecha_hasta']);
$params[] = 'pagina=' . $pagina;
return 'seguimiento_tramites.php?' . implode('&', $params);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seguimiento de Trámites - Empresa (Solo Lectura)</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<link rel="stylesheet" href="../css/style.css">
<style>
:root {
--sidebar-width: 280px;
--header-height: 100px;
--spacing-mobile: clamp(10px, 3vw, 15px);
--spacing-tablet: clamp(15px, 4vw, 20px);
--spacing-desktop: clamp(20px, 5vw, 30px);
--font-size-base: clamp(0.875rem, 0.9rem + 0.2vw, 1rem);
--font-size-sm: clamp(0.75rem, 0.8rem + 0.1vw, 0.9rem);
--font-size-lg: clamp(1.1rem, 1.2rem + 0.3vw, 1.25rem);
--border-radius-sm: clamp(10px, 2vw, 14px);
--border-radius-md: clamp(14px, 3vw, 20px);
--border-radius-lg: clamp(20px, 4vw, 28px);
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html { scroll-behavior: smooth; font-size: var(--font-size-base); }
body {
overflow-x: hidden;
font-size: 1rem;
-webkit-text-size-adjust: 100%;
line-height: 1.5;
}
.main-content-wrapper {
margin-left: var(--sidebar-width);
padding-top: var(--header-height);
padding-left: var(--spacing-desktop);
padding-right: var(--spacing-desktop);
transition: margin-left 0.35s ease, padding 0.35s ease;
min-height: calc(100vh - var(--header-height));
width: calc(100% - var(--sidebar-width));
}
body.sidebar-collapsed .main-content-wrapper {
margin-left: 0;
width: 100%;
}
/* Tablet - 991px */
@media (max-width: 991px) {
.main-content-wrapper {
margin-left: 0 !important;
width: 100% !important;
padding-left: var(--spacing-tablet);
padding-right: var(--spacing-tablet);
padding-top: 80px;
}
:root { --header-height: 80px; }
}
/* Mobile - 768px */
@media (max-width: 768px) {
.main-content-wrapper {
padding-left: var(--spacing-mobile);
padding-right: var(--spacing-mobile);
padding-top: 70px;
}
:root { --header-height: 70px; }
}
/* Mobile small - 480px */
@media (max-width: 480px) {
.main-content-wrapper {
padding-left: 10px;
padding-right: 10px;
padding-top: 65px;
}
:root { --header-height: 65px; }
}
.urgency-alert {
position: fixed;
bottom: 20px;
right: 20px;
background: linear-gradient(135deg, #dc3545, #c82333);
color: white;
padding: clamp(15px, 3vw, 25px);
border-radius: var(--border-radius-md);
box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4);
z-index: 9999;
display: flex;
align-items: center;
gap: clamp(10px, 2vw, 15px);
max-width: min(90vw, 400px);
animation: slideInRight 0.5s ease, pulse 2s infinite;
}
.urgency-alert i { font-size: clamp(1.4rem, 3vw, 1.8rem); }
.urgency-alert-content h6 { margin: 0; font-weight: 700; font-size: clamp(0.9rem, 2vw, 0.95rem); }
.urgency-alert-content p { margin: 5px 0 0; font-size: clamp(0.8rem, 1.8vw, 0.85rem); opacity: 0.9; }
.urgency-alert-close {
background: rgba(255,255,255,0.2);
border: none;
color: white;
width: clamp(26px, 5vw, 30px);
height: clamp(26px, 5vw, 30px);
border-radius: 50%;
cursor: pointer;
display: flex;
align-items: center;
justify-content: center;
min-width: 26px;
min-height: 26px;
}
@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes pulse { 0%, 100% { box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4); } 50% { box-shadow: 0 10px 60px rgba(220, 53, 69, 0.6); } }
.stats-container-modern {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
gap: clamp(15px, 3vw, 25px);
margin: clamp(20px, 4vw, 40px) 0;
}
.stat-card-modern {
background: white;
border-radius: var(--border-radius-lg);
padding: clamp(18px, 4vw, 28px);
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
border: 1px solid rgba(0, 0, 0, 0.05);
transition: transform 0.3s, box-shadow 0.3s;
}
.stat-card-modern:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18); }
.search-section-modern {
background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
border-radius: var(--border-radius-lg);
padding: clamp(20px, 5vw, 40px);
box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
margin-bottom: clamp(25px, 5vw, 40px);
border: 1px solid rgba(0, 0, 0, 0.06);
}
.resources-table-container {
background: white;
border-radius: var(--border-radius-md);
overflow: hidden;
box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
margin-bottom: clamp(20px, 4vw, 30px);
overflow-x: auto;
-webkit-overflow-scrolling: touch;
width: 100%;
max-width: 100%;
}
.resources-table {
width: 100%;
margin: 0;
border-collapse: separate;
border-spacing: 0;
min-width: 100%;
}
.resources-table thead { background: linear-gradient(135deg, #2c3e50, #1a252f); color: white; }
.resources-table thead th {
padding: clamp(14px, 2.5vw, 18px);
font-weight: 700;
font-size: var(--font-size-sm);
text-transform: uppercase;
letter-spacing: 0.5px;
border: none;
white-space: nowrap;
}
.resources-table tbody tr { transition: all 0.3s; border-bottom: 1px solid #f0f0f0; }
.resources-table tbody tr:hover { background: linear-gradient(135deg, #f8f9fa, #e9ecef); transform: scale(1.005); }
.resources-table tbody td {
padding: clamp(12px, 2vw, 16px);
vertical-align: middle;
font-size: var(--font-size-base);
white-space: nowrap;
}
.badge-pendiente { background: #ffc107 !important; color: #000; }
.badge-aprobado { background: #28a745 !important; color: #fff; }
.badge-rechazado { background: #dc3545 !important; color: #fff; }
.tipo-badge {
padding: clamp(4px, 1vw, 5px) clamp(8px, 2vw, 12px);
border-radius: 15px;
font-size: clamp(0.65rem, 1.5vw, 0.75rem);
font-weight: 700;
text-transform: uppercase;
white-space: nowrap;
}
.tipo-servicio { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
.tipo-sucursal { background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; }
.tipo-personal { background: linear-gradient(135deg, #e67e22, #d35400); color: white; }
.tipo-recursos { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
.tipo-documento { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
.time-badge { font-size: clamp(0.75rem, 1.8vw, 0.8rem); padding: clamp(2px, 0.5vw, 3px) clamp(6px, 1.5vw, 8px); border-radius: 10px; white-space: nowrap; }
.time-normal { background: #d4edda; color: #155724; }
.time-warning { background: #fff3cd; color: #856404; }
.time-danger { background: #f8d7da; color: #721c24; }
.empty-state-modern {
text-align: center;
padding: clamp(40px, 8vw, 60px) clamp(15px, 4vw, 20px);
background: linear-gradient(135deg, #f8f9fa, #e9ecef);
border-radius: var(--border-radius-lg);
margin-top: clamp(15px, 3vw, 20px);
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}
.form-control-modern {
border-radius: var(--border-radius-sm);
border: 2px solid #e9ecef;
padding: clamp(10px, 2vw, 14px) clamp(14px, 3vw, 18px);
font-size: var(--font-size-base);
transition: all 0.3s ease;
background: white;
font-weight: 500;
color: #2c3e50;
width: 100%;
min-height: clamp(42px, 8vw, 50px);
}
.form-control-modern:focus {
border-color: #4361ee;
box-shadow: 0 0 0 5px rgba(67, 97, 238, 0.12);
outline: none;
}
.btn-search-modern {
display: flex;
align-items: center;
justify-content: center;
gap: clamp(8px, 2vw, 10px);
background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
color: white;
border: none;
border-radius: var(--border-radius-sm);
padding: clamp(10px, 2vw, 14px) clamp(20px, 4vw, 32px);
font-weight: 700;
transition: all 0.3s ease;
box-shadow: 0 6px 20px rgba(67, 97, 238, 0.35);
cursor: pointer;
min-height: clamp(42px, 8vw, 50px);
white-space: nowrap;
width: 100%;
max-width: 100%;
}
.btn-search-modern:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(67, 97, 238, 0.5); }
.btn-search-modern:active { transform: translateY(0); }
/* Paginación */
.pagination-container {
display: flex;
justify-content: center;
align-items: center;
gap: clamp(5px, 1.5vw, 8px);
margin: clamp(20px, 4vw, 30px) 0 clamp(15px, 3vw, 20px);
flex-wrap: wrap;
}
.pagination-container .page-link {
min-width: clamp(32px, 6vw, 42px);
height: clamp(32px, 6vw, 42px);
display: flex;
align-items: center;
justify-content: center;
border-radius: var(--border-radius-sm);
border: 2px solid #e9ecef;
color: #2c3e50;
font-weight: 600;
text-decoration: none;
transition: all 0.2s ease;
background: white;
margin: clamp(1px, 0.3vw, 2px);
font-size: var(--font-size-base);
}
.pagination-container .page-link:hover {
background: linear-gradient(135deg, #4361ee, #3a0ca3);
color: white;
border-color: #4361ee;
transform: translateY(-2px);
}
.pagination-container .page-link.active {
background: linear-gradient(135deg, #4361ee, #3a0ca3);
color: white;
border-color: #4361ee;
font-weight: 700;
}
.pagination-container .page-link.disabled { opacity: 0.5; cursor: not-allowed; }
.pagination-container .page-link.disabled:hover { transform: none; background: white; color: #2c3e50; }
.pagination-info {
text-align: center;
color: #6c757d;
font-size: var(--font-size-sm);
margin-bottom: clamp(10px, 2vw, 15px);
}
.read-only-badge {
background: linear-gradient(135deg, #6c757d, #495057);
color: white;
padding: clamp(5px, 1.5vw, 6px) clamp(10px, 2.5vw, 12px);
border-radius: 20px;
font-size: var(--font-size-sm);
font-weight: 600;
}
/* Responsive adjustments */
/* Large desktop - 1200px+ */
@media (min-width: 1200px) {
.stats-container-modern {
grid-template-columns: repeat(4, 1fr);
}
}
/* Tablet - 991px */
@media (max-width: 991px) {
.stats-container-modern {
grid-template-columns: repeat(2, 1fr);
gap: clamp(10px, 2.5vw, 15px);
}
.stat-card-modern {
padding: clamp(15px, 3vw, 20px);
border-radius: var(--border-radius-md);
}
.stat-card-modern > div:first-child {
width: clamp(50px, 8vw, 55px) !important;
height: clamp(50px, 8vw, 55px) !important;
font-size: clamp(1.3rem, 2.5vw, 1.5rem) !important;
}
.stat-card-modern > div:nth-child(2) {
font-size: clamp(2rem, 4vw, 2.2rem) !important;
}
.stat-card-modern > div:nth-child(3) {
font-size: clamp(1rem, 2vw, 1.1rem) !important;
}
.search-section-modern {
padding: clamp(20px, 4vw, 25px);
border-radius: var(--border-radius-md);
}
.form-control-modern {
padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 15px);
font-size: var(--font-size-sm);
min-height: clamp(42px, 7vw, 45px);
}
.btn-search-modern {
padding: clamp(10px, 2vw, 12px) clamp(15px, 3vw, 20px);
font-size: var(--font-size-sm);
min-height: clamp(42px, 7vw, 45px);
}
.resources-table thead th {
padding: clamp(10px, 2vw, 14px);
font-size: clamp(0.75rem, 1.8vw, 0.8rem);
}
.resources-table tbody td {
padding: clamp(10px, 2vw, 12px);
font-size: var(--font-size-sm);
}
.tipo-badge {
padding: clamp(3px, 1vw, 4px) clamp(8px, 2vw, 10px);
font-size: clamp(0.65rem, 1.5vw, 0.7rem);
}
.pagination-container .page-link {
min-width: clamp(34px, 6vw, 38px);
height: clamp(34px, 6vw, 38px);
font-size: var(--font-size-sm);
}
}
/* Mobile - 768px */
@media (max-width: 768px) {
.stats-container-modern {
grid-template-columns: 1fr;
gap: clamp(8px, 2vw, 12px);
}
.stat-card-modern {
padding: clamp(14px, 3vw, 18px);
border-radius: var(--border-radius-sm);
}
.stat-card-modern > div:first-child {
width: clamp(45px, 8vw, 50px) !important;
height: clamp(45px, 8vw, 50px) !important;
border-radius: var(--border-radius-sm) !important;
font-size: clamp(1.1rem, 2.5vw, 1.3rem) !important;
}
.stat-card-modern > div:nth-child(2) {
font-size: clamp(1.8rem, 4vw, 2rem) !important;
}
.stat-card-modern > div:nth-child(3) {
font-size: clamp(0.95rem, 2vw, 1rem) !important;
}
.search-section-modern {
padding: clamp(15px, 4vw, 20px);
border-radius: var(--border-radius-sm);
}
.search-section-modern .d-flex.align-items-center.gap-3 {
flex-direction: column;
align-items: flex-start !important;
gap: clamp(10px, 3vw, 15px) !important;
}
.search-section-modern .d-flex.align-items-center.gap-3 > div:last-child {
text-align: left;
}
.form-label {
font-size: clamp(0.85rem, 2vw, 0.9rem) !important;
}
.form-control-modern {
padding: clamp(8px, 2vw, 10px) clamp(12px, 2.5vw, 14px);
font-size: var(--font-size-sm);
min-height: clamp(40px, 7vw, 44px);
}
.btn-search-modern {
padding: clamp(10px, 2vw, 12px);
font-size: var(--font-size-sm);
min-height: clamp(40px, 7vw, 44px);
width: 100%;
}
.btn-search-modern i {
margin-right: clamp(3px, 1vw, 5px);
}
.col-md-2.d-flex.align-items-end.gap-2 {
flex-direction: column;
align-items: stretch !important;
gap: clamp(8px, 2vw, 10px) !important;
}
.col-md-2.d-flex.align-items-end.gap-2 .btn-outline-secondary {
width: 100%;
padding: clamp(10px, 2vw, 12px);
min-height: clamp(40px, 7vw, 44px);
display: flex;
align-items: center;
justify-content: center;
}
.resources-table-container {
border-radius: var(--border-radius-sm);
margin-bottom: clamp(15px, 3vw, 20px);
}
.resources-table {
min-width: 100%;
}
.resources-table thead {
display: none;
}
.resources-table tbody tr {
display: block;
margin-bottom: clamp(8px, 2vw, 12px);
border: 1px solid #e9ecef;
border-radius: var(--border-radius-sm);
padding: clamp(10px, 2vw, 12px);
background: white;
}
.resources-table tbody td {
display: flex;
justify-content: space-between;
padding: clamp(6px, 1.5vw, 8px) 0;
border-bottom: 1px solid #f0f0f0;
font-size: var(--font-size-sm);
}
.resources-table tbody td:last-child {
border-bottom: none;
}
.resources-table tbody td::before {
content: attr(data-label);
font-weight: 600;
color: #2c3e50;
margin-right: clamp(8px, 2vw, 10px);
}
.resources-table tbody td[data-label="Acciones"] {
justify-content: flex-end;
padding-top: clamp(10px, 2vw, 12px);
}
.tipo-badge {
padding: clamp(3px, 1vw, 4px) clamp(6px, 1.5vw, 8px);
font-size: clamp(0.6rem, 1.5vw, 0.65rem);
}
.badge {
font-size: clamp(0.75rem, 1.8vw, 0.8rem);
padding: clamp(4px, 1vw, 5px) clamp(8px, 2vw, 10px);
}
.time-badge {
font-size: clamp(0.7rem, 1.8vw, 0.75rem);
padding: clamp(2px, 0.5vw, 2px) clamp(4px, 1.5vw, 6px);
}
.pagination-container {
gap: clamp(3px, 1vw, 5px);
}
.pagination-container .page-link {
min-width: clamp(30px, 6vw, 34px);
height: clamp(30px, 6vw, 34px);
font-size: clamp(0.8rem, 1.8vw, 0.85rem);
margin: clamp(1px, 0.3vw, 1px);
}
.pagination-info {
font-size: clamp(0.8rem, 1.8vw, 0.85rem);
}
.empty-state-modern {
padding: clamp(30px, 7vw, 40px) clamp(12px, 3vw, 15px);
border-radius: var(--border-radius-md);
}
.empty-state-modern i.fa-4x {
font-size: clamp(2.5rem, 6vw, 3rem);
}
.empty-state-modern h3 {
font-size: clamp(1.2rem, 3vw, 1.3rem);
}
.empty-state-modern .btn-lg {
padding: clamp(8px, 2vw, 10px) clamp(15px, 3vw, 20px);
font-size: clamp(0.9rem, 2vw, 0.95rem);
}
.urgency-alert {
bottom: clamp(10px, 2vw, 15px);
right: clamp(10px, 2vw, 15px);
left: clamp(10px, 2vw, 15px);
max-width: none;
padding: clamp(12px, 3vw, 15px) clamp(15px, 3vw, 20px);
border-radius: var(--border-radius-sm);
}
.urgency-alert i {
font-size: clamp(1.2rem, 3vw, 1.4rem);
}
.urgency-alert-content h6 {
font-size: clamp(0.85rem, 2vw, 0.9rem);
}
.urgency-alert-content p {
font-size: clamp(0.75rem, 1.8vw, 0.8rem);
margin: 3px 0 0;
}
.urgency-alert-close {
width: clamp(24px, 5vw, 26px);
height: clamp(24px, 5vw, 26px);
min-width: clamp(24px, 5vw, 26px);
min-height: clamp(24px, 5vw, 26px);
}
}
/* Mobile small - 480px */
@media (max-width: 480px) {
.stats-container-modern {
gap: clamp(8px, 2vw, 10px);
margin: clamp(15px, 4vw, 20px) 0 clamp(20px, 5vw, 30px);
}
.stat-card-modern {
padding: clamp(12px, 3vw, 15px);
border-radius: var(--border-radius-sm);
}
.stat-card-modern > div:first-child {
width: clamp(40px, 8vw, 45px) !important;
height: clamp(40px, 8vw, 45px) !important;
font-size: clamp(1rem, 2.5vw, 1.1rem) !important;
}
.stat-card-modern > div:nth-child(2) {
font-size: clamp(1.6rem, 4vw, 1.8rem) !important;
}
.stat-card-modern > div:nth-child(3) {
font-size: clamp(0.9rem, 2vw, 0.95rem) !important;
}
.search-section-modern {
padding: clamp(12px, 3vw, 15px);
border-radius: var(--border-radius-sm);
margin-bottom: clamp(20px, 5vw, 25px);
}
.form-control-modern {
padding: clamp(8px, 2vw, 10px) clamp(10px, 2.5vw, 12px);
font-size: clamp(0.8rem, 2vw, 0.85rem);
min-height: clamp(38px, 7vw, 42px);
}
.btn-search-modern {
padding: clamp(8px, 2vw, 10px);
font-size: clamp(0.8rem, 2vw, 0.85rem);
min-height: clamp(38px, 7vw, 42px);
}
.resources-table {
min-width: 100%;
}
.resources-table tbody td {
font-size: clamp(0.8rem, 2vw, 0.85rem);
padding: clamp(4px, 1.5vw, 6px) 0;
}
.resources-table tbody td::before {
font-size: clamp(0.8rem, 2vw, 0.85rem);
}
.tipo-badge {
padding: clamp(2px, 1vw, 3px) clamp(5px, 1.5vw, 7px);
font-size: clamp(0.55rem, 1.5vw, 0.6rem);
}
.badge {
font-size: clamp(0.7rem, 1.8vw, 0.75rem);
padding: clamp(3px, 1vw, 4px) clamp(6px, 1.5vw, 8px);
}
.pagination-container .page-link {
min-width: clamp(28px, 6vw, 32px);
height: clamp(28px, 6vw, 32px);
font-size: clamp(0.75rem, 1.8vw, 0.8rem);
}
.pagination-info {
font-size: clamp(0.75rem, 1.8vw, 0.8rem);
margin-bottom: clamp(8px, 2vw, 10px);
}
h2.mb-4 {
font-size: clamp(1.2rem, 3vw, 1.3rem);
}
h2.mb-4 .badge {
font-size: clamp(0.7rem, 1.8vw, 0.75rem);
padding: clamp(4px, 1vw, 5px) clamp(8px, 2vw, 10px);
}
}
/* Large desktop - 1400px+ */
@media (min-width: 1400px) {
.stats-container-modern {
grid-template-columns: repeat(4, 1fr);
}
.search-section-modern {
padding: clamp(40px, 6vw, 50px);
}
.resources-table thead th {
padding: clamp(16px, 2.5vw, 20px);
font-size: clamp(0.9rem, 1.5vw, 0.95rem);
}
.resources-table tbody td {
padding: clamp(14px, 2.5vw, 18px);
font-size: clamp(0.95rem, 1.5vw, 1rem);
}
}
/* Ultra wide screens */
@media (min-width: 1920px) {
:root {
--spacing-desktop: clamp(30px, 3vw, 50px);
}
.container {
max-width: 1600px;
}
}
/* Touch optimizations */
@media (hover: none) and (pointer: coarse) {
.btn-search-modern:hover {
transform: none;
}
.stat-card-modern:hover {
transform: none;
}
.resources-table tbody tr:hover {
transform: none;
background: #f8f9fa;
}
.pagination-container .page-link:hover {
transform: none;
}
.form-control-modern,
.btn-search-modern,
.pagination-container .page-link,
.urgency-alert-close,
.btn-close {
min-height: clamp(40px, 8vw, 44px);
min-width: clamp(40px, 8vw, 44px);
}
}
/* Print styles */
@media print {
.main-content-wrapper {
margin-left: 0 !important;
width: 100% !important;
padding: 0 !important;
}
.search-section-modern,
.urgency-alert,
.pagination-container {
display: none !important;
}
.resources-table-container {
box-shadow: none;
border-radius: 0;
}
.resources-table {
min-width: auto;
font-size: 9pt;
}
.resources-table thead {
display: table-header-group;
}
.resources-table tbody tr {
display: table-row !important;
}
.resources-table tbody td {
display: table-cell !important;
}
}
/* Accessibility */
@media (prefers-reduced-motion: reduce) {
*, *::before, *::after {
animation-duration: 0.01ms !important;
animation-iteration-count: 1 !important;
transition-duration: 0.01ms !important;
}
}
/* High contrast mode support */
@media (prefers-contrast: high) {
.stat-card-modern,
.search-section-modern,
.resources-table-container {
border-width: 2px;
}
.form-control-modern {
border-width: 3px;
}
}
/* Fluid typography for all screens */
html {
font-size: clamp(14px, 0.9rem + 0.2vw, 16px);
}
h1, h2, h3, h4, h5, h6 {
font-size: clamp(1.2rem, 1.5rem + 1vw, 2.5rem);
line-height: 1.3;
}
p, span, label, a, button, input, select, textarea {
font-size: clamp(0.875rem, 0.9rem + 0.1vw, 1rem);
}
/* Ensure images and media scale properly */
img, video, iframe, object, embed {
max-width: 100%;
height: auto;
display: block;
}
/* Ensure containers don't overflow */
.container, .container-fluid {
width: 100%;
max-width: 100%;
padding-left: var(--spacing-mobile);
padding-right: var(--spacing-mobile);
}
@media (min-width: 768px) {
.container, .container-fluid {
padding-left: var(--spacing-tablet);
padding-right: var(--spacing-tablet);
}
}
@media (min-width: 992px) {
.container, .container-fluid {
padding-left: var(--spacing-desktop);
padding-right: var(--spacing-desktop);
}
}
</style>
</head>
<body>
<?php include '../includes/header_empresa.php'; ?>
<?php include '../includes/sidebar_empresa.php'; ?>
<div class="main-content-wrapper">
<div class="container mt-4">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($hay_urgencia): ?>
<div class="urgency-alert" id="urgencyAlert">
<i class="fas fa-exclamation-triangle"></i>
<div class="urgency-alert-content">
<h6>⚠️ TRÁMITE URGENTE</h6>
<p>Debe presentarse en las oficinas de forma URGENTE</p>
</div>
<button class="urgency-alert-close" onclick="closeUrgencyAlert()" aria-label="Cerrar alerta"><i class="fas fa-times"></i></button>
</div>
<?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container-modern">
<div class="stat-card-modern"><div style="width:clamp(50px, 10vw, 70px);height:clamp(50px, 10vw, 70px);border-radius:clamp(16px, 3vw, 22px);background:linear-gradient(135deg,#4361ee,#3a0ca3);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:clamp(15px, 3vw, 20px);font-size:clamp(1.3rem, 3vw, 1.9rem);"><i class="fas fa-clipboard-list"></i></div><div style="font-size:clamp(2rem, 5vw, 2.8rem);font-weight:800;margin:clamp(8px, 2vw, 10px) 0;color:#2c3e50;"><?php echo $stats['total']; ?></div><div style="font-size:clamp(1rem, 2.5vw, 1.25rem);font-weight:700;color:#2c3e50;margin-top:clamp(4px, 1vw, 5px);">Total Trámites</div></div>
<div class="stat-card-modern"><div style="width:clamp(50px, 10vw, 70px);height:clamp(50px, 10vw, 70px);border-radius:clamp(16px, 3vw, 22px);background:linear-gradient(135deg,#ffc107,#ff9800);color:#000;display:flex;align-items:center;justify-content:center;margin-bottom:clamp(15px, 3vw, 20px);font-size:clamp(1.3rem, 3vw, 1.9rem);"><i class="fas fa-clock"></i></div><div style="font-size:clamp(2rem, 5vw, 2.8rem);font-weight:800;margin:clamp(8px, 2vw, 10px) 0;color:#2c3e50;"><?php echo $stats['pendientes']; ?></div><div style="font-size:clamp(1rem, 2.5vw, 1.25rem);font-weight:700;color:#2c3e50;margin-top:clamp(4px, 1vw, 5px);">Pendientes</div></div>
<div class="stat-card-modern"><div style="width:clamp(50px, 10vw, 70px);height:clamp(50px, 10vw, 70px);border-radius:clamp(16px, 3vw, 22px);background:linear-gradient(135deg,#28a745,#20c997);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:clamp(15px, 3vw, 20px);font-size:clamp(1.3rem, 3vw, 1.9rem);"><i class="fas fa-check-circle"></i></div><div style="font-size:clamp(2rem, 5vw, 2.8rem);font-weight:800;margin:clamp(8px, 2vw, 10px) 0;color:#2c3e50;"><?php echo $stats['aprobados']; ?></div><div style="font-size:clamp(1rem, 2.5vw, 1.25rem);font-weight:700;color:#2c3e50;margin-top:clamp(4px, 1vw, 5px);">Aprobados</div></div>
<div class="stat-card-modern"><div style="width:clamp(50px, 10vw, 70px);height:clamp(50px, 10vw, 70px);border-radius:clamp(16px, 3vw, 22px);background:linear-gradient(135deg,#dc3545,#c82333);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:clamp(15px, 3vw, 20px);font-size:clamp(1.3rem, 3vw, 1.9rem);"><i class="fas fa-times-circle"></i></div><div style="font-size:clamp(2rem, 5vw, 2.8rem);font-weight:800;margin:clamp(8px, 2vw, 10px) 0;color:#2c3e50;"><?php echo $stats['rechazados']; ?></div><div style="font-size:clamp(1rem, 2.5vw, 1.25rem);font-weight:700;color:#2c3e50;margin-top:clamp(4px, 1vw, 5px);">Rechazados</div></div>
</div>
<!-- FILTROS DE BÚSQUEDA (SOLO GET / LECTURA) -->
<div class="search-section-modern">
<div class="d-flex align-items-center justify-content-between mb-4">
<div class="d-flex align-items-center gap-3">
<div style="width:clamp(50px, 10vw, 60px);height:clamp(50px, 10vw, 60px);border-radius:clamp(14px, 3vw, 16px);background:linear-gradient(135deg,#4361ee,#3a0ca3);color:white;display:flex;align-items:center;justify-content:center;font-size:clamp(1.2rem, 2.5vw, 1.5rem);"><i class="fas fa-search"></i></div>
<div>
<h3 class="mb-1">Filtrar Trámites</h3>
<p class="text-muted mb-0">Consulta y filtra el estado de tus trámites</p>
</div>
</div>
<span class="read-only-badge"><i class="fas fa-eye me-1"></i> Modo Solo Lectura</span>
</div>
<form method="GET" action="seguimiento_tramites.php" class="row g-3">
<div class="col-md-3 col-12">
<label class="form-label fw-bold"><i class="fas fa-tag me-2"></i>Tipo de Trámite</label>
<select name="filtro_tipo" class="form-control-modern form-select" aria-label="Filtrar por tipo de trámite">
<option value="todos" <?php echo $filtro_tipo === 'todos' ? 'selected' : ''; ?>>Todos los tipos</option>
<option value="servicio" <?php echo $filtro_tipo === 'servicio' ? 'selected' : ''; ?>>🔔 Servicios</option>
<option value="sucursal" <?php echo $filtro_tipo === 'sucursal' ? 'selected' : ''; ?>>🏢 Sucursales</option>
<option value="personal" <?php echo $filtro_tipo === 'personal' ? 'selected' : ''; ?>>👥 Personal</option>
<option value="recursos" <?php echo $filtro_tipo === 'recursos' ? 'selected' : ''; ?>>📦 Recursos</option>
<option value="documento" <?php echo $filtro_tipo === 'documento' ? 'selected' : ''; ?>>📄 Documentos</option>
</select>
</div>
<div class="col-md-3 col-12">
<label class="form-label fw-bold"><i class="fas fa-filter me-2"></i>Estado</label>
<select name="filtro_estado" class="form-control-modern form-select" aria-label="Filtrar por estado">
<option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
<option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>⏳ Pendientes</option>
<option value="aprobado" <?php echo $filtro_estado === 'aprobado' ? 'selected' : ''; ?>>✅ Aprobados</option>
<option value="rechazado" <?php echo $filtro_estado === 'rechazado' ? 'selected' : ''; ?>>❌ Rechazados</option>
</select>
</div>
<div class="col-md-2 col-6">
<label class="form-label fw-bold"><i class="fas fa-calendar me-2"></i>Desde</label>
<input type="date" name="filtro_fecha_desde" class="form-control-modern form-control" value="<?php echo htmlspecialchars($filtro_fecha_desde); ?>" aria-label="Fecha desde">
</div>
<div class="col-md-2 col-6">
<label class="form-label fw-bold"><i class="fas fa-calendar me-2"></i>Hasta</label>
<input type="date" name="filtro_fecha_hasta" class="form-control-modern form-control" value="<?php echo htmlspecialchars($filtro_fecha_hasta); ?>" aria-label="Fecha hasta">
</div>
<div class="col-md-2 col-12 d-flex align-items-end gap-2">
<button type="submit" class="btn-search-modern flex-grow-1"><i class="fas fa-search"></i> Filtrar</button>
<a href="seguimiento_tramites.php" class="btn btn-outline-secondary" style="border-radius:var(--border-radius-sm);padding:clamp(10px, 2vw, 14px) clamp(15px, 3vw, 20px);" aria-label="Limpiar filtros"><i class="fas fa-redo"></i></a>
</div>
</form>
</div>
<!-- TABLA DE TRÁMITES -->
<h2 class="mb-4 d-flex align-items-center gap-3">
<i class="fas fa-list"></i> Historial de Trámites
<span class="badge bg-primary ms-2"><?php echo count($tramites_pagina); ?> de <?php echo $total_registros; ?></span>
</h2>
<?php if (count($tramites_pagina) > 0): ?>
<div class="resources-table-container">
<div class="table-responsive">
<table class="resources-table" id="tablaTramites" role="table">
<thead>
<tr>
<th scope="col"><i class="fas fa-tag me-2"></i>Tipo</th>
<th scope="col"><i class="fas fa-file me-2"></i>Descripción</th>
<th scope="col"><i class="fas fa-building me-2"></i>Sucursal</th>
<th scope="col"><i class="fas fa-calendar-plus me-2"></i>Fecha Envío</th>
<th scope="col"><i class="fas fa-clock me-2"></i>Estado</th>
<th scope="col"><i class="fas fa-hourglass me-2"></i>Tiempo Espera</th>
</tr>
</thead>
<tbody>
<?php foreach ($tramites_pagina as $tramite): ?>
<?php
$estado = strtolower($tramite['estado'] ?? 'pendiente');
$estado_class = ''; $estado_icon = '';
if ($estado === 'pendiente') { $estado_class = 'badge-pendiente'; $estado_icon = 'fa-clock'; }
elseif (in_array($estado, ['aprobado', 'aprobada', 'activo'])) { $estado_class = 'badge-aprobado'; $estado_icon = 'fa-check-circle'; }
elseif (in_array($estado, ['rechazado', 'rechazada'])) { $estado_class = 'badge-rechazado'; $estado_icon = 'fa-times-circle'; }
$tipo_class = ''; $tipo_icon = '';
switch($tramite['tipo_tramite']) {
case 'Servicio': $tipo_class = 'tipo-servicio'; $tipo_icon = 'fa-concierge-bell'; break;
case 'Sucursal': $tipo_class = 'tipo-sucursal'; $tipo_icon = 'fa-store'; break;
case 'Personal': $tipo_class = 'tipo-personal'; $tipo_icon = 'fa-user'; break;
case 'Recursos': $tipo_class = 'tipo-recursos'; $tipo_icon = 'fa-boxes'; break;
case 'Documento': $tipo_class = 'tipo-documento'; $tipo_icon = 'fa-file-pdf'; break;
}
$dias_espera = 0; $time_class = 'time-normal';
if ($estado === 'pendiente' && !empty($tramite['fecha_envio'])) {
$dias_espera = floor((time() - strtotime($tramite['fecha_envio'])) / 86400);
if ($dias_espera > 7) $time_class = 'time-danger';
elseif ($dias_espera > 3) $time_class = 'time-warning';
}
?>
<tr role="row">
<td data-label="Tipo"><span class="tipo-badge <?php echo $tipo_class; ?>"><i class="fas <?php echo $tipo_icon; ?> me-1"></i><?php echo $tramite['tipo_tramite']; ?></span></td>
<td data-label="Descripción"><strong><?php echo htmlspecialchars(substr($tramite['descripcion'], 0, 50)); ?><?php if (strlen($tramite['descripcion']) > 50) echo '...'; ?></strong></td>
<td data-label="Sucursal">
<?php if (!empty($tramite['sucursal_nombre'])): ?><span class="badge bg-info"><i class="fas fa-store me-1"></i><?php echo htmlspecialchars(substr($tramite['sucursal_nombre'], 0, 20)); ?></span>
<?php else: ?><span class="badge bg-secondary">N/A</span><?php endif; ?>
</td>
<td data-label="Fecha Envío"><i class="fas fa-calendar-alt text-muted me-1"></i><?php echo !empty($tramite['fecha_envio']) ? date('d/m/Y H:i', strtotime($tramite['fecha_envio'])) : 'N/A'; ?></td>
<td data-label="Estado"><span class="badge <?php echo $estado_class; ?>"><i class="fas <?php echo $estado_icon; ?> me-1"></i><?php echo ucfirst($estado); ?></span></td>
<td data-label="Tiempo Espera">
<?php if ($estado === 'pendiente'): ?><span class="time-badge <?php echo $time_class; ?>"><i class="fas fa-hourglass-half me-1"></i><?php echo $dias_espera; ?> día(s)</span>
<?php else: ?><span class="text-muted">-</span><?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<div class="pagination-info">Mostrando <?php echo $inicio + 1; ?> - <?php echo min($inicio + $registros_por_pagina, $total_registros); ?> de <?php echo $total_registros; ?> registros</div>
<?php if ($total_paginas > 1): ?>
<div class="pagination-container">
<?php if ($pagina_actual > 1): ?><a href="<?php echo construirUrlPaginacion($pagina_actual - 1); ?>" class="page-link" title="Página Anterior" aria-label="Página anterior"><i class="fas fa-chevron-left"></i></a>
<?php else: ?><span class="page-link disabled" aria-label="Página anterior"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
<?php $rango = 2; $inicio_paginas = max(1, $pagina_actual - $rango); $fin_paginas = min($total_paginas, $pagina_actual + $rango);
if ($inicio_paginas > 1): ?><a href="<?php echo construirUrlPaginacion(1); ?>" class="page-link" aria-label="Ir a página 1">1</a><?php if ($inicio_paginas > 2): ?><span class="page-link disabled" aria-label="Puntos suspensivos">...</span><?php endif; endif; ?>
<?php for ($i = $inicio_paginas; $i <= $fin_paginas; $i++): ?>
<?php if ($i == $pagina_actual): ?><span class="page-link active" aria-current="page"><?php echo $i; ?></span>
<?php else: ?><a href="<?php echo construirUrlPaginacion($i); ?>" class="page-link" aria-label="Ir a página <?php echo $i; ?>"><?php echo $i; ?></a><?php endif; ?>
<?php endfor; ?>
<?php if ($fin_paginas < $total_paginas): ?><?php if ($fin_paginas < $total_paginas - 1): ?><span class="page-link disabled" aria-label="Puntos suspensivos">...</span><?php endif; ?><a href="<?php echo construirUrlPaginacion($total_paginas); ?>" class="page-link" aria-label="Ir a página <?php echo $total_paginas; ?>"><?php echo $total_paginas; ?></a><?php endif; ?>
<?php if ($pagina_actual < $total_paginas): ?><a href="<?php echo construirUrlPaginacion($pagina_actual + 1); ?>" class="page-link" title="Página Siguiente" aria-label="Página siguiente"><i class="fas fa-chevron-right"></i></a>
<?php else: ?><span class="page-link disabled" aria-label="Página siguiente"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="empty-state-modern"><i class="fas fa-inbox fa-4x text-muted mb-3"></i><h3 class="mb-3">No hay trámites registrados</h3><p class="text-muted mb-4">No se encontraron trámites con los filtros seleccionados</p><a href="seguimiento_tramites.php" class="btn btn-primary btn-lg"><i class="fas fa-redo me-2"></i>Limpiar Filtros</a></div>
<?php endif; ?>
</div>
</div>
<!-- MODAL DETALLES (SOLO LECTURA) -->
<div class="modal fade" id="modalDetalleTramite" tabindex="-1" aria-hidden="true" aria-labelledby="modalDetalleTramiteLabel">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header" style="background: linear-gradient(135deg, #2c3e50, #1a252f); color: white;">
<h5 class="modal-title" id="modalDetalleTramiteLabel"><i class="fas fa-info-circle me-2"></i>Detalles del Trámite</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
</div>
<div class="modal-body" id="modalBodyDetalle">
<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Cargando detalles...</p></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div>
</div>
</div>
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function closeUrgencyAlert() {
const alert = document.getElementById('urgencyAlert');
if (alert) { alert.style.animation = 'slideInRight 0.5s ease reverse'; setTimeout(() => alert.style.display = 'none', 500); }
}
setTimeout(function() {
const alert = document.getElementById('urgencyAlert');
if (alert) { alert.style.animation = 'slideInRight 0.5s ease reverse'; setTimeout(() => alert.style.display = 'none', 500); }
}, 10000);
function verDetalle(id, tipo, usuarioAprobacion, usuarioRechazo, observaciones) {
const modal = new bootstrap.Modal(document.getElementById('modalDetalleTramite'));
const modalBody = document.getElementById('modalBodyDetalle');
modal.show();
modalBody.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Cargando detalles...</p></div>`;
setTimeout(() => {
let infoAprobacion = '';
if (usuarioAprobacion && usuarioAprobacion !== 'N/A') {
infoAprobacion = `<p class="text-success"><i class="fas fa-user-check me-1"></i>${usuarioAprobacion}</p>`;
} else {
infoAprobacion = `<p class="text-muted">-</p>`;
}
let infoRechazo = '';
if (usuarioRechazo && usuarioRechazo !== 'N/A') {
infoRechazo = `<p class="text-danger"><i class="fas fa-user-times me-1"></i>${usuarioRechazo}</p>`;
} else {
infoRechazo = `<p class="text-muted">-</p>`;
}
let infoObservaciones = '';
if (observaciones && observaciones.trim() !== '') {
infoObservaciones = `<p class="text-muted">${observaciones}</p>`;
} else {
infoObservaciones = `<p class="text-muted">Sin observaciones adicionales</p>`;
}
modalBody.innerHTML = `
<div class="row g-3">
<div class="col-12"><div class="alert alert-info"><i class="fas fa-info-circle me-2"></i><strong>Tipo:</strong> ${tipo} | <strong>ID:</strong> ${id}</div></div>
<div class="col-md-6"><label class="fw-bold">Fecha de Envío:</label><p class="text-muted">Consultar en tabla principal</p></div>
<div class="col-md-6"><label class="fw-bold">Estado Actual:</label><p class="text-muted">Consultar en tabla principal</p></div>
<div class="col-md-6"><label class="fw-bold"><i class="fas fa-user-check me-1"></i>Aprobado por:</label>${infoAprobacion}</div>
<div class="col-md-6"><label class="fw-bold"><i class="fas fa-user-times me-1"></i>Rechazado por:</label>${infoRechazo}</div>
<div class="col-12"><label class="fw-bold">Observaciones:</label>${infoObservaciones}</div>
<div class="col-12"><div class="alert alert-secondary mb-0"><small><i class="fas fa-info-circle me-1"></i>Nota: Los datos de aprobación/rechazo se mostrarán cuando estén disponibles en el sistema. Actualmente en modo solo lectura.</small></div></div>
</div>`;
}, 500);
}
// Toggle Sidebar (UI)
document.addEventListener('DOMContentLoaded', function() {
const toggleBtn = document.getElementById('toggleSidebarBtn');
const toggleIcon = document.getElementById('toggleIcon');
const body = document.body;
const savedState = localStorage.getItem('sidebarCollapsed');
const isMobile = window.innerWidth <= 991;
if (!isMobile && savedState === 'true') { body.classList.add('sidebar-collapsed'); if (toggleBtn) toggleBtn.classList.add('rotated'); if (toggleIcon) toggleIcon.className = 'fas fa-indent'; }
if (toggleBtn) {
toggleBtn.addEventListener('click', function() {
if (window.innerWidth <= 991) { body.classList.toggle('sidebar-mobile-open'); }
else { body.classList.toggle('sidebar-collapsed'); toggleBtn.classList.toggle('rotated'); toggleIcon.className = body.classList.contains('sidebar-collapsed') ? 'fas fa-indent' : 'fas fa-bars'; localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed')); }
});
}
// Handle window resize for responsive behavior
let resizeTimer;
window.addEventListener('resize', function() {
clearTimeout(resizeTimer);
resizeTimer = setTimeout(function() {
if (window.innerWidth <= 991) {
body.classList.remove('sidebar-collapsed');
if (toggleBtn) toggleBtn.classList.remove('rotated');
if (toggleIcon) toggleIcon.className = 'fas fa-bars';
localStorage.setItem('sidebarCollapsed', 'false');
}
}, 250);
});
// Improve modal behavior on mobile
const modalElement = document.getElementById('modalDetalleTramite');
if (modalElement) {
modalElement.addEventListener('shown.bs.modal', function() {
if (window.innerWidth <= 768) {
document.body.style.overflow = 'hidden';
}
});
modalElement.addEventListener('hidden.bs.modal', function() {
document.body.style.overflow = '';
});
}
// Prevent zoom on input focus for mobile - REMOVED to allow proper scaling
// Inputs now scale naturally with viewport
});
</script>
</body>
</html>