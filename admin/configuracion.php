<?php
/**
* ============================================================================
* CONFIGURACIÓN DEL SISTEMA - PANEL DE ADMINISTRACIÓN COMPLETO
* ============================================================================
* Incluye: Respaldo SQL completo, Configuración General, Información del Sistema,
*          Auditoría de cambios, Modo Mantenimiento, Gestión de Sesiones.
*
* @author Sistema de Seguridad
* @version 1.0 - Estilo uniforme con empresas.php
* @last_update 2026
* ============================================================================
*/
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ============================================================================
// 1. VERIFICAR AUTENTICACIÓN Y PERMISOS (SOLO ADMINISTRADOR)
// ============================================================================
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
header('Location: ../login.php');
exit;
}
$current_page = 'configuracion';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. MANEJAR GUARDADO DE CONFIGURACIÓN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_configuracion'])) {
try {
// Verificar token anti-CSRF básico (opcional, aquí simplificado con session check)
if (!hash_equals(hash_hmac('sha256', 'config', $_SESSION['config_token'] ?? ''), $_POST['csrf_token'] ?? '')) {
throw new Exception('Token de seguridad inválido. Recarga la página.');
}
$app_name = trim($_POST['app_name'] ?? 'Sistema de Seguridad');
$maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
$session_timeout = max(10, (int)($_POST['session_timeout'] ?? 30));
$max_upload_mb = max(2, (int)($_POST['max_upload_mb'] ?? 10));
// Crear tabla si no existe
$config_table = 'sistema_config';
$table_exists = $conn->query("SHOW TABLES LIKE '$config_table'")->rowCount() > 0;
if (!$table_exists) {
$conn->exec("CREATE TABLE $config_table (
id INT PRIMARY KEY AUTO_INCREMENT,
clave VARCHAR(50) UNIQUE NOT NULL,
valor TEXT,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}
$settings = [
'app_name' => $app_name,
'maintenance_mode' => $maintenance_mode,
'session_timeout' => $session_timeout,
'max_upload_mb' => $max_upload_mb
];
$stmt = $conn->prepare("INSERT INTO $config_table (clave, valor) VALUES (:clave, :valor) ON DUPLICATE KEY UPDATE valor = :valor, updated_at = CURRENT_TIMESTAMP");
foreach ($settings as $k => $v) {
$stmt->execute([':clave' => $k, ':valor' => $v]);
}
logAuditoria($conn, 'CONFIG_ACTUALIZADA', 'configuracion', null, $settings, $user['id']);
$_SESSION['success'] = "
<div class='alert alert-success alert-dismissible fade show' role='alert'>
<div class='d-flex align-items-center'>
<i class='fas fa-check-circle fa-2x me-3 text-success'></i>
<div>
<h5 class='mb-1'><strong>¡Configuración guardada exitosamente!</strong></h5>
<p class='mb-0'>Los cambios se aplicarán en el próximo inicio de sesión.</p>
</div>
</div>
<button type='button' class='btn-close' data-bs-dismiss='alert'></button>
</div>";
header('Location: configuracion.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_CONFIG', 'configuracion', null, ['error' => $e->getMessage()], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: configuracion.php');
exit;
}
}
// ============================================================================
// 3. GENERAR Y DESCARGAR RESPALDO SQL COMPLETO MEJORADO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_backup_db'])) {
try {
set_time_limit(0);
ini_set('memory_limit', '2G');
if (ob_get_level()) ob_end_clean();

// Parámetros mejorados de respaldo
$backup_type = $_POST['backup_type'] ?? 'full'; // full, structure, data
$use_compression = isset($_POST['use_compression']) && $_POST['use_compression'] === '1';
$selected_tables = $_POST['selected_tables'] ?? [];
$exclude_system_tables = isset($_POST['exclude_system_tables']) && $_POST['exclude_system_tables'] === '1';

// Obtener todas las tablas
$all_tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// Filtrar tablas según selección
$tables_to_backup = [];
foreach ($all_tables as $table) {
    if ($exclude_system_tables && preg_match('/^(sessions|logs|auditoria|cache_)/i', $table)) {
        continue;
    }
    if (empty($selected_tables) || in_array($table, $selected_tables)) {
        $tables_to_backup[] = $table;
    }
}

// Configurar cabeceras según compresión
$filename = 'backup_completo_' . date('Y-m-d_His');
if ($use_compression) {
    $filename .= '.sql.gz';
    header('Content-Type: application/gzip; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    ob_start('ob_gzhandler');
} else {
    $filename .= '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Cabeceras del archivo SQL mejoradas
$sql = "-- ============================================================================
";
$sql .= "-- RESPALDO COMPLETO DE BASE DE DATOS - SISTEMA DE SEGURIDAD
";
$sql .= "-- ============================================================================
";
$sql .= "-- Generado: " . date('Y-m-d H:i:s') . "
";
$sql .= "-- Usuario: " . ($user['nombre'] ?? 'N/A') . " " . ($user['apellido'] ?? '') . " (ID: " . ($user['id'] ?? 'N/A') . ")
";
$sql .= "-- Base de datos: " . $conn->query("SELECT DATABASE()")->fetchColumn() . "
";
$sql .= "-- Tipo de respaldo: " . strtoupper($backup_type) . "
";
$sql .= "-- Tablas incluidas: " . count($tables_to_backup) . "
";
$sql .= "-- Versión del sistema: 1.0
";
$sql .= "-- ============================================================================

";
$sql .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
";
$sql .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
";
$sql .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
";
$sql .= "/*!40101 SET NAMES utf8mb4 */;
";
$sql .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
";
$sql .= "/*!40103 SET TIME_ZONE='+00:00' */;
";
$sql .= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
";
$sql .= "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
";
$sql .= "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
";
$sql .= "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
";
$sql .= "
";

// Procesar cada tabla seleccionada
foreach ($tables_to_backup as $table) {
    $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    
    // Estructura de la tabla
    if ($backup_type === 'full' || $backup_type === 'structure') {
        $sql .= "--
-- Estructura de la tabla `$safe_table`
--
";
        $sql .= "DROP TABLE IF EXISTS `$safe_table`;
";
        $create = $conn->query("SHOW CREATE TABLE `$safe_table`")->fetch(PDO::FETCH_NUM);
        if ($create) {
            $sql .= $create[1] . ";
";
        }
    }
    
    // Datos de la tabla
    if ($backup_type === 'full' || $backup_type === 'data') {
        // Contar registros para estimación
        $count = $conn->query("SELECT COUNT(*) FROM `$safe_table`")->fetchColumn();
        if ($count > 0) {
            $sql .= "
--
-- Volcado de datos para la tabla `$safe_table` ($count registros)
--
";
            
            // Obtener columnas para construir consultas eficientes
            $columns_stmt = $conn->prepare("SHOW COLUMNS FROM `$safe_table`");
            $columns_stmt->execute();
            $columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
            $column_list = '`' . implode('`, `', $columns) . '`';
            
            // Exportar datos en lotes para manejar grandes volúmenes
            $batch_size = 500;
            $offset = 0;
            
            do {
                $rows = $conn->query("SELECT * FROM `$safe_table` LIMIT $offset, $batch_size")->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($rows) > 0) {
                    $sql .= "INSERT INTO `$safe_table` ($column_list) VALUES
";
                    $values_array = [];
                    
                    foreach ($rows as $row) {
                        $vals = [];
                        foreach ($row as $val) {
                            if (is_null($val)) {
                                $vals[] = 'NULL';
                            } elseif (is_numeric($val)) {
                                $vals[] = $val;
                            } else {
                                // Escape seguro para strings con manejo de caracteres especiales
                                $escaped = str_replace(
                                    ["\\", "\x00", "\n", "\r", "\x1a", "'", '"'],
                                    ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\'", '\"'],
                                    $val
                                );
                                $vals[] = "'" . $escaped . "'";
                            }
                        }
                        $values_array[] = '(' . implode(',', $vals) . ')';
                    }
                    
                    $sql .= implode(",
", $values_array) . ";
";
                }
                
                $offset += $batch_size;
            } while (count($rows) === $batch_size);
        }
    }
    $sql .= "
";
}

// Cierre del archivo SQL
$sql .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
";
$sql .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
";
$sql .= "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
";
$sql .= "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
";
$sql .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
";
$sql .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
";
$sql .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
";
$sql .= "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
";
$sql .= "
-- ============================================================================
-- Fin del respaldo
-- ============================================================================
";

// Registrar auditoría
$tables_count = count($tables_to_backup);
$estimated_size = round(strlen($sql) / 1048576, 2);
logAuditoria($conn, 'BACKUP_GENERADO_MEJORADO', 'configuracion', null, [
    'archivo' => $filename,
    'tablas_incluidas' => $tables_count,
    'tipo_backup' => $backup_type,
    'compresion' => $use_compression ? 'gzip' : 'none',
    'tamano_mb' => $estimated_size,
    'registros_totales' => array_sum(array_map(function($t) use ($conn) {
        try { return $conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } 
        catch(Exception $e) { return 0; }
    }, $tables_to_backup))
], $user['id']);

// Forzar salida del buffer y finalizar
if ($use_compression) {
    echo $sql;
    ob_end_flush();
} else {
    echo $sql;
}
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_BACKUP_MEJORADO', 'configuracion', null, ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], $user['id']);
$_SESSION['error'] = "<strong>❌ Error al generar respaldo:</strong> " . htmlspecialchars($e->getMessage());
header('Location: configuracion.php');
exit;
}
}
// ============================================================================
// 4. MANEJAR OPERACIONES SOBRE TABLAS DE LA BASE DE DATOS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_tabla'])) {
try {
$tabla = trim($_POST['tabla_nombre'] ?? '');
$accion = $_POST['accion_tabla'] ?? '';
if (empty($tabla) || !preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
throw new Exception('Nombre de tabla inválido.');
}
if ($accion === 'vaciar') {
$stmt = $conn->prepare("TRUNCATE TABLE `$tabla`");
$stmt->execute();
logAuditoria($conn, 'TABLA_VACIADA', 'configuracion', null, ['tabla' => $tabla], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Tabla <strong>" . htmlspecialchars($tabla) . "</strong> vaciada exitosamente. Contador reiniciado a 1.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
} elseif ($accion === 'eliminar') {
$stmt = $conn->prepare("DROP TABLE `$tabla`");
$stmt->execute();
logAuditoria($conn, 'TABLA_ELIMINADA', 'configuracion', null, ['tabla' => $tabla], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Tabla <strong>" . htmlspecialchars($tabla) . "</strong> eliminada exitosamente.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
} elseif ($accion === 'editar') {
$nuevo_nombre = trim($_POST['nuevo_nombre_tabla'] ?? '');
if (!empty($nuevo_nombre) && preg_match('/^[a-zA-Z0-9_]+$/', $nuevo_nombre)) {
$stmt = $conn->prepare("RENAME TABLE `$tabla` TO `$nuevo_nombre`");
$stmt->execute();
logAuditoria($conn, 'TABLA_EDITADA', 'configuracion', null, ['tabla_original' => $tabla, 'tabla_nueva' => $nuevo_nombre], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Tabla <strong>" . htmlspecialchars($tabla) . "</strong> renombrada a <strong>" . htmlspecialchars($nuevo_nombre) . "</strong> exitosamente.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
} else {
throw new Exception('Nombre de tabla nuevo inválido.');
}
} elseif ($accion === 'crear') {
$nuevo_nombre = trim($_POST['nuevo_nombre_tabla'] ?? '');
$campos = trim($_POST['campos_tabla'] ?? 'id INT PRIMARY KEY AUTO_INCREMENT');
if (!empty($nuevo_nombre) && preg_match('/^[a-zA-Z0-9_]+$/', $nuevo_nombre)) {
$stmt = $conn->prepare("CREATE TABLE `$nuevo_nombre` ($campos) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$stmt->execute();
logAuditoria($conn, 'TABLA_CREADA', 'configuracion', null, ['tabla_nueva' => $nuevo_nombre, 'campos' => $campos], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Tabla <strong>" . htmlspecialchars($nuevo_nombre) . "</strong> creada exitosamente.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
} else {
throw new Exception('Nombre de tabla o definición de campos inválida.');
}
}
header('Location: configuracion.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_TABLA', 'configuracion', null, ['error' => $e->getMessage(), 'tabla' => $_POST['tabla_nombre'] ?? 'N/A'], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: configuracion.php');
exit;
}
}
// ============================================================================
// 5. MANEJAR EDICIÓN DE REGISTROS DE TABLA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_registro'])) {
try {
$tabla = trim($_POST['tabla_nombre'] ?? '');
$accion = $_POST['accion_registro'] ?? '';
$registro_id = $_POST['registro_id'] ?? null;
$campos_datos = $_POST['campos_datos'] ?? [];
if (empty($tabla) || !preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
throw new Exception('Nombre de tabla inválido.');
}
if ($accion === 'actualizar' && $registro_id !== null) {
// Obtener columnas de la tabla para validar
$columns_stmt = $conn->prepare("SHOW COLUMNS FROM `$tabla`");
$columns_stmt->execute();
$columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
// Construir UPDATE dinámico con validación de columnas
$updates = [];
$params = [];
foreach ($campos_datos as $col => $val) {
if (in_array($col, $columns) && $col !== 'id') {
$updates[] = "`$col` = :$col";
$params[":$col"] = $val;
}
}
if (!empty($updates)) {
$params[':id'] = $registro_id;
$stmt = $conn->prepare("UPDATE `$tabla` SET " . implode(', ', $updates) . " WHERE id = :id");
$stmt->execute($params);
logAuditoria($conn, 'REGISTRO_ACTUALIZADO', 'configuracion', null, ['tabla' => $tabla, 'id_registro' => $registro_id], $user['id']);
$_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>Registro actualizado exitosamente en tabla <strong>" . htmlspecialchars($tabla) . "</strong>.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}
}
header('Location: configuracion.php');
exit;
} catch (Exception $e) {
logAuditoria($conn, 'ERROR_REGISTRO', 'configuracion', null, ['error' => $e->getMessage(), 'tabla' => $_POST['tabla_nombre'] ?? 'N/A'], $user['id']);
$_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
header('Location: configuracion.php');
exit;
}
}
// ============================================================================
// 6. CARGAR CONFIGURACIÓN ACTUAL E INFORMACIÓN DEL SISTEMA
// ============================================================================
$current_config = [
'app_name' => 'Sistema de Seguridad',
'maintenance_mode' => 0,
'session_timeout' => 30,
'max_upload_mb' => 10
];
try {
if ($conn->query("SHOW TABLES LIKE 'sistema_config'")->rowCount() > 0) {
$stmt = $conn->query("SELECT clave, valor FROM sistema_config");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
$current_config[$row['clave']] = $row['valor'];
}
}
} catch (Exception $e) {}
// Información del sistema
$db_info = $conn->query("SELECT VERSION() as version")->fetch(PDO::FETCH_ASSOC);
$db_size = $conn->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch(PDO::FETCH_ASSOC)['size_mb'] ?? 0;
$php_version = PHP_VERSION;
$server_os = PHP_OS;
$disk_free = round(disk_free_space('/') / 1024 / 1024, 2);
$disk_total = round(disk_total_space('/') / 1024 / 1024, 2);
$disk_used = round($disk_total - $disk_free, 2);
$last_backup = 'Nunca';
// Generar token CSRF
if (empty($_SESSION['config_token'])) {
$_SESSION['config_token'] = bin2hex(random_bytes(32));
}
// Obtener lista de tablas de la base de datos
$db_tables = [];
try {
$tables_result = $conn->query("SHOW TABLES");
while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
$table_name = $row[0];
// Obtener información adicional de cada tabla
$table_info = $conn->query("SELECT
TABLE_ROWS as filas,
ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 2) as tamaño_kb,
ENGINE as motor,
TABLE_COLLATION as colacion
FROM information_schema.tables
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table_name'")->fetch(PDO::FETCH_ASSOC);
$db_tables[] = [
'nombre' => $table_name,
'filas' => $table_info['filas'] ?? 0,
'tamaño_kb' => $table_info['tamaño_kb'] ?? 0,
'motor' => $table_info['motor'] ?? 'N/A',
'colacion' => $table_info['colacion'] ?? 'N/A'
];
}
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configuración del Sistema</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<script src="../js/bootstrap.bundle.min.js" defer></script>
<script src="../js/sweetalert2.all.min.js" defer></script>
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
.form-label { font-weight: 600; font-size: 0.9rem; color: #495057; }
.form-control, .form-select { border-radius: 4px; border: 1px solid #ced4da; padding: 8px 12px; }
.form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15); }
.btn { border-radius: 4px; font-weight: 500; padding: 8px 16px; }
.btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
.modal-content { border-radius: 4px; border: none; }
.modal-header { background-color: #f8f9fa; border-bottom: 1px solid var(--card-border); border-radius: 4px 4px 0 0; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
.info-item { background: #f8f9fa; padding: 12px; border-radius: 4px; border: 1px solid #e9ecef; }
.info-item strong { display: block; font-size: 0.8rem; color: #6c757d; text-transform: uppercase; }
.info-item span { font-size: 1.1rem; font-weight: 500; color: #212529; }
.table-custom th { background-color: #f8f9fa; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; color: #6c757d; }
.table-custom td { vertical-align: middle; font-size: 0.95rem; }
.badge-custom { font-size: 0.75rem; padding: 0.35em 0.65em; }
.registro-editor { max-height: 400px; overflow-y: auto; }
.registro-field { margin-bottom: 12px; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6; }
.registro-field label { font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 4px; color: #495057; }
.registro-field code { font-size: 0.75rem; color: #6c757d; }
.table-list-container { max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; }
.table-check-item { padding: 8px 12px; border-bottom: 1px solid #f1f3f5; display: flex; align-items: center; gap: 8px; }
.table-check-item:last-child { border-bottom: none; }
.table-check-item input { margin: 0; }
.table-check-item label { margin: 0; font-size: 0.9rem; cursor: pointer; flex: 1; }
.table-check-item small { color: #6c757d; font-size: 0.75rem; }
</style>
</head>
<body>
<?php $page_title = 'Configuración del Sistema'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<!-- ESTADÍSTICAS DEL SISTEMA -->
<div class="stats-container">
<div class="stat-card">
<div class="stat-icon mb-2 text-info"><i class="fas fa-database fa-2x"></i></div>
<div class="stat-number"><?php echo $db_size; ?> MB</div>
<div class="stat-label">Tamaño BD</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-primary"><i class="fas fa-code fa-2x"></i></div>
<div class="stat-number">PHP <?php echo $php_version; ?></div>
<div class="stat-label">Versión PHP</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-success"><i class="fas fa-hdd fa-2x"></i></div>
<div class="stat-number"><?php echo $disk_free; ?> MB</div>
<div class="stat-label">Espacio Libre</div>
</div>
<div class="stat-card">
<div class="stat-icon mb-2 text-warning"><i class="fas fa-clock fa-2x"></i></div>
<div class="stat-number"><?php echo $current_config['session_timeout']; ?> min</div>
<div class="stat-label">Timeout Sesión</div>
</div>
</div>
<!-- INFORMACIÓN DEL SISTEMA -->
<div class="section-box">
<div class="section-title"><i class="fas fa-server me-2"></i>Información del Sistema</div>
<div class="info-grid">
<div class="info-item"><strong>Sistema Operativo</strong><span><?php echo $server_os; ?></span></div>
<div class="info-item"><strong>Versión MySQL/MariaDB</strong><span><?php echo $db_info['version'] ?? 'Desconocida'; ?></span></div>
<div class="info-item"><strong>Disco Total</strong><span><?php echo $disk_total; ?> MB</span></div>
<div class="info-item"><strong>Disco Usado</strong><span><?php echo $disk_used; ?> MB (<?php echo round(($disk_used/$disk_total)*100,1); ?>%)</span></div>
<div class="info-item"><strong>Nombre de la App</strong><span><?php echo htmlspecialchars($current_config['app_name']); ?></span></div>
<div class="info-item"><strong>Último Respaldo</strong><span><?php echo $last_backup; ?></span></div>
</div>
</div>
<!-- CONFIGURACIÓN GENERAL -->
<div class="section-box">
<div class="section-title"><i class="fas fa-cogs me-2"></i>Configuración General del Sistema</div>
<form method="POST" action="">
<input type="hidden" name="guardar_configuracion" value="1">
<input type="hidden" name="csrf_token" value="<?php echo hash_hmac('sha256', 'config', $_SESSION['config_token']); ?>">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Nombre de la Aplicación</label>
<input type="text" name="app_name" class="form-control" value="<?php echo htmlspecialchars($current_config['app_name']); ?>" required>
</div>
<div class="col-md-3">
<label class="form-label">Tiempo de Inactividad (min)</label>
<input type="number" name="session_timeout" class="form-control" min="10" max="120" value="<?php echo $current_config['session_timeout']; ?>">
</div>
<div class="col-md-3">
<label class="form-label">Máx. Subida de Archivos (MB)</label>
<input type="number" name="max_upload_mb" class="form-control" min="2" max="100" value="<?php echo $current_config['max_upload_mb']; ?>">
</div>
<div class="col-12">
<div class="form-check form-switch p-2 bg-light rounded">
<input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance_mode" <?php echo $current_config['maintenance_mode'] ? 'checked' : ''; ?>>
<label class="form-check-label fw-bold" for="maintenance_mode">Activar Modo Mantenimiento</label>
<small class="d-block text-muted">Los usuarios no administradores verán una pantalla de mantenimiento mientras esté activo.</small>
</div>
</div>
<div class="col-12 text-end">
<button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Guardar Configuración</button>
</div>
</div>
</form>
</div>
<!-- RESPALDO DE BASE DE DATOS MEJORADO -->
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<span><i class="fas fa-download me-2"></i>Respaldo Completo (SQL)</span>
<span class="badge bg-info text-dark"><i class="fas fa-shield-alt me-1"></i>Seguro & Personalizable</span>
</div>
<div class="alert alert-light border">
<i class="fas fa-info-circle text-primary me-2"></i>
Genere respaldos SQL con opciones avanzadas: <strong>tipo de contenido</strong> (estructura, datos o completo), <strong>selección de tablas</strong>, <strong>compresión gzip</strong> y <strong>exclusión de tablas del sistema</strong>. Compatible con phpMyAdmin, MySQL Workbench y línea de comandos.
</div>
<div class="d-flex flex-wrap gap-2 align-items-center">
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirmBackupModal">
<i class="fas fa-file-export me-2"></i>Configurar y Descargar Respaldo
</button>
<span class="text-muted small ms-2">Tamaño estimado: ~<?php echo round($db_size * 1.3, 2); ?> MB (sin comprimir)</span>
</div>
</div>
</div>
</div>
<!-- MODAL CONFIRMACIÓN RESPALDO MEJORADO -->
<div class="modal fade" id="confirmBackupModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg">
<div class="modal-content">
<div class="modal-header bg-warning text-white">
<h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Configurar Respaldo de Base de Datos</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form method="POST" action="" id="backupForm" enctype="multipart/form-data">
<input type="hidden" name="generar_backup_db" value="1">
<input type="hidden" name="csrf_token" value="<?php echo hash_hmac('sha256', 'config', $_SESSION['config_token']); ?>">

<!-- Tipo de respaldo -->
<div class="mb-3">
<label class="form-label fw-bold"><i class="fas fa-layer-group me-2"></i>Tipo de Respaldo</label>
<div class="d-flex flex-wrap gap-3">
<div class="form-check">
<input class="form-check-input" type="radio" name="backup_type" id="backup_full" value="full" checked>
<label class="form-check-label" for="backup_full">Completo (Estructura + Datos)</label>
</div>
<div class="form-check">
<input class="form-check-input" type="radio" name="backup_type" id="backup_structure" value="structure">
<label class="form-check-label" for="backup_structure">Solo Estructura</label>
</div>
<div class="form-check">
<input class="form-check-input" type="radio" name="backup_type" id="backup_data" value="data">
<label class="form-check-label" for="backup_data">Solo Datos</label>
</div>
</div>
</div>

<!-- Opciones de compresión y exclusión -->
<div class="mb-3">
<label class="form-label fw-bold"><i class="fas fa-sliders-h me-2"></i>Opciones Avanzadas</label>
<div class="d-flex flex-wrap gap-3">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="use_compression" id="use_compression" value="1" checked>
<label class="form-check-label" for="use_compression">Comprimir con GZIP</label>
<small class="d-block text-muted">Reduce el tamaño del archivo ~70-90%</small>
</div>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="exclude_system_tables" id="exclude_system_tables" value="1" checked>
<label class="form-check-label" for="exclude_system_tables">Excluir tablas del sistema</label>
<small class="d-block text-muted">Omite sesiones, logs y tablas temporales</small>
</div>
</div>
</div>

<!-- Selección de tablas -->
<div class="mb-3">
<label class="form-label fw-bold"><i class="fas fa-table me-2"></i>Seleccionar Tablas</label>
<small class="text-muted d-block mb-2">Deje sin marcar para incluir todas las tablas no excluidas</small>
<div class="table-list-container">
<?php foreach ($db_tables as $tbl): ?>
<div class="table-check-item">
<input class="form-check-input" type="checkbox" name="selected_tables[]" id="tbl_<?php echo htmlspecialchars($tbl['nombre']); ?>" value="<?php echo htmlspecialchars($tbl['nombre']); ?>" checked>
<label for="tbl_<?php echo htmlspecialchars($tbl['nombre']); ?>"><?php echo htmlspecialchars($tbl['nombre']); ?></label>
<small><?php echo number_format($tbl['filas']); ?> filas • <?php echo $tbl['tamaño_kb']; ?> KB</small>
</div>
<?php endforeach; ?>
</div>
<div class="d-flex gap-2 mt-2">
<button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllTables(true)">Seleccionar todas</button>
<button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllTables(false)">Deseleccionar todas</button>
</div>
</div>

<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>Precaución:</strong> Los respaldos de bases de datos grandes pueden tardar varios minutos. Asegúrese de tener espacio suficiente en disco y una conexión estable.
</div>

<div class="d-flex justify-content-end gap-2 mt-3">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-warning" id="btnStartBackup">
<i class="fas fa-download me-1"></i>Generar y Descargar Respaldo
</button>
</div>
</form>
</div>
</div>
</div>
</div>
<!-- MODAL CREAR TABLA -->
<div class="modal fade" id="modalCrearTabla" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg">
<div class="modal-content">
<div class="modal-header bg-success text-white">
<h5 class="modal-title"><i class="fas fa-plus me-2"></i>Crear Nueva Tabla</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="accion_tabla" value="crear">
<div class="mb-3">
<label class="form-label fw-bold">Nombre de la Nueva Tabla</label>
<input type="text" class="form-control" name="nuevo_nombre_tabla" placeholder="Ej: nuevos_registros" pattern="[a-zA-Z0-9_]+" required>
<small class="text-muted">Solo letras, números y guiones bajos. Sin espacios.</small>
</div>
<div class="mb-3">
<label class="form-label fw-bold">Definición de Campos (SQL)</label>
<textarea class="form-control" name="campos_tabla" rows="6" placeholder="id INT PRIMARY KEY AUTO_INCREMENT,
nombre VARCHAR(100) NOT NULL,
email VARCHAR(150) UNIQUE,
fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP">id INT PRIMARY KEY AUTO_INCREMENT,
nombre VARCHAR(100) NOT NULL,
email VARCHAR(150) UNIQUE,
fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP</textarea>
<small class="text-muted">Defina los campos en formato SQL separado por comas. Ejemplo: <code>campo TIPO [OPCIONES]</code></small>
</div>
<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>Precaución:</strong> Asegúrese de definir correctamente la estructura. Errores en la sintaxis SQL pueden causar fallos.
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-success">Crear Tabla</button>
</div>
</form>
</div>
</div>
</div>
<!-- MODAL EDITAR TABLA -->
<div class="modal fade" id="modalEditarTabla" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header bg-info text-white">
<h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar/Renombrar Tabla</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="accion_tabla" value="editar">
<input type="hidden" name="tabla_nombre" id="editar_tabla_nombre">
<div class="mb-3">
<label class="form-label fw-bold">Nombre Actual</label>
<input type="text" class="form-control" id="editar_tabla_actual_display" disabled>
</div>
<div class="mb-3">
<label class="form-label fw-bold">Nuevo Nombre de la Tabla</label>
<input type="text" class="form-control" name="nuevo_nombre_tabla" id="editar_nuevo_nombre" placeholder="Ej: nuevos_registros" pattern="[a-zA-Z0-9_]+" required>
<small class="text-muted">Solo letras, números y guiones bajos. Sin espacios.</small>
</div>
<div class="alert alert-warning">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>Advertencia:</strong> Renombrar una tabla puede afectar consultas, vistas o procedimientos almacenados que la referencien. Verifique las dependencias antes de continuar.
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-info">Renombrar Tabla</button>
</div>
</form>
</div>
</div>
</div>
<!-- MODAL VACIAR TABLA -->
<div class="modal fade" id="modalVaciar" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header bg-warning text-white">
<h5 class="modal-title"><i class="fas fa-trash-restore me-2"></i>Vaciar Tabla</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="accion_tabla" value="vaciar">
<input type="hidden" name="tabla_nombre" id="vaciar_tabla_nombre">
<p>¿Está seguro de que desea <strong>vaciar completamente</strong> la tabla <strong id="vaciar_tabla_actual"></strong>?</p>
<div class="alert alert-danger">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>Advertencia:</strong> Esta acción eliminará TODOS los registros de la tabla y reiniciará el contador AUTO_INCREMENT a 1. Esta operación NO se puede deshacer.
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-danger">Sí, Vaciar Tabla</button>
</div>
</form>
</div>
</div>
</div>
<!-- MODAL ELIMINAR TABLA -->
<div class="modal fade" id="modalEliminar" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header bg-danger text-white">
<h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Eliminar Tabla</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="">
<div class="modal-body">
<input type="hidden" name="accion_tabla" value="eliminar">
<input type="hidden" name="tabla_nombre" id="eliminar_tabla_nombre">
<p>¿Está ABSOLUTAMENTE seguro de que desea <strong>ELIMINAR PERMANENTEMENTE</strong> la tabla <strong id="eliminar_tabla_actual"></strong>?</p>
<div class="alert alert-danger">
<i class="fas fa-exclamation-triangle me-2"></i>
<strong>PELIGRO:</strong> Esta acción eliminará la tabla completa, incluyendo su estructura y TODOS sus datos. Esta operación ES IRREVERSIBLE y puede romper funcionalidades del sistema.
</div>
<div class="mb-3">
<label class="form-label fw-bold">Para confirmar, escriba el nombre exacto de la tabla:</label>
<input type="text" class="form-control" id="confirmar_eliminar" placeholder="Ej: usuarios" autocomplete="off">
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="submit" class="btn btn-danger" id="btnConfirmarEliminar" disabled>Sí, Eliminar Permanentemente</button>
</div>
</form>
</div>
</div>
</div>
<!-- MODAL EDITAR REGISTROS -->
<div class="modal fade" id="modalEditarRegistros" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-xl">
<div class="modal-content">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title"><i class="fas fa-list me-2"></i>Editar Registros - <span id="editar_registros_tabla"></span></h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="tabla_nombre" id="editar_registros_tabla_input">
<div class="alert alert-info">
<i class="fas fa-info-circle me-2"></i>
Seleccione un registro para editar sus campos. Los cambios se guardarán inmediatamente.
</div>
<div class="table-responsive mb-3" style="max-height: 200px; overflow-y: auto;">
<table class="table table-sm table-hover table-custom" id="tabla_registros_lista">
<thead>
<tr>
<th>ID</th>
<th class="w-100">Vista Previa</th>
<th class="text-center">Acción</th>
</tr>
</thead>
<tbody id="registros_tbody">
<tr><td colspan="3" class="text-center text-muted">Cargando registros...</td></tr>
</tbody>
</table>
</div>
<hr>
<div id="editor_registro_container" style="display: none;">
<h6 class="fw-bold mb-3"><i class="fas fa-pen me-2"></i>Editar Registro #<span id="registro_id_display"></span></h6>
<form method="POST" action="" id="form_editar_registro">
<input type="hidden" name="accion_registro" value="actualizar">
<input type="hidden" name="tabla_nombre" id="form_tabla_nombre">
<input type="hidden" name="registro_id" id="form_registro_id">
<input type="hidden" name="csrf_token" value="<?php echo hash_hmac('sha256', 'config', $_SESSION['config_token']); ?>">
<div class="registro-editor" id="campos_registro_editor">
<!-- Los campos se generarán dinámicamente -->
</div>
<div class="d-flex justify-content-end gap-2 mt-3">
<button type="button" class="btn btn-secondary" onclick="cancelarEdicionRegistro()">Cancelar</button>
<button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Guardar Cambios</button>
</div>
</form>
</div>
</div>
</div>
</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Auto-cerrar alertas
document.querySelectorAll('.alert').forEach(alert => {
setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
// Confirmación antes de generar backup
const backupForm = document.getElementById('backupForm');
const btnStart = document.getElementById('btnStartBackup');
if (backupForm && btnStart) {
backupForm.addEventListener('submit', function(e) {
e.preventDefault();
const selectedTables = document.querySelectorAll('input[name="selected_tables[]"]:checked');
const backupType = document.querySelector('input[name="backup_type"]:checked').value;
const compression = document.getElementById('use_compression').checked;
let message = `<div class="text-start">
<p><strong>Tipo:</strong> ${backupType === 'full' ? 'Completo' : (backupType === 'structure' ? 'Solo Estructura' : 'Solo Datos')}</p>
<p><strong>Compresión:</strong> ${compression ? 'GZIP activada' : 'Sin compresión'}</p>
<p><strong>Tablas:</strong> ${selectedTables.length} seleccionadas</p>
</div>`;
Swal.fire({
title: 'Generando respaldo...',
html: message + '<p class="mt-3"><i class="fas fa-spinner fa-spin me-2"></i>Procesando tablas. Por favor espere...</p>',
allowOutsideClick: false,
allowEscapeKey: false,
didOpen: () => Swal.showLoading(),
timer: 600000
});
backupForm.submit();
});
}
// Validación para botón de eliminar tabla
const inputConfirmar = document.getElementById('confirmar_eliminar');
const btnEliminar = document.getElementById('btnConfirmarEliminar');
if (inputConfirmar && btnEliminar) {
inputConfirmar.addEventListener('input', function() {
const tablaActual = document.getElementById('eliminar_tabla_actual')?.textContent || '';
btnEliminar.disabled = this.value.trim() !== tablaActual.trim();
});
}
// Sidebar toggle (mismo comportamiento que empresas.php)
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
// Funciones para selección de tablas en backup
function selectAllTables(select) {
document.querySelectorAll('input[name="selected_tables[]"]').forEach(cb => {
cb.checked = select;
});
}
// Funciones para preparar modales de gestión de tablas
function prepararVaciar(nombreTabla) {
document.getElementById('vaciar_tabla_nombre').value = nombreTabla;
document.getElementById('vaciar_tabla_actual').textContent = nombreTabla;
}
function prepararEliminar(nombreTabla) {
document.getElementById('eliminar_tabla_nombre').value = nombreTabla;
document.getElementById('eliminar_tabla_actual').textContent = nombreTabla;
document.getElementById('confirmar_eliminar').value = '';
document.getElementById('btnConfirmarEliminar').disabled = true;
}
function prepararEditar(nombreTabla) {
document.getElementById('editar_tabla_nombre').value = nombreTabla;
document.getElementById('editar_tabla_actual_display').value = nombreTabla;
document.getElementById('editar_nuevo_nombre').value = nombreTabla;
}
function prepararEditarRegistros(nombreTabla) {
document.getElementById('editar_registros_tabla').textContent = nombreTabla;
document.getElementById('editar_registros_tabla_input').value = nombreTabla;
document.getElementById('form_tabla_nombre').value = nombreTabla;
document.getElementById('editor_registro_container').style.display = 'none';
cargarRegistrosTabla(nombreTabla);
}
function cancelarEdicionRegistro() {
document.getElementById('editor_registro_container').style.display = 'none';
}
function cargarRegistrosTabla(nombreTabla) {
const tbody = document.getElementById('registros_tbody');
tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Cargando...</td></tr>';
fetch('../api/obtener_registros.php', {
method: 'POST',
headers: {'Content-Type': 'application/json'},
body: JSON.stringify({tabla: nombreTabla, token: '<?php echo $_SESSION['config_token']; ?>'})
})
.then(response => {
if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
return response.json();
})
.then(data => {
if (data.success && data.registros.length > 0) {
tbody.innerHTML = '';
data.registros.slice(0, 50).forEach(reg => {
const preview = Object.entries(reg).slice(1, 4).map(([k,v]) => `<small><strong>${k}:</strong> ${String(v).substring(0,30)}</small>`).join(' | ');
tbody.innerHTML += `<tr>
<td><code>${reg.id}</code></td>
<td class="text-muted small">${preview}${Object.keys(reg).length > 4 ? '...' : ''}</td>
<td class="text-center">
<button class="btn btn-sm btn-outline-primary" onclick="cargarRegistroParaEditar('${nombreTabla}', ${reg.id})">
<i class="fas fa-edit"></i> Editar
</button>
</td>
</tr>`;
});
if (data.registros.length > 50) {
tbody.innerHTML += `<tr><td colspan="3" class="text-center text-muted small">Mostrando primeros 50 de ${data.registros.length} registros</td></tr>`;
}
} else if (data.success) {
tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No hay registros para editar.</td></tr>';
} else {
tbody.innerHTML = `<tr><td colspan="3" class="text-center text-danger">${data.error || 'Error al cargar registros'}</td></tr>`;
}
})
.catch(err => {
tbody.innerHTML = `<tr><td colspan="3" class="text-center text-danger"><i class="fas fa-exclamation-triangle me-2"></i>${err.message || 'Error de conexión'}</td></tr>`;
console.error('Error cargando registros:', err);
});
}
function cargarRegistroParaEditar(nombreTabla, registroId) {
fetch('../api/obtener_registro.php', {
method: 'POST',
headers: {'Content-Type': 'application/json'},
body: JSON.stringify({tabla: nombreTabla, id: registroId, token: '<?php echo $_SESSION['config_token']; ?>'})
})
.then(response => {
if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
return response.json();
})
.then(data => {
if (data.success) {
document.getElementById('registro_id_display').textContent = registroId;
document.getElementById('form_registro_id').value = registroId;
const editor = document.getElementById('campos_registro_editor');
editor.innerHTML = '';
Object.entries(data.registro).forEach(([campo, valor]) => {
if (campo === 'id') return;
const tipo = data.tipos[campo] || 'text';
const esTextarea = tipo.includes('text') && tipo !== 'tinytext';
editor.innerHTML += `
<div class="registro-field">
<label>${campo} <code class="ms-1">${tipo}</code></label>
${esTextarea
? `<textarea class="form-control form-control-sm" name="campos_datos[${campo}]" rows="3">${htmlspecialchars(valor)}</textarea>`
: `<input type="${tipo.includes('int')?'number':(tipo.includes('date')?'date':'text')}" class="form-control form-control-sm" name="campos_datos[${campo}]" value="${htmlspecialchars(valor)}">`}
</div>`;
});
document.getElementById('editor_registro_container').style.display = 'block';
editor.scrollTop = 0;
} else {
Swal.fire('Error', data.error || 'No se pudo cargar el registro', 'error');
}
})
.catch(err => {
Swal.fire('Error', `${err.message || 'Error de conexión'}`, 'error');
console.error('Error cargando registro:', err);
});
}
function htmlspecialchars(str) {
const div = document.createElement('div');
div.textContent = str ?? '';
return div.innerHTML;
}
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
</script>
</body>
</html>