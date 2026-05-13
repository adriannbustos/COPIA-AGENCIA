<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ==================== INICIALIZAR CONEXIÓN ====================
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user = $auth->getCurrentUser();
// ==================== VERIFICAR AUTENTICACIÓN ====================
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
// ==================== FUNCIÓN PARA OBTENER LABEL DE TIPO MOVIMIENTO ====================
if (!function_exists('getTipoMovimientoLabel')) {
    function getTipoMovimientoLabel($value) {
        $labels = [
            'cambio_domicilio' => 'Cambio de Domicilio',
            'cambio_director' => 'Cambio Director Técnico',
            'cambio_duenos' => 'Cambio de Dueños',
            'cambio_nombre' => 'Cambio de Nombre',
            'cedula' => 'Cédula',
            'falta_doc_habilitacion_empresa' => 'Falta Documentacion para habilitacion de Empresa',
            'falta_doc_habilitacion_sucursal' => 'Falta Documentacion para habilitacion de Sucursal',
            'falta_doc_habilitacion_personal' => 'Falta Documentacion para habilitacion del Personal',
            'otros' => 'Otros'
        ];
        return $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}
// ==================== GESTIÓN DE TRÁMITES EMPRESARIALES ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
// ✅ NUEVA ACCIÓN: REGISTRAR ENVÍO DE NOTIFICACIÓN (Incrementar contador)
if (isset($_POST['accion_registrar_notificacion'])) {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
$_SESSION['error'] = 'Acceso denegado';
header('Location: gestion_movimientos.php');
exit;
}
try {
$tramite_id = isset($_POST['tramite_id']) && !empty($_POST['tramite_id']) ? (int)$_POST['tramite_id'] : null;
if ($tramite_id) {
$stmt = $conn->prepare("UPDATE tramites_empresa SET cantidad_notificaciones = COALESCE(cantidad_notificaciones, 0) + 1 WHERE id = :id");
$stmt->execute(['id' => $tramite_id]);
$detalles = [
'accion' => 'REGISTRO_NOTIFICACION_TRAMITE',
'tabla' => 'tramites_empresa',
'registro_id' => $tramite_id,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s')
];
logAuditoria($conn, 'REGISTRO_NOTIFICACION_TRAMITE', 'tramites_empresa', $tramite_id, $detalles, $user['id']);
$_SESSION['success'] = 'Notificación registrada correctamente';
}
} catch(Exception $e) {
$_SESSION['error'] = 'Error al registrar notificación: ' . $e->getMessage();
}
header('Location: gestion_movimientos.php');
exit;
}
// ✅ ACCIÓN: CREAR/EDITAR TRÁMITE
if (isset($_POST['accion_tramite'])) {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
$_SESSION['error'] = 'Acceso denegado';
header('Location: gestion_movimientos.php');
exit;
}
try {
$tramite_id = isset($_POST['tramite_id']) && !empty($_POST['tramite_id']) ? (int)$_POST['tramite_id'] : null;
$empresa_id = (int)$_POST['empresa_id'];
$tipo_movimiento = $_POST['tipo_movimiento'];
$estado = $_POST['estado'];
$observaciones = sanitizeInput($_POST['observaciones_admin'] ?? '');
$datos_nuevos = sanitizeInput($_POST['datos_nuevos'] ?? '');
$datos_anteriores = sanitizeInput($_POST['datos_anteriores'] ?? '');
// ✅ NUEVOS CAMPOS
$fecha_notificacion = !empty($_POST['fecha_notificacion']) ? $_POST['fecha_notificacion'] : null;
$plazo_dias = isset($_POST['plazo_dias']) && !empty($_POST['plazo_dias']) ? (int)$_POST['plazo_dias'] : null;
$fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
$urgente = isset($_POST['urgente']) ? 1 : 0;
$target_dir_tramites = "../uploads/tramites_empresariales/";
if (!file_exists($target_dir_tramites)) mkdir($target_dir_tramites, 0777, true);
$pdf_documento_file = '';
if (isset($_FILES['pdf_documento_tramite']) && $_FILES['pdf_documento_tramite']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['pdf_documento_tramite']['name'], PATHINFO_EXTENSION));
if ($file_extension !== 'pdf') {
throw new Exception('El documento debe ser PDF');
}
if ($_FILES['pdf_documento_tramite']['size'] > 10000000) {
throw new Exception('El PDF no debe superar los 10MB');
}
$new_filename = 'tramite_' . $empresa_id . '_' . date('Ymd') . '_' . time() . '.pdf';
$target_file = $target_dir_tramites . $new_filename;
if (move_uploaded_file($_FILES['pdf_documento_tramite']['tmp_name'], $target_file)) {
$pdf_documento_file = $new_filename;
}
}
if (empty($pdf_documento_file) && $tramite_id) {
$stmt = $conn->prepare("SELECT pdf_documento FROM tramites_empresa WHERE id = :id");
$stmt->execute(['id' => $tramite_id]);
$existing = $stmt->fetch();
$pdf_documento_file = $existing['pdf_documento'] ?? '';
}
$notificada = isset($_POST['notificada']) && $_POST['notificada'] == '1' ? 1 : 0;
if ($tramite_id) {
$stmt = $conn->prepare("
UPDATE tramites_empresa SET
estado = :estado,
observaciones_admin = :observaciones,
pdf_documento = :pdf_documento,
notificada = :notificada,
fecha_resolucion = NOW(),
admin_responsable = :admin_id,
fecha_notificacion = :fecha_notificacion,
plazo_dias = :plazo_dias,
fecha_limite = :fecha_limite,
urgente = :urgente
WHERE id = :id
");
$stmt->execute([
'estado' => $estado,
'observaciones' => $observaciones,
'pdf_documento' => $pdf_documento_file,
'notificada' => $notificada,
'admin_id' => $user['id'],
'id' => $tramite_id,
'fecha_notificacion' => $fecha_notificacion,
'plazo_dias' => $plazo_dias,
'fecha_limite' => $fecha_limite,
'urgente' => $urgente
]);
$detalles = [
'accion' => 'MODIFICACION_TRAMITE_EMPRESARIAL',
'tabla' => 'tramites_empresa',
'registro_id' => $tramite_id,
'empresa_id' => $empresa_id,
'tipo_movimiento' => $tipo_movimiento,
'estado_nuevo' => $estado,
'estado_anterior' => $_POST['estado_anterior'] ?? 'desconocido',
'pdf_subido' => !empty($pdf_documento_file),
'notificada' => $notificada,
'observaciones' => substr($observaciones, 0, 100),
'fecha_notificacion' => $fecha_notificacion,
'plazo_dias' => $plazo_dias,
'urgente' => $urgente,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s')
];
logAuditoria($conn, 'MODIFICACION_TRAMITE_EMPRESARIAL', 'tramites_empresa', $tramite_id, $detalles, $user['id']);
$_SESSION['success'] = 'Trámite actualizado correctamente';
} else {
$stmt = $conn->prepare("
INSERT INTO tramites_empresa
(empresa_id, tipo_movimiento, estado, observaciones_admin, datos_anteriores, datos_nuevos,
pdf_documento, notificada, admin_responsable, fecha_notificacion, plazo_dias, fecha_limite, urgente, cantidad_notificaciones)
VALUES
(:empresa_id, :tipo, :estado, :obs, :ant, :nuevos, :pdf_documento, :notificada, :admin_id,
:fecha_notificacion, :plazo_dias, :fecha_limite, :urgente, 0)
");
$stmt->execute([
'empresa_id' => $empresa_id,
'tipo' => $tipo_movimiento,
'estado' => $estado,
'obs' => $observaciones,
'ant' => $datos_anteriores,
'nuevos' => $datos_nuevos,
'pdf_documento' => $pdf_documento_file,
'notificada' => $notificada,
'admin_id' => $user['id'],
'fecha_notificacion' => $fecha_notificacion,
'plazo_dias' => $plazo_dias,
'fecha_limite' => $fecha_limite,
'urgente' => $urgente
]);
$tramite_inserted_id = $conn->lastInsertId();
$detalles = [
'accion' => 'CREACION_TRAMITE_EMPRESARIAL',
'tabla' => 'tramites_empresa',
'registro_id' => $tramite_inserted_id,
'empresa_id' => $empresa_id,
'tipo_movimiento' => $tipo_movimiento,
'estado_inicial' => $estado,
'pdf_adjunto' => !empty($pdf_documento_file),
'notificado' => $notificada,
'observaciones' => substr($observaciones, 0, 100),
'fecha_notificacion' => $fecha_notificacion,
'plazo_dias' => $plazo_dias,
'urgente' => $urgente,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s')
];
logAuditoria($conn, 'CREACION_TRAMITE_EMPRESARIAL', 'tramites_empresa', $tramite_inserted_id, $detalles, $user['id']);
$_SESSION['success'] = 'Trámite registrado correctamente';
}
header('Location: gestion_movimientos.php');
exit;
} catch(Exception $e) {
$_SESSION['error'] = 'Error al gestionar trámite: ' . $e->getMessage();
logAuditoria($conn, 'ERROR_GESTION_TRAMITE', 'tramites_empresa', null, ['error' => $e->getMessage(), 'usuario' => $user['nombre_usuario']], $user['id']);
header('Location: gestion_movimientos.php');
exit;
}
}
}
if (!function_exists('sanitizeInput')) {
function sanitizeInput($data) {
return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
}
// ==================== FILTROS ====================
$filtro_tramite_texto = isset($_GET['filtro_tramite_texto']) && !empty($_GET['filtro_tramite_texto']) ? sanitizeInput($_GET['filtro_tramite_texto']) : '';
$fecha_inicio = isset($_GET['fecha_inicio']) && !empty($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : null;
$fecha_fin = isset($_GET['fecha_fin']) && !empty($_GET['fecha_fin']) ? $_GET['fecha_fin'] : null;
$estado_tramite = isset($_GET['estado_tramite']) && !empty($_GET['estado_tramite']) ? $_GET['estado_tramite'] : null;
// ==================== ✅ PAGINACIÓN Y ORDENAMIENTO ====================
$registros_por_pagina = 15;
$pagina_actual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;
// Columnas permitidas para ordenamiento (SEGURIDAD - SQL Injection)
$columnas_permitidas = ['empresa_nombre', 'tipo_movimiento', 'estado', 'fecha_solicitud', 'fecha_notificacion', 'plazo_dias', 'urgente', 'fecha_resolucion'];
$orden_por = isset($_GET['orden']) && in_array($_GET['orden'], $columnas_permitidas) ? $_GET['orden'] : 'fecha_solicitud';
$direccion = isset($_GET['direccion']) && strtoupper($_GET['direccion']) === 'ASC' ? 'ASC' : 'DESC';
// Toggle dirección para próximos clicks
$direccion_siguiente = ($direccion === 'ASC') ? 'DESC' : 'ASC';
// ==================== CONSULTA CON PAGINACIÓN ====================
$tramites_list = [];
// Query para contar total de registros (sin LIMIT)
$query_count = "SELECT COUNT(*) as total
FROM tramites_empresa t
LEFT JOIN empresas e ON t.empresa_id = e.id
WHERE 1=1";
$params_count = [];
if (!empty($filtro_tramite_texto)) {
$query_count .= " AND (e.nombre LIKE :filtro_tramite OR t.tipo_movimiento LIKE :filtro_tramite2 OR t.observaciones_admin LIKE :filtro_tramite3)";
$params_count['filtro_tramite'] = "%{$filtro_tramite_texto}%";
$params_count['filtro_tramite2'] = "%{$filtro_tramite_texto}%";
$params_count['filtro_tramite3'] = "%{$filtro_tramite_texto}%";
}
if (!empty($fecha_inicio)) {
$query_count .= " AND t.fecha_solicitud >= :fecha_inicio";
$params_count['fecha_inicio'] = $fecha_inicio . ' 00:00:00';
}
if (!empty($fecha_fin)) {
$query_count .= " AND t.fecha_solicitud <= :fecha_fin";
$params_count['fecha_fin'] = $fecha_fin . ' 23:59:59';
}
if (!empty($estado_tramite)) {
$query_count .= " AND t.estado = :estado_tramite";
$params_count['estado_tramite'] = $estado_tramite;
}
$stmt_count = $conn->prepare($query_count);
$stmt_count->execute($params_count);
$total_registros = $stmt_count->fetch()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
// ✅ Query principal con LIMIT y OFFSET - CORREGIDO
$query_tramites = "SELECT t.*, e.nombre as empresa_nombre
FROM tramites_empresa t
LEFT JOIN empresas e ON t.empresa_id = e.id
WHERE 1=1";
$params_tramites = $params_count;
if (!empty($filtro_tramite_texto)) {
$query_tramites .= " AND (e.nombre LIKE :filtro_tramite OR t.tipo_movimiento LIKE :filtro_tramite2 OR t.observaciones_admin LIKE :filtro_tramite3)";
}
if (!empty($fecha_inicio)) {
$query_tramites .= " AND t.fecha_solicitud >= :fecha_inicio";
}
if (!empty($fecha_fin)) {
$query_tramites .= " AND t.fecha_solicitud <= :fecha_fin";
}
if (!empty($estado_tramite)) {
$query_tramites .= " AND t.estado = :estado_tramite";
}
// ✅ ORDER BY dinámico (validado por whitelist)
$query_tramites .= " ORDER BY {$orden_por} {$direccion}";
$query_tramites .= " LIMIT :limit OFFSET :offset";
$stmt_tramites = $conn->prepare($query_tramites);
// ✅ Bind de parámetros normales
foreach ($params_tramites as $key => $value) {
$stmt_tramites->bindValue($key, $value);
}
// ✅ ✅ ✅ Bind de LIMIT y OFFSET como ENTEROS (esto soluciona el error)
$stmt_tramites->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt_tramites->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_tramites->execute();
$tramites_list = $stmt_tramites->fetchAll();
$detalles_busqueda = [
'cantidad_encontradas' => count($tramites_list),
'total_registros' => $total_registros,
'pagina_actual' => $pagina_actual,
'filtros_aplicados' => compact('filtro_tramite_texto', 'fecha_inicio', 'fecha_fin', 'estado_tramite', 'orden_por', 'direccion')
];
logAuditoria($conn, 'BUSQUEDA_TRAMITES_EMPRESARIALES', 'tramites_empresa', null, $detalles_busqueda, $user['id']);
// ==================== DATOS AUXILIARES ====================
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll();
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ✅ FUNCIÓN PARA CONSTRUIR URL CON PARÁMETROS
function construirUrl($params_extra = []) {
$params = [];
if (!empty($_GET['filtro_tramite_texto'])) $params['filtro_tramite_texto'] = $_GET['filtro_tramite_texto'];
if (!empty($_GET['fecha_inicio'])) $params['fecha_inicio'] = $_GET['fecha_inicio'];
if (!empty($_GET['fecha_fin'])) $params['fecha_fin'] = $_GET['fecha_fin'];
if (!empty($_GET['estado_tramite'])) $params['estado_tramite'] = $_GET['estado_tramite'];
if (!empty($_GET['orden'])) $params['orden'] = $_GET['orden'];
if (!empty($_GET['direccion'])) $params['direccion'] = $_GET['direccion'];
$params = array_merge($params, $params_extra);
return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Movimientos Empresariales - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
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
/* ✅ ANTES */
.table-container {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
overflow: hidden;
}
/* ✅ DESPUÉS - CON SCROLL HORIZONTAL */
.table-container {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
overflow-x: auto;  /* Permite scroll horizontal */
overflow-y: visible;
max-width: 100%;
}
/* ✅ AGREGAR ESTOS ESTILOS NUEVOS */
.table-container::-webkit-scrollbar {
height: 8px;
}
.table-container::-webkit-scrollbar-track {
background: #f1f1f1;
border-radius: 4px;
}
.table-container::-webkit-scrollbar-thumb {
background: #888;
border-radius: 4px;
}
.table-container::-webkit-scrollbar-thumb:hover {
background: #555;
}
/* ✅ Evitar que el texto se envuelva en las celdas */
.table tbody td,
.table thead th {
white-space: nowrap;
vertical-align: middle;
}
/* ✅ Hacer la tabla más flexible */
.table {
margin-bottom: 0;
min-width: 100%;
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
cursor: pointer;
user-select: none;
white-space: nowrap;
}
.table thead th:hover {
background-color: #e9ecef;
}
.table thead th .sort-icon {
margin-left: 5px;
opacity: 0.3;
}
.table thead th.sorted .sort-icon {
opacity: 1;
color: var(--primary-color);
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
.badge-pendiente { background: #ffc107 !important; color: #000; }
.badge-aprobado { background: #28a745 !important; color: #fff; }
.badge-rechazado { background: #dc3545 !important; color: #fff; }
/* ✅ ESTILOS DE PAGINACIÓN */
.pagination-container {
display: flex;
justify-content: space-between;
align-items: center;
padding: 15px 20px;
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
margin-top: 20px;
}
.pagination-info {
color: #6c757d;
font-size: 0.9rem;
}
.pagination {
margin-bottom: 0;
}
.pagination .page-link {
color: var(--primary-color);
border: 1px solid #dee2e6;
margin: 0 2px;
border-radius: 4px;
}
.pagination .page-item.active .page-link {
background-color: var(--primary-color);
border-color: var(--primary-color);
color: #fff;
}
.pagination .page-item.disabled .page-link {
color: #6c757d;
pointer-events: none;
}
</style>
</head>
<body>
<?php $page_title = 'Gestión de Movimientos'; include '../includes/header.php'; ?>
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
<!-- ✅ PANEL DE GESTIÓN DE MOVIMIENTOS EMPRESARIALES -->
<div class="section-box">
<h3 class="section-title">
<i class="fas fa-exchange-alt me-2"></i>Gestión de Movimientos Empresariales
</h3>
<!-- ✅ FILTROS - CON COLLAPSE -->
<div class="card bg-light mb-4">
<div class="card-body">
<h5 class="card-title"
data-bs-toggle="collapse"
data-bs-target="#contenidoFiltros"
style="cursor: pointer;"
title="Clic para mostrar/ocultar filtros">
<i class="fas fa-filter me-2"></i>Filtrar Movimientos
<i class="fas fa-chevron-down float-end mt-1" id="iconoFiltros"></i>
</h5>
<div id="contenidoFiltros" class="collapse">
<form method="GET" action="gestion_movimientos.php" class="row g-3">
<div class="col-md-4">
<label class="form-label">Buscar Texto</label>
<input type="text" name="filtro_tramite_texto" class="form-control"
placeholder="Empresa, tipo, observaciones..."
value="<?php echo htmlspecialchars($filtro_tramite_texto ?? ''); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Desde</label>
<input type="date" name="fecha_inicio" class="form-control"
value="<?php echo htmlspecialchars($fecha_inicio ?? ''); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Hasta</label>
<input type="date" name="fecha_fin" class="form-control"
value="<?php echo htmlspecialchars($fecha_fin ?? ''); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="estado_tramite" class="form-select">
<option value="">Todos</option>
<option value="en_tramite" <?php echo ($estado_tramite == 'en_tramite') ? 'selected' : ''; ?>>En Trámite</option>
<option value="aprobado" <?php echo ($estado_tramite == 'aprobado') ? 'selected' : ''; ?>>Aprobado</option>
<option value="rechazado" <?php echo ($estado_tramite == 'rechazado') ? 'selected' : ''; ?>>Rechazado</option>
<option value="fuera_tiempo" <?php echo ($estado_tramite == 'fuera_tiempo') ? 'selected' : ''; ?>>Fuera de Tiempo</option>
</select>
</div>
<div class="col-md-2 d-flex align-items-end">
<button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Buscar</button>
<a href="gestion_movimientos.php" class="btn btn-secondary ms-2"><i class="fas fa-times"></i></a>
</div>
</form>
</div>
</div>
</div>
<!-- ✅ REGISTRAR NUEVO MOVIMIENTO - CON COLLAPSE -->
<div class="card bg-light mb-4">
<div class="card-body">
<h5 class="card-title"
data-bs-toggle="collapse"
data-bs-target="#contenidoNuevoMovimiento"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<i class="fas fa-plus-circle me-2"></i>Registrar Nuevo Movimiento
<i class="fas fa-chevron-down float-end mt-1" id="iconoNuevoMovimiento"></i>
</h5>
<div id="contenidoNuevoMovimiento" class="collapse">
<form method="POST" enctype="multipart/form-data" class="row g-3">
<input type="hidden" name="accion_tramite" value="1">
<div class="col-md-4">
<label class="form-label">Empresa</label>
<select name="empresa_id" class="form-select" required>
<option value="">Seleccione...</option>
<?php foreach ($empresas as $emp): ?>
<option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Tipo de Movimiento</label>
<select name="tipo_movimiento" class="form-select" required>
<option value="cambio_domicilio">Cambio de Domicilio</option>
<option value="cambio_director">Cambio Director Técnico</option>
<option value="cambio_duenos">Cambio de Dueños</option>
<option value="cambio_nombre">Cambio de Nombre</option>
<option value="cedula">Cédula</option>
<option value="falta_doc_habilitacion_empresa">Falta Documentacion para habilitacion de Empresa</option>
<option value="falta_doc_habilitacion_sucursal">Falta Documentacion para habilitacion de Sucursal</option>
<option value="falta_doc_habilitacion_personal">Falta Documentacion para habilitacion del Personal</option>
<option value="otros">Otros</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Estado Inicial</label>
<select name="estado" class="form-select">
<option value="en_tramite">En Trámite</option>
<option value="aprobado">Aprobado</option>
<option value="rechazado">Rechazado</option>
<option value="fuera_tiempo">Fuera de Tiempo</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">📅 Fecha de Notificación</label>
<input type="date" name="fecha_notificacion" class="form-control"
value="<?php echo date('Y-m-d'); ?>" id="fecha_notificacion_input">
</div>
<div class="col-md-3">
<label class="form-label">⏱️ Plazo (Días)</label>
<input type="number" name="plazo_dias" class="form-control"
placeholder="Ej: 10" min="1" max="365" id="plazo_dias_input">
</div>
<div class="col-md-3">
<label class="form-label">📅 Fecha Límite</label>
<input type="date" name="fecha_limite" class="form-control"
id="fecha_limite_input">
</div>
<div class="col-md-3">
<label class="form-label">🚨 Urgencia</label>
<div class="d-grid">
<button type="button" class="btn btn-outline-danger" id="btnUrgente"
onclick="toggleUrgente()">
<i class="fas fa-exclamation-triangle"></i> NO URGENTE
</button>
<input type="hidden" name="urgente" id="urgente_input" value="0">
</div>
</div>
<div class="col-md-6">
<label class="form-label">Datos Anteriores</label>
<input type="text" name="datos_anteriores" class="form-control" placeholder="Ej: Calle Falsa 123">
</div>
<div class="col-md-6">
<label class="form-label">Datos Nuevos</label>
<input type="text" name="datos_nuevos" class="form-control" placeholder="Ej: Av. Siempre Viva 742">
</div>
<div class="col-md-12">
<label class="form-label">📄 Cargar Documento PDF (Opcional)</label>
<input type="file" name="pdf_documento_tramite" class="form-control" accept=".pdf">
<div class="form-text">
<i class="fas fa-info-circle"></i> Máximo 10MB - Solo archivos PDF permitidos
</div>
</div>
<div class="col-md-12">
<label class="form-label">🔔 ¿Empresa Notificada?</label>
<div class="d-flex gap-4 mt-2">
<div class="form-check">
<input class="form-check-input" type="radio" name="notificada" id="notificacionSi" value="1" checked>
<label class="form-check-label text-success" for="notificacionSi">
<i class="fas fa-check-circle"></i> SÍ Notificada
</label>
</div>
<div class="form-check">
<input class="form-check-input" type="radio" name="notificada" id="notificacionNo" value="0">
<label class="form-check-label text-danger" for="notificacionNo">
<i class="fas fa-times-circle"></i> NO Notificada
</label>
</div>
</div>
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="observaciones_admin" class="form-control" rows="2"></textarea>
</div>
<div class="col-12 text-end">
<button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Guardar Trámite</button>
</div>
</form>
</div>
</div>
</div>
<!-- ✅ TABLA DE MOVIMIENTOS CON ORDENAMIENTO -->
<div class="table-container">
<table class="table">
<thead>
<tr>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'empresa_nombre', 'pagina' => 1, 'direccion' => $orden_por === 'empresa_nombre' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'empresa_nombre' ? 'sorted' : ''; ?>">
Empresa
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'empresa_nombre'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'tipo_movimiento', 'pagina' => 1, 'direccion' => $orden_por === 'tipo_movimiento' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'tipo_movimiento' ? 'sorted' : ''; ?>">
Tipo
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'tipo_movimiento'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'estado', 'pagina' => 1, 'direccion' => $orden_por === 'estado' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'estado' ? 'sorted' : ''; ?>">
Estado
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'estado'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'fecha_solicitud', 'pagina' => 1, 'direccion' => $orden_por === 'fecha_solicitud' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'fecha_solicitud' ? 'sorted' : ''; ?>">
F. Solicitud
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'fecha_solicitud'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'fecha_notificacion', 'pagina' => 1, 'direccion' => $orden_por === 'fecha_notificacion' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'fecha_notificacion' ? 'sorted' : ''; ?>">
📅 Notificación
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'fecha_notificacion'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'plazo_dias', 'pagina' => 1, 'direccion' => $orden_por === 'plazo_dias' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'plazo_dias' ? 'sorted' : ''; ?>">
⏱️ Plazo
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'plazo_dias'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'urgente', 'pagina' => 1, 'direccion' => $orden_por === 'urgente' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'urgente' ? 'sorted' : ''; ?>">
🚨 Urgente
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'urgente'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th onclick="window.location='<?php echo construirUrl(['orden' => 'fecha_resolucion', 'pagina' => 1, 'direccion' => $orden_por === 'fecha_resolucion' ? $direccion_siguiente : 'ASC']); ?>'"
class="<?php echo $orden_por === 'fecha_resolucion' ? 'sorted' : ''; ?>">
Última C/A
<i class="fas fa-sort sort-icon"></i>
<?php if ($orden_por === 'fecha_resolucion'): ?>
<i class="fas fa-sort-<?php echo strtolower($direccion); ?> sort-icon"></i>
<?php endif; ?>
</th>
<th>Observaciones</th>
<th>PDF Adjunto</th>
<th>✅ Notificada</th>
<th>🔔 Notificaciones</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php if (count($tramites_list) > 0): ?>
<?php foreach ($tramites_list as $t):
$badge = 'bg-secondary';
if ($t['estado'] == 'aprobado') $badge = 'badge-aprobado';
if ($t['estado'] == 'rechazado') $badge = 'badge-rechazado';
if ($t['estado'] == 'fuera_tiempo') $badge = 'bg-warning';
if ($t['estado'] == 'en_tramite') $badge = 'bg-info';
$ultima_carga = !empty($t['fecha_resolucion']) ? $t['fecha_resolucion'] : $t['fecha_solicitud'];
$ultima_carga_formateada = date('d/m/Y H:i', strtotime($ultima_carga));
$notificada_html = $t['notificada'] == 1
? '<i class="fas fa-check-circle text-success" title="Sí Notificada"></i>'
: '<i class="fas fa-times-circle text-danger" title="No Notificada"></i>';
$urgente_badge = isset($t['urgente']) && $t['urgente'] == 1
? '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> URGENTE</span>'
: '<span class="badge bg-secondary">Normal</span>';
$fecha_notificacion_html = !empty($t['fecha_notificacion'])
? date('d/m/Y', strtotime($t['fecha_notificacion']))
: '<span class="text-muted">-</span>';
$plazo_html = !empty($t['plazo_dias'])
? $t['plazo_dias'] . ' días'
: '<span class="text-muted">-</span>';
$cantidad_notificaciones = $t['cantidad_notificaciones'] ?? 0;
?>
<tr>
<td><?php echo htmlspecialchars($t['empresa_nombre']); ?></td>
<td><?php echo getTipoMovimientoLabel($t['tipo_movimiento']); ?></td>
<td><span class="badge <?php echo $badge; ?>"><?php echo strtoupper(str_replace('_', ' ', $t['estado'])); ?></span></td>
<td><?php echo date('d/m/Y', strtotime($t['fecha_solicitud'])); ?></td>
<td><?php echo $fecha_notificacion_html; ?></td>
<td><?php echo $plazo_html; ?></td>
<td><?php echo $urgente_badge; ?></td>
<td class="fw-bold text-primary"><?php echo $ultima_carga_formateada; ?></td>
<td class="small"><?php echo htmlspecialchars(substr($t['observaciones_admin'], 0, 50)) . (strlen($t['observaciones_admin']) > 50 ? '...' : ''); ?></td>
<td>
<?php if (!empty($t['pdf_documento'])): ?>
<a href="../uploads/tramites_empresariales/<?php echo htmlspecialchars($t['pdf_documento']); ?>" target="_blank" class="btn btn-sm btn-danger">
<i class="fas fa-file-pdf"></i> Ver PDF
</a>
<?php else: ?>
<span class="badge bg-secondary">Sin PDF</span>
<?php endif; ?>
</td>
<td><?php echo $notificada_html; ?></td>
<td class="text-center">
<span class="badge bg-info"><?php echo $cantidad_notificaciones; ?></span>
</td>
<td>
<a href="generar_cedula.php?id=<?php echo $t['id']; ?>&cantidad_notificaciones=<?php echo $t['cantidad_notificaciones'] ?? 0; ?>" target="_blank"
class="btn btn-sm btn-warning me-1" title="Generar Cédula de Notificación">
<i class="fas fa-file-download"></i>
</a>
<form method="POST" style="display:inline;">
<input type="hidden" name="accion_registrar_notificacion" value="1">
<input type="hidden" name="tramite_id" value="<?php echo $t['id']; ?>">
<button type="submit" class="btn btn-sm btn-secondary me-1" title="Registrar Envío de Notificación (+1)">
<i class="fas fa-envelope"></i> +1
</button>
</form>
<button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#modalEditarTramite<?php echo $t['id']; ?>">
<i class="fas fa-edit"></i>
</button>
</td>
</tr>
<!-- Modal Editar Trámite -->
<div class="modal fade" id="modalEditarTramite<?php echo $t['id']; ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="accion_tramite" value="1">
<input type="hidden" name="tramite_id" value="<?php echo $t['id']; ?>">
<div class="modal-header">
<h5 class="modal-title">Actualizar Estado Trámite</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="mb-3 p-2 bg-light rounded">
<label class="fw-bold">Tipo de Movimiento:</label>
<span class="badge bg-primary"><?php echo getTipoMovimientoLabel($t['tipo_movimiento']); ?></span>
</div>
<div class="mb-3 p-2 bg-light rounded">
<label class="fw-bold">Notificaciones Enviadas:</label>
<span class="badge bg-info"><?php echo $cantidad_notificaciones; ?></span>
</div>
<div class="mb-3">
<label>Nuevo Estado</label>
<select name="estado" class="form-select">
<option value="en_tramite" <?php echo $t['estado']=='en_tramite'?'selected':''; ?>>En Trámite</option>
<option value="aprobado" <?php echo $t['estado']=='aprobado'?'selected':''; ?>>Aprobado</option>
<option value="rechazado" <?php echo $t['estado']=='rechazado'?'selected':''; ?>>Rechazado</option>
<option value="fuera_tiempo" <?php echo $t['estado']=='fuera_tiempo'?'selected':''; ?>>Fuera de Tiempo</option>
</select>
</div>
<div class="mb-3">
<label>Observaciones Admin</label>
<textarea name="observaciones_admin" class="form-control"><?php echo htmlspecialchars($t['observaciones_admin']); ?></textarea>
</div>
<div class="mb-3">
<label>📅 Fecha de Notificación</label>
<input type="date" name="fecha_notificacion" class="form-control"
value="<?php echo htmlspecialchars($t['fecha_notificacion'] ?? ''); ?>">
</div>
<div class="mb-3">
<label>⏱️ Plazo (Días)</label>
<input type="number" name="plazo_dias" class="form-control"
value="<?php echo htmlspecialchars($t['plazo_dias'] ?? ''); ?>" min="1" max="365">
</div>
<div class="mb-3">
<label>📅 Fecha Límite</label>
<input type="date" name="fecha_limite" class="form-control"
value="<?php echo htmlspecialchars($t['fecha_limite'] ?? ''); ?>">
</div>
<div class="mb-3">
<label>🚨 Urgencia</label>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="urgente"
id="urgente_edit_<?php echo $t['id']; ?>"
<?php echo isset($t['urgente']) && $t['urgente'] == 1 ? 'checked' : ''; ?>>
<label class="form-check-label" for="urgente_edit_<?php echo $t['id']; ?>">
Marcar como Urgente
</label>
</div>
</div>
<div class="mb-3">
<label>Cambiar PDF Adjunto (Opcional)</label>
<input type="file" name="pdf_documento_tramite" class="form-control" accept=".pdf">
<?php if (!empty($t['pdf_documento'])): ?>
<div class="mt-2">
<a href="../uploads/tramites_empresariales/<?php echo htmlspecialchars($t['pdf_documento']); ?>" target="_blank" class="btn btn-sm btn-outline-danger">
<i class="fas fa-file-pdf"></i> Ver PDF Actual
</a>
</div>
<?php endif; ?>
</div>
<div class="mb-3">
<label>🔔 ¿Empresa Notificada?</label>
<div class="d-flex gap-4">
<div class="form-check">
<input class="form-check-input" type="radio" name="notificada" id="editNotificacionSi<?php echo $t['id']; ?>" value="1" <?php echo $t['notificada']==1?'checked':''; ?>>
<label class="form-check-label text-success" for="editNotificacionSi<?php echo $t['id']; ?>">
<i class="fas fa-check-circle"></i> SÍ Notificada
</label>
</div>
<div class="form-check">
<input class="form-check-input" type="radio" name="notificada" id="editNotificacionNo<?php echo $t['id']; ?>" value="0" <?php echo $t['notificada']==0?'checked':''; ?>>
<label class="form-check-label text-danger" for="editNotificacionNo<?php echo $t['id']; ?>">
<i class="fas fa-times-circle"></i> NO Notificada
</label>
</div>
</div>
</div>
</div>
<div class="modal-footer">
<button type="submit" class="btn btn-primary">Actualizar</button>
</div>
</form>
</div>
</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<tr>
<td colspan="14" class="text-center py-4">
<i class="fas fa-inbox fa-3x text-muted mb-3"></i>
<p class="text-muted">No se encontraron trámites con los filtros aplicados</p>
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
<!-- ✅ CONTROLES DE PAGINACIÓN -->
<?php if ($total_paginas > 1): ?>
<div class="pagination-container">
<div class="pagination-info">
Mostrando <strong><?php echo min($offset + 1, $total_registros); ?> - <?php echo min($offset + $registros_por_pagina, $total_registros); ?></strong>
de <strong><?php echo $total_registros; ?></strong> registros
(Página <strong><?php echo $pagina_actual; ?></strong> de <strong><?php echo $total_paginas; ?></strong>)
</div>
<nav>
<ul class="pagination">
<!-- Primera página -->
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="<?php echo construirUrl(['pagina' => 1]); ?>" title="Primera">
<i class="fas fa-angle-double-left"></i>
</a>
</li>
<!-- Anterior -->
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="<?php echo construirUrl(['pagina' => $pagina_actual - 1]); ?>" title="Anterior">
<i class="fas fa-angle-left"></i>
</a>
</li>
<!-- Números de página -->
<?php
$rango = 2;
$inicio = max(1, $pagina_actual - $rango);
$fin = min($total_paginas, $pagina_actual + $rango);
if ($inicio > 1) {
echo '<li class="page-item"><a class="page-link" href="' . construirUrl(['pagina' => 1]) . '">1</a></li>';
if ($inicio > 2) {
echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
}
}
for ($i = $inicio; $i <= $fin; $i++) {
$active = ($i == $pagina_actual) ? 'active' : '';
echo '<li class="page-item ' . $active . '"><a class="page-link" href="' . construirUrl(['pagina' => $i]) . '">' . $i . '</a></li>';
}
if ($fin < $total_paginas) {
if ($fin < $total_paginas - 1) {
echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
}
echo '<li class="page-item"><a class="page-link" href="' . construirUrl(['pagina' => $total_paginas]) . '">' . $total_paginas . '</a></li>';
}
?>
<!-- Siguiente -->
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="<?php echo construirUrl(['pagina' => $pagina_actual + 1]); ?>" title="Siguiente">
<i class="fas fa-angle-right"></i>
</a>
</li>
<!-- Última -->
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="<?php echo construirUrl(['pagina' => $total_paginas]); ?>" title="Última">
<i class="fas fa-angle-double-right"></i>
</a>
</li>
</ul>
</nav>
</div>
<?php endif; ?>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ✅ Rotar icono de flecha en sección Nuevo Movimiento
function toggleSidebar() {
const body = document.body;
const icon = document.querySelector('#sidebarToggle i');
body.classList.toggle('sidebar-collapsed');
if (body.classList.contains('sidebar-collapsed')) {
icon.className = 'fas fa-bars';
localStorage.setItem('sidebar_state', 'collapsed');
} else {
icon.className = 'fas fa-times';
localStorage.setItem('sidebar_state', 'expanded');
}
}
document.addEventListener('DOMContentLoaded', function() {
const savedState = localStorage.getItem('sidebar_state');
const icon = document.querySelector('#sidebarToggle i');
if (savedState === 'expanded') {
document.body.classList.remove('sidebar-collapsed');
if(icon) icon.className = 'fas fa-times';
} else {
document.body.classList.add('sidebar-collapsed');
if(icon) icon.className = 'fas fa-bars';
}
});
// ✅ Rotar icono de flecha en sección Filtros
document.addEventListener('DOMContentLoaded', function() {
const contenidoFiltros = document.getElementById('contenidoFiltros');
const iconoFiltros = document.getElementById('iconoFiltros');
if (contenidoFiltros && iconoFiltros) {
contenidoFiltros.addEventListener('show.bs.collapse', function() {
iconoFiltros.classList.remove('fa-chevron-down');
iconoFiltros.classList.add('fa-chevron-up');
});
contenidoFiltros.addEventListener('hide.bs.collapse', function() {
iconoFiltros.classList.remove('fa-chevron-up');
iconoFiltros.classList.add('fa-chevron-down');
});
}
});
// ✅ CALCULAR FECHA LÍMITE AUTOMÁTICAMENTE
document.addEventListener('DOMContentLoaded', function() {
const plazoInput = document.getElementById('plazo_dias_input');
const fechaNotificacion = document.getElementById('fecha_notificacion_input');
const fechaLimite = document.getElementById('fecha_limite_input');
if (plazoInput && fechaNotificacion && fechaLimite) {
function calcularFechaLimite() {
if (fechaNotificacion.value && plazoInput.value) {
const fecha = new Date(fechaNotificacion.value);
const dias = parseInt(plazoInput.value);
fecha.setDate(fecha.getDate() + dias);
fechaLimite.value = fecha.toISOString().split('T')[0];
}
}
plazoInput.addEventListener('change', calcularFechaLimite);
plazoInput.addEventListener('input', calcularFechaLimite);
fechaNotificacion.addEventListener('change', calcularFechaLimite);
}
});
// ✅ TOGGLE BOTÓN URGENTE
function toggleUrgente() {
const btn = document.getElementById('btnUrgente');
const input = document.getElementById('urgente_input');
if (input.value === '0') {
input.value = '1';
btn.className = 'btn btn-danger';
btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ⚠️ URGENTE';
} else {
input.value = '0';
btn.className = 'btn btn-outline-danger';
btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> NO URGENTE';
}
}
// Auto-close alerts
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
</script>
</body>
</html>