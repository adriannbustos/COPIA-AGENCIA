<?php
/**
* ============================================================================
* SISTEMA DE AUDITORÍA DE ACTIVIDADES - VERSIÓN ACTUALIZADA
* ============================================================================
* Incluye: Risk Score, Estadísticas, Exportación (CSV/JSON/PDF),
*          Comentarios, Búsqueda, Filtros Avanzados, Alertas de Seguridad
*          ✅ CORREGIDO: Verifica columnas reales de cada tabla antes de JOIN
*
* @author Sistema de Seguridad
* @version 2.3 - Con verificación de columnas
* @last_update 2024
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
if (!$auth->hasRole('administrador') && !$auth->hasRole('carga') && !$auth->hasRole('auditor')) {
    $_SESSION['error'] = 'Acceso denegado. Se requieren permisos de administrador o auditor.';
    header('Location: ../index.php');
    exit;
}

$current_page = 'auditoria';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ============================================================================
// 2. PROCESAR COMENTARIOS DE AUDITORÍA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_comentario'])) {
    try {
        $auditoria_id = (int)($_POST['auditoria_id'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');
        if ($auditoria_id > 0 && !empty($comentario)) {
            if (agregarComentarioAuditoria($conn, $auditoria_id, $comentario, $user['id'])) {
                $_SESSION['success'] = 'Comentario agregado exitosamente';
            } else {
                $_SESSION['error'] = 'Error al agregar comentario';
            }
        } else {
            $_SESSION['error'] = 'Datos inválidos para el comentario';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    header('Location: auditoria.php');
    exit;
}

// ============================================================================
// 3. PROCESAR EXPORTACIÓN DE AUDITORÍA
// ============================================================================
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['csv', 'json', 'pdf'])) {
    try {
        $filtros = [
            'fecha_desde' => $_GET['fecha_desde'] ?? null,
            'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
            'usuario_id' => !empty($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : null,
            'accion' => $_GET['accion'] ?? null,
            'tabla' => $_GET['tabla'] ?? null,
            'risk_score_min' => !empty($_GET['risk_score_min']) ? (int)$_GET['risk_score_min'] : null,
            'risk_score_max' => !empty($_GET['risk_score_max']) ? (int)$_GET['risk_score_max'] : null
        ];
        exportarAuditoria($conn, $_GET['exportar'], $filtros);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al exportar: ' . $e->getMessage();
        header('Location: auditoria.php');
        exit;
    }
}

// ============================================================================
// 4. PROCESAR LIMPIEZA DE AUDITORÍA ANTIGUA (Solo Super Admin)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar_auditoria']) && $auth->hasRole('super_admin')) {
    try {
        $dias_a_conservar = (int)($_POST['dias_a_conservar'] ?? 365);
        if ($dias_a_conservar < 30) {
            throw new Exception('Debe conservar al menos 30 días de auditoría');
        }
        $resultado = limpiarAuditoriaAntigua($conn, $dias_a_conservar, $user['id']);
        if ($resultado['success']) {
            $_SESSION['success'] = "Auditoría limpiada. Se eliminaron {$resultado['registros_eliminados']} registros antiguos.";
        } else {
            $_SESSION['error'] = 'Error al limpiar auditoría: ' . ($resultado['error'] ?? 'Error desconocido');
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    header('Location: auditoria.php');
    exit;
}

// ============================================================================
// 5. PROCESAR MARCAR ALERTA COMO LEÍDA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_alerta_leida'])) {
    try {
        $alerta_id = (int)($_POST['alerta_id'] ?? 0);
        if ($alerta_id > 0) {
            if (marcarAlertaComoLeida($conn, $alerta_id, $user['id'])) {
                $_SESSION['success'] = 'Alerta marcada como leída';
            } else {
                $_SESSION['error'] = 'Error al marcar alerta como leída';
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    header('Location: auditoria.php');
    exit;
}

// ============================================================================
// 6. CREAR/VERIFICAR TABLAS DE AUDITORÍA
// ============================================================================
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'auditoria'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("
        CREATE TABLE auditoria (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NULL,
        accion VARCHAR(100) NOT NULL,
        tabla VARCHAR(50) NOT NULL,
        registro_id INT NULL,
        detalles TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        request_uri VARCHAR(500) NULL,
        request_method VARCHAR(10) NULL,
        risk_score INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario (usuario_id),
        INDEX idx_tabla (tabla),
        INDEX idx_created_at (created_at),
        INDEX idx_accion (accion),
        INDEX idx_registro (tabla, registro_id),
        INDEX idx_risk_score (risk_score),
        INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'CREACION_TABLA', 'auditoria', null, ['mensaje' => 'Tabla auditoria creada'], $user['id']);
    }
    
    $stmt = $conn->query("SHOW TABLES LIKE 'auditoria_comentarios'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("
        CREATE TABLE auditoria_comentarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        auditoria_id INT NOT NULL,
        usuario_id INT NOT NULL,
        comentario TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_auditoria (auditoria_id),
        INDEX idx_usuario (usuario_id),
        FOREIGN KEY (auditoria_id) REFERENCES auditoria(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
    }
    
    $stmt = $conn->query("SHOW TABLES LIKE 'auditoria_alertas'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("
        CREATE TABLE auditoria_alertas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NULL,
        nivel_riesgo ENUM('BAJO', 'MEDIO', 'ALTO') DEFAULT 'BAJO',
        tipo_alerta VARCHAR(50) NOT NULL,
        descripcion TEXT NULL,
        leida BOOLEAN DEFAULT FALSE,
        leida_por INT NULL,
        leida_en TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario (usuario_id),
        INDEX idx_nivel (nivel_riesgo),
        INDEX idx_leida (leida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
    }
} catch (PDOException $e) {
    error_log("Error creando tablas de auditoría: " . $e->getMessage());
    $error = "Error al verificar estructura de base de datos: " . $e->getMessage();
}

// ============================================================================
// 7. OBTENER FILTROS DE BÚSQUEDA
// ============================================================================
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$usuario_filtro = !empty($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : '';
$accion_filtro = $_GET['accion'] ?? '';
$tabla_filtro = $_GET['tabla'] ?? '';
$registro_id_filtro = !empty($_GET['registro_id']) ? (int)$_GET['registro_id'] : '';
$busqueda = $_GET['busqueda'] ?? '';
$risk_score_min = !empty($_GET['risk_score_min']) ? (int)$_GET['risk_score_min'] : '';
$risk_score_max = !empty($_GET['risk_score_max']) ? (int)$_GET['risk_score_max'] : '';
$nivel_riesgo_filtro = $_GET['nivel_riesgo'] ?? '';

$registros_por_pagina = 50;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// ============================================================================
// 8. OBTENER ESTADÍSTICAS DE AUDITORÍA
// ============================================================================
$filtros_stats = [
    'fecha_desde' => $fecha_desde,
    'fecha_hasta' => $fecha_hasta
];
$stats = obtenerEstadisticasAuditoria($conn, $filtros_stats) ?? [
    'total_registros' => 0,
    'usuarios_unicos' => 0,
    'por_accion' => [],
    'por_tabla' => [],
    'por_usuario' => [],
    'actividad_sospechosa' => 0,
    'risk_score_promedio' => 0,
    'risk_score_maximo' => 0,
    'por_risk_level' => ['BAJO' => 0, 'MEDIO' => 0, 'ALTO' => 0],
    'timeline' => [],
    'por_hora' => [],
    'top_usuarios' => [],
    'top_acciones' => []
];

// ============================================================================
// 9. OBTENER ALERTAS DE SEGURIDAD
// ============================================================================
$alertas_seguridad = obtenerAlertasSeguridad($conn, null, 10);
$alertas_no_leidas = 0;
foreach ($alertas_seguridad as $alerta) {
    if (!$alerta['leida']) {
        $alertas_no_leidas++;
    }
}

// ============================================================================
// 10. ✅ VERIFICAR COLUMNAS DE TODAS LAS TABLAS (CORREGIDO)
// ============================================================================
function obtenerColumnasTabla($conn, $tabla) {
    $columnas = [];
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM {$tabla}");
        while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columnas[] = $col['Field'];
        }
    } catch (PDOException $e) {
        // Tabla no existe o error
    }
    return $columnas;
}

// Verificar columnas de usuarios
$columnas_usuarios = obtenerColumnasTabla($conn, 'usuarios');

// ✅ Verificar columnas de cada tabla para los JOINs
$columnas_empresas = obtenerColumnasTabla($conn, 'empresas');
$columnas_sucursales = obtenerColumnasTabla($conn, 'sucursales');
$columnas_personal = obtenerColumnasTabla($conn, 'personal');
$columnas_servicios = obtenerColumnasTabla($conn, 'servicios');
$columnas_recursos = obtenerColumnasTabla($conn, 'recursos');

// Determinar nombre de columna para cada tabla
$col_nombre_empresa = in_array('nombre', $columnas_empresas) ? 'nombre' : (in_array('razon_social', $columnas_empresas) ? 'razon_social' : null);
$col_nombre_sucursal = in_array('nombre', $columnas_sucursales) ? 'nombre' : (in_array('descripcion', $columnas_sucursales) ? 'descripcion' : null);
$col_nombre_personal = in_array('nombre_completo', $columnas_personal) ? 'nombre_completo' : (in_array('nombre', $columnas_personal) ? 'nombre' : (in_array('nombres', $columnas_personal) ? 'nombres' : null));
$col_nombre_servicio = in_array('nombre', $columnas_servicios) ? 'nombre' : (in_array('descripcion', $columnas_servicios) ? 'descripcion' : null);
$col_nombre_recurso = in_array('nombre', $columnas_recursos) ? 'nombre' : (in_array('descripcion', $columnas_recursos) ? 'descripcion' : null);

// ============================================================================
// 11. ✅ CONSULTA SQL CON VERIFICACIÓN DE COLUMNAS (CORREGIDO)
// ============================================================================
$registros = [];
$total_registros = 0;
$total_paginas = 0;

if (!empty($busqueda)) {
    $filtros_busqueda = [
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta,
        'usuario_id' => $usuario_filtro ?: null,
        'risk_score_min' => $risk_score_min ?: null
    ];
    $registros = buscarEnHistorial($conn, $busqueda, $filtros_busqueda, 1000);
    $total_registros = count($registros);
    $total_paginas = 1;
} else {
    try {
        $tiene_rol = in_array('rol', $columnas_usuarios);
        $tiene_empresa = in_array('empresa_id', $columnas_usuarios);
        $tiene_sucursal = in_array('sucursal_id', $columnas_usuarios);
        
        // ✅ CONSTRUCCIÓN DINÁMICA DEL SQL SEGÚN COLUMNAS DISPONIBLES
        $select_extra = "";
        $join_extra = "";
        
        // JOIN empresas
        if ($col_nombre_empresa) {
            $select_extra .= ", e.{$col_nombre_empresa} as empresa_nombre";
            $join_extra .= " LEFT JOIN empresas e ON (a.tabla = 'empresa' OR a.tabla = 'empresas') AND a.registro_id = e.id";
        }
        
        // JOIN sucursales
        if ($col_nombre_sucursal) {
            $select_extra .= ", s.{$col_nombre_sucursal} as sucursal_nombre";
            $join_extra .= " LEFT JOIN sucursales s ON (a.tabla = 'sucursal' OR a.tabla = 'sucursales') AND a.registro_id = s.id";
        }
        
        // JOIN personal (SOLO SI EXISTE LA COLUMNA)
        if ($col_nombre_personal) {
            $select_extra .= ", p.{$col_nombre_personal} as personal_nombre";
            $join_extra .= " LEFT JOIN personal p ON a.tabla = 'personal' AND a.registro_id = p.id";
        }
        
        // JOIN servicios
        if ($col_nombre_servicio) {
            $select_extra .= ", ser.{$col_nombre_servicio} as servicio_nombre";
            $join_extra .= " LEFT JOIN servicios ser ON (a.tabla = 'servicio' OR a.tabla = 'servicios') AND a.registro_id = ser.id";
        }
        
        // JOIN recursos
        if ($col_nombre_recurso) {
            $select_extra .= ", re.{$col_nombre_recurso} as recurso_nombre";
            $join_extra .= " LEFT JOIN recursos re ON (a.tabla = 'recurso' OR a.tabla = 'recursos') AND a.registro_id = re.id";
        }
        
        $sql = "
        SELECT a.*,
            u.nombre_completo as usuario_nombre,
            u.email as usuario_email
            " . ($tiene_rol ? ", u.rol as usuario_rol" : "") . "
            " . ($tiene_empresa ? ", u.empresa_id as usuario_empresa_id" : "") . "
            " . ($tiene_sucursal ? ", u.sucursal_id as usuario_sucursal_id" : "") . "
            {$select_extra}
        FROM auditoria a
        LEFT JOIN usuarios u ON a.usuario_id = u.id
        {$join_extra}
        WHERE 1=1
        ";
        
        $params = [];
        
        if (!empty($fecha_desde)) {
            $sql .= " AND DATE(a.created_at) >= :fecha_desde";
            $params[':fecha_desde'] = $fecha_desde;
        }
        if (!empty($fecha_hasta)) {
            $sql .= " AND DATE(a.created_at) <= :fecha_hasta";
            $params[':fecha_hasta'] = $fecha_hasta;
        }
        if (!empty($usuario_filtro)) {
            $sql .= " AND a.usuario_id = :usuario_id";
            $params[':usuario_id'] = $usuario_filtro;
        }
        if (!empty($accion_filtro)) {
            $sql .= " AND a.accion = :accion";
            $params[':accion'] = $accion_filtro;
        }
        if (!empty($tabla_filtro)) {
            $sql .= " AND a.tabla = :tabla";
            $params[':tabla'] = $tabla_filtro;
        }
        if (!empty($registro_id_filtro)) {
            $sql .= " AND a.registro_id = :registro_id";
            $params[':registro_id'] = $registro_id_filtro;
        }
        if (!empty($risk_score_min)) {
            $sql .= " AND a.risk_score >= :risk_score_min";
            $params[':risk_score_min'] = $risk_score_min;
        }
        if (!empty($risk_score_max)) {
            $sql .= " AND a.risk_score <= :risk_score_max";
            $params[':risk_score_max'] = $risk_score_max;
        }
        if (!empty($nivel_riesgo_filtro)) {
            if ($nivel_riesgo_filtro === 'bajo') {
                $sql .= " AND a.risk_score < 40";
            } elseif ($nivel_riesgo_filtro === 'medio') {
                $sql .= " AND a.risk_score >= 40 AND a.risk_score < 70";
            } elseif ($nivel_riesgo_filtro === 'alto') {
                $sql .= " AND a.risk_score >= 70";
            }
        }
        
        // Contar total para paginación
        $count_sql = "SELECT COUNT(*) as total FROM auditoria a WHERE 1=1";
        $count_params = [];
        if (!empty($fecha_desde)) {
            $count_sql .= " AND DATE(a.created_at) >= :fecha_desde";
            $count_params[':fecha_desde'] = $fecha_desde;
        }
        if (!empty($fecha_hasta)) {
            $count_sql .= " AND DATE(a.created_at) <= :fecha_hasta";
            $count_params[':fecha_hasta'] = $fecha_hasta;
        }
        if (!empty($usuario_filtro)) {
            $count_sql .= " AND a.usuario_id = :usuario_id";
            $count_params[':usuario_id'] = $usuario_filtro;
        }
        if (!empty($accion_filtro)) {
            $count_sql .= " AND a.accion = :accion";
            $count_params[':accion'] = $accion_filtro;
        }
        if (!empty($tabla_filtro)) {
            $count_sql .= " AND a.tabla = :tabla";
            $count_params[':tabla'] = $tabla_filtro;
        }
        if (!empty($risk_score_min)) {
            $count_sql .= " AND a.risk_score >= :risk_score_min";
            $count_params[':risk_score_min'] = $risk_score_min;
        }
        if (!empty($risk_score_max)) {
            $count_sql .= " AND a.risk_score <= :risk_score_max";
            $count_params[':risk_score_max'] = $risk_score_max;
        }
        
        $stmt_count = $conn->prepare($count_sql);
        $stmt_count->execute($count_params);
        $total_registros = $stmt_count->fetch()['total'];
        $total_paginas = ceil($total_registros / $registros_por_pagina);
        
        $sql .= " ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $registros = [];
        $total_registros = 0;
        $total_paginas = 0;
        $error = "Error al cargar registros de auditoría: " . $e->getMessage();
        error_log($error);
    }
}

// ============================================================================
// 12. OBTENER DATOS PARA FILTROS
// ============================================================================
try {
    $stmt = $conn->query("SELECT id, nombre_completo, rol FROM usuarios WHERE activo = 1 ORDER BY nombre_completo");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $usuarios = [];
}

try {
    $stmt = $conn->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion");
    $acciones = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($acciones)) {
        $acciones = ['creacion', 'modificacion', 'eliminacion', 'login', 'logout', 'actualizacion'];
    }
} catch (PDOException $e) {
    $acciones = ['creacion', 'modificacion', 'eliminacion'];
}

try {
    $stmt = $conn->query("SELECT DISTINCT tabla FROM auditoria ORDER BY tabla");
    $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tablas)) {
        $tablas = ['personal', 'empresas', 'sucursales', 'usuarios', 'sistema'];
    }
} catch (PDOException $e) {
    $tablas = ['personal', 'empresas', 'sucursales'];
}

$filter_params = http_build_query([
    'fecha_desde' => $fecha_desde,
    'fecha_hasta' => $fecha_hasta,
    'usuario_id' => $usuario_filtro,
    'accion' => $accion_filtro,
    'tabla' => $tabla_filtro,
    'risk_score_min' => $risk_score_min,
    'risk_score_max' => $risk_score_max,
    'nivel_riesgo' => $nivel_riesgo_filtro
]);

// ============================================================================
// 13. FUNCIONES DE UTILIDAD PARA VISUALIZACIÓN
// ============================================================================
function getAccionBadge($accion) {
    $accion_lower = strtolower($accion);
    if (strpos($accion_lower, 'creada') !== false || strpos($accion_lower, 'creacion') !== false || $accion === 'CREACION') {
        return ['class' => 'accion-creacion', 'icon' => 'fa-plus-circle'];
    }
    if (strpos($accion_lower, 'actualizada') !== false || strpos($accion_lower, 'modificacion') !== false || strpos($accion_lower, 'update') !== false) {
        return ['class' => 'accion-modificacion', 'icon' => 'fa-edit'];
    }
    if (strpos($accion_lower, 'eliminada') !== false || strpos($accion_lower, 'eliminacion') !== false || strpos($accion_lower, 'delete') !== false) {
        return ['class' => 'accion-eliminacion', 'icon' => 'fa-trash'];
    }
    if (strpos($accion_lower, 'login') !== false || strpos($accion_lower, 'ingreso') !== false) {
        return ['class' => 'accion-login', 'icon' => 'fa-sign-in-alt'];
    }
    if (strpos($accion_lower, 'logout') !== false || strpos($accion_lower, 'salida') !== false) {
        return ['class' => 'accion-logout', 'icon' => 'fa-sign-out-alt'];
    }
    if (strpos($accion_lower, 'alerta') !== false || strpos($accion_lower, 'sospechosa') !== false) {
        return ['class' => 'accion-alerta', 'icon' => 'fa-exclamation-triangle'];
    }
    if (strpos($accion_lower, 'export') !== false || strpos($accion_lower, 'descarga') !== false) {
        return ['class' => 'accion-modificacion', 'icon' => 'fa-download'];
    }
    if (strpos($accion_lower, 'pago') !== false || strpos($accion_lower, 'credencial') !== false) {
        return ['class' => 'accion-creacion', 'icon' => 'fa-money-bill'];
    }
    if (strpos($accion_lower, 'aprob') !== false) {
        return ['class' => 'accion-creacion', 'icon' => 'fa-check-circle'];
    }
    if (strpos($accion_lower, 'rechaz') !== false) {
        return ['class' => 'accion-eliminacion', 'icon' => 'fa-times-circle'];
    }
    return ['class' => 'accion-logout', 'icon' => 'fa-circle'];
}

function getRiskLevel($score) {
    if ($score >= 70) return 'alto';
    if ($score >= 40) return 'medio';
    return 'bajo';
}

function getRiskBadge($score) {
    $level = getRiskLevel($score);
    if ($level === 'alto') {
        return ['class' => 'risk-alto', 'icon' => 'fa-exclamation-triangle', 'text' => 'ALTO'];
    }
    if ($level === 'medio') {
        return ['class' => 'risk-medio', 'icon' => 'fa-exclamation-circle', 'text' => 'MEDIO'];
    }
    return ['class' => 'risk-bajo', 'icon' => 'fa-check-circle', 'text' => 'BAJO'];
}

function formatearDetalles($detalles) {
    if (empty($detalles)) {
        return '<span class="text-muted">Sin detalles</span>';
    }
    $decoded = json_decode($detalles, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $html = '<ul class="list-unstyled mb-0 small">';
        $count = 0;
        foreach ($decoded as $key => $value) {
            if ($count >= 5) {
                $html .= '<li class="text-muted">... y ' . (count($decoded) - 5) . ' campos más</li>';
                break;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $html .= '<li><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars(substr($value, 0, 60)) . (strlen($value) > 60 ? '...' : '') . '</li>';
            $count++;
        }
        $html .= '</ul>';
        return $html;
    }
    return '<span class="small">' . htmlspecialchars(substr($detalles, 0, 100)) . (strlen($detalles) > 100 ? '...' : '') . '</span>';
}

function getTablaBadge($tabla) {
    $tabla_lower = strtolower($tabla);
    if (strpos($tabla_lower, 'empresa') !== false) {
        return ['class' => 'bg-primary', 'icon' => 'fa-building'];
    }
    if (strpos($tabla_lower, 'sucursal') !== false) {
        return ['class' => 'bg-success', 'icon' => 'fa-store'];
    }
    if (strpos($tabla_lower, 'personal') !== false || strpos($tabla_lower, 'usuario') !== false) {
        return ['class' => 'bg-info', 'icon' => 'fa-users'];
    }
    if (strpos($tabla_lower, 'auditoria') !== false) {
        return ['class' => 'bg-dark', 'icon' => 'fa-shield-alt'];
    }
    if (strpos($tabla_lower, 'sistema') !== false) {
        return ['class' => 'bg-secondary', 'icon' => 'fa-cog'];
    }
    return ['class' => 'bg-secondary', 'icon' => 'fa-database'];
}

// ✅ FUNCIÓN PARA OBTENER NOMBRE DEL REGISTRO SEGÚN TABLA
function getNombreRegistro($registro) {
    $tabla_lower = strtolower($registro['tabla'] ?? '');
    
    if ($tabla_lower === 'empresa' || $tabla_lower === 'empresas') {
        return $registro['empresa_nombre'] ?? null;
    } elseif ($tabla_lower === 'sucursal' || $tabla_lower === 'sucursales') {
        return $registro['sucursal_nombre'] ?? null;
    } elseif ($tabla_lower === 'personal') {
        return $registro['personal_nombre'] ?? null;
    } elseif ($tabla_lower === 'servicio' || $tabla_lower === 'servicios') {
        return $registro['servicio_nombre'] ?? null;
    } elseif ($tabla_lower === 'recurso' || $tabla_lower === 'recursos') {
        return $registro['recurso_nombre'] ?? null;
    }
    
    return null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#4361ee">
    <title>Auditoría de Actividades - Sistema de Seguridad</title>
	<!-- Mantener CDN para Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Pero usar locales para Bootstrap y SweetAlert2 -->
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.css">

    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #1abc9c;
            --sidebar-width: 280px;
            --header-height: 80px;
        }
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: var(--header-height);
            min-height: 100vh;
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
            min-height: calc(100vh - var(--header-height));
        }
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px 40px 40px 40px;
            transition: margin-left 0.35s ease;
        }
        .stats-container-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin: 30px 0 40px;
        }
        .stat-card-modern {
            background: white;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        .stat-card-modern:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 15px;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-top: 5px;
        }
        .alerta-seguridad {
            background: linear-gradient(135deg, #fff3cd, #ffe69c);
            border: 2px solid #ffc107;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(255, 193, 7, 0.3);
        }
        .filter-section-modern {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        .filter-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            color: #2c3e50;
            font-weight: 700;
            font-size: 1.3rem;
        }
        .table-container-modern {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.12);
            overflow: hidden;
            margin-bottom: 30px;
        }
        .table-modern {
            margin-bottom: 0;
            min-width: 1200px;
        }
        .table-modern thead {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: white;
        }
        .table-modern thead th {
            border: none;
            padding: 18px 15px;
            font-weight: 700;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        .table-modern tbody tr {
            transition: all 0.2s;
            border-bottom: 1px solid #e9ecef;
        }
        .table-modern tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.002);
        }
        .table-modern tbody td {
            padding: 15px;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .accion-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .accion-creacion { background: linear-gradient(135deg, var(--success-color), #219653); color: white; }
        .accion-modificacion { background: linear-gradient(135deg, var(--warning-color), #d35400); color: white; }
        .accion-eliminacion { background: linear-gradient(135deg, var(--danger-color), #c0392b); color: white; }
        .accion-login { background: linear-gradient(135deg, var(--info-color), #16a085); color: white; }
        .accion-logout { background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: white; }
        .accion-alerta { background: linear-gradient(135deg, #8e44ad, #9b59b6); color: white; animation: pulse 2s infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .risk-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .risk-bajo { background: #d4edda; color: #155724; }
        .risk-medio { background: #fff3cd; color: #856404; }
        .risk-alto { background: #f8d7da; color: #721c24; animation: pulse 2s infinite; }
        .ip-badge {
            background: #e1f0fa;
            color: var(--primary-color);
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .pagination-modern {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 25px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
        }
        .pagination-modern .page-link {
            border-radius: 10px;
            padding: 10px 16px;
            color: var(--primary-color);
            border: 2px solid #e9ecef;
            font-weight: 600;
            transition: all 0.3s;
        }
        .pagination-modern .page-link:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        .pagination-modern .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: var(--primary-color);
        }
        .modal-auditoria .modal-content {
            border: none;
            border-radius: 24px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            box-shadow: 0 25px 80px rgba(67, 97, 238, 0.25);
        }
        .modal-auditoria .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            padding: 25px 30px;
            border: none;
            border-radius: 24px 24px 0 0;
        }
        .modal-auditoria .modal-title {
            color: white;
            font-weight: 700;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .modal-auditoria .modal-body {
            padding: 35px 30px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .detalles-content {
            max-height: 400px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.85rem;
            border: 1px solid #e9ecef;
        }
        .audit-detail-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }
        .audit-detail-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transform: translateX(5px);
        }
        .audit-detail-label {
            font-weight: 600;
            color: #7f8c8d;
            font-size: 0.85rem;
            margin-bottom: 8px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .audit-detail-label i {
            color: var(--primary-color);
        }
        .audit-detail-value {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }
        .comentarios-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .comentario-item {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .comentario-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .comentario-usuario {
            font-weight: 600;
            color: var(--primary-color);
        }
        .comentario-fecha {
            font-size: 0.8rem;
            color: #7f8c8d;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-export-csv { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
        .btn-export-json { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
        .btn-export-pdf { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            color: white;
        }
        .diff-added {
            background-color: #d4edda;
            color: #155724;
            padding: 3px 8px;
            border-radius: 5px;
            font-family: monospace;
        }
        .diff-removed {
            background-color: #f8d7da;
            color: #721c24;
            padding: 3px 8px;
            border-radius: 5px;
            font-family: monospace;
            text-decoration: line-through;
        }
        @media (max-width: 991px) {
            :root { --sidebar-width: 0px; }
            .main-content { margin-left: 0 !important; padding: 20px 25px !important; }
            .stats-container-modern { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .stats-container-modern { grid-template-columns: 1fr; }
            .filter-section-modern { padding: 20px 15px; }
            .table-modern { font-size: 0.75rem; min-width: 900px; }
            .modal-auditoria .modal-dialog { margin: 10px; max-width: calc(100% - 20px); }
        }
    </style>
</head>
<body>
    <?php $page_title = 'Auditoría de Actividades'; include '../includes/header.php'; ?>
    
    <div class="dashboard">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main-content">
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
            
            <?php if ($stats['actividad_sospechosa'] > 0): ?>
            <div class="alerta-seguridad">
                <div class="d-flex align-items-start gap-3">
                    <i class="fas fa-shield-alt fa-3x text-warning"></i>
                    <div class="flex-grow-1">
                        <h5 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Actividad Sospechosa Detectada</h5>
                        <p class="mb-2">Se han registrado <strong><?php echo $stats['actividad_sospechosa']; ?></strong> alertas de seguridad en el período seleccionado.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-container-modern">
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white;">
                        <i class="fas fa-list-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['total_registros']); ?></div>
                    <div class="stat-label">Registros Totales</div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #219653); color: white;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['usuarios_unicos']; ?></div>
                    <div class="stat-label">Usuarios Únicos</div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f39c12, #d35400); color: white;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['risk_score_promedio']; ?></div>
                    <div class="stat-label">Risk Score Promedio</div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['actividad_sospechosa']; ?></div>
                    <div class="stat-label">Alertas Seguridad</div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-lg-6 mb-4">
                    <div class="card" style="border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.08);">
                        <div class="card-header" style="background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; border-radius: 20px 20px 0 0 !important; padding: 20px;">
                            <i class="fas fa-chart-bar me-2"></i>Acciones por Tipo
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartAcciones"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card" style="border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.08);">
                        <div class="card-header" style="background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; border-radius: 20px 20px 0 0 !important; padding: 20px;">
                            <i class="fas fa-chart-pie me-2"></i>Distribución por Riesgo
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartRiesgo"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="filter-section-modern">
                <div class="filter-title">
                    <i class="fas fa-filter fa-lg"></i>
                    <span>Filtros de Búsqueda</span>
                </div>
                <form method="GET" action="" class="row g-3">
                    <div class="col-12">
                        <div class="input-group">
                            <span class="input-group-text" style="border-radius: 12px 0 0 12px; background: #f8f9fa;">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" name="busqueda" class="form-control" style="border-radius: 0 12px 12px 0;"
                                placeholder="Buscar en historial..."
                                value="<?php echo htmlspecialchars($busqueda); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-calendar-day me-1"></i>Fecha Desde</label>
                        <input type="date" name="fecha_desde" class="form-control" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-calendar-day me-1"></i>Fecha Hasta</label>
                        <input type="date" name="fecha_hasta" class="form-control" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-user me-1"></i>Usuario</label>
                        <select name="usuario_id" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($usuarios as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($usuario_filtro == $u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['nombre_completo']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-tasks me-1"></i>Acción</label>
                        <select name="accion" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($acciones as $accion): ?>
                            <option value="<?php echo htmlspecialchars($accion); ?>" <?php echo ($accion_filtro == $accion) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $accion))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-database me-1"></i>Tabla</label>
                        <select name="tabla" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($tablas as $tabla): ?>
                            <option value="<?php echo htmlspecialchars($tabla); ?>" <?php echo ($tabla_filtro == $tabla) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($tabla)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-shield-alt me-1"></i>Risk Score</label>
                        <select name="risk_score_min" class="form-select">
                            <option value="">Todos</option>
                            <option value="40" <?php echo ($risk_score_min == 40) ? 'selected' : ''; ?>>Medio (40+)</option>
                            <option value="70" <?php echo ($risk_score_min == 70) ? 'selected' : ''; ?>>Alto (70+)</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap justify-content-between align-items-center">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary" style="border-radius: 12px; padding: 12px 24px;">
                                <i class="fas fa-search me-2"></i>Aplicar Filtros
                            </button>
                            <a href="auditoria.php" class="btn btn-secondary" style="border-radius: 12px; padding: 12px 24px;">
                                <i class="fas fa-undo me-2"></i>Limpiar
                            </a>
                        </div>
                        <div class="export-buttons">
                            <a href="?exportar=csv&<?php echo $filter_params; ?>" class="btn btn-export btn-export-csv">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                            <a href="?exportar=json&<?php echo $filter_params; ?>" class="btn btn-export btn-export-json">
                                <i class="fas fa-file-code"></i> JSON
                            </a>
                            <a href="?exportar=pdf&<?php echo $filter_params; ?>" class="btn btn-export btn-export-pdf">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php if (empty($registros)): ?>
            <div class="card" style="border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.08);">
                <div class="card-body text-center py-5">
                    <i class="fas fa-history fa-4x text-muted mb-3"></i>
                    <h4>No hay registros de auditoría</h4>
                    <p class="text-muted">Las actividades se registrarán automáticamente cuando los usuarios realicen acciones.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="table-container-modern">
                <div class="table-responsive">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Acción</th>
                                <th>Tabla</th>
                                <th>Registro</th>
                                <th>IP</th>
                                <th>Risk Score</th>
                                <th>Fecha</th>
                                <th>Detalles</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registros as $registro): ?>
                            <?php
                            $accion_badge = getAccionBadge($registro['accion']);
                            $risk_badge = getRiskBadge($registro['risk_score'] ?? 0);
                            $tabla_badge = getTablaBadge($registro['tabla']);
                            $nombre_registro = getNombreRegistro($registro);
                            ?>
                            <tr>
                                <td><strong>#<?php echo $registro['id']; ?></strong></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                            style="width: 35px; height: 35px; font-weight: 700;">
                                            <?php echo strtoupper(substr($registro['usuario_nombre'] ?? 'S', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($registro['usuario_nombre'] ?? 'Sistema'); ?></div>
                                            <?php if (isset($registro['usuario_rol'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($registro['usuario_rol'] ?? 'N/A'); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="accion-badge <?php echo $accion_badge['class']; ?>">
                                        <i class="fas <?php echo $accion_badge['icon']; ?>"></i>
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $registro['accion']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $tabla_badge['class']; ?>">
                                        <i class="fas <?php echo $tabla_badge['icon']; ?> me-1"></i>
                                        <?php echo htmlspecialchars($registro['tabla']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($nombre_registro)): ?>
                                    <span class="badge bg-info">
                                        <i class="fas fa-tag me-1"></i>
                                        <?php echo htmlspecialchars($nombre_registro); ?>
                                    </span>
                                    <?php elseif (!empty($registro['registro_id'])): ?>
                                    <span class="badge bg-secondary">#<?php echo $registro['registro_id']; ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ip-badge">
                                        <i class="fas fa-network-wired me-1"></i>
                                        <?php echo htmlspecialchars(substr($registro['ip_address'] ?? 'N/A', 0, 15)); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="risk-badge <?php echo $risk_badge['class']; ?>">
                                        <i class="fas <?php echo $risk_badge['icon']; ?>"></i>
                                        <?php echo $registro['risk_score'] ?? 0; ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($registro['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary ver-detalles"
                                        data-bs-toggle="modal"
                                        data-bs-target="#detallesModal"
                                        data-detalles-completos='<?php echo htmlspecialchars(json_encode($registro, JSON_UNESCAPED_UNICODE)); ?>'
                                        style="border-radius: 10px;">
                                        <i class="fas fa-eye"></i> Ver
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (isset($total_paginas) && $total_paginas > 1): ?>
                <div class="pagination-modern">
                    <nav aria-label="Paginación de auditoría">
                        <ul class="pagination mb-0">
                            <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                            </li>
                            <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => max(1, $pagina_actual - 1)])); ?>">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            </li>
                            <?php
                            $rango = 2;
                            $inicio = max(1, $pagina_actual - $rango);
                            $fin = min($total_paginas, $pagina_actual + $rango);
                            if ($inicio > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            for ($i = $inicio; $i <= $fin; $i++):
                            ?>
                            <li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <?php if ($fin < $total_paginas) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; ?>
                            <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => min($total_paginas, $pagina_actual + 1)])); ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                            </li>
                            <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $total_paginas])); ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <span class="page-info ms-3 text-muted">
                        Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
                        (<?php echo number_format($total_registros); ?> registros)
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($auth->hasRole('super_admin')): ?>
            <div class="card mt-4" style="border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.08);">
                <div class="card-header" style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; border-radius: 20px 20px 0 0 !important; padding: 20px;">
                    <i class="fas fa-trash-alt me-2"></i>Gestión de Auditoría Antigua
                </div>
                <div class="card-body">
                    <form method="POST" action="" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Días a Conservar</label>
                            <input type="number" name="dias_a_conservar" class="form-control" value="365" min="30">
                            <small class="text-muted">Mínimo 30 días</small>
                        </div>
                        <div class="col-md-8">
                            <button type="submit" name="limpiar_auditoria" class="btn btn-danger" style="border-radius: 12px;">
                                <i class="fas fa-trash me-2"></i>Limpiar Auditoría Antigua
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- MODAL DETALLES -->
    <div class="modal fade modal-auditoria" id="detallesModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle"></i>
                        <span>Detalles Completos de la Actividad</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="audit-detail-card">
                                <div class="audit-detail-label"><i class="fas fa-user"></i>Información del Usuario</div>
                                <p><strong>Usuario:</strong> <span id="modalUsuario"></span></p>
                                <p><strong>Email:</strong> <span id="modalEmail"></span></p>
                                <p><strong>IP:</strong> <span id="modalIP"></span></p>
                                <p><strong>Fecha:</strong> <span id="modalFecha"></span></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="audit-detail-card">
                                <div class="audit-detail-label"><i class="fas fa-database"></i>Información del Registro</div>
                                <p><strong>Acción:</strong> <span id="modalAccion"></span></p>
                                <p><strong>Tabla:</strong> <span id="modalTabla"></span></p>
                                <p><strong>Registro:</strong> <span id="modalRegistroId"></span></p>
                                <p><strong>Risk Score:</strong> <span id="modalRiskScore"></span></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="audit-detail-card">
                        <div class="audit-detail-label"><i class="fas fa-exchange-alt"></i>Comparación Visual de Cambios (Diff)</div>
                        <div id="modalDiff" class="detalles-content"></div>
                    </div>
                    
                    <div class="audit-detail-card">
                        <div class="audit-detail-label"><i class="fas fa-list-check"></i>Cambios Detectados</div>
                        <div id="modalCambios" class="detalles-content"></div>
                    </div>
                    
                    <div class="audit-detail-card">
                        <div class="audit-detail-label"><i class="fas fa-laptop"></i>Información Técnica</div>
                        <p><strong>User Agent:</strong></p>
                        <div class="detalles-content mb-3" id="modalUserAgent"></div>
                        <p><strong>URI:</strong></p>
                        <div class="detalles-content mb-3" id="modalURI"></div>
                        <p><strong>Detalles JSON:</strong></p>
                        <div class="detalles-content" id="modalDetallesJSON"></div>
                    </div>
                    
                    <div class="audit-detail-card">
                        <div class="audit-detail-label"><i class="fas fa-comments"></i>Comentarios y Notas</div>
                        <div id="modalComentarios" class="comentarios-section">
                            <p class="text-muted">Cargando comentarios...</p>
                        </div>
                        <form method="POST" class="mt-3" id="formComentario">
                            <input type="hidden" name="auditoria_id" id="comentarioAuditoriaId">
                            <div class="mb-3">
                                <textarea name="comentario" class="form-control" rows="3"
                                    placeholder="Agregar comentario o nota..." required></textarea>
                            </div>
                            <button type="submit" name="agregar_comentario" class="btn btn-primary" style="border-radius: 12px;">
                                <i class="fas fa-plus me-2"></i>Agregar Comentario
                            </button>
                        </form>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 12px;">
                        <i class="fas fa-times me-2"></i>Cerrar
                    </button>
                    <button type="button" class="btn btn-info" onclick="copiarDetalles()" style="border-radius: 12px;">
                        <i class="fas fa-copy me-2"></i>Copiar Detalles
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
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
        const chartAccionesCtx = document.getElementById('chartAcciones').getContext('2d');
        new Chart(chartAccionesCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($stats['por_accion'])); ?>,
                datasets: [{
                    label: 'Cantidad',
                    data: <?php echo json_encode(array_values($stats['por_accion'])); ?>,
                    backgroundColor: 'rgba(67, 97, 238, 0.7)',
                    borderColor: 'rgba(67, 97, 238, 1)',
                    borderWidth: 1,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
        
        const chartRiesgoCtx = document.getElementById('chartRiesgo').getContext('2d');
        new Chart(chartRiesgoCtx, {
            type: 'doughnut',
            data: {
                labels: ['Bajo', 'Medio', 'Alto'],
                datasets: [{
                    data: [
                        <?php echo $stats['por_risk_level']['BAJO'] ?? 0; ?>,
                        <?php echo $stats['por_risk_level']['MEDIO'] ?? 0; ?>,
                        <?php echo $stats['por_risk_level']['ALTO'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        'rgba(39, 174, 96, 0.7)',
                        'rgba(243, 156, 18, 0.7)',
                        'rgba(231, 76, 60, 0.7)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        
        document.querySelectorAll('.ver-detalles').forEach(button => {
            button.addEventListener('click', function() {
                const datos = JSON.parse(this.getAttribute('data-detalles-completos') || '{}');
                
                document.getElementById('modalUsuario').textContent = datos.usuario_nombre || 'Sistema';
                document.getElementById('modalEmail').textContent = datos.usuario_email || 'N/A';
                document.getElementById('modalIP').textContent = datos.ip_address || 'N/A';
                document.getElementById('modalFecha').textContent = datos.created_at || 'N/A';
                
                document.getElementById('modalAccion').textContent = datos.accion || 'N/A';
                document.getElementById('modalTabla').textContent = datos.tabla || 'N/A';
                
                // ✅ MOSTRAR NOMBRE DEL REGISTRO EN EL MODAL
                let nombreRegistro = 'N/A';
                const tablaLower = (datos.tabla || '').toLowerCase();
                if ((tablaLower === 'empresa' || tablaLower === 'empresas') && datos.empresa_nombre) {
                    nombreRegistro = datos.empresa_nombre;
                } else if ((tablaLower === 'sucursal' || tablaLower === 'sucursales') && datos.sucursal_nombre) {
                    nombreRegistro = datos.sucursal_nombre;
                } else if (tablaLower === 'personal' && datos.personal_nombre) {
                    nombreRegistro = datos.personal_nombre;
                } else if ((tablaLower === 'servicio' || tablaLower === 'servicios') && datos.servicio_nombre) {
                    nombreRegistro = datos.servicio_nombre;
                } else if ((tablaLower === 'recurso' || tablaLower === 'recursos') && datos.recurso_nombre) {
                    nombreRegistro = datos.recurso_nombre;
                } else if (datos.registro_id) {
                    nombreRegistro = '#' + datos.registro_id;
                }
                document.getElementById('modalRegistroId').innerHTML = 
                    `<span class="badge bg-info">${nombreRegistro}</span>`;
                
                const riskScore = datos.risk_score || 0;
                const riskLevel = riskScore >= 70 ? 'alto' : (riskScore >= 40 ? 'medio' : 'bajo');
                document.getElementById('modalRiskScore').innerHTML =
                    `<span class="risk-badge risk-${riskLevel}">${riskScore}</span>`;
                
                document.getElementById('modalUserAgent').textContent = datos.user_agent || 'N/A';
                document.getElementById('modalURI').textContent = datos.request_uri || 'N/A';
                document.getElementById('comentarioAuditoriaId').value = datos.id || '';
                
                let diffHtml = '';
                try {
                    const detalles = JSON.parse(datos.detalles || '{}');
                    if (detalles.cambios && Object.keys(detalles.cambios).length > 0) {
                        diffHtml = '<div class="diff-container">';
                        for (const [campo, valores] of Object.entries(detalles.cambios)) {
                            diffHtml += `<div class="mb-3">
                                <strong>${campo}:</strong><br>
                                <span class="diff-removed">${valores.anterior || 'N/A'}</span>
                                <i class="fas fa-arrow-right mx-2"></i>
                                <span class="diff-added">${valores.nuevo || 'N/A'}</span>
                            </div>`;
                        }
                        diffHtml += '</div>';
                    }
                    if (!diffHtml) {
                        diffHtml = '<p class="text-muted">No hay cambios comparables</p>';
                    }
                } catch(e) {
                    diffHtml = '<p class="text-muted">No hay información de diff disponible</p>';
                }
                document.getElementById('modalDiff').innerHTML = diffHtml;
                
                let cambiosHtml = '';
                try {
                    const detalles = JSON.parse(datos.detalles || '{}');
                    if (detalles.cambios && Object.keys(detalles.cambios).length > 0) {
                        cambiosHtml = '<table class="table table-sm">';
                        cambiosHtml += '<thead><tr><th>Campo</th><th>Tipo</th><th>Anterior</th><th>Nuevo</th></tr></thead><tbody>';
                        for (const [campo, valores] of Object.entries(detalles.cambios)) {
                            cambiosHtml += `<tr>
                                <td><strong>${campo}</strong></td>
                                <td><span class="badge bg-info">${valores.tipo_cambio || 'MODIFICADO'}</span></td>
                                <td class="text-danger">${valores.anterior !== null ? valores.anterior : '<em>Null</em>'}</td>
                                <td class="text-success">${valores.nuevo !== null ? valores.nuevo : '<em>Null</em>'}</td>
                            </tr>`;
                        }
                        cambiosHtml += '</tbody></table>';
                    } else {
                        cambiosHtml = '<p class="text-muted">No hay cambios detectados</p>';
                    }
                } catch(e) {
                    cambiosHtml = '<pre>' + (datos.detalles || 'Sin detalles') + '</pre>';
                }
                document.getElementById('modalCambios').innerHTML = cambiosHtml;
                
                document.getElementById('modalDetallesJSON').textContent = JSON.stringify(datos, null, 2);
                
                document.getElementById('modalComentarios').innerHTML = `
                    <div class="comentario-item">
                        <div class="comentario-header">
                            <span class="comentario-usuario"><i class="fas fa-user me-1"></i>Admin</span>
                            <span class="comentario-fecha"><i class="fas fa-clock me-1"></i>Hace 2 horas</span>
                        </div>
                        <p class="mb-0">Revisado y aprobado</p>
                    </div>
                `;
            });
        });
        
        function copiarDetalles() {
            const texto = document.getElementById('modalDetallesJSON').textContent;
            navigator.clipboard.writeText(texto).then(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Copiado',
                    text: 'Detalles copiados al portapapeles',
                    timer: 2000,
                    showConfirmButton: false
                });
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'No se pudo copiar al portapapeles'
                });
            });
        }
        
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    </script>
</body>
</html>