<?php
require_once '../config/database.php';
require_once '../config/auth.php';
if (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('auditor')) {
    $_SESSION['error'] = 'Acceso denegado. Se requieren permisos de administrador o auditor.';
    header('Location: ../index.php');
    exit;
}
$user = $auth->getCurrentUser();
$conn = getDBConnection();
// ============================================================================
// FUNCIÓN AUXILIAR: VERIFICAR SI EXISTE UNA COLUMNA
// ============================================================================
function columnaExiste($conn, $tabla, $columna) {
try {
$stmt = $conn->prepare("
SELECT COUNT(*) as total
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = ?
AND COLUMN_NAME = ?
");
$stmt->execute([$tabla, $columna]);
return $stmt->fetch()['total'] > 0;
} catch (Exception $e) {
return false;
}
}
// ============================================================================
// FUNCIÓN PARA OBTENER ICONO SEGÚN TIPO DE ALERTA
// ============================================================================
function obtenerIconoAlerta($tipo) {
$iconos = [
// EMPRESAS
'empresas_inactivas' => 'building',
'empresas_sin_responsable' => 'user-shield',
'empresas_cuit_duplicado' => 'exclamation-triangle',
'empresas_sin_documentacion' => 'file-contract',
// SUCURSALES
'sucursales_pendientes_aprobacion' => 'clock',
'aranceles_vencidos' => 'money-bill-wave',
'sucursales_rechazadas' => 'times-circle',
'sucursales_documentacion_incompleta' => 'file-contract',
'sucursales_sin_pdf_resolucion' => 'file-pdf',
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
// 📄 DOCUMENTOS EMPRESAS (NUEVO)
'documentos_pendientes_revision' => 'file-signature',
'documentos_rechazados' => 'times-circle',
'documentos_sin_observaciones' => 'file-alt',
// INFORMES
'informe_pendiente' => 'file-alt',
'multas_pendientes' => 'file-invoice-dollar'
];
return $iconos[$tipo] ?? 'exclamation-circle';
}
// ============================================================================
// FUNCIÓN PARA OBTENER COLOR SEGÚN PRIORIDAD
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
// FUNCIÓN PARA OBTENER MÓDULO SEGÚN TIPO DE ALERTA
// ============================================================================
function obtenerModuloAlerta($tipo) {
if (in_array($tipo, ['empresas_inactivas', 'empresas_sin_responsable', 'empresas_cuit_duplicado', 'empresas_sin_documentacion'])) {
return 'empresas';
} elseif (in_array($tipo, ['sucursales_pendientes_aprobacion', 'aranceles_vencidos', 'sucursales_rechazadas', 'sucursales_documentacion_incompleta', 'sucursales_sin_pdf_resolucion'])) {
return 'sucursales';
} elseif (in_array($tipo, ['doc_vencida', 'doc_por_vencer', 'personal_inactivo_sin_baja', 'documentacion_pendiente_revision', 'revalidaciones_vencidas', 'revalidaciones_por_vencer', 'credenciales_sin_pagar', 'personal_sin_foto', 'personal_sin_pdf_datos', 'personal_sin_cupon_pago', 'personal_sin_certificado'])) {
return 'personal';
} elseif (in_array($tipo, ['recursos_pendientes_aprobacion', 'recursos_rechazados', 'recursos_items_vencidos', 'recursos_sin_pdf', 'recursos_por_vencer'])) {
return 'recursos';
} elseif (in_array($tipo, ['servicios_pendientes_aprobacion', 'servicios_con_sanciones', 'servicios_sin_pdf', 'servicios_vencidos', 'servicios_sin_personal_asignado'])) {
return 'servicios';
} elseif (in_array($tipo, ['inspecciones_pendientes', 'inspecciones_con_observaciones', 'inspecciones_con_sanciones', 'inspecciones_sin_sumario', 'inspecciones_por_vencer'])) {
return 'inspecciones';
} elseif (in_array($tipo, ['documentos_pendientes_revision', 'documentos_rechazados', 'documentos_sin_observaciones'])) {
return 'documentos';
}
return 'otros';
}
// ============================================================================
// FUNCIÓN PARA OBTENER TODAS LAS ALERTAS DEL SISTEMA
// ============================================================================
function obtenerAlertas($conn) {
$alertas = [];
$hoy = date('Y-m-d');
$proximos_30_dias = date('Y-m-d', strtotime('+30 days'));
// =========================================================================
// ==================== MÓDULO EMPRESAS ====================
// =========================================================================
// 1. Empresas Inactivas
try {
if (columnaExiste($conn, 'empresas', 'activo')) {
$stmt = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE activo = FALSE");
$empresas_inactivas = $stmt->fetch()['total'] ?? 0;
if ($empresas_inactivas > 0) {
$alertas[] = [
'tipo' => 'empresas_inactivas',
'prioridad' => 'alta',
'titulo' => 'Empresas Inactivas',
'descripcion' => "Hay {$empresas_inactivas} empresa(s) inactivas que requieren reactivación o baja definitiva",
'accion_url' => 'empresas.php?search_estado=inactivas'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - empresas_inactivas: " . $e->getMessage());
}
// 2. Empresas Sin Responsable
try {
if (columnaExiste($conn, 'empresas', 'responsable_id')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM empresas
WHERE responsable_id IS NULL AND activo = TRUE
");
$empresas_sin_responsable = $stmt->fetch()['total'] ?? 0;
if ($empresas_sin_responsable > 0) {
$alertas[] = [
'tipo' => 'empresas_sin_responsable',
'prioridad' => 'media',
'titulo' => 'Empresas Sin Responsable',
'descripcion' => "Hay {$empresas_sin_responsable} empresa(s) activas sin responsable de seguridad asignado",
'accion_url' => 'empresas.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - empresas_sin_responsable: " . $e->getMessage());
}
// 3. CUIT Duplicado
try {
$stmt = $conn->query("
SELECT COUNT(DISTINCT cuit) as total FROM empresas
WHERE cuit IS NOT NULL AND cuit != ''
GROUP BY cuit HAVING COUNT(*) > 1
");
$cuit_duplicados = $stmt->rowCount();
if ($cuit_duplicados > 0) {
$alertas[] = [
'tipo' => 'empresas_cuit_duplicado',
'prioridad' => 'alta',
'titulo' => 'CUIT Duplicado',
'descripcion' => "Hay {$cuit_duplicados} CUIT(s) duplicados en el sistema de empresas",
'accion_url' => 'empresas.php'
];
}
} catch (Exception $e) {
error_log("Error en alertas - empresas_cuit_duplicado: " . $e->getMessage());
}
// =========================================================================
// ==================== MÓDULO SUCURSALES ====================
// =========================================================================
// 4. Sucursales Pendientes de Aprobación
try {
if (columnaExiste($conn, 'sucursales', 'estado_aprobacion')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE (estado_aprobacion = 'pendiente' OR estado_aprobacion IS NULL)
AND en_funcionamiento = 1
");
$sucursales_pendientes = $stmt->fetch()['total'] ?? 0;
if ($sucursales_pendientes > 0) {
$alertas[] = [
'tipo' => 'sucursales_pendientes_aprobacion',
'prioridad' => 'alta',
'titulo' => 'Sucursales Pendientes de Aprobación',
'descripcion' => "Hay {$sucursales_pendientes} sucursal(es) en funcionamiento pendientes de aprobación administrativa",
'accion_url' => 'sucursales.php?filtro_aprobacion=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_pendientes_aprobacion: " . $e->getMessage());
}
// 5. Aranceles Vencidos (>380 días)
try {
if (columnaExiste($conn, 'sucursales', 'fecha_pago_arancel')) {
$stmt = $conn->query("
SELECT COUNT(*) as total FROM sucursales
WHERE fecha_pago_arancel IS NOT NULL
AND fecha_pago_arancel < DATE_SUB(NOW(), INTERVAL 380 DAY)
");
$aranceles_vencidos = $stmt->fetch()['total'] ?? 0;
if ($aranceles_vencidos > 0) {
$alertas[] = [
'tipo' => 'aranceles_vencidos',
'prioridad' => 'alta',
'titulo' => 'Aranceles de Sucursales Vencidos',
'descripcion' => "Hay {$aranceles_vencidos} sucursal(es) con arancel vencido (más de 380 días sin pago)",
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
");
$sucursales_rechazadas = $stmt->fetch()['total'] ?? 0;
if ($sucursales_rechazadas > 0) {
$alertas[] = [
'tipo' => 'sucursales_rechazadas',
'prioridad' => 'alta',
'titulo' => 'Sucursales Rechazadas',
'descripcion' => "Hay {$sucursales_rechazadas} sucursal(es) rechazadas que requieren revisión y corrección",
'accion_url' => 'sucursales.php?filtro_aprobacion=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_rechazadas: " . $e->getMessage());
}
// 7. Sucursales con Documentación Incompleta
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
'titulo' => 'Sucursales con Documentación Incompleta',
'descripcion' => "Hay {$sucursales_doc_incompleta} sucursal(es) activas con documentación incompleta (RENAR, Certificado o Habilitación)",
'accion_url' => 'sucursales.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_documentacion_incompleta: " . $e->getMessage());
}
// 8. Sucursales Sin PDF de Resolución
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
'titulo' => 'Sucursales Sin PDF de Resolución',
'descripcion' => "Hay {$sucursales_sin_pdf} sucursal(es) activas sin PDF de resolución cargado",
'accion_url' => 'sucursales.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - sucursales_sin_pdf_resolucion: " . $e->getMessage());
}
// =========================================================================
// ==================== MÓDULO PERSONAL ====================
// =========================================================================
// 9. Documentación Vencida
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
'titulo' => 'Documentación Vencida',
'descripcion' => "El personal {$personal['apellido']}, {$personal['nombre']} (DNI: {$personal['dni']}) tiene documentación vencida desde " . date('d/m/Y', strtotime($personal['fecha_vencimiento'])),
'personal_id' => $personal['id'],
'empresa_nombre' => $personal['empresa'],
'sucursal_nombre' => $personal['sucursal']
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - doc_vencida: " . $e->getMessage());
}
// 10. Documentación por Vencer (30 días)
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
'titulo' => 'Documentación por Vencer',
'descripcion' => "El personal {$personal['apellido']}, {$personal['nombre']} (DNI: {$personal['dni']}) vence en " . ceil($dias) . " días (" . date('d/m/Y', strtotime($personal['fecha_vencimiento'])) . ")",
'personal_id' => $personal['id'],
'empresa_nombre' => $personal['empresa'],
'sucursal_nombre' => $personal['sucursal']
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - doc_por_vencer: " . $e->getMessage());
}
// 11. Personal Inactivo Sin Baja
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
// 12. Documentación Pendiente de Revisión
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
'titulo' => 'Documentación Pendiente de Revisión',
'descripcion' => "Hay {$documentacion_pendiente} registro(s) de personal con documentación pendiente de aprobación/rechazo",
'accion_url' => 'personal.php?filtro_estado_documentacion=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentacion_pendiente_revision: " . $e->getMessage());
}
// 13. Revalidaciones Vencidas
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
'descripcion' => "Hay {$revalidaciones_vencidas} registro(s) de personal con revalidación vencida",
'accion_url' => 'personal.php?filtro_revalidacion=vencido'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - revalidaciones_vencidas: " . $e->getMessage());
}
// 14. Revalidaciones por Vencer (30 días)
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
'descripcion' => "Hay {$revalidaciones_por_vencer} registro(s) de personal con revalidación por vencer en los próximos 30 días",
'accion_url' => 'personal.php?filtro_revalidacion=proximo'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - revalidaciones_por_vencer: " . $e->getMessage());
}
// 15. Credenciales Sin Pagar
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
// 16. Personal Sin Foto Carnet
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
// 17. Personal Sin PDF Datos Personales
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
// 18. Personal Sin Cupón de Pago
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
'titulo' => 'Personal Sin Cupón de Pago',
'descripcion' => "Hay {$personal_sin_cupon} registro(s) con credencial pagada pero sin cupón cargado",
'accion_url' => 'personal.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - personal_sin_cupon_pago: " . $e->getMessage());
}
// =========================================================================
// ==================== MÓDULO RECURSOS ====================
// =========================================================================
// 19. Recursos Pendientes de Aprobación
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
'titulo' => 'Recursos Pendientes de Aprobación',
'descripcion' => "Hay {$recursos_pendientes} solicitud(es) de recursos pendiente(s) de aprobación",
'accion_url' => 'recursos.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_pendientes_aprobacion: " . $e->getMessage());
}
// 20. Recursos Rechazados
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
'descripcion' => "Hay {$recursos_rechazados} solicitud(es) de recursos rechazada(s) que requieren corrección",
'accion_url' => 'recursos.php?search_estado=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_rechazados: " . $e->getMessage());
}
// 21. Recursos con Items Vencidos
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
// 22. Recursos Sin PDF Adjunto
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
'descripcion' => "Hay {$recursos_sin_pdf} recurso(s) aprobados sin documentación PDF cargada",
'accion_url' => 'recursos.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_sin_pdf: " . $e->getMessage());
}
// 23. Recursos por Vencer (30 días)
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
'descripcion' => "Hay {$recursos_por_vencer} recurso(s) con items por vencer en los próximos 30 días",
'accion_url' => 'recursos.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - recursos_por_vencer: " . $e->getMessage());
}
// =========================================================================
// ==================== MÓDULO SERVICIOS ====================
// =========================================================================
// 24. Servicios Pendientes de Aprobación
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
'titulo' => 'Servicios Pendientes de Aprobación',
'descripcion' => "Hay {$servicios_pendientes} servicio(s) pendiente(s) de aprobación",
'accion_url' => 'servicios.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_pendientes_aprobacion: " . $e->getMessage());
}
// 25. Servicios con Sanciones
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
// 26. Servicios Sin PDF
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
'descripcion' => "Hay {$servicios_sin_pdf} servicio(s) activos sin documentación PDF cargada",
'accion_url' => 'servicios.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - servicios_sin_pdf: " . $e->getMessage());
}
// 27. Servicios Vencidos
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
// 28. Servicios Sin Personal Asignado
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
// ==================== MÓDULO INSPECCIONES ====================
// =========================================================================
// 29. Inspecciones Pendientes
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
'descripcion' => "Hay {$inspecciones_pendientes} inspección(es) pendiente(s) de finalización",
'accion_url' => 'inspecciones.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_pendientes: " . $e->getMessage());
}
// 30. Inspecciones con Observaciones
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
'descripcion' => "Hay {$inspecciones_con_observaciones} inspección(es) con irregularidades registradas",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_con_observaciones: " . $e->getMessage());
}
// 31. Inspecciones con Sanciones
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
'descripcion' => "Hay {$inspecciones_con_sanciones} inspección(es) con sumario/sanción vinculada",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_con_sanciones: " . $e->getMessage());
}
// 32. Inspecciones Sin Sumario Vinculado
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
'descripcion' => "Hay {$inspecciones_sin_sumario} inspección(es) con irregularidades pero sin sumario vinculado",
'accion_url' => 'inspecciones.php'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - inspecciones_sin_sumario: " . $e->getMessage());
}
// =========================================================================
// ==================== 📄 MÓDULO DOCUMENTOS EMPRESAS (NUEVO) ====================
// =========================================================================
// 33. Documentos Pendientes de Revisión
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
'titulo' => 'Documentos Pendientes de Revisión',
'descripcion' => "Hay {$documentos_pendientes} documento(s) de empresa(s) pendiente(s) de aprobación/rechazo",
'accion_url' => 'documentos_empresas.php?search_estado=pendiente'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentos_pendientes_revision: " . $e->getMessage());
}
// 34. Documentos Rechazados
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
'descripcion' => "Hay {$documentos_rechazados} documento(s) rechazado(s) en los últimos 7 días que requieren corrección",
'accion_url' => 'documentos_empresas.php?search_estado=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentos_rechazados: " . $e->getMessage());
}
// 35. Documentos Sin Observaciones (Rechazados sin motivación)
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
'titulo' => 'Documentos Rechazados Sin Motivación',
'descripcion' => "Hay {$documentos_sin_motivacion} documento(s) rechazado(s) sin motivación registrada",
'accion_url' => 'documentos_empresas.php?search_estado=rechazado'
];
}
}
} catch (Exception $e) {
error_log("Error en alertas - documentos_sin_observaciones: " . $e->getMessage());
}
// =========================================================================
// ==================== MÓDULO INFORMES (EXISTENTE) ====================
// =========================================================================
// 36. Informes Mensuales Pendientes
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
// 37. Multas Pendientes
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
// ORDENAR POR PRIORIDAD (ALTA PRIMERO)
// =========================================================================
usort($alertas, function($a, $b) {
$prioridad = ['alta' => 3, 'media' => 2, 'baja' => 1];
return $prioridad[$b['prioridad']] - $prioridad[$a['prioridad']];
});
return $alertas;
}
// ============================================================================
// OBTENER TODAS LAS ALERTAS
// ============================================================================
$alertas = obtenerAlertas($conn);
$total_alertas = count($alertas);
$alertas_alta = array_filter($alertas, fn($a) => $a['prioridad'] === 'alta');
$alertas_media = array_filter($alertas, fn($a) => $a['prioridad'] === 'media');
$alertas_baja = array_filter($alertas, fn($a) => $a['prioridad'] === 'baja');
// Contadores por módulo
$contadores_por_modulo = [
'empresas' => 0,
'sucursales' => 0,
'personal' => 0,
'recursos' => 0,
'servicios' => 0,
'inspecciones' => 0,
'documentos' => 0,
'otros' => 0
];
foreach ($alertas as $alerta) {
$modulo = obtenerModuloAlerta($alerta['tipo']);
if (isset($contadores_por_modulo[$modulo])) {
$contadores_por_modulo[$modulo]++;
}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alertas del Sistema - Sistema de Seguridad</title>
<!-- Mantener CDN para Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
:root {
--primary-color: #3498db;
--secondary-color: #2c3e50;
--success-color: #27ae60;
--warning-color: #f39c12;
--danger-color: #e74c3c;
--info-color: #1abc9c;
}
body {
background-color: #f8f9fa;
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
padding-top: 70px;
}
.header-modern {
background: linear-gradient(120deg, #1a2a6c, #2c3e50, #4a6491);
color: white;
padding: 0;
box-shadow: 0 4px 25px rgba(0, 0, 0, 0.35);
position: fixed;
top: 0;
left: 0;
width: 100%;
z-index: 1100;
border-bottom: 3px solid #4361ee;
}
.dashboard {
display: flex;
min-height: calc(100vh - 70px);
}
.sidebar {
width: 250px;
background: var(--secondary-color);
color: white;
padding: 20px 0;
position: fixed;
height: calc(100vh - 70px);
overflow-y: auto;
z-index: 900;
}
.sidebar-menu a {
display: block;
padding: 12px 25px;
color: #ecf0f1;
text-decoration: none;
transition: all 0.3s;
border-left: 4px solid transparent;
}
.sidebar-menu a:hover,
.sidebar-menu a.active {
background: #34495e;
border-left-color: var(--primary-color);
}
.sidebar-menu a i {
margin-right: 10px;
width: 20px;
text-align: center;
}
.main-content {
flex: 1;
margin-left: 250px;
padding: 25px;
}
.alert-container {
background: white;
border-radius: 15px;
box-shadow: 0 5px 15px rgba(0,0,0,0.08);
margin-bottom: 20px;
padding: 20px;
transition: transform 0.3s;
border-left: 4px solid #3498db;
}
.alert-container:hover {
transform: translateY(-3px);
box-shadow: 0 8px 20px rgba(0,0,0,0.12);
}
.alert-container.alta { border-left-color: #e74c3c; }
.alert-container.media { border-left-color: #f39c12; }
.alert-container.baja { border-left-color: #3498db; }
.alert-icon {
font-size: 2.5rem;
margin-right: 15px;
}
.alert-title {
font-weight: 700;
font-size: 1.3rem;
margin-bottom: 5px;
color: #2c3e50;
}
.alert-desc {
color: #34495e;
line-height: 1.5;
margin-bottom: 10px;
}
.alert-meta {
display: flex;
flex-wrap: wrap;
gap: 15px;
font-size: 0.9rem;
color: #7f8c8d;
margin-top: 10px;
}
.alert-badge {
padding: 3px 10px;
border-radius: 12px;
font-weight: 600;
font-size: 0.85rem;
}
.badge-alta {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
}
.badge-media {
background: linear-gradient(135deg, #f39c12, #d35400);
color: white;
}
.badge-baja {
background: linear-gradient(135deg, #3498db, #2980b9);
color: white;
}
.stats-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
gap: 20px;
margin: 25px 0;
}
.stat-card {
background: white;
padding: 20px;
border-radius: 15px;
text-align: center;
box-shadow: 0 5px 15px rgba(0,0,0,0.08);
transition: all 0.3s;
}
.stat-card:hover {
transform: translateY(-5px);
}
.stat-card.alta { border-top: 4px solid #e74c3c; }
.stat-card.media { border-top: 4px solid #f39c12; }
.stat-card.baja { border-top: 4px solid #3498db; }
.stat-card.total { border-top: 4px solid #3498db; }
.stat-number {
font-size: 2.5rem;
font-weight: 800;
margin: 10px 0;
}
.stat-label {
font-size: 1.1rem;
color: #7f8c8d;
text-transform: uppercase;
letter-spacing: 1px;
}
.module-stats-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
gap: 15px;
margin: 25px 0;
}
.module-stat-card {
background: white;
padding: 15px;
border-radius: 12px;
text-align: center;
box-shadow: 0 3px 10px rgba(0,0,0,0.08);
transition: all 0.3s;
border: 2px solid #e9ecef;
}
.module-stat-card:hover {
transform: translateY(-3px);
border-color: #4361ee;
}
.module-stat-number {
font-size: 1.8rem;
font-weight: 700;
margin: 5px 0;
color: #4361ee;
}
.module-stat-label {
font-size: 0.85rem;
color: #7f8c8d;
text-transform: uppercase;
}
.empty-state {
text-align: center;
padding: 60px 20px;
color: #95a5a6;
}
.empty-state i {
font-size: 5rem;
margin-bottom: 20px;
opacity: 0.7;
}
.empty-state h3 {
font-size: 1.8rem;
margin-bottom: 15px;
}
.priority-filter {
display: flex;
gap: 10px;
margin: 20px 0;
flex-wrap: wrap;
}
.priority-filter .btn {
border-radius: 50px;
padding: 8px 20px;
font-weight: 600;
}
.module-filter {
display: flex;
gap: 10px;
margin: 20px 0;
flex-wrap: wrap;
}
.module-filter .btn {
border-radius: 50px;
padding: 8px 20px;
font-weight: 600;
}
.alert-type-badge {
display: inline-block;
padding: 2px 8px;
border-radius: 8px;
font-size: 0.75rem;
font-weight: 600;
margin-right: 5px;
background: #e9ecef;
color: #495057;
}
@media (max-width: 768px) {
.dashboard { flex-direction: column; }
.sidebar { position: relative; width: 100%; height: auto; margin-bottom: 20px; }
.main-content { margin-left: 0; padding: 15px; }
.stats-grid { grid-template-columns: repeat(2, 1fr); }
.module-stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@keyframes fadeIn {
from { opacity: 0; transform: translateY(20px); }
to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>
<!-- HEADER FIJO EN LA PARTE SUPERIOR -->
<?php
$page_title = 'Alertas del Sistema';
include '../includes/header.php';
?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php if ($total_alertas > 0): ?>
<!-- ESTADÍSTICAS GENERALES -->
<div class="stats-grid">
<div class="stat-card total">
<i class="fas fa-bell fa-2x" style="color: #3498db;"></i>
<div class="stat-number"><?php echo $total_alertas; ?></div>
<div class="stat-label">Alertas Totales</div>
</div>
<div class="stat-card alta">
<i class="fas fa-exclamation-triangle fa-2x" style="color: #e74c3c;"></i>
<div class="stat-number"><?php echo count($alertas_alta); ?></div>
<div class="stat-label">Prioridad Alta</div>
</div>
<div class="stat-card media">
<i class="fas fa-exclamation-circle fa-2x" style="color: #f39c12;"></i>
<div class="stat-number"><?php echo count($alertas_media); ?></div>
<div class="stat-label">Prioridad Media</div>
</div>
<div class="stat-card baja">
<i class="fas fa-info-circle fa-2x" style="color: #3498db;"></i>
<div class="stat-number"><?php echo count($alertas_baja); ?></div>
<div class="stat-label">Prioridad Baja</div>
</div>
</div>
<!-- ESTADÍSTICAS POR MÓDULO -->
<h4 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Alertas por Módulo</h4>
<div class="module-stats-grid">
<div class="module-stat-card">
<i class="fas fa-building fa-2x mb-2" style="color: #3498db;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['empresas']; ?></div>
<div class="module-stat-label">Empresas</div>
</div>
<div class="module-stat-card">
<i class="fas fa-store fa-2x mb-2" style="color: #27ae60;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['sucursales']; ?></div>
<div class="module-stat-label">Sucursales</div>
</div>
<div class="module-stat-card">
<i class="fas fa-users fa-2x mb-2" style="color: #9b59b6;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['personal']; ?></div>
<div class="module-stat-label">Personal</div>
</div>
<div class="module-stat-card">
<i class="fas fa-boxes fa-2x mb-2" style="color: #f39c12;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['recursos']; ?></div>
<div class="module-stat-label">Recursos</div>
</div>
<div class="module-stat-card">
<i class="fas fa-concierge-bell fa-2x mb-2" style="color: #e74c3c;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['servicios']; ?></div>
<div class="module-stat-label">Servicios</div>
</div>
<div class="module-stat-card">
<i class="fas fa-clipboard-check fa-2x mb-2" style="color: #1abc9c;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['inspecciones']; ?></div>
<div class="module-stat-label">Inspecciones</div>
</div>
<div class="module-stat-card">
<i class="fas fa-file-contract fa-2x mb-2" style="color: #4361ee;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['documentos']; ?></div>
<div class="module-stat-label">Documentos</div>
</div>
<div class="module-stat-card">
<i class="fas fa-folder fa-2x mb-2" style="color: #95a5a6;"></i>
<div class="module-stat-number"><?php echo $contadores_por_modulo['otros']; ?></div>
<div class="module-stat-label">Otros</div>
</div>
</div>
<!-- FILTROS DE PRIORIDAD -->
<div class="priority-filter">
<button class="btn btn-outline-danger active" data-filter="all">
<i class="fas fa-list"></i> Todas las Alertas
</button>
<button class="btn btn-outline-danger" data-filter="alta">
<i class="fas fa-exclamation-triangle"></i> Alta Prioridad
</button>
<button class="btn btn-outline-warning" data-filter="media">
<i class="fas fa-exclamation-circle"></i> Media Prioridad
</button>
<button class="btn btn-outline-info" data-filter="baja">
<i class="fas fa-info-circle"></i> Baja Prioridad
</button>
</div>
<!-- FILTROS POR MÓDULO -->
<div class="module-filter">
<button class="btn btn-outline-primary active" data-module="all">
<i class="fas fa-th"></i> Todos los Módulos
</button>
<button class="btn btn-outline-primary" data-module="empresas">
<i class="fas fa-building"></i> Empresas
</button>
<button class="btn btn-outline-primary" data-module="sucursales">
<i class="fas fa-store"></i> Sucursales
</button>
<button class="btn btn-outline-primary" data-module="personal">
<i class="fas fa-users"></i> Personal
</button>
<button class="btn btn-outline-primary" data-module="recursos">
<i class="fas fa-boxes"></i> Recursos
</button>
<button class="btn btn-outline-primary" data-module="servicios">
<i class="fas fa-concierge-bell"></i> Servicios
</button>
<button class="btn btn-outline-primary" data-module="inspecciones">
<i class="fas fa-clipboard-check"></i> Inspecciones
</button>
<button class="btn btn-outline-primary" data-module="documentos">
<i class="fas fa-file-contract"></i> Documentos
</button>
<button class="btn btn-outline-primary" data-module="otros">
<i class="fas fa-folder"></i> Otros
</button>
</div>
<!-- LISTADO DE ALERTAS -->
<div id="alertasContainer">
<?php foreach ($alertas as $alerta):
$modulo = obtenerModuloAlerta($alerta['tipo']);
$icono = obtenerIconoAlerta($alerta['tipo']);
$color = obtenerColorPrioridad($alerta['prioridad']);
?>
<div class="alert-container <?php echo $alerta['prioridad']; ?>"
data-priority="<?php echo $alerta['prioridad']; ?>"
data-module="<?php echo $modulo; ?>">
<div style="display: flex; align-items: flex-start;">
<div style="flex-shrink: 0;">
<i class="fas fa-<?php echo $icono; ?> alert-icon"
style="color: <?php echo $color; ?>;"></i>
</div>
<div style="flex-grow: 1; margin-left: 15px;">
<div class="alert-title">
<?php echo htmlspecialchars($alerta['titulo']); ?>
<span class="alert-badge badge-<?php echo $alerta['prioridad']; ?>">
<?php echo strtoupper($alerta['prioridad']); ?>
</span>
<span class="alert-type-badge">
<i class="fas fa-tag"></i> <?php echo $modulo; ?>
</span>
</div>
<div class="alert-desc"><?php echo htmlspecialchars($alerta['descripcion']); ?></div>
<?php if (isset($alerta['empresa_nombre'])): ?>
<div class="alert-meta">
<span><i class="fas fa-building"></i> <?php echo htmlspecialchars($alerta['empresa_nombre']); ?></span>
<?php if (isset($alerta['sucursal_nombre'])): ?>
<span><i class="fas fa-store"></i> <?php echo htmlspecialchars($alerta['sucursal_nombre']); ?></span>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if (isset($alerta['accion_url'])): ?>
<a href="<?php echo $alerta['accion_url']; ?>" class="btn btn-sm btn-primary mt-2">
<i class="fas fa-arrow-right"></i> Resolver Ahora
</a>
<?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state">
<i class="fas fa-check-circle"></i>
<h3>¡Excelente! No hay alertas pendientes</h3>
<p class="mb-0">El sistema no ha detectado ninguna situación que requiera atención inmediata.</p>
<div class="mt-4">
<a href="dashboard.php" class="btn btn-success">
<i class="fas fa-tachometer-alt"></i> Volver al Dashboard
</a>
</div>
</div>
<?php endif; ?>
</div>
</div>
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="../css/sweetalert2.all.min.js"></script>
<script src="../css/sweetalert2@11"></script>
<script>
// Filtro de prioridad
document.querySelectorAll('.priority-filter .btn').forEach(button => {
button.addEventListener('click', function() {
document.querySelectorAll('.priority-filter .btn').forEach(btn => {
btn.classList.remove('active');
});
this.classList.add('active');
const filter = this.dataset.filter;
const alertas = document.querySelectorAll('#alertasContainer .alert-container');
alertas.forEach(alerta => {
if (filter === 'all' || alerta.dataset.priority === filter) {
alerta.style.display = 'block';
alerta.style.animation = 'fadeIn 0.5s';
} else {
alerta.style.display = 'none';
}
});
});
});
// Filtro por módulo
document.querySelectorAll('.module-filter .btn').forEach(button => {
button.addEventListener('click', function() {
document.querySelectorAll('.module-filter .btn').forEach(btn => {
btn.classList.remove('active');
});
this.classList.add('active');
const module = this.dataset.module;
const alertas = document.querySelectorAll('#alertasContainer .alert-container');
alertas.forEach(alerta => {
if (module === 'all' || alerta.dataset.module === module) {
alerta.style.display = 'block';
alerta.style.animation = 'fadeIn 0.5s';
} else {
alerta.style.display = 'none';
}
});
});
});
// Animación de entrada para las alertas
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
</script>
</body>
</html>