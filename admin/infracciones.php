<?php
/**
* ============================================================================
* GESTIÓN DE INFRACCIONES - ACTAS DE CONTROL
* ============================================================================
* Permite registrar infracciones según dos tipos de actas:
* - ACTA DE CONTROL DE AGENCIAS
* - ACTA VIGILADOR
*
* Basado en Ley I N° 168 y Decreto N° 1220/19 - Provincia del Chubut
*
* @author Sistema de Seguridad
* @version 1.1
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
$current_page = 'infracciones';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// OBTENER INSPECCIÓN RELACIONADA (SI VIENE DE inspecciones.php)
// ============================================================================
$inspeccion_id = isset($_GET['inspeccion_id']) ? (int)$_GET['inspeccion_id'] : 0;
$inspeccion_data = null;
if ($inspeccion_id > 0) {
$stmt = $conn->prepare("SELECT * FROM inspecciones WHERE id = ? AND activo = 1");
$stmt->execute([$inspeccion_id]);
$inspeccion_data = $stmt->fetch(PDO::FETCH_ASSOC);
}
// ============================================================================
// OBTENER LISTA DE INSPECCIONES PARA EL SELECTOR DE ORIGEN
// ============================================================================
$inspecciones_origen = [];
try {
$stmt_insp = $conn->query("SELECT id, numero_acta, numero_anio, empresa_nombre, sucursal_nombre, responsable_nombre, funcionario_nombre, fecha_inspeccion, hora_inspeccion, lugar FROM inspecciones WHERE activo = 1 ORDER BY fecha_inspeccion DESC, numero_acta DESC");
$inspecciones_origen = $stmt_insp->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
error_log("Error al obtener inspecciones: " . $e->getMessage());
}
// ============================================================================
// VERIFICAR/CREAR ESTRUCTURA DE TABLA INFRACCIONES
// ============================================================================
try {
$table_exists = $conn->query("SHOW TABLES LIKE 'infracciones'")->rowCount() > 0;
if (!$table_exists) {
$conn->exec("
CREATE TABLE infracciones (
id INT AUTO_INCREMENT PRIMARY KEY,
inspeccion_id INT NOT NULL,
tipo_acta ENUM('agencias', 'vigilador') NOT NULL,
fecha_acta DATE NULL,
hora_acta TIME NULL,
lugar VARCHAR(255) NULL,
agencia_local VARCHAR(255) NULL,
persona_identificada VARCHAR(255) NULL,
vestimenta VARCHAR(255) NULL,
-- Infracciones ACTA AGENCIAS
inf_fichero BOOLEAN DEFAULT FALSE,
inf_libros_inspecciones BOOLEAN DEFAULT FALSE,
inf_libro_personal BOOLEAN DEFAULT FALSE,
inf_autorizacion_cnc BOOLEAN DEFAULT FALSE,
inf_inscripcion_renar BOOLEAN DEFAULT FALSE,
inf_registro_usuario BOOLEAN DEFAULT FALSE,
inf_credencial BOOLEAN DEFAULT FALSE,
inf_archivos_investigaciones BOOLEAN DEFAULT FALSE,
inf_hab_jefatura BOOLEAN DEFAULT FALSE,
inf_vehiculo_balizas BOOLEAN DEFAULT FALSE,
inf_pago_afip BOOLEAN DEFAULT FALSE,
inf_pago_vehiculo BOOLEAN DEFAULT FALSE,
inf_pago_hab_comercial BOOLEAN DEFAULT FALSE,
inf_pago_dir_tecnico BOOLEAN DEFAULT FALSE,
inf_reglamento_estatuto BOOLEAN DEFAULT FALSE,
-- Infracciones ACTA VIGILADOR
inf_credencial_directivo BOOLEAN DEFAULT FALSE,
inf_elementos_sujecion BOOLEAN DEFAULT FALSE,
inf_uniforme_empresa BOOLEAN DEFAULT FALSE,
inf_autorizacion_vigilador BOOLEAN DEFAULT FALSE,
inf_arma BOOLEAN DEFAULT FALSE,
inf_otras_faltas BOOLEAN DEFAULT FALSE,
inf_tarjeta_legitimo_usuario BOOLEAN DEFAULT FALSE,
inf_tarjeta_tenencia BOOLEAN DEFAULT FALSE,
inf_tarjeta_portacion BOOLEAN DEFAULT FALSE,
otras_infracciones TEXT NULL,
observaciones TEXT NULL,
funcionario_actuante VARCHAR(255) NULL,
responsable_presente VARCHAR(255) NULL,
testigos TEXT NULL,
-- Campos para Sanción y Notificación
sancion_tipo VARCHAR(100) NULL,
sancion_fecha DATE NULL,
sancion_resolucion VARCHAR(100) NULL,
sancion_monto DECIMAL(10,2) NULL,
sancion_observaciones TEXT NULL,
notificacion_fecha DATE NULL,
notificacion_entregado_a VARCHAR(255) NULL,
notificacion_firma_responsable BOOLEAN DEFAULT FALSE,
fecha_limite_pago DATE NULL,
multa_pagada BOOLEAN DEFAULT FALSE,
-- Evidencia y Medidas Cautelares (Art. 23 - Ley I-168)
evidencia_fotos_cantidad INT NULL,
evidencia_fotos_descripcion TEXT NULL,
evidencia_fotos_archivos TEXT NULL,
evidencia_videos_cantidad INT NULL,
evidencia_videos_soporte VARCHAR(255) NULL,
evidencia_videos_archivos TEXT NULL,
evidencia_elementos_retenidos TEXT NULL,
estado_acta ENUM('borrador', 'elevado_jefatura', 'sancionado', 'notificado', 'multa_pagada', 'cerrado') DEFAULT 'borrador',
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
INDEX idx_inspeccion (inspeccion_id),
INDEX idx_tipo_acta (tipo_acta),
INDEX idx_fecha (fecha_acta),
INDEX idx_estado (estado_acta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
logAuditoria($conn, 'TABLA_CREADA', 'infracciones', null, ['mensaje' => 'Tabla infracciones creada'], $user['id']);
} else {
// Verificar columnas necesarias
$columns = $conn->query("SHOW COLUMNS FROM infracciones")->fetchAll(PDO::FETCH_COLUMN);
$required_columns = [
'tipo_acta', 'fecha_acta', 'hora_acta', 'lugar', 'agencia_local',
'persona_identificada', 'vestimenta', 'otras_infracciones', 'observaciones',
'sancion_tipo', 'sancion_fecha', 'sancion_resolucion', 'sancion_monto',
'sancion_observaciones', 'notificacion_fecha', 'notificacion_entregado_a',
'notificacion_firma_responsable', 'fecha_limite_pago', 'multa_pagada', 'estado_acta',
'evidencia_fotos_cantidad', 'evidencia_fotos_descripcion', 'evidencia_fotos_archivos',
'evidencia_videos_cantidad', 'evidencia_videos_soporte', 'evidencia_videos_archivos',
'evidencia_elementos_retenidos'
];
foreach ($required_columns as $col) {
if (!in_array($col, $columns)) {
if ($col === 'fecha_limite_pago') {
$conn->exec("ALTER TABLE infracciones ADD COLUMN $col DATE NULL");
} elseif ($col === 'multa_pagada') {
$conn->exec("ALTER TABLE infracciones ADD COLUMN $col BOOLEAN DEFAULT FALSE");
} elseif ($col === 'evidencia_fotos_cantidad' || $col === 'evidencia_videos_cantidad') {
$conn->exec("ALTER TABLE infracciones ADD COLUMN $col INT NULL");
} elseif ($col === 'evidencia_fotos_descripcion' || $col === 'evidencia_elementos_retenidos' || $col === 'evidencia_fotos_archivos' || $col === 'evidencia_videos_archivos') {
$conn->exec("ALTER TABLE infracciones ADD COLUMN $col TEXT NULL");
} elseif ($col === 'evidencia_videos_soporte') {
$conn->exec("ALTER TABLE infracciones ADD COLUMN $col VARCHAR(255) NULL");
} else {
$conn->exec("ALTER TABLE infracciones ADD COLUMN $col VARCHAR(255) NULL");
}
}
}
// Verificar si estado_acta necesita ser ENUM con nuevo valor
$estado_col = $conn->query("SHOW COLUMNS FROM infracciones LIKE 'estado_acta'")->fetch(PDO::FETCH_ASSOC);
if ($estado_col && (strpos($estado_col['Type'], 'enum') === false || strpos($estado_col['Type'], 'multa_pagada') === false)) {
$conn->exec("ALTER TABLE infracciones MODIFY COLUMN estado_acta ENUM('borrador', 'elevado_jefatura', 'sancionado', 'notificado', 'multa_pagada', 'cerrado') DEFAULT 'borrador'");
}
}
} catch (PDOException $e) {
$error = "Error al verificar estructura: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// OBTENER INFRACCIONES EXISTENTES PARA LA INSPECCIÓN
// ============================================================================
$infraccion_existente = null;
if ($inspeccion_id > 0) {
$stmt = $conn->prepare("SELECT * FROM infracciones WHERE inspeccion_id = ? AND activo = 1");
$stmt->execute([$inspeccion_id]);
$infraccion_existente = $stmt->fetch(PDO::FETCH_ASSOC);
}
// ============================================================================
// MANEJAR CREACIÓN/ACTUALIZACIÓN DE INFRACCIONES
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
try {
// DEBUG - Log para verificar datos recibidos
error_log("POST DATA: " . print_r($_POST, true));
$inspeccion_id_post = (int)($_POST['inspeccion_id'] ?? 0);
if ($inspeccion_id_post <= 0) {
throw new Exception('ID de inspección inválido');
}
$tipo_acta = trim($_POST['tipo_acta'] ?? '');
// Validación estricta del tipo de acta
if (empty($tipo_acta) || !in_array($tipo_acta, ['agencias', 'vigilador'])) {
error_log("ERROR: tipo_acta inválido = '$tipo_acta'");
throw new Exception('Tipo de acta inválido. Debe seleccionar "Agencias" o "Vigilador"');
}
// Datos generales
$fecha_acta = !empty($_POST['fecha_acta']) ? $_POST['fecha_acta'] : date('Y-m-d');
$hora_acta = !empty($_POST['hora_acta']) ? $_POST['hora_acta'] : date('H:i');
$lugar = trim($_POST['lugar'] ?? '');
$agencia_local = trim($_POST['agencia_local'] ?? '');
$persona_identificada = trim($_POST['persona_identificada'] ?? '');
$vestimenta = trim($_POST['vestimenta'] ?? '');
$otras_infracciones = trim($_POST['otras_infracciones'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$funcionario_actuante = trim($_POST['funcionario_actuante'] ?? '');
$responsable_presente = trim($_POST['responsable_presente'] ?? '');
$testigos = trim($_POST['testigos'] ?? '');
// Infracciones ACTA AGENCIAS
$inf_agencias = [
'inf_fichero' => isset($_POST['inf_fichero']) ? 1 : 0,
'inf_libros_inspecciones' => isset($_POST['inf_libros_inspecciones']) ? 1 : 0,
'inf_libro_personal' => isset($_POST['inf_libro_personal']) ? 1 : 0,
'inf_autorizacion_cnc' => isset($_POST['inf_autorizacion_cnc']) ? 1 : 0,
'inf_inscripcion_renar' => isset($_POST['inf_inscripcion_renar']) ? 1 : 0,
'inf_registro_usuario' => isset($_POST['inf_registro_usuario']) ? 1 : 0,
'inf_credencial' => isset($_POST['inf_credencial']) ? 1 : 0,
'inf_archivos_investigaciones' => isset($_POST['inf_archivos_investigaciones']) ? 1 : 0,
'inf_hab_jefatura' => isset($_POST['inf_hab_jefatura']) ? 1 : 0,
'inf_vehiculo_balizas' => isset($_POST['inf_vehiculo_balizas']) ? 1 : 0,
'inf_pago_afip' => isset($_POST['inf_pago_afip']) ? 1 : 0,
'inf_pago_vehiculo' => isset($_POST['inf_pago_vehiculo']) ? 1 : 0,
'inf_pago_hab_comercial' => isset($_POST['inf_pago_hab_comercial']) ? 1 : 0,
'inf_pago_dir_tecnico' => isset($_POST['inf_pago_dir_tecnico']) ? 1 : 0,
'inf_reglamento_estatuto' => isset($_POST['inf_reglamento_estatuto']) ? 1 : 0,
];
// Infracciones ACTA VIGILADOR
$inf_vigilador = [
'inf_credencial_directivo' => isset($_POST['inf_credencial_directivo']) ? 1 : 0,
'inf_elementos_sujecion' => isset($_POST['inf_elementos_sujecion']) ? 1 : 0,
'inf_uniforme_empresa' => isset($_POST['inf_uniforme_empresa']) ? 1 : 0,
'inf_autorizacion_vigilador' => isset($_POST['inf_autorizacion_vigilador']) ? 1 : 0,
'inf_arma' => isset($_POST['inf_arma']) ? 1 : 0,
'inf_otras_faltas' => isset($_POST['inf_otras_faltas']) ? 1 : 0,
'inf_tarjeta_legitimo_usuario' => isset($_POST['inf_tarjeta_legitimo_usuario']) ? 1 : 0,
'inf_tarjeta_tenencia' => isset($_POST['inf_tarjeta_tenencia']) ? 1 : 0,
'inf_tarjeta_portacion' => isset($_POST['inf_tarjeta_portacion']) ? 1 : 0,
];
// Datos de Sanción y Notificación
$sancion_tipo = trim($_POST['sancion_tipo'] ?? '');
$sancion_fecha = !empty($_POST['sancion_fecha']) ? $_POST['sancion_fecha'] : null;
$sancion_resolucion = trim($_POST['sancion_resolucion'] ?? '');
$sancion_monto = !empty($_POST['sancion_monto']) ? (float)$_POST['sancion_monto'] : null;
$sancion_observaciones = trim($_POST['sancion_observaciones'] ?? '');
$notificacion_fecha = !empty($_POST['notificacion_fecha']) ? $_POST['notificacion_fecha'] : null;
$notificacion_entregado_a = trim($_POST['notificacion_entregado_a'] ?? '');
$notificacion_firma_responsable = isset($_POST['notificacion_firma_responsable']) ? 1 : 0;
$fecha_limite_pago = !empty($_POST['fecha_limite_pago']) ? $_POST['fecha_limite_pago'] : null;
$multa_pagada = isset($_POST['multa_pagada']) ? 1 : 0;
// Evidencia y Medidas Cautelares (Art. 23 - Ley I-168)
$evidencia_fotos_cantidad = !empty($_POST['evidencia_fotos_cantidad']) ? (int)$_POST['evidencia_fotos_cantidad'] : null;
$evidencia_fotos_descripcion = trim($_POST['evidencia_fotos_descripcion'] ?? '');
$evidencia_videos_cantidad = !empty($_POST['evidencia_videos_cantidad']) ? (int)$_POST['evidencia_videos_cantidad'] : null;
$evidencia_videos_soporte = trim($_POST['evidencia_videos_soporte'] ?? '');
$evidencia_elementos_retenidos = trim($_POST['evidencia_elementos_retenidos'] ?? '');
// Procesar subida de archivos de evidencia
$evidencia_fotos_archivos = [];
$evidencia_videos_archivos = [];
// Mantener archivos existentes si no se suben nuevos
if ($infraccion_existente) {
if (!empty($infraccion_existente['evidencia_fotos_archivos'])) {
$evidencia_fotos_archivos = json_decode($infraccion_existente['evidencia_fotos_archivos'], true) ?? [];
}
if (!empty($infraccion_existente['evidencia_videos_archivos'])) {
$evidencia_videos_archivos = json_decode($infraccion_existente['evidencia_videos_archivos'], true) ?? [];
}
}
// Procesar nuevas fotos subidas
if (!empty($_FILES['evidencia_fotos_archivos']['name'][0])) {
$uploadDir = '../uploads/evidencias/fotos/';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0755, true);
}
foreach ($_FILES['evidencia_fotos_archivos']['name'] as $key => $name) {
if ($_FILES['evidencia_fotos_archivos']['error'][$key] === UPLOAD_ERR_OK) {
$tmpName = $_FILES['evidencia_fotos_archivos']['tmp_name'][$key];
$fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
$targetPath = $uploadDir . $fileName;
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpName);
finfo_close($finfo);
if (in_array($mimeType, $allowedTypes) && move_uploaded_file($tmpName, $targetPath)) {
$evidencia_fotos_archivos[] = 'uploads/evidencias/fotos/' . $fileName;
}
}
}
}
// Procesar nuevos videos subidos
if (!empty($_FILES['evidencia_videos_archivos']['name'][0])) {
$uploadDir = '../uploads/evidencias/videos/';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0755, true);
}
foreach ($_FILES['evidencia_videos_archivos']['name'] as $key => $name) {
if ($_FILES['evidencia_videos_archivos']['error'][$key] === UPLOAD_ERR_OK) {
$tmpName = $_FILES['evidencia_videos_archivos']['tmp_name'][$key];
$fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
$targetPath = $uploadDir . $fileName;
$allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpName);
finfo_close($finfo);
if (in_array($mimeType, $allowedTypes) && move_uploaded_file($tmpName, $targetPath)) {
$evidencia_videos_archivos[] = 'uploads/evidencias/videos/' . $fileName;
}
}
}
}
$evidencia_fotos_archivos_json = !empty($evidencia_fotos_archivos) ? json_encode($evidencia_fotos_archivos) : null;
$evidencia_videos_archivos_json = !empty($evidencia_videos_archivos) ? json_encode($evidencia_videos_archivos) : null;
$estado_acta = trim($_POST['estado_acta'] ?? 'borrador');
// Verificar si ya existe registro para esta inspección
if ($infraccion_existente) {
// ACTUALIZAR
$fields = [
'tipo_acta' => $tipo_acta,
'fecha_acta' => $fecha_acta,
'hora_acta' => $hora_acta,
'lugar' => $lugar,
'agencia_local' => $agencia_local,
'persona_identificada' => $persona_identificada,
'vestimenta' => $vestimenta,
'otras_infracciones' => $otras_infracciones,
'observaciones' => $observaciones,
'funcionario_actuante' => $funcionario_actuante,
'responsable_presente' => $responsable_presente,
'testigos' => $testigos,
'sancion_tipo' => $sancion_tipo,
'sancion_fecha' => $sancion_fecha,
'sancion_resolucion' => $sancion_resolucion,
'sancion_monto' => $sancion_monto,
'sancion_observaciones' => $sancion_observaciones,
'notificacion_fecha' => $notificacion_fecha,
'notificacion_entregado_a' => $notificacion_entregado_a,
'notificacion_firma_responsable' => $notificacion_firma_responsable,
'fecha_limite_pago' => $fecha_limite_pago,
'multa_pagada' => $multa_pagada,
'evidencia_fotos_cantidad' => $evidencia_fotos_cantidad,
'evidencia_fotos_descripcion' => $evidencia_fotos_descripcion,
'evidencia_fotos_archivos' => $evidencia_fotos_archivos_json,
'evidencia_videos_cantidad' => $evidencia_videos_cantidad,
'evidencia_videos_soporte' => $evidencia_videos_soporte,
'evidencia_videos_archivos' => $evidencia_videos_archivos_json,
'evidencia_elementos_retenidos' => $evidencia_elementos_retenidos,
'estado_acta' => $estado_acta
];
// Agregar infracciones según tipo de acta
$infracciones_to_update = ($tipo_acta === 'agencias') ? $inf_agencias : $inf_vigilador;
$fields = array_merge($fields, $infracciones_to_update);
$set_clause = implode(', ', array_map(function($k) { return "$k = ?"; }, array_keys($fields)));
$values = array_values($fields);
$values[] = $inspeccion_id_post;
$stmt = $conn->prepare("UPDATE infracciones SET $set_clause WHERE inspeccion_id = ?");
$stmt->execute($values);
// Actualizar flag en inspecciones
$tiene_infracciones = (count(array_filter($infracciones_to_update)) > 0 || !empty($otras_infracciones)) ? 1 : 0;
$stmt_upd = $conn->prepare("UPDATE inspecciones SET tiene_infracciones = ? WHERE id = ?");
$stmt_upd->execute([$tiene_infracciones, $inspeccion_id_post]);
logAuditoria($conn, 'INFRACCIONES_ACTUALIZADAS', 'infracciones', $infraccion_existente['id'], [
'inspeccion_id' => $inspeccion_id_post,
'tipo_acta' => $tipo_acta,
'estado_acta' => $estado_acta,
'usuario' => $user['username']
], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>¡Infracciones actualizadas exitosamente!
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
} else {
// CREAR NUEVO REGISTRO
$fields = [
'inspeccion_id', 'tipo_acta', 'fecha_acta', 'hora_acta', 'lugar',
'agencia_local', 'persona_identificada', 'vestimenta',
'otras_infracciones', 'observaciones', 'funcionario_actuante',
'responsable_presente', 'testigos', 'activo',
'sancion_tipo', 'sancion_fecha', 'sancion_resolucion', 'sancion_monto',
'sancion_observaciones', 'notificacion_fecha', 'notificacion_entregado_a',
'notificacion_firma_responsable', 'fecha_limite_pago', 'multa_pagada',
'evidencia_fotos_cantidad', 'evidencia_fotos_descripcion', 'evidencia_fotos_archivos',
'evidencia_videos_cantidad', 'evidencia_videos_soporte', 'evidencia_videos_archivos',
'evidencia_elementos_retenidos', 'estado_acta'
];
$values = [
$inspeccion_id_post, $tipo_acta, $fecha_acta, $hora_acta, $lugar,
$agencia_local, $persona_identificada, $vestimenta,
$otras_infracciones, $observaciones, $funcionario_actuante,
$responsable_presente, $testigos, 1,
$sancion_tipo, $sancion_fecha, $sancion_resolucion, $sancion_monto,
$sancion_observaciones, $notificacion_fecha, $notificacion_entregado_a,
$notificacion_firma_responsable, $fecha_limite_pago, $multa_pagada,
$evidencia_fotos_cantidad, $evidencia_fotos_descripcion, $evidencia_fotos_archivos_json,
$evidencia_videos_cantidad, $evidencia_videos_soporte, $evidencia_videos_archivos_json,
$evidencia_elementos_retenidos, $estado_acta
];
// Agregar infracciones según tipo de acta
$infracciones_to_insert = ($tipo_acta === 'agencias') ? $inf_agencias : $inf_vigilador;
$fields = array_merge($fields, array_keys($infracciones_to_insert));
$values = array_merge($values, array_values($infracciones_to_insert));
$sql = "INSERT INTO infracciones (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")";
$stmt = $conn->prepare($sql);
$stmt->execute($values);
$infraccion_id = $conn->lastInsertId();
// Actualizar flag en inspecciones
$tiene_infracciones = (count(array_filter($infracciones_to_insert)) > 0 || !empty($otras_infracciones)) ? 1 : 0;
$stmt_upd = $conn->prepare("UPDATE inspecciones SET tiene_infracciones = ? WHERE id = ?");
$stmt_upd->execute([$tiene_infracciones, $inspeccion_id_post]);
logAuditoria($conn, 'INFRACCIONES_CREADAS', 'infracciones', $infraccion_id, [
'inspeccion_id' => $inspeccion_id_post,
'tipo_acta' => $tipo_acta,
'estado_acta' => $estado_acta,
'usuario' => $user['username']
], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>¡Infracciones registradas exitosamente!
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
}
// Redirigir de vuelta a inspecciones.php
header('Location: inspecciones.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_INFRACCIONES', 'infracciones', null, ['error' => $e->getMessage()], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'inspecciones.php');
exit;
}
}
// ============================================================================
// CONFIGURACIÓN DE LAS INFRACCIONES POR TIPO DE ACTA
// ============================================================================
$infracciones_config = [
'agencias' => [
'title' => 'ACTA DE CONTROL DE AGENCIAS',
'subtitle' => 'Acta de Infracción a La Ley I N° 168 y Decreto N° 1220/19',
'fields' => [
['key' => 'agencia_local', 'label' => 'AGENCIA', 'required' => true],
['key' => 'persona_identificada', 'label' => 'PERSONA IDENTIFICADA', 'required' => true],
],
'infracciones' => [
['key' => 'inf_fichero', 'label' => 'F/ fichero'],
['key' => 'inf_libros_inspecciones', 'label' => 'F/ libros inspecciones'],
['key' => 'inf_libro_personal', 'label' => 'F/ libro personal'],
['key' => 'inf_autorizacion_cnc', 'label' => 'F/ autorización C.N.C'],
['key' => 'inf_inscripcion_renar', 'label' => 'F/ Inscripción RENAR'],
['key' => 'inf_registro_usuario', 'label' => 'F/ registro usuario'],
['key' => 'inf_credencial', 'label' => 'F/ credencial'],
['key' => 'inf_archivos_investigaciones', 'label' => 'F/ archivos De Investigaciones'],
['key' => 'inf_hab_jefatura', 'label' => 'F/ hab. Jefatura'],
['key' => 'inf_vehiculo_balizas', 'label' => 'P/ Vehículo Balizas o Ident.'],
['key' => 'inf_pago_afip', 'label' => 'F/ pago AFIP'],
['key' => 'inf_pago_vehiculo', 'label' => 'F/ pago Vehículo'],
['key' => 'inf_pago_hab_comercial', 'label' => 'F/ pago Hab. A. Comercial'],
['key' => 'inf_pago_dir_tecnico', 'label' => 'F/ pago Director Técnico'],
['key' => 'inf_reglamento_estatuto', 'label' => 'F/ reglamento y estatuto'],
]
],
'vigilador' => [
'title' => 'ACTA VIGILADOR',
'subtitle' => 'Acta de Infracción a La Ley I N° 168 y Decreto N° 1220/19',
'fields' => [
['key' => 'agencia_local', 'label' => 'LOCAL COMERCIAL', 'required' => true],
['key' => 'persona_identificada', 'label' => 'PERSONA IDENTIFICADA', 'required' => true],
['key' => 'vestimenta', 'label' => 'VESTIMENTA', 'required' => false],
],
'infracciones' => [
['key' => 'inf_credencial_directivo', 'label' => 'F/Credencial Directivo o Vigilador'],
['key' => 'inf_elementos_sujecion', 'label' => 'Posee elementos de sujeción u aprensión'],
['key' => 'inf_uniforme_empresa', 'label' => 'corresponde uniforme c/ empresa'],
['key' => 'inf_autorizacion_vigilador', 'label' => 'F/ autorización'],
['key' => 'inf_arma', 'label' => 'F/ Arma'],
['key' => 'inf_otras_faltas', 'label' => 'otras Faltas'],
['key' => 'inf_tarjeta_legitimo_usuario', 'label' => 'F/ tarjeta Legitimo Usuario'],
['key' => 'inf_tarjeta_tenencia', 'label' => 'Tarjeta Tenencia'],
['key' => 'inf_tarjeta_portacion', 'label' => 'Tarjeta de portación'],
]
]
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registro de Infracciones - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
.checklist-grid {
display: grid;
grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
gap: 10px;
margin-bottom: 20px;
}
.checklist-item {
display: flex;
align-items: center;
gap: 8px;
padding: 8px 12px;
background: #f8f9fa;
border: 1px solid #dee2e6;
border-radius: 4px;
cursor: pointer;
transition: all 0.2s;
}
.checklist-item:hover {
background: #e9ecef;
border-color: #0d6efd;
}
.checklist-item input[type="checkbox"] {
width: 18px;
height: 18px;
cursor: pointer;
}
.checklist-item label {
margin: 0;
font-size: 0.9rem;
cursor: pointer;
flex: 1;
}
.acta-selector {
display: flex;
gap: 15px;
margin-bottom: 25px;
flex-wrap: wrap;
}
.acta-card {
flex: 1;
min-width: 250px;
border: 2px solid #dee2e6;
border-radius: 8px;
padding: 20px;
text-align: center;
cursor: pointer;
transition: all 0.3s;
background: #fff;
}
.acta-card:hover {
border-color: #0d6efd;
box-shadow: 0 4px 12px rgba(13, 110, 253, 0.15);
}
.acta-card.selected {
border-color: #0d6efd;
background: #e7f1ff;
}
.acta-card i {
font-size: 2rem;
color: #0d6efd;
margin-bottom: 10px;
}
.acta-card h5 {
font-size: 1rem;
font-weight: 600;
margin: 0;
}
.acta-card small {
display: block;
color: #6c757d;
font-size: 0.8rem;
margin-top: 5px;
}
.legal-section {
background: #f8f9fa;
border-left: 4px solid #0d6efd;
padding: 15px;
margin: 20px 0;
font-size: 0.85rem;
}
.legal-section h6 {
color: #0d6efd;
font-weight: 600;
margin-bottom: 10px;
}
.signature-area {
display: flex;
gap: 30px;
margin-top: 30px;
padding-top: 20px;
border-top: 2px dashed #dee2e6;
}
.signature-box {
flex: 1;
}
.signature-box p {
margin-bottom: 5px;
font-weight: 600;
}
.signature-box .line {
border-bottom: 1px solid #000;
margin: 40px 0 5px 0;
}
.signature-box small {
color: #6c757d;
}
.info-badge {
background: #e7f1ff;
border: 1px solid #0d6efd;
color: #0d6efd;
padding: 10px 15px;
border-radius: 4px;
margin-bottom: 20px;
font-size: 0.9rem;
}
.info-badge i {
margin-right: 8px;
}
#formContent {
display: none;
}
#formContent.show {
display: block;
}
.acta-header {
background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
color: white;
padding: 20px;
border-radius: 4px 4px 0 0;
margin: -20px -20px 20px -20px;
cursor: pointer;
}
.acta-header:hover {
background: linear-gradient(135deg, #0b5ed7 0%, #0952bc 100%);
}
.acta-header h4 {
margin: 0 0 5px 0;
font-weight: 700;
}
.acta-header small {
opacity: 0.9;
}
.acta-number {
display: flex;
align-items: center;
gap: 10px;
margin-bottom: 15px;
}
.acta-number input {
max-width: 120px;
}
.toggle-icon {
transition: transform 0.3s ease;
}
.toggle-icon.collapsed {
transform: rotate(-90deg);
}
/* Estilos para vista de impresión */
@media print {
body * {
visibility: hidden;
}
#printArea, #printArea * {
visibility: visible;
}
#printArea {
position: absolute;
left: 0;
top: 0;
width: 100%;
padding: 20px;
background: white;
color: black;
}
.no-print {
display: none !important;
}
.section-box, .acta-header {
box-shadow: none !important;
border: 1px solid #000 !important;
}
.checklist-item {
border: 1px solid #000 !important;
background: white !important;
}
}
#printArea {
display: none;
background: white;
padding: 30px;
border: 1px solid #dee2e6;
margin-bottom: 20px;
}
#printArea.show {
display: block;
}
.print-header {
text-align: center;
border-bottom: 3px double #000;
padding-bottom: 15px;
margin-bottom: 20px;
}
.print-header h3 {
margin: 0;
color: #000;
}
.print-header .subtitulo {
font-size: 0.9rem;
color: #333;
margin: 5px 0;
}
.print-body {
font-size: 0.95rem;
line-height: 1.6;
}
.print-body table {
width: 100%;
border-collapse: collapse;
margin: 10px 0;
}
.print-body table th, .print-body table td {
border: 1px solid #000;
padding: 8px;
text-align: left;
}
.print-body table th {
background: #f8f9fa;
font-weight: 600;
}
.print-checklist {
display: grid;
grid-template-columns: repeat(2, 1fr);
gap: 5px;
margin: 10px 0;
}
.print-checklist-item {
display: flex;
align-items: center;
gap: 5px;
}
.print-checklist-item.checked::before {
content: "☑";
color: #0d6efd;
font-weight: bold;
}
.print-checklist-item.unchecked::before {
content: "☐";
color: #666;
}
.print-signature {
margin-top: 40px;
display: flex;
justify-content: space-between;
gap: 30px;
}
.print-signature-box {
flex: 1;
text-align: center;
}
.print-signature-box .line {
border-top: 1px solid #000;
margin: 50px 0 5px 0;
}
.print-footer {
margin-top: 30px;
padding-top: 15px;
border-top: 1px solid #000;
font-size: 0.85rem;
text-align: center;
color: #333;
}
.estado-badge {
display: inline-block;
padding: 4px 12px;
border-radius: 20px;
font-size: 0.8rem;
font-weight: 500;
}
.estado-borrador { background: #e9ecef; color: #495057; }
.estado-elevado_jefatura { background: #fff3cd; color: #856404; }
.estado-sancionado { background: #f8d7da; color: #721c24; }
.estado-notificado { background: #d1ecf1; color: #0c5460; }
.estado-multa_pagada { background: #d4edda; color: #155724; }
.estado-cerrado { background: #6c757d; color: #ffffff; }
.sancion-section, .notificacion-section, .evidencia-section {
background: #fff;
border: 1px solid #dee2e6;
border-radius: 4px;
padding: 15px;
margin: 15px 0;
}
.sancion-section h5, .notificacion-section h5, .evidencia-section h5 {
color: #0d6efd;
margin-bottom: 10px;
font-size: 1.1rem;
}
.action-buttons {
display: flex;
gap: 10px;
flex-wrap: wrap;
margin: 20px 0;
}
.action-buttons .btn {
display: flex;
align-items: center;
gap: 5px;
}
.evidencia-files-list {
margin-top: 8px;
font-size: 0.85rem;
}
.evidencia-files-list a {
display: block;
color: #0d6efd;
text-decoration: none;
}
.evidencia-files-list a:hover {
text-decoration: underline;
}
</style>
</head>
<body class="sidebar-collapsed">
<?php $page_title = 'Registro de Infracciones'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
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
<!-- SELECCIÓN DE TIPO DE ACTA (MODIFICADO: LISTA DESPLEGABLE) -->
<div class="section-box">
<?php if ($inspeccion_data): ?>
<div class="info-badge">
<i class="fas fa-info-circle"></i>
<strong>Inspección vinculada:</strong>
Acta N° <?php echo htmlspecialchars($inspeccion_data['numero_acta'] ?? 'N/A'); ?>/<?php echo htmlspecialchars($inspeccion_data['numero_anio'] ?? '-'); ?> -
<?php echo htmlspecialchars($inspeccion_data['empresa_nombre'] ?? 'N/A'); ?>
<?php if (!empty($inspeccion_data['sucursal_nombre'])): ?>
- <?php echo htmlspecialchars($inspeccion_data['sucursal_nombre']); ?>
<?php endif; ?>
</div>

<?php endif; ?>
<div class="section-title">
<i class="fas fa-file-alt me-2"></i>Seleccionar Tipo de Acta
</div>
<div class="mb-3">
<label class="form-label" for="tipoActaSelect">Tipo de Acta *</label>
<select name="tipo_acta_select" id="tipoActaSelect" class="form-select" required>
<option value="">Seleccione un tipo de acta...</option>
<option value="agencias" <?php echo (!$infraccion_existente || $infraccion_existente['tipo_acta'] === 'agencias') ? 'selected' : ''; ?>>ACTA DE CONTROL DE AGENCIAS - Ley I N° 168 - Decreto 1220/19</option>
<option value="vigilador" <?php echo ($infraccion_existente && $infraccion_existente['tipo_acta'] === 'vigilador') ? 'selected' : ''; ?>>ACTA VIGILADOR - Ley I N° 168 - Decreto 1220/19</option>
</select>
<small class="text-muted">Seleccione el tipo de acta según corresponda: Agencias para inspección de empresas, Vigilador para control de personal</small>
</div>
</div>
<!-- FORMULARIO DE INFRACCIONES -->
<form method="POST" action="" id="infraccionesForm" enctype="multipart/form-data">
<input type="hidden" name="tipo_acta" id="tipoActaInput"
value="<?php echo htmlspecialchars($infraccion_existente['tipo_acta'] ?? 'agencias'); ?>">
<input type="hidden" name="inspeccion_id" id="inspeccionIdInput" value="<?php echo $inspeccion_id; ?>">
<div id="formContent" class="collapse <?php echo $infraccion_existente ? 'show' : ''; ?>">
<div class="section-box">
<!-- HEADER DEL ACTA -->
<div class="acta-header" data-bs-toggle="collapse" data-bs-target="#formContentBody" aria-expanded="<?php echo $infraccion_existente ? 'true' : 'false'; ?>" aria-controls="formContentBody">
<div class="d-flex justify-content-between align-items-center">
<div>
<h4 id="actaTitle">ACTA DE CONTROL DE AGENCIAS</h4>
<small id="actaSubtitle">Acta de Infracción a La Ley I N° 168 y Decreto N° 1220/19</small>
</div>
<button type="button" class="btn btn-sm btn-outline-light toggle-btn">
<i class="fas fa-chevron-down toggle-icon" id="toggleIcon"></i>
</button>
</div>
<div class="acta-number mt-3">
<label class="form-label text-white mb-0">Acta N°</label>
<input type="text" class="form-control form-control-sm" style="max-width: 100px;"
value="<?php echo htmlspecialchars($inspeccion_data['numero_acta'] ?? ''); ?>" readonly>
<span class="text-white">/</span>
<input type="text" class="form-control form-control-sm" style="max-width: 80px;"
value="<?php echo htmlspecialchars($inspeccion_data['numero_anio'] ?? date('Y')); ?>" readonly>
</div>
</div>
<!-- CUERPO COLAPSABLE DEL FORMULARIO -->
<div id="formContentBody" class="collapse <?php echo $infraccion_existente ? 'show' : ''; ?>">
<!-- DATOS GENERALES -->
<div class="row g-3">
<div class="col-md-3">
<label class="form-label">Fecha</label>
<input type="date" name="fecha_acta" id="fechaActa" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['fecha_acta'] ?? $inspeccion_data['fecha_inspeccion'] ?? date('Y-m-d')); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Plazo otorgado (10 días hábiles)</label>
<input type="text" id="plazoOtorgado" class="form-control" readonly
placeholder="Se calcula automáticamente"
value="<?php echo !empty($infraccion_existente['fecha_acta']) ? calcularPlazoHabil($infraccion_existente['fecha_acta']) : ''; ?>">
<small class="text-muted">Plazo para regularizar conforme Art. 25 Ley I N° 168</small>
</div>
<div class="col-md-3">
<label class="form-label">Hora</label>
<input type="time" name="hora_acta" id="horaActa" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['hora_acta'] ?? $inspeccion_data['hora_inspeccion'] ?? date('H:i')); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Lugar</label>
<input type="text" name="lugar" id="lugar" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['lugar'] ?? $inspeccion_data['lugar'] ?? ''); ?>">
</div>
<!-- Campos dinámicos según tipo de acta -->
<div id="dynamicFields">
<!-- Se llena con JavaScript -->
</div>
<div class="col-md-6">
<label class="form-label">Funcionario Actuante</label>
<input type="text" name="funcionario_actuante" id="funcionarioActuante" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['funcionario_actuante'] ?? $inspeccion_data['funcionario_nombre'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Responsable Presente / Testigos</label>
<input type="text" name="responsable_presente" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['responsable_presente'] ?? $inspeccion_data['responsable_nombre'] ?? ''); ?>">
</div>
<div class="col-md-12">
<label class="form-label">Testigos (si corresponde)</label>
<input type="text" name="testigos" class="form-control"
placeholder="Nombre y DNI de testigos - Para casos de negativa de firma"
value="<?php echo htmlspecialchars($infraccion_existente['testigos'] ?? $inspeccion_data['testigos_nombres'] ?? ''); ?>">
</div>
</div>
<!-- CHECKLIST DE INFRACCIONES -->
<div class="section-title mt-4">
<i class="fas fa-exclamation-triangle me-2 text-warning"></i>INFRACCIONES
</div>
<div id="infraccionesChecklist" class="checklist-grid">
<!-- Se llena con JavaScript según tipo de acta -->
</div>
<!-- OTRAS INFRACCIONES -->
<div class="mb-3">
<label class="form-label">OTRAS INFRACCIONES</label>
<textarea name="otras_infracciones" class="form-control" rows="2"><?php echo htmlspecialchars($infraccion_existente['otras_infracciones'] ?? ''); ?></textarea>
</div>
<!-- OBSERVACIONES -->
<div class="mb-3">
<label class="form-label">OBSERVACIONES</label>
<textarea name="observaciones" class="form-control" rows="3"><?php echo htmlspecialchars($infraccion_existente['observaciones'] ?? ''); ?></textarea>
</div>
<!-- EVIDENCIA Y MEDIDAS CAUTELARES (Art. 23 - Ley I-168) -->
<div class="evidencia-section">
<h5><i class="fas fa-camera me-2"></i>EVIDENCIA Y MEDIDAS CAUTELARES (Art. 23 - Ley I-168)</h5>
<div class="row g-3">
<!-- Fotos -->
<div class="col-md-6">
<label class="form-label">Fotografías (cantidad)</label>
<input type="number" name="evidencia_fotos_cantidad" class="form-control" min="0"
placeholder="0"
value="<?php echo htmlspecialchars($infraccion_existente['evidencia_fotos_cantidad'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Subir Fotografías</label>
<input type="file" name="evidencia_fotos_archivos[]" class="form-control" multiple accept="image/*">
<small class="text-muted">Puede seleccionar múltiples archivos (JPG, PNG, GIF, WEBP)</small>
<?php if (!empty($infraccion_existente['evidencia_fotos_archivos'])): ?>
<div class="evidencia-files-list">
<small class="text-muted d-block mt-1"><strong>Archivos existentes:</strong></small>
<?php
$fotos_existentes = json_decode($infraccion_existente['evidencia_fotos_archivos'], true);
if (is_array($fotos_existentes)):
foreach($fotos_existentes as $archivo):
?>
<a href="../<?php echo htmlspecialchars($archivo); ?>" target="_blank" class="d-block">
<i class="fas fa-image me-1"></i><?php echo htmlspecialchars(basename($archivo)); ?>
</a>
<?php
endforeach;
endif;
?>
</div>
<?php endif; ?>
</div>
<div class="col-md-12">
<label class="form-label">Descripción de Fotografías</label>
<textarea name="evidencia_fotos_descripcion" class="form-control" rows="2"
placeholder="Descripción de las fotografías tomadas"><?php echo htmlspecialchars($infraccion_existente['evidencia_fotos_descripcion'] ?? ''); ?></textarea>
</div>
<!-- Videos -->
<div class="col-md-6">
<label class="form-label">Videos (cantidad)</label>
<input type="number" name="evidencia_videos_cantidad" class="form-control" min="0"
placeholder="0"
value="<?php echo htmlspecialchars($infraccion_existente['evidencia_videos_cantidad'] ?? ''); ?>">
</div>
<div class="col-md-6">
<label class="form-label">Subir Videos</label>
<input type="file" name="evidencia_videos_archivos[]" class="form-control" multiple accept="video/*">
<small class="text-muted">Puede seleccionar múltiples archivos (MP4, WebM, OGG, MOV)</small>
<?php if (!empty($infraccion_existente['evidencia_videos_archivos'])): ?>
<div class="evidencia-files-list">
<small class="text-muted d-block mt-1"><strong>Archivos existentes:</strong></small>
<?php
$videos_existentes = json_decode($infraccion_existente['evidencia_videos_archivos'], true);
if (is_array($videos_existentes)):
foreach($videos_existentes as $archivo):
?>
<a href="../<?php echo htmlspecialchars($archivo); ?>" target="_blank" class="d-block">
<i class="fas fa-video me-1"></i><?php echo htmlspecialchars(basename($archivo)); ?>
</a>
<?php
endforeach;
endif;
?>
</div>
<?php endif; ?>
</div>
<div class="col-md-4">
<label class="form-label">Soporte de Videos</label>
<input type="text" name="evidencia_videos_soporte" class="form-control"
placeholder="USB, DVD, nube, etc."
value="<?php echo htmlspecialchars($infraccion_existente['evidencia_videos_soporte'] ?? ''); ?>">
</div>
<div class="col-md-12">
<label class="form-label">Elementos Retenidos</label>
<textarea name="evidencia_elementos_retenidos" class="form-control" rows="2"
placeholder="Armas, credenciales, documentos, etc."><?php echo htmlspecialchars($infraccion_existente['evidencia_elementos_retenidos'] ?? ''); ?></textarea>
</div>
</div>
</div>
<!-- SECCIÓN DE SANCIÓN (visible si el acta fue elevado a jefatura) -->
<?php if ($infraccion_existente && in_array($infraccion_existente['estado_acta'] ?? '', ['elevado_jefatura', 'sancionado', 'notificado', 'multa_pagada', 'cerrado'])): ?>
<div class="sancion-section">
<h5><i class="fas fa-gavel me-2"></i>Registro de Sanción</h5>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Tipo de Sanción</label>
<select name="sancion_tipo" class="form-select">
<option value="">Seleccione...</option>
<option value="Apercibimiento" <?php echo ($infraccion_existente['sancion_tipo'] ?? '') === 'Apercibimiento' ? 'selected' : ''; ?>>Apercibimiento</option>
<option value="Multa" <?php echo ($infraccion_existente['sancion_tipo'] ?? '') === 'Multa' ? 'selected' : ''; ?>>Multa</option>
<option value="Suspensión" <?php echo ($infraccion_existente['sancion_tipo'] ?? '') === 'Suspensión' ? 'selected' : ''; ?>>Suspensión</option>
<option value="Cancelación" <?php echo ($infraccion_existente['sancion_tipo'] ?? '') === 'Cancelación' ? 'selected' : ''; ?>>Cancelación de Habilitación</option>
<option value="Otra" <?php echo ($infraccion_existente['sancion_tipo'] ?? '') === 'Otra' ? 'selected' : ''; ?>>Otra</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Fecha de Sanción</label>
<input type="date" name="sancion_fecha" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['sancion_fecha'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">N° Resolución</label>
<input type="text" name="sancion_resolucion" class="form-control"
placeholder="Ej: RES-2024-001"
value="<?php echo htmlspecialchars($infraccion_existente['sancion_resolucion'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Monto de Multa ($)</label>
<input type="number" step="0.01" name="sancion_monto" class="form-control"
placeholder="0.00"
value="<?php echo htmlspecialchars($infraccion_existente['sancion_monto'] ?? ''); ?>">
</div>
<div class="col-md-12">
<label class="form-label">Observaciones de la Sanción</label>
<textarea name="sancion_observaciones" class="form-control" rows="2"><?php echo htmlspecialchars($infraccion_existente['sancion_observaciones'] ?? ''); ?></textarea>
</div>
</div>
</div>
<?php endif; ?>
<!-- SECCIÓN DE NOTIFICACIÓN (visible si hay sanción registrada) -->
<?php if ($infraccion_existente && !empty($infraccion_existente['sancion_tipo'])): ?>
<div class="notificacion-section">
<h5><i class="fas fa-bell me-2"></i>Notificación a la Empresa</h5>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Fecha de Notificación</label>
<input type="date" name="notificacion_fecha" id="notificacionFecha" class="form-control"
value="<?php echo htmlspecialchars($infraccion_existente['notificacion_fecha'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Decreto N° 1220/19</label>
<input type="text" id="fechaLimitePago" class="form-control" readonly
placeholder="Se calcula automáticamente"
value="<?php echo !empty($infraccion_existente['fecha_limite_pago']) ? htmlspecialchars($infraccion_existente['fecha_limite_pago']) : ''; ?>">
<small class="text-muted">Plazo para abonar la multa conforme Ley 19.549</small>
</div>
<div class="col-md-4">
<label class="form-label">¿Multa Pagada?</label>
<select name="multa_pagada" class="form-select">
<option value="0" <?php echo empty($infraccion_existente['multa_pagada']) ? 'selected' : ''; ?>>No</option>
<option value="1" <?php echo !empty($infraccion_existente['multa_pagada']) ? 'selected' : ''; ?>>Sí</option>
</select>
</div>
<div class="col-md-8">
<label class="form-label">Entregado a (Nombre y Cargo)</label>
<input type="text" name="notificacion_entregado_a" class="form-control"
placeholder="Nombre completo y cargo de quien recibe la notificación"
value="<?php echo htmlspecialchars($infraccion_existente['notificacion_entregado_a'] ?? ''); ?>">
</div>
<div class="col-md-12">
<div class="form-check">
<input type="checkbox" name="notificacion_firma_responsable" class="form-check-input" id="firmaResponsable"
value="1" <?php echo !empty($infraccion_existente['notificacion_firma_responsable']) ? 'checked' : ''; ?>>
<label class="form-check-label" for="firmaResponsable">
El responsable de la empresa firmó la notificación de la falta
</label>
</div>
</div>
</div>
</div>
<?php endif; ?>
<!-- ESTADO DEL ACTA -->
<div class="mb-3">
<label class="form-label">Estado del Acta</label>
<select name="estado_acta" class="form-select">
<option value="borrador" <?php echo ($infraccion_existente['estado_acta'] ?? 'borrador') === 'borrador' ? 'selected' : ''; ?>>Borrador</option>
<option value="elevado_jefatura" <?php echo ($infraccion_existente['estado_acta'] ?? '') === 'elevado_jefatura' ? 'selected' : ''; ?>>Elevado a Jefatura</option>
<option value="sancionado" <?php echo ($infraccion_existente['estado_acta'] ?? '') === 'sancionado' ? 'selected' : ''; ?>>Sancionado</option>
<option value="notificado" <?php echo ($infraccion_existente['estado_acta'] ?? '') === 'notificado' ? 'selected' : ''; ?>>Notificado a Empresa</option>
<option value="multa_pagada" <?php echo ($infraccion_existente['estado_acta'] ?? '') === 'multa_pagada' ? 'selected' : ''; ?>>Multa Pagada</option>
<option value="cerrado" <?php echo ($infraccion_existente['estado_acta'] ?? '') === 'cerrado' ? 'selected' : ''; ?>>Cerrado</option>
</select>
<small class="text-muted">Seleccione el estado actual del trámite administrativo</small>
</div>
<!-- BOTONES DE ACCIÓN -->
<div class="d-flex justify-content-end gap-2 mt-4">
<a href="inspecciones.php" class="btn btn-secondary">
<i class="fas fa-times me-2"></i>Cancelar
</a>
<button type="button" class="btn btn-primary" id="btnImprimirElevacion" <?php echo (!$infraccion_existente || ($infraccion_existente['estado_acta'] ?? '') !== 'elevado_jefatura') ? 'disabled' : ''; ?>>
<i class="fas fa-file-pdf me-2"></i>Crear PDF Elevación a Jefatura
</button>
<button type="button" class="btn btn-warning" id="btnImprimirNotificacion" <?php echo (!$infraccion_existente || empty($infraccion_existente['sancion_tipo'])) ? 'disabled' : ''; ?>>
<i class="fas fa-file-pdf me-2"></i>Crear PDF de Notificación
</button>
<button type="submit" class="btn btn-success">
<i class="fas fa-save me-2"></i>
<?php echo $infraccion_existente ? 'Actualizar Infracciones' : 'Registrar Infracciones'; ?>
</button>
</div>
</div>
</div>
</div>
</form>
<!-- ÁREA DE VISTA PREVIA PARA IMPRESIÓN -->
<div id="printArea" class="section-box">
<div class="print-header">
<h3 id="printActaTitle">ACTA DE CONTROL DE AGENCIAS</h3>
<div class="subtitulo" id="printActaSubtitle">Acta de Infracción a La Ley I N° 168 y Decreto N° 1220/19</div>
<div class="subtitulo">Provincia del Chubut - Autoridad de Aplicación: Jefatura de Policía</div>
<div class="mt-2"><strong>Acta N°:</strong> <span id="printNumeroActa"></span>/<span id="printAnioActa"></span></div>
</div>
<div class="print-body">
<!-- Datos generales -->
<div class="mb-3">
<strong>Fecha:</strong> <span id="printFecha"></span> &nbsp;&nbsp;
<strong>Plazo otorgado:</strong> <span id="printPlazo"></span> &nbsp;&nbsp;
<strong>Hora:</strong> <span id="printHora"></span> &nbsp;&nbsp;
<strong>Lugar:</strong> <span id="printLugar"></span>
</div>
<div class="mb-3">
<strong>Agencia/Local:</strong> <span id="printAgencia"></span><br>
<strong>Persona Identificada:</strong> <span id="printPersona"></span><br>
<strong>Vestimenta:</strong> <span id="printVestimenta"></span>
</div>
<div class="mb-3">
<strong>Funcionario Actuante:</strong> <span id="printFuncionario"></span><br>
<strong>Responsable Presente:</strong> <span id="printResponsable"></span><br>
<strong>Testigos:</strong> <span id="printTestigos"></span>
</div>
<!-- Infracciones marcadas -->
<div class="mb-3">
<strong>INFRACCIONES CONSTATADAS:</strong>
<div id="printInfracciones" class="print-checklist"></div>
</div>
<!-- Otras infracciones y observaciones -->
<div class="mb-3">
<strong>Otras Infracciones:</strong><br>
<span id="printOtrasInfracciones"></span>
</div>
<div class="mb-3">
<strong>Observaciones:</strong><br>
<span id="printObservaciones"></span>
</div>
<!-- Evidencia y Medidas Cautelares -->
<div id="printEvidencia" class="mb-3" style="display:none;">
<strong>EVIDENCIA Y MEDIDAS CAUTELARES (Art. 23 - Ley I-168):</strong><br>
<strong>Fotografías:</strong> <span id="printFotosCantidad"></span> - <span id="printFotosDescripcion"></span><br>
<strong>Archivos de Fotos:</strong> <span id="printFotosArchivos"></span><br>
<strong>Videos:</strong> <span id="printVideosCantidad"></span> - Soporte: <span id="printVideosSoporte"></span><br>
<strong>Archivos de Videos:</strong> <span id="printVideosArchivos"></span><br>
<strong>Elementos Retenidos:</strong> <span id="printElementosRetenidos"></span>
</div>
<!-- Sección de sanción (si existe) -->
<div id="printSancion" class="mb-3" style="display:none;">
<strong>SANCIÓN APLICADA:</strong><br>
<strong>Tipo:</strong> <span id="printSancionTipo"></span><br>
<strong>Fecha:</strong> <span id="printSancionFecha"></span><br>
<strong>Resolución N°:</strong> <span id="printSancionResolucion"></span><br>
<strong>Monto:</strong> $<span id="printSancionMonto"></span><br>
<strong>Observaciones:</strong> <span id="printSancionObservaciones"></span>
</div>
<!-- Sección de notificación (si existe) -->
<div id="printNotificacion" class="mb-3" style="display:none;">
<strong>NOTIFICACIÓN A LA EMPRESA:</strong><br>
<strong>Fecha de Notificación:</strong> <span id="printNotificacionFecha"></span><br>
<strong>Decreto N° 1220/19:</strong> <span id="printFechaLimitePago"></span><br>
<strong>Multa Pagada:</strong> <span id="printMultaPagada"></span><br>
<strong>Entregado a:</strong> <span id="printNotificacionEntregado"></span><br>
<strong>Firma del Responsable:</strong> <span id="printNotificacionFirma"></span>
</div>
<!-- Firmas -->
<div class="print-signature">
<div class="print-signature-box">
<div class="line"></div>
<small>Firma Funcionario Actuante</small>
</div>
<div class="print-signature-box">
<div class="line"></div>
<small>Firma Responsable Inspeccionado</small>
</div>
</div>
<!-- Footer legal -->
<div class="print-footer">
<p class="mb-1"><strong>Plazo para regularizar:</strong> 10 días hábiles desde la notificación, conforme Art. 25 Ley I N° 168 y Ley 19.549 de Procedimientos Administrativos.</p>
<p class="mb-1"><strong>Plazo para abonar multa:</strong> 15 días hábiles desde la notificación, conforme Ley 19.549 de Procedimientos Administrativos.</p>
<p class="mb-1"><strong>Lugar de presentación de descargo:</strong> Agencia de Seguridad Privada, Policía de la Provincia del Chubut, calles Pedro Martínez y Rivadavia, Rawson (CP 9103), Tel: 2804 482666 int. 1320.</p>
<p class="mb-0 small">La presente acta se labra en dos (2) ejemplares de igual tenor. Art. 25 Ley I N° 168 - Recursos admisibles: reconsideración y jerárquico, conforme Ley 19.549.</p>
</div>
</div>
</div>
</div>
</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
const config = <?php echo json_encode($infracciones_config); ?>;
const existingData = <?php echo json_encode($infraccion_existente ?? []); ?>;
const inspeccionData = <?php echo json_encode($inspeccion_data ?? []); ?>;
const tipoActaSelect = document.getElementById('tipoActaSelect');
const tipoActaInput = document.getElementById('tipoActaInput');
const formContent = document.getElementById('formContent');
const formContentBody = document.getElementById('formContentBody');
const actaHeader = document.querySelector('.acta-header');
const actaTitle = document.getElementById('actaTitle');
const actaSubtitle = document.getElementById('actaSubtitle');
const dynamicFields = document.getElementById('dynamicFields');
const infraccionesChecklist = document.getElementById('infraccionesChecklist');
const inspeccionIdInput = document.getElementById('inspeccionIdInput');
const printArea = document.getElementById('printArea');
const btnImprimirElevacion = document.getElementById('btnImprimirElevacion');
const btnImprimirNotificacion = document.getElementById('btnImprimirNotificacion');
const toggleIcon = document.getElementById('toggleIcon');
const fechaActaInput = document.getElementById('fechaActa');
const plazoOtorgadoInput = document.getElementById('plazoOtorgado');
const notificacionFechaInput = document.getElementById('notificacionFecha');
const fechaLimitePagoInput = document.getElementById('fechaLimitePago');
// Campos a auto-completar
const campoAgencia = document.querySelector('[name="agencia_local"]');
const campoPersona = document.querySelector('[name="persona_identificada"]');
const campoFuncionario = document.getElementById('funcionarioActuante');
const campoFecha = document.getElementById('fechaActa');
const campoHora = document.getElementById('horaActa');
const campoLugar = document.getElementById('lugar');
// Función para calcular días hábiles (excluye sábados y domingos)
function calcularDiasHabiles(fechaInicio, dias) {
let fecha = new Date(fechaInicio);
let diasContados = 0;
while (diasContados < dias) {
fecha.setDate(fecha.getDate() + 1);
let diaSemana = fecha.getDay();
// 0 = Domingo, 6 = Sábado
if (diaSemana !== 0 && diaSemana !== 6) {
diasContados++;
}
}
return fecha.toISOString().split('T')[0];
}
// Función para formatear fecha para mostrar
function formatearFecha(fechaStr) {
if (!fechaStr) return '';
const fecha = new Date(fechaStr + 'T00:00:00');
const opciones = { day: '2-digit', month: 'long', year: 'numeric' };
return fecha.toLocaleDateString('es-AR', opciones).charAt(0).toUpperCase() +
fecha.toLocaleDateString('es-AR', opciones).slice(1);
}
// Actualizar plazo otorgado cuando cambia la fecha del acta
function actualizarPlazoOtorgado() {
const fechaActa = fechaActaInput.value;
if (fechaActa) {
const fechaPlazo = calcularDiasHabiles(fechaActa, 10);
plazoOtorgadoInput.value = formatearFecha(fechaPlazo);
// También actualizar en vista de impresión
document.getElementById('printPlazo').textContent = formatearFecha(fechaPlazo);
} else {
plazoOtorgadoInput.value = '';
document.getElementById('printPlazo').textContent = '';
}
}
// Actualizar Decreto N° 1220/19 cuando cambia la fecha de notificación - CORREGIDO
function actualizarFechaLimitePago() {
const notificacionInput = document.getElementById('notificacionFecha');
const limiteInput = document.getElementById('fechaLimitePago');
const printFechaLimite = document.getElementById('printFechaLimitePago');
const fechaNotificacion = notificacionInput?.value;
if (fechaNotificacion) {
const fechaLimite = calcularDiasHabiles(fechaNotificacion, 15);
if (limiteInput) {
limiteInput.value = formatearFecha(fechaLimite);
}
if (printFechaLimite) {
printFechaLimite.textContent = formatearFecha(fechaLimite);
}
} else {
if (limiteInput) {
limiteInput.value = '';
}
if (printFechaLimite) {
printFechaLimite.textContent = '';
}
}
}
// Event listener para cambio de fecha
if (fechaActaInput) {
fechaActaInput.addEventListener('change', actualizarPlazoOtorgado);
}
// Event listener para cambio de fecha de notificación - CORREGIDO: más robusto
function setupNotificacionListeners() {
const notificacionInput = document.getElementById('notificacionFecha');
if (notificacionInput) {
notificacionInput.removeEventListener('change', actualizarFechaLimitePago);
notificacionInput.removeEventListener('input', actualizarFechaLimitePago);
notificacionInput.addEventListener('change', actualizarFechaLimitePago);
notificacionInput.addEventListener('input', actualizarFechaLimitePago);
}
}
setupNotificacionListeners();
// ⚠️ CORRECCIÓN: Forzar selección inicial si no hay datos existentes
if (!existingData.tipo_acta) {
tipoActaInput.value = 'agencias';
tipoActaSelect.value = 'agencias';
formContent.classList.add('show');
formContentBody.classList.add('show');
renderForm('agencias');
actualizarPlazoOtorgado();
} else {
formContent.classList.add('show');
formContentBody.classList.add('show');
tipoActaSelect.value = existingData.tipo_acta;
renderForm(existingData.tipo_acta);
actualizarPlazoOtorgado();
if (existingData.notificacion_fecha) {
actualizarFechaLimitePago();
}
}
// Toggle del icono al expandir/colapsar
formContentBody.addEventListener('shown.bs.collapse', function() {
toggleIcon.classList.remove('collapsed');
});
formContentBody.addEventListener('hidden.bs.collapse', function() {
toggleIcon.classList.add('collapsed');
});
// Prevenir que el clic en el botón de toggle propague al header
document.querySelector('.toggle-btn')?.addEventListener('click', function(e) {
e.stopPropagation();
});
// Event listener para el selector de tipo de acta (MODIFICADO: dropdown)
if (tipoActaSelect) {
tipoActaSelect.addEventListener('change', function() {
const selectedValue = this.value;
if (selectedValue && ['agencias', 'vigilador'].includes(selectedValue)) {
tipoActaInput.value = selectedValue;
formContent.classList.add('show');
formContentBody.classList.add('show');
renderForm(selectedValue);
setupNotificacionListeners();
}
});
}
// ⚠️ CORRECCIÓN CRÍTICA: Agregar event listeners a los botones de PDF
if (btnImprimirElevacion) {
btnImprimirElevacion.addEventListener('click', function() {
if (this.disabled) return;
generarPDFAElevacion();
});
}
// CORREGIDO: Botón de PDF de Notificación con manejo de errores
if (btnImprimirNotificacion) {
btnImprimirNotificacion.addEventListener('click', function() {
if (this.disabled) {
Swal.fire({
icon: 'info',
title: 'Botón deshabilitado',
text: 'Debe haber una sanción registrada con tipo de sanción para generar la notificación',
confirmButtonText: 'Entendido'
});
return;
}
try {
if (typeof window.jspdf === 'undefined') {
throw new Error('La biblioteca jsPDF no está cargada');
}
crearPDFNotificacion();
} catch (error) {
console.error('Error al generar PDF de notificación:', error);
Swal.fire({
icon: 'error',
title: 'Error al generar PDF',
text: 'Hubo un problema al crear el documento: ' + error.message,
confirmButtonText: 'Entendido'
});
}
});
}
function renderForm(actaType) {
const acta = config[actaType];
// Actualizar títulos
actaTitle.textContent = acta.title;
actaSubtitle.textContent = acta.subtitle;
// Renderizar campos dinámicos
let fieldsHTML = '';
acta.fields.forEach(field => {
const value = existingData[field.key] || '';
fieldsHTML += `
<div class="col-md-6">
<label class="form-label">${field.label}${field.required ? ' *' : ''}</label>
<input type="text" name="${field.key}" class="form-control"
value="${escapeHtml(value)}" ${field.required ? 'required' : ''}>
</div>
`;
});
dynamicFields.innerHTML = fieldsHTML;
// Renderizar checklist de infracciones
let checklistHTML = '';
acta.infracciones.forEach(inf => {
const checked = existingData[inf.key] == 1 ? 'checked' : '';
checklistHTML += `
<label class="checklist-item">
<input type="checkbox" name="${inf.key}" value="1" ${checked}>
<span>${escapeHtml(inf.label)}</span>
</label>
`;
});
infraccionesChecklist.innerHTML = checklistHTML;
// Re-configurar listeners después de renderizar
setupNotificacionListeners();
}
function escapeHtml(text) {
const div = document.createElement('div');
div.textContent = text;
return div.innerHTML;
}
// Función para llenar el área de impresión con los datos del formulario
function llenarVistaPrevia() {
// Datos del acta
document.getElementById('printActaTitle').textContent = actaTitle.textContent;
document.getElementById('printActaSubtitle').textContent = actaSubtitle.textContent;
document.getElementById('printNumeroActa').textContent = inspeccionData.numero_acta || '';
document.getElementById('printAnioActa').textContent = inspeccionData.numero_anio || new Date().getFullYear();
// Datos generales
document.getElementById('printFecha').textContent = document.getElementById('fechaActa').value || '';
document.getElementById('printPlazo').textContent = plazoOtorgadoInput.value || '';
document.getElementById('printHora').textContent = document.getElementById('horaActa').value || '';
document.getElementById('printLugar').textContent = document.getElementById('lugar').value || '';
document.getElementById('printAgencia').textContent = document.querySelector('[name="agencia_local"]')?.value || '';
document.getElementById('printPersona').textContent = document.querySelector('[name="persona_identificada"]')?.value || '';
document.getElementById('printVestimenta').textContent = document.querySelector('[name="vestimenta"]')?.value || 'N/A';
document.getElementById('printFuncionario').textContent = document.getElementById('funcionarioActuante').value || '';
document.getElementById('printResponsable').textContent = document.querySelector('[name="responsable_presente"]')?.value || '';
document.getElementById('printTestigos').textContent = document.querySelector('[name="testigos"]')?.value || 'Ninguno';
// Infracciones marcadas
const checklistItems = document.querySelectorAll('#infraccionesChecklist .checklist-item');
let infraccionesHTML = '';
checklistItems.forEach(item => {
const checkbox = item.querySelector('input[type="checkbox"]');
const label = item.querySelector('span').textContent;
if (checkbox.checked) {
infraccionesHTML += `<div class="print-checklist-item checked">${label}</div>`;
} else {
infraccionesHTML += `<div class="print-checklist-item unchecked">${label}</div>`;
}
});
document.getElementById('printInfracciones').innerHTML = infraccionesHTML;
// Otras infracciones y observaciones
document.getElementById('printOtrasInfracciones').textContent = document.querySelector('[name="otras_infracciones"]')?.value || 'Ninguna';
document.getElementById('printObservaciones').textContent = document.querySelector('[name="observaciones"]')?.value || 'Sin observaciones';
// Evidencia y Medidas Cautelares
const fotosCantidad = document.querySelector('[name="evidencia_fotos_cantidad"]')?.value;
const fotosDesc = document.querySelector('[name="evidencia_fotos_descripcion"]')?.value;
const videosCantidad = document.querySelector('[name="evidencia_videos_cantidad"]')?.value;
const videosSoporte = document.querySelector('[name="evidencia_videos_soporte"]')?.value;
const elementosRetenidos = document.querySelector('[name="evidencia_elementos_retenidos"]')?.value;
const fotosArchivosInput = document.querySelector('[name="evidencia_fotos_archivos"]');
const videosArchivosInput = document.querySelector('[name="evidencia_videos_archivos"]');
// Mostrar sección de evidencia si hay datos
if (fotosCantidad || fotosDesc || videosCantidad || videosSoporte || elementosRetenidos ||
(fotosArchivosInput && fotosArchivosInput.files?.length > 0) ||
(videosArchivosInput && videosArchivosInput.files?.length > 0)) {
document.getElementById('printEvidencia').style.display = 'block';
document.getElementById('printFotosCantidad').textContent = fotosCantidad ? fotosCantidad + ' foto(s)' : '';
document.getElementById('printFotosDescripcion').textContent = fotosDesc || '';
// Archivos de fotos
let fotosArchivosTexto = '';
if (fotosArchivosInput && fotosArchivosInput.files?.length > 0) {
for (let i = 0; i < fotosArchivosInput.files.length; i++) {
fotosArchivosTexto += fotosArchivosInput.files[i].name + (i < fotosArchivosInput.files.length - 1 ? ', ' : '');
}
}
document.getElementById('printFotosArchivos').textContent = fotosArchivosTexto || 'N/A';
document.getElementById('printVideosCantidad').textContent = videosCantidad ? videosCantidad + ' video(s)' : '';
document.getElementById('printVideosSoporte').textContent = videosSoporte || 'N/A';
// Archivos de videos
let videosArchivosTexto = '';
if (videosArchivosInput && videosArchivosInput.files?.length > 0) {
for (let i = 0; i < videosArchivosInput.files.length; i++) {
videosArchivosTexto += videosArchivosInput.files[i].name + (i < videosArchivosInput.files.length - 1 ? ', ' : '');
}
}
document.getElementById('printVideosArchivos').textContent = videosArchivosTexto || 'N/A';
document.getElementById('printElementosRetenidos').textContent = elementosRetenidos || 'Ninguno';
} else {
document.getElementById('printEvidencia').style.display = 'none';
}
// Sección de sanción (si existe)
const sancionTipo = document.querySelector('[name="sancion_tipo"]')?.value;
if (sancionTipo) {
document.getElementById('printSancion').style.display = 'block';
document.getElementById('printSancionTipo').textContent = sancionTipo;
document.getElementById('printSancionFecha').textContent = document.querySelector('[name="sancion_fecha"]')?.value || '';
document.getElementById('printSancionResolucion').textContent = document.querySelector('[name="sancion_resolucion"]')?.value || '';
document.getElementById('printSancionMonto').textContent = document.querySelector('[name="sancion_monto"]')?.value ? '$' + parseFloat(document.querySelector('[name="sancion_monto"]').value).toFixed(2) : '';
document.getElementById('printSancionObservaciones').textContent = document.querySelector('[name="sancion_observaciones"]')?.value || 'Sin observaciones';
} else {
document.getElementById('printSancion').style.display = 'none';
}
// Sección de notificación (si existe)
const notificacionFecha = document.querySelector('[name="notificacion_fecha"]')?.value;
if (notificacionFecha) {
document.getElementById('printNotificacion').style.display = 'block';
document.getElementById('printNotificacionFecha').textContent = notificacionFecha;
document.getElementById('printFechaLimitePago').textContent = fechaLimitePagoInput?.value || '';
const multaPagada = document.querySelector('[name="multa_pagada"]')?.value;
document.getElementById('printMultaPagada').textContent = multaPagada == '1' ? 'Sí' : 'No';
document.getElementById('printNotificacionEntregado').textContent = document.querySelector('[name="notificacion_entregado_a"]')?.value || '';
document.getElementById('printNotificacionFirma').textContent = document.getElementById('firmaResponsable')?.checked ? 'Sí' : 'No';
} else {
document.getElementById('printNotificacion').style.display = 'none';
}
// Mostrar el área de impresión
printArea.classList.add('show');
printArea.scrollIntoView({ behavior: 'smooth' });
}
// Función para generar PDF de Elevación a Jefatura (similar a ELEVACION.docx) - DISEÑO MODERNO
function generarPDFAElevacion() {
const { jsPDF } = window.jspdf;
const doc = new jsPDF({
orientation: 'portrait',
unit: 'mm',
format: [216, 340]
});
// Configurar fuente y márgenes - DISEÑO MODERNO
doc.setFont('helvetica');
const margenIzq = 20;
const margenDer = 20;
const anchoPagina = doc.internal.pageSize.getWidth();
const anchoContenido = anchoPagina - margenIzq - margenDer;
let y = 12;
// Header: AREA INVESTIGACIONES RW. (A-2), Fecha
doc.setFontSize(9);
doc.setFont('helvetica', 'normal');
const fechaActual = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: 'long', year: 'numeric' });
doc.text(`AREA INVESTIGACIONES RW. (A-2), ${fechaActual.charAt(0).toUpperCase() + fechaActual.slice(1)}.`, margenIzq, y);
y += 6;
// Destinatario
doc.setFont('helvetica', 'bold');
doc.text('SEÑOR', margenIzq, y);
y += 4;
doc.text('JEFE ASESORÍA LETRADA', margenIzq, y);
y += 4;
doc.text('DR. HÉCTOR OMAR SIMI ONATI', margenIzq, y);
y += 4;
doc.text('SU DESPACHO', margenIzq, y);
y += 10;
// Cuerpo del texto - MODIFICADO SEGÚN INSTRUCCIONES
doc.setFont('helvetica', 'normal');
doc.setFontSize(10);
const cuerpoTextos = [
'De mi mayor consideración:',
'',
'Por medio del presente, y en el marco de las facultades conferidas a esta Área de Investigaciones, elevo adjunto el ACTA DE INSPECCIÓN labrada el día ' + (inspeccionData.fecha_inspeccion || '___') + ' a la empresa ' + (inspeccionData.empresa_nombre || '___') + ', CUIT N° ' + (inspeccionData.empresa_cuit || '___') + '.',
'',
'El motivo de la elevación es poner en su conocimiento las presuntas infracciones detalladas en el cuerpo del acta, las cuales contravienen lo establecido en la Ley I N° 168 y Decreto N° 1220/19.',
'',
'Por lo expuesto, SOLICITO:',
'- Se sirva analizar las actuaciones adjuntas.',
'- Emita el DICTAMEN LEGAL correspondiente sobre la viabilidad y tipificación de las infracciones.',
'- Eleve el PROYECTO DE RESOLUCIÓN para la aplicación de la sanción correspondiente, conforme al marco normativo vigente.',
'- Se requiera la respuesta en un plazo perentorio de 10 días hábiles, a fin de dar celeridad al procedimiento administrativo.',
'',
'Se adjunta al presente:',
'- Original del Acta de Inspección N° ' + (inspeccionData.numero_acta || '___') + '/' + (inspeccionData.numero_anio || new Date().getFullYear()) + '.',
'- Evidencia fotográfica/documental (si hubiera).',
'',
'Sin otro particular, y quedando a disposición para cualquier consulta técnica sobre lo actuado, lo saludo a Ud. muy atentamente.'
];
cuerpoTextos.forEach(linea => {
if (linea === '') {
y += 4;
} else {
const lineas = doc.splitTextToSize(linea, anchoContenido);
doc.text(lineas, margenIzq, y);
y += lineas.length * 4;
}
});
y += 10;
// DETALLE DE INFRACCIONES CON ARTÍCULOS DE LEY - NUEVA SECCIÓN AGREGADA
doc.setFont('helvetica', 'bold');
doc.text('DETALLE DE INFRACCIONES CONSTATADAS Y FUNDAMENTO LEGAL:', margenIzq, y);
y += 6;
doc.setFont('helvetica', 'normal');
doc.setFontSize(9);
// Mapeo de infracciones a artículos de Ley I N° 168 y Decreto 1220/19
const articulosLey = {
// ACTA AGENCIAS
'inf_fichero': { label: 'F/ fichero', articulo: 'Art. 15 Ley I N° 168 - Obligación de mantener fichero actualizado' },
'inf_libros_inspecciones': { label: 'F/ libros inspecciones', articulo: 'Art. 16 Ley I N° 168 - Libros de inspección obligatorios' },
'inf_libro_personal': { label: 'F/ libro personal', articulo: 'Art. 17 Ley I N° 168 - Registro de personal de seguridad' },
'inf_autorizacion_cnc': { label: 'F/ autorización C.N.C', articulo: 'Art. 12 Decreto 1220/19 - Autorización del Consejo Nacional de Control' },
'inf_inscripcion_renar': { label: 'F/ Inscripción RENAR', articulo: 'Art. 8 Ley I N° 168 - Inscripción en Registro Nacional de Armas' },
'inf_registro_usuario': { label: 'F/ registro usuario', articulo: 'Art. 18 Ley I N° 168 - Registro de usuarios y operadores' },
'inf_credencial': { label: 'F/ credencial', articulo: 'Art. 20 Ley I N° 168 - Credencial habilitante para personal de seguridad' },
'inf_archivos_investigaciones': { label: 'F/ archivos De Investigaciones', articulo: 'Art. 22 Ley I N° 168 - Archivo y conservación de investigaciones' },
'inf_hab_jefatura': { label: 'F/ hab. Jefatura', articulo: 'Art. 10 Ley I N° 168 - Habilitación por Jefatura de Policía' },
'inf_vehiculo_balizas': { label: 'P/ Vehículo Balizas o Ident.', articulo: 'Art. 25 Decreto 1220/19 - Identificación vehicular obligatoria' },
'inf_pago_afip': { label: 'F/ pago AFIP', articulo: 'Art. 30 Ley I N° 168 - Obligaciones tributarias y previsionales' },
'inf_pago_vehiculo': { label: 'F/ pago Vehículo', articulo: 'Art. 31 Ley I N° 168 - Tasas por registro vehicular' },
'inf_pago_hab_comercial': { label: 'F/ pago Hab. A. Comercial', articulo: 'Art. 11 Ley I N° 168 - Tasa por habilitación comercial' },
'inf_pago_dir_tecnico': { label: 'F/ pago Director Técnico', articulo: 'Art. 14 Ley I N° 168 - Honorarios por dirección técnica' },
'inf_reglamento_estatuto': { label: 'F/ reglamento y estatuto', articulo: 'Art. 9 Decreto 1220/19 - Aprobación de reglamento interno' },
// ACTA VIGILADOR
'inf_credencial_directivo': { label: 'F/Credencial Directivo o Vigilador', articulo: 'Art. 20 Ley I N° 168 - Credencial obligatoria para ejercicio' },
'inf_elementos_sujecion': { label: 'Posee elementos de sujeción u aprensión', articulo: 'Art. 27 Decreto 1220/19 - Uso autorizado de elementos de sujeción' },
'inf_uniforme_empresa': { label: 'corresponde uniforme c/ empresa', articulo: 'Art. 24 Decreto 1220/19 - Uniforme reglamentario identificatorio' },
'inf_autorizacion_vigilador': { label: 'F/ autorización', articulo: 'Art. 13 Ley I N° 168 - Autorización individual para vigiladores' },
'inf_arma': { label: 'F/ Arma', articulo: 'Art. 8 y 26 Ley I N° 168 - Tenencia y portación de armas reglamentada' },
'inf_otras_faltas': { label: 'otras Faltas', articulo: 'Art. 35 Ley I N° 168 - Infracciones genéricas al régimen de seguridad privada' },
'inf_tarjeta_legitimo_usuario': { label: 'F/ tarjeta Legitimo Usuario', articulo: 'Art. 19 Decreto 1220/19 - Tarjeta de legítimo usuario de arma' },
'inf_tarjeta_tenencia': { label: 'Tarjeta Tenencia', articulo: 'Art. 8 Ley Nacional 20.429 - Tenencia de arma de fuego' },
'inf_tarjeta_portacion': { label: 'Tarjeta de portación', articulo: 'Art. 9 Ley Nacional 20.429 - Portación de arma de fuego' }
};
// Obtener tipo de acta y listar infracciones marcadas con sus artículos
const actaType = existingData.tipo_acta || 'agencias';
const infraccionesList = config[actaType]?.infracciones || [];
let hayInfracciones = false;
infraccionesList.forEach(inf => {
if (existingData[inf.key] == 1) {
hayInfracciones = true;
const info = articulosLey[inf.key] || { label: inf.label, articulo: 'Art. 35 Ley I N° 168 - Infracción al régimen de seguridad privada' };
const lineaInfraccion = `• ${info.label} - ${info.articulo}`;
const lineas = doc.splitTextToSize(lineaInfraccion, anchoContenido - 5);
doc.text(lineas, margenIzq + 5, y);
y += lineas.length * 3.5;
}
});
// Agregar otras infracciones si existen
if (existingData.otras_infracciones && existingData.otras_infracciones.trim() !== '') {
const lineaOtras = `• Otras infracciones: ${existingData.otras_infracciones} - Art. 35 Ley I N° 168`;
const lineas = doc.splitTextToSize(lineaOtras, anchoContenido - 5);
doc.text(lineas, margenIzq + 5, y);
y += lineas.length * 3.5;
hayInfracciones = true;
}
// Si no hay infracciones marcadas, indicar
if (!hayInfracciones) {
doc.text('No se registraron infracciones específicas en el presente acta.', margenIzq + 5, y);
y += 4;
}
y += 8;
// Despedida y firma
doc.text('Atte.', margenIzq, y);
y += 15;
doc.text('AREA INVESTIGACIONES (A-2) RW.', margenIzq, y);
y += 4;
doc.text(`${fechaActual.charAt(0).toUpperCase() + fechaActual.slice(1)}.`, margenIzq, y);
// Agregar datos de la inspección como referencia adicional
y += 10;
doc.setFontSize(8);
doc.setFont('helvetica', 'italic');
doc.text(`Referencia adicional: Acta N° ${inspeccionData.numero_acta || '___'}/${inspeccionData.numero_anio || new Date().getFullYear()} - ${inspeccionData.empresa_nombre || ''}`, margenIzq, y);
y += 4;
if (inspeccionData.sucursal_nombre) {
doc.text(`Sucursal: ${inspeccionData.sucursal_nombre}`, margenIzq, y);
y += 4;
}
doc.text(`Fecha de inspección: ${inspeccionData.fecha_inspeccion || ''} - Hora: ${inspeccionData.hora_inspeccion || ''}`, margenIzq, y);
y += 4;
doc.text(`Funcionario actuante: ${inspeccionData.funcionario_nombre || ''}`, margenIzq, y);
// Agregar resumen de evidencia si existe
if (existingData.evidencia_fotos_cantidad || existingData.evidencia_videos_cantidad || existingData.evidencia_elementos_retenidos || existingData.evidencia_fotos_archivos || existingData.evidencia_videos_archivos) {
y += 6;
doc.setFont('helvetica', 'bold');
doc.text('Evidencia y Medidas Cautelares (Art. 23 - Ley I-168):', margenIzq, y);
y += 4;
doc.setFont('helvetica', 'normal');
if (existingData.evidencia_fotos_cantidad) {
doc.text(`Fotografías: ${existingData.evidencia_fotos_cantidad}`, margenIzq + 5, y);
y += 4;
}
if (existingData.evidencia_fotos_descripcion) {
const lineas = doc.splitTextToSize(`Descripción: ${existingData.evidencia_fotos_descripcion}`, anchoContenido - 5);
doc.text(lineas, margenIzq + 5, y);
y += lineas.length * 4;
}
if (existingData.evidencia_fotos_archivos) {
try {
const fotosArch = JSON.parse(existingData.evidencia_fotos_archivos);
if (Array.isArray(fotosArch) && fotosArch.length > 0) {
doc.text(`Archivos de Fotos: ${fotosArch.map(f => f.split('/').pop()).join(', ')}`, margenIzq + 5, y);
y += 4;
}
} catch(e) {}
}
if (existingData.evidencia_videos_cantidad) {
doc.text(`Videos: ${existingData.evidencia_videos_cantidad} - Soporte: ${existingData.evidencia_videos_soporte || 'N/A'}`, margenIzq + 5, y);
y += 4;
}
if (existingData.evidencia_videos_archivos) {
try {
const videosArch = JSON.parse(existingData.evidencia_videos_archivos);
if (Array.isArray(videosArch) && videosArch.length > 0) {
doc.text(`Archivos de Videos: ${videosArch.map(v => v.split('/').pop()).join(', ')}`, margenIzq + 5, y);
y += 4;
}
} catch(e) {}
}
if (existingData.evidencia_elementos_retenidos) {
const lineas = doc.splitTextToSize(`Elementos Retenidos: ${existingData.evidencia_elementos_retenidos}`, anchoContenido - 5);
doc.text(lineas, margenIzq + 5, y);
y += lineas.length * 4;
}
}
// Guardar PDF
doc.save(`Elevacion_Jefatura_Acta_${inspeccionData.numero_acta || '___'}_${inspeccionData.numero_anio || new Date().getFullYear()}.pdf`);
// Mostrar confirmación
Swal.fire({
icon: 'success',
title: 'PDF Generado',
text: 'El documento de elevación a Jefatura ha sido generado exitosamente',
confirmButtonText: 'Entendido'
});
}
// Función para crear PDF de Notificación de Sanción (CORREGIDA: con validación de jsPDF) - DISEÑO MODERNO
function crearPDFNotificacion() {
if (typeof window.jspdf === 'undefined') {
throw new Error('La biblioteca jsPDF no está disponible');
}
const { jsPDF } = window.jspdf;
const doc = new jsPDF({
orientation: 'portrait',
unit: 'mm',
format: [216, 340]
});
// Configurar fuente y márgenes - DISEÑO MODERNO
doc.setFont('helvetica');
const margenIzq = 20;
const margenDer = 20;
const anchoPagina = doc.internal.pageSize.getWidth();
const anchoContenido = anchoPagina - margenIzq - margenDer;
let y = 15;
// Header: AREA INVESTIGACIONES RW. (A-2), Fecha actual
doc.setFontSize(9);
doc.setFont('helvetica', 'normal');
const fechaActual = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: 'long', year: 'numeric' });
doc.text(`AREA INVESTIGACIONES RW. (A-2), ${fechaActual.charAt(0).toUpperCase() + fechaActual.slice(1)}.`, margenIzq, y);
y += 8;
// Destinatario
doc.setFont('helvetica', 'bold');
doc.text('SEÑOR', margenIzq, y);
y += 5;
doc.setFont('helvetica', 'normal');
doc.text(`${inspeccionData.empresa_nombre || 'Empresa'}`, margenIzq, y);
y += 5;
doc.text('SU DESPACHO', margenIzq, y);
y += 12;
// Cuerpo del texto
doc.setFont('helvetica', 'normal');
doc.setFontSize(10);
const cuerpoTextos = [
'De mi mayor consideración:,',
'',
'Por medio de la presente, y en cumplimiento de lo establecido en el Art. 25 de la Ley I N° 168, Decreto Reglamentario N° 1220/19, y conforme a los principios de la Ley Nacional de Procedimientos Administrativos N° 19.549, se NOTIFICA FORMALMENTE a la empresa inspeccionada la sanción administrativa que se detalla a continuación, quedando habilitado el plazo de DIEZ (10) DÍAS HÁBILES administrativos, contados a partir de la presente notificación, para ejercer los recursos de reconsideración y jerárquico que correspondan.',
'',
'ASIMISMO, se informa que el plazo para el abono de la multa impuesta es de QUINCE (15) DÍAS HÁBILES administrativos, contados a partir de la fecha de notificación de la presente, conforme lo establece la Ley 19.549 de Procedimientos Administrativos. El incumplimiento de dicho plazo podrá generar la aplicación de medidas ejecutivas y recargos conforme a la normativa vigente.',
'',
'Sin otro particular, y quedando a disposición para cualquier consulta técnica sobre lo actuado, lo saludo a Ud. muy atentamente.'
];
cuerpoTextos.forEach(linea => {
if (linea === '') {
y += 4;
} else {
const lineas = doc.splitTextToSize(linea, anchoContenido);
doc.text(lineas, margenIzq, y);
y += lineas.length * 4;
}
});
y += 12;
// DETALLE DE INFRACCIONES CONSTATADAS Y FUNDAMENTO LEGAL
doc.setFont('helvetica', 'bold');
doc.text('DETALLE DE INFRACCIONES CONSTATADAS Y FUNDAMENTO LEGAL:', margenIzq, y);
y += 6;
doc.setFont('helvetica', 'normal');
doc.setFontSize(9);
// Mapeo de infracciones a artículos de Ley I N° 168 y Decreto 1220/19
const articulosLey = {
// ACTA AGENCIAS
'inf_fichero': { label: 'F/ fichero', articulo: 'Art. 15 Ley I N° 168 - Obligación de mantener fichero actualizado' },
'inf_libros_inspecciones': { label: 'F/ libros inspecciones', articulo: 'Art. 16 Ley I N° 168 - Libros de inspección obligatorios' },
'inf_libro_personal': { label: 'F/ libro personal', articulo: 'Art. 17 Ley I N° 168 - Registro de personal de seguridad' },
'inf_autorizacion_cnc': { label: 'F/ autorización C.N.C', articulo: 'Art. 12 Decreto 1220/19 - Autorización del Consejo Nacional de Control' },
'inf_inscripcion_renar': { label: 'F/ Inscripción RENAR', articulo: 'Art. 8 Ley I N° 168 - Inscripción en Registro Nacional de Armas' },
'inf_registro_usuario': { label: 'F/ registro usuario', articulo: 'Art. 18 Ley I N° 168 - Registro de usuarios y operadores' },
'inf_credencial': { label: 'F/ credencial', articulo: 'Art. 20 Ley I N° 168 - Credencial habilitante para personal de seguridad' },
'inf_archivos_investigaciones': { label: 'F/ archivos De Investigaciones', articulo: 'Art. 22 Ley I N° 168 - Archivo y conservación de investigaciones' },
'inf_hab_jefatura': { label: 'F/ hab. Jefatura', articulo: 'Art. 10 Ley I N° 168 - Habilitación por Jefatura de Policía' },
'inf_vehiculo_balizas': { label: 'P/ Vehículo Balizas o Ident.', articulo: 'Art. 25 Decreto 1220/19 - Identificación vehicular obligatoria' },
'inf_pago_afip': { label: 'F/ pago AFIP', articulo: 'Art. 30 Ley I N° 168 - Obligaciones tributarias y previsionales' },
'inf_pago_vehiculo': { label: 'F/ pago Vehículo', articulo: 'Art. 31 Ley I N° 168 - Tasas por registro vehicular' },
'inf_pago_hab_comercial': { label: 'F/ pago Hab. A. Comercial', articulo: 'Art. 11 Ley I N° 168 - Tasa por habilitación comercial' },
'inf_pago_dir_tecnico': { label: 'F/ pago Director Técnico', articulo: 'Art. 14 Ley I N° 168 - Honorarios por dirección técnica' },
'inf_reglamento_estatuto': { label: 'F/ reglamento y estatuto', articulo: 'Art. 9 Decreto 1220/19 - Aprobación de reglamento interno' },
// ACTA VIGILADOR
'inf_credencial_directivo': { label: 'F/Credencial Directivo o Vigilador', articulo: 'Art. 20 Ley I N° 168 - Credencial obligatoria para ejercicio' },
'inf_elementos_sujecion': { label: 'Posee elementos de sujeción u aprensión', articulo: 'Art. 27 Decreto 1220/19 - Uso autorizado de elementos de sujeción' },
'inf_uniforme_empresa': { label: 'corresponde uniforme c/ empresa', articulo: 'Art. 24 Decreto 1220/19 - Uniforme reglamentario identificatorio' },
'inf_autorizacion_vigilador': { label: 'F/ autorización', articulo: 'Art. 13 Ley I N° 168 - Autorización individual para vigiladores' },
'inf_arma': { label: 'F/ Arma', articulo: 'Art. 8 y 26 Ley I N° 168 - Tenencia y portación de armas reglamentada' },
'inf_otras_faltas': { label: 'otras Faltas', articulo: 'Art. 35 Ley I N° 168 - Infracciones genéricas al régimen de seguridad privada' },
'inf_tarjeta_legitimo_usuario': { label: 'F/ tarjeta Legitimo Usuario', articulo: 'Art. 19 Decreto 1220/19 - Tarjeta de legítimo usuario de arma' },
'inf_tarjeta_tenencia': { label: 'Tarjeta Tenencia', articulo: 'Art. 8 Ley Nacional 20.429 - Tenencia de arma de fuego' },
'inf_tarjeta_portacion': { label: 'Tarjeta de portación', articulo: 'Art. 9 Ley Nacional 20.429 - Portación de arma de fuego' }
};
// Obtener tipo de acta y listar infracciones marcadas con sus artículos
const actaType = existingData.tipo_acta || 'agencias';
const infraccionesList = config[actaType]?.infracciones || [];
let hayInfracciones = false;
infraccionesList.forEach(inf => {
if (existingData[inf.key] == 1) {
hayInfracciones = true;
const info = articulosLey[inf.key] || { label: inf.label, articulo: 'Art. 35 Ley I N° 168 - Infracción al régimen de seguridad privada' };
const lineaInfraccion = `• ${info.label} - ${info.articulo}`;
const lineas = doc.splitTextToSize(lineaInfraccion, anchoContenido - 5);
doc.text(lineas, margenIzq + 5, y);
y += lineas.length * 3.5;
}
});
// Agregar otras infracciones si existen
if (existingData.otras_infracciones && existingData.otras_infracciones.trim() !== '') {
const lineaOtras = `• Otras infracciones: ${existingData.otras_infracciones} - Art. 35 Ley I N° 168`;
const lineas = doc.splitTextToSize(lineaOtras, anchoContenido - 5);
doc.text(lineas, margenIzq + 5, y);
y += lineas.length * 3.5;
hayInfracciones = true;
}
// Si no hay infracciones marcadas, indicar
if (!hayInfracciones) {
doc.text('No se registraron infracciones específicas en el presente acta.', margenIzq + 5, y);
y += 4;
}
y += 12;
// Detalles de la sanción
doc.setFont('helvetica', 'bold');
doc.text('DETALLES DE LA SANCIÓN:', margenIzq, y);
y += 6;
doc.setFont('helvetica', 'normal');
// Tipo de Sanción
doc.setFont('helvetica', 'bold');
doc.text('Tipo de Sanción:', margenIzq, y);
doc.setFont('helvetica', 'normal');
doc.text(`${document.querySelector('[name="sancion_tipo"]')?.value || ''}`, margenIzq + 40, y);
y += 6;
// Multa (tipo de sanción si es multa)
doc.setFont('helvetica', 'bold');
doc.text('Multa:', margenIzq, y);
doc.setFont('helvetica', 'normal');
const sancionTipo = document.querySelector('[name="sancion_tipo"]')?.value;
doc.text(`${sancionTipo === 'Multa' ? 'Sí' : 'No aplica'}`, margenIzq + 40, y);
y += 6;
// Fecha de Sanción
doc.setFont('helvetica', 'bold');
doc.text('Fecha de Sanción:', margenIzq, y);
doc.setFont('helvetica', 'normal');
doc.text(`${document.querySelector('[name="sancion_fecha"]')?.value || ''}`, margenIzq + 40, y);
y += 6;
// N° Resolución
doc.setFont('helvetica', 'bold');
doc.text('N° Resolución:', margenIzq, y);
doc.setFont('helvetica', 'normal');
doc.text(`${document.querySelector('[name="sancion_resolucion"]')?.value || ''}`, margenIzq + 40, y);
y += 6;
// Monto de Multa ($)
doc.setFont('helvetica', 'bold');
doc.text('Monto de Multa ($):', margenIzq, y);
doc.setFont('helvetica', 'normal');
const monto = document.querySelector('[name="sancion_monto"]')?.value;
doc.text(`${monto ? '$' + parseFloat(monto).toFixed(2) : ''}`, margenIzq + 40, y);
y += 6;
// Decreto N° 1220/19
doc.setFont('helvetica', 'bold');
doc.text('Decreto N° 1220/19:', margenIzq, y);
doc.setFont('helvetica', 'normal');
doc.text(`${fechaLimitePagoInput?.value || ''}`, margenIzq + 40, y);
y += 6;
// Estado de pago de multa
doc.setFont('helvetica', 'bold');
doc.text('Multa Pagada:', margenIzq, y);
doc.setFont('helvetica', 'normal');
const multaPagada = document.querySelector('[name="multa_pagada"]')?.value;
doc.text(`${multaPagada == '1' ? 'Sí' : 'No'}`, margenIzq + 40, y);
y += 6;
// Observaciones de la Sanción
doc.setFont('helvetica', 'bold');
doc.text('Observaciones de la Sanción:', margenIzq, y);
y += 4;
doc.setFont('helvetica', 'normal');
const observaciones = document.querySelector('[name="sancion_observaciones"]')?.value || 'Sin observaciones';
const obsLineas = doc.splitTextToSize(observaciones, anchoContenido);
doc.text(obsLineas, margenIzq, y);
y += obsLineas.length * 4 + 12;
// Evidencia y Medidas Cautelares (si existe)
const fotosCantidad = document.querySelector('[name="evidencia_fotos_cantidad"]')?.value;
const fotosDesc = document.querySelector('[name="evidencia_fotos_descripcion"]')?.value;
const videosCantidad = document.querySelector('[name="evidencia_videos_cantidad"]')?.value;
const videosSoporte = document.querySelector('[name="evidencia_videos_soporte"]')?.value;
const elementosRetenidos = document.querySelector('[name="evidencia_elementos_retenidos"]')?.value;
const fotosArchivos = existingData.evidencia_fotos_archivos ? JSON.parse(existingData.evidencia_fotos_archivos) : [];
const videosArchivos = existingData.evidencia_videos_archivos ? JSON.parse(existingData.evidencia_videos_archivos) : [];
if (fotosCantidad || fotosDesc || videosCantidad || videosSoporte || elementosRetenidos || fotosArchivos.length > 0 || videosArchivos.length > 0) {
doc.setFont('helvetica', 'bold');
doc.text('EVIDENCIA Y MEDIDAS CAUTELARES (Art. 23 - Ley I-168):', margenIzq, y);
y += 5;
doc.setFont('helvetica', 'normal');
if (fotosCantidad) {
doc.text(`Fotografías: ${fotosCantidad}`, margenIzq, y);
y += 4;
}
if (fotosDesc) {
const lineas = doc.splitTextToSize(`Descripción: ${fotosDesc}`, anchoContenido);
doc.text(lineas, margenIzq, y);
y += lineas.length * 4;
}
if (fotosArchivos.length > 0) {
const archivosNombres = fotosArchivos.map(f => f.split('/').pop()).join(', ');
const lineas = doc.splitTextToSize(`Archivos adjuntos: ${archivosNombres}`, anchoContenido);
doc.text(lineas, margenIzq, y);
y += lineas.length * 4;
}
if (videosCantidad) {
doc.text(`Videos: ${videosCantidad} - Soporte: ${videosSoporte || 'N/A'}`, margenIzq, y);
y += 4;
}
if (videosArchivos.length > 0) {
const archivosNombres = videosArchivos.map(v => v.split('/').pop()).join(', ');
const lineas = doc.splitTextToSize(`Archivos adjuntos: ${archivosNombres}`, anchoContenido);
doc.text(lineas, margenIzq, y);
y += lineas.length * 4;
}
if (elementosRetenidos) {
const lineas = doc.splitTextToSize(`Elementos Retenidos: ${elementosRetenidos}`, anchoContenido);
doc.text(lineas, margenIzq, y);
y += lineas.length * 4 + 8;
}
}
// Footer legal
doc.setFontSize(8);
doc.setFont('helvetica', 'italic');
doc.text('Provincia del Chubut - Autoridad de Aplicación: Jefatura de Policía', margenIzq, y);
y += 4;
doc.text('Agencia de Seguridad Privada - Ley I N° 168 y Decreto N° 1220/19', margenIzq, y);
y += 4;
doc.text('Ley Nacional de Procedimientos Administrativos N° 19.549 - Recursos: reconsideración y jerárquico', margenIzq, y);
y += 12;
// Línea de firma
doc.setFont('helvetica', 'normal');
doc.setFontSize(9);
doc.text('Firma y Aclaración del Responsable', margenIzq + 100, y);
y += 20;
doc.line(margenIzq + 80, y, margenIzq + 180, y);
y += 6;
doc.setFontSize(7);
doc.text('Fecha: ___/___/______', margenIzq + 100, y);
// Guardar PDF
const nombreArchivo = `Notificacion_Sancion_Acta_${inspeccionData.numero_acta || '___'}_${inspeccionData.numero_anio || new Date().getFullYear()}.pdf`;
doc.save(nombreArchivo);
// Mostrar confirmación
Swal.fire({
icon: 'success',
title: 'PDF de Notificación Generado',
text: 'El documento de notificación de sanción ha sido creado exitosamente',
confirmButtonText: 'Entendido'
});
}
// Función para imprimir notificación a empresa (mantenida para compatibilidad)
function imprimirNotificacionAEmpresa() {
llenarVistaPrevia();
// Modificar título para impresión de notificación
document.getElementById('printActaTitle').textContent = 'NOTIFICACIÓN DE SANCIÓN ADMINISTRATIVA';
document.getElementById('printActaSubtitle').textContent = 'Comunicación de resolución de infracción a la empresa inspeccionada';
// Agregar nota específica de notificación
const notaNotificacion = document.createElement('div');
notaNotificacion.style.cssText = 'background:#d1ecf1;border:1px solid #0c5460;padding:10px;margin:15px 0;font-size:0.9rem;';
notaNotificacion.innerHTML = '<strong>NOTIFICACIÓN FORMAL:</strong><br>Por la presente se notifica a la empresa inspeccionada la sanción administrativa impuesta, conforme a lo establecido en el Art. 25 de la Ley I N° 168 y Ley 19.549 de Procedimientos Administrativos. Contra esta resolución son admisibles los recursos de reconsideración y jerárquico, los que deberán interponerse fundados dentro de los DIEZ (10) DÍAS HÁBILES administrativos contados desde la notificación. <strong>PLAZO DE PAGO:</strong> Quince (15) días hábiles administrativos para el abono de la multa, conforme Ley 19.549.';
const printBody = document.querySelector('#printArea .print-body');
printBody.insertBefore(notaNotificacion, printBody.firstChild);
// Imprimir
window.print();
// Restaurar después de imprimir
notaNotificacion.remove();
document.getElementById('printActaTitle').textContent = actaTitle.textContent;
document.getElementById('printActaSubtitle').textContent = actaSubtitle.textContent;
}
// ⚠️ CORRECCIÓN: Validación robusta del formulario
document.getElementById('infraccionesForm').addEventListener('submit', function(e) {
const tipoActa = tipoActaInput.value.trim();
// Validar que tipo_acta tenga valor válido
if (!tipoActa || !['agencias', 'vigilador'].includes(tipoActa)) {
e.preventDefault();
Swal.fire({
icon: 'error',
title: 'Error de Validación',
text: 'Debe seleccionar un tipo de acta (Agencias o Vigilador) antes de continuar',
confirmButtonText: 'Entendido'
});
return false;
}
const acta = config[tipoActa];
// Validar campos requeridos
let valid = true;
acta.fields.forEach(field => {
if (field.required) {
const input = this.querySelector(`[name="${field.key}"]`);
if (input && !input.value.trim()) {
valid = false;
input.classList.add('is-invalid');
} else if (input) {
input.classList.remove('is-invalid');
}
}
});
if (!valid) {
e.preventDefault();
Swal.fire({
icon: 'warning',
title: 'Campos requeridos',
text: 'Por favor complete todos los campos obligatorios marcados con *',
confirmButtonText: 'Entendido'
});
}
});
// Auto-remover clase is-invalid al escribir
document.querySelectorAll('.form-control').forEach(input => {
input.addEventListener('input', function() {
this.classList.remove('is-invalid');
});
});
// Inicializar plazo si hay fecha cargada
if (fechaActaInput.value) {
actualizarPlazoOtorgado();
}
// Inicializar Decreto N° 1220/19 si hay fecha de notificación cargada
const notifInput = document.getElementById('notificacionFecha');
if (notifInput?.value) {
actualizarFechaLimitePago();
}
});
// Función PHP helper para calcular plazo en el lado del servidor (para valores existentes)
<?php
function calcularPlazoHabil($fechaInicio, $dias = 10) {
$fecha = new DateTime($fechaInicio);
$diasContados = 0;
while ($diasContados < $dias) {
$fecha->modify('+1 day');
$diaSemana = $fecha->format('N'); // 1=Lunes, 7=Domingo
if ($diaSemana < 6) { // Lunes a Viernes
$diasContados++;
}
}
$fecha->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
return $fecha->format('d \d\e F \d\e Y');
}
?>
</script>
</body>
</html>