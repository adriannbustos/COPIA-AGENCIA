<?php
// ============================================================================
// GESTIÓN DE RECURSOS - VERSIÓN COMPLETA CON AUDITORÍA
// ============================================================================
// Incluye: CRUD completo, Auditoría detallada, Aprobación/Rechazo,
//          Validaciones, Paginación, Búsqueda, Filtros Avanzados,
//          Múltiples tipos de recursos (Chalecos, Comunicación, Armamento, etc.)
//
// @author Sistema de Seguridad
// @version 3.0 - Diseño Uniforme y Plano
// @last_update 2024
// ============================================================================
// ?? MANEJO AJAX - DEBE IR ANTES DE CUALQUIER OUTPUT
if (isset($_GET['action'])) {
session_start();
ob_clean();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
if (!$auth->isLoggedIn()) {
http_response_code(403);
echo json_encode(['error' => 'Acceso denegado']);
exit;
}
$action = $_GET['action'];
$conn = getDBConnection();
$user = $auth->getCurrentUser();
// === GET SUCURSALES ===
if ($action === 'get_sucursales' && isset($_GET['empresa_id'])) {
try {
$empresa_id = (int)$_GET['empresa_id'];
if ($empresa_id <= 0) {
echo json_encode([]);
exit;
}
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = :empresa_id AND activa = TRUE ORDER BY nombre");
$stmt->execute(['empresa_id' => $empresa_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch(PDOException $e) {
error_log("Error get_sucursales: " . $e->getMessage());
http_response_code(500);
echo json_encode(['error' => 'Error al cargar sucursales']);
}
exit;
}
// === GET PERSONAL ===
if ($action === 'get_personal' && isset($_GET['sucursal_id'])) {
try {
$sucursal_id = (int)$_GET['sucursal_id'];
if ($sucursal_id <= 0) {
echo json_encode([]);
exit;
}
$stmt = $conn->prepare("
SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo, dni, cargo
FROM personal WHERE sucursal_id = :sucursal_id AND activo = TRUE
ORDER BY apellido, nombre
");
$stmt->execute(['sucursal_id' => $sucursal_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch(PDOException $e) {
error_log("Error get_personal: " . $e->getMessage());
http_response_code(500);
echo json_encode(['error' => 'Error al cargar personal']);
}
exit;
}
// === GET RECURSO DETAILS ===
if ($action === 'get_recurso_details' && isset($_GET['id'])) {
try {
$id = (int)$_GET['id'];
if ($id <= 0) {
http_response_code(400);
echo json_encode(['error' => 'ID invalido']);
exit;
}
if (!$auth->hasRole('administrador')) {
http_response_code(403);
echo json_encode(['error' => 'Acceso denegado']);
exit;
}
$stmt = $conn->prepare("
SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo,
rs.estado, rs.motivo_rechazo, rs.fecha_aprobacion, rs.archivo_pdf,
u.username as aprobado_por_username
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN usuarios u ON rs.aprobado_por = u.id
WHERE rs.id = :id
");
$stmt->execute(['id' => $id]);
$recurso = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$recurso) {
http_response_code(404);
echo json_encode(['error' => 'Asignacion de recursos no encontrada']);
exit;
}
$stmt = $conn->prepare("
SELECT tipo_recurso, atributos
FROM recursos_items
WHERE recursos_sucursal_id = :id
ORDER BY tipo_recurso, id
");
$stmt->execute(['id' => $id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$items_por_tipo = [
'chaleco' => [],
'equipo_comunicacion' => [],
'armamento' => [],
'vehiculo' => [],
'equipos_video_vigilancia' => []
];
foreach ($items as $item) {
$tipo = $item['tipo_recurso'];
$atributos = json_decode($item['atributos'], true);
if (isset($items_por_tipo[$tipo])) {
$items_por_tipo[$tipo][] = $atributos;
}
}
$response = [
'id' => $recurso['id'],
'empresa_nombre' => $recurso['empresa_nombre'] ?? 'Sin empresa',
'sucursal_nombre' => $recurso['sucursal_nombre'] ?? 'Sin sucursal',
'personal_nombre' => $recurso['personal_nombre'] ?? null,
'dni' => $recurso['dni'] ?? null,
'cargo' => $recurso['cargo'] ?? null,
'observaciones' => $recurso['observaciones'] ?? '',
'estado' => $recurso['estado'] ?? 'pendiente',
'motivo_rechazo' => $recurso['motivo_rechazo'] ?? null,
'aprobado_por_username' => $recurso['aprobado_por_username'] ?? null,
'fecha_aprobacion' => $recurso['fecha_aprobacion'] ?? null,
'archivo_pdf' => $recurso['archivo_pdf'] ?? null,
'created_at' => $recurso['created_at'] ?? null,
'updated_at' => $recurso['updated_at'] ?? null,
'items' => $items_por_tipo
];
echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch(PDOException $e) {
error_log("Error get_recurso_details: " . $e->getMessage());
http_response_code(500);
echo json_encode(['error' => 'Error al cargar los datos']);
}
exit;
}
// === EXPORTAR PDF CON FILTROS ===
if ($action === 'exportar_pdf') {
try {
require_once '../config/database.php';
require_once '../config/auth.php';
if (!$auth->isLoggedIn()) {
http_response_code(403);
echo json_encode(['error' => 'Acceso denegado']);
exit;
}
$search_empresa = $_GET['search_empresa'] ?? '';
$search_sucursal = $_GET['search_sucursal'] ?? '';
$search_personal = $_GET['search_personal'] ?? '';
$search_tipo_recurso = $_GET['search_tipo_recurso'] ?? 'todos';
$search_estado = $_GET['search_estado'] ?? 'todos';
$order_column = isset($_GET['order']) && in_array($_GET['order'], ['empresa_nombre', 'sucursal_nombre', 'personal_nombre', 'chaleco_count', 'comunicacion_count', 'armamento_count', 'vehiculo_count', 'video_count', 'total_items', 'created_at', 'estado']) ? $_GET['order'] : 'empresa_nombre';
$order_direction = isset($_GET['direction']) && strtoupper($_GET['direction']) === 'DESC' ? 'DESC' : 'ASC';
$conn = getDBConnection();
$where_clauses = [];
$params = [];
if (!empty($search_empresa) && is_numeric($search_empresa)) {
$where_clauses[] = "rs.empresa_id = ?";
$params[] = (int)$search_empresa;
}
if (!empty($search_sucursal) && is_numeric($search_sucursal)) {
$where_clauses[] = "rs.sucursal_id = ?";
$params[] = (int)$search_sucursal;
}
if (!empty($search_personal)) {
$where_clauses[] = "CONCAT(p.nombre, ' ', p.apellido) LIKE ?";
$params[] = '%' . $search_personal . '%';
}
if ($search_tipo_recurso !== 'todos') {
$where_clauses[] = "ri.tipo_recurso = ?";
$params[] = $search_tipo_recurso;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "rs.estado = ?";
$params[] = $search_estado;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$stmt = $conn->prepare("
SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo,
rs.estado, rs.motivo_rechazo, rs.fecha_aprobacion, rs.aprobado_por,
u.username as aprobado_por_username,
SUM(CASE WHEN ri.tipo_recurso = 'chaleco' THEN 1 ELSE 0 END) as chaleco_count,
SUM(CASE WHEN ri.tipo_recurso = 'equipo_comunicacion' THEN 1 ELSE 0 END) as comunicacion_count,
SUM(CASE WHEN ri.tipo_recurso = 'armamento' THEN 1 ELSE 0 END) as armamento_count,
SUM(CASE WHEN ri.tipo_recurso = 'vehiculo' THEN 1 ELSE 0 END) as vehiculo_count,
SUM(CASE WHEN ri.tipo_recurso = 'equipos_video_vigilancia' THEN 1 ELSE 0 END) as video_count,
COUNT(ri.id) as total_items
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN usuarios u ON rs.aprobado_por = u.id
LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
$where_sql
GROUP BY rs.id
ORDER BY $order_column $order_direction
");
$stmt->execute($params);
$recursos_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/pdf; charset=utf-8');
header('Content-Disposition: attachment; filename="recursos_filtrados_' . date('Ymd_His') . '.pdf"');
header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0, max-age=1');
header('Pragma: public');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
echo "%PDF-1.4\n";
echo "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
echo "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
echo "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
echo "4 0 obj\n<< /Length " . (200 + strlen(json_encode($recursos_list))) . " >>\nstream\n";
echo "BT /F1 12 Tf 50 750 Td (REPORTE DE RECURSOS FILTRADOS) Tj 0 -20 Td (";
echo "Fecha: " . date('d/m/Y H:i') . " - Filtros: Empresa=" . ($search_empresa ?: 'Todas') . ", Estado=" . $search_estado . ") Tj 0 -40 Td (";
echo "Empresa | Sucursal | Personal | Estado | Chalecos | Comunicacion | Armamento | Vehiculos | Video | Total | Fecha) Tj\n";
foreach ($recursos_list as $r) {
echo "0 -15 Td (" . htmlspecialchars($r['empresa_nombre'] ?? '') . " | ";
echo htmlspecialchars($r['sucursal_nombre'] ?? '') . " | ";
echo htmlspecialchars($r['personal_nombre'] ?? 'Sucursal') . " | ";
echo htmlspecialchars($r['estado'] ?? 'pendiente') . " | ";
echo ($r['chaleco_count'] ?? 0) . " | ";
echo ($r['comunicacion_count'] ?? 0) . " | ";
echo ($r['armamento_count'] ?? 0) . " | ";
echo ($r['vehiculo_count'] ?? 0) . " | ";
echo ($r['video_count'] ?? 0) . " | ";
echo ($r['total_items'] ?? 0) . " | ";
echo (isset($r['created_at']) ? date('d/m/Y', strtotime($r['created_at'])) : '') . ") Tj\n";
}
echo "ET\nendstream\nendobj\n";
echo "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
echo "xref\n0 6\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000110 00000 n \n0000000260 00000 n \n0000000410 00000 n \n";
echo "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n560\n%%EOF";
exit;
} catch(Exception $e) {
error_log("Error exportar_pdf: " . $e->getMessage());
http_response_code(500);
echo json_encode(['error' => 'Error al generar PDF: ' . $e->getMessage()]);
exit;
}
}
// === APROBAR RECURSOS ===
if ($action === 'aprobar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
try {
$id = (int)$_POST['id'];
$user = $auth->getCurrentUser();
$stmt = $conn->prepare("
UPDATE recursos_sucursal
SET estado = 'aprobado',
aprobado_por = ?,
fecha_aprobacion = NOW(),
updated_at = NOW()
WHERE id = ?
");
$stmt->execute([$user['id'], $id]);
logAuditoria($conn, 'APROBACION_RECURSO', 'recursos_sucursal', $id, [
'acción' => 'Aprobación de recursos',
'aprobado_por' => $user['id'],
'recurso_id' => $id
], $user['id']);
echo json_encode(['success' => true, 'message' => 'Recursos aprobados correctamente']);
} catch(PDOException $e) {
error_log("Error aprobar recursos: " . $e->getMessage());
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
}
// === RECHAZAR RECURSOS ===
if ($action === 'rechazar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
try {
$id = (int)$_POST['id'];
$motivo = htmlspecialchars(strip_tags(trim($_POST['motivo'] ?? 'Sin motivo especificado')), ENT_QUOTES, 'UTF-8');
$user = $auth->getCurrentUser();
$stmt = $conn->prepare("
UPDATE recursos_sucursal
SET estado = 'rechazado',
aprobado_por = ?,
fecha_aprobacion = NOW(),
motivo_rechazo = ?,
updated_at = NOW()
WHERE id = ?
");
$stmt->execute([$user['id'], $motivo, $id]);
logAuditoria($conn, 'RECHAZO_RECURSO', 'recursos_sucursal', $id, [
'acción' => 'Rechazo de recursos',
'motivo' => $motivo,
'rechazado_por' => $user['id'],
'recurso_id' => $id
], $user['id']);
echo json_encode(['success' => true, 'message' => 'Recursos rechazados correctamente']);
} catch(PDOException $e) {
error_log("Error rechazar recursos: " . $e->getMessage());
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
}
}
// ==================== INICIO VISTA NORMAL ====================
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
function uploadPDF($file, $resource_id) {
if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
return null;
}
$allowed_types = ['application/pdf'];
$max_size = 5 * 1024 * 1024;
if (!in_array($file['type'], $allowed_types)) {
throw new Exception('Solo se permiten archivos PDF');
}
if ($file['size'] > $max_size) {
throw new Exception('El archivo no puede superar los 5MB');
}
$upload_dir = '../uploads/recursos/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
$file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$new_filename = 'recurso_' . $resource_id . '_' . time() . '.' . $file_extension;
$upload_path = $upload_dir . $new_filename;
if (move_uploaded_file($file['tmp_name'], $upload_path)) {
return 'uploads/recursos/' . $new_filename;
}
throw new Exception('Error al subir el archivo');
}
function deletePDF($file_path) {
if ($file_path && file_exists('../' . $file_path)) {
unlink('../' . $file_path);
}
}
if (!function_exists('sanitizeInput')) {
function sanitizeInput($data) {
return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
}
if (!$auth->isLoggedIn()) {
header('Location: ../login.php');
exit;
}
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
$current_page = 'recursos';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
$search_empresa = $_GET['search_empresa'] ?? '';
$search_sucursal = $_GET['search_sucursal'] ?? '';
$search_personal = $_GET['search_personal'] ?? '';
$search_tipo_recurso = $_GET['search_tipo_recurso'] ?? 'todos';
$search_estado = $_GET['search_estado'] ?? 'todos';
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;
$allowed_order_columns = ['empresa_nombre', 'sucursal_nombre', 'personal_nombre', 'chaleco_count', 'comunicacion_count', 'armamento_count', 'vehiculo_count', 'video_count', 'total_items', 'created_at', 'estado'];
$order_column = isset($_GET['order']) && in_array($_GET['order'], $allowed_order_columns) ? $_GET['order'] : 'empresa_nombre';
$order_direction = isset($_GET['direction']) && strtoupper($_GET['direction']) === 'DESC' ? 'DESC' : 'ASC';
try {
$stmt = $conn->query("SHOW TABLES LIKE 'recursos_sucursal'");
if ($stmt->rowCount() == 0) {
$conn->exec("
CREATE TABLE recursos_sucursal (
id INT AUTO_INCREMENT PRIMARY KEY,
empresa_id INT NOT NULL,
sucursal_id INT NOT NULL,
personal_id INT NULL,
observaciones TEXT NULL,
archivo_pdf VARCHAR(255) NULL,
estado ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente',
aprobado_por INT NULL,
fecha_aprobacion TIMESTAMP NULL,
motivo_rechazo TEXT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
}
$stmt = $conn->query("SHOW TABLES LIKE 'notificaciones_recursos'");
if ($stmt->rowCount() == 0) {
$conn->exec("
CREATE TABLE notificaciones_recursos (
id INT AUTO_INCREMENT PRIMARY KEY,
recursos_sucursal_id INT NOT NULL,
usuario_id INT NOT NULL,
tipo ENUM('aprobado', 'rechazado', 'pendiente') NOT NULL,
mensaje TEXT,
leido BOOLEAN DEFAULT FALSE,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (recursos_sucursal_id) REFERENCES recursos_sucursal(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
}
$stmt = $conn->query("SHOW TABLES LIKE 'recursos_items'");
if ($stmt->rowCount() == 0) {
$conn->exec("
CREATE TABLE recursos_items (
id INT AUTO_INCREMENT PRIMARY KEY,
recursos_sucursal_id INT NOT NULL,
tipo_recurso ENUM('chaleco', 'equipo_comunicacion', 'armamento', 'vehiculo', 'equipos_video_vigilancia') NOT NULL,
atributos JSON NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (recursos_sucursal_id) REFERENCES recursos_sucursal(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
}
} catch(PDOException $e) {
error_log("Error DB: " . $e->getMessage());
$error = "Error al inicializar base de datos.";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_recursos'])) {
try {
$empresa_id = (int)$_POST['empresa_id'];
$sucursal_id = (int)$_POST['sucursal_id'];
$personal_id = !empty($_POST['personal_id']) ? (int)$_POST['personal_id'] : null;
$observaciones = sanitizeInput($_POST['observaciones'] ?? '');
$stmt = $conn->prepare("SELECT id FROM sucursales WHERE id = ? AND empresa_id = ? AND activa = TRUE");
$stmt->execute([$sucursal_id, $empresa_id]);
if (!$stmt->fetch()) {
throw new Exception('La sucursal no pertenece a la empresa seleccionada');
}
if ($personal_id) {
$stmt = $conn->prepare("SELECT id FROM personal WHERE id = ? AND sucursal_id = ? AND activo = TRUE");
$stmt->execute([$personal_id, $sucursal_id]);
if (!$stmt->fetch()) {
throw new Exception('El personal no pertenece a la sucursal seleccionada');
}
}
$recurso_id = isset($_POST['recurso_id']) ? (int)$_POST['recurso_id'] : null;
$archivo_pdf_path = null;
$eliminar_pdf = isset($_POST['eliminar_pdf']) && $_POST['eliminar_pdf'] == '1';
$datos_antiguos = null;
$accion_auditoria = 'CREACION_RECURSO';
if ($recurso_id) {
$accion_auditoria = 'MODIFICACION_RECURSO';
$stmt = $conn->prepare("SELECT * FROM recursos_sucursal WHERE id = ?");
$stmt->execute([$recurso_id]);
$datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
}
if ($recurso_id) {
$stmt = $conn->prepare("SELECT archivo_pdf FROM recursos_sucursal WHERE id = ?");
$stmt->execute([$recurso_id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
$archivo_pdf_path = $existing['archivo_pdf'] ?? null;
if ($eliminar_pdf && $archivo_pdf_path) {
deletePDF($archivo_pdf_path);
$archivo_pdf_path = null;
}
$stmt = $conn->prepare("UPDATE recursos_sucursal SET observaciones = ?, estado = 'pendiente', updated_at = NOW() WHERE id = ?");
$stmt->execute([$observaciones, $recurso_id]);
$recursos_sucursal_id = $recurso_id;
$stmt = $conn->prepare("DELETE FROM recursos_items WHERE recursos_sucursal_id = ?");
$stmt->execute([$recursos_sucursal_id]);
$mensaje = 'Solicitud de actualización enviada para aprobación del administrador';
} else {
$stmt = $conn->prepare("INSERT INTO recursos_sucursal (empresa_id, sucursal_id, personal_id, observaciones, estado) VALUES (?, ?, ?, ?, 'pendiente')");
$stmt->execute([$empresa_id, $sucursal_id, $personal_id, $observaciones]);
$recursos_sucursal_id = $conn->lastInsertId();
$mensaje = 'Solicitud de recursos enviada para aprobacion';
}
if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
if ($archivo_pdf_path) {
deletePDF($archivo_pdf_path);
}
$archivo_pdf_path = uploadPDF($_FILES['archivo_pdf'], $recursos_sucursal_id);
$stmt = $conn->prepare("UPDATE recursos_sucursal SET archivo_pdf = ? WHERE id = ?");
$stmt->execute([$archivo_pdf_path, $recursos_sucursal_id]);
} elseif ($eliminar_pdf) {
$stmt = $conn->prepare("UPDATE recursos_sucursal SET archivo_pdf = NULL WHERE id = ?");
$stmt->execute([$recursos_sucursal_id]);
}
$stmt = $conn->prepare("
INSERT INTO notificaciones_recursos (recursos_sucursal_id, usuario_id, tipo, mensaje, created_at)
SELECT ?, id, 'pendiente', 'Nueva solicitud de recursos pendiente de aprobacion', NOW()
FROM usuarios WHERE rol = 'administrador'
");
$stmt->execute([$recursos_sucursal_id]);
$tipos_recursos = ['chaleco', 'equipo_comunicacion', 'armamento', 'vehiculo', 'equipos_video_vigilancia'];
$items_count = 0;
$detalles_items = [];
foreach ($tipos_recursos as $tipo) {
if (isset($_POST["items_$tipo"]) && is_array($_POST["items_$tipo"])) {
$detalles_items[$tipo] = count($_POST["items_$tipo"]);
foreach ($_POST["items_$tipo"] as $item) {
if (!empty(array_filter($item))) {
$atributos_json = json_encode($item, JSON_UNESCAPED_UNICODE);
$stmt = $conn->prepare("INSERT INTO recursos_items (recursos_sucursal_id, tipo_recurso, atributos) VALUES (?, ?, ?)");
$stmt->execute([$recursos_sucursal_id, $tipo, $atributos_json]);
$items_count++;
}
}
}
}
$detalles_auditoria = [
'empresa_id' => $empresa_id,
'sucursal_id' => $sucursal_id,
'personal_id' => $personal_id,
'observaciones' => $observaciones,
'total_items' => $items_count,
'distribucion_items' => $detalles_items,
'archivo_pdf' => $archivo_pdf_path
];
if ($datos_antiguos) {
$detalles_auditoria['datos_antiguos'] = $datos_antiguos;
}
logAuditoria($conn, $accion_auditoria, 'recursos_sucursal', $recursos_sucursal_id, $detalles_auditoria, $user['id']);
$_SESSION['success'] = $mensaje;
header('Location: recursos.php');
exit;
} catch(Exception $e) {
$_SESSION['error'] = 'Error: ' . $e->getMessage();
$form_data = $_POST;
}
}
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
try {
$id = (int)$_POST['id'];
$stmt = $conn->prepare("SELECT archivo_pdf FROM recursos_sucursal WHERE id = ?");
$stmt->execute([$id]);
$recurso_data = $stmt->fetch(PDO::FETCH_ASSOC);
if ($recurso_data && $recurso_data['archivo_pdf']) {
deletePDF($recurso_data['archivo_pdf']);
}
$stmt = $conn->prepare("DELETE FROM recursos_sucursal WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'ELIMINACION_RECURSO', 'recursos_sucursal', $id, [
'acción' => 'Eliminación de recursos',
'recurso_id' => $id,
'archivo_pdf_eliminado' => $recurso_data['archivo_pdf'] ?? null
], $user['id']);
echo json_encode(['success' => true, 'message' => 'Recursos eliminados']);
exit;
} catch(PDOException $e) {
echo json_encode(['success' => false, 'message' => 'Error al eliminar']);
exit;
}
}
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sucursales = [];
$personales = [];
if (isset($_POST['empresa_id']) && !empty($_POST['empresa_id'])) {
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activa = TRUE ORDER BY nombre");
$stmt->execute([(int)$_POST['empresa_id']]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if (isset($_POST['sucursal_id']) && !empty($_POST['sucursal_id'])) {
$stmt = $conn->prepare("
SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo, dni, cargo
FROM personal
WHERE sucursal_id = ? AND activo = TRUE
ORDER BY apellido, nombre
");
$stmt->execute([(int)$_POST['sucursal_id']]);
$personales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$recurso_edit = null;
$items_edit = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
$edit_id = (int)$_GET['edit'];
$stmt = $conn->prepare("
SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN usuarios u ON rs.aprobado_por = u.id
WHERE rs.id = ?
");
$stmt->execute([$edit_id]);
$recurso_edit = $stmt->fetch(PDO::FETCH_ASSOC);
if ($recurso_edit) {
$stmt = $conn->prepare("SELECT * FROM recursos_items WHERE recursos_sucursal_id = ? ORDER BY tipo_recurso, id");
$stmt->execute([$edit_id]);
$items_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($items_db as $item) {
$items_edit[$item['tipo_recurso']][] = json_decode($item['atributos'], true);
}
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activa = TRUE ORDER BY nombre");
$stmt->execute([$recurso_edit['empresa_id']]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->prepare("
SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo, dni, cargo
FROM personal
WHERE sucursal_id = ? AND activo = TRUE
ORDER BY apellido, nombre
");
$stmt->execute([$recurso_edit['sucursal_id']]);
$personales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}
$where_clauses = [];
$params = [];
if (!empty($search_empresa) && is_numeric($search_empresa)) {
$where_clauses[] = "rs.empresa_id = ?";
$params[] = (int)$search_empresa;
}
if (!empty($search_sucursal) && is_numeric($search_sucursal)) {
$where_clauses[] = "rs.sucursal_id = ?";
$params[] = (int)$search_sucursal;
}
if (!empty($search_personal)) {
$where_clauses[] = "CONCAT(p.nombre, ' ', p.apellido) LIKE ?";
$params[] = '%' . $search_personal . '%';
}
if ($search_tipo_recurso !== 'todos') {
$where_clauses[] = "ri.tipo_recurso = ?";
$params[] = $search_tipo_recurso;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "rs.estado = ?";
$params[] = $search_estado;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$count_stmt = $conn->prepare("
SELECT COUNT(DISTINCT rs.id) as total
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN usuarios u ON rs.aprobado_por = u.id
LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
$where_sql
");
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);
$stmt = $conn->prepare("
SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo,
rs.estado, rs.motivo_rechazo, rs.fecha_aprobacion, rs.aprobado_por,
u.username as aprobado_por_username,
SUM(CASE WHEN ri.tipo_recurso = 'chaleco' THEN 1 ELSE 0 END) as chaleco_count,
SUM(CASE WHEN ri.tipo_recurso = 'equipo_comunicacion' THEN 1 ELSE 0 END) as comunicacion_count,
SUM(CASE WHEN ri.tipo_recurso = 'armamento' THEN 1 ELSE 0 END) as armamento_count,
SUM(CASE WHEN ri.tipo_recurso = 'vehiculo' THEN 1 ELSE 0 END) as vehiculo_count,
SUM(CASE WHEN ri.tipo_recurso = 'equipos_video_vigilancia' THEN 1 ELSE 0 END) as video_count,
COUNT(ri.id) as total_items
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN usuarios u ON rs.aprobado_por = u.id
LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
$where_sql
GROUP BY rs.id
ORDER BY $order_column $order_direction
LIMIT $records_per_page OFFSET $offset
");
$stmt->execute($params);
$recursos_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filter_params = '';
if (!empty($search_empresa)) $filter_params .= '&search_empresa=' . urlencode($search_empresa);
if (!empty($search_sucursal)) $filter_params .= '&search_sucursal=' . urlencode($search_sucursal);
if (!empty($search_personal)) $filter_params .= '&search_personal=' . urlencode($search_personal);
if ($search_tipo_recurso !== 'todos') $filter_params .= '&search_tipo_recurso=' . urlencode($search_tipo_recurso);
if ($search_estado !== 'todos') $filter_params .= '&search_estado=' . urlencode($search_estado);
$stmt = $conn->query("SELECT COUNT(*) as total FROM recursos_sucursal");
$total_asignaciones = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as pendientes FROM recursos_sucursal WHERE estado = 'pendiente'");
$pendientes_count = $stmt->fetch()['pendientes'];
$items_chaleco = $items_edit['chaleco'] ?? [['Modelo' => '', 'Marca' => '', 'Talla' => '', 'Nivel de Proteccion NIJ' => '', 'Serial' => '', 'Fecha de Vencimiento' => '', 'Estado' => '']];
$items_com = $items_edit['equipo_comunicacion'] ?? [['Modelo' => '', 'Marca' => '', 'Tipo' => '', 'Numero de Serie' => '', 'Frecuencia Operativa (MHz)' => '', 'Cantidad de Canales' => '', 'Estado' => '']];
$items_arm = $items_edit['armamento'] ?? [['Tipo' => '', 'Marca' => '', 'Modelo' => '', 'Calibre' => '', 'Numero de Registro RENAR' => '', 'Serial Arma' => '', 'Estado' => '']];
$items_veh = $items_edit['vehiculo'] ?? [['Tipo' => '', 'Marca' => '', 'Modelo' => '', 'Ano' => '', 'Patente' => '', 'Numero de Chasis' => '', 'Numero de Motor' => '', 'Kilometraje Actual' => '', 'VTV Vencimiento' => '', 'Estado' => '']];
$items_vid = $items_edit['equipos_video_vigilancia'] ?? [['Tipo' => '', 'Marca' => '', 'Modelo' => '', 'Numero de Serie' => '', 'Ubicacion Fisica' => '', 'Estado' => '']];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Recursos - Sistema de Seguridad</title>
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
/* Secciones de recursos */
.detalles-section {
background: #f8f9fa;
border-radius: 4px;
padding: 15px;
margin: 10px 0;
border-left: 4px solid var(--primary-color);
}
.detalles-section.chaleco { border-left-color: #27ae60; }
.detalles-section.comunicacion { border-left-color: #3498db; }
.detalles-section.armamento { border-left-color: #e74c3c; }
.detalles-section.vehiculo { border-left-color: #9b59b6; }
.detalles-section.video { border-left-color: #f39c12; }
.items-table th {
background-color: #f8f9fa !important;
color: #495057;
}
.section-title-resource {
background: #f8f9fa;
color: #495057;
padding: 10px 15px;
border-radius: 4px;
margin: 15px 0 10px 0;
font-weight: 600;
display: flex;
align-items: center;
cursor: pointer;
border: 1px solid var(--card-border);
}
.section-title-resource:hover {
background: #e9ecef;
}
.section-title-resource i {
margin-right: 10px;
transition: transform 0.3s;
}
.section-title-resource.collapsed i {
transform: rotate(-90deg);
}
/* Alertas */
.alert-pendientes {
background: #fff3cd;
border-left: 4px solid #f39c12;
border-radius: 4px;
padding: 15px;
margin-bottom: 20px;
}
.estado-fecha {
display: block;
margin-top: 5px;
font-size: 0.75rem;
color: #6c757d;
}
.estado-fecha i {
margin-right: 4px;
}
.badge-pendiente { background: #ffc107 !important; color: #000; }
.badge-aprobado { background: #28a745 !important; color: #fff; }
.badge-rechazado { background: #dc3545 !important; color: #fff; }
.bg-purple { background-color: #9b59b6 !important; }
.bg-purple:hover { background-color: #8e44ad !important; }
.loading {
display: none;
text-align: center;
padding: 20px;
}
.loading-spinner {
border: 4px solid rgba(0, 0, 0, 0.1);
border-radius: 50%;
border-top: 4px solid var(--primary-color);
width: 40px;
height: 40px;
animation: spin 1s linear infinite;
margin: 0 auto 10px;
}
@keyframes spin {
0% { transform: rotate(0deg); }
100% { transform: rotate(360deg); }
}
</style>
</head>
<body>
<!-- HEADER -->
<?php $page_title = 'Gestión de Recursos'; include '../includes/header.php'; ?>
<div class="dashboard">
<!-- SIDEBAR -->
<?php include '../includes/sidebar.php'; ?>
<!-- CONTENIDO PRINCIPAL -->
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<!-- MENSAJES -->
<?php if ($pendientes_count > 0): ?>
<div class="alert-pendientes">
<div class="d-flex align-items-center justify-content-between">
<div>
<i class="fas fa-bell fa-2x text-warning me-3"></i>
<strong class="fs-5">Tienes <span class="text-warning"><?php echo $pendientes_count; ?></span> solicitudes pendientes de aprobación</strong>
<p class="mb-0 mt-2 text-muted">Revisa y aprueba las solicitudes de recursos de las empresas</p>
</div>
<a href="recursos.php?search_estado=pendiente" class="btn btn-warning btn-lg">
<i class="fas fa-eye me-2"></i>Ver Pendientes
</a>
</div>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="fas fa-check-circle"></i> <?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-boxes fa-2x"></i></div>
<div class="stat-number"><?php echo $total_asignaciones; ?></div>
<div class="stat-label">Total Asignaciones</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $pendientes_count; ?></div>
<div class="stat-label">Pendientes</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-vest fa-2x"></i></div>
<div class="stat-number"><?php echo $total_asignaciones - $pendientes_count; ?></div>
<div class="stat-label">Procesadas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-shield-alt fa-2x"></i></div>
<div class="stat-number"><?php echo count($recursos_list); ?></div>
<div class="stat-label">En Página</div>
</div>
</div>
<!-- ? FILTROS DE BÚSQUEDA - CON COLLAPSE -->
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
<select name="search_empresa" class="form-select" id="searchEmpresaSelect">
<option value="">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($search_empresa == $empresa['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Sucursal</label>
<select name="search_sucursal" class="form-select" id="searchSucursalSelect">
<option value="">Todas las sucursales</option>
<?php if (!empty($search_empresa)): ?>
<?php
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activa = TRUE ORDER BY nombre");
$stmt->execute([$search_empresa]);
$sucursales_filter = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sucursales_filter as $sucursal):
?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($search_sucursal == $sucursal['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($sucursal['nombre']); ?>
</option>
<?php endforeach; ?>
<?php endif; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Personal</label>
<input type="text" name="search_personal" class="form-control" value="<?php echo htmlspecialchars($search_personal); ?>" placeholder="Nombre...">
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="search_estado" class="form-select">
<option value="todos" <?php echo ($search_estado === 'todos') ? 'selected' : ''; ?>>Todos</option>
<option value="pendiente" <?php echo ($search_estado === 'pendiente') ? 'selected' : ''; ?>>Pendientes</option>
<option value="aprobado" <?php echo ($search_estado === 'aprobado') ? 'selected' : ''; ?>>Aprobados</option>
<option value="rechazado" <?php echo ($search_estado === 'rechazado') ? 'selected' : ''; ?>>Rechazados</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Tipo de Recurso</label>
<select name="search_tipo_recurso" class="form-select">
<option value="todos" <?php echo ($search_tipo_recurso === 'todos') ? 'selected' : ''; ?>>Todos</option>
<option value="chaleco" <?php echo ($search_tipo_recurso === 'chaleco') ? 'selected' : ''; ?>>Chalecos</option>
<option value="equipo_comunicacion" <?php echo ($search_tipo_recurso === 'equipo_comunicacion') ? 'selected' : ''; ?>>Comunicación</option>
<option value="armamento" <?php echo ($search_tipo_recurso === 'armamento') ? 'selected' : ''; ?>>Armamento</option>
<option value="vehiculo" <?php echo ($search_tipo_recurso === 'vehiculo') ? 'selected' : ''; ?>>Vehículos</option>
<option value="equipos_video_vigilancia" <?php echo ($search_tipo_recurso === 'equipos_video_vigilancia') ? 'selected' : ''; ?>>Video</option>
</select>
</div>
<div class="col-12 d-flex gap-2">
<button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
<a href="recursos.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
<button type="button" class="btn btn-success" onclick="exportarPDF()"><i class="fas fa-file-pdf me-2"></i>Exportar PDF</button>
</div>
</form>
</div>  <!-- ? CIERRA contenidoFiltros -->
</div>  <!-- ? CIERRA section-box -->
<!-- ? ASIGNAR RECURSOS - CON COLLAPSE -->
<div class="section-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="d-flex justify-content-between align-items-center section-title"
data-bs-toggle="collapse"
data-bs-target="#asignarRecursosForm"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<span><i class="fas fa-plus-circle me-2"></i><?php echo $recurso_edit ? 'Editar Recursos' : 'Asignar Recursos'; ?></span>
<div class="d-flex align-items-center gap-2">
<i class="fas fa-chevron-down" id="iconoAsignarRecursos"></i>
</div>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto, expandido si hay edición) -->
<div class="collapse mt-3 <?php echo $recurso_edit ? 'show' : ''; ?>" id="asignarRecursosForm">
<h5 class="mb-3"><i class="fas fa-boxes me-2"></i><?php echo $recurso_edit ? 'Editar Asignación de Recursos' : 'Registrar Nueva Asignación'; ?></h5>
<form method="POST" action="" class="row g-3" id="recursosForm" enctype="multipart/form-data">
<?php if ($recurso_edit): ?>
<input type="hidden" name="recurso_id" value="<?php echo $recurso_edit['id']; ?>">
<?php endif; ?>
<div class="col-md-6">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<select name="empresa_id" class="form-control" required id="empresaSelect">
<option value="">Seleccione...</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($recurso_edit && $recurso_edit['empresa_id'] == $empresa['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal <span class="text-danger">*</span></label>
<select name="sucursal_id" class="form-control" required id="sucursalSelect">
<option value="">Seleccione...</option>
<?php foreach ($sucursales as $sucursal): ?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($recurso_edit && $recurso_edit['sucursal_id'] == $sucursal['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($sucursal['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Personal Asignado <span class="text-muted">(Opcional)</span></label>
<select name="personal_id" class="form-control" id="personalSelect">
<option value="">-- Recursos para la Sucursal --</option>
<?php foreach ($personales as $personal): ?>
<option value="<?php echo $personal['id']; ?>" <?php echo ($recurso_edit && $recurso_edit['personal_id'] == $personal['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($personal['nombre_completo']); ?>
<?php if(!empty($personal['dni'])): ?> (DNI: <?php echo htmlspecialchars($personal['dni']); ?>) <?php endif; ?>
<?php if(!empty($personal['cargo'])): ?> - <?php echo htmlspecialchars($personal['cargo']); ?> <?php endif; ?>
</option>
<?php endforeach; ?>
</select>
<small class="text-muted"><i class="fas fa-info-circle"></i> Si no selecciona personal, los recursos quedarán asignados a la sucursal</small>
</div>
<div class="col-md-6">
<label class="form-label"><i class="fas fa-file-pdf me-2"></i>Archivo PDF (Opcional)</label>
<div class="input-group">
<input type="file" name="archivo_pdf" class="form-control" accept=".pdf,application/pdf" id="archivoPdfInput">
<?php if ($recurso_edit && !empty($recurso_edit['archivo_pdf'])): ?>
<a href="../<?php echo htmlspecialchars($recurso_edit['archivo_pdf']); ?>" target="_blank" class="btn btn-outline-primary">
<i class="fas fa-eye"></i> Ver PDF Actual
</a>
<?php endif; ?>
</div>
<?php if ($recurso_edit && !empty($recurso_edit['archivo_pdf'])): ?>
<div class="form-check mt-2">
<input type="checkbox" class="form-check-input" name="eliminar_pdf" id="eliminarPdf" value="1">
<label class="form-check-label" for="eliminarPdf">Eliminar PDF actual</label>
</div>
<?php endif; ?>
</div>
<!-- Chalecos -->
<div class="col-12">
<div class="section-title-resource" data-target="chaleco-section">
<i class="fas fa-chevron-down"></i><i class="fas fa-vest"></i> Chalecos <span class="badge bg-success ms-2" id="chaleco-count">0</span>
</div>
<div class="detalles-section chaleco" id="chaleco-section">
<div class="table-responsive">
<table class="table table-bordered items-table">
<thead><tr><th>Modelo</th><th>Marca</th><th>Talla</th><th>Nivel NIJ</th><th>Serial</th><th>Vencimiento</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
<tbody id="chaleco-items-body">
<?php foreach ($items_chaleco as $index => $item): ?>
<tr>
<td><input type="text" name="items_chaleco[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
<td><select name="items_chaleco[<?php echo $index; ?>][Marca]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Second Chance', 'Point Blank', 'American Body Armor', 'Safariland', 'Otra'] as $marca): ?><option value="<?php echo $marca; ?>" <?php echo ($item['Marca'] ?? '') == $marca ? 'selected' : ''; ?>><?php echo $marca; ?></option><?php endforeach; ?></select></td>
<td><select name="items_chaleco[<?php echo $index; ?>][Talla]" class="form-control"><option value="">Seleccione...</option><?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $talla): ?><option value="<?php echo $talla; ?>" <?php echo ($item['Talla'] ?? '') == $talla ? 'selected' : ''; ?>><?php echo $talla; ?></option><?php endforeach; ?></select></td>
<td><select name="items_chaleco[<?php echo $index; ?>][Nivel de Proteccion NIJ]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Nivel IIA', 'Nivel II', 'Nivel IIIA', 'Nivel III', 'Nivel IV'] as $nivel): ?><option value="<?php echo $nivel; ?>" <?php echo ($item['Nivel de Proteccion NIJ'] ?? '') == $nivel ? 'selected' : ''; ?>><?php echo $nivel; ?></option><?php endforeach; ?></select></td>
<td><input type="text" name="items_chaleco[<?php echo $index; ?>][Serial]" class="form-control" value="<?php echo htmlspecialchars($item['Serial'] ?? ''); ?>"></td>
<td><input type="date" name="items_chaleco[<?php echo $index; ?>][Fecha de Vencimiento]" class="form-control" value="<?php echo htmlspecialchars($item['Fecha de Vencimiento'] ?? ''); ?>"></td>
<td><select name="items_chaleco[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Nuevo', 'En uso', 'Reparacion', 'Vencido', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
<td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<button type="button" class="btn btn-sm btn-success add-item-btn" onclick="addItem('chaleco')"><i class="fas fa-plus"></i> Agregar Chaleco</button>
</div>
</div>
<!-- Comunicación -->
<div class="col-12">
<div class="section-title-resource" data-target="comunicacion-section">
<i class="fas fa-chevron-down"></i><i class="fas fa-headset"></i> Equipos de Comunicación <span class="badge bg-info ms-2" id="equipo_comunicacion-count">0</span>
</div>
<div class="detalles-section comunicacion" id="comunicacion-section">
<div class="table-responsive">
<table class="table table-bordered items-table">
<thead><tr><th>Modelo</th><th>Marca</th><th>Tipo</th><th>N° Serie</th><th>Frecuencia</th><th>Canales</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
<tbody id="equipo_comunicacion-items-body">
<?php foreach ($items_com as $index => $item): ?>
<tr>
<td><input type="text" name="items_equipo_comunicacion[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
<td><select name="items_equipo_comunicacion[<?php echo $index; ?>][Marca]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Motorola', 'Kenwood', 'Hytera', 'Icom', 'Baofeng', 'Otra'] as $marca): ?><option value="<?php echo $marca; ?>" <?php echo ($item['Marca'] ?? '') == $marca ? 'selected' : ''; ?>><?php echo $marca; ?></option><?php endforeach; ?></select></td>
<td><select name="items_equipo_comunicacion[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Portatil', 'Movil (Vehicular)', 'Base Fija'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
<td><input type="text" name="items_equipo_comunicacion[<?php echo $index; ?>][Numero de Serie]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Serie'] ?? ''); ?>"></td>
<td><input type="text" name="items_equipo_comunicacion[<?php echo $index; ?>][Frecuencia Operativa (MHz)]" class="form-control" value="<?php echo htmlspecialchars($item['Frecuencia Operativa (MHz)'] ?? ''); ?>"></td>
<td><input type="number" name="items_equipo_comunicacion[<?php echo $index; ?>][Cantidad de Canales]" class="form-control" value="<?php echo htmlspecialchars($item['Cantidad de Canales'] ?? ''); ?>"></td>
<td><select name="items_equipo_comunicacion[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'Reparacion', 'Fuera de servicio', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
<td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<button type="button" class="btn btn-sm btn-info add-item-btn" onclick="addItem('equipo_comunicacion')"><i class="fas fa-plus"></i> Agregar Equipo</button>
</div>
</div>
<!-- Armamento -->
<div class="col-12">
<div class="section-title-resource" data-target="armamento-section">
<i class="fas fa-chevron-down"></i><i class="fas fa-gun"></i> Armamento <span class="badge bg-danger ms-2" id="armamento-count">0</span>
</div>
<div class="detalles-section armamento" id="armamento-section">
<div class="table-responsive">
<table class="table table-bordered items-table">
<thead><tr><th>Tipo</th><th>Marca</th><th>Modelo</th><th>Calibre</th><th>N° RENAR</th><th>Serial</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
<tbody id="armamento-items-body">
<?php foreach ($items_arm as $index => $item): ?>
<tr>
<td><select name="items_armamento[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Pistola', 'Revolver', 'Escopeta', 'Rifle', 'Carabina', 'Otro'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
<td><input type="text" name="items_armamento[<?php echo $index; ?>][Marca]" class="form-control" value="<?php echo htmlspecialchars($item['Marca'] ?? ''); ?>"></td>
<td><input type="text" name="items_armamento[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
<td><select name="items_armamento[<?php echo $index; ?>][Calibre]" class="form-control"><option value="">Seleccione...</option><?php foreach (['9mm', '.40 S&W', '.45 ACP', '12 Gauge', '5.56mm', '.223 Rem', 'Otro'] as $calibre): ?><option value="<?php echo $calibre; ?>" <?php echo ($item['Calibre'] ?? '') == $calibre ? 'selected' : ''; ?>><?php echo $calibre; ?></option><?php endforeach; ?></select></td>
<td><input type="text" name="items_armamento[<?php echo $index; ?>][Numero de Registro RENAR]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Registro RENAR'] ?? ''); ?>"></td>
<td><input type="text" name="items_armamento[<?php echo $index; ?>][Serial Arma]" class="form-control" value="<?php echo htmlspecialchars($item['Serial Arma'] ?? ''); ?>"></td>
<td><select name="items_armamento[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'Reparacion', 'Fuera de servicio', 'Decomiso', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
<td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<button type="button" class="btn btn-sm btn-danger add-item-btn" onclick="addItem('armamento')"><i class="fas fa-plus"></i> Agregar Armamento</button>
</div>
</div>
<!-- Vehículos -->
<div class="col-12">
<div class="section-title-resource" data-target="vehiculo-section">
<i class="fas fa-chevron-down"></i><i class="fas fa-car"></i> Vehículos <span class="badge bg-purple ms-2" id="vehiculo-count">0</span>
</div>
<div class="detalles-section vehiculo" id="vehiculo-section">
<div class="table-responsive">
<table class="table table-bordered items-table">
<thead><tr><th>Tipo</th><th>Marca</th><th>Modelo</th><th>Año</th><th>Patente</th><th>Chasis</th><th>Motor</th><th>Kms</th><th>VTV</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
<tbody id="vehiculo-items-body">
<?php foreach ($items_veh as $index => $item): ?>
<tr>
<td><select name="items_vehiculo[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Sedan', 'Pickup', 'Utilitario', 'Camioneta', 'Moto', 'Furgon', 'Otro'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
<td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Marca]" class="form-control" value="<?php echo htmlspecialchars($item['Marca'] ?? ''); ?>"></td>
<td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
<td><input type="number" name="items_vehiculo[<?php echo $index; ?>][Ano]" class="form-control" value="<?php echo htmlspecialchars($item['Ano'] ?? ''); ?>"></td>
<td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Patente]" class="form-control" value="<?php echo htmlspecialchars($item['Patente'] ?? ''); ?>"></td>
<td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Numero de Chasis]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Chasis'] ?? ''); ?>"></td>
<td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Numero de Motor]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Motor'] ?? ''); ?>"></td>
<td><input type="number" name="items_vehiculo[<?php echo $index; ?>][Kilometraje Actual]" class="form-control" value="<?php echo htmlspecialchars($item['Kilometraje Actual'] ?? ''); ?>"></td>
<td><input type="date" name="items_vehiculo[<?php echo $index; ?>][VTV Vencimiento]" class="form-control" value="<?php echo htmlspecialchars($item['VTV Vencimiento'] ?? ''); ?>"></td>
<td><select name="items_vehiculo[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'En taller', 'Fuera de servicio', 'Siniestrado', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
<td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<button type="button" class="btn btn-sm btn-purple add-item-btn" onclick="addItem('vehiculo')"><i class="fas fa-plus"></i> Agregar Vehículo</button>
</div>
</div>
<!-- Video Vigilancia -->
<div class="col-12">
<div class="section-title-resource" data-target="video-section">
<i class="fas fa-chevron-down"></i><i class="fas fa-video"></i> Video Vigilancia <span class="badge bg-warning ms-2" id="equipos_video_vigilancia-count">0</span>
</div>
<div class="detalles-section video" id="video-section">
<div class="table-responsive">
<table class="table table-bordered items-table">
<thead><tr><th>Tipo</th><th>Marca</th><th>Modelo</th><th>N° Serie</th><th>Ubicación</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
<tbody id="equipos_video_vigilancia-items-body">
<?php foreach ($items_vid as $index => $item): ?>
<tr>
<td><select name="items_equipos_video_vigilancia[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Camara Dome', 'Camara Bullet', 'Camara PTZ', 'Camara Oculta', 'DVR', 'NVR', 'Monitor', 'Otro'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
<td><select name="items_equipos_video_vigilancia[<?php echo $index; ?>][Marca]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Hikvision', 'Dahua', 'Axis', 'Bosch', 'Samsung', 'TP-Link', 'Otra'] as $marca): ?><option value="<?php echo $marca; ?>" <?php echo ($item['Marca'] ?? '') == $marca ? 'selected' : ''; ?>><?php echo $marca; ?></option><?php endforeach; ?></select></td>
<td><input type="text" name="items_equipos_video_vigilancia[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
<td><input type="text" name="items_equipos_video_vigilancia[<?php echo $index; ?>][Numero de Serie]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Serie'] ?? ''); ?>"></td>
<td><input type="text" name="items_equipos_video_vigilancia[<?php echo $index; ?>][Ubicacion Fisica]" class="form-control" value="<?php echo htmlspecialchars($item['Ubicacion Fisica'] ?? ''); ?>"></td>
<td><select name="items_equipos_video_vigilancia[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'Parcialmente Operativo', 'Reparacion', 'Fuera de servicio', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
<td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<button type="button" class="btn btn-sm btn-warning add-item-btn" onclick="addItem('equipos_video_vigilancia')"><i class="fas fa-plus"></i> Agregar Equipo</button>
</div>
</div>
<div class="col-12">
<label class="form-label">Observaciones Generales</label>
<textarea name="observaciones" class="form-control" rows="3"><?php echo $recurso_edit ? htmlspecialchars($recurso_edit['observaciones']) : ''; ?></textarea>
</div>
<div class="col-12 text-end">
<button type="submit" name="guardar_recursos" class="btn btn-success btn-lg px-5">
<i class="fas fa-save me-2"></i> <?php echo $recurso_edit ? 'Actualizar Recursos' : 'Asignar Recursos'; ?>
</button>
<?php if ($recurso_edit): ?>
<a href="recursos.php" class="btn btn-secondary btn-lg px-5 ms-2"><i class="fas fa-times me-2"></i> Cancelar</a>
<?php endif; ?>
</div>
</form>
</div>  <!-- ? CIERRA asignarRecursosForm -->
</div>  <!-- ? CIERRA section-box -->
<!-- LISTADO DE RECURSOS -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Recursos Asignados
<span class="badge bg-primary ms-2"><?php echo $total_records; ?> registros</span>
</div>
<?php if (empty($recursos_list)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-boxes fa-3x text-muted mb-3"></i>
<h5>No hay recursos asignados</h5>
<p class="text-muted"><?php echo empty($search_empresa) && $search_estado === 'todos' ? 'Registra tu primera asignación de recursos para comenzar.' : 'No se encontraron recursos con los filtros aplicados.'; ?></p>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#asignarRecursosForm">
<i class="fas fa-plus me-2"></i>Crear Primera Asignación
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th><i class="fas fa-building me-2"></i>Empresa</th>
<th><i class="fas fa-map-marker-alt me-2"></i>Sucursal</th>
<th><i class="fas fa-user me-2"></i>Personal</th>
<th><i class="fas fa-clipboard-check me-2"></i>Estado</th>
<th class="text-center"><i class="fas fa-vest me-1" style="color: #27ae60;"></i>Chalecos</th>
<th class="text-center"><i class="fas fa-headset me-1" style="color: #3498db;"></i>Comunicación</th>
<th class="text-center"><i class="fas fa-gun me-1" style="color: #e74c3c;"></i>Armamento</th>
<th class="text-center"><i class="fas fa-car me-1" style="color: #9b59b6;"></i>Vehículos</th>
<th class="text-center"><i class="fas fa-video me-1" style="color: #f39c12;"></i>Video</th>
<th class="text-center"><i class="fas fa-boxes me-1"></i>Total</th>
<th class="text-center"><i class="fas fa-calendar-alt me-1"></i>Fecha</th>
<th class="text-center">Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($recursos_list as $recurso): ?>
<tr class="<?php echo ($recurso['estado'] ?? 'pendiente') === 'pendiente' ? 'table-warning' : ''; ?>">
<td><strong><?php echo htmlspecialchars($recurso['empresa_nombre']); ?></strong></td>
<td><span class="badge bg-info"><?php echo htmlspecialchars($recurso['sucursal_nombre']); ?></span></td>
<td><?php if (!empty($recurso['personal_nombre'])): ?><span class="badge bg-success"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($recurso['personal_nombre']); ?></span><?php else: ?><span class="badge bg-secondary"><i class="fas fa-building me-1"></i>Sucursal</span><?php endif; ?></td>
<td>
<?php
$estado = $recurso['estado'] ?? 'pendiente';
$badge_colors = ['pendiente' => 'pendiente', 'aprobado' => 'aprobado', 'rechazado' => 'rechazado'];
$badge_icons = ['pendiente' => 'fa-clock', 'aprobado' => 'fa-check-circle', 'rechazado' => 'fa-times-circle'];
?>
<span class="badge badge-<?php echo $badge_colors[$estado]; ?>">
<i class="fas <?php echo $badge_icons[$estado]; ?> me-1"></i>
<?php echo ucfirst($estado); ?>
</span>
<?php if (($estado === 'aprobado' || $estado === 'rechazado') && !empty($recurso['fecha_aprobacion'])): ?>
<br><small class="estado-fecha">
<i class="fas fa-calendar-check"></i>
<?php echo date('d/m/Y H:i', strtotime($recurso['fecha_aprobacion'])); ?>
</small>
<?php if (!empty($recurso['aprobado_por_username'])): ?>
<br><small class="estado-fecha">
<i class="fas fa-user"></i>
<?php echo htmlspecialchars($recurso['aprobado_por_username']); ?>
</small>
<?php endif; ?>
<?php endif; ?>
<?php if ($estado === 'rechazado' && !empty($recurso['motivo_rechazo'])): ?>
<small class="text-danger d-block mt-1"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(substr($recurso['motivo_rechazo'], 0, 50)); ?>...</small>
<?php endif; ?>
</td>
<td class="text-center"><span class="badge bg-success"><?php echo $recurso['chaleco_count']; ?></span></td>
<td class="text-center"><span class="badge bg-primary"><?php echo $recurso['comunicacion_count']; ?></span></td>
<td class="text-center"><span class="badge bg-danger"><?php echo $recurso['armamento_count']; ?></span></td>
<td class="text-center"><span class="badge bg-purple"><?php echo $recurso['vehiculo_count']; ?></span></td>
<td class="text-center"><span class="badge bg-warning text-dark"><?php echo $recurso['video_count']; ?></span></td>
<td class="text-center"><strong class="text-primary"><?php echo $recurso['total_items']; ?></strong></td>
<td class="text-center text-muted small"><?php echo date('d/m/Y', strtotime($recurso['created_at'])); ?></td>
<td class="text-center">
<div class="btn-group btn-group-sm">
<a href="#" class="btn btn-outline-secondary" onclick="event.preventDefault(); verDetallesRecurso(<?php echo $recurso['id']; ?>);" title="Ver Detalles"><i class="fas fa-eye"></i></a>
<a href="recursos.php?edit=<?php echo $recurso['id']; ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="fas fa-edit"></i></a>
<?php if ($estado === 'pendiente'): ?>
<button type="button" class="btn btn-outline-info" onclick="aprobarRecurso(<?php echo $recurso['id']; ?>)" title="Aprobar"><i class="fas fa-check"></i></button>
<button type="button" class="btn btn-outline-dark" onclick="rechazarRecurso(<?php echo $recurso['id']; ?>)" title="Rechazar"><i class="fas fa-times"></i></button>
<?php endif; ?>
<button type="button" class="btn btn-sm btn-outline-warning" data-id="<?php echo $recurso['id']; ?>" data-sucursal="<?php echo htmlspecialchars($recurso['sucursal_nombre']); ?>" title="Eliminar"><i class="fas fa-trash"></i></button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_pages > 1): ?>
<div class="d-flex justify-content-center mt-3">
<nav aria-label="Paginación de recursos">
<ul class="pagination mb-0">
<li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>"><i class="fas fa-angle-double-left"></i></a>
</li>
<li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>"><i class="fas fa-angle-left"></i></a>
</li>
<?php
$rango = 2;
$inicio = max(1, $page - $rango);
$fin = min($total_pages, $page + $rango);
if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
for ($i = $inicio; $i <= $fin; $i++):
?>
<li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
</li>
<?php endfor; ?>
<?php if ($fin < $total_pages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>
<li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>"><i class="fas fa-angle-right"></i></a>
</li>
<li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"><i class="fas fa-angle-double-right"></i></a>
</li>
</ul>
</nav>
<span class="ms-3 text-muted align-self-center">
Página <?php echo $page; ?> de <?php echo $total_pages; ?>
</span>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
<!-- MODAL ELIMINAR -->
<div class="modal fade" id="modalEliminar" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-danger text-white">
<h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p>¿Eliminar todos los recursos asignados a <strong id="sucursalEliminar"></strong>?</p>
<p class="text-danger"><i class="fas fa-exclamation-circle"></i> Esta acción no se puede deshacer.</p>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
<button type="button" class="btn btn-danger" id="confirmarEliminar"><i class="fas fa-trash"></i> Eliminar</button>
</div>
</div>
</div>
</div>
<!-- MODAL DETALLES RECURSO -->
<div class="modal fade" id="modalDetallesRecurso" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-boxes"></i> <span id="modalTituloRecurso">Detalles de Asignación de Recursos</span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<div class="loading" id="modalLoadingRecurso">
<div class="loading-spinner"></div>
<p>Cargando información de la asignación...</p>
</div>
<div id="modalContentRecurso" style="display:none;"></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cerrar</button>
</div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ? Rotar icono de flecha en sección Filtros al colapsar/expandir
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
let recursoIdEliminar = null;
let itemIndex = {
'chaleco': <?php echo !empty($items_chaleco) ? count($items_chaleco) : 1; ?>,
'equipo_comunicacion': <?php echo !empty($items_com) ? count($items_com) : 1; ?>,
'armamento': <?php echo !empty($items_arm) ? count($items_arm) : 1; ?>,
'vehiculo': <?php echo !empty($items_veh) ? count($items_veh) : 1; ?>,
'equipos_video_vigilancia': <?php echo !empty($items_vid) ? count($items_vid) : 1; ?>
};
document.addEventListener('DOMContentLoaded', function() {
// Secciones colapsables
document.querySelectorAll('.section-title-resource').forEach(section => {
section.addEventListener('click', function() {
const targetId = this.getAttribute('data-target');
const targetSection = document.getElementById(targetId);
const icon = this.querySelector('i.fa-chevron-down');
if (targetSection && (targetSection.style.display === 'none' || !targetSection.style.display)) {
targetSection.style.display = 'block';
if (icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-up'); }
this.classList.add('collapsed');
} else if (targetSection) {
targetSection.style.display = 'none';
if (icon) { icon.classList.remove('fa-chevron-up'); icon.classList.add('fa-chevron-down'); }
this.classList.remove('collapsed');
}
});
});
// Empresa Select
const empresaSelect = document.getElementById('empresaSelect');
if (empresaSelect) {
empresaSelect.addEventListener('change', function() {
const empresaId = this.value;
const sucursalSelect = document.getElementById('sucursalSelect');
const personalSelect = document.getElementById('personalSelect');
if (!empresaId) {
if (sucursalSelect) sucursalSelect.innerHTML = '<option value="">Seleccione...</option>';
if (personalSelect) personalSelect.innerHTML = '<option value="">-- Recursos para la Sucursal --</option>';
return;
}
if (sucursalSelect) sucursalSelect.innerHTML = '<option value="">Cargando...</option>';
fetch(`recursos.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
if (sucursalSelect) {
sucursalSelect.innerHTML = '<option value="">Seleccione...</option>';
data.forEach(s => {
sucursalSelect.innerHTML += `<option value="${s.id}">${s.nombre}</option>`;
});
}
if (personalSelect) personalSelect.innerHTML = '<option value="">-- Recursos para la Sucursal --</option>';
})
.catch(error => {
console.error('Error:', error);
if (sucursalSelect) sucursalSelect.innerHTML = '<option value="">Error al cargar</option>';
});
});
}
// Sucursal Select
const sucursalSelect = document.getElementById('sucursalSelect');
if (sucursalSelect) {
sucursalSelect.addEventListener('change', function() {
const sucursalId = this.value;
const personalSelect = document.getElementById('personalSelect');
if (!sucursalId) {
if (personalSelect) personalSelect.innerHTML = '<option value="">-- Recursos para la Sucursal --</option>';
return;
}
if (personalSelect) personalSelect.innerHTML = '<option value="">Cargando...</option>';
fetch(`recursos.php?action=get_personal&sucursal_id=${sucursalId}`)
.then(response => response.json())
.then(data => {
if (personalSelect) {
personalSelect.innerHTML = '<option value="">-- Recursos para la Sucursal --</option>';
data.forEach(p => {
personalSelect.innerHTML += `<option value="${p.id}">${p.nombre_completo}${p.dni ? ' (DNI: ' + p.dni + ')' : ''}${p.cargo ? ' - ' + p.cargo : ''}</option>`;
});
}
})
.catch(error => {
console.error('Error:', error);
if (personalSelect) personalSelect.innerHTML = '<option value="">Error al cargar</option>';
});
});
}
// Search Empresa Select
const searchEmpresaSelect = document.getElementById('searchEmpresaSelect');
const searchSucursalSelect = document.getElementById('searchSucursalSelect');
if (searchEmpresaSelect && searchSucursalSelect) {
searchEmpresaSelect.addEventListener('change', function() {
const empresaId = this.value;
if (!empresaId) {
searchSucursalSelect.innerHTML = '<option value="">Todas las sucursales</option>';
return;
}
searchSucursalSelect.innerHTML = '<option value="">Cargando...</option>';
fetch(`recursos.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
searchSucursalSelect.innerHTML = '<option value="">Todas las sucursales</option>';
data.forEach(s => {
searchSucursalSelect.innerHTML += `<option value="${s.id}">${s.nombre}</option>`;
});
})
.catch(error => {
console.error('Error:', error);
searchSucursalSelect.innerHTML = '<option value="">Error al cargar</option>';
});
});
}
// Update counts
['chaleco', 'equipo_comunicacion', 'armamento', 'vehiculo', 'equipos_video_vigilancia'].forEach(tipo => { updateCount(tipo); });
// Eliminar recursos
document.querySelectorAll('.eliminar-recurso').forEach(btn => {
btn.addEventListener('click', function() {
recursoIdEliminar = this.dataset.id;
const sucursalElement = document.getElementById('sucursalEliminar');
if (sucursalElement) sucursalElement.textContent = this.dataset.sucursal;
new bootstrap.Modal(document.getElementById('modalEliminar')).show();
});
});
const confirmarBtn = document.getElementById('confirmarEliminar');
if (confirmarBtn) {
confirmarBtn.addEventListener('click', function() {
if (!recursoIdEliminar) return;
fetch('recursos.php', {
method: 'POST',
headers: {'Content-Type': 'application/x-www-form-urlencoded'},
body: `action=delete&id=${recursoIdEliminar}`
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({ icon: 'success', title: 'Eliminado!', text: data.message, timer: 2000, showConfirmButton: false });
setTimeout(() => location.reload(), 2000);
} else {
Swal.fire({ icon: 'error', title: 'Error', text: data.message });
}
})
.catch(error => {
Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo eliminar el recurso' });
});
});
}
// Auto-close alerts
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
});
function aprobarRecurso(id) {
Swal.fire({
title: '¿Aprobar recursos?',
text: 'Los recursos quedarán activos inmediatamente y se notificará a la empresa',
icon: 'question',
showCancelButton: true,
confirmButtonText: 'Sí, aprobar',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#28a745',
cancelButtonColor: '#6c757d'
}).then((result) => {
if (result.isConfirmed) {
fetch('recursos.php?action=aprobar', {
method: 'POST',
headers: {'Content-Type': 'application/x-www-form-urlencoded'},
body: `id=${id}`
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({ icon: 'success', title: 'Aprobado!', text: 'Los recursos han sido activados', timer: 2000, showConfirmButton: false });
setTimeout(() => location.reload(), 2000);
} else {
Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'No se pudo aprobar' });
}
})
.catch(error => {
Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo aprobar el recurso' });
});
}
});
}
function rechazarRecurso(id) {
Swal.fire({
title: 'Rechazar recursos',
input: 'textarea',
inputLabel: 'Motivo del rechazo *',
inputPlaceholder: 'Explique por qué se rechaza esta solicitud...',
inputAttributes: {rows: 4, required: true},
showCancelButton: true,
confirmButtonText: 'Rechazar',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#dc3545',
cancelButtonColor: '#6c757d',
preConfirm: (motivo) => {
if (!motivo || motivo.trim() === '') {
Swal.showValidationMessage('Debe ingresar un motivo para el rechazo');
return false;
}
return motivo;
}
}).then((result) => {
if (result.isConfirmed && result.value) {
fetch('recursos.php?action=rechazar', {
method: 'POST',
headers: {'Content-Type': 'application/x-www-form-urlencoded'},
body: `id=${id}&motivo=${encodeURIComponent(result.value)}`
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({ icon: 'error', title: 'Rechazado!', text: 'La solicitud ha sido rechazada', timer: 2000, showConfirmButton: false });
setTimeout(() => location.reload(), 2000);
} else {
Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'No se pudo rechazar' });
}
})
.catch(error => {
Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo rechazar el recurso' });
});
}
});
}
function addItem(tipo) {
const index = itemIndex[tipo];
const tbody = document.getElementById(tipo + '-items-body');
if (!tbody) return;
const row = document.createElement('tr');
let fields = [];
if (tipo === 'chaleco') {
fields = [
{type: 'text', name: 'Modelo', value: ''},
{type: 'select', name: 'Marca', options: ['Second Chance', 'Point Blank', 'American Body Armor', 'Safariland', 'Otra']},
{type: 'select', name: 'Talla', options: ['XS', 'S', 'M', 'L', 'XL', 'XXL']},
{type: 'select', name: 'Nivel de Proteccion NIJ', options: ['Nivel IIA', 'Nivel II', 'Nivel IIIA', 'Nivel III', 'Nivel IV']},
{type: 'text', name: 'Serial', value: ''},
{type: 'date', name: 'Fecha de Vencimiento', value: ''},
{type: 'select', name: 'Estado', options: ['Nuevo', 'En uso', 'Reparacion', 'Vencido', 'Baja']}
];
} else if (tipo === 'equipo_comunicacion') {
fields = [
{type: 'text', name: 'Modelo', value: ''},
{type: 'select', name: 'Marca', options: ['Motorola', 'Kenwood', 'Hytera', 'Icom', 'Baofeng', 'Otra']},
{type: 'select', name: 'Tipo', options: ['Portatil', 'Movil (Vehicular)', 'Base Fija']},
{type: 'text', name: 'Numero de Serie', value: ''},
{type: 'text', name: 'Frecuencia Operativa (MHz)', value: ''},
{type: 'number', name: 'Cantidad de Canales', value: ''},
{type: 'select', name: 'Estado', options: ['Operativo', 'Reparacion', 'Fuera de servicio', 'Baja']}
];
} else if (tipo === 'armamento') {
fields = [
{type: 'select', name: 'Tipo', options: ['Pistola', 'Revolver', 'Escopeta', 'Rifle', 'Carabina', 'Otro']},
{type: 'text', name: 'Marca', value: ''},
{type: 'text', name: 'Modelo', value: ''},
{type: 'select', name: 'Calibre', options: ['9mm', '.40 S&W', '.45 ACP', '12 Gauge', '5.56mm', '.223 Rem', 'Otro']},
{type: 'text', name: 'Numero de Registro RENAR', value: ''},
{type: 'text', name: 'Serial Arma', value: ''},
{type: 'select', name: 'Estado', options: ['Operativo', 'Reparacion', 'Fuera de servicio', 'Decomiso', 'Baja']}
];
} else if (tipo === 'vehiculo') {
fields = [
{type: 'select', name: 'Tipo', options: ['Sedan', 'Pickup', 'Utilitario', 'Camioneta', 'Moto', 'Furgon', 'Otro']},
{type: 'text', name: 'Marca', value: ''},
{type: 'text', name: 'Modelo', value: ''},
{type: 'number', name: 'Ano', value: ''},
{type: 'text', name: 'Patente', value: ''},
{type: 'text', name: 'Numero de Chasis', value: ''},
{type: 'text', name: 'Numero de Motor', value: ''},
{type: 'number', name: 'Kilometraje Actual', value: ''},
{type: 'date', name: 'VTV Vencimiento', value: ''},
{type: 'select', name: 'Estado', options: ['Operativo', 'En taller', 'Fuera de servicio', 'Siniestrado', 'Baja']}
];
} else if (tipo === 'equipos_video_vigilancia') {
fields = [
{type: 'select', name: 'Tipo', options: ['Camara Dome', 'Camara Bullet', 'Camara PTZ', 'Camara Oculta', 'DVR', 'NVR', 'Monitor', 'Otro']},
{type: 'select', name: 'Marca', options: ['Hikvision', 'Dahua', 'Axis', 'Bosch', 'Samsung', 'TP-Link', 'Otra']},
{type: 'text', name: 'Modelo', value: ''},
{type: 'text', name: 'Numero de Serie', value: ''},
{type: 'text', name: 'Ubicacion Fisica', value: ''},
{type: 'select', name: 'Estado', options: ['Operativo', 'Parcialmente Operativo', 'Reparacion', 'Fuera de servicio', 'Baja']}
];
}
fields.forEach((field) => {
const cell = document.createElement('td');
if (field.type === 'select') {
let select = document.createElement('select');
select.className = 'form-control';
select.name = `items_${tipo}[${index}][${field.name}]`;
let defaultOption = document.createElement('option');
defaultOption.value = '';
defaultOption.textContent = 'Seleccione...';
select.appendChild(defaultOption);
field.options.forEach(opt => {
let option = document.createElement('option');
option.value = opt;
option.textContent = opt;
select.appendChild(option);
});
cell.appendChild(select);
} else {
let input = document.createElement('input');
input.type = field.type;
input.className = 'form-control';
input.name = `items_${tipo}[${index}][${field.name}]`;
input.value = field.value || '';
cell.appendChild(input);
}
row.appendChild(cell);
});
const actionCell = document.createElement('td');
actionCell.innerHTML = '<i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i>';
row.appendChild(actionCell);
tbody.appendChild(row);
itemIndex[tipo]++;
updateCount(tipo);
}
function removeItem(element) {
const row = element.closest('tr');
if (!row) return;
const tipo = row.parentElement.id.replace('-items-body', '');
row.remove();
updateCount(tipo);
}
function updateCount(tipo) {
const count = document.querySelectorAll(`#${tipo}-items-body tr`).length;
const countElement = document.getElementById(`${tipo}-count`);
if (countElement) countElement.textContent = count;
}
function verDetallesRecurso(id) {
const modal = new bootstrap.Modal(document.getElementById('modalDetallesRecurso'));
const loading = document.getElementById('modalLoadingRecurso');
const content = document.getElementById('modalContentRecurso');
if (!loading || !content) return;
loading.style.display = 'block';
content.style.display = 'none';
content.innerHTML = '';
modal.show();
fetch(`recursos.php?action=get_recurso_details&id=${id}`)
.then(response => response.json())
.then(data => {
if (data.error) throw new Error(data.error);
const estadoBadges = {
'pendiente': '<span class="badge badge-pendiente"><i class="fas fa-clock"></i> Pendiente</span>',
'aprobado': '<span class="badge badge-aprobado"><i class="fas fa-check-circle"></i> Aprobado</span>',
'rechazado': '<span class="badge badge-rechazado"><i class="fas fa-times-circle"></i> Rechazado</span>'
};
let html = `<div class="row"><div class="col-md-12"><div class="detalles-section"><div class="section-title"><i class="fas fa-building"></i> Información de Asignación</div><div class="row g-3">
<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Empresa</div><div class="fw-bold">${data.empresa_nombre}</div></div></div>
<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Sucursal</div><div class="fw-bold">${data.sucursal_nombre}</div></div></div>`;
if (data.personal_nombre) {
html += `<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Personal Asignado</div><div class="fw-bold"><i class="fas fa-user me-1"></i>${data.personal_nombre}</div></div></div>`;
if (data.dni) html += `<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">DNI</div><div class="fw-bold">${data.dni}</div></div></div>`;
if (data.cargo) html += `<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Cargo</div><div class="fw-bold">${data.cargo}</div></div></div>`;
} else {
html += `<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Asignación</div><div class="fw-bold"><i class="fas fa-building me-1"></i>Recursos para la Sucursal</div></div></div>`;
}
html += `<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Estado</div><div class="fw-bold">${estadoBadges[data.estado] || estadoBadges['pendiente']}</div></div></div>
<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Fecha de Creación</div><div class="fw-bold">${data.created_at ? new Date(data.created_at).toLocaleString('es-AR') : 'N/A'}</div></div></div>
<div class="col-md-4"><div class="p-2 bg-light rounded"><div class="text-muted small">Última Actualización</div><div class="fw-bold">${data.updated_at ? new Date(data.updated_at).toLocaleString('es-AR') : 'N/A'}</div></div></div></div></div>`;
if (data.archivo_pdf) {
html += `<div class="detalles-section" style="border-left-color: #dc3545;">
<div class="section-title" style="color: #dc3545;">
<i class="fas fa-file-pdf"></i> Documento Adjunto
</div>
<div class="p-2 bg-light rounded">
<a href="../${data.archivo_pdf}" target="_blank" class="btn btn-danger btn-sm">
<i class="fas fa-file-pdf me-2"></i>Ver PDF
</a>
<a href="../${data.archivo_pdf}" download class="btn btn-outline-danger btn-sm ms-2">
<i class="fas fa-download me-2"></i>Descargar
</a>
</div>
</div>`;
}
if (data.observaciones) {
html += `<div class="detalles-section"><div class="section-title"><i class="fas fa-sticky-note"></i> Observaciones</div><div class="p-2 bg-light rounded" style="white-space: pre-wrap;">${data.observaciones}</div></div>`;
}
if (data.estado === 'rechazado' && data.motivo_rechazo) {
html += `<div class="detalles-section" style="border-left-color: #dc3545;"><div class="section-title" style="color: #dc3545;"><i class="fas fa-times-circle"></i> Motivo de Rechazo</div><div class="p-2 bg-light rounded" style="white-space: pre-wrap; color: #dc3545;">${data.motivo_rechazo}</div></div>`;
}
if (data.fecha_aprobacion) {
html += `<div class="detalles-section" style="border-left-color: #28a745;"><div class="section-title" style="color: #28a745;"><i class="fas fa-check-circle"></i> Fecha de Aprobación/Rechazo</div><div class="p-2 bg-light rounded">${new Date(data.fecha_aprobacion).toLocaleString('es-AR')}</div></div>`;
}
if (data.aprobado_por_username) {
html += `<div class="detalles-section" style="border-left-color: #4361ee;"><div class="section-title" style="color: #4361ee;"><i class="fas fa-user-shield"></i> Aprobado/Rechazado Por</div><div class="p-2 bg-light rounded">${data.aprobado_por_username}</div></div>`;
}
const tipos = [
{ key: 'chaleco', label: 'Chalecos', icon: 'fa-vest', badge: 'bg-success' },
{ key: 'equipo_comunicacion', label: 'Equipos de Comunicación', icon: 'fa-headset', badge: 'bg-info' },
{ key: 'armamento', label: 'Armamento', icon: 'fa-gun', badge: 'bg-danger' },
{ key: 'vehiculo', label: 'Vehículos', icon: 'fa-car', badge: 'bg-purple' },
{ key: 'equipos_video_vigilancia', label: 'Video Vigilancia', icon: 'fa-video', badge: 'bg-warning' }
];
tipos.forEach(tipo => {
if (data.items[tipo.key] && data.items[tipo.key].length > 0) {
html += `<div class="detalles-section"><div class="section-title"><i class="fas ${tipo.icon}"></i> ${tipo.label} (${data.items[tipo.key].length})</div><div class="table-responsive"><table class="table table-sm"><thead><tr>${Object.keys(data.items[tipo.key][0]).map(attr => `<th>${attr}</th>`).join('')}</tr></thead><tbody>${data.items[tipo.key].map(item => `<tr>${Object.values(item).map(val => `<td>${val || '-'}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`;
}
});
html += `</div></div>`;
content.innerHTML = html;
const tituloElement = document.getElementById('modalTituloRecurso');
if (tituloElement) tituloElement.textContent = `Detalles: ${data.empresa_nombre} - ${data.sucursal_nombre}`;
loading.style.display = 'none';
content.style.display = 'block';
})
.catch(error => {
console.error('Error:', error);
loading.innerHTML = `<div class="alert alert-danger text-center p-4"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><div><strong>Error al cargar los datos</strong></div><small>${error.message}</small></div>`;
});
}
function exportarPDF() {
const params = new URLSearchParams(window.location.search);
const order = params.get('order') || 'empresa_nombre';
const direction = params.get('direction') || 'ASC';
const url = `recursos.php?action=exportar_pdf&search_empresa=${encodeURIComponent('<?php echo addslashes($search_empresa); ?>')}&search_sucursal=${encodeURIComponent('<?php echo addslashes($search_sucursal); ?>')}&search_personal=${encodeURIComponent('<?php echo addslashes($search_personal); ?>')}&search_tipo_recurso=${encodeURIComponent('<?php echo addslashes($search_tipo_recurso); ?>')}&search_estado=${encodeURIComponent('<?php echo addslashes($search_estado); ?>')}&order=${order}&direction=${direction}`;
window.location.href = url;
}
</script>
</body>
</html>