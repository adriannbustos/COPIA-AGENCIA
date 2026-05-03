<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ==================== MANEJO DE ACCIONES AJAX ====================
// 3. OBTENER SUCURSALES POR EMPRESA (AJAX) - MEJORADO
if (isset($_GET['action']) && $_GET['action'] === 'get_sucursales') {
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
$empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
error_log("AJAX get_sucursales - empresa_id: " . $empresa_id);
if ($empresa_id > 0) {
try {
$conn = getDBConnection();
if (!$conn) {
echo json_encode([
'success' => false,
'error' => 'No se pudo conectar a la base de datos',
'data' => [],
'debug' => ['empresa_id' => $empresa_id]
]);
exit;
}
$stmt = $conn->prepare("
SELECT id, nombre, activa
FROM sucursales
WHERE empresa_id = :empresa_id
ORDER BY nombre
");
$stmt->execute(['empresa_id' => $empresa_id]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Sucursales encontradas: " . count($sucursales));
echo json_encode([
'success' => true,
'data' => $sucursales,
'count' => count($sucursales),
'debug' => ['empresa_id' => $empresa_id]
]);
} catch(PDOException $e) {
error_log("Error al obtener sucursales: " . $e->getMessage());
echo json_encode([
'success' => false,
'error' => $e->getMessage(),
'data' => [],
'debug' => ['empresa_id' => $empresa_id]
]);
}
} else {
echo json_encode([
'success' => true,
'data' => [],
'count' => 0,
'debug' => ['empresa_id' => $empresa_id, 'mensaje' => 'empresa_id no válido']
]);
}
exit;
}
// ✅ 9. BUSCAR PERSONAL PARA LOTE (AJAX) - NUEVA FUNCIÓN AGREGADA
if (isset($_GET['action']) && $_GET['action'] === 'buscar_personal_lote') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga'))) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$filtro_empresa = isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa']) ? (int)$_GET['filtro_empresa'] : 0;
$filtro_sucursal = isset($_GET['filtro_sucursal']) && !empty($_GET['filtro_sucursal']) ? (int)$_GET['filtro_sucursal'] : 0;
$filtro_activo = isset($_GET['filtro_activo']) && $_GET['filtro_activo'] !== '' ? $_GET['filtro_activo'] : '';
$filtro_vencimiento = isset($_GET['filtro_vencimiento']) && !empty($_GET['filtro_vencimiento']) ? $_GET['filtro_vencimiento'] : '';
$filtro_credencial = isset($_GET['filtro_credencial']) && !empty($_GET['filtro_credencial']) ? $_GET['filtro_credencial'] : '';
$filtro_texto = isset($_GET['filtro_texto']) && !empty($_GET['filtro_texto']) ? sanitizeInput($_GET['filtro_texto']) : '';
// ✅ NUEVO: FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (PRIMERA PALABRA)
$filtro_letra_desde = isset($_GET['filtro_letra_desde']) && !empty($_GET['filtro_letra_desde']) ? strtoupper(sanitizeInput($_GET['filtro_letra_desde'])) : 'A';
$filtro_letra_hasta = isset($_GET['filtro_letra_hasta']) && !empty($_GET['filtro_letra_hasta']) ? strtoupper(sanitizeInput($_GET['filtro_letra_hasta'])) : 'Z';
try {
$conn = getDBConnection();
$query = "
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE 1=1
";
$params = [];
if ($filtro_empresa > 0) {
$query .= " AND p.empresa_id = :filtro_empresa";
$params['filtro_empresa'] = $filtro_empresa;
}
if ($filtro_sucursal > 0) {
$query .= " AND p.sucursal_id = :filtro_sucursal";
$params['filtro_sucursal'] = $filtro_sucursal;
}
if ($filtro_activo !== '') {
$query .= " AND p.activo = :filtro_activo";
$params['filtro_activo'] = $filtro_activo;
}
if (!empty($filtro_vencimiento)) {
if ($filtro_vencimiento === 'vencido') {
$query .= " AND p.fecha_vencimiento < CURDATE()";
} elseif ($filtro_vencimiento === 'proximo') {
$query .= " AND (p.fecha_vencimiento >= CURDATE() AND p.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_vencimiento === 'vigente') {
$query .= " AND (p.fecha_vencimiento IS NULL OR p.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_credencial)) {
if ($filtro_credencial === 'pagada') {
$query .= " AND p.pago_credencial = 1";
} elseif ($filtro_credencial === 'pendiente') {
$query .= " AND (p.pago_credencial = 0 OR p.pago_credencial IS NULL)";
}
}
// ✅ APLICAR FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (primera palabra de apellido) - CORREGIDO: FILTRO SOLO EN APELLIDO
if ($filtro_letra_desde !== 'A' || $filtro_letra_hasta !== 'Z') {
$query .= " AND UPPER(LEFT(SUBSTRING_INDEX(p.apellido, ' ', 1), 1)) BETWEEN :filtro_letra_desde_ap AND :filtro_letra_hasta_ap";
$params['filtro_letra_desde_ap'] = $filtro_letra_desde;
$params['filtro_letra_hasta_ap'] = $filtro_letra_hasta;
}
$query .= " ORDER BY p.apellido, p.nombre LIMIT 500";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$personal = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'personal' => $personal, 'count' => count($personal)]);
} catch(PDOException $e) {
error_log("Error al buscar personal para lote: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al buscar personal: ' . $e->getMessage()]);
}
exit;
}
// ✅ NUEVO: EXPORTAR FILTROS A PDF (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'exportar_pdf_filtrado') {
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga'))) {
die('No autorizado');
}
ob_clean();
// Recibir filtros desde GET
$filtro_empresa = isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa']) ? (int)$_GET['filtro_empresa'] : 0;
$filtro_sucursal = isset($_GET['filtro_sucursal']) && !empty($_GET['filtro_sucursal']) ? (int)$_GET['filtro_sucursal'] : 0;
$filtro_texto = isset($_GET['filtro_texto']) && !empty($_GET['filtro_texto']) ? sanitizeInput($_GET['filtro_texto']) : '';
$filtro_estado_documentacion = isset($_GET['filtro_estado_documentacion']) && !empty($_GET['filtro_estado_documentacion']) ? $_GET['filtro_estado_documentacion'] : '';
$filtro_cargo = isset($_GET['filtro_cargo']) && !empty($_GET['filtro_cargo']) ? sanitizeInput($_GET['filtro_cargo']) : '';
$filtro_modalidad = isset($_GET['filtro_modalidad']) && !empty($_GET['filtro_modalidad']) ? sanitizeInput($_GET['filtro_modalidad']) : '';
$filtro_activo = isset($_GET['filtro_activo']) && $_GET['filtro_activo'] !== '' ? $_GET['filtro_activo'] : '';
$filtro_certificado = isset($_GET['filtro_certificado']) && $_GET['filtro_certificado'] !== '' ? $_GET['filtro_certificado'] : '';
$filtro_arma = isset($_GET['filtro_arma']) && $_GET['filtro_arma'] !== '' ? $_GET['filtro_arma'] : '';
$filtro_penales = isset($_GET['filtro_penales']) && $_GET['filtro_penales'] !== '' ? $_GET['filtro_penales'] : '';
$filtro_prov = isset($_GET['filtro_prov']) && $_GET['filtro_prov'] !== '' ? $_GET['filtro_prov'] : '';
$filtro_clu = isset($_GET['filtro_clu']) && $_GET['filtro_clu'] !== '' ? $_GET['filtro_clu'] : '';
$filtro_ram = isset($_GET['filtro_ram']) && $_GET['filtro_ram'] !== '' ? $_GET['filtro_ram'] : '';
$filtro_desde = isset($_GET['filtro_desde']) && !empty($_GET['filtro_desde']) ? $_GET['filtro_desde'] : '';
$filtro_hasta = isset($_GET['filtro_hasta']) && !empty($_GET['filtro_hasta']) ? $_GET['filtro_hasta'] : '';
$filtro_edad_min = isset($_GET['filtro_edad_min']) && !empty($_GET['filtro_edad_min']) ? (int)$_GET['filtro_edad_min'] : 0;
$filtro_edad_max = isset($_GET['filtro_edad_max']) && !empty($_GET['filtro_edad_max']) ? (int)$_GET['filtro_edad_max'] : 0;
$filtro_vencimiento = isset($_GET['filtro_vencimiento']) && !empty($_GET['filtro_vencimiento']) ? $_GET['filtro_vencimiento'] : '';
$filtro_revalidacion = isset($_GET['filtro_revalidacion']) && !empty($_GET['filtro_revalidacion']) ? $_GET['filtro_revalidacion'] : '';
$filtro_credencial = isset($_GET['filtro_credencial']) && !empty($_GET['filtro_credencial']) ? $_GET['filtro_credencial'] : '';
$filtro_letra_desde = isset($_GET['filtro_letra_desde']) && !empty($_GET['filtro_letra_desde']) ? strtoupper(sanitizeInput($_GET['filtro_letra_desde'])) : 'A';
$filtro_letra_hasta = isset($_GET['filtro_letra_hasta']) && !empty($_GET['filtro_letra_hasta']) ? strtoupper(sanitizeInput($_GET['filtro_letra_hasta'])) : 'Z';
try {
$conn = getDBConnection();
// Construir query con filtros
$query = "
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio, e.localidad as empresa_localidad
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE 1=1
";
$params = [];
if ($filtro_empresa > 0) {
$query .= " AND p.empresa_id = :filtro_empresa";
$params['filtro_empresa'] = $filtro_empresa;
}
if ($filtro_sucursal > 0) {
$query .= " AND p.sucursal_id = :filtro_sucursal";
$params['filtro_sucursal'] = $filtro_sucursal;
}
if (!empty($filtro_texto)) {
$query .= " AND (p.nombre LIKE :filtro_texto_nombre OR p.apellido LIKE :filtro_texto_apellido OR p.dni LIKE :filtro_texto_dni)";
$params['filtro_texto_nombre'] = "%{$filtro_texto}%";
$params['filtro_texto_apellido'] = "%{$filtro_texto}%";
$params['filtro_texto_dni'] = "%{$filtro_texto}%";
}
if (!empty($filtro_estado_documentacion)) {
$query .= " AND p.estado_documentacion = :filtro_estado_documentacion";
$params['filtro_estado_documentacion'] = $filtro_estado_documentacion;
}
if (!empty($filtro_cargo)) {
$query .= " AND p.cargo = :filtro_cargo";
$params['filtro_cargo'] = $filtro_cargo;
}
if (!empty($filtro_modalidad)) {
$query .= " AND p.modalidad_contrato = :filtro_modalidad";
$params['filtro_modalidad'] = $filtro_modalidad;
}
if ($filtro_activo !== '') {
$query .= " AND p.activo = :filtro_activo";
$params['filtro_activo'] = $filtro_activo;
}
if ($filtro_certificado !== '') {
$query .= " AND p.tiene_certificado = :filtro_certificado";
$params['filtro_certificado'] = $filtro_certificado;
}
if ($filtro_arma !== '') {
$query .= " AND p.arma_autorizada = :filtro_arma";
$params['filtro_arma'] = $filtro_arma;
}
if ($filtro_penales !== '') {
$query .= " AND p.tiene_penales = :filtro_penales";
$params['filtro_penales'] = $filtro_penales;
}
if ($filtro_prov !== '') {
$query .= " AND p.antecedentes_provinciales = :filtro_prov";
$params['filtro_prov'] = $filtro_prov;
}
if ($filtro_clu !== '') {
$query .= " AND p.clu_numero = :filtro_clu";
$params['filtro_clu'] = $filtro_clu;
}
if ($filtro_ram !== '') {
$query .= " AND p.ram = :filtro_ram";
$params['filtro_ram'] = $filtro_ram;
}
if (!empty($filtro_desde)) {
$query .= " AND p.fecha_ingreso >= :filtro_desde";
$params['filtro_desde'] = $filtro_desde;
}
if (!empty($filtro_hasta)) {
$query .= " AND p.fecha_ingreso <= :filtro_hasta";
$params['filtro_hasta'] = $filtro_hasta;
}
if ($filtro_edad_min > 0 || $filtro_edad_max > 0) {
if ($filtro_edad_min > 0 && $filtro_edad_max > 0) {
$query .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) BETWEEN :edad_min AND :edad_max";
$params['edad_min'] = $filtro_edad_min;
$params['edad_max'] = $filtro_edad_max;
} elseif ($filtro_edad_min > 0) {
$query .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) >= :edad_min";
$params['edad_min'] = $filtro_edad_min;
} elseif ($filtro_edad_max > 0) {
$query .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) <= :edad_max";
$params['edad_max'] = $filtro_edad_max;
}
}
if (!empty($filtro_vencimiento) || !empty($filtro_revalidacion) || !empty($filtro_credencial)) {
$conditions = [];
if (!empty($filtro_vencimiento)) {
if ($filtro_vencimiento === 'vencido') {
$conditions[] = "p.fecha_vencimiento < CURDATE()";
} elseif ($filtro_vencimiento === 'proximo') {
$conditions[] = "(p.fecha_vencimiento >= CURDATE() AND p.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_vencimiento === 'vigente') {
$conditions[] = "(p.fecha_vencimiento IS NULL OR p.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_revalidacion)) {
if ($filtro_revalidacion === 'vencido') {
$conditions[] = "p.fecha_revalidacion < CURDATE()";
} elseif ($filtro_revalidacion === 'proximo') {
$conditions[] = "(p.fecha_revalidacion >= CURDATE() AND p.fecha_revalidacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_revalidacion === 'vigente') {
$conditions[] = "(p.fecha_revalidacion IS NULL OR p.fecha_revalidacion > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_credencial)) {
if ($filtro_credencial === 'pagada') {
$conditions[] = "p.pago_credencial = 1";
} elseif ($filtro_credencial === 'pendiente') {
$conditions[] = "(p.pago_credencial = 0 OR p.pago_credencial IS NULL)";
}
}
if (!empty($conditions)) {
$query .= " AND (" . implode(" AND ", $conditions) . ")";
}
}
if ($filtro_letra_desde !== 'A' || $filtro_letra_hasta !== 'Z') {
$query .= " AND UPPER(LEFT(SUBSTRING_INDEX(p.apellido, ' ', 1), 1)) BETWEEN :filtro_letra_desde_ap AND :filtro_letra_hasta_ap";
$params['filtro_letra_desde_ap'] = $filtro_letra_desde;
$params['filtro_letra_hasta_ap'] = $filtro_letra_hasta;
}
$query .= " ORDER BY p.apellido, p.nombre";
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
$stmt->bindValue(":{$key}", $value);
}
$stmt->execute();
$personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Verificar FPDF
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
class PDF_Personal extends FPDF {
function Header() {
$this->SetFont('Arial','B',14);
$this->Cell(0,10,'REPORTE DE PERSONAL - FILTROS APLICADOS',0,1,'C');
$this->Ln(5);
$this->SetFont('Arial','',9);
$this->Cell(0,5,'Fecha de generación: ' . date('d/m/Y H:i:s'),0,1,'R');
$this->Ln(3);
// Filtros aplicados
if (!empty($this->filtros_aplicados)) {
$this->SetFont('Arial','B',8);
$this->Cell(0,4,'Filtros aplicados:',0,1,'L');
$this->SetFont('Arial','',8);
foreach ($this->filtros_aplicados as $filtro) {
$this->Cell(0,4,utf8_decode('• ' . $filtro),0,1,'L');
}
$this->Ln(3);
}
// Cabecera de tabla
$this->SetFont('Arial','B',7);
$this->SetFillColor(200,220,255);
$this->Cell(8,6,'ID',1,0,'C',true);
$this->Cell(60,6,'Apellido y Nombre',1,0,'L',true);
$this->Cell(20,6,'CUIL',1,0,'C',true);
$this->Cell(25,6,'Cargo',1,0,'L',true);
$this->Cell(55,6,'Empresa',1,0,'L',true);
$this->Cell(55,6,'Sucursal',1,0,'L',true);
$this->Cell(15,6,'Estado',1,0,'C',true);
$this->Cell(22,6,'Vencimiento',1,0,'C',true);
$this->Cell(20,6,'Doc.',1,1,'C',true);
}
function Footer() {
$this->SetY(-15);
$this->SetFont('Arial','I',8);
$this->Cell(0,10,'Página '.$this->PageNo().'/{nb}',0,0,'C');
}
function Row($data) {
$this->SetFont('Arial','',7);
$this->Cell(8,6,$data['id'],1,0,'C');
$this->Cell(60,6,utf8_decode($data['apellido'].', '.$data['nombre']),1,0,'L');
$this->Cell(20,6,$data['dni'],1,0,'C');
$this->Cell(25,6,utf8_decode($data['cargo']??''),1,0,'L');
$this->Cell(55,6,utf8_decode($data['empresa_nombre']??'N/A'),1,0,'L');
$this->Cell(55,6,utf8_decode($data['sucursal_nombre']??'N/A'),1,0,'L');
$estado = $data['activo'] ? 'Activo' : 'Inactivo';
$this->Cell(15,6,$estado,1,0,'C');
$venc = $data['fecha_vencimiento'] ? date('d/m/Y',strtotime($data['fecha_vencimiento'])) : '-';
$this->Cell(22,6,$venc,1,0,'C');
$doc = ucfirst($data['estado_documentacion']??'pendiente');
$this->Cell(20,6,$doc,1,1,'C');
}
public $filtros_aplicados = [];
}
$pdf = new PDF_Personal();
$pdf->AliasNbPages();
$pdf->AddPage('L','A4');
$pdf->SetFont('Arial','',7);
// Registrar filtros aplicados
$filtros_txt = [];
if ($filtro_empresa > 0) {
$stmt_emp = $conn->prepare("SELECT nombre FROM empresas WHERE id = :id");
$stmt_emp->execute(['id'=>$filtro_empresa]);
$emp_nom = $stmt_emp->fetchColumn();
$filtros_txt[] = "Empresa: $emp_nom";
}
if ($filtro_sucursal > 0) {
$stmt_suc = $conn->prepare("SELECT nombre FROM sucursales WHERE id = :id");
$stmt_suc->execute(['id'=>$filtro_sucursal]);
$suc_nom = $stmt_suc->fetchColumn();
$filtros_txt[] = "Sucursal: $suc_nom";
}
if (!empty($filtro_texto)) $filtros_txt[] = "Texto: $filtro_texto";
if (!empty($filtro_estado_documentacion)) $filtros_txt[] = "Estado Doc: ".ucfirst($filtro_estado_documentacion);
if (!empty($filtro_cargo)) $filtros_txt[] = "Cargo: $filtro_cargo";
if (!empty($filtro_modalidad)) $filtros_txt[] = "Modalidad: $filtro_modalidad";
if ($filtro_activo !== '') $filtros_txt[] = "Estado: ".($filtro_activo=='1'?'Activo':'Inactivo');
if ($filtro_certificado !== '') $filtros_txt[] = "Certificado: ".($filtro_certificado=='1'?'Sí':'No');
if ($filtro_arma !== '') $filtros_txt[] = "Arma: ".($filtro_arma=='1'?'Autorizada':'No');
if ($filtro_penales !== '') $filtros_txt[] = "Ant. Penales: ".($filtro_penales=='1'?'Sí':'No');
if ($filtro_prov !== '') $filtros_txt[] = "Ant. Provinciales: ".($filtro_prov=='1'?'Sí':'No');
if ($filtro_clu !== '') $filtros_txt[] = "CLU: ".($filtro_clu=='1'?'Sí':'No');
if ($filtro_ram !== '') $filtros_txt[] = "RAM: ".($filtro_ram=='1'?'Pagado':'Pendiente');
if (!empty($filtro_desde)) $filtros_txt[] = "Ingreso desde: $filtro_desde";
if (!empty($filtro_hasta)) $filtros_txt[] = "Ingreso hasta: $filtro_hasta";
if ($filtro_edad_min > 0) $filtros_txt[] = "Edad mínima: $filtro_edad_min";
if ($filtro_edad_max > 0) $filtros_txt[] = "Edad máxima: $filtro_edad_max";
if (!empty($filtro_vencimiento)) $filtros_txt[] = "Vencimiento: ".ucfirst($filtro_vencimiento);
if (!empty($filtro_revalidacion)) $filtros_txt[] = "Revalidación: ".ucfirst($filtro_revalidacion);
if (!empty($filtro_credencial)) $filtros_txt[] = "Credencial: ".ucfirst($filtro_credencial);
if ($filtro_letra_desde !== 'A' || $filtro_letra_hasta !== 'Z') $filtros_txt[] = "Apellido: $filtro_letra_desde - $filtro_letra_hasta";
$pdf->filtros_aplicados = $filtros_txt;
// Datos
foreach ($personal_list as $persona) {
$pdf->Row([
'id' => $persona['id'],
'apellido' => $persona['apellido'],
'nombre' => $persona['nombre'],
'dni' => $persona['dni'],
'cargo' => $persona['cargo'],
'empresa_nombre' => $persona['empresa_nombre'],
'sucursal_nombre' => $persona['sucursal_nombre'],
'activo' => $persona['activo'],
'fecha_vencimiento' => $persona['fecha_vencimiento'],
'estado_documentacion' => $persona['estado_documentacion']
]);
}
// Total
$pdf->Ln(5);
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,6,'Total de registros: '.count($personal_list),0,1,'R');
// Nombre de archivo
$nombre_archivo = 'Reporte_Personal_Filtrado_'.date('Ymd_His').'.pdf';
$pdf->Output('D', $nombre_archivo);
exit;
} catch(PDOException $e) {
error_log("Error al exportar PDF: " . $e->getMessage());
die('Error al generar el PDF: ' . $e->getMessage());
}
}
// ✅ NUEVO: EXPORTAR PERSONAL SELECCIONADO (LOTE) A PDF (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'exportar_personal_seleccionado_pdf') {
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga'))) {
die('No autorizado');
}
ob_clean();
$personal_ids = isset($_POST['personal_ids']) && is_array($_POST['personal_ids'])
? array_map('intval', $_POST['personal_ids']) : [];
if (empty($personal_ids)) {
die('<h2 style="color:red;text-align:center;padding:30px">❌ No se seleccionó personal para exportar</h2>');
}
try {
$conn = getDBConnection();
// Construir query con IDs seleccionados
$placeholders = implode(',', array_fill(0, count($personal_ids), '?'));
$query = "
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio, e.localidad as empresa_localidad
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.id IN ($placeholders)
ORDER BY p.apellido, p.nombre
";
$stmt = $conn->prepare($query);
$stmt->execute($personal_ids);
$personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Verificar FPDF
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
class PDF_Personal_Lote extends FPDF {
function Header() {
$this->SetFont('Arial','B',14);
$this->Cell(0,10,'LISTADO DE PERSONAL SELECCIONADO',0,1,'C');
$this->Ln(5);
$this->SetFont('Arial','',9);
$this->Cell(0,5,'Fecha de generación: ' . date('d/m/Y H:i:s'),0,1,'R');
$this->Ln(3);
// Cabecera de tabla
$this->SetFont('Arial','B',7);
$this->SetFillColor(200,220,255);
$this->Cell(8,6,'ID',1,0,'C',true);
$this->Cell(60,6,'Apellido y Nombre',1,0,'L',true);
$this->Cell(20,6,'CUIL',1,0,'C',true);
$this->Cell(25,6,'Cargo',1,0,'L',true);
$this->Cell(55,6,'Empresa',1,0,'L',true);
$this->Cell(55,6,'Sucursal',1,0,'L',true);
$this->Cell(15,6,'Estado',1,0,'C',true);
$this->Cell(22,6,'Vencimiento',1,0,'C',true);
$this->Cell(20,6,'Doc.',1,1,'C',true);
}
function Footer() {
$this->SetY(-15);
$this->SetFont('Arial','I',8);
$this->Cell(0,10,'Página '.$this->PageNo().'/{nb}',0,0,'C');
}
function Row($data) {
$this->SetFont('Arial','',7);
$this->Cell(8,6,$data['id'],1,0,'C');
$this->Cell(60,6,utf8_decode($data['apellido'].', '.$data['nombre']),1,0,'L');
$this->Cell(20,6,$data['dni'],1,0,'C');
$this->Cell(25,6,utf8_decode($data['cargo']??''),1,0,'L');
$this->Cell(55,6,utf8_decode($data['empresa_nombre']??'N/A'),1,0,'L');
$this->Cell(55,6,utf8_decode($data['sucursal_nombre']??'N/A'),1,0,'L');
$estado = $data['activo'] ? 'Activo' : 'Inactivo';
$this->Cell(15,6,$estado,1,0,'C');
$venc = $data['fecha_vencimiento'] ? date('d/m/Y',strtotime($data['fecha_vencimiento'])) : '-';
$this->Cell(22,6,$venc,1,0,'C');
$doc = ucfirst($data['estado_documentacion']??'pendiente');
$this->Cell(20,6,$doc,1,1,'C');
}
}
$pdf = new PDF_Personal_Lote();
$pdf->AliasNbPages();
$pdf->AddPage('L','A4');
$pdf->SetFont('Arial','',7);
// Datos
foreach ($personal_list as $persona) {
$pdf->Row([
'id' => $persona['id'],
'apellido' => $persona['apellido'],
'nombre' => $persona['nombre'],
'dni' => $persona['dni'],
'cargo' => $persona['cargo'],
'empresa_nombre' => $persona['empresa_nombre'],
'sucursal_nombre' => $persona['sucursal_nombre'],
'activo' => $persona['activo'],
'fecha_vencimiento' => $persona['fecha_vencimiento'],
'estado_documentacion' => $persona['estado_documentacion']
]);
}
// Total
$pdf->Ln(5);
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,6,'Total de registros: '.count($personal_list),0,1,'R');
// Nombre de archivo
$nombre_archivo = 'Listado_Personal_Seleccionado_'.date('Ymd_His').'.pdf';
$pdf->Output('D', $nombre_archivo);
exit;
} catch(PDOException $e) {
error_log("Error al exportar PDF de lote: " . $e->getMessage());
die('Error al generar el PDF: ' . $e->getMessage());
}
}
// 4. OBTENER LOG DE AUDITORÍA (AJAX) - MEJORADO CON FILTROS
if (isset($_GET['action']) && $_GET['action'] === 'get_audit_log') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$tabla = $_GET['tabla'] ?? '';
$registro_id = $_GET['registro_id'] ?? 0;
$accion_filtro = $_GET['accion'] ?? '';
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
if (empty($tabla) || empty($registro_id)) {
echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
exit;
}
try {
$conn = getDBConnection();
$sql = "
SELECT a.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre,
p.email as usuario_email
FROM auditoria a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE a.tabla = :tabla AND a.registro_id = :registro_id
";
$params = ['tabla' => $tabla, 'registro_id' => $registro_id];
if (!empty($accion_filtro)) {
$sql .= " AND a.accion = :accion";
$params['accion'] = $accion_filtro;
}
if (!empty($desde)) {
$sql .= " AND DATE(a.created_at) >= :desde";
$params['desde'] = $desde;
}
if (!empty($hasta)) {
$sql .= " AND DATE(a.created_at) <= :hasta";
$params['hasta'] = $hasta;
}
$sql .= " ORDER BY a.created_at DESC LIMIT 100";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'logs' => $logs]);
} catch(PDOException $e) {
error_log("Error al cargar auditoría: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al cargar auditoría']);
}
exit;
}
// 5. EXPORTAR AUDITORÍA (CSV/JSON)
if (isset($_GET['action']) && $_GET['action'] === 'exportar_auditoria') {
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
die('No autorizado');
}
$tabla = $_GET['tabla'] ?? '';
$registro_id = $_GET['registro_id'] ?? 0;
$formato = $_GET['formato'] ?? 'csv';
try {
$conn = getDBConnection();
$stmt = $conn->prepare("
SELECT a.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre
FROM auditoria a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE a.tabla = :tabla AND a.registro_id = :registro_id
ORDER BY a.created_at DESC
");
$stmt->execute(['tabla' => $tabla, 'registro_id' => $registro_id]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($formato === 'csv') {
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=auditoria_personal_' . $registro_id . '_' . date('Y-m-d_His') . '.csv');
$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Fecha', 'Usuario', 'Acción', 'IP', 'Risk Score', 'Detalles']);
foreach ($logs as $log) {
fputcsv($output, [
$log['id'],
$log['created_at'],
$log['usuario_nombre'] ?? 'Sistema',
$log['accion'],
$log['ip_address'] ?? 'N/A',
$log['risk_score'] ?? 0,
$log['detalles'] ?? ''
]);
}
fclose($output);
} elseif ($formato === 'json') {
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=auditoria_personal_' . $registro_id . '_' . date('Y-m-d_His') . '.json');
echo json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
exit;
} catch(Exception $e) {
die('Error: ' . $e->getMessage());
}
}
// 7. APROBAR DOCUMENTACIÓN (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'aprobar_documentacion') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$personal_id = isset($_POST['personal_id']) ? (int)$_POST['personal_id'] : 0;
if ($personal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID inválido']);
exit;
}
try {
$conn = getDBConnection();
$user = $auth->getCurrentUser();
$stmt = $conn->prepare("
UPDATE personal SET
estado_documentacion = 'aprobada',
fecha_revision_documentacion = NOW(),
revisado_por_usuario_id = :usuario_id,
updated_at = NOW()
WHERE id = :id
");
$stmt->execute([':usuario_id' => $user['id'], ':id' => $personal_id]);
$detalles = [
'accion' => 'APROBAR_DOCUMENTACION',
'tabla' => 'personal',
'registro_id' => $personal_id,
'estado_nuevo' => 'aprobada',
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'APROBAR_DOCUMENTACION', 'personal', $personal_id, $detalles);
echo json_encode(['success' => true, 'message' => 'Documentación aprobada correctamente']);
} catch(PDOException $e) {
error_log("Error al aprobar documentación: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al aprobar documentación']);
}
exit;
}
// 8. RECHAZAR DOCUMENTACIÓN (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'rechazar_documentacion') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$personal_id = isset($_POST['personal_id']) ? (int)$_POST['personal_id'] : 0;
$motivo_rechazo = isset($_POST['motivo_rechazo']) ? sanitizeInput($_POST['motivo_rechazo']) : '';
if ($personal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID inválido']);
exit;
}
try {
$conn = getDBConnection();
$user = $auth->getCurrentUser();
$stmt_personal = $conn->prepare("SELECT observaciones FROM personal WHERE id = :id");
$stmt_personal->execute([':id' => $personal_id]);
$personal_data = $stmt_personal->fetch();
$observaciones_actuales = $personal_data['observaciones'] ?? '';
$nueva_observacion = $observaciones_actuales . "
[RECHAZO: " . date('Y-m-d H:i:s') . "] " . $motivo_rechazo;
$stmt = $conn->prepare("
UPDATE personal SET
estado_documentacion = 'rechazada',
fecha_revision_documentacion = NOW(),
revisado_por_usuario_id = :usuario_id,
observaciones = :observaciones,
updated_at = NOW()
WHERE id = :id
");
$stmt->execute([
':usuario_id' => $user['id'],
':id' => $personal_id,
':observaciones' => $nueva_observacion
]);
$detalles = [
'accion' => 'RECHAZAR_DOCUMENTACION',
'tabla' => 'personal',
'registro_id' => $personal_id,
'estado_nuevo' => 'rechazada',
'motivo' => $motivo_rechazo,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'RECHAZAR_DOCUMENTACION', 'personal', $personal_id, $detalles);
echo json_encode(['success' => true, 'message' => 'Documentación rechazada correctamente']);
} catch(PDOException $e) {
error_log("Error al rechazar documentación: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al rechazar documentación']);
}
exit;
}
// 1. CAMBIAR ESTADO ACTIVO/INACTIVO (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'cambiar_estado') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$personal_id = isset($_POST['personal_id']) ? (int)$_POST['personal_id'] : 0;
$nuevo_estado = isset($_POST['activo']) ? (int)$_POST['activo'] : 0;
if ($personal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID inválido']);
exit;
}
try {
$conn = getDBConnection();
$user = $auth->getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$datos_antiguos = $stmt->fetch();
$stmt = $conn->prepare("UPDATE personal SET activo = :activo, updated_at = NOW() WHERE id = :id");
$stmt->execute(['activo' => $nuevo_estado, 'id' => $personal_id]);
$detalles = [
'accion' => 'CAMBIO_ESTADO',
'tabla' => 'personal',
'registro_id' => $personal_id,
'estado_anterior' => $datos_antiguos['activo'],
'estado_nuevo' => $nuevo_estado,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'CAMBIO_ESTADO', 'personal', $personal_id, $detalles);
echo json_encode(['success' => true, 'message' => 'Estado actualizado correctamente']);
} catch(PDOException $e) {
error_log("Error al cambiar estado: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al actualizar estado']);
}
exit;
}
// 2. CAMBIAR ESTADO BAJA (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'cambiar_baja') {
header('Content-Type: application/json');
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
echo json_encode(['success' => false, 'message' => 'No autorizado']);
exit;
}
$personal_id = isset($_POST['personal_id']) ? (int)$_POST['personal_id'] : 0;
$nueva_baja = isset($_POST['baja']) ? (int)$_POST['baja'] : 0;
if ($personal_id <= 0) {
echo json_encode(['success' => false, 'message' => 'ID inválido']);
exit;
}
try {
$conn = getDBConnection();
$user = $auth->getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$datos_antiguos = $stmt->fetch();
$stmt = $conn->prepare("UPDATE personal SET baja = :baja, updated_at = NOW() WHERE id = :id");
$stmt->execute(['baja' => $nueva_baja, 'id' => $personal_id]);
$detalles = [
'accion' => 'CAMBIO_BAJA',
'tabla' => 'personal',
'registro_id' => $personal_id,
'baja_anterior' => $datos_antiguos['baja'],
'baja_nueva' => $nueva_baja,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'CAMBIO_BAJA', 'personal', $personal_id, $detalles);
echo json_encode(['success' => true, 'message' => 'Estado de baja actualizado correctamente']);
} catch(PDOException $e) {
error_log("Error al cambiar baja: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al actualizar baja']);
}
exit;
}
// 5. GENERAR CREDENCIAL INDIVIDUAL (PDF)
if (isset($_GET['action']) && $_GET['action'] === 'generar_qr_personal') {
require_once '../config/auth.php';
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga'))) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2>');
}
ob_clean();
$conn = getDBConnection();
$personal_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$personal_id) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ ID de personal no válido</h2>');
}
try {
$stmt = $conn->prepare("
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.id = :id
");
$stmt->execute(['id' => $personal_id]);
$personal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$personal) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Personal no encontrado</h2>');
}
} catch(PDOException $e) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Error al cargar los datos del personal</h2>');
}
try {
$stmt = $conn->query("SELECT jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path FROM config_credenciales WHERE id = 1");
$config_jefe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$config_jefe) {
$config_jefe = ['jefe_apellido' => 'Apellido', 'jefe_nombre' => 'Nombre', 'jefe_gerarquia' => 'Jerarquía del Jefe', 'firma_path' => null];
}
} catch(PDOException $e) {
$config_jefe = ['jefe_apellido' => 'Apellido', 'jefe_nombre' => 'Nombre', 'jefe_gerarquia' => 'Jerarquía del Jefe', 'firma_path' => null];
}
$firma_path = null;
$escudo_path = '../uploads/fondos_credenciales/escudo.png';
$firma_valida = false;
$escudo_valido = false;
if (!empty($config_jefe['firma_path']) && file_exists('../uploads/firmas_jefe/' . $config_jefe['firma_path'])) {
$firma_path = '../uploads/firmas_jefe/' . $config_jefe['firma_path'];
$info = @getimagesize($firma_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) $firma_valida = true;
}
if (file_exists($escudo_path)) {
$info = @getimagesize($escudo_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) $escudo_valido = true;
}
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
class PDF_Credencial extends FPDF {
function Header() {}
function Footer() {}
}
$pdf = new PDF_Credencial();
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage('L', array(85.6, 54));
$fondo_frente = '../uploads/fondos_credenciales/fondo_frente.jpg';
if (file_exists($fondo_frente)) $pdf->Image($fondo_frente, 0, 0, 85.6, 54, 'JPEG');
$watermark_path = '../uploads/fondos_credenciales/logo-policia-chubut.png';
if (file_exists($watermark_path)) $pdf->Image($watermark_path, 42.8, 10, 25, 25, 'PNG');
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(0, 2);
$pdf->Cell(85.6, 6, 'EMPRESA DE SEGURIDAD', 0, 0, 'C');
$foto_path = '../uploads/fotos_personal/' . $personal['foto'];
if (!empty($personal['foto']) && file_exists($foto_path)) {
$pdf->Image($foto_path, 4, 10, 28, 32, 'JPEG');
} else {
$pdf->SetDrawColor(200, 200, 200);
$pdf->Rect(4, 10, 28, 32);
$pdf->SetXY(4, 19);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(200, 200, 200);
$pdf->Cell(28, 8, 'SIN', 0, 0, 'C');
$pdf->SetXY(4, 24);
$pdf->Cell(28, 8, 'FOTO', 0, 0, 'C');
$pdf->SetTextColor(0, 0, 0);
}
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(4, 43);
$pdf->Cell(28, 4, 'Legajo:', 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetXY(4, 47);
$pdf->Cell(28, 6, $personal['dni'], 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(34, 12);
$pdf->Cell(50, 5, 'Puesto: ' . strtoupper(utf8_decode($personal['cargo'] ?? 'SIN CARGO')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 17);
$pdf->Cell(50, 5, 'Apellido: ' . strtoupper(utf8_decode($personal['apellido'] ?? '')), 0, 0, 'L');
$pdf->SetXY(34, 22);
$pdf->Cell(50, 5, 'Nombre: ' . strtoupper(utf8_decode($personal['nombre'] ?? '')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 27);
$pdf->Cell(50, 5, 'Empresa: ' . strtoupper(utf8_decode($personal['empresa_nombre'] ?? 'SIN EMPRESA')), 0, 0, 'L');
$fecha_inicio = $personal['fecha_ingreso'] ? date('d/m/Y', strtotime($personal['fecha_ingreso'])) : date('d/m/Y');
$fecha_fin = $personal['fecha_vencimiento'] ? date('d/m/Y', strtotime($personal['fecha_vencimiento'])) : date('d/m/Y', strtotime('+1 year', strtotime($fecha_inicio)));
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 32);
$pdf->Cell(50, 5, 'Vigencia: ' . $fecha_inicio . ' al ' . $fecha_fin, 0, 0, 'L');
$bloque_y = 36;
$margen_derecho = -5;
$ancho_escudo = 12;
$ancho_firma = 37.5;
$alto_firma = 10;
$gap = 2;
$ancho_total_bloque = $ancho_escudo + $gap + $ancho_firma;
$bloque_x = 85.6 - $margen_derecho - $ancho_total_bloque;
if ($escudo_valido) $pdf->Image($escudo_path, $bloque_x, $bloque_y, $ancho_escudo, $ancho_escudo, 'PNG');
$firma_x = $bloque_x + $ancho_escudo + $gap;
if ($firma_valida) $pdf->Image($firma_path, $firma_x, $bloque_y, $ancho_firma, $alto_firma, 'PNG');
$texto_y = $bloque_y + $alto_firma + 0.8;
$line_height = 1.6;
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetXY(25, $texto_y);
$pdf->Cell(85.6, $line_height, strtoupper(utf8_decode($config_jefe['jefe_apellido'] . ', ' . $config_jefe['jefe_nombre'])), 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetXY(25, $texto_y + $line_height);
$pdf->Cell(85.6, $line_height, strtoupper(utf8_decode($config_jefe['jefe_gerarquia'])), 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetXY(25, $texto_y + (2 * $line_height));
$pdf->Cell(85.6, $line_height, 'JEFE AREA INVESTIGACION (A-2)', 0, 0, 'C');
$pdf->AddPage('L', array(85.6, 54));
$fondo_reverso = '../uploads/fondos_credenciales/fondo_reverso.jpg';
if (file_exists($fondo_reverso)) $pdf->Image($fondo_reverso, 0, 0, 85.6, 54, 'JPEG');
$pdf->SetXY(0, 2);
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(85.6, 6, 'EMPRESA DE SEGURIDAD', 0, 0, 'C');
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
// ✅ TOKEN DE SEGURIDAD CON EXPIRACIÓN DE 380 DÍAS
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$expiracion_timestamp = time() + (380 * 24 * 60 * 60); // 380 días en segundos
$payload_token = $personal_id . '|' . $expiracion_timestamp;
$security_token = hash_hmac('sha256', $payload_token, $secret_key);
$verify_url = $protocol . $domain . '/agencia_seguridad/verificar_personal.php?id=' . $personal_id . '&exp=' . $expiracion_timestamp . '&token=' . $security_token;
$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($verify_url);
$qr_temp = sys_get_temp_dir() . '/qr_credencial_' . $personal_id . '_' . time() . '.png';
file_put_contents($qr_temp, file_get_contents($qr_api));
if (file_exists($qr_temp)) {
$pdf->Image($qr_temp, 26.8, 8, 32, 32);
unlink($qr_temp);
}
$pdf->SetXY(0, 42);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(85.6, 5, 'AREA INVESTIGACIONES(A2)', 0, 0, 'C');
$pdf->SetXY(0, 47);
$pdf->SetFont('Arial', '', 6);
$pdf->Cell(85.6, 4, utf8_decode('De hallarse esta tarjeta, se agradecerá su devolución'), 0, 0, 'C');
$pdf->SetXY(0, 50.5);
$pdf->Cell(85.6, 4, utf8_decode('a la Comisaría o Dependencia policial más próxima.'), 0, 0, 'C');
$nombre_archivo = 'Credencial_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $personal['nombre'] . '_' . $personal['apellido']) . '_' . $personal['dni'] . '.pdf';
$pdf->Output('D', $nombre_archivo);
exit;
}
// 6. GENERAR CREDENCIALES POR LOTE (PDF) - MODIFICADO PARA MÚLTIPLES PDFs SEGÚN TAMAÑO DE LOTE
if (isset($_POST['action']) && $_POST['action'] === 'generar_qr_lote') {
require_once '../config/auth.php';
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga'))) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Acceso denegado</h2>');
}
ob_clean();
$conn = getDBConnection();
// ✅ TAMAÑO DE LOTE FIJO EN 40 CREDENCIALES POR PDF (REQUERIMIENTO)
$tamano_lote = 40;
$personal_ids = isset($_POST['personal_ids']) && is_array($_POST['personal_ids'])
? array_map('intval', $_POST['personal_ids']) : [];
if (empty($personal_ids)) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ No se seleccionó personal para generar credenciales</h2>');
}
// ✅ Máximo ajustado: 5 lotes de 40 = 200 credenciales máximo por solicitud
$maximo_personal = $tamano_lote * 5;
if (count($personal_ids) > $maximo_personal) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ Máximo ' . $maximo_personal . ' credenciales por solicitud (5 lotes de ' . $tamano_lote . ')</h2>');
}
$filtro_empresa = isset($_POST['filtro_empresa']) && !empty($_POST['filtro_empresa']) ? (int)$_POST['filtro_empresa'] : 0;
$filtro_sucursal = isset($_POST['filtro_sucursal']) && !empty($_POST['filtro_sucursal']) ? (int)$_POST['filtro_sucursal'] : 0;
$filtro_activo = isset($_POST['filtro_activo']) && $_POST['filtro_activo'] !== '' ? $_POST['filtro_activo'] : '';
$filtro_vencimiento = isset($_POST['filtro_vencimiento']) && !empty($_POST['filtro_vencimiento']) ? $_POST['filtro_vencimiento'] : '';
$filtro_revalidacion = isset($_POST['filtro_revalidacion']) && !empty($_POST['filtro_revalidacion']) ? $_POST['filtro_revalidacion'] : '';
$filtro_credencial = isset($_POST['filtro_credencial']) && !empty($_POST['filtro_credencial']) ? $_POST['filtro_credencial'] : '';
// ✅ NUEVO: FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (PRIMERA PALABRA) EN GENERACIÓN DE LOTE
$filtro_letra_desde = isset($_POST['filtro_letra_desde']) && !empty($_POST['filtro_letra_desde']) ? strtoupper(sanitizeInput($_POST['filtro_letra_desde'])) : 'A';
$filtro_letra_hasta = isset($_POST['filtro_letra_hasta']) && !empty($_POST['filtro_letra_hasta']) ? strtoupper(sanitizeInput($_POST['filtro_letra_hasta'])) : 'Z';
$personal_ids_filtrados = [];
foreach ($personal_ids as $id) {
$stmt = $conn->prepare("SELECT * FROM personal WHERE id = :id");
$stmt->execute(['id' => $id]);
$personal_check = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$personal_check) continue;
if ($filtro_empresa > 0 && $personal_check['empresa_id'] != $filtro_empresa) continue;
if ($filtro_sucursal > 0 && $personal_check['sucursal_id'] != $filtro_sucursal) continue;
if ($filtro_activo !== '' && $personal_check['activo'] != $filtro_activo) continue;
if ($filtro_vencimiento !== '') {
$fecha_venc = $personal_check['fecha_vencimiento'] ? new DateTime($personal_check['fecha_vencimiento']) : null;
$hoy = new DateTime();
if ($filtro_vencimiento === 'vigente' && (!$fecha_venc || $hoy > $fecha_venc)) continue;
if ($filtro_vencimiento === 'vencido' && (!$fecha_venc || $hoy <= $fecha_venc)) continue;
if ($filtro_vencimiento === 'proximo') {
if (!$fecha_venc) continue;
$diff = $hoy->diff($fecha_venc);
if ($hoy > $fecha_venc || $diff->days > 30) continue;
}
}
if ($filtro_revalidacion !== '') {
$fecha_reval = $personal_check['fecha_revalidacion'] ? new DateTime($personal_check['fecha_revalidacion']) : null;
$hoy = new DateTime();
if ($filtro_revalidacion === 'vigente' && (!$fecha_reval || $hoy > $fecha_reval)) continue;
if ($filtro_revalidacion === 'vencido' && (!$fecha_reval || $hoy <= $fecha_reval)) continue;
if ($filtro_revalidacion === 'proximo') {
if (!$fecha_reval) continue;
$diff = $hoy->diff($fecha_reval);
if ($hoy > $fecha_reval || $diff->days > 30) continue;
}
}
if ($filtro_credencial !== '') {
if ($filtro_credencial === 'pagada' && empty($personal_check['pago_credencial'])) continue;
if ($filtro_credencial === 'pendiente' && !empty($personal_check['pago_credencial'])) continue;
}
// ✅ APLICAR FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (primera palabra de apellido) EN GENERACIÓN DE LOTE - CORREGIDO: SOLO FILTRO EN APELLIDO CON PHP
if ($filtro_letra_desde !== 'A' || $filtro_letra_hasta !== 'Z') {
$apellido_palabras = explode(' ', trim($personal_check['apellido']));
$primera_palabra_apellido = strtoupper(substr($apellido_palabras[0], 0, 1));
if (!($primera_palabra_apellido >= $filtro_letra_desde && $primera_palabra_apellido <= $filtro_letra_hasta)) continue;
}
$personal_ids_filtrados[] = $id;
}
$personal_ids = $personal_ids_filtrados;
if (empty($personal_ids)) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ No hay personal que cumpla con los filtros seleccionados</h2>');
}
try {
$stmt = $conn->query("SELECT jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path FROM config_credenciales WHERE id = 1");
$config_jefe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$config_jefe) {
$config_jefe = ['jefe_apellido' => 'Apellido', 'jefe_nombre' => 'Nombre', 'jefe_gerarquia' => 'Jerarquía del Jefe', 'firma_path' => null];
}
} catch(PDOException $e) {
$config_jefe = ['jefe_apellido' => 'Apellido', 'jefe_nombre' => 'Nombre', 'jefe_gerarquia' => 'Jerarquía del Jefe', 'firma_path' => null];
}
$firma_path = null;
$escudo_path = '../uploads/fondos_credenciales/escudo.png';
$firma_valida = false;
$escudo_valido = false;
if (!empty($config_jefe['firma_path']) && file_exists('../uploads/firmas_jefe/' . $config_jefe['firma_path'])) {
$firma_path = '../uploads/firmas_jefe/' . $config_jefe['firma_path'];
$info = @getimagesize($firma_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) $firma_valida = true;
}
if (file_exists($escudo_path)) {
$info = @getimagesize($escudo_path);
if ($info !== false && $info[2] === IMAGETYPE_PNG) $escudo_valido = true;
}
if (!file_exists('../vendor/fpdf/fpdf.php')) {
die('<h2 style="color:#e74c3c;text-align:center;padding:30px">⚠️ FPDF no instalado</h2>');
}
require_once '../vendor/fpdf/fpdf.php';
class PDF_Credencial_Lote extends FPDF {
function Header() {}
function Footer() {}
}
// ✅ USAR TAMAÑO DE LOTE FIJO EN 40 PARA DIVIDIR EN MÚLTIPLES PDFs
$limite_por_pdf = $tamano_lote;
$chunks = array_chunk($personal_ids, $limite_por_pdf);
$total_lotes = count($chunks);
$credenciales_generadas_total = 0;
$credenciales_fallidas_total = 0;
// ✅ GENERAR UN PDF POR CADA LOTE (CHUNK) - SIN COMPRIMIR EN ZIP
foreach ($chunks as $index => $chunk_ids) {
$pdf = new PDF_Credencial_Lote();
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$credenciales_en_este_lote = 0;
foreach ($chunk_ids as $personal_id) {
try {
$stmt = $conn->prepare("
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.id = :id
");
$stmt->execute(['id' => $personal_id]);
$personal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$personal) {
$credenciales_fallidas_total++;
continue;
}
$pdf->AddPage('L', array(85.6, 54));
$fondo_frente = '../uploads/fondos_credenciales/fondo_frente.jpg';
if (file_exists($fondo_frente)) $pdf->Image($fondo_frente, 0, 0, 85.6, 54, 'JPEG');
$watermark_path = '../uploads/fondos_credenciales/logo-policia-chubut.png';
if (file_exists($watermark_path)) $pdf->Image($watermark_path, 42.8, 10, 25, 25, 'PNG');
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(0, 2);
$pdf->Cell(85.6, 6, 'EMPRESA DE SEGURIDAD', 0, 0, 'C');
$foto_path = '../uploads/fotos_personal/' . $personal['foto'];
if (!empty($personal['foto']) && file_exists($foto_path)) {
$pdf->Image($foto_path, 4, 10, 28, 32, 'JPEG');
} else {
$pdf->SetDrawColor(200, 200, 200);
$pdf->Rect(4, 10, 28, 32);
$pdf->SetXY(4, 19);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(200, 200, 200);
$pdf->Cell(28, 8, 'SIN', 0, 0, 'C');
$pdf->SetXY(4, 24);
$pdf->Cell(28, 8, 'FOTO', 0, 0, 'C');
$pdf->SetTextColor(0, 0, 0);
}
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(4, 43);
$pdf->Cell(28, 4, 'Legajo:', 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetXY(4, 47);
$pdf->Cell(28, 6, $personal['dni'], 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetXY(34, 12);
$pdf->Cell(50, 5, 'Puesto: ' . strtoupper(utf8_decode($personal['cargo'] ?? 'SIN CARGO')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 17);
$pdf->Cell(50, 5, 'Apellido: ' . strtoupper(utf8_decode($personal['apellido'] ?? '')), 0, 0, 'L');
$pdf->SetXY(34, 22);
$pdf->Cell(50, 5, 'Nombre: ' . strtoupper(utf8_decode($personal['nombre'] ?? '')), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 27);
$pdf->Cell(50, 5, 'Empresa: ' . strtoupper(utf8_decode($personal['empresa_nombre'] ?? 'SIN EMPRESA')), 0, 0, 'L');
$fecha_inicio = $personal['fecha_ingreso'] ? date('d/m/Y', strtotime($personal['fecha_ingreso'])) : date('d/m/Y');
$fecha_fin = $personal['fecha_vencimiento'] ? date('d/m/Y', strtotime($personal['fecha_vencimiento'])) : date('d/m/Y', strtotime('+1 year', strtotime($fecha_inicio)));
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(34, 32);
$pdf->Cell(50, 5, 'Vigencia: ' . $fecha_inicio . ' al ' . $fecha_fin, 0, 0, 'L');
$bloque_y = 36;
$margen_derecho = -5;
$ancho_escudo = 12;
$ancho_firma = 37.5;
$alto_firma = 10;
$gap = 2;
$ancho_total_bloque = $ancho_escudo + $gap + $ancho_firma;
$bloque_x = 85.6 - $margen_derecho - $ancho_total_bloque;
if ($escudo_valido) $pdf->Image($escudo_path, $bloque_x, $bloque_y, $ancho_escudo, $ancho_escudo, 'PNG');
$firma_x = $bloque_x + $ancho_escudo + $gap;
if ($firma_valida) $pdf->Image($firma_path, $firma_x, $bloque_y, $ancho_firma, $alto_firma, 'PNG');
$texto_y = $bloque_y + $alto_firma + 0.8;
$line_height = 1.6;
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetXY(25, $texto_y);
$pdf->Cell(85.6, $line_height, strtoupper(utf8_decode($config_jefe['jefe_apellido'] . ', ' . $config_jefe['jefe_nombre'])), 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetXY(25, $texto_y + $line_height);
$pdf->Cell(85.6, $line_height, strtoupper(utf8_decode($config_jefe['jefe_gerarquia'])), 0, 0, 'C');
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetXY(25, $texto_y + (2 * $line_height));
$pdf->Cell(85.6, $line_height, 'JEFE AREA INVESTIGACION (A-2)', 0, 0, 'C');
$pdf->AddPage('L', array(85.6, 54));
$fondo_reverso = '../uploads/fondos_credenciales/fondo_reverso.jpg';
if (file_exists($fondo_reverso)) $pdf->Image($fondo_reverso, 0, 0, 85.6, 54, 'JPEG');
$pdf->SetXY(0, 2);
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(85.6, 6, 'EMPRESA DE SEGURIDAD', 0, 0, 'C');
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$domain = $_SERVER['HTTP_HOST'];
// ✅ TOKEN DE SEGURIDAD CON EXPIRACIÓN DE 380 DÍAS
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$expiracion_timestamp = time() + (380 * 24 * 60 * 60); // 380 días en segundos
$payload_token = $personal_id . '|' . $expiracion_timestamp;
$security_token = hash_hmac('sha256', $payload_token, $secret_key);
$verify_url = $protocol . $domain . '/agencia_seguridad/verificar_personal.php?id=' . $personal_id . '&exp=' . $expiracion_timestamp . '&token=' . $security_token;
$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($verify_url);
$qr_temp = sys_get_temp_dir() . '/qr_credencial_' . $personal_id . '_' . time() . '.png';
$qr_content = @file_get_contents($qr_api);
if ($qr_content) {
file_put_contents($qr_temp, $qr_content);
if (file_exists($qr_temp)) {
$pdf->Image($qr_temp, 26.8, 8, 32, 32);
unlink($qr_temp);
}
}
$pdf->SetXY(0, 42);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(85.6, 5, 'AREA INVESTIGACIONES(A2)', 0, 0, 'C');
$pdf->SetXY(0, 47);
$pdf->SetFont('Arial', '', 6);
$pdf->Cell(85.6, 4, utf8_decode('De hallarse esta tarjeta, se agradecerá su devolución'), 0, 0, 'C');
$pdf->SetXY(0, 50.5);
$pdf->Cell(85.6, 4, utf8_decode('a la Comisaría o Dependencia policial más próxima.'), 0, 0, 'C');
$credenciales_en_este_lote++;
$credenciales_generadas_total++;
} catch(Exception $e) {
$credenciales_fallidas_total++;
error_log("Error generando credencial ID {$personal_id}: " . $e->getMessage());
}
}
// ✅ GENERAR Y DESCARGAR CADA PDF INDIVIDUALMENTE POR LOTE
if ($credenciales_en_este_lote > 0) {
$suffix = ($total_lotes > 1) ? '_Parte_' . ($index + 1) : '';
$nombre_archivo = 'Credenciales_Lote_' . date('Ymd_His') . $suffix . '.pdf';
$pdf->Output('D', $nombre_archivo);
// ✅ Si hay más lotes pendientes, pausar para que el usuario descargue cada uno
if ($index < ($total_lotes - 1)) {
echo "<script>
setTimeout(function() {
if(confirm('✅ PDF Parte " . ($index + 1) . " generado.\
\
¿Desea continuar generando la siguiente parte?')) {
window.location.reload();
} else {
window.close();
}
}, 1000);
</script>";
exit;
}
}
}
// ✅ Si llegamos aquí, todos los lotes fueron procesados
if ($credenciales_generadas_total === 0) {
die('<h2 style="color:red;text-align:center;padding:50px">❌ No se pudo generar ninguna credencial</h2>');
}
try {
$user = $auth->getCurrentUser();
$detalles = [
'accion' => 'GENERACION_LOTE',
'tabla' => 'personal',
'registros_afectados' => $credenciales_generadas_total,
'ids_procesados' => $personal_ids,
'lotes_generados' => $total_lotes,
'filtros_aplicados' => [
'empresa' => $filtro_empresa,
'sucursal' => $filtro_sucursal,
'activo' => $filtro_activo,
'vencimiento' => $filtro_vencimiento,
'revalidacion' => $filtro_revalidacion,
'credencial' => $filtro_credencial,
'letra_desde' => $filtro_letra_desde,
'letra_hasta' => $filtro_letra_hasta
],
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'GENERACION_LOTE', 'personal', null, $detalles);
} catch(Exception $e) {
error_log("Error registrando auditoría de lote: " . $e->getMessage());
}
exit;
}
// ==================== FIN DE MANEJO DE ACCIONES ====================
// Función de sanitización
if (!function_exists('sanitizeInput')) {
function sanitizeInput($data) {
return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
}
// ==================== VERIFICAR Y CREAR TABLA AUDITORIA SI NO EXISTE ====================
try {
$conn = getDBConnection();
$stmt = $conn->query("SHOW TABLES LIKE 'auditoria'");
if ($stmt->rowCount() == 0) {
$conn->exec("
CREATE TABLE auditoria (
id INT AUTO_INCREMENT PRIMARY KEY,
usuario_id INT NULL,
accion VARCHAR(100) NOT NULL,
tabla VARCHAR(50) NOT NULL,
registro_id INT NULL,
detalles TEXT NULL,
ip_address VARCHAR(45) NULL,
user_agent TEXT NULL,
request_uri VARCHAR(500) NULL,
request_method VARCHAR(10) NULL,
risk_score INT DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
INDEX idx_usuario (usuario_id),
INDEX idx_tabla (tabla),
INDEX idx_created_at (created_at),
INDEX idx_accion (accion),
INDEX idx_registro (tabla, registro_id),
INDEX idx_risk_score (risk_score),
INDEX idx_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
error_log("Tabla 'auditoria' creada exitosamente");
}
} catch(PDOException $e) {
error_log("Error creando tabla auditoria: " . $e->getMessage());
}
// ==================== VERIFICAR Y ACTUALIZAR ESTRUCTURA DE TABLA PERSONAL ====================
try {
$columns = $conn->query("SHOW COLUMNS FROM personal")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('fecha_revalidacion', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN fecha_revalidacion DATE NULL AFTER fecha_vencimiento");
error_log("Columna 'fecha_revalidacion' agregada a tabla personal");
}
if (!in_array('updated_at', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
error_log("Columna 'updated_at' agregada a tabla personal");
}
if (!in_array('estado_documentacion', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN estado_documentacion ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente' AFTER updated_at");
error_log("Columna 'estado_documentacion' agregada a tabla personal");
}
if (!in_array('fecha_revision_documentacion', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN fecha_revision_documentacion DATETIME NULL AFTER estado_documentacion");
error_log("Columna 'fecha_revision_documentacion' agregada a tabla personal");
}
if (!in_array('revisado_por_usuario_id', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN revisado_por_usuario_id INT NULL AFTER fecha_revision_documentacion");
error_log("Columna 'revisado_por_usuario_id' agregada a tabla personal");
}
if (!in_array('num_nota', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN num_nota VARCHAR(50) NULL AFTER revisado_por_usuario_id");
error_log("Columna 'num_nota' agregada a tabla personal");
}
if (!in_array('antecedentes_provinciales', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN antecedentes_provinciales TINYINT(1) DEFAULT 0 AFTER tiene_penales");
error_log("Columna 'antecedentes_provinciales' agregada a tabla personal");
}
if (!in_array('arma_autorizada', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN arma_autorizada TINYINT(1) DEFAULT 0 AFTER ram");
error_log("Columna 'arma_autorizada' agregada a tabla personal");
}
if (!in_array('estudios_cursados', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN estudios_cursados VARCHAR(100) NULL AFTER pago_credencial");
error_log("Columna 'estudios_cursados' agregada a tabla personal");
}
if (!in_array('clu_numero', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN clu_numero TINYINT(1) DEFAULT 0 AFTER estudios_cursados");
error_log("Columna 'clu_numero' modificada a SI/NO en tabla personal");
}
if (!in_array('inhibicion_bienes', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN inhibicion_bienes TINYINT(1) DEFAULT 0 AFTER clu_numero");
error_log("Columna 'inhibicion_bienes' agregada a tabla personal");
}
if (!in_array('habilitacion_comercial', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN habilitacion_comercial TINYINT(1) DEFAULT 0 AFTER inhibicion_bienes");
error_log("Columna 'habilitacion_comercial' agregada a tabla personal");
}
// ✅ AGREGAR COLUMNA MODALIDAD_CONTRATO SI NO EXISTE
if (!in_array('modalidad_contrato', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN modalidad_contrato VARCHAR(10) NULL AFTER habilitacion_comercial");
error_log("Columna 'modalidad_contrato' agregada a tabla personal");
}
// ✅ AGREGAR COLUMNA FECHA_PAGO_CREDENCIAL SI NO EXISTE
if (!in_array('fecha_pago_credencial', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN fecha_pago_credencial DATE NULL AFTER pago_credencial");
error_log("Columna 'fecha_pago_credencial' agregada a tabla personal");
}
// ✅ AGREGAR COLUMNA EXAMEN_DT SI NO EXISTE
if (!in_array('examen_dt', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN examen_dt TINYINT(1) DEFAULT 0 AFTER modalidad_contrato");
error_log("Columna 'examen_dt' agregada a tabla personal");
}
// ✅ NUEVOS CAMPOS: DATOS PERSONALES Y BAJA
if (!in_array('sexo', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN sexo VARCHAR(20) NULL AFTER apellido");
error_log("Columna 'sexo' agregada a tabla personal");
}
if (!in_array('estado_civil', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN estado_civil VARCHAR(30) NULL AFTER sexo");
error_log("Columna 'estado_civil' agregada a tabla personal");
}
if (!in_array('lugar_nacimiento', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN lugar_nacimiento VARCHAR(100) NULL AFTER estado_civil");
error_log("Columna 'lugar_nacimiento' agregada a tabla personal");
}
if (!in_array('grupo_sanguineo', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN grupo_sanguineo VARCHAR(5) NULL AFTER lugar_nacimiento");
error_log("Columna 'grupo_sanguineo' agregada a tabla personal");
}
if (!in_array('fecha_baja', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN fecha_baja DATE NULL AFTER baja");
error_log("Columna 'fecha_baja' agregada a tabla personal");
}
if (!in_array('nota_baja', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN nota_baja TEXT NULL AFTER fecha_baja");
error_log("Columna 'nota_baja' agregada a tabla personal");
}
if (!in_array('instituto_nombre', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN instituto_nombre VARCHAR(100) NULL AFTER estudios_cursados");
error_log("Columna 'instituto_nombre' agregada a tabla personal");
}
if (!in_array('ano_finalizacion', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN ano_finalizacion YEAR NULL AFTER instituto_nombre");
error_log("Columna 'ano_finalizacion' agregada a tabla personal");
}
if (!in_array('contacto_emergencia_nombre', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN contacto_emergencia_nombre VARCHAR(100) NULL AFTER ano_finalizacion");
error_log("Columna 'contacto_emergencia_nombre' agregada a tabla personal");
}
if (!in_array('contacto_emergencia_telefono', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN contacto_emergencia_telefono VARCHAR(20) NULL AFTER contacto_emergencia_nombre");
error_log("Columna 'contacto_emergencia_telefono' agregada a tabla personal");
}
if (!in_array('contacto_emergencia_parentesco', $columns)) {
$conn->exec("ALTER TABLE personal ADD COLUMN contacto_emergencia_parentesco VARCHAR(50) NULL AFTER contacto_emergencia_telefono");
error_log("Columna 'contacto_emergencia_parentesco' agregada a tabla personal");
}
}
catch(PDOException $e) {
error_log("Error verificando estructura personal: " . $e->getMessage());
}
// Verificar autenticación
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}
$current_page = 'personal';
$page_title = 'Gestión de Personal';
$page_icon = 'fas fa-users';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ==================== SUBIDA DE ARCHIVOS ====================
$target_dir_fotos = "../uploads/fotos_personal/";
$target_dir_pdf = "../uploads/pdf_personal/";
$target_dir_cupones = "../uploads/cupones_credencial/";
$target_dir_uniformes = "../uploads/uniformes/";
if (!file_exists($target_dir_fotos)) mkdir($target_dir_fotos, 0777, true);
if (!file_exists($target_dir_pdf)) mkdir($target_dir_pdf, 0777, true);
if (!file_exists($target_dir_cupones)) mkdir($target_dir_cupones, 0777, true);
if (!file_exists($target_dir_uniformes)) mkdir($target_dir_uniformes, 0777, true);
// ==================== ELIMINAR PERSONAL (CON AUDITORÍA) ====================
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
try {
$personal_id = (int)$_POST['personal_id'];
$stmt = $conn->prepare("SELECT * FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$datos_antiguos = $stmt->fetch();
$stmt = $conn->prepare("SELECT foto, pdf_datos_personales, cupon_pago_credencial, foto_uniforme FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$files = $stmt->fetch();
if (!empty($files['foto']) && file_exists($target_dir_fotos . $files['foto']))
unlink($target_dir_fotos . $files['foto']);
if (!empty($files['pdf_datos_personales']) && file_exists($target_dir_pdf . $files['pdf_datos_personales']))
unlink($target_dir_pdf . $files['pdf_datos_personales']);
if (!empty($files['cupon_pago_credencial']) && file_exists($target_dir_cupones . $files['cupon_pago_credencial']))
unlink($target_dir_cupones . $files['cupon_pago_credencial']);
if (!empty($files['foto_uniforme']) && file_exists($target_dir_uniformes . $files['foto_uniforme']))
unlink($target_dir_uniformes . $files['foto_uniforme']);
$stmt = $conn->prepare("DELETE FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$detalles = [
'accion' => 'ELIMINACION',
'tabla' => 'personal',
'registro_id' => $personal_id,
'datos_eliminados' => $datos_antiguos,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'ELIMINACION', 'personal', $personal_id, $detalles);
echo json_encode(['success' => true, 'message' => 'Personal eliminado correctamente']);
exit;
} catch(PDOException $e) {
error_log("Error al eliminar personal: " . $e->getMessage());
echo json_encode(['success' => false, 'message' => 'Error al eliminar personal']);
exit;
}
}
// ==================== GUARDAR/ACTUALIZAR PERSONAL (CON AUDITORÍA) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_personal'])) {
try {
$personal_id = isset($_POST['personal_id']) && !empty($_POST['personal_id']) ? (int)$_POST['personal_id'] : null;
$datos_antiguos = null;
if ($personal_id) {
$stmt = $conn->prepare("SELECT * FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$datos_antiguos = $stmt->fetch();
}
$empresa_id = (int)$_POST['empresa_id'];
$sucursal_id = (int)$_POST['sucursal_id'];
$nombre = sanitizeInput($_POST['nombre']);
$apellido = sanitizeInput($_POST['apellido']);
$dni = sanitizeInput($_POST['dni']);
$fecha_nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
$domicilio = sanitizeInput($_POST['domicilio']);
$telefono = sanitizeInput($_POST['telefono']);
$email = sanitizeInput($_POST['email']);
$cargo_options = ['ACCIONISTA', 'ADMINISTRATIVO', 'VIGILADOR', 'CHOFER', 'DIRECTOR TECNICO', 'GERENTE GENERAL', 'SUPERVISOR', 'PRESIDENTE DEL DIRECTORIO', 'REPRESENTANTE LEGAL'];
$cargo = in_array($_POST['cargo'], $cargo_options) ? $_POST['cargo'] : null;
$fecha_ingreso = sanitizeInput($_POST['fecha_ingreso']);
$activo = isset($_POST['activo']) ? 1 : 0;
$apto_fisico = isset($_POST['apto_fisico']) ? 1 : 0;
$apto_psicologico = isset($_POST['apto_psicologico']) ? 1 : 0;
$baja = isset($_POST['baja']) ? 1 : 0;
$tiene_certificado = isset($_POST['tiene_certificado']) ? 1 : 0;
$tiene_penales = isset($_POST['tiene_penales']) ? 1 : 0;
$antecedentes_provinciales = isset($_POST['antecedentes_provinciales']) ? 1 : 0;
$arma_autorizada = isset($_POST['arma_autorizada']) ? 1 : 0;
$ram = isset($_POST['ram']) ? 1 : 0;
$pago_credencial = isset($_POST['pago_credencial']) ? 1 : 0;
$fecha_pago_credencial = !empty($_POST['fecha_pago_credencial']) ? $_POST['fecha_pago_credencial'] : null;
$estudios_cursados = sanitizeInput($_POST['estudios_cursados'] ?? '');
$clu_numero = isset($_POST['clu_numero']) ? 1 : 0;
$inhibicion_bienes = isset($_POST['inhibicion_bienes']) ? 1 : 0;
$habilitacion_comercial = isset($_POST['habilitacion_comercial']) ? 1 : 0;
// ✅ CAPTURAR MODALIDAD_CONTRATO DEL POST
$modalidad_contrato = sanitizeInput($_POST['modalidad_contrato'] ?? '');
// ✅ CAPTURAR EXAMEN_DT DEL POST
$examen_dt = isset($_POST['examen_dt']) ? 1 : 0;
$num_nota = sanitizeInput($_POST['num_nota'] ?? '');
$observaciones = sanitizeInput($_POST['observaciones'] ?? '');
$fecha_autorizacion = !empty($_POST['fecha_autorizacion']) ? $_POST['fecha_autorizacion'] : null;
$fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
$fecha_revalidacion = !empty($_POST['fecha_revalidacion']) ? $_POST['fecha_revalidacion'] : null;
// ✅ NUEVOS CAMPOS
$sexo = sanitizeInput($_POST['sexo'] ?? '');
$estado_civil = sanitizeInput($_POST['estado_civil'] ?? '');
$lugar_nacimiento = sanitizeInput($_POST['lugar_nacimiento'] ?? '');
$grupo_sanguineo = sanitizeInput($_POST['grupo_sanguineo'] ?? '');
$fecha_baja = !empty($_POST['fecha_baja']) ? $_POST['fecha_baja'] : null;
$nota_baja = sanitizeInput($_POST['nota_baja'] ?? '');
$instituto_nombre = sanitizeInput($_POST['instituto_nombre'] ?? '');
$ano_finalizacion = !empty($_POST['ano_finalizacion']) ? (int)$_POST['ano_finalizacion'] : null;
$contacto_emergencia_nombre = sanitizeInput($_POST['contacto_emergencia_nombre'] ?? '');
$contacto_emergencia_telefono = sanitizeInput($_POST['contacto_emergencia_telefono'] ?? '');
$contacto_emergencia_parentesco = sanitizeInput($_POST['contacto_emergencia_parentesco'] ?? '');
if (empty($nombre) || empty($apellido) || empty($dni)) {
throw new Exception('Los campos Nombre, Apellido y DNI son obligatorios');
}
$stmt = $conn->prepare("SELECT id FROM personal WHERE dni = :dni AND id != :id");
$stmt->execute(['dni' => $dni, 'id' => $personal_id ?? 0]);
if ($stmt->fetch() && !$personal_id) throw new Exception('El DNI ya está registrado en otro personal');
$stmt_empresa = $conn->prepare("SELECT nombre FROM empresas WHERE id = :id");
$stmt_empresa->execute(['id' => $empresa_id]);
$empresa_data = $stmt_empresa->fetch();
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $empresa_data['nombre']);
$fecha_actual = date('Ymd');
$foto_file = '';
if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['jpg', 'jpeg', 'png'])) throw new Exception('La foto debe ser JPG, JPEG o PNG');
if ($_FILES['foto']['size'] > 2000000) throw new Exception('La foto no debe superar los 2MB');
$image_info = getimagesize($_FILES['foto']['tmp_name']);
if ($image_info === false) {
throw new Exception('El archivo no es una imagen válida');
}
$image_width = $image_info[0];
$image_height = $image_info[1];
if ($image_width !== 285 || $image_height !== 354) {
throw new Exception("La foto debe tener dimensiones exactas de 285x354 píxeles. Dimensiones actuales: {$image_width}x{$image_height}");
}
$new_filename = 'foto_carnet_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $dni . '.' . $file_extension;
$target_file = $target_dir_fotos . $new_filename;
if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_file)) $foto_file = $new_filename;
else throw new Exception('Error al subir la foto');
}
if (empty($foto_file) && $personal_id) {
$stmt = $conn->prepare("SELECT foto FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$existing = $stmt->fetch();
$foto_file = $existing['foto'] ?? '';
}
$foto_uniforme_file = '';
if (isset($_FILES['foto_uniforme']) && $_FILES['foto_uniforme']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['foto_uniforme']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['jpg', 'jpeg', 'png'])) throw new Exception('La foto de uniforme debe ser JPG, JPEG o PNG');
if ($_FILES['foto_uniforme']['size'] > 2000000) throw new Exception('La foto de uniforme no debe superar los 2MB');
$image_info = getimagesize($_FILES['foto_uniforme']['tmp_name']);
if ($image_info === false) {
throw new Exception('El archivo no es una imagen válida');
}
$new_filename = 'foto_uniforme_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $dni . '.' . $file_extension;
$target_file = $target_dir_uniformes . $new_filename;
if (move_uploaded_file($_FILES['foto_uniforme']['tmp_name'], $target_file)) $foto_uniforme_file = $new_filename;
else throw new Exception('Error al subir la foto de uniforme');
}
if (empty($foto_uniforme_file) && $personal_id) {
$stmt = $conn->prepare("SELECT foto_uniforme FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$existing = $stmt->fetch();
$foto_uniforme_file = $existing['foto_uniforme'] ?? '';
}
$pdf_file = '';
if (isset($_FILES['pdf_datos_personales']) && $_FILES['pdf_datos_personales']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['pdf_datos_personales']['name'], PATHINFO_EXTENSION));
if ($file_extension !== 'pdf') throw new Exception('El archivo debe ser PDF');
if ($_FILES['pdf_datos_personales']['size'] > 5000000) throw new Exception('El PDF no debe superar los 5MB');
$new_filename = 'datos_personales_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $dni . '.pdf';
$target_file = $target_dir_pdf . $new_filename;
if (move_uploaded_file($_FILES['pdf_datos_personales']['tmp_name'], $target_file)) $pdf_file = $new_filename;
else throw new Exception('Error al subir el PDF');
}
if (empty($pdf_file) && $personal_id) {
$stmt = $conn->prepare("SELECT pdf_datos_personales FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$existing = $stmt->fetch();
$pdf_file = $existing['pdf_datos_personales'] ?? '';
}
$cupon_pago_file = '';
if (isset($_FILES['cupon_pago_credencial']) && $_FILES['cupon_pago_credencial']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['cupon_pago_credencial']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['pdf', 'jpg', 'jpeg', 'png'])) throw new Exception('El cupón debe ser PDF, JPG o PNG');
if ($_FILES['cupon_pago_credencial']['size'] > 5000000) throw new Exception('El cupón no debe superar los 5MB');
$new_filename = 'cupon_credencial_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $dni . '.' . $file_extension;
$target_file = $target_dir_cupones . $new_filename;
if (move_uploaded_file($_FILES['cupon_pago_credencial']['tmp_name'], $target_file)) $cupon_pago_file = $new_filename;
else throw new Exception('Error al subir el cupón');
}
if (empty($cupon_pago_file) && $personal_id) {
$stmt = $conn->prepare("SELECT cupon_pago_credencial FROM personal WHERE id = :id");
$stmt->execute(['id' => $personal_id]);
$existing = $stmt->fetch();
$cupon_pago_file = $existing['cupon_pago_credencial'] ?? '';
}
$datos_nuevos = [
'empresa_id' => $empresa_id, 'sucursal_id' => $sucursal_id, 'nombre' => $nombre, 'apellido' => $apellido,
'dni' => $dni, 'fecha_nacimiento' => $fecha_nacimiento, 'domicilio' => $domicilio, 'telefono' => $telefono,
'email' => $email, 'cargo' => $cargo, 'fecha_ingreso' => $fecha_ingreso, 'foto' => $foto_file,
'foto_uniforme' => $foto_uniforme_file, 'pdf_datos_personales' => $pdf_file, 'cupon_pago_credencial' => $cupon_pago_file,
'activo' => $activo, 'apto_fisico' => $apto_fisico, 'apto_psicologico' => $apto_psicologico, 'baja' => $baja,
'tiene_certificado' => $tiene_certificado, 'tiene_penales' => $tiene_penales, 'antecedentes_provinciales' => $antecedentes_provinciales,
'arma_autorizada' => $arma_autorizada, 'ram' => $ram, 'pago_credencial' => $pago_credencial,
'fecha_pago_credencial' => $fecha_pago_credencial,
'estudios_cursados' => $estudios_cursados, 'clu_numero' => $clu_numero, 'inhibicion_bienes' => $inhibicion_bienes,
'habilitacion_comercial' => $habilitacion_comercial,
'modalidad_contrato' => $modalidad_contrato,
'examen_dt' => $examen_dt,
'num_nota' => $num_nota,
'observaciones' => $observaciones, 'fecha_autorizacion' => $fecha_autorizacion, 'fecha_vencimiento' => $fecha_vencimiento, 'fecha_revalidacion' => $fecha_revalidacion,
'sexo' => $sexo, 'estado_civil' => $estado_civil, 'lugar_nacimiento' => $lugar_nacimiento, 'grupo_sanguineo' => $grupo_sanguineo,
'fecha_baja' => $fecha_baja, 'nota_baja' => $nota_baja, 'instituto_nombre' => $instituto_nombre, 'ano_finalizacion' => $ano_finalizacion,
'contacto_emergencia_nombre' => $contacto_emergencia_nombre, 'contacto_emergencia_telefono' => $contacto_emergencia_telefono, 'contacto_emergencia_parentesco' => $contacto_emergencia_parentesco
];
if ($personal_id) {
$stmt = $conn->prepare("
UPDATE personal SET
empresa_id = :empresa_id, sucursal_id = :sucursal_id, nombre = :nombre, apellido = :apellido,
dni = :dni, fecha_nacimiento = :fecha_nacimiento, domicilio = :domicilio, telefono = :telefono,
email = :email, cargo = :cargo, fecha_ingreso = :fecha_ingreso, foto = :foto, foto_uniforme = :foto_uniforme,
pdf_datos_personales = :pdf_datos_personales, cupon_pago_credencial = :cupon_pago_credencial,
activo = :activo, apto_fisico = :apto_fisico, apto_psicologico = :apto_psicologico, baja = :baja,
tiene_certificado = :tiene_certificado, tiene_penales = :tiene_penales, antecedentes_provinciales = :antecedentes_provinciales,
arma_autorizada = :arma_autorizada, ram = :ram, pago_credencial = :pago_credencial,
fecha_pago_credencial = :fecha_pago_credencial,
estudios_cursados = :estudios_cursados, clu_numero = :clu_numero, inhibicion_bienes = :inhibicion_bienes,
habilitacion_comercial = :habilitacion_comercial,
modalidad_contrato = :modalidad_contrato,
examen_dt = :examen_dt,
num_nota = :num_nota,
observaciones = :observaciones, fecha_autorizacion = :fecha_autorizacion, fecha_vencimiento = :fecha_vencimiento, fecha_revalidacion = :fecha_revalidacion,
sexo = :sexo, estado_civil = :estado_civil, lugar_nacimiento = :lugar_nacimiento, grupo_sanguineo = :grupo_sanguineo,
fecha_baja = :fecha_baja, nota_baja = :nota_baja, instituto_nombre = :instituto_nombre, ano_finalizacion = :ano_finalizacion,
contacto_emergencia_nombre = :contacto_emergencia_nombre, contacto_emergencia_telefono = :contacto_emergencia_telefono, contacto_emergencia_parentesco = :contacto_emergencia_parentesco,
updated_at = NOW()
WHERE id = :id
");
$stmt->execute([
'empresa_id' => $empresa_id, 'sucursal_id' => $sucursal_id, 'nombre' => $nombre, 'apellido' => $apellido,
'dni' => $dni, 'fecha_nacimiento' => $fecha_nacimiento, 'domicilio' => $domicilio, 'telefono' => $telefono,
'email' => $email, 'cargo' => $cargo, 'fecha_ingreso' => $fecha_ingreso, 'foto' => $foto_file, 'foto_uniforme' => $foto_uniforme_file,
'pdf_datos_personales' => $pdf_file, 'cupon_pago_credencial' => $cupon_pago_file, 'activo' => $activo,
'apto_fisico' => $apto_fisico, 'apto_psicologico' => $apto_psicologico, 'baja' => $baja,
'tiene_certificado' => $tiene_certificado, 'tiene_penales' => $tiene_penales, 'antecedentes_provinciales' => $antecedentes_provinciales,
'arma_autorizada' => $arma_autorizada, 'ram' => $ram, 'pago_credencial' => $pago_credencial,
'fecha_pago_credencial' => $fecha_pago_credencial,
'estudios_cursados' => $estudios_cursados, 'clu_numero' => $clu_numero, 'inhibicion_bienes' => $inhibicion_bienes,
'habilitacion_comercial' => $habilitacion_comercial,
'modalidad_contrato' => $modalidad_contrato,
'examen_dt' => $examen_dt,
'num_nota' => $num_nota,
'observaciones' => $observaciones, 'fecha_autorizacion' => $fecha_autorizacion, 'fecha_vencimiento' => $fecha_vencimiento, 'fecha_revalidacion' => $fecha_revalidacion,
'sexo' => $sexo, 'estado_civil' => $estado_civil, 'lugar_nacimiento' => $lugar_nacimiento, 'grupo_sanguineo' => $grupo_sanguineo,
'fecha_baja' => $fecha_baja, 'nota_baja' => $nota_baja, 'instituto_nombre' => $instituto_nombre, 'ano_finalizacion' => $ano_finalizacion,
'contacto_emergencia_nombre' => $contacto_emergencia_nombre, 'contacto_emergencia_telefono' => $contacto_emergencia_telefono, 'contacto_emergencia_parentesco' => $contacto_emergencia_parentesco,
'id' => $personal_id
]);
$detalles = [
'accion' => 'MODIFICACION',
'tabla' => 'personal',
'registro_id' => $personal_id,
'datos_nuevos' => $datos_nuevos,
'datos_antiguos' => $datos_antiguos,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'MODIFICACION', 'personal', $personal_id, $detalles);
$_SESSION['success'] = 'Personal actualizado correctamente';
} else {
$stmt = $conn->prepare("
INSERT INTO personal
(empresa_id, sucursal_id, nombre, apellido, dni, fecha_nacimiento, domicilio, telefono, email, cargo,
fecha_ingreso, foto, foto_uniforme, pdf_datos_personales, cupon_pago_credencial, activo, apto_fisico, apto_psicologico,
baja, tiene_certificado, tiene_penales, antecedentes_provinciales, arma_autorizada, ram, pago_credencial,
fecha_pago_credencial,
estudios_cursados, clu_numero, inhibicion_bienes, habilitacion_comercial, modalidad_contrato, examen_dt, num_nota, observaciones, fecha_autorizacion,
fecha_vencimiento, fecha_revalidacion,
sexo, estado_civil, lugar_nacimiento, grupo_sanguineo, fecha_baja, nota_baja, instituto_nombre, ano_finalizacion,
contacto_emergencia_nombre, contacto_emergencia_telefono, contacto_emergencia_parentesco)
VALUES (:empresa_id, :sucursal_id, :nombre, :apellido, :dni, :fecha_nacimiento, :domicilio, :telefono,
:email, :cargo, :fecha_ingreso, :foto, :foto_uniforme, :pdf_datos_personales, :cupon_pago_credencial, :activo,
:apto_fisico, :apto_psicologico, :baja, :tiene_certificado, :tiene_penales, :antecedentes_provinciales, :arma_autorizada, :ram, :pago_credencial,
:fecha_pago_credencial,
:estudios_cursados, :clu_numero, :inhibicion_bienes, :habilitacion_comercial, :modalidad_contrato, :examen_dt, :num_nota, :observaciones, :fecha_autorizacion,
:fecha_vencimiento, :fecha_revalidacion,
:sexo, :estado_civil, :lugar_nacimiento, :grupo_sanguineo, :fecha_baja, :nota_baja, :instituto_nombre, :ano_finalizacion,
:contacto_emergencia_nombre, :contacto_emergencia_telefono, :contacto_emergencia_parentesco)
");
$stmt->execute([
'empresa_id' => $empresa_id, 'sucursal_id' => $sucursal_id, 'nombre' => $nombre, 'apellido' => $apellido,
'dni' => $dni, 'fecha_nacimiento' => $fecha_nacimiento, 'domicilio' => $domicilio, 'telefono' => $telefono,
'email' => $email, 'cargo' => $cargo, 'fecha_ingreso' => $fecha_ingreso, 'foto' => $foto_file, 'foto_uniforme' => $foto_uniforme_file,
'pdf_datos_personales' => $pdf_file, 'cupon_pago_credencial' => $cupon_pago_file, 'activo' => $activo,
'apto_fisico' => $apto_fisico, 'apto_psicologico' => $apto_psicologico, 'baja' => $baja,
'tiene_certificado' => $tiene_certificado, 'tiene_penales' => $tiene_penales, 'antecedentes_provinciales' => $antecedentes_provinciales,
'arma_autorizada' => $arma_autorizada, 'ram' => $ram, 'pago_credencial' => $pago_credencial,
'fecha_pago_credencial' => $fecha_pago_credencial,
'estudios_cursados' => $estudios_cursados, 'clu_numero' => $clu_numero, 'inhibicion_bienes' => $inhibicion_bienes,
'habilitacion_comercial' => $habilitacion_comercial,
'modalidad_contrato' => $modalidad_contrato,
'examen_dt' => $examen_dt,
'num_nota' => $num_nota,
'observaciones' => $observaciones, 'fecha_autorizacion' => $fecha_autorizacion, 'fecha_vencimiento' => $fecha_vencimiento, 'fecha_revalidacion' => $fecha_revalidacion,
'sexo' => $sexo, 'estado_civil' => $estado_civil, 'lugar_nacimiento' => $lugar_nacimiento, 'grupo_sanguineo' => $grupo_sanguineo,
'fecha_baja' => $fecha_baja, 'nota_baja' => $nota_baja, 'instituto_nombre' => $instituto_nombre, 'ano_finalizacion' => $ano_finalizacion,
'contacto_emergencia_nombre' => $contacto_emergencia_nombre, 'contacto_emergencia_telefono' => $contacto_emergencia_telefono, 'contacto_emergencia_parentesco' => $contacto_emergencia_parentesco
]);
$nueva_id = $conn->lastInsertId();
$detalles = [
'accion' => 'CREACION',
'tabla' => 'personal',
'registro_id' => $nueva_id,
'datos_nuevos' => $datos_nuevos,
'usuario' => $user['nombre_usuario'] ?? 'Sistema'
];
logAuditoria($conn, 'CREACION', 'personal', $nueva_id, $detalles);
$_SESSION['success'] = 'Personal creado correctamente';
}
header('Location: personal.php');
exit;
} catch(Exception $e) {
error_log("Error al guardar personal: " . $e->getMessage());
$_SESSION['error'] = $e->getMessage();
$form_data = $_POST;
}
}
// ==================== OBTENER DATOS ====================
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = TRUE ORDER BY nombre");
$empresas = $stmt->fetchAll();
$personal_edit = null;
$sucursales = [];
$form_data = $form_data ?? null;
// ============================================
// ✅ INICIALIZAR FILTROS PARA EVITAR WARNINGS
// ============================================
$filtro_empresa = isset($_GET['filtro_empresa']) && !empty($_GET['filtro_empresa']) ? (int)$_GET['filtro_empresa'] : 0;
$filtro_sucursal = isset($_GET['filtro_sucursal']) && !empty($_GET['filtro_sucursal']) ? (int)$_GET['filtro_sucursal'] : 0;
// ============================================
// ✅ CONTADOR DE INACTIVOS SIN BAJA (AL INICIO)
// ============================================
$stmt_inactivos_count = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE activo = FALSE AND baja = FALSE");
$stmt_inactivos_count->execute();
$total_inactivos_sin_baja = $stmt_inactivos_count->fetch()['total'];
// ============================================
// ✅ CONTADOR DE DOCUMENTACIÓN PENDIENTE
// ============================================
$stmt_pendientes = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE estado_documentacion = 'pendiente'");
$stmt_pendientes->execute();
$total_pendientes_doc = $stmt_pendientes->fetch()['total'];
// ============================================
// ✅ NUEVO: CONTADOR DE SUCURSALES CON DIRECTOR TECNICO
// ============================================
$stmt_dt = $conn->prepare("SELECT COUNT(DISTINCT sucursal_id) as total, GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', ') as nombres FROM personal p LEFT JOIN sucursales s ON p.sucursal_id = s.id WHERE p.cargo = 'DIRECTOR TECNICO' AND p.activo = 1" .
($filtro_empresa > 0 ? " AND p.empresa_id = :filtro_empresa" : ""));
if ($filtro_empresa > 0) $stmt_dt->bindValue(':filtro_empresa', $filtro_empresa, PDO::PARAM_INT);
$stmt_dt->execute();
$dt_data = $stmt_dt->fetch();
$director_tecnico_sucursales = $dt_data['total'] ?? 0;
$director_tecnico_nombres = $dt_data['nombres'] ?? '';
// ============================================
// ✅ NUEVOS FILTROS DE BÚSQUEDA (resto de filtros)
// ============================================
$filtro_cargo = isset($_GET['filtro_cargo']) && !empty($_GET['filtro_cargo']) ? sanitizeInput($_GET['filtro_cargo']) : '';
$filtro_modalidad = isset($_GET['filtro_modalidad']) && !empty($_GET['filtro_modalidad']) ? sanitizeInput($_GET['filtro_modalidad']) : '';
$filtro_activo = isset($_GET['filtro_activo']) && $_GET['filtro_activo'] !== '' ? $_GET['filtro_activo'] : '';
$filtro_certificado = isset($_GET['filtro_certificado']) && $_GET['filtro_certificado'] !== '' ? $_GET['filtro_certificado'] : '';
$filtro_arma = isset($_GET['filtro_arma']) && $_GET['filtro_arma'] !== '' ? $_GET['filtro_arma'] : '';
$filtro_penales = isset($_GET['filtro_penales']) && $_GET['filtro_penales'] !== '' ? $_GET['filtro_penales'] : '';
$filtro_prov = isset($_GET['filtro_prov']) && $_GET['filtro_prov'] !== '' ? $_GET['filtro_prov'] : '';
$filtro_clu = isset($_GET['filtro_clu']) && $_GET['filtro_clu'] !== '' ? $_GET['filtro_clu'] : '';
$filtro_ram = isset($_GET['filtro_ram']) && $_GET['filtro_ram'] !== '' ? $_GET['filtro_ram'] : '';
$filtro_desde = isset($_GET['filtro_desde']) && !empty($_GET['filtro_desde']) ? $_GET['filtro_desde'] : '';
$filtro_hasta = isset($_GET['filtro_hasta']) && !empty($_GET['filtro_hasta']) ? $_GET['filtro_hasta'] : '';
$filtro_edad_min = isset($_GET['filtro_edad_min']) && !empty($_GET['filtro_edad_min']) ? (int)$_GET['filtro_edad_min'] : 0;
$filtro_edad_max = isset($_GET['filtro_edad_max']) && !empty($_GET['filtro_edad_max']) ? (int)$_GET['filtro_edad_max'] : 0;
$filtro_texto = isset($_GET['filtro_texto']) && !empty($_GET['filtro_texto']) ? sanitizeInput($_GET['filtro_texto']) : '';
// ============================================
// ✅ FILTRO DE ESTADO DE DOCUMENTACIÓN
// ============================================
$filtro_estado_documentacion = isset($_GET['filtro_estado_documentacion']) && !empty($_GET['filtro_estado_documentacion']) ? $_GET['filtro_estado_documentacion'] : '';
// ============================================
// ✅ FILTROS DE VENCIMIENTO
// ============================================
$filtro_vencimiento = isset($_GET['filtro_vencimiento']) && !empty($_GET['filtro_vencimiento']) ? $_GET['filtro_vencimiento'] : '';
$filtro_revalidacion = isset($_GET['filtro_revalidacion']) && !empty($_GET['filtro_revalidacion']) ? $_GET['filtro_revalidacion'] : '';
$filtro_credencial = isset($_GET['filtro_credencial']) && !empty($_GET['filtro_credencial']) ? $_GET['filtro_credencial'] : '';
// ============================================
// ✅ FILTROS PARA LOTE (CON EMPRESA Y SUCURSAL)
// ============================================
$filtro_lote_activo = isset($_GET['lote_activo']) && $_GET['lote_activo'] !== '' ? $_GET['lote_activo'] : '';
$filtro_lote_empresa = isset($_GET['lote_empresa']) && !empty($_GET['lote_empresa']) ? (int)$_GET['lote_empresa'] : 0;
$filtro_lote_sucursal = isset($_GET['lote_sucursal']) && !empty($_GET['lote_sucursal']) ? (int)$_GET['lote_sucursal'] : 0;
$filtro_lote_vencimiento = isset($_GET['lote_vencimiento']) && !empty($_GET['lote_vencimiento']) ? $_GET['lote_vencimiento'] : '';
$filtro_lote_revalidacion = isset($_GET['lote_revalidacion']) && !empty($_GET['lote_revalidacion']) ? $_GET['lote_revalidacion'] : '';
$filtro_lote_credencial = isset($_GET['lote_credencial']) && !empty($_GET['lote_credencial']) ? $_GET['lote_credencial'] : '';
// ✅ NUEVO: FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (PRIMERA PALABRA) PARA LOTES
$filtro_lote_letra_desde = isset($_GET['lote_letra_desde']) && !empty($_GET['lote_letra_desde']) ? strtoupper(sanitizeInput($_GET['lote_letra_desde'])) : 'A';
$filtro_lote_letra_hasta = isset($_GET['lote_letra_hasta']) && !empty($_GET['lote_letra_hasta']) ? strtoupper(sanitizeInput($_GET['lote_letra_hasta'])) : 'Z';
// ============================================
// ✅ PAGINACIÓN Y ORDENAMIENTO
// ============================================
// MODIFICADO: Permitir elegir cantidad de registros (10, 30, 50)
$registros_por_pagina_options = [10, 30, 50];
$registros_por_pagina = isset($_GET['registros_por_pagina']) && in_array((int)$_GET['registros_por_pagina'], $registros_por_pagina_options) ? (int)$_GET['registros_por_pagina'] : 10;
$pagina_actual = isset($_GET['pagina']) && (int)$_GET['pagina'] > 0 ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$columnas_permitidas = ['id', 'nombre', 'apellido', 'dni', 'cargo', 'empresa_nombre', 'sucursal_nombre', 'fecha_ingreso', 'activo', 'modalidad_contrato'];
$columna_orden = isset($_GET['orden']) && in_array($_GET['orden'], $columnas_permitidas) ? $_GET['orden'] : 'apellido';
$direccion_orden = isset($_GET['direccion']) && strtoupper($_GET['direccion']) === 'ASC' ? 'ASC' : 'DESC';
$empresa_id_for_sucursales = 0;
if ($filtro_empresa > 0) {
$empresa_id_for_sucursales = $filtro_empresa;
} elseif (isset($_GET['edit']) && !empty($_GET['edit'])) {
$edit_id = (int)$_GET['edit'];
$stmt = $conn->prepare("
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio, e.localidad as empresa_localidad
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.id = :id
");
$stmt->execute(['id' => $edit_id]);
$personal_edit = $stmt->fetch();
if ($personal_edit) {
$empresa_id_for_sucursales = $personal_edit['empresa_id'];
}
} elseif (isset($_POST['empresa_id']) && !empty($_POST['empresa_id'])) {
$empresa_id_for_sucursales = (int)$_POST['empresa_id'];
} elseif (isset($form_data['empresa_id']) && !empty($form_data['empresa_id'])) {
$empresa_id_for_sucursales = (int)$form_data['empresa_id'];
}
if ($empresa_id_for_sucursales > 0) {
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = :empresa_id AND activa = TRUE ORDER BY nombre");
$stmt->execute(['empresa_id' => $empresa_id_for_sucursales]);
$sucursales = $stmt->fetchAll();
}
$sucursales_filtro = [];
if ($filtro_empresa > 0) {
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = :empresa_id AND activa = TRUE ORDER BY nombre");
$stmt->execute(['empresa_id' => $filtro_empresa]);
$sucursales_filtro = $stmt->fetchAll();
}
// ============================================
// ✅ CONSULTA CON FILTROS, ORDEN Y PAGINACIÓN - CORREGIDA
// ============================================
$query = "
SELECT p.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
e.cuit as empresa_cuit, e.domicilio as empresa_domicilio, e.localidad as empresa_localidad,
u.username as revisado_por_username
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
LEFT JOIN usuarios u ON p.revisado_por_usuario_id = u.id
WHERE 1=1
";
$params = [];
// Filtros principales
if ($filtro_empresa > 0) {
$query .= " AND p.empresa_id = :filtro_empresa";
$params['filtro_empresa'] = $filtro_empresa;
}
if ($filtro_sucursal > 0) {
$query .= " AND p.sucursal_id = :filtro_sucursal";
$params['filtro_sucursal'] = $filtro_sucursal;
}
if (!empty($filtro_texto)) {
$query .= " AND (p.nombre LIKE :filtro_texto_nombre OR p.apellido LIKE :filtro_texto_apellido OR p.dni LIKE :filtro_texto_dni)";
$params['filtro_texto_nombre'] = "%{$filtro_texto}%";
$params['filtro_texto_apellido'] = "%{$filtro_texto}%";
$params['filtro_texto_dni'] = "%{$filtro_texto}%";
}
if (!empty($filtro_estado_documentacion)) {
$query .= " AND p.estado_documentacion = :filtro_estado_documentacion";
$params['filtro_estado_documentacion'] = $filtro_estado_documentacion;
}
if (!empty($filtro_cargo)) {
$query .= " AND p.cargo = :filtro_cargo";
$params['filtro_cargo'] = $filtro_cargo;
}
if (!empty($filtro_modalidad)) {
$query .= " AND p.modalidad_contrato = :filtro_modalidad";
$params['filtro_modalidad'] = $filtro_modalidad;
}
if ($filtro_activo !== '') {
$query .= " AND p.activo = :filtro_activo";
$params['filtro_activo'] = $filtro_activo;
}
if ($filtro_certificado !== '') {
$query .= " AND p.tiene_certificado = :filtro_certificado";
$params['filtro_certificado'] = $filtro_certificado;
}
if ($filtro_arma !== '') {
$query .= " AND p.arma_autorizada = :filtro_arma";
$params['filtro_arma'] = $filtro_arma;
}
if ($filtro_penales !== '') {
$query .= " AND p.tiene_penales = :filtro_penales";
$params['filtro_penales'] = $filtro_penales;
}
if ($filtro_prov !== '') {
$query .= " AND p.antecedentes_provinciales = :filtro_prov";
$params['filtro_prov'] = $filtro_prov;
}
if ($filtro_clu !== '') {
$query .= " AND p.clu_numero = :filtro_clu";
$params['filtro_clu'] = $filtro_clu;
}
if ($filtro_ram !== '') {
$query .= " AND p.ram = :filtro_ram";
$params['filtro_ram'] = $filtro_ram;
}
if (!empty($filtro_desde)) {
$query .= " AND p.fecha_ingreso >= :filtro_desde";
$params['filtro_desde'] = $filtro_desde;
}
if (!empty($filtro_hasta)) {
$query .= " AND p.fecha_ingreso <= :filtro_hasta";
$params['filtro_hasta'] = $filtro_hasta;
}
if ($filtro_edad_min > 0 || $filtro_edad_max > 0) {
if ($filtro_edad_min > 0 && $filtro_edad_max > 0) {
$query .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) BETWEEN :edad_min AND :edad_max";
$params['edad_min'] = $filtro_edad_min;
$params['edad_max'] = $filtro_edad_max;
} elseif ($filtro_edad_min > 0) {
$query .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) >= :edad_min";
$params['edad_min'] = $filtro_edad_min;
} elseif ($filtro_edad_max > 0) {
$query .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) <= :edad_max";
$params['edad_max'] = $filtro_edad_max;
}
}
// Filtros de vencimiento, revalidación y credencial
if (!empty($filtro_vencimiento) || !empty($filtro_revalidacion) || !empty($filtro_credencial)) {
$conditions = [];
if (!empty($filtro_vencimiento)) {
if ($filtro_vencimiento === 'vencido') {
$conditions[] = "p.fecha_vencimiento < CURDATE()";
} elseif ($filtro_vencimiento === 'proximo') {
$conditions[] = "(p.fecha_vencimiento >= CURDATE() AND p.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_vencimiento === 'vigente') {
$conditions[] = "(p.fecha_vencimiento IS NULL OR p.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_revalidacion)) {
if ($filtro_revalidacion === 'vencido') {
$conditions[] = "p.fecha_revalidacion < CURDATE()";
} elseif ($filtro_revalidacion === 'proximo') {
$conditions[] = "(p.fecha_revalidacion >= CURDATE() AND p.fecha_revalidacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_revalidacion === 'vigente') {
$conditions[] = "(p.fecha_revalidacion IS NULL OR p.fecha_revalidacion > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_credencial)) {
if ($filtro_credencial === 'pagada') {
$conditions[] = "p.pago_credencial = 1";
} elseif ($filtro_credencial === 'pendiente') {
$conditions[] = "(p.pago_credencial = 0 OR p.pago_credencial IS NULL)";
}
}
if (!empty($conditions)) {
$query .= " AND (" . implode(" AND ", $conditions) . ")";
}
}
$query .= " ORDER BY {$columna_orden} {$direccion_orden}";
$query .= " LIMIT :offset, :limit";
$stmt = $conn->prepare($query);
// Bind todos los parámetros
foreach ($params as $key => $value) {
$stmt->bindValue(":{$key}", $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$personal_list = $stmt->fetchAll();
// Obtener total de registros - QUERY COUNT CORREGIDO CON MISMO FILTRO DE TEXTO
$query_count = "
SELECT COUNT(*) as total FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE 1=1
";
$params_count = [];
if ($filtro_empresa > 0) {
$query_count .= " AND p.empresa_id = :filtro_empresa";
$params_count['filtro_empresa'] = $filtro_empresa;
}
if ($filtro_sucursal > 0) {
$query_count .= " AND p.sucursal_id = :filtro_sucursal";
$params_count['filtro_sucursal'] = $filtro_sucursal;
}
// ✅ APLICAR FILTRO DE TEXTO EN COUNT QUERY
if (!empty($filtro_texto)) {
$query_count .= " AND (p.nombre LIKE :filtro_texto_nombre OR p.apellido LIKE :filtro_texto_apellido OR p.dni LIKE :filtro_texto_dni)";
$params_count['filtro_texto_nombre'] = "%{$filtro_texto}%";
$params_count['filtro_texto_apellido'] = "%{$filtro_texto}%";
$params_count['filtro_texto_dni'] = "%{$filtro_texto}%";
}
if (!empty($filtro_estado_documentacion)) {
$query_count .= " AND p.estado_documentacion = :filtro_estado_documentacion";
$params_count['filtro_estado_documentacion'] = $filtro_estado_documentacion;
}
if (!empty($filtro_cargo)) {
$query_count .= " AND p.cargo = :filtro_cargo";
$params_count['filtro_cargo'] = $filtro_cargo;
}
if (!empty($filtro_modalidad)) {
$query_count .= " AND p.modalidad_contrato = :filtro_modalidad";
$params_count['filtro_modalidad'] = $filtro_modalidad;
}
if ($filtro_activo !== '') {
$query_count .= " AND p.activo = :filtro_activo";
$params_count['filtro_activo'] = $filtro_activo;
}
if ($filtro_certificado !== '') {
$query_count .= " AND p.tiene_certificado = :filtro_certificado";
$params_count['filtro_certificado'] = $filtro_certificado;
}
if ($filtro_arma !== '') {
$query_count .= " AND p.arma_autorizada = :filtro_arma";
$params_count['filtro_arma'] = $filtro_arma;
}
if ($filtro_penales !== '') {
$query_count .= " AND p.tiene_penales = :filtro_penales";
$params_count['filtro_penales'] = $filtro_penales;
}
if ($filtro_prov !== '') {
$query_count .= " AND p.antecedentes_provinciales = :filtro_prov";
$params_count['filtro_prov'] = $filtro_prov;
}
if ($filtro_clu !== '') {
$query_count .= " AND p.clu_numero = :filtro_clu";
$params_count['filtro_clu'] = $filtro_clu;
}
if ($filtro_ram !== '') {
$query_count .= " AND p.ram = :filtro_ram";
$params_count['filtro_ram'] = $filtro_ram;
}
if (!empty($filtro_desde)) {
$query_count .= " AND p.fecha_ingreso >= :filtro_desde";
$params_count['filtro_desde'] = $filtro_desde;
}
if (!empty($filtro_hasta)) {
$query_count .= " AND p.fecha_ingreso <= :filtro_hasta";
$params_count['filtro_hasta'] = $filtro_hasta;
}
if ($filtro_edad_min > 0 || $filtro_edad_max > 0) {
if ($filtro_edad_min > 0 && $filtro_edad_max > 0) {
$query_count .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) BETWEEN :edad_min AND :edad_max";
$params_count['edad_min'] = $filtro_edad_min;
$params_count['edad_max'] = $filtro_edad_max;
} elseif ($filtro_edad_min > 0) {
$query_count .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) >= :edad_min";
$params_count['edad_min'] = $filtro_edad_min;
} elseif ($filtro_edad_max > 0) {
$query_count .= " AND TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) <= :edad_max";
$params_count['edad_max'] = $filtro_edad_max;
}
}
// Filtros de vencimiento, revalidación y credencial en COUNT
if (!empty($filtro_vencimiento) || !empty($filtro_revalidacion) || !empty($filtro_credencial)) {
$conditions_count = [];
if (!empty($filtro_vencimiento)) {
if ($filtro_vencimiento === 'vencido') {
$conditions_count[] = "p.fecha_vencimiento < CURDATE()";
} elseif ($filtro_vencimiento === 'proximo') {
$conditions_count[] = "(p.fecha_vencimiento >= CURDATE() AND p.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_vencimiento === 'vigente') {
$conditions_count[] = "(p.fecha_vencimiento IS NULL OR p.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_revalidacion)) {
if ($filtro_revalidacion === 'vencido') {
$conditions_count[] = "p.fecha_revalidacion < CURDATE()";
} elseif ($filtro_revalidacion === 'proximo') {
$conditions_count[] = "(p.fecha_revalidacion >= CURDATE() AND p.fecha_revalidacion <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
} elseif ($filtro_revalidacion === 'vigente') {
$conditions_count[] = "(p.fecha_revalidacion IS NULL OR p.fecha_revalidacion > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
}
}
if (!empty($filtro_credencial)) {
if ($filtro_credencial === 'pagada') {
$conditions_count[] = "p.pago_credencial = 1";
} elseif ($filtro_credencial === 'pendiente') {
$conditions_count[] = "(p.pago_credencial = 0 OR p.pago_credencial IS NULL)";
}
}
if (!empty($conditions_count)) {
$query_count .= " AND (" . implode(" AND ", $conditions_count) . ")";
}
}
$stmt_count = $conn->prepare($query_count);
foreach ($params_count as $key => $value) {
$stmt_count->bindValue(":{$key}", $value);
}
$stmt_count->execute();
$total_registros = $stmt_count->fetch()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
// Estadísticas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE 1=1" .
($filtro_empresa > 0 ? " AND empresa_id = :filtro_empresa" : "") .
($filtro_sucursal > 0 ? " AND sucursal_id = :filtro_sucursal" : ""));
if ($filtro_empresa > 0) $stmt->bindValue(':filtro_empresa', $filtro_empresa, PDO::PARAM_INT);
if ($filtro_sucursal > 0) $stmt->bindValue(':filtro_sucursal', $filtro_sucursal, PDO::PARAM_INT);
$stmt->execute();
$total_personal = $stmt->fetch()['total'];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE activo = TRUE" .
($filtro_empresa > 0 ? " AND empresa_id = :filtro_empresa" : "") .
($filtro_sucursal > 0 ? " AND sucursal_id = :filtro_sucursal" : ""));
if ($filtro_empresa > 0) $stmt->bindValue(':filtro_empresa', $filtro_empresa, PDO::PARAM_INT);
if ($filtro_sucursal > 0) $stmt->bindValue(':filtro_sucursal', $filtro_sucursal, PDO::PARAM_INT);
$stmt->execute();
$personal_activo = $stmt->fetch()['total'];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE apto_fisico = TRUE AND apto_psicologico = TRUE" .
($filtro_empresa > 0 ? " AND empresa_id = :filtro_empresa" : "") .
($filtro_sucursal > 0 ? " AND sucursal_id = :filtro_sucursal" : ""));
if ($filtro_empresa > 0) $stmt->bindValue(':filtro_empresa', $filtro_empresa, PDO::PARAM_INT);
if ($filtro_sucursal > 0) $stmt->bindValue(':filtro_sucursal', $filtro_sucursal, PDO::PARAM_INT);
$stmt->execute();
$personal_apto = $stmt->fetch()['total'];
$cargos_disponibles = [
'ACCIONISTA', 'ADMINISTRATIVO', 'VIGILADOR', 'CHOFER', 'DIRECTOR TECNICO',
'GERENTE GENERAL', 'SUPERVISOR', 'PRESIDENTE DEL DIRECTORIO', 'REPRESENTANTE LEGAL'
];
// ✅ LISTA DE MODALIDADES DE CONTRATO (RG 2143) - 52 CÓDIGOS
$modalidades_contrato = [
['codigo' => '0', 'descripcion' => 'Contrato Modalidad Promovida. Reduccion 0%'],
['codigo' => '1', 'descripcion' => 'A tiempo parcial: Indeterminado/permanente'],
['codigo' => '2', 'descripcion' => 'Becarios- Residencias medicas Ley N°22.127'],
['codigo' => '3', 'descripcion' => 'De aprendizaje Ley N 25.013'],
['codigo' => '4', 'descripcion' => 'Especial de Fomento del Empleo: Ley N° 24.465'],
['codigo' => '5', 'descripcion' => 'Fomento del empleo Leyes N 24.013 y N 24.465.'],
['codigo' => '6', 'descripcion' => 'Lanzamiento nueva actividad. Idem 005'],
['codigo' => '7', 'descripcion' => 'Periodo de prueba. Leyes N° 24.465 y N° 25.013'],
['codigo' => '8', 'descripcion' => 'A Tiempo completo indeterminado/Trabajo pemanente'],
['codigo' => '9', 'descripcion' => 'Practica laboral para jovenes.'],
['codigo' => '10', 'descripcion' => 'Pasantias. Ley N° 25.165. Decreto N 340/92'],
['codigo' => '11', 'descripcion' => 'Trabajo de temporada.'],
['codigo' => '12', 'descripcion' => 'Trabajo eventual.'],
['codigo' => '13', 'descripcion' => 'Trabajo formacion.'],
['codigo' => '14', 'descripcion' => 'Nuevo Periodo de Prueba'],
['codigo' => '15', 'descripcion' => 'Puesto Nuevo Varones y Mujeres de 25 a 44 anos'],
['codigo' => '16', 'descripcion' => 'Nuevo Periodo de Prueba Trabajador Discapacitado Art. 34 de la Ley N° 24.147.'],
['codigo' => '17', 'descripcion' => 'Puesto Nuevo menor de 25 anos, Varones y Mujeres de 45 o mas anos y Mujer Jefe de flia. S/limite/edad'],
['codigo' => '18', 'descripcion' => 'Trabajador Discapacitado Art. 34 de la Ley N° 24.147.'],
['codigo' => '19', 'descripcion' => 'Puesto Nuevo. Varones y Mujeres 25 a 44 anos. Art. 34 de la Ley N°24.147.'],
['codigo' => '20', 'descripcion' => 'Pto. Nuevo Menor 25 anos, Varones y Mujeres 45 o mas y Mujer Jefe de flia.S/limite de edad. Art. 34 de la Ley N° 24.147.'],
['codigo' => '21', 'descripcion' => 'A tiempo parcial deteminado (contrato a plazo fijo)'],
['codigo' => '22', 'descripcion' => 'A Tiempo completo determinado (contrato a plazo fijo)'],
['codigo' => '23', 'descripcion' => 'Personal no permanente Ley N° 22.248.'],
['codigo' => '24', 'descripcion' => 'Personal de la Construcción Ley N° 22.250.'],
['codigo' => '25', 'descripcion' => 'Empleo publico provincial.'],
['codigo' => '26', 'descripcion' => 'Beneficiario de programa de empleo, capacitacion y de recuperacion productiva.'],
['codigo' => '27', 'descripcion' => 'Pasantias Decreto N° 1.227/01.'],
['codigo' => '28', 'descripcion' => 'Programas Jefes y Jefas de Hogar.'],
['codigo' => '29', 'descripcion' => 'Decreto N° 1212/03 Aportante Autonomo.'],
['codigo' => '30', 'descripcion' => 'Nuevo Periodo de Prueba Trabajador Discapacitado. Art. 87 de la Ley N° 24.013.'],
['codigo' => '31', 'descripcion' => 'Trabajador Discapacitado Art. 87 de la Ley N° 24.013.'],
['codigo' => '32', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877.'],
['codigo' => '33', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N 25.877. Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '34', 'descripcion' => 'Periodo de Prueba Art. 6°de la Ley N° 25.877. Art. 34 de la Ley N° 24.147.'],
['codigo' => '35', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877. Art. 34 de la Ley N° 24.147.Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '36', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877. Trabajador Discapacitado Art. 87de la Ley N° 24.013.'],
['codigo' => '37', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877.Trab. Discapacitado Art 87de la Ley N° 24.013 Beneficiarios planes Jefes y Jefas hogar'],
['codigo' => '38', 'descripcion' => 'Puesto Nuevo Art. 6°de la Ley N° 25.877.'],
['codigo' => '39', 'descripcion' => 'Puesto Nuevo Art. 6° de la Ley N 25.877. Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '40', 'descripcion' => 'Puesto Nuevo Art. 6°de la Ley N° 25.877. Art. 34. Ley N 24.147'],
['codigo' => '41', 'descripcion' => 'Puesto Nuevo Art. 6° de la Ley N 25.877. Art. 34 de la Ley N24.147.Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '42', 'descripcion' => 'Puesto Nuevo Art. 6° de la Ley N 25.877.Trabajador Discapacitado Art. 87 de la Ley N° 24.013'],
['codigo' => '43', 'descripcion' => 'Puesto Nuevo Art.6° de la Ley N° 25.877.Trabajador Discapacitado Art. 87 de la Ley N° 24.013 Beneficiarios de planes Jefes y Jefas hogar'],
['codigo' => '44', 'descripcion' => 'Changa Solidaria. CCT 62/75'],
['codigo' => '45', 'descripcion' => 'Personal no permanente hoteles Art. 68 del CCT 362/03'],
['codigo' => '46', 'descripcion' => 'Planta transitoria Adm Publica Nacional. Provincial y/o Municipal'],
['codigo' => '47', 'descripcion' => 'Representacion gremial'],
['codigo' => '48', 'descripcion' => 'Art. 4° de la Ley N° 24.241.Traslado temporario desde el exterior 6 Convenios bilaterales de Seguridad Social'],
['codigo' => '50', 'descripcion' => 'Contrato Modalidad Promovida. Reduccion 50%'],
['codigo' => '99', 'descripcion' => 'LRT (Directores SA, municipios, org, cent y descent. Emp mixt provin, docentes de jurisdicciones incorporadas o no al SIJP)'],
['codigo' => '100', 'descripcion' => 'Contrato Modalidad Promovida. Reduccion 100%']
];
$fondo_path = '../uploads/fondos_credenciales/fondo_credencial.jpg';
$fondo_existe = file_exists($fondo_path);
function generarUrlOrden($columna, $direccion_actual, $columna_actual) {
$nueva_direccion = ($columna === $columna_actual && $direccion_actual === 'ASC') ? 'DESC' : 'ASC';
$params = $_GET;
$params['orden'] = $columna;
$params['direccion'] = $nueva_direccion;
return '?' . http_build_query($params);
}
function iconoOrden($columna, $columna_actual, $direccion_actual) {
if ($columna !== $columna_actual) {
return '<i class="fas fa-sort text-muted ms-1"></i>';
}
return $direccion_actual === 'ASC'
? '<i class="fas fa-sort-up text-primary ms-1"></i>'
: '<i class="fas fa-sort-down text-primary ms-1"></i>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?> - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
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
.alert-inactivos {
background: #fff3cd;
border-left: 4px solid #f39c12;
border-radius: 4px;
padding: 15px;
margin-bottom: 20px;
}
.alert-documentacion-pendiente {
background: #fff3cd;
border-left: 4px solid #f39c12;
border-radius: 4px;
padding: 15px;
margin-bottom: 20px;
}
.badge-activo-true { background: #28a745 !important; color: #fff; }
.badge-activo-false { background: #dc3545 !important; color: #fff; }
.doc-pendiente { background: #ffc107 !important; color: #000; }
.doc-aprobada { background: #28a745 !important; color: #fff; }
.doc-rechazada { background: #dc3545 !important; color: #fff; }
.vencimiento-vencido { background: #dc3545 !important; color: #fff; }
.vencimiento-proximo { background: #ffc107 !important; color: #000; }
.vencimiento-vigente { background: #28a745 !important; color: #fff; }
.btn-aprobar { background: #28a745; border: none; color: white; }
.btn-rechazar { background: #dc3545; border: none; color: white; }
.btn-qr { background: #17a2b8; border: none; color: white; }
.btn-auditoria { background: #007bff; border: none; color: white; }
.btn-lote-action { background: #17a2b8; border: none; color: white; }
.btn-exportar-lista { background: #6f42c1; border: none; color: white; }
.lote-stats-card {
background: #f8f9fa;
border-radius: 4px;
padding: 20px;
text-align: center;
border: 1px solid var(--card-border);
}
.foto-thumbnail {
width: 50px;
height: 50px;
border-radius: 50%;
object-fit: cover;
border: 2px solid #3498db;
}
.vencimientos-container {
background: #f8f9fa;
border-radius: 4px;
padding: 10px;
min-width: 200px;
display: flex;
flex-direction: column;
gap: 6px;
}
.vencimiento-badge {
padding: 5px 12px;
border-radius: 15px;
font-size: 0.75rem;
font-weight: 600;
display: inline-flex;
align-items: center;
gap: 6px;
justify-content: center;
white-space: nowrap;
}
/* Estilos para Tabs */
.nav-tabs .nav-link {
color: #495057;
border: 1px solid transparent;
border-radius: 4px 4px 0 0;
padding: 10px 20px;
font-weight: 500;
}
.nav-tabs .nav-link.active {
color: var(--primary-color);
background-color: #fff;
border-color: var(--card-border) var(--card-border) #fff;
}
.nav-tabs .nav-link:hover {
border-color: #e9ecef #e9ecef var(--card-border);
}
.tab-content {
border: 1px solid var(--card-border);
border-top: none;
border-radius: 0 0 4px 4px;
padding: 20px;
background: #fff;
}
</style>
</head>
<body>
<?php include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="fas fa-check-circle"></i> <?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($total_inactivos_sin_baja > 0): ?>
<div class="alert-inactivos">
<div class="d-flex align-items-center justify-content-between">
<div>
<i class="fas fa-user-times fa-2x text-warning me-3"></i>
<strong class="fs-5">⚠️ Personal Inactivo (Sin Baja)</strong>
<p class="mb-0 mt-2 text-muted">Hay personal que está inactivo pero no ha sido dado de baja. Revise y regularice la situación.</p>
</div>
<div class="alert-inactivos-count" style="background: #e74c3c; color: white; padding: 10px 25px; border-radius: 25px; font-size: 1.5rem; font-weight: 700; display: inline-block;">
<i class="fas fa-users"></i> <?php echo $total_inactivos_sin_baja; ?> Registros
</div>
</div>
</div>
<?php endif; ?>
<?php if ($total_pendientes_doc > 0): ?>
<div class="alert-documentacion-pendiente">
<div class="d-flex align-items-center justify-content-between">
<div>
<i class="fas fa-file-signature fa-2x text-warning me-3"></i>
<strong class="fs-5">📄 Documentación Pendiente de Revisión</strong>
<p class="mb-0 mt-2 text-muted">Documentación de personal cargada que requiere aprobación o rechazo por parte del administrador.</p>
</div>
<div class="alert-documentacion-count" style="background: #f39c12; color: white; padding: 10px 25px; border-radius: 25px; font-size: 1.5rem; font-weight: 700; display: inline-block;">
<i class="fas fa-clock"></i> <?php echo $total_pendientes_doc; ?> Pendientes
</div>
</div>
</div>
<?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-users fa-2x"></i></div>
<div class="stat-number"><?php echo $total_personal; ?></div>
<div class="stat-label">Total Personal</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
<div class="stat-number"><?php echo $personal_activo; ?></div>
<div class="stat-label">Personal Activo</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-user-shield fa-2x"></i></div>
<div class="stat-number"><?php echo $personal_apto; ?></div>
<div class="stat-label">Personal Apto</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-clock fa-2x"></i></div>
<div class="stat-number"><?php echo $total_pendientes_doc; ?></div>
<div class="stat-label">Doc. Pendiente</div>
</div>
<div class="stat-card" title="<?php echo htmlspecialchars($director_tecnico_nombres); ?>">
<div class="stat-icon mb-2 text-danger"><i class="fas fa-user-tie fa-2x"></i></div>
<div class="stat-number"><?php echo $director_tecnico_sucursales; ?></div>
<div class="stat-label">Suc. con Dir. Técnico</div>
</div>
</div>
<?php if ($auth->hasRole('administrador') || $auth->hasRole('carga')): ?>
<!-- ✅ SECCIÓN DE IMPRESIÓN POR LOTES - CON COLLAPSE -->
<div class="section-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="section-title"
data-bs-toggle="collapse"
data-bs-target="#contenidoLotes"
style="cursor: pointer;"
title="Clic para mostrar/ocultar">
<i class="fas fa-print me-2"></i>Impresión de Credenciales por Lotes
<i class="fas fa-chevron-down float-end mt-1" id="iconoLotes"></i>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto) -->
<div id="contenidoLotes" class="collapse">
<div class="row g-3">
<div class="col-md-2">
<label class="form-label">Desde Letra</label>
<select id="loteLetraDesdeSelect" class="form-select">
<?php
$letras = range('A', 'Z');
foreach ($letras as $letra): ?>
<option value="<?php echo $letra; ?>" <?php echo $filtro_lote_letra_desde === $letra ? 'selected' : ''; ?>><?php echo $letra; ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Hasta Letra</label>
<select id="loteLetraHastaSelect" class="form-select">
<?php
$letras = range('A', 'Z');
foreach ($letras as $letra): ?>
<option value="<?php echo $letra; ?>" <?php echo $filtro_lote_letra_hasta === $letra ? 'selected' : ''; ?>><?php echo $letra; ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<small class="text-muted d-block mt-4">Filtrar por primera letra de la primera palabra de apellido</small>
</div>
<div class="col-md-3">
<label class="form-label">Empresa</label>
<select id="loteEmpresaSelect" class="form-select">
<option value="0">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>">
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Sucursal</label>
<select id="loteSucursalSelect" class="form-select">
<option value="0">Todas las sucursales</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select id="loteActivoSelect" class="form-select">
<option value="">Todos</option>
<option value="1">Activos</option>
<option value="0">Inactivos</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Vencimiento Credencial</label>
<select id="loteVencimientoSelect" class="form-select">
<option value="">Todos</option>
<option value="vigente">Vigentes</option>
<option value="proximo">Próximos (30 días)</option>
<option value="vencido">Vencidos</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Pago Credencial</label>
<select id="loteCredencialSelect" class="form-select">
<option value="">Todos</option>
<option value="pagada">Pagadas</option>
<option value="pendiente">Pendientes</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Acciones</label>
<div class="d-flex gap-2 flex-wrap">
<button type="button" class="btn btn-info" onclick="buscarPersonalParaLote()">
<i class="fas fa-search me-2"></i>Buscar Personal
</button>
<button type="button" class="btn btn-success" onclick="generarLoteCredenciales()" id="btnGenerarLote" disabled>
<i class="fas fa-print me-2"></i>Generar Lote (40 c/u)
</button>
<button type="button" class="btn btn-exportar-lista" onclick="exportarListaSeleccionadaPDF()" id="btnExportarLista" disabled>
<i class="fas fa-file-pdf me-2"></i>Exportar Lista (PDF)
</button>
</div>
<small class="text-muted d-block mt-1">✅ Cada PDF de credenciales contendrá exactamente 40 credenciales | 📄 Lista en PDF con personal seleccionado</small>
</div>
</div>
<!-- Resultados de búsqueda para lote -->
<div id="resultadoLote" class="mt-3" style="display: none;">
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>
<strong id="cantidadPersonalLote">0</strong> personales encontrados para el lote
</div>
<div class="table-responsive">
<table class="table table-sm table-bordered">
<thead>
<tr>
<th><input type="checkbox" id="selectAllLote" onclick="toggleSelectAllLote()"></th>
<th>ID</th>
<th>Nombre</th>
<th>DNI</th>
<th>Empresa</th>
<th>Sucursal</th>
<th>Vencimiento</th>
</tr>
</thead>
<tbody id="tablaPersonalLote">
</tbody>
</table>
</div>
</div>
</div>  <!-- ✅ CIERRA contenidoLotes -->
</div>  <!-- ✅ CIERRA section-box -->
<?php endif; ?>
<!-- ✅ FILTROS DE BÚSQUEDA AVANZADOS - CON COLLAPSE -->
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
<!-- Filtros Principales -->
<div class="col-md-3">
<label class="form-label">Empresa</label>
<select name="filtro_empresa" class="form-select" id="filtroEmpresaSelect">
<option value="0">Todas las empresas</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo $filtro_empresa == $empresa['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Sucursal</label>
<select name="filtro_sucursal" class="form-select" id="filtroSucursalSelect">
<option value="0">Todas las sucursales</option>
<?php foreach ($sucursales_filtro as $sucursal): ?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo $filtro_sucursal == $sucursal['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($sucursal['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Cargo</label>
<select name="filtro_cargo" class="form-select">
<option value="">Todos los cargos</option>
<?php foreach ($cargos_disponibles as $cargo_opcion): ?>
<option value="<?php echo $cargo_opcion; ?>" <?php echo (isset($_GET['filtro_cargo']) && $_GET['filtro_cargo'] == $cargo_opcion) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($cargo_opcion); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Modalidad de Contrato</label>
<select name="filtro_modalidad" class="form-select">
<option value="">Todas las modalidades</option>
<?php foreach ($modalidades_contrato as $modalidad): ?>
<option value="<?php echo $modalidad['codigo']; ?>" <?php echo (isset($_GET['filtro_modalidad']) && $_GET['filtro_modalidad'] == $modalidad['codigo']) ? 'selected' : ''; ?>>
<?php echo $modalidad['codigo']; ?> - <?php echo htmlspecialchars(substr($modalidad['descripcion'], 0, 40)); ?>...
</option>
<?php endforeach; ?>
</select>
</div>
<!-- Filtros de Estado -->
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="filtro_activo" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_activo']) && $_GET['filtro_activo'] == '1') ? 'selected' : ''; ?>>Activos</option>
<option value="0" <?php echo (isset($_GET['filtro_activo']) && $_GET['filtro_activo'] == '0') ? 'selected' : ''; ?>>Inactivos</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Estado Doc.</label>
<select name="filtro_estado_documentacion" class="form-select">
<option value="">Todos</option>
<option value="pendiente" <?php echo $filtro_estado_documentacion == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
<option value="aprobada" <?php echo $filtro_estado_documentacion == 'aprobada' ? 'selected' : ''; ?>>Aprobada</option>
<option value="rechazada" <?php echo $filtro_estado_documentacion == 'rechazada' ? 'selected' : ''; ?>>Rechazada</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Vencimiento</label>
<select name="filtro_vencimiento" class="form-select">
<option value="">Todos</option>
<option value="vigente" <?php echo $filtro_vencimiento == 'vigente' ? 'selected' : ''; ?>>Vigente</option>
<option value="proximo" <?php echo $filtro_vencimiento == 'proximo' ? 'selected' : ''; ?>>Próximo (30 días)</option>
<option value="vencido" <?php echo $filtro_vencimiento == 'vencido' ? 'selected' : ''; ?>>Vencido</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Revalidación</label>
<select name="filtro_revalidacion" class="form-select">
<option value="">Todos</option>
<option value="vigente" <?php echo $filtro_revalidacion == 'vigente' ? 'selected' : ''; ?>>Vigente</option>
<option value="proximo" <?php echo $filtro_revalidacion == 'proximo' ? 'selected' : ''; ?>>Próximo</option>
<option value="vencido" <?php echo $filtro_revalidacion == 'vencido' ? 'selected' : ''; ?>>Vencido</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Credencial</label>
<select name="filtro_credencial" class="form-select">
<option value="">Todos</option>
<option value="pagada" <?php echo $filtro_credencial == 'pagada' ? 'selected' : ''; ?>>Pagada</option>
<option value="pendiente" <?php echo $filtro_credencial == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
</select>
</div>
<!-- Filtros de Certificaciones -->
<div class="col-md-2">
<label class="form-label">Certificado</label>
<select name="filtro_certificado" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_certificado']) && $_GET['filtro_certificado'] == '1') ? 'selected' : ''; ?>>Con Certificado</option>
<option value="0" <?php echo (isset($_GET['filtro_certificado']) && $_GET['filtro_certificado'] == '0') ? 'selected' : ''; ?>>Sin Certificado</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Arma Autorizada</label>
<select name="filtro_arma" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_arma']) && $_GET['filtro_arma'] == '1') ? 'selected' : ''; ?>>Con Arma</option>
<option value="0" <?php echo (isset($_GET['filtro_arma']) && $_GET['filtro_arma'] == '0') ? 'selected' : ''; ?>>Sin Arma</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Ant. Penales</label>
<select name="filtro_penales" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_penales']) && $_GET['filtro_penales'] == '1') ? 'selected' : ''; ?>>Con Antecedentes</option>
<option value="0" <?php echo (isset($_GET['filtro_penales']) && $_GET['filtro_penales'] == '0') ? 'selected' : ''; ?>>Sin Antecedentes</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Ant. Provinciales</label>
<select name="filtro_prov" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_prov']) && $_GET['filtro_prov'] == '1') ? 'selected' : ''; ?>>Con Antecedentes</option>
<option value="0" <?php echo (isset($_GET['filtro_prov']) && $_GET['filtro_prov'] == '0') ? 'selected' : ''; ?>>Sin Antecedentes</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Tiene CLU</label>
<select name="filtro_clu" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_clu']) && $_GET['filtro_clu'] == '1') ? 'selected' : ''; ?>>Con CLU</option>
<option value="0" <?php echo (isset($_GET['filtro_clu']) && $_GET['filtro_clu'] == '0') ? 'selected' : ''; ?>>Sin CLU</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">RAM Pagado</label>
<select name="filtro_ram" class="form-select">
<option value="">Todos</option>
<option value="1" <?php echo (isset($_GET['filtro_ram']) && $_GET['filtro_ram'] == '1') ? 'selected' : ''; ?>>Pagado</option>
<option value="0" <?php echo (isset($_GET['filtro_ram']) && $_GET['filtro_ram'] == '0') ? 'selected' : ''; ?>>Pendiente</option>
</select>
</div>
<!-- Filtros de Texto y Fecha -->
<div class="col-md-4">
<label class="form-label">Búsqueda por Texto</label>
<input type="text" name="filtro_texto" class="form-control"
value="<?php echo htmlspecialchars($filtro_texto); ?>"
placeholder="Nombre, apellido, DNI, email...">
</div>
<div class="col-md-2">
<label class="form-label">Desde (Ingreso)</label>
<input type="date" name="filtro_desde" class="form-control"
value="<?php echo isset($_GET['filtro_desde']) ? htmlspecialchars($_GET['filtro_desde']) : ''; ?>">
</div>
<div class="col-md-2">
<label class="form-label">Hasta (Ingreso)</label>
<input type="date" name="filtro_hasta" class="form-control"
value="<?php echo isset($_GET['filtro_hasta']) ? htmlspecialchars($_GET['filtro_hasta']) : ''; ?>">
</div>
<div class="col-md-2">
<label class="form-label">Edad Mínima</label>
<input type="number" name="filtro_edad_min" class="form-control"
value="<?php echo isset($_GET['filtro_edad_min']) ? htmlspecialchars($_GET['filtro_edad_min']) : ''; ?>"
placeholder="Ej: 21" min="18" max="100">
</div>
<div class="col-md-2">
<label class="form-label">Edad Máxima</label>
<input type="number" name="filtro_edad_max" class="form-control"
value="<?php echo isset($_GET['filtro_edad_max']) ? htmlspecialchars($_GET['filtro_edad_max']) : ''; ?>"
placeholder="Ej: 65" min="18" max="100">
</div>
<!-- Botones de Acción -->
<div class="col-12">
<div class="d-flex gap-2 justify-content-end">
<a href="personal.php" class="btn btn-outline-secondary">
<i class="fas fa-eraser me-1"></i>Limpiar Filtros
</a>
<button type="submit" class="btn btn-primary">
<i class="fas fa-search me-1"></i>Buscar
</button>
<button type="button" class="btn btn-success" onclick="exportarFiltros()">
<i class="fas fa-file-export me-1"></i>Exportar a PDF
</button>
</div>
</div>
</form>
<!-- Resumen de Filtros Activos -->
<?php
$filtros_activos = [];
if ($filtro_empresa > 0) $filtros_activos[] = "Empresa: " . ($empresas[array_search($filtro_empresa, array_column($empresas, 'id'))]['nombre'] ?? '');
if ($filtro_sucursal > 0) $filtros_activos[] = "Sucursal";
if (isset($_GET['filtro_cargo']) && !empty($_GET['filtro_cargo'])) $filtros_activos[] = "Cargo";
if (isset($_GET['filtro_activo']) && $_GET['filtro_activo'] !== '') $filtros_activos[] = "Estado: " . ($_GET['filtro_activo'] == '1' ? 'Activo' : 'Inactivo');
if (!empty($filtro_estado_documentacion)) $filtros_activos[] = "Doc: " . ucfirst($filtro_estado_documentacion);
if (!empty($filtro_vencimiento)) $filtros_activos[] = "Vencimiento: " . ucfirst($filtro_vencimiento);
if (isset($_GET['filtro_certificado']) && $_GET['filtro_certificado'] !== '') $filtros_activos[] = "Certificado";
if (!empty($filtro_texto)) $filtros_activos[] = "Texto: " . $filtro_texto;
if (!empty($filtros_activos)):
?>
<div class="alert alert-info mt-3 mb-0">
<i class="fas fa-info-circle me-2"></i>
<strong>Filtros activos:</strong> <?php echo implode(' | ', $filtros_activos); ?>
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> resultados</span>
</div>
<?php endif; ?>
</div>  <!-- ✅ CIERRA contenidoFiltros -->
</div>  <!-- ✅ CIERRA section-box -->
<!-- ✅ NUEVO PERSONAL - CON COLLAPSE Y TABS -->
<div class="section-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="d-flex justify-content-between align-items-center section-title"
data-bs-toggle="collapse"
data-bs-target="#nuevoPersonalForm"
style="cursor: pointer;"
title="Clic para mostrar/ocultar formulario">
<span><i class="fas fa-plus-circle me-2"></i>Nuevo Personal</span>
<div class="d-flex align-items-center gap-2">
<i class="fas fa-chevron-down" id="iconoNuevoPersonal"></i>
</div>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto, expandido si hay edición) -->
<div class="collapse mt-3 <?php echo $personal_edit ? 'show' : ''; ?>" id="nuevoPersonalForm">
<h5 class="mb-3"><i class="fas fa-user-plus me-2"></i><?php echo $personal_edit ? 'Editar' : 'Registrar Nuevo'; ?> Personal</h5>
<form method="POST" action="" class="row g-3 needs-validation" novalidate enctype="multipart/form-data">
<?php if ($personal_edit): ?>
<input type="hidden" name="personal_id" value="<?php echo $personal_edit['id']; ?>">
<?php endif; ?>
<!-- NAV TABS -->
<ul class="nav nav-tabs mb-3" id="personalTabs" role="tablist">
<li class="nav-item" role="presentation">
<button class="nav-link active" id="datos-personales-tab" data-bs-toggle="tab" data-bs-target="#datos-personales" type="button" role="tab">Datos Personales</button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="laboral-tab" data-bs-toggle="tab" data-bs-target="#laboral" type="button" role="tab">Laboral y Contratación</button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="certificaciones-tab" data-bs-toggle="tab" data-bs-target="#certificaciones" type="button" role="tab">Certificaciones y Vencimientos</button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="emergencia-tab" data-bs-toggle="tab" data-bs-target="#emergencia" type="button" role="tab">Contacto de Emergencia y Salud</button>
</li>
</ul>
<!-- TAB CONTENT -->
<div class="tab-content" id="personalTabsContent">
<!-- TAB 1: DATOS PERSONALES -->
<div class="tab-pane fade show active" id="datos-personales" role="tabpanel">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Estado</label>
<div class="row g-2">
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="activo" id="activo" <?php echo ($personal_edit['activo'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="activo">Activo</label>
</div>
</div>
</div>
</div>
<div class="col-md-6">
<label class="form-label">Nombre <span class="text-danger">*</span></label>
<input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($personal_edit['nombre'] ?? (isset($form_data['nombre']) ? $form_data['nombre'] : '')); ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Apellido <span class="text-danger">*</span></label>
<input type="text" name="apellido" class="form-control" value="<?php echo htmlspecialchars($personal_edit['apellido'] ?? (isset($form_data['apellido']) ? $form_data['apellido'] : '')); ?>" required>
</div>
<div class="col-md-3">
<label class="form-label">Sexo</label>
<select name="sexo" class="form-select">
<option value="">Seleccionar...</option>
<option value="Masculino" <?php echo ($personal_edit && $personal_edit['sexo'] === 'Masculino') ? 'selected' : ''; ?>>Masculino</option>
<option value="Femenino" <?php echo ($personal_edit && $personal_edit['sexo'] === 'Femenino') ? 'selected' : ''; ?>>Femenino</option>
<option value="Otro" <?php echo ($personal_edit && $personal_edit['sexo'] === 'Otro') ? 'selected' : ''; ?>>Otro</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Estado Civil</label>
<select name="estado_civil" class="form-select">
<option value="">Seleccionar...</option>
<option value="Soltero/a" <?php echo ($personal_edit && $personal_edit['estado_civil'] === 'Soltero/a') ? 'selected' : ''; ?>>Soltero/a</option>
<option value="Casado/a" <?php echo ($personal_edit && $personal_edit['estado_civil'] === 'Casado/a') ? 'selected' : ''; ?>>Casado/a</option>
<option value="Divorciado/a" <?php echo ($personal_edit && $personal_edit['estado_civil'] === 'Divorciado/a') ? 'selected' : ''; ?>>Divorciado/a</option>
<option value="Viudo/a" <?php echo ($personal_edit && $personal_edit['estado_civil'] === 'Viudo/a') ? 'selected' : ''; ?>>Viudo/a</option>
<option value="Unión de Hecho" <?php echo ($personal_edit && $personal_edit['estado_civil'] === 'Unión de Hecho') ? 'selected' : ''; ?>>Unión de Hecho</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Lugar de Nacimiento</label>
<input type="text" name="lugar_nacimiento" class="form-control" value="<?php echo htmlspecialchars($personal_edit['lugar_nacimiento'] ?? (isset($form_data['lugar_nacimiento']) ? $form_data['lugar_nacimiento'] : '')); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Grupo Sanguíneo</label>
<select name="grupo_sanguineo" class="form-select">
<option value="">Seleccionar...</option>
<option value="A+" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'A+') ? 'selected' : ''; ?>>A+</option>
<option value="A-" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'A-') ? 'selected' : ''; ?>>A-</option>
<option value="B+" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'B+') ? 'selected' : ''; ?>>B+</option>
<option value="B-" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'B-') ? 'selected' : ''; ?>>B-</option>
<option value="AB+" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'AB+') ? 'selected' : ''; ?>>AB+</option>
<option value="AB-" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'AB-') ? 'selected' : ''; ?>>AB-</option>
<option value="O+" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'O+') ? 'selected' : ''; ?>>O+</option>
<option value="O-" <?php echo ($personal_edit && $personal_edit['grupo_sanguineo'] === 'O-') ? 'selected' : ''; ?>>O-</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">CUIL <span class="text-danger">*</span></label>
<input type="text" name="dni" class="form-control" placeholder="00-00000000-0" pattern="\d{2}-\d{8}-\d{1}" required value="<?php echo htmlspecialchars($personal_edit['dni'] ?? (isset($form_data['dni']) ? $form_data['dni'] : '')); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Fecha de Nacimiento</label>
<input type="date" name="fecha_nacimiento" class="form-control" value="<?php echo $personal_edit['fecha_nacimiento'] ?? (isset($form_data['fecha_nacimiento']) ? $form_data['fecha_nacimiento'] : ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($personal_edit['telefono'] ?? (isset($form_data['telefono']) ? $form_data['telefono'] : '')); ?>" placeholder="Ej: 280-123-4567">
</div>
<div class="col-md-6">
<label class="form-label">Domicilio</label>
<input type="text" name="domicilio" class="form-control" value="<?php echo htmlspecialchars($personal_edit['domicilio'] ?? (isset($form_data['domicilio']) ? $form_data['domicilio'] : '')); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($personal_edit['email'] ?? (isset($form_data['email']) ? $form_data['email'] : '')); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Estudios Cursados</label>
<select name="estudios_cursados" class="form-select">
<option value="">Seleccionar nivel educativo</option>
<option value="Primario Completo" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Primario Completo') ? 'selected' : ''; ?>>Primario Completo</option>
<option value="Primario Incompleto" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Primario Incompleto') ? 'selected' : ''; ?>>Primario Incompleto</option>
<option value="Secundario Completo" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Secundario Completo') ? 'selected' : ''; ?>>Secundario Completo</option>
<option value="Secundario Incompleto" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Secundario Incompleto') ? 'selected' : ''; ?>>Secundario Incompleto</option>
<option value="Superior Completo" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Superior Completo') ? 'selected' : ''; ?>>Superior Completo</option>
<option value="Superior Incompleto" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Superior Incompleto') ? 'selected' : ''; ?>>Superior Incompleto</option>
<option value="Universitario Completo" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Universitario Completo') ? 'selected' : ''; ?>>Universitario Completo</option>
<option value="Universitario Incompleto" <?php echo ($personal_edit && $personal_edit['estudios_cursados'] === 'Universitario Incompleto') ? 'selected' : ''; ?>>Universitario Incompleto</option>
</select>
</div>
</div>
</div>
<!-- TAB 2: LABORAL Y CONTRATACIÓN -->
<div class="tab-pane fade" id="laboral" role="tabpanel">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Estado</label>
<div class="row g-2">
<div class="col-md-3 mb-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="baja" id="baja"
<?php echo ($personal_edit['baja'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="baja">Tiene Baja</label>
</div>
</div>
<!-- Contenedor de campos (oculto por defecto) -->
<div id="contenedor-baja" class="row" style="display: <?php echo ($personal_edit['baja'] ?? 0) ? 'flex' : 'none'; ?>;">
<div class="col-md-4">
<label class="form-label">Fecha de Baja</label>
<input type="date" name="fecha_baja" class="form-control"
value="<?php echo $personal_edit['fecha_baja'] ?? (isset($form_data['fecha_baja']) ? $form_data['fecha_baja'] : ''); ?>">
</div>
<div class="col-md-8">
<label class="form-label">Nota de Baja</label>
<textarea name="nota_baja" class="form-control" rows="3"><?php echo htmlspecialchars($personal_edit['nota_baja'] ?? (isset($form_data['nota_baja']) ? $form_data['nota_baja'] : '')); ?></textarea>
</div>
</div>
</div>
</div>
<div class="col-md-6">
<label class="form-label">Empresa <span class="text-danger"></span></label>
<select name="empresa_id" class="form-select" id="empresaSelect">
<option value="">Seleccione una empresa...</option>
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($personal_edit && $personal_edit['empresa_id'] == $empresa['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($empresa['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal <span class="text-danger"></span></label>
<select name="sucursal_id" class="form-select" id="sucursalSelect">
<option value="">Seleccione una sucursal...</option>
<?php foreach ($sucursales as $sucursal): ?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($personal_edit && $personal_edit['sucursal_id'] == $sucursal['id']) ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($sucursal['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Cargo <span class="text-danger"></span></label>
<select name="cargo" class="form-select">
<option value="">Seleccione un cargo...</option>
<?php foreach ($cargos_disponibles as $cargo_opcion): ?>
<option value="<?php echo $cargo_opcion; ?>" <?php echo ($personal_edit && $personal_edit['cargo'] == $cargo_opcion) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cargo_opcion); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Ingreso <span class="text-danger"></span></label>
<input type="date" name="fecha_ingreso" class="form-control" value="<?php echo $personal_edit['fecha_ingreso'] ?? (isset($form_data['fecha_ingreso']) ? $form_data['fecha_ingreso'] : ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Modalidad de Contrato</label>
<select name="modalidad_contrato" class="form-select">
<option value="">Seleccione modalidad...</option>
<?php foreach ($modalidades_contrato as $modalidad): ?>
<option value="<?php echo $modalidad['codigo']; ?>" <?php echo ($personal_edit && $personal_edit['modalidad_contrato'] == $modalidad['codigo']) ? 'selected' : ''; ?>>
<?php echo $modalidad['codigo']; ?> - <?php echo htmlspecialchars($modalidad['descripcion']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Nota A-2<span class="text-danger"></span></label>
<input type="text" name="num_nota" class="form-control" value="<?php echo htmlspecialchars($personal_edit['num_nota'] ?? (isset($form_data['num_nota']) ? $form_data['num_nota'] : '')); ?>" placeholder="Ej: NOTA 123/2024">
</div>
<div class="col-md-4">
<label class="form-label">Autorización A-2</label>
<input type="date" name="fecha_autorizacion" class="form-control" value="<?php echo $personal_edit['fecha_autorizacion'] ?? (isset($form_data['fecha_autorizacion']) ? $form_data['fecha_autorizacion'] : ''); ?>">
</div>
</div>
</div>
<!-- TAB 3: CERTIFICACIONES Y VENCIMIENTOS -->
<div class="tab-pane fade" id="certificaciones" role="tabpanel">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Estado</label>
<div class="row g-2">
<!-- Checkbox Interruptor -->
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_certificado" id="tiene_certificado"
<?php echo ($personal_edit['tiene_certificado'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_certificado">Certificado Vigilador</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_penales" id="tiene_penales" <?php echo ($personal_edit['tiene_penales'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_penales">Antecedentes Penales</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="antecedentes_provinciales" id="antecedentes_provinciales" <?php echo ($personal_edit['antecedentes_provinciales'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="antecedentes_provinciales">Antecedentes Provinciales</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="examen_dt" id="examen_dt" <?php echo ($personal_edit['examen_dt'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="examen_dt">Examen D.T.</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="ram" id="ram" <?php echo ($personal_edit['ram'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="ram">RAM</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="pago_credencial" id="pago_credencial" <?php echo ($personal_edit['pago_credencial'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="pago_credencial">Credencial Pagada</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="clu_numero" id="clu_numero" <?php echo ($personal_edit['clu_numero'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="clu_numero">Tiene CLU</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="inhibicion_bienes" id="inhibicion_bienes" <?php echo ($personal_edit['inhibicion_bienes'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="inhibicion_bienes">Inhibición de Bienes</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="habilitacion_comercial" id="habilitacion_comercial" <?php echo ($personal_edit['habilitacion_comercial'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="habilitacion_comercial">Habilitación Comercial</label>
</div>
</div>
</div>
</div>
<!-- Campos Ocultos (se mostrarán si el checkbox está activado) -->
<div id="campos-certificado" class="row g-3 d-none">
<div class="col-md-4">
<label class="form-label">Instituto Curso Vigilador</label>
<input type="text" name="instituto_nombre" class="form-control"
value="<?php echo htmlspecialchars($personal_edit['instituto_nombre'] ?? (isset($form_data['instituto_nombre']) ? $form_data['instituto_nombre'] : '')); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Finalización Cursos Vigilador</label>
<input type="number" name="ano_finalizacion" id="ano_finalizacion" class="form-control" min="1950" max="2100"
value="<?php echo htmlspecialchars($personal_edit['ano_finalizacion'] ?? (isset($form_data['ano_finalizacion']) ? $form_data['ano_finalizacion'] : '')); ?>">
</div>
<!-- Campo Fecha Revalidación -->
<div class="col-md-4">
<label class="form-label">Revalidación Curso</label>
<input type="date" name="fecha_revalidacion" id="fecha_revalidacion" class="form-control"
value="<?php echo htmlspecialchars($personal_edit['fecha_revalidacion'] ?? (isset($form_data['fecha_revalidacion']) ? $form_data['fecha_revalidacion'] : '')); ?>">
</div>
</div>
<div id="contenedorFiltrosCredencial" class="row d-none">
<div class="col-md-4">
<label class="form-label">Fecha de Pago Credencial</label>
<input type="date" name="fecha_pago_credencial" id="fecha_pago_credencial" class="form-control" value="<?php echo $personal_edit['fecha_pago_credencial'] ?? (isset($form_data['fecha_pago_credencial']) ? $form_data['fecha_pago_credencial'] : ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Cupón Pago Credencial</label>
<input type="file" name="cupon_pago_credencial" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
<?php if (!empty($personal_edit['cupon_pago_credencial'])): ?>
<small class="text-muted"><a href="../uploads/cupones_credencial/<?php echo htmlspecialchars($personal_edit['cupon_pago_credencial']); ?>" target="_blank">Ver actual</a></small>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">Vencimiento Credencial</label>
<input type="date" name="fecha_vencimiento" id="fecha_vencimiento" class="form-control" value="<?php echo $personal_edit['fecha_vencimiento'] ?? (isset($form_data['fecha_vencimiento']) ? $form_data['fecha_vencimiento'] : ''); ?>">
</div>
</div>
<div class="col-12">
<label class="form-label">Archivos Adjuntos</label>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Foto Carnet</label>
<input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png">
<?php if (!empty($personal_edit['foto'])): ?>
<small class="text-muted"><a href="../uploads/fotos_personal/<?php echo htmlspecialchars($personal_edit['foto']); ?>" target="_blank">Ver actual</a></small>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">PDF Datos Personales</label>
<input type="file" name="pdf_datos_personales" class="form-control" accept=".pdf">
<?php if (!empty($personal_edit['pdf_datos_personales'])): ?>
<small class="text-muted"><a href="../uploads/pdf_personal/<?php echo htmlspecialchars($personal_edit['pdf_datos_personales']); ?>" target="_blank">Ver actual</a></small>
<?php endif; ?>
</div>
</div>
</div>
</div>
</div>
<!-- TAB 4: CONTACTO DE EMERGENCIA Y SALUD -->
<div class="tab-pane fade" id="emergencia" role="tabpanel">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Nombre Contacto Emergencia</label>
<input type="text" name="contacto_emergencia_nombre" class="form-control" value="<?php echo htmlspecialchars($personal_edit['contacto_emergencia_nombre'] ?? (isset($form_data['contacto_emergencia_nombre']) ? $form_data['contacto_emergencia_nombre'] : '')); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Teléfono Contacto Emergencia</label>
<input type="text" name="contacto_emergencia_telefono" class="form-control" value="<?php echo htmlspecialchars($personal_edit['contacto_emergencia_telefono'] ?? (isset($form_data['contacto_emergencia_telefono']) ? $form_data['contacto_emergencia_telefono'] : '')); ?>" placeholder="Ej: 280-123-4567">
</div>
<div class="col-md-4">
<label class="form-label">Parentesco</label>
<input type="text" name="contacto_emergencia_parentesco" class="form-control" value="<?php echo htmlspecialchars($personal_edit['contacto_emergencia_parentesco'] ?? (isset($form_data['contacto_emergencia_parentesco']) ? $form_data['contacto_emergencia_parentesco'] : '')); ?>" placeholder="Ej: Esposo/a, Hijo/a">
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="observaciones" class="form-control" rows="4"><?php echo htmlspecialchars($personal_edit['observaciones'] ?? (isset($form_data['observaciones']) ? $form_data['observaciones'] : '')); ?></textarea>
</div>
</div>
</div>
</div>
</div>
<div class="col-12 text-end mt-3">
<button type="submit" name="guardar_personal" class="btn btn-success btn-lg px-5">
<i class="fas fa-save me-2"></i><?php echo $personal_edit ? 'Actualizar Personal' : 'Guardar Personal'; ?>
</button>
<?php if ($personal_edit): ?>
<a href="personal.php" class="btn btn-secondary btn-lg px-5 ms-2">
<i class="fas fa-times me-2"></i>Cancelar
</a>
<?php endif; ?>
</div>
</form>
</div>  <!-- ✅ CIERRA nuevoPersonalForm -->
</div>  <!-- ✅ CIERRA section-box -->
<!-- LISTADO DE PERSONAL -->
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<div>
<i class="fas fa-table me-2"></i>Listado de Personal
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<form method="GET" action="" class="d-flex align-items-center gap-2">
<input type="hidden" name="filtro_empresa" value="<?php echo $filtro_empresa; ?>">
<input type="hidden" name="filtro_sucursal" value="<?php echo $filtro_sucursal; ?>">
<input type="hidden" name="filtro_texto" value="<?php echo htmlspecialchars($filtro_texto); ?>">
<input type="hidden" name="filtro_estado_documentacion" value="<?php echo $filtro_estado_documentacion; ?>">
<input type="hidden" name="filtro_vencimiento" value="<?php echo $filtro_vencimiento; ?>">
<input type="hidden" name="orden" value="<?php echo $columna_orden; ?>">
<input type="hidden" name="direccion" value="<?php echo $direccion_orden; ?>">
<input type="hidden" name="pagina" value="1">
<label class="form-label mb-0 small">Mostrar:</label>
<select name="registros_por_pagina" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
<option value="10" <?php echo $registros_por_pagina == 10 ? 'selected' : ''; ?>>10</option>
<option value="30" <?php echo $registros_por_pagina == 30 ? 'selected' : ''; ?>>30</option>
<option value="50" <?php echo $registros_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
</select>
</form>
</div>
<?php if (empty($personal_list)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-users fa-4x text-muted mb-3"></i>
<h5>No hay personal registrado</h5>
<p class="text-muted">Registra tu primer empleado para comenzar.</p>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevoPersonalForm">
<i class="fas fa-plus me-2"></i>Crear Primer Empleado
</button>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
<thead>
<tr>
<th><a href="<?php echo generarUrlOrden('id', $direccion_orden, $columna_orden); ?>" class="text-decoration-none text-dark">ID <?php echo iconoOrden('id', $columna_orden, $direccion_orden); ?></a></th>
<th>Foto</th>
<th><a href="<?php echo generarUrlOrden('apellido', $direccion_orden, $columna_orden); ?>" class="text-decoration-none text-dark">Nombre <?php echo iconoOrden('apellido', $columna_orden, $direccion_orden); ?></a></th>
<th><a href="<?php echo generarUrlOrden('dni', $direccion_orden, $columna_orden); ?>" class="text-decoration-none text-dark">CUIL <?php echo iconoOrden('dni', $columna_orden, $direccion_orden); ?></a></th>
<th><a href="<?php echo generarUrlOrden('cargo', $direccion_orden, $columna_orden); ?>" class="text-decoration-none text-dark">Cargo <?php echo iconoOrden('cargo', $columna_orden, $direccion_orden); ?></a></th>
<th><a href="<?php echo generarUrlOrden('empresa_nombre', $direccion_orden, $columna_orden); ?>" class="text-decoration-none text-dark">Empresa <?php echo iconoOrden('empresa_nombre', $columna_orden, $direccion_orden); ?></a></th>
<th><a href="<?php echo generarUrlOrden('sucursal_nombre', $direccion_orden, $columna_orden); ?>" class="text-decoration-none text-dark">Sucursal <?php echo iconoOrden('sucursal_nombre', $columna_orden, $direccion_orden); ?></a></th>
<th>Estado</th>
<th>Vencimientos</th>
<th>Estado Doc.</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($personal_list as $persona):
$estado = !empty($persona['activo']) ? 'activo' : 'inactivo';
$vencimiento_vencido = false;
$vencimiento_proximo = false;
if (!empty($persona['fecha_vencimiento'])) {
$fecha_venc = new DateTime($persona['fecha_vencimiento']);
$hoy = new DateTime();
$diff = $hoy->diff($fecha_venc);
if ($hoy > $fecha_venc) {
$vencimiento_vencido = true;
} elseif ($diff->days <= 30) {
$vencimiento_proximo = true;
}
}
$revalidacion_vencida = false;
$revalidacion_proxima = false;
if (!empty($persona['fecha_revalidacion'])) {
$fecha_reval = new DateTime($persona['fecha_revalidacion']);
$hoy = new DateTime();
$diff = $hoy->diff($fecha_reval);
if ($hoy > $fecha_reval) {
$revalidacion_vencida = true;
} elseif ($diff->days <= 30) {
$revalidacion_proxima = true;
}
}
$credencial_pagada = !empty($persona['pago_credencial']);
$activo_value = $persona['activo'] ? '1' : '0';
$vencimiento_status = $vencimiento_vencido ? 'vencido' : ($vencimiento_proximo ? 'proximo' : 'vigente');
$revalidacion_status = $revalidacion_vencida ? 'vencido' : ($revalidacion_proxima ? 'proximo' : 'vigente');
$credencial_status = $credencial_pagada ? 'pagada' : 'pendiente';
$estado_doc = $persona['estado_documentacion'] ?? 'pendiente';
$fecha_revision = !empty($persona['fecha_revision_documentacion']) ? $persona['fecha_revision_documentacion'] : null;
?>
<tr>
<td><strong>#<?php echo $persona['id']; ?></strong></td>
<td>
<?php if (!empty($persona['foto'])): ?>
<img src="../uploads/fotos_personal/<?php echo htmlspecialchars($persona['foto']); ?>" class="foto-thumbnail" alt="Foto">
<?php else: ?>
<div class="foto-thumbnail bg-light d-flex align-items-center justify-content-center">
<i class="fas fa-user text-muted"></i>
</div>
<?php endif; ?>
</td>
<td><strong><?php echo htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre']); ?></strong></td>
<td><?php echo htmlspecialchars($persona['dni']); ?></td>
<td><span class="badge bg-info"><?php echo htmlspecialchars($persona['cargo']); ?></span></td>
<td><?php echo htmlspecialchars($persona['empresa_nombre'] ?? 'N/A'); ?></td>
<td><?php echo htmlspecialchars($persona['sucursal_nombre'] ?? 'N/A'); ?></td>
<td>
<span class="badge badge-activo-<?php echo $estado === 'activo' ? 'true' : 'false'; ?>">
<i class="fas fa-<?php echo $estado === 'activo' ? 'check-circle' : 'times-circle'; ?>"></i>
<?php echo $estado === 'activo' ? 'Activo' : 'Inactivo'; ?>
</span>
</td>
<td>
<div class="vencimientos-container">
<div class="vencimiento-badge vencimiento-<?php echo $revalidacion_vencida ? 'vencido' : ($revalidacion_proxima ? 'proximo' : 'vigente'); ?>">
<i class="fas fa-sync-alt"></i>
<?php echo $revalidacion_vencida ? 'Vencida' : ($revalidacion_proxima ? 'Próxima' : 'Vigente'); ?>
</div>
<div class="vencimiento-badge vencimiento-<?php echo $credencial_pagada ? 'vigente' : 'vencido'; ?>">
<i class="fas fa-credit-card"></i>
<?php echo $credencial_pagada ? 'Pagada' : 'Pendiente'; ?>
</div>
</div>
</td>
<td>
<?php
$doc_class = $estado_doc === 'aprobada' ? 'doc-aprobada' : ($estado_doc === 'rechazada' ? 'doc-rechazada' : 'doc-pendiente');
$doc_icon = $estado_doc === 'aprobada' ? 'check-circle' : ($estado_doc === 'rechazada' ? 'times-circle' : 'clock');
$revisado_por_username = $persona['revisado_por_username'] ?? null;
?>
<span class="badge-estado-documentacion <?php echo $doc_class; ?>" style="padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
<i class="fas fa-<?php echo $doc_icon; ?>"></i>
<?php echo ucfirst($estado_doc); ?>
</span>
<?php if (!empty($fecha_revision)): ?>
<br><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($fecha_revision)); ?></small>
<?php if (!empty($revisado_por_username)): ?>
<br><small class="text-primary"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($revisado_por_username); ?></small>
<?php endif; ?>
<?php endif; ?>
</td>
<td>
<div class="btn-group btn-group-sm">
<button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editarPersonalModal<?php echo $persona['id']; ?>" title="Editar">
<i class="fas fa-edit"></i>
</button>
<?php if (($estado_doc ?? 'pendiente') === 'pendiente'): ?>
<button class="btn btn-sm btn-aprobar me-1 btn-aprobar-doc" data-id="<?php echo $persona['id']; ?>" data-nombre="<?php echo htmlspecialchars($persona['nombre'] . ' ' . $persona['apellido']); ?>" title="Aprobar Documentación">
<i class="fas fa-check"></i>
</button>
<button class="btn btn-sm btn-rechazar me-1 btn-rechazar-doc" data-id="<?php echo $persona['id']; ?>" data-nombre="<?php echo htmlspecialchars($persona['nombre'] . ' ' . $persona['apellido']); ?>" title="Rechazar Documentación">
<i class="fas fa-times"></i>
</button>
<?php else: ?>
<button class="btn btn-sm btn-secondary me-1" disabled title="Ya revisado">
<i class="fas fa-check-double"></i>
</button>
<?php endif; ?>
<?php if ($auth->hasRole('administrador') || $auth->hasRole('carga')): ?>
<?php if ($persona['activo']): ?>
<a href="personal.php?action=generar_qr_personal&id=<?php echo $persona['id']; ?>" class="btn btn-sm btn-qr me-1" target="_blank" title="Imprimir Credencial">
<i class="fas fa-id-card"></i>
</a>
<?php else: ?>
<button class="btn btn-sm btn-secondary me-1" disabled title="Personal Inactivo">
<i class="fas fa-id-card"></i>
</button>
<?php endif; ?>
<?php endif; ?>
</div>
</td>
</tr>
<!-- MODAL DE EDICIÓN CON TABS - ACTUALIZADO PARA INCLUIR TODOS LOS CAMPOS DE NUEVO PERSONAL -->
<div class="modal fade" id="editarPersonalModal<?php echo $persona['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Editar Personal: <?php echo htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre']); ?></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form method="POST" action="personal.php" enctype="multipart/form-data">
<input type="hidden" name="guardar_personal" value="1">
<input type="hidden" name="personal_id" value="<?php echo $persona['id']; ?>">
<!-- NAV TABS MODAL -->
<ul class="nav nav-tabs mb-3" id="editPersonalTabs<?php echo $persona['id']; ?>" role="tablist">
<li class="nav-item" role="presentation">
<button class="nav-link active" id="edit-datos-personales-tab<?php echo $persona['id']; ?>" data-bs-toggle="tab" data-bs-target="#edit-datos-personales<?php echo $persona['id']; ?>" type="button" role="tab">Datos Personales</button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="edit-laboral-tab<?php echo $persona['id']; ?>" data-bs-toggle="tab" data-bs-target="#edit-laboral<?php echo $persona['id']; ?>" type="button" role="tab">Laboral y Contratación</button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="edit-certificaciones-tab<?php echo $persona['id']; ?>" data-bs-toggle="tab" data-bs-target="#edit-certificaciones<?php echo $persona['id']; ?>" type="button" role="tab">Certificaciones y Vencimientos</button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="edit-emergencia-tab<?php echo $persona['id']; ?>" data-bs-toggle="tab" data-bs-target="#edit-emergencia<?php echo $persona['id']; ?>" type="button" role="tab">Contacto de Emergencia y Salud</button>
</li>
</ul>
<!-- TAB CONTENT MODAL -->
<div class="tab-content" id="editPersonalTabsContent<?php echo $persona['id']; ?>">
<!-- TAB 1: DATOS PERSONALES - ACTUALIZADO PARA COINCIDIR CON NUEVO PERSONAL -->
<div class="tab-pane fade show active" id="edit-datos-personales<?php echo $persona['id']; ?>" role="tabpanel">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Estado</label>
<div class="row g-2">
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="activo" id="activo_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['activo'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="activo_edit_<?php echo $persona['id']; ?>">Activo</label>
</div>
</div>
</div>
</div>
<div class="col-md-6">
<label class="form-label">Nombre <span class="text-danger">*</span></label>
<input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($persona['nombre']); ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Apellido <span class="text-danger">*</span></label>
<input type="text" name="apellido" class="form-control" value="<?php echo htmlspecialchars($persona['apellido']); ?>" required>
</div>
<div class="col-md-3">
<label class="form-label">Sexo</label>
<select name="sexo" class="form-select">
<option value="">Seleccionar...</option>
<option value="Masculino" <?php echo ($persona['sexo'] ?? '') === 'Masculino' ? 'selected' : ''; ?>>Masculino</option>
<option value="Femenino" <?php echo ($persona['sexo'] ?? '') === 'Femenino' ? 'selected' : ''; ?>>Femenino</option>
<option value="Otro" <?php echo ($persona['sexo'] ?? '') === 'Otro' ? 'selected' : ''; ?>>Otro</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Estado Civil</label>
<select name="estado_civil" class="form-select">
<option value="">Seleccionar...</option>
<option value="Soltero/a" <?php echo ($persona['estado_civil'] ?? '') === 'Soltero/a' ? 'selected' : ''; ?>>Soltero/a</option>
<option value="Casado/a" <?php echo ($persona['estado_civil'] ?? '') === 'Casado/a' ? 'selected' : ''; ?>>Casado/a</option>
<option value="Divorciado/a" <?php echo ($persona['estado_civil'] ?? '') === 'Divorciado/a' ? 'selected' : ''; ?>>Divorciado/a</option>
<option value="Viudo/a" <?php echo ($persona['estado_civil'] ?? '') === 'Viudo/a' ? 'selected' : ''; ?>>Viudo/a</option>
<option value="Unión de Hecho" <?php echo ($persona['estado_civil'] ?? '') === 'Unión de Hecho' ? 'selected' : ''; ?>>Unión de Hecho</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Lugar de Nacimiento</label>
<input type="text" name="lugar_nacimiento" class="form-control" value="<?php echo htmlspecialchars($persona['lugar_nacimiento'] ?? ''); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Grupo Sanguíneo</label>
<select name="grupo_sanguineo" class="form-select">
<option value="">Seleccionar...</option>
<option value="A+" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'A+' ? 'selected' : ''; ?>>A+</option>
<option value="A-" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'A-' ? 'selected' : ''; ?>>A-</option>
<option value="B+" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'B+' ? 'selected' : ''; ?>>B+</option>
<option value="B-" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'B-' ? 'selected' : ''; ?>>B-</option>
<option value="AB+" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'AB+' ? 'selected' : ''; ?>>AB+</option>
<option value="AB-" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'AB-' ? 'selected' : ''; ?>>AB-</option>
<option value="O+" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'O+' ? 'selected' : ''; ?>>O+</option>
<option value="O-" <?php echo ($persona['grupo_sanguineo'] ?? '') === 'O-' ? 'selected' : ''; ?>>O-</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">CUIL <span class="text-danger">*</span></label>
<input type="text" name="dni" class="form-control" placeholder="00-00000000-0" pattern="\d{2}-\d{8}-\d{1}" required value="<?php echo htmlspecialchars($persona['dni'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Fecha de Nacimiento</label>
<input type="date" name="fecha_nacimiento" class="form-control" value="<?php echo htmlspecialchars($persona['fecha_nacimiento'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($persona['telefono'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Domicilio</label>
<input type="text" name="domicilio" class="form-control" value="<?php echo htmlspecialchars($persona['domicilio'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($persona['email'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Estudios Cursados</label>
<select name="estudios_cursados" class="form-select">
<option value="">Seleccionar nivel educativo</option>
<option value="Primario Completo" <?php echo ($persona['estudios_cursados'] ?? '') === 'Primario Completo' ? 'selected' : ''; ?>>Primario Completo</option>
<option value="Primario Incompleto" <?php echo ($persona['estudios_cursados'] ?? '') === 'Primario Incompleto' ? 'selected' : ''; ?>>Primario Incompleto</option>
<option value="Secundario Completo" <?php echo ($persona['estudios_cursados'] ?? '') === 'Secundario Completo' ? 'selected' : ''; ?>>Secundario Completo</option>
<option value="Secundario Incompleto" <?php echo ($persona['estudios_cursados'] ?? '') === 'Secundario Incompleto' ? 'selected' : ''; ?>>Secundario Incompleto</option>
<option value="Superior Completo" <?php echo ($persona['estudios_cursados'] ?? '') === 'Superior Completo' ? 'selected' : ''; ?>>Superior Completo</option>
<option value="Superior Incompleto" <?php echo ($persona['estudios_cursados'] ?? '') === 'Superior Incompleto' ? 'selected' : ''; ?>>Superior Incompleto</option>
<option value="Universitario Completo" <?php echo ($persona['estudios_cursados'] ?? '') === 'Universitario Completo' ? 'selected' : ''; ?>>Universitario Completo</option>
<option value="Universitario Incompleto" <?php echo ($persona['estudios_cursados'] ?? '') === 'Universitario Incompleto' ? 'selected' : ''; ?>>Universitario Incompleto</option>
</select>
</div>
</div>
</div>
<!-- TAB 2: LABORAL Y CONTRATACIÓN - ACTUALIZADO PARA COINCIDIR CON NUEVO PERSONAL -->
<div class="tab-pane fade" id="edit-laboral<?php echo $persona['id']; ?>" role="tabpanel">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Estado</label>
<div class="row g-2">
<div class="col-md-3 mb-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="baja" id="baja_edit_<?php echo $persona['id']; ?>"
<?php echo ($persona['baja'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="baja_edit_<?php echo $persona['id']; ?>">Tiene Baja</label>
</div>
</div>
<!-- Contenedor de campos (oculto por defecto) -->
<div id="contenedor-baja-edit-<?php echo $persona['id']; ?>" class="row" style="display: <?php echo ($persona['baja'] ?? 0) ? 'flex' : 'none'; ?>;">
<div class="col-md-4">
<label class="form-label">Fecha de Baja</label>
<input type="date" name="fecha_baja" class="form-control"
value="<?php echo $persona['fecha_baja'] ?? ''; ?>">
</div>
<div class="col-md-8">
<label class="form-label">Nota de Baja</label>
<textarea name="nota_baja" class="form-control" rows="3"><?php echo htmlspecialchars($persona['nota_baja'] ?? ''); ?></textarea>
</div>
</div>
</div>
</div>
<div class="col-md-6">
<label class="form-label">Empresa <span class="text-danger"></span></label>
<select name="empresa_id" class="form-select modal-empresa-select" id="edit_empresa_<?php echo $persona['id']; ?>" data-persona-id="<?php echo $persona['id']; ?>">
<?php foreach ($empresas as $empresa): ?>
<option value="<?php echo $empresa['id']; ?>" <?php echo ($persona['empresa_id'] == $empresa['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($empresa['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal <span class="text-danger"></span></label>
<select name="sucursal_id" class="form-select modal-sucursal-select" id="edit_sucursal_<?php echo $persona['id']; ?>" data-persona-id="<?php echo $persona['id']; ?>">
<?php
$stmt_suc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = :empresa_id AND activa = TRUE ORDER BY nombre");
$stmt_suc->execute(['empresa_id' => $persona['empresa_id']]);
$sucursales_persona = $stmt_suc->fetchAll();
foreach ($sucursales_persona as $sucursal):
?>
<option value="<?php echo $sucursal['id']; ?>" <?php echo ($persona['sucursal_id'] == $sucursal['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Cargo <span class="text-danger"></span></label>
<select name="cargo" class="form-select" >
<?php foreach ($cargos_disponibles as $cargo_opcion): ?>
<option value="<?php echo $cargo_opcion; ?>" <?php echo ($persona['cargo'] == $cargo_opcion) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cargo_opcion); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Ingreso <span class="text-danger"></span></label>
<input type="date" name="fecha_ingreso" class="form-control" value="<?php echo htmlspecialchars($persona['fecha_ingreso']); ?>" >
</div>
<div class="col-md-4">
<label class="form-label">Modalidad de Contrato</label>
<select name="modalidad_contrato" class="form-select">
<option value="">Seleccione modalidad...</option>
<?php foreach ($modalidades_contrato as $modalidad): ?>
<option value="<?php echo $modalidad['codigo']; ?>" <?php echo ($persona['modalidad_contrato'] ?? '') == $modalidad['codigo'] ? 'selected' : ''; ?>>
<?php echo $modalidad['codigo']; ?> - <?php echo htmlspecialchars($modalidad['descripcion']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Nota A-2 <span class="text-danger"></span></label>
<input type="text" name="num_nota" class="form-control" value="<?php echo htmlspecialchars($persona['num_nota'] ?? ''); ?>" >
</div>
<div class="col-md-4">
<label class="form-label">Autorización A-2</label>
<input type="date" name="fecha_autorizacion" class="form-control" value="<?php echo htmlspecialchars($persona['fecha_autorizacion'] ?? ''); ?>">
</div>
</div>
</div>
<!-- TAB 3: CERTIFICACIONES Y VENCIMIENTOS - ACTUALIZADO PARA COINCIDIR CON NUEVO PERSONAL -->
<div class="tab-pane fade" id="edit-certificaciones<?php echo $persona['id']; ?>" role="tabpanel">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Estado</label>
<div class="row g-2">
<!-- Checkbox Interruptor -->
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_certificado" id="tiene_certificado_edit_<?php echo $persona['id']; ?>"
<?php echo ($persona['tiene_certificado'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_certificado_edit_<?php echo $persona['id']; ?>">Certificado Vigilador</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="tiene_penales" id="tiene_penales_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['tiene_penales'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="tiene_penales_edit_<?php echo $persona['id']; ?>">Antecedentes Penales</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="antecedentes_provinciales" id="antecedentes_provinciales_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['antecedentes_provinciales'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="antecedentes_provinciales_edit_<?php echo $persona['id']; ?>">Antecedentes Provinciales</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="examen_dt" id="examen_dt_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['examen_dt'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="examen_dt_edit_<?php echo $persona['id']; ?>">Examen D.T.</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="ram" id="ram_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['ram'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="ram_edit_<?php echo $persona['id']; ?>">RAM</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="pago_credencial" id="pago_credencial_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['pago_credencial'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="pago_credencial_edit_<?php echo $persona['id']; ?>">Credencial Pagada</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="clu_numero" id="clu_numero_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['clu_numero'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="clu_numero_edit_<?php echo $persona['id']; ?>">Tiene CLU</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="inhibicion_bienes" id="inhibicion_bienes_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['inhibicion_bienes'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="inhibicion_bienes_edit_<?php echo $persona['id']; ?>">Inhibición de Bienes</label>
</div>
</div>
<div class="col-md-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="habilitacion_comercial" id="habilitacion_comercial_edit_<?php echo $persona['id']; ?>" <?php echo ($persona['habilitacion_comercial'] ?? 0) ? 'checked' : ''; ?>>
<label class="form-check-label" for="habilitacion_comercial_edit_<?php echo $persona['id']; ?>">Habilitación Comercial</label>
</div>
</div>
</div>
</div>
<!-- Campos Ocultos (se mostrarán si el checkbox está activado) - ACTUALIZADOS CON IDs ÚNICOS -->
<div id="campos-certificado-edit-<?php echo $persona['id']; ?>" class="row g-3 d-none">
<div class="col-md-4">
<label class="form-label">Instituto Curso Vigilador</label>
<input type="text" name="instituto_nombre" class="form-control"
value="<?php echo htmlspecialchars($persona['instituto_nombre'] ?? ''); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Finalización Cursos Vigilador</label>
<input type="number" name="ano_finalizacion" id="ano_finalizacion_edit_<?php echo $persona['id']; ?>" class="form-control" min="1950" max="2100"
value="<?php echo htmlspecialchars($persona['ano_finalizacion'] ?? ''); ?>">
</div>
<!-- Campo Fecha Revalidación -->
<div class="col-md-4">
<label class="form-label">Revalidación Curso</label>
<input type="date" name="fecha_revalidacion" id="fecha_revalidacion_edit_<?php echo $persona['id']; ?>" class="form-control"
value="<?php echo htmlspecialchars($persona['fecha_revalidacion'] ?? ''); ?>">
</div>
</div>
<div id="contenedorFiltrosCredencial-edit-<?php echo $persona['id']; ?>" class="row d-none">
<div class="col-md-4">
<label class="form-label">Fecha de Pago Credencial</label>
<input type="date" name="fecha_pago_credencial" id="fecha_pago_credencial_edit_<?php echo $persona['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($persona['fecha_pago_credencial'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Cupón Pago Credencial</label>
<input type="file" name="cupon_pago_credencial" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
<?php if (!empty($persona['cupon_pago_credencial'])): ?>
<small class="text-muted"><a href="../uploads/cupones_credencial/<?php echo htmlspecialchars($persona['cupon_pago_credencial']); ?>" target="_blank">Ver actual</a></small>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">Vencimiento Credencial</label>
<input type="date" name="fecha_vencimiento" id="fecha_vencimiento_edit_<?php echo $persona['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($persona['fecha_vencimiento'] ?? ''); ?>">
</div>
</div>
<div class="col-12">
<label class="form-label">Archivos Adjuntos</label>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Foto Carnet</label>
<input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png">
<?php if (!empty($persona['foto'])): ?>
<small class="text-muted"><a href="../uploads/fotos_personal/<?php echo htmlspecialchars($persona['foto']); ?>" target="_blank">Ver actual</a></small>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">PDF Datos Personales</label>
<input type="file" name="pdf_datos_personales" class="form-control" accept=".pdf">
<?php if (!empty($persona['pdf_datos_personales'])): ?>
<small class="text-muted"><a href="../uploads/pdf_personal/<?php echo htmlspecialchars($persona['pdf_datos_personales']); ?>" target="_blank">Ver actual</a></small>
<?php endif; ?>
</div>
</div>
</div>
</div>
</div>
<!-- TAB 4: CONTACTO DE EMERGENCIA Y SALUD - ACTUALIZADO PARA COINCIDIR CON NUEVO PERSONAL -->
<div class="tab-pane fade" id="edit-emergencia<?php echo $persona['id']; ?>" role="tabpanel">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Nombre Contacto Emergencia</label>
<input type="text" name="contacto_emergencia_nombre" class="form-control" value="<?php echo htmlspecialchars($persona['contacto_emergencia_nombre'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Teléfono Contacto Emergencia</label>
<input type="text" name="contacto_emergencia_telefono" class="form-control" value="<?php echo htmlspecialchars($persona['contacto_emergencia_telefono'] ?? ''); ?>" placeholder="Ej: 280-123-4567">
</div>
<div class="col-md-4">
<label class="form-label">Parentesco</label>
<input type="text" name="contacto_emergencia_parentesco" class="form-control" value="<?php echo htmlspecialchars($persona['contacto_emergencia_parentesco'] ?? ''); ?>" placeholder="Ej: Esposo/a, Hijo/a">
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="observaciones" class="form-control" rows="4"><?php echo htmlspecialchars($persona['observaciones'] ?? ''); ?></textarea>
</div>
</div>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
</div>
</form>
</div>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- PAGINACIÓN -->
<?php if ($total_paginas > 1): ?>
<div class="d-flex justify-content-center mt-3">
<nav aria-label="Paginación de personal">
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ✅ Función para exportar filtros a PDF
function exportarFiltros() {
const params = new URLSearchParams(window.location.search);
params.set('action', 'exportar_pdf_filtrado');
window.location.href = 'personal.php?' + params.toString();
}
// ✅ Función para exportar lista seleccionada a PDF
function exportarListaSeleccionadaPDF() {
const selectedIds = [];
document.querySelectorAll('.select-personal-lote:checked').forEach(checkbox => {
selectedIds.push(checkbox.value);
});
if (selectedIds.length === 0) {
Swal.fire({
icon: 'warning',
title: 'Advertencia',
text: 'Seleccione al menos un personal para exportar'
});
return;
}
Swal.fire({
title: 'Exportar Lista',
html: `Se exportará una lista en PDF con <strong>${selectedIds.length}</strong> personal(es) seleccionado(s).`,
icon: 'info',
showCancelButton: true,
confirmButtonText: 'Exportar PDF',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#6f42c1'
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'personal.php';
const inputAction = document.createElement('input');
inputAction.type = 'hidden';
inputAction.name = 'action';
inputAction.value = 'exportar_personal_seleccionado_pdf';
form.appendChild(inputAction);
selectedIds.forEach(id => {
const input = document.createElement('input');
input.type = 'hidden';
input.name = 'personal_ids[]';
input.value = id;
form.appendChild(input);
});
document.body.appendChild(form);
form.submit();
}
});
}
// ✅ Funciones para impresión por lotes - CORREGIDAS
let personalParaLote = [];
function cargarSucursalesLote(empresaId) {
const sucursalSelect = document.getElementById('loteSucursalSelect');
if (!sucursalSelect) return;
if (!empresaId || empresaId == '0') {
sucursalSelect.innerHTML = '<option value="0">Todas las sucursales</option>';
return;
}
fetch(`personal.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
sucursalSelect.innerHTML = '<option value="0">Todas las sucursales</option>';
const sucursales = data.data || [];
sucursales.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
sucursalSelect.appendChild(option);
});
})
.catch(error => console.error('Error:', error));
}
document.getElementById('loteEmpresaSelect')?.addEventListener('change', function() {
cargarSucursalesLote(this.value);
});
function buscarPersonalParaLote() {
// ✅ Mostrar loading
Swal.fire({
title: 'Buscando...',
text: 'Cargando personal disponible',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading() }
});
const tamanoLote = 40; // ✅ TAMAÑO FIJO EN 40
const empresaId = document.getElementById('loteEmpresaSelect')?.value || '0';
const sucursalId = document.getElementById('loteSucursalSelect')?.value || '0';
const activo = document.getElementById('loteActivoSelect')?.value || '';
const vencimiento = document.getElementById('loteVencimientoSelect')?.value || '';
const credencial = document.getElementById('loteCredencialSelect')?.value || '';
// ✅ NUEVO: FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (PRIMERA PALABRA)
const letraDesde = document.getElementById('loteLetraDesdeSelect')?.value || 'A';
const letraHasta = document.getElementById('loteLetraHastaSelect')?.value || 'Z';
const params = new URLSearchParams({
action: 'buscar_personal_lote',
tamano_lote: tamanoLote,
filtro_empresa: empresaId,
filtro_sucursal: sucursalId,
filtro_activo: activo,
filtro_vencimiento: vencimiento,
filtro_credencial: credencial,
filtro_letra_desde: letraDesde,
filtro_letra_hasta: letraHasta
});
fetch(`personal.php?${params}`)
.then(response => response.json())
.then(data => {
Swal.close();
if (data.success) {
personalParaLote = data.personal || [];
mostrarResultadoLote(personalParaLote);
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.message || 'Error al buscar personal'
});
}
})
.catch(error => {
Swal.close();
console.error('Error:', error);
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: error.message
});
});
}
function mostrarResultadoLote(personal) {
// ✅ VERIFICAR QUE LA SECCIÓN ESTÉ EXPANDIDA
const contenidoLotes = document.getElementById('contenidoLotes');
if (contenidoLotes && !contenidoLotes.classList.contains('show')) {
const collapse = new bootstrap.Collapse(contenidoLotes, { toggle: false });
collapse.show();
}
// ✅ VALIDAR ELEMENTOS ANTES DE USARLOS
const resultadoDiv = document.getElementById('resultadoLote');
const tablaBody = document.getElementById('tablaPersonalLote');
const cantidadLabel = document.getElementById('cantidadPersonalLote');
const btnGenerar = document.getElementById('btnGenerarLote');
const btnExportar = document.getElementById('btnExportarLista');
if (!resultadoDiv || !tablaBody || !cantidadLabel) {
console.error('Elementos del DOM no encontrados:', {
resultadoDiv: !!resultadoDiv,
tablaBody: !!tablaBody,
cantidadLabel: !!cantidadLabel
});
Swal.fire({
icon: 'error',
title: 'Error',
text: 'No se pudo mostrar los resultados. Recarga la página (Ctrl + F5).'
});
return;
}
// ✅ MOSTRAR RESULTADOS DE FORMA SEGURA
resultadoDiv.style.display = 'block';
if (cantidadLabel) {
cantidadLabel.textContent = personal ? personal.length : 0;
}
if (btnGenerar) {
btnGenerar.disabled = !personal || personal.length === 0;
}
if (btnExportar) {
btnExportar.disabled = !personal || personal.length === 0;
}
tablaBody.innerHTML = '';
if (!personal || personal.length === 0) {
tablaBody.innerHTML = '<tr><td colspan="7" class="text-center py-3">No se encontró personal con los filtros seleccionados</td></tr>';
return;
}
personal.forEach(persona => {
const row = document.createElement('tr');
row.innerHTML = `
<td><input type="checkbox" class="select-personal-lote" value="${persona.id || ''}" checked></td>
<td>${persona.id || 'N/A'}</td>
<td>${(persona.apellido || '')}, ${(persona.nombre || '')}</td>
<td>${persona.dni || 'N/A'}</td>
<td>${persona.empresa_nombre || 'N/A'}</td>
<td>${persona.sucursal_nombre || 'N/A'}</td>
<td>${persona.fecha_vencimiento || 'N/A'}</td>
`;
tablaBody.appendChild(row);
});
// ✅ SCROLL HACIA LOS RESULTADOS
setTimeout(() => {
resultadoDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
}, 300);
}
function toggleSelectAllLote() {
const selectAll = document.getElementById('selectAllLote');
if (!selectAll) return;
document.querySelectorAll('.select-personal-lote').forEach(checkbox => {
checkbox.checked = selectAll.checked;
});
}
function generarLoteCredenciales() {
const selectedIds = [];
document.querySelectorAll('.select-personal-lote:checked').forEach(checkbox => {
selectedIds.push(checkbox.value);
});
if (selectedIds.length === 0) {
Swal.fire({
icon: 'warning',
title: 'Advertencia',
text: 'Seleccione al menos un personal'
});
return;
}
// ✅ TAMAÑO DE LOTE FIJO EN 40
const tamanoLote = 40;
const empresaId = document.getElementById('loteEmpresaSelect')?.value || '0';
const sucursalId = document.getElementById('loteSucursalSelect')?.value || '0';
const activo = document.getElementById('loteActivoSelect')?.value || '';
const vencimiento = document.getElementById('loteVencimientoSelect')?.value || '';
const credencial = document.getElementById('loteCredencialSelect')?.value || '';
// ✅ NUEVO: FILTRO POR RANGO DE LETRAS DEL ABECEDARIO (PRIMERA PALABRA)
const letraDesde = document.getElementById('loteLetraDesdeSelect')?.value || 'A';
const letraHasta = document.getElementById('loteLetraHastaSelect')?.value || 'Z';
const totalLotes = Math.ceil(selectedIds.length / tamanoLote);
Swal.fire({
title: 'Generando Credenciales',
html: `Se generarán <strong>${selectedIds.length}</strong> credenciales en <strong>${totalLotes}</strong> PDF(s) de <strong>${tamanoLote}</strong> credenciales cada uno.<br><br>⚠️ Deberá confirmar cada descarga individualmente.`,
icon: 'info',
showConfirmButton: true,
confirmButtonText: 'Continuar',
confirmButtonColor: '#28a745'
}).then((result) => {
if (result.isConfirmed) {
const form = document.createElement('form');
form.method = 'POST';
form.action = 'personal.php';
const inputs = {
action: 'generar_qr_lote',
tamano_lote: tamanoLote,
filtro_empresa: empresaId,
filtro_sucursal: sucursalId,
filtro_activo: activo,
filtro_vencimiento: vencimiento,
filtro_credencial: credencial,
filtro_letra_desde: letraDesde,
filtro_letra_hasta: letraHasta
};
Object.keys(inputs).forEach(key => {
const input = document.createElement('input');
input.type = 'hidden';
input.name = key;
input.value = inputs[key];
form.appendChild(input);
});
selectedIds.forEach(id => {
const input = document.createElement('input');
input.type = 'hidden';
input.name = 'personal_ids[]';
input.value = id;
form.appendChild(input);
});
document.body.appendChild(form);
form.submit();
}
});
}
// ✅ FUNCIONES PARA CARGAR SUCURSALES DINÁMICAMENTE EN FORMULARIO PRINCIPAL Y MODALES
function cargarSucursalesFormPrincipal(empresaId) {
const sucursalSelect = document.getElementById('sucursalSelect');
if (!sucursalSelect) return;
if (!empresaId || empresaId == '0') {
sucursalSelect.innerHTML = '<option value="">Seleccione una sucursal...</option>';
return;
}
fetch(`personal.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
sucursalSelect.innerHTML = '<option value="">Seleccione una sucursal...</option>';
if (data.success && data.data) {
data.data.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
sucursalSelect.appendChild(option);
});
}
})
.catch(error => console.error('Error cargando sucursales:', error));
}
function cargarSucursalesModal(personaId, empresaId) {
const sucursalSelect = document.getElementById(`edit_sucursal_${personaId}`);
if (!sucursalSelect) return;
if (!empresaId || empresaId == '0') {
sucursalSelect.innerHTML = '<option value="">Seleccione una sucursal...</option>';
return;
}
fetch(`personal.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
sucursalSelect.innerHTML = '<option value="">Seleccione una sucursal...</option>';
if (data.success && data.data) {
data.data.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
sucursalSelect.appendChild(option);
});
}
})
.catch(error => console.error('Error cargando sucursales:', error));
}
// ✅ EVENT LISTENERS PARA EMPRESA -> SUCURSAL EN FORMULARIO PRINCIPAL
document.getElementById('empresaSelect')?.addEventListener('change', function() {
cargarSucursalesFormPrincipal(this.value);
});
// ✅ EVENT LISTENERS PARA EMPRESA -> SUCURSAL EN MODALES DE EDICIÓN
document.querySelectorAll('.modal-empresa-select').forEach(select => {
select.addEventListener('change', function() {
const personaId = this.dataset.personaId;
cargarSucursalesModal(personaId, this.value);
});
});
// ✅ EVENT LISTENER PARA FILTROS DE BÚSQUEDA: EMPRESA -> SUCURSAL (CORREGIDO)
document.getElementById('filtroEmpresaSelect')?.addEventListener('change', function() {
const sucursalSelect = document.getElementById('filtroSucursalSelect');
if (!sucursalSelect) return;
const empresaId = this.value;
if (!empresaId || empresaId == '0') {
sucursalSelect.innerHTML = '<option value="0">Todas las sucursales</option>';
return;
}
fetch(`personal.php?action=get_sucursales&empresa_id=${empresaId}`)
.then(response => response.json())
.then(data => {
sucursalSelect.innerHTML = '<option value="0">Todas las sucursales</option>';
const sucursales = data.data || [];
sucursales.forEach(sucursal => {
const option = document.createElement('option');
option.value = sucursal.id;
option.textContent = sucursal.nombre;
sucursalSelect.appendChild(option);
});
})
.catch(error => console.error('Error cargando sucursales para filtros:', error));
});
// ✅ AUTO-FORMATEO DE CUIL (XX-XXXXXXXX-X) EN TODOS LOS CAMPOS DNI
document.querySelectorAll('input[name="dni"]').forEach(input => {
input.addEventListener('input', function() {
let value = this.value.replace(/\D/g, '');
if (value.length > 2) {
value = value.substring(0, 2) + '-' + value.substring(2);
}
if (value.length > 11) {
value = value.substring(0, 11) + '-' + value.substring(11, 12);
}
this.value = value.substring(0, 14);
});
});
// ✅ EVENT LISTENERS PARA BOTONES DE APROBAR/RECHAZAR DOCUMENTACIÓN - CORREGIDO
document.addEventListener('DOMContentLoaded', function() {
// APROBAR DOCUMENTACIÓN
document.querySelectorAll('.btn-aprobar-doc').forEach(button => {
button.addEventListener('click', function(e) {
e.preventDefault();
const personalId = this.dataset.id;
const nombrePersonal = this.dataset.nombre;
Swal.fire({
title: '¿Aprobar documentación?',
text: `¿Está seguro de aprobar la documentación de ${nombrePersonal}?`,
icon: 'question',
showCancelButton: true,
confirmButtonText: 'Sí, aprobar',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#28a745',
cancelButtonColor: '#6c757d'
}).then((result) => {
if (result.isConfirmed) {
const formData = new FormData();
formData.append('action', 'aprobar_documentacion');
formData.append('personal_id', personalId);
fetch('personal.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({
icon: 'success',
title: 'Aprobado',
text: data.message,
timer: 2000,
showConfirmButton: false
}).then(() => {
location.reload();
});
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.message || 'Error al aprobar documentación'
});
}
})
.catch(error => {
console.error('Error:', error);
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: 'No se pudo conectar con el servidor'
});
});
}
});
});
});
// RECHAZAR DOCUMENTACIÓN
document.querySelectorAll('.btn-rechazar-doc').forEach(button => {
button.addEventListener('click', function(e) {
e.preventDefault();
const personalId = this.dataset.id;
const nombrePersonal = this.dataset.nombre;
Swal.fire({
title: 'Rechazar documentación',
html: `
<label for="motivo-rechazo" class="swal2-input" style="display:block;text-align:left;margin-bottom:10px;font-weight:600;">Motivo del rechazo (obligatorio):</label>
<input type="text" id="motivo-rechazo" class="swal2-input" placeholder="Ingrese el motivo del rechazo..." style="width:100%;">
`,
icon: 'warning',
showCancelButton: true,
confirmButtonText: 'Sí, rechazar',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#dc3545',
cancelButtonColor: '#6c757d',
preConfirm: () => {
const motivo = document.getElementById('motivo-rechazo').value.trim();
if (!motivo) {
Swal.showValidationMessage('El motivo del rechazo es obligatorio');
return false;
}
return motivo;
}
}).then((result) => {
if (result.isConfirmed && result.value) {
const formData = new FormData();
formData.append('action', 'rechazar_documentacion');
formData.append('personal_id', personalId);
formData.append('motivo_rechazo', result.value);
fetch('personal.php', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
Swal.fire({
icon: 'success',
title: 'Rechazado',
text: data.message,
timer: 2000,
showConfirmButton: false
}).then(() => {
location.reload();
});
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.message || 'Error al rechazar documentación'
});
}
})
.catch(error => {
console.error('Error:', error);
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: 'No se pudo conectar con el servidor'
});
});
}
});
});
});
});
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
// ✅ EVENT LISTENERS PARA CONDICIONALES EN FORMULARIO PRINCIPAL
document.addEventListener('DOMContentLoaded', function() {
// Checkbox tiene_certificado
const checkboxCert = document.getElementById('tiene_certificado');
const containerCert = document.getElementById('campos-certificado');
if (checkboxCert && containerCert) {
const inputsCert = containerCert.querySelectorAll('input, select, textarea');
function toggleCert() {
if (checkboxCert.checked) {
containerCert.classList.remove('d-none');
inputsCert.forEach(input => input.disabled = false);
} else {
containerCert.classList.add('d-none');
inputsCert.forEach(input => input.disabled = true);
}
}
toggleCert();
checkboxCert.addEventListener('change', toggleCert);
}
// Checkbox pago_credencial
const checkboxCred = document.getElementById('pago_credencial');
const containerCred = document.getElementById('contenedorFiltrosCredencial');
if (checkboxCred && containerCred) {
function toggleCred() {
if (checkboxCred.checked) {
containerCred.classList.remove('d-none');
} else {
containerCred.classList.add('d-none');
}
}
toggleCred();
checkboxCred.addEventListener('change', toggleCred);
}
// Checkbox baja
const checkboxBaja = document.getElementById('baja');
const containerBaja = document.getElementById('contenedor-baja');
if (checkboxBaja && containerBaja) {
function toggleBaja() {
containerBaja.style.display = checkboxBaja.checked ? 'flex' : 'none';
if (!checkboxBaja.checked) {
containerBaja.querySelector('input[name="fecha_baja"]').value = '';
containerBaja.querySelector('textarea[name="nota_baja"]').value = '';
}
}
checkboxBaja.addEventListener('change', toggleBaja);
toggleBaja();
}
// Auto-cálculo fecha revalidación
const inputAno = document.getElementById('ano_finalizacion');
const inputFechaReval = document.getElementById('fecha_revalidacion');
if (inputAno && inputFechaReval) {
function actualizarFechaRevalidacion() {
const ano = parseInt(inputAno.value, 10);
if (!isNaN(ano) && ano >= 1950 && ano <= 2100) {
const anoSiguiente = ano + 2;
inputFechaReval.value = `${anoSiguiente}-01-15`;
} else {
inputFechaReval.value = '';
}
}
inputAno.addEventListener('input', actualizarFechaRevalidacion);
if (inputAno.value) {
actualizarFechaRevalidacion();
}
}
// Auto-cálculo vencimiento credencial
const pagoInput = document.getElementById('fecha_pago_credencial');
const vencimientoInput = document.getElementById('fecha_vencimiento');
if (pagoInput && vencimientoInput) {
function calcularVencimiento() {
const fechaPago = pagoInput.value;
if (fechaPago) {
const anio = parseInt(fechaPago.split('-')[0], 10) + 1;
vencimientoInput.value = `${anio}-01-15`;
} else {
vencimientoInput.value = '';
}
}
pagoInput.addEventListener('change', calcularVencimiento);
if (pagoInput.value) {
calcularVencimiento();
}
}
});
// ✅ EVENT LISTENERS PARA CONDICIONALES EN MODALES DE EDICIÓN (DINÁMICOS PARA CADA PERSONA)
document.addEventListener('DOMContentLoaded', function() {
<?php foreach ($personal_list as $persona): ?>
// Modal <?php echo $persona['id']; ?> - tiene_certificado
const checkboxCertEdit<?php echo $persona['id']; ?> = document.getElementById('tiene_certificado_edit_<?php echo $persona['id']; ?>');
const containerCertEdit<?php echo $persona['id']; ?> = document.getElementById('campos-certificado-edit-<?php echo $persona['id']; ?>');
if (checkboxCertEdit<?php echo $persona['id']; ?> && containerCertEdit<?php echo $persona['id']; ?>) {
const inputsCertEdit<?php echo $persona['id']; ?> = containerCertEdit<?php echo $persona['id']; ?>.querySelectorAll('input, select, textarea');
function toggleCertEdit<?php echo $persona['id']; ?>() {
if (checkboxCertEdit<?php echo $persona['id']; ?>.checked) {
containerCertEdit<?php echo $persona['id']; ?>.classList.remove('d-none');
inputsCertEdit<?php echo $persona['id']; ?>.forEach(input => input.disabled = false);
} else {
containerCertEdit<?php echo $persona['id']; ?>.classList.add('d-none');
inputsCertEdit<?php echo $persona['id']; ?>.forEach(input => input.disabled = true);
}
}
toggleCertEdit<?php echo $persona['id']; ?>();
checkboxCertEdit<?php echo $persona['id']; ?>?.addEventListener('change', toggleCertEdit<?php echo $persona['id']; ?>);
}
// Modal <?php echo $persona['id']; ?> - pago_credencial
const checkboxCredEdit<?php echo $persona['id']; ?> = document.getElementById('pago_credencial_edit_<?php echo $persona['id']; ?>');
const containerCredEdit<?php echo $persona['id']; ?> = document.getElementById('contenedorFiltrosCredencial-edit-<?php echo $persona['id']; ?>');
if (checkboxCredEdit<?php echo $persona['id']; ?> && containerCredEdit<?php echo $persona['id']; ?>) {
function toggleCredEdit<?php echo $persona['id']; ?>() {
if (checkboxCredEdit<?php echo $persona['id']; ?>.checked) {
containerCredEdit<?php echo $persona['id']; ?>.classList.remove('d-none');
} else {
containerCredEdit<?php echo $persona['id']; ?>.classList.add('d-none');
}
}
toggleCredEdit<?php echo $persona['id']; ?>();
checkboxCredEdit<?php echo $persona['id']; ?>?.addEventListener('change', toggleCredEdit<?php echo $persona['id']; ?>);
}
// Modal <?php echo $persona['id']; ?> - baja
const checkboxBajaEdit<?php echo $persona['id']; ?> = document.getElementById('baja_edit_<?php echo $persona['id']; ?>');
const containerBajaEdit<?php echo $persona['id']; ?> = document.getElementById('contenedor-baja-edit-<?php echo $persona['id']; ?>');
if (checkboxBajaEdit<?php echo $persona['id']; ?> && containerBajaEdit<?php echo $persona['id']; ?>) {
function toggleBajaEdit<?php echo $persona['id']; ?>() {
containerBajaEdit<?php echo $persona['id']; ?>.style.display = checkboxBajaEdit<?php echo $persona['id']; ?>.checked ? 'flex' : 'none';
if (!checkboxBajaEdit<?php echo $persona['id']; ?>.checked) {
containerBajaEdit<?php echo $persona['id']; ?>.querySelector('input[name="fecha_baja"]').value = '';
containerBajaEdit<?php echo $persona['id']; ?>.querySelector('textarea[name="nota_baja"]').value = '';
}
}
checkboxBajaEdit<?php echo $persona['id']; ?>?.addEventListener('change', toggleBajaEdit<?php echo $persona['id']; ?>);
toggleBajaEdit<?php echo $persona['id']; ?>();
}
// Modal <?php echo $persona['id']; ?> - auto-cálculo fecha revalidación
const inputAnoEdit<?php echo $persona['id']; ?> = document.getElementById('ano_finalizacion_edit_<?php echo $persona['id']; ?>');
const inputFechaRevalEdit<?php echo $persona['id']; ?> = document.getElementById('fecha_revalidacion_edit_<?php echo $persona['id']; ?>');
if (inputAnoEdit<?php echo $persona['id']; ?> && inputFechaRevalEdit<?php echo $persona['id']; ?>) {
function actualizarFechaRevalidacionEdit<?php echo $persona['id']; ?>() {
const ano = parseInt(inputAnoEdit<?php echo $persona['id']; ?>.value, 10);
if (!isNaN(ano) && ano >= 1950 && ano <= 2100) {
const anoSiguiente = ano + 2;
inputFechaRevalEdit<?php echo $persona['id']; ?>.value = `${anoSiguiente}-01-15`;
} else {
inputFechaRevalEdit<?php echo $persona['id']; ?>.value = '';
}
}
inputAnoEdit<?php echo $persona['id']; ?>?.addEventListener('input', actualizarFechaRevalidacionEdit<?php echo $persona['id']; ?>);
if (inputAnoEdit<?php echo $persona['id']; ?>?.value) {
actualizarFechaRevalidacionEdit<?php echo $persona['id']; ?>();
}
}
// Modal <?php echo $persona['id']; ?> - auto-cálculo vencimiento credencial
const pagoInputEdit<?php echo $persona['id']; ?> = document.getElementById('fecha_pago_credencial_edit_<?php echo $persona['id']; ?>');
const vencimientoInputEdit<?php echo $persona['id']; ?> = document.getElementById('fecha_vencimiento_edit_<?php echo $persona['id']; ?>');
if (pagoInputEdit<?php echo $persona['id']; ?> && vencimientoInputEdit<?php echo $persona['id']; ?>) {
function calcularVencimientoEdit<?php echo $persona['id']; ?>() {
const fechaPago = pagoInputEdit<?php echo $persona['id']; ?>.value;
if (fechaPago) {
const anio = parseInt(fechaPago.split('-')[0], 10) + 1;
vencimientoInputEdit<?php echo $persona['id']; ?>.value = `${anio}-01-15`;
} else {
vencimientoInputEdit<?php echo $persona['id']; ?>.value = '';
}
}
pagoInputEdit<?php echo $persona['id']; ?>?.addEventListener('change', calcularVencimientoEdit<?php echo $persona['id']; ?>);
if (pagoInputEdit<?php echo $persona['id']; ?>?.value) {
calcularVencimientoEdit<?php echo $persona['id']; ?>();
}
}
<?php endforeach; ?>
});
</script>
</body>
</html>