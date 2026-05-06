<?php
/**
* ============================================================================
* GESTIÓN DE INSPECCIONES - VERSIÓN CON PROGRAMACIÓN INTEGRADA
* ============================================================================
* Incluye: CRUD completo, Auditoría detallada, Exportación PDF,
*          Validaciones, Paginación, Búsqueda, Filtros Avanzados,
*          Acta de Inspección según Ley I Nº 168 (Chubut)
*          CON SUBIDA DE ARCHIVO PDF Y SELECCIÓN DE SUCURSALES
*          CON AUTO-COMPLETADO DE RESPONSABLE Y DNI DESDE TABLA PERSONAL
*          CON FORMATO APELLIDO, NOMBRE PARA RESPONSABLE PRESENTE
*          CON AUTO-COMPLETADO DE LEGAJE DESDE USUARIOS
*          CON CÁLCULO AUTOMÁTICO DE FECHA DE PRESENTACIÓN (Fecha + Plazo días hábiles)
*          CON MODAL COMPLETO DE VISUALIZACIÓN (TODAS LAS SECCIONES)
*          DISEÑO UNIFICADO CON EMPRESAS.PHP Y SUCURSALES.PHP
*          ✅ INTEGRACIÓN CON INSPECCIONES PROGRAMADAS
*          ✅ VINCULACIÓN DE INSPECCIÓN REAL CON PROGRAMACIÓN
*          ✅ ACTUALIZADO PARA CUMPLIMIENTO LEY I Nº 168 (CHUBUT)
*          ✅ SECCIÓN 6: EVIDENCIA Y MEDIDAS CAUTELARES AGREGADA
*
* @author Sistema de Seguridad
* @version 8.3 - Sin Detalle Infracciones, Columna Infracciones, Link a infracciones.php
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
// FUNCIONES DE UTILIDAD
// ============================================================================
function calcularFechaPresentacion($fecha_inspeccion, $plazo_dias) {
if (empty($fecha_inspeccion)) {
return date('Y-m-d');
}
$fecha = new DateTime($fecha_inspeccion);
$dias_sumados = 0;
while ($dias_sumados < $plazo_dias) {
$fecha->modify('+1 day');
$dia_semana = (int)$fecha->format('N');
if ($dia_semana < 7) {
$dias_sumados++;
}
}
return $fecha->format('Y-m-d');
}
function getEstadoBadge($activo) {
return $activo
? '<span class="badge bg-success">Activa</span>'
: '<span class="badge bg-secondary">Inactiva</span>';
}
function getInfraccionesBadge($tiene_infracciones) {
return $tiene_infracciones
? '<span class="badge bg-danger"><i class="fas fa-exclamation-circle"></i> Sí</span>'
: '<span class="badge bg-success"><i class="fas fa-check-circle"></i> No</span>';
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
function mantenerFiltros() {
$params = $_GET;
unset($params['pagina'], $params['orden'], $params['direccion'], $params['edit']);
return !empty($params) ? '&' . http_build_query($params) : '';
}
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
$current_page = 'inspecciones';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// ✅ AJAX - OBTENER PROGRAMACIONES PENDIENTES PARA VINCULAR
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_programaciones_pendientes') {
header('Content-Type: application/json');
try {
$stmt = $conn->prepare("
SELECT
p.id,
p.fecha_programada,
p.hora_programada,
p.frecuencia,
p.observaciones_planificacion,
e.nombre as empresa_nombre,
s.nombre as sucursal_nombre,
s.id as sucursal_id,
u.username as inspector_username,
u.nombre_completo as inspector_completo,
u.id as inspector_id
FROM inspecciones_programadas p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
LEFT JOIN usuarios u ON p.inspector_id = u.id
WHERE p.estado = 'pendiente'
ORDER BY p.fecha_programada ASC
");
$stmt->execute();
$programaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode([
'success' => true,
'programaciones' => $programaciones,
'count' => count($programaciones)
]);
exit;
} catch (Exception $e) {
echo json_encode([
'success' => false,
'programaciones' => [],
'error' => $e->getMessage()
]);
exit;
}
}
// ============================================================================
// ✅ AJAX - OBTENER INSPECCIONES DISPONIBLES PARA SUMARIO
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_inspecciones_disponibles') {
header('Content-Type: application/json');
try {
$stmt = $conn->prepare("
SELECT i.*
FROM inspecciones i
LEFT JOIN sumarios s ON i.id = s.inspeccion_id
WHERE i.activo = 1 AND s.id IS NULL
ORDER BY i.fecha_inspeccion DESC
");
$stmt->execute();
$inspecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode([
'success' => true,
'inspecciones' => $inspecciones,
'count' => count($inspecciones)
]);
exit;
} catch (Exception $e) {
echo json_encode([
'success' => false,
'inspecciones' => [],
'error' => $e->getMessage()
]);
exit;
}
}
// ============================================================================
// AJAX - OBTENER FUNCIONARIOS DESDE USUARIOS
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_funcionarios') {
header('Content-Type: application/json');
try {
$stmt = $conn->prepare("
SELECT id, username, nombre_completo, email, rol
FROM usuarios
WHERE nombre_completo IS NOT NULL AND nombre_completo != ''
ORDER BY nombre_completo
");
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'usuarios' => $usuarios]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'usuarios' => [], 'error' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// AJAX - OBTENER SUCURSALES POR EMPRESA CON RESPONSABLE
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_sucursales' && isset($_GET['empresa_id'])) {
header('Content-Type: application/json');
try {
$empresa_id = (int)$_GET['empresa_id'];
$sql = "
SELECT
s.id, s.nombre, s.domicilio, s.localidad, s.jurisdiccion,
p.id as responsable_id,
COALESCE(CONCAT(p.apellido, ', ', p.nombre), s.responsable_nombre) as responsable_completo,
COALESCE(p.nombre, '') as responsable_nombre,
COALESCE(p.apellido, '') as responsable_apellido,
COALESCE(p.dni, s.responsable_dni) as responsable_dni
FROM sucursales s
LEFT JOIN personal p ON s.responsable_id = p.id
WHERE s.empresa_id = ? AND s.activo = 1
ORDER BY s.nombre
";
$stmt = $conn->prepare($sql);
$stmt->execute([$empresa_id]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode([
'success' => true,
'sucursales' => $sucursales,
'count' => count($sucursales)
]);
exit;
} catch (Exception $e) {
echo json_encode([
'success' => false,
'sucursales' => [],
'error' => $e->getMessage()
]);
exit;
}
}
// ============================================================================
// AJAX - OBTENER DATOS DE INSPECCIÓN PARA MODAL
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_inspeccion_detalle' && isset($_GET['id'])) {
header('Content-Type: application/json');
try {
$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM inspecciones WHERE id = ?");
$stmt->execute([$id]);
$inspeccion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inspeccion) {
echo json_encode(['success' => false, 'error' => 'Inspección no encontrada']);
exit;
}
echo json_encode(['success' => true, 'inspeccion' => $inspeccion]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// VERIFICAR/CREAR ESTRUCTURA DE TABLA INSPECCIONES
// ============================================================================
try {
$table_exists = $conn->query("SHOW TABLES LIKE 'inspecciones'")->rowCount() > 0;
if (!$table_exists) {
$conn->exec("
CREATE TABLE inspecciones (
id INT AUTO_INCREMENT PRIMARY KEY,
numero_acta VARCHAR(50) NULL,
numero_anio VARCHAR(10) NULL,
fecha_inspeccion DATE NULL,
hora_inspeccion TIME NULL,
lugar VARCHAR(255) NULL,
funcionario_nombre VARCHAR(255) NULL,
funcionario_legajo VARCHAR(50) NULL,
empresa_id INT NULL,
sucursal_id INT NULL,
empresa_nombre VARCHAR(255) NULL,
empresa_cuit VARCHAR(20) NULL,
empresa_domicilio VARCHAR(255) NULL,
sucursal_nombre VARCHAR(255) NULL,
sucursal_domicilio VARCHAR(255) NULL,
responsable_nombre VARCHAR(255) NULL,
responsable_dni VARCHAR(20) NULL,
testigos_nombres TEXT NULL,
consentimiento BOOLEAN DEFAULT FALSE,
tiene_infracciones BOOLEAN DEFAULT FALSE,
archivo_pdf VARCHAR(255) NULL,
archivo_pdf_original VARCHAR(255) NULL,
programacion_id INT NULL,
observaciones_generales TEXT NULL,
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
INDEX idx_fecha (fecha_inspeccion),
INDEX idx_empresa (empresa_id),
INDEX idx_sucursal (sucursal_id),
INDEX idx_funcionario (funcionario_nombre),
INDEX idx_activo (activo),
INDEX idx_programacion (programacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
logAuditoria($conn, 'TABLA_CREADA', 'inspecciones', null, ['mensaje' => 'Tabla inspecciones creada'], $user['id']);
} else {
$columns = $conn->query("SHOW COLUMNS FROM inspecciones")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('programacion_id', $columns)) {
$conn->exec("ALTER TABLE inspecciones ADD COLUMN programacion_id INT NULL AFTER archivo_pdf_original");
logAuditoria($conn, 'COLUMNA_AGREGADA', 'inspecciones', null, ['columna' => 'programacion_id'], $user['id']);
}
if (!in_array('tiene_infracciones', $columns)) {
$conn->exec("ALTER TABLE inspecciones ADD COLUMN tiene_infracciones BOOLEAN DEFAULT FALSE AFTER consentimiento");
logAuditoria($conn, 'COLUMNA_AGREGADA', 'inspecciones', null, ['columna' => 'tiene_infracciones'], $user['id']);
}
}
} catch (PDOException $e) {
$error = "Error al verificar estructura: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// INICIALIZAR VARIABLES DE FILTROS
// ============================================================================
$search_empresa = $_GET['search_empresa'] ?? '';
$search_funcionario = $_GET['search_funcionario'] ?? '';
$search_fecha_desde = $_GET['search_fecha_desde'] ?? '';
$search_fecha_hasta = $_GET['search_fecha_hasta'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_programada = $_GET['search_programada'] ?? 'todos';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$columnas_permitidas = ['id', 'numero_acta', 'fecha_inspeccion', 'empresa_nombre', 'funcionario_nombre', 'activo', 'tiene_infracciones'];
$orden_columna = $_GET['orden'] ?? 'fecha_inspeccion';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'DESC');
if (!in_array($orden_columna, $columnas_permitidas)) $orden_columna = 'fecha_inspeccion';
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') $orden_direccion = 'DESC';
$orden_direccion_next = ($orden_direccion === 'ASC') ? 'DESC' : 'ASC';
// ============================================================================
// MANEJAR CREACIÓN DE INSPECCIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_inspeccion'])) {
try {
$numero_acta = trim($_POST['numero_acta'] ?? '');
$numero_anio = trim($_POST['numero_anio'] ?? date('Y'));
$fecha_inspeccion = !empty($_POST['fecha_inspeccion']) ? $_POST['fecha_inspeccion'] : date('Y-m-d');
$hora_inspeccion = !empty($_POST['hora_inspeccion']) ? $_POST['hora_inspeccion'] : date('H:i');
$lugar = trim($_POST['lugar'] ?? '');
$funcionario_nombre = trim($_POST['funcionario_nombre'] ?? '');
$funcionario_legajo = trim($_POST['funcionario_legajo'] ?? '');
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
$empresa_nombre = trim($_POST['empresa_nombre'] ?? '');
$empresa_cuit = trim($_POST['empresa_cuit'] ?? '');
$empresa_domicilio = trim($_POST['empresa_domicilio'] ?? '');
$sucursal_nombre = trim($_POST['sucursal_nombre'] ?? '');
$sucursal_domicilio = trim($_POST['sucursal_domicilio'] ?? '');
$responsable_nombre = trim($_POST['responsable_nombre'] ?? '');
$responsable_dni = trim($_POST['responsable_dni'] ?? '');
$testigos_nombres = trim($_POST['testigos_nombres'] ?? '');
$consentimiento = isset($_POST['consentimiento']) && $_POST['consentimiento'] === 'si' ? 1 : 0;
$tiene_infracciones = isset($_POST['tiene_infracciones']) && $_POST['tiene_infracciones'] === 'si' ? 1 : 0;
$observaciones_generales = trim($_POST['observaciones_generales'] ?? '');
$programacion_id = !empty($_POST['programacion_id']) ? (int)$_POST['programacion_id'] : null;
$archivo_pdf = null;
$archivo_pdf_original = null;
if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
$allowed_types = ['application/pdf', 'application/x-pdf'];
$file_ext = strtolower(pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION));
if ($file_ext !== 'pdf') throw new Exception('Solo se permiten archivos PDF');
if ($_FILES['archivo_pdf']['size'] > 10 * 1024 * 1024) throw new Exception('El archivo no puede superar los 10MB');
$upload_dir = '../uploads/inspecciones/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
$unique_name = 'acta_' . date('Ymd_His') . '_' . uniqid() . '.pdf';
$upload_path = $upload_dir . $unique_name;
if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $upload_path)) {
$archivo_pdf = $upload_path;
$archivo_pdf_original = $_FILES['archivo_pdf']['name'];
} else {
throw new Exception('Error al subir el archivo PDF');
}
}
if (empty($funcionario_nombre)) throw new Exception('El nombre del funcionario actuante es obligatorio');
if (empty($empresa_nombre)) throw new Exception('El nombre de la empresa inspeccionada es obligatorio');
$main_fields = [
'numero_acta', 'numero_anio', 'fecha_inspeccion', 'hora_inspeccion', 'lugar',
'funcionario_nombre', 'funcionario_legajo', 'empresa_id', 'sucursal_id',
'empresa_nombre', 'empresa_cuit', 'empresa_domicilio',
'sucursal_nombre', 'sucursal_domicilio',
'responsable_nombre', 'responsable_dni', 'testigos_nombres',
'consentimiento', 'tiene_infracciones',
'archivo_pdf', 'archivo_pdf_original', 'programacion_id',
'observaciones_generales', 'activo'
];
$main_values = [
$numero_acta, $numero_anio, $fecha_inspeccion, $hora_inspeccion, $lugar,
$funcionario_nombre, $funcionario_legajo, $empresa_id, $sucursal_id,
$empresa_nombre, $empresa_cuit, $empresa_domicilio,
$sucursal_nombre, $sucursal_domicilio,
$responsable_nombre, $responsable_dni, $testigos_nombres,
$consentimiento, $tiene_infracciones,
$archivo_pdf, $archivo_pdf_original, $programacion_id,
$observaciones_generales, 1
];
$sql = "INSERT INTO inspecciones (" . implode(', ', $main_fields) . ") VALUES (" . implode(', ', array_fill(0, count($main_fields), '?')) . ")";
$stmt = $conn->prepare($sql);
$stmt->execute($main_values);
$inspeccion_id = $conn->lastInsertId();
if ($programacion_id) {
$stmt_prog = $conn->prepare("
UPDATE inspecciones_programadas
SET estado = 'realizada', inspeccion_id_relacionado = ?
WHERE id = ?
");
$stmt_prog->execute([$inspeccion_id, $programacion_id]);
logAuditoria($conn, 'PROGRAMACION_VINCULADA', 'inspecciones_programadas', $programacion_id, [
'inspeccion_id' => $inspeccion_id,
'usuario' => $user['username']
], $user['id']);
}
$detalles = [
'accion' => 'inspeccion_creada',
'id' => $inspeccion_id,
'numero_acta' => $numero_acta,
'empresa' => $empresa_nombre,
'sucursal' => $sucursal_nombre ?: 'Casa Central',
'funcionario' => $funcionario_nombre,
'fecha' => $fecha_inspeccion,
'consentimiento' => $consentimiento,
'tiene_infracciones' => $tiene_infracciones,
'archivo_pdf' => $archivo_pdf_original ?? 'No subido',
'programacion_id' => $programacion_id,
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'INSPECCION_CREADA', 'inspecciones', $inspeccion_id, $detalles, $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-check-circle fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Inspección creada exitosamente!</strong></h5>
<p class='mb-0'><strong>Acta N°:</strong> {$numero_acta}/{$numero_anio}</p>
<p class='mb-0'><strong>Empresa:</strong> {$empresa_nombre}" . ($sucursal_nombre ? " - {$sucursal_nombre}" : "") . "</p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: inspecciones.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_CREACION_INSPECCION', 'inspecciones', null, ['error' => $e->getMessage()], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: inspecciones.php');
exit;
}
}
// ============================================================================
// MANEJAR ACTUALIZACIÓN DE INSPECCIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_inspeccion'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) throw new Exception('ID de inspección inválido');
$stmt = $conn->prepare("SELECT * FROM inspecciones WHERE id = ?");
$stmt->execute([$id]);
$datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$datos_antiguos) throw new Exception('Inspección no encontrada');
$archivo_pdf = $datos_antiguos['archivo_pdf'];
$archivo_pdf_original = $datos_antiguos['archivo_pdf_original'];
if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
if (!empty($datos_antiguos['archivo_pdf']) && file_exists($datos_antiguos['archivo_pdf'])) {
unlink($datos_antiguos['archivo_pdf']);
}
$upload_dir = '../uploads/inspecciones/';
$unique_name = 'acta_' . date('Ymd_His') . '_' . uniqid() . '.pdf';
$upload_path = $upload_dir . $unique_name;
if (move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $upload_path)) {
$archivo_pdf = $upload_path;
$archivo_pdf_original = $_FILES['archivo_pdf']['name'];
}
}
$stmt = $conn->prepare("
UPDATE inspecciones SET
numero_acta = ?, numero_anio = ?, fecha_inspeccion = ?, hora_inspeccion = ?,
lugar = ?, funcionario_nombre = ?, funcionario_legajo = ?, empresa_id = ?,
sucursal_id = ?, empresa_nombre = ?, empresa_cuit = ?, empresa_domicilio = ?,
sucursal_nombre = ?, sucursal_domicilio = ?,
responsable_nombre = ?, responsable_dni = ?, testigos_nombres = ?, consentimiento = ?,
tiene_infracciones = ?,
archivo_pdf = ?, archivo_pdf_original = ?, programacion_id = ?,
observaciones_generales = ?
WHERE id = ?
");
$stmt->execute([
trim($_POST['numero_acta'] ?? ''), trim($_POST['numero_anio'] ?? ''),
$_POST['fecha_inspeccion'] ?? null, $_POST['hora_inspeccion'] ?? null,
trim($_POST['lugar'] ?? ''), trim($_POST['funcionario_nombre'] ?? ''),
trim($_POST['funcionario_legajo'] ?? ''), !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null,
!empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null,
trim($_POST['empresa_nombre'] ?? ''), trim($_POST['empresa_cuit'] ?? ''),
trim($_POST['empresa_domicilio'] ?? ''), trim($_POST['sucursal_nombre'] ?? ''),
trim($_POST['sucursal_domicilio'] ?? ''), trim($_POST['responsable_nombre'] ?? ''),
trim($_POST['responsable_dni'] ?? ''), trim($_POST['testigos_nombres'] ?? ''),
isset($_POST['consentimiento']) && $_POST['consentimiento'] === 'si' ? 1 : 0,
isset($_POST['tiene_infracciones']) && $_POST['tiene_infracciones'] === 'si' ? 1 : 0,
$archivo_pdf, $archivo_pdf_original, !empty($_POST['programacion_id']) ? (int)$_POST['programacion_id'] : null,
trim($_POST['observaciones_generales'] ?? ''),
$id
]);
logAuditoria($conn, 'INSPECCION_ACTUALIZADA', 'inspecciones', $id, ['id' => $id], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>¡Inspección actualizada exitosamente!
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: inspecciones.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: inspecciones.php');
exit;
}
}
// ============================================================================
// MANEJAR ELIMINACIÓN/DESACTIVACIÓN DE INSPECCIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_inspeccion'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) throw new Exception('ID de inspección inválido');
$stmt = $conn->prepare("SELECT * FROM inspecciones WHERE id = ?");
$stmt->execute([$id]);
$inspeccion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inspeccion) throw new Exception('Inspección no encontrada');
if (!empty($inspeccion['archivo_pdf']) && file_exists($inspeccion['archivo_pdf'])) {
unlink($inspeccion['archivo_pdf']);
}
$stmt = $conn->prepare("UPDATE inspecciones SET activo = 0 WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'INSPECCION_DESACTIVADA', 'inspecciones', $id, ['id' => $id], $user['id']);
$_SESSION['success'] = "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
<i class='fas fa-exclamation-triangle me-2'></i>¡Inspección desactivada!
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: inspecciones.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: inspecciones.php');
exit;
}
}
// ============================================================================
// DESCARGAR ARCHIVO PDF ORIGINAL
// ============================================================================
if (isset($_GET['descargar_pdf']) && isset($_GET['id'])) {
try {
$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT archivo_pdf, archivo_pdf_original FROM inspecciones WHERE id = ?");
$stmt->execute([$id]);
$inspeccion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inspeccion || empty($inspeccion['archivo_pdf'])) throw new Exception('Archivo no encontrado');
if (!file_exists($inspeccion['archivo_pdf'])) throw new Exception('El archivo físico no existe');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . basename($inspeccion['archivo_pdf_original'] ?? 'acta.pdf') . '"');
header('Content-Length: ' . filesize($inspeccion['archivo_pdf']));
readfile($inspeccion['archivo_pdf']);
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al descargar: ' . $e->getMessage();
header('Location: inspecciones.php');
exit;
}
}
// ============================================================================
// OBTENER DATOS CON PAGINACIÓN
// ============================================================================
$inspecciones = [];
$empresas = [];
$total_registros = 0;
$total_paginas = 0;
$total_activas = 0;
$total_irregularidades = 0;
$total_empresas_inspeccionadas = 0;
$total_programadas = 0;
$total_no_programadas = 0;
try {
$where_clauses = [];
$params = [];
if (!empty($search_empresa)) {
$where_clauses[] = "empresa_nombre LIKE :search_empresa";
$params[':search_empresa'] = '%' . $search_empresa . '%';
}
if (!empty($search_funcionario)) {
$where_clauses[] = "funcionario_nombre LIKE :search_funcionario";
$params[':search_funcionario'] = '%' . $search_funcionario . '%';
}
if (!empty($search_fecha_desde)) {
$where_clauses[] = "fecha_inspeccion >= :fecha_desde";
$params[':fecha_desde'] = $search_fecha_desde;
}
if (!empty($search_fecha_hasta)) {
$where_clauses[] = "fecha_inspeccion <= :fecha_hasta";
$params[':fecha_hasta'] = $search_fecha_hasta;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "activo = :activo";
$params[':activo'] = ($search_estado === 'activas') ? 1 : 0;
}
if ($search_programada === 'programada') {
$where_clauses[] = "programacion_id IS NOT NULL";
} elseif ($search_programada === 'no_programada') {
$where_clauses[] = "programacion_id IS NULL";
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$count_sql = "SELECT COUNT(*) as total FROM inspecciones $where_sql";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$orden_sql = "ORDER BY $orden_columna $orden_direccion";
$limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
$sql = "SELECT * FROM inspecciones $where_sql $orden_sql $limit_sql";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$inspecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->query("SELECT id, nombre, cuit, domicilio FROM empresas WHERE activo = 1 ORDER BY nombre");
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones WHERE activo = 1");
$total_activas = $stmt->fetch()['total'];
$columns = $conn->query("SHOW COLUMNS FROM inspecciones")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('irregularidad_multa', $columns) && in_array('irregularidad_clausura', $columns)) {
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones WHERE irregularidad_multa = 1 OR irregularidad_clausura = 1");
$total_irregularidades = $stmt->fetch()['total'];
}
$stmt = $conn->query("SELECT COUNT(DISTINCT empresa_id) as total FROM inspecciones WHERE empresa_id IS NOT NULL");
$total_empresas_inspeccionadas = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones WHERE programacion_id IS NOT NULL AND activo = 1");
$total_programadas = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones WHERE programacion_id IS NULL AND activo = 1");
$total_no_programadas = $stmt->fetch()['total'];
} catch (PDOException $e) {
$inspecciones = [];
$error = "<strong>⚠️ Atención:</strong> No se pudieron cargar las inspecciones. Error: " . htmlspecialchars($e->getMessage());
error_log("Error en inspecciones.php: " . $e->getMessage());
}
// ============================================================================
// INSPECCIÓN PARA EDITAR
// ============================================================================
$inspeccion_edit = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
$edit_id = (int)$_GET['edit'];
$stmt = $conn->prepare("SELECT * FROM inspecciones WHERE id = ?");
$stmt->execute([$edit_id]);
$inspeccion_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}
// ============================================================================
// PRE-CARGAR DATOS DE PROGRAMACIÓN SI VIENE DE inspecciones_programadas.php
// ============================================================================
$programacion_preload = null;
if (isset($_GET['programacion_id']) && !empty($_GET['programacion_id'])) {
$prog_id = (int)$_GET['programacion_id'];
$stmt = $conn->prepare("
SELECT
p.*,
e.nombre as empresa_nombre,
e.cuit as empresa_cuit,
e.domicilio as empresa_domicilio,
s.nombre as sucursal_nombre,
s.domicilio as sucursal_domicilio,
s.id as sucursal_id_preload,
u.username as inspector_username,
u.nombre_completo as inspector_completo,
u.id as inspector_id_preload
FROM inspecciones_programadas p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
LEFT JOIN usuarios u ON p.inspector_id = u.id
WHERE p.id = ?
");
$stmt->execute([$prog_id]);
$programacion_preload = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Inspecciones - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
.checklist-group {
background: #f8f9fa;
border: 1px solid var(--card-border);
border-radius: 4px;
padding: 15px;
margin-bottom: 15px;
}
.checklist-group h5 {
font-size: 1rem;
font-weight: 600;
color: #495057;
margin-bottom: 10px;
border-bottom: 1px solid #dee2e6;
padding-bottom: 5px;
}
.icono-rotar {
transition: transform 0.3s ease;
}
.icono-rotar.rotado {
transform: rotate(180deg);
}
.badge-programada {
background: linear-gradient(135deg, #9b59b6, #8e44ad);
color: white;
}
.badge-no-programada {
background: linear-gradient(135deg, #3498db, #2980b9);
color: white;
}
.filtros-box {
background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
border: 1px solid var(--card-border);
border-radius: 8px;
padding: 20px;
margin-bottom: 20px;
box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}
.evidencia-card {
background: #fff3cd;
border: 1px solid #ffc107;
border-radius: 4px;
padding: 15px;
margin-bottom: 15px;
}
.evidencia-card h5 {
color: #856404;
font-weight: 600;
margin-bottom: 10px;
}
.legal-text {
background: #f8f9fa;
border-left: 4px solid #0d6efd;
padding: 12px 15px;
font-size: 0.9rem;
margin: 10px 0;
}
.signature-section {
border-top: 2px dashed #dee2e6;
padding-top: 20px;
margin-top: 20px;
}
</style>
</head>
<body class="sidebar-collapsed">
<?php $page_title = 'Gestión de Inspecciones'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
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
<div class="stat-icon mb-2 text-primary"><i class="fas fa-clipboard-check fa-2x"></i></div>
<div class="stat-number"><?php echo $total_registros; ?></div>
<div class="stat-label">Inspecciones Totales</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $total_activas; ?></div>
<div class="stat-label">Inspecciones Activas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-calendar-check fa-2x"></i></div>
<div class="stat-number"><?php echo $total_programadas; ?></div>
<div class="stat-label">De Programación</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-clipboard fa-2x"></i></div>
<div class="stat-number"><?php echo $total_no_programadas; ?></div>
<div class="stat-label">Espontáneas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-exclamation-triangle fa-2x"></i></div>
<div class="stat-number"><?php echo $total_irregularidades; ?></div>
<div class="stat-label">Con Irregularidades</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-secondary"><i class="fas fa-building fa-2x"></i></div>
<div class="stat-number"><?php echo $total_empresas_inspeccionadas; ?></div>
<div class="stat-label">Empresas Inspeccionadas</div>
</div>
</div>
<!-- FILTROS DE BÚSQUEDA -->
<div class="filtros-box">
<div class="d-flex justify-content-between align-items-center mb-3"
data-bs-toggle="collapse"
data-bs-target="#contenidoFiltros"
style="cursor: pointer;">
<h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtros de Búsqueda</h5>
<i class="fas fa-chevron-down icono-rotar" id="iconoFiltros"></i>
</div>
<div id="contenidoFiltros" class="collapse">
<form method="GET" action="" class="row g-3">
<div class="col-md-3">
<label class="form-label">Empresa</label>
<input type="text" name="search_empresa" class="form-control"
value="<?php echo htmlspecialchars($search_empresa); ?>" placeholder="Buscar empresa...">
</div>
<div class="col-md-3">
<label class="form-label">Funcionario</label>
<input type="text" name="search_funcionario" class="form-control"
value="<?php echo htmlspecialchars($search_funcionario); ?>" placeholder="Buscar funcionario...">
</div>
<div class="col-md-2">
<label class="form-label">Desde</label>
<input type="date" name="search_fecha_desde" class="form-control"
value="<?php echo htmlspecialchars($search_fecha_desde); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Hasta</label>
<input type="date" name="search_fecha_hasta" class="form-control"
value="<?php echo htmlspecialchars($search_fecha_hasta); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Estado</label>
<select name="search_estado" class="form-select">
<option value="todos" <?php echo ($search_estado === 'todos') ? 'selected' : ''; ?>>Todas</option>
<option value="activas" <?php echo ($search_estado === 'activas') ? 'selected' : ''; ?>>Activas</option>
<option value="inactivas" <?php echo ($search_estado === 'inactivas') ? 'selected' : ''; ?>>Inactivas</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Tipo</label>
<select name="search_programada" class="form-select">
<option value="todos" <?php echo ($search_programada === 'todos') ? 'selected' : ''; ?>>Todas</option>
<option value="programada" <?php echo ($search_programada === 'programada') ? 'selected' : ''; ?>>De Programación</option>
<option value="no_programada" <?php echo ($search_programada === 'no_programada') ? 'selected' : ''; ?>>Espontáneas</option>
</select>
</div>
<div class="col-12 d-flex gap-2">
<button type="submit" class="btn btn-primary">
<i class="fas fa-search me-2"></i>Filtrar
</button>
<a href="inspecciones.php" class="btn btn-secondary">
<i class="fas fa-undo me-2"></i>Limpiar
</a>
</div>
</form>
</div>
</div>
<!-- NUEVA INSPECCIÓN -->
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center"
data-bs-toggle="collapse"
data-bs-target="#nuevaInspeccionForm"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<span><i class="fas fa-plus-circle me-2"></i>Nueva Inspección</span>
<div class="d-flex align-items-center gap-2">
<?php if ($programacion_preload): ?>
<span class="badge bg-warning text-dark">
<i class="fas fa-calendar-check"></i> Vinculada a Programación #<?php echo $programacion_preload['id']; ?>
</span>
<?php endif; ?>
<i class="fas fa-chevron-down icono-rotar" id="iconoNuevaInspeccion"></i>
</div>
</div>
<div class="collapse mt-3 <?php echo $inspeccion_edit || $programacion_preload ? 'show' : ''; ?>" id="nuevaInspeccionForm">
<h5 class="mb-3">
<i class="fas fa-clipboard-check me-2"></i>
<?php echo $inspeccion_edit ? 'Editar' : 'Registrar Nueva'; ?> Inspección
</h5>
<form method="POST" action="" enctype="multipart/form-data">
<input type="hidden" name="crear_inspeccion" value="1">
<?php if ($programacion_preload): ?>
<input type="hidden" name="programacion_id" value="<?php echo $programacion_preload['id']; ?>">
<input type="hidden" id="preloadInspectorId" value="<?php echo htmlspecialchars($programacion_preload['inspector_id_preload'] ?? ''); ?>">
<input type="hidden" id="preloadInspectorUsername" value="<?php echo htmlspecialchars($programacion_preload['inspector_username'] ?? ''); ?>">
<input type="hidden" id="preloadInspectorNombre" value="<?php echo htmlspecialchars($programacion_preload['inspector_completo'] ?? ''); ?>">
<input type="hidden" id="preloadSucursalId" value="<?php echo htmlspecialchars($programacion_preload['sucursal_id_preload'] ?? ''); ?>">
<input type="hidden" id="preloadSucursalNombre" value="<?php echo htmlspecialchars($programacion_preload['sucursal_nombre'] ?? ''); ?>">
<?php endif; ?>
<div class="row g-3">
<div class="col-md-3">
<label class="form-label">N° Acta</label>
<input type="text" name="numero_acta" class="form-control"
value="<?php echo htmlspecialchars($inspeccion_edit['numero_acta'] ?? ''); ?>" placeholder="001">
<small class="text-muted"><i class="fas fa-info-circle"></i> N° Acta se forma: URE-001</small>
</div>
<div class="col-md-3">
<label class="form-label">Año</label>
<input type="text" name="numero_anio" class="form-control"
value="<?php echo htmlspecialchars($inspeccion_edit['numero_anio'] ?? date('Y')); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Fecha Inspección</label>
<input type="date" name="fecha_inspeccion" class="form-control"
value="<?php echo htmlspecialchars($inspeccion_edit['fecha_inspeccion'] ?? $programacion_preload['fecha_programada'] ?? date('Y-m-d')); ?>"
id="fechaInspeccionInput">
</div>
<div class="col-md-3">
<label class="form-label">Hora</label>
<input type="time" name="hora_inspeccion" class="form-control"
value="<?php echo htmlspecialchars($inspeccion_edit['hora_inspeccion'] ?? $programacion_preload['hora_programada'] ?? date('H:i')); ?>">
</div>
<div class="col-md-12">
<label class="form-label">
<i class="fas fa-calendar-alt me-1"></i> Vincular con Programación Pendiente (Opcional)
</label>
<select name="programacion_id" class="form-select" id="selectProgramacion"
<?php echo $programacion_preload ? 'disabled' : ''; ?>>
<option value="">-- Sin vincular --</option>
</select>
<small class="text-muted">
<i class="fas fa-info-circle"></i>
<?php if ($programacion_preload): ?>
Programación #<?php echo $programacion_preload['id']; ?> precargada automáticamente
<?php else: ?>
Seleccione una programación pendiente para vincular esta inspección
<?php endif; ?>
</small>
</div>
<div class="col-md-6">
<label class="form-label">Lugar</label>
<input type="text" name="lugar" class="form-control" id="lugarInput"
value="<?php echo htmlspecialchars($inspeccion_edit['lugar'] ?? $programacion_preload['empresa_domicilio'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Funcionario Actuante</label>
<select name="funcionario_nombre" class="form-select" id="funcionarioSelect" required>
<option value="">Seleccionar funcionario...</option>
</select>
<small class="text-muted"><i class="fas fa-info-circle"></i> Seleccione un usuario del sistema</small>
</div>
<div class="col-md-4">
<label class="form-label">D.N.I. Funcionario</label>
<input type="text" name="funcionario_legajo" class="form-control" id="funcionarioLegajo"
readonly style="background-color:#e8f5e9;"
value="<?php echo htmlspecialchars($inspeccion_edit['funcionario_legajo'] ?? $programacion_preload['inspector_username'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Empresa</label>
<select name="empresa_id" class="form-select" id="empresaSelect">
<option value="">Seleccionar empresa...</option>
<?php foreach ($empresas as $emp): ?>
<option value="<?php echo $emp['id']; ?>"
data-nombre="<?php echo htmlspecialchars($emp['nombre']); ?>"
data-cuit="<?php echo htmlspecialchars($emp['cuit']); ?>"
data-domicilio="<?php echo htmlspecialchars($emp['domicilio'] ?? ''); ?>"
<?php echo ($inspeccion_edit && $inspeccion_edit['empresa_id'] == $emp['id']) ||
($programacion_preload && $programacion_preload['empresa_id'] == $emp['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($emp['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Sucursal</label>
<select name="sucursal_id" class="form-select" id="sucursalSelect" disabled>
<option value="">Casa Central</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Responsable Presente</label>
<input type="text" name="responsable_nombre" class="form-control" id="responsableNombreInput"
readonly style="background-color:#e8f5e9;"
value="<?php echo htmlspecialchars($inspeccion_edit['responsable_nombre'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">DNI Responsable</label>
<input type="text" name="responsable_dni" class="form-control" id="responsableDniInput"
readonly style="background-color:#e8f5e9;"
value="<?php echo htmlspecialchars($inspeccion_edit['responsable_dni'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Testigos (si corresponde)</label>
<input type="text" name="testigos_nombres" class="form-control" id="testigosNombres"
placeholder="Nombre y DNI de testigos"
value="<?php echo htmlspecialchars($inspeccion_edit['testigos_nombres'] ?? ''); ?>">
<small class="text-muted"><i class="fas fa-info-circle"></i> Para casos de negativa de firma</small>
</div>
<div class="col-md-4">
<label class="form-label">CUIT Empresa</label>
<input type="text" name="empresa_cuit" class="form-control" id="empresaCuit"
readonly style="background-color:#e8f5e9;"
value="<?php echo htmlspecialchars($inspeccion_edit['empresa_cuit'] ?? $programacion_preload['empresa_cuit'] ?? ''); ?>">
</div>
<div class="col-md-8">
<label class="form-label">Domicilio Empresa</label>
<input type="text" name="empresa_domicilio" class="form-control" id="empresaDomicilio"
readonly style="background-color:#e8f5e9;"
value="<?php echo htmlspecialchars($inspeccion_edit['empresa_domicilio'] ?? $programacion_preload['empresa_domicilio'] ?? ''); ?>">
</div>
<div class="col-md-12">
<label class="form-label">Nombre Empresa</label>
<input type="text" name="empresa_nombre" class="form-control" id="empresaNombre"
readonly style="background-color:#e8f5e9;"
value="<?php echo htmlspecialchars($inspeccion_edit['empresa_nombre'] ?? $programacion_preload['empresa_nombre'] ?? ''); ?>">
</div>
<input type="hidden" name="sucursal_nombre" id="sucursalNombre"
value="<?php echo htmlspecialchars($inspeccion_edit['sucursal_nombre'] ?? $programacion_preload['sucursal_nombre'] ?? ''); ?>">
<input type="hidden" name="sucursal_domicilio" id="sucursalDomicilio"
value="<?php echo htmlspecialchars($inspeccion_edit['sucursal_domicilio'] ?? $programacion_preload['sucursal_domicilio'] ?? ''); ?>">
<div class="col-md-4">
<label class="form-label">Consentimiento</label>
<select name="consentimiento" class="form-select" required>
<option value="si" <?php echo ($inspeccion_edit && $inspeccion_edit['consentimiento']) ? 'selected' : ''; ?>>
✅ SI - Presta consentimiento
</option>
<option value="no" <?php echo ($inspeccion_edit && !$inspeccion_edit['consentimiento']) ? 'selected' : ''; ?>>
❌ NO - No presta consentimiento
</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">¿Tiene Infracciones?</label>
<select name="tiene_infracciones" class="form-select" required>
<option value="si" <?php echo ($inspeccion_edit && $inspeccion_edit['tiene_infracciones']) ? 'selected' : ''; ?>>
✅ Sí - Registrar infracciones
</option>
<option value="no" <?php echo ($inspeccion_edit && !$inspeccion_edit['tiene_infracciones']) ? 'selected' : ''; ?>>
❌ No - Sin infracciones
</option>
</select>
<small class="text-muted"><i class="fas fa-info-circle"></i> Si selecciona "Sí", podrá agregar detalles en infracciones.php</small>
</div>
<div class="col-md-12">
<label class="form-label">Subir Acta de Inspección (PDF)</label>
<input type="file" name="archivo_pdf" id="archivoPdfInput" class="form-control" accept=".pdf">
<?php if ($inspeccion_edit && !empty($inspeccion_edit['archivo_pdf_original'])): ?>
<small class="text-muted">
<i class="fas fa-file-pdf"></i> Archivo actual: <?php echo htmlspecialchars($inspeccion_edit['archivo_pdf_original']); ?>
</small>
<?php endif; ?>
</div>
<div class="col-md-12">
<label class="form-label">Observaciones Generales</label>
<textarea name="observaciones_generales" class="form-control" rows="3"><?php echo htmlspecialchars($inspeccion_edit['observaciones_generales'] ?? ''); ?></textarea>
</div>
<div class="col-12 text-end">
<button type="submit" class="btn btn-success">
<i class="fas fa-save me-2"></i>
<?php echo $inspeccion_edit ? 'Actualizar Inspección' : 'Registrar Inspección'; ?>
</button>
<?php if ($inspeccion_edit): ?>
<a href="inspecciones.php" class="btn btn-secondary ms-2">
<i class="fas fa-times me-2"></i> Cancelar
</a>
<?php endif; ?>
</div>
</div>
</form>
</div>
</div>
<!-- LISTADO DE INSPECCIONES -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Inspecciones
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<?php if (empty($inspecciones)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
<h5>No hay inspecciones registradas</h5>
<p class="text-muted">
<?php echo empty($search_empresa) && $search_estado === 'todos' ?
'Registra tu primera inspección para comenzar.' :
'No se encontraron inspecciones con los filtros aplicados.'; ?>
</p>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaInspeccionForm">
<i class="fas fa-plus me-2"></i>Crear Inspección
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th>
<a href="<?php echo generarUrlOrden('id', $orden_columna === 'id' ? $orden_direccion_next : 'ASC'); ?>"
class="text-decoration-none text-dark">
ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?>
</a>
</th>
<th>Acta</th>
<th>
<a href="<?php echo generarUrlOrden('fecha_inspeccion', $orden_columna === 'fecha_inspeccion' ? $orden_direccion_next : 'ASC'); ?>"
class="text-decoration-none text-dark">
Fecha <?php echo mostrarIconoOrden('fecha_inspeccion', $orden_columna, $orden_direccion); ?>
</a>
</th>
<th>Empresa/Sucursal</th>
<th>Funcionario</th>
<th>Tipo</th>
<th>PDF</th>
<th>
<a href="<?php echo generarUrlOrden('tiene_infracciones', $orden_columna === 'tiene_infracciones' ? $orden_direccion_next : 'ASC'); ?>"
class="text-decoration-none text-dark">
Infracciones <?php echo mostrarIconoOrden('tiene_infracciones', $orden_columna, $orden_direccion); ?>
</a>
</th>
<th>
<a href="<?php echo generarUrlOrden('activo', $orden_columna === 'activo' ? $orden_direccion_next : 'ASC'); ?>"
class="text-decoration-none text-dark">
Estado <?php echo mostrarIconoOrden('activo', $orden_columna, $orden_direccion); ?>
</a>
</th>
<th class="table-actions">Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($inspecciones as $inspeccion): ?>
<tr>
<td><strong>#<?php echo $inspeccion['id']; ?></strong></td>
<td>
<?php echo htmlspecialchars($inspeccion['numero_acta'] ?? 'N/A'); ?>/
<?php echo htmlspecialchars($inspeccion['numero_anio'] ?? '-'); ?>
</td>
<td>
<?php echo !empty($inspeccion['fecha_inspeccion']) ?
date('d/m/Y', strtotime($inspeccion['fecha_inspeccion'])) : '-'; ?>
</td>
<td>
<strong><?php echo htmlspecialchars($inspeccion['empresa_nombre'] ?? 'N/A'); ?></strong><br>
<small class="text-muted">
<?php echo htmlspecialchars($inspeccion['sucursal_nombre'] ?? 'Casa Central'); ?>
</small>
</td>
<td><?php echo htmlspecialchars($inspeccion['funcionario_nombre'] ?? 'N/A'); ?></td>
<td>
<?php if (!empty($inspeccion['programacion_id'])): ?>
<span class="badge badge-programada">
<i class="fas fa-calendar-check"></i> Programada
</span>
<?php else: ?>
<span class="badge badge-no-programada">
<i class="fas fa-clipboard"></i> Espontánea
</span>
<?php endif; ?>
</td>
<td>
<?php if (!empty($inspeccion['archivo_pdf'])): ?>
<a href="?descargar_pdf=1&id=<?php echo $inspeccion['id']; ?>"
class="btn btn-sm btn-danger">
<i class="fas fa-file-pdf"></i>
</a>
<?php else: ?>
<span class="text-muted">-</span>
<?php endif; ?>
</td>
<td>
<?php echo getInfraccionesBadge($inspeccion['tiene_infracciones'] ?? false); ?>
</td>
<td><?php echo getEstadoBadge($inspeccion['activo']); ?></td>
<td class="table-actions">
<div class="btn-group" role="group">
<button class="btn btn-sm btn-outline-primary btn-ver-inspeccion"
title="Ver" data-id="<?php echo $inspeccion['id']; ?>">
<i class="fas fa-eye"></i>
</button>
<a href="infracciones.php?inspeccion_id=<?php echo $inspeccion['id']; ?>"
class="btn btn-sm btn-outline-warning"
title="Agregar/Editar Infracciones">
<i class="fas fa-plus-circle"></i>
</a>
<a href="sumarios.php?crear_desde_inspeccion=<?php echo $inspeccion['id']; ?>"
class="btn btn-sm btn-outline-warning"
title="Crear Sumario Administrativo">
<i class="fas fa-gavel"></i>
</a>
<?php if ($auth->hasRole('administrador') || $auth->hasRole('super_admin')): ?>
<button class="btn btn-sm btn-outline-danger btn-eliminar"
title="Eliminar" data-id="<?php echo $inspeccion['id']; ?>">
<i class="fas fa-trash"></i>
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
<nav aria-label="Paginación de inspecciones">
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
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>">
<?php echo $i; ?>
</a>
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
<!-- MODAL VER INSPECCIÓN -->
<div class="modal fade" id="modalVerInspeccion" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-eye me-2"></i>Detalle de Inspección</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div id="modalInspeccionContent">
<div class="text-center py-5">
<div class="spinner-border text-primary" role="status"></div>
<p class="mt-2">Cargando datos...</p>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
<a href="#" id="btnDescargarPdf" class="btn btn-danger" style="display:none;">
<i class="fas fa-file-pdf me-2"></i>Descargar PDF
</a>
</div>
</div>
</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
let pendingSucursalSelection = null;
const contenidoFiltros = document.getElementById('contenidoFiltros');
const iconoFiltros = document.getElementById('iconoFiltros');
if (contenidoFiltros && iconoFiltros) {
contenidoFiltros.addEventListener('show.bs.collapse', function() {
iconoFiltros.classList.add('rotado');
});
contenidoFiltros.addEventListener('hide.bs.collapse', function() {
iconoFiltros.classList.remove('rotado');
});
}
const contenidoNuevaInspeccion = document.getElementById('nuevaInspeccionForm');
const iconoNuevaInspeccion = document.getElementById('iconoNuevaInspeccion');
if (contenidoNuevaInspeccion && iconoNuevaInspeccion) {
contenidoNuevaInspeccion.addEventListener('show.bs.collapse', function() {
iconoNuevaInspeccion.classList.add('rotado');
});
contenidoNuevaInspeccion.addEventListener('hide.bs.collapse', function() {
iconoNuevaInspeccion.classList.remove('rotado');
});
}
const selectProgramacion = document.getElementById('selectProgramacion');
if (selectProgramacion && !selectProgramacion.disabled) {
fetch('inspecciones.php?action=get_programaciones_pendientes')
.then(response => response.json())
.then(data => {
if (data.success && data.programaciones.length > 0) {
data.programaciones.forEach(prog => {
const option = document.createElement('option');
option.value = prog.id;
option.textContent = `${prog.empresa_nombre}${prog.sucursal_nombre ? ' - ' + prog.sucursal_nombre : ''} (${prog.fecha_programada})`;
option.dataset.empresaNombre = prog.empresa_nombre;
option.dataset.empresaCuit = '';
option.dataset.empresaDomicilio = '';
option.dataset.sucursalNombre = prog.sucursal_nombre || '';
option.dataset.sucursalDomicilio = '';
option.dataset.sucursalId = prog.sucursal_id || '';
option.dataset.inspectorId = prog.inspector_id || '';
option.dataset.inspectorUsername = prog.inspector_username || '';
option.dataset.inspectorNombre = prog.inspector_completo || '';
selectProgramacion.appendChild(option);
});
<?php if ($programacion_preload): ?>
selectProgramacion.value = '<?php echo $programacion_preload['id']; ?>';
selectProgramacion.dispatchEvent(new Event('change'));
<?php endif; ?>
}
})
.catch(error => console.error('Error al cargar programaciones:', error));
selectProgramacion.addEventListener('change', function() {
const option = this.options[this.selectedIndex];
if (this.value && option.dataset.empresaNombre) {
const empresaSelect = document.getElementById('empresaSelect');
const funcionarioSelect = document.getElementById('funcionarioSelect');
const funcionarioLegajo = document.getElementById('funcionarioLegajo');
if (empresaSelect) {
for (let i = 0; i < empresaSelect.options.length; i++) {
if (empresaSelect.options[i].text.includes(option.dataset.empresaNombre)) {
empresaSelect.selectedIndex = i;
empresaSelect.dispatchEvent(new Event('change'));
break;
}
}
}
if (funcionarioSelect && option.dataset.inspectorNombre) {
const inspectorNombre = option.dataset.inspectorNombre;
const inspectorUsername = option.dataset.inspectorUsername;
for (let i = 0; i < funcionarioSelect.options.length; i++) {
if (funcionarioSelect.options[i].value === inspectorNombre ||
funcionarioSelect.options[i].dataset.username === inspectorUsername) {
funcionarioSelect.selectedIndex = i;
if (funcionarioLegajo) funcionarioLegajo.value = inspectorUsername;
funcionarioSelect.dispatchEvent(new Event('change'));
break;
}
}
}
if (option.dataset.sucursalId) {
pendingSucursalSelection = option.dataset.sucursalId;
}
}
});
}
const funcionarioSelect = document.getElementById('funcionarioSelect');
const funcionarioLegajo = document.getElementById('funcionarioLegajo');
if (funcionarioSelect) {
fetch('inspecciones.php?action=get_funcionarios')
.then(response => response.json())
.then(data => {
if (data.success && data.usuarios.length > 0) {
data.usuarios.forEach(usuario => {
const option = document.createElement('option');
option.value = usuario.nombre_completo;
option.textContent = usuario.nombre_completo;
option.dataset.username = usuario.username;
option.dataset.email = usuario.email;
option.dataset.id = usuario.id;
funcionarioSelect.appendChild(option);
});
<?php if ($programacion_preload && !empty($programacion_preload['inspector_completo'])): ?>
const preloadNombre = '<?php echo addslashes($programacion_preload['inspector_completo']); ?>';
const preloadUsername = '<?php echo addslashes($programacion_preload['inspector_username'] ?? ''); ?>';
for (let i = 0; i < funcionarioSelect.options.length; i++) {
if (funcionarioSelect.options[i].value === preloadNombre ||
funcionarioSelect.options[i].dataset.username === preloadUsername) {
funcionarioSelect.selectedIndex = i;
funcionarioLegajo.value = preloadUsername;
funcionarioSelect.dispatchEvent(new Event('change'));
break;
}
}
<?php endif; ?>
}
})
.catch(error => console.error('Error al cargar funcionarios:', error));
funcionarioSelect.addEventListener('change', function() {
const selectedOption = this.options[this.selectedIndex];
if (selectedOption && selectedOption.dataset.username) {
funcionarioLegajo.value = selectedOption.dataset.username;
} else {
funcionarioLegajo.value = '';
}
});
}
const empresaSelect = document.getElementById('empresaSelect');
const sucursalSelect = document.getElementById('sucursalSelect');
const empresaNombre = document.getElementById('empresaNombre');
const empresaCuit = document.getElementById('empresaCuit');
const empresaDomicilio = document.getElementById('empresaDomicilio');
const lugarInput = document.getElementById('lugarInput');
const sucursalNombre = document.getElementById('sucursalNombre');
const sucursalDomicilio = document.getElementById('sucursalDomicilio');
const responsableNombreInput = document.getElementById('responsableNombreInput');
const responsableDniInput = document.getElementById('responsableDniInput');
if (empresaSelect) {
empresaSelect.addEventListener('change', function() {
const option = this.options[this.selectedIndex];
const empresaId = this.value;
empresaNombre.value = option.dataset.nombre || '';
empresaCuit.value = option.dataset.cuit || '';
empresaDomicilio.value = option.dataset.domicilio || '';
lugarInput.value = option.dataset.domicilio || '';
sucursalSelect.innerHTML = '<option value="">Casa Central</option>';
sucursalSelect.disabled = true;
sucursalNombre.value = '';
sucursalDomicilio.value = '';
if (responsableNombreInput) responsableNombreInput.value = '';
if (responsableDniInput) responsableDniInput.value = '';
if (empresaId) {
fetch(`inspecciones.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
if (data.success && data.sucursales.length > 0) {
sucursalSelect.disabled = false;
data.sucursales.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
option.dataset.domicilio = sucursal.domicilio || '';
option.dataset.localidad = sucursal.localidad || '';
option.dataset.jurisdiccion = sucursal.jurisdiccion || '';
option.dataset.responsableNombre = sucursal.responsable_completo || '';
option.dataset.responsableDni = sucursal.responsable_dni || '';
sucursalSelect.appendChild(option);
});
<?php if ($programacion_preload && !empty($programacion_preload['sucursal_id_preload'])): ?>
const preloadSucursalId = '<?php echo $programacion_preload['sucursal_id_preload']; ?>';
const preloadSucursalNombre = '<?php echo addslashes($programacion_preload['sucursal_nombre'] ?? ''); ?>';
for (let i = 0; i < sucursalSelect.options.length; i++) {
if (sucursalSelect.options[i].value == preloadSucursalId ||
sucursalSelect.options[i].textContent === preloadSucursalNombre) {
sucursalSelect.selectedIndex = i;
sucursalSelect.dispatchEvent(new Event('change'));
break;
}
}
<?php endif; ?>
if (pendingSucursalSelection) {
for (let i = 0; i < sucursalSelect.options.length; i++) {
if (sucursalSelect.options[i].value == pendingSucursalSelection) {
sucursalSelect.selectedIndex = i;
sucursalSelect.dispatchEvent(new Event('change'));
pendingSucursalSelection = null;
break;
}
}
}
} else {
sucursalSelect.disabled = true;
}
})
.catch(error => {
console.error('Error al cargar sucursales:', error);
sucursalSelect.disabled = true;
});
}
});
if (empresaSelect.value) {
empresaSelect.dispatchEvent(new Event('change'));
}
}
if (sucursalSelect) {
sucursalSelect.addEventListener('change', function() {
const option = this.options[this.selectedIndex];
if (this.value) {
sucursalNombre.value = option.textContent;
sucursalDomicilio.value = option.dataset.domicilio || '';
if (responsableNombreInput && option.dataset.responsableNombre) {
responsableNombreInput.value = option.dataset.responsableNombre;
}
if (responsableDniInput && option.dataset.responsableDni) {
responsableDniInput.value = option.dataset.responsableDni;
}
let domicilioCompleto = option.dataset.domicilio || '';
if (option.dataset.localidad) domicilioCompleto += ', ' + option.dataset.localidad;
if (option.dataset.jurisdiccion) domicilioCompleto += ', ' + option.dataset.jurisdiccion;
empresaDomicilio.value = domicilioCompleto;
lugarInput.value = domicilioCompleto;
} else {
sucursalNombre.value = '';
sucursalDomicilio.value = '';
empresaDomicilio.value = empresaSelect?.options[empresaSelect.selectedIndex]?.dataset.domicilio || '';
lugarInput.value = empresaDomicilio.value;
if (responsableNombreInput) responsableNombreInput.value = '';
if (responsableDniInput) responsableDniInput.value = '';
}
});
if (sucursalSelect.value) {
sucursalSelect.dispatchEvent(new Event('change'));
}
}
const modalVerInspeccion = new bootstrap.Modal(document.getElementById('modalVerInspeccion'));
const btnDescargarPdf = document.getElementById('btnDescargarPdf');
document.querySelectorAll('.btn-ver-inspeccion').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
const modalContent = document.getElementById('modalInspeccionContent');
modalContent.innerHTML = `
<div class="text-center py-5">
<div class="spinner-border text-primary" role="status"></div>
<p class="mt-2">Cargando datos...</p>
</div>
`;
modalVerInspeccion.show();
fetch(`inspecciones.php?action=get_inspeccion_detalle&id=${id}`)
.then(response => response.json())
.then(data => {
if (data.success) {
const ins = data.inspeccion;
const fechaInspeccion = ins.fecha_inspeccion ?
new Date(ins.fecha_inspeccion + 'T00:00:00').toLocaleDateString('es-AR') : '-';
const checkIcon = (val) => val == 1 ?
'<span class="badge bg-success"><i class="fas fa-check"></i></span>' :
'<span class="badge bg-danger"><i class="fas fa-times"></i></span>';
let html = `
<div class="row g-3">
<div class="col-md-12">
<div class="card bg-light border-primary">
<div class="card-header bg-primary text-white">
<h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>ACTA DE INFRACCIÓN N° ${ins.numero_acta || '______'} / ${ins.numero_anio || '______'}</h5>
<small>EMPRESAS DE SEGURIDAD PRIVADA - LEY I Nº 168 (Chubut) - LEY XV Nº 27 - Resolución ANMAC N° 149/24 - Ley 20429</small>
</div>
</div>
</div>
<div class="col-md-6">
<div class="card bg-light h-100">
<div class="card-header bg-primary text-white">
<h6 class="mb-0"><i class="fas fa-file-alt me-2"></i>Datos del Acta</h6>
</div>
<div class="card-body">
<p class="mb-2"><strong>Fecha:</strong> ${fechaInspeccion}</p>
<p class="mb-2"><strong>Hora:</strong> ${ins.hora_inspeccion || '-'}</p>
<p class="mb-2"><strong>Lugar:</strong> ${ins.lugar || '-'}</p>
<p class="mb-2"><strong>Funcionario:</strong> ${ins.funcionario_nombre || '-'}</p>
<p class="mb-2"><strong>Legajo:</strong> ${ins.funcionario_legajo || '-'}</p>
<p class="mb-2"><strong>Tipo:</strong> ${ins.programacion_id ? '<span class="badge badge-programada">Programada</span>' : '<span class="badge badge-no-programada">Espontánea</span>'}</p>
${ins.testigos_nombres ? `<p class="mb-2"><strong>Testigos:</strong> ${ins.testigos_nombres}</p>` : ''}
</div>
</div>
</div>
<div class="col-md-6">
<div class="card bg-light h-100">
<div class="card-header bg-primary text-white">
<h6 class="mb-0"><i class="fas fa-building me-2"></i>Empresa Inspeccionada</h6>
</div>
<div class="card-body">
<p class="mb-2"><strong>Empresa:</strong> ${ins.empresa_nombre || '-'}</p>
<p class="mb-2"><strong>CUIT:</strong> ${ins.empresa_cuit || '-'}</p>
<p class="mb-2"><strong>Sucursal:</strong> ${ins.sucursal_nombre || 'Casa Central'}</p>
<p class="mb-2"><strong>Responsable:</strong> ${ins.responsable_nombre || '-'}</p>
<p class="mb-2"><strong>DNI:</strong> ${ins.responsable_dni || '-'}</p>
</div>
</div>
</div>
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-info text-white">
<h6 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Notificación y Plazos</h6>
</div>
<div class="card-body">
<div class="row">
<div class="col-md-6">
<p class="mb-1"><strong>Consentimiento:</strong><br>
${ins.consentimiento == 1 ? '<span class="badge bg-success">SI</span>' : '<span class="badge bg-danger">NO</span>'}
</p>
</div>
<div class="col-md-6">
<p class="mb-1"><strong>Infracciones:</strong><br>
${ins.tiene_infracciones == 1 ? '<span class="badge bg-danger">Sí</span>' : '<span class="badge bg-success">No</span>'}
</p>
</div>
</div>
<div class="legal-text mt-3">
<strong>NOTIFICACIÓN:</strong> Se deja constancia de haber informado las causales de inspección conforme Art. 2º Ley I Nº 168.
</div>
</div>
</div>
</div>
${ins.observaciones_generales ? `
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-warning text-dark">
<h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Observaciones Generales</h6>
</div>
<div class="card-body">
<p class="mb-0">${ins.observaciones_generales}</p>
</div>
</div>
</div>
` : ''}
<!-- ✅ SECCIÓN DE CONSENTIMIENTO Y FIRMAS -->
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-secondary text-white">
<h6 class="mb-0"><i class="fas fa-signature me-2"></i>Consentimiento y Firmas</h6>
</div>
<div class="card-body">
<div class="legal-text">
<strong>CONSENTIMIENTO:</strong> El responsable ${ins.consentimiento == 1 ? 'SI' : 'NO'} presta consentimiento para la inspección.<br>
${ins.consentimiento == 0 ? '<small class="text-danger"><i class="fas fa-exclamation-triangle"></i> En caso de negativa de consentimiento o de firma, se deja constancia de la presencia de los testigos consignados, quienes dan fe del acto de inspección realizado.</small>' : ''}
</div>
<div class="signature-section">
<div class="row">
<div class="col-md-6">
<p class="mb-1"><strong>FUNCIONARIO ACTUANTE</strong></p>
<p class="text-muted small">Firma y Aclaración</p>
</div>
<div class="col-md-6">
<p class="mb-1"><strong>RESPONSABLE PRESENTE / TESTIGOS</strong></p>
<p class="text-muted small">Firma y Aclaración</p>
</div>
</div>
</div>
<div class="legal-text mt-3">
<small><strong>NOTA:</strong> La presente acta se labra en dos (2) ejemplares de igual tenor y a un solo efecto, entregándose una copia al responsable inspeccionado y archivándose el original en la Autoridad de Aplicación.</small>
</div>
</div>
</div>
</div>
</div>
`;
modalContent.innerHTML = html;
if (ins.archivo_pdf) {
btnDescargarPdf.href = `?descargar_pdf=1&id=${id}`;
btnDescargarPdf.style.display = 'inline-block';
} else {
btnDescargarPdf.style.display = 'none';
}
} else {
modalContent.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>${data.error || 'Error al cargar los datos'}</div>`;
}
})
.catch(error => {
modalContent.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error de conexión: ${error.message}</div>`;
});
});
});
document.querySelectorAll('.btn-eliminar').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
Swal.fire({
title: '¿Eliminar inspección?',
text: 'Esta acción desactivará el registro. ¿Estás seguro?',
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#e74c3c',
cancelButtonColor: '#95a5a6',
confirmButtonText: '<i class="fas fa-trash me-2"></i>Sí, eliminar',
cancelButtonText: '<i class="fas fa-times me-2"></i>Cancelar',
reverseButtons: true
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'inspecciones.php';
const input1 = document.createElement('input');
input1.type = 'hidden';
input1.name = 'eliminar_inspeccion';
input1.value = '1';
const input2 = document.createElement('input');
input2.type = 'hidden';
input2.name = 'id';
input2.value = id;
form.appendChild(input1);
form.appendChild(input2);
document.body.appendChild(form);
form.submit();
}
});
});
});
setTimeout(() => {
document.querySelectorAll('.alert').forEach(alert => {
new bootstrap.Alert(alert).close();
});
}, 5000);
});
</script>
</body>
</html>