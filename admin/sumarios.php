<?php
/**
* ============================================================================
* GESTIÓN DE SUMARIOS ADMINISTRATIVOS - EMPRESAS DE SEGURIDAD PRIVADA
* ============================================================================
* Incluye: CRUD completo, 8 Pasos del Proceso, Auditoría detallada,
*          Exportación PDF, Validaciones, Paginación, Búsqueda,
*          Vinculación con Inspecciones (Ley I Nº 168 - Chubut)
*          SOLO UN SUMARIO POR INSPECCIÓN
*          ✅ NOTIFICACIONES PDF POR CADA PASO DEL PROCESO (SUBIR Y VER)
*          ✅ CORREGIDO: Manejo de multibyte en iconv para PDF
*          ✅ NUEVO: Alertas de vencimiento y días hábiles precisos
*          ✅ NUEVO: Validación de flujo de trabajo
*          ✅ NUEVO: Historial de cambios de estado
*          ✅ NUEVO: Vacíos Legales / Procedimentales (Críticos)
*                   - Gestión de Recursos (Apelaciones)
*                   - Suspensión de Plazos
*                   - Medio de Notificación
*                   - Silencio Administrativo
*                   - ✅ RESOLUCIÓN DE APELACIÓN ANTES DE CIERRE
*                   - ✅ PDF DE NOTIFICACIÓN DE APELACIÓN GENERABLE POR SEPARADO
*                   - ✅ REFERENCIAS LEGALES POR PASO (Ley 19.549 + Ley I Nº 168)
*                   - ✅ VALIDACIÓN ESTRICTA DE CAMPOS POR PASO
*                   - ✅ PASO 3: DOS VARIANTES (PRESENTÓ / NO PRESENTÓ DESCARGO)
*                   - ✅ NUEVO: RESOLUCIÓN FINAL UNIFICADA (Paso 8 + Apelación)
*                   - ✅ NOTIFICACIONES MEJORADAS CON FORMATO PROFESIONAL Y CLARIDAD
*                   - ✅ RESOLUCIÓN FINAL CLARA PARA NOTIFICACIÓN CON UNIFICACIÓN DE APELACIÓN
*                   - ✅ ARTÍCULOS DE LEY 19.549 EXPLICADOS EN CADA NOTIFICACIÓN
*
* @author Sistema de Seguridad
* @version 5.7 - Notificaciones Mejoradas con Explicación de Ley 19.549
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
// ✅ CONFIGURACIÓN DE FERIAS Y DÍAS NO HÁBILES
// ============================================================================
$feriados_fijos = [
'01-01', // Año Nuevo
'05-01', // Día del Trabajador
'05-25', // Revolución de Mayo
'06-20', // Día de la Bandera
'07-09', // Día de la Independencia
'08-17', // Muerte de San Martín
'10-12', // Día de la Raza
'11-20', // Día de la Soberanía
'12-08', // Inmaculada Concepción
'12-25', // Navidad
];
$feriados_2024 = [
'2024-02-12', '2024-02-13', // Carnaval
'2024-03-29', // Viernes Santo
'2024-04-02', // Malvinas
'2024-06-17', // Güemes
'2024-10-14', // Puente turístico
'2024-12-09', // Puente turístico
];
$feriados_2025 = [
'2025-02-24', '2025-02-25', // Carnaval
'2025-04-18', // Viernes Santo
'2025-04-02', // Malvinas
'2025-06-16', // Güemes
];
// ============================================================================
// ✅ REFERENCIAS LEGALES POR PASO (Integradas de sum.php + Ley I Nº 168 + Ley 19.549)
// ============================================================================
$base_legal_por_paso = [
1 => [
'nacional' => 'Art. 1 Ley 19.549: Impulsión de oficio, celeridad, economía, sencillez, eficacia e informalismo. Art. 7: Requisitos esenciales del acto administrativo (competencia, causa, objeto, procedimiento, motivación, finalidad, forma).',
'provincial' => 'Ley I Nº 168 Art. 23 - Inicio de procedimiento sancionatorio con garantía de legalidad'
],
2 => [
'nacional' => 'Art. 11 Ley 19.549: Notificación fehaciente para eficacia del acto. Art. 12: Presunción de legitimidad y fuerza ejecutoria. Art. 26: Derecho de defensa y traslado por 15 días hábiles.',
'provincial' => 'Ley I Nº 168 Art. 24 - Notificación fehaciente al imputado garantizando el debido proceso'
],
3 => [
'nacional' => 'Art. 1.f.1 y 2 Ley 19.549: Derecho a ser oído, exponer pretensiones y defensas, ofrecer y producir prueba. Art. 27: Plazo para descargo y producción probatoria con contralor de las partes.',
'provincial' => 'Ley I Nº 168 Art. 25 - Presentación de descargo y producción de prueba con garantías constitucionales'
],
4 => [
'nacional' => 'Art. 27, 28, 29 y 30 Ley 19.549: Producción de prueba de oficio o a petición, informes técnicos, dictámenes necesarios para la verdad jurídica objetiva.',
'provincial' => 'Ley I Nº 168 Art. 25 - Producción de prueba de oficio o a petición de parte con intervención del interesado'
],
5 => [
'nacional' => 'Art. 31 Ley 19.549: Vista de actuaciones antes de resolución para alegatos finales. Art. 1.f.3: Decisión fundada con consideración de argumentos principales.',
'provincial' => 'Ley I Nº 168 Art. 26 - Vista de actuaciones antes de resolución garantizando el derecho de defensa'
],
6 => [
'nacional' => 'Art. 32 Ley 19.549: Dictamen jurídico obligatorio para sanciones que afecten derechos. Decreto 1023/2001: Contrataciones públicas. Art. 7.e: Motivación expresa de razones.',
'provincial' => 'Ley I Nº 168 Art. 27 - Dictamen jurídico obligatorio para sanciones graves con fundamentación legal'
],
7 => [
'nacional' => 'Art. 33-35 Ley 19.549: Resolución fundada, recursos procedentes. Art. 84-87: Recursos administrativos (reconsideración, jerárquico), silencio administrativo. Art. 90: Ejecución voluntaria.',
'provincial' => 'Ley I Nº 168 Art. 28-30 - Resolución fundada con recursos procedentes y notificación fehaciente'
],
8 => [
'nacional' => 'Art. 84-90 Ley 19.549: Ejecución de sanciones, cierre del procedimiento, archivo de actuaciones. Art. 12: Fuerza ejecutoria del acto firme. Art. 25: Plazos perentorios para impugnación judicial.',
'provincial' => 'Ley I Nº 168 Art. 31-32 - Ejecución de sanciones y cierre del procedimiento con certificación de cumplimiento'
]
];
// ============================================================================
// ✅ VALIDACIÓN ESTRICTA DE CAMPOS POR PASO
// ============================================================================
$campos_requeridos_por_paso = [
2 => [
'campos' => ['fecha_notificacion_inicio'],
'archivos' => [],
'mensaje' => 'Debe registrar la fecha de notificación de inicio'
],
3 => [
'campos' => ['descargo_presentado', 'fecha_descargo_presentado'],
'archivos' => [],
'mensaje' => 'Debe indicar si se presentó descargo y la fecha de presentación'
],
4 => [
'campos' => ['medidas_probatorias'],
'archivos' => [],
'mensaje' => 'Debe detallar las medidas probatorias dispuestas'
],
5 => [
'campos' => ['alegatos_finales'],
'archivos' => [],
'mensaje' => 'Debe registrar los alegatos finales presentados'
],
6 => [
'campos' => ['tipo_sancion', 'numero_resolucion'],
'archivos' => [],
'mensaje' => 'Debe especificar el tipo de sanción y número de resolución'
],
7 => [
'campos' => ['fecha_notificacion_resolucion'],
'archivos' => ['pdf_notificacion'],
'mensaje' => 'Debe registrar la fecha de notificación de resolución y adjuntar PDF'
],
8 => [
'campos' => ['multa_pagada', 'subsanacion_verificada'],
'archivos' => [],
'mensaje' => 'Debe verificar el estado de pago de multa y subsanación'
]
];
// ============================================================================
// ✅ FUNCIONES DE DÍAS HÁBILES MEJORADAS
// ============================================================================
function esDiaHabil($fecha, $feriados_fijos, $feriados_dinamicos = []) {
$fecha_obj = new DateTime($fecha);
$dia_semana = (int)$fecha_obj->format('N');
// Fin de semana no es hábil (6 = Sábado, 7 = Domingo)
if ($dia_semana >= 6) {
return false;
}
// Verificar feriados fijos
$mes_dia = $fecha_obj->format('m-d');
if (in_array($mes_dia, $feriados_fijos)) {
return false;
}
// Verificar feriados dinámicos
$fecha_str = $fecha_obj->format('Y-m-d');
if (in_array($fecha_str, $feriados_dinamicos)) {
return false;
}
return true;
}
function calcularFechaHabil($fecha_inicio, $dias_habiles, $feriados_fijos, $feriados_dinamicos = []) {
if (empty($fecha_inicio)) {
return date('Y-m-d');
}
$fecha = new DateTime($fecha_inicio);
$dias_sumados = 0;
while ($dias_sumados < $dias_habiles) {
$fecha->modify('+1 day');
if (esDiaHabil($fecha->format('Y-m-d'), $feriados_fijos, $feriados_dinamicos)) {
$dias_sumados++;
}
}
return $fecha->format('Y-m-d');
}
function obtenerFechaVencimiento($fecha_base, $dias_habiles, $feriados_fijos, $feriados_dinamicos = []) {
if (empty($fecha_base)) {
return date('d/m/Y');
}
$fecha = calcularFechaHabil($fecha_base, $dias_habiles, $feriados_fijos, $feriados_dinamicos);
return date('d/m/Y', strtotime($fecha));
}
// ============================================================================
// ✅ NUEVO: FUNCIÓN PARA OBTENER SUSPENSIONES DE UN SUMARIO
// ============================================================================
function obtenerSuspensionesSumario($conn, $sumario_id) {
try {
$stmt = $conn->prepare("SELECT fecha_inicio, fecha_fin FROM sumarios_suspensiones WHERE sumario_id = ? AND activo = 1");
$stmt->execute([$sumario_id]);
return $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
return [];
}
}
// ============================================================================
// ✅ MODIFICADO: CALCULAR DÍAS HÁBILES RESTANTES EXCLUYENDO SUSPENSIONES
// ============================================================================
function calcularDiasHabilesRestantes($fecha_vencimiento, $feriados_fijos, $feriados_dinamicos = [], $suspensiones = []) {
$hoy = new DateTime();
$vencimiento = new DateTime($fecha_vencimiento);
if ($hoy > $vencimiento) {
return 0; // Vencido
}
$dias_rest = 0;
$fecha_iter = clone $hoy;
while ($fecha_iter <= $vencimiento) {
$fecha_str = $fecha_iter->format('Y-m-d');
$en_suspension = false;
foreach ($suspensiones as $susp) {
if ($fecha_str >= $susp['fecha_inicio'] && $fecha_str <= $susp['fecha_fin']) {
$en_suspension = true;
break;
}
}
if (!$en_suspension && esDiaHabil($fecha_str, $feriados_fijos, $feriados_dinamicos)) {
$dias_rest++;
}
$fecha_iter->modify('+1 day');
}
return max(0, $dias_rest - 1); // Restar el día actual
}
function getAlertaVencimiento($fecha_vencimiento, $feriados_fijos, $feriados_dinamicos = [], $suspensiones = []) {
if (empty($fecha_vencimiento)) {
return ['class' => 'secondary', 'icon' => 'fa-clock', 'texto' => 'Sin fecha'];
}
$dias_rest = calcularDiasHabilesRestantes($fecha_vencimiento, $feriados_fijos, $feriados_dinamicos, $suspensiones);
if ($dias_rest < 0) {
return ['class' => 'danger', 'icon' => 'fa-exclamation-triangle', 'texto' => 'VENCIDO', 'dias' => abs($dias_rest)];
} elseif ($dias_rest <= 3) {
return ['class' => 'warning', 'icon' => 'fa-bell', 'texto' => 'PRÓXIMO A VENCER', 'dias' => $dias_rest];
} elseif ($dias_rest <= 7) {
return ['class' => 'info', 'icon' => 'fa-clock', 'texto' => 'EN TIEMPO', 'dias' => $dias_rest];
} else {
return ['class' => 'success', 'icon' => 'fa-check-circle', 'texto' => 'OK', 'dias' => $dias_rest];
}
}
// ============================================================================
// ✅ VALIDACIÓN DE FLUJO DE TRABAJO (MODIFICADO PARA APELACIONES Y VALIDACIÓN ESTRICTA)
// ============================================================================
function validarProgresoPaso($paso_actual, $nuevo_paso, $sumario, $campos_requeridos_por_paso = null, $archivos_subidos = null, $post_data = null) {
global $campos_requeridos_por_paso;
// No permitir retrocesos
if ($nuevo_paso < $paso_actual) {
return ['valido' => false, 'mensaje' => 'No se puede retroceder en el flujo'];
}
// No permitir saltos mayores a 1 paso (excepto cierre)
if ($nuevo_paso - $paso_actual > 1 && $nuevo_paso != 8) {
return ['valido' => false, 'mensaje' => 'Debe completar los pasos intermedios'];
}
// ✅ VALIDACIÓN CRÍTICA: No permitir cierre (Paso 8) si hay apelación pendiente
if ($nuevo_paso == 8 && !empty($sumario['en_apelacion']) && $sumario['en_apelacion'] == 1) {
return ['valido' => false, 'mensaje' => 'No se puede cerrar el sumario mientras haya un recurso de apelación pendiente. Primero debe resolverse la apelación mediante la acción "Resolver Apelación" en el panel de gestión.'];
}
// ✅ VALIDACIÓN ESTRICTA DE CAMPOS POR PASO
if (isset($campos_requeridos_por_paso[$nuevo_paso])) {
$requisitos = $campos_requeridos_por_paso[$nuevo_paso];
// Validar campos requeridos - usar POST data si está disponible para el nuevo paso
foreach ($requisitos['campos'] as $campo) {
$valor = null;
// Si tenemos post_data y el campo está en POST, usar ese valor
if ($post_data && isset($post_data[$campo])) {
$valor = $post_data[$campo];
} elseif (isset($sumario[$campo])) {
// Si no, usar el valor de la base de datos
$valor = $sumario[$campo];
}
if (empty($valor)) {
return ['valido' => false, 'mensaje' => $requisitos['mensaje'] . " (Campo faltante: $campo)"];
}
}
// Validar archivos requeridos si corresponde
if (!empty($requisitos['archivos']) && isset($archivos_subidos)) {
foreach ($requisitos['archivos'] as $archivo) {
if (!isset($archivos_subidos[$archivo]) || $archivos_subidos[$archivo]['error'] !== UPLOAD_ERR_OK) {
return ['valido' => false, 'mensaje' => $requisitos['mensaje'] . " (Archivo requerido: $archivo)"];
}
}
}
}
return ['valido' => true, 'mensaje' => ''];
}
function generarBadgeAlerta($alerta) {
$clases = [
'danger' => 'bg-danger',
'warning' => 'bg-warning text-dark',
'info' => 'bg-info text-dark',
'success' => 'bg-success',
'secondary' => 'bg-secondary'
];
$clase = $clases[$alerta['class']] ?? 'bg-secondary';
return '<span class="badge ' . $clase . '"><i class="fas ' . $alerta['icon'] . ' me-1"></i>' .
$alerta['texto'] .
(isset($alerta['dias']) ? ' (' . $alerta['dias'] . ' días)' : '') .
'</span>';
}
// ============================================================================
// FUNCIONES DE UTILIDAD
// ============================================================================
function getEstadoSumarioBadge($estado) {
$estados = [
'iniciado' => ['class' => 'bg-info', 'icon' => 'fa-play-circle', 'label' => 'Iniciado'],
'notificado' => ['class' => 'bg-primary', 'icon' => 'fa-bell', 'label' => 'Notificado'],
'descargo' => ['class' => 'bg-warning', 'icon' => 'fa-file-alt', 'label' => 'En Descargo'],
'probatoria' => ['class' => 'bg-purple', 'icon' => 'fa-search', 'label' => 'Etapa Probatoria'],
'vista' => ['class' => 'bg-indigo', 'icon' => 'fa-eye', 'label' => 'Vista de Actuados'],
'resolucion' => ['class' => 'bg-danger', 'icon' => 'fa-gavel', 'label' => 'Resolución'],
'notificado_resolucion' => ['class' => 'bg-orange', 'icon' => 'fa-check-circle', 'label' => 'Resolución Notificada'],
'en_apelacion' => ['class' => 'bg-purple', 'icon' => 'fa-balance-scale', 'label' => 'En Apelación'],
'cerrado' => ['class' => 'bg-success', 'icon' => 'fa-archive', 'label' => 'Cerrado']
];
$config = $estados[$estado] ?? ['class' => 'bg-secondary', 'icon' => 'fa-question', 'label' => $estado];
return '<span class="badge bg-' . $config['class'] . '"><i class="fas ' . $config['icon'] . ' me-1"></i>' . $config['label'] . '</span>';
}
function getNombreEstado($estado) {
$estados = [
'iniciado' => 'Iniciado',
'notificado' => 'Notificado',
'descargo' => 'En Descargo',
'probatoria' => 'Etapa Probatoria',
'vista' => 'Vista de Actuados',
'resolucion' => 'Resolución',
'notificado_resolucion' => 'Resolución Notificada',
'en_apelacion' => 'En Apelación',
'cerrado' => 'Cerrado'
];
return $estados[$estado] ?? $estado;
}
// ============================================================================
// FUNCIÓN MEJORADA PARA CONVERTIR UTF-8 A ISO-8859-1 SIN ERRORES
// ============================================================================
function utf8_to_iso88591($text) {
if (empty($text)) return '';
// Reemplazar caracteres problemáticos antes de convertir
$text = str_replace(['ñ', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ü', 'Ü'],
['n', 'N', 'a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'u', 'U'], $text);
try {
$result = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
return $result ?: $text; // Si falla, retorna el texto original
} catch (Exception $e) {
// Loguear pero no interrumpir
error_log("Error en iconv: " . $e->getMessage());
return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8') ?: $text;
}
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
$current_page = 'sumarios';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// ✅ AJAX - RESOLVER APELACIÓN (NUEVO ENDPOINT)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'resolver_apelacion') {
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
echo json_encode(['success' => false, 'error' => 'Método no permitido']);
exit;
}
try {
$sumario_id = (int)($_POST['sumario_id'] ?? 0);
$resolucion_apelacion = trim($_POST['resolucion_apelacion'] ?? '');
$fecha_resolucion_apelacion = $_POST['fecha_resolucion_apelacion'] ?? date('Y-m-d');
$observaciones_resolucion = trim($_POST['observaciones_resolucion'] ?? '');
if ($sumario_id <= 0 || empty($resolucion_apelacion)) {
throw new Exception('Datos incompletos para resolver la apelación');
}
// Verificar que el sumario existe y está en apelación
$stmt = $conn->prepare("SELECT id, estado, en_apelacion FROM sumarios WHERE id = ?");
$stmt->execute([$sumario_id]);
$sumario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sumario) {
throw new Exception('Sumario no encontrado');
}
if ($sumario['en_apelacion'] != 1) {
throw new Exception('Este sumario no tiene una apelación pendiente para resolver');
}
// Actualizar el sumario: marcar apelación como resuelta
$stmt = $conn->prepare("
UPDATE sumarios SET
en_apelacion = 0,
estado = 'notificado_resolucion',
fecha_resolucion_apelacion = ?,
resolucion_apelacion = ?,
observaciones_resolucion = ?,
fecha_actualizacion = CURRENT_TIMESTAMP
WHERE id = ?
");
$stmt->execute([
$fecha_resolucion_apelacion,
$resolucion_apelacion,
$observaciones_resolucion,
$sumario_id
]);
// Registrar en historial
$stmt = $conn->prepare("
INSERT INTO sumarios_historial (sumario_id, usuario_id, paso_anterior, paso_nuevo, estado_anterior, estado_nuevo, campos_modificados)
VALUES (?, ?, 7, 7, 'en_apelacion', 'notificado_resolucion', ?)
");
$campos_mod = json_encode([
'accion' => 'resolucion_apelacion',
'resolucion' => $resolucion_apelacion,
'observaciones' => $observaciones_resolucion
]);
$stmt->execute([$sumario_id, $user['id'], $campos_mod]);
logAuditoria($conn, 'APELACION_RESUELTA', 'sumarios', $sumario_id, [
'resolucion' => $resolucion_apelacion,
'observaciones' => $observaciones_resolucion
], $user['id']);
// ✅ GENERAR Y GUARDAR PDF DE NOTIFICACIÓN DE RESOLUCIÓN DE APELACIÓN UNIFICADA CON PASO 8
require_once('../vendor/fpdf/fpdf.php');
class PDF_Apelacion extends FPDF {
public function UTF8Encode($text) {
if (empty($text)) return '';
$char_replacements = [
'¡' => '!', '¿' => '?',
'ñ' => 'n', 'Ñ' => 'N',
'á' => 'a', 'é' => 'e', 'í' => 'i',
'ó' => 'o', 'ú' => 'u',
'Á' => 'A', 'É' => 'E', 'Í' => 'I',
'Ó' => 'O', 'Ú' => 'U',
'ü' => 'u', 'Ü' => 'U'
];
$text = strtr($text, $char_replacements);
try {
return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
} catch (Exception $e) {
return @iconv('UTF-8', 'ISO-8859-1', $text);
}
}
public function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=0, $link='') {
parent::Cell($w, $h, $this->UTF8Encode($txt), $border, $ln, $align, $fill, $link);
}
public function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=0) {
parent::MultiCell($w, $h, $this->UTF8Encode($txt), $border, $align, $fill);
}
public function Write($h, $txt, $link='') {
parent::Write($h, $this->UTF8Encode($txt), $link);
}
public function Header() {
$this->SetFont('Arial', 'B', 14);
$this->Cell(0, 10, 'AUTORIDAD DE APLICACIÓN - LEY I Nº 168', 0, 1, 'C');
$this->SetFont('Arial', '', 10);
$this->Cell(0, 6, 'EMPRESAS DE SEGURIDAD PRIVADA - PROVINCIA DEL CHUBUT', 0, 1, 'C');
$this->Ln(5);
$this->Line(10, $this->GetY(), 200, $this->GetY());
$this->Ln(5);
}
public function Footer() {
$this->SetY(-25);
$this->SetFont('Arial', 'I', 8);
$this->Cell(0, 10, 'Página ' . $this->PageNo(), 0, 0, 'C');
$this->Ln(5);
$this->Cell(0, 10, 'Documento generado electrónicamente - ' . date('d/m/Y H:i'), 0, 0, 'C');
}
public function Firma($cargo, $fecha) {
$this->Ln(30);
$this->Cell(90, 10, '_________________________', 0, 0, 'C');
$this->Cell(90, 10, '_________________________', 0, 1, 'C');
$this->Cell(90, 7, $cargo, 0, 0, 'C');
$this->Cell(90, 7, 'Fecha: ' . $fecha, 0, 1, 'C');
}
}
$pdf = new PDF_Apelacion('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'RESOLUCIÓN FINAL UNIFICADA', 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, '(Apelación Resuelta + Notificación de Cierre)', 0, 1, 'C');
$pdf->Ln(10);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'SUMARIO N°:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, ($sumario['numero_expediente'] ?? '') . '/' . ($sumario['numero_anio'] ?? ''), 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Empresa:', 0, 0);
$pdf->Cell(0, 7, $sumario['empresa_nombre'] ?? '-', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'RESOLUCIÓN DEL RECURSO DE APELACIÓN:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$resolucion_texto = '';
switch ($resolucion_apelacion) {
case 'confirmada': $resolucion_texto = 'CONFIRMADA - Se mantiene la sanción original'; break;
case 'revocada': $resolucion_texto = 'REVOCADA - Se deja sin efecto la sanción'; break;
case 'modificada': $resolucion_texto = 'MODIFICADA - Se ajusta la sanción aplicada'; break;
}
$pdf->MultiCell(0, 7, utf8_to_iso88591('Mediante la presente se notifica que el recurso de apelación interpuesto ha sido resuelto como: ' . strtoupper($resolucion_texto) . '.'), 0, 'L');
$pdf->Ln(5);
if (!empty($observaciones_resolucion)) {
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'FUNDAMENTOS:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591($observaciones_resolucion), 0, 'L');
$pdf->Ln(5);
}
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'EFECTOS Y NOTIFICACIÓN DE CIERRE:', 0, 1);
$pdf->SetFont('Arial', '', 11);
if ($resolucion_apelacion === 'confirmada') {
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ La sanción original queda FIRME Y EJECUTORIA. Deberá procederse al cumplimiento de lo dispuesto en la Resolución notificada dentro de los plazos legales establecidos.'), 0, 'L');
$pdf->Ln(3);
$pdf->MultiCell(0, 7, utf8_to_iso88591('📋 ESTADO DEL SUMARIO: CERRADO - Se archivan las actuaciones una vez cumplida la sanción o verificada la subsanación.'), 0, 'L');
} elseif ($resolucion_apelacion === 'revocada') {
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ La sanción queda SIN EFECTO. Se archivan las actuaciones sin más trámite y se deja sin efecto cualquier medida cautelar vigente.'), 0, 'L');
$pdf->Ln(3);
$pdf->MultiCell(0, 7, utf8_to_iso88591('📋 ESTADO DEL SUMARIO: CERRADO SIN SANCION - Se expide certificado de cierre sin antecedentes.'), 0, 'L');
} else {
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ La sanción ha sido MODIFICADA conforme a lo resuelto. Deberá notificarse el nuevo contenido y proceder a su cumplimiento según los nuevos términos establecidos.'), 0, 'L');
$pdf->Ln(3);
$pdf->MultiCell(0, 7, utf8_to_iso88591('📋 ESTADO DEL SUMARIO: CERRADO CON MODIFICACIÓN - Se notifica la nueva resolución y se procede al cumplimiento ajustado.'), 0, 'L');
}
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, '⚖️ BASE LEGAL APLICABLE (LEY 19.549):', 0, 1);
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 12: Presunción de legitimidad y fuerza ejecutoria del acto administrativo firme"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 33-35: Resolución fundada con motivación expresa de hechos y derecho aplicable"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 84-87: Recursos administrativos y silencio administrativo (30 días hábiles para resolver)"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 90: Ejecución voluntaria de sanciones y cierre del procedimiento"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 25: Plazo perentorio de 90 días hábiles para impugnación judicial desde notificación"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Ley I Nº 168 (Chubut): Arts. 28-32 - Procedimiento sancionatorio y recursos"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Constitución Nacional: Art. 18 - Derecho de defensa en juicio"), 0, 'L');
$pdf->Ln(10);
$pdf->Firma('Autoridad de Aplicación', date('d/m/Y', strtotime($fecha_resolucion_apelacion)));
$filename_apelacion = 'resolucion_final_unificada_sumario_' . ($sumario['numero_expediente'] ?? $sumario['id']) . '_' . date('Y-m-d') . '.pdf';
$upload_dir_apelacion = '../uploads/sumarios/notificaciones_firmadas/';
if (!file_exists($upload_dir_apelacion)) {
mkdir($upload_dir_apelacion, 0755, true);
}
$pdf_path_apelacion = $upload_dir_apelacion . $filename_apelacion;
$pdf->Output('F', $pdf_path_apelacion);
// Guardar referencia en BD
$stmt = $conn->prepare("
INSERT INTO notificaciones_archivos (sumario_id, paso_actual, nombre_archivo, ruta_archivo, tipo_notificacion)
VALUES (?, ?, ?, ?, 'resolucion_final')
");
$stmt->execute([$sumario_id, 8, $filename_apelacion, $filename_apelacion]);
logAuditoria($conn, 'PDF_RESOLUCION_FINAL_GENERADO', 'notificaciones_archivos', null, [
'sumario_id' => $sumario_id,
'archivo' => $filename_apelacion
], $user['id']);
echo json_encode([
'success' => true,
'mensaje' => 'Apelación resuelta y Resolución Final Unificada generada exitosamente. El sumario ha sido cerrado.',
'sumario_id' => $sumario_id,
'pdf_generado' => $filename_apelacion
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
}
// ============================================================================
// ✅ AJAX - GENERAR PDF DE NOTIFICACIÓN DE APELACIÓN (NUEVO ENDPOINT)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'generar_notificacion_apelacion_pdf' && isset($_GET['id'])) {
if (ob_get_level()) ob_end_clean();
try {
$sumario_id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM sumarios WHERE id = ?");
$stmt->execute([$sumario_id]);
$sumario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sumario) {
throw new Exception('Sumario no encontrado');
}
if (empty($sumario['resolucion_apelacion'])) {
throw new Exception('Este sumario no tiene una resolución de apelación registrada');
}
require_once('../vendor/fpdf/fpdf.php');
class PDF_Apelacion_Generar extends FPDF {
public function UTF8Encode($text) {
if (empty($text)) return '';
$char_replacements = [
'¡' => '!', '¿' => '?',
'ñ' => 'n', 'Ñ' => 'N',
'á' => 'a', 'é' => 'e', 'í' => 'i',
'ó' => 'o', 'ú' => 'u',
'Á' => 'A', 'É' => 'E', 'Í' => 'I',
'Ó' => 'O', 'Ú' => 'U',
'ü' => 'u', 'Ü' => 'U'
];
$text = strtr($text, $char_replacements);
try {
return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
} catch (Exception $e) {
return @iconv('UTF-8', 'ISO-8859-1', $text);
}
}
public function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=0, $link='') {
parent::Cell($w, $h, $this->UTF8Encode($txt), $border, $ln, $align, $fill, $link);
}
public function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=0) {
parent::MultiCell($w, $h, $this->UTF8Encode($txt), $border, $align, $fill);
}
public function Write($h, $txt, $link='') {
parent::Write($h, $this->UTF8Encode($txt), $link);
}
public function Header() {
$this->SetFont('Arial', 'B', 14);
$this->Cell(0, 10, 'AUTORIDAD DE APLICACIÓN - LEY I Nº 168', 0, 1, 'C');
$this->SetFont('Arial', '', 10);
$this->Cell(0, 6, 'EMPRESAS DE SEGURIDAD PRIVADA - PROVINCIA DEL CHUBUT', 0, 1, 'C');
$this->Ln(5);
$this->Line(10, $this->GetY(), 200, $this->GetY());
$this->Ln(5);
}
public function Footer() {
$this->SetY(-25);
$this->SetFont('Arial', 'I', 8);
$this->Cell(0, 10, 'Página ' . $this->PageNo(), 0, 0, 'C');
$this->Ln(5);
$this->Cell(0, 10, 'Documento generado electrónicamente - ' . date('d/m/Y H:i'), 0, 0, 'C');
}
public function Firma($cargo, $fecha) {
$this->Ln(30);
$this->Cell(90, 10, '_________________________', 0, 0, 'C');
$this->Cell(90, 10, '_________________________', 0, 1, 'C');
$this->Cell(90, 7, $cargo, 0, 0, 'C');
$this->Cell(90, 7, 'Fecha: ' . $fecha, 0, 1, 'C');
}
}
$pdf = new PDF_Apelacion_Generar('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'RESOLUCIÓN FINAL UNIFICADA', 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, '(Apelación Resuelta + Notificación de Cierre)', 0, 1, 'C');
$pdf->Ln(10);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'SUMARIO N°:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, ($sumario['numero_expediente'] ?? '') . '/' . ($sumario['numero_anio'] ?? ''), 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Empresa:', 0, 0);
$pdf->Cell(0, 7, $sumario['empresa_nombre'] ?? '-', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'RESOLUCIÓN DEL RECURSO DE APELACIÓN:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$resolucion_texto = '';
switch ($sumario['resolucion_apelacion']) {
case 'confirmada': $resolucion_texto = 'CONFIRMADA - Se mantiene la sanción original'; break;
case 'revocada': $resolucion_texto = 'REVOCADA - Se deja sin efecto la sanción'; break;
case 'modificada': $resolucion_texto = 'MODIFICADA - Se ajusta la sanción aplicada'; break;
}
$pdf->MultiCell(0, 7, utf8_to_iso88591('Mediante la presente se notifica que el recurso de apelación interpuesto ha sido resuelto como: ' . strtoupper($resolucion_texto) . '.'), 0, 'L');
$pdf->Ln(5);
if (!empty($sumario['observaciones_resolucion'])) {
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'FUNDAMENTOS:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591($sumario['observaciones_resolucion']), 0, 'L');
$pdf->Ln(5);
}
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'EFECTOS Y NOTIFICACIÓN DE CIERRE:', 0, 1);
$pdf->SetFont('Arial', '', 11);
if ($sumario['resolucion_apelacion'] === 'confirmada') {
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ La sanción original queda FIRME Y EJECUTORIA. Deberá procederse al cumplimiento de lo dispuesto en la Resolución notificada dentro de los plazos legales establecidos.'), 0, 'L');
$pdf->Ln(3);
$pdf->MultiCell(0, 7, utf8_to_iso88591('📋 ESTADO DEL SUMARIO: CERRADO - Se archivan las actuaciones una vez cumplida la sanción o verificada la subsanación.'), 0, 'L');
} elseif ($sumario['resolucion_apelacion'] === 'revocada') {
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ La sanción queda SIN EFECTO. Se archivan las actuaciones sin más trámite y se deja sin efecto cualquier medida cautelar vigente.'), 0, 'L');
$pdf->Ln(3);
$pdf->MultiCell(0, 7, utf8_to_iso88591('📋 ESTADO DEL SUMARIO: CERRADO SIN SANCION - Se expide certificado de cierre sin antecedentes.'), 0, 'L');
} else {
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ La sanción ha sido MODIFICADA conforme a lo resuelto. Deberá notificarse el nuevo contenido y proceder a su cumplimiento según los nuevos términos establecidos.'), 0, 'L');
$pdf->Ln(3);
$pdf->MultiCell(0, 7, utf8_to_iso88591('📋 ESTADO DEL SUMARIO: CERRADO CON MODIFICACIÓN - Se notifica la nueva resolución y se procede al cumplimiento ajustado.'), 0, 'L');
}
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, '⚖️ BASE LEGAL APLICABLE (LEY 19.549):', 0, 1);
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 12: Presunción de legitimidad y fuerza ejecutoria del acto administrativo firme"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 33-35: Resolución fundada con motivación expresa de hechos y derecho aplicable"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 84-87: Recursos administrativos y silencio administrativo (30 días hábiles para resolver)"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 90: Ejecución voluntaria de sanciones y cierre del procedimiento"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Art. 25: Plazo perentorio de 90 días hábiles para impugnación judicial desde notificación"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Ley I Nº 168 (Chubut): Arts. 28-32 - Procedimiento sancionatorio y recursos"), 0, 'L');
$pdf->MultiCell(0, 5, utf8_to_iso88591("• Constitución Nacional: Art. 18 - Derecho de defensa en juicio"), 0, 'L');
$pdf->Ln(10);
$fecha_resolucion = !empty($sumario['fecha_resolucion_apelacion']) ? date('d/m/Y', strtotime($sumario['fecha_resolucion_apelacion'])) : date('d/m/Y');
$pdf->Firma('Autoridad de Aplicación', $fecha_resolucion);
$filename = 'resolucion_final_unificada_sumario_' . ($sumario['numero_expediente'] ?? $sumario['id']) . '_' . date('Y-m-d') . '.pdf';
$upload_dir = '../uploads/sumarios/notificaciones_firmadas/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
$pdf_path = $upload_dir . $filename;
$pdf->Output('F', $pdf_path);
$pdf->Output('D', $filename);
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al generar PDF de apelación: ' . $e->getMessage();
header('Location: sumarios.php');
exit;
}
}
// ============================================================================
// ✅ AJAX - SUBIR ARCHIVO PDF DE NOTIFICACIÓN
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'subir_notificacion_pdf') {
header('Content-Type: application/json');
if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
echo json_encode(['success' => false, 'error' => 'No se recibió ningún archivo']);
exit;
}
try {
$sumario_id = (int)($_POST['sumario_id'] ?? 0);
$paso_actual = (int)($_POST['paso_actual'] ?? 0);
if ($sumario_id <= 0 || $paso_actual <= 0) {
throw new Exception('Datos inválidos');
}
// Verificar que el sumario existe
$stmt = $conn->prepare("SELECT id FROM sumarios WHERE id = ?");
$stmt->execute([$sumario_id]);
if (!$stmt->fetch()) {
throw new Exception('Sumario no encontrado');
}
$upload_dir = '../uploads/sumarios/notificaciones_firmadas/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
$extension = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
if (!in_array(strtolower($extension), ['pdf'])) {
throw new Exception('Solo se permiten archivos PDF');
}
$max_size = 5 * 1024 * 1024; // 5MB
if ($_FILES['archivo']['size'] > $max_size) {
throw new Exception('El archivo excede el tamaño máximo permitido (5MB)');
}
// Generar nombre único
$nuevo_nombre = 'notificacion_' . $sumario_id . '_paso' . $paso_actual . '_' . time() . '.pdf';
$ruta_completa = $upload_dir . $nuevo_nombre;
if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $ruta_completa)) {
throw new Exception('Error al mover el archivo');
}
// ✅ CAMPOS DE MEDIO DE NOTIFICACIÓN
$tipo_notificacion = $_POST['tipo_notificacion'] ?? 'personal';
$numero_cedula = $_POST['numero_cedula'] ?? null;
$numero_carta_documento = $_POST['numero_carta_documento'] ?? null;
$fecha_notificacion_legal = $_POST['fecha_notificacion_legal'] ?? date('Y-m-d');
// Guardar en BD con nuevos campos
$stmt = $conn->prepare("
INSERT INTO notificaciones_archivos (sumario_id, paso_actual, nombre_archivo, ruta_archivo, tipo_notificacion, numero_cedula, numero_carta_documento, fecha_notificacion_legal)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$sumario_id, $paso_actual, $nuevo_nombre, $nuevo_nombre, $tipo_notificacion, $numero_cedula, $numero_carta_documento, $fecha_notificacion_legal]);
$archivo_id = $conn->lastInsertId();
logAuditoria($conn, 'PDF_NOTIFICACION_SUBIDO', 'notificaciones_archivos', $archivo_id, [
'sumario_id' => $sumario_id,
'paso' => $paso_actual,
'archivo' => $nuevo_nombre,
'tipo_notificacion' => $tipo_notificacion
], $user['id']);
echo json_encode([
'success' => true,
'archivo_id' => $archivo_id,
'nombre' => $nuevo_nombre,
'url' => 'uploads/sumarios/notificaciones_firmadas/' . $nuevo_nombre
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
}
// ============================================================================
// ✅ AJAX - OBTENER LISTA DE NOTIFICACIONES PARA UN SUMARIO
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'obtener_notificaciones') {
header('Content-Type: application/json');
try {
$sumario_id = (int)$_GET['id'];
$stmt = $conn->prepare("
SELECT * FROM notificaciones_archivos
WHERE sumario_id = ?
ORDER BY paso_actual ASC, fecha_subida DESC
");
$stmt->execute([$sumario_id]);
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'notificaciones' => $notificaciones]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
}
// ============================================================================
// ✅ AJAX - GENERAR PDF DE NOTIFICACIÓN POR PASO (CORREGIDO - LEY 19.549)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'generar_notificacion_pdf' && isset($_GET['id']) && isset($_GET['paso'])) {
// Limpiar cualquier buffer previo
if (ob_get_level()) ob_end_clean();
try {
$sumario_id = (int)$_GET['id'];
$paso = (int)$_GET['paso'];
// ✅ NUEVO: Parámetro para variante de Paso 3 (si_presento / no_presento)
$variante_paso3 = $_GET['variante'] ?? null;
$stmt = $conn->prepare("SELECT * FROM sumarios WHERE id = ?");
$stmt->execute([$sumario_id]);
$sumario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sumario) {
throw new Exception('Sumario no encontrado');
}
// ✅ OBTENER REFERENCIA LEGAL PARA ESTE PASO
global $base_legal_por_paso;
$referencia_legal = isset($base_legal_por_paso[$paso]) ? $base_legal_por_paso[$paso] : null;
require_once('../vendor/fpdf/fpdf.php');
class PDF_Notificacion extends FPDF {
public function UTF8Encode($text) {
if (empty($text)) return '';
// Pre-procesamiento de caracteres
$char_replacements = [
'¡' => '!', '¿' => '?',
'ñ' => 'n', 'Ñ' => 'N',
'á' => 'a', 'é' => 'e', 'í' => 'i',
'ó' => 'o', 'ú' => 'u',
'Á' => 'A', 'É' => 'E', 'Í' => 'I',
'Ó' => 'O', 'Ú' => 'U',
'ü' => 'u', 'Ü' => 'U'
];
$text = strtr($text, $char_replacements);
try {
return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
} catch (Exception $e) {
return @iconv('UTF-8', 'ISO-8859-1', $text);
}
}
public function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=0, $link='') {
parent::Cell($w, $h, $this->UTF8Encode($txt), $border, $ln, $align, $fill, $link);
}
public function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=0) {
parent::MultiCell($w, $h, $this->UTF8Encode($txt), $border, $align, $fill);
}
public function Write($h, $txt, $link='') {
parent::Write($h, $this->UTF8Encode($txt), $link);
}
public function Header() {
$this->SetFont('Arial', 'B', 14);
$this->Cell(0, 10, 'AUTORIDAD DE APLICACIÓN - LEY I Nº 168', 0, 1, 'C');
$this->SetFont('Arial', '', 10);
$this->Cell(0, 6, 'EMPRESAS DE SEGURIDAD PRIVADA - PROVINCIA DEL CHUBUT', 0, 1, 'C');
$this->Ln(5);
$this->Line(10, $this->GetY(), 200, $this->GetY());
$this->Ln(5);
}
public function Footer() {
$this->SetY(-25);
$this->SetFont('Arial', 'I', 8);
$this->Cell(0, 10, 'Página ' . $this->PageNo(), 0, 0, 'C');
$this->Ln(5);
$this->Cell(0, 10, 'Documento generado electrónicamente - ' . date('d/m/Y H:i'), 0, 0, 'C');
}
public function Firma($cargo, $fecha) {
$this->Ln(30);
$this->Cell(90, 10, '_________________________', 0, 0, 'C');
$this->Cell(90, 10, '_________________________', 0, 1, 'C');
$this->Cell(90, 7, $cargo, 0, 0, 'C');
$this->Cell(90, 7, 'Fecha: ' . $fecha, 0, 1, 'C');
}
// ✅ NUEVO MÉTODO: Agregar sección de base legal con explicación de artículos
public function AgregarBaseLegalExplicada($nacional, $provincial, $paso) {
$this->Ln(5);
$this->SetFont('Arial', 'B', 10);
$this->SetFillColor(245, 245, 245);
$this->Cell(0, 6, '⚖️ FUNDAMENTACIÓN LEGAL - LEY 19.549:', 0, 1, 'L', true);
$this->SetFont('Arial', '', 8);
// Explicación según el paso
switch($paso) {
case 1:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 1: Principios rectores - Impulsión de oficio (la Administración impulsa el trámite), celeridad (agilidad), economía (eficiencia de recursos), sencillez (formalidades mínimas), eficacia (logro de fines) e informalismo (excusa de errores formales no esenciales)."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 7: Requisitos esenciales del acto - Competencia (autoridad habilitada), Causa (hechos y derecho), Objeto (cierto y posible), Procedimiento (trámites esenciales), Motivación (razones expresas), Finalidad (propósito legal) y Forma (escrita y firmada)."), 0, 'L', true);
break;
case 2:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 11: Eficacia del acto - Para que el acto administrativo produzca efectos debe ser notificado fehacientemente al interesado. La notificación es el acto de comunicación oficial que hace conocer el contenido del acto."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 12: Presunción de legitimidad - Todo acto administrativo se presume válido y legítimo hasta que se declare lo contrario. Tiene fuerza ejecutoria: la Administración puede hacerlo cumplir por sus propios medios."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 26: Derecho de defensa - El interesado tiene derecho a ser oído, presentar descargo y producir prueba antes de que se emita un acto que afecte sus derechos."), 0, 'L', true);
break;
case 3:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 1.f.1: Derecho a ser oído - El administrado puede exponer las razones de sus pretensiones y defensas antes de la emisión de actos que se refieran a sus derechos subjetivos o intereses legítimos."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 1.f.2: Derecho probatorio - Puede ofrecer prueba y que ésta se produzca si es pertinente. La Administración debe requerir y producir los informes necesarios para esclarecer la verdad jurídica objetiva."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 27: Plazos procesales - Los plazos para descargo y producción de prueba se cuentan por días hábiles administrativos y son obligatorios para las partes y la Administración."), 0, 'L', true);
break;
case 4:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 27-30: Producción de prueba - La Administración puede producir prueba de oficio o a petición de parte. Debe producir los informes y dictámenes necesarios para el esclarecimiento de los hechos y la verdad jurídica objetiva."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 1.e.2: Plazos probatorios - Se fijan atendiendo a la complejidad del asunto y la índole de la prueba, con contralor de los interesados quienes pueden presentar alegatos una vez concluido el período probatorio."), 0, 'L', true);
break;
case 5:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 31: Vista de actuaciones - Antes de dictar resolución, debe ponerse a vista del interesado el expediente completo para que formule alegatos finales, garantizando el derecho de defensa del Art. 18 de la Constitución Nacional."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 1.f.3: Decisión fundada - El acto decisorio debe hacer expresa consideración de los principales argumentos y cuestiones propuestas, en tanto fueren conducentes a la solución del caso."), 0, 'L', true);
break;
case 6:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 32: Dictamen jurídico - Para sanciones que puedan afectar derechos subjetivos, es esencial el dictamen de los servicios permanentes de asesoramiento jurídico antes de la emisión del acto."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 7.e: Motivación expresa - El acto debe expresar en forma concreta las razones que inducen a emitirlo, consignando los hechos, antecedentes y derecho aplicable que le sirven de causa."), 0, 'L', true);
break;
case 7:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 33-35: Resolución y recursos - La resolución debe ser fundada y notificar los recursos procedentes. Art. 84-85: Recursos de reconsideración y jerárquico por 10 días hábiles."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 86-87: Silencio administrativo - Si la Administración no resuelve el recurso en 30 días hábiles, opera el silencio administrativo negativo, habilitando la vía judicial."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 90: Ejecución voluntaria - El interesado puede cumplir voluntariamente la sanción dentro de los plazos establecidos, evitando la ejecución forzosa."), 0, 'L', true);
break;
case 8:
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 84-90: Cierre del procedimiento - Una vez firme la resolución y cumplida la sanción (o verificada la subsanación), se archivan las actuaciones. El acto firme tiene fuerza ejecutoria."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 25: Impugnación judicial - Contra el acto firme procede acción judicial dentro de 90 días hábiles desde la notificación, agotada la vía administrativa."), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Art. 12: Fuerza ejecutoria - El acto administrativo firme faculta a la Administración a ponerlo en práctica por sus propios medios, salvo que la ley exija intervención judicial."), 0, 'L', true);
break;
}
$this->MultiCell(0, 5, utf8_to_iso88591("• Ley I Nº 168 (Chubut): $provincial"), 0, 'L', true);
$this->MultiCell(0, 5, utf8_to_iso88591("• Constitución Nacional: Art. 18 - Derecho de defensa en juicio"), 0, 'L', true);
$this->Ln(3);
}
// ✅ NUEVO MÉTODO: Encabezado mejorado con logo y datos
public function EncabezadoMejorado($titulo, $subtitulo = '') {
$this->SetFont('Arial', 'B', 16);
$this->Cell(0, 10, utf8_to_iso88591($titulo), 0, 1, 'C');
if (!empty($subtitulo)) {
$this->SetFont('Arial', 'B', 12);
$this->Cell(0, 8, utf8_to_iso88591($subtitulo), 0, 1, 'C');
}
$this->Ln(5);
}
// ✅ NUEVO MÉTODO: Sección de datos del expediente
public function SeccionDatosExpediente($sumario) {
$this->SetFont('Arial', '', 10);
$this->SetFillColor(240, 240, 240);
$this->Cell(0, 6, 'DATOS DEL EXPEDIENTE', 0, 1, 'L', true);
$this->SetFont('Arial', '', 9);
$this->Cell(45, 5, 'Expediente N°:', 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(0, 5, ($sumario['numero_expediente'] ?? '') . '/' . ($sumario['numero_anio'] ?? ''), 0, 1);
$this->SetFont('Arial', '', 9);
$this->Cell(45, 5, 'Empresa:', 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(0, 5, $sumario['empresa_nombre'] ?? '-', 0, 1);
$this->SetFont('Arial', '', 9);
$this->Cell(45, 5, 'CUIT:', 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(0, 5, $sumario['empresa_cuit'] ?? '-', 0, 1);
$this->SetFont('Arial', '', 9);
$this->Cell(45, 5, 'Acta Base:', 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(0, 5, $sumario['acta_numero'] ?? '-', 0, 1);
$this->Ln(3);
}
// ✅ NUEVO MÉTODO: Sección de resolución final unificada clara
public function SeccionResolucionFinalUnificada($sumario, $referencia_legal = null) {
$this->SetFont('Arial', 'B', 14);
$this->Cell(0, 10, 'RESOLUCIÓN FINAL UNIFICADA', 0, 1, 'C');
$this->SetFont('Arial', 'B', 11);
$this->Cell(0, 8, '(Notificación de Cierre + Resolución de Apelación si corresponde)', 0, 1, 'C');
$this->Ln(8);
// Datos del expediente
$this->SeccionDatosExpediente($sumario);
$this->Ln(5);
// ✅ INFORMACIÓN DE APELACIÓN SI CORRESPONDE
if (!empty($sumario['resolucion_apelacion'])) {
$this->SetFont('Arial', 'B', 11);
$this->SetFillColor(230, 240, 255);
$this->Cell(0, 7, '📋 ANTECEDENTE: RESOLUCIÓN DE APELACIÓN', 0, 1, 'L', true);
$this->SetFont('Arial', '', 10);
$resolucion_texto = '';
switch ($sumario['resolucion_apelacion']) {
case 'confirmada': $resolucion_texto = 'CONFIRMADA - Se mantiene la sanción original'; break;
case 'revocada': $resolucion_texto = 'REVOCADA - Se deja sin efecto la sanción'; break;
case 'modificada': $resolucion_texto = 'MODIFICADA - Se ajusta la sanción aplicada'; break;
}
$this->MultiCell(0, 6, utf8_to_iso88591("• Resultado del recurso: " . strtoupper($resolucion_texto)), 0, 'L', true);
if (!empty($sumario['fecha_resolucion_apelacion'])) {
$this->MultiCell(0, 6, utf8_to_iso88591("• Fecha de resolución: " . date('d/m/Y', strtotime($sumario['fecha_resolucion_apelacion']))), 0, 'L', true);
}
if (!empty($sumario['observaciones_resolucion'])) {
$this->MultiCell(0, 6, utf8_to_iso88591("• Fundamentos: " . $sumario['observaciones_resolucion']), 0, 'L', true);
}
$this->Ln(5);
}
// ✅ ESTADO DE CUMPLIMIENTO Y CIERRE
$multa_pagada = ($sumario['multa_pagada'] == 1);
$subsanacion_verificada = ($sumario['subsanacion_verificada'] == 1);
if ($multa_pagada && $subsanacion_verificada) {
$this->SetFont('Arial', 'B', 12);
$this->SetFillColor(220, 255, 220);
$this->Cell(0, 8, '✅ CUMPLIMIENTO VERIFICADO - CIERRE DEL SUMARIO', 0, 1, 'L', true);
$this->SetFont('Arial', '', 10);
$this->MultiCell(0, 6, utf8_to_iso88591('Se deja constancia que la agencia ha subsanado las irregularidades y ha abonado las multas, conforme al principio de ejecución voluntaria del Art. 90 de la Ley 19.549.'), 0, 'L');
$this->Ln(3);
$this->SetFont('Arial', 'B', 11);
$this->Cell(0, 8, '📋 SE DISPONE EL CIERRE DEFINITIVO DEL SUMARIO', 0, 1, 'C');
$this->SetFont('Arial', '', 10);
$this->MultiCell(0, 6, utf8_to_iso88591('Las actuaciones se archivan sin más trámite. Se expide certificado de cierre a solicitud de parte.'), 0, 'L');
} else {
$this->SetFont('Arial', 'B', 12);
$this->SetFillColor(255, 240, 240);
$this->Cell(0, 8, '⚠️ INCUMPLIMIENTO - EJECUCIÓN FORZOSA', 0, 1, 'L', true);
$this->SetFont('Arial', '', 10);
$this->MultiCell(0, 6, utf8_to_iso88591('Vencido el plazo sin cumplimiento de la sanción, se procederá a la ejecución forzosa y/o cancelación de la Habilitación de la Agencia, conforme al Art. 90 de la Ley 19.549.'), 0, 'L');
$this->Ln(3);
$this->SetFont('Arial', 'B', 11);
$this->Cell(0, 7, 'Estado de Cumplimiento:', 0, 1);
$this->SetFont('Arial', '', 10);
$this->Cell(40, 6, 'Multa Pagada:', 0, 0);
$this->Cell(0, 6, $multa_pagada ? '✅ SI' : '❌ NO', 0, 1);
$this->Cell(40, 6, 'Subsanación Verificada:', 0, 0);
$this->Cell(0, 6, $subsanacion_verificada ? '✅ SI' : '❌ NO', 0, 1);
$this->Ln(3);
$this->SetFont('Arial', 'B', 11);
$this->Cell(0, 7, 'MEDIDAS A ADOPTAR:', 0, 1);
$this->SetFont('Arial', '', 10);
$this->MultiCell(0, 6, utf8_to_iso88591('1. Intimación final por carta documento para cumplimiento en 48hs hábiles.'), 0, 'L');
$this->MultiCell(0, 6, utf8_to_iso88591('2. Inicio de procedimiento de ejecución forzosa ante incumplimiento.'), 0, 'L');
$this->MultiCell(0, 6, utf8_to_iso88591('3. Comunicación a ANMAC para eventual cancelación de habilitación.'), 0, 'L');
}
$this->Ln(8);
// ✅ BASE LEGAL CONSOLIDADA CON EXPLICACIÓN
$this->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 8);
// ✅ NOTAS ADMINISTRATIVAS CLARAS
$this->SetFont('Arial', 'B', 10);
$this->SetFillColor(245, 245, 245);
$this->Cell(0, 6, '📝 NOTAS ADMINISTRATIVAS:', 0, 1, 'L', true);
$this->SetFont('Arial', '', 9);
$this->MultiCell(0, 5, utf8_to_iso88591("• La presente resolución es FIRME Y EJECUTORIA, agotando la vía administrativa."), 0, 'L');
$this->MultiCell(0, 5, utf8_to_iso88591("• Contra la misma procede interponer recurso judicial ante la Cámara de Apelaciones en lo Contencioso Administrativo dentro de los 90 días hábiles de notificada (Art. 25 Ley 19.549)."), 0, 'L');
$this->MultiCell(0, 5, utf8_to_iso88591("• El cumplimiento de la sanción deberá acreditarse mediante documentación fehaciente ante esta Autoridad de Aplicación."), 0, 'L');
$this->Ln(8);
// ✅ FIRMA
$fecha_notificacion = !empty($sumario['fecha_cierre']) ? date('d/m/Y', strtotime($sumario['fecha_cierre'])) : date('d/m/Y');
$this->Firma('Autoridad de Aplicación', $fecha_notificacion);
}
}
$pdf = new PDF_Notificacion('P', 'mm', 'A4');
$pdf->AddPage();
// ====================================================================
// PASO 1: NOTIFICACIÓN DE INICIO DE SUMARIO ADMINISTRATIVO
// ====================================================================
if ($paso === 1) {
$pdf->EncabezadoMejorado('NOTIFICACIÓN DE INICIO DE SUMARIO ADMINISTRATIVO');
$pdf->SeccionDatosExpediente($sumario);
$pdf->SetFont('Arial', '', 11);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'REFERENCIA: Inicio de Sumario Administrativo basado en Acta de Inspección N° ' . ($sumario['acta_numero'] ?? '') . '.', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 11);
$acta_fecha_text = !empty($sumario['acta_fecha']) ? date('d/m/Y', strtotime($sumario['acta_fecha'])) : '-';
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se notifica a la parte interesada que, en base a las irregularidades detectadas en el Acta de Inspección de fecha ' . $acta_fecha_text . ', se ha dispuesto la apertura del presente Sumario Administrativo conforme al Art. 23 de la Ley I Nº 168 y Arts. 1, 2 y 3 de la Ley 19.549 de Procedimientos Administrativos.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Imputación:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se le imputan las infracciones detalladas en el Acta adjunta (Secciones 1 a 5 y Sala de Armas) según corresponda en el acta.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Próximo Paso:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se concede plazo de QUINCE (15) DÍAS HÁBILES para presentación de descargo, conforme al Art. 27 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 1);
}
$fecha_notificacion = !empty($sumario['fecha_notificacion_inicio']) ? date('d/m/Y', strtotime($sumario['fecha_notificacion_inicio'])) : date('d/m/Y');
$pdf->Firma('Autoridad de Aplicación', $fecha_notificacion);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE RECEPCIÓN', 0, 1, 'L');
$pdf->Ln(15);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Recibido por:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Nombre y DNI:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Fecha de Entrega:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Firma:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
}
// ====================================================================
// PASO 2: CÉDULA DE NOTIFICACIÓN Y TRASLADO
// ====================================================================
elseif ($paso === 2) {
$pdf->EncabezadoMejorado('CÉDULA DE NOTIFICACIÓN Y TRASLADO');
$pdf->SeccionDatosExpediente($sumario);
$pdf->SetFont('Arial', '', 11);
$pdf->Ln(5);
$pdf->MultiCell(0, 7, utf8_to_iso88591('En el marco del procedimiento sancionatorio iniciado, se corre traslado de las actuaciones por el término de QUINCE (15) DÍAS HÁBILES, conforme lo estipulado en el Acta de Inspección, la Ley I Nº 168 y el Art. 26 de la Ley 19.549 de Procedimientos Administrativos.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'DEBERÁ:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Presentar descargo, alegatos o documentación complementaria (ej. renovación de habilitaciones, legajos de personal, certificados RENAR o lo que corresponda según el Acta de Inspección) en la oficina de la Autoridad de Aplicación.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'ADVERTENCIA:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('El vencimiento del plazo sin presentación se tendrá por decaído el derecho a defenderse en esta etapa, conforme al Art. 27 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(5);
$fecha_vencimiento = obtenerFechaVencimiento($sumario['fecha_notificacion_inicio'], 15, $feriados_fijos, $feriados_2024);
$pdf->Cell(40, 7, 'Fecha de Vencimiento:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, $fecha_vencimiento, 0, 1);
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 2);
}
$fecha_notificacion = !empty($sumario['fecha_notificacion_inicio']) ? date('d/m/Y', strtotime($sumario['fecha_notificacion_inicio'])) : date('d/m/Y');
$pdf->Firma('Funcionario Notificador', $fecha_notificacion);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE RECEPCIÓN', 0, 1, 'L');
$pdf->Ln(15);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Recibido por:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Nombre y DNI:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Fecha de Entrega:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Firma:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
}
// ====================================================================
// ✅ PASO 3: NOTIFICACIÓN DE ESTADO DE ACTUACIONES - DOS VARIANTES
// ====================================================================
elseif ($paso === 3) {
$pdf->EncabezadoMejorado('NOTIFICACIÓN DE ESTADO DE ACTUACIONES');
$pdf->SeccionDatosExpediente($sumario);
$pdf->Ln(5);
// ✅ VARIANTE 1: EL IMPUTADO PRESENTÓ DESCARGO
if ($variante_paso3 === 'si_presento') {
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE PRESENTACIÓN DE DESCARGO', 0, 1);
$pdf->SetFont('Arial', '', 11);
$descargo_presentado_fecha = !empty($sumario['fecha_descargo_presentado']) ? date('d/m/Y', strtotime($sumario['fecha_descargo_presentado'])) : '-';
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se deja constancia que la parte interesada, ' . ($sumario['empresa_nombre'] ?? '') . ', ha presentado escrito de descargo con fecha ' . $descargo_presentado_fecha . ', en tiempo y forma, conforme al derecho de defensa consagrado en el Art. 18 de la Constitución Nacional y el Art. 26 de la Ley 19.549 de Procedimientos Administrativos.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('✅ SE RECIBIÓ la documentación completa, la cual ha sido ingresada al legajo para su análisis por la Autoridad de Aplicación.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'ESTADO DEL TRÁMITE:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('El descargo presentado será valorado en el marco del principio de bilateralidad procesal. Se procederá a la etapa probatoria para verificar la veracidad de lo alegado (ej. verificación de credenciales en sistema, visita de control si aplica), conforme al Art. 27 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'PRÓXIMO PASO:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Producción de prueba y posterior vista de actuaciones para alegatos finales.'), 0, 'L');
}
// ✅ VARIANTE 2: EL IMPUTADO NO PRESENTÓ DESCARGO
elseif ($variante_paso3 === 'no_presento') {
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE NO PRESENTACIÓN DE DESCARGO', 0, 1);
$pdf->SetFont('Arial', '', 11);
$fecha_vencimiento_descargo = obtenerFechaVencimiento($sumario['fecha_notificacion_inicio'], 15, $feriados_fijos, $feriados_2024);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se deja constancia que la parte interesada, ' . ($sumario['empresa_nombre'] ?? '') . ', NO ha presentado escrito de descargo dentro del plazo legal de QUINCE (15) DÍAS HÁBILES otorgado, el cual venció el día ' . $fecha_vencimiento_descargo . '.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('❌ NO SE RECIBIÓ documentación de descargo, habiéndose dejado constancia de la falta de presentación en el expediente.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'EFECTOS PROCESALES:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Conforme al Art. 27 de la Ley 19.549 de Procedimientos Administrativos, el vencimiento del plazo sin presentación de descargo se tendrá por decaído el derecho a defenderse en esta etapa, sin perjuicio de que la Administración deba resolver el sumario con los elementos de convicción obrantes en el expediente.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'ESTADO DEL TRÁMITE:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('El expediente continuará su trámite con los elementos probatorios disponibles. La Autoridad de Aplicación resolverá en mérito a lo actuado, garantizando el principio de legalidad y objetividad del Art. 1 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'PRÓXIMO PASO:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Producción de prueba de oficio y posterior resolución.'), 0, 'L');
}
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 3);
}
$fecha_notificacion = !empty($sumario['fecha_descargo']) ? date('d/m/Y', strtotime($sumario['fecha_descargo'])) : date('d/m/Y');
$pdf->Firma('Autoridad de Aplicación', $fecha_notificacion);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE RECEPCIÓN', 0, 1, 'L');
$pdf->Ln(15);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Recibido por:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Nombre y DNI:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Fecha de Entrega:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Firma:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
}
// ====================================================================
// PASO 4: NOTIFICACIÓN DE DILIGENCIAMIENTO PROBATORIO
// ====================================================================
elseif ($paso === 4) {
$pdf->EncabezadoMejorado('NOTIFICACIÓN DE DILIGENCIAMIENTO PROBATORIO');
$pdf->SeccionDatosExpediente($sumario);
$pdf->SetFont('Arial', '', 11);
$pdf->Ln(5);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se notifica que la Autoridad de Aplicación ha dispuesto las siguientes medidas de prueba para esclarecer los hechos del Acta de Inspección, conforme a los Arts. 27, 28, 29 y 30 de la Ley 19.549 de Procedimientos Administrativos:'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 11);
$medidas = !empty($sumario['medidas_probatorias']) ? $sumario['medidas_probatorias'] : utf8_to_iso88591('1. Oficio a RENAR para validación de tenencia de armamento.
2. Verificación de validez de Credenciales de Personal en base de datos oficial.
3. Otra medida pertinente.');
$pdf->MultiCell(0, 7, $medidas, 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'PLAZO PARA PROPONER PRUEBAS:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Las partes podrán proponer otras pruebas dentro de los CINCO (5) días hábiles de notificadas de la presente resolución, conforme al Art. 27 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 4);
}
$fecha_notificacion = !empty($sumario['fecha_probatoria']) ? date('d/m/Y', strtotime($sumario['fecha_probatoria'])) : date('d/m/Y');
$pdf->Firma('Instructor del Sumario', $fecha_notificacion);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE RECEPCIÓN', 0, 1, 'L');
$pdf->Ln(15);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Recibido por:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Nombre y DNI:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Fecha de Entrega:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Firma:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
}
// ====================================================================
// PASO 5: NOTIFICACIÓN DE VISTA DE ACTUADOS
// ====================================================================
elseif ($paso === 5) {
$pdf->EncabezadoMejorado('NOTIFICACIÓN DE VISTA DE ACTUADOS');
$pdf->SeccionDatosExpediente($sumario);
$pdf->SetFont('Arial', '', 11);
$pdf->Ln(5);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se pone a vista de la parte interesada el expediente administrativo completo por el término de CINCO (5) DÍAS HÁBILES, conforme al Art. 31 de la Ley 19.549 de Procedimientos Administrativos.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'OBJETO:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Para que tome conocimiento de toda la evidencia reunida y formule los alegatos finales que considere pertinentes antes de la Resolución Final, garantizando el derecho de defensa del Art. 18 de la Constitución Nacional.'), 0, 'L');
$pdf->Ln(5);
$fecha_vencimiento = obtenerFechaVencimiento($sumario['fecha_vista'], 5, $feriados_fijos, $feriados_2024);
$pdf->Cell(40, 7, 'Fecha de Vencimiento:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, $fecha_vencimiento, 0, 1);
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 5);
}
$fecha_notificacion = !empty($sumario['fecha_vista']) ? date('d/m/Y', strtotime($sumario['fecha_vista'])) : date('d/m/Y');
$pdf->Firma('Autoridad de Aplicación', $fecha_notificacion);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE RECEPCIÓN', 0, 1, 'L');
$pdf->Ln(15);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Recibido por:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Nombre y DNI:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Fecha de Entrega:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Firma:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
}
// ====================================================================
// PASO 6: MEMORANDUM INTERNO DE RESOLUCIÓN
// ====================================================================
elseif ($paso === 6) {
$pdf->EncabezadoMejorado('MEMORANDUM INTERNO DE RESOLUCIÓN');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'PARA:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Dirección de Seguridad Privada / Autoridad de Aplicación', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'DE:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Sumariante', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'ASUNTO:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, utf8_to_iso88591('Proyecto de Resolución Sumario N° ' . ($sumario['numero_expediente'] ?? '') . '/' . ($sumario['numero_anio'] ?? '')), 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 11);
$tipo_sancion_display = strtoupper($sumario['tipo_sancion'] ?? 'A DETERMINAR');
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se eleva el presente para firma de la Resolución Final, aplicando sanción de ' . $tipo_sancion_display . ' basada en las irregularidades confirmadas en el Acta de Inspección N° ' . ($sumario['acta_numero'] ?? '') . ' y no subsanadas en el descargo, conforme al Art. 33 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(5);
if ($sumario['monto_multa']) {
$pdf->Cell(40, 7, 'Monto de Multa:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, '$' . number_format($sumario['monto_multa'], 2), 0, 1);
$pdf->SetFont('Arial', '', 11);
}
if ($sumario['dias_suspension']) {
$pdf->Cell(40, 7, 'Días de Suspensión:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, $sumario['dias_suspension'] . ' días', 0, 1);
$pdf->SetFont('Arial', '', 11);
}
if ($sumario['numero_resolucion']) {
$pdf->Cell(40, 7, 'Número de Resolución:', 0, 0);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, $sumario['numero_resolucion'], 0, 1);
$pdf->SetFont('Arial', '', 11);
}
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 6);
}
$fecha_notificacion = !empty($sumario['fecha_resolucion']) ? date('d/m/Y', strtotime($sumario['fecha_resolucion'])) : date('d/m/Y');
$pdf->Firma('Sumariante', $fecha_notificacion);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'CONSTANCIA DE RECEPCIÓN', 0, 1, 'L');
$pdf->Ln(15);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(40, 7, 'Recibido por:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Nombre y DNI:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Fecha de Entrega:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
$pdf->Ln(5);
$pdf->Cell(40, 7, 'Firma:', 0, 0);
$pdf->Cell(0, 7, '_________________________', 0, 1);
}
// ====================================================================
// PASO 7: NOTIFICACIÓN DE RESOLUCIÓN ADMINISTRATIVA
// ====================================================================
elseif ($paso === 7) {
$pdf->EncabezadoMejorado('NOTIFICACIÓN DE RESOLUCIÓN ADMINISTRATIVA');
$pdf->SeccionDatosExpediente($sumario);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'N° ' . ($sumario['numero_resolucion'] ?? 'XXX'), 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Se notifica que la Autoridad de Aplicación ha resuelto sancionar a la agencia con:'), 0, 'L');
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 12);
$sancion_texto = '';
if ($sumario['tipo_sancion'] === 'multa' && $sumario['monto_multa']) {
$sancion_texto = utf8_to_iso88591('MULTA DE $' . number_format($sumario['monto_multa'], 2));
} elseif ($sumario['tipo_sancion'] === 'suspension' && $sumario['dias_suspension']) {
$sancion_texto = utf8_to_iso88591('SUSPENSIÓN POR ' . $sumario['dias_suspension'] . ' DÍAS');
} else {
$sancion_texto = strtoupper($sumario['tipo_sancion'] ?? 'SIN SANCION');
}
$pdf->Cell(0, 10, $sancion_texto, 0, 1, 'C');
$pdf->SetFont('Arial', '', 11);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'FUNDAMENTO:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Incumplimiento de Ley I Nº 168 y Resolución ANMAC N° 149/24, detectados en Acta de Inspección N° ' . ($sumario['acta_numero'] ?? '') . ', conforme al Art. 33 de la Ley 19.549 (fundamentación de actos administrativos).'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'PAGO/CUMPLIMIENTO:', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 7, utf8_to_iso88591('Deberá abonar la multa en la oficina de la Autoridad de Aplicación o subsanar las fallas antes del plazo establecido.'), 0, 'L');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'RECURSOS:', 0, 1);
$pdf->SetFont('Arial', '', 11);
// ✅ SILENCIO ADMINISTRATIVO: Cálculo de vencimiento para resolver por parte del Estado
$fecha_vencimiento_estado = !empty($sumario['fecha_vencimiento_estado']) ? date('d/m/Y', strtotime($sumario['fecha_vencimiento_estado'])) : '-';
$pdf->MultiCell(0, 7, utf8_to_iso88591('Cuenta con plazo de DIEZ (10) DÍAS HÁBILES para interponer recurso de reconsideración o jerárquico, conforme a los Arts. 84 y 85 de la Ley 19.549.'), 0, 'L');
$pdf->Ln(3);
$pdf->SetFont('Arial', 'I', 9);
$pdf->MultiCell(0, 6, utf8_to_iso88591('NOTA: Si la Administración no resuelve el recurso en el plazo legal de 30 días hábiles (Art. 86 Ley 19.549), podrá operar el silencio administrativo negativo (Art. 87), habilitando la vía judicial.'), 0, 'L');
$pdf->Ln(10);
// ✅ AGREGAR BASE LEGAL EXPLICADA AL PDF
if ($referencia_legal) {
$pdf->AgregarBaseLegalExplicada($referencia_legal['nacional'], $referencia_legal['provincial'], 7);
}
$fecha_notificacion = !empty($sumario['fecha_notificacion_resolucion']) ? date('d/m/Y', strtotime($sumario['fecha_notificacion_resolucion'])) : date('d/m/Y');
$pdf->Firma('Autoridad de Aplicación', $fecha_notificacion);
}
// ====================================================================
// ✅ PASO 8: RESOLUCIÓN FINAL UNIFICADA CLARA PARA NOTIFICACIÓN
// ====================================================================
elseif ($paso === 8) {
// ✅ USAR MÉTODO MEJORADO PARA RESOLUCIÓN FINAL UNIFICADA CLARA
$pdf->SeccionResolucionFinalUnificada($sumario, $referencia_legal);
// ✅ AGREGAR PÁGINA ADICIONAL CON DETALLE DE NOTIFICACIÓN MEJORADO
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, '📋 CONSTANCIA DE NOTIFICACIÓN Y CIERRE', 0, 1, 'L');
$pdf->Ln(12);
$pdf->SetFont('Arial', '', 10);
$pdf->MultiCell(0, 6, utf8_to_iso88591('Se deja constancia que la presente Resolución Final Unificada ha sido notificada fehacientemente a la parte interesada, quedando FIRME Y EJECUTORIA la decisión administrativa, agotando la vía administrativa.'), 0, 'L');
$pdf->Ln(8);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, 'DETALLES DE LA NOTIFICACIÓN:', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(50, 6, 'Fecha de Notificación:', 0, 0);
$pdf->Cell(0, 6, '_________________________', 0, 1);
$pdf->Ln(3);
$pdf->Cell(50, 6, 'Medio de Notificación:', 0, 0);
$pdf->Cell(0, 6, '_________________________', 0, 1);
$pdf->Ln(3);
$pdf->Cell(50, 6, 'Notificado por:', 0, 0);
$pdf->Cell(0, 6, '_________________________', 0, 1);
$pdf->Ln(3);
$pdf->Cell(50, 6, 'Cargo del Notificador:', 0, 0);
$pdf->Cell(0, 6, '_________________________', 0, 1);
$pdf->Ln(8);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, 'RECIBÍ CONFORME:', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Ln(20);
$pdf->Cell(90, 7, '_________________________', 0, 0, 'C');
$pdf->Cell(90, 7, '_________________________', 0, 1, 'C');
$pdf->Cell(90, 5, 'Firma del Notificado', 0, 0, 'C');
$pdf->Cell(90, 5, 'Aclaración y DNI', 0, 1, 'C');
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetFillColor(245, 245, 245);
$pdf->MultiCell(0, 5, utf8_to_iso88591('Nota: La falta de firma del notificado no invalida la notificación si se ha realizado por medio fehaciente (cédula, carta documento, email certificado) conforme al Art. 25 de la Ley 19.549.'), 0, 'L', true);
}
// ====================================================================
// GENERAR Y DESCARGAR PDF
// ====================================================================
$filename = 'notificacion_paso_' . $paso . '_sumario_' . ($sumario['numero_expediente'] ?? $sumario['id']) . '_' . date('Y-m-d') . '.pdf';
$upload_dir = '../uploads/sumarios/notificaciones_firmadas/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
$pdf_path = $upload_dir . $filename;
$pdf->Output('F', $pdf_path);
$pdf->Output('D', $filename);
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al generar PDF: ' . $e->getMessage();
header('Location: sumarios.php');
exit;
}
}
// ============================================================================
// ✅ AJAX - OBTENER ALERTAS DE VENCIMIENTO (MODIFICADO PARA SILENCIO ADMINISTRATIVO)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_alertas_vencimiento') {
header('Content-Type: application/json');
try {
$stmt = $conn->prepare("
SELECT id, numero_expediente, numero_anio, empresa_nombre,
estado, paso_actual, fecha_notificacion_inicio, fecha_descargo,
fecha_probatoria, fecha_vista, fecha_resolucion, fecha_vencimiento_estado, en_apelacion
FROM sumarios
WHERE activo = 1 AND estado != 'cerrado'
ORDER BY fecha_inicio ASC
");
$stmt->execute();
$sumarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$alertas = [];
foreach ($sumarios as $sumario) {
$fecha_base = $sumario['fecha_notificacion_inicio'] ?? $sumario['fecha_inicio'];
$suspensiones = obtenerSuspensionesSumario($conn, $sumario['id']);
// Calcular vencimientos según el paso
if ($sumario['paso_actual'] >= 2) {
$venc_descargo = calcularFechaHabil($fecha_base, 15, $feriados_fijos, $feriados_2024);
$alerta_descargo = getAlertaVencimiento($venc_descargo, $feriados_fijos, $feriados_2024, $suspensiones);
if ($alerta_descargo['class'] == 'danger' || $alerta_descargo['class'] == 'warning') {
$alertas[] = [
'sumario_id' => $sumario['id'],
'expediente' => $sumario['numero_expediente'] . '/' . $sumario['numero_anio'],
'empresa' => $sumario['empresa_nombre'],
'tipo' => 'Descargo',
'vencimiento' => $venc_descargo,
'alerta' => $alerta_descargo
];
}
}
// ✅ ALERTA DE SILENCIO ADMINISTRATIVO: Vencimiento para resolver por parte del Estado
if (!empty($sumario['fecha_vencimiento_estado']) && $sumario['paso_actual'] >= 7) {
$alerta_estado = getAlertaVencimiento($sumario['fecha_vencimiento_estado'], $feriados_fijos, $feriados_2024, $suspensiones);
if ($alerta_estado['class'] == 'danger' || $alerta_estado['class'] == 'warning') {
$alertas[] = [
'sumario_id' => $sumario['id'],
'expediente' => $sumario['numero_expediente'] . '/' . $sumario['numero_anio'],
'empresa' => $sumario['empresa_nombre'],
'tipo' => 'Silencio Administrativo (Estado)',
'vencimiento' => $sumario['fecha_vencimiento_estado'],
'alerta' => $alerta_estado
];
}
}
// ✅ ALERTA DE APELACIÓN PENDIENTE
if (!empty($sumario['en_apelacion']) && $sumario['en_apelacion'] == 1) {
$alertas[] = [
'sumario_id' => $sumario['id'],
'expediente' => $sumario['numero_expediente'] . '/' . $sumario['numero_anio'],
'empresa' => $sumario['empresa_nombre'],
'tipo' => 'Apelación Pendiente',
'vencimiento' => '-',
'alerta' => ['class' => 'warning', 'icon' => 'fa-balance-scale', 'texto' => 'EN APELACIÓN', 'dias' => null]
];
}
}
echo json_encode(['success' => true, 'alertas' => $alertas, 'count' => count($alertas)]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
}
// ============================================================================
// AJAX - OBTENER INSPECCIONES DISPONIBLES PARA SUMARIO
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
// AJAX - OBTENER DATOS DE SUMARIO PARA MODAL
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_sumario_detalle' && isset($_GET['id'])) {
header('Content-Type: application/json');
try {
$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM sumarios WHERE id = ?");
$stmt->execute([$id]);
$sumario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sumario) {
echo json_encode(['success' => false, 'error' => 'Sumario no encontrado']);
exit;
}
// ✅ OBTENER SUSPENSIONES PARA EL MODAL
$stmt = $conn->prepare("SELECT * FROM sumarios_suspensiones WHERE sumario_id = ? AND activo = 1 ORDER BY fecha_inicio DESC");
$stmt->execute([$id]);
$sumario['suspensiones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
// ✅ AGREGAR REFERENCIA LEGAL AL DATO DEL SUMARIO PARA EL MODAL
global $base_legal_por_paso;
if (isset($base_legal_por_paso[$sumario['paso_actual']])) {
$sumario['base_legal'] = $base_legal_por_paso[$sumario['paso_actual']];
}
echo json_encode(['success' => true, 'sumario' => $sumario]);
exit;
} catch (Exception $e) {
echo json_encode(['success' => false, 'error' => $e->getMessage()]);
exit;
}
}
// ============================================================================
// VERIFICAR/CREAR ESTRUCTURA DE TABLAS
// ============================================================================
try {
// Crear tabla sumarios
$table_exists = $conn->query("SHOW TABLES LIKE 'sumarios'")->rowCount() > 0;
if (!$table_exists) {
$conn->exec("
CREATE TABLE sumarios (
id INT AUTO_INCREMENT PRIMARY KEY,
numero_expediente VARCHAR(50) NULL,
numero_anio VARCHAR(10) NULL,
inspeccion_id INT NULL,
empresa_id INT NULL,
empresa_nombre VARCHAR(255) NULL,
empresa_cuit VARCHAR(20) NULL,
empresa_domicilio VARCHAR(255) NULL,
acta_numero VARCHAR(50) NULL,
acta_fecha DATE NULL,
funcionario_nombre VARCHAR(255) NULL,
funcionario_legajo VARCHAR(50) NULL,
estado VARCHAR(50) DEFAULT 'iniciado',
paso_actual INT DEFAULT 1,
fecha_inicio DATE NULL,
fecha_notificacion_inicio DATE NULL,
fecha_descargo DATE NULL,
fecha_probatoria DATE NULL,
fecha_vista DATE NULL,
fecha_resolucion DATE NULL,
fecha_notificacion_resolucion DATE NULL,
fecha_cierre DATE NULL,
tipo_sancion VARCHAR(50) NULL,
monto_multa DECIMAL(10,2) NULL,
dias_suspension INT NULL,
observaciones_sancion TEXT NULL,
descargo_presentado BOOLEAN DEFAULT FALSE,
fecha_descargo_presentado DATE NULL,
documento_descargo VARCHAR(255) NULL,
informe_tecnico TEXT NULL,
medidas_probatorias TEXT NULL,
alegatos_finales TEXT NULL,
numero_resolucion VARCHAR(50) NULL,
recurso_apelacion BOOLEAN DEFAULT FALSE,
fecha_recurso DATE NULL,
multa_pagada BOOLEAN DEFAULT FALSE,
fecha_pago_multa DATE NULL,
subsanacion_verificada BOOLEAN DEFAULT FALSE,
fecha_verificacion DATE NULL,
archivo_resolucion VARCHAR(255) NULL,
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
-- ✅ NUEVOS CAMPOS PARA VACÍOS LEGALES
en_apelacion BOOLEAN DEFAULT FALSE,
fecha_vencimiento_estado DATE NULL,
silencio_operado BOOLEAN DEFAULT FALSE,
fecha_silencio DATE NULL,
-- ✅ NUEVOS CAMPOS PARA RESOLUCIÓN DE APELACIÓN
resolucion_apelacion ENUM('confirmada', 'revocada', 'modificada') NULL,
fecha_resolucion_apelacion DATE NULL,
observaciones_resolucion TEXT NULL,
INDEX idx_estado (estado),
INDEX idx_inspeccion (inspeccion_id),
INDEX idx_empresa (empresa_id),
INDEX idx_expediente (numero_expediente),
INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
logAuditoria($conn, 'TABLA_CREADA', 'sumarios', null, ['mensaje' => 'Tabla sumarios creada'], $user['id']);
} else {
$columns = $conn->query("SHOW COLUMNS FROM sumarios")->fetchAll(PDO::FETCH_COLUMN);
$required_columns = [
'numero_expediente' => "VARCHAR(50) NULL",
'numero_anio' => "VARCHAR(10) NULL",
'inspeccion_id' => "INT NULL",
'empresa_id' => "INT NULL",
'empresa_nombre' => "VARCHAR(255) NULL",
'empresa_cuit' => "VARCHAR(20) NULL",
'empresa_domicilio' => "VARCHAR(255) NULL",
'acta_numero' => "VARCHAR(50) NULL",
'acta_fecha' => "DATE NULL",
'funcionario_nombre' => "VARCHAR(255) NULL",
'funcionario_legajo' => "VARCHAR(50) NULL",
'estado' => "VARCHAR(50) DEFAULT 'iniciado'",
'paso_actual' => "INT DEFAULT 1",
'fecha_inicio' => "DATE NULL",
'fecha_notificacion_inicio' => "DATE NULL",
'fecha_descargo' => "DATE NULL",
'fecha_probatoria' => "DATE NULL",
'fecha_vista' => "DATE NULL",
'fecha_resolucion' => "DATE NULL",
'fecha_notificacion_resolucion' => "DATE NULL",
'fecha_cierre' => "DATE NULL",
'tipo_sancion' => "VARCHAR(50) NULL",
'monto_multa' => "DECIMAL(10,2) NULL",
'dias_suspension' => "INT NULL",
'observaciones_sancion' => "TEXT NULL",
'descargo_presentado' => "BOOLEAN DEFAULT FALSE",
'fecha_descargo_presentado' => "DATE NULL",
'documento_descargo' => "VARCHAR(255) NULL",
'informe_tecnico' => "TEXT NULL",
'medidas_probatorias' => "TEXT NULL",
'alegatos_finales' => "TEXT NULL",
'numero_resolucion' => "VARCHAR(50) NULL",
'recurso_apelacion' => "BOOLEAN DEFAULT FALSE",
'fecha_recurso' => "DATE NULL",
'multa_pagada' => "BOOLEAN DEFAULT FALSE",
'fecha_pago_multa' => "DATE NULL",
'subsanacion_verificada' => "BOOLEAN DEFAULT FALSE",
'fecha_verificacion' => "DATE NULL",
'archivo_resolucion' => "VARCHAR(255) NULL",
'activo' => "BOOLEAN DEFAULT TRUE",
// ✅ NUEVOS CAMPOS PARA VACÍOS LEGALES
'en_apelacion' => "BOOLEAN DEFAULT FALSE",
'fecha_vencimiento_estado' => "DATE NULL",
'silencio_operado' => "BOOLEAN DEFAULT FALSE",
'fecha_silencio' => "DATE NULL",
// ✅ NUEVOS CAMPOS PARA RESOLUCIÓN DE APELACIÓN
'resolucion_apelacion' => "ENUM('confirmada', 'revocada', 'modificada') NULL",
'fecha_resolucion_apelacion' => "DATE NULL",
'observaciones_resolucion' => "TEXT NULL"
];
foreach ($required_columns as $col => $definition) {
if (!in_array($col, $columns)) {
try {
$conn->exec("ALTER TABLE sumarios ADD COLUMN $col $definition");
logAuditoria($conn, 'COLUMNA_AGREGADA', 'sumarios', null, ['columna' => $col], $user['id']);
} catch (PDOException $e) {
error_log("Error agregando columna '$col': " . $e->getMessage());
}
}
}
}
// Crear tabla notificaciones_archivos
$notif_table_exists = $conn->query("SHOW TABLES LIKE 'notificaciones_archivos'")->rowCount() > 0;
if (!$notif_table_exists) {
$conn->exec("
CREATE TABLE notificaciones_archivos (
id INT AUTO_INCREMENT PRIMARY KEY,
sumario_id INT NOT NULL,
paso_actual INT NOT NULL,
nombre_archivo VARCHAR(255) NOT NULL,
ruta_archivo VARCHAR(500) NOT NULL,
fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-- ✅ NUEVOS CAMPOS PARA MEDIO DE NOTIFICACIÓN
tipo_notificacion ENUM('personal', 'cedula', 'carta_documento', 'email', 'otro') DEFAULT 'personal',
numero_cedula VARCHAR(50) NULL,
numero_carta_documento VARCHAR(50) NULL,
fecha_notificacion_legal DATE NULL,
FOREIGN KEY (sumario_id) REFERENCES sumarios(id) ON DELETE CASCADE,
INDEX idx_sumario (sumario_id),
INDEX idx_paso (paso_actual)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
logAuditoria($conn, 'TABLA_NOTIFICACIONES_CREADA', 'notificaciones_archivos', null, ['mensaje' => 'Tabla de notificaciones creada'], $user['id']);
} else {
// ✅ AGREGAR CAMPOS DE MEDIO DE NOTIFICACIÓN SI NO EXISTEN
$notif_columns = $conn->query("SHOW COLUMNS FROM notificaciones_archivos")->fetchAll(PDO::FETCH_COLUMN);
$notif_required = [
'tipo_notificacion' => "ENUM('personal', 'cedula', 'carta_documento', 'email', 'otro') DEFAULT 'personal'",
'numero_cedula' => "VARCHAR(50) NULL",
'numero_carta_documento' => "VARCHAR(50) NULL",
'fecha_notificacion_legal' => "DATE NULL"
];
foreach ($notif_required as $col => $definition) {
if (!in_array($col, $notif_columns)) {
try {
$conn->exec("ALTER TABLE notificaciones_archivos ADD COLUMN $col $definition");
logAuditoria($conn, 'COLUMNA_NOTIF_AGREGADA', 'notificaciones_archivos', null, ['columna' => $col], $user['id']);
} catch (PDOException $e) {
error_log("Error agregando columna '$col' en notificaciones_archivos: " . $e->getMessage());
}
}
}
}
// ✅ CREAR TABLA DE HISTORIAL DE CAMBIOS
$historial_exists = $conn->query("SHOW TABLES LIKE 'sumarios_historial'")->rowCount() > 0;
if (!$historial_exists) {
$conn->exec("
CREATE TABLE sumarios_historial (
id INT AUTO_INCREMENT PRIMARY KEY,
sumario_id INT NOT NULL,
usuario_id INT NOT NULL,
paso_anterior INT,
paso_nuevo INT,
estado_anterior VARCHAR(50),
estado_nuevo VARCHAR(50),
campos_modificados TEXT,
fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (sumario_id) REFERENCES sumarios(id) ON DELETE CASCADE,
INDEX idx_sumario (sumario_id),
INDEX idx_fecha (fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
logAuditoria($conn, 'TABLA_HISTORIAL_CREADA', 'sumarios_historial', null, ['mensaje' => 'Tabla historial creada'], $user['id']);
}
// ✅ CREAR TABLA DE SUSPENSIONES DE PLAZOS
$suspension_table_exists = $conn->query("SHOW TABLES LIKE 'sumarios_suspensiones'")->rowCount() > 0;
if (!$suspension_table_exists) {
$conn->exec("
CREATE TABLE sumarios_suspensiones (
id INT AUTO_INCREMENT PRIMARY KEY,
sumario_id INT NOT NULL,
fecha_inicio DATE NOT NULL,
fecha_fin DATE NULL,
motivo TEXT NOT NULL,
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (sumario_id) REFERENCES sumarios(id) ON DELETE CASCADE,
INDEX idx_sumario (sumario_id),
INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
logAuditoria($conn, 'TABLA_SUSPENSIONES_CREADA', 'sumarios_suspensiones', null, ['mensaje' => 'Tabla suspensiones creada'], $user['id']);
}
} catch (PDOException $e) {
$error = "Error al verificar estructura de base de datos: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// MANEJAR CREACIÓN DE SUMARIO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_sumario'])) {
try {
$inspeccion_id = !empty($_POST['inspeccion_id']) ? (int)$_POST['inspeccion_id'] : null;
if ($inspeccion_id) {
$stmt = $conn->prepare("SELECT id FROM sumarios WHERE inspeccion_id = ?");
$stmt->execute([$inspeccion_id]);
if ($stmt->fetch()) {
throw new Exception('Ya existe un sumario administrativo para esta inspección');
}
}
$numero_expediente = trim($_POST['numero_expediente'] ?? '');
$numero_anio = trim($_POST['numero_anio'] ?? date('Y'));
$empresa_nombre = trim($_POST['empresa_nombre'] ?? '');
$empresa_cuit = trim($_POST['empresa_cuit'] ?? '');
$empresa_domicilio = trim($_POST['empresa_domicilio'] ?? '');
$acta_numero = trim($_POST['acta_numero'] ?? '');
$acta_fecha = !empty($_POST['acta_fecha']) ? $_POST['acta_fecha'] : date('Y-m-d');
$funcionario_nombre = trim($_POST['funcionario_nombre'] ?? '');
$funcionario_legajo = trim($_POST['funcionario_legajo'] ?? '');
$estado = 'iniciado';
$paso_actual = 1;
$fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d');
$fecha_notificacion_inicio = calcularFechaHabil($fecha_inicio, 1, $feriados_fijos, $feriados_2024);
$tipo_sancion = trim($_POST['tipo_sancion'] ?? '');
$observaciones_sancion = trim($_POST['observaciones_sancion'] ?? '');
// ✅ CÁLCULO DE VENCIMIENTO PARA RESOLVER POR PARTE DEL ESTADO (SILENCIO ADMINISTRATIVO)
// Según Ley de Procedimientos Administrativos: 90 días hábiles para resolver desde el inicio
$fecha_vencimiento_estado = calcularFechaHabil($fecha_inicio, 90, $feriados_fijos, $feriados_2024);
$stmt = $conn->prepare("
INSERT INTO sumarios (
numero_expediente, numero_anio, inspeccion_id,
empresa_nombre, empresa_cuit, empresa_domicilio,
acta_numero, acta_fecha, funcionario_nombre, funcionario_legajo,
estado, paso_actual, fecha_inicio, fecha_notificacion_inicio,
tipo_sancion, observaciones_sancion, activo,
en_apelacion, fecha_vencimiento_estado, silencio_operado
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, 0)
");
$stmt->execute([
$numero_expediente, $numero_anio, $inspeccion_id,
$empresa_nombre, $empresa_cuit, $empresa_domicilio,
$acta_numero, $acta_fecha, $funcionario_nombre, $funcionario_legajo,
$estado, $paso_actual, $fecha_inicio, $fecha_notificacion_inicio,
$tipo_sancion, $observaciones_sancion,
$fecha_vencimiento_estado
]);
$sumario_id = $conn->lastInsertId();
if ($inspeccion_id) {
$stmt = $conn->prepare("UPDATE inspecciones SET activo = 0 WHERE id = ?");
$stmt->execute([$inspeccion_id]);
}
logAuditoria($conn, 'SUMARIO_CREADO', 'sumarios', $sumario_id, [
'numero_expediente' => $numero_expediente,
'empresa' => $empresa_nombre,
'inspeccion_id' => $inspeccion_id
], $user['id']);
// ✅ REGISTRAR EN HISTORIAL
$stmt = $conn->prepare("
INSERT INTO sumarios_historial (sumario_id, usuario_id, paso_nuevo, estado_nuevo)
VALUES (?, ?, ?, ?)
");
$stmt->execute([$sumario_id, $user['id'], 1, 'iniciado']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-gavel fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Sumario Administrativo creado exitosamente!</strong></h5>
<p class='mb-0'><strong>Expediente N°:</strong> {$numero_expediente}/{$numero_anio}</p>
<p class='mb-0'><strong>Empresa:</strong> {$empresa_nombre}</p>
<p class='mb-0'><strong>Acta Base:</strong> {$acta_numero}</p>
<p class='mb-0'><strong>Próximo Vencimiento:</strong> " . date('d/m/Y', strtotime($fecha_notificacion_inicio)) . "</p>
<p class='mb-0'><strong>Vencimiento Estado (Silencio):</strong> " . date('d/m/Y', strtotime($fecha_vencimiento_estado)) . "</p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: sumarios.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_CREACION_SUMARIO', 'sumarios', null, ['error' => $e->getMessage()], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: sumarios.php');
exit;
}
}
// ============================================================================
// MANEJAR ACTUALIZACIÓN DE ESTADO DEL SUMARIO (AVANZAR PASO)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_paso_sumario'])) {
try {
$id = (int)($_POST['id'] ?? 0);
$nuevo_paso = (int)($_POST['paso_actual'] ?? 1);
$nuevo_estado = trim($_POST['estado'] ?? 'iniciado');
if ($id <= 0) throw new Exception('ID de sumario inválido');
$stmt = $conn->prepare("SELECT * FROM sumarios WHERE id = ?");
$stmt->execute([$id]);
$datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$datos_antiguos) throw new Exception('Sumario no encontrado');
// ✅ VALIDAR PROGRESO DE PASOS CON VALIDACIÓN ESTRICTA DE CAMPOS - INCLUYENDO POST DATA
global $campos_requeridos_por_paso;
$archivos_subidos = isset($_FILES['pdf_notificacion']) ? ['pdf_notificacion' => $_FILES['pdf_notificacion']] : [];
// Pasar $_POST para validar campos del nuevo paso que aún no están en BD
$validacion = validarProgresoPaso($datos_antiguos['paso_actual'], $nuevo_paso, $datos_antiguos, $campos_requeridos_por_paso, $archivos_subidos, $_POST);
if (!$validacion['valido']) {
throw new Exception($validacion['mensaje']);
}
// Procesar subida de archivo PDF si corresponde
if (isset($_FILES['pdf_notificacion']) && $_FILES['pdf_notificacion']['error'] === UPLOAD_ERR_OK) {
$upload_dir = '../uploads/sumarios/notificaciones_firmadas/';
if (!file_exists($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
$extension = strtolower(pathinfo($_FILES['pdf_notificacion']['name'], PATHINFO_EXTENSION));
if ($extension === 'pdf') {
$max_size = 5 * 1024 * 1024;
if ($_FILES['pdf_notificacion']['size'] > $max_size) {
throw new Exception('El archivo excede el tamaño máximo permitido (5MB)');
}
$nuevo_nombre = 'notificacion_' . $id . '_paso' . $nuevo_paso . '_' . time() . '.pdf';
$ruta_completa = $upload_dir . $nuevo_nombre;
if (move_uploaded_file($_FILES['pdf_notificacion']['tmp_name'], $ruta_completa)) {
// ✅ CAMPOS DE MEDIO DE NOTIFICACIÓN
$tipo_notificacion = $_POST['tipo_notificacion'] ?? 'personal';
$numero_cedula = $_POST['numero_cedula'] ?? null;
$numero_carta_documento = $_POST['numero_carta_documento'] ?? null;
$fecha_notificacion_legal = $_POST['fecha_notificacion_legal'] ?? date('Y-m-d');
// Guardar referencia en BD con nuevos campos
$stmt = $conn->prepare("
INSERT INTO notificaciones_archivos (sumario_id, paso_actual, nombre_archivo, ruta_archivo, tipo_notificacion, numero_cedula, numero_carta_documento, fecha_notificacion_legal)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$id, $nuevo_paso, $nuevo_nombre, $nuevo_nombre, $tipo_notificacion, $numero_cedula, $numero_carta_documento, $fecha_notificacion_legal]);
logAuditoria($conn, 'PDF_NOTIFICACION_ASIGNADO', 'notificaciones_archivos', null, [
'sumario_id' => $id,
'paso' => $nuevo_paso,
'archivo' => $nuevo_nombre,
'tipo_notificacion' => $tipo_notificacion
], $user['id']);
}
}
}
$update_fields = ['estado = ?', 'paso_actual = ?'];
$params = [$nuevo_estado, $nuevo_paso];
$fecha_campo = '';
switch ($nuevo_paso) {
case 2: $fecha_campo = 'fecha_notificacion_inicio'; break;
case 3: $fecha_campo = 'fecha_descargo'; break;
case 4: $fecha_campo = 'fecha_probatoria'; break;
case 5: $fecha_campo = 'fecha_vista'; break;
case 6: $fecha_campo = 'fecha_resolucion'; break;
case 7: $fecha_campo = 'fecha_notificacion_resolucion'; break;
case 8: $fecha_campo = 'fecha_cierre'; break;
}
if ($fecha_campo) {
$update_fields[] = "$fecha_campo = ?";
$params[] = date('Y-m-d');
}
if ($nuevo_paso >= 6) {
$tipo_sancion = trim($_POST['tipo_sancion'] ?? '');
$monto_multa = !empty($_POST['monto_multa']) ? (float)$_POST['monto_multa'] : null;
$dias_suspension = !empty($_POST['dias_suspension']) ? (int)$_POST['dias_suspension'] : null;
$numero_resolucion = trim($_POST['numero_resolucion'] ?? '');
if ($tipo_sancion) {
$update_fields[] = 'tipo_sancion = ?';
$params[] = $tipo_sancion;
}
if ($monto_multa !== null) {
$update_fields[] = 'monto_multa = ?';
$params[] = $monto_multa;
}
if ($dias_suspension !== null) {
$update_fields[] = 'dias_suspension = ?';
$params[] = $dias_suspension;
}
if ($numero_resolucion) {
$update_fields[] = 'numero_resolucion = ?';
$params[] = $numero_resolucion;
}
}
if ($nuevo_paso >= 3) {
$descargo_presentado = isset($_POST['descargo_presentado']) && $_POST['descargo_presentado'] === 'si' ? 1 : 0;
$fecha_descargo_presentado = !empty($_POST['fecha_descargo_presentado']) ? $_POST['fecha_descargo_presentado'] : null;
$update_fields[] = 'descargo_presentado = ?';
$params[] = $descargo_presentado;
if ($fecha_descargo_presentado) {
$update_fields[] = 'fecha_descargo_presentado = ?';
$params[] = $fecha_descargo_presentado;
}
}
if ($nuevo_paso >= 4) {
$informe_tecnico = trim($_POST['informe_tecnico'] ?? '');
$medidas_probatorias = trim($_POST['medidas_probatorias'] ?? '');
if ($informe_tecnico) {
$update_fields[] = 'informe_tecnico = ?';
$params[] = $informe_tecnico;
}
if ($medidas_probatorias) {
$update_fields[] = 'medidas_probatorias = ?';
$params[] = $medidas_probatorias;
}
}
if ($nuevo_paso >= 5) {
$alegatos_finales = trim($_POST['alegatos_finales'] ?? '');
if ($alegatos_finales) {
$update_fields[] = 'alegatos_finales = ?';
$params[] = $alegatos_finales;
}
}
if ($nuevo_paso >= 8) {
$multa_pagada = isset($_POST['multa_pagada']) && $_POST['multa_pagada'] === 'si' ? 1 : 0;
$subsanacion_verificada = isset($_POST['subsanacion_verificada']) && $_POST['subsanacion_verificada'] === 'si' ? 1 : 0;
$update_fields[] = 'multa_pagada = ?';
$params[] = $multa_pagada;
$update_fields[] = 'subsanacion_verificada = ?';
$params[] = $subsanacion_verificada;
if ($multa_pagada) {
$update_fields[] = 'fecha_pago_multa = ?';
$params[] = date('Y-m-d');
}
if ($subsanacion_verificada) {
$update_fields[] = 'fecha_verificacion = ?';
$params[] = date('Y-m-d');
}
}
// ✅ MANEJO DE APELACIÓN: Si se marca recurso_apelacion, activar en_apelacion y cambiar estado
if (isset($_POST['recurso_apelacion']) && $_POST['recurso_apelacion'] === 'si') {
$update_fields[] = 'en_apelacion = ?';
$params[] = 1;
$update_fields[] = 'estado = ?';
$params[] = 'en_apelacion';
$fecha_recurso = !empty($_POST['fecha_recurso']) ? $_POST['fecha_recurso'] : date('Y-m-d');
$update_fields[] = 'fecha_recurso = ?';
$params[] = $fecha_recurso;
} elseif (isset($_POST['recurso_apelacion']) && $_POST['recurso_apelacion'] === 'no') {
// Si se indica que NO hay apelación, permitir avanzar a cierre
$update_fields[] = 'en_apelacion = ?';
$params[] = 0;
}
$params[] = $id;
$sql = "UPDATE sumarios SET " . implode(', ', $update_fields) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
// ✅ REGISTRAR EN HISTORIAL
$campos_modificados = json_encode(array_merge($_POST, ['paso' => $nuevo_paso, 'estado' => $nuevo_estado]));
$stmt = $conn->prepare("
INSERT INTO sumarios_historial
(sumario_id, usuario_id, paso_anterior, paso_nuevo, estado_anterior, estado_nuevo, campos_modificados)
VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
$id, $user['id'],
$datos_antiguos['paso_actual'], $nuevo_paso,
$datos_antiguos['estado'], $nuevo_estado,
$campos_modificados
]);
logAuditoria($conn, 'SUMARIO_ACTUALIZADO', 'sumarios', $id, [
'paso' => $nuevo_paso,
'estado' => $nuevo_estado
], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>¡Sumario actualizado a Paso {$nuevo_paso}!
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: sumarios.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: sumarios.php');
exit;
}
}
// ============================================================================
// MANEJAR ELIMINACIÓN/DESACTIVACIÓN DE SUMARIO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_sumario'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) throw new Exception('ID de sumario inválido');
$stmt = $conn->prepare("SELECT * FROM sumarios WHERE id = ?");
$stmt->execute([$id]);
$sumario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sumario) throw new Exception('Sumario no encontrado');
$stmt = $conn->prepare("UPDATE sumarios SET activo = 0 WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'SUMARIO_DESACTIVADO', 'sumarios', $id, ['id' => $id], $user['id']);
$_SESSION['success'] = "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
<i class='fas fa-exclamation-triangle me-2'></i>¡Sumario desactivado!
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: sumarios.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: sumarios.php');
exit;
}
}
// ============================================================================
// EXPORTAR SUMARIO A PDF (GUÍA COMPLETA DE 8 PASOS)
// ============================================================================
if (isset($_POST['exportar_sumario_pdf']) && isset($_POST['sumario_id'])) {
if (ob_get_level()) ob_end_clean();
try {
$sumario_id = (int)$_POST['sumario_id'];
$stmt = $conn->prepare("SELECT * FROM sumarios WHERE id = ?");
$stmt->execute([$sumario_id]);
$sumario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sumario) throw new Exception('Sumario no encontrado');
require_once('../vendor/fpdf/fpdf.php');
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'SUMARIO ADMINISTRATIVO', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 8, 'EMPRESAS DE SEGURIDAD PRIVADA', 0, 1, 'C');
$pdf->Cell(0, 8, 'LEY I Nº 168 (Chubut) - LEY XV Nº 27', 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'DATOS DEL EXPEDIENTE', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(50, 7, 'Expediente N°:', 0, 0);
$pdf->Cell(0, 7, ($sumario['numero_expediente'] ?? '') . '/' . ($sumario['numero_anio'] ?? ''), 0, 1);
$pdf->Cell(50, 7, 'Estado:', 0, 0);
$pdf->Cell(0, 7, getNombreEstado($sumario['estado']), 0, 1);
$pdf->Cell(50, 7, 'Paso Actual:', 0, 0);
$pdf->Cell(0, 7, $sumario['paso_actual'] . ' de 8', 0, 1);
$pdf->Cell(50, 7, 'Fecha Inicio:', 0, 0);
$pdf->Cell(0, 7, !empty($sumario['fecha_inicio']) ? date('d/m/Y', strtotime($sumario['fecha_inicio'])) : '-', 0, 1);
// ✅ INFORMACIÓN DE APELACIÓN Y SILENCIO ADMINISTRATIVO
if (!empty($sumario['en_apelacion']) && $sumario['en_apelacion'] == 1) {
$pdf->Cell(50, 7, 'En Apelación:', 0, 0);
$pdf->Cell(0, 7, 'SI', 0, 1);
}
if (!empty($sumario['fecha_vencimiento_estado'])) {
$pdf->Cell(50, 7, 'Venc. Estado (Silencio):', 0, 0);
$pdf->Cell(0, 7, date('d/m/Y', strtotime($sumario['fecha_vencimiento_estado'])), 0, 1);
}
// ✅ INFORMACIÓN DE RESOLUCIÓN DE APELACIÓN
if (!empty($sumario['resolucion_apelacion'])) {
$pdf->Cell(50, 7, 'Resolución Apelación:', 0, 0);
$pdf->Cell(0, 7, strtoupper($sumario['resolucion_apelacion']), 0, 1);
}
if (!empty($sumario['fecha_resolucion_apelacion'])) {
$pdf->Cell(50, 7, 'Fecha Resolución:', 0, 0);
$pdf->Cell(0, 7, date('d/m/Y', strtotime($sumario['fecha_resolucion_apelacion'])), 0, 1);
}
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'EMPRESA IMPUTADA', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(50, 7, 'Empresa:', 0, 0);
$pdf->Cell(0, 7, $sumario['empresa_nombre'] ?? '-', 0, 1);
$pdf->Cell(50, 7, 'CUIT:', 0, 0);
$pdf->Cell(0, 7, $sumario['empresa_cuit'] ?? '-', 0, 1);
$pdf->Cell(50, 7, 'Domicilio:', 0, 0);
$pdf->Cell(0, 7, $sumario['empresa_domicilio'] ?? '-', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'ACTA DE INSPECCIÓN BASE', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(50, 7, 'Acta N°:', 0, 0);
$pdf->Cell(0, 7, $sumario['acta_numero'] ?? '-', 0, 1);
$pdf->Cell(50, 7, 'Fecha Acta:', 0, 0);
$pdf->Cell(0, 7, !empty($sumario['acta_fecha']) ? date('d/m/Y', strtotime($sumario['acta_fecha'])) : '-', 0, 1);
$pdf->Cell(50, 7, 'Funcionario:', 0, 0);
$pdf->Cell(0, 7, $sumario['funcionario_nombre'] ?? '-', 0, 1);
$pdf->Ln(5);
// ✅ AGREGAR SECCIÓN DE BASE LEGAL AL PDF EXPORTADO
global $base_legal_por_paso;
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'BASE LEGAL APLICABLE (Ley 19.549)', 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
for ($p = 1; $p <= 8; $p++) {
if (isset($base_legal_por_paso[$p])) {
$pdf->Cell(0, 6, "Paso $p: " . $base_legal_por_paso[$p]['nacional'], 0, 1);
$pdf->Cell(0, 6, "  Provincial: " . $base_legal_por_paso[$p]['provincial'], 0, 1);
$pdf->Ln(2);
}
}
$filename = 'sumario_' . ($sumario['numero_expediente'] ?? 'sin_numero') . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
exit;
} catch (Exception $e) {
$_SESSION['error'] = 'Error al exportar PDF: ' . $e->getMessage();
header('Location: sumarios.php');
exit;
}
}
// ============================================================================
// OBTENER DATOS CON PAGINACIÓN
// ============================================================================
$search_empresa = $_GET['search_empresa'] ?? '';
$search_expediente = $_GET['search_expediente'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_fecha_desde = $_GET['search_fecha_desde'] ?? '';
$search_fecha_hasta = $_GET['search_fecha_hasta'] ?? '';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$columnas_permitidas = ['id', 'numero_expediente', 'fecha_inicio', 'empresa_nombre', 'estado'];
$orden_columna = $_GET['orden'] ?? 'fecha_inicio';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'DESC');
if (!in_array($orden_columna, $columnas_permitidas)) $orden_columna = 'fecha_inicio';
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') $orden_direccion = 'DESC';
$sumarios = [];
$total_registros = 0;
$total_paginas = 0;
$total_activos = 0;
$total_cerrados = 0;
$total_con_alerta = 0;
try {
$where_clauses = [];
$params = [];
if (!empty($search_empresa)) {
$where_clauses[] = "empresa_nombre LIKE :search_empresa";
$params[':search_empresa'] = '%' . $search_empresa . '%';
}
if (!empty($search_expediente)) {
$where_clauses[] = "numero_expediente LIKE :search_expediente";
$params[':search_expediente'] = '%' . $search_expediente . '%';
}
if (!empty($search_fecha_desde)) {
$where_clauses[] = "fecha_inicio >= :fecha_desde";
$params[':fecha_desde'] = $search_fecha_desde;
}
if (!empty($search_fecha_hasta)) {
$where_clauses[] = "fecha_inicio <= :fecha_hasta";
$params[':fecha_hasta'] = $search_fecha_hasta;
}
if ($search_estado !== 'todos') {
$where_clauses[] = "estado = :estado";
$params[':estado'] = $search_estado;
}
$where_clauses[] = "activo = 1";
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$count_sql = "SELECT COUNT(*) as total FROM sumarios $where_sql";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$orden_sql = "ORDER BY $orden_columna $orden_direccion";
$limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
$sql = "SELECT * FROM sumarios $where_sql $orden_sql $limit_sql";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$sumarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $conn->query("SELECT COUNT(*) as total FROM sumarios WHERE activo = 1");
$total_activos = $stmt->fetch()['total'];
$stmt = $conn->query("SELECT COUNT(*) as total FROM sumarios WHERE estado = 'cerrado' AND activo = 1");
$total_cerrados = $stmt->fetch()['total'];
} catch (PDOException $e) {
$sumarios = [];
$error = "<strong>⚠️ Atención:</strong> No se pudieron cargar los sumarios. Error: " . htmlspecialchars($e->getMessage());
error_log("Error en sumarios.php: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Sumarios Administrativos - Sistema de Seguridad</title>
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
.paso-timeline { display: flex; justify-content: space-between; margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 4px; }
.paso-item { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
.paso-number { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-bottom: 10px; }
.paso-item.completed .paso-number { background: var(--success-color); color: white; }
.paso-item.active .paso-number { background: var(--primary-color); color: white; animation: pulse 2s infinite; }
.paso-item.pending .paso-number { background: #ccc; color: white; }
.paso-label { font-size: 12px; text-align: center; }
/* ✅ ESTILOS PARA ALERTAS DE VENCIMIENTO */
.alerta-vencimiento {
animation: pulse-alert 2s infinite;
}
@keyframes pulse-alert {
0% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.4); }
70% { box-shadow: 0 0 0 10px rgba(231, 76, 60, 0); }
100% { box-shadow: 0 0 0 0 rgba(231, 76, 60, 0); }
}
.fila-vencida { background-color: #ffe6e6 !important; }
.fila-proxima { background-color: #fff3cd !important; }
/* ✅ PANEL DE ALERTAS */
.panel-alertas {
background: linear-gradient(135deg, #fff5f5 0%, #ffe6e6 100%);
border-left: 4px solid var(--danger-color);
border-radius: 10px;
padding: 15px 20px;
margin-bottom: 20px;
}
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(67, 97, 238, 0); } 100% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); } }
@media (max-width: 991px) { .main-content { margin-left: 0 !important; } }
.notification-item { transition: transform 0.2s ease; }
.notification-item:hover { transform: translateX(5px); }
.notif-badge { background: #dc3545; color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; }
.legal-ref-badge { background: linear-gradient(135deg, #6c757d, #495057); color: white; font-size: 0.75rem; padding: 2px 6px; border-radius: 3px; }
</style>
</head>
<body>
<div class="dashboard">
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<!-- ✅ PANEL DE ALERTAS DE VENCIMIENTO -->
<div id="panelAlertasVencimiento" class="panel-alertas" style="display: none;">
<div class="d-flex align-items-center justify-content-between">
<div>
<h5 class="mb-1"><i class="fas fa-exclamation-triangle me-2"></i>Alertas de Vencimiento</h5>
<p class="mb-0 text-muted" id="textoAlertas">Cargando alertas...</p>
</div>
<button class="btn btn-sm btn-outline-danger" onclick="verTodasLasAlertas()">
<i class="fas fa-list me-1"></i>Ver Todas
</button>
</div>
</div>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="d-flex align-items-center">
<div class="icon-box bg-primary text-white rounded-circle p-3 me-3">
<i class="fas fa-gavel fa-2x"></i>
</div>
<div>
<h3 class="mb-0"><?php echo $total_activos; ?></h3>
<p class="text-muted mb-0">Sumarios Activos</p>
</div>
</div>
</div>
<div class="stat-card">
<div class="d-flex align-items-center">
<div class="icon-box bg-success text-white rounded-circle p-3 me-3">
<i class="fas fa-check-circle fa-2x"></i>
</div>
<div>
<h3 class="mb-0"><?php echo $total_cerrados; ?></h3>
<p class="text-muted mb-0">Sumarios Cerrados</p>
</div>
</div>
</div>
<div class="stat-card">
<div class="d-flex align-items-center">
<div class="icon-box bg-warning text-white rounded-circle p-3 me-3">
<i class="fas fa-clock fa-2x"></i>
</div>
<div>
<h3 class="mb-0"><?php echo $total_registros; ?></h3>
<p class="text-muted mb-0">Total en Trámite</p>
</div>
</div>
</div>
<div class="stat-card alerta-vencimiento" style="border: 2px solid var(--danger-color);">
<div class="d-flex align-items-center">
<div class="icon-box bg-danger text-white rounded-circle p-3 me-3">
<i class="fas fa-bell fa-2x"></i>
</div>
<div>
<h3 class="mb-0" id="totalAlertas">0</h3>
<p class="text-muted mb-0">Con Vencimiento Próximo</p>
</div>
</div>
</div>
</div>
<!-- ✅ FILTROS DE BÚSQUEDA - CON COLLAPSE -->
<div class="filtros-box">
<!-- TÍTULO CON COLLAPSE -->
<div class="section-title d-flex justify-content-between align-items-center"
data-bs-toggle="collapse"
data-bs-target="#contenidoFiltros"
style="cursor: pointer;"
title="Clic para mostrar/ocultar filtros">
<h5><i class="fas fa-filter me-2"></i>Filtros de Búsqueda</h5>
<i class="fas fa-chevron-down" id="iconoFiltros"></i>
</div>
<!-- CONTENIDO COLAPSABLE (contraído por defecto) -->
<div id="contenidoFiltros" class="collapse">
<form method="GET" action="" class="row g-4">
<div class="col-lg-3 col-md-6">
<label class="form-label"><i class="fas fa-building"></i> Empresa</label>
<input type="text" name="search_empresa" class="form-control" value="<?php echo htmlspecialchars($search_empresa); ?>" placeholder="Buscar empresa...">
</div>
<div class="col-lg-3 col-md-6">
<label class="form-label"><i class="fas fa-folder"></i> Expediente</label>
<input type="text" name="search_expediente" class="form-control" value="<?php echo htmlspecialchars($search_expediente); ?>" placeholder="N° Expediente...">
</div>
<div class="col-lg-2 col-md-6">
<label class="form-label"><i class="fas fa-calendar"></i> Desde</label>
<input type="date" name="search_fecha_desde" class="form-control" value="<?php echo htmlspecialchars($search_fecha_desde); ?>">
</div>
<div class="col-lg-2 col-md-6">
<label class="form-label"><i class="fas fa-calendar"></i> Hasta</label>
<input type="date" name="search_fecha_hasta" class="form-control" value="<?php echo htmlspecialchars($search_fecha_hasta); ?>">
</div>
<div class="col-lg-2 col-md-6">
<label class="form-label"><i class="fas fa-toggle-on"></i> Estado</label>
<select name="search_estado" class="form-select">
<option value="todos" <?php echo ($search_estado === 'todos') ? 'selected' : ''; ?>>Todos</option>
<option value="iniciado" <?php echo ($search_estado === 'iniciado') ? 'selected' : ''; ?>>Iniciado</option>
<option value="notificado" <?php echo ($search_estado === 'notificado') ? 'selected' : ''; ?>>Notificado</option>
<option value="descargo" <?php echo ($search_estado === 'descargo') ? 'selected' : ''; ?>>En Descargo</option>
<option value="probatoria" <?php echo ($search_estado === 'probatoria') ? 'selected' : ''; ?>>Probatoria</option>
<option value="vista" <?php echo ($search_estado === 'vista') ? 'selected' : ''; ?>>Vista</option>
<option value="resolucion" <?php echo ($search_estado === 'resolucion') ? 'selected' : ''; ?>>Resolución</option>
<option value="en_apelacion" <?php echo ($search_estado === 'en_apelacion') ? 'selected' : ''; ?>>En Apelación</option>
<option value="cerrado" <?php echo ($search_estado === 'cerrado') ? 'selected' : ''; ?>>Cerrado</option>
</select>
</div>
<div class="col-12 d-flex gap-2">
<button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
<a href="sumarios.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
</div>
</form>
</div>  <!-- ✅ CIERRA contenidoFiltros -->
</div>
<!-- NUEVO SUMARIO -->
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<h2><i class="fas fa-plus-circle me-2"></i>Nuevo Sumario Administrativo</h2>
<button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#nuevoSumarioForm">
<i class="fas fa-plus me-1"></i>Crear desde Inspección
</button>
</div>
<div class="collapse mt-3" id="nuevoSumarioForm">
<h3 class="mb-4"><i class="fas fa-gavel me-2"></i>Crear Sumario desde Acta de Inspección</h3>
<form method="POST" action="">
<input type="hidden" name="crear_sumario" value="1">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">N° Expediente</label>
<input type="text" name="numero_expediente" class="form-control" placeholder="001" required>
<small class="text-muted"><i class="fas fa-info-circle"></i> Agregar antes URE-????</small>
</div>
<div class="col-md-4">
<label class="form-label">Año</label>
<input type="text" name="numero_anio" class="form-control" value="<?php echo date('Y'); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Fecha Inicio</label>
<input type="date" name="fecha_inicio" class="form-control" value="<?php echo date('Y-m-d'); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Inspección de Origen <span class="text-danger">*</span></label>
<select name="inspeccion_id" class="form-select" id="inspeccionSelect" required>
<option value="">Cargando inspecciones disponibles...</option>
</select>
<small class="text-muted"><i class="fas fa-info-circle"></i> Solo inspecciones sin sumario</small>
</div>
<div class="col-md-6">
<label class="form-label">Empresa</label>
<input type="text" name="empresa_nombre" class="form-control" id="empresaNombre" readonly style="background-color:#e8f5e9;">
</div>
<div class="col-md-4">
<label class="form-label">CUIT</label>
<input type="text" name="empresa_cuit" class="form-control" id="empresaCuit" readonly style="background-color:#e8f5e9;">
</div>
<div class="col-md-8">
<label class="form-label">Domicilio</label>
<input type="text" name="empresa_domicilio" class="form-control" id="empresaDomicilio" readonly style="background-color:#e8f5e9;">
</div>
<div class="col-md-4">
<label class="form-label">N° Acta</label>
<input type="text" name="acta_numero" class="form-control" id="actaNumero" readonly style="background-color:#e8f5e9;">
</div>
<div class="col-md-4">
<label class="form-label">Fecha Acta</label>
<input type="date" name="acta_fecha" class="form-control" id="actaFecha" readonly style="background-color:#e8f5e9;">
</div>
<div class="col-md-4">
<label class="form-label">Funcionario</label>
<input type="text" name="funcionario_nombre" class="form-control" id="funcionarioNombre" readonly style="background-color:#e8f5e9;">
</div>
<div class="col-md-12">
<label class="form-label">Tipo de Sanción Inicial (según Acta)</label>
<select name="tipo_sancion" class="form-select">
<option value="">Seleccionar...</option>
<option value="apercibimiento">Apercibimiento</option>
<option value="multa">Multa</option>
<option value="suspension">Suspensión</option>
<option value="clausura">Clausura</option>
<option value="cancelacion">Cancelación de Habilitación</option>
</select>
</div>
<div class="col-md-12">
<label class="form-label">Observaciones de Sanción</label>
<textarea name="observaciones_sancion" class="form-control" rows="3" placeholder="Fundamentación inicial basada en el Acta de Inspección..."></textarea>
</div>
<div class="text-end mt-4">
<button type="submit" class="btn btn-success btn-lg px-5">
<i class="fas fa-save me-2"></i>Crear Sumario
</button>
</div>
</div>
</form>
</div>
</div>
<!-- LISTADO DE SUMARIOS -->
<h2 class="section-title"><i class="fas fa-table me-2"></i>Listado de Sumarios<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span></h2>
<?php if (empty($sumarios)): ?>
<div class="empty-state-modern text-center p-5">
<i class="fas fa-gavel fa-4x text-muted"></i>
<h3>No hay sumarios registrados</h3>
<button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevoSumarioForm"><i class="fas fa-plus"></i> Crear Primer Sumario</button>
</div>
<?php else: ?>
<div class="table-container">
<table class="table table-striped">
<thead>
<tr>
<th>Expediente</th>
<th>Empresa</th>
<th>Acta Base</th>
<th>Fecha Inicio</th>
<th>Vencimiento</th>
<th>Alerta</th>
<th>Paso</th>
<th>Estado</th>
<th>Notificaciones PDF</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($sumarios as $sumario):
$fecha_base = $sumario['fecha_notificacion_inicio'] ?? $sumario['fecha_inicio'];
$suspensiones = obtenerSuspensionesSumario($conn, $sumario['id']);
$fecha_venc_descargo = calcularFechaHabil($fecha_base, 15, $feriados_fijos, $feriados_2024);
$alerta = getAlertaVencimiento($fecha_venc_descargo, $feriados_fijos, $feriados_2024, $suspensiones);
$clase_fila = '';
if ($alerta['class'] == 'danger') $clase_fila = 'fila-vencida';
elseif ($alerta['class'] == 'warning') $clase_fila = 'fila-proxima';
?>
<tr class="<?php echo $clase_fila; ?>">
<td><strong><?php echo htmlspecialchars($sumario['numero_expediente'] ?? 'N/A'); ?>/<?php echo htmlspecialchars($sumario['numero_anio'] ?? '-'); ?></strong></td>
<td><?php echo htmlspecialchars($sumario['empresa_nombre'] ?? 'N/A'); ?></td>
<td><?php echo htmlspecialchars($sumario['acta_numero'] ?? 'N/A'); ?></td>
<td><?php echo !empty($sumario['fecha_inicio']) ? date('d/m/Y', strtotime($sumario['fecha_inicio'])) : '-'; ?></td>
<td>
<small><?php echo date('d/m/Y', strtotime($fecha_venc_descargo)); ?></small>
</td>
<td>
<?php echo generarBadgeAlerta($alerta); ?>
</td>
<td><span class="badge bg-primary"><?php echo $sumario['paso_actual']; ?>/8</span></td>
<td><?php echo getEstadoSumarioBadge($sumario['estado']); ?></td>
<td>
<span class="notif-badge"><i class="fas fa-file-pdf me-1"></i><span class="notification-count-<?php echo $sumario['id']; ?>">0</span></span>
</td>
<td class="table-actions">
<div class="btn-group">
<button class="btn btn-sm btn-info btn-ver-sumario" title="Ver" data-id="<?php echo $sumario['id']; ?>">
<i class="fas fa-eye"></i>
</button>
<button class="btn btn-sm btn-success btn-generar-pdf" title="Generar Notificación PDF" data-id="<?php echo $sumario['id']; ?>" data-paso="<?php echo $sumario['paso_actual']; ?>">
<i class="fas fa-file-pdf"></i>
</button>
<button class="btn btn-sm btn-warning btn-avanzar-paso" title="Avanzar Paso" data-id="<?php echo $sumario['id']; ?>" data-paso="<?php echo $sumario['paso_actual']; ?>">
<i class="fas fa-arrow-right"></i>
</button>
<?php if (!empty($sumario['en_apelacion']) && $sumario['en_apelacion'] == 1): ?>
<button class="btn btn-sm btn-purple btn-resolver-apelacion" title="Resolver Apelación" data-id="<?php echo $sumario['id']; ?>">
<i class="fas fa-balance-scale"></i>
</button>
<?php endif; ?>
<?php if (!empty($sumario['resolucion_apelacion']) && $sumario['paso_actual'] >= 7): ?>
<button class="btn btn-sm btn-outline-purple btn-generar-pdf-apelacion" title="Generar PDF de Resolución Final Unificada" data-id="<?php echo $sumario['id']; ?>">
<i class="fas fa-file-pdf"></i> Res. Final
</button>
<?php endif; ?>
<form method="POST" action="" class="d-inline form-eliminar" data-id="<?php echo $sumario['id']; ?>">
<input type="hidden" name="eliminar_sumario" value="1">
<input type="hidden" name="id" value="<?php echo $sumario['id']; ?>">
<button type="button" class="btn btn-sm btn-danger btn-eliminar" title="Eliminar">
<i class="fas fa-trash"></i>
</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- PAGINACIÓN -->
<?php if ($total_paginas > 1): ?>
<nav aria-label="Paginación">
<ul class="pagination justify-content-center">
<?php for ($i = 1; $i <= $total_paginas; $i++): ?>
<li class="page-item <?php echo ($i === $pagina_actual) ? 'active' : ''; ?>">
<a class="page-link" href="?pagina=<?php echo $i; ?>&search_empresa=<?php echo urlencode($search_empresa); ?>&search_estado=<?php echo urlencode($search_estado); ?>"><?php echo $i; ?></a>
</li>
<?php endfor; ?>
</ul>
</nav>
<?php endif; ?>
<?php endif; ?>
<!-- MODAL VER SUMARIO -->
<div class="modal fade" id="modalVerSumario" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title"><i class="fas fa-eye me-2"></i>Detalle de Sumario</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div id="modalSumarioContent">
<div class="text-center py-5">
<div class="spinner-border text-primary" role="status"></div>
<p class="mt-2">Cargando datos...</p>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
<form method="POST" action="" class="d-inline">
<input type="hidden" name="exportar_sumario_pdf" value="1">
<input type="hidden" name="sumario_id" id="sumarioIdPdf">
<button type="submit" class="btn btn-danger"><i class="fas fa-file-pdf me-2"></i>Exportar PDF</button>
</form>
</div>
</div>
</div>
</div>
<!-- MODAL AVANZAR PASO -->
<div class="modal fade" id="modalAvanzarPaso" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header bg-warning text-dark">
<h5 class="modal-title"><i class="fas fa-arrow-right me-2"></i>Avanzar a Siguiente Paso</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form method="POST" action="" id="formAvanzarPaso" enctype="multipart/form-data">
<input type="hidden" name="actualizar_paso_sumario" value="1">
<input type="hidden" name="id" id="sumarioIdPaso">
<input type="hidden" name="paso_actual" id="nuevoPaso">
<input type="hidden" name="estado" id="nuevoEstado">
<div id="contenidoPaso">
</div>
<div class="text-end mt-4">
<button type="submit" class="btn btn-warning btn-lg"><i class="fas fa-check me-2"></i>Confirmar Avance</button>
</div>
</form>
</div>
</div>
</div>
</div>
<!-- ✅ MODAL RESOLVER APELACIÓN -->
<div class="modal fade" id="modalResolverApelacion" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header bg-purple text-white">
<h5 class="modal-title"><i class="fas fa-balance-scale me-2"></i>Resolver Apelación</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form id="formResolverApelacion">
<input type="hidden" name="sumario_id" id="apelacionSumarioId">
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>
<strong>Proceso de Resolución de Apelación:</strong><br>
Esta acción marcará la apelación como resuelta y permitirá avanzar el sumario al paso de cierre (Paso 8). La Resolución Final Unificada incluirá tanto la decisión de apelación como la notificación de cierre.
</div>
<div class="mb-3">
<label class="form-label">Resolución de la Apelación <span class="text-danger">*</span></label>
<select name="resolucion_apelacion" class="form-select" required>
<option value="">Seleccionar resolución...</option>
<option value="confirmada">Confirmar Sanción Original</option>
<option value="revocada">Revocar Sanción (Sin efecto)</option>
<option value="modificada">Modificar Sanción</option>
</select>
<small class="text-muted">Seleccione el resultado de la resolución del recurso de apelación</small>
</div>
<div class="mb-3">
<label class="form-label">Fecha de Resolución</label>
<input type="date" name="fecha_resolucion_apelacion" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
</div>
<div class="mb-3">
<label class="form-label">Observaciones de la Resolución</label>
<textarea name="observaciones_resolucion" class="form-control" rows="4" placeholder="Detalle los fundamentos de la resolución de la apelación..."></textarea>
</div>
<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>Importante:</strong> Una vez resuelta la apelación, se generará automáticamente la Resolución Final Unificada que combina la decisión de apelación con la notificación de cierre del sumario.
</div>
<div class="alert alert-info">
<i class="fas fa-file-pdf me-2"></i>
<strong>PDF Unificado:</strong> Al confirmar, se generará un único PDF que contiene: (1) Resolución de apelación, (2) Notificación de cierre, (3) Base legal consolidada, y (4) Instrucciones finales de cumplimiento.
</div>
</form>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-purple" id="btnConfirmarResolucion">
<i class="fas fa-check me-2"></i>Confirmar Resolución y Generar PDF Unificado
</button>
</div>
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
document.addEventListener('DOMContentLoaded', function() {
// ========================================
// ✅ CARGAR ALERTAS DE VENCIMIENTO
// ========================================
function cargarAlertasVencimiento() {
fetch('sumarios.php?action=get_alertas_vencimiento')
.then(response => response.json())
.then(data => {
if (data.success && data.alertas.length > 0) {
document.getElementById('totalAlertas').textContent = data.count;
document.getElementById('panelAlertasVencimiento').style.display = 'block';
document.getElementById('textoAlertas').textContent =
`${data.count} sumario(s) con vencimiento próximo o vencido`;
// Mostrar notificación si hay alertas críticas
if (data.count > 0) {
Swal.fire({
icon: 'warning',
title: 'Alertas de Vencimiento',
text: `Hay ${data.count} sumario(s) que requieren atención inmediata`,
timer: 5000,
showConfirmButton: false
});
}
}
})
.catch(error => console.error('Error cargando alertas:', error));
}
cargarAlertasVencimiento();
// ========================================
// CARGAR INSPECCIONES DISPONIBLES PARA SUMARIO
// ========================================
const inspeccionSelect = document.getElementById('inspeccionSelect');
const empresaNombre = document.getElementById('empresaNombre');
const empresaCuit = document.getElementById('empresaCuit');
const empresaDomicilio = document.getElementById('empresaDomicilio');
const actaNumero = document.getElementById('actaNumero');
const actaFecha = document.getElementById('actaFecha');
const funcionarioNombre = document.getElementById('funcionarioNombre');
if (inspeccionSelect) {
fetch('inspecciones.php?action=get_inspecciones_disponibles')
.then(response => response.json())
.then(data => {
if (data.success && data.inspecciones.length > 0) {
inspeccionSelect.innerHTML = '<option value="">Seleccionar inspección...</option>';
data.inspecciones.forEach(insp => {
const option = document.createElement('option');
option.value = insp.id;
option.textContent = `Acta ${insp.numero_acta}/${insp.numero_anio} - ${insp.empresa_nombre}`;
option.dataset.empresa = insp.empresa_nombre || '';
option.dataset.cuit = insp.empresa_cuit || '';
option.dataset.domicilio = insp.empresa_domicilio || '';
option.dataset.actaNumero = insp.numero_acta || '';
option.dataset.actaFecha = insp.fecha_inspeccion || '';
option.dataset.funcionario = insp.funcionario_nombre || '';
inspeccionSelect.appendChild(option);
});
} else if (data.inspecciones.length === 0) {
inspeccionSelect.innerHTML = '<option value="" disabled selected>No hay inspecciones disponibles</option>';
} else {
inspeccionSelect.innerHTML = '<option value="" disabled selected>Error al cargar</option>';
}
})
.catch(error => {
console.error('Error de conexión:', error);
inspeccionSelect.innerHTML = '<option value="" disabled selected>Error de conexión</option>';
});
inspeccionSelect.addEventListener('change', function() {
const selectedOption = this.options[this.selectedIndex];
if (selectedOption && this.value) {
empresaNombre.value = selectedOption.dataset.empresa || '';
empresaCuit.value = selectedOption.dataset.cuit || '';
empresaDomicilio.value = selectedOption.dataset.domicilio || '';
actaNumero.value = selectedOption.dataset.actaNumero || '';
actaFecha.value = selectedOption.dataset.actaFecha || '';
funcionarioNombre.value = selectedOption.dataset.funcionario || '';
} else {
empresaNombre.value = '';
empresaCuit.value = '';
empresaDomicilio.value = '';
actaNumero.value = '';
actaFecha.value = '';
funcionarioNombre.value = '';
}
});
}
// ========================================
// MODAL VER SUMARIO
// ========================================
const modalVerSumario = new bootstrap.Modal(document.getElementById('modalVerSumario'));
const sumarioIdPdf = document.getElementById('sumarioIdPdf');
document.querySelectorAll('.btn-ver-sumario').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
const modalContent = document.getElementById('modalSumarioContent');
modalContent.innerHTML = `
<div class="text-center py-5">
<div class="spinner-border text-primary" role="status"></div>
<p class="mt-2">Cargando datos...</p>
</div>
`;
modalVerSumario.show();
fetch(`sumarios.php?action=get_sumario_detalle&id=${id}`)
.then(response => response.json())
.then(data => {
if (data.success) {
const sum = data.sumario;
let html = `
<div class="row g-3">
<div class="col-md-6">
<div class="card bg-light h-100">
<div class="card-header bg-primary text-white">
<h6 class="mb-0"><i class="fas fa-folder me-2"></i>Datos del Expediente</h6>
</div>
<div class="card-body">
<p class="mb-2"><strong>Expediente:</strong> ${sum.numero_expediente || 'N/A'}/${sum.numero_anio || '-'}</p>
<p class="mb-2"><strong>Estado:</strong> ${sum.estado || '-'}</p>
<p class="mb-2"><strong>Paso Actual:</strong> ${sum.paso_actual || 1}/8</p>
<p class="mb-2"><strong>Fecha Inicio:</strong> ${sum.fecha_inicio ? new Date(sum.fecha_inicio + 'T00:00:00').toLocaleDateString('es-AR') : '-'}</p>
`;
// ✅ MOSTRAR INFORMACIÓN DE APELACIÓN Y SILENCIO ADMINISTRATIVO
if (sum.en_apelacion == 1) {
html += `<p class="mb-2"><strong>En Apelación:</strong> <span class="badge bg-warning text-dark">SÍ</span></p>`;
}
if (sum.fecha_vencimiento_estado) {
html += `<p class="mb-2"><strong>Venc. Estado (Silencio):</strong> ${new Date(sum.fecha_vencimiento_estado + 'T00:00:00').toLocaleDateString('es-AR')}</p>`;
}
// ✅ MOSTRAR INFORMACIÓN DE RESOLUCIÓN DE APELACIÓN
if (sum.resolucion_apelacion) {
html += `<p class="mb-2"><strong>Resolución Apelación:</strong> <span class="badge bg-success">${sum.resolucion_apelacion.toUpperCase()}</span></p>`;
}
if (sum.fecha_resolucion_apelacion) {
html += `<p class="mb-2"><strong>Fecha Resolución:</strong> ${new Date(sum.fecha_resolucion_apelacion + 'T00:00:00').toLocaleDateString('es-AR')}</p>`;
}
html += `
</div>
</div>
</div>
<div class="col-md-6">
<div class="card bg-light h-100">
<div class="card-header bg-primary text-white">
<h6 class="mb-0"><i class="fas fa-building me-2"></i>Empresa</h6>
</div>
<div class="card-body">
<p class="mb-2"><strong>Empresa:</strong> ${sum.empresa_nombre || '-'}</p>
<p class="mb-2"><strong>CUIT:</strong> ${sum.empresa_cuit || '-'}</p>
<p class="mb-2"><strong>Domicilio:</strong> ${sum.empresa_domicilio || '-'}</p>
</div>
</div>
</div>
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-info text-white">
<h6 class="mb-0"><i class="fas fa-file-alt me-2"></i>Acta de Inspección Base</h6>
</div>
<div class="card-body">
<div class="row">
<div class="col-md-4"><p class="mb-1"><strong>Acta N°:</strong> ${sum.acta_numero || '-'}</p></div>
<div class="col-md-4"><p class="mb-1"><strong>Fecha:</strong> ${sum.acta_fecha ? new Date(sum.acta_fecha + 'T00:00:00').toLocaleDateString('es-AR') : '-'}</p></div>
<div class="col-md-4"><p class="mb-1"><strong>Funcionario:</strong> ${sum.funcionario_nombre || '-'}</p></div>
</div>
</div>
</div>
</div>
</div>
`;
const pasosNombres = ['Inicio', 'Notificación', 'Descargo', 'Probatoria', 'Vista', 'Resolución', 'Notif. Res.', 'Cierre'];
html += `
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-warning text-dark">
<h6 class="mb-0"><i class="fas fa-timeline me-2"></i>Progreso de 8 Pasos</h6>
</div>
<div class="card-body">
<div class="paso-timeline">
`;
for (let i = 1; i <= 8; i++) {
const clase = i < sum.paso_actual ? 'completed' : (i === sum.paso_actual ? 'active' : 'pending');
html += `
<div class="paso-item ${clase}">
<div class="paso-number">${i}</div>
<div class="paso-label">${pasosNombres[i-1]}</div>
</div>
`;
}
html += `
</div>
</div>
</div>
</div>
`;
// ✅ AGREGAR SECCIÓN DE BASE LEGAL EN EL MODAL
if (sum.base_legal) {
html += `
<div class="col-md-12">
<div class="card bg-light border-warning">
<div class="card-header bg-warning text-dark">
<h6 class="mb-0"><i class="fas fa-balance-scale me-2"></i>Base Legal del Paso ${sum.paso_actual}</h6>
</div>
<div class="card-body">
<p class="mb-1"><strong>Nacional:</strong> ${sum.base_legal.nacional}</p>
<p class="mb-0"><strong>Provincial:</strong> ${sum.base_legal.provincial}</p>
</div>
</div>
</div>
`;
}
// Agregar sección de notificaciones PDF
html += `
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-danger text-white">
<h6 class="mb-0"><i class="fas fa-file-pdf me-2"></i>Notificaciones PDF</h6>
</div>
<div class="card-body">
<div id="contenedorNotificaciones${id}">
<div class="text-center py-3">
<div class="spinner-border text-danger" role="status"></div>
<p class="mt-2">Cargando...</p>
</div>
</div>
</div>
</div>
</div>
`;
// ✅ SECCIÓN DE SUSPENSIONES DE PLAZOS
if (sum.suspensiones && sum.suspensiones.length > 0) {
html += `
<div class="col-md-12">
<div class="card bg-light">
<div class="card-header bg-info text-white">
<h6 class="mb-0"><i class="fas fa-pause-circle me-2"></i>Suspensiones de Plazos</h6>
</div>
<div class="card-body">
`;
sum.suspensiones.forEach(susp => {
html += `
<div class="alert alert-secondary mb-2">
<strong>${susp.motivo}</strong><br>
<small>Desde: ${new Date(susp.fecha_inicio + 'T00:00:00').toLocaleDateString('es-AR')} - Hasta: ${susp.fecha_fin ? new Date(susp.fecha_fin + 'T00:00:00').toLocaleDateString('es-AR') : 'Indefinido'}</small>
</div>
`;
});
html += `
</div>
</div>
</div>
</div>
`;
}
modalContent.innerHTML = html;
sumarioIdPdf.value = id;
// Cargar notificaciones
fetch(`sumarios.php?action=obtener_notificaciones&id=${id}`)
.then(response => response.json())
.then(notData => {
if (notData.success && notData.notificaciones.length > 0) {
let htmlNotifs = '';
notData.notificaciones.forEach(not => {
const tipoNotifTexto = {
'personal': 'Personal',
'cedula': 'Cédula',
'carta_documento': 'Carta Documento',
'email': 'Email Certificado',
'otro': 'Otro',
'apelacion': 'Resolución de Apelación',
'resolucion_final': 'Resolución Final Unificada'
};
htmlNotifs += `
<div class="d-flex align-items-center justify-content-between mb-2 p-2 border rounded notification-item">
<div class="d-flex align-items-center">
<i class="fas fa-file-pdf text-danger fa-2x me-3"></i>
<div>
<strong>${not.tipo_notificacion === 'resolucion_final' ? 'Resolución Final Unificada' : (not.tipo_notificacion === 'apelacion' ? 'Resolución de Apelación' : 'Paso ' + not.paso_actual + ': ' + pasosNombres[not.paso_actual-1])}</strong><br>
<small class="text-muted">${new Date(not.fecha_subida).toLocaleDateString('es-AR')} ${new Date(not.fecha_subida).toLocaleTimeString('es-AR')}</small><br>
<small><strong>Medio:</strong> ${tipoNotifTexto[not.tipo_notificacion] || not.tipo_notificacion}</small>
${not.numero_cedula ? `<small> | <strong>Cédula N°:</strong> ${not.numero_cedula}</small>` : ''}
${not.numero_carta_documento ? `<small> | <strong>Carta Doc. N°:</strong> ${not.numero_carta_documento}</small>` : ''}
</div>
</div>
<div>
<a href="../uploads/sumarios/notificaciones_firmadas/${not.nombre_archivo}" target="_blank" class="btn btn-sm btn-outline-danger me-2">
<i class="fas fa-eye"></i> Ver
</a>
<a href="../uploads/sumarios/notificaciones_firmadas/${not.nombre_archivo}" download class="btn btn-sm btn-outline-secondary">
<i class="fas fa-download"></i> Descargar
</a>
</div>
</div>
`;
});
document.getElementById('contenedorNotificaciones' + id).innerHTML = htmlNotifs;
document.querySelector('.notification-count-' + id).textContent = notData.notificaciones.length;
} else {
document.getElementById('contenedorNotificaciones' + id).innerHTML = `
<div class="alert alert-warning">
<i class="fas fa-info-circle"></i> No hay notificaciones PDF registradas para este sumario
</div>
`;
document.querySelector('.notification-count-' + id).textContent = '0';
}
})
.catch(error => {
document.getElementById('contenedorNotificaciones' + id).innerHTML = `
<div class="alert alert-danger">Error al cargar notificaciones: ${error.message}</div>
`;
});
} else {
modalContent.innerHTML = `<div class="alert alert-danger">${data.error || 'Error al cargar'}</div>`;
}
})
.catch(error => {
modalContent.innerHTML = `<div class="alert alert-danger">Error de conexión: ${error.message}</div>`;
});
});
});
// ========================================
// ✅ MODAL RESOLVER APELACIÓN
// ========================================
const modalResolverApelacion = new bootstrap.Modal(document.getElementById('modalResolverApelacion'));
document.querySelectorAll('.btn-resolver-apelacion').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
document.getElementById('apelacionSumarioId').value = id;
modalResolverApelacion.show();
});
});
document.getElementById('btnConfirmarResolucion').addEventListener('click', function() {
const form = document.getElementById('formResolverApelacion');
const formData = new FormData(form);
const sumarioId = document.getElementById('apelacionSumarioId').value;
// Validar campos requeridos
const resolucion = formData.get('resolucion_apelacion');
if (!resolucion) {
Swal.fire({
icon: 'error',
title: 'Campo requerido',
text: 'Debe seleccionar una resolución para la apelación'
});
return;
}
Swal.fire({
title: '¿Confirmar Resolución de Apelación?',
text: 'Esta acción generará la Resolución Final Unificada (Apelación + Cierre) y marcará el sumario como cerrado. ¿Está seguro?',
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#9b59b6',
cancelButtonColor: '#95a5a6',
confirmButtonText: '<i class="fas fa-check me-2"></i>Confirmar y Generar PDF Unificado',
cancelButtonText: '<i class="fas fa-times me-2"></i>Cancelar'
}).then((result) => {
if (result.isConfirmed) {
fetch('sumarios.php?action=resolver_apelacion', {
method: 'POST',
body: formData
})
.then(response => response.json())
.then(data => {
if (data.success) {
// Mostrar opción para descargar PDF generado
let pdfMessage = '';
if (data.pdf_generado) {
pdfMessage = `<br><br><strong>📄 Resolución Final Unificada generada:</strong><br>${data.pdf_generado}<br>
<a href="uploads/sumarios/notificaciones_firmadas/${data.pdf_generado}" target="_blank" class="btn btn-sm btn-outline-danger mt-2">
<i class="fas fa-file-pdf me-1"></i>Descargar PDF Unificado
</a>`;
}
Swal.fire({
icon: 'success',
title: '¡Resolución Final Generada!',
html: data.mensaje + pdfMessage,
showConfirmButton: true,
confirmButtonText: 'Cerrar'
}).then(() => {
location.reload();
});
} else {
Swal.fire({
icon: 'error',
title: 'Error',
text: data.error || 'No se pudo resolver la apelación'
});
}
})
.catch(error => {
Swal.fire({
icon: 'error',
title: 'Error de conexión',
text: 'No se pudo comunicar con el servidor: ' + error.message
});
});
}
});
});
// ========================================
// ✅ GENERAR PDF DE RESOLUCIÓN FINAL UNIFICADA (NUEVO)
// ========================================
document.querySelectorAll('.btn-generar-pdf-apelacion').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
Swal.fire({
title: 'Generar Resolución Final Unificada',
text: '¿Desea generar la notificación PDF que unifica la Resolución de Apelación con la Notificación de Cierre (Paso 8)?',
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#9b59b6',
cancelButtonColor: '#95a5a6',
confirmButtonText: '<i class="fas fa-file-pdf me-2"></i>Generar PDF Unificado',
cancelButtonText: '<i class="fas fa-times me-2"></i>Cancelar'
}).then((result) => {
if (result.isConfirmed) {
window.open(`sumarios.php?action=generar_notificacion_apelacion_pdf&id=${id}`, '_blank');
}
});
});
});
// ========================================
// GENERAR PDF DE NOTIFICACIÓN POR PASO
// ========================================
document.querySelectorAll('.btn-generar-pdf').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
const paso = this.dataset.paso;
// ✅ Para Paso 3, preguntar qué variante generar
if (paso == 3) {
Swal.fire({
title: 'Generar Notificación Paso 3',
text: 'Seleccione la variante a generar:',
icon: 'question',
showCancelButton: true,
showDenyButton: true,
confirmButtonText: '<i class="fas fa-check me-2"></i>Presentó Descargo',
denyButtonText: '<i class="fas fa-times me-2"></i>No Presentó Descargo',
cancelButtonText: '<i class="fas fa-ban me-2"></i>Cancelar',
confirmButtonColor: '#28a745',
denyButtonColor: '#dc3545'
}).then((result) => {
if (result.isConfirmed) {
window.open(`sumarios.php?action=generar_notificacion_pdf&id=${id}&paso=${paso}&variante=si_presento`, '_blank');
} else if (result.isDenied) {
window.open(`sumarios.php?action=generar_notificacion_pdf&id=${id}&paso=${paso}&variante=no_presento`, '_blank');
}
});
} else {
Swal.fire({
title: 'Generar Notificación PDF',
text: `¿Desea generar la notificación del Paso ${paso}?`,
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#4361ee',
cancelButtonColor: '#95a5a6',
confirmButtonText: '<i class="fas fa-file-pdf me-2"></i>Generar PDF',
cancelButtonText: '<i class="fas fa-times me-2"></i>Cancelar'
}).then((result) => {
if (result.isConfirmed) {
window.open(`sumarios.php?action=generar_notificacion_pdf&id=${id}&paso=${paso}`, '_blank');
}
});
}
});
});
// ========================================
// MODAL AVANZAR PASO CON SUBIDA DE PDF
// ========================================
const modalAvanzarPaso = new bootstrap.Modal(document.getElementById('modalAvanzarPaso'));
document.querySelectorAll('.btn-avanzar-paso').forEach(btn => {
btn.addEventListener('click', function() {
const id = this.dataset.id;
const pasoActual = parseInt(this.dataset.paso);
const nuevoPaso = pasoActual + 1;
if (nuevoPaso > 8) {
Swal.fire({
icon: 'info',
title: 'Proceso Completado',
text: 'Este sumario ya está en el paso final (Cierre)'
});
return;
}
document.getElementById('sumarioIdPaso').value = id;
document.getElementById('nuevoPaso').value = nuevoPaso;
const estados = {
2: 'notificado',
3: 'descargo',
4: 'probatoria',
5: 'vista',
6: 'resolucion',
7: 'notificado_resolucion',
8: 'cerrado'
};
document.getElementById('nuevoEstado').value = estados[nuevoPaso] || 'iniciado';
// Actualizar contenido del formulario según el paso
let contenido = `<h5>Paso ${nuevoPaso} de 8</h5><hr>`;
// Agregar subida de archivo PDF con campos de medio de notificación
contenido += `<div class="alert alert-info"><strong>📄 Subir Notificación PDF:</strong> Adjunte la notificación generada del paso anterior</div>`;
contenido += `<div class="mb-3">
<label class="form-label">Archivo PDF de Notificación <span class="text-danger">*</span></label>
<input type="file" name="pdf_notificacion" class="form-control" accept=".pdf" required>
<small class="text-muted">Tamaño máximo: 5MB</small>
</div>`;
contenido += `<hr><h6>📋 Medio de Notificación Legal</h6>`;
contenido += `<div class="mb-3">
<label class="form-label">Tipo de Notificación</label>
<select name="tipo_notificacion" class="form-select">
<option value="personal">Entrega Personal</option>
<option value="cedula">Cédula de Notificación</option>
<option value="carta_documento">Carta Documento</option>
<option value="email">Email Certificado</option>
<option value="otro">Otro Medio</option>
</select>
</div>`;
contenido += `<div class="row">
<div class="col-md-6 mb-3">
<label class="form-label">N° de Cédula (si aplica)</label>
<input type="text" name="numero_cedula" class="form-control" placeholder="Ej: CED-2024-001">
</div>
<div class="col-md-6 mb-3">
<label class="form-label">N° Carta Documento (si aplica)</label>
<input type="text" name="numero_carta_documento" class="form-control" placeholder="Ej: CD-12345678">
</div>
</div>`;
contenido += `<div class="mb-3">
<label class="form-label">Fecha de Notificación Legal</label>
<input type="date" name="fecha_notificacion_legal" class="form-control" value="${new Date().toISOString().split('T')[0]}">
</div>`;
contenido += `<hr>`;
switch (nuevoPaso) {
case 2:
contenido += `<div class="alert alert-info"><strong>NOTIFICACIÓN DE INICIO:</strong> Se notifica formalmente el inicio del sumario a la empresa.</div>`;
break;
case 3:
contenido += `
<div class="mb-3">
<label class="form-label">¿Se presentó descargo?</label>
<select name="descargo_presentado" class="form-select" required>
<option value="si">SI - Presentó descargo</option>
<option value="no">NO - No presentó</option>
</select>
</div>
<div class="mb-3">
<label class="form-label">Fecha de Presentación</label>
<input type="date" name="fecha_descargo_presentado" class="form-control">
</div>
`;
break;
case 4:
contenido += `
<div class="mb-3">
<label class="form-label">Informe Técnico</label>
<textarea name="informe_tecnico" class="form-control" rows="3" placeholder="Análisis del descargo y verificación de irregularidades..."></textarea>
</div>
<div class="mb-3">
<label class="form-label">Medidas Probatorias</label>
<textarea name="medidas_probatorias" class="form-control" rows="3" placeholder="Oficios a RENAR, verificación de credenciales, etc..."></textarea>
</div>
`;
break;
case 5:
contenido += `
<div class="mb-3">
<label class="form-label">Alegatos Finales</label>
<textarea name="alegatos_finales" class="form-control" rows="3" placeholder="Alegatos presentados por la empresa..."></textarea>
</div>
`;
break;
case 6:
contenido += `
<div class="mb-3">
<label class="form-label">Tipo de Sanción</label>
<select name="tipo_sancion" class="form-select" required>
<option value="apercibimiento">Apercibimiento</option>
<option value="multa">Multa</option>
<option value="suspension">Suspensión</option>
<option value="clausura">Clausura</option>
<option value="cancelacion">Cancelación de Habilitación</option>
</select>
</div>
<div class="mb-3">
<label class="form-label">Monto de Multa (si corresponde)</label>
<input type="number" name="monto_multa" class="form-control" step="0.01" min="0">
</div>
<div class="mb-3">
<label class="form-label">Días de Suspensión (si corresponde)</label>
<input type="number" name="dias_suspension" class="form-control" min="1">
</div>
<div class="mb-3">
<label class="form-label">Número de Resolución</label>
<input type="text" name="numero_resolucion" class="form-control" placeholder="RES-XXX-2024">
</div>
`;
break;
case 7:
contenido += `<div class="alert alert-info"><strong>📄 NOTIFICACIÓN DE RESOLUCIÓN:</strong> Se notifica fehacientemente la resolución final a la empresa.</div>`;
// ✅ CAMPO REQUERIDO AGREGADO:
contenido += `<div class="mb-3">
<label class="form-label">Fecha de Notificación de Resolución <span class="text-danger">*</span></label>
<input type="date" name="fecha_notificacion_resolucion" class="form-control" value="${new Date().toISOString().split('T')[0]}" required>
<small class="text-muted">Fecha en que se notificó la resolución a la empresa</small>
</div>`;
contenido += `<hr><div class="alert alert-warning"><strong>⚠️ RECURSO DE APELACIÓN:</strong> Si la empresa interpone recurso, marque "SÍ" en la opción siguiente. El sumario NO podrá cerrarse hasta resolver la apelación.</div>`;
contenido += `<div class="mb-3">
<label class="form-label">¿Se interpuso recurso de apelación?</label>
<select name="recurso_apelacion" class="form-select" required>
<option value="no">NO - Sin apelación</option>
<option value="si">SÍ - Con apelación pendiente</option>
</select>
</div>
<div class="mb-3">
<label class="form-label">Fecha de Interposición del Recurso</label>
<input type="date" name="fecha_recurso" class="form-control">
</div>`;
break;
case 8:
contenido += `
<div class="alert alert-warning">
<strong>⚠️ VALIDACIÓN DE CIERRE:</strong> Si existe un recurso de apelación pendiente, NO se puede cerrar el sumario. Primero debe resolverse la apelación mediante el botón "Resolver Apelación" en la lista.
</div>
<div class="mb-3">
<label class="form-label">¿Se pagó la multa?</label>
<select name="multa_pagada" class="form-select">
<option value="si">SI</option>
<option value="no">NO</option>
</select>
</div>
<div class="mb-3">
<label class="form-label">¿Se verificó la subsanación?</label>
<select name="subsanacion_verificada" class="form-select">
<option value="si">SI</option>
<option value="no">NO</option>
</select>
</div>
`;
break;
}
document.getElementById('contenidoPaso').innerHTML = contenido;
modalAvanzarPaso.show();
});
});
// ========================================
// CONFIRMACIÓN DE ELIMINACIÓN
// ========================================
document.querySelectorAll('.btn-eliminar').forEach(btn => {
btn.addEventListener('click', function() {
const form = this.closest('.form-eliminar');
const id = form.dataset.id;
Swal.fire({
title: '¿Eliminar sumario?',
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
form.submit();
}
});
});
});
// ========================================
// AUTO-CERRAR ALERTAS
// ========================================
setTimeout(() => {
document.querySelectorAll('.alert').forEach(alert => {
new bootstrap.Alert(alert).close();
});
}, 5000);
// ========================================
// PRE-SELECCIONAR INSPECCIÓN DESDE URL
// ========================================
const urlParams = new URLSearchParams(window.location.search);
const crearDesdeInspeccion = urlParams.get('crear_desde_inspeccion');
if (crearDesdeInspeccion) {
const collapseElement = document.getElementById('nuevoSumarioForm');
if (collapseElement) {
const bsCollapse = new bootstrap.Collapse(collapseElement, {show: true});
}
setTimeout(() => {
const inspeccionSelect = document.getElementById('inspeccionSelect');
if (inspeccionSelect) {
inspeccionSelect.value = crearDesdeInspeccion;
inspeccionSelect.dispatchEvent(new Event('change'));
}
}, 500);
window.history.replaceState({}, document.title, window.location.pathname);
}
// ========================================
// ✅ AUTO-REFRESCO DE ALERTAS CADA 5 MIN
// ========================================
setInterval(cargarAlertasVencimiento, 300000);
});
// ========================================
// ✅ VER TODAS LAS ALERTAS
// ========================================
function verTodasLasAlertas() {
fetch('sumarios.php?action=get_alertas_vencimiento')
.then(response => response.json())
.then(data => {
if (data.success && data.alertas.length > 0) {
let html = '<ul class="list-group">';
data.alertas.forEach(alerta => {
const clase = alerta.alerta.class === 'danger' ? 'list-group-item-danger' : 'list-group-item-warning';
html += `
<li class="list-group-item ${clase}">
<div class="d-flex justify-content-between align-items-center">
<div>
<strong>${alerta.expediente}</strong><br>
<small>${alerta.empresa} - ${alerta.tipo}</small>
</div>
<span class="badge bg-${alerta.alerta.class}">${alerta.alerta.texto}${alerta.alerta.dias ? ' (' + alerta.alerta.dias + ' días)' : ''}</span>
</div>
</li>
`;
});
html += '</ul>';
Swal.fire({
title: 'Alertas de Vencimiento',
html: html,
width: 600,
confirmButtonText: 'Cerrar'
});
}
});
}
</script>
</body>
</html>