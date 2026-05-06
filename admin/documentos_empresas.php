<?php
/**
* ============================================================================
* GESTIÓN DE DOCUMENTOS DE EMPRESAS - VERSIÓN COMPLETA Y CORREGIDA
* ============================================================================
* Verifica y agrega columnas faltantes automáticamente
* Muestra documentos de TODAS las empresas para administradores
*
* @author Sistema de Seguridad
* @version 3.0 - Diseño Uniforme y Plano
* @last_update 2024
* ============================================================================
*/
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
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
$current_page = 'documentos_empresas';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. VERIFICAR Y ACTUALIZAR ESTRUCTURA DE TABLAS
// ============================================================================
$table_documentos_exists = false;
$table_usuarios_exists = false;
$table_empresas_exists = false;
$table_sucursales_exists = false;
// Flags para columnas existentes
$tiene_usuario_nombre = false;
$tiene_usuario_apellido = false;
$tiene_empresa_nombre = false;
$tiene_sucursal_nombre = false;
$tiene_observaciones = false;
$tiene_motivacion = false;
$tiene_revisado_por = false;
$tiene_fecha_revision = false;
$tiene_sucursal_id = false;
try {
// Verificar tabla documentos_sucursales
$tableCheck = $conn->query("SHOW TABLES LIKE 'documentos_sucursales'");
$table_documentos_exists = $tableCheck->rowCount() > 0;
if (!$table_documentos_exists) {
// Crear tabla completa con todas las columnas
$conn->exec("
CREATE TABLE documentos_sucursales (
id INT AUTO_INCREMENT PRIMARY KEY,
empresa_id INT NOT NULL,
usuario_id INT NOT NULL,
sucursal_id INT NULL,
tipo_documento VARCHAR(50) NOT NULL,
archivo_pdf VARCHAR(500) NOT NULL,
observaciones TEXT NULL,
fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
estado ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente',
fecha_revision TIMESTAMP NULL,
revisado_por INT NULL,
motivacion_rechazo TEXT NULL,
INDEX idx_empresa (empresa_id),
INDEX idx_estado (estado),
INDEX idx_fecha (fecha_carga),
INDEX idx_tipo (tipo_documento),
INDEX idx_sucursal (sucursal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
logAuditoria($conn, 'TABLA_CREADA', 'documentos_sucursales', null,
['mensaje' => 'Tabla documentos_sucursales creada con estructura completa'], $user['id']);
$table_documentos_exists = true;
}
// ✅ VERIFICAR Y AGREGAR COLUMNAS FALTANTES
if ($table_documentos_exists) {
$columnas_documentos = $conn->query("SHOW COLUMNS FROM documentos_sucursales")->fetchAll(PDO::FETCH_COLUMN);
// Columnas a verificar y agregar si no existen
$columnas_requeridas = [
'sucursal_id' => "INT NULL AFTER usuario_id",
'observaciones' => "TEXT NULL AFTER archivo_pdf",
'fecha_revision' => "TIMESTAMP NULL AFTER estado",
'revisado_por' => "INT NULL AFTER fecha_revision",
'motivacion_rechazo' => "TEXT NULL AFTER revisado_por"
];
foreach ($columnas_requeridas as $col => $definition) {
if (!in_array($col, $columnas_documentos)) {
try {
$conn->exec("ALTER TABLE documentos_sucursales ADD COLUMN $col $definition");
logAuditoria($conn, 'COLUMNA_AGREGADA', 'documentos_sucursales', null,
['columna' => $col], $user['id']);
} catch (PDOException $e) {
error_log("Error agregando columna '$col': " . $e->getMessage());
}
}
}
// Verificar índices
$indexes = $conn->query("SHOW INDEX FROM documentos_sucursales")->fetchAll(PDO::FETCH_ASSOC);
$index_columns = array_column($indexes, 'Column_name');
if (!in_array('sucursal_id', $index_columns)) {
try {
$conn->exec("ALTER TABLE documentos_sucursales ADD INDEX idx_sucursal (sucursal_id)");
} catch (PDOException $e) {
error_log("Error creando índice: " . $e->getMessage());
}
}
// Actualizar flags
$tiene_observaciones = in_array('observaciones', $columnas_documentos) || in_array('observaciones', array_keys($columnas_requeridas));
$tiene_motivacion = in_array('motivacion_rechazo', $columnas_documentos) || in_array('motivacion_rechazo', array_keys($columnas_requeridas));
$tiene_revisado_por = in_array('revisado_por', $columnas_documentos) || in_array('revisado_por', array_keys($columnas_requeridas));
$tiene_fecha_revision = in_array('fecha_revision', $columnas_documentos) || in_array('fecha_revision', array_keys($columnas_requeridas));
$tiene_sucursal_id = in_array('sucursal_id', $columnas_documentos) || in_array('sucursal_id', array_keys($columnas_requeridas));
}
// Verificar tabla usuarios
$tableCheckUsuarios = $conn->query("SHOW TABLES LIKE 'usuarios'");
$table_usuarios_exists = $tableCheckUsuarios->rowCount() > 0;
if ($table_usuarios_exists) {
$columns_usuarios = $conn->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
$tiene_usuario_nombre = in_array('nombre', $columns_usuarios);
$tiene_usuario_apellido = in_array('apellido', $columns_usuarios);
$tiene_usuario_username = in_array('username', $columns_usuarios);
}
// Verificar tabla empresas
$tableCheckEmpresas = $conn->query("SHOW TABLES LIKE 'empresas'");
$table_empresas_exists = $tableCheckEmpresas->rowCount() > 0;
if ($table_empresas_exists) {
$columns_empresas = $conn->query("SHOW COLUMNS FROM empresas")->fetchAll(PDO::FETCH_COLUMN);
$tiene_empresa_nombre = in_array('nombre', $columns_empresas);
}
// Verificar tabla sucursales
$tableCheckSucursales = $conn->query("SHOW TABLES LIKE 'sucursales'");
$table_sucursales_exists = $tableCheckSucursales->rowCount() > 0;
if ($table_sucursales_exists) {
$columns_sucursales = $conn->query("SHOW COLUMNS FROM sucursales")->fetchAll(PDO::FETCH_COLUMN);
$tiene_sucursal_nombre = in_array('nombre', $columns_sucursales);
}
} catch (PDOException $e) {
$error = "Error al verificar estructura de base de datos: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// 3. APROBAR DOCUMENTO (AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'aprobar_documento') {
header('Content-Type: application/json');
try {
$documento_id = (int)($_POST['documento_id'] ?? 0);
if ($documento_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de documento inválido']);
exit;
}
$stmt = $conn->prepare("SELECT * FROM documentos_sucursales WHERE id = :id");
$stmt->execute([':id' => $documento_id]);
$doc_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc_data) {
echo json_encode(['success' => false, 'message' => 'Documento no encontrado']);
exit;
}
// Solo actualizar si las columnas existen
if ($tiene_revisado_por && $tiene_fecha_revision) {
$stmt = $conn->prepare("
UPDATE documentos_sucursales
SET estado = 'aprobado',
fecha_revision = NOW(),
revisado_por = :revisado_por
WHERE id = :id
");
$stmt->execute([
':revisado_por' => $user['id'],
':id' => $documento_id
]);
} else {
$stmt = $conn->prepare("
UPDATE documentos_sucursales
SET estado = 'aprobado'
WHERE id = :id
");
$stmt->execute([':id' => $documento_id]);
}
logAuditoria($conn, 'DOCUMENTO_APROBADO', 'documentos_sucursales', $documento_id, [
'accion' => 'aprobacion',
'id' => $documento_id,
'tipo_documento' => $doc_data['tipo_documento'],
'archivo' => $doc_data['archivo_pdf'],
'empresa_id' => $doc_data['empresa_id'],
'estado_anterior' => $doc_data['estado'],
'estado_nuevo' => 'aprobado',
'revisado_por' => $user['nombre'] ?? 'Sistema',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
], $user['id']);
echo json_encode([
'success' => true,
'message' => 'Documento aprobado correctamente'
]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
exit;
}
}
// ============================================================================
// 4. RECHAZAR DOCUMENTO (AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechazar_documento') {
header('Content-Type: application/json');
try {
$documento_id = (int)($_POST['documento_id'] ?? 0);
$motivacion = trim($_POST['motivacion'] ?? 'Sin motivación');
if ($documento_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de documento inválido']);
exit;
}
$stmt = $conn->prepare("SELECT * FROM documentos_sucursales WHERE id = :id");
$stmt->execute([':id' => $documento_id]);
$doc_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc_data) {
echo json_encode(['success' => false, 'message' => 'Documento no encontrado']);
exit;
}
// Solo actualizar si las columnas existen
if ($tiene_revisado_por && $tiene_fecha_revision && $tiene_motivacion) {
$stmt = $conn->prepare("
UPDATE documentos_sucursales
SET estado = 'rechazado',
fecha_revision = NOW(),
revisado_por = :revisado_por,
motivacion_rechazo = :motivacion
WHERE id = :id
");
$stmt->execute([
':revisado_por' => $user['id'],
':motivacion' => $motivacion,
':id' => $documento_id
]);
} else {
$stmt = $conn->prepare("
UPDATE documentos_sucursales
SET estado = 'rechazado'
WHERE id = :id
");
$stmt->execute([':id' => $documento_id]);
}
logAuditoria($conn, 'DOCUMENTO_RECHAZADO', 'documentos_sucursales', $documento_id, [
'accion' => 'rechazo',
'id' => $documento_id,
'tipo_documento' => $doc_data['tipo_documento'],
'archivo' => $doc_data['archivo_pdf'],
'empresa_id' => $doc_data['empresa_id'],
'estado_anterior' => $doc_data['estado'],
'estado_nuevo' => 'rechazado',
'motivacion' => $motivacion,
'revisado_por' => $user['nombre'] ?? 'Sistema',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
], $user['id']);
echo json_encode([
'success' => true,
'message' => 'Documento rechazado correctamente'
]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
exit;
}
}
// ============================================================================
// 5. INICIALIZAR VARIABLES DE FILTROS
// ============================================================================
$search_empresa = $_GET['search_empresa'] ?? '';
$search_sucursal = $_GET['search_sucursal'] ?? '';
$search_tipo = $_GET['search_tipo'] ?? 'todos';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_fecha_desde = $_GET['search_fecha_desde'] ?? '';
$search_fecha_hasta = $_GET['search_fecha_hasta'] ?? '';
// Paginación
$registros_por_pagina = 15;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
// Ordenamiento
$columnas_permitidas = ['id', 'tipo_documento', 'fecha_carga', 'estado', 'empresa_nombre'];
$orden_columna = $_GET['orden'] ?? 'fecha_carga';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'DESC');
if (!in_array($orden_columna, $columnas_permitidas)) {
$orden_columna = 'fecha_carga';
}
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') {
$orden_direccion = 'DESC';
}
$orden_direccion_next = ($orden_direccion === 'ASC') ? 'DESC' : 'ASC';
// ============================================================================
// 6. EXPORTAR DOCUMENTOS
// ============================================================================
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['csv', 'json'])) {
try {
// Construir SELECT según columnas disponibles
$select_usuario = "'Sistema' as usuario_nombre";
if ($tiene_usuario_nombre && $tiene_usuario_apellido) {
$select_usuario = "CONCAT(u.nombre, ' ', u.apellido) as usuario_nombre";
} elseif ($tiene_usuario_nombre) {
$select_usuario = "u.nombre as usuario_nombre";
}
$select_sucursal = "NULL as sucursal_nombre";
if ($tiene_sucursal_id && $tiene_sucursal_nombre) {
$select_sucursal = "s.nombre as sucursal_nombre";
}
$sql = "SELECT d.*, e.nombre as empresa_nombre, $select_usuario, $select_sucursal
FROM documentos_sucursales d
LEFT JOIN empresas e ON d.empresa_id = e.id
LEFT JOIN usuarios u ON d.usuario_id = u.id
" . ($tiene_sucursal_id ? "LEFT JOIN sucursales s ON d.sucursal_id = s.id" : "") . "
WHERE 1=1";
$params = [];
if (!empty($search_empresa)) {
$sql .= " AND e.nombre LIKE :search_empresa";
$params[':search_empresa'] = '%' . $search_empresa . '%';
}
if ($search_tipo !== 'todos') {
$sql .= " AND d.tipo_documento = :tipo";
$params[':tipo'] = $search_tipo;
}
if ($search_estado !== 'todos') {
$sql .= " AND d.estado = :estado";
$params[':estado'] = $search_estado;
}
if (!empty($search_fecha_desde)) {
$sql .= " AND d.fecha_carga >= :fecha_desde";
$params[':fecha_desde'] = $search_fecha_desde . ' 00:00:00';
}
if (!empty($search_fecha_hasta)) {
$sql .= " AND d.fecha_carga <= :fecha_hasta";
$params[':fecha_hasta'] = $search_fecha_hasta . ' 23:59:59';
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$documentos_export = $stmt->fetchAll(PDO::FETCH_ASSOC);
logAuditoria($conn, 'DOCUMENTOS_EXPORTADOS', 'documentos_sucursales', null, [
'formato' => $_GET['exportar'],
'total_registros' => count($documentos_export),
'filtros' => $_GET
], $user['id']);
if ($_GET['exportar'] === 'csv') {
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=documentos_' . date('Y-m-d_His') . '.csv');
$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Empresa', 'Sucursal', 'Tipo', 'Archivo', 'Estado', 'Fecha Carga', 'Usuario']);
foreach ($documentos_export as $doc) {
fputcsv($output, [
$doc['id'],
$doc['empresa_nombre'] ?? 'N/A',
$doc['sucursal_nombre'] ?? 'N/A',
$doc['tipo_documento'],
basename($doc['archivo_pdf']),
$doc['estado'],
$doc['fecha_carga'],
$doc['usuario_nombre'] ?? 'Sistema'
]);
}
fclose($output);
exit;
}
if ($_GET['exportar'] === 'json') {
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=documentos_' . date('Y-m-d_His') . '.json');
echo json_encode($documentos_export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
}
} catch (Exception $e) {
$_SESSION['error'] = 'Error al exportar: ' . $e->getMessage();
header('Location: documentos_empresas.php');
exit;
}
}
// ============================================================================
// 7. OBTENER DATOS CON PAGINACIÓN
// ============================================================================
$documentos = [];
$empresas = [];
$sucursales = [];
$total_registros = 0;
$total_paginas = 0;
try {
$where_clauses = [];
$params = [];
if (!empty($search_empresa)) {
$where_clauses[] = "e.nombre LIKE :search_empresa";
$params[':search_empresa'] = '%' . $search_empresa . '%';
}
if ($search_tipo !== 'todos') {
$where_clauses[] = "d.tipo_documento = :tipo";
$params[':tipo'] = $search_tipo;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "d.estado = :estado";
$params[':estado'] = $search_estado;
}
if (!empty($search_fecha_desde)) {
$where_clauses[] = "d.fecha_carga >= :fecha_desde";
$params[':fecha_desde'] = $search_fecha_desde . ' 00:00:00';
}
if (!empty($search_fecha_hasta)) {
$where_clauses[] = "d.fecha_carga <= :fecha_hasta";
$params[':fecha_hasta'] = $search_fecha_hasta . ' 23:59:59';
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
// Contar total
$count_sql = "SELECT COUNT(*) as total FROM documentos_sucursales d
LEFT JOIN empresas e ON d.empresa_id = e.id
$where_sql";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
// ✅ CONSULTA PRINCIPAL - Construir SELECT dinámicamente según columnas existentes
$orden_sql = "ORDER BY d.$orden_columna $orden_direccion";
$limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
// SELECT usuario
$select_usuario = "'Sistema' as usuario_nombre";
if ($tiene_usuario_nombre && $tiene_usuario_apellido) {
$select_usuario = "CONCAT(u.nombre, ' ', u.apellido) as usuario_nombre";
} elseif ($tiene_usuario_nombre) {
$select_usuario = "u.nombre as usuario_nombre";
}
// SELECT revisor (solo si existe la columna revisado_por) - ✅ AGREGADO username
$select_revisor = "NULL as revisor_nombre, NULL as revisor_username";
if ($tiene_revisado_por && $tiene_usuario_nombre && $tiene_usuario_username) {
$select_revisor = "CONCAT(ur.nombre, ' ', ur.apellido) as revisor_nombre, ur.username as revisor_username";
} elseif ($tiene_revisado_por && $tiene_usuario_username) {
$select_revisor = "NULL as revisor_nombre, ur.username as revisor_username";
} elseif ($tiene_revisado_por && $tiene_usuario_nombre) {
$select_revisor = "CONCAT(ur.nombre, ' ', ur.apellido) as revisor_nombre, NULL as revisor_username";
}
// SELECT sucursal
$select_sucursal = "NULL as sucursal_nombre";
if ($tiene_sucursal_id && $tiene_sucursal_nombre) {
$select_sucursal = "s.nombre as sucursal_nombre";
}
// ✅ JOIN condicional para revisor (solo si existe revisado_por)
$join_revisor = "";
if ($tiene_revisado_por) {
$join_revisor = "LEFT JOIN usuarios ur ON d.revisado_por = ur.id";
}
// ✅ JOIN condicional para sucursal
$join_sucursal = "";
if ($tiene_sucursal_id) {
$join_sucursal = "LEFT JOIN sucursales s ON d.sucursal_id = s.id";
}
$sql = "
SELECT d.*,
e.nombre as empresa_nombre,
e.cuit as empresa_cuit,
$select_usuario,
$select_revisor,
$select_sucursal
FROM documentos_sucursales d
LEFT JOIN empresas e ON d.empresa_id = e.id
LEFT JOIN usuarios u ON d.usuario_id = u.id
$join_revisor
$join_sucursal
$where_sql
$orden_sql
$limit_sql
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
logAuditoria($conn, 'DOCUMENTOS_LISTADO_VISUALIZADO', 'documentos_sucursales', null, [
'total_registros' => count($documentos),
'pagina' => $pagina_actual,
'filtros_aplicados' => $_GET,
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
'columnas_verificadas' => [
'revisado_por' => $tiene_revisado_por,
'fecha_revision' => $tiene_fecha_revision,
'motivacion_rechazo' => $tiene_motivacion
]
], $user['id']);
} catch (PDOException $e) {
$documentos = [];
logAuditoria($conn, 'ERROR_CONSULTA_DOCUMENTOS', 'documentos_sucursales', null, [
'error' => $e->getMessage(),
'tiene_revisado_por' => $tiene_revisado_por,
'tiene_usuario_nombre' => $tiene_usuario_nombre
], $user['id']);
$error = "<strong>⚠️ Atención:</strong> No se pudieron cargar los documentos. Error: " . htmlspecialchars($e->getMessage());
}
// Obtener TODAS las empresas para el filtro
try {
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
$empresas = [];
}
// Obtener TODAS las sucursales para el filtro
try {
$stmt = $conn->query("SELECT id, nombre FROM sucursales WHERE activa = TRUE ORDER BY nombre");
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
$sucursales = [];
}
// Tipos de documentos
$tipos_documento = [
'personal' => '👥 Personal',
'servicios' => '🔔 Servicios',
'recursos' => '📦 Recursos',
'sucursal' => '🏢 Sucursal',
'certificacion' => '✅ Certificación',
'informe' => '📄 Informe',
'otro' => '📄 Otro'
];
// ============================================================================
// 8. FUNCIONES DE UTILIDAD
// ============================================================================
function getEstadoBadge($estado) {
switch ($estado) {
case 'aprobado':
return ['class' => 'bg-success', 'icon' => 'fa-check-circle', 'color' => '#27ae60'];
case 'rechazado':
return ['class' => 'bg-danger', 'icon' => 'fa-times-circle', 'color' => '#e74c3c'];
case 'pendiente':
default:
return ['class' => 'bg-warning text-dark', 'icon' => 'fa-clock', 'color' => '#f39c12'];
}
}
function getTipoIcono($tipo) {
$iconos = [
'personal' => 'fa-users',
'servicios' => 'fa-concierge-bell',
'recursos' => 'fa-boxes',
'sucursal' => 'fa-store',
'certificacion' => 'fa-certificate',
'otro' => 'fa-file'
];
return $iconos[$tipo] ?? 'fa-file';
}
function generarUrlOrden($columna, $direccion) {
$params = $_GET;
$params['orden'] = $columna;
$params['direccion'] = $direccion;
return '?' . http_build_query($params);
}
function mostrarIconoOrden($columna, $orden_columna, $orden_direccion) {
if ($columna === $orden_columna) {
return $orden_direccion === 'ASC' ? '<i class="fas fa-sort-up ms-1"></i>' : '<i class="fas fa-sort-down ms-1"></i>';
}
return '<i class="fas fa-sort ms-1 text-muted"></i>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Documentos - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
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
/* Estilos Uniformes para Todas las Secciones */
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
/* Stats */
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
/* Tablas */
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
/* Formularios */
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
/* Botones */
.btn {
border-radius: 4px;
font-weight: 500;
padding: 8px 16px;
}
.btn-primary {
background-color: var(--primary-color);
border-color: var(--primary-color);
}
/* Modales */
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
/* Paginación */
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
/* Estados de documentos */
.documento-estado {
display: inline-block;
padding: 4px 12px;
border-radius: 4px;
font-size: 0.75rem;
font-weight: 600;
}
.estado-pendiente {
background-color: #ffc107;
color: #000;
}
.estado-aprobado {
background-color: #28a745;
color: #fff;
}
.estado-rechazado {
background-color: #dc3545;
color: #fff;
}
</style>
</head>
<body>
<!-- HEADER -->
<?php $page_title = 'Gestión de Documentos'; include '../includes/header.php'; ?>
<div class="dashboard">
<!-- SIDEBAR -->
<?php include '../includes/sidebar.php'; ?>
<!-- CONTENIDO PRINCIPAL -->
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<!-- MENSAJES -->
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-file-pdf fa-2x"></i></div>
<div class="stat-number"><?php echo $total_registros; ?></div>
<div class="stat-label">Total Documentos</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-clock fa-2x"></i></div>
<div class="stat-number">
<?php
try {
$stmt = $conn->query("SELECT COUNT(*) as total FROM documentos_sucursales WHERE estado = 'pendiente'");
echo $stmt->fetch()['total'];
} catch (Exception $e) { echo '0'; }
?>
</div>
<div class="stat-label">Pendientes</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number">
<?php
try {
$stmt = $conn->query("SELECT COUNT(*) as total FROM documentos_sucursales WHERE estado = 'aprobado'");
echo $stmt->fetch()['total'];
} catch (Exception $e) { echo '0'; }
?>
</div>
<div class="stat-label">Aprobados</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-times-circle fa-2x"></i></div>
<div class="stat-number">
<?php
try {
$stmt = $conn->query("SELECT COUNT(*) as total FROM documentos_sucursales WHERE estado = 'rechazado'");
echo $stmt->fetch()['total'];
} catch (Exception $e) { echo '0'; }
?>
</div>
<div class="stat-label">Rechazados</div>
</div>
</div>
<!-- ✅ FILTROS DE BÚSQUEDA - CON COLLAPSE -->
<div class="section-box">
    <!-- TÍTULO CON COLLAPSE -->
    <div class="section-title" 
         data-bs-toggle="collapse" 
         data-bs-target="#contenidoFiltros" 
         style="cursor: pointer;"
         title="Clic para mostrar/ocultar filtros">
        <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
        <i class="fas fa-chevron-down float-end mt-1" id="iconoFiltros"></i>
    </div>

    <!-- CONTENIDO COLAPSABLE (contraído por defecto) -->
    <div id="contenidoFiltros" class="collapse">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Empresa</label>
                <select name="search_empresa" class="form-select">
                    <option value="">📋 Todas las empresas</option>
                    <?php foreach ($empresas as $emp): ?>
                    <option value="<?php echo htmlspecialchars($emp['nombre']); ?>" <?php echo ($search_empresa == $emp['nombre']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($emp['nombre']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Sucursal</label>
                <select name="search_sucursal" class="form-select">
                    <option value="">📋 Todas las sucursales</option>
                    <?php foreach ($sucursales as $suc): ?>
                    <option value="<?php echo htmlspecialchars($suc['nombre']); ?>" <?php echo ($search_sucursal == $suc['nombre']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($suc['nombre']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo</label>
                <select name="search_tipo" class="form-select">
                    <option value="todos">📋 Todos</option>
                    <?php foreach ($tipos_documento as $key => $value): ?>
                    <option value="<?php echo $key; ?>" <?php echo ($search_tipo == $key) ? 'selected' : ''; ?>>
                    <?php echo $value; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Estado</label>
                <select name="search_estado" class="form-select">
                    <option value="todos">📋 Todos</option>
                    <option value="pendiente" <?php echo ($search_estado === 'pendiente') ? 'selected' : ''; ?>>⏳ Pendientes</option>
                    <option value="aprobado" <?php echo ($search_estado === 'aprobado') ? 'selected' : ''; ?>>✅ Aprobados</option>
                    <option value="rechazado" <?php echo ($search_estado === 'rechazado') ? 'selected' : ''; ?>>❌ Rechazados</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Desde</label>
                <input type="date" name="search_fecha_desde" class="form-control" value="<?php echo htmlspecialchars($search_fecha_desde); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Hasta</label>
                <input type="date" name="search_fecha_hasta" class="form-control" value="<?php echo htmlspecialchars($search_fecha_hasta); ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
                <a href="documentos_empresas.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
                <a href="?exportar=csv" class="btn btn-success"><i class="fas fa-file-csv me-2"></i>CSV</a>
                <a href="?exportar=json" class="btn btn-info" style="background: #17a2b8; border: none;"><i class="fas fa-file-code me-2"></i>JSON</a>
            </div>
        </form>
    </div>  <!-- ✅ CIERRA contenidoFiltros -->
</div>  <!-- ✅ CIERRA section-box -->
<!-- LISTADO DE DOCUMENTOS -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Documentos
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<?php if (empty($documentos)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-file-pdf fa-3x text-muted mb-3"></i>
<h5>No hay documentos registrados</h5>
<p class="text-muted"><?php echo empty($search_empresa) && $search_estado === 'todos' ? 'Los documentos cargados desde gestión_documentos_sucursales.php aparecerán aquí.' : 'No se encontraron documentos con los filtros aplicados.'; ?></p>
<a href="gestion_documentos_sucursales.php" class="btn btn-success">
<i class="fas fa-upload me-2"></i>Ir a Carga de Documentos
</a>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th><a href="<?php echo generarUrlOrden('id', $orden_columna === 'id' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?></a></th>
<th>Empresa</th>
<?php if ($tiene_sucursal_id): ?>
<th>Sucursal</th>
<?php endif; ?>
<th><a href="<?php echo generarUrlOrden('tipo_documento', $orden_columna === 'tipo_documento' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Tipo <?php echo mostrarIconoOrden('tipo_documento', $orden_columna, $orden_direccion); ?></a></th>
<th>Archivo</th>
<th>Usuario</th>
<th><a href="<?php echo generarUrlOrden('fecha_carga', $orden_columna === 'fecha_carga' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Fecha <?php echo mostrarIconoOrden('fecha_carga', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('estado', $orden_columna === 'estado' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Estado <?php echo mostrarIconoOrden('estado', $orden_columna, $orden_direccion); ?></a></th>
<th class="table-actions">Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($documentos as $doc): ?>
<tr>
<td><strong>#<?php echo $doc['id']; ?></strong></td>
<td><?php echo htmlspecialchars($doc['empresa_nombre'] ?? 'N/A'); ?></td>
<?php if ($tiene_sucursal_id): ?>
<td><?php echo htmlspecialchars($doc['sucursal_nombre'] ?? 'N/A'); ?></td>
<?php endif; ?>
<td>
<span class="badge bg-primary">
<i class="fas <?php echo getTipoIcono($doc['tipo_documento']); ?> me-1"></i>
<?php echo $tipos_documento[$doc['tipo_documento']] ?? $doc['tipo_documento']; ?>
</span>
</td>
<td>
<a href="../<?php echo htmlspecialchars($doc['archivo_pdf']); ?>" target="_blank" class="text-primary">
<i class="fas fa-file-pdf me-1"></i>
<?php echo basename($doc['archivo_pdf']); ?>
</a>
</td>
<td><?php echo htmlspecialchars($doc['usuario_nombre'] ?? 'Sistema'); ?></td>
<td><?php echo date('d/m/Y H:i', strtotime($doc['fecha_carga'])); ?></td>
<td>
<span class="documento-estado estado-<?php echo $doc['estado']; ?>">
<?php echo strtoupper($doc['estado']); ?>
</span>
</td>
<td class="table-actions">
<div class="btn-group" role="group">
<a href="../<?php echo htmlspecialchars($doc['archivo_pdf']); ?>" target="_blank" class="btn btn-outline-secondary me-1" title="Ver">
<i class="fas fa-eye"></i>
</a>
<?php if ($doc['estado'] === 'pendiente'): ?>
<button class="btn btn-outline-info" onclick="aprobarDocumento(<?php echo $doc['id']; ?>)" title="Aprobar">
<i class="fas fa-check"></i>
</button>
<button class="btn btn-outline-dark" onclick="rechazarDocumento(<?php echo $doc['id']; ?>)" title="Rechazar">
<i class="fas fa-times"></i>
</button>
<?php endif; ?>
<button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#detalleDocumentoModal<?php echo $doc['id']; ?>" title="Detalles">
<i class="fas fa-info-circle"></i>
</button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- PAGINACIÓN -->
<?php if ($total_paginas > 1): ?>
<div class="d-flex justify-content-center mt-3">
<nav aria-label="Paginación de documentos">
<ul class="pagination mb-0">
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>"><i class="fas fa-angle-double-left"></i></a>
</li>
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => max(1, $pagina_actual - 1)])); ?>"><i class="fas fa-angle-left"></i></a>
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
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => min($total_paginas, $pagina_actual + 1)])); ?>"><i class="fas fa-angle-right"></i></a>
</li>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>"><i class="fas fa-angle-double-right"></i></a>
</li>
</ul>
</nav>
<span class="ms-3 text-muted align-self-center">
Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
</span>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>
<!-- MODALES DE DETALLE -->
<?php foreach ($documentos as $doc): ?>
<div class="modal fade" id="detalleDocumentoModal<?php echo $doc['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">
<i class="fas fa-file-pdf"></i>
Detalle del Documento #<?php echo $doc['id']; ?>
</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="documento-preview" style="background: #f8f9fa; border: 1px solid var(--card-border); border-radius: 4px; padding: 20px; margin: 15px 0;">
<div class="row">
<div class="col-md-6">
<p><strong>Empresa:</strong> <?php echo htmlspecialchars($doc['empresa_nombre'] ?? 'N/A'); ?></p>
<?php if ($tiene_sucursal_id): ?>
<p><strong>Sucursal:</strong> <?php echo htmlspecialchars($doc['sucursal_nombre'] ?? 'N/A'); ?></p>
<?php endif; ?>
<p><strong>Tipo:</strong> <?php echo $tipos_documento[$doc['tipo_documento']] ?? $doc['tipo_documento']; ?></p>
<p><strong>Estado:</strong>
<span class="documento-estado estado-<?php echo $doc['estado']; ?>">
<?php echo strtoupper($doc['estado']); ?>
</span>
</p>
</div>
<div class="col-md-6">
<p><strong>Usuario:</strong> <?php echo htmlspecialchars($doc['usuario_nombre'] ?? 'Sistema'); ?></p>
<p><strong>Fecha Carga:</strong> <?php echo date('d/m/Y H:i:s', strtotime($doc['fecha_carga'])); ?></p>
<?php if ($tiene_fecha_revision && !empty($doc['fecha_revision'])): ?>
<p><strong>Fecha Revisión:</strong> <?php echo date('d/m/Y H:i:s', strtotime($doc['fecha_revision'])); ?></p>
<?php endif; ?>
<?php if ($tiene_revisado_por && !empty($doc['revisor_nombre'])): ?>
<p><strong>Revisado Por:</strong> <?php echo htmlspecialchars($doc['revisor_nombre']); ?></p>
<?php endif; ?>
<?php if ($tiene_revisado_por && !empty($doc['revisor_username'])): ?>
<p><strong>Usuario Revisor:</strong>
<span class="badge bg-info" style="font-size: 0.85rem;">
<i class="fas fa-user me-1"></i><?php echo htmlspecialchars($doc['revisor_username']); ?>
</span>
</p>
<?php endif; ?>
</div>
</div>
<?php if ($tiene_observaciones && !empty($doc['observaciones'])): ?>
<hr>
<p><strong>Observaciones:</strong></p>
<p class="text-muted"><?php echo nl2br(htmlspecialchars($doc['observaciones'])); ?></p>
<?php endif; ?>
<?php if ($tiene_motivacion && $doc['estado'] === 'rechazado' && !empty($doc['motivacion_rechazo'])): ?>
<hr>
<div class="alert alert-danger">
<strong><i class="fas fa-exclamation-circle"></i> Motivación del Rechazo:</strong>
<p class="mb-0"><?php echo nl2br(htmlspecialchars($doc['motivacion_rechazo'])); ?></p>
</div>
<?php endif; ?>
<hr>
<div class="text-center">
<a href="../<?php echo htmlspecialchars($doc['archivo_pdf']); ?>" target="_blank" class="btn btn-primary">
<i class="fas fa-download me-2"></i>Descargar PDF
</a>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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
function aprobarDocumento(documentoId) {
Swal.fire({
title: '¿Aprobar Documento?',
html: '<p>¿Confirmar que este documento cumple con los requisitos?</p>',
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#27ae60',
cancelButtonColor: '#95a5a6',
confirmButtonText: 'Sí, Aprobar',
cancelButtonText: 'Cancelar'
}).then((result) => {
if (result.isConfirmed) {
realizarAccion('aprobar_documento', documentoId);
}
});
}
function rechazarDocumento(documentoId) {
Swal.fire({
title: '¿Rechazar Documento?',
html: `
<p class="mb-3">Ingrese la motivación del rechazo:</p>
<textarea id="motivacionRechazo" class="form-control" rows="4" placeholder="Describa por qué se rechaza el documento..."></textarea>
`,
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#e74c3c',
cancelButtonColor: '#95a5a6',
confirmButtonText: 'Sí, Rechazar',
cancelButtonText: 'Cancelar',
preConfirm: () => {
const motivacion = document.getElementById('motivacionRechazo').value;
if (!motivacion || motivacion.trim() === '') {
Swal.showValidationMessage('La motivación es obligatoria');
return false;
}
return motivacion;
}
}).then((result) => {
if (result.isConfirmed) {
realizarAccion('rechazar_documento', documentoId, result.value);
}
});
}
function realizarAccion(accion, documentoId, motivacion = '') {
Swal.fire({
title: 'Procesando...',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading(); }
});
const formData = new FormData();
formData.append('action', accion);
formData.append('documento_id', documentoId);
if (motivacion) {
formData.append('motivacion', motivacion);
}
fetch('documentos_empresas.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
Swal.close();
if (data.success) {
Swal.fire({
icon: 'success',
title: '¡Actualizado!',
text: data.message,
timer: 2000,
showConfirmButton: false
});
setTimeout(() => { location.reload(); }, 2000);
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.message
});
}
})
.catch(error => {
Swal.close();
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: 'Verifica tu conexión'
});
});
}
// Auto-close alerts
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
</script>
</body>
</html>