<?php
/**
* ============================================================================
* GESTIÓN DE EMPRESAS - VERSIÓN COMPLETA CON AUDITORÍA (SIN RESPONSABLE)
* ============================================================================
* Incluye: CRUD completo, Auditoría detallada, Exportación PDF,
*          Validaciones, Paginación, Búsqueda, Filtros Avanzados, Toggle Estado,
*          Comparación de CUILs/DNIs con Personal (CON MODALIDAD DE CONTRATO)
*
* @author Sistema de Seguridad
* @version 3.6 - Diseño Uniforme y Plano (Sin Responsable)
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
$current_page = 'empresas';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. OBTENER CANTIDAD DE PERSONAL POR EMPRESA (AJAX)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_personal_count' && isset($_GET['empresa_id'])) {
header('Content-Type: application/json');
try {
$empresa_id = (int)$_GET['empresa_id'];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE empresa_id = ?");
$stmt->execute([$empresa_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'total' => $result['total']]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'total' => 0]);
exit;
}
}
// ============================================================================
// 3. TOGGLE ACTIVO/INACTIVO (AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado_empresa') {
header('Content-Type: application/json');
try {
$empresa_id = (int)($_POST['empresa_id'] ?? 0);
$nuevo_estado = ($_POST['estado'] === 'true') ? 1 : 0;
if ($empresa_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de empresa inválido']);
exit;
}
$stmt = $conn->prepare("SELECT nombre, activo FROM empresas WHERE id = :id");
$stmt->execute([':id' => $empresa_id]);
$empresa_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empresa_data) {
echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
exit;
}
$stmt = $conn->prepare("UPDATE empresas SET activo = :activo WHERE id = :id");
$stmt->execute([
':activo' => $nuevo_estado,
':id' => $empresa_id
]);
$detalles = [
'accion' => 'toggle_estado',
'id' => $empresa_id,
'nombre' => $empresa_data['nombre'],
'estado_anterior' => $empresa_data['activo'],
'estado_nuevo' => $nuevo_estado,
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'EMPRESA_ESTADO_CAMBIADO', 'empresas', $empresa_id, $detalles, $user['id']);
echo json_encode([
'success' => true,
'message' => $nuevo_estado ? 'Empresa activada correctamente' : 'Empresa desactivada correctamente'
]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
exit;
}
}
// ============================================================================
// 4. COMPARACIÓN DE CUILS/DNIS CON PERSONAL DE EMPRESA (CON MODALIDAD)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comparar_cuils'])) {
header('Content-Type: application/json');
try {
$empresa_id = (int)($_POST['empresa_id'] ?? 0);
$cuils_input = trim($_POST['cuils'] ?? '');
if ($empresa_id <= 0) {
echo json_encode(['success' => false, 'message' => 'Empresa no válida']);
exit;
}
$lineas = preg_split('/[
,;]+/', $cuils_input);
$entradas = [];
foreach ($lineas as $linea) {
$linea = trim($linea);
if (empty($linea)) continue;
if (strpos($linea, ',') !== false) {
$partes = explode(',', $linea);
$id_limpio = trim($partes[0]);
$modalidad_input = isset($partes[1]) ? trim($partes[1]) : null;
$entradas[] = ['id' => $id_limpio, 'modalidad' => $modalidad_input];
} else {
$entradas[] = ['id' => $linea, 'modalidad' => null];
}
}
$cuils_validos = [];
$cuils_invalidos = [];
foreach ($entradas as $entrada) {
$cuil_limpio = preg_replace('/[^0-9]/', '', $entrada['id']);
if ((strlen($cuil_limpio) === 8 || strlen($cuil_limpio) === 11) && ctype_digit($cuil_limpio)) {
$cuils_validos[] = ['numero' => $cuil_limpio, 'modalidad' => $entrada['modalidad']];
} else {
$cuils_invalidos[] = $entrada['id'];
}
}
if (empty($cuils_validos)) {
echo json_encode(['success' => false, 'message' => 'No se ingresaron CUILs/DNIs válidos']);
exit;
}
$stmt = $conn->prepare("
SELECT p.id, p.nombre, p.apellido, p.cuil, p.dni, p.activo, p.modalidad_contrato
FROM personal p
WHERE p.empresa_id = ?
ORDER BY p.apellido, p.nombre
");
$stmt->execute([$empresa_id]);
$personal_empresa = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->prepare("SELECT nombre, cuit FROM empresas WHERE id = ?");
$stmt->execute([$empresa_id]);
$empresa_data = $stmt->fetch(PDO::FETCH_ASSOC);
$coincidencias = [];
$no_coincidencias = [];
foreach ($cuils_validos as $entrada) {
$numero_ingresado_limpio = $entrada['numero'];
$modalidad_requerida = $entrada['modalidad'];
$encontrado = false;
$tipo_coincidencia = '';
foreach ($personal_empresa as $persona) {
$cuil_db_limpio = preg_replace('/[^0-9]/', '', $persona['cuil'] ?? '');
$dni_db_limpio = preg_replace('/[^0-9]/', '', $persona['dni'] ?? '');
if ($cuil_db_limpio === $numero_ingresado_limpio) {
$tipo_coincidencia = 'CUIL';
$encontrado = true;
} elseif ($dni_db_limpio === $numero_ingresado_limpio) {
$tipo_coincidencia = 'DNI';
$encontrado = true;
}
if ($encontrado) {
$modalidad_ok = true;
if ($modalidad_requerida !== null && $modalidad_requerida !== '') {
if ($persona['modalidad_contrato'] != $modalidad_requerida) {
$modalidad_ok = false;
}
}
if ($modalidad_ok) {
if (strlen($numero_ingresado_limpio) === 11) {
$numero_formateado = substr($numero_ingresado_limpio, 0, 2) . '-' .
substr($numero_ingresado_limpio, 2, 8) . '-' .
substr($numero_ingresado_limpio, 10, 1);
} else {
$numero_formateado = number_format($numero_ingresado_limpio, 0, ',', '.');
}
$coincidencias[] = [
'numero' => $numero_formateado,
'tipo' => $tipo_coincidencia,
'cuil' => $persona['cuil'] ?? '',
'dni' => $persona['dni'] ?? '',
'modalidad_db' => $persona['modalidad_contrato'] ?? '-',
'modalidad_input' => $modalidad_requerida ?? 'N/A',
'nombre' => $persona['nombre'],
'apellido' => $persona['apellido'],
'activo' => $persona['activo'],
'id' => $persona['id']
];
} else {
$numero_formateado = (strlen($numero_ingresado_limpio) === 11)
? substr($numero_ingresado_limpio, 0, 2) . '-' . substr($numero_ingresado_limpio, 2, 8) . '-' . substr($numero_ingresado_limpio, 10, 1)
: number_format($numero_ingresado_limpio, 0, ',', '.');
$no_coincidencias[] = [
'numero' => $numero_formateado,
'observacion' => "Modalidad incorrecta (Esperada: {$modalidad_requerida}, Real: {$persona['modalidad_contrato']})"
];
}
break;
}
}
if (!$encontrado) {
if (strlen($numero_ingresado_limpio) === 11) {
$numero_formateado = substr($numero_ingresado_limpio, 0, 2) . '-' .
substr($numero_ingresado_limpio, 2, 8) . '-' .
substr($numero_ingresado_limpio, 10, 1);
} else {
$numero_formateado = number_format($numero_ingresado_limpio, 0, ',', '.');
}
$no_coincidencias[] = ['numero' => $numero_formateado, 'observacion' => 'No registrado en la empresa'];
}
}
logAuditoria($conn, 'COMPARACION_CUILS_DNIS', 'empresas', $empresa_id, [
'total_ingresados' => count($cuils_validos),
'coincidencias' => count($coincidencias),
'no_coincidencias' => count($no_coincidencias),
'invalidos' => count($cuils_invalidos)
], $user['id']);
echo json_encode([
'success' => true,
'empresa' => $empresa_data,
'total_ingresados' => count($cuils_validos),
'cuils_invalidos' => $cuils_invalidos,
'coincidencias' => $coincidencias,
'no_coincidencias' => $no_coincidencias,
'total_personal_empresa' => count($personal_empresa)
]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
exit;
}
}
// ============================================================================
// 5. EXPORTAR COMPARACIÓN A PDF (FPDF)
// ============================================================================
if (isset($_POST['exportar_comparacion_pdf'])) {
if (ob_get_level()) {
ob_end_clean();
}
try {
$datos_comparacion = json_decode($_POST['datos_comparacion'], true);
require_once('../vendor/fpdf/fpdf.php');
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);
$color_primary = [67, 97, 238];
$color_success = [39, 174, 96];
$color_danger = [231, 76, 60];
$color_warning = [243, 156, 18];
$pdf->SetFillColor($color_primary[0], $color_primary[1], $color_primary[2]);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 15, 'REPORTE DE COMPARACION - CUILs y DNIs', 0, 1, 'C', true);
$pdf->Ln(5);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
$pdf->Cell(0, 6, 'Usuario: ' . ($user['nombre'] ?? 'N/A') . ' ' . ($user['apellido'] ?? 'N/A'), 0, 1, 'R');
$pdf->Ln(5);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'INFORMACION DE LA EMPRESA', 0, 1, 'L', false);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(40, 7, 'Empresa:', 0, 0);
$pdf->Cell(0, 7, $datos_comparacion['empresa']['nombre'], 0, 1);
$pdf->Cell(40, 7, 'CUIT Empresa:', 0, 0);
$pdf->Cell(0, 7, $datos_comparacion['empresa']['cuit'] ?? 'N/A', 0, 1);
$pdf->Cell(40, 7, 'Total Personal:', 0, 0);
$pdf->Cell(0, 7, $datos_comparacion['total_personal_empresa'] . ' personas', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'RESUMEN DE LA COMPARACION', 0, 1, 'L', false);
$pdf->SetFont('Arial', '', 10);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell(60, 7, 'Concepto', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Cantidad', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Porcentaje', 1, 1, 'C', true);
$total = $datos_comparacion['total_ingresados'];
$coincidencias_count = count($datos_comparacion['coincidencias']);
$no_coincidencias_count = count($datos_comparacion['no_coincidencias']);
$invalidos_count = count($datos_comparacion['cuils_invalidos']);
$pdf->Cell(60, 7, 'Total Ingresados', 1, 0);
$pdf->Cell(40, 7, $total, 1, 0, 'C');
$pdf->Cell(40, 7, '100%', 1, 1, 'C');
$pdf->SetFillColor(212, 237, 218);
$pdf->Cell(60, 7, 'Coincidencias', 1, 0);
$pdf->Cell(40, 7, $coincidencias_count, 1, 0, 'C');
$porcentaje_coin = $total > 0 ? round(($coincidencias_count / $total) * 100, 2) : 0;
$pdf->Cell(40, 7, $porcentaje_coin . '%', 1, 1, 'C');
$pdf->SetFillColor(248, 215, 218);
$pdf->Cell(60, 7, 'Sin Coincidencia', 1, 0);
$pdf->Cell(40, 7, $no_coincidencias_count, 1, 0, 'C');
$porcentaje_no = $total > 0 ? round(($no_coincidencias_count / $total) * 100, 2) : 0;
$pdf->Cell(40, 7, $porcentaje_no . '%', 1, 1, 'C');
if ($invalidos_count > 0) {
$pdf->SetFillColor(255, 243, 205);
$pdf->Cell(60, 7, 'Formato Invalido', 1, 0);
$pdf->Cell(40, 7, $invalidos_count, 1, 0, 'C');
$porcentaje_inv = $total > 0 ? round(($invalidos_count / $total) * 100, 2) : 0;
$pdf->Cell(40, 7, $porcentaje_inv . '%', 1, 1, 'C');
}
$pdf->Ln(5);
if ($coincidencias_count > 0) {
$pdf->AddPage();
$pdf->SetFillColor($color_success[0], $color_success[1], $color_success[2]);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'COINCIDENCIAS ENCONTRADAS', 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(35, 7, 'Numero', 1, 0, 'C', true);
$pdf->Cell(20, 7, 'Tipo', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'Modalidad', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Apellido', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Nombre', 1, 0, 'C', true);
$pdf->Cell(35, 7, 'CUIL', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach ($datos_comparacion['coincidencias'] as $coin) {
$pdf->Cell(35, 6, $coin['numero'], 1, 0, 'C');
$pdf->Cell(20, 6, $coin['tipo'], 1, 0, 'C');
$pdf->Cell(25, 6, $coin['modalidad_db'], 1, 0, 'C');
$pdf->Cell(45, 6, substr($coin['apellido'], 0, 20), 1, 0, 'L');
$pdf->Cell(45, 6, substr($coin['nombre'], 0, 20), 1, 0, 'L');
$pdf->Cell(35, 6, $coin['cuil'] ?? '-', 1, 1, 'C');
}
}
if ($no_coincidencias_count > 0) {
$pdf->AddPage();
$pdf->SetFillColor($color_danger[0], $color_danger[1], $color_danger[2]);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'SIN COINCIDENCIA', 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(60, 7, 'Numero Ingresado', 1, 0, 'C', true);
$pdf->Cell(0, 7, 'Observacion', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach ($datos_comparacion['no_coincidencias'] as $item) {
$numero = is_array($item) ? $item['numero'] : $item;
$observacion = is_array($item) ? $item['observacion'] : 'No registrado en la empresa';
$pdf->Cell(60, 6, $numero, 1, 0, 'C');
$pdf->Cell(0, 6, $observacion, 1, 1, 'L');
}
}
if ($invalidos_count > 0) {
$pdf->AddPage();
$pdf->SetFillColor($color_warning[0], $color_warning[1], $color_warning[2]);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'FORMATO INVALIDO', 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(60, 7, 'Numero', 1, 0, 'C', true);
$pdf->Cell(0, 7, 'Observacion', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach ($datos_comparacion['cuils_invalidos'] as $numero) {
$pdf->Cell(60, 6, $numero, 1, 0, 'C');
$pdf->Cell(0, 6, 'Debe tener 8 digitos (DNI) o 11 digitos (CUIL)', 1, 1, 'L');
}
}
$pdf->AddPage();
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(128, 128, 128);
$pdf->Cell(0, 10, 'Documento generado automaticamente por el Sistema de Seguridad', 0, 1, 'C');
$pdf->Cell(0, 6, 'Fecha de emision: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$filename = 'comparacion_cuils_dnis_' . date('Y-m-d_His') . '.pdf';
$pdf->Output('D', $filename);
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al exportar PDF: ' . $e->getMessage();
header('Location: empresas.php');
exit;
}
}
// ============================================================================
// 6. INICIALIZAR VARIABLES DE FILTROS
// ============================================================================
$search_nombre = $_GET['search_nombre'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_provincia = $_GET['search_provincia'] ?? '';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$columnas_permitidas = ['id', 'nombre', 'nombre_comercial', 'cuit', 'fecha_constitucion', 'localidad', 'provincia', 'activo', 'fecha_creacion'];
$orden_columna = $_GET['orden'] ?? 'nombre';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'ASC');
if (!in_array($orden_columna, $columnas_permitidas)) {
$orden_columna = 'nombre';
}
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') {
$orden_direccion = 'ASC';
}
$orden_direccion_next = ($orden_direccion === 'ASC') ? 'DESC' : 'ASC';
// ============================================================================
// 7. VERIFICAR/CREAR ESTRUCTURA DE TABLA EMPRESAS
// ============================================================================
try {
$table_exists = $conn->query("SHOW TABLES LIKE 'empresas'")->rowCount() > 0;
if (!$table_exists) {
$conn->exec("
CREATE TABLE empresas (
id INT AUTO_INCREMENT PRIMARY KEY,
nombre VARCHAR(255) NOT NULL,
nombre_comercial VARCHAR(255) NULL,
cuit VARCHAR(20) NULL,
fecha_constitucion DATE NULL,
direccion VARCHAR(255) NULL,
localidad VARCHAR(100) NULL,
provincia VARCHAR(100) NULL,
telefono VARCHAR(50) NULL,
email VARCHAR(100) NULL,
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
INDEX idx_nombre (nombre),
INDEX idx_activo (activo),
INDEX idx_cuit (cuit),
INDEX idx_provincia (provincia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
logAuditoria($conn, 'TABLA_CREADA', 'empresas', null, ['mensaje' => 'Tabla empresas creada con estructura completa'], $user['id']);
} else {
$columns = $conn->query("SHOW COLUMNS FROM empresas")->fetchAll(PDO::FETCH_COLUMN);
$required_columns = [
'nombre_comercial' => "VARCHAR(255) NULL",
'cuit' => "VARCHAR(20) NULL",
'fecha_constitucion' => "DATE NULL",
'direccion' => "VARCHAR(255) NULL",
'localidad' => "VARCHAR(100) NULL",
'provincia' => "VARCHAR(100) NULL",
'telefono' => "VARCHAR(50) NULL",
'email' => "VARCHAR(100) NULL",
'activo' => "BOOLEAN DEFAULT TRUE",
'fecha_creacion' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
'fecha_actualizacion' => "TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP"
];
foreach ($required_columns as $col => $definition) {
if (!in_array($col, $columns)) {
try {
$conn->exec("ALTER TABLE empresas ADD COLUMN $col $definition");
logAuditoria($conn, 'COLUMNA_AGREGADA', 'empresas', null, ['columna' => $col], $user['id']);
} catch (PDOException $e) {
error_log("Error agregando columna '$col': " . $e->getMessage());
}
}
}
}
$cols_personal = $conn->query("SHOW COLUMNS FROM personal")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('modalidad_contrato', $cols_personal)) {
$conn->exec("ALTER TABLE personal ADD COLUMN modalidad_contrato VARCHAR(50) NULL");
logAuditoria($conn, 'COLUMNA_AGREGADA', 'personal', null, ['columna' => 'modalidad_contrato'], $user['id']);
}
} catch (PDOException $e) {
$error = "Error al verificar estructura de base de datos: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// 8. MANEJAR CREACIÓN DE EMPRESA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_empresa'])) {
try {
$nombre = trim($_POST['nombre'] ?? '');
$nombre_comercial = trim($_POST['nombre_comercial'] ?? '');
$cuit = trim($_POST['cuit'] ?? '');
$fecha_constitucion = !empty($_POST['fecha_constitucion']) ? $_POST['fecha_constitucion'] : null;
$direccion = trim($_POST['direccion'] ?? '');
$localidad = trim($_POST['localidad'] ?? '');
$provincia = trim($_POST['provincia'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$email = trim($_POST['email'] ?? '');
if (empty($nombre)) {
throw new Exception('El nombre de la empresa es obligatorio');
}
if (!empty($cuit) && !preg_match('/^\d{2}-\d{8}-\d{1}$/', $cuit)) {
throw new Exception('El CUIT debe tener el formato XX-XXXXXXXX-X');
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
throw new Exception('El email no es válido');
}
if (!empty($cuit)) {
$stmt = $conn->prepare("SELECT id FROM empresas WHERE cuit = ?");
$stmt->execute([$cuit]);
if ($stmt->fetch()) {
throw new Exception('El CUIT ya está registrado en el sistema');
}
}
$stmt = $conn->prepare("
INSERT INTO empresas (
nombre, nombre_comercial, cuit, fecha_constitucion, direccion,
localidad, provincia, telefono, email, activo
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
");
$stmt->execute([
$nombre, $nombre_comercial, $cuit, $fecha_constitucion, $direccion,
$localidad, $provincia, $telefono, $email
]);
$empresa_id = $conn->lastInsertId();
$detalles = [
'accion' => 'empresa_creada',
'id' => $empresa_id,
'nombre' => $nombre,
'nombre_comercial' => $nombre_comercial,
'cuit' => $cuit,
'fecha_constitucion' => $fecha_constitucion ?: 'No especificada',
'direccion' => $direccion,
'localidad' => $localidad,
'provincia' => $provincia,
'telefono' => $telefono,
'email' => $email,
'datos_nuevos' => [
'nombre' => $nombre,
'nombre_comercial' => $nombre_comercial,
'cuit' => $cuit,
'direccion' => $direccion,
'localidad' => $localidad,
'provincia' => $provincia,
'telefono' => $telefono,
'email' => $email
],
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'EMPRESA_CREADA', 'empresas', $empresa_id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-check-circle fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Empresa creada exitosamente!</strong></h5>
<p class='mb-0'><strong>Nombre:</strong> {$nombre}</p>
<p class='mb-0'><strong>CUIT:</strong> {$cuit}</p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>
";
header('Location: empresas.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_CREACION_EMPRESA', 'empresas', null, [
'error' => $e->getMessage(),
'datos_intento' => compact('nombre', 'cuit', 'email')
], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: empresas.php');
exit;
}
}
// ============================================================================
// 9. MANEJAR ACTUALIZACIÓN DE EMPRESA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_empresa'])) {
try {
$id = (int)($_POST['id'] ?? 0);
$nombre = trim($_POST['nombre'] ?? '');
$nombre_comercial = trim($_POST['nombre_comercial'] ?? '');
$cuit = trim($_POST['cuit'] ?? '');
$fecha_constitucion = !empty($_POST['fecha_constitucion']) ? $_POST['fecha_constitucion'] : null;
$direccion = trim($_POST['direccion'] ?? '');
$localidad = trim($_POST['localidad'] ?? '');
$provincia = trim($_POST['provincia'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$email = trim($_POST['email'] ?? '');
$activo = isset($_POST['activo']) ? 1 : 0;
if ($id <= 0 || empty($nombre)) {
throw new Exception('Datos inválidos para actualizar la empresa');
}
if (!empty($cuit) && !preg_match('/^\d{2}-\d{8}-\d{1}$/', $cuit)) {
throw new Exception('El CUIT debe tener el formato XX-XXXXXXXX-X');
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
throw new Exception('El email no es válido');
}
$stmt = $conn->prepare("SELECT * FROM empresas WHERE id = ?");
$stmt->execute([$id]);
$datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$datos_antiguos) {
throw new Exception('Empresa no encontrada');
}
if (!empty($cuit)) {
$stmt = $conn->prepare("SELECT id FROM empresas WHERE cuit = ? AND id != ?");
$stmt->execute([$cuit, $id]);
if ($stmt->fetch()) {
throw new Exception('El CUIT ya está registrado en otra empresa');
}
}
$stmt = $conn->prepare("
UPDATE empresas
SET nombre = ?, nombre_comercial = ?, cuit = ?, fecha_constitucion = ?, direccion = ?,
localidad = ?, provincia = ?, telefono = ?, email = ?, activo = ?
WHERE id = ?
");
$stmt->execute([
$nombre, $nombre_comercial, $cuit, $fecha_constitucion, $direccion,
$localidad, $provincia, $telefono, $email, $activo, $id
]);
$datos_nuevos = [
'nombre' => $nombre,
'nombre_comercial' => $nombre_comercial,
'cuit' => $cuit,
'fecha_constitucion' => $fecha_constitucion,
'direccion' => $direccion,
'localidad' => $localidad,
'provincia' => $provincia,
'telefono' => $telefono,
'email' => $email,
'activo' => (bool)$activo
];
$cambios = obtenerCambios($datos_antiguos, $datos_nuevos);
$detalles = [
'accion' => 'empresa_actualizada',
'id' => $id,
'datos_anteriores' => $datos_antiguos,
'datos_nuevos' => $datos_nuevos,
'cambios' => $cambios,
'campos_modificados' => array_keys($cambios),
'total_cambios' => count($cambios),
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'EMPRESA_ACTUALIZADA', 'empresas', $id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-check-circle fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Empresa actualizada exitosamente!</strong></h5>
<p class='mb-0'><strong>Nombre:</strong> {$nombre}</p>
<p class='mb-0'><strong>Estado:</strong> " . ($activo ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>') . "</p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>
";
header('Location: empresas.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_ACTUALIZACION_EMPRESA', 'empresas', $_POST['id'] ?? null, [
'error' => $e->getMessage()
], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: empresas.php');
exit;
}
}
// ============================================================================
// 10. MANEJAR DESACTIVACIÓN DE EMPRESA (DAR DE BAJA) - MODIFICADO: UPDATE EN LUGAR DE DELETE
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dar_de_baja_empresa'])) {
try {
$id = (int)($_POST['id'] ?? 0);
$tipo_baja = $_POST['tipo_baja'] ?? 'desactivar';
if ($id <= 0) {
throw new Exception('ID de empresa inválido');
}
$stmt = $conn->prepare("SELECT * FROM empresas WHERE id = ?");
$stmt->execute([$id]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empresa) {
throw new Exception('Empresa no encontrada');
}
$conn->beginTransaction();
$sucursales_eliminadas = 0;
$servicios_eliminados = 0;
$recursos_eliminados = 0;
$personal_desvinculado = 0;
if ($tipo_baja === 'eliminar') {
// MODIFICADO: UPDATE en lugar de DELETE para preservar historial y auditoría
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM sucursales WHERE empresa_id = ?");
$stmt->execute([$id]);
$sucursales_eliminadas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$stmt = $conn->prepare("UPDATE sucursales SET activo = 0, fecha_baja = NOW() WHERE empresa_id = ?");
$stmt->execute([$id]);

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM servicios WHERE empresa_id = ?");
$stmt->execute([$id]);
$servicios_eliminados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$stmt = $conn->prepare("UPDATE servicios SET activo = 0, fecha_baja = NOW() WHERE empresa_id = ?");
$stmt->execute([$id]);

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM recursos WHERE empresa_id = ?");
$stmt->execute([$id]);
$recursos_eliminados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$stmt = $conn->prepare("UPDATE recursos SET activo = 0, fecha_baja = NOW() WHERE empresa_id = ?");
$stmt->execute([$id]);

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE empresa_id = ?");
$stmt->execute([$id]);
$personal_desvinculado = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$stmt = $conn->prepare("UPDATE personal SET empresa_id = NULL WHERE empresa_id = ?");
$stmt->execute([$id]);

// MODIFICADO: UPDATE en lugar de DELETE para preservar la empresa en auditoría
$stmt = $conn->prepare("UPDATE empresas SET activo = 0, fecha_baja = NOW() WHERE id = ?");
$stmt->execute([$id]);

$detalles = [
'accion' => 'empresa_eliminada',
'id' => $id,
'nombre' => $empresa['nombre'],
'tipo_baja' => 'eliminacion_completa',
'datos_empresa' => $empresa,
'sucursales_eliminadas' => $sucursales_eliminadas,
'servicios_eliminados' => $servicios_eliminados,
'recursos_eliminados' => $recursos_eliminados,
'personal_desvinculado' => $personal_desvinculado,
'justificacion' => $_POST['justificacion'] ?? 'Sin justificación',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'EMPRESA_ELIMINADA', 'empresas', $id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-check-circle fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Empresa eliminada exitosamente!</strong></h5>
<p class='mb-0'><strong>Nombre:</strong> {$empresa['nombre']}</p>
<p class='mb-0 text-warning'><small>Se desactivaron {$sucursales_eliminadas} sucursales, {$servicios_eliminados} servicios y {$recursos_eliminados} recursos. {$personal_desvinculado} personas desvinculadas.</small></p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>
";
} else {
$stmt = $conn->prepare("UPDATE empresas SET activo = 0 WHERE id = ?");
$stmt->execute([$id]);

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE empresa_id = ? AND activo = TRUE");
$stmt->execute([$id]);
$personal_desvinculado = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("UPDATE personal SET empresa_id = NULL WHERE empresa_id = ? AND activo = TRUE");
$stmt->execute([$id]);

$detalles = [
'accion' => 'empresa_desactivada',
'id' => $id,
'nombre' => $empresa['nombre'],
'tipo_baja' => 'desactivacion',
'datos_empresa' => $empresa,
'personal_desvinculado' => $personal_desvinculado,
'justificacion' => $_POST['justificacion'] ?? 'Sin justificación',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'EMPRESA_DESACTIVADA', 'empresas', $id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-warning alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-exclamation-triangle fa-2x me-3 text-warning'></i>
<div>
<h5 class='mb-1'><strong>¡Empresa dada de baja!</strong></h5>
<p class='mb-0'><strong>Nombre:</strong> {$empresa['nombre']}</p>
<p class='mb-0 text-warning'><small>{$personal_desvinculado} personas desvinculadas. La empresa puede reactivarse.</small></p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>
";
}
$conn->commit();
header('Location: empresas.php');
exit;
} catch (Exception $e) {
if (isset($conn) && $conn->inTransaction()) {
$conn->rollBack();
}
logAuditoria($conn, 'ERROR_BAJA_EMPRESA', 'empresas', $_POST['id'] ?? null, [
'error' => $e->getMessage(),
'tipo_baja' => $_POST['tipo_baja'] ?? 'desactivar'
], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: empresas.php');
exit;
}
}
// ============================================================================
// 11. MANEJAR REACTIVACIÓN DE EMPRESA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivar_empresa'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
throw new Exception('ID de empresa inválido');
}
$stmt = $conn->prepare("SELECT * FROM empresas WHERE id = ?");
$stmt->execute([$id]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empresa) {
throw new Exception('Empresa no encontrada');
}
$stmt = $conn->prepare("UPDATE empresas SET activo = 1 WHERE id = ?");
$stmt->execute([$id]);
$detalles = [
'accion' => 'empresa_reactivada',
'id' => $id,
'nombre' => $empresa['nombre'],
'datos_empresa' => $empresa,
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
];
logAuditoria($conn, 'EMPRESA_REACTIVADA', 'empresas', $id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-check-circle fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Empresa reactivada exitosamente!</strong></h5>
<p class='mb-0'><strong>Nombre:</strong> {$empresa['nombre']}</p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>
";
header('Location: empresas.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_REACTIVACION_EMPRESA', 'empresas', $_POST['id'] ?? null, [
'error' => $e->getMessage()
], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: empresas.php');
exit;
}
}
// ============================================================================
// 12. EXPORTAR EMPRESAS
// ============================================================================
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['csv', 'json', 'pdf'])) {
try {
$sql = "SELECT * FROM empresas WHERE 1=1";
$params = [];
if (!empty($search_nombre)) {
$sql .= " AND nombre LIKE :search_nombre";
$params[':search_nombre'] = '%' . $search_nombre . '%';
}
if ($search_estado !== 'todos') {
$sql .= " AND activo = :activo";
$params[':activo'] = ($search_estado === 'activas') ? 1 : 0;
}
if (!empty($search_provincia)) {
$sql .= " AND provincia = :provincia";
$params[':provincia'] = $search_provincia;
}
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$empresas_export = $stmt->fetchAll(PDO::FETCH_ASSOC);
logAuditoria($conn, 'EMPRESAS_EXPORTADAS', 'empresas', null, [
'formato' => $_GET['exportar'],
'total_registros' => count($empresas_export),
'filtros' => $_GET
], $user['id']);
if ($_GET['exportar'] === 'csv') {
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=empresas_' . date('Y-m-d_His') . '.csv');
$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Nombre', 'Nombre Comercial', 'CUIT', 'Dirección', 'Localidad', 'Provincia', 'Teléfono', 'Email', 'Estado', 'Fecha Creación']);
foreach ($empresas_export as $emp) {
fputcsv($output, [
$emp['id'],
$emp['nombre'],
$emp['nombre_comercial'] ?? '',
$emp['cuit'] ?? '',
$emp['direccion'] ?? '',
$emp['localidad'] ?? '',
$emp['provincia'] ?? '',
$emp['telefono'] ?? '',
$emp['email'] ?? '',
$emp['activo'] ? 'Activa' : 'Inactiva',
$emp['fecha_creacion']
]);
}
fclose($output);
exit;
}
if ($_GET['exportar'] === 'json') {
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=empresas_' . date('Y-m-d_His') . '.json');
echo json_encode($empresas_export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
}
// ============================================================================
// EXPORTAR A PDF (FPDF) - NUEVA FUNCIONALIDAD PARA FILTROS
// ============================================================================
if ($_GET['exportar'] === 'pdf') {
if (ob_get_level()) {
ob_end_clean();
}
require_once('../vendor/fpdf/fpdf.php');
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);
$color_primary = [67, 97, 238];
$color_success = [39, 174, 96];
$color_danger = [231, 76, 60];
$pdf->SetFillColor($color_primary[0], $color_primary[1], $color_primary[2]);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 15, 'REPORTE DE EMPRESAS - FILTROS APLICADOS', 0, 1, 'C', true);
$pdf->Ln(5);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
$pdf->Cell(0, 6, 'Usuario: ' . ($user['nombre'] ?? 'N/A') . ' ' . ($user['apellido'] ?? 'N/A'), 0, 1, 'R');
$pdf->Ln(5);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'FILTROS APLICADOS', 0, 1, 'L', false);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(40, 7, 'Nombre:', 0, 0);
$pdf->Cell(0, 7, !empty($search_nombre) ? htmlspecialchars($search_nombre) : 'Todos', 0, 1);
$pdf->Cell(40, 7, 'Estado:', 0, 0);
$pdf->Cell(0, 7, $search_estado === 'activas' ? 'Activas' : ($search_estado === 'inactivas' ? 'Inactivas' : 'Todos'), 0, 1);
$pdf->Cell(40, 7, 'Provincia:', 0, 0);
$pdf->Cell(0, 7, !empty($search_provincia) ? htmlspecialchars($search_provincia) : 'Todas', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'LISTADO DE EMPRESAS', 0, 1, 'L', false);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(15, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Empresa', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'CUIT', 1, 0, 'C', true);
$pdf->Cell(35, 7, 'Provincia', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Localidad', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'Estado', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach ($empresas_export as $emp) {
$pdf->Cell(15, 6, $emp['id'], 1, 0, 'C');
$pdf->Cell(50, 6, substr($emp['nombre'], 0, 45), 1, 0, 'L');
$pdf->Cell(30, 6, $emp['cuit'] ?? '-', 1, 0, 'C');
$pdf->Cell(35, 6, substr($emp['provincia'] ?? '-', 0, 30), 1, 0, 'L');
$pdf->Cell(30, 6, substr($emp['localidad'] ?? '-', 0, 25), 1, 0, 'L');
$pdf->Cell(25, 6, $emp['activo'] ? 'Activa' : 'Inactiva', 1, 1, 'C');
}
$pdf->Ln(5);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(128, 128, 128);
$pdf->Cell(0, 10, 'Documento generado automaticamente por el Sistema de Seguridad', 0, 1, 'C');
$pdf->Cell(0, 6, 'Fecha de emision: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$filename = 'empresas_filtradas_' . date('Y-m-d_His') . '.pdf';
$pdf->Output('D', $filename);
exit;
}
} catch (Exception $e) {
$_SESSION['error'] = 'Error al exportar: ' . $e->getMessage();
header('Location: empresas.php');
exit;
}
}
// ============================================================================
// 13. OBTENER DATOS CON PAGINACIÓN
// ============================================================================
$empresas = [];
$total_registros = 0;
$total_paginas = 0;
try {
$where_clauses = [];
$params = [];
if (!empty($search_nombre)) {
$where_clauses[] = "e.nombre LIKE :search_nombre";
$params[':search_nombre'] = '%' . $search_nombre . '%';
}
if ($search_estado !== 'todos') {
$where_clauses[] = "e.activo = :activo";
$params[':activo'] = ($search_estado === 'activas') ? 1 : 0;
}
if (!empty($search_provincia)) {
$where_clauses[] = "e.provincia = :provincia";
$params[':provincia'] = $search_provincia;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$count_sql = "SELECT COUNT(*) as total FROM empresas e $where_sql";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$orden_sql = "ORDER BY e.$orden_columna $orden_direccion";
$limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
$sql = "
SELECT e.id, e.nombre, e.activo, e.cuit, e.telefono, e.email,
e.localidad, e.provincia, e.nombre_comercial, e.fecha_constitucion,
e.direccion
FROM empresas e
$where_sql
$orden_sql
$limit_sql
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
logAuditoria($conn, 'EMPRESAS_LISTADO_VISUALIZADO', 'empresas', null, [
'total_registros' => count($empresas),
'pagina' => $pagina_actual,
'filtros_aplicados' => [
'search_nombre' => $search_nombre,
'search_estado' => $search_estado,
'search_provincia' => $search_provincia
],
'registros_por_pagina' => $registros_por_pagina,
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
], $user['id']);
} catch (PDOException $e) {
$empresas = [];
logAuditoria($conn, 'ERROR_CONSULTA_EMPRESAS', 'empresas', null, [
'error' => $e->getMessage()
], $user['id']);
$error = "<strong>⚠️ Atención:</strong> No se pudieron cargar las empresas. Error: " . htmlspecialchars($e->getMessage());
}
$auditoria_por_empresa = [];
try {
foreach ($empresas as $empresa) {
$empresa_id = $empresa['id'];
$stmt = $conn->prepare("
SELECT a.*,
CONCAT(u.nombre, ' ', u.apellido) as usuario_completo
FROM auditoria a
LEFT JOIN personal u ON a.usuario_id = u.id
WHERE a.registro_id = ? AND a.tabla = 'empresas'
ORDER BY a.created_at DESC
LIMIT 50
");
$stmt->execute([$empresa_id]);
$auditoria_por_empresa[$empresa_id] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
} catch (PDOException $e) {
logAuditoria($conn, 'ERROR_CONSULTA_AUDITORIA_EMPRESAS', 'auditoria', null, [
'error' => $e->getMessage()
], $user['id']);
}
// ============================================================================
// 14. FUNCIONES DE UTILIDAD
// ============================================================================
function getAccionBadge($accion) {
$accion_lower = strtolower($accion);
if (strpos($accion_lower, 'creada') !== false || strpos($accion_lower, 'creacion') !== false) {
return ['class' => 'bg-success', 'icon' => 'fa-plus-circle', 'color' => '#27ae60'];
}
if (strpos($accion_lower, 'actualizada') !== false || strpos($accion_lower, 'modificacion') !== false) {
return ['class' => 'bg-warning text-dark', 'icon' => 'fa-edit', 'color' => '#f39c12'];
}
if (strpos($accion_lower, 'eliminada') !== false || strpos($accion_lower, 'eliminacion') !== false) {
return ['class' => 'bg-danger', 'icon' => 'fa-trash', 'color' => '#e74c3c'];
}
if (strpos($accion_lower, 'desactivada') !== false || strpos($accion_lower, 'baja') !== false) {
return ['class' => 'bg-warning text-dark', 'icon' => 'fa-user-slash', 'color' => '#f39c12'];
}
if (strpos($accion_lower, 'reactivada') !== false) {
return ['class' => 'bg-success', 'icon' => 'fa-check', 'color' => '#27ae60'];
}
return ['class' => 'bg-secondary', 'icon' => 'fa-info-circle', 'color' => '#95a5a6'];
}
function formatearDetalles($detalles) {
if (empty($detalles)) {
return '<span class="text-muted">Sin detalles</span>';
}
$decoded = json_decode($detalles, true);
if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
$html = '<ul class="list-unstyled mb-0 small">';
foreach ($decoded as $key => $value) {
if (is_array($value)) {
$value = json_encode($value, JSON_UNESCAPED_UNICODE);
}
$html .= '<li><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars(substr($value, 0, 80)) . (strlen($value) > 80 ? '...' : '') . '</li>';
}
$html .= '</ul>';
return $html;
}
return '<span class="small">' . htmlspecialchars(substr($detalles, 0, 150)) . (strlen($detalles) > 150 ? '...' : '') . '</span>';
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
function obtenerCambios($antiguos, $nuevos) {
$cambios = [];
foreach ($nuevos as $key => $value) {
if (!isset($antiguos[$key]) || $antiguos[$key] !== $value) {
$cambios[$key] = [
'anterior' => $antiguos[$key] ?? null,
'nuevo' => $value
];
}
}
return $cambios;
}
// ============================================================================
// 15. PROVINCIAS Y LOCALIDADES DE ARGENTINA
// ============================================================================
$provincias_argentina = [
'' => 'Seleccione una provincia',
'Buenos Aires' => 'Buenos Aires',
'Catamarca' => 'Catamarca',
'Chaco' => 'Chaco',
'Chubut' => 'Chubut',
'Ciudad Autónoma de Buenos Aires' => 'Ciudad Autónoma de Buenos Aires',
'Córdoba' => 'Córdoba',
'Corrientes' => 'Corrientes',
'Entre Ríos' => 'Entre Ríos',
'Formosa' => 'Formosa',
'Jujuy' => 'Jujuy',
'La Pampa' => 'La Pampa',
'La Rioja' => 'La Rioja',
'Mendoza' => 'Mendoza',
'Misiones' => 'Misiones',
'Neuquén' => 'Neuquén',
'Río Negro' => 'Río Negro',
'Salta' => 'Salta',
'San Juan' => 'San Juan',
'San Luis' => 'San Luis',
'Santa Cruz' => 'Santa Cruz',
'Santa Fe' => 'Santa Fe',
'Santiago del Estero' => 'Santiago del Estero',
'Tierra del Fuego' => 'Tierra del Fuego',
'Tucumán' => 'Tucumán'
];
$localidades_por_provincia = [
'Buenos Aires' => ['La Plata', 'Mar del Plata', 'Bahía Blanca', 'Tandil', 'San Nicolás', 'Quilmes', 'Lanús', 'Morón', 'San Isidro', 'Vicente López', 'Avellaneda', 'Lomas de Zamora', 'Pilar', 'Tigre', 'San Fernando', 'Zárate', 'Campana', 'Pergamino', 'Junín', 'Olavarría'],
'Catamarca' => ['San Fernando del Valle de Catamarca', 'Valle Viejo', 'Andalgalá', 'Belén', 'Recreo', 'Santa María', 'Tinogasta'],
'Chaco' => ['Resistencia', 'Sáenz Peña', 'Villa Ángela', 'Charata', 'Presidencia Roque Sáenz Peña', 'Barranqueras', 'Fontana'],
'Chubut' => ['Rawson', 'Comodoro Rivadavia', 'Puerto Madryn', 'Trelew', 'Esquel', 'Sarmiento', 'Gaiman', 'Dolavon', 'Rada Tilly', 'Camarones'],
'Ciudad Autónoma de Buenos Aires' => ['Palermo', 'Recoleta', 'San Telmo', 'La Boca', 'Belgrano', 'Caballito', 'Flores', 'Villa Crespo', 'Almagro', 'Balvanera', 'Barracas', 'Constitución', 'Monserrat', 'San Nicolás', 'Retiro'],
'Córdoba' => ['Córdoba', 'Villa Carlos Paz', 'Río Cuarto', 'Alta Gracia', 'Villa María', 'San Francisco', 'Bell Ville', 'Río Tercero', 'Jesús María', 'Cosquín', 'La Falda', 'Villa Allende', 'Unquillo', 'Marcos Juárez'],
'Corrientes' => ['Corrientes', 'Goya', 'Paso de los Libres', 'Curuzú Cuatiá', 'Mercedes', 'Santo Tomé', 'Ituzaingó', 'Esquina'],
'Entre Ríos' => ['Paraná', 'Concordia', 'Gualeguaychú', 'Concepción del Uruguay', 'Victoria', 'Gualeguay', 'Colón', 'Diamante', 'Villaguay', 'Chajarí'],
'Formosa' => ['Formosa', 'Clorinda', 'El Colorado', 'Ibarreta', 'Pirané', 'Las Lomitas', 'Comandante Fontana'],
'Jujuy' => ['San Salvador de Jujuy', 'San Pedro de Jujuy', 'Palpalá', 'Perico', 'Libertador General San Martín', 'Humahuaca', 'Tilcara', 'La Quiaca'],
'La Pampa' => ['Santa Rosa', 'General Pico', 'Toay', 'Realicó', 'Eduardo Castex', 'Intendente Alvear', 'Macachín'],
'La Rioja' => ['La Rioja', 'Chilecito', 'Aimogasta', 'Chamical', 'Villa Unión', 'Famatina', 'Chepes'],
'Mendoza' => ['Mendoza', 'San Rafael', 'Godoy Cruz', 'Guaymallén', 'Las Heras', 'Maipú', 'Luján de Cuyo', 'Tunuyán', 'San Martín', 'Rivadavia'],
'Misiones' => ['Posadas', 'Oberá', 'Eldorado', 'Puerto Iguazú', 'Apóstoles', 'Leandro N. Alem', 'Montecarlo', 'Jardín América'],
'Neuquén' => ['Neuquén', 'San Martín de los Andes', 'Zapala', 'Cutral-Có', 'Plottier', 'Centenario', 'Junín de los Andes', 'Chos Malal'],
'Río Negro' => ['Viedma', 'San Carlos de Bariloche', 'General Roca', 'Cipolletti', 'Villa Regina', 'Cinco Saltos', 'El Bolsón', 'Ingeniero Jacobacci'],
'Salta' => ['Salta', 'San Ramón de la Nueva Orán', 'Tartagal', 'Metán', 'Cafayate', 'Rosario de la Frontera', 'Joaquín V. González', 'Embarcación'],
'San Juan' => ['San Juan', 'Rawson', 'Chimbas', 'Rivadavia', 'Santa Lucía', 'Pocito', 'Caucete', 'Albardón', 'Jáchal', 'Calingasta'],
'San Luis' => ['San Luis', 'Villa Mercedes', 'La Punta', 'Merlo', 'Justo Daract', 'Villa de la Quebrada', 'Concarán'],
'Santa Cruz' => ['Río Gallegos', 'Caleta Olivia', 'El Calafate', 'Pico Truncado', 'Puerto Deseado', 'Las Heras', 'Gobernador Gregores'],
'Santa Fe' => ['Santa Fe', 'Rosario', 'Rafaela', 'Reconquista', 'Venado Tuerto', 'Villa Gobernador Gálvez', 'Casilda', 'Esperanza', 'San Lorenzo', 'Cañada de Gómez'],
'Santiago del Estero' => ['Santiago del Estero', 'La Banda', 'Termas de Río Hondo', 'Frías', 'Añatuya', 'Fernández', 'Suncho Corral'],
'Tierra del Fuego' => ['Ushuaia', 'Río Grande', 'Tolhuin', 'Puerto Almanza'],
'Tucumán' => ['San Miguel de Tucumán', 'Yerba Buena', 'Tafí Viejo', 'Concepción', 'Aguilares', 'Monteros', 'Famaillá', 'Banda del Río Salí']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Empresas - Sistema de Seguridad</title>
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
/* Estilos de Paginación Personalizada */
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
</style>
</head>
<body>
<!-- HEADER -->
<?php $page_title = 'Gestión de Empresas'; include '../includes/header.php'; ?>
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
<div class="stat-icon mb-2 text-primary"><i class="fas fa-building fa-2x"></i></div>
<div class="stat-number"><?php echo $total_registros; ?></div>
<div class="stat-label">Empresas Totales</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number">
<?php
$stmt = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE activo = 1");
echo $stmt->fetch()['total'];
?>
</div>
<div class="stat-label">Empresas Activas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-times-circle fa-2x"></i></div>
<div class="stat-number">
<?php
$stmt = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE activo = 0");
echo $stmt->fetch()['total'];
?>
</div>
<div class="stat-label">Empresas Inactivas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-shield-alt fa-2x"></i></div>
<div class="stat-number">
<?php
$stmt = $conn->query("SELECT COUNT(*) as total FROM auditoria WHERE tabla = 'empresas' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
echo $stmt->fetch()['total'];
?>
</div>
<div class="stat-label">Actividad (7 días)</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-barcode fa-2x"></i></div>
<div class="stat-number" style="font-size: 1rem; margin-top: 10px;">
<button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#compararCuilsModal">
<i class="fas fa-exchange-alt me-2"></i>Comparar
</button>
</div>
<div class="stat-label">Herramienta</div>
</div>
</div>
<div class="section-box">
<!-- 1. TÍTULO: Ahora funciona como botón de apertura/cierre -->
<!-- Se agregó el cursor pointer y una flechita para indicar que es interactivo -->
<div class="section-title"
data-bs-toggle="collapse"
data-bs-target="#contenidoFiltros"
style="cursor: pointer;"
title="Clic para mostrar/ocultar filtros">
<i class="fas fa-filter me-2"></i>Filtros de Búsqueda
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<!-- 2. CONTENIDO: Envuelto en un div con ID y clase 'collapse' -->
<!-- La clase 'collapse' hace que se oculte automáticamente al cargar -->
<div id="contenidoFiltros" class="collapse">
<form method="GET" action="" class="row g-3">
<div class="col-md-4">
<label class="form-label">Nombre de Empresa</label>
<input type="text" name="search_nombre" class="form-control" value="<?php echo htmlspecialchars($search_nombre); ?>" placeholder="Buscar por nombre...">
</div>
<div class="col-md-4">
<label class="form-label">Estado</label>
<select name="search_estado" class="form-select">
<option value="todos">Todas las empresas</option>
<option value="activas" <?php echo ($search_estado === 'activas') ? 'selected' : ''; ?>>Solo Activas</option>
<option value="inactivas" <?php echo ($search_estado === 'inactivas') ? 'selected' : ''; ?>>Solo Inactivas</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Provincia</label>
<select name="search_provincia" class="form-select">
<option value="">Todas las provincias</option>
<?php foreach ($provincias_argentina as $key => $value): ?>
<?php if (!empty($key)): ?>
<option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($search_provincia == $key) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($value); ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<div class="col-12 d-flex gap-2">
<button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
<a href="empresas.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
<a href="?exportar=csv<?php echo !empty($search_nombre) ? '&search_nombre='.urlencode($search_nombre) : ''; ?><?php echo $search_estado !== 'todos' ? '&search_estado='.$search_estado : ''; ?><?php echo !empty($search_provincia) ? '&search_provincia='.urlencode($search_provincia) : ''; ?>" class="btn btn-outline-success"><i class="fas fa-file-csv me-2"></i>CSV</a>
<a href="?exportar=json<?php echo !empty($search_nombre) ? '&search_nombre='.urlencode($search_nombre) : ''; ?><?php echo $search_estado !== 'todos' ? '&search_estado='.$search_estado : ''; ?><?php echo !empty($search_provincia) ? '&search_provincia='.urlencode($search_provincia) : ''; ?>" class="btn btn-outline-info"><i class="fas fa-file-code me-2"></i>JSON</a>
<a href="?exportar=pdf<?php echo !empty($search_nombre) ? '&search_nombre='.urlencode($search_nombre) : ''; ?><?php echo $search_estado !== 'todos' ? '&search_estado='.$search_estado : ''; ?><?php echo !empty($search_provincia) ? '&search_provincia='.urlencode($search_provincia) : ''; ?>" class="btn btn-outline-danger"><i class="fas fa-file-pdf me-2"></i>PDF</a>
</div>
</form>
</div>
</div>
<!-- ✅ NUEVA EMPRESA - CON COLLAPSE EN TÍTULO -->
<div class="section-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="section-title d-flex justify-content-between align-items-center"
data-bs-toggle="collapse"
data-bs-target="#nuevaEmpresaForm"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<span><i class="fas fa-plus-circle me-2"></i>Nueva Empresa</span>
<div class="d-flex align-items-center gap-2">
<i class="fas fa-chevron-down" id="iconoNuevaEmpresa"></i>
</div>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto) -->
<div class="collapse mt-3" id="nuevaEmpresaForm">
<h5 class="mb-3">Registrar Nueva Empresa</h5>
<form method="POST" action="">
<input type="hidden" name="crear_empresa" value="1">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Nombre de la Empresa <span class="text-danger">*</span></label>
<input type="text" name="nombre" class="form-control" required placeholder="Ej: Empresa ABC S.A.">
</div>
<div class="col-md-6">
<label class="form-label">Nombre Comercial</label>
<input type="text" name="nombre_comercial" class="form-control" placeholder="Ej: ABC Seguridad">
</div>
<div class="col-md-6">
<label class="form-label">CUIT</label>
<input type="text" name="cuit" class="form-control cuit-input" placeholder="30-12345678-9">
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Constitución</label>
<input type="date" name="fecha_constitucion" class="form-control">
</div>
<div class="col-md-6">
<label class="form-label">Dirección</label>
<input type="text" name="direccion" class="form-control" placeholder="Calle y número">
</div>
<div class="col-md-6">
<label class="form-label">Provincia <span class="text-danger">*</span></label>
<select name="provincia" class="form-control provincia-select" required>
<?php foreach ($provincias_argentina as $key => $value): ?>
<option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($key === '') ? 'selected disabled' : ''; ?>>
<?php echo htmlspecialchars($value); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Localidad/Ciudad <span class="text-danger">*</span></label>
<select name="localidad" class="form-control localidad-select" required>
<option value="">Seleccione primero una provincia</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control" placeholder="+54 11 1234-5678">
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" placeholder="contacto@empresa.com">
</div>
<div class="col-12 text-end">
<button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Registrar Empresa</button>
</div>
</div>
</form>
</div>  <!-- ✅ CIERRA nuevaEmpresaForm -->
</div>  <!-- ✅ CIERRA section-box -->
<!-- LISTADO DE EMPRESAS -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Empresas
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<?php if (empty($empresas)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-building fa-3x text-muted mb-3"></i>
<h5>No hay empresas registradas</h5>
<p class="text-muted"><?php echo empty($search_nombre) && $search_estado === 'todos' ? 'Registra tu primera empresa para comenzar.' : 'No se encontraron empresas con los filtros aplicados.'; ?></p>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaEmpresaForm">
<i class="fas fa-plus me-2"></i>Crear Empresa
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th><a href="<?php echo generarUrlOrden('id', $orden_columna === 'id' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('nombre', $orden_columna === 'nombre' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Empresa <?php echo mostrarIconoOrden('nombre', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('cuit', $orden_columna === 'cuit' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">CUIT <?php echo mostrarIconoOrden('cuit', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('provincia', $orden_columna === 'provincia' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Provincia <?php echo mostrarIconoOrden('provincia', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('activo', $orden_columna === 'activo' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Estado <?php echo mostrarIconoOrden('activo', $orden_columna, $orden_direccion); ?></a></th>
<th class="table-actions">Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($empresas as $empresa): ?>
<tr>
<td><strong>#<?php echo $empresa['id']; ?></strong></td>
<td><strong><?php echo htmlspecialchars($empresa['nombre']); ?></strong></td>
<td><?php echo !empty($empresa['cuit']) ? htmlspecialchars($empresa['cuit']) : '<span class="text-muted">-</span>'; ?></td>
<td><?php echo !empty($empresa['provincia']) ? htmlspecialchars($empresa['provincia']) : '<span class="text-muted">-</span>'; ?></td>
<td>
<?php if ($empresa['activo']): ?>
<span class="badge bg-success">Activa</span>
<?php else: ?>
<span class="badge bg-secondary">Inactiva</span>
<?php endif; ?>
</td>
<td class="table-actions">
<div class="btn-group" role="group">
<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editarEmpresaModal<?php echo $empresa['id']; ?>" title="Editar">
<i class="fas fa-edit"></i>
</button>
<?php if ($empresa['activo']): ?>
<button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#bajaEmpresaModal<?php echo $empresa['id']; ?>" title="Dar de Baja">
<i class="fas fa-user-slash"></i>
</button>
<?php else: ?>
<button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#reactivarEmpresaModal<?php echo $empresa['id']; ?>" title="Reactivar">
<i class="fas fa-check"></i>
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
<div class="d-flex justify-content-center align-items-center mt-4 mb-3">
<nav aria-label="Paginación de empresas">
<ul class="pagination pagination-custom mb-0">
<!-- Primera página -->
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" title="Primera página">
<i class="fas fa-angle-double-left"></i>
</a>
</li>
<!-- Página anterior -->
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => max(1, $pagina_actual - 1)])); ?>" title="Anterior">
<i class="fas fa-chevron-left"></i>
</a>
</li>
<!-- Números de página -->
<?php
$rango = 1;
$inicio = max(1, $pagina_actual - $rango);
$fin = min($total_paginas, $pagina_actual + $rango);
if ($inicio > 1) {
echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
}
for ($i = $inicio; $i <= $fin; $i++):
?>
<li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>">
<?php echo $i; ?>
</a>
</li>
<?php endfor; ?>
<?php if ($fin < $total_paginas) {
echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
} ?>
<!-- Página siguiente -->
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => min($total_paginas, $pagina_actual + 1)])); ?>" title="Siguiente">
<i class="fas fa-chevron-right"></i>
</a>
</li>
<!-- Última página -->
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>" title="Última página">
<i class="fas fa-angle-double-right"></i>
</a>
</li>
</ul>
</nav>
<span class="ms-3 text-muted">
Página <strong><?php echo $pagina_actual; ?></strong> de <strong><?php echo $total_paginas; ?></strong>
</span>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
<!-- MODALES -->
<?php foreach ($empresas as $empresa): ?>
<!-- Modal Editar -->
<div class="modal fade" id="editarEmpresaModal<?php echo $empresa['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">
<i class="fas fa-edit"></i>
Editar Empresa: <?php echo htmlspecialchars($empresa['nombre']); ?>
</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="actualizar_empresa" value="1">
<input type="hidden" name="id" value="<?php echo $empresa['id']; ?>">
<div class="row g-3">
<div class="col-md-8">
<label class="form-label">Nombre <span class="text-danger">*</span></label>
<input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($empresa['nombre']); ?>" required>
</div>
<div class="col-md-4">
<label class="form-label">Nombre Comercial</label>
<input type="text" name="nombre_comercial" class="form-control" value="<?php echo htmlspecialchars($empresa['nombre_comercial'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">CUIT</label>
<input type="text" name="cuit" class="form-control cuit-input" value="<?php echo htmlspecialchars($empresa['cuit'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Constitución</label>
<input type="date" name="fecha_constitucion" class="form-control" value="<?php echo htmlspecialchars($empresa['fecha_constitucion'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Provincia</label>
<select name="provincia" class="form-control provincia-select-edit" data-modal-id="<?php echo $empresa['id']; ?>">
<?php foreach ($provincias_argentina as $key => $value): ?>
<option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($empresa['provincia'] ?? '') === $key ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($value); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Localidad</label>
<select name="localidad" class="form-control localidad-select-edit" data-modal-id="<?php echo $empresa['id']; ?>">
<?php
$provincia_actual = $empresa['provincia'] ?? '';
$localidad_actual = $empresa['localidad'] ?? '';
$localidades = isset($localidades_por_provincia[$provincia_actual]) ? $localidades_por_provincia[$provincia_actual] : [];
?>
<option value="">Seleccione localidad</option>
<?php foreach ($localidades as $loc): ?>
<option value="<?php echo htmlspecialchars($loc); ?>" <?php echo $localidad_actual === $loc ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($loc); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($empresa['email'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Estado</label>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="activo" id="activo<?php echo $empresa['id']; ?>" <?php echo $empresa['activo'] ? 'checked' : ''; ?>>
<label class="form-check-label" for="activo<?php echo $empresa['id']; ?>">
<?php echo $empresa['activo'] ? 'Activa' : 'Inactiva'; ?>
</label>
</div>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-primary">
<i class="fas fa-save me-1"></i>Guardar Cambios
</button>
</div>
</form>
</div>
</div>
</div>
<!-- Modal Auditoría -->
<div class="modal fade" id="auditoriaEmpresaModal<?php echo $empresa['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">
<i class="fas fa-shield-alt"></i>
Auditoría: <?php echo htmlspecialchars($empresa['nombre']); ?>
</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<?php
$auditoria_list = $auditoria_por_empresa[$empresa['id']] ?? [];
if (!empty($auditoria_list)):
?>
<div class="table-responsive">
<table class="table table-sm">
<thead>
<tr>
<th>ID</th>
<th>Fecha/Hora</th>
<th>Acción</th>
<th>Usuario</th>
<th>IP</th>
<th>Detalles</th>
</tr>
</thead>
<tbody>
<?php foreach ($auditoria_list as $registro):
$accion_badge = getAccionBadge($registro['accion']);
?>
<tr>
<td><strong>#<?php echo $registro['id']; ?></strong></td>
<td>
<div class="small">
<?php echo date('d/m/Y H:i:s', strtotime($registro['created_at'])); ?>
</div>
</td>
<td>
<span class="badge <?php echo $accion_badge['class']; ?>">
<?php echo htmlspecialchars($registro['accion']); ?>
</span>
</td>
<td>
<?php if (!empty($registro['usuario_completo'])): ?>
<span class="badge bg-secondary">
<?php echo htmlspecialchars($registro['usuario_completo']); ?>
</span>
<?php else: ?>
<span class="text-muted">Sistema</span>
<?php endif; ?>
</td>
<td class="text-muted small">
<?php echo !empty($registro['ip_address']) ? htmlspecialchars($registro['ip_address']) : '-'; ?>
</td>
<td>
<?php echo formatearDetalles($registro['detalles']); ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-shield-alt fa-3x text-muted mb-3"></i>
<h5>No hay registros de auditoría</h5>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
<!-- Modal Baja -->
<div class="modal fade" id="bajaEmpresaModal<?php echo $empresa['id']; ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-warning text-white">
<h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Dar de Baja Empresa</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p><strong>Empresa:</strong> <?php echo htmlspecialchars($empresa['nombre']); ?></p>
<div class="alert alert-warning">
<i class="fas fa-info-circle"></i> La empresa se marcará como inactiva y el personal será desvinculado.
</div>
<form method="POST" action="">
<input type="hidden" name="dar_de_baja_empresa" value="1">
<input type="hidden" name="id" value="<?php echo $empresa['id']; ?>">
<input type="hidden" name="tipo_baja" value="desactivar">
<div class="form-check mb-3">
<input class="form-check-input" type="checkbox" id="confirmarBaja<?php echo $empresa['id']; ?>" required>
<label class="form-check-label" for="confirmarBaja<?php echo $empresa['id']; ?>">
Confirmo que deseo dar de baja esta empresa
</label>
</div>
<div class="d-flex justify-content-between">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-warning">
<i class="fas fa-user-slash me-1"></i>Confirmar Baja
</button>
</div>
</form>
</div>
</div>
</div>
</div>
<!-- Modal Reactivar -->
<div class="modal fade" id="reactivarEmpresaModal<?php echo $empresa['id']; ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-success text-white">
<h5 class="modal-title"><i class="fas fa-check-circle"></i> Reactivar Empresa</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p><strong>Empresa:</strong> <?php echo htmlspecialchars($empresa['nombre']); ?></p>
<div class="alert alert-success">
<i class="fas fa-info-circle"></i> La empresa será reactivada y volverá a estar disponible.
</div>
<form method="POST" action="">
<input type="hidden" name="reactivar_empresa" value="1">
<input type="hidden" name="id" value="<?php echo $empresa['id']; ?>">
<div class="d-flex justify-content-between">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-success">
<i class="fas fa-check me-1"></i>Reactivar
</button>
</div>
</form>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
<!-- Modal Comparar CUILs/DNIs -->
<div class="modal fade" id="compararCuilsModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title">
<i class="fas fa-barcode"></i>
Comparar CUILs/DNIs con Personal de Empresa
</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form id="formCompararCuils">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Seleccionar Empresa</label>
<select name="empresa_id" id="empresaSelect" class="form-select" required>
<option value="">-- Seleccione una empresa --</option>
<?php
$stmt_empresas = $conn->query("SELECT id, nombre, cuit FROM empresas WHERE activo = 1 ORDER BY nombre");
while ($emp = $stmt_empresas->fetch(PDO::FETCH_ASSOC)):
?>
<option value="<?php echo $emp['id']; ?>">
<?php echo htmlspecialchars($emp['nombre']); ?>
<?php if (!empty($emp['cuit'])): ?> (CUIT: <?php echo htmlspecialchars($emp['cuit']); ?>) <?php endif; ?>
</option>
<?php endwhile; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Total Personal en Empresa</label>
<div class="form-control bg-light" id="totalPersonalEmpresa">
<span class="text-muted">Seleccione una empresa primero</span>
</div>
</div>
</div>
<div class="mt-3">
<label class="form-label">Ingresar CUILs o DNIs a Comparar</label>
<textarea name="cuils" id="cuilsInput" class="form-control" rows="6"
placeholder="Ingrese CUILs o DNIs uno por línea&#10;Ejemplo CON modalidad: 20123456789,1"
style="font-family: monospace;"></textarea>
<small class="text-muted">
Formato válido: 8 dígitos (DNI) o 11 dígitos (CUIL). Para validar modalidad: agregar coma y código (ej: 20123456789,1)
</small>
</div>
<div class="mt-3 d-flex gap-2">
<button type="submit" class="btn btn-primary" id="btnComparar">
<i class="fas fa-exchange-alt me-2"></i>Comparar
</button>
<button type="button" class="btn btn-secondary" id="btnLimpiar">
<i class="fas fa-eraser me-2"></i>Limpiar
</button>
<button type="button" class="btn btn-success" id="btnExportarPDF" style="display: none;">
<i class="fas fa-file-pdf me-2"></i>Exportar PDF
</button>
</div>
</form>
<div id="resultadosComparacion" style="display: none;" class="mt-4">
<hr>
<h5 class="mb-3">Resultados de la Comparación</h5>
<div class="row g-3 mb-4">
<div class="col-md-4">
<div class="card bg-success text-white">
<div class="card-body text-center">
<h2 id="totalCoincidencias">0</h2>
<p class="mb-0">Coincidencias</p>
</div>
</div>
</div>
<div class="col-md-4">
<div class="card bg-danger text-white">
<div class="card-body text-center">
<h2 id="totalNoCoincidencias">0</h2>
<p class="mb-0">Sin Coincidencia</p>
</div>
</div>
</div>
<div class="col-md-4">
<div class="card bg-warning text-dark">
<div class="card-body text-center">
<h2 id="totalInvalidos">0</h2>
<p class="mb-0">Formato Inválido</p>
</div>
</div>
</div>
</div>
<div class="card mb-3">
<div class="card-header bg-success text-white">
<h5 class="mb-0">Coincidencias Encontradas</h5>
</div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-sm table-striped" id="tablaCoincidencias">
<thead>
<tr>
<th>Número</th>
<th>Tipo</th>
<th>Modalidad</th>
<th>Apellido</th>
<th>Nombre</th>
<th>Estado</th>
<th>Acciones</th>
</tr>
</thead>
<tbody></tbody>
</table>
</div>
</div>
</div>
<div class="card mb-3">
<div class="card-header bg-danger text-white">
<h5 class="mb-0">Sin Coincidencia</h5>
</div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-sm table-striped" id="tablaNoCoincidencias">
<thead>
<tr>
<th>Número</th>
<th>Observación</th>
</tr>
</thead>
<tbody></tbody>
</table>
</div>
</div>
</div>
<div class="card mb-3" id="cardInvalidos" style="display: none;">
<div class="card-header bg-warning text-dark">
<h5 class="mb-0">Formato Inválido</h5>
</div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-sm table-striped" id="tablaInvalidos">
<thead>
<tr>
<th>Número</th>
<th>Observación</th>
</tr>
</thead>
<tbody></tbody>
</table>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
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
const localidadesPorProvincia = <?php echo json_encode($localidades_por_provincia, JSON_UNESCAPED_UNICODE); ?>;
let datosComparacionActual = null;
document.addEventListener('DOMContentLoaded', function() {
// Formato automático para CUIT
document.querySelectorAll('.cuit-input').forEach(input => {
input.addEventListener('input', function(e) {
let v = e.target.value.replace(/\D/g, '');
if (v.length > 2) v = v.slice(0,2) + '-' + v.slice(2);
if (v.length > 11) v = v.slice(0,11) + '-' + v.slice(11,12);
e.target.value = v.slice(0,13);
});
});
// Cargar localidades al seleccionar provincia (nueva empresa)
const provinciaSelect = document.querySelector('.provincia-select');
const localidadSelect = document.querySelector('.localidad-select');
if (provinciaSelect && localidadSelect) {
provinciaSelect.addEventListener('change', function() {
cargarLocalidades(this, localidadSelect);
});
}
// Cargar localidades al seleccionar provincia (editar empresa)
document.querySelectorAll('.provincia-select-edit').forEach(select => {
select.addEventListener('change', function() {
const modalId = this.dataset.modalId;
const localidadSelect = document.querySelector(`.localidad-select-edit[data-modal-id="${modalId}"]`);
if (localidadSelect) {
cargarLocalidades(this, localidadSelect);
}
});
});
// Obtener cantidad de personal al seleccionar empresa
const empresaSelect = document.getElementById('empresaSelect');
if (empresaSelect) {
empresaSelect.addEventListener('change', function() {
const empresaId = this.value;
if (empresaId) {
fetch(`empresas.php?action=get_personal_count&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
document.getElementById('totalPersonalEmpresa').innerHTML =
`<strong>${data.total}</strong> personas registradas`;
})
.catch(error => {
document.getElementById('totalPersonalEmpresa').innerHTML =
'<span class="text-danger">Error al cargar</span>';
});
} else {
document.getElementById('totalPersonalEmpresa').innerHTML =
'<span class="text-muted">Seleccione una empresa primero</span>';
}
});
}
// Comparar CUILs
const formComparar = document.getElementById('formCompararCuils');
if (formComparar) {
formComparar.addEventListener('submit', function(e) {
e.preventDefault();
compararCuils();
});
}
// Limpiar comparación
const btnLimpiar = document.getElementById('btnLimpiar');
if (btnLimpiar) {
btnLimpiar.addEventListener('click', function() {
document.getElementById('formCompararCuils').reset();
document.getElementById('resultadosComparacion').style.display = 'none';
document.getElementById('btnExportarPDF').style.display = 'none';
document.getElementById('totalPersonalEmpresa').innerHTML =
'<span class="text-muted">Seleccione una empresa primero</span>';
datosComparacionActual = null;
});
}
// Exportar PDF
const btnExportarPDF = document.getElementById('btnExportarPDF');
if (btnExportarPDF) {
btnExportarPDF.addEventListener('click', function() {
if (datosComparacionActual) {
exportarPDF();
}
});
}
// Auto-cerrar alertas después de 5 segundos
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
});
function cargarLocalidades(provinciaSelect, localidadSelect) {
const provincia = provinciaSelect.value;
localidadSelect.innerHTML = '<option value="">Seleccione localidad</option>';
if (provincia && localidadesPorProvincia[provincia]) {
localidadesPorProvincia[provincia].forEach(loc => {
const option = document.createElement('option');
option.value = loc;
option.textContent = loc;
localidadSelect.appendChild(option);
});
}
}
function compararCuils() {
const empresaId = document.getElementById('empresaSelect').value;
const cuilsInput = document.getElementById('cuilsInput').value;
if (!empresaId) {
Swal.fire({
icon: 'warning',
title: 'Empresa requerida',
text: 'Por favor seleccione una empresa'
});
return;
}
if (!cuilsInput.trim()) {
Swal.fire({
icon: 'warning',
title: 'CUILs/DNIs requeridos',
text: 'Por favor ingrese al menos un número'
});
return;
}
Swal.fire({
title: 'Comparando...',
html: 'Procesando CUILs/DNIs, por favor espere',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading(); }
});
const formData = new FormData();
formData.append('comparar_cuils', '1');
formData.append('empresa_id', empresaId);
formData.append('cuils', cuilsInput);
fetch('empresas.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
Swal.close();
if (data.success) {
datosComparacionActual = data;
mostrarResultados(data);
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
text: 'Verifica tu conexión e intenta nuevamente'
});
});
}
function mostrarResultados(data) {
document.getElementById('resultadosComparacion').style.display = 'block';
document.getElementById('btnExportarPDF').style.display = 'inline-block';
document.getElementById('totalCoincidencias').textContent = data.coincidencias.length;
document.getElementById('totalNoCoincidencias').textContent = data.no_coincidencias.length;
document.getElementById('totalInvalidos').textContent = data.cuils_invalidos.length;
// Tabla de coincidencias
const tbodyCoincidencias = document.querySelector('#tablaCoincidencias tbody');
tbodyCoincidencias.innerHTML = '';
if (data.coincidencias.length > 0) {
data.coincidencias.forEach(coin => {
const row = document.createElement('tr');
const tipoBadge = coin.tipo === 'CUIL'
? '<span class="badge bg-info">CUIL</span>'
: '<span class="badge bg-purple" style="background: #9b59b6;">DNI</span>';
const modalidadDisplay = (coin.modalidad_input !== 'N/A')
? `<small class="text-muted">Req: ${coin.modalidad_input}</small><br><strong>${coin.modalidad_db}</strong>`
: coin.modalidad_db;
row.innerHTML = `
<td><strong>${coin.numero}</strong></td>
<td>${tipoBadge}</td>
<td>${modalidadDisplay}</td>
<td>${coin.apellido}</td>
<td>${coin.nombre}</td>
<td>${coin.activo ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-warning">Inactivo</span>'}</td>
<td>
<a href="personal.php?ver=${coin.id}" class="btn btn-sm btn-info" target="_blank">
<i class="fas fa-eye"></i> Ver
</a>
</td>
`;
tbodyCoincidencias.appendChild(row);
});
} else {
tbodyCoincidencias.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No hay coincidencias</td></tr>';
}
// Tabla de no coincidencias
const tbodyNoCoincidencias = document.querySelector('#tablaNoCoincidencias tbody');
tbodyNoCoincidencias.innerHTML = '';
if (data.no_coincidencias.length > 0) {
data.no_coincidencias.forEach(item => {
const row = document.createElement('tr');
const numero = typeof item === 'object' ? item.numero : item;
const observacion = typeof item === 'object' ? item.observacion : 'No registrado en la empresa';
row.innerHTML = `
<td><strong>${numero}</strong></td>
<td class="text-danger">${observacion}</td>
`;
tbodyNoCoincidencias.appendChild(row);
});
} else {
tbodyNoCoincidencias.innerHTML = '<tr><td colspan="2" class="text-center text-success">¡Todos coinciden!</td></tr>';
}
// Tabla de inválidos
const cardInvalidos = document.getElementById('cardInvalidos');
if (data.cuils_invalidos.length > 0) {
cardInvalidos.style.display = 'block';
const tbodyInvalidos = document.querySelector('#tablaInvalidos tbody');
tbodyInvalidos.innerHTML = '';
data.cuils_invalidos.forEach(numero => {
const row = document.createElement('tr');
row.innerHTML = `
<td><strong>${numero}</strong></td>
<td class="text-warning">Formato incorrecto (8 o 11 dígitos)</td>
`;
tbodyInvalidos.appendChild(row);
});
} else {
cardInvalidos.style.display = 'none';
}
// Scroll a resultados
document.getElementById('resultadosComparacion').scrollIntoView({ behavior: 'smooth' });
}
function exportarPDF() {
if (!datosComparacionActual) {
Swal.fire({
icon: 'warning',
title: 'Sin datos',
text: 'No hay datos de comparación para exportar'
});
return;
}
const form = document.createElement('form');
form.method = 'POST';
form.action = 'empresas.php';
const input1 = document.createElement('input');
input1.type = 'hidden';
input1.name = 'exportar_comparacion_pdf';
input1.value = '1';
const input2 = document.createElement('input');
input2.type = 'hidden';
input2.name = 'datos_comparacion';
input2.value = JSON.stringify(datosComparacionActual);
form.appendChild(input1);
form.appendChild(input2);
document.body.appendChild(form);
form.submit();
document.body.removeChild(form);
}
</script>
</body>
</html>