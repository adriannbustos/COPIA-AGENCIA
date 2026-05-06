<?php
/**
* ============================================================================
* GESTIÓN DE REPORTES - SISTEMA DE SEGURIDAD
* ============================================================================
* Incluye: Generación de PDFs, Múltiples tipos de reportes,
*          Filtros avanzados, Exportación, Auditoría
*
* @author Sistema de Seguridad
* @version 1.1
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
if (!$auth->hasRole('administrador') && !$auth->hasRole('carga')) {
$_SESSION['error'] = 'Acceso denegado. Se requieren permisos de administrador.';
header('Location: ../index.php');
exit;
}
$current_page = 'reportes';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. GENERAR PDF (AJAX/POST)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_reporte'])) {
try {
$tipo_reporte = $_POST['tipo_reporte'] ?? '';
$filtro_empresa = isset($_POST['filtro_empresa']) && !empty($_POST['filtro_empresa']) ? (int)$_POST['filtro_empresa'] : null;
$filtro_sucursal = isset($_POST['filtro_sucursal']) && !empty($_POST['filtro_sucursal']) ? (int)$_POST['filtro_sucursal'] : null;
$filtro_estado = isset($_POST['filtro_estado']) && !empty($_POST['filtro_estado']) ? $_POST['filtro_estado'] : null;
$fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
$fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
if (empty($tipo_reporte)) {
throw new Exception('Tipo de reporte no especificado');
}
// Verificar FPDF
if (!file_exists('../vendor/fpdf/fpdf.php')) {
throw new Exception('FPDF no está instalado en el servidor');
}
require_once '../vendor/fpdf/fpdf.php';
// Clase PDF personalizada
class PDF_Reporte extends FPDF {
function Header() {
$this->SetFont('Arial', 'B', 16);
$this->SetTextColor(67, 97, 238);
$this->Cell(0, 10, 'SISTEMA DE GESTION DE SEGURIDAD', 0, 1, 'C');
$this->SetFont('Arial', 'B', 12);
$this->SetTextColor(0, 0, 0);
$this->Cell(0, 6, 'Policia de Chubut - Area Investigaciones', 0, 1, 'C');
$this->Ln(5);
$this->SetDrawColor(67, 97, 238);
$this->Line(10, 30, 200, 30);
$this->Ln(5);
}
function Footer() {
$this->SetY(-25);
$this->SetDrawColor(150, 150, 150);
$this->Rect(10, 270, 190, 20);
$this->SetFont('Arial', 'I', 8);
$this->SetTextColor(100, 100, 100);
$this->Cell(0, 5, 'DOCUMENTO GENERADO AUTOMATICAMENTE', 0, 1, 'C');
$this->Cell(0, 5, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$this->Cell(0, 5, 'Usuario: ' . $_SESSION['reporte_usuario'] ?? 'Sistema', 0, 1, 'C');
}
}
$pdf = new PDF_Reporte();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);
// Títulos de reportes
$titulos_reportes = [
'empresas' => 'REPORTE DE EMPRESAS',
'sucursales' => 'REPORTE DE SUCURSALES',
'personal' => 'REPORTE DE PERSONAL',
'servicios' => 'REPORTE DE SERVICIOS',
'recursos' => 'REPORTE DE RECURSOS',
'estadisticas' => 'REPORTE ESTADISTICO GENERAL'
];
$titulo = $titulos_reportes[$tipo_reporte] ?? 'REPORTE';
$pdf->Cell(0, 10, $titulo, 0, 1, 'C');
$pdf->Ln(5);
// Filtros aplicados
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(0, 6, 'Filtros Aplicados:', 0, 1, 'L', true);
$pdf->SetFont('Arial', '', 9);
$filtros_texto = [];
if ($filtro_empresa) {
$stmt = $conn->prepare("SELECT nombre FROM empresas WHERE id = ?");
$stmt->execute([$filtro_empresa]);
$emp = $stmt->fetch();
if ($emp) $filtros_texto[] = "Empresa: " . $emp['nombre'];
}
if ($filtro_sucursal) {
$stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ?");
$stmt->execute([$filtro_sucursal]);
$suc = $stmt->fetch();
if ($suc) $filtros_texto[] = "Sucursal: " . $suc['nombre'];
}
if ($filtro_estado) {
$filtros_texto[] = "Estado: " . ucfirst($filtro_estado);
}
if ($fecha_inicio) {
$filtros_texto[] = "Desde: " . date('d/m/Y', strtotime($fecha_inicio));
}
if ($fecha_fin) {
$filtros_texto[] = "Hasta: " . date('d/m/Y', strtotime($fecha_fin));
}
if (empty($filtros_texto)) {
$filtros_texto[] = "Sin filtros - Todos los registros";
}
foreach ($filtros_texto as $filtro) {
$pdf->Cell(0, 5, "• " . $filtro, 0, 1, 'L');
}
$pdf->Ln(5);
// Datos del reporte según tipo
$datos = [];
$total_registros = 0;
switch ($tipo_reporte) {
case 'empresas':
$sql = "SELECT * FROM empresas WHERE 1=1";
$params = [];
if ($filtro_estado) {
$sql .= " AND activo = ?";
$params[] = ($filtro_estado === 'activo') ? 1 : 0;
}
if ($fecha_inicio) {
$sql .= " AND fecha_creacion >= ?";
$params[] = $fecha_inicio . ' 00:00:00';
}
if ($fecha_fin) {
$sql .= " AND fecha_creacion <= ?";
$params[] = $fecha_fin . ' 23:59:59';
}
$sql .= " ORDER BY nombre";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(15, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(60, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'CUIT', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Provincia', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Estado', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($datos as $row) {
$pdf->Cell(15, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(60, 6, substr($row['nombre'], 0, 35), 1, 0, 'L', $fill);
$pdf->Cell(40, 6, $row['cuit'] ?? 'N/A', 1, 0, 'C', $fill);
$pdf->Cell(40, 6, $row['provincia'] ?? 'N/A', 1, 0, 'L', $fill);
$pdf->Cell(35, 6, $row['activo'] ? 'Activa' : 'Inactiva', 1, 1, 'C', $fill);
$fill = !$fill;
}
break;
case 'sucursales':
$sql = "SELECT s.*, e.nombre as empresa_nombre FROM sucursales s
LEFT JOIN empresas e ON s.empresa_id = e.id WHERE 1=1";
$params = [];
if ($filtro_empresa) {
$sql .= " AND s.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_estado) {
$sql .= " AND s.activa = ?";
$params[] = ($filtro_estado === 'activo') ? 1 : 0;
}
$sql .= " ORDER BY s.nombre";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Sucursal', 1, 0, 'L', true);
$pdf->Cell(50, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'Localidad', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'Estado', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($datos as $row) {
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(50, 6, substr($row['nombre'], 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(50, 6, substr($row['empresa_nombre'] ?? 'N/A', 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(40, 6, $row['localidad'] ?? 'N/A', 1, 0, 'L', $fill);
$pdf->Cell(40, 6, $row['activa'] ? 'Activa' : 'Inactiva', 1, 1, 'C', $fill);
$fill = !$fill;
}
break;
case 'personal':
$sql = "SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE 1=1";
$params = [];
if ($filtro_empresa) {
$sql .= " AND p.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_sucursal) {
$sql .= " AND p.sucursal_id = ?";
$params[] = $filtro_sucursal;
}
if ($filtro_estado) {
$sql .= " AND p.activo = ?";
$params[] = ($filtro_estado === 'activo') ? 1 : 0;
}
$sql .= " ORDER BY p.apellido, p.nombre";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Apellido y Nombre', 1, 0, 'L', true);
$pdf->Cell(30, 7, 'DNI', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Cargo', 1, 0, 'L', true);
$pdf->Cell(30, 7, 'Estado', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($datos as $row) {
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(45, 6, substr($row['apellido'] . ', ' . $row['nombre'], 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(30, 6, $row['dni'] ?? 'N/A', 1, 0, 'C', $fill);
$pdf->Cell(40, 6, substr($row['empresa_nombre'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(35, 6, substr($row['cargo'] ?? 'N/A', 0, 20), 1, 0, 'L', $fill);
$pdf->Cell(30, 6, $row['activo'] ? 'Activo' : 'Inactivo', 1, 1, 'C', $fill);
$fill = !$fill;
}
break;
case 'servicios':
$sql = "SELECT s.*, e.nombre as empresa_nombre FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id WHERE 1=1";
$params = [];
if ($filtro_empresa) {
$sql .= " AND s.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_estado) {
$sql .= " AND s.estado = ?";
$params[] = $filtro_estado;
}
if ($fecha_inicio) {
$sql .= " AND s.fecha_inicio >= ?";
$params[] = $fecha_inicio;
}
if ($fecha_fin) {
$sql .= " AND s.fecha_fin <= ?";
$params[] = $fecha_fin;
}
$sql .= " ORDER BY s.nombre";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Servicio', 1, 0, 'L', true);
$pdf->Cell(45, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(30, 7, 'Tipo', 1, 0, 'L', true);
$pdf->Cell(30, 7, 'Estado', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'Inicio', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($datos as $row) {
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(50, 6, substr($row['nombre'], 0, 35), 1, 0, 'L', $fill);
$pdf->Cell(45, 6, substr($row['empresa_nombre'] ?? 'N/A', 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(30, 6, ucfirst($row['tipo'] ?? 'N/A'), 1, 0, 'L', $fill);
$pdf->Cell(30, 6, ucfirst($row['estado'] ?? 'N/A'), 1, 0, 'C', $fill);
$pdf->Cell(25, 6, $row['fecha_inicio'] ? date('d/m/Y', strtotime($row['fecha_inicio'])) : 'N/A', 1, 1, 'C', $fill);
$fill = !$fill;
}
break;
case 'recursos':
$sql = "SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
WHERE 1=1";
$params = [];
if ($filtro_empresa) {
$sql .= " AND rs.empresa_id = ?";
$params[] = $filtro_empresa;
}
if ($filtro_sucursal) {
$sql .= " AND rs.sucursal_id = ?";
$params[] = $filtro_sucursal;
}
if ($filtro_estado) {
$sql .= " AND rs.estado = ?";
$params[] = $filtro_estado;
}
$sql .= " ORDER BY rs.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'Sucursal', 1, 0, 'L', true);
$pdf->Cell(45, 7, 'Personal', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Estado', 1, 0, 'C', true);
$pdf->Cell(20, 7, 'Fecha', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(0, 0, 0);
$fill = false;
foreach ($datos as $row) {
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(40, 6, substr($row['empresa_nombre'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(40, 6, substr($row['sucursal_nombre'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(45, 6, substr($row['personal_nombre'] ?? 'Sucursal', 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(35, 6, ucfirst($row['estado'] ?? 'N/A'), 1, 0, 'C', $fill);
$pdf->Cell(20, 6, date('d/m/Y', strtotime($row['created_at'])), 1, 1, 'C', $fill);
$fill = !$fill;
}
break;
case 'estadisticas':
// ========================================
// REPORTE ESTADÍSTICO DETALLADO MEJORADO
// ========================================

// Obtener información de la empresa si hay filtro
$nombre_empresa = 'Todas';
if ($filtro_empresa) {
$stmt = $conn->prepare("SELECT nombre FROM empresas WHERE id = ?");
$stmt->execute([$filtro_empresa]);
$emp_data = $stmt->fetch();
if ($emp_data) {
$nombre_empresa = $emp_data['nombre'];
}
}

// Obtener información de la sucursal si hay filtro
$nombre_sucursal = 'Todas';
$direccion_sucursal = '';
$estado_sucursal = 'N/A';
if ($filtro_sucursal) {
$stmt = $conn->prepare("SELECT s.*, e.nombre as empresa_nombre FROM sucursales s 
LEFT JOIN empresas e ON s.empresa_id = e.id WHERE s.id = ?");
$stmt->execute([$filtro_sucursal]);
$suc_data = $stmt->fetch();
if ($suc_data) {
$nombre_sucursal = $suc_data['nombre'];
$direccion_sucursal = $suc_data['direccion'] ?? 'Sin dirección';
$estado_sucursal = $suc_data['activa'] ? 'Activa' : 'Inactiva';
}
}

// ========================================
// SECCIÓN 1: DATOS DE LA SUCURSAL
// ========================================
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '1. INFORMACIÓN DE LA SUCURSAL', 0, 1, 'L', true);
$pdf->Ln(3);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(50, 8, 'Empresa:', 0, 0);
$pdf->Cell(140, 8, $nombre_empresa, 0, 1, 'L');
$pdf->Cell(50, 8, 'Sucursal:', 0, 0);
$pdf->Cell(140, 8, $nombre_sucursal, 0, 1, 'L');
$pdf->Cell(50, 8, 'Dirección:', 0, 0);
$pdf->Cell(140, 8, $direccion_sucursal, 0, 1, 'L');
$pdf->Cell(50, 8, 'Estado:', 0, 0);
$pdf->Cell(140, 8, $estado_sucursal, 0, 1, 'L');
$pdf->Ln(5);

// ========================================
// SECCIÓN 2: RESUMEN ESTADÍSTICO
// ========================================
$sql_per = "SELECT COUNT(*) as total FROM personal WHERE 1=1";
$sql_per_activo = "SELECT COUNT(*) as total FROM personal WHERE activo = 1 AND 1=1";
$sql_per_inactivo = "SELECT COUNT(*) as total FROM personal WHERE activo = 0 AND 1=1";
$sql_ser = "SELECT COUNT(*) as total FROM servicios WHERE 1=1";
$sql_rec = "SELECT COUNT(*) as total FROM recursos_sucursal WHERE 1=1";

$params_per = [];
$params_ser = [];
$params_rec = [];

// Aplicar filtros de empresa
if ($filtro_empresa) {
$params_per[] = $filtro_empresa;
$params_ser[] = $filtro_empresa;
$params_rec[] = $filtro_empresa;
$sql_per .= " AND empresa_id = ?";
$sql_per_activo .= " AND empresa_id = ?";
$sql_per_inactivo .= " AND empresa_id = ?";
$sql_ser .= " AND empresa_id = ?";
$sql_rec .= " AND empresa_id = ?";
}

// Aplicar filtros de sucursal
if ($filtro_sucursal) {
$params_per[] = $filtro_sucursal;
$params_rec[] = $filtro_sucursal;
$sql_per .= " AND sucursal_id = ?";
$sql_per_activo .= " AND sucursal_id = ?";
$sql_per_inactivo .= " AND sucursal_id = ?";
$sql_rec .= " AND sucursal_id = ?";
}

// Ejecutar consultas
$stmt = $conn->prepare($sql_per);
$stmt->execute($params_per);
$total_personal = $stmt->fetch()['total'];

$stmt = $conn->prepare($sql_per_activo);
$stmt->execute($params_per);
$total_personal_activo = $stmt->fetch()['total'];

$stmt = $conn->prepare($sql_per_inactivo);
$stmt->execute($params_per);
$total_personal_inactivo = $stmt->fetch()['total'];

$stmt = $conn->prepare($sql_ser);
$stmt->execute($params_ser);
$total_servicios = $stmt->fetch()['total'];

$stmt = $conn->prepare($sql_rec);
$stmt->execute($params_rec);
$total_recursos = $stmt->fetch()['total'];

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '2. RESUMEN ESTADÍSTICO', 0, 1, 'L', true);
$pdf->Ln(3);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(90, 8, 'Total Personal:', 0, 0);
$pdf->Cell(100, 8, $total_personal, 0, 1, 'L');
$pdf->Cell(90, 8, 'Personal Habilitado:', 0, 0, false, 0);
$pdf->SetTextColor(34, 197, 94);
$pdf->Cell(100, 8, $total_personal_activo, 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(90, 8, 'Personal No Habilitado:', 0, 0, false, 0);
$pdf->SetTextColor(239, 68, 68);
$pdf->Cell(100, 8, $total_personal_inactivo, 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(90, 8, 'Total Servicios:', 0, 0);
$pdf->Cell(100, 8, $total_servicios, 0, 1, 'L');
$pdf->Cell(90, 8, 'Total Recursos:', 0, 0);
$pdf->Cell(100, 8, $total_recursos, 0, 1, 'L');
$pdf->Ln(5);

// ========================================
// SECCIÓN 3: ESTADO DE SERVICIOS
// ========================================
$sql_estado_ser = "SELECT estado, COUNT(*) as total FROM servicios WHERE 1=1";
$params_estado = [];

if ($filtro_empresa) {
$params_estado[] = $filtro_empresa;
$sql_estado_ser .= " AND empresa_id = ?";
}

if ($filtro_sucursal) {
// Los servicios no tienen sucursal_id directo, usamos empresa_id
}

if ($fecha_inicio) {
$params_estado[] = $fecha_inicio;
$sql_estado_ser .= " AND fecha_inicio >= ?";
}

if ($fecha_fin) {
$params_estado[] = $fecha_fin;
$sql_estado_ser .= " AND fecha_fin <= ?";
}

$sql_estado_ser .= " GROUP BY estado";

$stmt = $conn->prepare($sql_estado_ser);
$stmt->execute($params_estado);
$servicios_estado = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '3. ESTADO DE SERVICIOS', 0, 1, 'L', true);
$pdf->Ln(3);
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(0, 0, 0);
if (count($servicios_estado) > 0) {
foreach ($servicios_estado as $estado) {
$pdf->Cell(90, 8, ucfirst($estado['estado']) . ':', 0, 0);
$pdf->Cell(100, 8, $estado['total'], 0, 1, 'L');
}
} else {
$pdf->Cell(0, 8, 'No hay servicios registrados', 0, 1, 'L');
}
$pdf->Ln(5);

// ========================================
// SECCIÓN 4: DETALLE DE PERSONAL (HABILITADOS Y NO HABILITADOS)
// ========================================
$sql_personal_detalle = "SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE 1=1";
$params_personal = [];

if ($filtro_empresa) {
$sql_personal_detalle .= " AND p.empresa_id = ?";
$params_personal[] = $filtro_empresa;
}

if ($filtro_sucursal) {
$sql_personal_detalle .= " AND p.sucursal_id = ?";
$params_personal[] = $filtro_sucursal;
}

$sql_personal_detalle .= " ORDER BY p.activo DESC, p.apellido, p.nombre";

$stmt = $conn->prepare($sql_personal_detalle);
$stmt->execute($params_personal);
$personal_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '4. DETALLE DE PERSONAL', 0, 1, 'L', true);
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Apellido y Nombre', 1, 0, 'L', true);
$pdf->Cell(25, 7, 'DNI', 1, 0, 'C', true);
$pdf->Cell(35, 7, 'Cargo', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Sucursal', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Estado', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$fill = false;
foreach ($personal_detalle as $row) {
$estado_color = $row['activo'] ? 0 : 255;
$pdf->SetTextColor($estado_color, $estado_color, $estado_color);
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(50, 6, substr($row['apellido'] . ', ' . $row['nombre'], 0, 35), 1, 0, 'L', $fill);
$pdf->Cell(25, 6, $row['dni'] ?? 'N/A', 1, 0, 'C', $fill);
$pdf->Cell(35, 6, substr($row['cargo'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(35, 6, substr($row['sucursal_nombre'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(35, 6, $row['activo'] ? 'Habilitado' : 'No Habilitado', 1, 1, 'C', $fill);
$fill = !$fill;
}
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(5);

// ========================================
// SECCIÓN 5: DETALLE DE RECURSOS
// ========================================
$sql_recursos_detalle = "SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
WHERE 1=1";
$params_recursos = [];

if ($filtro_empresa) {
$sql_recursos_detalle .= " AND rs.empresa_id = ?";
$params_recursos[] = $filtro_empresa;
}

if ($filtro_sucursal) {
$sql_recursos_detalle .= " AND rs.sucursal_id = ?";
$params_recursos[] = $filtro_sucursal;
}

$sql_recursos_detalle .= " ORDER BY rs.created_at DESC";

$stmt = $conn->prepare($sql_recursos_detalle);
$stmt->execute($params_recursos);
$recursos_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '5. DETALLE DE RECURSOS', 0, 1, 'L', true);
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(40, 7, 'Sucursal', 1, 0, 'L', true);
$pdf->Cell(45, 7, 'Personal/Asignado', 1, 0, 'L', true);
$pdf->Cell(35, 7, 'Estado', 1, 0, 'C', true);
$pdf->Cell(20, 7, 'Fecha', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$fill = false;
foreach ($recursos_detalle as $row) {
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(40, 6, substr($row['empresa_nombre'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(40, 6, substr($row['sucursal_nombre'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
$pdf->Cell(45, 6, substr($row['personal_nombre'] ?? 'Sucursal', 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(35, 6, ucfirst($row['estado'] ?? 'N/A'), 1, 0, 'C', $fill);
$pdf->Cell(20, 6, date('d/m/Y', strtotime($row['created_at'])), 1, 1, 'C', $fill);
$fill = !$fill;
}
$pdf->Ln(5);

// ========================================
// SECCIÓN 6: DETALLE DE SERVICIOS
// ========================================
$sql_servicios_detalle = "SELECT s.*, e.nombre as empresa_nombre FROM servicios s
LEFT JOIN empresas e ON s.empresa_id = e.id WHERE 1=1";
$params_servicios = [];

if ($filtro_empresa) {
$sql_servicios_detalle .= " AND s.empresa_id = ?";
$params_servicios[] = $filtro_empresa;
}

if ($filtro_estado) {
$sql_servicios_detalle .= " AND s.estado = ?";
$params_servicios[] = $filtro_estado;
}

if ($fecha_inicio) {
$sql_servicios_detalle .= " AND s.fecha_inicio >= ?";
$params_servicios[] = $fecha_inicio;
}

if ($fecha_fin) {
$sql_servicios_detalle .= " AND s.fecha_fin <= ?";
$params_servicios[] = $fecha_fin;
}

$sql_servicios_detalle .= " ORDER BY s.nombre";

$stmt = $conn->prepare($sql_servicios_detalle);
$stmt->execute($params_servicios);
$servicios_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(67, 97, 238);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '6. DETALLE DE SERVICIOS', 0, 1, 'L', true);
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(10, 7, 'ID', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Servicio', 1, 0, 'L', true);
$pdf->Cell(45, 7, 'Empresa', 1, 0, 'L', true);
$pdf->Cell(30, 7, 'Tipo', 1, 0, 'L', true);
$pdf->Cell(30, 7, 'Estado', 1, 0, 'C', true);
$pdf->Cell(25, 7, 'Inicio', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$fill = false;
foreach ($servicios_detalle as $row) {
$pdf->Cell(10, 6, $row['id'], 1, 0, 'C', $fill);
$pdf->Cell(50, 6, substr($row['nombre'], 0, 35), 1, 0, 'L', $fill);
$pdf->Cell(45, 6, substr($row['empresa_nombre'] ?? 'N/A', 0, 30), 1, 0, 'L', $fill);
$pdf->Cell(30, 6, ucfirst($row['tipo'] ?? 'N/A'), 1, 0, 'L', $fill);
$pdf->Cell(30, 6, ucfirst($row['estado'] ?? 'N/A'), 1, 0, 'C', $fill);
$pdf->Cell(25, 6, $row['fecha_inicio'] ? date('d/m/Y', strtotime($row['fecha_inicio'])) : 'N/A', 1, 1, 'C', $fill);
$fill = !$fill;
}

break;
}
$total_registros = count($datos);
// Auditoría
logAuditoria($conn, 'GENERACION_REPORTE_PDF', 'reportes', null, [
'tipo_reporte' => $tipo_reporte,
'total_registros' => $total_registros,
'filtros' => [
'empresa' => $filtro_empresa,
'sucursal' => $filtro_sucursal,
'estado' => $filtro_estado,
'fecha_inicio' => $fecha_inicio,
'fecha_fin' => $fecha_fin
],
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
], $user['id']);
// Guardar en sesión para el footer
$_SESSION['reporte_usuario'] = $user['nombre_usuario'] ?? 'Sistema';
// Nombre del archivo
$nombre_archivo = 'Reporte_' . ucfirst($tipo_reporte) . '_' . date('Y-m-d_His') . '.pdf';
// Output del PDF
$pdf->Output('D', $nombre_archivo);
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al generar reporte: ' . $e->getMessage();
logAuditoria($conn, 'ERROR_GENERAR_REPORTE', 'reportes', null, [
'error' => $e->getMessage(),
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
], $user['id']);
header('Location: reportes.php');
exit;
}
}
// ============================================================================
// 3. OBTENER DATOS PARA FILTROS
// ============================================================================
try {
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->query("SELECT id, nombre, empresa_id FROM sucursales WHERE activa = TRUE ORDER BY nombre");
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sucursales_por_empresa = [];
foreach ($sucursales as $sucursal) {
$empresa_id = $sucursal['empresa_id'] ?? 0;
if (!isset($sucursales_por_empresa[$empresa_id])) {
$sucursales_por_empresa[$empresa_id] = [];
}
$sucursales_por_empresa[$empresa_id][] = $sucursal;
}
// Contadores para estadísticas rápidas
$stmt = $conn->query("SELECT COUNT(*) as total FROM empresas");
$total_empresas = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales");
$total_sucursales = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM personal");
$total_personal = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM servicios");
$total_servicios = $stmt->fetch()['total'];
} catch (PDOException $e) {
$error = "Error al cargar datos: " . $e->getMessage();
$empresas = [];
$sucursales = [];
$sucursales_por_empresa = [];
$total_empresas = 0;
$total_sucursales = 0;
$total_personal = 0;
$total_servicios = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Reportes - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<style>
:root {
--primary-color: #4361ee;
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
transition: transform 0.3s;
}
.stat-card:hover {
transform: translateY(-5px);
box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
.report-type-card {
background: linear-gradient(135deg, #f8f9fa, #e9ecef);
border: 2px solid var(--card-border);
border-radius: 8px;
padding: 20px;
margin-bottom: 15px;
cursor: pointer;
transition: all 0.3s;
}
.report-type-card:hover {
border-color: var(--primary-color);
background: linear-gradient(135deg, #e3f2fd, #bbdefb);
transform: translateY(-3px);
}
.report-type-card.selected {
border-color: var(--primary-color);
background: linear-gradient(135deg, #4361ee, #3a0ca3);
color: white;
}
.report-type-card.selected .stat-label {
color: rgba(255,255,255,0.8);
}
.report-type-card.selected .stat-number {
color: white;
}
.report-icon {
font-size: 2.5rem;
margin-bottom: 10px;
color: var(--primary-color);
}
.report-type-card.selected .report-icon {
color: white;
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
.btn {
border-radius: 4px;
font-weight: 500;
padding: 8px 16px;
}
.btn-primary {
background-color: var(--primary-color);
border-color: var(--primary-color);
}
.btn-generar {
background: linear-gradient(135deg, #4361ee, #3a0ca3);
border: none;
color: white;
padding: 12px 30px;
font-size: 1.1rem;
box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
}
.btn-generar:hover {
transform: translateY(-2px);
box-shadow: 0 6px 20px rgba(67, 97, 238, 0.6);
}
.alert {
border-radius: 4px;
}
</style>
</head>
<body>
<?php $page_title = 'Gestión de Reportes'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- ESTADÍSTICAS RÁPIDAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-building fa-2x"></i></div>
<div class="stat-number"><?php echo $total_empresas; ?></div>
<div class="stat-label">Empresas</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-map-marker-alt fa-2x"></i></div>
<div class="stat-number"><?php echo $total_sucursales; ?></div>
<div class="stat-label">Sucursales</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-users fa-2x"></i></div>
<div class="stat-number"><?php echo $total_personal; ?></div>
<div class="stat-label">Personal</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-concierge-bell fa-2x"></i></div>
<div class="stat-number"><?php echo $total_servicios; ?></div>
<div class="stat-label">Servicios</div>
</div>
</div>
<!-- SELECCIÓN DE TIPO DE REPORTE -->
<div class="section-box">
<div class="section-title"><i class="fas fa-file-alt me-2"></i>Seleccionar Tipo de Reporte</div>
<div class="row">
<div class="col-md-4">
<div class="report-type-card" onclick="selectReporte('empresas', this)">
<div class="report-icon"><i class="fas fa-building"></i></div>
<h5>Empresas</h5>
<p class="stat-label">Listado completo de empresas</p>
</div>
</div>
<div class="col-md-4">
<div class="report-type-card" onclick="selectReporte('sucursales', this)">
<div class="report-icon"><i class="fas fa-map-marker-alt"></i></div>
<h5>Sucursales</h5>
<p class="stat-label">Listado de sucursales</p>
</div>
</div>
<div class="col-md-4">
<div class="report-type-card" onclick="selectReporte('personal', this)">
<div class="report-icon"><i class="fas fa-users"></i></div>
<h5>Personal</h5>
<p class="stat-label">Listado de personal</p>
</div>
</div>
<div class="col-md-4">
<div class="report-type-card" onclick="selectReporte('servicios', this)">
<div class="report-icon"><i class="fas fa-concierge-bell"></i></div>
<h5>Servicios</h5>
<p class="stat-label">Listado de servicios</p>
</div>
</div>
<div class="col-md-4">
<div class="report-type-card" onclick="selectReporte('recursos', this)">
<div class="report-icon"><i class="fas fa-boxes"></i></div>
<h5>Recursos</h5>
<p class="stat-label">Asignación de recursos</p>
</div>
</div>
<div class="col-md-4">
<div class="report-type-card" onclick="selectReporte('estadisticas', this)">
<div class="report-icon"><i class="fas fa-chart-bar"></i></div>
<h5>Estadísticas</h5>
<p class="stat-label">Resumen detallado del sistema</p>
</div>
</div>
</div>
</div>
<!-- FILTROS DEL REPORTE -->
<div class="section-box" id="filtrosSection" style="display: none;">
<div class="section-title"><i class="fas fa-filter me-2"></i>Filtros del Reporte</div>
<form method="POST" action="reportes.php" id="formReporte">
<input type="hidden" name="generar_reporte" value="1">
<input type="hidden" name="tipo_reporte" id="tipo_reporte" value="">
<div class="row g-3">
<div class="col-md-3" id="filtro_empresa_div" style="display: none;">
<label class="form-label"><i class="fas fa-building me-2"></i>Empresa</label>
<select name="filtro_empresa" id="filtro_empresa" class="form-select" onchange="filtrarSucursales(this.value)">
<option value="">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3" id="filtro_sucursal_div" style="display: none;">
<label class="form-label"><i class="fas fa-map-marker-alt me-2"></i>Sucursal</label>
<select name="filtro_sucursal" id="filtro_sucursal" class="form-select">
<option value="">Todas las sucursales</option>
</select>
</div>
<div class="col-md-3" id="filtro_estado_div" style="display: none;">
<label class="form-label"><i class="fas fa-toggle-on me-2"></i>Estado</label>
<select name="filtro_estado" id="filtro_estado" class="form-select">
<option value="">Todos</option>
<option value="activo">Activo</option>
<option value="inactivo">Inactivo</option>
<option value="pendiente">Pendiente</option>
<option value="aprobado">Aprobado</option>
<option value="rechazado">Rechazado</option>
</select>
</div>
<div class="col-md-3" id="filtro_fecha_inicio_div" style="display: none;">
<label class="form-label"><i class="fas fa-calendar-alt me-2"></i>Fecha Inicio</label>
<input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control">
</div>
<div class="col-md-3" id="filtro_fecha_fin_div" style="display: none;">
<label class="form-label"><i class="fas fa-calendar-check me-2"></i>Fecha Fin</label>
<input type="date" name="fecha_fin" id="fecha_fin" class="form-control">
</div>
</div>
<div class="mt-4 text-center">
<button type="submit" class="btn btn-generar" id="btnGenerar" disabled>
<i class="fas fa-file-pdf me-2"></i>Generar Reporte PDF
</button>
<a href="reportes.php" class="btn btn-secondary ms-2">
<i class="fas fa-times me-2"></i>Cancelar
</a>
</div>
</form>
</div>
<!-- INFORMACIÓN ADICIONAL -->
<div class="section-box">
<div class="section-title"><i class="fas fa-info-circle me-2"></i>Información</div>
<div class="alert alert-info">
<i class="fas fa-lightbulb me-2"></i>
<strong>Consejo:</strong> Seleccione un tipo de reporte para ver las opciones de filtrado disponibles.
Los reportes se generan en formato PDF y se descargan automáticamente.
</div>
<div class="row mt-3">
<div class="col-md-6">
<h6><i class="fas fa-check-circle text-success me-2"></i>Características:</h6>
<ul class="list-unstyled">
<li><i class="fas fa-angle-right text-primary me-2"></i>Exportación en PDF profesional</li>
<li><i class="fas fa-angle-right text-primary me-2"></i>Filtros personalizables</li>
<li><i class="fas fa-angle-right text-primary me-2"></i>Auditoría de generación</li>
<li><i class="fas fa-angle-right text-primary me-2"></i>Formato optimizado para impresión</li>
</ul>
</div>
<div class="col-md-6">
<h6><i class="fas fa-exclamation-triangle text-warning me-2"></i>Consideraciones:</h6>
<ul class="list-unstyled">
<li><i class="fas fa-angle-right text-warning me-2"></i>Reportes grandes pueden tardar más</li>
<li><i class="fas fa-angle-right text-warning me-2"></i>Máximo recomendado: 1000 registros</li>
<li><i class="fas fa-angle-right text-warning me-2"></i>Requiere permisos de administrador</li>
</ul>
</div>
</div>
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
});
const sucursalesPorEmpresa = <?php echo json_encode($sucursales_por_empresa, JSON_UNESCAPED_UNICODE); ?>;
// Configuración de filtros por tipo de reporte
const configFiltros = {
'empresas': {
empresa: false,
sucursal: false,
estado: true,
fecha_inicio: true,
fecha_fin: true
},
'sucursales': {
empresa: true,
sucursal: false,
estado: true,
fecha_inicio: false,
fecha_fin: false
},
'personal': {
empresa: true,
sucursal: true,
estado: true,
fecha_inicio: false,
fecha_fin: false
},
'servicios': {
empresa: true,
sucursal: false,
estado: true,
fecha_inicio: true,
fecha_fin: true
},
'recursos': {
empresa: true,
sucursal: true,
estado: true,
fecha_inicio: false,
fecha_fin: false
},
'estadisticas': {
empresa: true,
sucursal: true,
estado: true,
fecha_inicio: true,
fecha_fin: true
}
};
function selectReporte(tipo, element) {
// Remover selección previa
document.querySelectorAll('.report-type-card').forEach(card => {
card.classList.remove('selected');
});
// Seleccionar nuevo
element.classList.add('selected');
// Mostrar sección de filtros
document.getElementById('filtrosSection').style.display = 'block';
document.getElementById('tipo_reporte').value = tipo;
// Configurar filtros visibles
const config = configFiltros[tipo];
document.getElementById('filtro_empresa_div').style.display = config.empresa ? 'block' : 'none';
document.getElementById('filtro_sucursal_div').style.display = config.sucursal ? 'block' : 'none';
document.getElementById('filtro_estado_div').style.display = config.estado ? 'block' : 'none';
document.getElementById('filtro_fecha_inicio_div').style.display = config.fecha_inicio ? 'block' : 'none';
document.getElementById('filtro_fecha_fin_div').style.display = config.fecha_fin ? 'block' : 'none';
// Habilitar botón
document.getElementById('btnGenerar').disabled = false;
// Scroll suave hacia filtros
document.getElementById('filtrosSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function filtrarSucursales(empresaId) {
const selectSucursal = document.getElementById('filtro_sucursal');
selectSucursal.innerHTML = '<option value="">Todas las sucursales</option>';
if (empresaId && sucursalesPorEmpresa[empresaId]) {
sucursalesPorEmpresa[empresaId].forEach(function(sucursal) {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
selectSucursal.appendChild(option);
});
}
}
// Auto-close alerts
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
// Confirmación antes de generar
document.getElementById('formReporte').addEventListener('submit', function(e) {
const tipoReporte = document.getElementById('tipo_reporte').value;
if (!tipoReporte) {
e.preventDefault();
Swal.fire({
icon: 'warning',
title: 'Seleccione un reporte',
text: 'Debe seleccionar un tipo de reporte antes de generar'
});
return false;
}
});
</script>
</body>
</html>