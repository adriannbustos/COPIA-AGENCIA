<?php
/**
* ============================================================================
* GESTIÓN DE PAGOS DE SERVICIOS REGISTRALES - VERSIÓN COMPLETA
* ============================================================================
* Incluye: CRUD completo, Auditoría detallada, Exportación PDF/CSV,
*          Validaciones, Paginación, Búsqueda, Filtros Avanzados,
*          Cálculo automático de montos, Estados de pago, Recordatorios
*
* @author Sistema de Seguridad
* @version 1.0
* @last_update 2026
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
if (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('super_admin')) {
$_SESSION['error'] = 'Acceso denegado. Se requieren permisos de administrador.';
header('Location: ../index.php');
exit;
}
$current_page = 'pagos_servicios';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. CREAR/VERIFICAR TABLA DE PAGOS
// ============================================================================
try {
$table_exists = $conn->query("SHOW TABLES LIKE 'pagos_servicios'")->rowCount() > 0;
if (!$table_exists) {
$conn->exec("
CREATE TABLE pagos_servicios (
id INT AUTO_INCREMENT PRIMARY KEY,
empresa_id INT NULL,
empresa_nombre VARCHAR(255) NOT NULL,
boleta_numero VARCHAR(50) NOT NULL,
fecha_pago DATE NOT NULL,
fecha_vencimiento DATE NULL,
servicios_registrales VARCHAR(100) NOT NULL,
valor_modulo DECIMAL(10,2) NOT NULL,
periodo VARCHAR(50) NOT NULL,
monto_total DECIMAL(15,2) NOT NULL,
cantidad_modulos INT NOT NULL DEFAULT 1,
jurisdiccion VARCHAR(100) NULL,
motivo TEXT NULL,
observaciones TEXT NULL,
estado ENUM('pendiente','pagado','vencido','cancelado','parcial') DEFAULT 'pendiente',
metodo_pago ENUM('transferencia','efectivo','cheque','tarjeta','debito_automatico') NULL,
nro_transaccion VARCHAR(100) NULL,
comprobante_path VARCHAR(255) NULL,
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
INDEX idx_empresa (empresa_nombre),
INDEX idx_boleta (boleta_numero),
INDEX idx_fecha_pago (fecha_pago),
INDEX idx_periodo (periodo),
INDEX idx_estado (estado),
INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
logAuditoria($conn, 'TABLA_CREADA', 'pagos_servicios', null,
['mensaje' => 'Tabla pagos_servicios creada'], $user['id']);
}
} catch (PDOException $e) {
$error = "Error al verificar estructura: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// 2.1 CREAR/VERIFICAR TABLA DE RECORDATORIOS DE PAGOS
// ============================================================================
try {
$table_recordatorios_exists = $conn->query("SHOW TABLES LIKE 'recordatorios_pagos'")->rowCount() > 0;
if (!$table_recordatorios_exists) {
$conn->exec("
CREATE TABLE recordatorios_pagos (
id INT AUTO_INCREMENT PRIMARY KEY,
empresa_id INT NULL,
empresa_nombre VARCHAR(255) NOT NULL,
tipo_recordatorio ENUM('credenciales','aranceles','multas') NOT NULL,
descripcion TEXT NULL,
monto_estimado DECIMAL(15,2) NULL,
fecha_recordatorio DATE NOT NULL,
fecha_vencimiento DATE NULL,
frecuencia ENUM('unica','mensual','trimestral','semestral','anual') DEFAULT 'unica',
estado ENUM('pendiente','completado','vencido','omitido') DEFAULT 'pendiente',
prioridad ENUM('baja','media','alta','urgente') DEFAULT 'media',
jurisdiccion VARCHAR(100) NULL,
observaciones TEXT NULL,
recordatorio_activado BOOLEAN DEFAULT TRUE,
fecha_envio_recordatorio DATETIME NULL,
activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
INDEX idx_empresa (empresa_nombre),
INDEX idx_tipo (tipo_recordatorio),
INDEX idx_fecha_recordatorio (fecha_recordatorio),
INDEX idx_estado (estado),
INDEX idx_prioridad (prioridad),
INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
logAuditoria($conn, 'TABLA_CREADA', 'recordatorios_pagos', null,
['mensaje' => 'Tabla recordatorios_pagos creada'], $user['id']);
}
} catch (PDOException $e) {
$error = "Error al verificar estructura de recordatorios: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// 3. MANEJAR CREACIÓN DE PAGO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_pago'])) {
try {
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$empresa_nombre = trim($_POST['empresa_nombre'] ?? '');
$boleta_numero = trim($_POST['boleta_numero'] ?? '');
$fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d');
$fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
$servicios_registrales = trim($_POST['servicios_registrales'] ?? '');
$valor_modulo = str_replace(['.', ','], ['', '.'], $_POST['valor_modulo'] ?? 0);
$periodo = trim($_POST['periodo'] ?? '');
$cantidad_modulos = (int)($_POST['cantidad_modulos'] ?? 1);
$jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
$motivo = trim($_POST['motivo'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$estado = $_POST['estado'] ?? 'pendiente';
$metodo_pago = !empty($_POST['metodo_pago']) ? $_POST['metodo_pago'] : null;
$nro_transaccion = trim($_POST['nro_transaccion'] ?? '');
// Validaciones
if (empty($empresa_nombre)) {
throw new Exception('El nombre de la empresa es obligatorio');
}
if (empty($boleta_numero)) {
throw new Exception('El número de boleta es obligatorio');
}
if (empty($servicios_registrales)) {
throw new Exception('Los servicios registrales son obligatorios');
}
if (empty($periodo)) {
throw new Exception('El período es obligatorio');
}
// Calcular monto total
$subtotal = $valor_modulo * $cantidad_modulos;
$monto_total = $subtotal;
// Verificar si ya existe la boleta
$stmt = $conn->prepare("SELECT id FROM pagos_servicios WHERE boleta_numero = ?");
$stmt->execute([$boleta_numero]);
if ($stmt->fetch()) {
throw new Exception('El número de boleta ya está registrado');
}
$stmt = $conn->prepare("
INSERT INTO pagos_servicios (
empresa_id, empresa_nombre, boleta_numero, fecha_pago, fecha_vencimiento,
servicios_registrales, valor_modulo, periodo, monto_total,
cantidad_modulos, jurisdiccion, motivo, observaciones,
estado, metodo_pago, nro_transaccion
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
$empresa_id, $empresa_nombre, $boleta_numero, $fecha_pago, $fecha_vencimiento,
$servicios_registrales, $valor_modulo, $periodo, $monto_total,
$cantidad_modulos, $jurisdiccion, $motivo, $observaciones,
$estado, $metodo_pago, $nro_transaccion
]);
$pago_id = $conn->lastInsertId();
$detalles = [
'accion' => 'pago_creado',
'id' => $pago_id,
'empresa' => $empresa_nombre,
'boleta' => $boleta_numero,
'monto' => $monto_total,
'periodo' => $periodo
];
logAuditoria($conn, 'PAGO_CREADO', 'pagos_servicios', $pago_id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>
<strong>¡Pago registrado exitosamente!</strong><br>
Empresa: {$empresa_nombre} | Boleta: {$boleta_numero} | Monto: $" . number_format($monto_total, 2, ',', '.')
. "</div>";
header('Location: pagos_servicios.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php');
exit;
}
}
// ============================================================================
// 4. MANEJAR ACTUALIZACIÓN DE PAGO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_pago'])) {
try {
$id = (int)($_POST['id'] ?? 0);
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$empresa_nombre = trim($_POST['empresa_nombre'] ?? '');
$boleta_numero = trim($_POST['boleta_numero'] ?? '');
$fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d');
$fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
$servicios_registrales = trim($_POST['servicios_registrales'] ?? '');
$valor_modulo = str_replace(['.', ','], ['', '.'], $_POST['valor_modulo'] ?? 0);
$periodo = trim($_POST['periodo'] ?? '');
$cantidad_modulos = (int)($_POST['cantidad_modulos'] ?? 1);
$jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
$motivo = trim($_POST['motivo'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');
$estado = $_POST['estado'] ?? 'pendiente';
$metodo_pago = !empty($_POST['metodo_pago']) ? $_POST['metodo_pago'] : null;
$nro_transaccion = trim($_POST['nro_transaccion'] ?? '');
if ($id <= 0) {
throw new Exception('ID de pago inválido');
}
$subtotal = $valor_modulo * $cantidad_modulos;
$monto_total = $subtotal;
// Obtener datos anteriores
$stmt = $conn->prepare("SELECT * FROM pagos_servicios WHERE id = ?");
$stmt->execute([$id]);
$datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$datos_antiguos) {
throw new Exception('Pago no encontrado');
}
$stmt = $conn->prepare("
UPDATE pagos_servicios SET
empresa_id = ?, empresa_nombre = ?, boleta_numero = ?,
fecha_pago = ?, fecha_vencimiento = ?, servicios_registrales = ?,
valor_modulo = ?, periodo = ?, monto_total = ?, cantidad_modulos = ?,
jurisdiccion = ?, motivo = ?, observaciones = ?,
estado = ?, metodo_pago = ?, nro_transaccion = ?
WHERE id = ?
");
$stmt->execute([
$empresa_id, $empresa_nombre, $boleta_numero,
$fecha_pago, $fecha_vencimiento, $servicios_registrales,
$valor_modulo, $periodo, $monto_total, $cantidad_modulos,
$jurisdiccion, $motivo, $observaciones,
$estado, $metodo_pago, $nro_transaccion, $id
]);
$detalles = [
'accion' => 'pago_actualizado',
'id' => $id,
'datos_anteriores' => $datos_antiguos,
'monto_anterior' => $datos_antiguos['monto_total'],
'monto_nuevo' => $monto_total
];
logAuditoria($conn, 'PAGO_ACTUALIZADO', 'pagos_servicios', $id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>
<strong>¡Pago actualizado exitosamente!</strong><br>
Boleta: {$boleta_numero} | Monto: $" . number_format($monto_total, 2, ',', '.')
. "</div>";
header('Location: pagos_servicios.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php');
exit;
}
}
// ============================================================================
// 5. MANEJAR ELIMINACIÓN DE PAGO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_pago'])) {
try {
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
throw new Exception('ID de pago inválido');
}
$stmt = $conn->prepare("SELECT * FROM pagos_servicios WHERE id = ?");
$stmt->execute([$id]);
$pago = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pago) {
throw new Exception('Pago no encontrado');
}
$stmt = $conn->prepare("DELETE FROM pagos_servicios WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'PAGO_ELIMINADO', 'pagos_servicios', $id, [
'boleta' => $pago['boleta_numero'],
'empresa' => $pago['empresa_nombre'],
'monto' => $pago['monto_total']
], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Pago eliminado correctamente</div>";
header('Location: pagos_servicios.php');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php');
exit;
}
}
// ============================================================================
// 5.1 MANEJAR CREACIÓN DE RECORDATORIO DE PAGO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_recordatorio'])) {
try {
$empresa_id = !empty($_POST['recordatorio_empresa_id']) ? (int)$_POST['recordatorio_empresa_id'] : null;
$empresa_nombre = trim($_POST['recordatorio_empresa_nombre'] ?? '');
$tipo_recordatorio = trim($_POST['tipo_recordatorio'] ?? '');
$descripcion = trim($_POST['recordatorio_descripcion'] ?? '');
$monto_estimado = !empty($_POST['monto_estimado']) ? str_replace(['.', ','], ['', '.'], $_POST['monto_estimado']) : null;
$fecha_recordatorio = $_POST['fecha_recordatorio'] ?? date('Y-m-d');
$fecha_vencimiento = !empty($_POST['recordatorio_fecha_vencimiento']) ? $_POST['recordatorio_fecha_vencimiento'] : null;
$frecuencia = $_POST['frecuencia'] ?? 'unica';
$estado = $_POST['recordatorio_estado'] ?? 'pendiente';
$prioridad = $_POST['prioridad'] ?? 'media';
$jurisdiccion = trim($_POST['recordatorio_jurisdiccion'] ?? '');
$observaciones = trim($_POST['recordatorio_observaciones'] ?? '');
// Validaciones
if (empty($empresa_nombre)) {
throw new Exception('El nombre de la empresa es obligatorio');
}
if (empty($tipo_recordatorio)) {
throw new Exception('El tipo de recordatorio es obligatorio');
}
if (empty($fecha_recordatorio)) {
throw new Exception('La fecha del recordatorio es obligatoria');
}
$stmt = $conn->prepare("
INSERT INTO recordatorios_pagos (
empresa_id, empresa_nombre, tipo_recordatorio, descripcion,
monto_estimado, fecha_recordatorio, fecha_vencimiento, frecuencia,
estado, prioridad, jurisdiccion, observaciones
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
$empresa_id, $empresa_nombre, $tipo_recordatorio, $descripcion,
$monto_estimado, $fecha_recordatorio, $fecha_vencimiento, $frecuencia,
$estado, $prioridad, $jurisdiccion, $observaciones
]);
$recordatorio_id = $conn->lastInsertId();
$detalles = [
'accion' => 'recordatorio_creado',
'id' => $recordatorio_id,
'empresa' => $empresa_nombre,
'tipo' => $tipo_recordatorio,
'fecha' => $fecha_recordatorio
];
logAuditoria($conn, 'RECORDATORIO_CREADO', 'recordatorios_pagos', $recordatorio_id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-bell me-2'></i>
<strong>¡Recordatorio registrado exitosamente!</strong><br>
Empresa: {$empresa_nombre} | Tipo: " . ucfirst($tipo_recordatorio) . " | Fecha: " . formatearFecha($fecha_recordatorio)
. "</div>";
header('Location: pagos_servicios.php#section-recordatorios');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php#section-recordatorios');
exit;
}
}
// ============================================================================
// 5.2 MANEJAR ACTUALIZACIÓN DE RECORDATORIO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_recordatorio'])) {
try {
$id = (int)($_POST['recordatorio_id'] ?? 0);
$empresa_id = !empty($_POST['recordatorio_empresa_id']) ? (int)$_POST['recordatorio_empresa_id'] : null;
$empresa_nombre = trim($_POST['recordatorio_empresa_nombre'] ?? '');
$tipo_recordatorio = trim($_POST['tipo_recordatorio'] ?? '');
$descripcion = trim($_POST['recordatorio_descripcion'] ?? '');
$monto_estimado = !empty($_POST['monto_estimado']) ? str_replace(['.', ','], ['', '.'], $_POST['monto_estimado']) : null;
$fecha_recordatorio = $_POST['fecha_recordatorio'] ?? date('Y-m-d');
$fecha_vencimiento = !empty($_POST['recordatorio_fecha_vencimiento']) ? $_POST['recordatorio_fecha_vencimiento'] : null;
$frecuencia = $_POST['frecuencia'] ?? 'unica';
$estado = $_POST['recordatorio_estado'] ?? 'pendiente';
$prioridad = $_POST['prioridad'] ?? 'media';
$jurisdiccion = trim($_POST['recordatorio_jurisdiccion'] ?? '');
$observaciones = trim($_POST['recordatorio_observaciones'] ?? '');
if ($id <= 0) {
throw new Exception('ID de recordatorio inválido');
}
$stmt = $conn->prepare("SELECT * FROM recordatorios_pagos WHERE id = ?");
$stmt->execute([$id]);
$datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$datos_antiguos) {
throw new Exception('Recordatorio no encontrado');
}
$stmt = $conn->prepare("
UPDATE recordatorios_pagos SET
empresa_id = ?, empresa_nombre = ?, tipo_recordatorio = ?, descripcion = ?,
monto_estimado = ?, fecha_recordatorio = ?, fecha_vencimiento = ?, frecuencia = ?,
estado = ?, prioridad = ?, jurisdiccion = ?, observaciones = ?
WHERE id = ?
");
$stmt->execute([
$empresa_id, $empresa_nombre, $tipo_recordatorio, $descripcion,
$monto_estimado, $fecha_recordatorio, $fecha_vencimiento, $frecuencia,
$estado, $prioridad, $jurisdiccion, $observaciones, $id
]);
$detalles = [
'accion' => 'recordatorio_actualizado',
'id' => $id,
'datos_anteriores' => $datos_antiguos,
'estado_anterior' => $datos_antiguos['estado'],
'estado_nuevo' => $estado
];
logAuditoria($conn, 'RECORDATORIO_ACTUALIZADO', 'recordatorios_pagos', $id, $detalles, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<i class='fas fa-check-circle me-2'></i>
<strong>¡Recordatorio actualizado exitosamente!</strong><br>
Empresa: {$empresa_nombre} | Tipo: " . ucfirst($tipo_recordatorio)
. "</div>";
header('Location: pagos_servicios.php#section-recordatorios');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php#section-recordatorios');
exit;
}
}
// ============================================================================
// 5.3 MANEJAR ELIMINACIÓN DE RECORDATORIO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_recordatorio'])) {
try {
$id = (int)($_POST['recordatorio_id'] ?? 0);
if ($id <= 0) {
throw new Exception('ID de recordatorio inválido');
}
$stmt = $conn->prepare("SELECT * FROM recordatorios_pagos WHERE id = ?");
$stmt->execute([$id]);
$recordatorio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$recordatorio) {
throw new Exception('Recordatorio no encontrado');
}
$stmt = $conn->prepare("DELETE FROM recordatorios_pagos WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'RECORDATORIO_ELIMINADO', 'recordatorios_pagos', $id, [
'tipo' => $recordatorio['tipo_recordatorio'],
'empresa' => $recordatorio['empresa_nombre'],
'fecha' => $recordatorio['fecha_recordatorio']
], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Recordatorio eliminado correctamente</div>";
header('Location: pagos_servicios.php#section-recordatorios');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php#section-recordatorios');
exit;
}
}
// ============================================================================
// 5.4 MARCAR RECORDATORIO COMO COMPLETADO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['completar_recordatorio'])) {
try {
$id = (int)($_POST['recordatorio_id'] ?? 0);
if ($id <= 0) {
throw new Exception('ID de recordatorio inválido');
}
$stmt = $conn->prepare("UPDATE recordatorios_pagos SET estado = 'completado', fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = ?");
$stmt->execute([$id]);
logAuditoria($conn, 'RECORDATORIO_COMPLETADO', 'recordatorios_pagos', $id, ['accion' => 'marcado_completado'], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Recordatorio marcado como completado</div>";
header('Location: pagos_servicios.php#section-recordatorios');
exit;
} catch (Exception $e) {
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: pagos_servicios.php#section-recordatorios');
exit;
}
}
// ============================================================================
// 6. EXPORTAR DATOS
// ============================================================================
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['csv', 'json'])) {
try {
$sql = "SELECT * FROM pagos_servicios WHERE 1=1";
$params = [];
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($_GET['exportar'] === 'csv') {
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=pagos_servicios_' . date('Y-m-d_His') . '.csv');
$output = fopen('php://output', 'w');
fputcsv($output, [
'ID', 'Empresa', 'Boleta Nº', 'Fecha de Pago', 'Servicios Registrales',
'Valor Módulo', 'Cantidad Módulos', 'Período', 'Monto Total',
'Estado', 'Jurisdicción', 'Motivo'
]);
foreach ($pagos as $pago) {
fputcsv($output, [
$pago['id'],
$pago['empresa_nombre'],
$pago['boleta_numero'],
$pago['fecha_pago'],
$pago['servicios_registrales'],
$pago['valor_modulo'],
$pago['cantidad_modulos'],
$pago['periodo'],
$pago['monto_total'],
$pago['estado'],
$pago['jurisdiccion'] ?? '',
$pago['motivo'] ?? ''
]);
}
fclose($output);
exit;
}
if ($_GET['exportar'] === 'json') {
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=pagos_servicios_' . date('Y-m-d_His') . '.json');
echo json_encode($pagos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
}
} catch (Exception $e) {
$_SESSION['error'] = 'Error al exportar: ' . $e->getMessage();
header('Location: pagos_servicios.php');
exit;
}
}
// ============================================================================
// 7. OBTENER DATOS CON FILTROS Y PAGINACIÓN
// ============================================================================
$search_empresa = $_GET['search_empresa'] ?? '';
$search_boleta = $_GET['search_boleta'] ?? '';
$search_periodo = $_GET['search_periodo'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$registros_por_pagina = 15;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
try {
$where_clauses = [];
$params = [];
if (!empty($search_empresa)) {
$where_clauses[] = "empresa_nombre LIKE :empresa";
$params[':empresa'] = '%' . $search_empresa . '%';
}
if (!empty($search_boleta)) {
$where_clauses[] = "boleta_numero LIKE :boleta";
$params[':boleta'] = '%' . $search_boleta . '%';
}
if (!empty($search_periodo)) {
$where_clauses[] = "periodo LIKE :periodo";
$params[':periodo'] = '%' . $search_periodo . '%';
}
if ($search_estado !== 'todos') {
$where_clauses[] = "estado = :estado";
$params[':estado'] = $search_estado;
}
if (!empty($fecha_desde)) {
$where_clauses[] = "fecha_pago >= :fecha_desde";
$params[':fecha_desde'] = $fecha_desde;
}
if (!empty($fecha_hasta)) {
$where_clauses[] = "fecha_pago <= :fecha_hasta";
$params[':fecha_hasta'] = $fecha_hasta;
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
// Total de registros
$count_sql = "SELECT COUNT(*) as total FROM pagos_servicios $where_sql";
$stmt_count = $conn->prepare($count_sql);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
// Obtener pagos
$sql = "
SELECT * FROM pagos_servicios
$where_sql
ORDER BY fecha_pago DESC, id DESC
LIMIT $registros_por_pagina OFFSET $offset
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Calcular totales
$stmt_total = $conn->prepare("SELECT SUM(monto_total) as total_pagado FROM pagos_servicios $where_sql");
$stmt_total->execute($params);
$total_pagado = $stmt_total->fetch(PDO::FETCH_ASSOC)['total_pagado'] ?? 0;
// Contar por estado
$stmt_estados = $conn->prepare("
SELECT estado, COUNT(*) as cantidad
FROM pagos_servicios
GROUP BY estado
");
$stmt_estados->execute();
$estadisticas_estado = $stmt_estados->fetchAll(PDO::FETCH_ASSOC);
// ============================================================================
// NUEVA CONSULTA: TOTALES POR JURISDICCIÓN
// ============================================================================
$stmt_jurisdiccion = $conn->prepare("
SELECT
COALESCE(jurisdiccion, 'Sin jurisdicción') as jurisdiccion,
COUNT(*) as cantidad_pagos,
SUM(monto_total) as total_recaudado,
MIN(fecha_vencimiento) as primer_vencimiento,
MAX(fecha_vencimiento) as ultimo_vencimiento
FROM pagos_servicios
WHERE estado IN ('pagado', 'parcial')
GROUP BY jurisdiccion
ORDER BY total_recaudado DESC
");
$stmt_jurisdiccion->execute();
$totales_por_jurisdiccion = $stmt_jurisdiccion->fetchAll(PDO::FETCH_ASSOC);
// ============================================================================
// CONSULTA: TOTAL GENERAL DE TODAS LAS JURISDICCIONES
// ============================================================================
$stmt_total_general = $conn->prepare("
SELECT
COUNT(*) as total_pagos,
SUM(monto_total) as monto_total_general
FROM pagos_servicios
WHERE estado IN ('pagado', 'parcial')
");
$stmt_total_general->execute();
$total_general = $stmt_total_general->fetch(PDO::FETCH_ASSOC);
// ============================================================================
// NUEVA CONSULTA: TODOS LOS PAGOS CON FECHAS DE VENCIMIENTO (SOLO CON VENCIMIENTO)
// ============================================================================
$stmt_vencimientos = $conn->prepare("
SELECT
id, empresa_nombre, boleta_numero, fecha_pago, fecha_vencimiento,
periodo, monto_total, estado, jurisdiccion
FROM pagos_servicios
WHERE fecha_vencimiento IS NOT NULL
ORDER BY fecha_vencimiento ASC, fecha_pago DESC
LIMIT 100
");
$stmt_vencimientos->execute();
$pagos_con_vencimiento = $stmt_vencimientos->fetchAll(PDO::FETCH_ASSOC);
// ============================================================================
// NUEVA CONSULTA: RECORDATORIOS DE PAGOS PENDIENTES
// ============================================================================
$stmt_recordatorios = $conn->prepare("
SELECT * FROM recordatorios_pagos
WHERE activo = 1 AND estado IN ('pendiente', 'vencido')
ORDER BY fecha_recordatorio ASC, prioridad DESC
LIMIT 50
");
$stmt_recordatorios->execute();
$recordatorios_pendientes = $stmt_recordatorios->fetchAll(PDO::FETCH_ASSOC);
// ============================================================================
// ESTADÍSTICAS DE RECORDATORIOS
// ============================================================================
$stmt_stats_recordatorios = $conn->prepare("
SELECT
COUNT(*) as total,
SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
SUM(CASE WHEN estado = 'vencido' THEN 1 ELSE 0 END) as vencidos,
SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completados
FROM recordatorios_pagos
WHERE activo = 1
");
$stmt_stats_recordatorios->execute();
$stats_recordatorios = $stmt_stats_recordatorios->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
$pagos = [];
$total_registros = 0;
$total_paginas = 0;
$total_pagado = 0;
$estadisticas_estado = [];
$totales_por_jurisdiccion = [];
$total_general = ['total_pagos' => 0, 'monto_total_general' => 0];
$pagos_con_vencimiento = [];
$recordatorios_pendientes = [];
$stats_recordatorios = ['total' => 0, 'pendientes' => 0, 'vencidos' => 0, 'completados' => 0];
$error = "Error al cargar los pagos: " . htmlspecialchars($e->getMessage());
}
// ============================================================================
// 8. OBTENER EMPRESAS PARA SELECT
// ============================================================================
try {
$stmt = $conn->query("SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY nombre");
$empresas_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
$empresas_disponibles = [];
}
// ============================================================================
// 9. FUNCIONES DE UTILIDAD
// ============================================================================
function formatearFecha($fecha) {
if (empty($fecha)) return '-';
return date('d/m/Y', strtotime($fecha));
}
function formatearMonto($monto) {
return '$ ' . number_format($monto, 2, ',', '.');
}
function getEstadoBadge($estado) {
$badges = [
'pendiente' => ['class' => 'bg-warning text-dark', 'icon' => 'fa-clock'],
'pagado' => ['class' => 'bg-success', 'icon' => 'fa-check-circle'],
'vencido' => ['class' => 'bg-danger', 'icon' => 'fa-exclamation-triangle'],
'cancelado' => ['class' => 'bg-secondary', 'icon' => 'fa-ban'],
'parcial' => ['class' => 'bg-info', 'icon' => 'fa-adjust']
];
$badge = $badges[$estado] ?? ['class' => 'bg-secondary', 'icon' => 'fa-question-circle'];
return "<span class='badge {$badge['class']}'><i class='fas {$badge['icon']} me-1'></i>" . ucfirst($estado) . "</span>";
}
function getPrioridadBadge($prioridad) {
$badges = [
'baja' => ['class' => 'bg-secondary', 'icon' => 'fa-arrow-down'],
'media' => ['class' => 'bg-info text-dark', 'icon' => 'fa-arrow-right'],
'alta' => ['class' => 'bg-warning text-dark', 'icon' => 'fa-arrow-up'],
'urgente' => ['class' => 'bg-danger', 'icon' => 'fa-exclamation']
];
$badge = $badges[$prioridad] ?? ['class' => 'bg-secondary', 'icon' => 'fa-question'];
return "<span class='badge {$badge['class']}'><i class='fas {$badge['icon']} me-1'></i>" . ucfirst($prioridad) . "</span>";
}
function getTipoRecordatorioBadge($tipo) {
$badges = [
'credenciales' => ['class' => 'bg-primary', 'icon' => 'fa-id-card'],
'aranceles' => ['class' => 'bg-success', 'icon' => 'fa-file-invoice-dollar'],
'multas' => ['class' => 'bg-danger', 'icon' => 'fa-gavel']
];
$badge = $badges[$tipo] ?? ['class' => 'bg-secondary', 'icon' => 'fa-question'];
return "<span class='badge {$badge['class']}'><i class='fas {$badge['icon']} me-1'></i>" . ucfirst($tipo) . "</span>";
}
function generarUrlOrden($columna, $direccion) {
$params = $_GET;
$params['orden'] = $columna;
$params['direccion'] = $direccion;
return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Pagos de Servicios - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
--primary-color: #0d6efd;
--bg-color: #f8f9fa;
--card-border: #dee2e6;
}
body {
padding-top: 80px;
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
background-color: var(--bg-color);
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
cursor: pointer;
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
.table thead {
background-color: #f8f9fa;
border-bottom: 2px solid var(--card-border);
}
.form-label {
font-weight: 600;
font-size: 0.9rem;
}
.pagination-custom .page-link {
color: #495057;
background-color: #f8f9fa;
border: 1px solid #dee2e6;
border-radius: 4px !important;
margin: 0 2px;
}
.pagination-custom .page-item.active .page-link {
background-color: #0d6efd;
border-color: #0d6efd;
color: #ffffff;
}
.jurisdiccion-card {
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
color: white;
border-radius: 8px;
padding: 15px;
margin-bottom: 10px;
}
.jurisdiccion-card .monto {
font-size: 1.3rem;
font-weight: 700;
}
.jurisdiccion-card .detalle {
font-size: 0.85rem;
opacity: 0.9;
}
.jurisdiccion-total-card {
background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
color: white;
border-radius: 8px;
padding: 15px;
margin-bottom: 10px;
border: 2px solid #fff;
}
.jurisdiccion-total-card .monto {
font-size: 1.5rem;
font-weight: 700;
}
.jurisdiccion-total-card .detalle {
font-size: 0.9rem;
opacity: 0.95;
}
.vencimiento-alert {
border-left: 4px solid #ffc107;
background-color: #fff3cd;
padding: 10px 15px;
margin-bottom: 8px;
border-radius: 4px;
}
.vencimiento-alert.vencido {
border-left-color: #dc3545;
background-color: #f8d7da;
}
.vencimiento-alert.proximo {
border-left-color: #17a2b8;
background-color: #d1ecf1;
}
.recordatorio-card {
border-left: 4px solid #0d6efd;
background-color: #f8f9fa;
padding: 12px 15px;
margin-bottom: 8px;
border-radius: 4px;
transition: all 0.2s ease;
}
.recordatorio-card:hover {
background-color: #e9ecef;
transform: translateX(3px);
}
.recordatorio-card.vencido {
border-left-color: #dc3545;
background-color: #fff5f5;
}
.recordatorio-card.urgente {
border-left-color: #fd7e14;
background-color: #fff8f0;
}
.recordatorio-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 8px;
}
.recordatorio-fecha {
font-weight: 600;
color: #495057;
}
.recordatorio-dias {
font-size: 0.85rem;
}
.recordatorio-dias.pronto {
color: #fd7e14;
font-weight: 600;
}
.recordatorio-dias.vencido {
color: #dc3545;
font-weight: 600;
}
.recordatorio-acciones {
margin-top: 10px;
display: flex;
gap: 5px;
}
</style>
</head>
<body>
<!-- HEADER -->
<?php $page_title = 'Gestión de Pagos de Servicios Registrales'; include '../includes/header.php'; ?>
<div class="dashboard">
<!-- SIDEBAR -->
<?php include '../includes/sidebar.php'; ?>
<!-- CONTENIDO PRINCIPAL -->
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<!-- MENSAJES -->
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
<?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
<?php echo $error; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-primary">
<i class="fas fa-receipt fa-2x"></i>
</div>
<div class="stat-number"><?php echo $total_registros; ?></div>
<div class="stat-label">Pagos Registrados</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success">
<i class="fas fa-dollar-sign fa-2x"></i>
</div>
<div class="stat-number"><?php echo formatearMonto($total_pagado); ?></div>
<div class="stat-label">Total Pagado</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning">
<i class="fas fa-clock fa-2x"></i>
</div>
<div class="stat-number">
<?php
foreach ($estadisticas_estado as $est) {
if ($est['estado'] === 'pendiente') echo $est['cantidad'];
}
?>
</div>
<div class="stat-label">Pendientes</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-danger">
<i class="fas fa-exclamation-triangle fa-2x"></i>
</div>
<div class="stat-number">
<?php
foreach ($estadisticas_estado as $est) {
if ($est['estado'] === 'vencido') echo $est['cantidad'];
}
?>
</div>
<div class="stat-label">Vencidos</div>
</div>
</div>
<!-- ESTADÍSTICAS DE RECORDATORIOS -->
<div class="stats-container">
<div class="stat-card" style="border-left: 4px solid #0d6efd;">
<div class="stat-icon mb-2 text-primary">
<i class="fas fa-bell fa-2x"></i>
</div>
<div class="stat-number"><?php echo $stats_recordatorios['total'] ?? 0; ?></div>
<div class="stat-label">Recordatorios</div>
</div>
<div class="stat-card" style="border-left: 4px solid #ffc107;">
<div class="stat-icon mb-2 text-warning">
<i class="fas fa-clock fa-2x"></i>
</div>
<div class="stat-number"><?php echo $stats_recordatorios['pendientes'] ?? 0; ?></div>
<div class="stat-label">Pendientes</div>
</div>
<div class="stat-card" style="border-left: 4px solid #dc3545;">
<div class="stat-icon mb-2 text-danger">
<i class="fas fa-exclamation-triangle fa-2x"></i>
</div>
<div class="stat-number"><?php echo $stats_recordatorios['vencidos'] ?? 0; ?></div>
<div class="stat-label">Vencidos</div>
</div>
<div class="stat-card" style="border-left: 4px solid #198754;">
<div class="stat-icon mb-2 text-success">
<i class="fas fa-check-circle fa-2x"></i>
</div>
<div class="stat-number"><?php echo $stats_recordatorios['completados'] ?? 0; ?></div>
<div class="stat-label">Completados</div>
</div>
</div>
<!-- NUEVA SECCIÓN: TOTALES POR JURISDICCIÓN -->
<div class="section-box">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#jurisdiccionContent">
<i class="fas fa-map-marker-alt me-2"></i>Totales Recaudados por Jurisdicción
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="jurisdiccionContent" class="collapse">
<?php if (empty($totales_por_jurisdiccion) && (!$total_general || $total_general['monto_total_general'] == 0)): ?>
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>No hay datos de recaudación por jurisdicción disponibles.
</div>
<?php else: ?>
<!-- TARJETA DE TOTAL GENERAL -->
<div class="jurisdiccion-total-card mb-3">
<div class="d-flex justify-content-between align-items-center mb-2">
<strong class="jurisdiccion-nombre"><i class="fas fa-calculator me-2"></i>TOTAL GENERAL - TODAS LAS JURISDICCIONES</strong>
<span class="badge bg-white text-success"><?php echo $total_general['total_pagos'] ?? 0; ?> pagos</span>
</div>
<div class="monto"><?php echo formatearMonto($total_general['monto_total_general'] ?? 0); ?></div>
<div class="detalle mt-2">
<span class="text-white-50"><i class="fas fa-info-circle me-1"></i>Suma de todos los pagos en estado "Pagado" o "Parcial"</span>
</div>
</div>
<div class="row">
<?php foreach ($totales_por_jurisdiccion as $jur): ?>
<div class="col-md-4 col-lg-3 mb-3">
<div class="jurisdiccion-card">
<div class="d-flex justify-content-between align-items-center mb-2">
<strong class="jurisdiccion-nombre"><?php echo htmlspecialchars($jur['jurisdiccion']); ?></strong>
<span class="badge bg-white text-primary"><?php echo $jur['cantidad_pagos']; ?> pagos</span>
</div>
<div class="monto"><?php echo formatearMonto($jur['total_recaudado']); ?></div>
<div class="detalle mt-2">
<?php if ($jur['primer_vencimiento'] && $jur['ultimo_vencimiento']): ?>
<i class="fas fa-calendar-alt me-1"></i>
<?php echo formatearFecha($jur['primer_vencimiento']); ?> - <?php echo formatearFecha($jur['ultimo_vencimiento']); ?>
<?php else: ?>
<span class="text-white-50">Sin fechas de vencimiento</span>
<?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<!-- NUEVA SECCIÓN: FECHAS DE VENCIMIENTO -->
<div class="section-box">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#vencimientosContent">
<i class="fas fa-calendar-check me-2"></i>Fechas de Vencimiento de Pagos
<span class="badge bg-secondary ms-2">Últimos 100 con vencimiento</span>
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="vencimientosContent" class="collapse">
<?php if (empty($pagos_con_vencimiento)): ?>
<div class="text-center py-4 bg-light rounded">
<i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
<h5>No hay registros con fechas de vencimiento</h5>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover table-sm">
<thead class="table-light">
<tr>
<th>Boleta</th>
<th>Empresa</th>
<th>Jurisdicción</th>
<th>Período</th>
<th>Fecha Pago</th>
<th>Fecha Vencimiento</th>
<th>Monto</th>
<th>Estado</th>
<th>Días Restantes</th>
</tr>
</thead>
<tbody>
<?php foreach ($pagos_con_vencimiento as $pago):
$fechaVenc = $pago['fecha_vencimiento'] ?? null;
$diasRestantes = $fechaVenc ? floor((strtotime($fechaVenc) - time()) / (60 * 60 * 24)) : null;
$claseVencimiento = '';
if ($fechaVenc) {
if ($diasRestantes < 0) $claseVencimiento = 'vencido';
elseif ($diasRestantes <= 7) $claseVencimiento = 'proximo';
}
?>
<tr>
<td><small class="text-muted">#<?php echo $pago['id']; ?></small><br><strong><?php echo htmlspecialchars($pago['boleta_numero']); ?></strong></td>
<td><?php echo htmlspecialchars($pago['empresa_nombre']); ?></td>
<td><small><?php echo htmlspecialchars($pago['jurisdiccion'] ?? '-'); ?></small></td>
<td><?php echo htmlspecialchars($pago['periodo']); ?></td>
<td><?php echo formatearFecha($pago['fecha_pago']); ?></td>
<td>
<?php if ($fechaVenc): ?>
<span class="<?php echo $claseVencimiento ? 'fw-bold' : ''; ?>">
<?php echo formatearFecha($fechaVenc); ?>
</span>
<?php else: ?>
<span class="text-muted">-</span>
<?php endif; ?>
</td>
<td><strong><?php echo formatearMonto($pago['monto_total']); ?></strong></td>
<td><?php echo getEstadoBadge($pago['estado']); ?></td>
<td>
<?php if ($fechaVenc): ?>
<?php if ($diasRestantes < 0): ?>
<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i><?php echo abs($diasRestantes); ?> días vencido</span>
<?php elseif ($diasRestantes == 0): ?>
<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Hoy</span>
<?php else: ?>
<span class="badge bg-info text-dark"><i class="fas fa-calendar me-1"></i><?php echo $diasRestantes; ?> días</span>
<?php endif; ?>
<?php else: ?>
<span class="text-muted small">Sin venc.</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="mt-3">
<small class="text-muted">
<i class="fas fa-info-circle me-1"></i>
Mostrando los últimos 100 registros CON fecha de vencimiento, ordenados por fecha de vencimiento.
Use los filtros principales para búsquedas específicas.
</small>
</div>
<?php endif; ?>
</div>
</div>
<!-- NUEVA SECCIÓN: RECORDATORIOS DE PAGOS -->
<div class="section-box" id="section-recordatorios">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#recordatoriosContent">
<i class="fas fa-bell me-2"></i>Recordatorios de Pagos - Credenciales, Aranceles y Multas
<span class="badge bg-primary ms-2"><?php echo count($recordatorios_pendientes); ?> activos</span>
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="recordatoriosContent" class="collapse">
<!-- Formulario para nuevo recordatorio -->
<div class="card mb-4">
<div class="card-header bg-primary text-white">
<i class="fas fa-plus-circle me-2"></i>Nuevo Recordatorio de Pago
</div>
<div class="card-body">
<form method="POST" action="">
<input type="hidden" name="crear_recordatorio" value="1">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<select name="recordatorio_empresa_id" class="form-select" id="selectEmpresaRecordatorio" onchange="cargarNombreEmpresaRecordatorio()">
<option value="">-- Seleccione --</option>
<?php foreach ($empresas_disponibles as $emp): ?>
<option value="<?php echo $emp['id']; ?>" data-nombre="<?php echo htmlspecialchars($emp['nombre']); ?>">
<?php echo htmlspecialchars($emp['nombre']); ?>
</option>
<?php endforeach; ?>
<option value="otro">-- Otra empresa --</option>
</select>
<input type="text" name="recordatorio_empresa_nombre" id="inputEmpresaNombreRecordatorio" class="form-control mt-2"
placeholder="Nombre de la empresa" style="display: none;">
</div>
<div class="col-md-3">
<label class="form-label">Tipo de Recordatorio <span class="text-danger">*</span></label>
<select name="tipo_recordatorio" class="form-select" required>
<option value="">Seleccione...</option>
<option value="credenciales">Credenciales</option>
<option value="aranceles">Aranceles</option>
<option value="multas">Multas</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Fecha Recordatorio <span class="text-danger">*</span></label>
<input type="date" name="fecha_recordatorio" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Fecha Vencimiento</label>
<input type="date" name="recordatorio_fecha_vencimiento" class="form-control">
</div>
<div class="col-md-1">
<label class="form-label">Prioridad</label>
<select name="prioridad" class="form-select">
<option value="baja">Baja</option>
<option value="media" selected>Media</option>
<option value="alta">Alta</option>
<option value="urgente">Urgente</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Monto Estimado</label>
<input type="text" name="monto_estimado" class="form-control" placeholder="0,00" oninput="formatearMontoInput(this)">
</div>
<div class="col-md-2">
<label class="form-label">Frecuencia</label>
<select name="frecuencia" class="form-select">
<option value="unica">Única</option>
<option value="mensual">Mensual</option>
<option value="trimestral">Trimestral</option>
<option value="semestral">Semestral</option>
<option value="anual">Anual</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Estado</label>
<select name="recordatorio_estado" class="form-select">
<option value="pendiente">Pendiente</option>
<option value="vencido">Vencido</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Jurisdicción</label>
<select name="recordatorio_jurisdiccion" class="form-select" id="selectJurisdiccionRecordatorio" onchange="toggleOtraJurisdiccionRecordatorio()">
<option value="">Seleccione...</option>
<option value="Esquel">Esquel</option>
<option value="Comodoro">Comodoro</option>
<option value="Trelew">Trelew</option>
<option value="Puerto Madryn">Puerto Madryn</option>
<option value="Rawson">Rawson</option>
<option value="otro">-- Otra jurisdicción --</option>
</select>
<input type="text" name="recordatorio_jurisdiccion_texto" id="inputJurisdiccionTextoRecordatorio" class="form-control mt-2"
placeholder="Ingrese jurisdicción" style="display: none;">
</div>
<div class="col-md-4">
<label class="form-label">Descripción</label>
<input type="text" name="recordatorio_descripcion" class="form-control" placeholder="Descripción breve...">
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="recordatorio_observaciones" class="form-control" rows="2" placeholder="Notas adicionales..."></textarea>
</div>
<div class="col-12 text-end">
<button type="submit" class="btn btn-primary">
<i class="fas fa-bell me-2"></i>Crear Recordatorio
</button>
</div>
</div>
</form>
</div>
</div>
<!-- Listado de recordatorios pendientes -->
<h6 class="mb-3"><i class="fas fa-list me-2"></i>Recordatorios Activos</h6>
<?php if (empty($recordatorios_pendientes)): ?>
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>No hay recordatorios pendientes registrados.
</div>
<?php else: ?>
<div class="row">
<?php foreach ($recordatorios_pendientes as $recordatorio):
$fechaRecordatorio = $recordatorio['fecha_recordatorio'];
$diasParaVencer = $fechaRecordatorio ? floor((strtotime($fechaRecordatorio) - time()) / (60 * 60 * 24)) : null;
$claseCard = '';
if ($recordatorio['estado'] === 'vencido') {
$claseCard = 'vencido';
} elseif ($recordatorio['prioridad'] === 'urgente' || ($diasParaVencer !== null && $diasParaVencer <= 3)) {
$claseCard = 'urgente';
}
?>
<div class="col-md-6 col-lg-4">
<div class="recordatorio-card <?php echo $claseCard; ?>">
<div class="recordatorio-header">
<span class="recordatorio-fecha">
<i class="fas fa-calendar me-1"></i><?php echo formatearFecha($fechaRecordatorio); ?>
</span>
<?php echo getPrioridadBadge($recordatorio['prioridad']); ?>
</div>
<div class="mb-2">
<strong><?php echo htmlspecialchars($recordatorio['empresa_nombre']); ?></strong>
</div>
<div class="mb-2">
<?php echo getTipoRecordatorioBadge($recordatorio['tipo_recordatorio']); ?>
<?php if ($recordatorio['monto_estimado']): ?>
<span class="badge bg-light text-dark ms-1"><?php echo formatearMonto($recordatorio['monto_estimado']); ?></span>
<?php endif; ?>
</div>
<?php if ($recordatorio['descripcion']): ?>
<div class="mb-2 small text-muted"><?php echo htmlspecialchars($recordatorio['descripcion']); ?></div>
<?php endif; ?>
<?php if ($recordatorio['jurisdiccion']): ?>
<div class="mb-2 small">
<i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($recordatorio['jurisdiccion']); ?>
</div>
<?php endif; ?>
<div class="recordatorio-dias mb-2">
<?php if ($diasParaVencer !== null): ?>
<?php if ($diasParaVencer < 0): ?>
<span class="recordatorio-dias vencido"><i class="fas fa-exclamation-triangle me-1"></i><?php echo abs($diasParaVencer); ?> días vencido</span>
<?php elseif ($diasParaVencer == 0): ?>
<span class="recordatorio-dias pronto"><i class="fas fa-clock me-1"></i>¡Hoy!</span>
<?php elseif ($diasParaVencer <= 3): ?>
<span class="recordatorio-dias pronto"><i class="fas fa-bell me-1"></i><?php echo $diasParaVencer; ?> días restantes</span>
<?php else: ?>
<span class="text-muted"><i class="fas fa-calendar me-1"></i><?php echo $diasParaVencer; ?> días restantes</span>
<?php endif; ?>
<?php else: ?>
<span class="text-muted small">Sin fecha definida</span>
<?php endif; ?>
</div>
<div class="recordatorio-acciones">
<form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Marcar este recordatorio como completado?');">
<input type="hidden" name="recordatorio_id" value="<?php echo $recordatorio['id']; ?>">
<button type="submit" name="completar_recordatorio" value="1" class="btn btn-sm btn-success">
<i class="fas fa-check"></i>
</button>
</form>
<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editarRecordatorioModal<?php echo $recordatorio['id']; ?>">
<i class="fas fa-edit"></i>
</button>
<form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Eliminar este recordatorio?');">
<input type="hidden" name="recordatorio_id" value="<?php echo $recordatorio['id']; ?>">
<button type="submit" name="eliminar_recordatorio" value="1" class="btn btn-sm btn-outline-danger">
<i class="fas fa-trash"></i>
</button>
</form>
</div>
</div>
</div>
<!-- Modal Editar Recordatorio -->
<div class="modal fade" id="editarRecordatorioModal<?php echo $recordatorio['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="POST" action="">
<input type="hidden" name="actualizar_recordatorio" value="1">
<input type="hidden" name="recordatorio_id" value="<?php echo $recordatorio['id']; ?>">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title">Editar Recordatorio</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Empresa</label>
<input type="text" name="recordatorio_empresa_nombre" class="form-control" value="<?php echo htmlspecialchars($recordatorio['empresa_nombre']); ?>" required>
</div>
<div class="col-md-3">
<label class="form-label">Tipo</label>
<select name="tipo_recordatorio" class="form-select" required>
<option value="credenciales" <?php echo $recordatorio['tipo_recordatorio'] === 'credenciales' ? 'selected' : ''; ?>>Credenciales</option>
<option value="aranceles" <?php echo $recordatorio['tipo_recordatorio'] === 'aranceles' ? 'selected' : ''; ?>>Aranceles</option>
<option value="multas" <?php echo $recordatorio['tipo_recordatorio'] === 'multas' ? 'selected' : ''; ?>>Multas</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Prioridad</label>
<select name="prioridad" class="form-select">
<option value="baja" <?php echo $recordatorio['prioridad'] === 'baja' ? 'selected' : ''; ?>>Baja</option>
<option value="media" <?php echo $recordatorio['prioridad'] === 'media' ? 'selected' : ''; ?>>Media</option>
<option value="alta" <?php echo $recordatorio['prioridad'] === 'alta' ? 'selected' : ''; ?>>Alta</option>
<option value="urgente" <?php echo $recordatorio['prioridad'] === 'urgente' ? 'selected' : ''; ?>>Urgente</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Fecha Recordatorio</label>
<input type="date" name="fecha_recordatorio" class="form-control" value="<?php echo $recordatorio['fecha_recordatorio']; ?>" required>
</div>
<div class="col-md-3">
<label class="form-label">Fecha Vencimiento</label>
<input type="date" name="recordatorio_fecha_vencimiento" class="form-control" value="<?php echo $recordatorio['fecha_vencimiento'] ?? ''; ?>">
</div>
<div class="col-md-3">
<label class="form-label">Frecuencia</label>
<select name="frecuencia" class="form-select">
<option value="unica" <?php echo $recordatorio['frecuencia'] === 'unica' ? 'selected' : ''; ?>>Única</option>
<option value="mensual" <?php echo $recordatorio['frecuencia'] === 'mensual' ? 'selected' : ''; ?>>Mensual</option>
<option value="trimestral" <?php echo $recordatorio['frecuencia'] === 'trimestral' ? 'selected' : ''; ?>>Trimestral</option>
<option value="semestral" <?php echo $recordatorio['frecuencia'] === 'semestral' ? 'selected' : ''; ?>>Semestral</option>
<option value="anual" <?php echo $recordatorio['frecuencia'] === 'anual' ? 'selected' : ''; ?>>Anual</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Estado</label>
<select name="recordatorio_estado" class="form-select">
<option value="pendiente" <?php echo $recordatorio['estado'] === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
<option value="vencido" <?php echo $recordatorio['estado'] === 'vencido' ? 'selected' : ''; ?>>Vencido</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Monto Estimado</label>
<input type="text" name="monto_estimado" class="form-control" value="<?php echo $recordatorio['monto_estimado'] ? number_format($recordatorio['monto_estimado'], 2, ',', '.') : ''; ?>" placeholder="0,00" oninput="formatearMontoInput(this)">
</div>
<div class="col-md-4">
<label class="form-label">Jurisdicción</label>
<input type="text" name="recordatorio_jurisdiccion" class="form-control" value="<?php echo htmlspecialchars($recordatorio['jurisdiccion'] ?? ''); ?>">
</div>
<div class="col-md-5">
<label class="form-label">Descripción</label>
<input type="text" name="recordatorio_descripcion" class="form-control" value="<?php echo htmlspecialchars($recordatorio['descripcion'] ?? ''); ?>">
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="recordatorio_observaciones" class="form-control" rows="2"><?php echo htmlspecialchars($recordatorio['observaciones'] ?? ''); ?></textarea>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-primary">Actualizar</button>
</div>
</form>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<!-- FILTROS -->
<div class="section-box">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#filtrosContent">
<i class="fas fa-filter me-2"></i>Filtros de Búsqueda
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="filtrosContent" class="collapse">
<form method="GET" action="" class="row g-3">
<div class="col-md-3">
<label class="form-label">Empresa</label>
<input type="text" name="search_empresa" class="form-control"
value="<?php echo htmlspecialchars($search_empresa); ?>"
placeholder="Buscar empresa...">
</div>
<div class="col-md-2">
<label class="form-label">Boleta Nº</label>
<input type="text" name="search_boleta" class="form-control"
value="<?php echo htmlspecialchars($search_boleta); ?>"
placeholder="Nº Boleta...">
</div>
<div class="col-md-2">
<label class="form-label">Período</label>
<input type="text" name="search_periodo" class="form-control"
value="<?php echo htmlspecialchars($search_periodo); ?>"
placeholder="Ej: enero 2025">
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="search_estado" class="form-select">
<option value="todos">Todos</option>
<option value="pendiente" <?php echo $search_estado === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
<option value="pagado" <?php echo $search_estado === 'pagado' ? 'selected' : ''; ?>>Pagado</option>
<option value="vencido" <?php echo $search_estado === 'vencido' ? 'selected' : ''; ?>>Vencido</option>
<option value="cancelado" <?php echo $search_estado === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Fecha Desde</label>
<input type="date" name="fecha_desde" class="form-control"
value="<?php echo htmlspecialchars($fecha_desde); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Fecha Hasta</label>
<input type="date" name="fecha_hasta" class="form-control"
value="<?php echo htmlspecialchars($fecha_hasta); ?>">
</div>
<div class="col-12 d-flex gap-2">
<button type="submit" class="btn btn-primary">
<i class="fas fa-search me-2"></i>Filtrar
</button>
<a href="pagos_servicios.php" class="btn btn-secondary">
<i class="fas fa-undo me-2"></i>Limpiar
</a>
<div class="ms-auto">
<a href="?exportar=csv" class="btn btn-success">
<i class="fas fa-file-csv me-2"></i>Exportar CSV
</a>
</div>
</div>
</form>
</div>
</div>
<!-- NUEVO PAGO -->
<div class="section-box">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#nuevoPagoForm">
<i class="fas fa-plus-circle me-2"></i>Registrar Nuevo Pago
<i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="nuevoPagoForm" class="collapse">
<form method="POST" action="">
<input type="hidden" name="crear_pago" value="1">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<select name="empresa_id" class="form-select" id="selectEmpresa" onchange="cargarNombreEmpresa()">
<option value="">-- Seleccione --</option>
<?php foreach ($empresas_disponibles as $emp): ?>
<option value="<?php echo $emp['id']; ?>" data-nombre="<?php echo htmlspecialchars($emp['nombre']); ?>">
<?php echo htmlspecialchars($emp['nombre']); ?>
</option>
<?php endforeach; ?>
<option value="otro">-- Otra empresa --</option>
</select>
<input type="text" name="empresa_nombre" id="inputEmpresaNombre" class="form-control mt-2"
placeholder="Nombre de la empresa" style="display: none;">
</div>
<div class="col-md-2">
<label class="form-label">Boleta Nº <span class="text-danger">*</span></label>
<input type="text" name="boleta_numero" class="form-control" required>
</div>
<div class="col-md-2">
<label class="form-label">Fecha de Pago <span class="text-danger">*</span></label>
<input type="date" name="fecha_pago" id="fechaPago" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Fecha Vencimiento</label>
<input type="date" name="fecha_vencimiento" id="fechaVencimiento" class="form-control" readonly>
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="estado" class="form-select">
<option value="pendiente">Pendiente</option>
<option value="pagado">Pagado</option>
<option value="vencido">Vencido</option>
<option value="cancelado">Cancelado</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Servicios Registrales <span class="text-danger">*</span></label>
<select name="servicios_registrales" class="form-select" required>
<option value="">Seleccione...</option>
<option value="Inc. a">Inc. a</option>
<option value="Inc. b">Inc. b</option>
<option value="Inc. c">Inc. c</option>
<option value="Tasa registral">Tasa registral</option>
<option value="multas">Multas</option>
<option value="Otros">Otros</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Valor Módulo <span class="text-danger">*</span></label>
<input type="text" name="valor_modulo" id="valorModulo" class="form-control"
placeholder="0,00" required oninput="calcularMonto()">
</div>
<div class="col-md-2">
<label class="form-label">Cantidad Módulos <span class="text-danger">*</span></label>
<input type="number" name="cantidad_modulos" id="cantidadModulos" class="form-control"
value="1" min="1" required oninput="calcularMonto()">
</div>
<div class="col-md-3">
<label class="form-label">Período <span class="text-danger">*</span></label>
<input type="text" name="periodo" class="form-control" required placeholder="Ej: enero 2025">
</div>
<div class="col-md-3">
<label class="form-label">Monto Total</label>
<input type="text" id="montoTotal" class="form-control bg-light" readonly value="$ 0,00">
</div>
<div class="col-md-3">
<label class="form-label">Método de Pago</label>
<select name="metodo_pago" class="form-select">
<option value="">Seleccione...</option>
<option value="transferencia">Transferencia</option>
<option value="efectivo">Efectivo</option>
<option value="cheque">Cheque</option>
<option value="tarjeta">Tarjeta</option>
<option value="debito_automatico">Débito Automático</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Nro. Transacción</label>
<input type="text" name="nro_transaccion" class="form-control" placeholder="Nro. comprobante">
</div>
<div class="col-md-3">
<label class="form-label">Jurisdicción</label>
<select name="jurisdiccion" class="form-select" id="selectJurisdiccion" onchange="toggleOtraJurisdiccion()">
<option value="">Seleccione...</option>
<option value="Esquel">Esquel</option>
<option value="Comodoro">Comodoro</option>
<option value="Trelew">Trelew</option>
<option value="Puerto Madryn">Puerto Madryn</option>
<option value="Rawson">Rawson</option>
<option value="otro">-- Otra jurisdicción --</option>
</select>
<input type="text" name="jurisdiccion_texto" id="inputJurisdiccionTexto" class="form-control mt-2"
placeholder="Ingrese jurisdicción" style="display: none;">
</div>
<div class="col-md-4">
<label class="form-label">Motivo</label>
<input type="text" name="motivo" class="form-control" placeholder="Motivo del pago...">
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="observaciones" class="form-control" rows="2"></textarea>
</div>
<div class="col-12 text-end">
<button type="submit" class="btn btn-success">
<i class="fas fa-save me-2"></i>Registrar Pago
</button>
</div>
</div>
</form>
</div>
</div>
<!-- LISTADO DE PAGOS -->
<div class="section-box">
<div class="section-title">
<i class="fas fa-table me-2"></i>Listado de Pagos
<span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
</div>
<?php if (empty($pagos)): ?>
<div class="text-center py-5 bg-light rounded">
<i class="fas fa-receipt fa-3x text-muted mb-3"></i>
<h5>No hay pagos registrados</h5>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover table-striped">
<thead>
<tr>
<th>ID</th>
<th>Empresa</th>
<th>Boleta Nº</th>
<th>Fecha Pago</th>
<th>Servicios</th>
<th>Valor Módulo</th>
<th>Cant.</th>
<th>Período</th>
<th>Monto Total</th>
<th>Estado</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($pagos as $pago): ?>
<tr>
<td><strong>#<?php echo $pago['id']; ?></strong></td>
<td><?php echo htmlspecialchars($pago['empresa_nombre']); ?></td>
<td><span class="badge bg-info"><?php echo htmlspecialchars($pago['boleta_numero']); ?></span></td>
<td><?php echo formatearFecha($pago['fecha_pago']); ?></td>
<td><?php echo htmlspecialchars($pago['servicios_registrales']); ?></td>
<td><?php echo formatearMonto($pago['valor_modulo']); ?></td>
<td class="text-center"><?php echo $pago['cantidad_modulos']; ?></td>
<td><?php echo htmlspecialchars($pago['periodo']); ?></td>
<td><strong class="text-success"><?php echo formatearMonto($pago['monto_total']); ?></strong></td>
<td><?php echo getEstadoBadge($pago['estado']); ?></td>
<td>
<div class="btn-group btn-group-sm">
<button class="btn btn-outline-primary" data-bs-toggle="modal"
data-bs-target="#editarModal<?php echo $pago['id']; ?>" title="Editar">
<i class="fas fa-edit"></i>
</button>
<button class="btn btn-outline-danger" data-bs-toggle="modal"
data-bs-target="#eliminarModal<?php echo $pago['id']; ?>" title="Eliminar">
<i class="fas fa-trash"></i>
</button>
</div>
</td>
</tr>
<!-- Modal Editar Pago -->
<div class="modal fade" id="editarModal<?php echo $pago['id']; ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="POST" action="">
<input type="hidden" name="actualizar_pago" value="1">
<input type="hidden" name="id" value="<?php echo $pago['id']; ?>">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title">Editar Pago</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Empresa <span class="text-danger">*</span></label>
<input type="text" name="empresa_nombre" class="form-control" value="<?php echo htmlspecialchars($pago['empresa_nombre']); ?>" required>
</div>
<div class="col-md-2">
<label class="form-label">Boleta Nº <span class="text-danger">*</span></label>
<input type="text" name="boleta_numero" class="form-control" value="<?php echo htmlspecialchars($pago['boleta_numero']); ?>" required>
</div>
<div class="col-md-2">
<label class="form-label">Fecha de Pago <span class="text-danger">*</span></label>
<input type="date" name="fecha_pago" class="form-control" value="<?php echo $pago['fecha_pago']; ?>" required>
</div>
<div class="col-md-2">
<label class="form-label">Fecha Vencimiento</label>
<input type="date" name="fecha_vencimiento" class="form-control" value="<?php echo $pago['fecha_vencimiento'] ?? ''; ?>">
</div>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="estado" class="form-select">
<option value="pendiente" <?php echo $pago['estado'] === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
<option value="pagado" <?php echo $pago['estado'] === 'pagado' ? 'selected' : ''; ?>>Pagado</option>
<option value="vencido" <?php echo $pago['estado'] === 'vencido' ? 'selected' : ''; ?>>Vencido</option>
<option value="cancelado" <?php echo $pago['estado'] === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
<option value="parcial" <?php echo $pago['estado'] === 'parcial' ? 'selected' : ''; ?>>Parcial</option>
</select>
</div>
<div class="col-md-4">
<label class="form-label">Servicios Registrales <span class="text-danger">*</span></label>
<select name="servicios_registrales" class="form-select" required>
<option value="Inc. a" <?php echo $pago['servicios_registrales'] === 'Inc. a' ? 'selected' : ''; ?>>Inc. a</option>
<option value="Inc. b" <?php echo $pago['servicios_registrales'] === 'Inc. b' ? 'selected' : ''; ?>>Inc. b</option>
<option value="Inc. c" <?php echo $pago['servicios_registrales'] === 'Inc. c' ? 'selected' : ''; ?>>Inc. c</option>
<option value="Tasa registral" <?php echo $pago['servicios_registrales'] === 'Tasa registral' ? 'selected' : ''; ?>>Tasa registral</option>
<option value="multas" <?php echo $pago['servicios_registrales'] === 'multas' ? 'selected' : ''; ?>>Multas</option>
<option value="Otros" <?php echo $pago['servicios_registrales'] === 'Otros' ? 'selected' : ''; ?>>Otros</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Valor Módulo <span class="text-danger">*</span></label>
<input type="text" name="valor_modulo" class="form-control" value="<?php echo number_format($pago['valor_modulo'], 2, ',', '.'); ?>" required oninput="formatearMontoInput(this)">
</div>
<div class="col-md-2">
<label class="form-label">Cantidad Módulos <span class="text-danger">*</span></label>
<input type="number" name="cantidad_modulos" class="form-control" value="<?php echo $pago['cantidad_modulos']; ?>" min="1" required>
</div>
<div class="col-md-3">
<label class="form-label">Período <span class="text-danger">*</span></label>
<input type="text" name="periodo" class="form-control" value="<?php echo htmlspecialchars($pago['periodo']); ?>" required>
</div>
<div class="col-md-3">
<label class="form-label">Monto Total</label>
<input type="text" class="form-control bg-light" readonly value="<?php echo formatearMonto($pago['monto_total']); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Método de Pago</label>
<select name="metodo_pago" class="form-select">
<option value="">Seleccione...</option>
<option value="transferencia" <?php echo $pago['metodo_pago'] === 'transferencia' ? 'selected' : ''; ?>>Transferencia</option>
<option value="efectivo" <?php echo $pago['metodo_pago'] === 'efectivo' ? 'selected' : ''; ?>>Efectivo</option>
<option value="cheque" <?php echo $pago['metodo_pago'] === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
<option value="tarjeta" <?php echo $pago['metodo_pago'] === 'tarjeta' ? 'selected' : ''; ?>>Tarjeta</option>
<option value="debito_automatico" <?php echo $pago['metodo_pago'] === 'debito_automatico' ? 'selected' : ''; ?>>Débito Automático</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Nro. Transacción</label>
<input type="text" name="nro_transaccion" class="form-control" value="<?php echo htmlspecialchars($pago['nro_transaccion'] ?? ''); ?>">
</div>
<div class="col-md-3">
<label class="form-label">Jurisdicción</label>
<input type="text" name="jurisdiccion" class="form-control" value="<?php echo htmlspecialchars($pago['jurisdiccion'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Motivo</label>
<input type="text" name="motivo" class="form-control" value="<?php echo htmlspecialchars($pago['motivo'] ?? ''); ?>">
</div>
<div class="col-12">
<label class="form-label">Observaciones</label>
<textarea name="observaciones" class="form-control" rows="2"><?php echo htmlspecialchars($pago['observaciones'] ?? ''); ?></textarea>
</div>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-primary">Actualizar Pago</button>
</div>
</form>
</div>
</div>
</div>
<!-- Modal Eliminar -->
<div class="modal fade" id="eliminarModal<?php echo $pago['id']; ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST" action="">
<input type="hidden" name="eliminar_pago" value="1">
<input type="hidden" name="id" value="<?php echo $pago['id']; ?>">
<div class="modal-header bg-danger text-white">
<h5 class="modal-title">Eliminar Pago</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p><strong>¿Está seguro que desea eliminar este pago?</strong></p>
<p>Empresa: <?php echo htmlspecialchars($pago['empresa_nombre']); ?></p>
<p>Boleta: <?php echo htmlspecialchars($pago['boleta_numero']); ?></p>
<p>Monto: <?php echo formatearMonto($pago['monto_total']); ?></p>
<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle"></i> Esta acción no se puede deshacer
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-danger">Eliminar</button>
</div>
</form>
</div>
</div>
</div>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- PAGINACIÓN -->
<?php if ($total_paginas > 1): ?>
<nav aria-label="Paginación" class="mt-4">
<ul class="pagination pagination-custom justify-content-center">
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>">
<i class="fas fa-angle-double-left"></i>
</a>
</li>
<li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => max(1, $pagina_actual - 1)])); ?>">
<i class="fas fa-chevron-left"></i>
</a>
</li>
<?php for ($i = max(1, $pagina_actual - 2); $i <= min($total_paginas, $pagina_actual + 2); $i++): ?>
<li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>">
<?php echo $i; ?>
</a>
</li>
<?php endfor; ?>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => min($total_paginas, $pagina_actual + 1)])); ?>">
<i class="fas fa-chevron-right"></i>
</a>
</li>
<li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
<a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>">
<i class="fas fa-angle-double-right"></i>
</a>
</li>
</ul>
</nav>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function cargarNombreEmpresa() {
const select = document.getElementById('selectEmpresa');
const inputNombre = document.getElementById('inputEmpresaNombre');
if (select.value === 'otro') {
inputNombre.style.display = 'block';
inputNombre.required = true;
} else {
inputNombre.style.display = 'none';
inputNombre.required = false;
if (select.value) {
const option = select.options[select.selectedIndex];
inputNombre.value = option.dataset.nombre || '';
} else {
inputNombre.value = '';
}
}
}
function cargarNombreEmpresaRecordatorio() {
const select = document.getElementById('selectEmpresaRecordatorio');
const inputNombre = document.getElementById('inputEmpresaNombreRecordatorio');
if (select.value === 'otro') {
inputNombre.style.display = 'block';
inputNombre.required = true;
} else {
inputNombre.style.display = 'none';
inputNombre.required = false;
if (select.value) {
const option = select.options[select.selectedIndex];
inputNombre.value = option.dataset.nombre || '';
} else {
inputNombre.value = '';
}
}
}
function toggleOtraJurisdiccion() {
const select = document.getElementById('selectJurisdiccion');
const inputTexto = document.getElementById('inputJurisdiccionTexto');
if (select.value === 'otro') {
inputTexto.style.display = 'block';
inputTexto.required = true;
inputTexto.name = 'jurisdiccion';
} else {
inputTexto.style.display = 'none';
inputTexto.required = false;
inputTexto.name = 'jurisdiccion_texto';
}
}
function toggleOtraJurisdiccionRecordatorio() {
const select = document.getElementById('selectJurisdiccionRecordatorio');
const inputTexto = document.getElementById('inputJurisdiccionTextoRecordatorio');
if (select.value === 'otro') {
inputTexto.style.display = 'block';
inputTexto.required = true;
inputTexto.name = 'recordatorio_jurisdiccion';
} else {
inputTexto.style.display = 'none';
inputTexto.required = false;
inputTexto.name = 'recordatorio_jurisdiccion_texto';
}
}
function calcularVencimiento() {
const fechaPago = document.getElementById('fechaPago').value;
if (fechaPago) {
const fecha = new Date(fechaPago);
const nextYear = fecha.getFullYear() + 1;
const vencimiento = `${nextYear}-01-02`;
document.getElementById('fechaVencimiento').value = vencimiento;
}
}
function calcularMonto() {
const valorModulo = parseFloat(document.getElementById('valorModulo').value.replace(/\./g, '').replace(',', '.')) || 0;
const cantidadModulos = parseInt(document.getElementById('cantidadModulos').value) || 1;
const subtotal = valorModulo * cantidadModulos;
const montoTotal = subtotal;
document.getElementById('montoTotal').value = '$ ' + montoTotal.toLocaleString('es-AR', {
minimumFractionDigits: 2,
maximumFractionDigits: 2
});
}
function formatearMontoInput(input) {
let value = input.value.replace(/[^\d,]/g, '');
value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
input.value = value;
}
document.addEventListener('DOMContentLoaded', function() {
const fechaPago = document.getElementById('fechaPago');
if (fechaPago) {
fechaPago.addEventListener('change', calcularVencimiento);
if (fechaPago.value) {
calcularVencimiento();
}
}
const selectJurisdiccion = document.getElementById('selectJurisdiccion');
if (selectJurisdiccion && selectJurisdiccion.value === 'otro') {
toggleOtraJurisdiccion();
}
const selectJurisdiccionRecordatorio = document.getElementById('selectJurisdiccionRecordatorio');
if (selectJurisdiccionRecordatorio && selectJurisdiccionRecordatorio.value === 'otro') {
toggleOtraJurisdiccionRecordatorio();
}
const selectEmpresaRecordatorio = document.getElementById('selectEmpresaRecordatorio');
if (selectEmpresaRecordatorio && selectEmpresaRecordatorio.value === 'otro') {
cargarNombreEmpresaRecordatorio();
}
// Scroll suave a la sección de recordatorios si hay hash en URL
if (window.location.hash === '#section-recordatorios') {
document.getElementById('section-recordatorios').scrollIntoView({ behavior: 'smooth' });
}
});
</script>
</body>
</html>