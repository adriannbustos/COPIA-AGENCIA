<?php
/**
 * ============================================================================
 * CALENDARIO DE VENCIMIENTOS - VERSIÓN FINAL ACTUALIZADA
 * ============================================================================
 * - Día 02: Arancel Sucursales (TODAS las sucursales activas sin pago)
 * - Día 03: Credencial Personal (TODO el personal activo sin pago)
 * - Días 06, 11, 16, 21, 26: Informe Mensual (Empresas activas sin informe)
 * - Días 05, 10, 15, 20, 25: Trámites con fecha límite
 * @version 4.0 - Requerimientos Específicos Finales
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
if (!$auth->isLoggedIn() || (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('operador'))) {
header('Location: ../login.php');
exit;
}

$current_page = 'calendario_vencimientos';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ============================================================================
// 2. OBTENER FECHA ACTUAL Y PARÁMETROS
// ============================================================================
$dia_actual = (int)date('d');
$mes_actual = (int)date('m');
$anio_actual = (int)date('Y');

$mes_navegacion = isset($_GET['mes']) && is_numeric($_GET['mes']) && $_GET['mes'] >= 1 && $_GET['mes'] <= 12 
    ? (int)$_GET['mes'] : $mes_actual;
$anio_navegacion = isset($_GET['anio']) && is_numeric($_GET['anio']) && $_GET['anio'] >= 2020 && $_GET['anio'] <= 2030 
    ? (int)$_GET['anio'] : $anio_actual;

// ============================================================================
// 3. FUNCIÓN PARA OBTENER DATOS POR FECHA ESPECÍFICA
// ============================================================================
function obtenerDatosPorFecha($conn, $dia, $mes, $anio) {
    $datos = [
        'informes' => [],
        'aranceles' => [],
        'credenciales' => [],
        'tramites' => [],
        'vencidos' => [],
        'proximos' => []
    ];
    
    try {
        // 📄 INFORMES MENSUALES (Días 06, 11, 16, 21, 26)
        $dias_informe = [6, 11, 16, 21, 26];
        if (in_array($dia, $dias_informe)) {
            $sql = "
            SELECT 
                e.id, 
                e.nombre, 
                e.cuit, 
                e.email,
                e.activo,
                MAX(d.fecha_carga) as ultima_carga,
                DATEDIFF(CURDATE(), MAX(d.fecha_carga)) as dias_sin_informe,
                COUNT(d.id) as total_documentos_mes
            FROM empresas e
            LEFT JOIN documentos_sucursales d ON e.id = d.empresa_id 
                AND d.fecha_carga >= DATE_SUB(CURDATE(), INTERVAL 35 DAY)
            WHERE e.activo = TRUE
            GROUP BY e.id
            HAVING ultima_carga IS NULL 
                OR ultima_carga < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                OR total_documentos_mes = 0
            ORDER BY dias_sin_informe DESC
            ";
            $stmt = $conn->query($sql);
            $datos['informes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 💰 ARANCELES DE SUCURSALES (Día 02) - TODAS LAS SUCURSALES ACTIVAS SIN PAGO
        if ($dia == 2) {
            $sql = "
            SELECT 
                s.id as sucursal_id,
                s.nombre as sucursal_nombre,
                s.domicilio,
                s.localidad,
                s.jurisdiccion,
                s.pago_arancel,
                s.fecha_pago_arancel,
                s.activa,
                e.id as empresa_id,
                e.nombre as empresa_nombre,
                e.cuit,
                e.email
            FROM sucursales s
            INNER JOIN empresas e ON s.empresa_id = e.id
            WHERE e.activo = TRUE 
                AND s.activa = TRUE
                AND (s.pago_arancel = 0 OR s.pago_arancel IS NULL)
            ORDER BY e.nombre, s.nombre
            ";
            $stmt = $conn->query($sql);
            $datos['aranceles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 🆔 CREDENCIALES DE PERSONAL (Día 03) - TODO EL PERSONAL ACTIVO SIN PAGO
        if ($dia == 3) {
            $sql = "
            SELECT 
                p.id as personal_id,
                CONCAT(p.apellido, ', ', p.nombre) as nombre_completo,
                p.dni,
                p.cargo,
                p.pago_credencial,
                p.activo as personal_activo,
                p.fecha_vencimiento,
                e.id as empresa_id,
                e.nombre as empresa_nombre,
                e.cuit,
                s.id as sucursal_id,
                s.nombre as sucursal_nombre
            FROM personal p
            INNER JOIN empresas e ON p.empresa_id = e.id
            LEFT JOIN sucursales s ON p.sucursal_id = s.id
            WHERE e.activo = TRUE 
                AND p.activo = TRUE
                AND (p.pago_credencial = 0 OR p.pago_credencial IS NULL)
            ORDER BY e.nombre, p.apellido, p.nombre
            ";
            $stmt = $conn->query($sql);
            $datos['credenciales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // ⏰ TRÁMITES (Días 05, 10, 15, 20, 25)
        $dias_limite = [5, 10, 15, 20, 25];
        if (in_array($dia, $dias_limite)) {
            $sql = "
            SELECT 
                t.id, 
                e.nombre as empresa_nombre, 
                e.cuit, 
                e.email,
                t.tipo_movimiento, 
                t.estado, 
                t.fecha_limite,
                t.plazo_dias, 
                t.urgente, 
                t.cantidad_notificaciones,
                DATEDIFF(t.fecha_limite, CURDATE()) as dias_restantes
            FROM tramites_empresa t
            INNER JOIN empresas e ON t.empresa_id = e.id
            WHERE e.activo = TRUE 
                AND t.estado IN ('en_tramite', 'fuera_tiempo')
                AND t.fecha_limite IS NOT NULL
            ORDER BY t.fecha_limite ASC
            ";
            $stmt = $conn->query($sql);
            $tramites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($tramites as $tramite) {
                if ($tramite['dias_restantes'] < 0) {
                    $datos['vencidos'][] = $tramite;
                } elseif ($tramite['dias_restantes'] <= 5) {
                    $datos['proximos'][] = $tramite;
                } else {
                    $datos['tramites'][] = $tramite;
                }
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error obteniendo datos por fecha: " . $e->getMessage());
    }
    
    return $datos;
}

// ============================================================================
// 4. OBTENER DATOS PARA MODAL (AJAX)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'obtener_detalle_fecha') {
    header('Content-Type: application/json');
    
    $dia = isset($_GET['dia']) ? (int)$_GET['dia'] : 0;
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
    $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
    
    if ($dia < 1 || $dia > 31 || $mes < 1 || $mes > 12) {
        echo json_encode(['success' => false, 'message' => 'Fecha inválida']);
        exit;
    }
    
    $datos = obtenerDatosPorFecha($conn, $dia, $mes, $anio);
    
    echo json_encode([
        'success' => true,
        'fecha' => sprintf('%02d/%02d/%04d', $dia, $mes, $anio),
        'datos' => $datos,
        'totales' => [
            'informes' => count($datos['informes']),
            'aranceles' => count($datos['aranceles']),
            'credenciales' => count($datos['credenciales']),
            'tramites' => count($datos['tramites']),
            'vencidos' => count($datos['vencidos']),
            'proximos' => count($datos['proximos'])
        ]
    ]);
    exit;
}

// ============================================================================
// 5. DATOS AUXILIARES
// ============================================================================
$nombres_meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// ============================================================================
// 6. OBTENER ESTADÍSTICAS GENERALES
// ============================================================================
$stats = [
    'informes_pendientes' => 0,
    'aranceles_pendientes' => 0,
    'credenciales_pendientes' => 0,
    'tramites_vencidos' => 0
];

try {
    // ✅ EMPRESAS ACTIVAS SIN INFORME
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT e.id) as total 
        FROM empresas e
        LEFT JOIN documentos_sucursales d ON e.id = d.empresa_id 
            AND d.fecha_carga >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE e.activo = TRUE AND d.id IS NULL
    ");
    $stats['informes_pendientes'] = $stmt->fetch()['total'];
    
    // ✅ SUCURSALES ACTIVAS SIN PAGO DE ARANCEL
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT s.id) as total 
        FROM sucursales s
        INNER JOIN empresas e ON s.empresa_id = e.id
        WHERE e.activo = TRUE AND s.activa = TRUE AND (s.pago_arancel = 0 OR s.pago_arancel IS NULL)
    ");
    $stats['aranceles_pendientes'] = $stmt->fetch()['total'];
    
    // ✅ PERSONAL ACTIVO SIN PAGO DE CREDENCIAL
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT p.id) as total 
        FROM personal p
        INNER JOIN empresas e ON p.empresa_id = e.id
        WHERE e.activo = TRUE AND p.activo = TRUE AND (p.pago_credencial = 0 OR p.pago_credencial IS NULL)
    ");
    $stats['credenciales_pendientes'] = $stmt->fetch()['total'];
    
    // ✅ TRÁMITES VENCIDOS
    $stmt = $conn->query("
        SELECT COUNT(*) as total 
        FROM tramites_empresa
        WHERE fecha_limite < CURDATE() AND estado IN ('en_tramite', 'fuera_tiempo')
    ");
    $stats['tramites_vencidos'] = $stmt->fetch()['total'];
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
}

// ============================================================================
// 7. OBTENER DATOS DEL DÍA ACTUAL PARA MOSTRAR EN PANTALLA
// ============================================================================
$alertas = [];
$dias_informe = [6, 11, 16, 21, 26];
$dias_limite = [5, 10, 15, 20, 25];

try {
    // 📄 INFORMES MENSUALES
    if (in_array($dia_actual, $dias_informe) || isset($_GET['ver_todo'])) {
        $sql = "
        SELECT 
            e.id, 
            e.nombre, 
            e.cuit, 
            e.email,
            MAX(d.fecha_carga) as ultima_carga,
            DATEDIFF(CURDATE(), MAX(d.fecha_carga)) as dias_sin_informe,
            COUNT(d.id) as total_documentos_mes
        FROM empresas e
        LEFT JOIN documentos_sucursales d ON e.id = d.empresa_id 
            AND d.fecha_carga >= DATE_SUB(CURDATE(), INTERVAL 35 DAY)
        WHERE e.activo = TRUE
        GROUP BY e.id
        HAVING ultima_carga IS NULL 
            OR ultima_carga < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            OR total_documentos_mes = 0
        ORDER BY dias_sin_informe DESC
        ";
        $stmt = $conn->query($sql);
        $empresas_sin_informe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($empresas_sin_informe)) {
            $alertas[] = [
                'tipo' => 'informe_mensual',
                'dia' => $dias_informe,
                'titulo' => '📄 Empresas Sin Informe Mensual',
                'descripcion' => 'Empresas activas que no han enviado documentación en los últimos 30 días',
                'cantidad' => count($empresas_sin_informe),
                'datos' => $empresas_sin_informe,
                'color' => 'purple', // ✅ COLOR CAMBIADO
                'icono' => 'fa-file-pdf'
            ];
        }
    }
    
    // 💰 ARANCELES DE SUCURSALES - TODAS LAS SUCURSALES SIN PAGO
    if ($dia_actual == 2 || isset($_GET['ver_todo'])) {
        $sql = "
        SELECT 
            s.id as sucursal_id,
            s.nombre as sucursal_nombre,
            s.domicilio,
            s.localidad,
            s.jurisdiccion,
            s.pago_arancel,
            s.fecha_pago_arancel,
            s.activa,
            e.id as empresa_id,
            e.nombre as empresa_nombre,
            e.cuit,
            e.email
        FROM sucursales s
        INNER JOIN empresas e ON s.empresa_id = e.id
        WHERE e.activo = TRUE 
            AND s.activa = TRUE
            AND (s.pago_arancel = 0 OR s.pago_arancel IS NULL)
        ORDER BY e.nombre, s.nombre
        ";
        $stmt = $conn->query($sql);
        $sucursales_sin_arancel = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($sucursales_sin_arancel)) {
            $alertas[] = [
                'tipo' => 'arancel_sucursales',
                'dia' => 2,
                'titulo' => '💰 Sucursales Con Arancel Pendiente',
                'descripcion' => 'Todas las sucursales activas que no han pagado el arancel de habilitación',
                'cantidad' => count($sucursales_sin_arancel),
                'datos' => $sucursales_sin_arancel,
                'color' => 'danger',
                'icono' => 'fa-money-bill-wave'
            ];
        }
    }
    
    // 🆔 CREDENCIALES DE PERSONAL - TODO EL PERSONAL SIN PAGO
    if ($dia_actual == 3 || isset($_GET['ver_todo'])) {
        $sql = "
        SELECT 
            p.id as personal_id,
            CONCAT(p.apellido, ', ', p.nombre) as nombre_completo,
            p.dni,
            p.cargo,
            p.pago_credencial,
            p.activo as personal_activo,
            p.fecha_vencimiento,
            e.id as empresa_id,
            e.nombre as empresa_nombre,
            e.cuit,
            s.id as sucursal_id,
            s.nombre as sucursal_nombre
        FROM personal p
        INNER JOIN empresas e ON p.empresa_id = e.id
        LEFT JOIN sucursales s ON p.sucursal_id = s.id
        WHERE e.activo = TRUE 
            AND p.activo = TRUE
            AND (p.pago_credencial = 0 OR p.pago_credencial IS NULL)
        ORDER BY e.nombre, p.apellido, p.nombre
        ";
        $stmt = $conn->query($sql);
        $personal_sin_credencial = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($personal_sin_credencial)) {
            $alertas[] = [
                'tipo' => 'pago_credencial',
                'dia' => 3,
                'titulo' => '🆔 Personal Con Credencial Pendiente de Pago',
                'descripcion' => 'Todo el personal activo que no ha abonado la credencial',
                'cantidad' => count($personal_sin_credencial),
                'datos' => $personal_sin_credencial,
                'color' => 'info',
                'icono' => 'fa-id-card'
            ];
        }
    }
    
    // ⏰ TRÁMITES
    if (in_array($dia_actual, $dias_limite) || isset($_GET['ver_todo'])) {
        $sql = "
        SELECT 
            t.id, 
            e.nombre as empresa_nombre, 
            e.cuit, 
            e.email,
            t.tipo_movimiento, 
            t.estado, 
            t.fecha_limite,
            t.plazo_dias, 
            t.urgente, 
            t.cantidad_notificaciones,
            DATEDIFF(t.fecha_limite, CURDATE()) as dias_restantes
        FROM tramites_empresa t
        INNER JOIN empresas e ON t.empresa_id = e.id
        WHERE e.activo = TRUE 
            AND t.estado IN ('en_tramite', 'fuera_tiempo')
            AND t.fecha_limite IS NOT NULL
        ORDER BY t.fecha_limite ASC
        ";
        $stmt = $conn->query($sql);
        $tramites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $vencidos = array_filter($tramites, fn($t) => $t['dias_restantes'] < 0);
        $proximos = array_filter($tramites, fn($t) => $t['dias_restantes'] >= 0 && $t['dias_restantes'] <= 5);
        $al_dia = array_filter($tramites, fn($t) => $t['dias_restantes'] > 5);
        
        if (!empty($tramites)) {
            $alertas[] = [
                'tipo' => 'fecha_limite_tramites',
                'dia' => $dias_limite,
                'titulo' => '⏰ Trámites Con Fecha Límite',
                'descripcion' => 'Trámites empresariales con fecha límite próxima o vencida',
                'cantidad' => count($tramites),
                'datos' => $tramites,
                'color' => 'warning',
                'icono' => 'fa-clock',
                'vencidos' => count($vencidos),
                'proximos' => count($proximos)
            ];
        }
    }
    
} catch (PDOException $e) {
    $error = "Error al cargar el calendario: " . $e->getMessage();
    error_log($error);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendario de Vencimientos - Sistema de Seguridad</title>
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
.dashboard { display: flex; }
.main-content {
    margin-left: 280px;
    padding: 20px;
    flex: 1;
}
.section-box {
    background: #ffffff;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}
.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 15px;
    border-bottom: 2px solid var(--card-border);
    padding-bottom: 10px;
}
.calendario-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
}
.calendario-nav-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s;
}
.calendario-nav-btn:hover { background: rgba(255,255,255,0.3); }
.calendario-mes-anio {
    font-size: 1.5rem;
    font-weight: 700;
}
.calendario-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
    margin-bottom: 30px;
}
.calendario-dia-header {
    text-align: center;
    font-weight: 600;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
}
.calendario-dia {
    background: white;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    padding: 15px;
    min-height: 120px;
    position: relative;
    transition: all 0.3s;
    cursor: pointer;
}
.calendario-dia:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
    border-color: var(--primary-color);
}
.calendario-dia.hoy {
    border: 3px solid var(--primary-color);
    background: #e7f1ff;
}
.calendario-dia.con-alerta {
    border-left: 5px solid #dc3545;
}
.dia-numero {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 8px;
}
.dia-alertas { margin-top: 10px; }
.alerta-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    margin: 2px;
}
.alerta-warning { background: #ffc107; color: #000; }
.alerta-danger { background: #dc3545; color: #fff; }
.alerta-info { background: #17a2b8; color: #fff; }
.alerta-success { background: #28a745; color: #fff; }
.alerta-purple { background: #6f42c1; color: #fff; } /* ✅ NUEVO COLOR */
.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.stat-card {
    background: white;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}
.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-color);
}
.stat-label {
    font-size: 0.85rem;
    color: #6c757d;
    text-transform: uppercase;
}
.btn {
    border-radius: 4px;
    font-weight: 500;
    padding: 8px 16px;
}
.btn-ver-todo {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}
/* MODAL */
.modal-detalle-fecha .modal-content {
    border-radius: 8px;
    border: none;
}
.modal-detalle-fecha .modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 8px 8px 0 0;
}
.detalle-section {
    margin-bottom: 20px;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    overflow: hidden;
}
.detalle-section-header {
    padding: 12px 15px;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.detalle-section-header.vencidos { background: #dc3545; color: white; }
.detalle-section-header.proximos { background: #fd7e14; color: white; }
.detalle-section-header.informes { background: #6f42c1; color: white; } /* ✅ COLOR CAMBIADO */
.detalle-section-header.aranceles { background: #dc3545; color: white; }
.detalle-section-header.credenciales { background: #17a2b8; color: white; }
.detalle-section-header.tramites { background: #28a745; color: white; }
.detalle-section-body {
    padding: 15px;
    background: #fff;
    max-height: 300px;
    overflow-y: auto;
}
.detalle-item {
    padding: 10px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.detalle-item:last-child { border-bottom: none; }
.detalle-item:hover { background: #f8f9fa; }
.detalle-empresa { font-weight: 600; color: #212529; }
.detalle-accion { margin-left: 10px; }
.sin-datos {
    text-align: center;
    padding: 30px;
    color: #6c757d;
}
.sin-datos i {
    font-size: 3rem;
    margin-bottom: 15px;
    opacity: 0.5;
}
.leyenda-container {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 15px;
}
.leyenda-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
}
.leyenda-color {
    width: 15px;
    height: 15px;
    border-radius: 3px;
}
</style>
</head>
<body>
<?php $page_title = 'Calendario de Vencimientos'; include '../includes/header.php'; ?>
<div class="dashboard">
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <!-- ESTADÍSTICAS -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon mb-2" style="color: #6f42c1;"><i class="fas fa-file-pdf fa-2x"></i></div>
                <div class="stat-number"><?php echo $stats['informes_pendientes']; ?></div>
                <div class="stat-label">Informes Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon mb-2 text-danger"><i class="fas fa-money-bill-wave fa-2x"></i></div>
                <div class="stat-number"><?php echo $stats['aranceles_pendientes']; ?></div>
                <div class="stat-label">Aranceles Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon mb-2 text-info"><i class="fas fa-id-card fa-2x"></i></div>
                <div class="stat-number"><?php echo $stats['credenciales_pendientes']; ?></div>
                <div class="stat-label">Credenciales Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon mb-2 text-danger"><i class="fas fa-exclamation-triangle fa-2x"></i></div>
                <div class="stat-number"><?php echo $stats['tramites_vencidos']; ?></div>
                <div class="stat-label">Trámites Vencidos</div>
            </div>
        </div>
        
        <!-- HEADER DEL CALENDARIO -->
        <div class="calendario-header">
            <a href="?mes=<?php echo $mes_navegacion - 1; ?>&anio=<?php echo $mes_navegacion == 1 ? $anio_navegacion - 1 : $anio_navegacion; ?>" 
               class="calendario-nav-btn">
                <i class="fas fa-chevron-left"></i> Mes Anterior
            </a>
            <div class="calendario-mes-anio">
                <i class="fas fa-calendar-alt me-2"></i>
                <?php echo $nombres_meses[$mes_navegacion] . ' ' . $anio_navegacion; ?>
            </div>
            <a href="?mes=<?php echo $mes_navegacion + 1; ?>&anio=<?php echo $mes_navegacion == 12 ? $anio_navegacion + 1 : $anio_navegacion; ?>" 
               class="calendario-nav-btn">
                Mes Siguiente <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        
        <!-- LEYENDA -->
        <div class="section-box">
            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Leyenda - Hacé clic en cualquier fecha para ver detalles</h5>
            <div class="leyenda-container">
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: #dc3545;"></div>
                    <span><strong>Día 02:</strong> Arancel Sucursales (TODAS las sucursales activas sin pago)</span>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: #17a2b8;"></div>
                    <span><strong>Día 03:</strong> Credencial Personal (TODO el personal activo sin pago)</span>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: #6f42c1;"></div>
                    <span><strong>Días 06,11,16,21,26:</strong> Informe Mensual (Empresas activas sin informe)</span>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: #fd7e14;"></div>
                    <span><strong>Días 05,10,15,20,25:</strong> Trámites con fecha límite</span>
                </div>
            </div>
        </div>
        
        <!-- CALENDARIO VISUAL -->
        <div class="section-box">
            <h3 class="section-title">
                <i class="fas fa-calendar-week me-2"></i>Vista del Mes
            </h3>
            <div class="calendario-grid">
                <div class="calendario-dia-header">Lun</div>
                <div class="calendario-dia-header">Mar</div>
                <div class="calendario-dia-header">Mié</div>
                <div class="calendario-dia-header">Jue</div>
                <div class="calendario-dia-header">Vie</div>
                <div class="calendario-dia-header">Sáb</div>
                <div class="calendario-dia-header">Dom</div>
                
                <?php
                $primer_dia = date('N', mktime(0, 0, 0, $mes_navegacion, 1, $anio_navegacion));
                $dias_en_mes = date('t', mktime(0, 0, 0, $mes_navegacion, 1, $anio_navegacion));
                
                for ($i = 1; $i < $primer_dia; $i++) {
                    echo '<div class="calendario-dia" style="background: #f8f9fa; cursor: default;"></div>';
                }
                
                for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
                    $es_hoy = ($dia == $dia_actual && $mes_navegacion == $mes_actual && $anio_navegacion == $anio_actual);
                    $tiene_alerta = false;
                    $alertas_dia = [];
                    
                    if ($dia == 2) {
                        $tiene_alerta = true;
                        $alertas_dia[] = ['tipo' => 'arancel', 'color' => 'danger', 'icono' => 'money-bill'];
                    }
                    if ($dia == 3) {
                        $tiene_alerta = true;
                        $alertas_dia[] = ['tipo' => 'credencial', 'color' => 'info', 'icono' => 'id-card'];
                    }
                    if (in_array($dia, [5, 10, 15, 20, 25])) {
                        $tiene_alerta = true;
                        $alertas_dia[] = ['tipo' => 'tramites', 'color' => 'warning', 'icono' => 'clock'];
                    }
                    if (in_array($dia, [6, 11, 16, 21, 26])) {
                        $tiene_alerta = true;
                        $alertas_dia[] = ['tipo' => 'informe', 'color' => 'purple', 'icono' => 'file'];
                    }
                    
                    $clase = 'calendario-dia';
                    if ($es_hoy) $clase .= ' hoy';
                    if ($tiene_alerta) $clase .= ' con-alerta';
                    
                    echo "<div class=\"$clase\" onclick=\"verDetalleFecha($dia, $mes_navegacion, $anio_navegacion)\">";
                    echo "<div class=\"dia-numero\">$dia</div>";
                    echo "<div class=\"dia-alertas\">";
                    foreach ($alertas_dia as $alerta) {
                        echo "<span class=\"alerta-badge alerta-{$alerta['color']}\">";
                        echo "<i class=\"fas fa-{$alerta['icono']}\"></i> ";
                        echo strtoupper($alerta['tipo']);
                        echo "</span>";
                    }
                    if ($es_hoy) {
                        echo "<div class=\"mt-2\"><span class=\"badge bg-primary\">HOY</span></div>";
                    }
                    echo "</div>";
                    echo "</div>";
                }
                ?>
            </div>
        </div>
        
        <!-- ALERTAS DEL DÍA -->
        <?php if (!empty($alertas)): ?>
        <div class="section-box">
            <h3 class="section-title">
                <i class="fas fa-exclamation-triangle me-2"></i>Alertas del Día <?php echo $dia_actual; ?>
            </h3>
            
            <?php foreach ($alertas as $alerta): ?>
            <div class="alerta-card alerta-<?php echo $alerta['color']; ?>" style="background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; border-left: 5px solid <?php 
                echo $alerta['color'] == 'warning' ? '#ffc107' : 
                    ($alerta['color'] == 'danger' ? '#dc3545' : 
                    ($alerta['color'] == 'info' ? '#17a2b8' : 
                    ($alerta['color'] == 'purple' ? '#6f42c1' : '#ffc107'))); 
            ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas <?php echo $alerta['icono']; ?> me-2"></i>
                        <?php echo $alerta['titulo']; ?>
                    </h5>
                    <span class="badge bg-<?php echo $alerta['color'] == 'purple' ? 'secondary' : $alerta['color']; ?> fs-6" style="<?php echo $alerta['color'] == 'purple' ? 'background: #6f42c1 !important;' : ''; ?>">
                        <?php echo $alerta['cantidad']; ?> registros
                    </span>
                </div>
                <p class="text-muted mb-3"><?php echo $alerta['descripcion']; ?></p>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <?php if ($alerta['tipo'] == 'informe_mensual'): ?>
                                <th>Empresa</th>
                                <th>CUIT</th>
                                <th>Última Carga</th>
                                <th>Días Sin Informe</th>
                                <th>Acciones</th>
                                <?php elseif ($alerta['tipo'] == 'arancel_sucursales'): ?>
                                <th>Empresa</th>
                                <th>Sucursal</th>
                                <th>Localidad</th>
                                <th>Jurisdicción</th>
                                <th>Último Pago</th>
                                <th>Acciones</th>
                                <?php elseif ($alerta['tipo'] == 'pago_credencial'): ?>
                                <th>Empresa</th>
                                <th>Personal</th>
                                <th>DNI</th>
                                <th>Cargo</th>
                                <th>Sucursal</th>
                                <th>Acciones</th>
                                <?php elseif ($alerta['tipo'] == 'fecha_limite_tramites'): ?>
                                <th>Empresa</th>
                                <th>Tipo Trámite</th>
                                <th>Estado</th>
                                <th>Fecha Límite</th>
                                <th>Días Restantes</th>
                                <th>Acciones</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerta['datos'] as $dato): ?>
                            <tr>
                                <?php if ($alerta['tipo'] == 'informe_mensual'): ?>
                                <td><strong><?php echo htmlspecialchars($dato['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($dato['cuit'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if (!empty($dato['ultima_carga'])): ?>
                                        <span class="text-danger"><?php echo date('d/m/Y', strtotime($dato['ultima_carga'])); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Nunca</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo ($dato['dias_sin_informe'] ?? 0) > 30 ? 'danger' : 'warning'; ?>">
                                        <?php echo $dato['dias_sin_informe'] ?? 'N/A'; ?> días
                                    </span>
                                </td>
                                <td>
                                    <a href="documentos_empresas.php?search_empresa=<?php echo urlencode($dato['nombre']); ?>" 
                                       class="btn btn-sm btn-primary" target="_blank">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </td>
                                
                                <?php elseif ($alerta['tipo'] == 'arancel_sucursales'): ?>
                                <td><strong><?php echo htmlspecialchars($dato['empresa_nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($dato['sucursal_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($dato['localidad'] ?? 'N/A'); ?></td>
                                <td><span class="badge bg-info"><?php echo htmlspecialchars($dato['jurisdiccion'] ?? 'N/A'); ?></span></td>
                                <td>
                                    <?php if (!empty($dato['fecha_pago_arancel'])): ?>
                                        <?php echo date('d/m/Y', strtotime($dato['fecha_pago_arancel'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="sucursales.php?edit=<?php echo $dato['sucursal_id']; ?>" 
                                       class="btn btn-sm btn-primary" target="_blank">
                                        <i class="fas fa-building"></i> Ver
                                    </a>
                                </td>
                                
                                <?php elseif ($alerta['tipo'] == 'pago_credencial'): ?>
                                <td><strong><?php echo htmlspecialchars($dato['empresa_nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($dato['nombre_completo']); ?></td>
                                <td><?php echo htmlspecialchars($dato['dni']); ?></td>
                                <td><span class="badge bg-info"><?php echo htmlspecialchars($dato['cargo'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($dato['sucursal_nombre'] ?? 'N/A'); ?></td>
                                <td>
                                    <a href="personal.php?edit=<?php echo $dato['personal_id']; ?>" 
                                       class="btn btn-sm btn-primary" target="_blank">
                                        <i class="fas fa-user-edit"></i> Ver
                                    </a>
                                </td>
                                
                                <?php elseif ($alerta['tipo'] == 'fecha_limite_tramites'): ?>
                                <td><strong><?php echo htmlspecialchars($dato['empresa_nombre']); ?></strong></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $dato['tipo_movimiento'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $dato['estado'] == 'aprobado' ? 'success' : ($dato['estado'] == 'rechazado' ? 'danger' : 'warning'); ?>">
                                        <?php echo strtoupper($dato['estado']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($dato['fecha_limite'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $dato['dias_restantes'] < 0 ? 'danger' : ($dato['dias_restantes'] <= 5 ? 'warning' : 'success'); ?>">
                                        <?php echo $dato['dias_restantes']; ?> días
                                    </span>
                                </td>
                                <td>
                                    <a href="gestion_movimientos.php?estado_tramite=<?php echo $dato['estado']; ?>" 
                                       class="btn btn-sm btn-primary" target="_blank">
                                        <i class="fas fa-file-alt"></i> Ver
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            
        </div>
        <?php else: ?>
        <div class="section-box">
            <div class="text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h4>¡Todo en Orden!</h4>
                <p class="text-muted">No hay alertas pendientes para el día de hoy.</p>
                <a href="?ver_todo=1" class="btn btn-ver-todo mt-3">
                    <i class="fas fa-eye me-2"></i>Ver Todas las Alertas del Mes
                </a>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</div>

<!-- MODAL DE DETALLE POR FECHA -->
<div class="modal fade modal-detalle-fecha" id="modalDetalleFecha" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-day me-2"></i>
                    <span id="modalFechaTitulo">Detalle de Fecha</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDetalleContenido">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-3">Cargando información...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function verDetalleFecha(dia, mes, anio) {
    const modal = new bootstrap.Modal(document.getElementById('modalDetalleFecha'));
    document.getElementById('modalFechaTitulo').textContent = `Detalle del ${dia.toString().padStart(2, '0')}/${mes.toString().padStart(2, '0')}/${anio}`;
    document.getElementById('modalDetalleContenido').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="mt-3">Cargando información del ${dia}/${mes}/${anio}...</p>
        </div>
    `;
    modal.show();
    
    fetch(`calendario_vencimientos.php?action=obtener_detalle_fecha&dia=${dia}&mes=${mes}&anio=${anio}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostrarDetalleFecha(data);
            } else {
                document.getElementById('modalDetalleContenido').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error al cargar los datos: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('modalDetalleContenido').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Error de conexión: ${error.message}
                </div>
            `;
        });
}

function mostrarDetalleFecha(data) {
    const contenido = document.getElementById('modalDetalleContenido');
    const totales = data.totales;
    const totalObligaciones = totales.informes + totales.aranceles + totales.credenciales + totales.tramites + totales.vencidos + totales.proximos;
    
    let html = `
        <div class="alert alert-info mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Fecha:</strong> ${data.fecha}
                </div>
                <div>
                    <span class="badge bg-primary">${totalObligaciones} obligaciones encontradas</span>
                </div>
            </div>
        </div>
    `;
    
    // 📄 INFORMES MENSUALES
    if (totales.informes > 0) {
        html += `
            <div class="detalle-section">
                <div class="detalle-section-header informes">
                    <span><i class="fas fa-file-pdf me-2"></i>📄 Informes Mensuales Pendientes (${totales.informes})</span>
                </div>
                <div class="detalle-section-body">
        `;
        data.datos.informes.forEach(item => {
            html += `
                <div class="detalle-item">
                    <div>
                        <div class="detalle-empresa">${item.nombre}</div>
                        <small class="text-muted">CUIT: ${item.cuit} | Última carga: ${item.ultima_carga ? item.ultima_carga : 'Nunca'}</small>
                    </div>
                    <div class="detalle-accion">
                        <a href="documentos_empresas.php?search_empresa=${encodeURIComponent(item.nombre)}" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-eye"></i> Ver
                        </a>
                    </div>
                </div>
            `;
        });
        html += `</div></div>`;
    }
    
    // 💰 ARANCELES - TODAS LAS SUCURSALES
    if (totales.aranceles > 0) {
        html += `
            <div class="detalle-section">
                <div class="detalle-section-header aranceles">
                    <span><i class="fas fa-money-bill-wave me-2"></i>💰 Sucursales Con Arancel Pendiente (${totales.aranceles})</span>
                </div>
                <div class="detalle-section-body">
        `;
        data.datos.aranceles.forEach(item => {
            html += `
                <div class="detalle-item">
                    <div>
                        <div class="detalle-empresa">${item.empresa_nombre} - ${item.sucursal_nombre}</div>
                        <small class="text-muted">${item.localidad} | ${item.jurisdiccion}</small>
                    </div>
                    <div class="detalle-accion">
                        <a href="sucursales.php?edit=${item.sucursal_id}" target="_blank" class="btn btn-sm btn-danger">
                            <i class="fas fa-building"></i> Ver
                        </a>
                    </div>
                </div>
            `;
        });
        html += `</div></div>`;
    }
    
    // 🆔 CREDENCIALES - TODO EL PERSONAL
    if (totales.credenciales > 0) {
        html += `
            <div class="detalle-section">
                <div class="detalle-section-header credenciales">
                    <span><i class="fas fa-id-card me-2"></i>🆔 Personal Con Credencial Pendiente (${totales.credenciales})</span>
                </div>
                <div class="detalle-section-body">
        `;
        data.datos.credenciales.forEach(item => {
            html += `
                <div class="detalle-item">
                    <div>
                        <div class="detalle-empresa">${item.nombre_completo}</div>
                        <small class="text-muted">${item.empresa_nombre} | DNI: ${item.dni} | Cargo: ${item.cargo || 'N/A'}</small>
                    </div>
                    <div class="detalle-accion">
                        <a href="personal.php?edit=${item.personal_id}" target="_blank" class="btn btn-sm btn-info">
                            <i class="fas fa-user-edit"></i> Ver
                        </a>
                    </div>
                </div>
            `;
        });
        html += `</div></div>`;
    }
    
    // 🔴 VENCIDOS
    if (totales.vencidos > 0) {
        html += `
            <div class="detalle-section">
                <div class="detalle-section-header vencidos">
                    <span><i class="fas fa-exclamation-triangle me-2"></i>🔴 Trámites VENCIDOS (${totales.vencidos})</span>
                </div>
                <div class="detalle-section-body">
        `;
        data.datos.vencidos.forEach(item => {
            html += `
                <div class="detalle-item">
                    <div>
                        <div class="detalle-empresa">${item.empresa_nombre}</div>
                        <small class="text-muted">${item.tipo_movimiento} | Venció hace ${Math.abs(item.dias_restantes)} días</small>
                    </div>
                    <div class="detalle-accion">
                        <a href="gestion_movimientos.php?estado_tramite=${item.estado}" target="_blank" class="btn btn-sm btn-danger">
                            <i class="fas fa-file-alt"></i> Ver
                        </a>
                    </div>
                </div>
            `;
        });
        html += `</div></div>`;
    }
    
    // 🟠 PRÓXIMOS
    if (totales.proximos > 0) {
        html += `
            <div class="detalle-section">
                <div class="detalle-section-header proximos">
                    <span><i class="fas fa-clock me-2"></i>🟠 Trámites Próximos a Vencer (${totales.proximos})</span>
                </div>
                <div class="detalle-section-body">
        `;
        data.datos.proximos.forEach(item => {
            html += `
                <div class="detalle-item">
                    <div>
                        <div class="detalle-empresa">${item.empresa_nombre}</div>
                        <small class="text-muted">${item.tipo_movimiento} | Vence en ${item.dias_restantes} días</small>
                    </div>
                    <div class="detalle-accion">
                        <a href="gestion_movimientos.php?estado_tramite=${item.estado}" target="_blank" class="btn btn-sm btn-warning">
                            <i class="fas fa-file-alt"></i> Ver
                        </a>
                    </div>
                </div>
            `;
        });
        html += `</div></div>`;
    }
    
    if (totalObligaciones === 0) {
        html += `
            <div class="sin-datos">
                <i class="fas fa-check-circle text-success"></i>
                <h5>¡Todo en Orden!</h5>
                <p>No hay obligaciones pendientes para esta fecha.</p>
            </div>
        `;
    }
    
    contenido.innerHTML = html;
}

document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});
</script>
</body>
</html>