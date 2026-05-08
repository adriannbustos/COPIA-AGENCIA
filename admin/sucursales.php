<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ==================== INICIALIZAR CONEXIÓN ====================
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user = $auth->getCurrentUser();
// ==================== INICIALIZACIÓN AUTOMÁTICA DE REGISTROS DE REFERENCIA ====================
// Compara y crea automáticamente registros esenciales si no existen en las tablas del sistema
try {
// 1. Verificar/crear configuración de credenciales por defecto
$stmt_config = $conn->query("SELECT COUNT(*) as total FROM config_credenciales WHERE id = 1");
if ($stmt_config && $stmt_config->fetch()['total'] == 0) {
$stmt_insert = $conn->prepare("INSERT INTO config_credenciales (id, jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path) VALUES (1, 'Apellido', 'Nombre', 'Jerarquía del Jefe', NULL)");
$stmt_insert->execute();
logAuditoria($conn, 'CREACION_AUTOMATICA_CONFIG', 'config_credenciales', 1, ['accion' => 'Registro de configuración creado automáticamente'], $user['id'] ?? null);
}
// 2. Definir y asegurar jurisdicciones base para Chubut (se usan como valores de referencia)
$jurisdicciones_base = ['Esquel', 'Comodoro Rivadavia', 'Puerto Madryn', 'Trelew', 'Rawson'];
// Nota: Las jurisdicciones se almacenan como campo texto en sucursales, no en tabla separada
// Esta validación asegura consistencia en los datos de entrada
// 3. Verificar que existan empresas activas para asignación (registro de auditoría si no hay)
$stmt_empresas = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE activo = TRUE");
if ($stmt_empresas && $stmt_empresas->fetch()['total'] == 0) {
error_log("ADVERTENCIA: No hay empresas activas registradas en el sistema");
}
// 4. Asegurar directorios de subida existan (creación automática si faltan)
$directorios_requeridos = [
"../uploads/cupones/",
"../uploads/resoluciones/",
"../uploads/pdf_sucursal/",
"../uploads/fotos_sucursal/",
"../uploads/firmas_jefe/",
"../uploads/fondos_credenciales/"
];
foreach ($directorios_requeridos as $dir) {
if (!file_exists($dir)) {
mkdir($dir, 0777, true);
logAuditoria($conn, 'CREACION_DIRECTORIO_AUTO', 'sistema', null, ['directorio' => $dir], $user['id'] ?? null);
}
}
// 5. Verificar/crear registro de auditoría inicial del sistema si la tabla está vacía
$stmt_auditoria = $conn->query("SELECT COUNT(*) as total FROM auditoria LIMIT 1");
if ($stmt_auditoria && $stmt_auditoria->fetch()['total'] == 0) {
$stmt_log = $conn->prepare("INSERT INTO auditoria (accion, tabla, registro_id, detalles, usuario_id, fecha, ip_address) VALUES (:accion, :tabla, :registro_id, :detalles, :usuario_id, NOW(), :ip)");
$stmt_log->execute([
'accion' => 'INICIALIZACION_SISTEMA',
'tabla' => 'sistema',
'registro_id' => null,
'detalles' => json_encode(['mensaje' => 'Registro inicial de auditoría creado automáticamente', 'version' => '1.0']),
'usuario_id' => $user['id'] ?? null,
'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
]);
}
// 6. Verificar/actualizar estructura de la tabla sucursales - COMPROBACIÓN AUTOMÁTICA DE REGISTROS
try {
// Verificar que la tabla sucursales existe
$stmt_check_table = $conn->query("SHOW TABLES LIKE 'sucursales'");
if ($stmt_check_table && $stmt_check_table->rowCount() > 0) {
// Columnas requeridas según el esquema actual de sucursales.php
$required_columns = [
'id', 'empresa_id', 'nombre', 'domicilio', 'localidad', 'fecha_habilitacion',
'telefono', 'email', 'responsable_id', 'responsable_nombre', 'pago_arancel',
'fecha_pago_arancel', 'cupon_pago', 'en_funcionamiento', 'jurisdiccion',
'fecha_habilitacion_jp', 'numero_resolucion', 'fecha_resolucion',
'pdf_resolucion', 'pdf_sucursal', 'renar', 'certificado_cumplimiento',
'habilitacion_comercial', 'activa', 'estado_aprobacion', 'fecha_solicitud',
'aprobador_id', 'observaciones_aprobacion', 'fecha_aprobacion',
'fotos_uniforme', 'fotos_vehiculos', 'fecha_carga_fotos',
'tiene_protocolos', 'tiene_art', 'tiene_seguro_rc',
'sumarios_administrativos', 'sanciones_aplicadas', 'estado_judicial',
'infracciones_leves', 'infracciones_graves', 'infracciones_muy_graves',
'fecha_ultima_infraccion', 'observaciones_antecedentes',
'ultimos_informes_enviados',
'created_at', 'updated_at'
];
// Obtener columnas existentes
$stmt = $conn->query("DESCRIBE sucursales");
$existing_columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
// Agregar columnas faltantes
foreach ($required_columns as $col) {
if (!in_array($col, $existing_columns)) {
$alter_query = "ALTER TABLE sucursales ADD COLUMN $col ";
if ($col === 'id') {
$alter_query .= "INT AUTO_INCREMENT PRIMARY KEY FIRST";
} elseif (in_array($col, ['empresa_id', 'responsable_id', 'aprobador_id', 'infracciones_leves', 'infracciones_graves', 'infracciones_muy_graves'])) {
$alter_query .= "INT DEFAULT NULL";
} elseif (in_array($col, ['activa', 'pago_arancel', 'en_funcionamiento', 'renar', 'certificado_cumplimiento', 'habilitacion_comercial', 'tiene_protocolos', 'tiene_art', 'tiene_seguro_rc', 'ultimos_informes_enviados'])) {
$alter_query .= "TINYINT(1) DEFAULT 0";
} elseif (strpos($col, 'fecha') !== false || in_array($col, ['created_at', 'updated_at'])) {
$alter_query .= "DATETIME DEFAULT NULL";
} elseif (in_array($col, ['observaciones_aprobacion', 'sumarios_administrativos', 'sanciones_aplicadas', 'observaciones_antecedentes', 'estado_judicial'])) {
$alter_query .= "TEXT DEFAULT NULL";
} else {
$alter_query .= "VARCHAR(255) DEFAULT NULL";
}
$conn->exec($alter_query);
logAuditoria($conn, 'ACTUALIZACION_ESTRUCTURA_SUCURSALES', 'sucursales', null, ['columna_agregada' => $col, 'query' => $alter_query], $user['id'] ?? null);
}
}
// Verificar índices esenciales
$indexes_required = ['empresa_id', 'responsable_id', 'estado_aprobacion', 'activa'];
$stmt_indexes = $conn->query("SHOW INDEX FROM sucursales");
$existing_indexes = array_column($stmt_indexes->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
foreach ($indexes_required as $idx_col) {
$idx_name = 'idx_' . $idx_col;
if (!in_array($idx_name, $existing_indexes) && !in_array($idx_col, $existing_indexes)) {
try {
$conn->exec("ALTER TABLE sucursales ADD INDEX $idx_name ($idx_col)");
logAuditoria($conn, 'CREACION_INDICE_SUCURSALES', 'sucursales', null, ['indice' => $idx_name, 'columna' => $idx_col], $user['id'] ?? null);
} catch(PDOException $e) {
error_log("Índice ya existe o error: $idx_col - " . $e->getMessage());
}
}
}
}
} catch(PDOException $e) {
error_log("Error al verificar estructura de sucursales: " . $e->getMessage());
} catch(Exception $e) {
error_log("Error genérico en verificación de sucursales: " . $e->getMessage());
}
} catch(PDOException $e) {
error_log("Error en inicialización automática de registros: " . $e->getMessage());
// Continuar ejecución - la falta de estos registros no bloquea el funcionamiento principal
} catch(Exception $e) {
error_log("Error genérico en inicialización: " . $e->getMessage());
}
// ==================== FIN INICIALIZACIÓN AUTOMÁTICA ====================
// ==================== VERIFICAR AUTENTICACIÓN ====================
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
$current_page = 'sucursales';
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ==================== OBTENER SUCURSALES POR EMPRESA (AJAX) - CORREGIDO ====================
if (isset($_GET['action']) && $_GET['action'] === 'get_sucursales') {
header('Content-Type: application/json');
$empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
if ($empresa_id <= 0) {
echo json_encode(['success' => true, 'data' => []]);
exit;
}
try {
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = :empresa_id AND activa = TRUE ORDER BY nombre");
$stmt->execute(['empresa_id' => $empresa_id]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $sucursales]);
} catch(PDOException $e) {
error_log("Error al obtener sucursales: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al cargar sucursales']);
}
exit;
}
// ==================== NUEVO: GENERAR PDF CONSOLIDADO (EMPRESA/SUCURSAL) ====================
if (isset($_POST['action']) && $_POST['action'] === 'generar_pdf_consolidado') {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2>');
}
ob_clean();
$filtro_empresa = isset($_POST['filtro_empresa']) && !empty($_POST['filtro_empresa']) ? (int)$_POST['filtro_empresa'] : 0;
$filtro_sucursal = isset($_POST['filtro_sucursal']) && !empty($_POST['filtro_sucursal']) ? (int)$_POST['filtro_sucursal'] : 0;
if ($filtro_empresa <= 0) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Debe seleccionar una empresa</h2>');
}
try {
// Verificar FPDF
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
// Obtener datos de empresa
$stmt = $conn->prepare("SELECT nombre, cuit, domicilio, localidad, telefono, email FROM empresas WHERE id = ?");
$stmt->execute([$filtro_empresa]);
$empresa_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empresa_data) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Empresa no encontrada</h2>');
}
// Obtener datos de sucursal si se seleccionó
$sucursal_data = null;
if ($filtro_sucursal > 0) {
$stmt = $conn->prepare("SELECT nombre, domicilio, localidad, jurisdiccion FROM sucursales WHERE id = ?");
$stmt->execute([$filtro_sucursal]);
$sucursal_data = $stmt->fetch(PDO::FETCH_ASSOC);
}
// Obtener personal habilitado
$personal_query = "SELECT p.id, p.nombre, p.apellido, p.dni, p.cargo, p.fecha_ingreso, p.activo, p.tiene_certificado, p.tiene_penales, p.ram, p.pago_credencial, e.nombre as empresa_nombre, s.nombre as sucursal_nombre FROM personal p LEFT JOIN empresas e ON p.empresa_id = e.id LEFT JOIN sucursales s ON p.sucursal_id = s.id WHERE p.activo = 1 AND p.empresa_id = ?";
$params_personal = [$filtro_empresa];
if ($filtro_sucursal > 0) {
$personal_query .= " AND p.sucursal_id = ?";
$params_personal[] = $filtro_sucursal;
}
$personal_query .= " ORDER BY p.apellido, p.nombre";
$stmt = $conn->prepare($personal_query);
$stmt->execute($params_personal);
$personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Obtener servicios
$servicios_query = "SELECT s.id, s.nombre, s.descripcion, s.tipo, s.estado, s.fecha_inicio, s.fecha_fin, e.nombre as empresa_nombre, suc.nombre as sucursal_nombre FROM servicios s LEFT JOIN empresas e ON s.empresa_id = e.id LEFT JOIN sucursales suc ON s.sucursal_id = suc.id WHERE s.empresa_id = ?";
$params_servicios = [$filtro_empresa];
if ($filtro_sucursal > 0) {
$servicios_query .= " AND s.sucursal_id = ?";
$params_servicios[] = $filtro_sucursal;
}
$servicios_query .= " ORDER BY s.nombre";
$stmt = $conn->prepare($servicios_query);
$stmt->execute($params_servicios);
$servicios_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
// ✅ CORREGIDO: Obtener recursos con marca y modelo desde recursos_items
$recursos_list = []; // ✅ INICIALIZAR COMO ARRAY VACÍO PARA EVITAR ERRORS
try {
$recursos_query = "SELECT ri.id, ri.tipo_recurso, ri.atributos, rs.empresa_id, rs.sucursal_id, rs.personal_id, rs.estado, rs.observaciones, e.nombre as empresa_nombre, suc.nombre as sucursal_nombre, CONCAT(p.nombre, ' ', p.apellido) as asignado_a FROM recursos_items ri INNER JOIN recursos_sucursal rs ON ri.recursos_sucursal_id = rs.id LEFT JOIN empresas e ON rs.empresa_id = e.id LEFT JOIN sucursales suc ON rs.sucursal_id = suc.id LEFT JOIN personal p ON rs.personal_id = p.id WHERE rs.empresa_id = ?";
$params_recursos = [$filtro_empresa];
if ($filtro_sucursal > 0) {
$recursos_query .= " AND rs.sucursal_id = ?";
$params_recursos[] = $filtro_sucursal;
}
$recursos_query .= " ORDER BY ri.tipo_recurso";
$stmt = $conn->prepare($recursos_query);
$stmt->execute($params_recursos);
$recursos_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
error_log("Error al obtener recursos para PDF: " . $e->getMessage());
$recursos_list = []; // ✅ ASEGURAR QUE SEA ARRAY VACÍO EN CASO DE ERROR
}
// Clase PDF personalizada
class PDF_Consolidado extends FPDF {
function Header() {
$this->SetFont('Arial', 'B', 14);
$this->SetTextColor(0, 100, 0);
$this->Cell(0, 10, 'POLICIA DE CHUBUT', 0, 1, 'C');
$this->SetFont('Arial', 'B', 11);
$this->Cell(0, 6, 'Area Investigaciones (D.S.) - Agencias Privadas de Seguridad', 0, 1, 'C');
$this->SetFont('Arial', 'B', 12);
$this->SetTextColor(0, 0, 0);
$this->Ln(3);
$this->SetDrawColor(0, 100, 0);
$this->Line(10, 35, 200, 35);
$this->Ln(5);
}
function Footer() {
$this->SetY(-15);
$this->SetFont('Arial', 'I', 8);
$this->SetTextColor(100, 100, 100);
$this->Cell(0, 5, 'Página ' . $this->PageNo() . ' - Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$this->Cell(0, 5, 'Sistema de Gestion de Seguridad - Policia de Chubut', 0, 1, 'C');
}
function SectionTitle($label) {
$this->SetFont('Arial', 'B', 11);
$this->SetFillColor(230, 230, 250);
$this->Cell(0, 8, $label, 0, 1, 'L', true);
$this->Ln(2);
}
function DataTableHeader($headers) {
$this->SetFont('Arial', 'B', 8);
$this->SetFillColor(240, 240, 240);
$x = $this->GetX();
foreach ($headers as $h) {
$this->Cell($h['width'], 6, $h['label'], 1, 0, $h['align'], true);
}
$this->Ln();
$this->SetFont('Arial', '', 7);
}
function DataRow($data, $widths) {
$this->SetFont('Arial', '', 7);
$i = 0;
foreach ($data as $value) {
$this->Cell($widths[$i], 5, substr($value, 0, 30), 1, 0, 'L');
$i++;
}
$this->Ln();
}
}
$pdf = new PDF_Consolidado();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 10, 'REPORTE CONSOLIDADO', 0, 1, 'C');
$pdf->Ln(3);
// Información de la empresa
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 6, 'Empresa:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, utf8_decode($empresa_data['nombre']), 0, 1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 6, 'CUIT:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, $empresa_data['cuit'] ?? 'N/A', 0, 1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 6, 'Domicilio:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, utf8_decode($empresa_data['domicilio'] ?? '') . ' - ' . utf8_decode($empresa_data['localidad'] ?? ''), 0, 1);
// Información de sucursal si se filtró
if ($sucursal_data) {
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, 'Sucursal Filtrada:', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(30, 6, 'Nombre:', 0, 0);
$pdf->Cell(0, 6, utf8_decode($sucursal_data['nombre']), 0, 1);
$pdf->Cell(30, 6, 'Domicilio:', 0, 0);
$pdf->Cell(0, 6, utf8_decode($sucursal_data['domicilio'] ?? '') . ' - ' . utf8_decode($sucursal_data['localidad'] ?? ''), 0, 1);
$pdf->Cell(30, 6, 'Jurisdiccion:', 0, 0);
$pdf->Cell(0, 6, utf8_decode($sucursal_data['jurisdiccion'] ?? ''), 0, 1);
}
$pdf->Ln(5);
// SECCIÓN 1: PERSONAL HABILITADO
$pdf->SectionTitle('1. PERSONAL HABILITADO (' . count($personal_list) . ' registros)');
if (count($personal_list) > 0) {
$pdf->DataTableHeader([
['label' => 'Apellido y Nombre', 'width' => 55, 'align' => 'L'],
['label' => 'DNI', 'width' => 25, 'align' => 'C'],
['label' => 'Cargo', 'width' => 35, 'align' => 'L'],
['label' => 'Sucursal', 'width' => 40, 'align' => 'L'],
['label' => 'Cert/RAM', 'width' => 35, 'align' => 'C']
]);
foreach ($personal_list as $p) {
$cert_ram = ($p['tiene_certificado'] ? 'Cert:' : '') . ($p['ram'] ? 'RAM' : '');
$pdf->DataRow([
utf8_decode($p['apellido'] . ', ' . $p['nombre']),
$p['dni'] ?? '',
utf8_decode($p['cargo'] ?? ''),
utf8_decode($p['sucursal_nombre'] ?? '-'),
$cert_ram
], [55, 25, 35, 40, 35]);
}
} else {
$pdf->SetFont('Arial', 'I', 9);
$pdf->Cell(0, 6, 'No hay personal habilitado registrado', 0, 1, 'L');
}
$pdf->Ln(5);
// SECCIÓN 2: SERVICIOS
$pdf->SectionTitle('2. SERVICIOS (' . count($servicios_list) . ' registros)');
if (count($servicios_list) > 0) {
$pdf->DataTableHeader([
['label' => 'Servicio', 'width' => 50, 'align' => 'L'],
['label' => 'Tipo', 'width' => 25, 'align' => 'L'],
['label' => 'Estado', 'width' => 25, 'align' => 'C'],
['label' => 'Inicio', 'width' => 25, 'align' => 'C'],
['label' => 'Fin', 'width' => 25, 'align' => 'C'],
['label' => 'Sucursal', 'width' => 40, 'align' => 'L']
]);
foreach ($servicios_list as $s) {
$pdf->DataRow([
utf8_decode(substr($s['nombre'], 0, 35)),
utf8_decode(ucfirst($s['tipo'] ?? '')),
utf8_decode(ucfirst($s['estado'] ?? '')),
$s['fecha_inicio'] ? date('d/m/Y', strtotime($s['fecha_inicio'])) : '-',
$s['fecha_fin'] ? date('d/m/Y', strtotime($s['fecha_fin'])) : '-',
utf8_decode(substr($s['sucursal_nombre'] ?? '-', 0, 25))
], [50, 25, 25, 25, 25, 40]);
}
} else {
$pdf->SetFont('Arial', 'I', 9);
$pdf->Cell(0, 6, 'No hay servicios registrados', 0, 1, 'L');
}
$pdf->Ln(5);
// ✅ SECCIÓN 3: RECURSOS (MARCA Y MODELO) - CORREGIDO
$pdf->SectionTitle('3. RECURSOS - MARCA Y MODELO (' . count($recursos_list) . ' registros)');
if (count($recursos_list) > 0) {
$pdf->DataTableHeader([
['label' => 'Tipo', 'width' => 30, 'align' => 'L'],
['label' => 'Marca', 'width' => 35, 'align' => 'L'],
['label' => 'Modelo', 'width' => 35, 'align' => 'L'],
['label' => 'Patente/Serial', 'width' => 25, 'align' => 'C'],
['label' => 'Estado', 'width' => 25, 'align' => 'C'],
['label' => 'Asignado a', 'width' => 40, 'align' => 'L']
]);
foreach ($recursos_list as $r) {
$atributos = json_decode($r['atributos'] ?? '{}', true);
$marca = $atributos['Marca'] ?? $atributos['marca'] ?? '-';
$modelo = $atributos['Modelo'] ?? $atributos['modelo'] ?? '-';
$tipo = ucfirst(str_replace('_', ' ', $r['tipo_recurso'] ?? ''));
$patente = $atributos['Patente'] ?? $atributos['patente'] ?? $atributos['Serial'] ?? $atributos['serial'] ?? '-';
$estado = $atributos['Estado'] ?? $atributos['estado'] ?? $r['estado'] ?? '-';
$pdf->DataRow([
utf8_decode($tipo),
utf8_decode($marca),
utf8_decode($modelo),
utf8_decode($patente),
utf8_decode($estado),
utf8_decode(substr($r['asignado_a'] ?? 'Sucursal', 0, 25))
], [30, 35, 35, 25, 25, 40]);
}
} else {
$pdf->SetFont('Arial', 'I', 9);
$pdf->Cell(0, 6, 'No hay recursos registrados', 0, 1, 'L');
}
// Footer con firma
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'Documento generado automaticamente por el Sistema de Seguridad', 0, 1, 'C');
$pdf->Cell(0, 5, 'Usuario: ' . ($user['nombre'] ?? 'Sistema') . ' - Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
// Auditoría
logAuditoria($conn, 'GENERACION_PDF_CONSOLIDADO', 'sucursales', null, [
'empresa_id' => $filtro_empresa,
'sucursal_id' => $filtro_sucursal > 0 ? $filtro_sucursal : null,
'personal_count' => count($personal_list),
'servicios_count' => count($servicios_list),
'recursos_count' => count($recursos_list),
'usuario' => $user['nombre'] ?? 'Sistema'
], $user['id']);
$nombre_archivo = 'Reporte_Consolidado_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $empresa_data['nombre']) . ($sucursal_data ? '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sucursal_data['nombre']) : '') . '_' . date('Ymd_His') . '.pdf';
$pdf->Output('D', $nombre_archivo);
exit;
} catch(PDOException $e) {
error_log("Error al generar PDF consolidado: " . $e->getMessage());
die('Error al generar el PDF: ' . $e->getMessage());
}
}
// ==================== PAGAR CREDENCIALES PERSONAL SUCURSAL (AJAX - MASIVO) ====================
if (isset($_POST['action']) && $_POST['action'] === 'pagar_credenciales_sucursal') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$sucursal_id = isset($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : 0;
$fecha_pago = isset($_POST['fecha_pago']) && !empty($_POST['fecha_pago']) ? $_POST['fecha_pago'] : date('Y-m-d');
if ($sucursal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de sucursal inválido']);
exit;
}
try {
$stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE sucursal_id = :sucursal_id");
$stmt_count->execute(['sucursal_id' => $sucursal_id]);
$count = $stmt_count->fetch()['total'];
if ($count == 0) {
echo json_encode(['success' => true, 'message' => 'No hay personal asignado a esta sucursal']);
exit;
}
// ✅ CORRECCIÓN: Actualizar pago_credencial, fecha_pago_credencial y fecha_vencimiento en tabla personal
$fecha_pago_obj = new DateTime($fecha_pago);
$anio_siguiente = $fecha_pago_obj->format('Y') + 1;
$fecha_vencimiento = $anio_siguiente . '-01-10';
$stmt = $conn->prepare("UPDATE personal SET pago_credencial = 1, fecha_pago_credencial = :fecha_pago, fecha_vencimiento = :fecha_vencimiento, updated_at = NOW() WHERE sucursal_id = :sucursal_id");
$stmt->execute(['sucursal_id' => $sucursal_id, 'fecha_pago' => $fecha_pago, 'fecha_vencimiento' => $fecha_vencimiento]);
$detalles = [
'accion' => 'PAGO_CREDENCIALES_MASIVO_SUCURSAL',
'tabla' => 'personal',
'sucursal_id' => $sucursal_id,
'registros_afectados' => $count,
'fecha_pago' => $fecha_pago,
'fecha_vencimiento' => $fecha_vencimiento,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'metodo' => 'MASIVO'
];
logAuditoria($conn, 'PAGO_CREDENCIALES_MASIVO_SUCURSAL', 'personal', null, $detalles, $user['id']);
echo json_encode(['success' => true, 'message' => "Se marcaron como pagadas $count credenciales con fecha $fecha_pago"]);
} catch(PDOException $e) {
error_log("Error al pagar credenciales sucursal: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al actualizar pago de credenciales']);
}
exit;
}
// ==================== OBTENER PERSONAL POR SUCURSAL (AJAX) ====================
if (isset($_GET['action']) && $_GET['action'] === 'get_personal_sucursal') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
if ($sucursal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de sucursal inválido']);
exit;
}
try {
// ✅ CORRECCIÓN: Incluir fecha_pago_credencial y fecha_vencimiento en la selección
// ✅ NUEVO: Incluir contador de sucursales donde este personal es responsable
$stmt = $conn->prepare("
SELECT p.id, p.nombre, p.apellido, p.dni, p.pago_credencial, p.fecha_pago_credencial, p.fecha_vencimiento, p.activo,
(SELECT COUNT(*) FROM sucursales s2 WHERE s2.responsable_id = p.id) as total_sucursales_responsable
FROM personal p
WHERE p.sucursal_id = :sucursal_id
ORDER BY p.apellido, p.nombre
");
$stmt->execute(['sucursal_id' => $sucursal_id]);
$personal = $stmt->fetchAll(PDO::FETCH_ASSOC);
$detalles_log = ['sucursal_id' => $sucursal_id, 'cantidad_registrada' => count($personal)];
logAuditoria($conn, 'REVISION_PERSONAL_SUCURSAL', 'personal', null, $detalles_log, $user['id']);
echo json_encode(['success' => true, 'data' => $personal]);
} catch(PDOException $e) {
error_log("Error al obtener personal: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al cargar personal']);
}
exit;
}
// ==================== ACTUALIZAR PAGO CREDENCIAL INDIVIDUAL (AJAX) ====================
if (isset($_POST['action']) && $_POST['action'] === 'actualizar_pago_credencial_individual') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$personal_id = isset($_POST['personal_id']) ? (int)$_POST['personal_id'] : 0;
$pago_credencial = isset($_POST['pago_credencial']) ? (int)$_POST['pago_credencial'] : 0;
$fecha_pago = isset($_POST['fecha_pago']) && !empty($_POST['fecha_pago']) ? $_POST['fecha_pago'] : ($pago_credencial == 1 ? date('Y-m-d') : null);
if ($personal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID de personal inválido']);
exit;
}
try {
$stmt = $conn->prepare("SELECT nombre, apellido, sucursal_id FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$personal_data = $stmt->fetch();
if ($pago_credencial == 1) {
// ✅ CORRECCIÓN: Asegurar que se actualicen ambos campos en personal.php + fecha_vencimiento
$fecha_pago_obj = new DateTime($fecha_pago);
$anio_siguiente = $fecha_pago_obj->format('Y') + 1;
$fecha_vencimiento = $anio_siguiente . '-01-10';
$stmt = $conn->prepare("UPDATE personal SET pago_credencial = :pago_credencial, fecha_pago_credencial = :fecha_pago, fecha_vencimiento = :fecha_vencimiento, updated_at = NOW() WHERE id = :id");
$stmt->execute([
'pago_credencial' => $pago_credencial,
'fecha_pago' => $fecha_pago,
'fecha_vencimiento' => $fecha_vencimiento,
'id' => $personal_id
]);
} else {
$stmt = $conn->prepare("UPDATE personal SET pago_credencial = :pago_credencial, fecha_pago_credencial = NULL, fecha_vencimiento = NULL, updated_at = NOW() WHERE id = :id");
$stmt->execute([
'pago_credencial' => $pago_credencial,
'id' => $personal_id
]);
}
$detalles = [
'accion' => 'PAGO_CREDENCIAL_INDIVIDUAL',
'tabla' => 'personal',
'registro_id' => $personal_id,
'nombre' => $personal_data['apellido'] . ', ' . $personal_data['nombre'],
'sucursal_id' => $personal_data['sucursal_id'],
'pago_credencial' => $pago_credencial,
'fecha_pago' => $fecha_pago,
'fecha_vencimiento' => $pago_credencial == 1 ? $fecha_vencimiento : null,
'antiguo_pago' => 'pendiente',
'nuevo_pago' => $pago_credencial ? 'pagado' : 'pendiente',
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'metodo' => 'INDIVIDUAL'
];
logAuditoria($conn, 'PAGO_CREDENCIAL_INDIVIDUAL', 'personal', $personal_id, $detalles, $user['id']);
echo json_encode(['success' => true, 'message' => $pago_credencial ? "Pago registrado con fecha $fecha_pago" : 'Pago marcado como pendiente']);
} catch(PDOException $e) {
error_log("Error al actualizar pago credencial: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al actualizar pago']);
}
exit;
}
// ==================== PAGAR CREDENCIALES SELECCIONADOS (AJAX) - CORREGIDO ====================
if (isset($_POST['action']) && $_POST['action'] === 'pagar_credenciales_seleccionados') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$sucursal_id = isset($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : 0;
$fecha_pago = isset($_POST['fecha_pago']) && !empty($_POST['fecha_pago']) ? $_POST['fecha_pago'] : date('Y-m-d');
$personal_ids = isset($_POST['personal_ids']) && is_array($_POST['personal_ids']) ? array_map('intval', $_POST['personal_ids']) : [];
if ($sucursal_id <= 0 || empty($personal_ids)) {
echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
exit;
}
try {
// ✅ CORRECCIÓN: Usar placeholders nombrados correctamente
$placeholders = implode(',', array_map(function($id) {
return ':id_' . $id;
}, $personal_ids));
// ✅ CORRECCIÓN: Agregar fecha_vencimiento (10 de enero del año siguiente)
$fecha_pago_obj = new DateTime($fecha_pago);
$anio_siguiente = $fecha_pago_obj->format('Y') + 1;
$fecha_vencimiento = $anio_siguiente . '-01-10';
$stmt = $conn->prepare("UPDATE personal SET pago_credencial = 1, fecha_pago_credencial = :fecha_pago, fecha_vencimiento = :fecha_vencimiento, updated_at = NOW() WHERE id IN ($placeholders) AND sucursal_id = :sucursal_id");
// ✅ CORRECCIÓN: Bind de parámetros correcto
$params = ['fecha_pago' => $fecha_pago, 'fecha_vencimiento' => $fecha_vencimiento, 'sucursal_id' => $sucursal_id];
foreach ($personal_ids as $id) {
$params['id_' . $id] = $id;
}
$stmt->execute($params);
$count = $stmt->rowCount();
$detalles = [
'accion' => 'PAGO_CREDENCIALES_SELECCIONADOS',
'tabla' => 'personal',
'sucursal_id' => $sucursal_id,
'personal_ids' => $personal_ids,
'registros_afectados' => $count,
'fecha_pago' => $fecha_pago,
'fecha_vencimiento' => $fecha_vencimiento,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'metodo' => 'SELECCIONADO'
];
logAuditoria($conn, 'PAGO_CREDENCIALES_SELECCIONADOS', 'personal', null, $detalles, $user['id']);
echo json_encode(['success' => true, 'message' => "Se marcaron como pagadas $count credenciales con fecha $fecha_pago"]);
} catch(PDOException $e) {
error_log("Error al pagar credenciales seleccionados: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al actualizar pago: ' . $e->getMessage()]);
}
exit;
}
// ==================== GENERACIÓN DE PDF INTEGRADA ====================
if (isset($_GET['generar_pdf']) && isset($_GET['id'])) {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2><p style="text-align:center"><a href="sucursales.php">← Volver al listado</a></p>');
}
$sucursal_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$sucursal_id) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ ID de sucursal no válido</h2><p style="text-align:center"><a href="sucursales.php">← Volver al listado</a></p>');
}
$stmt = $conn->prepare("
SELECT s.*, e.nombre as empresa_nombre, e.cuit, e.domicilio as empresa_domicilio,
e.localidad as empresa_localidad, e.telefono as empresa_telefono,
e.email as empresa_email, e.razon_social
FROM sucursales s
LEFT JOIN empresas e ON s.empresa_id = e.id
WHERE s.id = :id
");
$stmt->execute(['id' => $sucursal_id]);
$sucursal = $stmt->fetch();
if (!$sucursal) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Sucursal no encontrada</h2><p style="text-align:center"><a href="sucursales.php">← Volver al listado</a></p>');
}
logAuditoria($conn, 'GENERACION_PDF_SUCURSAL', 'sucursales', $sucursal_id, [
'sucursal' => $sucursal['nombre'],
'empresa' => $sucursal['empresa_nombre'],
'formato' => 'PDF',
'fecha_generacion' => date('Y-m-d H:i:s')
], $user['id']);
$esta_habilitada = $sucursal['activa'] && $sucursal['en_funcionamiento'] && $sucursal['pago_arancel'];
try {
$stmt = $conn->query("SELECT jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path FROM config_credenciales WHERE id = 1");
$config_jefe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$config_jefe) {
$config_jefe = [
'jefe_apellido' => 'Apellido',
'jefe_nombre' => 'Nombre',
'jefe_gerarquia' => 'Jerarquía del Jefe',
'firma_path' => null
];
}
} catch(PDOException $e) {
$config_jefe = [
'jefe_apellido' => 'Apellido',
'jefe_nombre' => 'Nombre',
'jefe_gerarquia' => 'Jerarquía del Jefe',
'firma_path' => null
];
}
$firma_path = null;
$escudo_path = '../uploads/fondos_credenciales/escudo.png';
$firma_valida = false;
$escudo_valido = false;
if (!empty($config_jefe['firma_path']) && file_exists('../uploads/firmas_jefe/' . $config_jefe['firma_path'])) {
$firma_path = '../uploads/firmas_jefe/' . $config_jefe['firma_path'];
$info = @getimagesize($firma_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) {
$firma_valida = true;
}
}
if (file_exists($escudo_path)) {
$info = @getimagesize($escudo_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) {
$escudo_valido = true;
}
}
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
header('Content-Type: text/html; charset=utf-8');
class PDF extends FPDF {
function Header() {
$this->SetFont('Arial', 'B', 16);
$this->SetTextColor(0, 100, 0);
$this->Cell(0, 10, 'POLICIA DE CHUBUT', 0, 1, 'C');
$this->SetFont('Arial', 'B', 12);
$this->Cell(0, 6, 'Area Investigaciones (D.S.) - Agencias Privadas de Seguridad', 0, 1, 'C');
$this->SetTextColor(0, 0, 0);
$this->Ln(5);
$this->SetDrawColor(0, 100, 0);
$this->Line(10, 30, 200, 30);
$this->Ln(5);
}
function Footer() {
$this->SetY(-25);
$this->SetDrawColor(150, 150, 150);
$this->Rect(10, 270, 190, 20);
$this->SetFont('Arial', 'I', 8);
$this->SetTextColor(100, 100, 100);
$this->Cell(0, 5, 'DOCUMENTO OFICIAL - VALIDADO ELECTRONICAMENTE', 0, 1, 'C');
$this->Cell(0, 5, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$this->Cell(0, 5, 'Sistema de Gestion de Seguridad - Policia de Chubut', 0, 1, 'C');
}
}
$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 8, 'Nombre de la Agencia Privada:', 0, 1);
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 6, $sucursal['empresa_nombre'], 0, 1);
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(30, 6, 'C.U.I.T.:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, $sucursal['cuit'] ?? 'No registrado', 0, 1);
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, 'Domicilio Legal:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, $sucursal['empresa_domicilio'] . ' - ' . $sucursal['empresa_localidad'], 0, 1);
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, 'Domicilio Sucursal:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, $sucursal['domicilio'] . ' - ' . $sucursal['localidad'], 0, 1);
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(30, 6, 'Telefono:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(70, 6, $sucursal['telefono'] ?? $sucursal['empresa_telefono'] ?? 'No registrado', 0, 0, 'L');
$pdf->Cell(30, 6, 'Email:', 0, 0, 'L');
$pdf->Cell(0, 6, $sucursal['email'] ?? $sucursal['empresa_email'] ?? 'No registrado', 0, 1, 'L');
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(35, 6, 'Jurisdiccion:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(60, 6, $sucursal['jurisdiccion'] ?? 'No registrada', 0, 0, 'L');
$pdf->Cell(30, 6, 'Localidad:', 0, 0, 'L');
$pdf->Cell(0, 6, $sucursal['localidad'], 0, 1, 'L');
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 6, 'Resolucion Municipal:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, $sucursal['numero_resolucion'] ?? 'No registrada', 0, 1, 'L');
$pdf->Ln(2);
// ✅ FECHA DE RESOLUCION AGREGADA AL PDF
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(60, 6, 'Fecha de Resolucion:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, $sucursal['fecha_resolucion'] ? date('d/m/Y', strtotime($sucursal['fecha_resolucion'])) : 'No registrada', 0, 1, 'L');
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(60, 6, 'Fecha de Habilitacion:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, $sucursal['fecha_habilitacion'] ? date('d/m/Y', strtotime($sucursal['fecha_habilitacion'])) : 'No registrada', 0, 1, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(230, 230, 250);
$pdf->Cell(0, 8, 'Documentos y Certificaciones', 0, 1, 'L', true);
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 6, '  RENAR: ' . ($sucursal['renar'] ? 'Presente' : 'No Presente'), 0, 1, 'L');
$pdf->Cell(0, 6, '  Certificado de Cumplimiento: ' . ($sucursal['certificado_cumplimiento'] ? 'Presente' : 'No Presente'), 0, 1, 'L');
$pdf->Cell(0, 6, '  Habilitacion Comercial: ' . ($sucursal['habilitacion_comercial'] ? 'Presente' : 'No Presente'), 0, 1, 'L');
$pdf->Cell(0, 6, '  Protocolos: ' . (!empty($sucursal['tiene_protocolos']) ? 'Presente' : 'No Presente'), 0, 1, 'L');
$pdf->Cell(0, 6, '  ART (Aseguradora de Riesgos del Trabajo): ' . (!empty($sucursal['tiene_art']) ? 'Presente' : 'No Presente'), 0, 1, 'L');
$pdf->Cell(0, 6, '  Seguro de Responsabilidad Civil: ' . (!empty($sucursal['tiene_seguro_rc']) ? 'Presente' : 'No Presente'), 0, 1, 'L');
// ✅ NUEVO: Informes enviados a sucursales de la empresa
$pdf->Cell(0, 6, '  Últimos Informes Enviados a Sucursales: ' . (!empty($sucursal['ultimos_informes_enviados']) ? '✅ Sí' : '❌ No'), 0, 1, 'L');
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(230, 250, 230);
$pdf->Cell(0, 8, 'Estado de la Sucursal', 0, 1, 'L', true);
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 11);
if ($sucursal['en_funcionamiento']) {
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 6, '  La agencia privada se encuentra en funcionamiento', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
} else {
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(0, 6, '  La agencia privada NO se encuentra en funcionamiento', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
}
$pdf->Ln(2);
if ($sucursal['pago_arancel']) {
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 6, '  Se ha registrado el pago correspondiente al arancel de Habilitacion anual', 0, 1, 'L');
if (!empty($sucursal['fecha_pago_arancel'])) {
$fecha_pago = new DateTime($sucursal['fecha_pago_arancel']);
$fecha_actual = new DateTime();
$diferencia = $fecha_actual->diff($fecha_pago);
$dias_transcurridos = $diferencia->days;
if ($dias_transcurridos > 380) {
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(0, 6, '  ⚠️ ARANCEL VENCIDO: Han pasado ' . $dias_transcurridos . ' dias desde el ultimo pago (>380 dias)', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
} else {
$pdf->Cell(0, 6, '  Fecha de pago: ' . date('d/m/Y', strtotime($sucursal['fecha_pago_arancel'])), 0, 1, 'L');
}
}
$pdf->SetTextColor(0, 0, 0);
} else {
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(0, 6, '  No se han registrado pagos correspondientes al arancel de Habilitacion anual hasta la fecha actual', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
}
$pdf->Ln(3);
if (!empty($sucursal['responsable_nombre'])) {
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, 'Responsable:', 0, 1, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->SetFillColor(255, 255, 240);
$pdf->MultiCell(0, 6, $sucursal['responsable_nombre'], 0, 'L', true);
$pdf->Ln(3);
}
$pdf->SetFont('Arial', 'B', 18);
$pdf->Ln(5);
if ($esta_habilitada) {
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 12, 'LA SUCURSAL SE ENCUENTRA HABILITADA', 0, 1, 'C');
} else {
$pdf->SetTextColor(255, 0, 0);
$pdf->Cell(0, 12, 'LA SUCURSAL NO SE ENCUENTRA HABILITADA', 0, 1, 'C');
$pdf->Cell(0, 12, 'NO ESTA APROBADA - NO ESTA ACTIVA - NO PAGO EL ARANCEL', 0, 1, 'C');
}
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(15);
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetTextColor(0, 0, 0);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 20);
$pdf->Cell(0, 30, 'VERIFICACION ELECTRONICA', 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'I', 12);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 8, 'Escanee el codigo QR para verificar el estado actualizado en tiempo real', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(10);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['PHP_SELF']);
$basePath = rtrim($protocol . $domain . $scriptPath, '/\\');
// ✅ TOKEN DE SEGURIDAD CON EXPIRACIÓN DE 380 DÍAS
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$expiracion_timestamp = time() + (380 * 24 * 60 * 60); // 380 días en segundos
$payload_token = $sucursal_id . '|' . $expiracion_timestamp;
$security_token = hash_hmac('sha256', $payload_token, $secret_key);
$verify_url = $basePath . '../../agencia_seguridad/verificar_sucursal.php?id=' . $sucursal_id . '&exp=' . $expiracion_timestamp . '&token=' . $security_token;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($verify_url);
$qr_temp = sys_get_temp_dir() . '/qr_verify_' . $sucursal_id . '_' . time() . '.png';
@file_put_contents($qr_temp, @file_get_contents($qr_url));
if (file_exists($qr_temp)) {
$pdf->Image($qr_temp, 55, $pdf->GetY(), 100, 100);
$pdf->Ln(110);
@unlink($qr_temp);
} else {
$pdf->Cell(0, 100, 'QR no disponible', 0, 1, 'C');
$pdf->Ln(20);
}
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Escanee para Verificar Estado Actual', 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 24);
$pdf->SetTextColor(0, 100, 0);
$pdf->Cell(0, 15, strtoupper($sucursal['empresa_nombre']), 0, 1, 'C');
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 10, 'Sucursal: ' . strtoupper($sucursal['nombre']), 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 14);
if ($esta_habilitada) {
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 10, '  SUCURSAL HABILITADA', 0, 1, 'C');
} else {
$pdf->SetTextColor(255, 0, 0);
}
$pdf->SetTextColor(0, 0, 0);
$pdf->Output('Certificado_Sucursal_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sucursal['nombre']) . '.pdf', 'I');
exit;
}
// ==================== GENERACIÓN DE PDF DE LISTADO FILTRADO ====================
if (isset($_GET['generar_pdf_lista']) && $_GET['generar_pdf_lista'] == '1') {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2><p style="text-align:center"><a href="sucursales.php">← Volver al listado</a></p>');
}
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
// Reutilizar lógica de filtros
$filtro_empresa = isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa']) ? (int)$_GET['filtro_empresa'] : 0;
$filtro_jurisdiccion = isset($_GET['filtro_jurisdiccion']) && !empty($_GET['filtro_jurisdiccion']) ? sanitizeInput($_GET['filtro_jurisdiccion']) : '';
$filtro_localidad = isset($_GET['filtro_localidad']) && !empty($_GET['filtro_localidad']) ? sanitizeInput($_GET['filtro_localidad']) : '';
$filtro_responsable = isset($_GET['filtro_responsable']) && !empty($_GET['filtro_responsable']) ? (int)$_GET['filtro_responsable'] : 0;
$filtro_texto = isset($_GET['filtro_texto']) && !empty($_GET['filtro_texto']) ? sanitizeInput($_GET['filtro_texto']) : '';
$filtro_activa = isset($_GET['filtro_activa']) && $_GET['filtro_activa'] !== '' ? (int)$_GET['filtro_activa'] : null;
$filtro_en_funcionamiento = isset($_GET['filtro_en_funcionamiento']) && $_GET['filtro_en_funcionamiento'] !== '' ? (int)$_GET['filtro_en_funcionamiento'] : null;
$filtro_aprobacion = isset($_GET['filtro_aprobacion']) ? $_GET['filtro_aprobacion'] : 'todos';
$filtro_pago_arancel = isset($_GET['filtro_pago_arancel']) && $_GET['filtro_pago_arancel'] !== '' ? (int)$_GET['filtro_pago_arancel'] : null;
$filtro_renar = isset($_GET['filtro_renar']) && $_GET['filtro_renar'] !== '' ? (int)$_GET['filtro_renar'] : null;
$filtro_fecha_desde = isset($_GET['filtro_fecha_desde']) && !empty($_GET['filtro_fecha_desde']) ? $_GET['filtro_fecha_desde'] : '';
$filtro_fecha_hasta = isset($_GET['filtro_fecha_hasta']) && !empty($_GET['filtro_fecha_hasta']) ? $_GET['filtro_fecha_hasta'] : '';
// Construir query con filtros
$query = "SELECT s.*, e.nombre as empresa_nombre, p.nombre as responsable_nombre, p.apellido as responsable_apellido FROM sucursales s LEFT JOIN empresas e ON s.empresa_id = e.id LEFT JOIN personal p ON s.responsable_id = p.id WHERE 1=1";
$params = [];
if ($filtro_empresa > 0) { $query .= " AND s.empresa_id = :filtro_empresa"; $params['filtro_empresa'] = $filtro_empresa; }
if (!empty($filtro_jurisdiccion)) { $query .= " AND s.jurisdiccion = :filtro_jurisdiccion"; $params['filtro_jurisdiccion'] = $filtro_jurisdiccion; }
if (!empty($filtro_localidad)) { $query .= " AND s.localidad = :filtro_localidad"; $params['filtro_localidad'] = $filtro_localidad; }
if ($filtro_responsable > 0) { $query .= " AND s.responsable_id = :filtro_responsable"; $params['filtro_responsable'] = $filtro_responsable; }
if (!empty($filtro_texto)) { $query .= " AND (s.nombre LIKE :filtro_texto1 OR s.domicilio LIKE :filtro_texto2 OR s.localidad LIKE :filtro_texto3 OR e.nombre LIKE :filtro_texto4)"; $params['filtro_texto1'] = "%{$filtro_texto}%"; $params['filtro_texto2'] = "%{$filtro_texto}%"; $params['filtro_texto3'] = "%{$filtro_texto}%"; $params['filtro_texto4'] = "%{$filtro_texto}%"; }
if ($filtro_activa !== null) { $query .= " AND s.activa = :filtro_activa"; $params['filtro_activa'] = $filtro_activa; }
if ($filtro_en_funcionamiento !== null) { $query .= " AND s.en_funcionamiento = :filtro_en_funcionamiento"; $params['filtro_en_funcionamiento'] = $filtro_en_funcionamiento; }
if ($filtro_aprobacion === 'pendiente') { $query .= " AND (s.estado_aprobacion = 'pendiente' OR s.estado_aprobacion IS NULL)"; } elseif ($filtro_aprobacion === 'aprobado') { $query .= " AND s.estado_aprobacion = 'aprobado'"; } elseif ($filtro_aprobacion === 'rechazado') { $query .= " AND s.estado_aprobacion = 'rechazado'"; }
if ($filtro_pago_arancel !== null) { $query .= " AND s.pago_arancel = :filtro_pago_arancel"; $params['filtro_pago_arancel'] = $filtro_pago_arancel; }
if ($filtro_renar !== null) { $query .= " AND s.renar = :filtro_renar"; $params['filtro_renar'] = $filtro_renar; }
if (!empty($filtro_fecha_desde)) { $query .= " AND s.fecha_solicitud >= :filtro_fecha_desde"; $params['filtro_fecha_desde'] = $filtro_fecha_desde . ' 00:00:00'; }
if (!empty($filtro_fecha_hasta)) { $query .= " AND s.fecha_solicitud <= :filtro_fecha_hasta"; $params['filtro_fecha_hasta'] = $filtro_fecha_hasta . ' 23:59:59'; }
$query .= " ORDER BY s.nombre ASC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$sucursales_filtradas = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Configurar PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Sucursales_Filtradas_' . date('Ymd_His') . '.pdf"');
class PDF_Lista extends FPDF {
function Header() {
$this->SetFont('Arial', 'B', 14);
$this->SetTextColor(0, 100, 0);
$this->Cell(0, 10, 'POLICIA DE CHUBUT', 0, 1, 'C');
$this->SetFont('Arial', 'B', 11);
$this->Cell(0, 6, 'Area Investigaciones (D.S.) - Agencias Privadas de Seguridad', 0, 1, 'C');
$this->SetFont('Arial', 'B', 10);
$this->Cell(0, 8, 'Listado de Sucursales Filtradas', 0, 1, 'C');
$this->SetTextColor(0, 0, 0);
$this->Ln(2);
$this->SetDrawColor(0, 100, 0);
$this->Line(10, 35, 200, 35);
$this->Ln(5);
}
function Footer() {
$this->SetY(-15);
$this->SetFont('Arial', 'I', 8);
$this->SetTextColor(100, 100, 100);
$this->Cell(0, 5, 'Página ' . $this->PageNo() . ' - Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$this->Cell(0, 5, 'Sistema de Gestion de Seguridad - Policia de Chubut', 0, 1, 'C');
}
function TablaCabecera() {
$this->SetFont('Arial', 'B', 8);
$this->SetFillColor(230, 230, 250);
$this->Cell(15, 6, 'ID', 1, 0, 'C', true);
$this->Cell(50, 6, 'Sucursal', 1, 0, 'L', true);
$this->Cell(50, 6, 'Empresa', 1, 0, 'L', true);
$this->Cell(40, 6, 'Localidad', 1, 0, 'L', true);
$this->Cell(30, 6, 'Jurisdiccion', 1, 0, 'C', true);
$this->Cell(30, 6, 'Responsable', 1, 0, 'L', true);
$this->Cell(15, 6, 'Activa', 1, 0, 'C', true);
$this->Cell(20, 6, 'Aprobacion', 1, 1, 'C', true);
}
function TablaFila($sucursal) {
$this->SetFont('Arial', '', 7);
$this->Cell(15, 5, $sucursal['id'], 1, 0, 'C');
$this->Cell(50, 5, substr($sucursal['nombre'], 0, 38), 1, 0, 'L');
$this->Cell(50, 5, substr($sucursal['empresa_nombre'] ?? '', 0, 33), 1, 0, 'L');
$this->Cell(40, 5, substr($sucursal['localidad'] ?? '', 0, 23), 1, 0, 'L');
$this->Cell(30, 5, substr($sucursal['jurisdiccion'] ?? '', 0, 18), 1, 0, 'C');
$resp = ($sucursal['responsable_apellido'] ?? '') . ', ' . ($sucursal['responsable_nombre'] ?? '');
$this->Cell(30, 5, substr($resp, 0, 23), 1, 0, 'L');
$this->Cell(15, 5, $sucursal['activa'] ? 'SI' : 'NO', 1, 0, 'C');
$this->Cell(20, 5, substr($sucursal['estado_aprobacion'] ?? 'pendiente', 0, 18), 1, 1, 'C');
}
}
$pdf = new PDF_Lista();
$pdf->AddPage('L'); // Landscape para más columnas
$pdf->SetFont('Arial', '', 8);
// Mostrar filtros aplicados
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$filtros_aplicados = [];
if ($filtro_empresa > 0) $filtros_aplicados[] = "Empresa: $filtro_empresa";
if (!empty($filtro_jurisdiccion)) $filtros_aplicados[] = "Jurisdiccion: $filtro_jurisdiccion";
if (!empty($filtro_localidad)) $filtros_aplicados[] = "Localidad: $filtro_localidad";
if ($filtro_activa !== null) $filtros_aplicados[] = "Estado: " . ($filtro_activa ? 'Activas' : 'Inactivas');
if ($filtro_aprobacion !== 'todos') $filtros_aplicados[] = "Aprobacion: $filtro_aprobacion";
if (!empty($filtros_aplicados)) {
$pdf->Cell(0, 5, 'Filtros: ' . implode(' | ', $filtros_aplicados), 0, 1, 'L');
}
$pdf->Cell(0, 5, 'Total de registros: ' . count($sucursales_filtradas), 0, 1, 'L');
$pdf->Ln(2);
// Tabla de resultados
$pdf->TablaCabecera();
foreach ($sucursales_filtradas as $sucursal) {
$pdf->TablaFila($sucursal);
}
$pdf->Output('Sucursales_Filtradas_' . date('Ymd_His') . '.pdf', 'I');
exit;
}
// ==================== PROCESAR APROBACIÓN/RECHAZO ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_aprobacion'])) {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
$_SESSION['error'] = 'Acceso denegado';
header('Location: sucursales.php');
exit;
}
try {
$sucursal_id = (int)$_POST['sucursal_id'];
$accion = $_POST['accion_aprobacion'];
$observaciones = sanitizeInput($_POST['observaciones_aprobacion'] ?? '');
$nuevo_estado = ($accion === 'aprobar') ? 'aprobado' : 'rechazado';
$stmt = $conn->prepare("SELECT estado_aprobacion, activa FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$sucursal_previa = $stmt->fetch();
$stmt = $conn->prepare("
UPDATE sucursales SET
estado_aprobacion = :estado_aprobacion,
fecha_aprobacion = NOW(),
aprobador_id = :aprobador_id,
observaciones_aprobacion = :observaciones,
activa = :activa
WHERE id = :id
");
$stmt->execute([
'estado_aprobacion' => $nuevo_estado,
'aprobador_id' => $user['id'],
'observaciones' => $observaciones,
'activa' => ($accion === 'aprobar') ? 1 : 0,
'id' => $sucursal_id
]);
$detalles_auditoria = [
'accion' => 'APROBACION_RECHAZO_SUCURSAL',
'tabla' => 'sucursales',
'registro_id' => $sucursal_id,
'sucursal' => $sucursal_previa['nombre'] ?? 'Desconocida',
'accion_realizada' => $accion,
'estado_anterior' => $sucursal_previa['estado_aprobacion'] ?? 'pendiente',
'estado_nuevo' => $nuevo_estado,
'activacion' => ($accion === 'aprobar') ? 'activada' : 'desactivada',
'observaciones' => substr($observaciones, 0, 200),
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'nivel_riesgo' => ($accion === 'aprobar') ? 'medio' : 'alto',
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
];
logAuditoria($conn, 'APROBACION_RECHAZO_SUCURSAL', 'sucursales', $sucursal_id, $detalles_auditoria, $user['id']);
$_SESSION['success'] = ($accion === 'aprobar') ?
'Sucursal aprobada correctamente' :
'Sucursal rechazada correctamente';
header('Location: sucursales.php?filtro_aprobacion=pendiente');
exit;
} catch(Exception $e) {
$_SESSION['error'] = 'Error al procesar la aprobación: ' . $e->getMessage();
logAuditoria($conn, 'ERROR_APROBACION_SUCURSAL', 'sucursales', (int)$_POST['sucursal_id'] ?? null, ['error' => $e->getMessage()], $user['id']);
header('Location: sucursales.php');
exit;
}
}
// ==================== FUNCIÓN SANITIZE INPUT ====================
if (!function_exists('sanitizeInput')) {
function sanitizeInput($data) {
return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
}
// ==================== DIRECTORIOS DE SUBIDA ====================
$target_dir_cupones = "../uploads/cupones/";
$target_dir_resoluciones = "../uploads/resoluciones/";
$target_dir_pdf_sucursal = "../uploads/pdf_sucursal/";
$target_dir_fotos_sucursal = "../uploads/fotos_sucursal/";
if (!file_exists($target_dir_cupones)) mkdir($target_dir_cupones, 0777, true);
if (!file_exists($target_dir_resoluciones)) mkdir($target_dir_resoluciones, 0777, true);
if (!file_exists($target_dir_pdf_sucursal)) mkdir($target_dir_pdf_sucursal, 0777, true);
if (!file_exists($target_dir_fotos_sucursal)) mkdir($target_dir_fotos_sucursal, 0777, true);
// ==================== TOGGLE ESTADO ACTIVO/INACTIVO ====================
if (isset($_POST['action']) && $_POST['action'] === 'toggle_estado') {
try {
$sucursal_id = (int)$_POST['sucursal_id'];
$nuevo_estado = $_POST['estado'] === 'true' ? 1 : 0;
$stmt = $conn->prepare("SELECT nombre, empresa_id, activa FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$sucursal_data = $stmt->fetch();
$stmt = $conn->prepare("UPDATE sucursales SET activa = :activa WHERE id = :id");
$stmt->execute(['activa' => $nuevo_estado, 'id' => $sucursal_id]);
$detalles = [
'accion' => 'SUCURSAL_TOGGLE_ESTADO_ACTIVO',
'tabla' => 'sucursales',
'registro_id' => $sucursal_id,
'nombre' => $sucursal_data['nombre'] ?? 'Desconocido',
'empresa_id' => $sucursal_data['empresa_id'] ?? null,
'estado_anterior' => $sucursal_data['activa'] ? 'activa' : 'inactiva',
'estado_nuevo' => $nuevo_estado ? 'activa' : 'inactiva',
'nivel_riesgo' => $nuevo_estado ? 'bajo' : 'medio',
'detalles' => $nuevo_estado ? 'Activación manual de sucursal' : 'Desactivación manual de sucursal',
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
];
logAuditoria($conn, 'SUCURSAL_TOGGLE_ESTADO_ACTIVO', 'sucursales', $sucursal_id, $detalles, $user['id']);
echo json_encode(['success' => true, 'message' => 'Estado actualizado correctamente']);
exit;
} catch(PDOException $e) {
logAuditoria($conn, 'ERROR_TOOGLE_ESTADO_SUCURSAL', 'sucursales', $_POST['sucursal_id'] ?? null, ['error' => $e->getMessage()], $user['id']);
echo json_encode(['success' => false, 'message' => 'Error al actualizar estado: ' . $e->getMessage()]);
exit;
}
}
// ==================== GUARDAR SUCURSAL ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_sucursal'])) {
try {
$sucursal_id = isset($_POST['sucursal_id']) && !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
$empresa_id = (int)$_POST['empresa_id'];
$nombre = sanitizeInput($_POST['nombre']);
$domicilio = sanitizeInput($_POST['domicilio']);
$localidad = sanitizeInput($_POST['localidad']);
$fecha_habilitacion = !empty($_POST['fecha_habilitacion']) ? $_POST['fecha_habilitacion'] : null;
$telefono = sanitizeInput($_POST['telefono']);
$email = sanitizeInput($_POST['email']);
$responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
$responsable_nombre = '';
if ($responsable_id) {
$stmt = $conn->prepare("SELECT nombre, apellido FROM personal WHERE id = :id");
$stmt->execute(['id' => $responsable_id]);
$personal_data = $stmt->fetch();
if ($personal_data) {
$responsable_nombre = $personal_data['apellido'] . ', ' . $personal_data['nombre'];
}
}
$pago_arancel = isset($_POST['pago_arancel']) ? 1 : 0;
$fecha_pago_arancel = !empty($_POST['fecha_pago_arancel']) ? $_POST['fecha_pago_arancel'] : null;
$en_funcionamiento = isset($_POST['en_funcionamiento']) ? 1 : 0;
$jurisdiccion = sanitizeInput($_POST['jurisdiccion']);
$fecha_habilitacion_jp = !empty($_POST['fecha_habilitacion_jp']) ? $_POST['fecha_habilitacion_jp'] : null;
$numero_resolucion = sanitizeInput($_POST['numero_resolucion']);
// ✅ NUEVO CAMPO: FECHA DE RESOLUCION
$fecha_resolucion = !empty($_POST['fecha_resolucion']) ? $_POST['fecha_resolucion'] : null;
$renar = isset($_POST['renar']) ? 1 : 0;
$certificado_cumplimiento = isset($_POST['certificado_cumplimiento']) ? 1 : 0;
$habilitacion_comercial = isset($_POST['habilitacion_comercial']) ? 1 : 0;
$tiene_protocolos = isset($_POST['tiene_protocolos']) ? 1 : 0;
$tiene_art = isset($_POST['tiene_art']) ? 1 : 0;
$tiene_seguro_rc = isset($_POST['tiene_seguro_rc']) ? 1 : 0;
// ✅ NUEVO CAMPO: ÚLTIMOS INFORMES ENVIADOS A SUCURSALES DE LA EMPRESA
$ultimos_informes_enviados = isset($_POST['ultimos_informes_enviados']) ? 1 : 0;
// ✅ FIN NUEVO CAMPO
// ✅ NUEVOS CAMPOS: ANTECEDENTES DE LA EMPRESA
$sumarios_administrativos = sanitizeInput($_POST['sumarios_administrativos'] ?? '');
$sanciones_aplicadas = sanitizeInput($_POST['sanciones_aplicadas'] ?? '');
$estado_judicial = sanitizeInput($_POST['estado_judicial'] ?? '');
$infracciones_leves = !empty($_POST['infracciones_leves']) ? (int)$_POST['infracciones_leves'] : 0;
$infracciones_graves = !empty($_POST['infracciones_graves']) ? (int)$_POST['infracciones_graves'] : 0;
$infracciones_muy_graves = !empty($_POST['infracciones_muy_graves']) ? (int)$_POST['infracciones_muy_graves'] : 0;
$fecha_ultima_infraccion = !empty($_POST['fecha_ultima_infraccion']) ? $_POST['fecha_ultima_infraccion'] : null;
$observaciones_antecedentes = sanitizeInput($_POST['observaciones_antecedentes'] ?? '');
// ✅ FIN NUEVOS CAMPOS
if ($sucursal_id) {
$activa = isset($_POST['esta_activa']) ? 1 : 0;
} else {
$activa = 0;
}
if (empty($empresa_id) || empty($nombre) || empty($domicilio) || empty($localidad) || empty($jurisdiccion)) {
throw new Exception('Todos los campos marcados con * son obligatorios');
}
$stmt = $conn->prepare("SELECT id, nombre FROM empresas WHERE id = :id AND activo = TRUE");
$stmt->execute(['id' => $empresa_id]);
$empresa_data = $stmt->fetch();
if (!$empresa_data) {
throw new Exception('La empresa seleccionada no existe o está inactiva');
}
$nombre_empresa = $empresa_data['nombre'];
$cupon_pago_file = '';
if (isset($_FILES['cupon_pago']) && $_FILES['cupon_pago']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['cupon_pago']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['pdf', 'jpg', 'jpeg', 'png'])) {
throw new Exception('El cupón debe ser PDF, JPG, JPEG o PNG');
}
if ($_FILES['cupon_pago']['size'] > 5000000) {
throw new Exception('El cupón no debe superar los 5MB');
}
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre_empresa);
$new_filename = 'cupon_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.' . $file_extension;
$target_file = $target_dir_cupones . $new_filename;
if (move_uploaded_file($_FILES['cupon_pago']['tmp_name'], $target_file)) {
$cupon_pago_file = $new_filename;
}
}
if (empty($cupon_pago_file) && $sucursal_id) {
$stmt = $conn->prepare("SELECT cupon_pago FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$existing = $stmt->fetch();
$cupon_pago_file = $existing['cupon_pago'] ?? '';
}
$pdf_resolucion_file = '';
if (isset($_FILES['pdf_resolucion']) && $_FILES['pdf_resolucion']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['pdf_resolucion']['name'], PATHINFO_EXTENSION));
if ($file_extension !== 'pdf') {
throw new Exception('El archivo de resolución debe ser PDF');
}
if ($_FILES['pdf_resolucion']['size'] > 10000000) {
throw new Exception('El PDF no debe superar los 10MB');
}
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre_empresa);
$new_filename = 'resolucion_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.pdf';
$target_file = $target_dir_resoluciones . $new_filename;
if (move_uploaded_file($_FILES['pdf_resolucion']['tmp_name'], $target_file)) {
$pdf_resolucion_file = $new_filename;
}
}
if (empty($pdf_resolucion_file) && $sucursal_id) {
$stmt = $conn->prepare("SELECT pdf_resolucion FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$existing = $stmt->fetch();
$pdf_resolucion_file = $existing['pdf_resolucion'] ?? '';
}
$pdf_sucursal_file = '';
if (isset($_FILES['pdf_sucursal']) && $_FILES['pdf_sucursal']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['pdf_sucursal']['name'], PATHINFO_EXTENSION));
if ($file_extension !== 'pdf') {
throw new Exception('El archivo PDF de la sucursal debe ser PDF');
}
if ($_FILES['pdf_sucursal']['size'] > 10000000) {
throw new Exception('El PDF no debe superar los 10MB');
}
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre_empresa);
$new_filename = 'sucursal_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.pdf';
$target_file = $target_dir_pdf_sucursal . $new_filename;
if (move_uploaded_file($_FILES['pdf_sucursal']['tmp_name'], $target_file)) {
$pdf_sucursal_file = $new_filename;
}
}
if (empty($pdf_sucursal_file) && $sucursal_id) {
$stmt = $conn->prepare("SELECT pdf_sucursal FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$existing = $stmt->fetch();
$pdf_sucursal_file = $existing['pdf_sucursal'] ?? '';
}
// ✅ FOTOS DEL UNIFORME
$fotos_uniforme_file = '';
if (isset($_FILES['fotos_uniforme']) && $_FILES['fotos_uniforme']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['fotos_uniforme']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['jpg', 'jpeg', 'png', 'webp'])) {
throw new Exception('Las fotos deben ser JPG, JPEG, PNG o WebP');
}
if ($_FILES['fotos_uniforme']['size'] > 10000000) {
throw new Exception('Las fotos no deben superar los 10MB');
}
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre_empresa);
$new_filename = 'uniforme_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.' . $file_extension;
$target_file = $target_dir_fotos_sucursal . $new_filename;
if (move_uploaded_file($_FILES['fotos_uniforme']['tmp_name'], $target_file)) {
$fotos_uniforme_file = $new_filename;
}
}
if (empty($fotos_uniforme_file) && $sucursal_id) {
$stmt = $conn->prepare("SELECT fotos_uniforme FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$existing = $stmt->fetch();
$fotos_uniforme_file = $existing['fotos_uniforme'] ?? '';
}
// ✅ FOTOS DE LOS VEHICULOS
$fotos_vehiculos_file = '';
if (isset($_FILES['fotos_vehiculos']) && $_FILES['fotos_vehiculos']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['fotos_vehiculos']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['jpg', 'jpeg', 'png', 'webp'])) {
throw new Exception('Las fotos deben ser JPG, JPEG, PNG o WebP');
}
if ($_FILES['fotos_vehiculos']['size'] > 10000000) {
throw new Exception('Las fotos no deben superar los 10MB');
}
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre_empresa);
$new_filename = 'vehiculos_' . $empresa_nombre_limpio . '_' . date('Ymd') . '_' . time() . '.' . $file_extension;
$target_file = $target_dir_fotos_sucursal . $new_filename;
if (move_uploaded_file($_FILES['fotos_vehiculos']['tmp_name'], $target_file)) {
$fotos_vehiculos_file = $new_filename;
}
}
if (empty($fotos_vehiculos_file) && $sucursal_id) {
$stmt = $conn->prepare("SELECT fotos_vehiculos FROM sucursales WHERE id = :id");
$stmt->execute(['id' => $sucursal_id]);
$existing = $stmt->fetch();
$fotos_vehiculos_file = $existing['fotos_vehiculos'] ?? '';
}
$accion_tipo = $sucursal_id ? 'MODIFICACION' : 'CREACION';
if ($sucursal_id) {
// ==================== MODIFICACIÓN DE SUCURSAL EXISTENTE ====================
$stmt = $conn->prepare("SELECT * FROM sucursales WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $sucursal_id]);
$sucursal_anterior = $stmt->fetch();
$stmt = $conn->prepare("
UPDATE sucursales SET
empresa_id = :empresa_id, nombre = :nombre, domicilio = :domicilio, localidad = :localidad,
fecha_habilitacion = :fecha_habilitacion, telefono = :telefono, email = :email,
responsable_id = :responsable_id, responsable_nombre = :responsable_nombre,
pago_arancel = :pago_arancel, fecha_pago_arancel = :fecha_pago_arancel, cupon_pago = :cupon_pago,
en_funcionamiento = :en_funcionamiento, jurisdiccion = :jurisdiccion,
fecha_habilitacion_jp = :fecha_habilitacion_jp, numero_resolucion = :numero_resolucion,
fecha_resolucion = :fecha_resolucion,
pdf_resolucion = :pdf_resolucion, pdf_sucursal = :pdf_sucursal, renar = :renar,
certificado_cumplimiento = :certificado_cumplimiento, habilitacion_comercial = :habilitacion_comercial,
activa = :activa, fotos_uniforme = :fotos_uniforme, fotos_vehiculos = :fotos_vehiculos,
tiene_protocolos = :tiene_protocolos, tiene_art = :tiene_art, tiene_seguro_rc = :tiene_seguro_rc,
ultimos_informes_enviados = :ultimos_informes_enviados,
sumarios_administrativos = :sumarios_administrativos,
sanciones_aplicadas = :sanciones_aplicadas,
estado_judicial = :estado_judicial,
infracciones_leves = :infracciones_leves,
infracciones_graves = :infracciones_graves,
infracciones_muy_graves = :infracciones_muy_graves,
fecha_ultima_infraccion = :fecha_ultima_infraccion,
observaciones_antecedentes = :observaciones_antecedentes,
fecha_carga_fotos = NOW(), updated_at = NOW()
WHERE id = :id
");
$stmt->execute([
'empresa_id' => $empresa_id, 'nombre' => $nombre, 'domicilio' => $domicilio,
'localidad' => $localidad, 'fecha_habilitacion' => $fecha_habilitacion,
'telefono' => $telefono, 'email' => $email,
'responsable_id' => $responsable_id, 'responsable_nombre' => $responsable_nombre,
'pago_arancel' => $pago_arancel, 'fecha_pago_arancel' => $fecha_pago_arancel, 'cupon_pago' => $cupon_pago_file,
'en_funcionamiento' => $en_funcionamiento, 'jurisdiccion' => $jurisdiccion,
'fecha_habilitacion_jp' => $fecha_habilitacion_jp, 'numero_resolucion' => $numero_resolucion,
'fecha_resolucion' => $fecha_resolucion,
'pdf_resolucion' => $pdf_resolucion_file, 'pdf_sucursal' => $pdf_sucursal_file,
'renar' => $renar, 'certificado_cumplimiento' => $certificado_cumplimiento,
'habilitacion_comercial' => $habilitacion_comercial, 'activa' => $activa,
'fotos_uniforme' => $fotos_uniforme_file, 'fotos_vehiculos' => $fotos_vehiculos_file,
'tiene_protocolos' => $tiene_protocolos, 'tiene_art' => $tiene_art, 'tiene_seguro_rc' => $tiene_seguro_rc,
'ultimos_informes_enviados' => $ultimos_informes_enviados,
'sumarios_administrativos' => $sumarios_administrativos,
'sanciones_aplicadas' => $sanciones_aplicadas,
'estado_judicial' => $estado_judicial,
'infracciones_leves' => $infracciones_leves,
'infracciones_graves' => $infracciones_graves,
'infracciones_muy_graves' => $infracciones_muy_graves,
'fecha_ultima_infraccion' => $fecha_ultima_infraccion,
'observaciones_antecedentes' => $observaciones_antecedentes,
'id' => $sucursal_id
]);
// ✅ ELIMINADA TODA LA LÓGICA DE ACTUALIZACIÓN DE TABLA PERSONAL
$detalles = [
'accion' => 'MODIFICACION_SUCURSAL',
'tabla' => 'sucursales',
'registro_id' => $sucursal_id,
'empresa' => $nombre_empresa,
'nombre_sucursal' => $nombre,
'campos_modificados' => 20,
'responsable_id' => $responsable_id,
'cambio_pdf_cupón' => !empty($cupon_pago_file) && $cupon_pago_file != $sucursal_anterior['cupon_pago'] ? 'subido' : 'sin cambio',
'cambio_pdf_resolucion' => !empty($pdf_resolucion_file) && $pdf_resolucion_file != $sucursal_anterior['pdf_resolucion'] ? 'subido' : 'sin cambio',
'cambio_pdf_sucursal' => !empty($pdf_sucursal_file) && $pdf_sucursal_file != $sucursal_anterior['pdf_sucursal'] ? 'subido' : 'sin cambio',
'cambio_fotos_uniforme' => !empty($fotos_uniforme_file) && $fotos_uniforme_file != ($sucursal_anterior['fotos_uniforme'] ?? '') ? 'subido' : 'sin cambio',
'cambio_fotos_vehiculos' => !empty($fotos_vehiculos_file) && $fotos_vehiculos_file != ($sucursal_anterior['fotos_vehiculos'] ?? '') ? 'subido' : 'sin cambio',
'cambio_activa' => $activa != $sucursal_anterior['activa'] ? 'cambiado' : 'sin cambio',
'ultimos_informes_enviados' => $ultimos_informes_enviados,
'tiene_protocolos' => $tiene_protocolos,
'tiene_art' => $tiene_art,
'tiene_seguro_rc' => $tiene_seguro_rc,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
'notas' => 'Modificación de datos de sucursal - SIN actualización de tabla personal'
];
logAuditoria($conn, 'MODIFICACION_SUCURSAL', 'sucursales', $sucursal_id, $detalles, $user['id']);
$_SESSION['success'] = 'Sucursal actualizada correctamente';
} else {
// ==================== CREACIÓN DE NUEVA SUCURSAL ====================
$stmt = $conn->prepare("
INSERT INTO sucursales
(empresa_id, nombre, domicilio, localidad, fecha_habilitacion, telefono, email,
responsable_id, responsable_nombre,
pago_arancel, fecha_pago_arancel, cupon_pago, en_funcionamiento, jurisdiccion, fecha_habilitacion_jp,
numero_resolucion, fecha_resolucion,
pdf_resolucion, pdf_sucursal, renar, certificado_cumplimiento,
habilitacion_comercial, activa, estado_aprobacion, fecha_solicitud, aprobador_id,
fotos_uniforme, fotos_vehiculos, fecha_carga_fotos, tiene_protocolos, tiene_art, tiene_seguro_rc,
ultimos_informes_enviados,
sumarios_administrativos, sanciones_aplicadas, estado_judicial,
infracciones_leves, infracciones_graves, infracciones_muy_graves,
fecha_ultima_infraccion, observaciones_antecedentes)
VALUES (:empresa_id, :nombre, :domicilio, :localidad, :fecha_habilitacion, :telefono,
:email, :responsable_id, :responsable_nombre, :pago_arancel, :fecha_pago_arancel, :cupon_pago, :en_funcionamiento, :jurisdiccion,
:fecha_habilitacion_jp, :numero_resolucion, :fecha_resolucion,
:pdf_resolucion, :pdf_sucursal, :renar,
:certificado_cumplimiento, :habilitacion_comercial, :activa, 'pendiente', NOW(), :aprobador_id,
:fotos_uniforme, :fotos_vehiculos, NOW(), :tiene_protocolos, :tiene_art, :tiene_seguro_rc,
:ultimos_informes_enviados,
:sumarios_administrativos, :sanciones_aplicadas, :estado_judicial,
:infracciones_leves, :infracciones_graves, :infracciones_muy_graves,
:fecha_ultima_infraccion, :observaciones_antecedentes)
");
$stmt->execute([
'empresa_id' => $empresa_id, 'nombre' => $nombre, 'domicilio' => $domicilio,
'localidad' => $localidad, 'fecha_habilitacion' => $fecha_habilitacion,
'telefono' => $telefono, 'email' => $email,
'responsable_id' => $responsable_id, 'responsable_nombre' => $responsable_nombre,
'pago_arancel' => $pago_arancel, 'fecha_pago_arancel' => $fecha_pago_arancel, 'cupon_pago' => $cupon_pago_file,
'en_funcionamiento' => $en_funcionamiento, 'jurisdiccion' => $jurisdiccion,
'fecha_habilitacion_jp' => $fecha_habilitacion_jp, 'numero_resolucion' => $numero_resolucion,
'fecha_resolucion' => $fecha_resolucion,
'pdf_resolucion' => $pdf_resolucion_file, 'pdf_sucursal' => $pdf_sucursal_file,
'renar' => $renar, 'certificado_cumplimiento' => $certificado_cumplimiento,
'habilitacion_comercial' => $habilitacion_comercial,
'activa' => $activa,
'aprobador_id' => $user['id'],
'fotos_uniforme' => $fotos_uniforme_file,
'fotos_vehiculos' => $fotos_vehiculos_file,
'tiene_protocolos' => $tiene_protocolos,
'tiene_art' => $tiene_art,
'tiene_seguro_rc' => $tiene_seguro_rc,
'ultimos_informes_enviados' => $ultimos_informes_enviados,
'sumarios_administrativos' => $sumarios_administrativos,
'sanciones_aplicadas' => $sanciones_aplicadas,
'estado_judicial' => $estado_judicial,
'infracciones_leves' => $infracciones_leves,
'infracciones_graves' => $infracciones_graves,
'infracciones_muy_graves' => $infracciones_muy_graves,
'fecha_ultima_infraccion' => $fecha_ultima_infraccion,
'observaciones_antecedentes' => $observaciones_antecedentes
]);
$sucursal_inserted_id = $conn->lastInsertId();
// ✅ ELIMINADA TODA LA LÓGICA DE ACTUALIZACIÓN DE TABLA PERSONAL
$detalles = [
'accion' => 'CREACION_SUCURSAL',
'tabla' => 'sucursales',
'registro_id' => $sucursal_inserted_id,
'empresa' => $nombre_empresa,
'nombre_sucursal' => $nombre,
'domicilio' => $domicilio,
'jurisdiccion' => $jurisdiccion,
'responsable_id' => $responsable_id,
'archivo_cupón_subido' => !empty($cupon_pago_file),
'archivo_resolucion_subido' => !empty($pdf_resolucion_file),
'archivo_sucursal_subido' => !empty($pdf_sucursal_file),
'fotos_uniforme_subido' => !empty($fotos_uniforme_file),
'fotos_vehiculos_subido' => !empty($fotos_vehiculos_file),
'estado_aprobacion' => 'pendiente',
'estado_activacion' => $activa ? 'activa' : 'inactiva',
'ultimos_informes_enviados' => $ultimos_informes_enviados,
'tiene_protocolos' => $tiene_protocolos,
'tiene_art' => $tiene_art,
'tiene_seguro_rc' => $tiene_seguro_rc,
'usuario' => $user['nombre_usuario'] ?? 'Sistema',
'fecha' => date('Y-m-d H:i:s'),
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
'notas' => 'Creación de sucursal - SIN actualización de tabla personal'
];
logAuditoria($conn, 'CREACION_SUCURSAL', 'sucursales', $sucursal_inserted_id, $detalles, $user['id']);
$_SESSION['success'] = 'Sucursal creada correctamente (inactiva - pendiente de aprobación)';
}
header('Location: sucursales.php');
exit;
} catch(Exception $e) {
$_SESSION['error'] = $e->getMessage();
logAuditoria($conn, 'ERROR_GUARDAR_SUCURSAL', 'sucursales', (int)$_POST['sucursal_id'] ?? null, ['error' => $e->getMessage()], $user['id']);
}
}
// ==================== ✅ SECCIÓN DE FILTROS COMPLETA ====================
$filtro_empresa = isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa']) ? (int)$_GET['filtro_empresa'] : 0;
$filtro_jurisdiccion = isset($_GET['filtro_jurisdiccion']) && !empty($_GET['filtro_jurisdiccion']) ? sanitizeInput($_GET['filtro_jurisdiccion']) : '';
$filtro_localidad = isset($_GET['filtro_localidad']) && !empty($_GET['filtro_localidad']) ? sanitizeInput($_GET['filtro_localidad']) : '';
$filtro_responsable = isset($_GET['filtro_responsable']) && !empty($_GET['filtro_responsable']) ? (int)$_GET['filtro_responsable'] : 0;
$filtro_texto = isset($_GET['filtro_texto']) && !empty($_GET['filtro_texto']) ? sanitizeInput($_GET['filtro_texto']) : '';
$filtro_activa = isset($_GET['filtro_activa']) && $_GET['filtro_activa'] !== '' ? (int)$_GET['filtro_activa'] : null;
$filtro_en_funcionamiento = isset($_GET['filtro_en_funcionamiento']) && $_GET['filtro_en_funcionamiento'] !== '' ? (int)$_GET['filtro_en_funcionamiento'] : null;
$filtro_aprobacion = isset($_GET['filtro_aprobacion']) ? $_GET['filtro_aprobacion'] : 'todos';
$filtro_pago_arancel = isset($_GET['filtro_pago_arancel']) && $_GET['filtro_pago_arancel'] !== '' ? (int)$_GET['filtro_pago_arancel'] : null;
$filtro_renar = isset($_GET['filtro_renar']) && $_GET['filtro_renar'] !== '' ? (int)$_GET['filtro_renar'] : null;
$filtro_fecha_desde = isset($_GET['filtro_fecha_desde']) && !empty($_GET['filtro_fecha_desde']) ? $_GET['filtro_fecha_desde'] : '';
$filtro_fecha_hasta = isset($_GET['filtro_fecha_hasta']) && !empty($_GET['filtro_fecha_hasta']) ? $_GET['filtro_fecha_hasta'] : '';
$registros_por_pagina = 15;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
// ✅ MODIFICADO: Columnas permitidas para ordenamiento ampliadas y orden por defecto por estado (activa)
$columnas_permitidas = ['id', 'nombre', 'empresa_nombre', 'domicilio', 'localidad', 'jurisdiccion', 'activa', 'en_funcionamiento', 'fecha_habilitacion', 'fecha_solicitud', 'estado_aprobacion', 'responsable_nombre'];
$orden_columna = $_GET['orden'] ?? 'activa';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'ASC');
if (!in_array($orden_columna, $columnas_permitidas)) $orden_columna = 'activa';
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') $orden_direccion = 'ASC';
// ==================== CONSULTA PRINCIPAL ====================
$query = "SELECT s.*, e.nombre as empresa_nombre, u.username as aprobador_username, p.nombre as responsable_nombre, p.apellido as responsable_apellido FROM sucursales s LEFT JOIN empresas e ON s.empresa_id = e.id LEFT JOIN usuarios u ON s.aprobador_id = u.id LEFT JOIN personal p ON s.responsable_id = p.id WHERE 1=1";
$params = [];
if ($filtro_empresa > 0) {
$query .= " AND s.empresa_id = :filtro_empresa";
$params['filtro_empresa'] = $filtro_empresa;
}
if (!empty($filtro_jurisdiccion)) {
$query .= " AND s.jurisdiccion = :filtro_jurisdiccion";
$params['filtro_jurisdiccion'] = $filtro_jurisdiccion;
}
if (!empty($filtro_localidad)) {
$query .= " AND s.localidad = :filtro_localidad";
$params['filtro_localidad'] = $filtro_localidad;
}
if ($filtro_responsable > 0) {
$query .= " AND s.responsable_id = :filtro_responsable";
$params['filtro_responsable'] = $filtro_responsable;
}
if (!empty($filtro_texto)) {
$query .= " AND (s.nombre LIKE :filtro_texto1 OR s.domicilio LIKE :filtro_texto2 OR s.localidad LIKE :filtro_texto3 OR e.nombre LIKE :filtro_texto4)";
$params['filtro_texto1'] = "%{$filtro_texto}%";
$params['filtro_texto2'] = "%{$filtro_texto}%";
$params['filtro_texto3'] = "%{$filtro_texto}%";
$params['filtro_texto4'] = "%{$filtro_texto}%";
}
if ($filtro_activa !== null) {
$query .= " AND s.activa = :filtro_activa";
$params['filtro_activa'] = $filtro_activa;
}
if ($filtro_en_funcionamiento !== null) {
$query .= " AND s.en_funcionamiento = :filtro_en_funcionamiento";
$params['filtro_en_funcionamiento'] = $filtro_en_funcionamiento;
}
if ($filtro_aprobacion === 'pendiente') {
$query .= " AND (s.estado_aprobacion = 'pendiente' OR s.estado_aprobacion IS NULL)";
} elseif ($filtro_aprobacion === 'aprobado') {
$query .= " AND s.estado_aprobacion = 'aprobado'";
} elseif ($filtro_aprobacion === 'rechazado') {
$query .= " AND s.estado_aprobacion = 'rechazado'";
}
if ($filtro_pago_arancel !== null) {
$query .= " AND s.pago_arancel = :filtro_pago_arancel";
$params['filtro_pago_arancel'] = $filtro_pago_arancel;
}
if ($filtro_renar !== null) {
$query .= " AND s.renar = :filtro_renar";
$params['filtro_renar'] = $filtro_renar;
}
if (!empty($filtro_fecha_desde)) {
$query .= " AND s.fecha_solicitud >= :filtro_fecha_desde";
$params['filtro_fecha_desde'] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$query .= " AND s.fecha_solicitud <= :filtro_fecha_hasta";
$params['filtro_fecha_hasta'] = $filtro_fecha_hasta . ' 23:59:59';
}
// ==================== CONTADOR TOTAL ====================
$count_query = "SELECT COUNT(*) as total FROM sucursales s LEFT JOIN empresas e ON s.empresa_id = e.id WHERE 1=1";
$count_params = [];
if ($filtro_empresa > 0) {
$count_query .= " AND s.empresa_id = :filtro_empresa";
$count_params['filtro_empresa'] = $filtro_empresa;
}
if (!empty($filtro_jurisdiccion)) {
$count_query .= " AND s.jurisdiccion = :filtro_jurisdiccion";
$count_params['filtro_jurisdiccion'] = $filtro_jurisdiccion;
}
if (!empty($filtro_localidad)) {
$count_query .= " AND s.localidad = :filtro_localidad";
$count_params['filtro_localidad'] = $filtro_localidad;
}
if ($filtro_responsable > 0) {
$count_query .= " AND s.responsable_id = :filtro_responsable";
$count_params['filtro_responsable'] = $filtro_responsable;
}
if (!empty($filtro_texto)) {
$count_query .= " AND (s.nombre LIKE :filtro_texto1 OR s.domicilio LIKE :filtro_texto2 OR s.localidad LIKE :filtro_texto3 OR e.nombre LIKE :filtro_texto4)";
$count_params['filtro_texto1'] = "%{$filtro_texto}%";
$count_params['filtro_texto2'] = "%{$filtro_texto}%";
$count_params['filtro_texto3'] = "%{$filtro_texto}%";
$count_params['filtro_texto4'] = "%{$filtro_texto}%";
}
if ($filtro_activa !== null) {
$count_query .= " AND s.activa = :filtro_activa";
$count_params['filtro_activa'] = $filtro_activa;
}
if ($filtro_en_funcionamiento !== null) {
$count_query .= " AND s.en_funcionamiento = :filtro_en_funcionamiento";
$count_params['filtro_en_funcionamiento'] = $filtro_en_funcionamiento;
}
if ($filtro_aprobacion === 'pendiente') {
$count_query .= " AND (s.estado_aprobacion = 'pendiente' OR s.estado_aprobacion IS NULL)";
} elseif ($filtro_aprobacion === 'aprobado') {
$count_query .= " AND s.estado_aprobacion = 'aprobado'";
} elseif ($filtro_aprobacion === 'rechazado') {
$count_query .= " AND s.estado_aprobacion = 'rechazado'";
}
if ($filtro_pago_arancel !== null) {
$count_query .= " AND s.pago_arancel = :filtro_pago_arancel";
$count_params['filtro_pago_arancel'] = $filtro_pago_arancel;
}
if ($filtro_renar !== null) {
$count_query .= " AND s.renar = :filtro_renar";
$count_params['filtro_renar'] = $filtro_renar;
}
if (!empty($filtro_fecha_desde)) {
$count_query .= " AND s.fecha_solicitud >= :filtro_fecha_desde";
$count_params['filtro_fecha_desde'] = $filtro_fecha_desde . ' 00:00:00';
}
if (!empty($filtro_fecha_hasta)) {
$count_query .= " AND s.fecha_solicitud <= :filtro_fecha_hasta";
$count_params['filtro_fecha_hasta'] = $filtro_fecha_hasta . ' 23:59:59';
}
// Línea ~570 - Reemplazar por:
$pendientes_query = "SELECT COUNT(*) as total FROM sucursales WHERE (estado_aprobacion = 'pendiente' OR estado_aprobacion IS NULL)";
$pendientes_aprobacion_count = $conn->query($pendientes_query)->fetch()['total'];
$stmt_count = $conn->prepare($count_query);
$stmt_count->execute($count_params);
$total_registros = $stmt_count->fetch()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$query .= " ORDER BY s.$orden_columna $orden_direccion LIMIT $registros_por_pagina OFFSET $offset";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$sucursales = $stmt->fetchAll();
logAuditoria($conn, 'VISUALIZACION_LISTADO_SUCURSALES', 'sucursales', null, [
'cant_registros' => count($sucursales),
'filtros_usados' => compact('filtro_empresa', 'filtro_jurisdiccion', 'filtro_localidad', 'filtro_responsable', 'filtro_texto', 'filtro_activa', 'filtro_en_funcionamiento', 'filtro_aprobacion', 'filtro_pago_arancel', 'filtro_renar'),
'orden' => $orden_columna . ' ' . $orden_direccion,
'pagina' => $pagina_actual
], $user['id']);
// ==================== DATOS AUXILIARES ====================
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll();
// ✅ MODIFICADO: Incluir DIRECTOR TECNICO y todos los cargos con DIRECTOR
// ✅ NUEVO: Incluir subconsulta para contar sucursales donde cada personal es responsable
// NOTA: Un mismo Director Técnico puede ser responsable de múltiples sucursales (relación 1:N desde sucursales.responsable_id)
$stmt = $conn->query("SELECT id, nombre, apellido, dni, cargo, empresa_id, sucursal_id,
(SELECT COUNT(*) FROM sucursales s2 WHERE s2.responsable_id = personal.id) as total_sucursales_responsable
FROM personal WHERE activo = TRUE AND (cargo = 'DIRECTOR TECNICO' OR cargo LIKE '%DIRECTOR%' OR cargo IS NULL) ORDER BY apellido, nombre");
$personal_disponible = $stmt->fetchAll();
$stmt = $conn->query("SELECT DISTINCT jurisdiccion FROM sucursales WHERE jurisdiccion IS NOT NULL AND jurisdiccion != '' ORDER BY jurisdiccion");
$jurisdicciones = $stmt->fetchAll();
$stmt = $conn->query("SELECT DISTINCT localidad FROM sucursales WHERE localidad IS NOT NULL AND localidad != '' ORDER BY localidad");
$localidades = $stmt->fetchAll();
$sucursal_edit = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
$edit_id = (int)$_GET['edit'];
$stmt = $conn->prepare("SELECT s.*, e.nombre as empresa_nombre FROM sucursales s LEFT JOIN empresas e ON s.empresa_id = e.id WHERE s.id = :id");
$stmt->execute(['id' => $edit_id]);
$sucursal_edit = $stmt->fetch();
logAuditoria($conn, 'EDITAR_SUCURSAL_VER', 'sucursales', $edit_id, ['sucursal' => $sucursal_edit['nombre'] ?? 'desconocida'], $user['id']);
}
function generarUrlOrden($columna, $orden_columna, $orden_direccion) {
$params = $_GET;
$params['orden'] = $columna;
$params['direccion'] = ($columna === $orden_columna && $orden_direccion === 'ASC') ? 'DESC' : 'ASC';
unset($params['pagina']);
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
unset($params['pagina'], $params['orden'], $params['direccion']);
return !empty($params) ? '&' . http_build_query($params) : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Sucursales - Sistema de Seguridad</title>
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
.badge-funcionamiento-true {
background: linear-gradient(135deg, #2ecc71, #27ae60);
color: white;
}
.badge-funcionamiento-false {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
}
.aprobacion-pendiente { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
.aprobacion-aprobado { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
.aprobacion-rechazado { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
/* ✅ ESTILOS PARA FILTROS */
.filtros-box {
background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
border: 1px solid var(--card-border);
border-radius: 8px;
padding: 20px;
margin-bottom: 20px;
box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}
.filtros-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 15px;
cursor: pointer;
}
.filtros-header h5 {
margin: 0;
color: #495057;
font-weight: 600;
}
.filtro-group {
margin-bottom: 15px;
}
.filtro-group label {
font-size: 0.85rem;
font-weight: 600;
color: #6c757d;
margin-bottom: 5px;
}
.btn-limpiar-filtros {
background: #6c757d;
color: white;
}
.btn-limpiar-filtros:hover {
background: #5a6268;
color: white;
}
.badge-filtro-activo {
background: var(--primary-color);
color: white;
font-size: 0.7rem;
}
/* ✅ ICONO DE ROTACIÓN PARA FILTROS */
.icono-rotar {
transition: transform 0.3s ease;
}
.icono-rotar.rotado {
transform: rotate(180deg);
}
/* ✅ ESTILOS PARA ACORDEONES DEL FORMULARIO */
.accordion-item {
border: 1px solid var(--card-border);
margin-bottom: 10px;
border-radius: 4px !important;
}
.accordion-button {
background-color: #f8f9fa;
font-weight: 600;
color: #495057;
border-radius: 4px !important;
}
.accordion-button:not(.collapsed) {
background-color: var(--primary-color);
color: white;
}
.accordion-button:focus {
box-shadow: none;
border-color: var(--card-border);
}
.accordion-body {
background-color: #ffffff;
padding: 1rem 1.25rem;
}
</style>
</head>
<body>
<?php $page_title = 'Gestión de Sucursales'; include '../includes/header.php'; ?>
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
<div class="stat-icon mb-2 text-primary"><i class="fas fa-building fa-2x"></i></div>
<div class="stat-number"><?php echo $total_registros; ?></div>
<div class="stat-label">Sucursales Totales</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number">
<?php
$stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE activa = 1");
echo $stmt->fetch()['total'];
?>
</div>
<div class="stat-label">Sucursales Activas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-times-circle fa-2x"></i></div>
<div class="stat-number">
<?php
$stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE activa = 0");
echo $stmt->fetch()['total'];
?>
</div>
<div class="stat-label">Sucursales Inactivas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-clock fa-2x"></i></div>
<div class="stat-number"><?php echo $pendientes_aprobacion_count; ?></div>
<div class="stat-label">Pendientes Aprobación</div>
</div>
</div>
<!-- ✅ SECCIÓN DE FILTROS AVANZADOS (CONTRAÍBLE) -->
<div class="filtros-box">
<div class="filtros-header" data-bs-toggle="collapse" data-bs-target="#collapseFiltros" aria-expanded="false" aria-controls="collapseFiltros">
<h5><i class="fas fa-filter me-2"></i>Filtros de Búsqueda</h5>
<?php
$filtros_activos = array_filter([
$filtro_empresa, $filtro_jurisdiccion, $filtro_localidad, $filtro_responsable,
$filtro_texto, $filtro_activa, $filtro_en_funcionamiento, $filtro_aprobacion !== 'todos',
$filtro_pago_arancel, $filtro_renar, $filtro_fecha_desde, $filtro_fecha_hasta
]);
?>
<div class="d-flex align-items-center">
<?php if (!empty($filtros_activos)): ?>
<span class="badge badge-filtro-activo me-2"><?php echo count($filtros_activos); ?> filtros activos</span>
<?php endif; ?>
<i class="fas fa-chevron-down icono-rotar" id="iconoFiltros"></i>
</div>
</div>
<div class="collapse" id="collapseFiltros">
<form method="GET" action="sucursales.php" class="row g-3 mt-2">
<div class="col-md-3">
<div class="filtro-group">
<label><i class="fas fa-building me-1"></i>Empresa</label>
<select name="filtro_empresa" class="form-select form-select-sm">
<option value="">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo $filtro_empresa == $empresa['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-map-marker-alt me-1"></i>Jurisdicción</label>
<select name="filtro_jurisdiccion" class="form-select form-select-sm">
<option value="">Todas</option>
<?php foreach ($jurisdicciones as $jur): ?>
<option value="<?php echo $jur['jurisdiccion']; ?>" <?php echo $filtro_jurisdiccion == $jur['jurisdiccion'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($jur['jurisdiccion']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-city me-1"></i>Localidad</label>
<select name="filtro_localidad" class="form-select form-select-sm">
<option value="">Todas</option>
<?php foreach ($localidades as $loc): ?>
<option value="<?php echo $loc['localidad']; ?>" <?php echo $filtro_localidad == $loc['localidad'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($loc['localidad']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="col-md-3">
<div class="filtro-group">
<label><i class="fas fa-user me-1"></i>Responsable</label>
<select name="filtro_responsable" class="form-select form-select-sm">
<option value="">Todos los responsables</option>
<?php foreach ($personal_disponible as $persona): ?>
<option value="<?php echo $persona['id']; ?>" <?php echo $filtro_responsable == $persona['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre'] .
(isset($persona['total_sucursales_responsable']) && $persona['total_sucursales_responsable'] > 0 ? ' ('.$persona['total_sucursales_responsable'].' sucursales)' : '')); ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-search me-1"></i>Texto</label>
<input type="text" name="filtro_texto" class="form-control form-control-sm"
placeholder="Nombre, domicilio..." value="<?php echo htmlspecialchars($filtro_texto); ?>">
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-toggle-on me-1"></i>Estado</label>
<select name="filtro_activa" class="form-select form-select-sm">
<option value="">Todos</option>
<option value="1" <?php echo $filtro_activa === 1 ? 'selected' : ''; ?>>Activas</option>
<option value="0" <?php echo $filtro_activa === 0 ? 'selected' : ''; ?>>Inactivas</option>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-cog me-1"></i>Funcionamiento</label>
<select name="filtro_en_funcionamiento" class="form-select form-select-sm">
<option value="">Todos</option>
<option value="1" <?php echo $filtro_en_funcionamiento === 1 ? 'selected' : ''; ?>>En funcionamiento</option>
<option value="0" <?php echo $filtro_en_funcionamiento === 0 ? 'selected' : ''; ?>>No funciona</option>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-check-circle me-1"></i>Aprobación</label>
<select name="filtro_aprobacion" class="form-select form-select-sm">
<option value="todos" <?php echo $filtro_aprobacion === 'todos' ? 'selected' : ''; ?>>Todos</option>
<option value="pendiente" <?php echo $filtro_aprobacion === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
<option value="aprobado" <?php echo $filtro_aprobacion === 'aprobado' ? 'selected' : ''; ?>>Aprobados</option>
<option value="rechazado" <?php echo $filtro_aprobacion === 'rechazado' ? 'selected' : ''; ?>>Rechazados</option>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-money-bill me-1"></i>Pago Arancel</label>
<select name="filtro_pago_arancel" class="form-select form-select-sm">
<option value="">Todos</option>
<option value="1" <?php echo $filtro_pago_arancel === 1 ? 'selected' : ''; ?>>Pagado</option>
<option value="0" <?php echo $filtro_pago_arancel === 0 ? 'selected' : ''; ?>>No pagado</option>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-file-alt me-1"></i>RENAR</label>
<select name="filtro_renar" class="form-select form-select-sm">
<option value="">Todos</option>
<option value="1" <?php echo $filtro_renar === 1 ? 'selected' : ''; ?>>Presente</option>
<option value="0" <?php echo $filtro_renar === 0 ? 'selected' : ''; ?>>No presente</option>
</select>
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-calendar-alt me-1"></i>Desde</label>
<input type="date" name="filtro_fecha_desde" class="form-control form-control-sm" value="<?php echo $filtro_fecha_desde; ?>">
</div>
</div>
<div class="col-md-2">
<div class="filtro-group">
<label><i class="fas fa-calendar-alt me-1"></i>Hasta</label>
<input type="date" name="filtro_fecha_hasta" class="form-control form-control-sm" value="<?php echo $filtro_fecha_hasta; ?>">
</div>
</div>
<div class="col-12 text-end mt-3">
<button type="submit" class="btn btn-primary btn-sm">
<i class="fas fa-search me-1"></i>Filtrar
</button>
<a href="sucursales.php" class="btn btn-limpiar-filtros btn-sm">
<i class="fas fa-times me-1"></i>Limpiar Filtros
</a>
<!-- NUEVO: Botón para generar PDF con filtros -->
<a href="sucursales.php?generar_pdf_lista=1&<?php echo http_build_query(array_filter($_GET, function($key) { return $key !== 'pagina' && $key !== 'orden' && $key !== 'direccion'; }, ARRAY_FILTER_USE_KEY)); ?>"
class="btn btn-outline-danger btn-sm ms-2" target="_blank" title="Generar PDF con filtros aplicados">
<i class="fas fa-file-pdf me-1"></i>PDF Filtrado
</a>
</div>
</form>
</div>
</div>
<!-- ✅ BOTÓN PARA GENERAR PDF CONSOLIDADO (EMPRESA/SUCURSAL) -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-file-pdf me-2"></i>Generar Reporte Consolidado
</div>
<form method="POST" action="sucursales.php" class="row g-3 align-items-end">
<div class="col-md-5">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<select name="filtro_empresa" class="form-select" required>
<option value="">Seleccione una empresa...</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>">
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-5">
<label class="form-label">Sucursal (Opcional)</label>
<select name="filtro_sucursal" class="form-select">
<option value="">Todas las sucursales</option>
<!-- Se llenará dinámicamente con JS -->
</select>
</div>
<div class="col-md-2">
<button type="submit" name="action" value="generar_pdf_consolidado" class="btn btn-primary w-100">
<i class="fas fa-file-pdf me-1"></i>Generar PDF
</button>
</div>
</form>
<small class="text-muted mt-2 d-block"><i class="fas fa-info-circle me-1"></i>El PDF incluirá: Personal Habilitado, Servicios y Recursos (con Marca y Modelo) de la empresa seleccionada.</small>
</div>
<!-- ✅ NUEVA SUCURSAL - CON ACORDEONES -->
<div class="section-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="d-flex justify-content-between align-items-center section-title"
data-bs-toggle="collapse"
data-bs-target="#nuevaSucursalForm"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<span><i class="fas fa-plus-circle me-2"></i>Nueva Sucursal</span>
<div class="d-flex align-items-center gap-2">
<button type="button" class="btn btn-outline-primary btn-sm" onclick="abrirModalCrearDirector()" title="Crear Director Técnico">
<i class="fas fa-user-plus"></i> Director Técnico
</button>
<i class="fas fa-chevron-down" id="iconoNuevaSucursal"></i>
</div>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto) -->
<div class="collapse mt-3 <?php echo $sucursal_edit ? 'show' : ''; ?>" id="nuevaSucursalForm">
<h5 class="mb-3">
<i class="fas fa-store me-2"></i>
<?php echo $sucursal_edit ? 'Editar' : 'Registrar Nueva'; ?> Sucursal
<?php if (!$sucursal_edit): ?>
<span class="badge bg-warning text-dark ms-2">
<i class="fas fa-info-circle"></i> Se guardará como INACTIVA
</span>
<?php endif; ?>
</h5>
<form method="POST" action="" class="needs-validation" novalidate enctype="multipart/form-data">
<?php if ($sucursal_edit): ?>
<input type="hidden" name="sucursal_id" value="<?php echo $sucursal_edit['id']; ?>">
<?php endif; ?>
<!-- ACORDEÓN PRINCIPAL -->
<div class="accordion" id="accordionSucursal">
<!-- 📍 Datos Generales -->
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#datosGenerales" aria-expanded="true" aria-controls="datosGenerales">
📍 Datos Generales
</button>
</h2>
<div id="datosGenerales" class="accordion-collapse collapse show" data-bs-parent="#accordionSucursal">
<div class="accordion-body">
<div class="row g-4">
<div class="col-md-6">
<label class="form-label required">Empresa</label>
<select name="empresa_id" class="form-select" required>
<option value="">Seleccione una empresa...</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($sucursal_edit && $sucursal_edit['empresa_id'] == $empresa['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label required">Nombre de la Sucursal</label>
<input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($sucursal_edit['nombre'] ?? ''); ?>" required>
</div>
<div class="col-md-8">
<label class="form-label required">Domicilio</label>
<input type="text" name="domicilio" class="form-control" value="<?php echo htmlspecialchars($sucursal_edit['domicilio'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label required">Localidad (Chubut)</label>
<select name="localidad" class="form-select" required>
<option value="">Seleccione una localidad...</option>
<?php
$localidades_chubut = ['Rawson', 'Comodoro Rivadavia', 'Trelew', 'Puerto Madryn', 'Esquel', 'Sarmiento', 'Gaiman', 'Dolavon', 'Puerto Pirámides', 'Lago Puelo', 'El Hoyo', 'El Maitén', 'Cushamen', 'Trevelin', 'Rada Tilly', 'Camarones'];
foreach ($localidades_chubut as $localidad):
$selected = (isset($sucursal_edit['localidad']) && $localidad === $sucursal_edit['localidad']) ? 'selected' : '';
?>
<option value="<?php echo $localidad; ?>" <?php echo $selected; ?>><?php echo $localidad; ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Habilitación</label>
<input type="date" name="fecha_habilitacion" class="form-control" value="<?php echo $sucursal_edit['fecha_habilitacion'] ?? ''; ?>">
</div>
<div class="col-md-6">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($sucursal_edit['telefono'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($sucursal_edit['email'] ?? ''); ?>">
</div>
</div>
</div>
</div>
</div>
<!-- ⚖️ Responsable y Legal -->
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#responsableLegal" aria-expanded="false" aria-controls="responsableLegal">
⚖️ Responsable y Legal
</button>
</h2>
<div id="responsableLegal" class="accordion-collapse collapse" data-bs-parent="#accordionSucursal">
<div class="accordion-body">
<div class="row g-4">
<div class="col-md-6">
<label class="form-label">Responsable</label>
<div class="input-group">
<select name="responsable_id" class="form-select">
<option value="">Seleccione un responsable...</option>
<?php foreach ($personal_disponible as $persona): ?>
<option value="<?php echo $persona['id']; ?>"
<?php echo (isset($sucursal_edit['responsable_id']) && $sucursal_edit['responsable_id'] == $persona['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre'] . ' (DNI: ' . $persona['dni'] . ')'); ?>
<?php if (!empty($persona['cargo'])): ?>
<span class="text-muted"> - <?php echo htmlspecialchars($persona['cargo']); ?></span>
<?php endif; ?>
<?php if (isset($persona['total_sucursales_responsable']) && $persona['total_sucursales_responsable'] > 0): ?>
<span class="badge bg-info ms-1"><?php echo $persona['total_sucursales_responsable']; ?> sucursal(es)</span>
<?php endif; ?>
</option>
<?php endforeach; ?>
</select>
</div>
<small class="text-muted">Solo se muestran DIRECTOR TECNICO y cargos similares. El número entre paréntesis indica en cuántas sucursales es responsable.</small>
</div>
<div class="col-md-6">
<label class="form-label required">Jurisdicción</label>
<select name="jurisdiccion" class="form-select" required>
<option value="">Seleccione una jurisdicción...</option>
<?php
$jurisdicciones = ['Esquel', 'Comodoro Rivadavia', 'Puerto Madryn', 'Trelew', 'Rawson'];
foreach ($jurisdicciones as $jurisdiccion_opcion):
$selected = (isset($sucursal_edit['jurisdiccion']) && $jurisdiccion_opcion === $sucursal_edit['jurisdiccion']) ? 'selected' : '';
?>
<option value="<?php echo $jurisdiccion_opcion; ?>" <?php echo $selected; ?>><?php echo $jurisdiccion_opcion; ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Número de Resolución</label>
<input type="text" name="numero_resolucion" class="form-control" value="<?php echo htmlspecialchars($sucursal_edit['numero_resolucion'] ?? ''); ?>">
</div>
<!-- ✅ NUEVO CAMPO: FECHA DE RESOLUCION -->
<div class="col-md-6">
<label class="form-label">
<i class="fas fa-calendar-alt me-1"></i> Fecha de Resolución
</label>
<input type="date" name="fecha_resolucion" class="form-control"
value="<?php echo htmlspecialchars($sucursal_edit['fecha_resolucion'] ?? ''); ?>">
</div>
</div>
</div>
</div>
</div>
<!-- ✅ Estado y Certificaciones -->
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#estadoCertificaciones" aria-expanded="false" aria-controls="estadoCertificaciones">
✅ Estado y Certificaciones
</button>
</h2>
<div id="estadoCertificaciones" class="accordion-collapse collapse" data-bs-parent="#accordionSucursal">
<div class="accordion-body">
<div class="row g-3">
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="esta_activa" id="esta_activa"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['activa'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="esta_activa">
<i class="fas fa-toggle-on"></i> ¿Está Activa?
</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="en_funcionamiento" id="en_funcionamiento"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['en_funcionamiento'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="en_funcionamiento">
<i class="fas fa-cog"></i> ¿En Funcionamiento?
</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="renar" id="renar"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['renar'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="renar">
<i class="fas fa-file-alt"></i> RENAR
</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="certificado_cumplimiento" id="certificado_cumplimiento"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['certificado_cumplimiento'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="certificado_cumplimiento">
<i class="fas fa-certificate"></i> AFIP/ANSES/Rentas
</label>
</div>
</div>
<div class="col-md-4">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="habilitacion_comercial" id="habilitacion_comercial"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['habilitacion_comercial'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="habilitacion_comercial">
<i class="fas fa-building"></i> Habilitación Comercial
</label>
</div>
</div>
<div class="col-md-4">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="pago_arancel" id="pago_arancel"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['pago_arancel'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="pago_arancel">
<i class="fas fa-money-bill-wave"></i> Pago de Arancel
</label>
</div>
</div>
<div class="col-md-4">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_protocolos" id="tiene_protocolos"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['tiene_protocolos'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_protocolos">
<i class="fas fa-book"></i> Protocolos
</label>
</div>
</div>
<div class="col-md-4">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_art" id="tiene_art"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['tiene_art'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_art">
<i class="fas fa-shield-alt"></i> ART
</label>
</div>
</div>
<div class="col-md-4">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_seguro_rc" id="tiene_seguro_rc"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['tiene_seguro_rc'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_seguro_rc">
<i class="fas fa-file-contract"></i> Seguro Resp. Civil
</label>
</div>
</div>
<!-- ✅ NUEVO: ÚLTIMOS INFORMES ENVIADOS A SUCURSALES DE LA EMPRESA -->
<div class="col-md-4">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="ultimos_informes_enviados" id="ultimos_informes_enviados"
<?php echo (!empty($sucursal_edit) && !empty($sucursal_edit['ultimos_informes_enviados'])) ? 'checked' : ''; ?>>
<label class="form-check-label" for="ultimos_informes_enviados">
<i class="fas fa-paper-plane"></i> Últimos Informes Enviados
</label>
</div>
<small class="text-muted">Marcar si se enviaron los últimos informes a todas las sucursales de la empresa</small>
</div>
<!-- ✅ FIN NUEVO CAMPO -->
<div class="col-md-6">
<label class="form-label">Fecha de Pago de Arancel</label>
<input type="date" name="fecha_pago_arancel" class="form-control fecha-pago-arancel"
value="<?php echo $sucursal_edit['fecha_pago_arancel'] ?? ''; ?>"
id="fecha_pago_arancel_form">
</div>
</div>
</div>
</div>
</div>
<!-- ✅ ANTECEDENTES DE LA EMPRESA - NUEVA SECCIÓN -->
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#antecedentesEmpresa" aria-expanded="false" aria-controls="antecedentesEmpresa">
📋 Antecedentes de la Empresa (Sumarios y Sanciones)
</button>
</h2>
<div id="antecedentesEmpresa" class="accordion-collapse collapse" data-bs-parent="#accordionSucursal">
<div class="accordion-body">
<div class="alert alert-info mb-3">
<i class="fas fa-info-circle me-2"></i>
<strong>Registro de Antecedentes Administrativos:</strong> Complete esta sección con información sobre sumarios, sanciones e infracciones de la empresa conforme a la Ley 19.549 de Procedimientos Administrativos.
</div>
<div class="row g-3">
<!-- Sumarios Administrativos -->
<div class="col-12">
<label class="form-label">
<i class="fas fa-folder-open me-1"></i> Sumarios Administrativos
</label>
<textarea name="sumarios_administrativos" class="form-control" rows="3" placeholder="Describa los sumarios administrativos iniciados contra la empresa, números de expediente, estado, etc."><?php echo htmlspecialchars($sucursal_edit['sumarios_administrativos'] ?? ''); ?></textarea>
<small class="text-muted">Detalle de sumarios según Ley 19.549: número de expediente, fecha de inicio, órgano instructor, estado actual.</small>
</div>
<!-- Sanciones Aplicadas -->
<div class="col-12">
<label class="form-label">
<i class="fas fa-gavel me-1"></i> Sanciones Aplicadas
</label>
<textarea name="sanciones_aplicadas" class="form-control" rows="3" placeholder="Describa las sanciones administrativas aplicadas: multas, suspensiones, clausuras, etc."><?php echo htmlspecialchars($sucursal_edit['sanciones_aplicadas'] ?? ''); ?></textarea>
<small class="text-muted">Incluya tipo de sanción, monto de multa (si aplica), fecha de aplicación y fundamento legal.</small>
</div>
<!-- Estado Judicial -->
<div class="col-md-6">
<label class="form-label">
<i class="fas fa-balance-scale me-1"></i> Estado Judicial
</label>
<select name="estado_judicial" class="form-select">
<option value="">Seleccione...</option>
<option value="sin_novedad" <?php echo (isset($sucursal_edit['estado_judicial']) && $sucursal_edit['estado_judicial'] === 'sin_novedad') ? 'selected' : ''; ?>>Sin Novedad</option>
<option value="procesos_en_curso" <?php echo (isset($sucursal_edit['estado_judicial']) && $sucursal_edit['estado_judicial'] === 'procesos_en_curso') ? 'selected' : ''; ?>>Procesos en Curso</option>
<option value="condena_firme" <?php echo (isset($sucursal_edit['estado_judicial']) && $sucursal_edit['estado_judicial'] === 'condena_firme') ? 'selected' : ''; ?>>Condena Firme</option>
<option value="sobreseimiento" <?php echo (isset($sucursal_edit['estado_judicial']) && $sucursal_edit['estado_judicial'] === 'sobreseimiento') ? 'selected' : ''; ?>>Sobreseimiento</option>
<option value="otro" <?php echo (isset($sucursal_edit['estado_judicial']) && $sucursal_edit['estado_judicial'] === 'otro') ? 'selected' : ''; ?>>Otro</option>
</select>
</div>
<!-- Contador de Infracciones -->
<div class="col-md-4">
<label class="form-label">
<i class="fas fa-exclamation-triangle me-1"></i> Infracciones Leves
</label>
<input type="number" name="infracciones_leves" class="form-control" min="0" value="<?php echo isset($sucursal_edit['infracciones_leves']) ? (int)$sucursal_edit['infracciones_leves'] : 0; ?>">
</div>
<div class="col-md-4">
<label class="form-label">
<i class="fas fa-exclamation-circle me-1"></i> Infracciones Graves
</label>
<input type="number" name="infracciones_graves" class="form-control" min="0" value="<?php echo isset($sucursal_edit['infracciones_graves']) ? (int)$sucursal_edit['infracciones_graves'] : 0; ?>">
</div>
<div class="col-md-4">
<label class="form-label">
<i class="fas fa-times-circle me-1"></i> Infracciones Muy Graves
</label>
<input type="number" name="infracciones_muy_graves" class="form-control" min="0" value="<?php echo isset($sucursal_edit['infracciones_muy_graves']) ? (int)$sucursal_edit['infracciones_muy_graves'] : 0; ?>">
</div>
<!-- Fecha de Última Infracción -->
<div class="col-md-6">
<label class="form-label">
<i class="fas fa-calendar-alt me-1"></i> Fecha de Última Infracción
</label>
<input type="date" name="fecha_ultima_infraccion" class="form-control" value="<?php echo htmlspecialchars($sucursal_edit['fecha_ultima_infraccion'] ?? ''); ?>">
</div>
<!-- Observaciones Generales -->
<div class="col-12">
<label class="form-label">
<i class="fas fa-sticky-note me-1"></i> Observaciones Adicionales
</label>
<textarea name="observaciones_antecedentes" class="form-control" rows="2" placeholder="Observaciones complementarias sobre el historial administrativo de la empresa"><?php echo htmlspecialchars($sucursal_edit['observaciones_antecedentes'] ?? ''); ?></textarea>
</div>
</div>
</div>
</div>
</div>
<!-- ✅ FIN ANTECEDENTES DE LA EMPRESA -->
<!-- 📎 Documentos y Archivos -->
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#documentosArchivos" aria-expanded="false" aria-controls="documentosArchivos">
📎 Documentos y Archivos
</button>
</h2>
<div id="documentosArchivos" class="accordion-collapse collapse" data-bs-parent="#accordionSucursal">
<div class="accordion-body">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Cupón de Pago (PDF/JPG/PNG)</label>
<input type="file" name="cupon_pago" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
<?php if (isset($sucursal_edit['cupon_pago']) && !empty($sucursal_edit['cupon_pago'])): ?>
<div class="mt-2">
<i class="fas fa-file-pdf text-danger"></i>
<a href="../uploads/cupones/<?php echo htmlspecialchars($sucursal_edit['cupon_pago']); ?>" target="_blank" class="ms-2">
<i class="fas fa-eye"></i> Ver Archivo Actual
</a>
</div>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">Resolución PDF</label>
<input type="file" name="pdf_resolucion" class="form-control" accept=".pdf">
<?php if (isset($sucursal_edit['pdf_resolucion']) && !empty($sucursal_edit['pdf_resolucion'])): ?>
<div class="mt-2">
<i class="fas fa-file-pdf text-danger"></i>
<a href="../uploads/resoluciones/<?php echo htmlspecialchars($sucursal_edit['pdf_resolucion']); ?>" target="_blank" class="ms-2">
<i class="fas fa-eye"></i> Ver Archivo Actual
</a>
</div>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">PDF de la Sucursal</label>
<input type="file" name="pdf_sucursal" class="form-control" accept=".pdf">
<?php if (isset($sucursal_edit['pdf_sucursal']) && !empty($sucursal_edit['pdf_sucursal'])): ?>
<div class="mt-2">
<i class="fas fa-file-pdf text-danger"></i>
<a href="../uploads/pdf_sucursal/<?php echo htmlspecialchars($sucursal_edit['pdf_sucursal']); ?>" target="_blank" class="ms-2">
<i class="fas fa-eye"></i> Ver Archivo Actual
</a>
</div>
<?php endif; ?>
</div>
<!-- ✅ NUEVOS CAMPOS: FOTOS DE UNIFORME Y VEHICULOS -->
<div class="col-md-6">
<label class="form-label">
<i class="fas fa-user-shield me-1"></i> Fotos del Uniforme (JPG/PNG/WebP)
</label>
<input type="file" name="fotos_uniforme" class="form-control" accept=".jpg,.jpeg,.png,.webp">
<small class="text-muted">Máximo 10MB</small>
<?php if (isset($sucursal_edit['fotos_uniforme']) && !empty($sucursal_edit['fotos_uniforme'])): ?>
<div class="mt-2">
<i class="fas fa-image text-primary"></i>
<a href="../uploads/fotos_sucursal/<?php echo htmlspecialchars($sucursal_edit['fotos_uniforme']); ?>" target="_blank" class="ms-2">
<i class="fas fa-eye"></i> Ver Foto Actual
</a>
</div>
<?php endif; ?>
</div>
<div class="col-md-6">
<label class="form-label">
<i class="fas fa-car me-1"></i> Fotos de los Vehículos (JPG/PNG/WebP)
</label>
<input type="file" name="fotos_vehiculos" class="form-control" accept=".jpg,.jpeg,.png,.webp">
<small class="text-muted">Máximo 10MB</small>
<?php if (isset($sucursal_edit['fotos_vehiculos']) && !empty($sucursal_edit['fotos_vehiculos'])): ?>
<div class="mt-2">
<i class="fas fa-image text-primary"></i>
<a href="../uploads/fotos_sucursal/<?php echo htmlspecialchars($sucursal_edit['fotos_vehiculos']); ?>" target="_blank" class="ms-2">
<i class="fas fa-eye"></i> Ver Foto Actual
</a>
</div>
<?php endif; ?>
</div>
<!-- ✅ FIN NUEVOS CAMPOS -->
</div>
</div>
</div>
</div>
<!-- 💳 Gestión de Pagos (solo para edición) -->
<?php if ($sucursal_edit): ?>
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gestionPagos" aria-expanded="false" aria-controls="gestionPagos">
💳 Gestión de Pagos
</button>
</h2>
<div id="gestionPagos" class="accordion-collapse collapse" data-bs-parent="#accordionSucursal">
<div class="accordion-body">
<div class="alert alert-info">
<i class="fas fa-info-circle"></i>
<strong>Gestión de Pagos:</strong> Seleccione el personal que ha pagado su credencial y marque como pagadas las seleccionadas.
</div>
<!-- Botón de Pago Masivo con fecha -->
<div class="row mb-3 align-items-end">
<div class="col-md-8">
<button type="button" class="btn btn-success w-100" onclick="pagarCredencialesSeleccionados(<?php echo $sucursal_edit['id']; ?>)">
<i class="fas fa-check-double me-2"></i>Marcar SELECCIONADOS como PAGADOS
</button>
</div>
<div class="col-md-4">
<label class="form-label small">Fecha de Pago</label>
<input type="date" id="fecha_pago_masivo" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>">
</div>
</div>
<!-- Tabla de Personal -->
<div class="table-responsive">
<table class="table table-sm table-bordered" id="tablaPersonalSucursal">
<thead class="table-light">
<tr>
<th width="5%"><input type="checkbox" id="selectAllPersonal" onclick="toggleSelectAll(this)"></th>
<th width="5%">ID</th>
<th width="30%">Apellido y Nombre</th>
<th width="15%">DNI</th>
<th width="25%">Estado Pago</th>
<th width="20%">Fecha Pago</th>
</tr>
</thead>
<tbody id="tbodyPersonal">
<tr>
<td colspan="6" class="text-center py-4">
<i class="fas fa-spinner fa-spin me-2"></i>Cargando personal...
</td>
</tr>
</tbody>
</table>
</div>
<div class="mt-3 d-flex align-items-center gap-2">
<button type="button" class="btn btn-outline-primary btn-sm" onclick="cargarPersonalSucursal(<?php echo $sucursal_edit['id']; ?>)">
<i class="fas fa-sync-alt me-1"></i>Actualizar Lista
</button>
</div>
</div>
</div>
</div>
<?php endif; ?>
<!-- ✅ FIN ACORDEÓN PRINCIPAL -->
</div>
<!-- Botones de acción del formulario -->
<div class="col-12 text-end mt-4">
<button type="submit" name="guardar_sucursal" class="btn btn-success btn-lg px-5">
<i class="fas fa-save me-2"></i>
<?php echo $sucursal_edit ? 'Actualizar Sucursal' : 'Guardar Sucursal (Inactiva)'; ?>
</button>
<?php if ($sucursal_edit): ?>
<a href="sucursales.php" class="btn btn-secondary btn-lg px-5 ms-2">
<i class="fas fa-times me-2"></i> Cancelar
</a>
<?php endif; ?>
</div>
</form>
</div>  <!-- ✅ CIERRA nuevaSucursalForm -->
</div>  <!-- ✅ CIERRA section-box -->
<!-- LISTADO DE SUCURSALES -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Sucursales
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<?php if (empty($sucursales)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-store fa-3x text-muted mb-3"></i>
<h5>No hay sucursales registradas</h5>
<p class="text-muted">No se encontraron sucursales con los filtros aplicados.</p>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaSucursalForm">
<i class="fas fa-plus me-2"></i>Crear Nueva Sucursal
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th><a href="<?php echo generarUrlOrden('id', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('nombre', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Sucursal <?php echo mostrarIconoOrden('nombre', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('empresa_nombre', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Empresa <?php echo mostrarIconoOrden('empresa_nombre', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('localidad', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Localidad <?php echo mostrarIconoOrden('localidad', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('jurisdiccion', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Jurisdicción <?php echo mostrarIconoOrden('jurisdiccion', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('responsable_nombre', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Responsable <?php echo mostrarIconoOrden('responsable_nombre', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('estado_aprobacion', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Estado Aprobación <?php echo mostrarIconoOrden('estado_aprobacion', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('activa', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Estado <?php echo mostrarIconoOrden('activa', $orden_columna, $orden_direccion); ?></a></th>
<th><a href="<?php echo generarUrlOrden('en_funcionamiento', $orden_columna, $orden_direccion); ?>" class="text-decoration-none text-dark">Funcionamiento <?php echo mostrarIconoOrden('en_funcionamiento', $orden_columna, $orden_direccion); ?></a></th>
<th class="table-actions">Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($sucursales as $sucursal):
$es_activa = isset($sucursal['activa']) && ($sucursal['activa'] == 1 || $sucursal['activa'] === true);
$estado_aprobacion = $sucursal['estado_aprobacion'] ?? 'pendiente';
?>
<tr>
<td><strong>#<?php echo $sucursal['id']; ?></strong></td>
<td><strong><?php echo htmlspecialchars($sucursal['nombre']); ?></strong></td>
<td><?php echo !empty($sucursal['empresa_nombre']) ? htmlspecialchars($sucursal['empresa_nombre']) : '<span class="text-muted">-</span>'; ?></td>
<td><?php echo !empty($sucursal['localidad']) ? htmlspecialchars($sucursal['localidad']) : '<span class="text-muted">-</span>'; ?></td>
<td>
<?php if (!empty($sucursal['jurisdiccion'])): ?>
<span class="badge bg-info"><?php echo htmlspecialchars($sucursal['jurisdiccion']); ?></span>
<?php else: ?>
<span class="text-muted">-</span>
<?php endif; ?>
</td>
<td>
<?php if (!empty($sucursal['responsable_nombre'])): ?>
<i class="fas fa-user me-1"></i><?php echo htmlspecialchars($sucursal['responsable_apellido'] . ', ' . $sucursal['responsable_nombre']); ?>
<?php else: ?>
<span class="text-muted">-</span>
<?php endif; ?>
</td>
<td>
<?php if ($estado_aprobacion === 'pendiente' || empty($estado_aprobacion)): ?>
<span class="badge aprobacion-pendiente">
<i class="fas fa-clock"></i> Pendiente
</span>
<?php elseif ($estado_aprobacion === 'aprobado'): ?>
<span class="badge aprobacion-aprobado">
<i class="fas fa-check"></i> Aprobado
</span>
<?php elseif ($estado_aprobacion === 'rechazado'): ?>
<span class="badge aprobacion-rechazado">
<i class="fas fa-times"></i> Rechazado
</span>
<?php endif; ?>
<?php if (!empty($sucursal['fecha_aprobacion'])): ?>
<br><small class="text-muted">
<i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($sucursal['fecha_aprobacion'])); ?>
</small>
<?php endif; ?>
<?php if (!empty($sucursal['aprobador_username'])): ?>
<br><small class="text-muted">
<i class="fas fa-user"></i> <?php echo htmlspecialchars($sucursal['aprobador_username']); ?>
</small>
<?php endif; ?>
</td>
<td>
<?php if ($es_activa): ?>
<span class="badge bg-success">
<i class="fas fa-check-circle me-1"></i>Activa
</span>
<?php else: ?>
<span class="badge bg-secondary">
<i class="fas fa-times-circle me-1"></i>Inactiva
</span>
<?php endif; ?>
</td>
<td>
<?php if (!empty($sucursal['en_funcionamiento'])): ?>
<span class="badge badge-funcionamiento-true">
<i class="fas fa-check-circle me-1"></i>Funciona
</span>
<?php else: ?>
<span class="badge badge-funcionamiento-false">
<i class="fas fa-times-circle me-1"></i>No Funciona
</span>
<?php endif; ?>
</td>
<td class="table-actions">
<?php if ($estado_aprobacion === 'pendiente' || empty($estado_aprobacion)): ?>
<button class="btn btn-sm btn-outline-success me-1"
data-bs-toggle="modal"
data-bs-target="#modalAprobar<?php echo $sucursal['id']; ?>"
title="Aprobar">
<i class="fas fa-check"></i>
</button>
<button class="btn btn-sm btn-outline-danger me-1"
data-bs-toggle="modal"
data-bs-target="#modalRechazar<?php echo $sucursal['id']; ?>"
title="Rechazar">
<i class="fas fa-times"></i>
</button>
<?php endif; ?>
<a href="sucursales.php?generar_pdf=1&id=<?php echo $sucursal['id']; ?>"
class="btn btn-sm btn-outline-info me-1"
target="_blank"
title="Generar PDF">
<i class="fas fa-file-pdf"></i>
</a>
<a href="sucursales.php?edit=<?php echo $sucursal['id']; ?><?php echo mantenerFiltros(); ?>"
class="btn btn-sm btn-outline-primary"
title="Editar">
<i class="fas fa-edit"></i>
</a>
</td>
</tr>
<!-- Modal Aprobar -->
<div class="modal fade" id="modalAprobar<?php echo $sucursal['id']; ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-success text-white">
<h5 class="modal-title"><i class="fas fa-check-circle"></i> Aprobar Sucursal</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="sucursal_id" value="<?php echo $sucursal['id']; ?>">
<input type="hidden" name="accion_aprobacion" value="aprobar">
<p><strong>Sucursal:</strong> <?php echo htmlspecialchars($sucursal['nombre']); ?></p>
<p><strong>Empresa:</strong> <?php echo htmlspecialchars($sucursal['empresa_nombre'] ?? 'N/A'); ?></p>
<div class="mb-3">
<label class="form-label">Observaciones (Opcional)</label>
<textarea name="observaciones_aprobacion" class="form-control" rows="3"></textarea>
</div>
<div class="alert alert-success">
<i class="fas fa-info-circle"></i>
La sucursal será activada y visible en el sistema.
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Aprobar</button>
</div>
</form>
</div>
</div>
</div>
<!-- Modal Rechazar -->
<div class="modal fade" id="modalRechazar<?php echo $sucursal['id']; ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header bg-danger text-white">
<h5 class="modal-title"><i class="fas fa-times-circle"></i> Rechazar Sucursal</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="sucursal_id" value="<?php echo $sucursal['id']; ?>">
<input type="hidden" name="accion_aprobacion" value="rechazar">
<p><strong>Sucursal:</strong> <?php echo htmlspecialchars($sucursal['nombre']); ?></p>
<p><strong>Empresa:</strong> <?php echo htmlspecialchars($sucursal['empresa_nombre'] ?? 'N/A'); ?></p>
<div class="mb-3">
<label class="form-label required">Motivo del Rechazo *</label>
<textarea name="observaciones_aprobacion" class="form-control" rows="3" required></textarea>
</div>
<div class="alert alert-danger">
<i class="fas fa-exclamation-triangle"></i>
La sucursal será desactivada.
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Rechazar</button>
</div>
</form>
</div>
</div>
</div>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_paginas > 1): ?>
<div class="d-flex justify-content-center mt-3">
<nav aria-label="Paginación de sucursales">
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
<?php endif; ?>
</div>
</div>
</div>
<!-- ✅ MODAL PARA CREAR DIRECTOR TECNICO RÁPIDO (SIN EMPRESA Y SUCURSAL) -->
<div class="modal fade" id="modalCrearDirector" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Crear Director Técnico</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form id="formCrearDirector" method="POST" action="personal.php">
<div class="modal-body">
<input type="hidden" name="guardar_personal" value="1">
<input type="hidden" name="cargo" value="DIRECTOR TECNICO">
<input type="hidden" name="activo" value="1">
<!-- ✅ AÑADIR ESTA LÍNEA: Envía la fecha de hoy automáticamente -->
<input type="hidden" name="fecha_ingreso" value="<?php echo date('Y-m-d'); ?>">
<div class="mb-3">
<label class="form-label">Apellido *</label>
<input type="text" name="apellido" class="form-control" required>
</div>
<div class="mb-3">
<label class="form-label">Nombre *</label>
<input type="text" name="nombre" class="form-control" required>
</div>
<div class="mb-3">
<label class="form-label">CUIL *</label>
<input type="text" name="dni" class="form-control" placeholder="XX-XXXXXXXX-X" pattern="\d{2}-\d{8}-\d{1}" required>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Guardar Director</button>
</div>
</form>
</div>
</div>
</div>
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
// ✅ CARGAR PERSONAL DE LA SUCURSAL
<?php if ($sucursal_edit): ?>
const collapsePagos = document.getElementById('gestionPagos');
if (collapsePagos) {
collapsePagos.addEventListener('shown.bs.collapse', function() {
const sucursalId = <?php echo $sucursal_edit['id']; ?>;
if (sucursalId > 0) {
cargarPersonalSucursal(sucursalId);
}
});
}
// Si estamos editando y el collapse está abierto por defecto
const sucursalIdEdit = <?php echo $sucursal_edit['id']; ?>;
if (document.getElementById('gestionPagos') && document.getElementById('gestionPagos').classList.contains('show')) {
cargarPersonalSucursal(sucursalIdEdit);
}
<?php endif; ?>
// ✅ CARGAR SUCURSALES AL SELECCIONAR EMPRESA EN FORMULARIO CONSOLIDADO - CORREGIDO
const empresaSelectConsolidado = document.querySelector('form[action="sucursales.php"] select[name="filtro_empresa"]');
const sucursalSelectConsolidado = document.querySelector('form[action="sucursales.php"] select[name="filtro_sucursal"]');
if (empresaSelectConsolidado && sucursalSelectConsolidado) {
empresaSelectConsolidado.addEventListener('change', function() {
const empresaId = this.value;
sucursalSelectConsolidado.innerHTML = '<option value="">Cargando...</option>';
if (!empresaId) {
sucursalSelectConsolidado.innerHTML = '<option value="">Todas las sucursales</option>';
return;
}
fetch(`sucursales.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
sucursalSelectConsolidado.innerHTML = '<option value="">Todas las sucursales</option>';
if (data.success && data.data) {
data.data.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
sucursalSelectConsolidado.appendChild(option);
});
} else if (!data.success) {
console.error('Error del servidor:', data.message);
sucursalSelectConsolidado.innerHTML = '<option value="">Error: ' + (data.message || 'Desconocido') + '</option>';
}
})
.catch(error => {
console.error('Error de red:', error);
sucursalSelectConsolidado.innerHTML = '<option value="">Error de conexión</option>';
});
});
}
});
// ✅ FUNCIONES PARA EL MODAL DE CREAR DIRECTOR
function abrirModalCrearDirector() {
const modal = new bootstrap.Modal(document.getElementById('modalCrearDirector'));
modal.show();
}
// ✅ CARGAR PERSONAL DE LA SUCURSAL - CORREGIDO
function cargarPersonalSucursal(sucursal_id) {
if (!sucursal_id || sucursal_id <= 0) {
Swal.fire('Error', 'ID de sucursal inválido', 'error');
return;
}
const tbody = document.getElementById('tbodyPersonal');
if (tbody) {
tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><i class="fas fa-spinner fa-spin me-2"></i>Cargando personal...</td></tr>';
}
fetch('sucursales.php?action=get_personal_sucursal&sucursal_id=' + sucursal_id)
.then(response => {
if (!response.ok) throw new Error('Error en la respuesta');
return response.json();
})
.then(data => {
if (data.success) {
const tbody = document.getElementById('tbodyPersonal');
if (!tbody) return;
if (data.data.length === 0) {
tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-users-slash me-2"></i>No hay personal asignado a esta sucursal</td></tr>';
return;
}
let html = '';
data.data.forEach(personal => {
// ✅ CORRECCIÓN: Asegurar que pago_credencial y fecha_pago_credencial se reflejen correctamente
const estadoPago = personal.pago_credencial == 1
? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Pagado</span>'
: '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pendiente</span>';
const fechaPagoDisplay = personal.fecha_pago_credencial
? '<small class="text-muted">' + personal.fecha_pago_credencial + '</small>'
: '<small class="text-muted">-</small>';
const checked = personal.pago_credencial == 1 ? 'checked' : '';
// ✅ NUEVO: Mostrar contador de sucursales si está disponible
const totalSucursales = personal.total_sucursales_responsable ?
`<span class="badge bg-info ms-1">${personal.total_sucursales_responsable} sucursal(es)</span>` : '';
html += `<tr>
<td><input type="checkbox" class="form-check-input personal-checkbox" value="${personal.id}" ${checked} ${personal.pago_credencial == 1 ? 'disabled' : ''}></td>
<td>${personal.id}</td>
<td>${personal.apellido}, ${personal.nombre} ${totalSucursales}</td>
<td>${personal.dni || '-'}</td>
<td>${estadoPago}</td>
<td>${fechaPagoDisplay}</td>
</tr>`;
});
tbody.innerHTML = html;
// Resetear checkbox "Seleccionar todos"
document.getElementById('selectAllPersonal').checked = false;
} else {
Swal.fire('Error', data.message || 'Error al cargar personal', 'error');
}
})
.catch(error => {
console.error('Error:', error);
Swal.fire('Error', 'Error de conexión al cargar personal: ' + error.message, 'error');
});
}
// ✅ TOGGLE SELECCIONAR TODOS
function toggleSelectAll(checkbox) {
const checkboxes = document.querySelectorAll('.personal-checkbox:not(:disabled)');
checkboxes.forEach(cb => cb.checked = checkbox.checked);
}
// ✅ PAGAR CREDENCIALES SELECCIONADOS - CORREGIDO CON FECHA
function pagarCredencialesSeleccionados(sucursal_id) {
if (!sucursal_id || sucursal_id <= 0) {
Swal.fire('Error', 'ID de sucursal inválido', 'error');
return;
}
const checkboxes = document.querySelectorAll('.personal-checkbox:checked');
if (checkboxes.length === 0) {
Swal.fire('Atención', 'No ha seleccionado ningún personal para marcar como pagado', 'warning');
return;
}
const fechaPagoInput = document.getElementById('fecha_pago_masivo');
const fechaPago = fechaPagoInput ? fechaPagoInput.value : new Date().toISOString().split('T')[0];
const personalIds = Array.from(checkboxes).map(cb => cb.value);
Swal.fire({
title: '⚠️ ¿Confirmar Pago de Seleccionados?',
html: `
<div class="text-start">
<p>Esta acción marcará como <strong>PAGADAS</strong> las credenciales de <strong>${personalIds.length} empleado(s)</strong> seleccionado(s).</p>
<p class="text-danger"><i class="fas fa-exclamation-triangle"></i> Esta acción no se puede deshacer fácilmente.</p>
<p><strong>Fecha de pago registrada:</strong> ${fechaPago}</p>
<p><strong>Fecha de vencimiento calculada: 10/01 del año siguiente</strong></p>
<p><strong>¿Está seguro que el personal seleccionado ha realizado el pago?</strong></p>
</div>
`,
icon: 'warning',
showCancelButton: true,
confirmButtonColor: '#28a745',
cancelButtonColor: '#6c757d',
confirmButtonText: '<i class="fas fa-check me-1"></i>Sí, pagar seleccionados',
cancelButtonText: 'Cancelar',
reverseButtons: true
}).then((result) => {
if (result.isConfirmed) {
const formData = new FormData();
formData.append('action', 'pagar_credenciales_seleccionados');
formData.append('sucursal_id', sucursal_id);
formData.append('fecha_pago', fechaPago);
personalIds.forEach(id => formData.append('personal_ids[]', id));
fetch('sucursales.php', {
method: 'POST',
body: formData,
headers: {
'Accept': 'application/json'
}
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({
title: '¡Éxito!',
text: data.message,
icon: 'success',
timer: 2000,
showConfirmButton: false
});
// ✅ CORRECCIÓN: Recargar personal para reflejar cambios en personal.php
cargarPersonalSucursal(sucursal_id);
} else {
Swal.fire('Error', data.message, 'error');
}
})
.catch(error => {
console.error('Error:', error);
Swal.fire('Error', 'Error de conexión al procesar pago: ' + error.message, 'error');
});
}
});
}
// ✅ MANEJO DEL FORMULARIO DE CREAR DIRECTOR - REDIRECCIÓN A SUCURSALES.PHP
document.getElementById('formCrearDirector')?.addEventListener('submit', function(e) {
e.preventDefault();
const form = this;
const formData = new FormData(form);
Swal.fire({
title: 'Guardando...',
text: 'Creando nuevo Director Técnico',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading() }
});
fetch('personal.php', {
method: 'POST',
body: formData
})
.then(response => {
// ✅ REDIRECCIÓN MODIFICADA: Regresa a sucursales.php manteniendo contexto de edición si existe
const urlParams = new URLSearchParams(window.location.search);
const editId = urlParams.get('edit');
const filtros = new URLSearchParams();
// Mantener filtros actuales
<?php foreach($_GET as $key => $value): if(!in_array($key, ['pagina','orden','direccion','edit'])): ?>
filtros.append('<?php echo $key; ?>', '<?php echo htmlspecialchars($value); ?>');
<?php endif; endforeach; ?>
let redirectUrl = 'sucursales.php';
if (filtros.toString()) {
redirectUrl += '?' + filtros.toString();
}
if (editId) {
redirectUrl += (filtros.toString() ? '&' : '?') + 'edit=' + editId + '#gestionPagos';
}
window.location.href = redirectUrl;
})
.catch(error => {
Swal.fire('Error', 'No se pudo guardar: ' + error.message, 'error');
});
});
</script>
</body>
</html>