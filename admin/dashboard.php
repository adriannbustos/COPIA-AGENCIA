<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
require_once '../config/session_manager.php';
// [C2] & [A8] Validación estandarizada de sesión contra BD en todas las páginas protegidas
requireValidSession();
// [C4] Revalidar rol contra base de datos en cada request para mayor seguridad
$roles_permitidos = ['administrador', 'carga', 'operador'];
$rol_usuario = '';
if ($auth->isLoggedIn()) {
$user_session = $auth->getCurrentUser();
$user_id = $user_session['id'] ?? null;
if ($user_id) {
$conn_check = getDBConnection();
$stmt_check = $conn_check->prepare("SELECT rol FROM usuarios WHERE id = ? AND activo = TRUE LIMIT 1");
$stmt_check->execute([$user_id]);
$resultado = $stmt_check->fetch(PDO::FETCH_ASSOC);
if ($resultado && isset($resultado['rol'])) {
$rol_usuario = $resultado['rol'];
}
}
}
if (!$auth->isLoggedIn() || !in_array($rol_usuario, $roles_permitidos, true)) {
header('Location: ../login.php');
exit;
}
$current_page = 'dashboard';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// FUNCION PARA OBTENER ICONO SEGUN TIPO DE ALERTA
// ============================================================================
function obtenerIconoAlerta($tipo) {
$iconos = [
// SUCURSALES
'sucursales_pendientes_aprobacion' => 'clock',
'aranceles_vencidos' => 'money-bill-wave',
'sucursales_rechazadas' => 'times-circle',
'sucursales_documentacion_incompleta' => 'file-contract',
'sucursales_sin_pdf_resolucion' => 'file-pdf',
'sucursales_sin_informes' => 'file-export',
'sucursales_sin_director_tecnico' => 'user-times',
// PERSONAL
'doc_vencida' => 'user-times',
'doc_por_vencer' => 'user-clock',
'personal_inactivo_sin_baja' => 'user-slash',
'documentacion_pendiente_revision' => 'file-signature',
'revalidaciones_vencidas' => 'sync-alt',
'revalidaciones_por_vencer' => 'hourglass-half',
'credenciales_sin_pagar' => 'credit-card',
'personal_sin_foto' => 'image',
'personal_sin_pdf_datos' => 'file-pdf',
'personal_sin_cupon_pago' => 'receipt',
'personal_sin_certificado' => 'certificate',
// RECURSOS
'recursos_pendientes_aprobacion' => 'clipboard-list',
'recursos_rechazados' => 'times-circle',
'recursos_items_vencidos' => 'box-open',
'recursos_sin_pdf' => 'file-pdf',
'recursos_por_vencer' => 'hourglass-half',
// SERVICIOS
'servicios_pendientes_aprobacion' => 'clock',
'servicios_con_sanciones' => 'gavel',
'servicios_sin_pdf' => 'file-pdf',
'servicios_vencidos' => 'calendar-times',
'servicios_sin_personal_asignado' => 'user-slash',
// INSPECCIONES
'inspecciones_pendientes' => 'clipboard-list',
'inspecciones_con_observaciones' => 'exclamation-circle',
'inspecciones_con_sanciones' => 'gavel',
'inspecciones_sin_sumario' => 'link-slash',
'inspecciones_por_vencer' => 'hourglass-half',
// ?? DOCUMENTOS EMPRESAS (NUEVO)
'documentos_pendientes_revision' => 'file-signature',
'documentos_rechazados' => 'times-circle',
'documentos_sin_observaciones' => 'file-alt',
// INFORMES
'informe_pendiente' => 'file-alt',
'multas_pendientes' => 'file-invoice-dollar',
// INSPECCIONES PROGRAMADAS (Dashboard específico)
'inspecciones_programadas_hoy' => 'calendar-day',
'inspecciones_programadas_vencidas' => 'calendar-times',
'inspecciones_programadas_pendientes' => 'calendar-check'
];
return $iconos[$tipo] ?? 'exclamation-circle';
}
// ============================================================================
// FUNCION PARA OBTENER COLOR SEGUN PRIORIDAD
// ============================================================================
function obtenerColorPrioridad($prioridad) {
$colores = [
'alta' => '#e74c3c',
'media' => '#f39c12',
'baja' => '#3498db'
];
return $colores[$prioridad] ?? '#3498db';
}
// ============================================================================
// FUNCION PARA OBTENER MODULO SEGUN TIPO DE ALERTA
// ============================================================================
function obtenerModuloAlerta($tipo) {
if (in_array($tipo, ['sucursales_pendientes_aprobacion', 'aranceles_vencidos', 'sucursales_rechazadas', 'sucursales_documentacion_incompleta', 'sucursales_sin_pdf_resolucion', 'sucursales_sin_informes', 'sucursales_sin_director_tecnico'])) {
return 'sucursales';
} elseif (in_array($tipo, ['doc_vencida', 'doc_por_vencer', 'personal_inactivo_sin_baja', 'documentacion_pendiente_revision', 'revalidaciones_vencidas', 'revalidaciones_por_vencer', 'credenciales_sin_pagar', 'personal_sin_foto', 'personal_sin_pdf_datos', 'personal_sin_cupon_pago', 'personal_sin_certificado'])) {
return 'personal';
} elseif (in_array($tipo, ['recursos_pendientes_aprobacion', 'recursos_rechazados', 'recursos_items_vencidos', 'recursos_sin_pdf', 'recursos_por_vencer'])) {
return 'recursos';
} elseif (in_array($tipo, ['servicios_pendientes_aprobacion', 'servicios_con_sanciones', 'servicios_sin_pdf', 'servicios_vencidos', 'servicios_sin_personal_asignado'])) {
return 'servicios';
} elseif (in_array($tipo, ['inspecciones_pendientes', 'inspecciones_con_observaciones', 'inspecciones_con_sanciones', 'inspecciones_sin_sumario', 'inspecciones_por_vencer'])) {
return 'inspecciones';
} elseif (in_array($tipo, ['inspecciones_programadas_hoy', 'inspecciones_programadas_vencidas', 'inspecciones_programadas_pendientes'])) {
return 'inspecciones_programadas';
} elseif (in_array($tipo, ['documentos_pendientes_revision', 'documentos_rechazados', 'documentos_sin_observaciones'])) {
return 'documentos';
}
return 'otros';
}
// ============================================================================
// FUNCION AUXILIAR: VERIFICAR SI EXISTE UNA COLUMNA (con caché estática)
// ============================================================================
function columnaExiste($conn, $tabla, $columna) {
static $cache = [];
$key = "{$tabla}.{$columna}";
if (!isset($cache[$key])) {
try {
$stmt = $conn->prepare("
SELECT COUNT(*) as total
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = ?
AND COLUMN_NAME = ?
");
$stmt->execute([$tabla, $columna]);
$cache[$key] = $stmt->fetch()['total'] > 0;
} catch (Exception $e) {
$cache[$key] = false;
}
}
return $cache[$key];
}
// ============================================================================
// FUNCION AUXILIAR: VERIFICAR SI EXISTE UNA TABLA
// ============================================================================
function tablaExiste($conn, $tabla) {
try {
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$stmt->execute([$tabla]);
return $stmt->fetch()['total'] > 0;
} catch (Exception $e) { return false; }
}
// ============================================================================
// FUNCION PARA OBTENER TODAS LAS ALERTAS DEL SISTEMA (Versión completa)
// ============================================================================
function obtenerAlertas($conn) {
$alertas = [];
$hoy = date('Y-m-d');
$proximos_30_dias = date('Y-m-d', strtotime('+30 days'));
// =========================================================================
// ==================== MODULO SUCURSALES ====================
// =========================================================================
// 4. Sucursales Pendientes de Aprobacion
try {
if (columnaExiste($conn, 'sucursales', 'estado_aprobacion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE (estado_aprobacion = 'pendiente' OR estado_aprobacion IS NULL)
AND en_funcionamiento = 1
AND activa = 1
");
$sucursales_pendientes = $stmt->fetch()['total'] ?? 0;
if ($sucursales_pendientes > 0) {
$alertas[] = [
'tipo' => 'sucursales_pendientes_aprobacion',
'prioridad' => 'alta',
'titulo' => 'Sucursales Pendientes de Aprobacion',
'descripcion' => "Hay {$sucursales_pendientes} sucursal(es) en funcionamiento pendientes de aprobacion administrativa",
'accion_url' => 'sucursales.php?filtro_aprobacion=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_pendientes_aprobacion: " . $e->getMessage());
}
// 5. Aranceles Vencidos (>380 dias)
try {
if (columnaExiste($conn, 'sucursales', 'fecha_pago_arancel')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE fecha_pago_arancel IS NOT NULL
AND fecha_pago_arancel < DATE_SUB(NOW(), INTERVAL 380 DAY)
AND activa = 1
");
$aranceles_vencidos = $stmt->fetch()['total'] ?? 0;
if ($aranceles_vencidos > 0) {
$alertas[] = [
'tipo' => 'aranceles_vencidos',
'prioridad' => 'alta',
'titulo' => 'Aranceles de Sucursales Vencidos',
'descripcion' => "Hay {$aranceles_vencidos} sucursal(es) con arancel vencido (mas de 380 dias sin pago)",
'accion_url' => 'sucursales.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - aranceles_vencidos: " . $e->getMessage());
}
// 6. Sucursales Rechazadas
try {
if (columnaExiste($conn, 'sucursales', 'estado_aprobacion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE estado_aprobacion = 'rechazado'
AND activa = 1
");
$sucursales_rechazadas = $stmt->fetch()['total'] ?? 0;
if ($sucursales_rechazadas > 0) {
$alertas[] = [
'tipo' => 'sucursales_rechazadas',
'prioridad' => 'alta',
'titulo' => 'Sucursales Rechazadas',
'descripcion' => "Hay {$sucursales_rechazadas} sucursal(es) rechazadas que requieren revision y correccion",
'accion_url' => 'sucursales.php?filtro_aprobacion=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_rechazadas: " . $e->getMessage());
}
// 7. Sucursales con Documentacion Incompleta
try {
if (columnaExiste($conn, 'sucursales', 'renar')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE (renar = 0 OR certificado_cumplimiento = 0 OR habilitacion_comercial = 0)
AND activa = 1
");
$sucursales_doc_incompleta = $stmt->fetch()['total'] ?? 0;
if ($sucursales_doc_incompleta > 0) {
$alertas[] = [
'tipo' => 'sucursales_documentacion_incompleta',
'prioridad' => 'media',
'titulo' => 'Sucursales con Documentacion Incompleta',
'descripcion' => "Hay {$sucursales_doc_incompleta} sucursal(es) activas con documentacion incompleta (RENAR, Certificado o Habilitacion)",
'accion_url' => 'sucursales.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_documentacion_incompleta: " . $e->getMessage());
}
// 8. Sucursales Sin PDF de Resolucion
try {
if (columnaExiste($conn, 'sucursales', 'pdf_resolucion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE (pdf_resolucion IS NULL OR pdf_resolucion = '')
AND activa = 1
");
$sucursales_sin_pdf = $stmt->fetch()['total'] ?? 0;
if ($sucursales_sin_pdf > 0) {
$alertas[] = [
'tipo' => 'sucursales_sin_pdf_resolucion',
'prioridad' => 'baja',
'titulo' => 'Sucursales Sin PDF de Resolucion',
'descripcion' => "Hay {$sucursales_sin_pdf} sucursal(es) activas sin PDF de resolucion cargado",
'accion_url' => 'sucursales.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_sin_pdf_resolucion: " . $e->getMessage());
}
// 9. Sucursales Activas Sin Informes Enviados
try {
if (columnaExiste($conn, 'sucursales', 'ultimos_informes_enviados')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE activa = 1
AND (ultimos_informes_enviados = 0 OR ultimos_informes_enviados IS NULL)
");
$sucursales_sin_informes = $stmt->fetch()['total'] ?? 0;
if ($sucursales_sin_informes > 0) {
$alertas[] = [
'tipo' => 'sucursales_sin_informes',
'prioridad' => 'media',
'titulo' => 'Sucursales Sin Informes Enviados',
'descripcion' => "Hay {$sucursales_sin_informes} sucursal(es) activa(s) que no han enviado sus informes requeridos",
'accion_url' => 'sucursales.php?filtro_activa=1'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_sin_informes: " . $e->getMessage());
}
// 10. Sucursales Activas Sin Director Tecnico
try {
if (columnaExiste($conn, 'sucursales', 'responsable_id') && columnaExiste($conn, 'personal', 'cargo')) {
$stmt = $conn->query("
SELECT COUNT(*) as total
FROM sucursales s
LEFT JOIN personal p ON s.responsable_id = p.id
WHERE s.activa = 1
AND (s.responsable_id IS NULL OR p.cargo != 'DIRECTOR TECNICO' OR p.activo = 0)
");
$sucursales_sin_director = $stmt->fetch()['total'] ?? 0;
if ($sucursales_sin_director > 0) {
$alertas[] = [
'tipo' => 'sucursales_sin_director_tecnico',
'prioridad' => 'alta',
'titulo' => 'Sucursales Sin Director Tecnico',
'descripcion' => "Hay {$sucursales_sin_director} sucursal(es) activa(s) sin Director Tecnico asignado o con responsable no valido",
'accion_url' => 'sucursales.php?filtro_activa=1'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_sin_director_tecnico: " . $e->getMessage());
}
// =========================================================================
// ==================== MODULO PERSONAL ====================
// =========================================================================
// 11. Documentacion Vencida
try {
if (columnaExiste($conn, 'personal', 'fecha_vencimiento')) {
$stmt = $conn->prepare("
SELECT p.id, p.nombre, p.apellido, p.dni, p.fecha_vencimiento,
e.nombre as empresa, s.nombre as sucursal
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.activo = TRUE
AND p.fecha_vencimiento IS NOT NULL
AND p.fecha_vencimiento <= ?
ORDER BY p.fecha_vencimiento ASC
LIMIT 10
");
$stmt->execute([$hoy]);
$documentacion_vencida = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($documentacion_vencida as $personal) {
$alertas[] = [
'tipo' => 'doc_vencida',
'prioridad' => 'alta',
'titulo' => 'Documentacion Vencida',
'descripcion' => "El personal {$personal['apellido']}, {$personal['nombre']} (DNI: {$personal['dni']}) tiene documentacion vencida desde " . date('d/m/Y', strtotime($personal['fecha_vencimiento'])),
'personal_id' => $personal['id'],
'empresa_nombre' => $personal['empresa'],
'sucursal_nombre' => $personal['sucursal']
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - doc_vencida: " . $e->getMessage());
}
// 12. Documentacion por Vencer (30 dias)
try {
if (columnaExiste($conn, 'personal', 'fecha_vencimiento')) {
$stmt = $conn->prepare("
SELECT p.id, p.nombre, p.apellido, p.dni, p.fecha_vencimiento,
e.nombre as empresa, s.nombre as sucursal
FROM personal p
LEFT JOIN empresas e ON p.empresa_id = e.id
LEFT JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.activo = TRUE
AND p.fecha_vencimiento IS NOT NULL
AND p.fecha_vencimiento BETWEEN ? AND ?
ORDER BY p.fecha_vencimiento ASC
LIMIT 10
");
$stmt->execute([$hoy, $proximos_30_dias]);
$documentacion_por_vencer = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($documentacion_por_vencer as $personal) {
$dias = (strtotime($personal['fecha_vencimiento']) - strtotime($hoy)) / 86400;
$alertas[] = [
'tipo' => 'doc_por_vencer',
'prioridad' => ($dias <= 7) ? 'alta' : 'media',
'titulo' => 'Documentacion por Vencer',
'descripcion' => "El personal {$personal['apellido']}, {$personal['nombre']} (DNI: {$personal['dni']}) vence en " . ceil($dias) . " dias (" . date('d/m/Y', strtotime($personal['fecha_vencimiento'])) . ")",
'personal_id' => $personal['id'],
'empresa_nombre' => $personal['empresa'],
'sucursal_nombre' => $personal['sucursal']
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - doc_por_vencer: " . $e->getMessage());
}
// 13. Personal Inactivo Sin Baja
try {
if (columnaExiste($conn, 'personal', 'activo') && columnaExiste($conn, 'personal', 'baja')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE activo = FALSE AND baja = FALSE
");
$personal_inactivo_sin_baja = $stmt->fetch()['total'] ?? 0;
if ($personal_inactivo_sin_baja > 0) {
$alertas[] = [
'tipo' => 'personal_inactivo_sin_baja',
'prioridad' => 'alta',
'titulo' => 'Personal Inactivo Sin Baja',
'descripcion' => "Hay {$personal_inactivo_sin_baja} registro(s) de personal inactivo que no han sido dados de baja formalmente",
'accion_url' => 'personal.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - personal_inactivo_sin_baja: " . $e->getMessage());
}
// 14. Documentacion Pendiente de Revision
try {
if (columnaExiste($conn, 'personal', 'estado_documentacion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE estado_documentacion = 'pendiente'
");
$documentacion_pendiente = $stmt->fetch()['total'] ?? 0;
if ($documentacion_pendiente > 0) {
$alertas[] = [
'tipo' => 'documentacion_pendiente_revision',
'prioridad' => 'alta',
'titulo' => 'Documentacion Pendiente de Revision',
'descripcion' => "Hay {$documentacion_pendiente} registro(s) de personal con documentacion pendiente de aprobacion/rechazo",
'accion_url' => 'personal.php?filtro_estado_documentacion=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentacion_pendiente_revision: " . $e->getMessage());
}
// 15. Revalidaciones Vencidas
try {
if (columnaExiste($conn, 'personal', 'fecha_revalidacion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE fecha_revalidacion IS NOT NULL
AND fecha_revalidacion < CURDATE()
AND activo = TRUE
");
$revalidaciones_vencidas = $stmt->fetch()['total'] ?? 0;
if ($revalidaciones_vencidas > 0) {
$alertas[] = [
'tipo' => 'revalidaciones_vencidas',
'prioridad' => 'alta',
'titulo' => 'Revalidaciones Vencidas',
'descripcion' => "Hay {$revalidaciones_vencidas} registro(s) de personal con revalidacion vencida",
'accion_url' => 'personal.php?filtro_revalidacion=vencido'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - revalidaciones_vencidas: " . $e->getMessage());
}
// 16. Revalidaciones por Vencer (30 dias)
try {
if (columnaExiste($conn, 'personal', 'fecha_revalidacion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE fecha_revalidacion IS NOT NULL
AND fecha_revalidacion BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
AND activo = TRUE
");
$revalidaciones_por_vencer = $stmt->fetch()['total'] ?? 0;
if ($revalidaciones_por_vencer > 0) {
$alertas[] = [
'tipo' => 'revalidaciones_por_vencer',
'prioridad' => 'media',
'titulo' => 'Revalidaciones por Vencer',
'descripcion' => "Hay {$revalidaciones_por_vencer} registro(s) de personal con revalidacion por vencer en los proximos 30 dias",
'accion_url' => 'personal.php?filtro_revalidacion=proximo'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - revalidaciones_por_vencer: " . $e->getMessage());
}
// 17. Credenciales Sin Pagar
try {
if (columnaExiste($conn, 'personal', 'pago_credencial')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE (pago_credencial = 0 OR pago_credencial IS NULL)
AND activo = TRUE
");
$credenciales_sin_pagar = $stmt->fetch()['total'] ?? 0;
if ($credenciales_sin_pagar > 0) {
$alertas[] = [
'tipo' => 'credenciales_sin_pagar',
'prioridad' => 'media',
'titulo' => 'Credenciales Sin Pagar',
'descripcion' => "Hay {$credenciales_sin_pagar} registro(s) de personal activo con credencial sin pagar",
'accion_url' => 'personal.php?filtro_credencial=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - credenciales_sin_pagar: " . $e->getMessage());
}
// 18. Personal Sin Foto Carnet
try {
if (columnaExiste($conn, 'personal', 'foto')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE (foto IS NULL OR foto = '')
AND activo = TRUE
");
$personal_sin_foto = $stmt->fetch()['total'] ?? 0;
if ($personal_sin_foto > 0) {
$alertas[] = [
'tipo' => 'personal_sin_foto',
'prioridad' => 'baja',
'titulo' => 'Personal Sin Foto Carnet',
'descripcion' => "Hay {$personal_sin_foto} registro(s) de personal activo sin foto de carnet cargada (285x354 px)",
'accion_url' => 'personal.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - personal_sin_foto: " . $e->getMessage());
}
// 19. Personal Sin PDF Datos Personales
try {
if (columnaExiste($conn, 'personal', 'pdf_datos_personales')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE (pdf_datos_personales IS NULL OR pdf_datos_personales = '')
AND activo = TRUE
");
$personal_sin_pdf = $stmt->fetch()['total'] ?? 0;
if ($personal_sin_pdf > 0) {
$alertas[] = [
'tipo' => 'personal_sin_pdf_datos',
'prioridad' => 'baja',
'titulo' => 'Personal Sin PDF de Datos',
'descripcion' => "Hay {$personal_sin_pdf} registro(s) de personal activo sin PDF de datos personales",
'accion_url' => 'personal.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - personal_sin_pdf_datos: " . $e->getMessage());
}
// 20. Personal Sin Cupon de Pago
try {
if (columnaExiste($conn, 'personal', 'cupon_pago_credencial')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM personal
WHERE (cupon_pago_credencial IS NULL OR cupon_pago_credencial = '')
AND activo = TRUE
AND pago_credencial = 1
");
$personal_sin_cupon = $stmt->fetch()['total'] ?? 0;
if ($personal_sin_cupon > 0) {
$alertas[] = [
'tipo' => 'personal_sin_cupon_pago',
'prioridad' => 'baja',
'titulo' => 'Personal Sin Cupon de Pago',
'descripcion' => "Hay {$personal_sin_cupon} registro(s) con credencial pagada pero sin cupon cargado",
'accion_url' => 'personal.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - personal_sin_cupon_pago: " . $e->getMessage());
}
// =========================================================================
// ==================== MODULO RECURSOS ====================
// =========================================================================
// 21. Recursos Pendientes de Aprobacion
try {
if (columnaExiste($conn, 'recursos_sucursal', 'estado')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM recursos_sucursal
WHERE estado = 'pendiente'
");
$recursos_pendientes = $stmt->fetch()['total'] ?? 0;
if ($recursos_pendientes > 0) {
$alertas[] = [
'tipo' => 'recursos_pendientes_aprobacion',
'prioridad' => 'alta',
'titulo' => 'Recursos Pendientes de Aprobacion',
'descripcion' => "Hay {$recursos_pendientes} solicitud(es) de recursos pendiente(s) de aprobacion",
'accion_url' => 'recursos.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_pendientes_aprobacion: " . $e->getMessage());
}
// 22. Recursos Rechazados
try {
if (columnaExiste($conn, 'recursos_sucursal', 'estado')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM recursos_sucursal
WHERE estado = 'rechazado'
");
$recursos_rechazados = $stmt->fetch()['total'] ?? 0;
if ($recursos_rechazados > 0) {
$alertas[] = [
'tipo' => 'recursos_rechazados',
'prioridad' => 'media',
'titulo' => 'Recursos Rechazados',
'descripcion' => "Hay {$recursos_rechazados} solicitud(es) de recursos rechazada(s) que requieren correccion",
'accion_url' => 'recursos.php?search_estado=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_rechazados: " . $e->getMessage());
}
// 23. Recursos con Items Vencidos
try {
if (columnaExiste($conn, 'recursos_items', 'atributos')) {
$stmt = $conn->prepare("
SELECT COUNT(DISTINCT rs.id) as total
FROM recursos_items ri
INNER JOIN recursos_sucursal rs ON ri.recursos_sucursal_id = rs.id
WHERE JSON_EXTRACT(ri.atributos, '$.\"Fecha de Vencimiento\"') IS NOT NULL
AND JSON_EXTRACT(ri.atributos, '$.\"Fecha de Vencimiento\"') <= ?
AND rs.estado = 'aprobado'
");
$stmt->execute([$hoy]);
$recursos_items_vencidos = $stmt->fetch()['total'] ?? 0;
if ($recursos_items_vencidos > 0) {
$alertas[] = [
'tipo' => 'recursos_items_vencidos',
'prioridad' => 'alta',
'titulo' => 'Recursos con Items Vencidos',
'descripcion' => "Hay {$recursos_items_vencidos} recurso(s) con items vencidos (chalecos, equipos, etc.)",
'accion_url' => 'recursos.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_items_vencidos: " . $e->getMessage());
}
// 24. Recursos Sin PDF Adjunto
try {
if (columnaExiste($conn, 'recursos_sucursal', 'archivo_pdf')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM recursos_sucursal
WHERE (archivo_pdf IS NULL OR archivo_pdf = '')
AND estado = 'aprobado'
");
$recursos_sin_pdf = $stmt->fetch()['total'] ?? 0;
if ($recursos_sin_pdf > 0) {
$alertas[] = [
'tipo' => 'recursos_sin_pdf',
'prioridad' => 'baja',
'titulo' => 'Recursos Sin PDF Adjunto',
'descripcion' => "Hay {$recursos_sin_pdf} recurso(s) aprobados sin documentacion PDF cargada",
'accion_url' => 'recursos.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_sin_pdf: " . $e->getMessage());
}
// 25. Recursos por Vencer (30 dias)
try {
if (columnaExiste($conn, 'recursos_items', 'atributos')) {
$stmt = $conn->prepare("
SELECT COUNT(DISTINCT rs.id) as total
FROM recursos_items ri
INNER JOIN recursos_sucursal rs ON ri.recursos_sucursal_id = rs.id
WHERE JSON_EXTRACT(ri.atributos, '$.\"Fecha de Vencimiento\"') IS NOT NULL
AND JSON_EXTRACT(ri.atributos, '$.\"Fecha de Vencimiento\"') BETWEEN ? AND ?
AND rs.estado = 'aprobado'
");
$stmt->execute([$hoy, $proximos_30_dias]);
$recursos_por_vencer = $stmt->fetch()['total'] ?? 0;
if ($recursos_por_vencer > 0) {
$alertas[] = [
'tipo' => 'recursos_por_vencer',
'prioridad' => 'media',
'titulo' => 'Recursos por Vencer',
'descripcion' => "Hay {$recursos_por_vencer} recurso(s) con items por vencer en los proximos 30 dias",
'accion_url' => 'recursos.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_por_vencer: " . $e->getMessage());
}
// =========================================================================
// ==================== MODULO SERVICIOS ====================
// =========================================================================
// 26. Servicios Pendientes de Aprobacion
try {
if (columnaExiste($conn, 'servicios', 'estado')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM servicios
WHERE estado = 'pendiente'
");
$servicios_pendientes = $stmt->fetch()['total'] ?? 0;
if ($servicios_pendientes > 0) {
$alertas[] = [
'tipo' => 'servicios_pendientes_aprobacion',
'prioridad' => 'alta',
'titulo' => 'Servicios Pendientes de Aprobacion',
'descripcion' => "Hay {$servicios_pendientes} servicio(s) pendiente(s) de aprobacion",
'accion_url' => 'servicios.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_pendientes_aprobacion: " . $e->getMessage());
}
// 27. Servicios con Sanciones
try {
if (columnaExiste($conn, 'servicios', 'sancion_tipo')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM servicios
WHERE sancion_tipo IS NOT NULL AND sancion_tipo != ''
");
$servicios_con_sanciones = $stmt->fetch()['total'] ?? 0;
if ($servicios_con_sanciones > 0) {
$alertas[] = [
'tipo' => 'servicios_con_sanciones',
'prioridad' => 'media',
'titulo' => 'Servicios con Sanciones',
'descripcion' => "Hay {$servicios_con_sanciones} servicio(s) con sanciones registradas",
'accion_url' => 'servicios.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_con_sanciones: " . $e->getMessage());
}
// 28. Servicios Sin PDF
try {
if (columnaExiste($conn, 'servicios', 'pdf_file')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM servicios
WHERE (pdf_file IS NULL OR pdf_file = '')
AND estado = 'activo'
");
$servicios_sin_pdf = $stmt->fetch()['total'] ?? 0;
if ($servicios_sin_pdf > 0) {
$alertas[] = [
'tipo' => 'servicios_sin_pdf',
'prioridad' => 'baja',
'titulo' => 'Servicios Sin PDF',
'descripcion' => "Hay {$servicios_sin_pdf} servicio(s) activos sin documentacion PDF cargada",
'accion_url' => 'servicios.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_sin_pdf: " . $e->getMessage());
}
// 29. Servicios Vencidos
try {
if (columnaExiste($conn, 'servicios', 'fecha_fin')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM servicios
WHERE fecha_fin IS NOT NULL
AND fecha_fin < CURDATE()
AND estado = 'activo'
");
$servicios_vencidos = $stmt->fetch()['total'] ?? 0;
if ($servicios_vencidos > 0) {
$alertas[] = [
'tipo' => 'servicios_vencidos',
'prioridad' => 'alta',
'titulo' => 'Servicios Vencidos',
'descripcion' => "Hay {$servicios_vencidos} servicio(s) activos con fecha de fin vencida",
'accion_url' => 'servicios.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_vencidos: " . $e->getMessage());
}
// 30. Servicios Sin Personal Asignado
try {
if (columnaExiste($conn, 'servicios', 'personal_id')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM servicios
WHERE personal_id IS NULL
AND estado = 'activo'
");
$servicios_sin_personal = $stmt->fetch()['total'] ?? 0;
if ($servicios_sin_personal > 0) {
$alertas[] = [
'tipo' => 'servicios_sin_personal_asignado',
'prioridad' => 'media',
'titulo' => 'Servicios Sin Personal Asignado',
'descripcion' => "Hay {$servicios_sin_personal} servicio(s) activos sin personal asignado",
'accion_url' => 'servicios.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_sin_personal_asignado: " . $e->getMessage());
}
// =========================================================================
// ==================== MODULO INSPECCIONES ====================
// =========================================================================
// 31. Inspecciones Pendientes
try {
if (columnaExiste($conn, 'inspecciones', 'estado')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM inspecciones
WHERE estado = 'pendiente'
");
$inspecciones_pendientes = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_pendientes > 0) {
$alertas[] = [
'tipo' => 'inspecciones_pendientes',
'prioridad' => 'media',
'titulo' => 'Inspecciones Pendientes',
'descripcion' => "Hay {$inspecciones_pendientes} inspeccion(es) pendiente(s) de finalizacion",
'accion_url' => 'inspecciones.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_pendientes: " . $e->getMessage());
}
// 32. Inspecciones con Observaciones
try {
if (columnaExiste($conn, 'inspecciones', 'irregularidades')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM inspecciones
WHERE (irregularidades IS NOT NULL AND irregularidades != '')
AND estado != 'cerrada'
");
$inspecciones_con_observaciones = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_con_observaciones > 0) {
$alertas[] = [
'tipo' => 'inspecciones_con_observaciones',
'prioridad' => 'media',
'titulo' => 'Inspecciones con Observaciones',
'descripcion' => "Hay {$inspecciones_con_observaciones} inspeccion(es) con irregularidades registradas",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_con_observaciones: " . $e->getMessage());
}
// 33. Inspecciones con Sanciones
try {
if (columnaExiste($conn, 'inspecciones', 'expediente_id')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM inspecciones
WHERE expediente_id IS NOT NULL
");
$inspecciones_con_sanciones = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_con_sanciones > 0) {
$alertas[] = [
'tipo' => 'inspecciones_con_sanciones',
'prioridad' => 'alta',
'titulo' => 'Inspecciones con Sanciones',
'descripcion' => "Hay {$inspecciones_con_sanciones} inspeccion(es) con sumario/sancion vinculada",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_con_sanciones: " . $e->getMessage());
}
// 34. Inspecciones Sin Sumario Vinculado
try {
if (columnaExiste($conn, 'inspecciones', 'expediente_id')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM inspecciones
WHERE expediente_id IS NULL
AND (irregularidades IS NOT NULL AND irregularidades != '')
AND estado != 'cerrada'
");
$inspecciones_sin_sumario = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_sin_sumario > 0) {
$alertas[] = [
'tipo' => 'inspecciones_sin_sumario',
'prioridad' => 'media',
'titulo' => 'Inspecciones Sin Sumario',
'descripcion' => "Hay {$inspecciones_sin_sumario} inspeccion(es) con irregularidades pero sin sumario vinculado",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_sin_sumario: " . $e->getMessage());
}
// =========================================================================
// ==================== ?? MODULO DOCUMENTOS EMPRESAS (NUEVO) ====================
// =========================================================================
// 35. Documentos Pendientes de Revision
try {
if (columnaExiste($conn, 'documentos_sucursales', 'estado')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM documentos_sucursales
WHERE estado = 'pendiente'
");
$documentos_pendientes = $stmt->fetch()['total'] ?? 0;
if ($documentos_pendientes > 0) {
$alertas[] = [
'tipo' => 'documentos_pendientes_revision',
'prioridad' => 'alta',
'titulo' => 'Documentos Pendientes de Revision',
'descripcion' => "Hay {$documentos_pendientes} documento(s) de empresa(s) pendiente(s) de aprobacion/rechazo",
'accion_url' => 'documentos_empresas.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentos_pendientes_revision: " . $e->getMessage());
}
// 36. Documentos Rechazados
try {
if (columnaExiste($conn, 'documentos_sucursales', 'estado')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM documentos_sucursales
WHERE estado = 'rechazado'
AND fecha_revision >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$documentos_rechazados = $stmt->fetch()['total'] ?? 0;
if ($documentos_rechazados > 0) {
$alertas[] = [
'tipo' => 'documentos_rechazados',
'prioridad' => 'media',
'titulo' => 'Documentos Rechazados',
'descripcion' => "Hay {$documentos_rechazados} documento(s) rechazado(s) en los ultimos 7 dias que requieren correccion",
'accion_url' => 'documentos_empresas.php?search_estado=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentos_rechazados: " . $e->getMessage());
}
// 37. Documentos Sin Observaciones (Rechazados sin motivacion)
try {
if (columnaExiste($conn, 'documentos_sucursales', 'motivacion_rechazo')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM documentos_sucursales
WHERE estado = 'rechazado'
AND (motivacion_rechazo IS NULL OR motivacion_rechazo = '')
");
$documentos_sin_motivacion = $stmt->fetch()['total'] ?? 0;
if ($documentos_sin_motivacion > 0) {
$alertas[] = [
'tipo' => 'documentos_sin_observaciones',
'prioridad' => 'baja',
'titulo' => 'Documentos Rechazados Sin Motivacion',
'descripcion' => "Hay {$documentos_sin_motivacion} documento(s) rechazado(s) sin motivacion registrada",
'accion_url' => 'documentos_empresas.php?search_estado=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentos_sin_observaciones: " . $e->getMessage());
}
// =========================================================================
// ==================== MODULO INFORMES (EXISTENTE) ====================
// =========================================================================
// 38. Informes Mensuales Pendientes
try {
if (columnaExiste($conn, 'informes_mensuales', 'empresa_id')) {
$stmt = $conn->prepare("
SELECT e.id, e.nombre, COUNT(im.id) as informes_cargados
FROM empresas e
LEFT JOIN informes_mensuales im
ON e.id = im.empresa_id
AND im.mes = ?
AND im.anio = ?
WHERE e.activo = TRUE
GROUP BY e.id, e.nombre
HAVING COUNT(im.id) = 0
ORDER BY e.nombre
");
$stmt->execute([date('m'), date('Y')]);
$empresas_sin_informe = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($empresas_sin_informe as $empresa) {
$alertas[] = [
'tipo' => 'informe_pendiente',
'prioridad' => 'alta',
'titulo' => 'Informe Mensual Pendiente',
'descripcion' => "La empresa '{$empresa['nombre']}' no ha presentado el informe mensual de " . date('F Y'),
'empresa_id' => $empresa['id'],
'empresa_nombre' => $empresa['nombre'],
'accion_url' => 'informes_mensuales.php?modo=generar_pdf_form&empresa_id=' . $empresa['id']
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - informe_pendiente: " . $e->getMessage());
}
// 39. Multas Pendientes
try {
if (columnaExiste($conn, 'multas', 'estado')) {
$stmt = $conn->query("SELECT COUNT(*) as total FROM multas WHERE estado = 'pendiente'");
$multas_pendientes = $stmt->fetch()['total'] ?? 0;
if ($multas_pendientes > 0) {
$alertas[] = [
'tipo' => 'multas_pendientes',
'prioridad' => 'media',
'titulo' => 'Multas Pendientes de Pago',
'descripcion' => "Hay {$multas_pendientes} multa(s) pendientes de pago en el sistema",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - multas_pendientes: " . $e->getMessage());
}
// =========================================================================
// ==================== MODULO INSPECCIONES PROGRAMADAS (Dashboard específico) ====================
// =========================================================================
// Inspecciones Programadas para Hoy
try {
if (tablaExiste($conn, 'inspecciones_programadas')) {
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada = CURDATE() AND estado = 'pendiente'");
$inspecciones_programadas_hoy = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_programadas_hoy > 0) {
$alertas[] = [
'tipo' => 'inspecciones_programadas_hoy',
'prioridad' => 'alta',
'titulo' => 'Inspecciones Programadas para Hoy',
'descripcion' => "Hay {$inspecciones_programadas_hoy} inspeccion(es) programada(s) para hoy",
'accion_url' => 'inspecciones_programadas.php?filtro_fecha_desde=' . $hoy . '&filtro_fecha_hasta=' . $hoy
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_programadas_hoy: " . $e->getMessage());
}
// Inspecciones Programadas Vencidas
try {
if (tablaExiste($conn, 'inspecciones_programadas')) {
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada < CURDATE() AND estado = 'pendiente'");
$inspecciones_programadas_vencidas = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_programadas_vencidas > 0) {
$alertas[] = [
'tipo' => 'inspecciones_programadas_vencidas',
'prioridad' => 'alta',
'titulo' => 'Inspecciones Programadas Vencidas',
'descripcion' => "Hay {$inspecciones_programadas_vencidas} inspeccion(es) programada(s) vencida(s)",
'accion_url' => 'inspecciones_programadas.php?filtro_estado=vencida'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_programadas_vencidas: " . $e->getMessage());
}
// Inspecciones Programadas Próximas
try {
if (tablaExiste($conn, 'inspecciones_programadas')) {
$stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada > CURDATE() AND estado = 'pendiente'");
$inspecciones_programadas_pendientes = $stmt->fetch()['total'] ?? 0;
if ($inspecciones_programadas_pendientes > 0) {
$alertas[] = [
'tipo' => 'inspecciones_programadas_pendientes',
'prioridad' => 'media',
'titulo' => 'Inspecciones Programadas Próximas',
'descripcion' => "Hay {$inspecciones_programadas_pendientes} inspeccion(es) programada(s) próximas",
'accion_url' => 'inspecciones_programadas.php?filtro_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_programadas_pendientes: " . $e->getMessage());
}
// =========================================================================
// ORDENAR POR PRIORIDAD (ALTA PRIMERO)
// =========================================================================
usort($alertas, function($a, $b) {
$prioridad = ['alta' => 3, 'media' => 2, 'baja' => 1];
return $prioridad[$b['prioridad']] - $prioridad[$a['prioridad']];
});
return $alertas;
}
// ==================== ESTADÍSTICAS GENERALES ====================
$stats = [
'total_empresas' => 0, 'empresas_activas' => 0,
'total_personal' => 0, 'personal_activo' => 0,
'total_usuarios' => 0, 'usuarios_activos' => 0,
'alertas_pendientes' => 0, 'auditoria_hoy' => 0,
'inspecciones_programadas_total' => 0, 'inspecciones_programadas_pendientes' => 0,
'inspecciones_programadas_realizadas' => 0, 'inspecciones_programadas_hoy' => 0
];
try {
$stats['total_empresas'] = $conn->query("SELECT COUNT(*) as total FROM empresas")->fetch()['total'];
$stats['empresas_activas'] = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE activo = TRUE")->fetch()['total'];
$stats['total_personal'] = $conn->query("SELECT COUNT(*) as total FROM personal")->fetch()['total'];
$stats['personal_activo'] = $conn->query("SELECT COUNT(*) as total FROM personal WHERE activo = TRUE")->fetch()['total'];
$stats['total_usuarios'] = $conn->query("SELECT COUNT(*) as total FROM usuarios")->fetch()['total'];
$stats['usuarios_activos'] = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE activo = TRUE")->fetch()['total'];
if (tablaExiste($conn, 'inspecciones_programadas')) {
$stats['inspecciones_programadas_total'] = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas")->fetch()['total'];
$stats['inspecciones_programadas_pendientes'] = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE estado = 'pendiente'")->fetch()['total'];
$stats['inspecciones_programadas_realizadas'] = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE estado = 'realizada'")->fetch()['total'];
$stats['inspecciones_programadas_hoy'] = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada = CURDATE() AND estado = 'pendiente'")->fetch()['total'];
}
} catch (PDOException $e) {
$error = "Error de carga: " . htmlspecialchars($e->getMessage());
error_log("Dashboard Stats Error: " . $e->getMessage());
}
$alertas = obtenerAlertas($conn);
$total_alertas = count($alertas);
$alertas_por_modulo = [
'sucursales' => 0, 'personal' => 0, 'recursos' => 0,
'servicios' => 0, 'inspecciones' => 0, 'inspecciones_programadas' => 0,
'documentos' => 0, 'otros' => 0
];
foreach ($alertas as $alerta) {
$modulo = obtenerModuloAlerta($alerta['tipo']);
if (isset($alertas_por_modulo[$modulo])) $alertas_por_modulo[$modulo]++;
}
try {
logAuditoria($conn, 'dashboard_visualizado', 'dashboard', null, ['usuario' => $user['username']]);
} catch (Exception $e) {
error_log("Auditoría Log Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<meta name="theme-color" content="#4361ee">
<title>Dashboard Administrador - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<script src="../css/chart.js"></script>
<style>
:root { --primary-color: #4361ee; --secondary-color: #3a0ca3; --sidebar-width: 280px; --header-height: 80px; --header-height-mobile: 65px; }
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { padding-top: var(--header-height); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%); min-height: 100vh; overflow-x: hidden; }
.header-modern { background: linear-gradient(120deg, #1a2a6c, #2c3e50, #4a6491); color: white; padding: 0; box-shadow: 0 4px 25px rgba(0, 0, 0, 0.35); position: fixed; top: 0; left: 0; width: 100%; z-index: 1100; border-bottom: 3px solid #4361ee; }
.dashboard { display: flex; min-height: calc(100vh - var(--header-height)); padding-top: 20px; }
.main-content { padding: 30px 40px 40px 40px !important; margin-left: var(--sidebar-width) !important; width: calc(100% - var(--sidebar-width)); transition: margin-left 0.35s ease; }
.alertas-section { background: white; border-radius: 24px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); border: 1px solid rgba(0, 0, 0, 0.05); margin-bottom: 30px; overflow: hidden; }
.alertas-header { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; padding: 20px 30px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s ease; }
.alertas-header:hover { background: linear-gradient(135deg, #c0392b, #a93226); }
.alertas-header .alertas-title { display: flex; align-items: center; gap: 15px; }
.alertas-header .alertas-title h2 { font-weight: 800; font-size: 1.5rem; margin: 0; }
.alertas-header .alertas-badge { background: rgba(255, 255, 255, 0.2); padding: 5px 15px; border-radius: 20px; font-weight: 700; font-size: 0.9rem; }
.alertas-header .toggle-icon { font-size: 1.5rem; transition: transform 0.3s ease; }
.alertas-header.collapsed .toggle-icon { transform: rotate(-90deg); }
.alertas-content { max-height: 0; overflow: hidden; transition: max-height 0.5s ease; }
.alertas-content.expanded { max-height: 2000px; }
.alertas-filters { padding: 20px 30px; background: #f8f9fa; border-bottom: 1px solid #e9ecef; display: flex; gap: 10px; flex-wrap: wrap; }
.alertas-filters .filter-btn { padding: 10px 20px; border: 2px solid #e9ecef; background: white; border-radius: 25px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; }
.alertas-filters .filter-btn:hover { border-color: #4361ee; background: #ebf4ff; }
.alertas-filters .filter-btn.active { background: #4361ee; border-color: #4361ee; color: white; }
.alertas-filters .filter-btn .badge-count { background: rgba(0, 0, 0, 0.2); padding: 2px 8px; border-radius: 10px; font-size: 0.8rem; }
.alertas-list { padding: 20px 30px; }
.alerta-item { background: #f8f9fa; border-radius: 16px; padding: 20px; margin-bottom: 15px; border-left: 4px solid #3498db; transition: all 0.3s ease; display: none; }
.alerta-item.show { display: block; animation: fadeIn 0.5s ease; }
.alerta-item:hover { transform: translateX(5px); box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); }
.alerta-item.alta { border-left-color: #e74c3c; }
.alerta-item.media { border-left-color: #f39c12; }
.alerta-item.baja { border-left-color: #3498db; }
.alerta-item .alerta-header { display: flex; align-items: center; gap: 15px; margin-bottom: 10px; }
.alerta-item .alerta-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: white; }
.alerta-item .alerta-title { font-weight: 700; font-size: 1.1rem; color: #2c3e50; flex: 1; }
.alerta-item .alerta-priority { padding: 4px 12px; border-radius: 15px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; }
.alerta-item .alerta-priority.alta { background: #e74c3c; color: white; }
.alerta-item .alerta-priority.media { background: #f39c12; color: white; }
.alerta-item .alerta-priority.baja { background: #3498db; color: white; }
.alerta-item .alerta-description { color: #555; margin-bottom: 15px; line-height: 1.5; }
.alerta-item .alerta-action { display: inline-block; padding: 8px 20px; background: #4361ee; color: white; border-radius: 10px; text-decoration: none; font-weight: 600; transition: all 0.3s ease; }
.alerta-item .alerta-action:hover { background: #3a0ca3; transform: translateY(-2px); }
.alertas-empty { text-align: center; padding: 40px 20px; color: #95a5a6; }
.alertas-empty i { font-size: 4rem; margin-bottom: 15px; opacity: 0.5; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.stats-container-modern { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; margin: 30px 0 40px; }
.stat-card-modern { background: white; border-radius: 24px; padding: 28px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); border: 1px solid rgba(0, 0, 0, 0.05); transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; overflow: hidden; }
.stat-card-modern::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; background: linear-gradient(135deg, #4361ee, #3a0ca3); }
.stat-card-modern:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18); }
.stat-card-modern .stat-icon { width: 70px; height: 70px; border-radius: 22px; display: flex; align-items: center; justify-content: center; font-size: 1.9rem; color: white; margin-bottom: 20px; }
.stat-card-modern .stat-value { font-size: 2.8rem; font-weight: 800; margin: 10px 0; color: #2c3e50; }
.stat-card-modern .stat-label { font-size: 1.1rem; font-weight: 700; color: #2c3e50; }
.stat-card-modern .stat-trend { font-size: 0.9rem; margin-top: 10px; display: flex; align-items: center; gap: 5px; }
.stat-card-modern .stat-trend.up { color: #10b981; }
.stat-card-modern .stat-trend.down { color: #ef4444; }
.stat-card-1 .stat-icon { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
.stat-card-2 .stat-icon { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
.stat-card-3 .stat-icon { background: linear-gradient(135deg, #f72585, #b5179e); }
.stat-card-4 .stat-icon { background: linear-gradient(135deg, #7209b7, #560bad); }
.stat-card-5 .stat-icon { background: linear-gradient(135deg, #10b981, #059669); }
.stat-card-6 .stat-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
.stat-card-7 .stat-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
.stat-card-8 .stat-icon { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.section-title-modern { font-weight: 800; font-size: 1.5rem; color: #2c3e50; display: flex; align-items: center; gap: 12px; margin: 40px 0 25px; }
.section-title-modern i { color: #4361ee; width: 45px; height: 45px; background: linear-gradient(135deg, #ebf4ff, #e6f0ff); border-radius: 14px; display: flex; align-items: center; justify-content: center; }
.quick-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
.quick-action-card { background: white; border-radius: 20px; padding: 25px; text-align: center; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); transition: all 0.3s ease; text-decoration: none; color: inherit; border: 2px solid transparent; }
.quick-action-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(67, 97, 238, 0.2); border-color: #4361ee; }
.quick-action-card .icon { width: 70px; height: 70px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: white; margin: 0 auto 15px; }
.quick-action-card .label { font-weight: 700; font-size: 1rem; color: #2c3e50; }
.quick-action-1 .icon { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
.quick-action-2 .icon { background: linear-gradient(135deg, #10b981, #059669); }
.quick-action-3 .icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
.quick-action-4 .icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
.quick-action-5 .icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
.quick-action-6 .icon { background: linear-gradient(135deg, #06b6d4, #0891b2); }
.quick-action-7 .icon { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.quick-action-8 .icon { background: linear-gradient(135deg, #6366f1, #4f46e5); }
@media (max-width: 991px) { :root { --sidebar-width: 0px; } .main-content { margin-left: 0 !important; width: 100%; padding: 20px 25px !important; } .stats-container-modern { grid-template-columns: repeat(2, 1fr); gap: 15px; } .alertas-filters { flex-direction: column; } .alertas-filters .filter-btn { width: 100%; justify-content: center; } }
@media (max-width: 767px) { body { padding-top: var(--header-height-mobile); } :root { --header-height: var(--header-height-mobile); } .stats-container-modern { grid-template-columns: 1fr; } .quick-actions { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 575px) { .quick-actions { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php $page_title = 'Dashboard Administrador'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<?php echo $error; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<div class="alertas-section">
<div class="alertas-header" id="alertasHeader" onclick="toggleAlertas()">
<div class="alertas-title">
<i class="fas fa-bell fa-2x"></i>
<div>
<h2>Alertas del Sistema</h2>
<div class="alertas-badge"><i class="fas fa-exclamation-triangle"></i> <?php echo $total_alertas; ?> alertas pendientes</div>
</div>
</div>
<i class="fas fa-chevron-down toggle-icon" id="toggleIcon"></i>
</div>
<div class="alertas-content" id="alertasContent">
<div class="alertas-filters">
<button class="filter-btn active" data-filter="all" onclick="filterAlertas('all')"><i class="fas fa-list"></i> Todas <span class="badge-count"><?php echo $total_alertas; ?></span></button>
<button class="filter-btn" data-filter="sucursales" onclick="filterAlertas('sucursales')"><i class="fas fa-store"></i> Sucursales <span class="badge-count"><?php echo $alertas_por_modulo['sucursales']; ?></span></button>
<button class="filter-btn" data-filter="personal" onclick="filterAlertas('personal')"><i class="fas fa-users"></i> Personal <span class="badge-count"><?php echo $alertas_por_modulo['personal']; ?></span></button>
<button class="filter-btn" data-filter="recursos" onclick="filterAlertas('recursos')"><i class="fas fa-boxes"></i> Recursos <span class="badge-count"><?php echo $alertas_por_modulo['recursos']; ?></span></button>
<button class="filter-btn" data-filter="servicios" onclick="filterAlertas('servicios')"><i class="fas fa-concierge-bell"></i> Servicios <span class="badge-count"><?php echo $alertas_por_modulo['servicios']; ?></span></button>
<button class="filter-btn" data-filter="inspecciones" onclick="filterAlertas('inspecciones')"><i class="fas fa-clipboard-check"></i> Inspecciones <span class="badge-count"><?php echo $alertas_por_modulo['inspecciones']; ?></span></button>
<button class="filter-btn" data-filter="inspecciones_programadas" onclick="filterAlertas('inspecciones_programadas')"><i class="fas fa-calendar-check"></i> Programadas <span class="badge-count"><?php echo $alertas_por_modulo['inspecciones_programadas']; ?></span></button>
<button class="filter-btn" data-filter="documentos" onclick="filterAlertas('documentos')"><i class="fas fa-file-contract"></i> Documentos <span class="badge-count"><?php echo $alertas_por_modulo['documentos']; ?></span></button>
<button class="filter-btn" data-filter="otros" onclick="filterAlertas('otros')"><i class="fas fa-folder"></i> Otros <span class="badge-count"><?php echo $alertas_por_modulo['otros']; ?></span></button>
</div>
<div class="alertas-list" id="alertasList">
<?php if ($total_alertas > 0): ?>
<?php foreach ($alertas as $alerta):
$modulo = obtenerModuloAlerta($alerta['tipo']);
$icono = obtenerIconoAlerta($alerta['tipo']);
$color = obtenerColorPrioridad($alerta['prioridad']);
?>
<div class="alerta-item <?php echo $alerta['prioridad']; ?>" data-module="<?php echo $modulo; ?>" style="display: none;">
<div class="alerta-header">
<div class="alerta-icon" style="background: <?php echo $color; ?>;"><i class="fas fa-<?php echo $icono; ?>"></i></div>
<div class="alerta-title"><?php echo htmlspecialchars($alerta['titulo'], ENT_QUOTES, 'UTF-8'); ?></div>
<span class="alerta-priority <?php echo $alerta['prioridad']; ?>"><?php echo $alerta['prioridad']; ?></span>
</div>
<div class="alerta-description"><i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($alerta['descripcion'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php if (isset($alerta['empresa_nombre'])): ?>
<div style="font-size: 0.9rem; color: #666; margin: 5px 0;">
<i class="fas fa-building"></i> <?php echo htmlspecialchars($alerta['empresa_nombre']); ?>
<?php if (isset($alerta['sucursal_nombre'])): ?>
<i class="fas fa-store ms-2"></i> <?php echo htmlspecialchars($alerta['sucursal_nombre']); ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if (isset($alerta['accion_url'])): ?>
<a href="<?php echo htmlspecialchars($alerta['accion_url'], ENT_QUOTES, 'UTF-8'); ?>" class="alerta-action"><i class="fas fa-arrow-right me-1"></i> Resolver Ahora</a>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="alertas-empty"><i class="fas fa-check-circle"></i><h3>¡Excelente! No hay alertas pendientes</h3><p>El sistema no ha detectado ninguna situación que requiera atención inmediata.</p></div>
<?php endif; ?>
</div>
</div>
</div>
<div class="stats-container-modern">
<div class="stat-card-modern stat-card-1"><div class="stat-icon"><i class="fas fa-building"></i></div><div class="stat-value"><?php echo $stats['total_empresas']; ?></div><div class="stat-label">Empresas Totales</div><div class="stat-trend up"><i class="fas fa-arrow-up"></i><span><?php echo $stats['empresas_activas']; ?> activas</span></div></div>
<div class="stat-card-modern stat-card-2"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-value"><?php echo $stats['total_personal']; ?></div><div class="stat-label">Personal Registrado</div><div class="stat-trend up"><i class="fas fa-arrow-up"></i><span><?php echo $stats['personal_activo']; ?> activos</span></div></div>
<div class="stat-card-modern stat-card-3"><div class="stat-icon"><i class="fas fa-user-shield"></i></div><div class="stat-value"><?php echo $stats['total_usuarios']; ?></div><div class="stat-label">Usuarios Sistema</div><div class="stat-trend up"><i class="fas fa-arrow-up"></i><span><?php echo $stats['usuarios_activos']; ?> activos</span></div></div>
<div class="stat-card-modern stat-card-4"><div class="stat-icon"><i class="fas fa-bell"></i></div><div class="stat-value"><?php echo $total_alertas; ?></div><div class="stat-label">Alertas Totales</div><div class="stat-trend <?php echo $total_alertas > 0 ? 'down' : 'up'; ?>"><i class="fas fa-<?php echo $total_alertas > 0 ? 'exclamation-circle' : 'check-circle'; ?>"></i><span><?php echo $total_alertas > 0 ? 'Requieren atención' : 'Sin alertas'; ?></span></div></div>
<?php if ($stats['inspecciones_programadas_total'] > 0): ?>
<div class="stat-card-modern stat-card-7"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div><div class="stat-value"><?php echo $stats['inspecciones_programadas_hoy']; ?></div><div class="stat-label">Inspecciones Hoy</div><div class="stat-trend <?php echo $stats['inspecciones_programadas_hoy'] > 0 ? 'down' : 'up'; ?>"><i class="fas fa-<?php echo $stats['inspecciones_programadas_hoy'] > 0 ? 'calendar-check' : 'check-circle'; ?>"></i><span><?php echo $stats['inspecciones_programadas_pendientes']; ?> pendientes</span></div></div>
<?php endif; ?>
</div>
<h2 class="section-title-modern"><i class="fas fa-bolt"></i><span>Accesos Rápidos</span></h2>
<div class="quick-actions">
<a href="empresas.php" class="quick-action-card quick-action-1"><div class="icon"><i class="fas fa-building"></i></div><div class="label">Gestionar Empresas</div></a>
<a href="personal.php" class="quick-action-card quick-action-2"><div class="icon"><i class="fas fa-users"></i></div><div class="label">Gestionar Personal</div></a>
<a href="sucursales.php" class="quick-action-card quick-action-3"><div class="icon"><i class="fas fa-store"></i></div><div class="label">Gestionar Sucursales</div></a>
<a href="inspecciones.php" class="quick-action-card quick-action-4"><div class="icon"><i class="fas fa-clipboard-check"></i></div><div class="label">Inspecciones</div></a>
<a href="inspecciones_programadas.php" class="quick-action-card quick-action-7"><div class="icon"><i class="fas fa-calendar-check"></i></div><div class="label">Inspecciones Programadas</div></a>
<a href="calendario_vencimientos.php" class="quick-action-card quick-action-8"><div class="icon"><i class="fas fa-calendar-alt"></i></div><div class="label">Calendario de Vencimientos</div></a>
<a href="documentos_empresas.php" class="quick-action-card quick-action-5"><div class="icon"><i class="fas fa-file-contract"></i></div><div class="label">Documentos Empresas</div></a>
<a href="auditoria.php" class="quick-action-card quick-action-6"><div class="icon"><i class="fas fa-clipboard-list"></i></div><div class="label">Auditoría</div></a>
</div>
</div>
</div>
<script src="../css/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.alert').forEach(alert => { setTimeout(() => new bootstrap.Alert(alert).close(), 5000); });
function toggleAlertas() {
const header = document.getElementById('alertasHeader'), content = document.getElementById('alertasContent'), icon = document.getElementById('toggleIcon');
header.classList.toggle('collapsed'); content.classList.toggle('expanded');
icon.style.transform = content.classList.contains('expanded') ? 'rotate(0deg)' : 'rotate(-90deg)';
}
function filterAlertas(filter) {
document.querySelectorAll('.alertas-filters .filter-btn').forEach(btn => btn.classList.remove('active'));
document.querySelector(`.filter-btn[data-filter="${filter}"]`).classList.add('active');
document.querySelectorAll('.alerta-item').forEach(item => {
if (filter === 'all' || item.dataset.module === filter) { item.style.display = 'block'; item.classList.add('show'); }
else { item.style.display = 'none'; item.classList.remove('show'); }
});
}
// Mostrar automáticamente todas las alertas al cargar la página
document.addEventListener('DOMContentLoaded', function() {
// Mostrar todas las alertas por defecto
document.querySelectorAll('.alerta-item').forEach(item => {
item.style.display = 'block';
item.classList.add('show');
});
// Expandir la sección de alertas automáticamente
const content = document.getElementById('alertasContent');
const icon = document.getElementById('toggleIcon');
const header = document.getElementById('alertasHeader');
if (content && !content.classList.contains('expanded')) {
content.classList.add('expanded');
header.classList.remove('collapsed');
if (icon) icon.style.transform = 'rotate(0deg)';
}
});
</script>
</body>
</html>