<?php
// ============================================================================
// SISTEMA DE GESTIÓN DE RECURSOS - EMPRESA
// Con Auditoría Completa Integrada
// ============================================================================
// ? MANEJO AJAX - DEBE IR ANTES DE CUALQUIER OUTPUT
if (isset($_GET['action'])) {
    session_start();
    ob_clean();
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    require_once '../config/database.php';
    require_once '../config/auth.php';
    require_once '../config/auditoria_func.php';
    if (!$auth->isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }
    $action = $_GET['action'];
    $conn = getDBConnection();
    $user = $auth->getCurrentUser();
    $empresa_id = $user['empresa_id'] ?? 0;
    // === GET SUCURSALES (Solo de su empresa) ===
    if ($action === 'get_sucursales') {
        try {
            if ($empresa_id <= 0) {
                echo json_encode([]);
                exit;
            }
            $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = :empresa_id AND activa = TRUE ORDER BY nombre");
            $stmt->execute(['empresa_id' => $empresa_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        } catch(PDOException $e) {
            error_log("Error get_sucursales: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al cargar sucursales']);
        }
        exit;
    }
    // === GET PERSONAL ===
    if ($action === 'get_personal' && isset($_GET['sucursal_id'])) {
        try {
            $sucursal_id = (int)$_GET['sucursal_id'];
            if ($sucursal_id <= 0) {
                echo json_encode([]);
                exit;
            }
            $stmt = $conn->prepare("SELECT id FROM sucursales WHERE id = :sucursal_id AND empresa_id = :empresa_id");
            $stmt->execute(['sucursal_id' => $sucursal_id, 'empresa_id' => $empresa_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['error' => 'Sucursal no pertenece a su empresa']);
                exit;
            }
            $stmt = $conn->prepare("
            SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo, dni, cargo
            FROM personal WHERE sucursal_id = :sucursal_id AND activo = TRUE
            ORDER BY apellido, nombre
            ");
            $stmt->execute(['sucursal_id' => $sucursal_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        } catch(PDOException $e) {
            error_log("Error get_personal: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al cargar personal']);
        }
        exit;
    }
    // === GET RECURSO DETAILS (Solo propios) ===
    if ($action === 'get_recurso_details' && isset($_GET['id'])) {
        try {
            $id = (int)$_GET['id'];
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'ID invalido']);
                exit;
            }
            logAuditoria($conn, 'RECURSOS_DETALLES_VISTOS', 'recursos_sucursal', $id, [
                'usuario_id' => $user['id'],
                'empresa_id' => $empresa_id,
                'via' => 'ajax',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
            ], $user['id']);
            $stmt = $conn->prepare("
            SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
            CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo,
            rs.estado, rs.motivo_rechazo, rs.fecha_aprobacion, rs.archivo_pdf
            FROM recursos_sucursal rs
            LEFT JOIN empresas e ON rs.empresa_id = e.id
            LEFT JOIN sucursales s ON rs.sucursal_id = s.id
            LEFT JOIN personal p ON rs.personal_id = p.id
            WHERE rs.id = :id AND rs.empresa_id = :empresa_id
            ");
            $stmt->execute(['id' => $id, 'empresa_id' => $empresa_id]);
            $recurso = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$recurso) {
                http_response_code(404);
                echo json_encode(['error' => 'Asignacion de recursos no encontrada']);
                exit;
            }
            $stmt = $conn->prepare("
            SELECT tipo_recurso, atributos
            FROM recursos_items
            WHERE recursos_sucursal_id = :id
            ORDER BY tipo_recurso, id
            ");
            $stmt->execute(['id' => $id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items_por_tipo = [
                'chaleco' => [],
                'equipo_comunicacion' => [],
                'armamento' => [],
                'vehiculo' => [],
                'equipos_video_vigilancia' => []
            ];
            foreach ($items as $item) {
                $tipo = $item['tipo_recurso'];
                $atributos = json_decode($item['atributos'], true);
                if (isset($items_por_tipo[$tipo])) {
                    $items_por_tipo[$tipo][] = $atributos;
                }
            }
            $response = [
                'id' => $recurso['id'],
                'empresa_nombre' => $recurso['empresa_nombre'] ?? 'Sin empresa',
                'sucursal_nombre' => $recurso['sucursal_nombre'] ?? 'Sin sucursal',
                'personal_nombre' => $recurso['personal_nombre'] ?? null,
                'dni' => $recurso['dni'] ?? null,
                'cargo' => $recurso['cargo'] ?? null,
                'observaciones' => $recurso['observaciones'] ?? '',
                'estado' => $recurso['estado'] ?? 'pendiente',
                'motivo_rechazo' => $recurso['motivo_rechazo'] ?? null,
                'fecha_aprobacion' => $recurso['fecha_aprobacion'] ?? null,
                'archivo_pdf' => $recurso['archivo_pdf'] ?? null,
                'created_at' => $recurso['created_at'] ?? null,
                'updated_at' => $recurso['updated_at'] ?? null,
                'items' => $items_por_tipo
            ];
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch(PDOException $e) {
            error_log("Error get_recurso_details: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al cargar los datos']);
        }
        exit;
    }
}
// ==================== INICIO VISTA NORMAL ====================
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// Funcion para subir PDF
function uploadPDF($file, $resource_id) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $allowed_types = ['application/pdf'];
    $max_size = 5 * 1024 * 1024;
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Solo se permiten archivos PDF');
    }
    if ($file['size'] > $max_size) {
        throw new Exception('El archivo no puede superar los 5MB');
    }
    $upload_dir = '../uploads/recursos/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = 'recurso_' . $resource_id . '_' . time() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return 'uploads/recursos/' . $new_filename;
    }
    throw new Exception('Error al subir el archivo');
}
// Funcion para eliminar PDF
function deletePDF($file_path) {
    if ($file_path && file_exists('../' . $file_path)) {
        unlink('../' . $file_path);
    }
}
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
}
if (!$auth->isLoggedIn() || !$auth->hasRole('empresa')) {
    header('Location: ../login.php');
    exit;
}
$current_page = 'recursos_empresa';
$user = $auth->getCurrentUser();
$empresa_id = $user['empresa_id'] ?? 0;
if ($empresa_id <= 0) {
    $_SESSION['error'] = 'No tiene una empresa asociada. Contacte al administrador.';
    header('Location: dashboard.php');
    exit;
}
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ==================== ? VERIFICAR TRÁMITES URGENTES ====================
$hay_urgencia = false;
try {
    $stmt = $conn->prepare("
    SELECT COUNT(*) as urgentes
    FROM tramites_empresa
    WHERE empresa_id = :empresa_id
    AND urgente = 1
    AND estado IN ('en_tramite', 'pendiente')
    ");
    $stmt->execute([':empresa_id' => $empresa_id]);
    $urgentes = $stmt->fetch(PDO::FETCH_ASSOC);
    $hay_urgencia = ($urgentes['urgentes'] > 0);
} catch(PDOException $e) {
    // Silencioso si la tabla no existe
}
// ==================== FILTROS DE BUSQUEDA ====================
$search_sucursal = $_GET['search_sucursal'] ?? '';
$search_personal = $_GET['search_personal'] ?? '';
$search_tipo_recurso = $_GET['search_tipo_recurso'] ?? 'todos';
$search_estado = $_GET['search_estado'] ?? 'todos';
// ==================== PAGINACION Y ORDENAMIENTO ====================
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;
$allowed_order_columns = ['sucursal_nombre', 'personal_nombre', 'total_items', 'created_at', 'estado'];
$order_column = isset($_GET['order']) && in_array($_GET['order'], $allowed_order_columns) ? $_GET['order'] : 'created_at';
$order_direction = isset($_GET['direction']) && strtoupper($_GET['direction']) === 'DESC' ? 'DESC' : 'ASC';
// ==================== OBTENER DATOS DE LA EMPRESA ====================
$stmt = $conn->prepare("SELECT id, nombre, cuit FROM empresas WHERE id = ? AND activo = TRUE");
$stmt->execute([$empresa_id]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empresa) {
    $_SESSION['error'] = 'Empresa no encontrada o inactiva.';
    header('Location: dashboard.php');
    exit;
}
$sucursales = [];
$personales = [];
// ==================== GUARDAR/ACTUALIZAR RECURSOS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_recursos'])) {
    try {
        $sucursal_id = (int)$_POST['sucursal_id'];
        $personal_id = !empty($_POST['personal_id']) ? (int)$_POST['personal_id'] : null;
        $observaciones = sanitizeInput($_POST['observaciones'] ?? '');
        $stmt = $conn->prepare("SELECT id FROM sucursales WHERE id = ? AND empresa_id = ? AND activa = TRUE");
        $stmt->execute([$sucursal_id, $empresa_id]);
        if (!$stmt->fetch()) {
            throw new Exception('La sucursal no pertenece a su empresa');
        }
        if ($personal_id) {
            $stmt = $conn->prepare("SELECT id FROM personal WHERE id = ? AND sucursal_id = ? AND activo = TRUE");
            $stmt->execute([$personal_id, $sucursal_id]);
            if (!$stmt->fetch()) {
                throw new Exception('El personal no pertenece a la sucursal seleccionada');
            }
        }
        $recurso_id = isset($_POST['recurso_id']) ? (int)$_POST['recurso_id'] : null;
        $archivo_pdf_path = null;
        $eliminar_pdf = isset($_POST['eliminar_pdf']) && $_POST['eliminar_pdf'] == '1';
        $es_actualizacion = false;
        if ($recurso_id) {
            $es_actualizacion = true;
            $stmt = $conn->prepare("SELECT archivo_pdf FROM recursos_sucursal WHERE id = ?");
            $stmt->execute([$recurso_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            $archivo_pdf_path = $existing['archivo_pdf'] ?? null;
            if ($eliminar_pdf && $archivo_pdf_path) {
                deletePDF($archivo_pdf_path);
                logAuditoria($conn, 'RECURSOS_PDF_ELIMINADO', 'recursos_sucursal', $recurso_id, [
                    'archivo_anterior' => $archivo_pdf_path,
                    'usuario_id' => $user['id'],
                    'empresa_id' => $empresa_id
                ], $user['id']);
                $archivo_pdf_path = null;
            }
            $stmt = $conn->prepare("UPDATE recursos_sucursal SET observaciones = ?, estado = 'pendiente', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$observaciones, $recurso_id]);
            $recursos_sucursal_id = $recurso_id;
            $stmt = $conn->prepare("DELETE FROM recursos_items WHERE recursos_sucursal_id = ?");
            $stmt->execute([$recursos_sucursal_id]);
            logAuditoria($conn, 'RECURSOS_SOLICITUD_ACTUALIZADA', 'recursos_sucursal', $recurso_id, [
                'observaciones' => substr($observaciones, 0, 200),
                'estado_anterior' => 'pendiente',
                'usuario_id' => $user['id'],
                'empresa_id' => $empresa_id,
                'sucursal_id' => $sucursal_id,
                'personal_id' => $personal_id
            ], $user['id']);
            $mensaje = 'Solicitud de actualizacion enviada para aprobacion del administrador';
        } else {
            $stmt = $conn->prepare("INSERT INTO recursos_sucursal (empresa_id, sucursal_id, personal_id, observaciones, estado) VALUES (?, ?, ?, ?, 'pendiente')");
            $stmt->execute([$empresa_id, $sucursal_id, $personal_id, $observaciones]);
            $recursos_sucursal_id = $conn->lastInsertId();
            logAuditoria($conn, 'RECURSOS_SOLICITUD_CREADA', 'recursos_sucursal', $recursos_sucursal_id, [
                'empresa_id' => $empresa_id,
                'sucursal_id' => $sucursal_id,
                'personal_id' => $personal_id,
                'observaciones' => substr($observaciones, 0, 200),
                'usuario_id' => $user['id']
            ], $user['id']);
            $mensaje = 'Solicitud de recursos enviada para aprobacion del administrador';
        }
        if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
            if ($archivo_pdf_path) {
                deletePDF($archivo_pdf_path);
            }
            $archivo_pdf_path = uploadPDF($_FILES['archivo_pdf'], $recursos_sucursal_id);
            $stmt = $conn->prepare("UPDATE recursos_sucursal SET archivo_pdf = ? WHERE id = ?");
            $stmt->execute([$archivo_pdf_path, $recursos_sucursal_id]);
            logAuditoria($conn, 'RECURSOS_PDF_SUBIDO', 'recursos_sucursal', $recursos_sucursal_id, [
                'archivo_path' => $archivo_pdf_path,
                'tamaño_archivo' => $_FILES['archivo_pdf']['size'],
                'nombre_archivo' => $_FILES['archivo_pdf']['name'],
                'usuario_id' => $user['id']
            ], $user['id']);
        } elseif ($eliminar_pdf) {
            $stmt = $conn->prepare("UPDATE recursos_sucursal SET archivo_pdf = NULL WHERE id = ?");
            $stmt->execute([$recursos_sucursal_id]);
        }
        $stmt = $conn->prepare("
        INSERT INTO notificaciones_recursos (recursos_sucursal_id, usuario_id, tipo, mensaje, created_at)
        SELECT ?, id, 'pendiente', 'Nueva solicitud de recursos pendiente de aprobacion', NOW()
        FROM usuarios WHERE rol = 'administrador'
        ");
        $stmt->execute([$recursos_sucursal_id]);
        $tipos_recursos = ['chaleco', 'equipo_comunicacion', 'armamento', 'vehiculo', 'equipos_video_vigilancia'];
        $items_count = 0;
        $items_detalle = [];
        foreach ($tipos_recursos as $tipo) {
            if (isset($_POST["items_$tipo"]) && is_array($_POST["items_$tipo"])) {
                foreach ($_POST["items_$tipo"] as $item) {
                    if (!empty(array_filter($item))) {
                        $atributos_json = json_encode($item, JSON_UNESCAPED_UNICODE);
                        $stmt = $conn->prepare("INSERT INTO recursos_items (recursos_sucursal_id, tipo_recurso, atributos) VALUES (?, ?, ?)");
                        $stmt->execute([$recursos_sucursal_id, $tipo, $atributos_json]);
                        $items_count++;
                        $items_detalle[$tipo][] = $item;
                    }
                }
            }
        }
        if ($items_count > 0) {
            logAuditoria($conn, 'RECURSOS_ITEMS_AGRAGADOS', 'recursos_items', $recursos_sucursal_id, [
                'total_items' => $items_count,
                'tipos_recursos' => array_keys($items_detalle),
                'detalle_resumen' => array_map(function($tipo, $items) {
                    return "$tipo: " . count($items) . " items";
                }, array_keys($items_detalle), $items_detalle),
                'usuario_id' => $user['id']
            ], $user['id']);
        }
        $_SESSION['success'] = $mensaje;
        header('Location: recursos_empresa.php');
        exit;
    } catch(Exception $e) {
        logAuditoria($conn, 'RECURSOS_ERROR_OPERACION', 'recursos_sucursal', $recurso_id ?? null, [
            'error' => $e->getMessage(),
            'accion' => $es_actualizacion ? 'actualizacion' : 'creacion',
            'usuario_id' => $user['id'],
            'empresa_id' => $empresa_id
        ], $user['id']);
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        $form_data = $_POST;
    }
}
// ==================== OBTENER SUCURSALES Y PERSONALES PARA EDICION ====================
if (isset($_POST['empresa_id']) && !empty($_POST['empresa_id'])) {
    $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activa = TRUE ORDER BY nombre");
    $stmt->execute([(int)$_POST['empresa_id']]);
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if (isset($_POST['sucursal_id']) && !empty($_POST['sucursal_id'])) {
    $stmt = $conn->prepare("
    SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo, dni, cargo
    FROM personal
    WHERE sucursal_id = ? AND activo = TRUE
    ORDER BY apellido, nombre
    ");
    $stmt->execute([(int)$_POST['sucursal_id']]);
    $personales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$recurso_edit = null;
$items_edit = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("
    SELECT rs.*, e.nombre as empresa_nombre, s.nombre as sucursal_nombre,
    CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo
    FROM recursos_sucursal rs
    LEFT JOIN empresas e ON rs.empresa_id = e.id
    LEFT JOIN sucursales s ON rs.sucursal_id = s.id
    LEFT JOIN personal p ON rs.personal_id = p.id
    WHERE rs.id = ? AND rs.empresa_id = ?
    ");
    $stmt->execute([$edit_id, $empresa_id]);
    $recurso_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($recurso_edit) {
        $stmt = $conn->prepare("SELECT * FROM recursos_items WHERE recursos_sucursal_id = ? ORDER BY tipo_recurso, id");
        $stmt->execute([$edit_id]);
        $items_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items_db as $item) {
            $items_edit[$item['tipo_recurso']][] = json_decode($item['atributos'], true);
        }
        $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activa = TRUE ORDER BY nombre");
        $stmt->execute([$empresa_id]);
        $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $conn->prepare("
        SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo, dni, cargo
        FROM personal
        WHERE sucursal_id = ? AND activo = TRUE
        ORDER BY apellido, nombre
        ");
        $stmt->execute([$recurso_edit['sucursal_id']]);
        $personales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
// ==================== CONSULTA CON FILTROS ====================
$where_clauses = ["rs.empresa_id = ?"];
$params = [$empresa_id];
if (!empty($search_sucursal)) {
    $where_clauses[] = "s.nombre LIKE ?";
    $params[] = '%' . $search_sucursal . '%';
}
if (!empty($search_personal)) {
    $where_clauses[] = "CONCAT(p.nombre, ' ', p.apellido) LIKE ?";
    $params[] = '%' . $search_personal . '%';
}
if ($search_tipo_recurso !== 'todos') {
    $where_clauses[] = "ri.tipo_recurso = ?";
    $params[] = $search_tipo_recurso;
}
if ($search_estado !== 'todos') {
    $where_clauses[] = "rs.estado = ?";
    $params[] = $search_estado;
}
$where_sql = "WHERE " . implode(" AND ", $where_clauses);
$count_stmt = $conn->prepare("
SELECT COUNT(DISTINCT rs.id) as total
FROM recursos_sucursal rs
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
$where_sql
");
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);
$stmt = $conn->prepare("
SELECT rs.*, s.nombre as sucursal_nombre,
CONCAT(p.nombre, ' ', p.apellido) as personal_nombre, p.dni, p.cargo,
rs.estado, rs.motivo_rechazo,
SUM(CASE WHEN ri.tipo_recurso = 'chaleco' THEN 1 ELSE 0 END) as chaleco_count,
SUM(CASE WHEN ri.tipo_recurso = 'equipo_comunicacion' THEN 1 ELSE 0 END) as comunicacion_count,
SUM(CASE WHEN ri.tipo_recurso = 'armamento' THEN 1 ELSE 0 END) as armamento_count,
SUM(CASE WHEN ri.tipo_recurso = 'vehiculo' THEN 1 ELSE 0 END) as vehiculo_count,
SUM(CASE WHEN ri.tipo_recurso = 'equipos_video_vigilancia' THEN 1 ELSE 0 END) as video_count,
COUNT(ri.id) as total_items
FROM recursos_sucursal rs
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN personal p ON rs.personal_id = p.id
LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
$where_sql
GROUP BY rs.id
ORDER BY $order_column $order_direction
LIMIT $records_per_page OFFSET $offset
");
$stmt->execute($params);
$recursos_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filter_params = '';
if (!empty($search_sucursal)) $filter_params .= '&search_sucursal=' . urlencode($search_sucursal);
if (!empty($search_personal)) $filter_params .= '&search_personal=' . urlencode($search_personal);
if ($search_tipo_recurso !== 'todos') $filter_params .= '&search_tipo_recurso=' . urlencode($search_tipo_recurso);
if ($search_estado !== 'todos') $filter_params .= '&search_estado=' . urlencode($search_estado);
// ==================== ESTADISTICAS ====================
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM recursos_sucursal WHERE empresa_id = ?");
$stmt->execute([$empresa_id]);
$total_asignaciones = $stmt->fetch()['total'];
$estadisticas_estado = [];
foreach (['pendiente', 'aprobado', 'rechazado'] as $estado) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM recursos_sucursal WHERE empresa_id = ? AND estado = ?");
    $stmt->execute([$empresa_id, $estado]);
    $estadisticas_estado[$estado] = $stmt->fetch()['total'];
}
$items_chaleco = $items_edit['chaleco'] ?? [['Modelo' => '', 'Marca' => '', 'Talla' => '', 'Nivel de Proteccion NIJ' => '', 'Serial' => '', 'Fecha de Vencimiento' => '', 'Estado' => '']];
$items_com = $items_edit['equipo_comunicacion'] ?? [['Modelo' => '', 'Marca' => '', 'Tipo' => '', 'Numero de Serie' => '', 'Frecuencia Operativa (MHz)' => '', 'Cantidad de Canales' => '', 'Estado' => '']];
$items_arm = $items_edit['armamento'] ?? [['Tipo' => '', 'Marca' => '', 'Modelo' => '', 'Calibre' => '', 'Numero de Registro RENAR' => '', 'Serial Arma' => '', 'Estado' => '']];
$items_veh = $items_edit['vehiculo'] ?? [['Tipo' => '', 'Marca' => '', 'Modelo' => '', 'Ano' => '', 'Patente' => '', 'Numero de Chasis' => '', 'Numero de Motor' => '', 'Kilometraje Actual' => '', 'VTV Vencimiento' => '', 'Estado' => '']];
$items_vid = $items_edit['equipos_video_vigilancia'] ?? [['Tipo' => '', 'Marca' => '', 'Modelo' => '', 'Numero de Serie' => '', 'Ubicacion Fisica' => '', 'Estado' => '']];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mis Recursos - <?php echo htmlspecialchars($empresa['nombre']); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ? ESTILOS ESPECÍFICOS DE RECURSOS EMPRESA */
.main-content-wrapper {
    margin-left: 280px;
    padding-top: 100px;
    padding-left: 30px;
    padding-right: 30px;
    transition: margin-left 0.35s ease, padding 0.35s ease;
    min-height: calc(100vh - 100px);
    width: calc(100% - 280px);
}

body.sidebar-collapsed .main-content-wrapper {
    margin-left: 0;
    width: 100%;
}

/* ? TABLET (992px - 1199px) */
@media (min-width: 992px) and (max-width: 1199px) {
    .main-content-wrapper {
        padding-left: 20px;
        padding-right: 20px;
    }
}

/* ? NOTEBOOK/LAPTOP (1200px - 1440px) */
@media (min-width: 1200px) and (max-width: 1440px) {
    .main-content-wrapper {
        padding-left: 25px;
        padding-right: 25px;
    }
}

/* ? PC ESCRITORIO (> 1441px) */
@media (min-width: 1441px) {
    .main-content-wrapper {
        padding-left: 40px;
        padding-right: 40px;
    }
}

/* ? CELULAR ANDROID/MOBIL (< 991px) */
@media (max-width: 991px) {
    .main-content-wrapper {
        margin-left: 0 !important;
        width: 100% !important;
        padding-left: 15px;
        padding-right: 15px;
        padding-top: 80px;
    }
}

/* ? CELULARS PEQUEÑOS (< 576px) */
@media (max-width: 576px) {
    .main-content-wrapper {
        padding-left: 10px;
        padding-right: 10px;
        padding-top: 70px;
    }
}

/* ? TRANSICIONES SUAVES */
.main-content-wrapper,
.sidebar-moderno {
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ? PREVENIR SCROLL HORIZONTAL */
body {
    overflow-x: hidden;
}

.container {
    max-width: 100%;
    padding-left: 15px;
    padding-right: 15px;
}

/* ? ALERTA FLOTANTE DE URGENCIA */
.urgency-alert {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
    padding: 20px 25px;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4);
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 15px;
    max-width: 400px;
    animation: slideInRight 0.5s ease, pulse 2s infinite;
}
.urgency-alert i { font-size: 1.8rem; }
.urgency-alert-content h6 { margin: 0; font-weight: 700; font-size: 0.95rem; }
.urgency-alert-content p { margin: 5px 0 0; font-size: 0.85rem; opacity: 0.9; }
.urgency-alert-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.urgency-alert-close:hover { background: rgba(255,255,255,0.3); }
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4); }
    50% { box-shadow: 0 10px 60px rgba(220, 53, 69, 0.6); }
}

/* ? ESTADÍSTICAS MODERNAS */
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
    transition: transform 0.3s, box-shadow 0.3s;
}
.stat-card-modern:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18);
}

/* ? BÚSQUEDA MODERNA */
.search-section-modern {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 24px;
    padding: 40px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    margin-bottom: 40px;
    border: 1px solid rgba(0, 0, 0, 0.06);
}
.search-header {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 35px;
    padding-bottom: 25px;
    border-bottom: 2px solid rgba(67, 97, 238, 0.1);
}
.search-icon-wrapper {
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 25px rgba(67, 97, 238, 0.35);
    font-size: 2rem;
    color: white;
}
.search-title-group h3 {
    color: #2c3e50;
    font-weight: 800;
    margin: 0 0 8px 0;
    font-size: 1.8rem;
}
.search-subtitle {
    color: #6c757d;
    font-size: 0.95rem;
    margin: 0;
}
.search-form-modern {
    display: flex;
    flex-direction: column;
    gap: 25px;
}
.search-inputs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
}
.search-input-wrapper {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.search-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: #2c3e50;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.search-label i {
    color: #4361ee;
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}
.input-group-modern {
    position: relative;
    display: flex;
    align-items: center;
}
.input-icon {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #4361ee;
    font-size: 1.1rem;
    z-index: 2;
}
.form-control-modern, .form-select-modern {
    width: 100%;
    border-radius: 14px;
    border: 2px solid #e9ecef;
    padding: 14px 18px 14px 50px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
    font-weight: 500;
    color: #2c3e50;
}
.form-control-modern:focus, .form-select-modern:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 5px rgba(67, 97, 238, 0.12);
    outline: none;
}
.search-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    align-items: center;
    padding-top: 10px;
}
.btn-search-modern {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    color: white;
    border: none;
    border-radius: 14px;
    padding: 14px 32px;
    font-weight: 700;
    transition: all 0.3s ease;
    box-shadow: 0 6px 20px rgba(67, 97, 238, 0.35);
    cursor: pointer;
}
.btn-search-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(67, 97, 238, 0.5);
}
.btn-reset-modern {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    color: #6c757d;
    border: 2px solid #dee2e6;
    border-radius: 14px;
    padding: 14px 28px;
    font-weight: 600;
    transition: all 0.3s ease;
    cursor: pointer;
}
.btn-reset-modern:hover {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    border-color: #dc3545;
    color: white;
}

/* ? SECCIONES DEL FORMULARIO */
.section-header-modern {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 25px 35px;
    background: linear-gradient(135deg, #2c3e50, #1a252f);
    border-radius: 20px;
    margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.18);
}
.section-header-modern h2 {
    color: white;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.form-section-modern {
    background: white;
    border-radius: 28px;
    padding: 40px;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15);
    margin-bottom: 40px;
    border: 1px solid rgba(0, 0, 0, 0.08);
}
.section-title {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    padding: 10px 15px;
    border-radius: 8px;
    margin: 20px 0 15px 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    cursor: pointer;
    transition: all 0.3s;
}
.section-title:hover {
    background: linear-gradient(135deg, #2980b9, #2472a4);
    transform: translateX(5px);
}
.section-title i {
    margin-right: 10px;
    transition: transform 0.3s;
}
.section-title.collapsed i {
    transform: rotate(-90deg);
}
.detalles-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin: 15px 0;
    border-left: 4px solid #3498db;
    display: block !important;
}
.detalles-section.chaleco { border-left-color: #27ae60; }
.detalles-section.comunicacion { border-left-color: #3498db; }
.detalles-section.armamento { border-left-color: #e74c3c; }
.detalles-section.vehiculo { border-left-color: #9b59b6; }
.detalles-section.video { border-left-color: #f39c12; }
.items-table { width: 100%; margin-top: 15px; }
.items-table th { background-color: #3498db !important; color: white; }
.add-item-btn { margin-top: 10px; }
.remove-item-btn { color: #e74c3c; cursor: pointer; }
.bg-purple { background-color: #9b59b6 !important; }
.bg-purple:hover { background-color: #8e44ad !important; }
.empty-state-modern {
    text-align: center;
    padding: 60px 20px;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 28px;
    margin-top: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}
.loading { display: none; text-align: center; padding: 20px; }
.loading-spinner {
    border: 4px solid rgba(0, 0, 0, 0.1);
    border-radius: 50%;
    border-top: 4px solid #3498db;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 10px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.modal-xl { max-width: 1200px; }
.modal-details-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    border-left: 4px solid #3498db;
}
.modal-details-title {
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 10px;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-details-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 10px;
}
.modal-detail-item {
    background: white;
    padding: 12px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.modal-detail-label {
    font-weight: 600;
    color: #7f8c8d;
    font-size: 0.9rem;
    margin-bottom: 3px;
}
.modal-detail-value {
    font-weight: 600;
    color: #2c3e50;
    font-size: 1.1rem;
}
.item-table { width: 100%; margin-top: 10px; font-size: 0.9rem; }
.item-table th { background-color: #3498db !important; color: white; font-size: 0.85rem; padding: 8px; }
.item-table td { padding: 6px 8px; border-bottom: 1px solid #eee; }
.item-section { margin-bottom: 25px; border-left: 3px solid #3498db; padding-left: 15px; }
.item-section.chaleco { border-left-color: #27ae60; }
.item-section.comunicacion { border-left-color: #3498db; }
.item-section.armamento { border-left-color: #e74c3c; }
.item-section.vehiculo { border-left-color: #9b59b6; }
.item-section.video { border-left-color: #f39c12; }
.section-badge {
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 15px;
    color: white;
    display: inline-block;
    margin-bottom: 10px;
}
.badge-chaleco { background: #27ae60; }
.badge-comunicacion { background: #3498db; }
.badge-armamento { background: #e74c3c; }
.badge-vehiculo { background: #9b59b6; }
.badge-video { background: #f39c12; }
.resources-table-container {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
    margin-bottom: 30px;
}
.resources-table {
    width: 100%;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
}
.resources-table thead {
    background: linear-gradient(135deg, #2c3e50, #1a252f);
    color: white;
}
.resources-table thead th {
    padding: 18px 15px;
    font-weight: 700;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
    white-space: nowrap;
}
.resources-table tbody tr {
    transition: all 0.3s;
    border-bottom: 1px solid #f0f0f0;
}
.resources-table tbody tr:hover {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    transform: scale(1.005);
}
.resources-table tbody td {
    padding: 16px 15px;
    vertical-align: middle;
    font-size: 0.95rem;
}
.pagination-container {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    margin-top: 20px;
}
.pagination .page-link {
    border-radius: 10px;
    margin: 0 3px;
    padding: 10px 16px;
    color: #4361ee;
    border: 2px solid #e9ecef;
    font-weight: 600;
    transition: all 0.3s;
}
.pagination .page-link:hover {
    background: #4361ee;
    border-color: #4361ee;
    color: white;
    transform: translateY(-2px);
}
.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    border-color: #4361ee;
    color: white;
    box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
}
.pagination-info {
    text-align: center;
    color: #6c757d;
    font-size: 0.9rem;
    margin-top: 15px;
    font-weight: 500;
}
.badge-pendiente { background: #ffc107 !important; color: #000; }
.badge-aprobado { background: #28a745 !important; color: #fff; }
.badge-rechazado { background: #dc3545 !important; color: #fff; }
.estado-info {
    background: linear-gradient(135deg, #fff3cd, #ffe69c);
    border: 2px solid #ffc107;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 25px;
}

/* ? RESPONSIVE PARA TABLAS */
@media (max-width: 768px) {
    .resources-table thead {
        display: none;
    }
    .resources-table tbody tr {
        display: block;
        margin-bottom: 15px;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 15px;
    }
    .resources-table tbody td {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .resources-table tbody td:last-child {
        border-bottom: none;
    }
    .resources-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #2c3e50;
    }
    .search-section-modern {
        padding: 20px;
    }
    .form-section-modern {
        padding: 20px;
    }
}
</style>
</head>
<body>
<!-- ? HEADER (primero) -->
<?php include '../includes/header_empresa.php'; ?>
<!-- ? SIDEBAR (después del header) -->
<?php include '../includes/sidebar_empresa.php'; ?>

<!-- ? CONTENIDO PRINCIPAL WRAPPER -->
<div class="main-content-wrapper">
<div class="container mt-4">
<div class="estado-info">
    <div class="d-flex align-items-center">
        <i class="fas fa-info-circle fa-2x text-warning me-3"></i>
        <div>
            <strong class="fs-5">Solicitud de Recursos</strong>
            <p class="mb-0 text-muted">Las solicitudes de recursos seran revisadas y aprobadas por el administrador del sistema. Recibira una notificacion cuando su solicitud sea procesada.</p>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ? ALERTA FLOTANTE DE URGENCIA -->
<?php if ($hay_urgencia): ?>
<div class="urgency-alert" id="urgencyAlert">
    <i class="fas fa-exclamation-triangle"></i>
    <div class="urgency-alert-content">
        <h6>?? TRÁMITE URGENTE</h6>
        <p>Debe presentarse en las oficinas de forma URGENTE</p>
    </div>
    <button class="urgency-alert-close" onclick="closeUrgencyAlert()">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php endif; ?>

<div class="stats-container-modern">
    <div class="stat-card-modern">
        <div style="width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#ffc107,#ff9800);color:#000;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:1.9rem;">
            <i class="fas fa-clock"></i>
        </div>
        <div style="font-size:2.8rem;font-weight:800;margin:10px 0;color:#2c3e50;"><?php echo $estadisticas_estado['pendiente']; ?></div>
        <div style="font-size:1.25rem;font-weight:700;color:#2c3e50;margin-top:5px;">Pendientes</div>
    </div>
    <div class="stat-card-modern">
        <div style="width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#28a745,#20c997);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:1.9rem;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div style="font-size:2.8rem;font-weight:800;margin:10px 0;color:#2c3e50;"><?php echo $estadisticas_estado['aprobado']; ?></div>
        <div style="font-size:1.25rem;font-weight:700;color:#2c3e50;margin-top:5px;">Aprobados</div>
    </div>
    <div class="stat-card-modern">
        <div style="width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#dc3545,#c82333);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:1.9rem;">
            <i class="fas fa-times-circle"></i>
        </div>
        <div style="font-size:2.8rem;font-weight:800;margin:10px 0;color:#2c3e50;"><?php echo $estadisticas_estado['rechazado']; ?></div>
        <div style="font-size:1.25rem;font-weight:700;color:#2c3e50;margin-top:5px;">Rechazados</div>
    </div>
    <div class="stat-card-modern">
        <div style="width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#4361ee,#3a0ca3);color:white;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:1.9rem;">
            <i class="fas fa-boxes"></i>
        </div>
        <div style="font-size:2.8rem;font-weight:800;margin:10px 0;color:#2c3e50;"><?php echo $total_asignaciones; ?></div>
        <div style="font-size:1.25rem;font-weight:700;color:#2c3e50;margin-top:5px;">Total Solicitudes</div>
    </div>
</div>

<div class="search-section-modern">
    <div class="search-header">
        <div class="search-icon-wrapper">
            <i class="fas fa-search"></i>
        </div>
        <div class="search-title-group">
            <h3>Buscar Mis Solicitudes</h3>
            <p class="search-subtitle">Encuentra rapidamente tus solicitudes de recursos</p>
        </div>
    </div>
    <form method="GET" action="" class="search-form-modern">
        <div class="search-inputs-grid">
            <div class="search-input-wrapper">
                <label class="search-label"><i class="fas fa-map-marker-alt"></i><span>Sucursal</span></label>
                <div class="input-group-modern">
                    <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                    <input type="text" name="search_sucursal" class="form-control-modern" value="<?php echo htmlspecialchars($search_sucursal); ?>" placeholder="Nombre de la sucursal...">
                </div>
            </div>
            <div class="search-input-wrapper">
                <label class="search-label"><i class="fas fa-user"></i><span>Personal</span></label>
                <div class="input-group-modern">
                    <span class="input-icon"><i class="fas fa-user"></i></span>
                    <input type="text" name="search_personal" class="form-control-modern" value="<?php echo htmlspecialchars($search_personal); ?>" placeholder="Nombre del personal...">
                </div>
            </div>
            <div class="search-input-wrapper">
                <label class="search-label"><i class="fas fa-clipboard-check"></i><span>Estado</span></label>
                <div class="input-group-modern">
                    <span class="input-icon"><i class="fas fa-filter"></i></span>
                    <select name="search_estado" class="form-select-modern">
                        <option value="todos" <?php echo ($search_estado === 'todos') ? 'selected' : ''; ?>>Todos los estados</option>
                        <option value="pendiente" <?php echo ($search_estado === 'pendiente') ? 'selected' : ''; ?>>Pendientes</option>
                        <option value="aprobado" <?php echo ($search_estado === 'aprobado') ? 'selected' : ''; ?>>Aprobados</option>
                        <option value="rechazado" <?php echo ($search_estado === 'rechazado') ? 'selected' : ''; ?>>Rechazados</option>
                    </select>
                </div>
            </div>
            <div class="search-input-wrapper">
                <label class="search-label"><i class="fas fa-boxes"></i><span>Tipo de Recurso</span></label>
                <div class="input-group-modern">
                    <span class="input-icon"><i class="fas fa-filter"></i></span>
                    <select name="search_tipo_recurso" class="form-select-modern">
                        <option value="todos" <?php echo ($search_tipo_recurso === 'todos') ? 'selected' : ''; ?>>Todos los recursos</option>
                        <option value="chaleco" <?php echo ($search_tipo_recurso === 'chaleco') ? 'selected' : ''; ?>>Chalecos</option>
                        <option value="equipo_comunicacion" <?php echo ($search_tipo_recurso === 'equipo_comunicacion') ? 'selected' : ''; ?>>Equipos de Comunicacion</option>
                        <option value="armamento" <?php echo ($search_tipo_recurso === 'armamento') ? 'selected' : ''; ?>>Armamento</option>
                        <option value="vehiculo" <?php echo ($search_tipo_recurso === 'vehiculo') ? 'selected' : ''; ?>>Vehiculos</option>
                        <option value="equipos_video_vigilancia" <?php echo ($search_tipo_recurso === 'equipos_video_vigilancia') ? 'selected' : ''; ?>>Video Vigilancia</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="search-actions">
            <button type="submit" class="btn-search-modern"><i class="fas fa-search"></i><span>Buscar</span></button>
            <a href="recursos_empresa.php" class="btn-reset-modern"><i class="fas fa-redo"></i><span>Limpiar</span></a>
        </div>
    </form>
</div>

<div class="section-header-modern">
    <h2><i class="fas fa-plus-circle"></i><?php echo $recurso_edit ? 'Editar Solicitud' : 'Nueva Solicitud de Recursos'; ?></h2>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#asignarRecursosForm">
        <i class="fas fa-plus me-1"></i><?php echo $recurso_edit ? 'Editar' : 'Crear Solicitud'; ?>
    </button>
</div>

<div class="collapse form-section-modern <?php echo $recurso_edit ? 'show' : ''; ?>" id="asignarRecursosForm">
    <h3 class="mb-4"><i class="fas fa-boxes me-2"></i><?php echo $recurso_edit ? 'Editar Solicitud de Recursos' : 'Registrar Nueva Solicitud'; ?></h3>
    <form method="POST" action="" class="row g-4" id="recursosForm" enctype="multipart/form-data">
        <?php if ($recurso_edit): ?>
        <input type="hidden" name="recurso_id" value="<?php echo $recurso_edit['id']; ?>">
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label">Sucursal <span class="text-danger">*</span></label>
            <select name="sucursal_id" class="form-control form-control-lg" required id="sucursalSelect">
                <option value="">Seleccione...</option>
                <?php foreach ($sucursales as $sucursal): ?>
                <option value="<?php echo $sucursal['id']; ?>" <?php echo ($recurso_edit && $recurso_edit['sucursal_id'] == $sucursal['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sucursal['nombre']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Personal Asignado <span class="text-muted">(Opcional)</span></label>
            <select name="personal_id" class="form-control form-control-lg" id="personalSelect">
                <option value="">-- Recursos para la Sucursal --</option>
                <?php foreach ($personales as $personal): ?>
                <option value="<?php echo $personal['id']; ?>" <?php echo ($recurso_edit && $recurso_edit['personal_id'] == $personal['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($personal['nombre_completo']); ?>
                    <?php if(!empty($personal['dni'])): ?> (DNI: <?php echo htmlspecialchars($personal['dni']); ?>) <?php endif; ?>
                    <?php if(!empty($personal['cargo'])): ?> - <?php echo htmlspecialchars($personal['cargo']); ?> <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted"><i class="fas fa-info-circle"></i> Si no selecciona personal, los recursos quedaran asignados a la sucursal</small>
        </div>
        <div class="col-12">
            <label class="form-label">
                <i class="fas fa-file-pdf me-2"></i>Archivo PDF (Opcional)
                <small class="text-muted d-block">Maximo 5MB. Solo archivos PDF.</small>
            </label>
            <div class="input-group">
                <input type="file" name="archivo_pdf" class="form-control" accept=".pdf,application/pdf" id="archivoPdfInput">
                <?php if ($recurso_edit && !empty($recurso_edit['archivo_pdf'])): ?>
                <a href="../<?php echo htmlspecialchars($recurso_edit['archivo_pdf']); ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-eye"></i> Ver PDF Actual
                </a>
                <?php endif; ?>
            </div>
            <?php if ($recurso_edit && !empty($recurso_edit['archivo_pdf'])): ?>
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" name="eliminar_pdf" id="eliminarPdf" value="1">
                <label class="form-check-label" for="eliminarPdf">Eliminar PDF actual</label>
            </div>
            <?php endif; ?>
        </div>
        <!-- Chalecos -->
        <div class="col-12">
            <div class="section-title" data-target="chaleco-section">
                <i class="fas fa-chevron-down"></i><i class="fas fa-vest"></i> Chalecos <span class="badge bg-success ms-2" id="chaleco-count">0</span>
            </div>
            <div class="detalles-section chaleco" id="chaleco-section">
                <div class="table-responsive">
                    <table class="table table-bordered items-table">
                        <thead><tr><th>Modelo</th><th>Marca</th><th>Talla</th><th>Nivel NIJ</th><th>Serial</th><th>Vencimiento</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
                        <tbody id="chaleco-items-body">
                            <?php foreach ($items_chaleco as $index => $item): ?>
                            <tr>
                                <td><input type="text" name="items_chaleco[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
                                <td><select name="items_chaleco[<?php echo $index; ?>][Marca]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Second Chance', 'Point Blank', 'American Body Armor', 'Safariland', 'Otra'] as $marca): ?><option value="<?php echo $marca; ?>" <?php echo ($item['Marca'] ?? '') == $marca ? 'selected' : ''; ?>><?php echo $marca; ?></option><?php endforeach; ?></select></td>
                                <td><select name="items_chaleco[<?php echo $index; ?>][Talla]" class="form-control"><option value="">Seleccione...</option><?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $talla): ?><option value="<?php echo $talla; ?>" <?php echo ($item['Talla'] ?? '') == $talla ? 'selected' : ''; ?>><?php echo $talla; ?></option><?php endforeach; ?></select></td>
                                <td><select name="items_chaleco[<?php echo $index; ?>][Nivel de Proteccion NIJ]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Nivel IIA', 'Nivel II', 'Nivel IIIA', 'Nivel III', 'Nivel IV'] as $nivel): ?><option value="<?php echo $nivel; ?>" <?php echo ($item['Nivel de Proteccion NIJ'] ?? '') == $nivel ? 'selected' : ''; ?>><?php echo $nivel; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="items_chaleco[<?php echo $index; ?>][Serial]" class="form-control" value="<?php echo htmlspecialchars($item['Serial'] ?? ''); ?>"></td>
                                <td><input type="date" name="items_chaleco[<?php echo $index; ?>][Fecha de Vencimiento]" class="form-control" value="<?php echo htmlspecialchars($item['Fecha de Vencimiento'] ?? ''); ?>"></td>
                                <td><select name="items_chaleco[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Nuevo', 'En uso', 'Reparacion', 'Vencido', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
                                <td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-success add-item-btn" onclick="addItem('chaleco')"><i class="fas fa-plus"></i> Agregar Chaleco</button>
            </div>
        </div>
        <!-- Comunicacion -->
        <div class="col-12">
            <div class="section-title" data-target="comunicacion-section">
                <i class="fas fa-chevron-down"></i><i class="fas fa-headset"></i> Equipos de Comunicacion <span class="badge bg-info ms-2" id="comunicacion-count">0</span>
            </div>
            <div class="detalles-section comunicacion" id="comunicacion-section">
                <div class="table-responsive">
                    <table class="table table-bordered items-table">
                        <thead><tr><th>Modelo</th><th>Marca</th><th>Tipo</th><th>N° Serie</th><th>Frecuencia</th><th>Canales</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
                        <tbody id="comunicacion-items-body">
                            <?php foreach ($items_com as $index => $item): ?>
                            <tr>
                                <td><input type="text" name="items_equipo_comunicacion[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
                                <td><select name="items_equipo_comunicacion[<?php echo $index; ?>][Marca]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Motorola', 'Kenwood', 'Hytera', 'Icom', 'Baofeng', 'Otra'] as $marca): ?><option value="<?php echo $marca; ?>" <?php echo ($item['Marca'] ?? '') == $marca ? 'selected' : ''; ?>><?php echo $marca; ?></option><?php endforeach; ?></select></td>
                                <td><select name="items_equipo_comunicacion[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Portatil', 'Movil (Vehicular)', 'Base Fija'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="items_equipo_comunicacion[<?php echo $index; ?>][Numero de Serie]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Serie'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_equipo_comunicacion[<?php echo $index; ?>][Frecuencia Operativa (MHz)]" class="form-control" value="<?php echo htmlspecialchars($item['Frecuencia Operativa (MHz)'] ?? ''); ?>"></td>
                                <td><input type="number" name="items_equipo_comunicacion[<?php echo $index; ?>][Cantidad de Canales]" class="form-control" value="<?php echo htmlspecialchars($item['Cantidad de Canales'] ?? ''); ?>"></td>
                                <td><select name="items_equipo_comunicacion[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'Reparacion', 'Fuera de servicio', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
                                <td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-info add-item-btn" onclick="addItem('equipo_comunicacion')"><i class="fas fa-plus"></i> Agregar Equipo</button>
            </div>
        </div>
        <!-- Armamento -->
        <div class="col-12">
            <div class="section-title" data-target="armamento-section">
                <i class="fas fa-chevron-down"></i><i class="fas fa-gun"></i> Armamento <span class="badge bg-danger ms-2" id="armamento-count">0</span>
            </div>
            <div class="detalles-section armamento" id="armamento-section">
                <div class="table-responsive">
                    <table class="table table-bordered items-table">
                        <thead><tr><th>Tipo</th><th>Marca</th><th>Modelo</th><th>Calibre</th><th>N° RENAR</th><th>Serial</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
                        <tbody id="armamento-items-body">
                            <?php foreach ($items_arm as $index => $item): ?>
                            <tr>
                                <td><select name="items_armamento[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Pistola', 'Revolver', 'Escopeta', 'Rifle', 'Carabina', 'Otro'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="items_armamento[<?php echo $index; ?>][Marca]" class="form-control" value="<?php echo htmlspecialchars($item['Marca'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_armamento[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
                                <td><select name="items_armamento[<?php echo $index; ?>][Calibre]" class="form-control"><option value="">Seleccione...</option><?php foreach (['9mm', '.40 S&W', '.45 ACP', '12 Gauge', '5.56mm', '.223 Rem', 'Otro'] as $calibre): ?><option value="<?php echo $calibre; ?>" <?php echo ($item['Calibre'] ?? '') == $calibre ? 'selected' : ''; ?>><?php echo $calibre; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="items_armamento[<?php echo $index; ?>][Numero de Registro RENAR]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Registro RENAR'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_armamento[<?php echo $index; ?>][Serial Arma]" class="form-control" value="<?php echo htmlspecialchars($item['Serial Arma'] ?? ''); ?>"></td>
                                <td><select name="items_armamento[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'Reparacion', 'Fuera de servicio', 'Decomiso', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
                                <td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-danger add-item-btn" onclick="addItem('armamento')"><i class="fas fa-plus"></i> Agregar Armamento</button>
            </div>
        </div>
        <!-- Vehiculos -->
        <div class="col-12">
            <div class="section-title" data-target="vehiculo-section">
                <i class="fas fa-chevron-down"></i><i class="fas fa-car"></i> Vehiculos <span class="badge bg-purple ms-2" id="vehiculo-count">0</span>
            </div>
            <div class="detalles-section vehiculo" id="vehiculo-section">
                <div class="table-responsive">
                    <table class="table table-bordered items-table">
                        <thead><tr><th>Tipo</th><th>Marca</th><th>Modelo</th><th>Ano</th><th>Patente</th><th>Chasis</th><th>Motor</th><th>Kms</th><th>VTV</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
                        <tbody id="vehiculo-items-body">
                            <?php foreach ($items_veh as $index => $item): ?>
                            <tr>
                                <td><select name="items_vehiculo[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Sedan', 'Pickup', 'Utilitario', 'Camioneta', 'Moto', 'Furgon', 'Otro'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Marca]" class="form-control" value="<?php echo htmlspecialchars($item['Marca'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
                                <td><input type="number" name="items_vehiculo[<?php echo $index; ?>][Ano]" class="form-control" value="<?php echo htmlspecialchars($item['Ano'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Patente]" class="form-control" value="<?php echo htmlspecialchars($item['Patente'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Numero de Chasis]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Chasis'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_vehiculo[<?php echo $index; ?>][Numero de Motor]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Motor'] ?? ''); ?>"></td>
                                <td><input type="number" name="items_vehiculo[<?php echo $index; ?>][Kilometraje Actual]" class="form-control" value="<?php echo htmlspecialchars($item['Kilometraje Actual'] ?? ''); ?>"></td>
                                <td><input type="date" name="items_vehiculo[<?php echo $index; ?>][VTV Vencimiento]" class="form-control" value="<?php echo htmlspecialchars($item['VTV Vencimiento'] ?? ''); ?>"></td>
                                <td><select name="items_vehiculo[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'En taller', 'Fuera de servicio', 'Siniestrado', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
                                <td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-purple add-item-btn" onclick="addItem('vehiculo')"><i class="fas fa-plus"></i> Agregar Vehiculo</button>
            </div>
        </div>
        <!-- Video Vigilancia -->
        <div class="col-12">
            <div class="section-title" data-target="video-section">
                <i class="fas fa-chevron-down"></i><i class="fas fa-video"></i> Video Vigilancia <span class="badge bg-warning ms-2" id="video-count">0</span>
            </div>
            <div class="detalles-section video" id="video-section">
                <div class="table-responsive">
                    <table class="table table-bordered items-table">
                        <thead><tr><th>Tipo</th><th>Marca</th><th>Modelo</th><th>N° Serie</th><th>Ubicacion</th><th>Estado</th><th><i class="fas fa-trash remove-item-btn"></i></th></tr></thead>
                        <tbody id="video-items-body">
                            <?php foreach ($items_vid as $index => $item): ?>
                            <tr>
                                <td><select name="items_equipos_video_vigilancia[<?php echo $index; ?>][Tipo]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Camara Dome', 'Camara Bullet', 'Camara PTZ', 'Camara Oculta', 'DVR', 'NVR', 'Monitor', 'Otro'] as $tipo): ?><option value="<?php echo $tipo; ?>" <?php echo ($item['Tipo'] ?? '') == $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option><?php endforeach; ?></select></td>
                                <td><select name="items_equipos_video_vigilancia[<?php echo $index; ?>][Marca]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Hikvision', 'Dahua', 'Axis', 'Bosch', 'Samsung', 'TP-Link', 'Otra'] as $marca): ?><option value="<?php echo $marca; ?>" <?php echo ($item['Marca'] ?? '') == $marca ? 'selected' : ''; ?>><?php echo $marca; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="items_equipos_video_vigilancia[<?php echo $index; ?>][Modelo]" class="form-control" value="<?php echo htmlspecialchars($item['Modelo'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_equipos_video_vigilancia[<?php echo $index; ?>][Numero de Serie]" class="form-control" value="<?php echo htmlspecialchars($item['Numero de Serie'] ?? ''); ?>"></td>
                                <td><input type="text" name="items_equipos_video_vigilancia[<?php echo $index; ?>][Ubicacion Fisica]" class="form-control" value="<?php echo htmlspecialchars($item['Ubicacion Fisica'] ?? ''); ?>"></td>
                                <td><select name="items_equipos_video_vigilancia[<?php echo $index; ?>][Estado]" class="form-control"><option value="">Seleccione...</option><?php foreach (['Operativo', 'Parcialmente Operativo', 'Reparacion', 'Fuera de servicio', 'Baja'] as $estado): ?><option value="<?php echo $estado; ?>" <?php echo ($item['Estado'] ?? '') == $estado ? 'selected' : ''; ?>><?php echo $estado; ?></option><?php endforeach; ?></select></td>
                                <td><i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-warning add-item-btn" onclick="addItem('equipos_video_vigilancia')"><i class="fas fa-plus"></i> Agregar Equipo</button>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label">Observaciones Generales</label>
            <textarea name="observaciones" class="form-control" rows="3"><?php echo $recurso_edit ? htmlspecialchars($recurso_edit['observaciones']) : ''; ?></textarea>
        </div>
        <div class="col-12 text-end">
            <button type="submit" name="guardar_recursos" class="btn btn-success btn-lg px-5">
                <i class="fas fa-paper-plane me-2"></i> <?php echo $recurso_edit ? 'Actualizar Solicitud' : 'Enviar Solicitud'; ?>
            </button>
            <?php if ($recurso_edit): ?>
            <a href="recursos_empresa.php" class="btn btn-secondary btn-lg px-5 ms-2"><i class="fas fa-times me-2"></i> Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<h2 class="section-title-modern"><i class="fas fa-list"></i> Mis Solicitudes <span class="badge bg-primary ms-2"><?php echo $total_records; ?></span></h2>
<?php if (empty($recursos_list)): ?>
<div class="empty-state-modern">
    <i class="fas fa-boxes fa-4x text-muted mb-3"></i>
    <h3 class="mb-3">No hay solicitudes de recursos</h3>
    <p class="mb-4">Registra tu primera solicitud de recursos para comenzar.</p>
    <button class="btn btn-success btn-lg" type="button" data-bs-toggle="collapse" data-bs-target="#asignarRecursosForm"><i class="fas fa-plus me-2"></i>Crear Primera Solicitud</button>
</div>
<?php else: ?>
<div class="resources-table-container">
    <div class="table-responsive">
        <table class="resources-table">
            <thead>
                <tr>
                    <th><i class="fas fa-map-marker-alt me-2"></i>Sucursal</th>
                    <th><i class="fas fa-user me-2"></i>Personal</th>
                    <th><i class="fas fa-clipboard-check me-2"></i>Estado</th>
                    <th class="text-center"><i class="fas fa-vest me-1" style="color: #27ae60;"></i>Chalecos</th>
                    <th class="text-center"><i class="fas fa-headset me-1" style="color: #3498db;"></i>Comunicacion</th>
                    <th class="text-center"><i class="fas fa-gun me-1" style="color: #e74c3c;"></i>Armamento</th>
                    <th class="text-center"><i class="fas fa-car me-1" style="color: #9b59b6;"></i>Vehiculos</th>
                    <th class="text-center"><i class="fas fa-video me-1" style="color: #f39c12;"></i>Video</th>
                    <th class="text-center"><i class="fas fa-boxes me-1"></i>Total</th>
                    <th class="text-center"><i class="fas fa-calendar-alt me-1"></i>Fecha</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recursos_list as $recurso): ?>
                <tr class="<?php echo ($recurso['estado'] ?? 'pendiente') === 'pendiente' ? 'table-warning' : ''; ?>">
                    <td data-label="Sucursal"><span class="badge bg-info"><?php echo htmlspecialchars($recurso['sucursal_nombre']); ?></span></td>
                    <td data-label="Personal"><?php if (!empty($recurso['personal_nombre'])): ?><span class="badge bg-success"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($recurso['personal_nombre']); ?></span><?php else: ?><span class="badge bg-secondary"><i class="fas fa-building me-1"></i>Sucursal</span><?php endif; ?></td>
                    <td data-label="Estado">
                        <?php
                        $estado = $recurso['estado'] ?? 'pendiente';
                        $badge_colors = ['pendiente' => 'pendiente', 'aprobado' => 'aprobado', 'rechazado' => 'rechazado'];
                        $badge_icons = ['pendiente' => 'fa-clock', 'aprobado' => 'fa-check-circle', 'rechazado' => 'fa-times-circle'];
                        ?>
                        <span class="badge badge-<?php echo $badge_colors[$estado]; ?>">
                            <i class="fas <?php echo $badge_icons[$estado]; ?> me-1"></i>
                            <?php echo ucfirst($estado); ?>
                        </span>
                        <?php if ($estado === 'rechazado' && !empty($recurso['motivo_rechazo'])): ?>
                        <small class="text-danger d-block mt-1"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(substr($recurso['motivo_rechazo'], 0, 50)); ?>...</small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Chalecos" class="text-center"><span class="badge bg-success"><?php echo $recurso['chaleco_count']; ?></span></td>
                    <td data-label="Comunicacion" class="text-center"><span class="badge bg-primary"><?php echo $recurso['comunicacion_count']; ?></span></td>
                    <td data-label="Armamento" class="text-center"><span class="badge bg-danger"><?php echo $recurso['armamento_count']; ?></span></td>
                    <td data-label="Vehiculos" class="text-center"><span class="badge bg-purple"><?php echo $recurso['vehiculo_count']; ?></span></td>
                    <td data-label="Video" class="text-center"><span class="badge bg-warning text-dark"><?php echo $recurso['video_count']; ?></span></td>
                    <td data-label="Total" class="text-center"><strong class="text-primary"><?php echo $recurso['total_items']; ?></strong></td>
                    <td data-label="Fecha" class="text-center text-muted small"><?php echo date('d/m/Y', strtotime($recurso['created_at'])); ?></td>
                    <td data-label="Acciones" class="text-center">
                        <div class="btn-group btn-group-sm">
                            <a href="#" class="btn btn-info" onclick="event.preventDefault(); verDetallesRecurso(<?php echo $recurso['id']; ?>);" title="Ver Detalles"><i class="fas fa-eye"></i></a>
                            <?php if ($estado !== 'aprobado'): ?>
                            <a href="recursos_empresa.php?edit=<?php echo $recurso['id']; ?>" class="btn btn-warning" title="Editar"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($total_pages > 1): ?>
<div class="pagination-container">
    <nav>
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>"><i class="fas fa-chevron-left"></i> Anterior</a>
            </li>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?><?= $filter_params ?>&order=<?= $order_column ?>&direction=<?= $order_direction ?>">Siguiente <i class="fas fa-chevron-right"></i></a>
            </li>
        </ul>
    </nav>
    <div class="pagination-info"><i class="fas fa-table me-2"></i>Mostrando <?= $offset + 1 ?> - <?= min($offset + $records_per_page, $total_records) ?> de <?= $total_records ?> registros</div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>

<div class="modal fade" id="modalDetallesRecurso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #2c3e50, #1a2530); color: white;">
                <h5 class="modal-title"><i class="fas fa-boxes"></i> <span id="modalTituloRecurso">Detalles de Solicitud de Recursos</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="loading" id="modalLoadingRecurso">
                    <div class="loading-spinner"></div>
                    <p>Cargando informacion de la solicitud...</p>
                </div>
                <div id="modalContentRecurso" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- ? SCRIPT UNIFICADO PARA TOGGLE SIDEBAR -->
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const tbodyMap = {
    'chaleco': 'chaleco-items-body',
    'equipo_comunicacion': 'comunicacion-items-body',
    'armamento': 'armamento-items-body',
    'vehiculo': 'vehiculo-items-body',
    'equipos_video_vigilancia': 'video-items-body'
};
const countMap = {
    'chaleco': 'chaleco-count',
    'equipo_comunicacion': 'comunicacion-count',
    'armamento': 'armamento-count',
    'vehiculo': 'vehiculo-count',
    'equipos_video_vigilancia': 'video-count'
};
let itemIndex = {
    'chaleco': <?php echo count($items_chaleco); ?>,
    'equipo_comunicacion': <?php echo count($items_com); ?>,
    'armamento': <?php echo count($items_arm); ?>,
    'vehiculo': <?php echo count($items_veh); ?>,
    'equipos_video_vigilancia': <?php echo count($items_vid); ?>
};
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.section-title').forEach(section => {
        section.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetSection = document.getElementById(targetId);
            const icon = this.querySelector('i.fa-chevron-down, i.fa-chevron-up');
            if (targetSection) {
                const isHidden = targetSection.style.display === 'none';
                if (isHidden) {
                    targetSection.style.display = 'block';
                    if (icon) {
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-up');
                    }
                    this.classList.add('collapsed');
                } else {
                    targetSection.style.display = 'none';
                    if (icon) {
                        icon.classList.remove('fa-chevron-up');
                        icon.classList.add('fa-chevron-down');
                    }
                    this.classList.remove('collapsed');
                }
                const tipo = getTipoFromSectionId(targetId);
                if (tipo) updateCount(tipo);
            }
        });
    });
    fetch('recursos_empresa.php?action=get_sucursales')
    .then(response => response.json())
    .then(data => {
        const select = document.getElementById('sucursalSelect');
        if (select && data && data.length > 0) {
            const selectedValue = select.value;
            select.innerHTML = '<option value="">Seleccione...</option>';
            data.forEach(s => {
                const option = document.createElement('option');
                option.value = s.id;
                option.textContent = s.nombre;
                if (s.id == selectedValue) option.selected = true;
                select.appendChild(option);
            });
        }
    })
    .catch(error => {
        console.error('Error cargando sucursales:', error);
    });
    const sucursalSelect = document.getElementById('sucursalSelect');
    if (sucursalSelect) {
        sucursalSelect.addEventListener('change', function() {
            const sucursalId = this.value;
            const personalSelect = document.getElementById('personalSelect');
            if (!sucursalId) {
                if (personalSelect) personalSelect.innerHTML = '<option value="">-- Recursos para la Sucursal --</option>';
                return;
            }
            if (personalSelect) personalSelect.innerHTML = '<option value="">Cargando...</option>';
            fetch(`recursos_empresa.php?action=get_personal&sucursal_id=${sucursalId}`)
            .then(response => response.json())
            .then(data => {
                if (personalSelect) {
                    personalSelect.innerHTML = '<option value="">-- Recursos para la Sucursal --</option>';
                    if (data && data.length > 0) {
                        data.forEach(p => {
                            personalSelect.innerHTML += `<option value="${p.id}">${p.nombre_completo}${p.dni ? ' (DNI: ' + p.dni + ')' : ''}${p.cargo ? ' - ' + p.cargo : ''}</option>`;
                        });
                    } else {
                        personalSelect.innerHTML = '<option value="">No hay personal</option>';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (personalSelect) personalSelect.innerHTML = '<option value="">Error al cargar</option>';
            });
        });
    }
    ['chaleco', 'equipo_comunicacion', 'armamento', 'vehiculo', 'equipos_video_vigilancia'].forEach(tipo => {
        updateCount(tipo);
        const sectionId = getSectionIdFromTipo(tipo);
        const section = document.getElementById(sectionId);
        if (section) section.style.display = 'block';
    });
});
function getSectionIdFromTipo(tipo) {
    if (tipo === 'equipo_comunicacion') return 'comunicacion-section';
    if (tipo === 'equipos_video_vigilancia') return 'video-section';
    return tipo + '-section';
}
function getTipoFromSectionId(sectionId) {
    if (sectionId === 'comunicacion-section') return 'equipo_comunicacion';
    if (sectionId === 'video-section') return 'equipos_video_vigilancia';
    return sectionId.replace('-section', '');
}
function addItem(tipo) {
    const index = itemIndex[tipo] || 1;
    const tbodyId = tbodyMap[tipo];
    const tbody = document.getElementById(tbodyId);
    if (!tbody) {
        console.error('No se encontro el tbody para:', tipo, 'ID buscado:', tbodyId);
        return;
    }
    const row = document.createElement('tr');
    let fields = [];
    if (tipo === 'chaleco') {
        fields = [
            {type: 'text', name: 'Modelo', value: ''},
            {type: 'select', name: 'Marca', options: ['Second Chance', 'Point Blank', 'American Body Armor', 'Safariland', 'Otra']},
            {type: 'select', name: 'Talla', options: ['XS', 'S', 'M', 'L', 'XL', 'XXL']},
            {type: 'select', name: 'Nivel de Proteccion NIJ', options: ['Nivel IIA', 'Nivel II', 'Nivel IIIA', 'Nivel III', 'Nivel IV']},
            {type: 'text', name: 'Serial', value: ''},
            {type: 'date', name: 'Fecha de Vencimiento', value: ''},
            {type: 'select', name: 'Estado', options: ['Nuevo', 'En uso', 'Reparacion', 'Vencido', 'Baja']}
        ];
    } else if (tipo === 'equipo_comunicacion') {
        fields = [
            {type: 'text', name: 'Modelo', value: ''},
            {type: 'select', name: 'Marca', options: ['Motorola', 'Kenwood', 'Hytera', 'Icom', 'Baofeng', 'Otra']},
            {type: 'select', name: 'Tipo', options: ['Portatil', 'Movil (Vehicular)', 'Base Fija']},
            {type: 'text', name: 'Numero de Serie', value: ''},
            {type: 'text', name: 'Frecuencia Operativa (MHz)', value: ''},
            {type: 'number', name: 'Cantidad de Canales', value: ''},
            {type: 'select', name: 'Estado', options: ['Operativo', 'Reparacion', 'Fuera de servicio', 'Baja']}
        ];
    } else if (tipo === 'armamento') {
        fields = [
            {type: 'select', name: 'Tipo', options: ['Pistola', 'Revolver', 'Escopeta', 'Rifle', 'Carabina', 'Otro']},
            {type: 'text', name: 'Marca', value: ''},
            {type: 'text', name: 'Modelo', value: ''},
            {type: 'select', name: 'Calibre', options: ['9mm', '.40 S&W', '.45 ACP', '12 Gauge', '5.56mm', '.223 Rem', 'Otro']},
            {type: 'text', name: 'Numero de Registro RENAR', value: ''},
            {type: 'text', name: 'Serial Arma', value: ''},
            {type: 'select', name: 'Estado', options: ['Operativo', 'Reparacion', 'Fuera de servicio', 'Decomiso', 'Baja']}
        ];
    } else if (tipo === 'vehiculo') {
        fields = [
            {type: 'select', name: 'Tipo', options: ['Sedan', 'Pickup', 'Utilitario', 'Camioneta', 'Moto', 'Furgon', 'Otro']},
            {type: 'text', name: 'Marca', value: ''},
            {type: 'text', name: 'Modelo', value: ''},
            {type: 'number', name: 'Ano', value: ''},
            {type: 'text', name: 'Patente', value: ''},
            {type: 'text', name: 'Numero de Chasis', value: ''},
            {type: 'text', name: 'Numero de Motor', value: ''},
            {type: 'number', name: 'Kilometraje Actual', value: ''},
            {type: 'date', name: 'VTV Vencimiento', value: ''},
            {type: 'select', name: 'Estado', options: ['Operativo', 'En taller', 'Fuera de servicio', 'Siniestrado', 'Baja']}
        ];
    } else if (tipo === 'equipos_video_vigilancia') {
        fields = [
            {type: 'select', name: 'Tipo', options: ['Camara Dome', 'Camara Bullet', 'Camara PTZ', 'Camara Oculta', 'DVR', 'NVR', 'Monitor', 'Otro']},
            {type: 'select', name: 'Marca', options: ['Hikvision', 'Dahua', 'Axis', 'Bosch', 'Samsung', 'TP-Link', 'Otra']},
            {type: 'text', name: 'Modelo', value: ''},
            {type: 'text', name: 'Numero de Serie', value: ''},
            {type: 'text', name: 'Ubicacion Fisica', value: ''},
            {type: 'select', name: 'Estado', options: ['Operativo', 'Parcialmente Operativo', 'Reparacion', 'Fuera de servicio', 'Baja']}
        ];
    }
    fields.forEach((field) => {
        const cell = document.createElement('td');
        if (field.type === 'select') {
            let select = document.createElement('select');
            select.className = 'form-control';
            select.name = `items_${tipo}[${index}][${field.name}]`;
            let defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Seleccione...';
            select.appendChild(defaultOption);
            field.options.forEach(opt => {
                let option = document.createElement('option');
                option.value = opt;
                option.textContent = opt;
                select.appendChild(option);
            });
            cell.appendChild(select);
        } else {
            let input = document.createElement('input');
            input.type = field.type;
            input.className = 'form-control';
            input.name = `items_${tipo}[${index}][${field.name}]`;
            input.value = field.value || '';
            cell.appendChild(input);
        }
        row.appendChild(cell);
    });
    const actionCell = document.createElement('td');
    actionCell.innerHTML = '<i class="fas fa-trash remove-item-btn" onclick="removeItem(this)"></i>';
    row.appendChild(actionCell);
    tbody.appendChild(row);
    itemIndex[tipo] = index + 1;
    updateCount(tipo);
}
function removeItem(element) {
    const row = element.closest('tr');
    if (!row) return;
    const tbodyId = row.parentElement.id;
    let tipo = null;
    for (const [key, value] of Object.entries(tbodyMap)) {
        if (value === tbodyId) {
            tipo = key;
            break;
        }
    }
    row.remove();
    if (tipo) updateCount(tipo);
}
function updateCount(tipo) {
    const tbodyId = tbodyMap[tipo];
    const countId = countMap[tipo];
    const count = document.querySelectorAll(`#${tbodyId} tr`).length;
    const countElement = document.getElementById(countId);
    if (countElement) countElement.textContent = count;
}
function verDetallesRecurso(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalDetallesRecurso'));
    const loading = document.getElementById('modalLoadingRecurso');
    const content = document.getElementById('modalContentRecurso');
    if (!loading || !content) return;
    loading.style.display = 'block';
    content.style.display = 'none';
    content.innerHTML = '';
    modal.show();
    fetch(`recursos_empresa.php?action=get_recurso_details&id=${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        const estadoBadges = {
            'pendiente': '<span class="badge badge-pendiente"><i class="fas fa-clock"></i> Pendiente de aprobacion</span>',
            'aprobado': '<span class="badge badge-aprobado"><i class="fas fa-check-circle"></i> Aprobado</span>',
            'rechazado': '<span class="badge badge-rechazado"><i class="fas fa-times-circle"></i> Rechazado</span>'
        };
        let html = `<div class="row"><div class="col-md-12"><div class="modal-details-section"><div class="modal-details-title"><i class="fas fa-building"></i> Informacion de Solicitud</div><div class="modal-details-row">
        <div class="modal-detail-item"><div class="modal-detail-label">Empresa</div><div class="modal-detail-value">${data.empresa_nombre}</div></div>
        <div class="modal-detail-item"><div class="modal-detail-label">Sucursal</div><div class="modal-detail-value">${data.sucursal_nombre}</div></div>`;
        if (data.personal_nombre) {
            html += `<div class="modal-detail-item"><div class="modal-detail-label">Personal Asignado</div><div class="modal-detail-value"><i class="fas fa-user me-1"></i>${data.personal_nombre}</div></div>`;
            if (data.dni) html += `<div class="modal-detail-item"><div class="modal-detail-label">DNI</div><div class="modal-detail-value">${data.dni}</div></div>`;
            if (data.cargo) html += `<div class="modal-detail-item"><div class="modal-detail-label">Cargo</div><div class="modal-detail-value">${data.cargo}</div></div>`;
        } else {
            html += `<div class="modal-detail-item"><div class="modal-detail-label">Asignacion</div><div class="modal-detail-value"><i class="fas fa-building me-1"></i>Recursos para la Sucursal</div></div>`;
        }
        html += `<div class="modal-detail-item"><div class="modal-detail-label">Estado</div><div class="modal-detail-value">${estadoBadges[data.estado] || estadoBadges['pendiente']}</div></div>
        <div class="modal-detail-item"><div class="modal-detail-label">Fecha de Creacion</div><div class="modal-detail-value">${data.created_at ? new Date(data.created_at).toLocaleString('es-AR') : 'N/A'}</div></div>
        <div class="modal-detail-item"><div class="modal-detail-label">Ultima Actualizacion</div><div class="modal-detail-value">${data.updated_at ? new Date(data.updated_at).toLocaleString('es-AR') : 'N/A'}</div></div></div></div>`;
        if (data.archivo_pdf) {
            html += `<div class="modal-details-section" style="border-left-color: #dc3545;">
            <div class="modal-details-title" style="color: #dc3545;">
            <i class="fas fa-file-pdf"></i> Documento Adjunto
            </div>
            <div class="modal-detail-item">
            <a href="../${data.archivo_pdf}" target="_blank" class="btn btn-danger btn-sm">
            <i class="fas fa-file-pdf me-2"></i>Ver PDF
            </a>
            <a href="../${data.archivo_pdf}" download class="btn btn-outline-danger btn-sm ms-2">
            <i class="fas fa-download me-2"></i>Descargar
            </a>
            </div>
            </div>`;
        }
        if (data.observaciones) {
            html += `<div class="modal-details-section"><div class="modal-details-title"><i class="fas fa-sticky-note"></i> Observaciones</div><div class="modal-detail-item"><div class="modal-detail-value" style="white-space: pre-wrap;">${data.observaciones}</div></div></div>`;
        }
        if (data.estado === 'rechazado' && data.motivo_rechazo) {
            html += `<div class="modal-details-section" style="border-left-color: #dc3545;"><div class="modal-details-title" style="color: #dc3545;"><i class="fas fa-times-circle"></i> Motivo de Rechazo</div><div class="modal-detail-item"><div class="modal-detail-value" style="white-space: pre-wrap; color: #dc3545;">${data.motivo_rechazo}</div></div></div>`;
        }
        if (data.estado === 'aprobado' && data.fecha_aprobacion) {
            html += `<div class="modal-details-section" style="border-left-color: #28a745;"><div class="modal-details-title" style="color: #28a745;"><i class="fas fa-check-circle"></i> Fecha de Aprobacion</div><div class="modal-detail-item"><div class="modal-detail-value">${new Date(data.fecha_aprobacion).toLocaleString('es-AR')}</div></div></div>`;
        }
        const tipos = [
            { key: 'chaleco', label: 'Chalecos', icon: 'fa-vest', badge: 'badge-chaleco' },
            { key: 'equipo_comunicacion', label: 'Equipos de Comunicacion', icon: 'fa-headset', badge: 'badge-comunicacion' },
            { key: 'armamento', label: 'Armamento', icon: 'fa-gun', badge: 'badge-armamento' },
            { key: 'vehiculo', label: 'Vehiculos', icon: 'fa-car', badge: 'badge-vehiculo' },
            { key: 'equipos_video_vigilancia', label: 'Video Vigilancia', icon: 'fa-video', badge: 'badge-video' }
        ];
        tipos.forEach(tipo => {
            if (data.items[tipo.key] && data.items[tipo.key].length > 0) {
                html += `<div class="item-section ${tipo.key}"><div class="section-badge ${tipo.badge}"><i class="fas ${tipo.icon}"></i> ${tipo.label} (${data.items[tipo.key].length})</div><div class="table-responsive"><table class="item-table table table-sm"><thead><tr>${Object.keys(data.items[tipo.key][0]).map(attr => `<th>${attr}</th>`).join('')}</tr></thead><tbody>${data.items[tipo.key].map(item => `<tr>${Object.values(item).map(val => `<td>${val || '-'}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`;
            }
        });
        html += `</div></div>`;
        content.innerHTML = html;
        const tituloElement = document.getElementById('modalTituloRecurso');
        if (tituloElement) tituloElement.textContent = `Detalles: ${data.sucursal_nombre}`;
        loading.style.display = 'none';
        content.style.display = 'block';
    })
    .catch(error => {
        console.error('Error:', error);
        loading.innerHTML = `<div class="alert alert-danger text-center p-4"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><div><strong>Error al cargar los datos</strong></div><small>${error.message}</small></div>`;
    });
}

// ? Función para cerrar la alerta de urgencia
function closeUrgencyAlert() {
    const alert = document.getElementById('urgencyAlert');
    if (alert) {
        alert.style.animation = 'slideInRight 0.5s ease reverse';
        setTimeout(() => alert.style.display = 'none', 500);
    }
}

// Auto-ocultar alerta después de 10 segundos
setTimeout(function() {
    const alert = document.getElementById('urgencyAlert');
    if (alert) {
        alert.style.animation = 'slideInRight 0.5s ease reverse';
        setTimeout(() => alert.style.display = 'none', 500);
    }
}, 10000);

// ? Toggle Sidebar con Persistencia de Estado
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleSidebarBtn');
    const toggleIcon = document.getElementById('toggleIcon');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;
    const sidebar = document.querySelector('.sidebar-moderno');
    
    // ? RESTAURAR ESTADO GUARDADO AL CARGAR
    const savedState = localStorage.getItem('sidebarCollapsed');
    const isMobile = window.innerWidth <= 991;
    
    if (!isMobile && savedState === 'true') {
        body.classList.add('sidebar-collapsed');
        if (toggleBtn) toggleBtn.classList.add('rotated');
        if (toggleIcon) toggleIcon.className = 'fas fa-indent';
    } else if (!isMobile) {
        body.classList.remove('sidebar-collapsed');
        if (toggleBtn) toggleBtn.classList.remove('rotated');
        if (toggleIcon) toggleIcon.className = 'fas fa-bars';
    }
    
    function toggleSidebar() {
        if (window.innerWidth <= 991) {
            // Mobile
            body.classList.toggle('sidebar-mobile-open');
            body.style.overflow = body.classList.contains('sidebar-mobile-open') ? 'hidden' : '';
            if (toggleIcon) {
                toggleIcon.className = body.classList.contains('sidebar-mobile-open')
                    ? 'fas fa-times'
                    : 'fas fa-bars';
            }
        } else {
            // Desktop
            body.classList.toggle('sidebar-collapsed');
            if (toggleBtn) toggleBtn.classList.toggle('rotated');
            if (toggleIcon) {
                toggleIcon.className = body.classList.contains('sidebar-collapsed')
                    ? 'fas fa-indent'
                    : 'fas fa-bars';
            }
            // ? GUARDAR ESTADO EN LOCALSTORAGE
            localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
        }
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            body.classList.remove('sidebar-mobile-open');
            body.style.overflow = '';
            if (toggleIcon) toggleIcon.className = 'fas fa-bars';
        });
    }
    
    // ? DETECTAR CAMBIO DE TAMAÑO DE VENTANA
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const isNowMobile = window.innerWidth <= 991;
            if (isNowMobile) {
                body.classList.remove('sidebar-collapsed');
                body.classList.remove('sidebar-mobile-open');
                body.style.overflow = '';
            }
        }, 250);
    });
});
</script>
</body>
</html>