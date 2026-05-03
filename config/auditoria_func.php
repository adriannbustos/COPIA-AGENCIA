<?php
/**
* ============================================================================
* SISTEMA DE AUDITORÍA CENTRALIZADO - VERSIÓN COMPLETA
* ============================================================================
* Incluye: Risk Score, Comentarios, Alertas, Exportación (CSV/JSON/PDF),
*          Diff Visual, Notificaciones de Seguridad, Búsqueda en Historial
*
* @author Sistema de Seguridad
* @version 2.0
* @last_update 2024
* ============================================================================
*/

// ============================================================================
// FUNCIÓN COMPATIBLE registrarAuditoria (para seguimiento_tramites.php)
// ============================================================================
if (!function_exists('registrarAuditoria')) {
function registrarAuditoria($conn, $usuario_id, $accion, $tabla, $registro_id, $descripcion) {
    // Esta función es un wrapper compatible con logAuditoria
    return logAuditoria($conn, $accion, $tabla, $registro_id, [
        'descripcion' => $descripcion
    ], $usuario_id);
}
}

// ============================================================================
// 1. FUNCIÓN PRINCIPAL DE LOG DE AUDITORÍA
// ============================================================================
if (!function_exists('logAuditoria')) {
function logAuditoria($conn, $accion, $tabla, $registro_id = null, $detalles = [], $usuario_id = null) {
try {
// Obtener usuario actual si no se proporcionó
if (!$usuario_id) {
global $auth;
if ($auth && $auth->isLoggedIn()) {
$user = $auth->getCurrentUser();
$usuario_id = $user['id'] ?? null;
}
}

// Capturar información del request
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
$request_uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

// Calcular score de riesgo
$risk_score = calcularRiskScore($conn, $usuario_id, $accion, $tabla, $ip_address);

// Serializar detalles como JSON
$detalles['risk_score'] = $risk_score;
$detalles['ip_address'] = $ip_address;
$detalles['user_agent'] = $user_agent;
$detalles_json = !empty($detalles) ? json_encode($detalles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null;

$stmt = $conn->prepare("
INSERT INTO auditoria (
usuario_id, accion, tabla, registro_id, detalles,
ip_address, user_agent, request_uri, request_method, risk_score
) VALUES (:usuario_id, :accion, :tabla, :registro_id, :detalles,
:ip_address, :user_agent, :request_uri, :request_method, :risk_score)
");

$stmt->execute([
':usuario_id' => $usuario_id,
':accion' => $accion,
':tabla' => $tabla,
':registro_id' => $registro_id,
':detalles' => $detalles_json,
':ip_address' => $ip_address,
':user_agent' => $user_agent,
':request_uri' => $request_uri,
':request_method' => $request_method,
':risk_score' => $risk_score
]);

// Verificar actividades sospechosas
verificarActividadSospechosa($conn, $usuario_id, $accion, $tabla, $risk_score);

return $conn->lastInsertId();

} catch(PDOException $e) {
error_log("Error en auditoría: " . $e->getMessage());
return false;
}
}
}

// ============================================================================
// 2. CÁLCULO DE RISK SCORE (PUNTAJE DE RIESGO)
// ============================================================================
if (!function_exists('calcularRiskScore')) {
function calcularRiskScore($conn, $usuario_id, $accion, $tabla, $ip_address) {
$score = 0;

// Acciones de alto riesgo (+30 puntos)
$acciones_alto_riesgo = [
'ELIMINACION', 'CAMBIO_ESTADO', 'CAMBIO_BAJA', 'EXPORTACION_MASIVA',
'CAMBIO_PERMISOS', 'empresa_eliminada', 'empresa_desactivada',
'sucursal_eliminada', 'usuario_eliminado', 'permisos_cambiados'
];

if (in_array(strtoupper($accion), $acciones_alto_riesgo) || in_array($accion, $acciones_alto_riesgo)) {
$score += 30;
}

// Acciones de riesgo medio (+15 puntos)
$acciones_medio_riesgo = [
'MODIFICACION', 'CAMBIO_PASSWORD', 'ACTUALIZACION', 'empresa_actualizada',
'sucursal_actualizada', 'usuario_actualizado', 'datos_modificados'
];

if (in_array(strtoupper($accion), $acciones_medio_riesgo) || in_array($accion, $acciones_medio_riesgo)) {
$score += 15;
}

// Verificar acciones recientes del usuario (última hora)
try {
$stmt = $conn->prepare("
SELECT COUNT(*) as total, AVG(risk_score) as avg_score
FROM auditoria
WHERE usuario_id = :usuario_id
AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([':usuario_id' => $usuario_id]);
$resultado = $stmt->fetch();

if ($resultado && $resultado['total'] > 20) {
$score += 25; // Muchas acciones en poco tiempo
}

if ($resultado && $resultado['avg_score'] > 50) {
$score += 20; // Historial de alto riesgo
}
} catch(Exception $e) {
error_log("Error calculando risk score: " . $e->getMessage());
}

// Verificar IP sospechosa (múltiples IPs en 24 horas)
try {
$stmt = $conn->prepare("
SELECT COUNT(DISTINCT ip_address) as ips_diferentes
FROM auditoria
WHERE usuario_id = :usuario_id
AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$stmt->execute([':usuario_id' => $usuario_id]);
$resultado = $stmt->fetch();

if ($resultado && $resultado['ips_diferentes'] > 3) {
$score += 30; // Múltiples IPs en 24 horas
}
} catch(Exception $e) {
error_log("Error verificando IPs: " . $e->getMessage());
}

// Horario fuera de lo normal (2 AM - 5 AM) (+15 puntos)
$hora_actual = date('H');
if ($hora_actual >= 2 && $hora_actual <= 5) {
$score += 15;
}

// Acciones en fin de semana (+10 puntos)
$dia_semana = date('N');
if ($dia_semana >= 6) {
$score += 10;
}

// Acciones en días feriados (+10 puntos)
$mes = date('n');
$dia = date('j');
$feriados = [
'1-1', '5-1', '5-25', '6-20', '7-9', '8-17', '10-12', '11-20', '12-8', '12-25'
];

if (in_array("$mes-$dia", $feriados)) {
$score += 10;
}

// Limitar score a 100
return min($score, 100);
}
}

// ============================================================================
// 3. OBTENER CAMBIOS ENTRE DATOS ANTIGUOS Y NUEVOS
// ============================================================================
if (!function_exists('obtenerCambios')) {
function obtenerCambios($datos_antiguos, $datos_nuevos, $excluir = ['updated_at', 'created_at', 'password', 'contrasena']) {
$cambios = [];

if (!is_array($datos_antiguos) || !is_array($datos_nuevos)) {
return $cambios;
}

foreach ($datos_nuevos as $campo => $valor_nuevo) {
if (in_array($campo, $excluir)) continue;

$valor_antiguo = $datos_antiguos[$campo] ?? null;

if ($valor_antiguo != $valor_nuevo) {
$cambios[$campo] = [
'anterior' => $valor_antiguo,
'nuevo' => $valor_nuevo,
'tipo_cambio' => getTipoCambio($valor_antiguo, $valor_nuevo),
'diff' => generarDiff($valor_antiguo, $valor_nuevo)
];
}
}

return $cambios;
}
}

// ============================================================================
// 4. GENERAR DIFF VISUAL DE CAMBIOS
// ============================================================================
if (!function_exists('generarDiff')) {
function generarDiff($antiguo, $nuevo) {
$diff = [
'removed' => [],
'added' => [],
'unchanged' => []
];

if (is_string($antiguo) && is_string($nuevo)) {
$antiguo_arr = str_split($antiguo);
$nuevo_arr = str_split($nuevo);

$diff['removed'] = array_diff($antiguo_arr, $nuevo_arr);
$diff['added'] = array_diff($nuevo_arr, $antiguo_arr);
} elseif (is_array($antiguo) && is_array($nuevo)) {
$diff['removed'] = array_diff($antiguo, $nuevo);
$diff['added'] = array_diff($nuevo, $antiguo);
$diff['unchanged'] = array_intersect($antiguo, $nuevo);
}

return $diff;
}
}

// ============================================================================
// 5. OBTENER TIPO DE CAMBIO
// ============================================================================
if (!function_exists('getTipoCambio')) {
function getTipoCambio($antiguo, $nuevo) {
if ($antiguo === null && $nuevo !== null) return 'CREADO';
if ($antiguo !== null && $nuevo === null) return 'ELIMINADO';
if ($antiguo === '' && $nuevo !== '') return 'AGREGADO';
if ($antiguo !== '' && $nuevo === '') return 'VACIADO';
if (is_numeric($antiguo) && is_numeric($nuevo) && $nuevo > $antiguo) return 'INCREMENTADO';
if (is_numeric($antiguo) && is_numeric($nuevo) && $nuevo < $antiguo) return 'DECREMENTADO';
return 'MODIFICADO';
}
}

// ============================================================================
// 6. VERIFICAR ACTIVIDAD SOSPECHOSA
// ============================================================================
if (!function_exists('verificarActividadSospechosa')) {
function verificarActividadSospechosa($conn, $usuario_id, $accion, $tabla, $risk_score) {
try {
// Contar acciones críticas recientes del usuario (últimos 5 minutos)
$stmt = $conn->prepare("
SELECT COUNT(*) as total, MAX(risk_score) as max_score
FROM auditoria
WHERE usuario_id = :usuario_id
AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
AND (
accion LIKE '%ELIMINACION%' OR
accion LIKE '%CAMBIO_ESTADO%' OR
accion LIKE '%BAJA%' OR
accion LIKE '%EXPORTACION%' OR
risk_score >= 70
)
");
$stmt->execute([':usuario_id' => $usuario_id]);
$resultado = $stmt->fetch();

$nivel_riesgo = 'BAJO';
if ($risk_score >= 70) $nivel_riesgo = 'ALTO';
elseif ($risk_score >= 40) $nivel_riesgo = 'MEDIO';

// Si hay más de 10 acciones críticas en 5 minutos O score > 70
if (($resultado && $resultado['total'] > 10) || $risk_score >= 70) {
logAuditoria($conn, 'ALERTA_ACTIVIDAD_SOSPECHOSA', 'sistema', null, [
'usuario_id' => $usuario_id,
'accion' => $accion,
'tabla' => $tabla,
'acciones_recientes' => $resultado['total'] ?? 0,
'nivel_riesgo' => $nivel_riesgo,
'risk_score' => $risk_score,
'max_score_reciente' => $resultado['max_score'] ?? 0
], $usuario_id);

// Enviar notificación de seguridad
enviarNotificacionSeguridad($conn, $usuario_id, $nivel_riesgo);
}
} catch(Exception $e) {
error_log("Error verificando actividad sospechosa: " . $e->getMessage());
}
}
}

// ============================================================================
// 7. ENVIAR NOTIFICACIÓN DE SEGURIDAD
// ============================================================================
if (!function_exists('enviarNotificacionSeguridad')) {
function enviarNotificacionSeguridad($conn, $usuario_id, $nivel_riesgo) {
try {
// Registrar alerta en tabla de alertas
$stmt = $conn->prepare("
INSERT INTO auditoria_alertas (
usuario_id, nivel_riesgo, tipo_alerta, descripcion, created_at
) VALUES (:usuario_id, :nivel_riesgo, :tipo_alerta, :descripcion, NOW())
");

$stmt->execute([
':usuario_id' => $usuario_id,
':nivel_riesgo' => $nivel_riesgo,
':tipo_alerta' => 'ACTIVIDAD_SOSPECHOSA',
':descripcion' => "Usuario ID $usuario_id - Nivel de riesgo: $nivel_riesgo - " . date('Y-m-d H:i:s')
]);

// Log en archivo para monitoreo externo
error_log("🚨 ALERTA DE SEGURIDAD: Usuario ID $usuario_id - Nivel: $nivel_riesgo - " . date('Y-m-d H:i:s'));

// TODO: Implementar envío de email a administradores
// TODO: Implementar notificación push/SMS si está configurado

} catch(Exception $e) {
error_log("Error enviando notificación de seguridad: " . $e->getMessage());
}
}
}

// ============================================================================
// 8. AGREGAR COMENTARIO A REGISTRO DE AUDITORÍA
// ============================================================================
if (!function_exists('agregarComentarioAuditoria')) {
function agregarComentarioAuditoria($conn, $auditoria_id, $comentario, $usuario_id) {
try {
if (empty($comentario)) {
return false;
}

$stmt = $conn->prepare("
INSERT INTO auditoria_comentarios (
auditoria_id, usuario_id, comentario, created_at
) VALUES (:auditoria_id, :usuario_id, :comentario, NOW())
");

$stmt->execute([
':auditoria_id' => $auditoria_id,
':usuario_id' => $usuario_id,
':comentario' => $comentario
]);

// Logear la acción de agregar comentario
logAuditoria($conn, 'COMENTARIO_AUDITORIA', 'auditoria_comentarios', $auditoria_id, [
'comentario' => substr($comentario, 0, 100),
'auditoria_id' => $auditoria_id
], $usuario_id);

return $conn->lastInsertId();

} catch(PDOException $e) {
error_log("Error agregando comentario: " . $e->getMessage());
return false;
}
}
}

// ============================================================================
// 9. OBTENER COMENTARIOS DE AUDITORÍA
// ============================================================================
if (!function_exists('obtenerComentariosAuditoria')) {
function obtenerComentariosAuditoria($conn, $auditoria_id) {
try {
$stmt = $conn->prepare("
SELECT c.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre,
p.email as usuario_email
FROM auditoria_comentarios c
LEFT JOIN personal p ON c.usuario_id = p.id
WHERE c.auditoria_id = :auditoria_id
ORDER BY c.created_at ASC
");

$stmt->execute([':auditoria_id' => $auditoria_id]);

return $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
error_log("Error obteniendo comentarios: " . $e->getMessage());
return [];
}
}
}

// ============================================================================
// 10. EXPORTAR AUDITORÍA (CSV, JSON, PDF)
// ============================================================================
if (!function_exists('exportarAuditoria')) {
function exportarAuditoria($conn, $formato = 'csv', $filtros = []) {
try {
$sql = "SELECT a.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre,
p.email as usuario_email
FROM auditoria a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE 1=1";

$params = [];

if (!empty($filtros['fecha_desde'])) {
$sql .= " AND DATE(a.created_at) >= :fecha_desde";
$params[':fecha_desde'] = $filtros['fecha_desde'];
}

if (!empty($filtros['fecha_hasta'])) {
$sql .= " AND DATE(a.created_at) <= :fecha_hasta";
$params[':fecha_hasta'] = $filtros['fecha_hasta'];
}

if (!empty($filtros['usuario_id'])) {
$sql .= " AND a.usuario_id = :usuario_id";
$params[':usuario_id'] = $filtros['usuario_id'];
}

if (!empty($filtros['accion'])) {
$sql .= " AND a.accion = :accion";
$params[':accion'] = $filtros['accion'];
}

if (!empty($filtros['tabla'])) {
$sql .= " AND a.tabla = :tabla";
$params[':tabla'] = $filtros['tabla'];
}

if (!empty($filtros['risk_score_min'])) {
$sql .= " AND a.risk_score >= :risk_score_min";
$params[':risk_score_min'] = $filtros['risk_score_min'];
}

if (!empty($filtros['risk_score_max'])) {
$sql .= " AND a.risk_score <= :risk_score_max";
$params[':risk_score_max'] = $filtros['risk_score_max'];
}

$sql .= " ORDER BY a.created_at DESC LIMIT 10000";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log de exportación
logAuditoria($conn, 'AUDITORIA_EXPORTADA', 'auditoria', null, [
'formato' => $formato,
'total_registros' => count($registros),
'filtros' => $filtros
]);

if ($formato === 'csv') {
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=auditoria_' . date('Y-m-d_His') . '.csv');

$output = fopen('php://output', 'w');

fputcsv($output, ['ID', 'Usuario', 'Email', 'Acción', 'Tabla', 'Registro ID', 'IP', 'Risk Score', 'Fecha', 'Detalles']);

foreach ($registros as $reg) {
fputcsv($output, [
$reg['id'],
$reg['usuario_nombre'] ?? 'Sistema',
$reg['usuario_email'] ?? 'N/A',
$reg['accion'],
$reg['tabla'],
$reg['registro_id'] ?? 'N/A',
$reg['ip_address'] ?? 'N/A',
$reg['risk_score'] ?? 0,
$reg['created_at'],
$reg['detalles'] ?? ''
]);
}

fclose($output);
exit;
}

if ($formato === 'json') {
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=auditoria_' . date('Y-m-d_His') . '.json');

echo json_encode($registros, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
}

if ($formato === 'pdf') {
exportarAuditoriaPDF($registros, $filtros);
exit;
}

} catch(Exception $e) {
error_log("Error exportando auditoría: " . $e->getMessage());
return false;
}
}
}

// ============================================================================
// 11. EXPORTAR AUDITORÍA A PDF
// ============================================================================
if (!function_exists('exportarAuditoriaPDF')) {
function exportarAuditoriaPDF($registros, $filtros) {
// Verificar si FPDF está disponible
if (!file_exists('../vendor/fpdf/fpdf.php')) {
// Fallback a CSV si no hay FPDF
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=auditoria_' . date('Y-m-d_His') . '.csv');

$output = fopen('php://output', 'w');

fputcsv($output, ['ID', 'Usuario', 'Acción', 'Tabla', 'Fecha', 'Risk Score']);

foreach ($registros as $reg) {
fputcsv($output, [
$reg['id'],
$reg['usuario_nombre'] ?? 'Sistema',
$reg['accion'],
$reg['tabla'],
$reg['created_at'],
$reg['risk_score'] ?? 0
]);
}

fclose($output);
exit;
}

require_once '../vendor/fpdf/fpdf.php';

class PDF_Auditoria extends FPDF {
function Header() {
$this->SetFont('Arial', 'B', 14);
$this->Cell(0, 10, 'REPORTE DE AUDITORIA DEL SISTEMA', 0, 1, 'C');
$this->SetFont('Arial', 'I', 10);
$this->Cell(0, 6, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$this->Ln(5);

$this->SetDrawColor(200, 200, 200);
$this->Line(10, 35, 200, 35);
$this->Ln(5);
}

function Footer() {
$this->SetY(-15);
$this->SetFont('Arial', 'I', 8);
$this->Cell(0, 10, 'Página ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
}

function TablaCabecera() {
$this->SetFont('Arial', 'B', 9);
$this->SetFillColor(230, 230, 230);
$this->Cell(15, 8, 'ID', 1, 0, 'C', true);
$this->Cell(40, 8, 'Usuario', 1, 0, 'L', true);
$this->Cell(35, 8, 'Accion', 1, 0, 'L', true);
$this->Cell(25, 8, 'Tabla', 1, 0, 'L', true);
$this->Cell(20, 8, 'Risk', 1, 0, 'C', true);
$this->Cell(45, 8, 'Fecha', 1, 1, 'C', true);
}

function TablaFila($reg) {
$this->SetFont('Arial', '', 8);
$this->Cell(15, 6, $reg['id'], 1, 0, 'C');
$this->Cell(40, 6, substr($reg['usuario_nombre'] ?? 'Sistema', 0, 20), 1, 0, 'L');
$this->Cell(35, 6, substr($reg['accion'], 0, 20), 1, 0, 'L');
$this->Cell(25, 6, $reg['tabla'], 1, 0, 'L');
$this->Cell(20, 6, $reg['risk_score'] ?? 0, 1, 0, 'C');
$this->Cell(45, 6, date('d/m/Y H:i', strtotime($reg['created_at'])), 1, 1, 'C');
}
}

$pdf = new PDF_Auditoria();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->TablaCabecera();

$contador = 0;
foreach ($registros as $reg) {
$pdf->TablaFila($reg);
$contador++;

// Nueva página cada 50 registros
if ($contador % 50 === 0) {
$pdf->AddPage();
$pdf->TablaCabecera();
}
}

$pdf->Output('I', 'Auditoria_' . date('Y-m-d_His') . '.pdf');
exit;
}
}

// ============================================================================
// 12. OBTENER ESTADÍSTICAS DE AUDITORÍA
// ============================================================================
if (!function_exists('obtenerEstadisticasAuditoria')) {
function obtenerEstadisticasAuditoria($conn, $filtros = []) {
try {
$stats = [
'total_registros' => 0,
'usuarios_unicos' => 0,
'por_accion' => [],
'por_tabla' => [],
'por_usuario' => [],
'por_ip' => [],
'actividad_sospechosa' => 0,
'risk_score_promedio' => 0,
'risk_score_maximo' => 0,
'por_risk_level' => ['BAJO' => 0, 'MEDIO' => 0, 'ALTO' => 0],
'timeline' => [],
'por_hora' => [],
'top_usuarios' => [],
'top_acciones' => []
];

// Construir WHERE clause
$where = "WHERE 1=1";
$params = [];

if (!empty($filtros['fecha_desde'])) {
$where .= " AND DATE(created_at) >= :fecha_desde";
$params[':fecha_desde'] = $filtros['fecha_desde'];
}

if (!empty($filtros['fecha_hasta'])) {
$where .= " AND DATE(created_at) <= :fecha_hasta";
$params[':fecha_hasta'] = $filtros['fecha_hasta'];
}

// Total registros
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM auditoria $where");
$stmt->execute($params);
$stats['total_registros'] = $stmt->fetch()['total'];

// Usuarios únicos
$stmt = $conn->prepare("SELECT COUNT(DISTINCT usuario_id) as total FROM auditoria $where AND usuario_id IS NOT NULL");
$stmt->execute($params);
$stats['usuarios_unicos'] = $stmt->fetch()['total'];

// Por acción (Top 10)
$stmt = $conn->prepare("SELECT accion, COUNT(*) as total FROM auditoria $where GROUP BY accion ORDER BY total DESC LIMIT 10");
$stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$stats['por_accion'][$row['accion']] = $row['total'];
}

// Por tabla (Top 10)
$stmt = $conn->prepare("SELECT tabla, COUNT(*) as total FROM auditoria $where GROUP BY tabla ORDER BY total DESC LIMIT 10");
$stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$stats['por_tabla'][$row['tabla']] = $row['total'];
}

// Risk score promedio y máximo
$stmt = $conn->prepare("SELECT AVG(risk_score) as avg, MAX(risk_score) as max FROM auditoria $where");
$stmt->execute($params);
$result = $stmt->fetch();
$stats['risk_score_promedio'] = round($result['avg'] ?? 0, 2);
$stats['risk_score_maximo'] = $result['max'] ?? 0;

// Por nivel de riesgo
$stmt = $conn->prepare("
SELECT
SUM(CASE WHEN risk_score < 40 THEN 1 ELSE 0 END) as bajo,
SUM(CASE WHEN risk_score >= 40 AND risk_score < 70 THEN 1 ELSE 0 END) as medio,
SUM(CASE WHEN risk_score >= 70 THEN 1 ELSE 0 END) as alto
FROM auditoria $where
");
$stmt->execute($params);
$result = $stmt->fetch();
$stats['por_risk_level']['BAJO'] = $result['bajo'] ?? 0;
$stats['por_risk_level']['MEDIO'] = $result['medio'] ?? 0;
$stats['por_risk_level']['ALTO'] = $result['alto'] ?? 0;

// Actividad sospechosa
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM auditoria $where AND accion LIKE '%ALERTA%'");
$stmt->execute($params);
$stats['actividad_sospechosa'] = $stmt->fetch()['total'];

// Timeline (últimos 7 días)
$stmt = $conn->prepare("
SELECT DATE(created_at) as fecha, COUNT(*) as total
FROM auditoria
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY fecha ASC
");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$stats['timeline'][$row['fecha']] = $row['total'];
}

// Por hora del día (últimos 7 días)
$stmt = $conn->prepare("
SELECT HOUR(created_at) as hora, COUNT(*) as total
FROM auditoria
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY HOUR(created_at)
ORDER BY hora ASC
");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
$stats['por_hora'][$row['hora']] = $row['total'];
}

// Top 10 usuarios más activos (últimos 30 días)
$stmt = $conn->prepare("
SELECT a.usuario_id,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre,
COUNT(*) as total_acciones,
AVG(a.risk_score) as risk_promedio
FROM auditoria a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE a.usuario_id IS NOT NULL
AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY a.usuario_id
ORDER BY total_acciones DESC
LIMIT 10
");
$stmt->execute();
$stats['top_usuarios'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top 10 acciones más realizadas (últimos 30 días)
$stmt = $conn->prepare("
SELECT accion, COUNT(*) as total, AVG(risk_score) as risk_promedio
FROM auditoria
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY accion
ORDER BY total DESC
LIMIT 10
");
$stmt->execute();
$stats['top_acciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

return $stats;

} catch(PDOException $e) {
error_log("Error obteniendo estadísticas: " . $e->getMessage());
return null;
}
}
}

// ============================================================================
// 13. OBTENER REGISTROS RELACIONADOS DE AUDITORÍA
// ============================================================================
if (!function_exists('obtenerRegistrosRelacionados')) {
function obtenerRegistrosRelacionados($conn, $tabla, $registro_id, $limite = 50) {
try {
$stmt = $conn->prepare("
SELECT a.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre,
p.email as usuario_email
FROM auditoria a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE a.tabla = :tabla AND a.registro_id = :registro_id
ORDER BY a.created_at DESC
LIMIT :limite
");

$stmt->execute([
':tabla' => $tabla,
':registro_id' => $registro_id,
':limite' => $limite
]);

return $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
error_log("Error obteniendo registros relacionados: " . $e->getMessage());
return [];
}
}
}

// ============================================================================
// 14. BUSCAR EN HISTORIAL DE AUDITORÍA
// ============================================================================
if (!function_exists('buscarEnHistorial')) {
function buscarEnHistorial($conn, $termino, $filtros = [], $limite = 100) {
try {
$sql = "
SELECT a.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre,
p.email as usuario_email
FROM auditoria a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE (
a.accion LIKE :termino OR
a.tabla LIKE :termino OR
a.detalles LIKE :termino OR
a.ip_address LIKE :termino OR
CONCAT(p.nombre, ' ', p.apellido) LIKE :termino OR
a.request_uri LIKE :termino
)
";

$params = [':termino' => "%$termino%"];

if (!empty($filtros['fecha_desde'])) {
$sql .= " AND DATE(a.created_at) >= :fecha_desde";
$params[':fecha_desde'] = $filtros['fecha_desde'];
}

if (!empty($filtros['fecha_hasta'])) {
$sql .= " AND DATE(a.created_at) <= :fecha_hasta";
$params[':fecha_hasta'] = $filtros['fecha_hasta'];
}

if (!empty($filtros['usuario_id'])) {
$sql .= " AND a.usuario_id = :usuario_id";
$params[':usuario_id'] = $filtros['usuario_id'];
}

if (!empty($filtros['risk_score_min'])) {
$sql .= " AND a.risk_score >= :risk_score_min";
$params[':risk_score_min'] = $filtros['risk_score_min'];
}

$sql .= " ORDER BY a.created_at DESC LIMIT :limite";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);

return $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
error_log("Error buscando en historial: " . $e->getMessage());
return [];
}
}
}

// ============================================================================
// 15. LIMPIAR AUDITORÍA ANTIGUA (Solo administradores)
// ============================================================================
if (!function_exists('limpiarAuditoriaAntigua')) {
function limpiarAuditoriaAntigua($conn, $dias_a_conservar = 365, $usuario_id = null) {
try {
// Contar registros a eliminar
$stmt = $conn->prepare("
SELECT COUNT(*) as total
FROM auditoria
WHERE created_at < DATE_SUB(NOW(), INTERVAL :dias DAY)
");
$stmt->bindValue(':dias', $dias_a_conservar, PDO::PARAM_INT);
$stmt->execute();

$total_a_eliminar = $stmt->fetch()['total'];

if ($total_a_eliminar > 0) {
// Eliminar registros antiguos
$stmt = $conn->prepare("
DELETE FROM auditoria
WHERE created_at < DATE_SUB(NOW(), INTERVAL :dias DAY)
");
$stmt->bindValue(':dias', $dias_a_conservar, PDO::PARAM_INT);
$stmt->execute();

$registros_eliminados = $stmt->rowCount();

// Log de la limpieza
logAuditoria($conn, 'AUDITORIA_LIMPIADA', 'auditoria', null, [
'dias_conservados' => $dias_a_conservar,
'registros_eliminados' => $registros_eliminados
], $usuario_id);

return [
'success' => true,
'registros_eliminados' => $registros_eliminados
];
}

return [
'success' => true,
'registros_eliminados' => 0,
'mensaje' => 'No hay registros antiguos para eliminar'
];

} catch(PDOException $e) {
error_log("Error limpiando auditoría antigua: " . $e->getMessage());
return [
'success' => false,
'error' => $e->getMessage()
];
}
}
}

// ============================================================================
// 16. OBTENER ALERTAS DE SEGURIDAD
// ============================================================================
if (!function_exists('obtenerAlertasSeguridad')) {
function obtenerAlertasSeguridad($conn, $usuario_id = null, $limite = 50) {
try {
$sql = "
SELECT a.*,
CONCAT(p.nombre, ' ', p.apellido) as usuario_nombre
FROM auditoria_alertas a
LEFT JOIN personal p ON a.usuario_id = p.id
WHERE 1=1
";

$params = [];

if ($usuario_id) {
$sql .= " AND a.usuario_id = :usuario_id";
$params[':usuario_id'] = $usuario_id;
}

$sql .= " ORDER BY a.created_at DESC LIMIT :limite";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);

return $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
error_log("Error obteniendo alertas de seguridad: " . $e->getMessage());
return [];
}
}
}

// ============================================================================
// 17. MARCAR ALERTA COMO LEÍDA
// ============================================================================
if (!function_exists('marcarAlertaComoLeida')) {
function marcarAlertaComoLeida($conn, $alerta_id, $usuario_id) {
try {
$stmt = $conn->prepare("
UPDATE auditoria_alertas
SET leida = TRUE, leida_por = :usuario_id, leida_en = NOW()
WHERE id = :alerta_id
");

$stmt->execute([
':alerta_id' => $alerta_id,
':usuario_id' => $usuario_id
]);

return $stmt->rowCount() > 0;

} catch(PDOException $e) {
error_log("Error marcando alerta como leída: " . $e->getMessage());
return false;
}
}
}

// ============================================================================
// 18. VERIFICAR PERMISOS DE AUDITORÍA
// ============================================================================
if (!function_exists('verificarPermisoAuditoria')) {
function verificarPermisoAuditoria($auth, $accion_requerida = 'ver') {
if (!$auth->isLoggedIn()) {
return false;
}

$user = $auth->getCurrentUser();
$roles_permitidos = ['super_admin', 'administrador', 'auditor'];

if (in_array($user['rol'] ?? '', $roles_permitidos)) {
return true;
}

// Log de intento no autorizado
global $conn;
if (isset($conn)) {
logAuditoria($conn, 'ACCESO_NO_AUTORIZADO_AUDITORIA', 'sistema', null, [
'usuario_id' => $user['id'] ?? null,
'accion_intentada' => $accion_requerida,
'rol_usuario' => $user['rol'] ?? 'sin_rol'
]);
}

return false;
}
}

// ============================================================================
// FIN DEL ARCHIVO auditoria_func.php
// ============================================================================
?>