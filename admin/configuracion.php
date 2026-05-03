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
            'max_upload_mb' => $max_upload
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
// 3. GENERAR Y DESCARGAR RESPALDO SQL COMPLETO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_backup_db'])) {
    try {
        set_time_limit(0);
        ini_set('memory_limit', '1G');
        if (ob_get_level()) ob_end_clean();

        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- Respaldo completo de la base de datos\n";
        $sql .= "-- Generado: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Usuario: " . ($user['nombre'] ?? 'N/A') . " " . ($user['apellido'] ?? '') . "\n";
        $sql .= "-- Base de datos: " . $conn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql .= $create[1] . ";\n\n";

            $rows = $conn->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $sql .= "INSERT INTO `$table` VALUES\n";
                $values_array = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        $vals[] = is_null($val) ? 'NULL' : $conn->quote($val);
                    }
                    $values_array[] = '(' . implode(',', $vals) . ')';
                }
                $sql .= implode(",\n", $values_array) . ";\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        $filename = 'backup_completo_' . date('Y-m-d_His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $sql;
        logAuditoria($conn, 'BACKUP_GENERADO', 'configuracion', null, ['archivo' => $filename, 'tablas_incluidas' => count($tables), 'tamano_mb' => round(strlen($sql)/1048576, 2)], $user['id']);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error al generar respaldo:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: configuracion.php');
        exit;
    }
}

// ============================================================================
// 4. CARGAR CONFIGURACIÓN ACTUAL E INFORMACIÓN DEL SISTEMA
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

        <!-- RESPALDO DE BASE DE DATOS -->
        <div class="section-box">
            <div class="section-title d-flex justify-content-between align-items-center">
                <span><i class="fas fa-download me-2"></i>Respaldo Completo (SQL)</span>
                <span class="badge bg-info text-dark"><i class="fas fa-shield-alt me-1"></i>Seguro & Completo</span>
            </div>
            <div class="alert alert-light border">
                <i class="fas fa-info-circle text-primary me-2"></i>
                Se generará un archivo <code>.sql</code> con la estructura completa y todos los datos de la base de datos actual. Este archivo es compatible con phpMyAdmin, MySQL Workbench y líneas de comando.
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirmBackupModal">
                    <i class="fas fa-file-export me-2"></i>Descargar Respaldo Completo
                </button>
                <span class="text-muted small ms-2">Tamaño estimado: ~<?php echo round($db_size * 1.5, 2); ?> MB comprimido</span>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CONFIRMACIÓN RESPALDO -->
<div class="modal fade" id="confirmBackupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Generación de Respaldo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Está a punto de generar un respaldo <strong>completo</strong> de la base de datos. Este proceso puede tardar unos segundos dependiendo del volumen de datos.</p>
                <ul class="small text-muted">
                    <li>✅ Se incluirán todas las tablas y relaciones</li>
                    <li>✅ Compatible con restauración manual</li>
                    <li>⚠️ Asegúrese de tener espacio suficiente en disco</li>
                </ul>
                <form method="POST" action="" id="backupForm">
                    <input type="hidden" name="generar_backup_db" value="1">
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning" id="btnStartBackup">
                            <i class="fas fa-download me-1"></i>Generar y Descargar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
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
            Swal.fire({
                title: 'Generando respaldo...',
                html: 'Por favor espere mientras se procesan todas las tablas.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
                timer: 300000 // Timeout de seguridad de 5 min
            });
            backupForm.submit();
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