<?php
/**
* ============================================================================
* GESTIÓN DE INSPECCIONES PROGRAMADAS
* ============================================================================
* Incluye: CRUD completo, Auditoría detallada, Calendario FullCalendar,
*          Validaciones, Paginación, Búsqueda, Filtros Avanzados,
*          Integración con inspecciones.php para marcar como realizadas
*          DISEÑO UNIFICADO CON SUCURSALES.PHP E INSPECCIONES.PHP
*
* @author Sistema de Seguridad
* @version 1.0 - Módulo de Programación de Inspecciones
* @last_update 2024
* ============================================================================
*/
// ============================================================================
// INCLUSIÓN DE CONFIGURACIONES
// ============================================================================
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ============================================================================
// VERIFICACIÓN DE AUTENTICACIÓN Y PERMISOS
// ============================================================================
if (!$auth->isLoggedIn()) {
header('Location: ../login.php');
exit;
}
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
$current_page = 'inspecciones_programadas';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// FUNCIÓN SANITIZE INPUT
// ============================================================================
if (!function_exists('sanitizeInput')) {
function sanitizeInput($data) {
return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
}
// ============================================================================
// ✅ AJAX - OBTENER SUCURSALES POR EMPRESA (NUEVO HANDLER)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_sucursales') {
header('Content-Type: application/json');
try {
$empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
if ($empresa_id <= 0) {
echo json_encode(['success' => true, 'sucursales' => []]);
exit;
}
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activo = TRUE ORDER BY nombre");
$stmt->execute([$empresa_id]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'sucursales' => $sucursales]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// ✅ AJAX - OBTENER PROGRAMACIONES PARA CALENDARIO
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_programaciones_calendar') {
header('Content-Type: application/json');
try {
$fecha_desde = $_GET['start'] ?? date('Y-m-01');
$fecha_hasta = $_GET['end'] ?? date('Y-m-t');
$stmt = $conn->prepare("
SELECT
p.id,
p.fecha_programada,
p.hora_programada,
p.estado,
p.frecuencia,
p.observaciones_planificacion,
e.nombre as empresa_nombre,
s.nombre as sucursal_nombre,
u.username as inspector_nombre
FROM inspecciones_programadas p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
LEFT JOIN usuarios u ON p.inspector_id = u.id
WHERE p.fecha_programada BETWEEN ? AND ?
ORDER BY p.fecha_programada ASC
");
$stmt->execute([$fecha_desde, $fecha_hasta]);
$programaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$events = [];
foreach ($programaciones as $prog) {
$color = '#f39c12'; // pendiente
if ($prog['estado'] === 'realizada') $color = '#27ae60';
if ($prog['estado'] === 'cancelada') $color = '#95a5a6';
if ($prog['estado'] === 'vencida') $color = '#e74c3c';
$events[] = [
'id' => $prog['id'],
'title' => 'Inspección: ' . ($prog['sucursal_nombre'] ?? $prog['empresa_nombre']),
'start' => $prog['fecha_programada'] . ($prog['hora_programada'] ? 'T' . $prog['hora_programada'] : ''),
'backgroundColor' => $color,
'borderColor' => $color,
'extendedProps' => [
'estado' => $prog['estado'],
'frecuencia' => $prog['frecuencia'],
'inspector' => $prog['inspector_nombre'],
'observaciones' => $prog['observaciones_planificacion']
]
];
}
echo json_encode(['success' => true, 'events' => $events]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// ✅ AJAX - OBTENER PROGRAMACIONES PENDIENTES PARA VINCULAR
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_programaciones_pendientes') {
header('Content-Type: application/json');
try {
$stmt = $conn->query("
SELECT
p.id,
p.fecha_programada,
p.inspector_id,
p.sucursal_id,
e.nombre as empresa_nombre,
s.nombre as sucursal_nombre
FROM inspecciones_programadas p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.estado = 'pendiente'
ORDER BY p.fecha_programada ASC
");
$programaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $programaciones]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// ✅ AJAX - MARCAR PROGRAMACIÓN COMO REALIZADA
// ============================================================================
if (isset($_POST['action']) && $_POST['action'] === 'marcar_programacion_realizada') {
header('Content-Type: application/json');
try {
$programacion_id = (int)($_POST['programacion_id'] ?? 0);
$inspeccion_id = isset($_POST['inspeccion_id']) && !empty($_POST['inspeccion_id']) ? (int)$_POST['inspeccion_id'] : null;
if ($programacion_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de programación inválido']);
exit;
}
// CORRECCIÓN: Solo actualizar inspeccion_id_relacionado si existe un ID válido
if ($inspeccion_id && $inspeccion_id > 0) {
$stmt = $conn->prepare("
UPDATE inspecciones_programadas
SET estado = 'realizada', inspeccion_id_relacionado = ?
WHERE id = ?
");
$stmt->execute([$inspeccion_id, $programacion_id]);
} else {
// Marcar como realizada sin vincular a inspección específica
$stmt = $conn->prepare("
UPDATE inspecciones_programadas
SET estado = 'realizada', inspeccion_id_relacionado = NULL
WHERE id = ?
");
$stmt->execute([$programacion_id]);
}
logAuditoria($conn, 'PROGRAMACION_MARCADA_REALIZADA', 'inspecciones_programadas', $programacion_id, [
'programacion_id' => $programacion_id,
'inspeccion_id' => $inspeccion_id,
'usuario' => $user['username']
], $user['id']);
echo json_encode(['success' => true, 'message' => 'Programación marcada como realizada']);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// ✅ CREAR NUEVA PROGRAMACIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_programacion'])) {
try {
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
$inspector_id = !empty($_POST['inspector_id']) ? (int)$_POST['inspector_id'] : $user['id'];
$fecha_programada = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : date('Y-m-d');
$hora_programada = !empty($_POST['hora_programada']) ? $_POST['hora_programada'] : null;
$frecuencia = $_POST['frecuencia'] ?? 'UNICA';
$observaciones = sanitizeInput($_POST['observaciones_planificacion'] ?? '');
if (empty($empresa_id)) {
throw new Exception('La empresa es obligatoria');
}
if (empty($inspector_id)) {
throw new Exception('El Funcionario Actuantees obligatorio');
}
$stmt = $conn->prepare("
INSERT INTO inspecciones_programadas
(empresa_id, sucursal_id, inspector_id, fecha_programada, hora_programada, frecuencia, observaciones_planificacion, estado, creado_por, fecha_creacion)
VALUES (:empresa_id, :sucursal_id, :inspector_id, :fecha_programada, :hora_programada, :frecuencia, :observaciones, 'pendiente', :creado_por, NOW())
");
$stmt->execute([
'empresa_id' => $empresa_id,
'sucursal_id' => $sucursal_id,
'inspector_id' => $inspector_id,
'fecha_programada' => $fecha_programada,
'hora_programada' => $hora_programada,
'frecuencia' => $frecuencia,
'observaciones' => $observaciones,
'creado_por' => $user['id']
]);
$programacion_id = $conn->lastInsertId();
logAuditoria($conn, 'INSPECCION_PROGRAMADA_CREADA', 'inspecciones_programadas', $programacion_id, [
'empresa_id' => $empresa_id,
'sucursal_id' => $sucursal_id,
'inspector_id' => $inspector_id,
'fecha_programada' => $fecha_programada,
'frecuencia' => $frecuencia,
'usuario' => $user['username']
], $user['id']);
$_SESSION['success'] = 'Inspección programada correctamente';
header('Location: inspecciones_programadas.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_PROGRAMACION_CREADA', 'inspecciones_programadas', null, ['error' => $e->getMessage()], $user['id']);
$_SESSION['error'] = 'Error al programar inspección: ' . $e->getMessage();
header('Location: inspecciones_programadas.php');
exit;
}
}
// ============================================================================
// ✅ ACTUALIZAR PROGRAMACIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_programacion'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) throw new Exception('ID inválido');
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
$inspector_id = !empty($_POST['inspector_id']) ? (int)$_POST['inspector_id'] : null;
$fecha_programada = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : null;
$hora_programada = !empty($_POST['hora_programada']) ? $_POST['hora_programada'] : null;
$frecuencia = $_POST['frecuencia'] ?? 'UNICA';
$observaciones = sanitizeInput($_POST['observaciones_planificacion'] ?? '');
$estado = $_POST['estado'] ?? 'pendiente';
// CORRECCIÓN: No actualizar inspeccion_id_relacionado desde este formulario
$stmt = $conn->prepare("
UPDATE inspecciones_programadas
SET empresa_id = :empresa_id,
sucursal_id = :sucursal_id,
inspector_id = :inspector_id,
fecha_programada = :fecha_programada,
hora_programada = :hora_programada,
frecuencia = :frecuencia,
observaciones_planificacion = :observaciones,
estado = :estado
WHERE id = :id
");
$stmt->execute([
'empresa_id' => $empresa_id,
'sucursal_id' => $sucursal_id,
'inspector_id' => $inspector_id,
'fecha_programada' => $fecha_programada,
'hora_programada' => $hora_programada,
'frecuencia' => $frecuencia,
'observaciones' => $observaciones,
'estado' => $estado,
'id' => $id
]);
logAuditoria($conn, 'INSPECCION_PROGRAMADA_ACTUALIZADA', 'inspecciones_programadas', $id, [
'usuario' => $user['username']
], $user['id']);
$_SESSION['success'] = 'Programación actualizada correctamente';
header('Location: inspecciones_programadas.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al actualizar: ' . $e->getMessage();
header('Location: inspecciones_programadas.php');
exit;
}
}
// ============================================================================
// ✅ ELIMINAR/CANCELAR PROGRAMACIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_programacion'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) throw new Exception('ID inválido');
$stmt = $conn->prepare("UPDATE inspecciones_programadas SET estado = 'cancelada' WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'INSPECCION_PROGRAMADA_CANCELADA', 'inspecciones_programadas', $id, [
'usuario' => $user['username']
], $user['id']);
$_SESSION['success'] = 'Programación cancelada correctamente';
header('Location: inspecciones_programadas.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al cancelar: ' . $e->getMessage();
header('Location: inspecciones_programadas.php');
exit;
}
}
// ============================================================================
// ✅ OBTENER DATOS CON FILTROS Y PAGINACIÓN
// ============================================================================
$filtro_empresa = isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa']) ? (int)$_GET['filtro_empresa'] : 0;
$filtro_inspector = isset($_GET['filtro_inspector']) && !empty($_GET['filtro_inspector']) ? (int)$_GET['filtro_inspector'] : 0;
$filtro_estado = isset($_GET['filtro_estado']) && !empty($_GET['filtro_estado']) ? $_GET['filtro_estado'] : 'todos';
$filtro_fecha_desde = isset($_GET['filtro_fecha_desde']) && !empty($_GET['filtro_fecha_desde']) ? $_GET['filtro_fecha_desde'] : '';
$filtro_fecha_hasta = isset($_GET['filtro_fecha_hasta']) && !empty($_GET['filtro_fecha_hasta']) ? $_GET['filtro_fecha_hasta'] : '';
$registros_por_pagina = 15;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$where_clauses = [];
$params = [];
if ($filtro_empresa > 0) {
$where_clauses[] = "p.empresa_id = :filtro_empresa";
$params['filtro_empresa'] = $filtro_empresa;
}
if ($filtro_inspector > 0) {
$where_clauses[] = "p.inspector_id = :filtro_inspector";
$params['filtro_inspector'] = $filtro_inspector;
}
if ($filtro_estado !== 'todos') {
$where_clauses[] = "p.estado = :filtro_estado";
$params['filtro_estado'] = $filtro_estado;
}
if (!empty($filtro_fecha_desde)) {
$where_clauses[] = "p.fecha_programada >= :fecha_desde";
$params['fecha_desde'] = $filtro_fecha_desde;
}
if (!empty($filtro_fecha_hasta)) {
$where_clauses[] = "p.fecha_programada <= :fecha_hasta";
$params['fecha_hasta'] = $filtro_fecha_hasta;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
// Contador total
$count_sql = "SELECT COUNT(*) as total FROM inspecciones_programadas p $where_sql";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
// Obtener programaciones
$query = "
SELECT
p.*,
e.nombre as empresa_nombre,
s.nombre as sucursal_nombre,
u.username as inspector_username,
u.nombre_completo as inspector_completo
FROM inspecciones_programadas p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
LEFT JOIN usuarios u ON p.inspector_id = u.id
$where_sql
ORDER BY p.fecha_programada ASC
LIMIT $registros_por_pagina OFFSET $offset
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$programaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Datos auxiliares
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll();
// ✅ FILTRO: Funcionario Actuante- Todos los roles MENOS 'empresa'
$stmt = $conn->query("SELECT id, username, nombre_completo FROM usuarios WHERE activo = TRUE AND rol != 'empresa' ORDER BY nombre_completo");
$inspectores = $stmt->fetchAll();
// Estadísticas
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE estado = 'pendiente'");
$total_pendientes = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE estado = 'realizada'");
$total_realizadas = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE estado = 'vencida'");
$total_vencidas = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada = CURDATE() AND estado = 'pendiente'");
$total_hoy = $stmt->fetch()['total'];
// Programación para editar
$programacion_edit = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
$edit_id = (int)$_GET['edit'];
$stmt = $conn->prepare("SELECT * FROM inspecciones_programadas WHERE id = ?");
$stmt->execute([$edit_id]);
$programacion_edit = $stmt->fetch();
}
// ============================================================================
// FUNCIÓN GENERAR URL ORDEN
// ============================================================================
function mantenerFiltros() {
$params = $_GET;
unset($params['pagina'], $params['edit']);
return !empty($params) ? '&' . http_build_query($params) : '';
}
logAuditoria($conn, 'VISUALIZACION_PROGRAMACIONES', 'inspecciones_programadas', null, [
'usuario' => $user['username'],
'filtros' => compact('filtro_empresa', 'filtro_inspector', 'filtro_estado')
], $user['id']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inspecciones Programadas - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet' />
<link rel="stylesheet" href="../css/sweetalert2.min.css">
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
.pagination .page-link {
color: var(--primary-color);
border-radius: 4px;
margin: 0 2px;
border: 1px solid var(--card-border);
}
.pagination .page-item.active .page-link {
background-color: var(--primary-color);
border-color: var(--primary-color);
}
/* Estados */
.badge-pendiente { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
.badge-realizada { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
.badge-cancelada { background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: white; }
.badge-vencida { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
/* Calendario */
#calendar {
background: white;
padding: 20px;
border-radius: 8px;
border: 1px solid var(--card-border);
}
.fc-event {
cursor: pointer;
}
.filtros-box {
background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
border: 1px solid var(--card-border);
border-radius: 8px;
padding: 20px;
margin-bottom: 20px;
box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}
.icono-rotar {
transition: transform 0.3s ease;
}
.icono-rotar.rotado {
transform: rotate(180deg);
}
</style>
</head>
<body>
<?php $page_title = 'Inspecciones Programadas'; include '../includes/header.php'; ?>
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
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-calendar-check fa-2x"></i></div>
<div class="stat-number"><?php echo $total_registros; ?></div>
<div class="stat-label">Total Programadas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-clock fa-2x"></i></div>
<div class="stat-number"><?php echo $total_pendientes; ?></div>
<div class="stat-label">Pendientes</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $total_realizadas; ?></div>
<div class="stat-label">Realizadas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-exclamation-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $total_vencidas; ?></div>
<div class="stat-label">Vencidas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-calendar-day fa-2x"></i></div>
<div class="stat-number"><?php echo $total_hoy; ?></div>
<div class="stat-label">Para Hoy</div>
</div>
</div>
<!-- CALENDARIO -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-calendar-alt me-2"></i>Calendario de Inspecciones
</div>
<div id='calendar'></div>
</div>
<!-- FILTROS -->
<div class="filtros-box">
<div class="d-flex justify-content-between align-items-center mb-3"
data-bs-toggle="collapse"
data-bs-target="#collapseFiltros"
style="cursor: pointer;">
<h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtros de Búsqueda</h5>
<i class="fas fa-chevron-down icono-rotar" id="iconoFiltros"></i>
</div>
<div class="collapse" id="collapseFiltros">
<form method="GET" action="" class="row g-3 mt-2">
<div class="col-md-3">
<label class="form-label">Empresa</label>
<select name="filtro_empresa" class="form-select">
<option value="">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo $filtro_empresa == $empresa['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Inspector</label>
<select name="filtro_inspector" class="form-select">
<option value="">Todos los inspectores</option>
<?php foreach ($inspectores as $inspector): ?>
<option value="<?php echo $inspector['id']; ?>" <?php echo $filtro_inspector == $inspector['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($inspector['nombre_completo'] ?? $inspector['username']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="filtro_estado" class="form-select">
<option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
<option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
<option value="realizada" <?php echo $filtro_estado === 'realizada' ? 'selected' : ''; ?>>Realizadas</option>
<option value="cancelada" <?php echo $filtro_estado === 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
<option value="vencida" <?php echo $filtro_estado === 'vencida' ? 'selected' : ''; ?>>Vencidas</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Desde</label>
<input type="date" name="filtro_fecha_desde" class="form-control" value="<?php echo $filtro_fecha_desde; ?>">
</div>
<div class="col-md-2">
<label class="form-label">Hasta</label>
<input type="date" name="filtro_fecha_hasta" class="form-control" value="<?php echo $filtro_fecha_hasta; ?>">
</div>
<div class="col-12 text-end mt-3">
<button type="submit" class="btn btn-primary btn-sm">
<i class="fas fa-search me-1"></i>Filtrar
</button>
<a href="inspecciones_programadas.php" class="btn btn-secondary btn-sm">
<i class="fas fa-times me-1"></i>Limpiar
</a>
</div>
</form>
</div>
</div>
<!-- NUEVA PROGRAMACIÓN -->
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center"
data-bs-toggle="collapse"
data-bs-target="#nuevaProgramacionForm"
style="cursor: pointer;">
<span><i class="fas fa-plus-circle me-2"></i>Nueva Programación</span>
<i class="fas fa-chevron-down icono-rotar" id="iconoNuevaProgramacion"></i>
</div>
<div class="collapse mt-3 <?php echo $programacion_edit ? 'show' : ''; ?>" id="nuevaProgramacionForm">
<h5 class="mb-3">
<i class="fas fa-calendar-plus me-2"></i>
<?php echo $programacion_edit ? 'Editar' : 'Registrar Nueva'; ?> Programación
</h5>
<form method="POST" action="" class="row g-4">
<?php if ($programacion_edit): ?>
<input type="hidden" name="id" value="<?php echo $programacion_edit['id']; ?>">
<?php endif; ?>
<div class="col-md-6">
<label class="form-label required">Empresa *</label>
<select name="empresa_id" class="form-select" required>
<option value="">Seleccione una empresa...</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>"
<?php echo ($programacion_edit && $programacion_edit['empresa_id'] == $empresa['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal</label>
<select name="sucursal_id" class="form-select" id="sucursalSelect">
<option value="">Casa Central</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label required">Funcionario Actuante*</label>
<select name="inspector_id" class="form-select" required>
<option value="">Seleccione inspector...</option>
<?php foreach ($inspectores as $inspector): ?>
<option value="<?php echo $inspector['id']; ?>"
<?php echo ($programacion_edit && $programacion_edit['inspector_id'] == $inspector['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($inspector['nombre_completo'] ?? $inspector['username']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-4">
<label class="form-label required">Fecha Programada *</label>
<input type="date" name="fecha_programada" class="form-control"
value="<?php echo $programacion_edit['fecha_programada'] ?? date('Y-m-d'); ?>" required>
</div>
<div class="col-md-4">
<label class="form-label">Hora (Opcional)</label>
<input type="time" name="hora_programada" class="form-control"
value="<?php echo $programacion_edit['hora_programada'] ?? ''; ?>">
</div>
<div class="col-md-4">
<label class="form-label">Frecuencia</label>
<select name="frecuencia" class="form-select">
<option value="UNICA" <?php echo ($programacion_edit && $programacion_edit['frecuencia'] === 'UNICA') ? 'selected' : ''; ?>>Única</option>
<option value="MENSUAL" <?php echo ($programacion_edit && $programacion_edit['frecuencia'] === 'MENSUAL') ? 'selected' : ''; ?>>Mensual</option>
<option value="TRIMESTRAL" <?php echo ($programacion_edit && $programacion_edit['frecuencia'] === 'TRIMESTRAL') ? 'selected' : ''; ?>>Trimestral</option>
<option value="SEMESTRAL" <?php echo ($programacion_edit && $programacion_edit['frecuencia'] === 'SEMESTRAL') ? 'selected' : ''; ?>>Semestral</option>
<option value="ANUAL" <?php echo ($programacion_edit && $programacion_edit['frecuencia'] === 'ANUAL') ? 'selected' : ''; ?>>Anual</option>
</select>
</div>
<div class="col-md-8">
<label class="form-label">Observaciones</label>
<textarea name="observaciones_planificacion" class="form-control" rows="2"><?php echo htmlspecialchars($programacion_edit['observaciones_planificacion'] ?? ''); ?></textarea>
</div>
<?php if ($programacion_edit): ?>
<div class="col-md-4">
<label class="form-label">Estado</label>
<select name="estado" class="form-select">
<option value="pendiente" <?php echo $programacion_edit['estado'] === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
<option value="realizada" <?php echo $programacion_edit['estado'] === 'realizada' ? 'selected' : ''; ?>>Realizada</option>
<option value="cancelada" <?php echo $programacion_edit['estado'] === 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
<option value="vencida" <?php echo $programacion_edit['estado'] === 'vencida' ? 'selected' : ''; ?>>Vencida</option>
</select>
</div>
<?php endif; ?>
<div class="col-12 text-end">
<button type="submit" name="<?php echo $programacion_edit ? 'actualizar_programacion' : 'crear_programacion'; ?>"
class="btn btn-success btn-lg px-5">
<i class="fas fa-save me-2"></i>
<?php echo $programacion_edit ? 'Actualizar' : 'Guardar Programación'; ?>
</button>
<?php if ($programacion_edit): ?>
<a href="inspecciones_programadas.php" class="btn btn-secondary btn-lg px-5 ms-2">
<i class="fas fa-times me-2"></i> Cancelar
</a>
<?php endif; ?>
</div>
</form>
</div>
</div>
<!-- LISTADO -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Programaciones
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<?php if (empty($programaciones)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
<h5>No hay programaciones registradas</h5>
<p class="text-muted">No se encontraron programaciones con los filtros aplicados.</p>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaProgramacionForm">
<i class="fas fa-plus me-2"></i>Crear Nueva Programación
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th>ID</th>
<th>Fecha</th>
<th>Empresa/Sucursal</th>
<th>Inspector</th>
<th>Frecuencia</th>
<th>Estado</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($programaciones as $prog): ?>
<tr>
<td><strong>#<?php echo $prog['id']; ?></strong></td>
<td>
<?php echo !empty($prog['fecha_programada']) ? date('d/m/Y', strtotime($prog['fecha_programada'])) : '-'; ?>
<?php if (!empty($prog['hora_programada'])): ?>
<br><small class="text-muted"><?php echo substr($prog['hora_programada'], 0, 5); ?> hs</small>
<?php endif; ?>
</td>
<td>
<strong><?php echo htmlspecialchars($prog['empresa_nombre'] ?? 'N/A'); ?></strong>
<?php if (!empty($prog['sucursal_nombre'])): ?>
<br><small class="text-muted"><?php echo htmlspecialchars($prog['sucursal_nombre']); ?></small>
<?php endif; ?>
</td>
<td><?php echo htmlspecialchars($prog['inspector_completo'] ?? $prog['inspector_username'] ?? 'N/A'); ?></td>
<td>
<span class="badge bg-info"><?php echo $prog['frecuencia']; ?></span>
</td>
<td>
<?php if ($prog['estado'] === 'pendiente'): ?>
<span class="badge badge-pendiente"><i class="fas fa-clock"></i> Pendiente</span>
<?php elseif ($prog['estado'] === 'realizada'): ?>
<span class="badge badge-realizada"><i class="fas fa-check"></i> Realizada</span>
<?php elseif ($prog['estado'] === 'cancelada'): ?>
<span class="badge badge-cancelada"><i class="fas fa-times"></i> Cancelada</span>
<?php elseif ($prog['estado'] === 'vencida'): ?>
<span class="badge badge-vencida"><i class="fas fa-exclamation"></i> Vencida</span>
<?php endif; ?>
</td>
<td>
<div class="btn-group" role="group">
<a href="inspecciones_programadas.php?edit=<?php echo $prog['id']; ?><?php echo mantenerFiltros(); ?>"
class="btn btn-sm btn-outline-primary" title="Editar">
<i class="fas fa-edit"></i>
</a>
<?php if ($prog['estado'] === 'pendiente'): ?>
<button class="btn btn-sm btn-outline-success btn-marcar-realizada"
data-id="<?php echo $prog['id']; ?>" title="Marcar como Realizada">
<i class="fas fa-check"></i>
</button>
<?php endif; ?>
<?php if ($prog['estado'] !== 'cancelada' && $prog['estado'] !== 'realizada'): ?>
<button class="btn btn-sm btn-outline-danger btn-cancelar"
data-id="<?php echo $prog['id']; ?>" title="Cancelar">
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
<?php if ($total_paginas > 1): ?>
<div class="d-flex justify-content-center mt-3">
<nav aria-label="Paginación">
<ul class="pagination mb-0">
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>">
<i class="fas fa-angle-double-left"></i>
</a>
</li>
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => max(1, $pagina_actual - 1)])); ?>">
<i class="fas fa-angle-left"></i>
</a>
</li>
<?php
$rango = 2;
$inicio = max(1, $pagina_actual - $rango);
$fin = min($total_paginas, $pagina_actual + $rango);
if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
for ($i = $inicio; $i <= $fin; $i++):
?>
<li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
</li>
<?php endfor; ?>
<?php if ($fin < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => min($total_paginas, $pagina_actual + 1)])); ?>">
<i class="fas fa-angle-right"></i>
</a>
</li>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>">
<i class="fas fa-angle-double-right"></i>
</a>
</li>
</ul>
</nav>
<span class="ms-3 text-muted align-self-center">
Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
</span>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
<!-- MODAL MARCAR COMO REALIZADA -->
<div class="modal fade" id="modalMarcarRealizada" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-success text-white">
<h5 class="modal-title"><i class="fas fa-check-circle"></i> Marcar como Realizada</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p>¿Desea marcar esta programación como realizada?</p>
<p class="text-muted">Esto permitirá vincularla con una inspección existente.</p>
<input type="hidden" id="programacionIdRealizada">
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-success" onclick="confirmarMarcarRealizada()">
<i class="fas fa-check"></i> Confirmar
</button>
</div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Rotar iconos
const collapseFiltros = document.getElementById('collapseFiltros');
const iconoFiltros = document.getElementById('iconoFiltros');
if (collapseFiltros && iconoFiltros) {
collapseFiltros.addEventListener('show.bs.collapse', () => iconoFiltros.classList.add('rotado'));
collapseFiltros.addEventListener('hide.bs.collapse', () => iconoFiltros.classList.remove('rotado'));
}
const collapseNueva = document.getElementById('nuevaProgramacionForm');
const iconoNueva = document.getElementById('iconoNuevaProgramacion');
if (collapseNueva && iconoNueva) {
collapseNueva.addEventListener('show.bs.collapse', () => iconoNueva.classList.add('rotado'));
collapseNueva.addEventListener('hide.bs.collapse', () => iconoNueva.classList.remove('rotado'));
}
// Calendario FullCalendar
var calendarEl = document.getElementById('calendar');
var calendar = new FullCalendar.Calendar(calendarEl, {
initialView: 'dayGridMonth',
locale: 'es',
headerToolbar: {
left: 'prev,next today',
center: 'title',
right: 'dayGridMonth,timeGridWeek,listWeek'
},
events: function(info, successCallback, failureCallback) {
fetch('inspecciones_programadas.php?action=get_programaciones_calendar&start=' + info.startStr + '&end=' + info.endStr)
.then(response => response.json())
.then(data => {
if (data.success) {
successCallback(data.events);
} else {
failureCallback(data.error);
}
})
.catch(error => failureCallback(error));
},
eventClick: function(info) {
Swal.fire({
title: 'Inspección Programada',
html: `
<p><strong>Estado:</strong> ${info.event.extendedProps.estado}</p>
<p><strong>Frecuencia:</strong> ${info.event.extendedProps.frecuencia}</p>
<p><strong>Inspector:</strong> ${info.event.extendedProps.inspector || 'N/A'}</p>
<p><strong>Observaciones:</strong> ${info.event.extendedProps.observaciones || 'Sin observaciones'}</p>
`,
icon: 'info',
showCancelButton: true,
confirmButtonText: 'Gestionar',
cancelButtonText: 'Cerrar'
}).then((result) => {
if (result.isConfirmed) {
window.location.href = 'inspecciones_programadas.php?edit=' + info.event.id;
}
});
}
});
calendar.render();
// Cargar sucursales al seleccionar empresa - CORREGIDO: usa mismo archivo
const empresaSelect = document.querySelector('select[name="empresa_id"]');
const sucursalSelect = document.getElementById('sucursalSelect');
if (empresaSelect && sucursalSelect) {
empresaSelect.addEventListener('change', function() {
const empresaId = this.value;
sucursalSelect.innerHTML = '<option value="">Casa Central</option>';
if (empresaId) {
// CORRECCIÓN: Cambiado a mismo archivo para evitar error de ruta
fetch('inspecciones_programadas.php?action=get_sucursales&empresa_id=' + empresaId)
.then(response => response.json())
.then(data => {
if (data.success && data.sucursales && data.sucursales.length > 0) {
data.sucursales.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
sucursalSelect.appendChild(option);
});
}
})
.catch(error => {
console.error('Error al cargar sucursales:', error);
Swal.fire('Error', 'No se pudieron cargar las sucursales de esta empresa', 'error');
});
}
});
// Si hay una empresa preseleccionada (edición), cargar sus sucursales automáticamente
if (empresaSelect.value) {
empresaSelect.dispatchEvent(new Event('change'));
}
}
// Marcar como realizada
document.querySelectorAll('.btn-marcar-realizada').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
document.getElementById('programacionIdRealizada').value = id;
new bootstrap.Modal(document.getElementById('modalMarcarRealizada')).show();
});
});
// Cancelar programación
document.querySelectorAll('.btn-cancelar').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
Swal.fire({
title: '¿Cancelar programación?',
text: 'Esta acción no se puede deshacer',
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#e74c3c',
confirmButtonText: 'Sí, cancelar',
cancelButtonText: 'No'
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'inspecciones_programadas.php';
form.innerHTML = `
<input type="hidden" name="eliminar_programacion" value="1">
<input type="hidden" name="id" value="${id}">
`;
document.body.appendChild(form);
form.submit();
}
});
});
});
// Auto-cerrar alertas
setTimeout(() => {
document.querySelectorAll('.alert').forEach(alert => {
new bootstrap.Alert(alert).close();
});
}, 5000);
});
function confirmarMarcarRealizada() {
const programacionId = document.getElementById('programacionIdRealizada').value;
Swal.fire({
title: 'Vincular con Inspección',
text: '¿Desea ir a inspecciones.php para crear/vincular una inspección?',
icon: 'question',
showCancelButton: true,
confirmButtonText: 'Ir a Inspecciones',
cancelButtonText: 'Solo marcar realizada'
}).then((result) => {
if (result.isConfirmed) {
// CORRECCIÓN: Obtener datos de la programación para pasar inspector_id y sucursal_id
fetch('inspecciones_programadas.php?action=get_programaciones_pendientes')
.then(response => response.json())
.then(data => {
if (data.success && data.data) {
const programacion = data.data.find(p => p.id == programacionId);
let url = 'inspecciones.php?programacion_id=' + programacionId;
if (programacion && programacion.inspector_id) {
url += '&funcionario_nombre=' + programacion.inspector_id;
}
if (programacion && programacion.sucursal_id) {
url += '&sucursal_id=' + programacion.sucursal_id;
}
window.location.href = url;
} else {
// Fallback si no se pueden obtener los datos
window.location.href = 'inspecciones.php?programacion_id=' + programacionId;
}
})
.catch(error => {
console.error('Error al obtener datos de programación:', error);
window.location.href = 'inspecciones.php?programacion_id=' + programacionId;
});
} else {
// CORRECCIÓN: Marcar como realizada sin inspeccion_id
fetch('inspecciones_programadas.php', {
method: 'POST',
headers: {'Content-Type': 'application/x-www-form-urlencoded'},
body: 'action=marcar_programacion_realizada&programacion_id=' + programacionId
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire('Éxito', data.message, 'success').then(() => {
location.reload();
});
} else {
Swal.fire('Error', data.message, 'error');
}
})
.catch(error => {
Swal.fire('Error', 'Error de conexión: ' + error, 'error');
});
}
});
}
</script>
</body>
</html>