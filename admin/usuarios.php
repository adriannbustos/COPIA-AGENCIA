<?php
// [M3] Verificar sesión activa antes de acceder a $_SESSION
if (session_status() !== PHP_SESSION_ACTIVE) {
session_start();
}
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// [C2] Validar sesión contra BD — requireValidSession() redirige al login si falla
require_once '../config/session_manager.php';
requireValidSession();
// [C4] Revalidar rol desde BD, no desde $_SESSION
$rol = getUserRoleFromDB((int) $_SESSION['usuario_id']);
if ($rol !== 'administrador') {
header('Location: ../login.php');
exit;
}
// ==================== GENERACIÓN DE CREDENCIALES (INDIVIDUAL Y LOTE) ====================
// Generar Credencial Individual
if (isset($_GET['action']) && $_GET['action'] === 'generar_qr_usuario') {
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['rol'] !== 'administrador') {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2>');
}
ob_clean();
$conn = getDBConnection();
$usuario_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$usuario_id) die('<h2 style="color:red;text-align:center;padding:50px">❌ ID de usuario no válido</h2>');
try {
$stmt = $conn->prepare("
SELECT u.id, u.username, u.nombre_completo, u.email, u.rol as role, u.jurisdiccion, u.created_at, u.empresa_id,
e.nombre as empresa_nombre
FROM usuarios u
LEFT JOIN empresas e ON u.empresa_id = e.id
WHERE u.id = :id AND (u.activo = 1 OR u.activo IS NULL)
");
$stmt->execute(['id' => $usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuario) die('<h2 style="color:red;text-align:center;padding:50px">❌ Usuario no encontrado</h2>');
} catch(PDOException $e) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Error al cargar datos</h2>');
}
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
class PDF_Credencial_Usuario extends FPDF {
function Header() {}
function Footer() {}
}
$pdf = new PDF_Credencial_Usuario();
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage('L', array(85.6, 54));
// Fondo (si existe)
$fondo_frente = '../uploads/fondos_credenciales/fondo_frente.jpg';
if (file_exists($fondo_frente)) $pdf->Image($fondo_frente, 0, 0, 85.6, 54, 'JPEG');
$watermark_path = '../uploads/fondos_credenciales/logo-policia-chubut.png';
if (file_exists($watermark_path)) $pdf->Image($watermark_path, 42.8, 10, 25, 25, 'PNG');
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(0, 2);
$pdf->Cell(85.6, 6, 'EMPRESA DE SEGURIDAD', 0, 0, 'C');
// Placeholder de Foto
$pdf->SetDrawColor(200, 200, 200);
$pdf->Rect(4, 10, 28, 32);
$pdf->SetXY(4, 19);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(200, 200, 200);
$pdf->Cell(28, 8, 'SIN', 0, 0, 'C');
$pdf->SetXY(4, 24);
$pdf->Cell(28, 8, 'FOTO', 0, 0, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(4, 43);
$pdf->Cell(28, 4, 'Usuario:', 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetXY(4, 47);
$pdf->Cell(28, 6, $usuario['username'], 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(34, 12);
$pdf->Cell(50, 5, 'Puesto: ' . strtoupper(utf8_decode($usuario['role'] ?? 'N/A')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 17);
$pdf->Cell(50, 5, 'Nombre: ' . strtoupper(utf8_decode($usuario['nombre_completo'] ?? '')), 0, 0, 'L');
$pdf->SetXY(34, 22);
$pdf->Cell(50, 5, 'Empresa: ' . strtoupper(utf8_decode($usuario['empresa_nombre'] ?? 'N/A')), 0, 0, 'L');
$fecha_inicio = $usuario['created_at'] ? date('d/m/Y', strtotime($usuario['created_at'])) : date('d/m/Y');
$fecha_fin = date('d/m/Y', strtotime('+1 year', strtotime($fecha_inicio)));
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 27);
$pdf->Cell(50, 5, 'Vigencia: ' . $fecha_inicio . ' al ' . $fecha_fin, 0, 0, 'L');
if(!empty($usuario['jurisdiccion'])) {
$pdf->SetXY(34, 32);
$pdf->Cell(50, 5, 'Jurisdicción: ' . strtoupper(utf8_decode($usuario['jurisdiccion'])), 0, 0, 'L');
}
// QR Generation
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$expiracion_timestamp = time() + (380 * 24 * 60 * 60);
$payload_token = $usuario_id . '|' . $expiracion_timestamp;
$security_token = hash_hmac('sha256', $payload_token, $secret_key);
$verify_url = $protocol . $domain . '/agencia_seguridad/verificar_usuario.php?id=' . $usuario_id . '&exp=' . $expiracion_timestamp . '&token=' . $security_token;
$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($verify_url);
$qr_temp = sys_get_temp_dir() . '/qr_credencial_u_' . $usuario_id . '_' . time() . '.png';
file_put_contents($qr_temp, file_get_contents($qr_api));
if (file_exists($qr_temp)) {
$pdf->Image($qr_temp, 26.8, 8, 32, 32);
unlink($qr_temp);
}
$pdf->SetXY(0, 42);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(85.6, 5, 'AREA INVESTIGACIONES(A2)', 0, 0, 'C');
$nombre_archivo = 'Credencial_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $usuario['nombre_completo'] . '_' . $usuario['username']) . '.pdf';
$pdf->Output('D', $nombre_archivo);
exit;
}
// Generar Credenciales por Lote
if (isset($_POST['action']) && $_POST['action'] === 'generar_qr_usuarios_lote') {
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['rol'] !== 'administrador') {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2>');
}
ob_clean();
$conn = getDBConnection();
$tamano_lote = 40;
$usuario_ids = isset($_POST['usuario_ids']) && is_array($_POST['usuario_ids'])
? array_map('intval', $_POST['usuario_ids']) : [];
if (empty($usuario_ids)) die('<h2 style="color:red;text-align:center;padding:50px">❌ No se seleccionó usuarios</h2>');
$maximo_personal = $tamano_lote * 5;
if (count($usuario_ids) > $maximo_personal) die('<h2 style="color:red;text-align:center;padding:50px">❌ Máximo ' . $maximo_personal . ' credenciales por solicitud</h2>');
if (!file_exists('../vendor/fpdf/fpdf.php')) die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
require_once '../vendor/fpdf/fpdf.php';
class PDF_Credencial_Usuario_Lote extends FPDF {
function Header() {}
function Footer() {}
}
$chunks = array_chunk($usuario_ids, $tamano_lote);
$total_lotes = count($chunks);
foreach ($chunks as $index => $chunk_ids) {
$pdf = new PDF_Credencial_Usuario_Lote();
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$credenciales_en_este_lote = 0;
foreach ($chunk_ids as $uid) {
try {
$stmt = $conn->prepare("
SELECT u.id, u.username, u.nombre_completo, u.rol as role, u.jurisdiccion, u.created_at, u.empresa_id,
e.nombre as empresa_nombre
FROM usuarios u
LEFT JOIN empresas e ON u.empresa_id = e.id
WHERE u.id = :id AND (u.activo = 1 OR u.activo IS NULL)
");
$stmt->execute(['id' => $uid]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) continue;
$pdf->AddPage('L', array(85.6, 54));
$fondo_frente = '../uploads/fondos_credenciales/fondo_frente.jpg';
if (file_exists($fondo_frente)) $pdf->Image($fondo_frente, 0, 0, 85.6, 54, 'JPEG');
$watermark_path = '../uploads/fondos_credenciales/logo-policia-chubut.png';
if (file_exists($watermark_path)) $pdf->Image($watermark_path, 42.8, 10, 25, 25, 'PNG');
$pdf->SetFont('Arial', 'B', 13); $pdf->SetXY(0, 2); $pdf->Cell(85.6, 6, 'EMPRESA DE SEGURIDAD', 0, 0, 'C');
$pdf->SetDrawColor(200, 200, 200); $pdf->Rect(4, 10, 28, 32);
$pdf->SetXY(4, 19); $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(200, 200, 200); $pdf->Cell(28, 8, 'SIN', 0, 0, 'C');
$pdf->SetXY(4, 24); $pdf->Cell(28, 8, 'FOTO', 0, 0, 'C'); $pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 8); $pdf->SetXY(4, 43); $pdf->Cell(28, 4, 'Usuario:', 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 14); $pdf->SetXY(4, 47); $pdf->Cell(28, 6, $u['username'], 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 9); $pdf->SetXY(34, 12); $pdf->Cell(50, 5, 'Puesto: ' . strtoupper(utf8_decode($u['role'])), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8); $pdf->SetXY(34, 17); $pdf->Cell(50, 5, 'Nombre: ' . strtoupper(utf8_decode($u['nombre_completo'] ?? '')), 0, 0, 'L');
$pdf->SetXY(34, 22); $pdf->Cell(50, 5, 'Empresa: ' . strtoupper(utf8_decode($u['empresa_nombre'] ?? 'N/A')), 0, 0, 'L');
$fecha_inicio = $u['created_at'] ? date('d/m/Y', strtotime($u['created_at'])) : date('d/m/Y');
$fecha_fin = date('d/m/Y', strtotime('+1 year', strtotime($fecha_inicio)));
$pdf->SetXY(34, 27); $pdf->Cell(50, 5, 'Vigencia: ' . $fecha_inicio . ' al ' . $fecha_fin, 0, 0, 'L');
if(!empty($u['jurisdiccion'])) {
$pdf->SetXY(34, 32); $pdf->Cell(50, 5, 'Jurisdicción: ' . strtoupper(utf8_decode($u['jurisdiccion'])), 0, 0, 'L');
}
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$expiracion_timestamp = time() + (380 * 24 * 60 * 60);
$security_token = hash_hmac('sha256', $uid . '|' . $expiracion_timestamp, $secret_key);
$verify_url = $protocol . $domain . '/agencia_seguridad/verificar_usuario.php?id=' . $uid . '&exp=' . $expiracion_timestamp . '&token=' . $security_token;
$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($verify_url);
$qr_temp = sys_get_temp_dir() . '/qr_u_' . $uid . '_' . time() . '.png';
$qr_content = @file_get_contents($qr_api);
if ($qr_content) {
file_put_contents($qr_temp, $qr_content);
if (file_exists($qr_temp)) { $pdf->Image($qr_temp, 26.8, 8, 32, 32); unlink($qr_temp); }
}
$pdf->SetXY(0, 42); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(85.6, 5, 'AREA INVESTIGACIONES(A2)', 0, 0, 'C');
$credenciales_en_este_lote++;
} catch(Exception $e) { continue; }
}
if ($credenciales_en_este_lote > 0) {
$suffix = ($total_lotes > 1) ? '_Parte_' . ($index + 1) : '';
$nombre_archivo = 'Credenciales_Usuarios_Lote_' . date('Ymd_His') . $suffix . '.pdf';
$pdf->Output('D', $nombre_archivo);
if ($index < ($total_lotes - 1)) {
echo "<script>
setTimeout(function() {
if(confirm('✅ PDF Parte " . ($index + 1) . " generado.\
\
¿Desea continuar generando la siguiente parte?')) {
window.location.reload();
} else { window.close(); }
}, 1000);
</script>";
exit;
}
}
}
exit;
}
// ==================== FIN DE ACCIONES DE CREDENCIALES ====================
$current_page = 'usuarios';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ==================== GENERAR TOKEN CSRF ====================
if (empty($_SESSION['csrf_token'])) {
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// ==================== ESTANDARIZAR COLUMNA ROL Y CREAR FALTANTES ====================
$columna_rol = 'rol';
try {
$columns = $conn->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('role', $columns) && !in_array('rol', $columns)) {
$conn->exec("ALTER TABLE usuarios CHANGE COLUMN role rol VARCHAR(50) NOT NULL DEFAULT 'operador'");
} elseif (!in_array('rol', $columns) && !in_array('role', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN rol VARCHAR(50) NOT NULL DEFAULT 'operador' AFTER password");
}
if (!in_array('nombre_completo', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN nombre_completo VARCHAR(100) NULL DEFAULT NULL AFTER username");
}
if (!in_array('jurisdiccion', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN jurisdiccion VARCHAR(50) NULL DEFAULT NULL AFTER empresa_id");
}
if (!in_array('empresa_id', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN empresa_id INT NULL DEFAULT NULL AFTER rol");
}
if (!in_array('updated_at', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER empresa_id");
}
if (!in_array('ultimo_login', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN ultimo_login TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
}
if (!in_array('activo', $columns)) {
$conn->exec("ALTER TABLE usuarios ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol");
}
$stmt_check = $conn->query("DESCRIBE usuarios password");
$pass_info = $stmt_check->fetch(PDO::FETCH_ASSOC);
if ($pass_info && strpos($pass_info['Type'], 'varchar') !== false) {
preg_match('/varchar\((\d+)\)/i', $pass_info['Type'], $matches);
if (isset($matches[1]) && $matches[1] < 255) {
$conn->exec("ALTER TABLE usuarios MODIFY COLUMN password VARCHAR(255) NOT NULL");
}
}
} catch(PDOException $e) {
error_log("Error estructura tabla usuarios: " . $e->getMessage());
$error = "Error de base de datos: " . $e->getMessage();
}
// ==================== ROLES DISPONIBLES ====================
$roles_disponibles = [
'administrador' => ['nombre' => 'Administrador', 'icono' => 'fa-user-shield', 'color' => '#e74c3c', 'descripcion' => 'Acceso completo al sistema'],
'carga' => ['nombre' => 'Carga', 'icono' => 'fa-database', 'color' => '#f39c12', 'descripcion' => 'Gestión de datos y recursos'],
'operador' => ['nombre' => 'Operador', 'icono' => 'fa-user-cog', 'color' => '#3498db', 'descripcion' => 'Operaciones diarias'],
'auditor' => ['nombre' => 'Auditor', 'icono' => 'fa-clipboard-check', 'color' => '#9b59b6', 'descripcion' => 'Auditoría y reportes'],
'empresa' => ['nombre' => 'Empresa', 'icono' => 'fa-building', 'color' => '#27ae60', 'descripcion' => 'Gestión de empresas asignadas'],
'supervisor' => ['nombre' => 'Supervisor', 'icono' => 'fa-user-tie', 'color' => '#f39c12', 'descripcion' => 'Supervisión de operaciones'],
'usuario' => ['nombre' => 'Usuario', 'icono' => 'fa-user', 'color' => '#3498db', 'descripcion' => 'Acceso limitado']
];
$jurisdicciones_disponibles = ['Esquel' => 'Esquel', 'Comodoro' => 'Comodoro', 'Puerto Madryn' => 'Puerto Madryn', 'Trelew' => 'Trelew', 'Rawson' => 'Rawson'];
$empresas_disponibles = [];
try {
$stmt = $conn->query("SELECT id, nombre, nombre_comercial FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) { $empresas_disponibles = []; }
// ==================== FILTROS Y BÚSQUEDA ====================
$search_username = $_GET['search_username'] ?? '';
$search_role = $_GET['search_role'] ?? 'todos';
$search_empresa = $_GET['search_empresa'] ?? '';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$columnas_permitidas = ['id', 'username', 'email', 'rol', 'ultimo_login', 'created_at'];
$orden_columna = $_GET['orden'] ?? 'username';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'ASC');
if (!in_array($orden_columna, $columnas_permitidas)) $orden_columna = 'username';
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') $orden_direccion = 'ASC';
$usuarios = []; $total_registros = 0; $total_paginas = 0;
try {
$where_clauses = []; $params = [];
$where_clauses[] = "(u.activo = 1 OR u.activo IS NULL)";
if (!empty($search_username)) {
$search_username_escaped = addcslashes($search_username, '%_');
$where_clauses[] = "(u.username LIKE ? OR u.email LIKE ? OR u.nombre_completo LIKE ?)";
$params[] = '%' . $search_username_escaped . '%'; $params[] = '%' . $search_username_escaped . '%'; $params[] = '%' . $search_username_escaped . '%';
}
if ($search_role !== 'todos') { $where_clauses[] = "u.rol = ?"; $params[] = $search_role; }
if (!empty($search_empresa)) { $where_clauses[] = "u.empresa_id = ?"; $params[] = (int)$search_empresa; }
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$count_sql = "SELECT COUNT(*) as total FROM usuarios u $where_sql";
$stmt_count = $conn->prepare($count_sql); $stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$orden_sql = "ORDER BY $orden_columna $orden_direccion";
$limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
$sql = "SELECT u.id, u.username, u.nombre_completo, u.email, u.rol as role, u.ultimo_login, u.created_at, u.empresa_id, u.jurisdiccion, e.nombre as empresa_nombre FROM usuarios u LEFT JOIN empresas e ON u.empresa_id = e.id $where_sql $orden_sql $limit_sql";
$stmt = $conn->prepare($sql); $stmt->execute($params); $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
$usuarios = []; $error = "Error cargando usuarios: " . htmlspecialchars($e->getMessage());
}
// ==================== GUARDAR/ACTUALIZAR USUARIO ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_usuario'])) {
try {
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) throw new Exception('Token CSRF inválido');
$username = trim($_POST['username'] ?? ''); $nombre_completo = trim($_POST['nombre_completo'] ?? '');
$jurisdiccion = !empty($_POST['jurisdiccion']) ? trim($_POST['jurisdiccion']) : null; $email = trim($_POST['email'] ?? '');
$role = trim($_POST['role'] ?? 'operador'); $password = $_POST['password'] ?? null;
$usuario_id = $_POST['usuario_id'] ?? null; $empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
if (!array_key_exists($role, $roles_disponibles)) throw new Exception('Rol inválido');
if (empty($username) || empty($email)) throw new Exception('El nombre de usuario y email son obligatorios');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email inválido');
if ($role === 'empresa' && empty($empresa_id)) throw new Exception('Debe seleccionar una empresa para este rol');
if ($role === 'operador' && empty($jurisdiccion)) throw new Exception('Debe seleccionar una jurisdicción para este rol');
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?"); $stmt->execute([$username, $usuario_id ?? 0]);
if ($stmt->fetch() && !$usuario_id) throw new Exception('El nombre de usuario ya existe');
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?"); $stmt->execute([$email, $usuario_id ?? 0]);
if ($stmt->fetch() && !$usuario_id) throw new Exception('El email ya está registrado');
if ($usuario_id) {
if (!empty($password)) {
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
if (!$hashed_password || strlen($hashed_password) > 255) throw new Exception('Error al procesar la contraseña');
$stmt = $conn->prepare("UPDATE usuarios SET username=?, nombre_completo=?, email=?, rol=?, password=?, empresa_id=?, jurisdiccion=?, updated_at=NOW() WHERE id=?");
$stmt->execute([$username, $nombre_completo, $email, $role, $hashed_password, $empresa_id, $jurisdiccion, $usuario_id]);
} else {
$stmt = $conn->prepare("UPDATE usuarios SET username=?, nombre_completo=?, email=?, rol=?, empresa_id=?, jurisdiccion=?, updated_at=NOW() WHERE id=?");
$stmt->execute([$username, $nombre_completo, $email, $role, $empresa_id, $jurisdiccion, $usuario_id]);
}
$mensaje = 'Usuario actualizado correctamente';
} else {
if (empty($password)) throw new Exception('La contraseña es obligatoria para nuevos usuarios');
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
if (!$hashed_password || strlen($hashed_password) > 255) throw new Exception('Error al procesar la contraseña');
$stmt = $conn->prepare("INSERT INTO usuarios (username, nombre_completo, email, password, rol, empresa_id, jurisdiccion, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
$stmt->execute([$username, $nombre_completo, $email, $hashed_password, $role, $empresa_id, $jurisdiccion]);
$mensaje = 'Usuario creado correctamente';
}
$_SESSION['success'] = $mensaje;
header('Location: usuarios.php'); exit;
} catch(Exception $e) {
$_SESSION['error'] = 'Error: ' . $e->getMessage(); $form_data = $_POST;
}
}
// ==================== DESACTIVAR USUARIO (SOFT DELETE) ====================
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
try {
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']); exit; }
$id = (int)$_POST['id'];
if ($id == $user['id']) { echo json_encode(['success' => false, 'message' => 'No puedes desactivarte a ti mismo']); exit; }
$stmt = $conn->prepare("UPDATE usuarios SET activo = 0, updated_at = NOW() WHERE id = ?"); $stmt->execute([$id]);
logAuditoria($conn, 'usuario_desactivado', 'usuarios', $id, ['id' => $id, 'activo' => 0]);
echo json_encode(['success' => true, 'message' => 'Usuario desactivado correctamente']); exit;
} catch(PDOException $e) {
echo json_encode(['success' => false, 'message' => 'Error al desactivar usuario']); exit;
}
}
$usuario_edit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
$edit_id = (int)$_GET['edit'];
$stmt = $conn->prepare("SELECT id, username, nombre_completo, email, rol as role, empresa_id, jurisdiccion FROM usuarios WHERE id = ?");
$stmt->execute([$edit_id]); $usuario_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}
function generarUrlOrden($columna, $orden_columna, $orden_direccion) {
$params = $_GET; $params['orden'] = $columna; $params['direccion'] = ($columna === $orden_columna && $orden_direccion === 'ASC') ? 'DESC' : 'ASC';
unset($params['pagina']); return '?' . http_build_query($params);
}
function mostrarIconoOrden($columna, $orden_columna, $orden_direccion) {
if ($columna === $orden_columna) return $orden_direccion === 'ASC' ? '<i class="fas fa-sort-up ms-1"></i>' : '<i class="fas fa-sort-down ms-1"></i>';
return '<i class="fas fa-sort ms-1 text-white-50"></i>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<meta name="theme-color" content="#4361ee">
<title>Gestión de Usuarios - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
:root { --primary-color: #4361ee; --secondary-color: #3a0ca3; --sidebar-width: 280px; --header-height: 80px; --header-height-mobile: 65px; }
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { padding-top: var(--header-height); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%); min-height: 100vh; overflow-x: hidden; }
.dashboard { display: flex; min-height: calc(100vh - var(--header-height)); padding-top: 20px; }
.main-content { padding: 30px 40px 40px 40px !important; margin-left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)); transition: margin-left 0.35s ease; }
.stats-container-modern { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; margin: 30px 0 40px; }
.stat-card-modern { background: white; border-radius: 24px; padding: 28px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); border: 1px solid rgba(0, 0, 0, 0.05); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.stat-card-modern:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18); }
.filter-section-modern { background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%); border-radius: 28px; padding: 35px 40px; margin-bottom: 35px; box-shadow: 0 10px 40px rgba(67, 97, 238, 0.12); border: 1px solid rgba(67, 97, 238, 0.1); }
.filter-header-modern { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 15px; }
.filter-title-modern { display: flex; align-items: center; gap: 14px; margin: 0; }
.filter-title-modern .icon-box { width: 50px; height: 50px; background: linear-gradient(135deg, #4361ee, #3a0ca3); border-radius: 16px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.3rem; }
.filter-title-modern h3 { font-weight: 800; font-size: 1.4rem; color: #2c3e50; margin: 0; }
.filter-form-modern .form-label { font-weight: 600; color: #4a5568; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.filter-form-modern .form-label i { color: #4361ee; width: 20px; text-align: center; }
.filter-form-modern .form-control, .filter-form-modern .form-select { border: 2px solid #e2e8f0; border-radius: 14px; padding: 14px 18px; font-size: 0.95rem; transition: all 0.3s ease; }
.filter-form-modern .form-control:focus, .filter-form-modern .form-select:focus { border-color: #4361ee; box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15); outline: none; }
.filter-actions-modern { display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 10px; flex-wrap: wrap; }
.btn-filter-modern { background: linear-gradient(135deg, #4361ee, #3a0ca3); border: none; color: white; padding: 14px 32px; border-radius: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s ease; }
.btn-filter-modern:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(67, 97, 238, 0.5); }
.btn-reset-modern { background: linear-gradient(135deg, #f8f9fa, #e9ecef); border: 2px solid #e2e8f0; color: #4a5568; padding: 14px 28px; border-radius: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s ease; }
.section-header-modern { display: flex; justify-content: space-between; align-items: center; padding: 20px 30px; background: linear-gradient(135deg, #2c3e50, #1a252f); border-radius: 20px; margin-bottom: 25px; }
.section-header-modern h2 { color: white; margin: 0; font-weight: 700; }
.table-container-modern { background: white; border-radius: 20px; box-shadow: 0 10px 35px rgba(0, 0, 0, 0.12); overflow: hidden; margin-bottom: 30px; }
.table-responsive-modern { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.table-modern { margin-bottom: 0; min-width: 1100px; }
.table-modern thead { background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; }
.table-modern thead th { border: none; padding: 18px 15px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.table-modern thead th a { color: white; text-decoration: none; }
.table-modern tbody tr { transition: all 0.2s; border-bottom: 1px solid #e9ecef; }
.table-modern tbody tr:hover { background-color: #f8f9fa; transform: scale(1.005); }
.table-modern tbody td { padding: 15px; vertical-align: middle; font-size: 0.95rem; }
.role-badge { padding: 6px 14px; border-radius: 20px; font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; }
.role-administrador { background: #e74c3c; color: white; }
.role-carga { background: #f39c12; color: white; }
.role-operador { background: #3498db; color: white; }
.role-auditor { background: #9b59b6; color: white; }
.role-empresa { background: #27ae60; color: white; }
.role-supervisor { background: #f39c12; color: white; }
.role-usuario { background: #3498db; color: white; }
.pagination-modern { display: flex; justify-content: center; align-items: center; gap: 10px; padding: 25px; background: #f8f9fa; border-top: 1px solid #e9ecef; flex-wrap: wrap; }
.pagination-modern .page-link { border-radius: 10px; padding: 10px 16px; color: #4361ee; border: 2px solid #e9ecef; font-weight: 600; transition: all 0.3s; }
.pagination-modern .page-link:hover { background: #4361ee; color: white; border-color: #4361ee; transform: translateY(-2px); }
.pagination-modern .page-item.active .page-link { background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; border-color: #4361ee; }
.modal-edit-responsive .modal-content { border: none; border-radius: 24px; background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%); box-shadow: 0 25px 80px rgba(67, 97, 238, 0.25); }
.modal-edit-responsive .modal-header { background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%); padding: 25px 30px; border: none; border-radius: 24px 24px 0 0; }
.modal-edit-responsive .modal-title { color: white; font-weight: 700; font-size: 1.3rem; display: flex; align-items: center; gap: 12px; }
.modal-edit-responsive .btn-close-white { background: rgba(255, 255, 255, 0.2); border: none; border-radius: 12px; width: 40px; height: 40px; opacity: 1; transition: all 0.3s ease; }
.modal-edit-responsive .btn-close-white:hover { background: rgba(255, 255, 255, 0.35); transform: rotate(90deg); }
.modal-edit-responsive .modal-body { padding: 35px 30px; max-height: 70vh; overflow-y: auto; }
.modal-edit-responsive .form-section { background: white; border-radius: 18px; padding: 25px; margin-bottom: 20px; border: 1px solid rgba(67, 97, 238, 0.1); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04); }
.modal-edit-responsive .section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid rgba(67, 97, 238, 0.15); }
.modal-edit-responsive .section-header i { color: #4361ee; width: 35px; height: 35px; background: linear-gradient(135deg, #ebf4ff, #e6f0ff); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.modal-edit-responsive .section-header span { font-weight: 700; color: #2c3e50; }
.modal-edit-responsive .form-label { font-weight: 600; color: #4a5568; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
.modal-edit-responsive .form-label i { color: #4361ee; width: 18px; text-align: center; }
.modal-edit-responsive .form-label .required { color: #e53e3e; margin-left: auto; }
.modal-edit-responsive .form-control, .modal-edit-responsive .form-select { border: 2px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; font-size: 0.95rem; transition: all 0.3s ease; }
.modal-edit-responsive .form-control:focus, .modal-edit-responsive .form-select:focus { border-color: #4361ee; box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15); outline: none; }
.modal-edit-responsive .form-info { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 0.85rem; }
.modal-edit-responsive .form-info i { color: #4361ee; }
.modal-edit-responsive .form-info .required { color: #e53e3e; font-weight: 700; }
.modal-edit-responsive .form-actions { display: flex; gap: 12px; }
.modal-edit-responsive .btn-cancel { background: linear-gradient(135deg, #f8f9fa, #e9ecef); color: #4a5568; border: 2px solid #e2e8f0; padding: 12px 28px; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s ease; }
.modal-edit-responsive .btn-cancel:hover { background: linear-gradient(135deg, #e9ecef, #dee2e6); border-color: #cbd5e0; transform: translateY(-2px); }
.modal-edit-responsive .btn-save { background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; border: none; padding: 12px 28px; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 10px; box-shadow: 0 6px 20px rgba(67, 97, 238, 0.35); transition: all 0.3s ease; }
.modal-edit-responsive .btn-save:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(67, 97, 238, 0.5); }
.empresa-section { display: none; background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border: 2px solid #27ae60; border-radius: 12px; padding: 20px; margin-top: 15px; }
.empresa-section.active { display: block; animation: fadeIn 0.3s ease; }
.jurisdiccion-section { display: none; background: linear-gradient(135deg, #e3f2fd, #bbdefb); border: 2px solid #3498db; border-radius: 12px; padding: 20px; margin-top: 15px; }
.jurisdiccion-section.active { display: block; animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.empty-state-modern { text-align: center; padding: 60px 20px; background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: 28px; margin-top: 20px; }
@media (max-width: 991px) { :root { --sidebar-width: 0px; } .main-content { margin-left: 0 !important; width: 100%; padding: 20px 25px !important; } .stats-container-modern { grid-template-columns: repeat(2, 1fr); gap: 15px; } }
@media (max-width: 767px) { body { padding-top: var(--header-height-mobile); } :root { --header-height: var(--header-height-mobile); } .stats-container-modern { grid-template-columns: 1fr; } .filter-section-modern { padding: 20px 15px; } .filter-header-modern { flex-direction: column; align-items: flex-start; } .filter-actions-modern { width: 100%; justify-content: stretch; flex-direction: column; } .table-modern { font-size: 0.75rem; min-width: 950px; } .modal-edit-responsive .modal-dialog { margin: 5px; max-width: calc(100% - 10px); } .modal-edit-responsive .modal-footer { flex-direction: column; gap: 15px; } .modal-edit-responsive .form-actions { width: 100%; justify-content: stretch; flex-direction: column; } .modal-edit-responsive .btn-cancel, .modal-edit-responsive .btn-save { width: 100%; justify-content: center; } }
@keyframes modalSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
.btn-qr { background: #17a2b8; border: none; color: white; }
.btn-lote { background: #28a745; border: none; color: white; }
</style>
</head>
<body>
<?php $page_title = 'Gestión de Usuarios'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<div class="stats-container-modern">
<div class="stat-card-modern">
<div style="width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#4361ee,#3a0ca3);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:1.9rem;"><i class="fas fa-users"></i></div>
<div style="font-size:2.8rem;font-weight:800;margin:10px 0;color:#2c3e50;"><?php echo $total_registros; ?></div>
<div style="font-size:1.25rem;font-weight:700;color:#2c3e50;margin-top:5px;">Usuarios Totales</div>
</div>
<div class="stat-card-modern">
<div style="width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#e74c3c,#c0392b);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:1.9rem;"><i class="fas fa-user-shield"></i></div>
<div style="font-size:2.8rem;font-weight:800;margin:10px 0;color:#2c3e50;"><?php echo count(array_filter($usuarios, fn($u) => ($u['role'] ?? 'operador') == 'administrador')); ?></div>
<div style="font-size:1.25rem;font-weight:700;color:#2c3e50;margin-top:5px;">Administradores</div>
</div>
</div>
<div class="filter-section-modern">
<div class="filter-header-modern">
<div class="filter-title-modern"><div class="icon-box"><i class="fas fa-filter"></i></div><h3>Filtros de Búsqueda</h3></div>
<?php if (!empty($search_username) || $search_role !== 'todos' || !empty($search_empresa)): ?>
<div style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#4361ee,#4cc9f0);color:white;padding:8px 18px;border-radius:25px;font-size:0.85rem;font-weight:700;"><i class="fas fa-check-circle"></i><span>Filtros Activos</span></div>
<?php endif; ?>
</div>
<form method="GET" action="" class="filter-form-modern">
<div class="row g-4">
<div class="col-lg-4 col-md-6">
<label class="form-label"><i class="fas fa-search"></i><span>Buscar Usuario</span></label>
<input type="text" name="search_username" class="form-control" value="<?php echo htmlspecialchars($search_username); ?>" placeholder="Nombre, email o nombre completo...">
</div>
<div class="col-lg-3 col-md-6">
<label class="form-label"><i class="fas fa-user-tag"></i><span>Rol</span></label>
<select name="search_role" class="form-select"><option value="todos">📋 Todos los roles</option><?php foreach ($roles_disponibles as $role_key => $role_info): ?><option value="<?php echo $role_key; ?>" <?php echo ($search_role === $role_key) ? 'selected' : ''; ?>><?php echo $role_info['nombre']; ?></option><?php endforeach; ?></select>
</div>
<div class="col-lg-3 col-md-6">
<label class="form-label"><i class="fas fa-building"></i><span>Empresa</span></label>
<select name="search_empresa" class="form-select"><option value="">🏢 Todas las empresas</option><?php foreach ($empresas_disponibles as $emp): ?><option value="<?php echo $emp['id']; ?>" <?php echo ($search_empresa == $emp['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['nombre']); ?></option><?php endforeach; ?></select>
</div>
<div class="col-lg-2 col-md-6 d-flex align-items-end">
<div style="display:flex;align-items:center;gap:10px;padding:12px 18px;background:linear-gradient(135deg,#ebf4ff,#e6f0ff);border-radius:12px;border:1px solid rgba(67,97,238,0.2);font-size:0.9rem;color:#4361ee;font-weight:600;" class="w-100"><i class="fas fa-database"></i><span><?php echo $total_registros; ?> registros</span></div>
</div>
</div>
<div class="filter-actions-modern">
<button type="submit" class="btn-filter-modern"><i class="fas fa-search"></i><span>Filtrar Resultados</span></button>
<a href="usuarios.php" class="btn-reset-modern"><i class="fas fa-times-circle"></i><span>Limpiar Filtros</span></a>
</div>
</form>
</div>
<div class="section-header-modern">
<h2><i class="fas fa-user-plus me-2"></i>Gestión de Usuarios</h2>
<div class="d-flex gap-2">
<button type="button" class="btn btn-lote" onclick="generarLoteUsuarios()" id="btnLoteUsuarios" disabled>
<i class="fas fa-print me-1"></i>Generar Lote (40 c/u)
</button>
<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#nuevoUsuarioModal">
<i class="fas fa-plus me-1"></i>Nuevo Usuario
</button>
</div>
</div>
<h2 class="section-title-modern" style="font-weight:800;font-size:2rem;color:#2c3e50;margin:30px 0 25px;padding-bottom:15px;border-bottom:3px solid #e9ecef;display:flex;align-items:center;gap:15px;">
<i class="fas fa-table me-2"></i>Usuarios Registrados
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</h2>
<?php if (empty($usuarios)): ?>
<div class="empty-state-modern"><i class="fas fa-users fa-4x text-muted mb-3"></i><h3 class="mb-3">No hay usuarios registrados</h3><p class="mb-4">Registra tu primer usuario para comenzar.</p><button class="btn btn-success btn-lg" type="button" data-bs-toggle="modal" data-bs-target="#nuevoUsuarioModal"><i class="fas fa-plus me-2"></i>Crear Primer Usuario</button></div>
<?php else: ?>
<div class="table-container-modern">
<div class="table-responsive-modern">
<table class="table table-modern">
<thead>
<tr>
<th><input type="checkbox" id="selectAllUsuarios" onclick="toggleSelectAllUsuarios()"></th>
<th><a href="<?php echo generarUrlOrden('id', $orden_columna, $orden_direccion); ?>" class="text-white text-decoration-none">ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('username', $orden_columna, $orden_direccion); ?>" class="text-white text-decoration-none">Usuario <?php echo mostrarIconoOrden('username', $orden_columna, $orden_direccion); ?></a></th>
<th>Nombre Completo</th>
<th><a href="<?php echo generarUrlOrden('email', $orden_columna, $orden_direccion); ?>" class="text-white text-decoration-none">Email <?php echo mostrarIconoOrden('email', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('rol', $orden_columna, $orden_direccion); ?>" class="text-white text-decoration-none">Rol <?php echo mostrarIconoOrden('rol', $orden_columna, $orden_direccion); ?></a></th>
<th>Jurisdicción</th>
<th>Empresa</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($usuarios as $u): ?>
<tr>
<td><input type="checkbox" class="select-usuario" value="<?php echo $u['id']; ?>"></td>
<td><strong>#<?php echo $u['id']; ?></strong></td>
<td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
<td><?php echo !empty($u['nombre_completo']) ? htmlspecialchars($u['nombre_completo']) : '<span class="text-muted">-</span>'; ?></td>
<td><?php echo htmlspecialchars($u['email']); ?></td>
<td>
<?php $role = $u['role'] ?? 'operador'; $role_info = $roles_disponibles[$role] ?? $roles_disponibles['operador']; ?>
<span class="role-badge role-<?php echo $role; ?>"><i class="fas <?php echo $role_info['icono']; ?>"></i> <?php echo $role_info['nombre']; ?></span>
</td>
<td>
<?php if (!empty($u['jurisdiccion'])): ?>
<span class="badge bg-info text-dark"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($u['jurisdiccion']); ?></span>
<?php else: ?><span class="text-muted">-</span><?php endif; ?>
</td>
<td>
<?php if (!empty($u['empresa_nombre'])): ?>
<span class="badge bg-success"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($u['empresa_nombre']); ?></span>
<?php else: ?><span class="text-muted">-</span><?php endif; ?>
</td>
<td>
<div class="d-flex gap-1">
<a href="usuarios.php?action=generar_qr_usuario&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-qr" target="_blank" title="Generar Credencial"><i class="fas fa-id-card"></i></a>
<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editarUsuarioModal<?php echo $u['id']; ?>" title="Editar"><i class="fas fa-edit"></i></button>
<?php if ($u['id'] != $user['id']): ?>
<button type="button" class="btn btn-sm btn-outline-danger eliminar-usuario" data-id="<?php echo $u['id']; ?>" data-username="<?php echo htmlspecialchars($u['username']); ?>" title="Desactivar"><i class="fas fa-user-slash"></i></button>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_paginas > 1): ?>
<div class="pagination-modern">
<nav aria-label="Paginación de usuarios">
<ul class="pagination mb-0">
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>"><i class="fas fa-angle-double-left"></i></a></li>
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => max(1, $pagina_actual - 1)])); ?>"><i class="fas fa-angle-left"></i></a></li>
<?php $rango = 2; $inicio = max(1, $pagina_actual - $rango); $fin = min($total_paginas, $pagina_actual + $rango); if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; for ($i = $inicio; $i <= $fin; $i++): ?>
<li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a></li>
<?php endfor; if ($fin < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => min($total_paginas, $pagina_actual + 1)])); ?>"><i class="fas fa-angle-right"></i></a></li>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>"><i class="fas fa-angle-double-right"></i></a></li>
</ul>
</nav>
<span class="page-info">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> (<?php echo $total_registros; ?> registros)</span>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
<!-- MODAL NUEVO USUARIO -->
<div class="modal fade modal-edit-responsive" id="nuevoUsuarioModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-plus"></i><span>Nuevo Usuario</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
<form method="POST" action="">
<input type="hidden" name="guardar_usuario" value="1">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<div class="form-section"><div class="section-header"><i class="fas fa-user"></i><span>Información de Cuenta</span></div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label"><i class="fas fa-user"></i>Nombre de Usuario<span class="required">*</span></label><input type="text" name="username" class="form-control" required placeholder="Ej: jperez"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-id-card"></i>Nombre Completo</label><input type="text" name="nombre_completo" class="form-control" placeholder="Ej: Juan Pérez"><div class="form-text">Opcional</div></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-envelope"></i>Email<span class="required">*</span></label><input type="email" name="email" class="form-control" required placeholder="usuario@empresa.com"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-lock"></i>Contraseña<span class="required">*</span></label><input type="password" name="password" class="form-control" required minlength="8" placeholder="Mínimo 8 caracteres"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-user-tag"></i>Rol<span class="required">*</span></label><select name="role" class="form-select" required id="roleNuevo"><?php foreach ($roles_disponibles as $role_key => $role_info): ?><option value="<?php echo $role_key; ?>" data-descripcion="<?php echo $role_info['descripcion']; ?>"><?php echo $role_info['nombre']; ?></option><?php endforeach; ?></select><div class="form-text mt-2"><i class="fas fa-info-circle text-primary"></i><span id="roleDescripcionNuevo">Acceso limitado</span></div></div>
</div></div>
<div class="jurisdiccion-section" id="jurisdiccionSectionNuevo">
<div class="section-header"><i class="fas fa-map-marker-alt"></i><span>Jurisdicción Asignada</span></div>
<div class="row g-3"><div class="col-md-12"><label class="form-label"><i class="fas fa-map-pin"></i>Seleccionar Jurisdicción<span class="required">*</span></label><select name="jurisdiccion" class="form-select"><option value="">Seleccione una jurisdicción...</option><?php foreach ($jurisdicciones_disponibles as $jur_key => $jur_nombre): ?><option value="<?php echo $jur_key; ?>"><?php echo htmlspecialchars($jur_nombre); ?></option><?php endforeach; ?></select></div></div>
</div>
<div class="empresa-section" id="empresaSectionNuevo">
<div class="section-header"><i class="fas fa-building"></i><span>Empresa Asignada</span></div>
<div class="row g-3"><div class="col-md-12"><label class="form-label"><i class="fas fa-building"></i>Seleccionar Empresa<span class="required">*</span></label><select name="empresa_id" class="form-select"><option value="">Seleccione una empresa...</option><?php foreach ($empresas_disponibles as $emp): ?><option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['nombre']); ?><?php if (!empty($emp['nombre_comercial'])): ?> (<?php echo htmlspecialchars($emp['nombre_comercial']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div></div>
</div>
<div class="modal-footer"><div class="form-info"><i class="fas fa-info-circle"></i><span>Los campos marcados con <span class="required">*</span> son obligatorios</span></div><div class="form-actions"><button type="button" class="btn-cancel" data-bs-dismiss="modal"><i class="fas fa-times"></i><span>Cancelar</span></button><button type="submit" class="btn-save"><i class="fas fa-save"></i><span>Crear Usuario</span></button></div></div>
</form>
</div></div></div></div>
<!-- MODALES DE EDICIÓN -->
<?php foreach ($usuarios as $u): ?>
<div class="modal fade modal-edit-responsive" id="editarUsuarioModal<?php echo $u['id']; ?>" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-edit"></i><span>Editar Usuario: <?php echo htmlspecialchars($u['username']); ?></span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
<form method="POST" action="">
<input type="hidden" name="guardar_usuario" value="1"><input type="hidden" name="usuario_id" value="<?php echo $u['id']; ?>">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<div class="form-section"><div class="section-header"><i class="fas fa-user"></i><span>Información de Cuenta</span></div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label"><i class="fas fa-user"></i>Nombre de Usuario<span class="required">*</span></label><input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($u['username']); ?>"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-id-card"></i>Nombre Completo</label><input type="text" name="nombre_completo" class="form-control" value="<?php echo htmlspecialchars($u['nombre_completo'] ?? ''); ?>"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-envelope"></i>Email<span class="required">*</span></label><input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($u['email']); ?>"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-lock"></i>Contraseña</label><input type="password" name="password" class="form-control" minlength="8" placeholder="Dejar en blanco para no cambiar"></div>
<div class="col-md-6"><label class="form-label"><i class="fas fa-user-tag"></i>Rol<span class="required">*</span></label><select name="role" class="form-select" required id="roleEdit<?php echo $u['id']; ?>"><?php foreach ($roles_disponibles as $role_key => $role_info): ?><option value="<?php echo $role_key; ?>" <?php echo ($u['role'] ?? 'operador') == $role_key ? 'selected' : ''; ?> data-descripcion="<?php echo $role_info['descripcion']; ?>"><?php echo $role_info['nombre']; ?></option><?php endforeach; ?></select><div class="form-text mt-2"><i class="fas fa-info-circle text-primary"></i><span id="roleDescripcionEdit<?php echo $u['id']; ?>"><?php echo $roles_disponibles[$u['role'] ?? 'operador']['descripcion'] ?? 'Acceso limitado'; ?></span></div></div>
</div></div>
<div class="jurisdiccion-section <?php echo ($u['role'] ?? 'operador') == 'operador' ? 'active' : ''; ?>" id="jurisdiccionSectionEdit<?php echo $u['id']; ?>">
<div class="section-header"><i class="fas fa-map-marker-alt"></i><span>Jurisdicción Asignada</span></div>
<div class="row g-3"><div class="col-md-12"><label class="form-label"><i class="fas fa-map-pin"></i>Seleccionar Jurisdicción<span class="required">*</span></label><select name="jurisdiccion" class="form-select"><option value="">Seleccione una jurisdicción...</option><?php foreach ($jurisdicciones_disponibles as $jur_key => $jur_nombre): ?><option value="<?php echo $jur_key; ?>" <?php echo ($u['jurisdiccion'] ?? '') == $jur_key ? 'selected' : ''; ?>><?php echo htmlspecialchars($jur_nombre); ?></option><?php endforeach; ?></select></div></div>
</div>
<div class="empresa-section <?php echo ($u['role'] ?? 'operador') == 'empresa' ? 'active' : ''; ?>" id="empresaSectionEdit<?php echo $u['id']; ?>">
<div class="section-header"><i class="fas fa-building"></i><span>Empresa Asignada</span></div>
<div class="row g-3"><div class="col-md-12"><label class="form-label"><i class="fas fa-building"></i>Seleccionar Empresa<span class="required">*</span></label><select name="empresa_id" class="form-select"><option value="">Seleccione una empresa...</option><?php foreach ($empresas_disponibles as $emp): ?><option value="<?php echo $emp['id']; ?>" <?php echo ($u['empresa_id'] ?? 0) == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['nombre']); ?><?php if (!empty($emp['nombre_comercial'])): ?> (<?php echo htmlspecialchars($emp['nombre_comercial']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div></div>
</div>
<div class="modal-footer"><div class="form-info"><i class="fas fa-info-circle"></i><span>Los campos marcados con <span class="required">*</span> son obligatorios</span></div><div class="form-actions"><button type="button" class="btn-cancel" data-bs-dismiss="modal"><i class="fas fa-times"></i><span>Cancelar</span></button><button type="submit" class="btn-save"><i class="fas fa-save"></i><span>Guardar Cambios</span></button></div></div>
</form>
</div></div></div></div>
<?php endforeach; ?>
<div class="modal fade" id="modalEliminar" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Desactivación</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>¿Desactivar al usuario <strong id="usernameEliminar"></strong>?</p><p class="text-danger"><i class="fas fa-exclamation-circle"></i> Esta acción preservará el historial y podrá revertirse desde administración.</p></div><div class="modal-footer"><form id="formEliminar" method="POST" action=""><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="idEliminar"></form><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button><button type="button" class="btn btn-danger" id="confirmarEliminar"><i class="fas fa-user-slash"></i> Desactivar</button></div></div></div></div>
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="../css/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Toggle Jurisdiccion/Empresa Nuevo
const roleNuevo = document.getElementById('roleNuevo');
const jurNuevo = document.getElementById('jurisdiccionSectionNuevo');
const empNuevo = document.getElementById('empresaSectionNuevo');
if(roleNuevo) {
roleNuevo.addEventListener('change', function() {
const esEmpresa = this.value === 'empresa';
const esOperador = this.value === 'operador';
esOperador ? jurNuevo.classList.add('active') : jurNuevo.classList.remove('active');
esEmpresa ? empNuevo.classList.add('active') : empNuevo.classList.remove('active');
document.getElementById('roleDescripcionNuevo').textContent = this.options[this.selectedIndex].dataset.descripcion || 'Acceso limitado';
});
roleNuevo.dispatchEvent(new Event('change'));
}
// Toggle Jurisdiccion/Empresa Edición
<?php foreach ($usuarios as $u): ?>
(function() {
const roleEdit = document.getElementById('roleEdit<?php echo $u['id']; ?>');
const jurEdit = document.getElementById('jurisdiccionSectionEdit<?php echo $u['id']; ?>');
const empEdit = document.getElementById('empresaSectionEdit<?php echo $u['id']; ?>');
if(roleEdit) {
roleEdit.addEventListener('change', function() {
this.value === 'operador' ? jurEdit.classList.add('active') : jurEdit.classList.remove('active');
this.value === 'empresa' ? empEdit.classList.add('active') : empEdit.classList.remove('active');
document.getElementById('roleDescripcionEdit<?php echo $u['id']; ?>').textContent = this.options[this.selectedIndex].dataset.descripcion || 'Acceso limitado';
});
}
})();
<?php endforeach; ?>
// Desactivar Usuario
document.querySelectorAll('.eliminar-usuario').forEach(btn => {
btn.addEventListener('click', function() {
document.getElementById('usernameEliminar').textContent = this.dataset.username;
document.getElementById('idEliminar').value = this.dataset.id;
new bootstrap.Modal(document.getElementById('modalEliminar')).show();
});
});
document.getElementById('confirmarEliminar')?.addEventListener('click', function() {
fetch('usuarios.php', {
method: 'POST',
headers: {'Content-Type': 'application/x-www-form-urlencoded'},
body: `action=delete&id=${document.getElementById('idEliminar').value}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
}).then(r => r.json()).then(data => {
if(data.success) { Swal.fire({icon: 'success', title: '¡Desactivado!', text: data.message, timer: 2000}); setTimeout(()=>location.reload(), 2000); }
else { Swal.fire({icon: 'error', title: 'Error', text: data.message}); }
}).catch(() => Swal.fire({icon: 'error', title: 'Error', text: 'No se pudo desactivar'}));
});
// Alertas y Modales
document.querySelectorAll('.alert').forEach(alert => { setTimeout(() => new bootstrap.Alert(alert).close(), 5000); });
document.querySelectorAll('.modal-edit-responsive').forEach(modal => {
modal.addEventListener('show.bs.modal', function() { this.querySelector('.modal-content').style.animation = 'modalSlideIn 0.3s ease'; });
});
// Selección masiva
window.toggleSelectAllUsuarios = function() {
const checkAll = document.getElementById('selectAllUsuarios');
document.querySelectorAll('.select-usuario').forEach(c => c.checked = checkAll.checked);
actualizarBotonLote();
};
document.querySelectorAll('.select-usuario').forEach(c => c.addEventListener('change', actualizarBotonLote));
function actualizarBotonLote() {
const count = document.querySelectorAll('.select-usuario:checked').length;
document.getElementById('btnLoteUsuarios').disabled = count === 0;
}
// Generar Lote
window.generarLoteUsuarios = function() {
const selectedIds = Array.from(document.querySelectorAll('.select-usuario:checked')).map(c => c.value);
if(selectedIds.length === 0) return Swal.fire('Advertencia', 'Seleccione al menos un usuario', 'warning');
const tamanoLote = 40;
const totalLotes = Math.ceil(selectedIds.length / tamanoLote);
Swal.fire({
title: 'Generando Credenciales',
html: `Se generarán <strong>${selectedIds.length}</strong> credenciales en <strong>${totalLotes}</strong> PDF(s) de <strong>${tamanoLote}</strong>.<br><br>⚠️ Deberá confirmar cada descarga individualmente.`,
icon: 'info', confirmButtonText: 'Continuar', confirmButtonColor: '#28a745'
}).then(res => {
if(res.isConfirmed) {
const form = document.createElement('form'); form.method = 'POST'; form.action = 'usuarios.php';
let action = document.createElement('input'); action.type = 'hidden'; action.name = 'action'; action.value = 'generar_qr_usuarios_lote'; form.appendChild(action);
selectedIds.forEach(id => { let inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'usuario_ids[]'; inp.value = id; form.appendChild(inp); });
document.body.appendChild(form); form.submit();
}
});
};
});
</script>
</body>
</html>