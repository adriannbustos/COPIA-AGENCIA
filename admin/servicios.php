<?php
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
$current_page = 'servicios';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ==================== FUNCIÓN CON CACHE PARA VERIFICAR COLUMNAS (O4) ====================
function columnaExisteCache($conn, $tabla, $columna) {
    static $cache = [];
    $key = "{$tabla}.{$columna}";
    if (!isset($cache[$key])) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tabla, $columna]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    }
    return $cache[$key];
}

// ==================== VERIFICAR COLUMNAS CON CACHE (O4) ====================
$hasPDF = columnaExisteCache($conn, 'servicios', 'pdf_file');
$hasPrioridad = columnaExisteCache($conn, 'servicios', 'prioridad');
$hasEstadoUpdated = columnaExisteCache($conn, 'servicios', 'estado_updated_at');
$hasAprobadoPor = columnaExisteCache($conn, 'servicios', 'aprobado_por');

// ==================== PROCESAR FORMULARIOS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
$action = $_POST['action'];
try {
// ==================== APROBAR SERVICIO ====================
if ($action === 'approve') {
$id = $_POST['id'] ?? null;
if (!$id) {
throw new Exception('ID de servicio no válido');
}
$stmt = $conn->prepare("SELECT id, nombre, estado FROM servicios WHERE id = ?");
$stmt->execute([$id]);
$servicio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$servicio) {
throw new Exception('Servicio no encontrado');
}
if ($servicio['estado'] !== 'pendiente') {
throw new Exception('El servicio ya no está en estado pendiente');
}
// B1: Validar empresa_id contra BD si el servicio tiene empresa asociada
if (!empty($servicio['empresa_id'])) {
    $stmt_validar = $conn->prepare("SELECT id FROM empresas WHERE id = ? AND activo = TRUE LIMIT 1");
    $stmt_validar->execute([$servicio['empresa_id']]);
    if (!$stmt_validar->fetch()) {
        throw new Exception('La empresa asociada a este servicio no es válida o está inactiva');
    }
}
$datos_antiguos = [
'estado' => $servicio['estado'],
'updated_at' => date('Y-m-d H:i:s')
];
$stmt = $conn->prepare("UPDATE servicios SET estado = 'activo', estado_updated_at = NOW(), aprobado_por = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$user['id'], $id]);
$datos_nuevos = [
'estado' => 'activo',
'updated_at' => date('Y-m-d H:i:s')
];
$cambios = obtenerCambios($datos_antiguos, $datos_nuevos);
logAuditoria($conn, 'servicio_aprobado', 'servicios', $id, [
'nombre' => $servicio['nombre'],
'estado_anterior' => 'pendiente',
'estado_nuevo' => 'activo',
'cambios' => $cambios,
'usuario_aprobador' => $user['nombre'] ?? 'Desconocido',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
]);
$_SESSION['success'] = 'Servicio aprobado correctamente. Estado cambiado a ACTIVO';
header('Location: servicios.php');
exit;
}
// ==================== DENEGAR/CANCELAR SERVICIO ====================
if ($action === 'deny') {
$id = $_POST['id'] ?? null;
if (!$id) {
throw new Exception('ID de servicio no válido');
}
$stmt = $conn->prepare("SELECT id, nombre, estado FROM servicios WHERE id = ?");
$stmt->execute([$id]);
$servicio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$servicio) {
throw new Exception('Servicio no encontrado');
}
if ($servicio['estado'] !== 'pendiente') {
throw new Exception('El servicio ya no está en estado pendiente');
}
// B1: Validar empresa_id contra BD
if (!empty($servicio['empresa_id'])) {
    $stmt_validar = $conn->prepare("SELECT id FROM empresas WHERE id = ? AND activo = TRUE LIMIT 1");
    $stmt_validar->execute([$servicio['empresa_id']]);
    if (!$stmt_validar->fetch()) {
        throw new Exception('La empresa asociada a este servicio no es válida o está inactiva');
    }
}
$datos_antiguos = [
'estado' => $servicio['estado'],
'updated_at' => date('Y-m-d H:i:s')
];
$stmt = $conn->prepare("UPDATE servicios SET estado = 'cancelado', estado_updated_at = NOW(), aprobado_por = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$user['id'], $id]);
$datos_nuevos = [
'estado' => 'cancelado',
'updated_at' => date('Y-m-d H:i:s')
];
$cambios = obtenerCambios($datos_antiguos, $datos_nuevos);
logAuditoria($conn, 'servicio_denegado', 'servicios', $id, [
'nombre' => $servicio['nombre'],
'estado_anterior' => 'pendiente',
'estado_nuevo' => 'cancelado',
'cambios' => $cambios,
'usuario_aprobador' => $user['nombre'] ?? 'Desconocido',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
]);
$_SESSION['success'] = 'Servicio denegado correctamente. Estado cambiado a CANCELADO';
header('Location: servicios.php');
exit;
}
// ==================== CREAR SERVICIO ====================
if ($action === 'create') {
$nombre = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$tipo = $_POST['tipo'] ?? 'vigilancia';
$prioridad = isset($_POST['prioridad']) && in_array($_POST['prioridad'], ['baja', 'media', 'alta']) ? $_POST['prioridad'] : 'media';
$estado = $_POST['estado'] ?? 'pendiente';
$fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
$fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
$domicilio = trim($_POST['domicilio'] ?? '');
$jurisdiccion = trim($_POST['jurisdiccion'] ?? '');

// B1: Validar empresa_id contra BD si se proporciona
if ($empresa_id) {
    $stmt_validar = $conn->prepare("SELECT id FROM empresas WHERE id = ? AND activo = TRUE LIMIT 1");
    $stmt_validar->execute([$empresa_id]);
    if (!$stmt_validar->fetch()) {
        throw new Exception('La empresa seleccionada no es válida o está inactiva');
    }
}

$columnCheck = columnaExisteCache($conn, 'servicios', 'hora_inicio');
$hasHorarios = !empty($columnCheck);
$hora_inicio = $hasHorarios && !empty($_POST['hora_inicio']) ? $_POST['hora_inicio'] : null;
$hora_fin = $hasHorarios && !empty($_POST['hora_fin']) ? $_POST['hora_fin'] : null;
$dias_semana = $hasHorarios && isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : null;
$personal_id = $hasHorarios && !empty($_POST['personal_id']) ? (int)$_POST['personal_id'] : null;

// ==================== SUBIDA DE PDF CON VALIDACIÓN MIME REAL (A3) ====================
$pdf_file = null;
if ($hasPDF && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
$allowed_types = ['application/pdf'];
$max_size = 5 * 1024 * 1024;
// A3: Validación real del MIME type usando finfo_file en lugar de $_FILES['type']
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['pdf_file']['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowed_types)) {
throw new Exception('Solo se permiten archivos PDF');
}
if ($_FILES['pdf_file']['size'] > $max_size) {
throw new Exception('El PDF no puede superar los 5MB');
}
$upload_dir = '../uploads/servicios/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
$extension = pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION);
$pdf_file = time() . '_' . uniqid() . '.' . $extension;
$file_path = $upload_dir . $pdf_file;
if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $file_path)) {
throw new Exception('Error al subir el archivo PDF');
}
}

// ✅ INSERT
if ($hasHorarios) {
if ($hasPDF && $pdf_file) {
$stmt = $conn->prepare("
INSERT INTO servicios (
nombre, descripcion, tipo, prioridad, estado,
fecha_inicio, fecha_fin, hora_inicio, hora_fin,
dias_semana, personal_id, empresa_id, sucursal_id,
domicilio, jurisdiccion, pdf_file, estado_updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
$nombre, $descripcion, $tipo, $prioridad, $estado,
$fecha_inicio, $fecha_fin, $hora_inicio, $hora_fin,
$dias_semana, $personal_id, $empresa_id, $sucursal_id,
$domicilio, $jurisdiccion, $pdf_file
]);
} else {
$stmt = $conn->prepare("
INSERT INTO servicios (
nombre, descripcion, tipo, prioridad, estado,
fecha_inicio, fecha_fin, hora_inicio, hora_fin,
dias_semana, personal_id, empresa_id, sucursal_id,
domicilio, jurisdiccion, estado_updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
$nombre, $descripcion, $tipo, $prioridad, $estado,
$fecha_inicio, $fecha_fin, $hora_inicio, $hora_fin,
$dias_semana, $personal_id, $empresa_id, $sucursal_id,
$domicilio, $jurisdiccion
]);
}
} else {
if ($hasPDF && $pdf_file) {
$stmt = $conn->prepare("
INSERT INTO servicios (
nombre, descripcion, tipo, prioridad, estado,
fecha_inicio, fecha_fin, empresa_id, sucursal_id,
domicilio, jurisdiccion, pdf_file, estado_updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
$nombre, $descripcion, $tipo, $prioridad, $estado,
$fecha_inicio, $fecha_fin, $empresa_id, $sucursal_id,
$domicilio, $jurisdiccion, $pdf_file
]);
} else {
$stmt = $conn->prepare("
INSERT INTO servicios (
nombre, descripcion, tipo, prioridad, estado,
fecha_inicio, fecha_fin, empresa_id, sucursal_id,
domicilio, jurisdiccion, estado_updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
$nombre, $descripcion, $tipo, $prioridad, $estado,
$fecha_inicio, $fecha_fin, $empresa_id, $sucursal_id,
$domicilio, $jurisdiccion
]);
}
}
$new_id = $conn->lastInsertId();
logAuditoria($conn, 'servicio_creado', 'servicios', $new_id, [
'nombre' => $nombre,
'tipo' => $tipo,
'prioridad' => $prioridad,
'estado' => $estado,
'empresa_id' => $empresa_id,
'sucursal_id' => $sucursal_id,
'pdf_subido' => $pdf_file ? 'Sí' : 'No',
'pdf_nombre' => $pdf_file ?? null,
'fecha_inicio' => $fecha_inicio,
'fecha_fin' => $fecha_fin,
'usuario_creador' => $user['nombre'] ?? 'Desconocido',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
]);
$_SESSION['success'] = 'Servicio creado correctamente' . ($pdf_file ? ' con PDF' : '');

// ==================== ACTUALIZAR SERVICIO ====================
} elseif ($action === 'update') {
$id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : null;
if (!$id || $id <= 0) {
throw new Exception('ID de servicio no válido: ' . ($_POST['id'] ?? 'null'));
}
// O3: Reemplazar SELECT * por columnas explícitas
$stmt = $conn->prepare("SELECT id, nombre, descripcion, tipo, prioridad, estado, fecha_inicio, fecha_fin, hora_inicio, hora_fin, dias_semana, personal_id, empresa_id, sucursal_id, domicilio, jurisdiccion, pdf_file, estado_updated_at, aprobado_por, created_at, updated_at FROM servicios WHERE id = ?");
$stmt->execute([$id]);
$servicio_anterior = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$servicio_anterior) {
throw new Exception('Servicio no encontrado con ID: ' . $id);
}
$nombre = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$tipo = $_POST['tipo'] ?? 'vigilancia';
$prioridad = isset($_POST['prioridad']) && in_array($_POST['prioridad'], ['baja', 'media', 'alta']) ? $_POST['prioridad'] : 'media';
$estado = $_POST['estado'] ?? 'pendiente';
$fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
$fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
$domicilio = trim($_POST['domicilio'] ?? '');
$jurisdiccion = trim($_POST['jurisdiccion'] ?? '');

// B1: Validar empresa_id contra BD si se proporciona y es diferente al anterior
if ($empresa_id && $empresa_id !== ($servicio_anterior['empresa_id'] ?? null)) {
    $stmt_validar = $conn->prepare("SELECT id FROM empresas WHERE id = ? AND activo = TRUE LIMIT 1");
    $stmt_validar->execute([$empresa_id]);
    if (!$stmt_validar->fetch()) {
        throw new Exception('La empresa seleccionada no es válida o está inactiva');
    }
}

$columnCheck = columnaExisteCache($conn, 'servicios', 'hora_inicio');
$hasHorarios = !empty($columnCheck);
$hora_inicio = $hasHorarios && !empty($_POST['hora_inicio']) ? $_POST['hora_inicio'] : null;
$hora_fin = $hasHorarios && !empty($_POST['hora_fin']) ? $_POST['hora_fin'] : null;
$dias_semana = $hasHorarios && isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : null;
$personal_id = $hasHorarios && !empty($_POST['personal_id']) ? (int)$_POST['personal_id'] : null;

// ==================== SUBIDA DE PDF CON VALIDACIÓN MIME REAL (A3) ====================
$pdf_file = null;
$pdf_update = "";
$pdf_anterior = $servicio_anterior['pdf_file'] ?? null;
if ($hasPDF && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
$allowed_types = ['application/pdf'];
$max_size = 5 * 1024 * 1024;
// A3: Validación real del MIME type usando finfo_file
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['pdf_file']['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowed_types)) {
throw new Exception('Solo se permiten archivos PDF');
}
if ($_FILES['pdf_file']['size'] > $max_size) {
throw new Exception('El PDF no puede superar los 5MB');
}
$upload_dir = '../uploads/servicios/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
if ($pdf_anterior && file_exists($upload_dir . $pdf_anterior)) {
unlink($upload_dir . $pdf_anterior);
}
$extension = pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION);
$pdf_file = time() . '_' . uniqid() . '.' . $extension;
$file_path = $upload_dir . $pdf_file;
if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $file_path)) {
throw new Exception('Error al subir el archivo PDF');
}
$pdf_update = ", pdf_file = ?";
}
$estado_anterior = $servicio_anterior['estado'] ?? 'pendiente';
$estado_nuevo = $estado;
$estado_update = ($estado_anterior !== $estado_nuevo) ? ", estado_updated_at = NOW()" : "";
// ✅ UPDATE
if ($hasHorarios) {
$stmt = $conn->prepare("
UPDATE servicios SET
nombre = ?, descripcion = ?, tipo = ?, prioridad = ?, estado = ?,
fecha_inicio = ?, fecha_fin = ?, hora_inicio = ?, hora_fin = ?,
dias_semana = ?, personal_id = ?, empresa_id = ?, sucursal_id = ?,
domicilio = ?, jurisdiccion = ?, updated_at = NOW()
$pdf_update
$estado_update
WHERE id = ?
");
$params = [
$nombre, $descripcion, $tipo, $prioridad, $estado,
$fecha_inicio, $fecha_fin, $hora_inicio, $hora_fin,
$dias_semana, $personal_id, $empresa_id, $sucursal_id,
$domicilio, $jurisdiccion
];
if ($pdf_file) {
$params[] = $pdf_file;
}
$params[] = $id;
$stmt->execute($params);
} else {
$stmt = $conn->prepare("
UPDATE servicios SET
nombre = ?, descripcion = ?, tipo = ?, prioridad = ?, estado = ?,
fecha_inicio = ?, fecha_fin = ?, empresa_id = ?, sucursal_id = ?,
domicilio = ?, jurisdiccion = ?, updated_at = NOW()
$pdf_update
$estado_update
WHERE id = ?
");
$params = [
$nombre, $descripcion, $tipo, $prioridad, $estado,
$fecha_inicio, $fecha_fin, $empresa_id, $sucursal_id,
$domicilio, $jurisdiccion
];
if ($pdf_file) {
$params[] = $pdf_file;
}
$params[] = $id;
$stmt->execute($params);
}
// O3: Reemplazar SELECT * por columnas explícitas
$stmt = $conn->prepare("SELECT id, nombre, descripcion, tipo, prioridad, estado, fecha_inicio, fecha_fin, hora_inicio, hora_fin, dias_semana, personal_id, empresa_id, sucursal_id, domicilio, jurisdiccion, pdf_file, estado_updated_at, aprobado_por, created_at, updated_at FROM servicios WHERE id = ?");
$stmt->execute([$id]);
$servicio_nuevo = $stmt->fetch(PDO::FETCH_ASSOC);
$excluir = ['id', 'created_at', 'updated_at'];
$datos_antiguos = array_diff_key($servicio_anterior, array_flip($excluir));
$datos_nuevos = array_diff_key($servicio_nuevo, array_flip($excluir));
$cambios = obtenerCambios($datos_antiguos, $datos_nuevos, $excluir);
logAuditoria($conn, 'servicio_actualizado', 'servicios', $id, [
'nombre' => $nombre,
'pdf_actualizado' => $pdf_file ? 'Sí' : 'No',
'pdf_anterior' => $pdf_anterior,
'pdf_nuevo' => $pdf_file,
'cambios_detectados' => $cambios,
'campos_modificados' => array_keys($cambios),
'total_cambios' => count($cambios),
'estado_cambiado' => $estado_anterior !== $estado_nuevo,
'usuario_modificador' => $user['nombre'] ?? 'Desconocido',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
]);
$_SESSION['success'] = 'Servicio actualizado correctamente' . ($pdf_file ? ' con nuevo PDF' : '');

// ==================== ELIMINAR SERVICIO (BORRADO LÓGICO) ====================
} elseif ($action === 'delete') {
$id = $_POST['id'] ?? $_GET['id'] ?? null;
if (!$id) {
throw new Exception('ID de servicio no válido');
}
// O3: Reemplazar SELECT * por columnas explícitas
$stmt = $conn->prepare("SELECT id, nombre, tipo, estado, empresa_id, sucursal_id, pdf_file FROM servicios WHERE id = ?");
$stmt->execute([$id]);
$servicio_a_eliminar = $stmt->fetch(PDO::FETCH_ASSOC);
$pdf_eliminado = false;
if ($hasPDF && $servicio_a_eliminar) {
$pdf_file = $servicio_a_eliminar['pdf_file'] ?? null;
if ($pdf_file && file_exists('../uploads/servicios/' . $pdf_file)) {
unlink('../uploads/servicios/' . $pdf_file);
$pdf_eliminado = true;
}
}
// DELETE → UPDATE: Borrado lógico con columna activo
$stmt = $conn->prepare("UPDATE servicios SET activo = 0 WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'servicio_eliminado', 'servicios', $id, [
'nombre' => $servicio_a_eliminar['nombre'] ?? 'Desconocido',
'tipo' => $servicio_a_eliminar['tipo'] ?? 'N/A',
'estado' => $servicio_a_eliminar['estado'] ?? 'N/A',
'empresa_id' => $servicio_a_eliminar['empresa_id'] ?? null,
'sucursal_id' => $servicio_a_eliminar['sucursal_id'] ?? null,
'pdf_eliminado' => $pdf_eliminado ? 'Sí' : 'No',
'pdf_nombre' => $servicio_a_eliminar['pdf_file'] ?? null,
'usuario_eliminador' => $user['nombre'] ?? 'Desconocido',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
'motivo' => 'Eliminación manual desde interfaz (borrado lógico)'
]);
$_SESSION['success'] = 'Servicio eliminado correctamente';
}
header('Location: servicios.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error: ' . $e->getMessage();
logAuditoria($conn, 'error_servicios', 'servicios', null, [
'accion' => $action ?? 'desconocida',
'error' => $e->getMessage(),
'usuario' => $user['nombre'] ?? 'Desconocido',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
]);
header('Location: servicios.php');
exit;
}
}
// ==================== EXPORTAR FILTROS A PDF ====================
if (isset($_GET['action']) && $_GET['action'] === 'export_pdf') {
$search_servicio = $_GET['search_servicio'] ?? '';
$search_empresa = $_GET['search_empresa'] ?? '';
$search_sucursal = $_GET['search_sucursal'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_tipo = $_GET['search_tipo'] ?? 'todos';
$where_clauses = [];
$params = [];
if (!empty($search_servicio)) {
// A6: Escapar caracteres wildcard en LIKE
$search_servicio_escaped = addcslashes($search_servicio, '%_');
$where_clauses[] = "s.nombre LIKE ?";
$params[] = '%' . $search_servicio_escaped . '%';
}
if (!empty($search_empresa)) {
$where_clauses[] = "s.empresa_id = ?";
$params[] = $search_empresa;
}
if (!empty($search_sucursal)) {
$where_clauses[] = "s.sucursal_id = ?";
$params[] = $search_sucursal;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "s.estado = ?";
$params[] = $search_estado;
}
if ($search_tipo !== 'todos') {
$where_clauses[] = "s.tipo = ?";
$params[] = $search_tipo;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$columnCheck = columnaExisteCache($conn, 'servicios', 'hora_inicio');
$hasHorarios = !empty($columnCheck);
if ($hasHorarios) {
$stmt = $conn->prepare("
SELECT
s.id, s.nombre, s.descripcion, s.tipo, s.prioridad, s.estado,
s.fecha_inicio, s.fecha_fin, s.hora_inicio, s.hora_fin,
s.dias_semana, s.empresa_id, s.sucursal_id,
s.domicilio, s.jurisdiccion, " . ($hasPDF ? "s.pdf_file," : "") . "
s.created_at, s.updated_at,
e.nombre as empresa_nombre,
suc.nombre as sucursal_nombre
FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
$where_sql
ORDER BY s.created_at DESC
");
} else {
$stmt = $conn->prepare("
SELECT
s.id, s.nombre, s.descripcion, s.tipo, s.prioridad, s.estado,
s.fecha_inicio, s.fecha_fin, s.empresa_id, s.sucursal_id,
s.domicilio, s.jurisdiccion, " . ($hasPDF ? "s.pdf_file," : "") . "
s.created_at, s.updated_at,
e.nombre as empresa_nombre,
suc.nombre as sucursal_nombre,
NULL as hora_inicio, NULL as hora_fin,
NULL as dias_semana
FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
$where_sql
ORDER BY s.created_at DESC
");
}
$stmt->execute($params);
$servicios_export = $stmt->fetchAll(PDO::FETCH_ASSOC);
require_once('../libs/tcpdf/tcpdf.php');
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Sistema de Seguridad');
$pdf->SetAuthor($user['nombre'] ?? 'Usuario');
$pdf->SetTitle('Listado de Servicios - Exportación');
$pdf->SetSubject('Exportación de servicios filtrados');
$pdf->SetMargins(15, 25, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'LISTADO DE SERVICIOS', 0, 1, 'C');
$pdf->SetFont('helvetica', 'I', 9);
$pdf->Cell(0, 6, 'Fecha de exportación: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
$pdf->Cell(0, 6, 'Usuario: ' . ($user['nombre'] ?? 'Desconocido'), 0, 1, 'R');
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 8, 'Filtros Aplicados:', 1, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$filtros_texto = [];
if (!empty($search_servicio)) $filtros_texto[] = "Servicio: $search_servicio";
if (!empty($search_empresa)) {
$stmt_emp = $conn->prepare("SELECT nombre FROM empresas WHERE id = ?");
$stmt_emp->execute([$search_empresa]);
$emp_nom = $stmt_emp->fetchColumn();
if ($emp_nom) $filtros_texto[] = "Empresa: $emp_nom";
}
if (!empty($search_sucursal)) {
$stmt_suc = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ?");
$stmt_suc->execute([$search_sucursal]);
$suc_nom = $stmt_suc->fetchColumn();
if ($suc_nom) $filtros_texto[] = "Sucursal: $suc_nom";
}
if ($search_estado !== 'todos') $filtros_texto[] = "Estado: " . ucfirst($search_estado);
if ($search_tipo !== 'todos') $filtros_texto[] = "Tipo: " . ucfirst($search_tipo);
$pdf->Cell(0, 6, (!empty($filtros_texto) ? implode(' | ', $filtros_texto) : 'Sin filtros aplicados'), 1, 1, 'L');
$pdf->Ln(3);
$pdf->SetFont('helvetica', 'B', 8);
$header_style = ['fill' => true, 'bgcolor' => [220, 220, 220]];
$pdf->Cell(35, 7, 'Servicio', 1, 0, 'L', $header_style);
$pdf->Cell(30, 7, 'Empresa', 1, 0, 'L', $header_style);
$pdf->Cell(25, 7, 'Sucursal', 1, 0, 'L', $header_style);
$pdf->Cell(20, 7, 'Tipo', 1, 0, 'C', $header_style);
$pdf->Cell(15, 7, 'Prior.', 1, 0, 'C', $header_style);
$pdf->Cell(20, 7, 'Estado', 1, 0, 'C', $header_style);
$pdf->Cell(25, 7, 'Fecha Inicio', 1, 1, 'C', $header_style);
$pdf->SetFont('helvetica', '', 7.5);
foreach ($servicios_export as $serv) {
$pdf->Cell(35, 6, mb_strimwidth($serv['nombre'] ?? '', 0, 30, '...'), 1, 0, 'L');
$pdf->Cell(30, 6, mb_strimwidth($serv['empresa_nombre'] ?? '', 0, 25, '...'), 1, 0, 'L');
$pdf->Cell(25, 6, mb_strimwidth($serv['sucursal_nombre'] ?? '', 0, 20, '...'), 1, 0, 'L');
$pdf->Cell(20, 6, ucfirst($serv['tipo'] ?? ''), 1, 0, 'C');
$prioridad_badge = $serv['prioridad'] === 'alta' ? 'A' : ($serv['prioridad'] === 'media' ? 'M' : 'B');
$pdf->Cell(15, 6, $prioridad_badge, 1, 0, 'C');
$estado_text = ucfirst(substr($serv['estado'] ?? 'pendiente', 0, 3));
$pdf->Cell(20, 6, $estado_text, 1, 0, 'C');
$fecha_ini = !empty($serv['fecha_inicio']) ? date('d/m/Y', strtotime($serv['fecha_inicio'])) : 'N/A';
$pdf->Cell(25, 6, $fecha_ini, 1, 1, 'C');
}
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 6, "Total de registros: " . count($servicios_export), 0, 1, 'R');
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(0, 5, 'Documento generado automáticamente por el Sistema de Gestión de Servicios - Ley 19.549', 0, 1, 'C');
$pdf->Output('servicios_filtrados_' . date('Ymd_His') . '.pdf', 'D');
exit;
}
// ==================== VER HISTORIAL DE AUDITORÍA DE UN SERVICIO ====================
$historial_servicio = null;
if (isset($_GET['ver_historial']) && !empty($_GET['id'])) {
$servicio_id = (int)$_GET['id'];
$historial_servicio = obtenerRegistrosRelacionados($conn, 'servicios', $servicio_id, 100);
}
// ==================== FILTROS DE BÚSQUEDA ====================
$search_servicio = $_GET['search_servicio'] ?? '';
$search_empresa = $_GET['search_empresa'] ?? '';
$search_sucursal = $_GET['search_sucursal'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_tipo = $_GET['search_tipo'] ?? 'todos';
// ==================== PAGINACIÓN Y ORDENAMIENTO ====================
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;
$allowed_order_columns = [
'nombre', 'empresa_nombre', 'sucursal_nombre', 'domicilio', 'jurisdiccion',
'tipo', 'prioridad', 'estado', 'fecha_inicio', 'hora_inicio',
'personal_nombre', 'created_at', 'updated_at', 'estado_updated_at'
];
$order_column = isset($_GET['order']) && in_array($_GET['order'], $allowed_order_columns) ? $_GET['order'] : 'created_at';
$order_direction = isset($_GET['direction']) && strtoupper($_GET['direction']) === 'DESC' ? 'DESC' : 'ASC';
function getOrderIcon($column, $current_order, $current_direction) {
if ($column !== $current_order) return '<i class="fas fa-sort text-muted opacity-50"></i>';
return $current_direction === 'ASC' ? '<i class="fas fa-sort-up text-primary"></i>' : '<i class="fas fa-sort-down text-primary"></i>';
}
// ==================== OBTENER DATOS ====================
try {
$columnCheck = columnaExisteCache($conn, 'servicios', 'hora_inicio');
$hasHorarios = !empty($columnCheck);
$where_clauses = [];
$params = [];
if (!empty($search_servicio)) {
// A6: Escapar caracteres wildcard en LIKE
$search_servicio_escaped = addcslashes($search_servicio, '%_');
$where_clauses[] = "s.nombre LIKE ?";
$params[] = '%' . $search_servicio_escaped . '%';
}
if (!empty($search_empresa)) {
$where_clauses[] = "s.empresa_id = ?";
$params[] = $search_empresa;
}
if (!empty($search_sucursal)) {
$where_clauses[] = "s.sucursal_id = ?";
$params[] = $search_sucursal;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "s.estado = ?";
$params[] = $search_estado;
}
if ($search_tipo !== 'todos') {
$where_clauses[] = "s.tipo = ?";
$params[] = $search_tipo;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$count_stmt = $conn->prepare("
SELECT COUNT(DISTINCT s.id) as total
FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
WHERE s.activo = TRUE
$where_sql
");
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);
if ($hasHorarios) {
$stmt = $conn->prepare("
SELECT
s.id, s.nombre, s.descripcion, s.tipo, s.prioridad, s.estado,
s.estado_updated_at, s.aprobado_por,
s.fecha_inicio, s.fecha_fin, s.hora_inicio, s.hora_fin,
s.dias_semana, s.personal_id, s.empresa_id, s.sucursal_id,
s.domicilio, s.jurisdiccion, " . ($hasPDF ? "s.pdf_file," : "") . "
s.created_at, s.updated_at,
e.nombre as empresa_nombre,
suc.nombre as sucursal_nombre,
p.nombre as personal_nombre,
u.username as aprobador_username
FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
LEFT JOIN personal p ON s.personal_id = p.id
LEFT JOIN usuarios u ON s.aprobado_por = u.id
WHERE s.activo = TRUE
$where_sql
ORDER BY $order_column $order_direction
LIMIT $records_per_page OFFSET $offset
");
} else {
$stmt = $conn->prepare("
SELECT
s.id, s.nombre, s.descripcion, s.tipo, s.prioridad, s.estado,
s.estado_updated_at, s.aprobado_por,
s.fecha_inicio, s.fecha_fin, s.empresa_id, s.sucursal_id,
s.domicilio, s.jurisdiccion, " . ($hasPDF ? "s.pdf_file," : "") . "
s.created_at, s.updated_at,
e.nombre as empresa_nombre,
suc.nombre as sucursal_nombre,
NULL as hora_inicio, NULL as hora_fin,
NULL as dias_semana, NULL as personal_id,
NULL as personal_nombre,
u.username as aprobador_username
FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
LEFT JOIN usuarios u ON s.aprobado_por = u.id
WHERE s.activo = TRUE
$where_sql
ORDER BY $order_column $order_direction
LIMIT $records_per_page OFFSET $offset
");
}
$stmt->execute($params);
$servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
logAuditoria($conn, 'listado_visualizado', 'servicios', null, [
'total_registros' => count($servicios),
'filtros' => [
'servicio' => $search_servicio,
'empresa' => $search_empresa,
'sucursal' => $search_sucursal,
'estado' => $search_estado,
'tipo' => $search_tipo
],
'pagina' => $page,
'usuario' => $user['nombre'] ?? 'Desconocido'
]);
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_empresas = count($empresas);
$stmt = $conn->query("SELECT id, nombre, empresa_id FROM sucursales WHERE activo = TRUE ORDER BY nombre");
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_sucursales = count($sucursales);
$sucursales_por_empresa = [];
foreach ($sucursales as $sucursal) {
$empresa_id = $sucursal['empresa_id'] ?? 0;
if (!isset($sucursales_por_empresa[$empresa_id])) {
$sucursales_por_empresa[$empresa_id] = [];
}
$sucursales_por_empresa[$empresa_id][] = $sucursal;
}
$stmt = $conn->query("SELECT id, nombre, cargo FROM personal WHERE activo = TRUE ORDER BY nombre");
$personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->query("SELECT COUNT(*) as total FROM servicios WHERE activo = TRUE");
$total_servicios = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM servicios WHERE estado = 'activo' AND activo = TRUE");
$servicios_activos = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM servicios WHERE estado = 'completado' AND activo = TRUE");
$servicios_completados = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM servicios WHERE estado = 'pendiente' AND activo = TRUE");
$servicios_pendientes = $stmt->fetch()['total'];
} catch (PDOException $e) {
$error = "Error al cargar los datos: " . $e->getMessage();
$servicios = [];
$empresas = [];
$sucursales = [];
$personal_list = [];
$total_empresas = 0;
$total_sucursales = 0;
$total_servicios = 0;
$servicios_activos = 0;
$servicios_completados = 0;
$servicios_pendientes = 0;
$hasHorarios = false;
$hasPDF = false;
$hasPrioridad = false;
$total_records = 0;
$total_pages = 0;
$sucursales_por_empresa = [];
logAuditoria($conn, 'error_consulta_servicios', 'servicios', null, [
'error' => $e->getMessage(),
'usuario' => $user['nombre'] ?? 'Desconocido'
]);
}
function renderDiasCheckboxes($name, $selectedDays = '') {
$dias = ['1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles', '4' => 'Jueves', '5' => 'Viernes', '6' => 'Sábado', '0' => 'Domingo'];
$selectedArray = explode(',', $selectedDays);
$html = '<div class="d-flex flex-wrap gap-2">';
foreach ($dias as $val => $label) {
$checked = in_array($val, $selectedArray) ? 'checked' : '';
$html .= "<div class='form-check'>
<input class='form-check-input' type='checkbox' name='{$name}[]' value='{$val}' id='{$name}{$val}' {$checked}>
<label class='form-check-label' for='{$name}{$val}'>{$label}</label>
</div>";
}
$html .= '</div>';
return $html;
}
$filter_params = '';
if (!empty($search_servicio)) $filter_params .= '&search_servicio=' . urlencode($search_servicio);
if (!empty($search_empresa)) $filter_params .= '&search_empresa=' . urlencode($search_empresa);
if (!empty($search_sucursal)) $filter_params .= '&search_sucursal=' . urlencode($search_sucursal);
if ($search_estado !== 'todos') $filter_params .= '&search_estado=' . urlencode($search_estado);
if ($search_tipo !== 'todos') $filter_params .= '&search_tipo=' . urlencode($search_tipo);
$jurisdicciones = ['Esquel', 'Comodoro Rivadavia', 'Trelew', 'Puerto Madryn', 'Rawson'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Servicios - Sistema de Seguridad</title>
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
/* Alertas */
.alert-pending-services {
background: #fff3cd;
border-left: 4px solid #f39c12;
border-radius: 4px;
padding: 15px;
margin-bottom: 20px;
}
</style>
</head>
<body>
<?php $page_title = 'Gestión de Servicios'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($servicios_pendientes > 0): ?>
<div class="alert-pending-services alert-dismissible fade show" role="alert">
<div class="d-flex align-items-center">
<div style="font-size: 2rem; color: #f39c12; margin-right: 15px;">
<i class="fas fa-clock"></i>
</div>
<div class="flex-grow-1">
<h5 style="color: #856404; margin-bottom: 5px;">
<i class="fas fa-exclamation-triangle"></i>
<?php echo $servicios_pendientes; ?> Servicio(s) Pendiente(s) de Aprobación
</h5>
<p style="color: #856404; margin-bottom: 0;">
Hay servicios creados por empresas que esperan tu revisión.
<a href="servicios.php?search_estado=pendiente" style="color: #856404; font-weight: 600;">
<i class="fas fa-filter"></i> Ver todos los pendientes
</a>
</p>
</div>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
</div>
<?php endif; ?>
<?php if (!$hasHorarios): ?>
<div class="alert alert-warning" role="alert">
<h5><i class="fas fa-exclamation-triangle"></i> Columnas de base de datos faltantes</h5>
<p class="mb-0">La tabla <code>servicios</code> no tiene las columnas necesarias.</p>
</div>
<?php endif; ?>
<?php if (!$hasPDF): ?>
<div class="alert alert-warning" role="alert">
<h5><i class="fas fa-file-pdf"></i> Columna PDF faltante</h5>
<p class="mb-0">La tabla <code>servicios</code> no tiene la columna <code>pdf_file</code>.</p>
</div>
<?php endif; ?>
<?php if (!$hasPrioridad): ?>
<div class="alert alert-warning" role="alert">
<h5><i class="fas fa-flag"></i> Columna PRIORIDAD faltante</h5>
<p class="mb-0">La tabla <code>servicios</code> no tiene la columna <code>prioridad</code>.</p>
</div>
<?php endif; ?>
<?php if ($total_empresas == 0 || $total_sucursales == 0): ?>
<div class="alert alert-warning" role="alert">
<h5><i class="fas fa-exclamation-circle"></i> Datos insuficientes</h5>
<p class="mb-0">
<?php if ($total_empresas == 0): ?><strong>No hay empresas registradas.</strong><br><?php endif; ?>
<?php if ($total_sucursales == 0): ?><strong>No hay sucursales registradas.</strong><br><?php endif; ?>
</p>
</div>
<?php endif; ?>
<?php if ($historial_servicio): ?>
<div class="modal fade" id="historialAuditoriaModal" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-xl modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-history"></i> Historial de Auditoría del Servicio</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" onclick="window.location='servicios.php'"></button>
</div>
<div class="modal-body">
<?php if (empty($historial_servicio)): ?>
<div class="text-center py-5">
<i class="fas fa-history fa-4x text-muted mb-3"></i>
<h5>No hay registros de auditoría para este servicio</h5>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>Acción</th>
<th>Usuario</th>
<th>Fecha</th>
<th>Detalles</th>
</tr>
</thead>
<tbody>
<?php foreach ($historial_servicio as $registro): ?>
<tr>
<td>#<?php echo $registro['id']; ?></td>
<td><span class="badge bg-primary"><?php echo htmlspecialchars($registro['accion']); ?></span></td>
<td><?php echo htmlspecialchars($registro['usuario_nombre'] ?? 'Sistema'); ?></td>
<td><?php echo date('d/m/Y H:i', strtotime($registro['created_at'])); ?></td>
<td><?php echo formatearDetalles($registro['detalles'] ?? ''); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="window.location='servicios.php'">
<i class="fas fa-times"></i> Cerrar
</button>
</div>
</div>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
var modal = new bootstrap.Modal(document.getElementById('historialAuditoriaModal'));
modal.show();
});
</script>
<?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-number"><?php echo $total_servicios; ?></div>
<div class="stat-label">Total Servicios</div>
</div>
<div class="stat-card">
<div class="stat-number" style="color: #27ae60;"><?php echo $servicios_activos; ?></div>
<div class="stat-label">Activos</div>
</div>
<div class="stat-card">
<div class="stat-number" style="color: #9b59b6;"><?php echo $servicios_completados; ?></div>
<div class="stat-label">Completados</div>
</div>
<div class="stat-card">
<div class="stat-number" style="color: #f39c12;"><?php echo $servicios_pendientes; ?></div>
<div class="stat-label">Pendientes</div>
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
<form method="GET" action="" class="row g-3" id="formFiltros">
<div class="col-md-3">
<label class="form-label">Servicio</label>
<input type="text" name="search_servicio" class="form-control" value="<?php echo htmlspecialchars($search_servicio); ?>" placeholder="Nombre del servicio...">
</div>
<div class="col-md-2">
<label class="form-label">Empresa</label>
<select name="search_empresa" id="search_empresa" class="form-select" onchange="filtrarSucursalesFiltro(this.value)">
<option value="">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($search_empresa == $empresa['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Sucursal</label>
<select name="search_sucursal" id="search_sucursal" class="form-select">
<option value="">Todas las sucursales</option>
<?php
if (!empty($search_empresa)):
foreach ($sucursales_por_empresa[$search_empresa] ?? [] as $sucursal):
?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($search_sucursal == $sucursal['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($sucursal['nombre']); ?>
</option>
<?php
endforeach;
else:
foreach ($sucursales as $sucursal):
?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($search_sucursal == $sucursal['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($sucursal['nombre']); ?>
</option>
<?php
endforeach;
endif;
?>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="search_estado" class="form-select">
<option value="todos" <?php echo ($search_estado === 'todos') ? 'selected' : ''; ?>>Todos</option>
<option value="activo" <?php echo ($search_estado === 'activo') ? 'selected' : ''; ?>>Activo</option>
<option value="pendiente" <?php echo ($search_estado === 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
<option value="completado" <?php echo ($search_estado === 'completado') ? 'selected' : ''; ?>>Completado</option>
<option value="cancelado" <?php echo ($search_estado === 'cancelado') ? 'selected' : ''; ?>>Cancelado</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Tipo</label>
<select name="search_tipo" class="form-select">
<option value="todos" <?php echo ($search_tipo === 'todos') ? 'selected' : ''; ?>>Todos</option>
<option value="vigilancia" <?php echo ($search_tipo === 'vigilancia') ? 'selected' : ''; ?>>Vigilancia</option>
<option value="escolta" <?php echo ($search_tipo === 'escolta') ? 'selected' : ''; ?>>Escolta</option>
<option value="eventos" <?php echo ($search_tipo === 'eventos') ? 'selected' : ''; ?>>Eventos</option>
</select>
</div>
<div class="col-12 d-flex gap-2">
<button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
<a href="servicios.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
<a href="servicios.php?action=export_pdf<?php echo $filter_params; ?>" class="btn btn-danger" target="_blank"><i class="fas fa-file-pdf me-2"></i>Exportar PDF</a>
</div>
</form>
</div>  <!-- ✅ CIERRA contenidoFiltros -->
</div>  <!-- ✅ CIERRA section-box -->
<!-- NUEVO SERVICIO -->
<!-- ✅ NUEVO SERVICIO - CON COLLAPSE -->
<div class="section-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="d-flex justify-content-between align-items-center section-title"
data-bs-toggle="collapse"
data-bs-target="#nuevoServicioForm"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<span><i class="fas fa-plus-circle me-2"></i>Nuevo Servicio</span>
<div class="d-flex align-items-center gap-2">
<i class="fas fa-chevron-down" id="iconoNuevoServicio"></i>
</div>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto) -->
<div class="collapse mt-3" id="nuevoServicioForm">
<h5 class="mb-3"><i class="fas fa-concierge-bell me-2"></i>Registrar Nuevo Servicio</h5>
<form method="POST" action="servicios.php" class="row g-3" enctype="multipart/form-data">
<input type="hidden" name="action" value="create">
<div class="col-md-6">
<label class="form-label">Nombre del Servicio <span class="text-danger">*</span></label>
<input type="text" name="nombre" class="form-control" required placeholder="Ej: Vigilancia Nocturna">
</div>
<div class="col-md-6">
<label class="form-label">Tipo de Servicio <span class="text-danger">*</span></label>
<select name="tipo" class="form-select" required>
<option value="">Seleccione un tipo</option>
<option value="vigilancia">Vigilancia</option>
<option value="escolta">Escolta</option>
<option value="eventos">Eventos</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<select name="empresa_id" class="form-select" required onchange="filtrarSucursales(this.value, 'sucursal_id_nuevo')">
<option value="">Seleccione una empresa</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal</label>
<select name="sucursal_id" id="sucursal_id_nuevo" class="form-select">
<option value="">Seleccione una sucursal</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Domicilio</label>
<input type="text" name="domicilio" class="form-control" placeholder="Ej: Av. Principal 123">
</div>
<div class="col-md-6">
<label class="form-label">Jurisdicción</label>
<select name="jurisdiccion" class="form-select">
<option value="">Seleccione una jurisdicción</option>
<?php foreach ($jurisdicciones as $jur): ?>
<option value="<?php echo htmlspecialchars($jur); ?>"><?php echo htmlspecialchars($jur); ?></option>
<?php endforeach; ?>
</select>
</div>
<?php if ($hasHorarios): ?>
<div class="col-md-6">
<label class="form-label">Hora Inicio</label>
<input type="time" name="hora_inicio" class="form-control">
</div>
<div class="col-md-6">
<label class="form-label">Hora Fin</label>
<input type="time" name="hora_fin" class="form-control">
</div>
<div class="col-12">
<label class="form-label">Días de Atención</label>
<div class="d-flex flex-wrap gap-2">
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="1" class="form-check-input"> Lunes</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="2" class="form-check-input"> Martes</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="3" class="form-check-input"> Miércoles</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="4" class="form-check-input"> Jueves</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="5" class="form-check-input"> Viernes</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="6" class="form-check-input"> Sábado</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="0" class="form-check-input"> Domingo</label>
</div>
</div>
<div class="col-md-12">
<label class="form-label">Personal Asignado</label>
<select name="personal_id" class="form-select">
<option value="">Seleccione personal (opcional)...</option>
<?php foreach ($personal_list as $p): ?>
<option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>
<div class="col-md-6">
<label class="form-label">Fecha de Inicio <span class="text-danger">*</span></label>
<input type="date" name="fecha_inicio" class="form-control" required>
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Fin</label>
<input type="date" name="fecha_fin" class="form-control">
</div>
<div class="col-md-6">
<label class="form-label">Prioridad <span class="text-danger">*</span></label>
<select name="prioridad" class="form-select" required>
<option value="baja">Baja</option>
<option value="media" selected>Media</option>
<option value="alta">Alta</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Estado <span class="text-danger">*</span></label>
<select name="estado" class="form-select" required>
<option value="pendiente" selected>Pendiente</option>
<option value="activo">Activo</option>
<option value="completado">Completado</option>
<option value="cancelado">Cancelado</option>
</select>
</div>
<div class="col-12">
<label class="form-label">Descripción</label>
<textarea name="descripcion" class="form-control" rows="3" placeholder="Descripción detallada del servicio..."></textarea>
</div>
<?php if ($hasPDF): ?>
<div class="col-12">
<label class="form-label">Archivo PDF (Opcional)</label>
<input type="file" name="pdf_file" class="form-control" accept=".pdf" id="pdfFileNuevo" onchange="previewPDF(this, 'pdfPreviewNuevo')">
<small class="text-muted">Máximo 5MB. Solo archivos PDF.</small>
<div id="pdfPreviewNuevo" class="mt-2"></div>
</div>
<?php endif; ?>
<div class="col-12 text-end">
<button type="submit" class="btn btn-success btn-lg px-5">
<i class="fas fa-save me-2"></i>Registrar Servicio
</button>
</div>
</form>
</div>  <!-- ✅ CIERRA nuevoServicioForm -->
</div>  <!-- ✅ CIERRA section-box -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Servicios
<span class="badge bg-primary ms-2"><?php echo $total_records; ?> registros</span>
</div>
<?php if (empty($servicios)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-concierge-bell fa-3x text-muted mb-3"></i>
<h5>No hay servicios registrados</h5>
<p class="text-muted"><?php echo empty($search_servicio) && $search_estado === 'todos' ? 'Registra tu primer servicio para comenzar.' : 'No se encontraron servicios con los filtros aplicados.'; ?></p>
<button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#nuevoServicioModal">
<i class="fas fa-plus me-2"></i>Crear Servicio
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th><a href="?order=nombre&direction=<?= $order_direction === 'ASC' && $order_column === 'nombre' ? 'DESC' : 'ASC' ?><?= $filter_params ?>" class="text-decoration-none text-dark">Servicio <?php echo getOrderIcon('nombre', $order_column, $order_direction); ?></a></th>
<th><a href="?order=empresa_nombre&direction=<?= $order_direction === 'ASC' && $order_column === 'empresa_nombre' ? 'DESC' : 'ASC' ?><?= $filter_params ?>" class="text-decoration-none text-dark">Empresa <?php echo getOrderIcon('empresa_nombre', $order_column, $order_direction); ?></a></th>
<th><a href="?order=sucursal_nombre&direction=<?= $order_direction === 'ASC' && $order_column === 'sucursal_nombre' ? 'DESC' : 'ASC' ?><?= $filter_params ?>" class="text-decoration-none text-dark">Sucursal <?php echo getOrderIcon('sucursal_nombre', $order_column, $order_direction); ?></a></th>
<th>Tipo</th>
<th>Prioridad</th>
<th>Estado</th>
<th>Fecha</th>
<?php if ($hasPDF): ?>
<th>PDF</th>
<?php endif; ?>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($servicios as $servicio): ?>
<tr>
<td><strong><?php echo htmlspecialchars($servicio['nombre'] ?? 'Sin nombre'); ?></strong></td>
<td><span class="badge bg-primary"><?php echo htmlspecialchars($servicio['empresa_nombre'] ?? 'Sin empresa'); ?></span></td>
<td><span class="badge bg-success"><?php echo htmlspecialchars($servicio['sucursal_nombre'] ?? 'Sin sucursal'); ?></span></td>
<td><span class="badge bg-info"><?php echo ucfirst(htmlspecialchars($servicio['tipo'] ?? 'vigilancia')); ?></span></td>
<td>
<span class="badge <?php echo $servicio['prioridad'] === 'alta' ? 'bg-danger' : ($servicio['prioridad'] === 'media' ? 'bg-warning text-dark' : 'bg-success'); ?>">
<?php echo strtoupper(htmlspecialchars($servicio['prioridad'] ?? 'media')); ?>
</span>
</td>
<td>
<span class="badge <?php echo $servicio['estado'] === 'activo' ? 'bg-success' : ($servicio['estado'] === 'pendiente' ? 'bg-warning text-dark' : ($servicio['estado'] === 'completado' ? 'bg-purple' : 'bg-secondary')); ?>">
<?php echo ucfirst(htmlspecialchars($servicio['estado'] ?? 'pendiente')); ?>
</span>
<?php if (!empty($servicio['estado_updated_at'])): ?>
<br><small class="text-muted" style="font-size: 0.75rem; line-height: 1.3;">
<i class="fas fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($servicio['estado_updated_at'])); ?>
<?php if (!empty($servicio['aprobador_username'])): ?>
<br><i class="fas fa-user"></i> <?php echo htmlspecialchars($servicio['aprobador_username']); ?>
<?php endif; ?>
</small>
<?php endif; ?>
</td>
<td class="text-muted"><?php if (!empty($servicio['fecha_inicio'])) { echo date('d/m/Y', strtotime($servicio['fecha_inicio'])); } else { echo 'N/A'; } ?></td>
<?php if ($hasPDF): ?>
<td>
<?php if (!empty($servicio['pdf_file'])): ?>
<a href="../uploads/servicios/<?php echo htmlspecialchars($servicio['pdf_file']); ?>" class="btn btn-sm btn-outline-danger" target="_blank"><i class="fas fa-file-pdf"></i></a>
<?php else: ?><span class="text-muted">-</span><?php endif; ?>
</td>
<?php endif; ?>
<td>
<div class="btn-group btn-group-sm">
<?php if ($servicio['estado'] === 'pendiente'): ?>
<button type="button" class="btn btn-success" title="Aprobar" onclick="confirmarAprobacion(<?php echo $servicio['id']; ?>, '<?php echo addslashes($servicio['nombre']); ?>')">
<i class="fas fa-check"></i>
</button>
<button type="button" class="btn btn-danger" title="Denegar" onclick="confirmarDenegacion(<?php echo $servicio['id']; ?>, '<?php echo addslashes($servicio['nombre']); ?>')">
<i class="fas fa-times"></i>
</button>
<?php endif; ?>
<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editarServicioModal<?php echo $servicio['id']; ?>" title="Editar"><i class="fas fa-edit"></i></button>
<button type="button" class="btn btn-sm btn-outline-warning" title="Eliminar" onclick="confirmarEliminacion(<?php echo $servicio['id']; ?>, '<?php echo addslashes($servicio['nombre']); ?>')"><i class="fas fa-trash"></i></button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_pages > 1): ?>
<div class="pagination-container" style="background: #f8f9fa; border-radius: 4px; padding: 15px; margin-top: 15px;">
<nav>
<ul class="pagination justify-content-center mb-0">
<li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $page - 1 ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>"><i class="fas fa-chevron-left"></i> Anterior</a></li>
<?php $start_page = max(1, $page - 2); $end_page = min($total_pages, $page + 2); if ($start_page > 1): ?>
<li class="page-item"><a class="page-link" href="?page=1<?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>">1</a></li>
<?php if ($start_page > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
<?php endif; ?>
<?php for ($i = $start_page; $i <= $end_page; $i++): ?>
<li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>"><?= $i ?></a></li>
<?php endfor; ?>
<?php if ($end_page < $total_pages): ?>
<?php if ($end_page < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
<li class="page-item"><a class="page-link" href="?page=<?= $total_pages ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>"><?= $total_pages ?></a></li>
<?php endif; ?>
<li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $page + 1 ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>">Siguiente <i class="fas fa-chevron-right"></i></a></li>
</ul>
</nav>
<div class="text-center text-muted mt-2"><small>Mostrando <?= $offset + 1 ?> - <?= min($offset + $records_per_page, $total_records) ?> de <?= $total_records ?> registros</small></div>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>
<!-- MODALES DE EDICIÓN -->
<?php foreach ($servicios as $servicio): ?>
<div class="modal fade" id="editarServicioModal<?php echo $servicio['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-edit"></i>Editar Servicio: <?php echo htmlspecialchars($servicio['nombre'] ?? 'Sin nombre'); ?></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form method="POST" action="servicios.php" id="formEditarServicio<?php echo $servicio['id']; ?>" enctype="multipart/form-data">
<input type="hidden" name="action" value="update">
<input type="hidden" name="id" value="<?php echo (int)$servicio['id']; ?>">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Nombre del Servicio <span class="text-danger">*</span></label>
<input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($servicio['nombre'] ?? ''); ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Tipo de Servicio <span class="text-danger">*</span></label>
<select name="tipo" class="form-select" required>
<option value="vigilancia" <?php echo ($servicio['tipo'] ?? '') == 'vigilancia' ? 'selected' : ''; ?>>Vigilancia</option>
<option value="escolta" <?php echo ($servicio['tipo'] ?? '') == 'escolta' ? 'selected' : ''; ?>>Escolta</option>
<option value="eventos" <?php echo ($servicio['tipo'] ?? '') == 'eventos' ? 'selected' : ''; ?>>Eventos</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<select name="empresa_id" class="form-select" required onchange="filtrarSucursales(this.value, 'sucursal_id_<?php echo $servicio['id']; ?>')">
<option value="">Seleccione una empresa</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($servicio['empresa_id'] ?? '') == $empresa['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($empresa['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal</label>
<select name="sucursal_id" id="sucursal_id_<?php echo $servicio['id']; ?>" class="form-select">
<option value="">Seleccione una sucursal</option>
<?php
$empresa_id_actual = $servicio['empresa_id'] ?? 0;
if (isset($sucursales_por_empresa[$empresa_id_actual])):
foreach ($sucursales_por_empresa[$empresa_id_actual] as $sucursal):
?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($servicio['sucursal_id'] ?? '') == $sucursal['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
<?php
endforeach;
endif;
?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Domicilio</label>
<input type="text" name="domicilio" class="form-control" value="<?php echo htmlspecialchars($servicio['domicilio'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Jurisdicción</label>
<select name="jurisdiccion" class="form-select">
<option value="">Seleccione una jurisdicción</option>
<?php foreach ($jurisdicciones as $jur): ?>
<option value="<?php echo htmlspecialchars($jur); ?>" <?php echo ($servicio['jurisdiccion'] ?? '') == $jur ? 'selected' : ''; ?>><?php echo htmlspecialchars($jur); ?></option>
<?php endforeach; ?>
</select>
</div>
<?php if ($hasHorarios): ?>
<div class="col-md-6">
<label class="form-label">Hora Inicio</label>
<input type="time" name="hora_inicio" class="form-control" value="<?php echo htmlspecialchars($servicio['hora_inicio'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Hora Fin</label>
<input type="time" name="hora_fin" class="form-control" value="<?php echo htmlspecialchars($servicio['hora_fin'] ?? ''); ?>">
</div>
<div class="col-12">
<label class="form-label">Días de Atención</label>
<div class="d-flex flex-wrap gap-2">
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="1" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '1') !== false) ? 'checked' : ''; ?>> Lunes</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="2" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '2') !== false) ? 'checked' : ''; ?>> Martes</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="3" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '3') !== false) ? 'checked' : ''; ?>> Miércoles</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="4" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '4') !== false) ? 'checked' : ''; ?>> Jueves</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="5" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '5') !== false) ? 'checked' : ''; ?>> Viernes</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="6" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '6') !== false) ? 'checked' : ''; ?>> Sábado</label>
<label class="form-check"><input type="checkbox" name="dias_semana[]" value="0" class="form-check-input" <?php echo (strpos($servicio['dias_semana'] ?? '', '0') !== false) ? 'checked' : ''; ?>> Domingo</label>
</div>
</div>
<div class="col-md-12">
<label class="form-label">Personal Asignado</label>
<select name="personal_id" class="form-select">
<option value="">Seleccione personal (opcional)...</option>
<?php foreach ($personal_list as $p): ?>
<option value="<?php echo $p['id']; ?>" <?php echo ($servicio['personal_id'] ?? '') == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>
<div class="col-md-6">
<label class="form-label">Fecha de Inicio <span class="text-danger">*</span></label>
<input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($servicio['fecha_inicio'] ?? ''); ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Fin</label>
<input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($servicio['fecha_fin'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Prioridad <span class="text-danger">*</span></label>
<select name="prioridad" class="form-select" required>
<option value="baja" <?php echo ($servicio['prioridad'] ?? 'media') == 'baja' ? 'selected' : ''; ?>>Baja</option>
<option value="media" <?php echo ($servicio['prioridad'] ?? 'media') == 'media' ? 'selected' : ''; ?>>Media</option>
<option value="alta" <?php echo ($servicio['prioridad'] ?? 'media') == 'alta' ? 'selected' : ''; ?>>Alta</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Estado <span class="text-danger">*</span></label>
<select name="estado" class="form-select" required>
<option value="activo" <?php echo ($servicio['estado'] ?? 'pendiente') == 'activo' ? 'selected' : ''; ?>>Activo</option>
<option value="pendiente" <?php echo ($servicio['estado'] ?? 'pendiente') == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
<option value="completado" <?php echo ($servicio['estado'] ?? 'pendiente') == 'completado' ? 'selected' : ''; ?>>Completado</option>
<option value="cancelado" <?php echo ($servicio['estado'] ?? 'pendiente') == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
</select>
</div>
<div class="col-12">
<label class="form-label">Descripción</label>
<textarea name="descripcion" class="form-control" rows="3"><?php echo htmlspecialchars($servicio['descripcion'] ?? ''); ?></textarea>
</div>
<?php if ($hasPDF): ?>
<div class="col-12">
<label class="form-label">Archivo PDF (Opcional)</label>
<input type="file" name="pdf_file" class="form-control" accept=".pdf" id="pdfFileEditar<?php echo $servicio['id']; ?>" onchange="previewPDF(this, 'pdfPreviewEditar<?php echo $servicio['id']; ?>')">
<small class="text-muted">Máximo 5MB. Solo PDF. Dejar vacío para mantener el actual.</small>
<?php if (!empty($servicio['pdf_file'])): ?>
<div class="mt-2 p-2 bg-light rounded">
<i class="fas fa-file-pdf text-danger"></i>
<?php echo htmlspecialchars($servicio['pdf_file']); ?>
<a href="../uploads/servicios/<?php echo htmlspecialchars($servicio['pdf_file']); ?>" class="btn btn-sm btn-outline-danger ms-2" target="_blank"><i class="fas fa-eye"></i> Ver</a>
</div>
<?php endif; ?>
<div id="pdfPreviewEditar<?php echo $servicio['id']; ?>" class="mt-2"></div>
</div>
<?php endif; ?>
</div>
</form>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" form="formEditarServicio<?php echo $servicio['id']; ?>" class="btn btn-primary">Guardar Cambios</button>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ✅ Rotar icono de flecha en sección Nuevo Servicio al colapsar/expandir
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
// ✅ Rotar icono de flecha en sección Filtros al colapsar/expandir
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
const sucursalesPorEmpresaFiltro = <?php echo json_encode($sucursales_por_empresa, JSON_UNESCAPED_UNICODE); ?>;
const sucursalesPorEmpresa = <?php echo json_encode($sucursales_por_empresa, JSON_UNESCAPED_UNICODE); ?>;
function filtrarSucursalesFiltro(empresaId) {
const selectSucursal = document.getElementById('search_sucursal');
selectSucursal.innerHTML = '<option value="">Todas las sucursales</option>';
if (empresaId && sucursalesPorEmpresaFiltro[empresaId]) {
sucursalesPorEmpresaFiltro[empresaId].forEach(function(sucursal) {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
selectSucursal.appendChild(option);
});
}
}
function filtrarSucursales(empresaId, selectSucursalId) {
const selectSucursal = document.getElementById(selectSucursalId);
selectSucursal.innerHTML = '<option value="">Seleccione una sucursal</option>';
if (empresaId && sucursalesPorEmpresa[empresaId]) {
sucursalesPorEmpresa[empresaId].forEach(function(sucursal) {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
selectSucursal.appendChild(option);
});
}
}
document.addEventListener('DOMContentLoaded', function() {
<?php foreach ($servicios as $servicio): ?>
const empresaSelect<?php echo $servicio['id']; ?> = document.querySelector('#editarServicioModal<?php echo $servicio['id']; ?> select[name="empresa_id"]');
if (empresaSelect<?php echo $servicio['id']; ?>) {
empresaSelect<?php echo $servicio['id']; ?>.addEventListener('change', function() {
filtrarSucursales(this.value, 'sucursal_id_<?php echo $servicio['id']; ?>');
});
}
<?php endforeach; ?>
const empresaSelectNuevo = document.querySelector('#nuevoServicioModal select[name="empresa_id"]');
if (empresaSelectNuevo) {
empresaSelectNuevo.addEventListener('change', function() {
filtrarSucursales(this.value, 'sucursal_id_nuevo');
});
}
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
});
function confirmarAprobacion(id, nombre) {
Swal.fire({
title: '¿Aprobar Servicio?',
text: 'El estado cambiará de Pendiente a Activo',
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#27ae60',
cancelButtonColor: '#95a5a6',
confirmButtonText: 'Sí, Aprobar',
cancelButtonText: 'Cancelar'
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'servicios.php';
form.innerHTML = '<input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="' + id + '">';
document.body.appendChild(form);
form.submit();
}
});
}
function confirmarDenegacion(id, nombre) {
Swal.fire({
title: '¿Denegar Servicio?',
text: 'El estado cambiará de Pendiente a Cancelado',
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#e74c3c',
cancelButtonColor: '#95a5a6',
confirmButtonText: 'Sí, Denegar',
cancelButtonText: 'Cancelar'
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'servicios.php';
form.innerHTML = '<input type="hidden" name="action" value="deny"><input type="hidden" name="id" value="' + id + '">';
document.body.appendChild(form);
form.submit();
}
});
}
function confirmarEliminacion(id, nombre) {
Swal.fire({
title: '¿Eliminar Servicio?',
text: 'Esta acción no se puede deshacer',
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#dc3545',
cancelButtonColor: '#95a5a6',
confirmButtonText: 'Sí, Eliminar',
cancelButtonText: 'Cancelar'
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'servicios.php';
form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
document.body.appendChild(form);
form.submit();
}
});
}
function previewPDF(input, previewId) {
const preview = document.getElementById(previewId);
if (input.files && input.files[0]) {
const file = input.files[0];
if (file.type !== 'application/pdf') {
Swal.fire({icon: 'error', title: 'Archivo inválido', text: 'Solo se permiten archivos PDF'});
input.value = '';
preview.innerHTML = '';
return;
}
if (file.size > 5 * 1024 * 1024) {
Swal.fire({icon: 'error', title: 'Archivo muy grande', text: 'El PDF no puede superar los 5MB'});
input.value = '';
preview.innerHTML = '';
return;
}
preview.innerHTML = '<div class="p-2 bg-light rounded"><i class="fas fa-file-pdf text-danger"></i> ' + file.name + ' <span class="badge bg-success">Listo</span></div>';
} else {
preview.innerHTML = '';
}
}
</script>
</body>
</html>