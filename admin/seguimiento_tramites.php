<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ============================================================================
// 1. VERIFICAR AUTENTICACIÓN Y PERMISOS (ADMIN, CARGA, OPERADOR)
// ============================================================================
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
$user = $auth->getCurrentUser();
$user_role = $user['rol'] ?? 'operador';
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. MANEJAR APROBACIÓN/RECHAZO DE TRÁMITES
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_tramite'])) {
try {
$tramite_id = (int)($_POST['tramite_id'] ?? 0);
$tipo_tramite = $_POST['tipo_tramite'] ?? '';
$accion = $_POST['accion'] ?? ''; // aprobar, rechazar
$observaciones = trim($_POST['observaciones'] ?? '');
if ($tramite_id <= 0 || empty($tipo_tramite) || empty($accion)) {
throw new Exception('Datos inválidos para procesar el trámite');
}
$conn->beginTransaction();
$fecha_actual = date('Y-m-d H:i:s');
$usuario_id = $user['id'];
switch($tipo_tramite) {
case 'Servicio':
$tabla = 'servicios';
$campo_estado = 'estado';
$campo_fecha_aprob = 'fecha_aprobacion';
$campo_fecha_rechazo = 'fecha_rechazo';
$campo_obs = 'observaciones_aprobacion';
break;
case 'Sucursal':
$tabla = 'sucursales';
$campo_estado = 'estado_aprobacion';
$campo_fecha_aprob = 'fecha_aprobacion';
$campo_fecha_rechazo = 'fecha_rechazo';
$campo_obs = 'observaciones_aprobacion';
break;
case 'Personal':
$tabla = 'personal';
$campo_estado = 'estado_documentacion';
$campo_fecha_aprob = 'fecha_revision_documentacion';
$campo_fecha_rechazo = NULL;
$campo_obs = 'observaciones';
break;
case 'Recursos':
$tabla = 'recursos_sucursal';
$campo_estado = 'estado';
$campo_fecha_aprob = 'fecha_aprobacion';
$campo_fecha_rechazo = 'fecha_rechazo';
$campo_obs = 'motivo_rechazo';
break;
case 'Documento':
$tabla = 'documentos_sucursales';
$campo_estado = 'estado';
$campo_fecha_aprob = 'fecha_aprobacion';
$campo_fecha_rechazo = 'fecha_rechazo';
$campo_obs = 'observaciones';
break;
default:
throw new Exception('Tipo de trámite no válido');
}
if ($accion === 'aprobar') {
$nuevo_estado = 'aprobado';
$updates = [
"$campo_estado = :estado",
"$campo_fecha_aprob = :fecha_aprob"
];
if ($campo_fecha_rechazo !== NULL) {
$updates[] = "$campo_fecha_rechazo = NULL";
}
$updates[] = "$campo_obs = :observaciones";
$sql = "UPDATE $tabla SET " . implode(",
", $updates) . " WHERE id = :id";
$params = [
':estado' => $nuevo_estado,
':fecha_aprob' => $fecha_actual,
':observaciones' => $observaciones,
':id' => $tramite_id
];
} elseif ($accion === 'rechazar') {
$nuevo_estado = 'rechazado';
$updates = [
"$campo_estado = :estado",
"$campo_fecha_rechazo = :fecha_rechazo",
"$campo_fecha_aprob = NULL",
"$campo_obs = :observaciones"
];
$sql = "UPDATE $tabla SET " . implode(",
", $updates) . " WHERE id = :id";
$params = [
':estado' => $nuevo_estado,
':fecha_rechazo' => $fecha_actual,
':observaciones' => $observaciones,
':id' => $tramite_id
];
} else {
throw new Exception('Acción no válida');
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
// Obtener datos para auditoría
$stmt = $conn->prepare("SELECT * FROM $tabla WHERE id = ?");
$stmt->execute([$tramite_id]);
$tramite_data = $stmt->fetch(PDO::FETCH_ASSOC);
// Log de auditoría
logAuditoria($conn, 'TRAMITE_' . strtoupper($accion), $tabla, $tramite_id, [
'accion' => $accion,
'tipo_tramite' => $tipo_tramite,
'estado_anterior' => $tramite_data[$campo_estado] ?? 'desconocido',
'estado_nuevo' => $nuevo_estado,
'observaciones' => $observaciones,
'usuario_id' => $usuario_id,
'usuario_nombre' => $user['nombre'] . ' ' . $user['apellido'],
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
], $usuario_id);
$conn->commit();
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>
Trámite <strong>" . strtoupper($accion) . "</strong> exitosamente.
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
} catch (Exception $e) {
if (isset($conn) && $conn->inTransaction()) {
$conn->rollBack();
}
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
}
header('Location: seguimiento_tramites.php?' . http_build_query($_GET));
exit;
}
// ============================================================================
// 3. FILTROS DE BÚSQUEDA
// ============================================================================
$filtro_tipo = $_GET['filtro_tipo'] ?? 'todos';
$filtro_estado = $_GET['filtro_estado'] ?? 'todos';
$filtro_empresa = $_GET['filtro_empresa'] ?? '';
$filtro_fecha_desde = $_GET['filtro_fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['filtro_fecha_hasta'] ?? '';
// ============================================================================
// 4. PAGINACIÓN Y ORDENAMIENTO
// ============================================================================
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$registros_por_pagina = 15;
$orden_campo = $_GET['orden'] ?? 'fecha_envio';
$orden_direccion = $_GET['direccion'] ?? 'DESC';
$campos_validos = ['fecha_envio', 'descripcion', 'estado', 'tipo_tramite', 'sucursal_nombre', 'empresa_nombre', 'fecha_aprobacion', 'fecha_rechazo'];
if (!in_array($orden_campo, $campos_validos)) $orden_campo = 'fecha_envio';
if (!in_array(strtoupper($orden_direccion), ['ASC', 'DESC'])) $orden_direccion = 'DESC';
// ============================================================================
// 5. OBTENER TODOS LOS TRÁMITES (TODAS LAS EMPRESAS)
// ============================================================================
$tramites = [];
// 1. SUCURSALES PENDIENTES
try {
$where = "WHERE 1=1";
$params = [];
if ($filtro_empresa !== '') {
$where .= " AND s.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_tipo !== 'todos' && $filtro_tipo !== 'sucursal') {
// No aplicar filtro
} elseif ($filtro_tipo === 'sucursal') {
$where .= " AND 1=1";
}
if ($filtro_estado !== 'todos') {
$where .= " AND s.estado_aprobacion = ?";
$params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
$where .= " AND s.fecha_solicitud >= ?";
$params[] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$where .= " AND s.fecha_solicitud <= ?";
$params[] = $filtro_fecha_hasta . ' 23:59:59';
}
$stmt = $conn->prepare("
SELECT
'Sucursal' as tipo_tramite,
s.id,
s.nombre as descripcion,
s.fecha_solicitud as fecha_envio,
s.estado_aprobacion as estado,
s.fecha_aprobacion,
s.fecha_rechazo,
s.observaciones_aprobacion,
s.nombre as sucursal_nombre,
e.nombre as empresa_nombre,
e.id as empresa_id
FROM sucursales s
LEFT JOIN empresas e ON s.empresa_id = e.id
$where
ORDER BY s.fecha_solicitud DESC
");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
error_log("Error sucursales: " . $e->getMessage());
}
// 2. SERVICIOS PENDIENTES
try {
$where = "WHERE 1=1";
$params = [];
if ($filtro_empresa !== '') {
$where .= " AND s.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_tipo !== 'todos' && $filtro_tipo !== 'servicio') {
// No aplicar filtro
} elseif ($filtro_tipo === 'servicio') {
$where .= " AND 1=1";
}
if ($filtro_estado !== 'todos') {
$where .= " AND s.estado = ?";
$params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
$where .= " AND s.created_at >= ?";
$params[] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$where .= " AND s.created_at <= ?";
$params[] = $filtro_fecha_hasta . ' 23:59:59';
}
$stmt = $conn->prepare("
SELECT
'Servicio' as tipo_tramite,
s.id,
s.nombre as descripcion,
s.created_at as fecha_envio,
s.estado,
s.fecha_aprobacion,
s.fecha_rechazo,
s.observaciones_aprobacion,
suc.nombre as sucursal_nombre,
e.nombre as empresa_nombre,
e.id as empresa_id
FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
$where
ORDER BY s.created_at DESC
");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
error_log("Error servicios: " . $e->getMessage());
}
// 3. PERSONAL (DOCUMENTACIÓN)
try {
$where = "WHERE 1=1";
$params = [];
if ($filtro_empresa !== '') {
$where .= " AND p.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_tipo !== 'todos' && $filtro_tipo !== 'personal') {
// No aplicar filtro
} elseif ($filtro_tipo === 'personal') {
$where .= " AND 1=1";
}
if ($filtro_estado !== 'todos') {
$where .= " AND p.estado_documentacion = ?";
$params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
$where .= " AND p.updated_at >= ?";
$params[] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$where .= " AND p.updated_at <= ?";
$params[] = $filtro_fecha_hasta . ' 23:59:59';
}
$stmt = $conn->prepare("
SELECT
'Personal' as tipo_tramite,
p.id,
CONCAT(p.nombre, ' ', p.apellido) as descripcion,
p.updated_at as fecha_envio,
p.estado_documentacion as estado,
p.fecha_revision_documentacion as fecha_aprobacion,
NULL as fecha_rechazo,
p.observaciones as observaciones_aprobacion,
p.aprobacion_credencial as aprobacion_credencial,
suc.nombre as sucursal_nombre,
e.nombre as empresa_nombre,
e.id as empresa_id
FROM personal p
LEFT JOIN sucursales suc ON p.sucursal_id = suc.id
LEFT JOIN empresas e ON p.empresa_id = e.id
$where
ORDER BY p.updated_at DESC
");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
error_log("Error personal: " . $e->getMessage());
}
// 4. RECURSOS PENDIENTES
try {
$tableCheck = $conn->query("SHOW TABLES LIKE 'recursos_sucursal'");
if ($tableCheck->rowCount() > 0) {
$where = "WHERE 1=1";
$params = [];
if ($filtro_empresa !== '') {
$where .= " AND rs.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_tipo !== 'todos' && $filtro_tipo !== 'recurso') {
// No aplicar filtro
} elseif ($filtro_tipo === 'recurso') {
$where .= " AND 1=1";
}
if ($filtro_estado !== 'todos') {
$where .= " AND rs.estado = ?";
$params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
$where .= " AND rs.created_at >= ?";
$params[] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$where .= " AND rs.created_at <= ?";
$params[] = $filtro_fecha_hasta . ' 23:59:59';
}
$stmt = $conn->prepare("
SELECT
'Recurso' as tipo_tramite,
rs.id,
CONCAT('Recursos - ', COALESCE(p.nombre, 'Sucursal')) as descripcion,
rs.created_at as fecha_envio,
rs.estado,
rs.fecha_aprobacion,
rs.fecha_rechazo,
rs.motivo_rechazo as observaciones_aprobacion,
s.nombre as sucursal_nombre,
e.nombre as empresa_nombre,
e.id as empresa_id
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
$where
ORDER BY rs.created_at DESC
");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
} catch(PDOException $e) {
error_log("Error recursos: " . $e->getMessage());
}
// 5. DOCUMENTOS SUCURSALES
try {
$tableCheck = $conn->query("SHOW TABLES LIKE 'documentos_sucursales'");
if ($tableCheck->rowCount() > 0) {
$where = "WHERE 1=1";
$params = [];
if ($filtro_empresa !== '') {
$where .= " AND d.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_tipo !== 'todos' && $filtro_tipo !== 'documento') {
// No aplicar filtro
} elseif ($filtro_tipo === 'documento') {
$where .= " AND 1=1";
}
if ($filtro_estado !== 'todos') {
$where .= " AND d.estado = ?";
$params[] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
$where .= " AND d.fecha_carga >= ?";
$params[] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$where .= " AND d.fecha_carga <= ?";
$params[] = $filtro_fecha_hasta . ' 23:59:59';
}
$stmt = $conn->prepare("
SELECT
'Documento' as tipo_tramite,
d.id,
CONCAT(d.tipo_documento, ' - ', SUBSTRING(d.archivo_pdf, 25)) as descripcion,
d.fecha_carga as fecha_envio,
d.estado,
d.fecha_aprobacion,
d.fecha_rechazo,
d.observaciones as observaciones_aprobacion,
s.nombre as sucursal_nombre,
e.nombre as empresa_nombre,
e.id as empresa_id
FROM documentos_sucursales d
LEFT JOIN sucursales s ON d.sucursal_id = s.id
LEFT JOIN empresas e ON d.empresa_id = e.id
$where
ORDER BY d.fecha_carga DESC
");
$stmt->execute($params);
$tramites = array_merge($tramites, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
} catch(PDOException $e) {
error_log("Error documentos: " . $e->getMessage());
}
// Ordenar por campo dinámico
usort($tramites, function($a, $b) use ($orden_campo, $orden_direccion) {
$valA = $a[$orden_campo] ?? '';
$valB = $b[$orden_campo] ?? '';
if (is_numeric($valA) && is_numeric($valB)) {
$comparison = $valA <=> $valB;
} else {
$comparison = strcasecmp($valA, $valB);
}
return $orden_direccion === 'DESC' ? -$comparison : $comparison;
});
// Aplicar paginación
$total_registros = count($tramites);
$total_paginas = ceil($total_registros / $registros_por_pagina);
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$tramites_paginados = array_slice($tramites, $offset, $registros_por_pagina);
// ============================================================================
// 6. ESTADÍSTICAS
// ============================================================================
$stats = [
'total' => count($tramites),
'pendientes' => 0,
'aprobados' => 0,
'rechazados' => 0
];
foreach ($tramites as $t) {
$estado = strtolower($t['estado'] ?? 'pendiente');
if ($estado === 'pendiente') {
$stats['pendientes']++;
} elseif (in_array($estado, ['aprobado', 'aprobada', 'activo'])) {
$stats['aprobados']++;
} elseif (in_array($estado, ['rechazado', 'rechazada'])) {
$stats['rechazados']++;
}
}
// ============================================================================
// 7. OBTENER LISTA DE EMPRESAS PARA FILTRO
// ============================================================================
$empresas_lista = [];
try {
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY nombre");
$empresas_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
error_log("Error obteniendo empresas: " . $e->getMessage());
}
// ============================================================================
// 8. FUNCIONES DE UTILIDAD
// ============================================================================
function generarUrlOrden($campo, $direccion_actual) {
$params = $_GET;
$params['orden'] = $campo;
$params['direccion'] = ($campo === ($params['orden'] ?? '') && $direccion_actual === 'ASC') ? 'DESC' : 'ASC';
unset($params['pagina']);
return '?' . http_build_query($params);
}
function getIconoOrden($campo, $orden_campo, $orden_direccion) {
if ($campo !== $orden_campo) return '<i class="fas fa-sort text-muted ms-1"></i>';
return $orden_direccion === 'ASC' ? '<i class="fas fa-sort-up text-primary ms-1"></i>' : '<i class="fas fa-sort-down text-primary ms-1"></i>';
}
function getPuedeAprobar($user_role, $estado) {
$estado_lower = strtolower($estado);
if ($estado_lower !== 'pendiente') return false;
if ($user_role === 'administrador') return true;
if ($user_role === 'carga') return true;
if ($user_role === 'operador') return false;
return false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seguimiento de Trámites - Administración</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="../css/sweetalert2.all.min.js"></script>
<style>
:root {
--primary-color: #0d6efd;
--bg-color: #f8f9fa;
--card-border: #dee2e6;
--text-color: #212529;
}
body {
padding-top: 80px;
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
background-color: var(--bg-color);
color: var(--text-color);
}
.section-box {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
padding: 20px;
margin-bottom: 20px;
box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.section-title {
font-size: 1.25rem;
font-weight: 600;
color: #495057;
margin-bottom: 15px;
border-bottom: 1px solid var(--card-border);
padding-bottom: 10px;
}
.stats-container {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
gap: 15px;
margin-bottom: 20px;
}
.stat-card {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
padding: 15px;
text-align: center;
}
.stat-number {
font-size: 1.5rem;
font-weight: 700;
color: var(--primary-color);
}
.stat-label {
font-size: 0.85rem;
color: #6c757d;
text-transform: uppercase;
}
.table-container {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
overflow: hidden;
}
.table {
margin-bottom: 0;
}
.table thead {
background-color: #f8f9fa;
border-bottom: 2px solid var(--card-border);
}
.table thead th {
font-weight: 600;
color: #495057;
border: none;
padding: 12px;
}
.table tbody tr {
border-bottom: 1px solid var(--card-border);
}
.table tbody tr:hover {
background-color: #f8f9fa;
}
.form-label {
font-weight: 600;
font-size: 0.9rem;
color: #495057;
}
.form-control, .form-select {
border-radius: 4px;
border: 1px solid #ced4da;
padding: 8px 12px;
}
.form-control:focus, .form-select:focus {
border-color: var(--primary-color);
box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}
.btn {
border-radius: 4px;
font-weight: 500;
padding: 8px 16px;
}
.btn-primary {
background-color: var(--primary-color);
border-color: var(--primary-color);
}
.modal-content {
border-radius: 4px;
border: none;
}
.modal-header {
background-color: #f8f9fa;
border-bottom: 1px solid var(--card-border);
border-radius: 4px 4px 0 0;
}
.modal-title {
font-weight: 600;
color: #495057;
}
.pagination-custom {
gap: 4px;
}
.pagination-custom .page-link {
color: #495057;
background-color: #f8f9fa;
border: 1px solid #dee2e6;
border-radius: 4px !important;
padding: 8px 12px;
margin: 0 2px;
font-weight: 500;
transition: all 0.2s ease;
min-width: 40px;
text-align: center;
}
.pagination-custom .page-link:hover {
background-color: #e9ecef;
border-color: #adb5bd;
color: #495057;
}
.pagination-custom .page-item.active .page-link {
background-color: #0d6efd;
border-color: #0d6efd;
color: #ffffff;
font-weight: 600;
}
.pagination-custom .page-item.disabled .page-link {
color: #6c757d;
background-color: #f8f9fa;
border-color: #dee2e6;
cursor: not-allowed;
opacity: 0.6;
}
.pagination-custom .page-link i {
font-size: 0.85rem;
}
.pagination-custom .page-item.active .page-link i {
color: #ffffff;
}
.badge-pendiente { background: #ffc107 !important; color: #000; }
.badge-aprobado { background: #28a745 !important; color: #fff; }
.badge-rechazado { background: #dc3545 !important; color: #fff; }
.tipo-badge {
padding: 5px 12px;
border-radius: 4px;
font-size: 0.75rem;
font-weight: 700;
text-transform: uppercase;
}
.tipo-servicio { background: #3498db; color: white; }
.tipo-sucursal { background: #9b59b6; color: white; }
.tipo-personal { background: #e67e22; color: white; }
.tipo-recursos { background: #27ae60; color: white; }
.tipo-documento { background: #e74c3c; color: white; }
.role-badge {
background: #6f42c1;
color: white;
padding: 4px 12px;
border-radius: 4px;
font-size: 0.75rem;
font-weight: 700;
text-transform: uppercase;
}
.search-section {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
padding: 20px;
margin-bottom: 20px;
}
.empty-state {
text-align: center;
padding: 40px 20px;
background: #f8f9fa;
border-radius: 4px;
margin-top: 20px;
border: 1px solid var(--card-border);
}
</style>
</head>
<body class="sidebar-collapsed">
<?php $page_title = 'Registro de Infracciones'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
<i class="fas fa-check-circle"></i> <?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
<i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- BADGE DE ROL -->
<div class="d-flex justify-content-between align-items-center mb-4">
<h2><i class="fas fa-clipboard-list me-2"></i>Seguimiento de Trámites</h2>
<span class="role-badge">
<i class="fas fa-user-shield me-1"></i><?php echo ucfirst($user_role); ?>
</span>
</div>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-clipboard-list fa-2x"></i></div>
<div class="stat-number"><?php echo $stats['total']; ?></div>
<div class="stat-label">Total Trámites</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-clock fa-2x"></i></div>
<div class="stat-number"><?php echo $stats['pendientes']; ?></div>
<div class="stat-label">Pendientes</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $stats['aprobados']; ?></div>
<div class="stat-label">Aprobados</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-times-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $stats['rechazados']; ?></div>
<div class="stat-label">Rechazados</div>
</div>
</div>
<!-- FILTROS DE BÚSQUEDA -->
<div class="section-box">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#contenidoFiltros" style="cursor: pointer;" title="Clic para mostrar/ocultar filtros">
<i class="fas fa-filter me-2"></i>Filtrar Trámites
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="contenidoFiltros" class="collapse">
<form method="GET" action="seguimiento_tramites.php" class="row g-3">
<input type="hidden" name="orden" value="<?php echo htmlspecialchars($orden_campo); ?>">
<input type="hidden" name="direccion" value="<?php echo htmlspecialchars($orden_direccion); ?>">
<div class="col-md-3">
<label class="form-label"><i class="fas fa-building me-2"></i>Empresa</label>
<select name="filtro_empresa" class="form-select">
<option value="">Todas las empresas</option>
<?php foreach ($empresas_lista as $emp): ?>
<option value="<?php echo $emp['id']; ?>" <?php echo $filtro_empresa == $emp['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($emp['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label"><i class="fas fa-tag me-2"></i>Tipo</label>
<select name="filtro_tipo" class="form-select">
<option value="todos" <?php echo $filtro_tipo === 'todos' ? 'selected' : ''; ?>>Todos</option>
<option value="sucursal" <?php echo $filtro_tipo === 'sucursal' ? 'selected' : ''; ?>>🏢 Sucursales</option>
<option value="servicio" <?php echo $filtro_tipo === 'servicio' ? 'selected' : ''; ?>>🔔 Servicios</option>
<option value="personal" <?php echo $filtro_tipo === 'personal' ? 'selected' : ''; ?>>👥 Personal</option>
<option value="recurso" <?php echo $filtro_tipo === 'recurso' ? 'selected' : ''; ?>>📦 Recursos</option>
<option value="documento" <?php echo $filtro_tipo === 'documento' ? 'selected' : ''; ?>>📄 Documentos</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label"><i class="fas fa-filter me-2"></i>Estado</label>
<select name="filtro_estado" class="form-select">
<option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
<option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>⏳ Pendientes</option>
<option value="aprobado" <?php echo $filtro_estado === 'aprobado' ? 'selected' : ''; ?>>✅ Aprobados</option>
<option value="rechazado" <?php echo $filtro_estado === 'rechazado' ? 'selected' : ''; ?>>❌ Rechazados</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label"><i class="fas fa-calendar me-2"></i>Desde</label>
<input type="date" name="filtro_fecha_desde" class="form-control" value="<?php echo htmlspecialchars($filtro_fecha_desde); ?>">
</div>
<div class="col-md-2">
<label class="form-label"><i class="fas fa-calendar me-2"></i>Hasta</label>
<input type="date" name="filtro_fecha_hasta" class="form-control" value="<?php echo htmlspecialchars($filtro_fecha_hasta); ?>">
</div>
<div class="col-md-1 d-flex align-items-end gap-2">
<button type="submit" class="btn btn-primary flex-grow-1">
<i class="fas fa-search"></i>
</button>
</div>
</form>
<div class="d-flex gap-3 mt-4">
<a href="seguimiento_tramites.php" class="btn btn-secondary">
<i class="fas fa-redo"></i> Limpiar
</a>
<button class="btn btn-success" onclick="exportarExcel()">
<i class="fas fa-file-excel me-2"></i>Exportar Excel
</button>
<button class="btn btn-danger" onclick="exportarPDF()">
<i class="fas fa-file-pdf me-2"></i>Exportar PDF
</button>
</div>
</div>
</div>
<!-- TABLA DE TRÁMITES -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-list me-2"></i>Historial de Trámites
<span class="badge bg-primary ms-2"><?php echo count($tramites_paginados); ?> de <?php echo $total_registros; ?></span>
</div>
<?php if (count($tramites_paginados) > 0): ?>
<div class="table-responsive">
<table class="table table-hover" id="tablaTramites">
<thead>
<tr>
<th><a href="<?php echo generarUrlOrden('empresa_nombre', $orden_direccion); ?>" class="text-decoration-none text-dark">
<i class="fas fa-building me-2"></i>Empresa<?php echo getIconoOrden('empresa_nombre', $orden_campo, $orden_direccion); ?>
</a></th>
<th><a href="<?php echo generarUrlOrden('tipo_tramite', $orden_direccion); ?>" class="text-decoration-none text-dark">
<i class="fas fa-tag me-2"></i>Tipo<?php echo getIconoOrden('tipo_tramite', $orden_campo, $orden_direccion); ?>
</a></th>
<th><a href="<?php echo generarUrlOrden('descripcion', $orden_direccion); ?>" class="text-decoration-none text-dark">
<i class="fas fa-file me-2"></i>Descripción<?php echo getIconoOrden('descripcion', $orden_campo, $orden_direccion); ?>
</a></th>
<th><a href="<?php echo generarUrlOrden('sucursal_nombre', $orden_direccion); ?>" class="text-decoration-none text-dark">
<i class="fas fa-store me-2"></i>Sucursal<?php echo getIconoOrden('sucursal_nombre', $orden_campo, $orden_direccion); ?>
</a></th>
<th><a href="<?php echo generarUrlOrden('fecha_envio', $orden_direccion); ?>" class="text-decoration-none text-dark">
<i class="fas fa-calendar-plus me-2"></i>Fecha<?php echo getIconoOrden('fecha_envio', $orden_campo, $orden_direccion); ?>
</a></th>
<th><i class="fas fa-calendar-check me-2"></i>Fecha Resolución</th>
<th><a href="<?php echo generarUrlOrden('estado', $orden_direccion); ?>" class="text-decoration-none text-dark">
<i class="fas fa-clock me-2"></i>Estado<?php echo getIconoOrden('estado', $orden_campo, $orden_direccion); ?>
</a></th>
<th class="text-center"><i class="fas fa-cog me-2"></i>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($tramites_paginados as $tramite): ?>
<?php
$estado = strtolower($tramite['estado'] ?? 'pendiente');
$estado_class = '';
$estado_icon = '';
if ($estado === 'pendiente') {
$estado_class = 'badge-pendiente';
$estado_icon = 'fa-clock';
} elseif (in_array($estado, ['aprobado', 'aprobada', 'activo'])) {
$estado_class = 'badge-aprobado';
$estado_icon = 'fa-check-circle';
} elseif (in_array($estado, ['rechazado', 'rechazada'])) {
$estado_class = 'badge-rechazado';
$estado_icon = 'fa-times-circle';
}
$tipo_class = '';
$tipo_icon = '';
switch($tramite['tipo_tramite']) {
case 'Servicio': $tipo_class = 'tipo-servicio'; $tipo_icon = 'fa-concierge-bell'; break;
case 'Sucursal': $tipo_class = 'tipo-sucursal'; $tipo_icon = 'fa-store'; break;
case 'Personal': $tipo_class = 'tipo-personal'; $tipo_icon = 'fa-user'; break;
case 'Recursos': $tipo_class = 'tipo-recursos'; $tipo_icon = 'fa-boxes'; break;
case 'Documento': $tipo_class = 'tipo-documento'; $tipo_icon = 'fa-file-pdf'; break;
}
$puede_aprobar = getPuedeAprobar($user_role, $estado);
$fecha_resolucion = !empty($tramite['fecha_rechazo']) ? $tramite['fecha_rechazo'] : $tramite['fecha_aprobacion'];
?>
<tr>
<td>
<span class="badge bg-info">
<i class="fas fa-building me-1"></i>
<?php echo htmlspecialchars(substr($tramite['empresa_nombre'] ?? 'N/A', 0, 25)); ?>
</span>
</td>
<td>
<span class="tipo-badge <?php echo $tipo_class; ?>">
<i class="fas <?php echo $tipo_icon; ?> me-1"></i>
<?php echo $tramite['tipo_tramite']; ?>
</span>
</td>
<td>
<strong><?php echo htmlspecialchars(substr($tramite['descripcion'], 0, 40)); ?>
<?php if (strlen($tramite['descripcion']) > 40) echo '...'; ?></strong>
</td>
<td>
<?php if (!empty($tramite['sucursal_nombre'])): ?>
<span class="badge bg-secondary">
<i class="fas fa-store me-1"></i>
<?php echo htmlspecialchars(substr($tramite['sucursal_nombre'], 0, 20)); ?>
</span>
<?php else: ?>
<span class="badge bg-light text-dark">N/A</span>
<?php endif; ?>
</td>
<td>
<i class="fas fa-calendar-alt text-muted me-1"></i>
<?php echo !empty($tramite['fecha_envio']) ? date('d/m/Y H:i', strtotime($tramite['fecha_envio'])) : 'N/A'; ?>
</td>
<td>
<?php if (!empty($fecha_resolucion)): ?>
<i class="fas fa-calendar-check text-muted me-1"></i>
<?php echo date('d/m/Y H:i', strtotime($fecha_resolucion)); ?>
<?php else: ?>
<span class="text-muted">N/A</span>
<?php endif; ?>
</td>
<td>
<span class="badge <?php echo $estado_class; ?>">
<i class="fas <?php echo $estado_icon; ?> me-1"></i>
<?php echo ucfirst($estado); ?>
</span>
<?php if ($tramite['tipo_tramite'] === 'Personal' && !empty($tramite['aprobacion_credencial'])): ?>
<br><small class="text-muted d-block mt-1"><?php echo htmlspecialchars($tramite['aprobacion_credencial']); ?></small>
<?php endif; ?>
</td>
<td class="text-center">
<div class="btn-group btn-group-sm">
<button class="btn btn-outline-primary" onclick="verDetalle(<?php echo $tramite['id']; ?>, '<?php echo $tramite['tipo_tramite']; ?>', <?php echo $tramite['empresa_id']; ?>)" title="Ver Detalles">
<i class="fas fa-eye"></i>
</button>
<?php if ($puede_aprobar): ?>
<button class="btn btn-outline-success" onclick="mostrarModalAprobacion(<?php echo $tramite['id']; ?>, '<?php echo $tramite['tipo_tramite']; ?>', '<?php echo $tramite['empresa_nombre']; ?>')" title="Aprobar">
<i class="fas fa-check"></i>
</button>
<button class="btn btn-outline-danger" onclick="mostrarModalRechazo(<?php echo $tramite['id']; ?>, '<?php echo $tramite['tipo_tramite']; ?>', '<?php echo $tramite['empresa_nombre']; ?>')" title="Rechazar">
<i class="fas fa-times"></i>
</button>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- PAGINACIÓN -->
<?php if ($total_paginas > 1): ?>
<div class="d-flex justify-content-center align-items-center mt-4 mb-3">
<nav aria-label="Paginación de trámites">
<ul class="pagination pagination-custom mb-0">
<?php if ($pagina_actual > 1): ?>
<li class="page-item">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" title="Primera">
<i class="fas fa-angle-double-left"></i>
</a>
</li>
<li class="page-item">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual - 1])); ?>" title="Anterior">
<i class="fas fa-chevron-left"></i>
</a>
</li>
<?php endif; ?>
<?php for ($i = max(1, $pagina_actual - 2); $i <= min($total_paginas, $pagina_actual + 2); $i++): ?>
<li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
</li>
<?php endfor; ?>
<?php if ($pagina_actual < $total_paginas): ?>
<li class="page-item">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_actual + 1])); ?>" title="Siguiente">
<i class="fas fa-chevron-right"></i>
</a>
</li>
<li class="page-item">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>" title="Última">
<i class="fas fa-angle-double-right"></i>
</a>
</li>
<?php endif; ?>
</ul>
</nav>
<span class="ms-3 text-muted">
Página <strong><?php echo $pagina_actual; ?></strong> de <strong><?php echo $total_paginas; ?></strong>
</span>
</div>
<?php endif; ?>
<?php else: ?>
<div class="empty-state">
<i class="fas fa-inbox fa-3x text-muted mb-3"></i>
<h5 class="mb-3">No hay trámites registrados</h5>
<p class="text-muted mb-4">No se encontraron trámites con los filtros seleccionados</p>
<a href="seguimiento_tramites.php" class="btn btn-primary">
<i class="fas fa-redo me-2"></i>Limpiar Filtros
</a>
</div>
<?php endif; ?>
</div>
</div>
</div>
<!-- MODAL DETALLES -->
<div class="modal fade" id="modalDetalleTramite" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Detalles del Trámite</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="modalBodyDetalle">
<div class="text-center py-5">
<div class="spinner-border text-primary" role="status"></div>
<p class="mt-3">Cargando detalles...</p>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>
<!-- MODAL APROBAR -->
<div class="modal fade" id="modalAprobarTramite" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-success text-white">
<h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Aprobar Trámite</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="seguimiento_tramites.php">
<div class="modal-body">
<input type="hidden" name="accion_tramite" value="1">
<input type="hidden" name="tramite_id" id="aprobar_tramite_id">
<input type="hidden" name="tipo_tramite" id="aprobar_tipo_tramite">
<input type="hidden" name="accion" value="aprobar">
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>
<strong>Empresa:</strong> <span id="aprobar_empresa_nombre"></span>
</div>
<div class="mb-3">
<label class="form-label">Observaciones (Opcional)</label>
<textarea name="observaciones" class="form-control" rows="3" placeholder="Agregar observaciones..."></textarea>
</div>
<div class="alert alert-success">
<i class="fas fa-check-circle me-2"></i>
El trámite será marcado como <strong>APROBADO</strong>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-success">
<i class="fas fa-check me-1"></i>Confirmar Aprobación
</button>
</div>
</form>
</div>
</div>
</div>
<!-- MODAL RECHAZAR -->
<div class="modal fade" id="modalRechazarTramite" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-danger text-white">
<h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Rechazar Trámite</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="seguimiento_tramites.php">
<div class="modal-body">
<input type="hidden" name="accion_tramite" value="1">
<input type="hidden" name="tramite_id" id="rechazar_tramite_id">
<input type="hidden" name="tipo_tramite" id="rechazar_tipo_tramite">
<input type="hidden" name="accion" value="rechazar">
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>
<strong>Empresa:</strong> <span id="rechazar_empresa_nombre"></span>
</div>
<div class="mb-3">
<label class="form-label">Motivo del Rechazo <span class="text-danger">*</span></label>
<textarea name="observaciones" class="form-control" rows="3" placeholder="Explique el motivo del rechazo..." required></textarea>
</div>
<div class="alert alert-danger">
<i class="fas fa-exclamation-triangle me-2"></i>
El trámite será marcado como <strong>RECHAZADO</strong>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-danger">
<i class="fas fa-times me-1"></i>Confirmar Rechazo
</button>
</div>
</form>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Toggle del icono al expandir/colapsar
formContentBody.addEventListener('shown.bs.collapse', function() {
toggleIcon.classList.remove('collapsed');
});
formContentBody.addEventListener('hidden.bs.collapse', function() {
toggleIcon.classList.add('collapsed');
});
function verDetalle(id, tipo, empresa_id) {
const modal = new bootstrap.Modal(document.getElementById('modalDetalleTramite'));
const modalBody = document.getElementById('modalBodyDetalle');
modal.show();
modalBody.innerHTML = `
<div class="text-center py-5">
<div class="spinner-border text-primary" role="status"></div>
<p class="mt-3">Cargando detalles...</p>
</div>
`;
setTimeout(() => {
modalBody.innerHTML = `
<div class="row g-3">
<div class="col-md-12">
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>
<strong>Tipo:</strong> ${tipo} | <strong>ID:</strong> ${id}
</div>
</div>
<div class="col-md-6">
<label class="fw-bold">Fecha de Envío:</label>
<p class="text-muted">Ver en tabla principal</p>
</div>
<div class="col-md-6">
<label class="fw-bold">Estado Actual:</label>
<p class="text-muted">Ver en tabla principal</p>
</div>
<div class="col-md-12">
<label class="fw-bold">Observaciones:</label>
<p class="text-muted">Sin observaciones adicionales</p>
</div>
</div>
`;
}, 500);
}
function mostrarModalAprobacion(id, tipo, empresa) {
document.getElementById('aprobar_tramite_id').value = id;
document.getElementById('aprobar_tipo_tramite').value = tipo;
document.getElementById('aprobar_empresa_nombre').textContent = empresa;
const modal = new bootstrap.Modal(document.getElementById('modalAprobarTramite'));
modal.show();
}
function mostrarModalRechazo(id, tipo, empresa) {
document.getElementById('rechazar_tramite_id').value = id;
document.getElementById('rechazar_tipo_tramite').value = tipo;
document.getElementById('rechazar_empresa_nombre').textContent = empresa;
const modal = new bootstrap.Modal(document.getElementById('modalRechazarTramite'));
modal.show();
}
function exportarExcel() {
Swal.fire({
title: '<i class="fas fa-file-excel"></i> Exportar a Excel',
text: 'Se generará un archivo Excel con todos los trámites filtrados',
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#27ae60',
cancelButtonColor: '#95a5a6',
confirmButtonText: '<i class="fas fa-check"></i> Sí, Exportar',
cancelButtonText: '<i class="fas fa-times"></i> Cancelar'
}).then((result) => {
if (result.isConfirmed) {
window.location.href = 'seguimiento_tramites.php?' + new URLSearchParams(window.location.search) + '&exportar=excel';
}
});
}
function exportarPDF() {
Swal.fire({
title: '<i class="fas fa-file-pdf"></i> Exportar a PDF',
text: 'Se generará un archivo PDF con todos los trámites filtrados',
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#dc3545',
cancelButtonColor: '#95a5a6',
confirmButtonText: '<i class="fas fa-check"></i> Sí, Exportar',
cancelButtonText: '<i class="fas fa-times"></i> Cancelar'
}).then((result) => {
if (result.isConfirmed) {
window.location.href = 'seguimiento_tramites.php?' + new URLSearchParams(window.location.search) + '&exportar=pdf';
}
});
}
function ordenarTabla(campo) {
const params = new URLSearchParams(window.location.search);
const currentOrder = params.get('orden');
const currentDir = params.get('direccion') || 'DESC';
if (currentOrder === campo) {
params.set('direccion', currentDir === 'ASC' ? 'DESC' : 'ASC');
} else {
params.set('orden', campo);
params.set('direccion', 'ASC');
}
params.delete('pagina');
window.location.search = params.toString();
}
// Toggle Sidebar - CORREGIDO: Funcionalidad y estado inicial contraído
document.addEventListener('DOMContentLoaded', function() {
const toggleBtn = document.getElementById('toggleSidebarBtn');
const toggleIcon = document.getElementById('toggleIcon');
const body = document.body;
const savedState = localStorage.getItem('sidebarCollapsed');
const isMobile = window.innerWidth <= 991;
// CORRECCIÓN 1: Sidebar contraído por defecto al ingresar (si no hay estado guardado o si está guardado como true)
if (!isMobile && (savedState === null || savedState === 'true')) {
body.classList.add('sidebar-collapsed');
if (toggleBtn) toggleBtn.classList.add('rotated');
if (toggleIcon) toggleIcon.className = 'fas fa-indent';
}
// CORRECCIÓN 2: Funcionalidad del botón toggle
if (toggleBtn) {
toggleBtn.addEventListener('click', function(e) {
e.preventDefault();
if (window.innerWidth <= 991) {
body.classList.toggle('sidebar-mobile-open');
} else {
body.classList.toggle('sidebar-collapsed');
toggleBtn.classList.toggle('rotated');
// Actualizar icono según estado
if (body.classList.contains('sidebar-collapsed')) {
toggleIcon.className = 'fas fa-indent';
localStorage.setItem('sidebarCollapsed', 'true');
} else {
toggleIcon.className = 'fas fa-bars';
localStorage.setItem('sidebarCollapsed', 'false');
}
}
});
}
// Manejar resize de ventana para ajustar estado del sidebar
window.addEventListener('resize', function() {
const isNowMobile = window.innerWidth <= 991;
if (isNowMobile) {
body.classList.remove('sidebar-collapsed');
if (toggleBtn) toggleBtn.classList.remove('rotated');
if (toggleIcon) toggleIcon.className = 'fas fa-bars';
}
});
});
</script>
</body>
</html>