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
// FUNCIÓN PARA OBTENER ICONO SEGÚN TIPO DE ALERTA
// ============================================================================
function obtenerIconoAlerta($tipo) {
    $iconos = [
        'empresas_inactivas' => 'building', 'empresas_sin_responsable' => 'user-shield', 'empresas_cuit_duplicado' => 'exclamation-triangle', 'empresas_sin_documentacion' => 'file-contract',
        'sucursales_pendientes_aprobacion' => 'clock', 'aranceles_vencidos' => 'money-bill-wave', 'sucursales_rechazadas' => 'times-circle', 'sucursales_documentacion_incompleta' => 'file-contract', 'sucursales_sin_pdf_resolucion' => 'file-pdf',
        'doc_vencida' => 'user-times', 'doc_por_vencer' => 'user-clock', 'personal_inactivo_sin_baja' => 'user-slash', 'documentacion_pendiente_revision' => 'file-signature', 'revalidaciones_vencidas' => 'sync-alt', 'revalidaciones_por_vencer' => 'hourglass-half', 'credenciales_sin_pagar' => 'credit-card', 'personal_sin_foto' => 'image', 'personal_sin_pdf_datos' => 'file-pdf', 'personal_sin_cupon_pago' => 'receipt', 'personal_sin_certificado' => 'certificate',
        'recursos_pendientes_aprobacion' => 'clipboard-list', 'recursos_rechazados' => 'times-circle', 'recursos_items_vencidos' => 'box-open', 'recursos_sin_pdf' => 'file-pdf', 'recursos_por_vencer' => 'hourglass-half',
        'servicios_pendientes_aprobacion' => 'clock', 'servicios_con_sanciones' => 'gavel', 'servicios_sin_pdf' => 'file-pdf', 'servicios_vencidos' => 'calendar-times', 'servicios_sin_personal_asignado' => 'user-slash',
        'inspecciones_pendientes' => 'clipboard-list', 'inspecciones_con_observaciones' => 'exclamation-circle', 'inspecciones_con_sanciones' => 'gavel', 'inspecciones_sin_sumario' => 'link-slash', 'inspecciones_por_vencer' => 'hourglass-half',
        'inspecciones_programadas_hoy' => 'calendar-day', 'inspecciones_programadas_vencidas' => 'calendar-times', 'inspecciones_programadas_pendientes' => 'calendar-check',
        'documentos_pendientes_revision' => 'file-signature', 'documentos_rechazados' => 'times-circle', 'documentos_sin_observaciones' => 'file-alt',
        'informe_pendiente' => 'file-alt', 'multas_pendientes' => 'file-invoice-dollar'
    ];
    return $iconos[$tipo] ?? 'exclamation-circle';
}

function obtenerColorPrioridad($prioridad) {
    return match($prioridad) {
        'alta' => '#e74c3c',
        'media' => '#f39c12',
        'baja' => '#3498db',
        default => '#3498db'
    };
}

function obtenerModuloAlerta($tipo) {
    if (in_array($tipo, ['empresas_inactivas', 'empresas_sin_responsable', 'empresas_cuit_duplicado', 'empresas_sin_documentacion'])) return 'empresas';
    if (in_array($tipo, ['sucursales_pendientes_aprobacion', 'aranceles_vencidos', 'sucursales_rechazadas', 'sucursales_documentacion_incompleta', 'sucursales_sin_pdf_resolucion'])) return 'sucursales';
    if (in_array($tipo, ['doc_vencida', 'doc_por_vencer', 'personal_inactivo_sin_baja', 'documentacion_pendiente_revision', 'revalidaciones_vencidas', 'revalidaciones_por_vencer', 'credenciales_sin_pagar', 'personal_sin_foto', 'personal_sin_pdf_datos', 'personal_sin_cupon_pago', 'personal_sin_certificado'])) return 'personal';
    if (in_array($tipo, ['recursos_pendientes_aprobacion', 'recursos_rechazados', 'recursos_items_vencidos', 'recursos_sin_pdf', 'recursos_por_vencer'])) return 'recursos';
    if (in_array($tipo, ['servicios_pendientes_aprobacion', 'servicios_con_sanciones', 'servicios_sin_pdf', 'servicios_vencidos', 'servicios_sin_personal_asignado'])) return 'servicios';
    if (in_array($tipo, ['inspecciones_pendientes', 'inspecciones_con_observaciones', 'inspecciones_con_sanciones', 'inspecciones_sin_sumario', 'inspecciones_por_vencer'])) return 'inspecciones';
    if (in_array($tipo, ['inspecciones_programadas_hoy', 'inspecciones_programadas_vencidas', 'inspecciones_programadas_pendientes'])) return 'inspecciones_programadas';
    if (in_array($tipo, ['documentos_pendientes_revision', 'documentos_rechazados', 'documentos_sin_observaciones'])) return 'documentos';
    return 'otros';
}

function columnaExiste($conn, $tabla, $columna) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tabla, $columna]);
        return $stmt->fetch()['total'] > 0;
    } catch (Exception $e) { return false; }
}

function tablaExiste($conn, $tabla) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tabla]);
        return $stmt->fetch()['total'] > 0;
    } catch (Exception $e) { return false; }
}

function obtenerAlertas($conn) {
    $alertas = [];
    $hoy = date('Y-m-d');
    $proximos_30_dias = date('Y-m-d', strtotime('+30 days'));

    // EMPRESAS
    try {
        if (columnaExiste($conn, 'empresas', 'activo')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE activo = FALSE");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'empresas_inactivas', 'prioridad' => 'alta', 'titulo' => 'Empresas Inactivas', 'descripcion' => "Hay {$total} empresa(s) inactivas", 'accion_url' => 'empresas.php?search_estado=inactivas'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Empresas: " . $e->getMessage()); }
    
    try {
        if (columnaExiste($conn, 'empresas', 'responsable_id')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM empresas WHERE responsable_id IS NULL AND activo = TRUE");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'empresas_sin_responsable', 'prioridad' => 'media', 'titulo' => 'Empresas Sin Responsable', 'descripcion' => "Hay {$total} empresa(s) sin responsable", 'accion_url' => 'empresas.php'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Empresas Resp: " . $e->getMessage()); }

    try {
        $stmt = $conn->query("SELECT COUNT(DISTINCT cuit) as total FROM empresas WHERE cuit IS NOT NULL AND cuit != '' GROUP BY cuit HAVING COUNT(*) > 1");
        $total = $stmt->rowCount();
        if ($total > 0) $alertas[] = ['tipo' => 'empresas_cuit_duplicado', 'prioridad' => 'alta', 'titulo' => 'CUIT Duplicado', 'descripcion' => "Hay {$total} CUIT(s) duplicados", 'accion_url' => 'empresas.php'];
    } catch (Exception $e) { error_log("Error Dashboard CUIT: " . $e->getMessage()); }

    // SUCURSALES
    try {
        if (columnaExiste($conn, 'sucursales', 'estado_aprobacion')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE (estado_aprobacion = 'pendiente' OR estado_aprobacion IS NULL) AND en_funcionamiento = 1");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'sucursales_pendientes_aprobacion', 'prioridad' => 'alta', 'titulo' => 'Sucursales Pendientes', 'descripcion' => "Hay {$total} sucursal(es) pendientes", 'accion_url' => 'sucursales.php?filtro_aprobacion=pendiente'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Sucursales: " . $e->getMessage()); }

    try {
        if (columnaExiste($conn, 'sucursales', 'fecha_pago_arancel')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE fecha_pago_arancel IS NOT NULL AND fecha_pago_arancel < DATE_SUB(NOW(), INTERVAL 380 DAY)");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'aranceles_vencidos', 'prioridad' => 'alta', 'titulo' => 'Aranceles Vencidos', 'descripcion' => "Hay {$total} sucursal(es) con arancel vencido", 'accion_url' => 'sucursales.php'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Aranceles: " . $e->getMessage()); }

    try {
        if (columnaExiste($conn, 'sucursales', 'estado_aprobacion')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM sucursales WHERE estado_aprobacion = 'rechazado'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'sucursales_rechazadas', 'prioridad' => 'alta', 'titulo' => 'Sucursales Rechazadas', 'descripcion' => "Hay {$total} sucursal(es) rechazadas", 'accion_url' => 'sucursales.php?filtro_aprobacion=rechazado'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Suc Rech: " . $e->getMessage()); }

    // PERSONAL
    try {
        if (columnaExiste($conn, 'personal', 'fecha_vencimiento')) {
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE activo = TRUE AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento <= ?");
            $stmt->execute([$hoy]);
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'doc_vencida', 'prioridad' => 'alta', 'titulo' => 'Documentación Vencida', 'descripcion' => "Hay {$total} registro(s) con documentación vencida", 'accion_url' => 'personal.php?filtro_vencimiento=vencido'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Doc Venc: " . $e->getMessage()); }

    try {
        if (columnaExiste($conn, 'personal', 'fecha_vencimiento')) {
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM personal WHERE activo = TRUE AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN ? AND ?");
            $stmt->execute([$hoy, $proximos_30_dias]);
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'doc_por_vencer', 'prioridad' => 'media', 'titulo' => 'Documentación por Vencer', 'descripcion' => "Hay {$total} registro(s) por vencer en 30 días", 'accion_url' => 'personal.php?filtro_vencimiento=proximo'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Doc Prox: " . $e->getMessage()); }

    try {
        if (columnaExiste($conn, 'personal', 'activo') && columnaExiste($conn, 'personal', 'baja')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM personal WHERE activo = FALSE AND baja = FALSE");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'personal_inactivo_sin_baja', 'prioridad' => 'alta', 'titulo' => 'Personal Inactivo Sin Baja', 'descripcion' => "Hay {$total} registro(s) sin baja formal", 'accion_url' => 'personal.php'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Pers Baja: " . $e->getMessage()); }

    // DOCUMENTOS EMPRESAS
    try {
        if (columnaExiste($conn, 'documentos_sucursales', 'estado')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM documentos_sucursales WHERE estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'documentos_pendientes_revision', 'prioridad' => 'alta', 'titulo' => 'Documentos Pendientes de Revisión', 'descripcion' => "Hay {$total} documento(s) pendiente(s) de aprobación", 'accion_url' => 'documentos_empresas.php?search_estado=pendiente'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Doc Emp: " . $e->getMessage()); }

    try {
        if (columnaExiste($conn, 'documentos_sucursales', 'estado')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM documentos_sucursales WHERE estado = 'rechazado' AND fecha_revision >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'documentos_rechazados', 'prioridad' => 'media', 'titulo' => 'Documentos Rechazados', 'descripcion' => "Hay {$total} documento(s) rechazado(s) en los últimos 7 días", 'accion_url' => 'documentos_empresas.php?search_estado=rechazado'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Doc Rech: " . $e->getMessage()); }

    try {
        if (columnaExiste($conn, 'documentos_sucursales', 'motivacion_rechazo')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM documentos_sucursales WHERE estado = 'rechazado' AND (motivacion_rechazo IS NULL OR motivacion_rechazo = '')");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'documentos_sin_observaciones', 'prioridad' => 'baja', 'titulo' => 'Documentos Sin Motivación', 'descripcion' => "Hay {$total} documento(s) rechazados sin motivación", 'accion_url' => 'documentos_empresas.php?search_estado=rechazado'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Doc Mot: " . $e->getMessage()); }

    // RECURSOS
    try {
        if (columnaExiste($conn, 'recursos_sucursal', 'estado')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM recursos_sucursal WHERE estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'recursos_pendientes_aprobacion', 'prioridad' => 'alta', 'titulo' => 'Recursos Pendientes', 'descripcion' => "Hay {$total} recurso(s) pendiente(s)", 'accion_url' => 'recursos.php?search_estado=pendiente'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Rec: " . $e->getMessage()); }

    // SERVICIOS
    try {
        if (columnaExiste($conn, 'servicios', 'estado')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM servicios WHERE estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'servicios_pendientes_aprobacion', 'prioridad' => 'alta', 'titulo' => 'Servicios Pendientes', 'descripcion' => "Hay {$total} servicio(s) pendiente(s)", 'accion_url' => 'servicios.php?search_estado=pendiente'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Serv: " . $e->getMessage()); }

    // INSPECCIONES
    try {
        if (columnaExiste($conn, 'inspecciones', 'estado')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones WHERE estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'inspecciones_pendientes', 'prioridad' => 'media', 'titulo' => 'Inspecciones Pendientes', 'descripcion' => "Hay {$total} inspección(es) pendiente(s)", 'accion_url' => 'inspecciones.php?search_estado=pendiente'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Insp: " . $e->getMessage()); }

    // INSPECCIONES PROGRAMADAS
    try {
        if (tablaExiste($conn, 'inspecciones_programadas')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada = CURDATE() AND estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'inspecciones_programadas_hoy', 'prioridad' => 'alta', 'titulo' => 'Inspecciones Programadas para Hoy', 'descripcion' => "Hay {$total} inspección(es) programada(s) para hoy", 'accion_url' => 'inspecciones_programadas.php?filtro_fecha_desde=' . $hoy . '&filtro_fecha_hasta=' . $hoy];
            
            $stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada < CURDATE() AND estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'inspecciones_programadas_vencidas', 'prioridad' => 'alta', 'titulo' => 'Inspecciones Programadas Vencidas', 'descripcion' => "Hay {$total} inspección(es) programada(s) vencida(s)", 'accion_url' => 'inspecciones_programadas.php?filtro_estado=vencida'];
            
            $stmt = $conn->query("SELECT COUNT(*) as total FROM inspecciones_programadas WHERE fecha_programada > CURDATE() AND estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'inspecciones_programadas_pendientes', 'prioridad' => 'media', 'titulo' => 'Inspecciones Programadas Próximas', 'descripcion' => "Hay {$total} inspección(es) programada(s) próximas", 'accion_url' => 'inspecciones_programadas.php?filtro_estado=pendiente'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Insp Prog: " . $e->getMessage()); }

    // INFORMES
    try {
        if (columnaExiste($conn, 'multas', 'estado')) {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM multas WHERE estado = 'pendiente'");
            $total = $stmt->fetch()['total'] ?? 0;
            if ($total > 0) $alertas[] = ['tipo' => 'multas_pendientes', 'prioridad' => 'media', 'titulo' => 'Multas Pendientes', 'descripcion' => "Hay {$total} multa(s) pendientes de pago", 'accion_url' => 'inspecciones.php'];
        }
    } catch (Exception $e) { error_log("Error Dashboard Multas: " . $e->getMessage()); }

    // ORDENAR POR PRIORIDAD
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
    'empresas' => 0, 'sucursales' => 0, 'personal' => 0, 'recursos' => 0, 
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
<button class="filter-btn" data-filter="empresas" onclick="filterAlertas('empresas')"><i class="fas fa-building"></i> Empresas <span class="badge-count"><?php echo $alertas_por_modulo['empresas']; ?></span></button>
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
<a href="configuracion.php" class="quick-action-card quick-action-6"><div class="icon"><i class="fas fa-cog"></i></div><div class="label">Configuración</div></a>
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
</script>
</body>
</html>
