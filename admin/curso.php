<?php
/**
* ============================================================================
* GESTIÓN DE CURSOS - MATERIAS, MÓDULOS Y MATERIALES
* ============================================================================
* Incluye: CRUD completo de Materias, Módulos, Materiales,
*          Asignación de Profesores, Auditoría detallada,
*          Exportación PDF, Validaciones, Paginación, Búsqueda,
*          Enlace a Exámenes (examenes.php)
*
* @author Sistema de Seguridad
* @version 1.0 - Diseño Uniforme y Plano
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
if (!$auth->hasRole('administrador') && !$auth->hasRole('profesor') && !$auth->hasRole('super_admin')) {
    $_SESSION['error'] = 'Acceso denegado. Se requieren permisos de administrador o profesor.';
    header('Location: ../index.php');
    exit;
}

$current_page = 'cursos';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ============================================================================
// 2. OBTENER CANTIDAD DE MÓDULOS POR MATERIA (AJAX)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_modulos_count' && isset($_GET['materia_id'])) {
    header('Content-Type: application/json');
    try {
        $materia_id = (int)$_GET['materia_id'];
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM modulos WHERE materia_id = ?");
        $stmt->execute([$materia_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'total' => $result['total']]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'total' => 0]);
        exit;
    }
}

// ============================================================================
// 3. OBTENER MATERIALES POR MÓDULO (AJAX)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_materiales' && isset($_GET['modulo_id'])) {
    header('Content-Type: application/json');
    try {
        $modulo_id = (int)$_GET['modulo_id'];
        $stmt = $conn->prepare("
            SELECT m.*, CONCAT(p.nombre, ' ', p.apellido) as profesor_nombre
            FROM materiales m
            LEFT JOIN personal p ON m.profesor_id = p.id
            WHERE m.modulo_id = ?
            ORDER BY m.orden, m.fecha_creacion DESC
        ");
        $stmt->execute([$modulo_id]);
        $materiales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'materiales' => $materiales]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============================================================================
// 4. TOGGLE ACTIVO/INACTIVO MATERIA (AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado_materia') {
    header('Content-Type: application/json');
    try {
        $materia_id = (int)($_POST['materia_id'] ?? 0);
        $nuevo_estado = ($_POST['estado'] === 'true') ? 1 : 0;
        
        if ($materia_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de materia inválido']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT nombre, activo FROM materias WHERE id = :id");
        $stmt->execute([':id' => $materia_id]);
        $materia_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$materia_data) {
            echo json_encode(['success' => false, 'message' => 'Materia no encontrada']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE materias SET activo = :activo WHERE id = :id");
        $stmt->execute([
            ':activo' => $nuevo_estado,
            ':id' => $materia_id
        ]);
        
        $detalles = [
            'accion' => 'toggle_estado',
            'id' => $materia_id,
            'nombre' => $materia_data['nombre'],
            'estado_anterior' => $materia_data['activo'],
            'estado_nuevo' => $nuevo_estado,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'MATERIA_ESTADO_CAMBIADO', 'materias', $materia_id, $detalles, $user['id']);
        
        echo json_encode([
            'success' => true,
            'message' => $nuevo_estado ? 'Materia activada correctamente' : 'Materia desactivada correctamente'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================================================
// 5. VERIFICAR/CREAR ESTRUCTURA DE TABLAS
// ============================================================================
try {
    // Tabla MATERIAS
    $table_exists = $conn->query("SHOW TABLES LIKE 'materias'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE materias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(255) NOT NULL,
                descripcion TEXT NULL,
                codigo VARCHAR(50) NULL,
                profesor_id INT NULL,
                duracion_horas INT DEFAULT 0,
                creditos INT DEFAULT 0,
                activo BOOLEAN DEFAULT TRUE,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_nombre (nombre),
                INDEX idx_activo (activo),
                INDEX idx_profesor (profesor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'materias', null, ['mensaje' => 'Tabla materias creada'], $user['id']);
    }
    
    // Tabla MODULOS
    $table_exists = $conn->query("SHOW TABLES LIKE 'modulos'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE modulos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                materia_id INT NOT NULL,
                nombre VARCHAR(255) NOT NULL,
                descripcion TEXT NULL,
                orden INT DEFAULT 0,
                activo BOOLEAN DEFAULT TRUE,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
                INDEX idx_materia (materia_id),
                INDEX idx_orden (orden)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'modulos', null, ['mensaje' => 'Tabla modulos creada'], $user['id']);
    }
    
    // Tabla MATERIALES
    $table_exists = $conn->query("SHOW TABLES LIKE 'materiales'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE materiales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                modulo_id INT NOT NULL,
                profesor_id INT NULL,
                titulo VARCHAR(255) NOT NULL,
                descripcion TEXT NULL,
                tipo ENUM('lectura', 'enlace', 'video', 'documento', 'examen') DEFAULT 'lectura',
                contenido TEXT NULL,
                url VARCHAR(500) NULL,
                archivo_path VARCHAR(500) NULL,
                orden INT DEFAULT 0,
                activo BOOLEAN DEFAULT TRUE,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (modulo_id) REFERENCES modulos(id) ON DELETE CASCADE,
                INDEX idx_modulo (modulo_id),
                INDEX idx_profesor (profesor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'materiales', null, ['mensaje' => 'Tabla materiales creada'], $user['id']);
    }
    
    // Tabla EXAMENES (para referencia)
    $table_exists = $conn->query("SHOW TABLES LIKE 'examenes'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE examenes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                materia_id INT NOT NULL,
                modulo_id INT NULL,
                titulo VARCHAR(255) NOT NULL,
                descripcion TEXT NULL,
                tiempo_limite_minutos INT DEFAULT 60,
                intentos_permitidos INT DEFAULT 1,
                fecha_inicio DATETIME NULL,
                fecha_fin DATETIME NULL,
                activo BOOLEAN DEFAULT TRUE,
                creado_por INT NULL,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
                INDEX idx_materia (materia_id),
                INDEX idx_activo (activo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'examenes', null, ['mensaje' => 'Tabla examenes creada'], $user['id']);
    }
    
    // Tabla PREGUNTAS
    $table_exists = $conn->query("SHOW TABLES LIKE 'preguntas'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE preguntas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                examen_id INT NOT NULL,
                tipo ENUM('multiple_choice', 'seleccion', 'texto') DEFAULT 'multiple_choice',
                enunciado TEXT NOT NULL,
                puntos INT DEFAULT 1,
                orden INT DEFAULT 0,
                respuesta_max_caracteres INT DEFAULT 200,
                FOREIGN KEY (examen_id) REFERENCES examenes(id) ON DELETE CASCADE,
                INDEX idx_examen (examen_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'preguntas', null, ['mensaje' => 'Tabla preguntas creada'], $user['id']);
    }
    
    // Tabla OPCIONES (para multiple choice)
    $table_exists = $conn->query("SHOW TABLES LIKE 'opciones'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE opciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pregunta_id INT NOT NULL,
                texto VARCHAR(500) NOT NULL,
                es_correcta BOOLEAN DEFAULT FALSE,
                orden INT DEFAULT 0,
                FOREIGN KEY (pregunta_id) REFERENCES preguntas(id) ON DELETE CASCADE,
                INDEX idx_pregunta (pregunta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'opciones', null, ['mensaje' => 'Tabla opciones creada'], $user['id']);
    }
    
    // Tabla EXAMEN_ASIGNACIONES
    $table_exists = $conn->query("SHOW TABLES LIKE 'examen_asignaciones'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE examen_asignaciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                examen_id INT NOT NULL,
                alumno_id INT NOT NULL,
                estado ENUM('pendiente', 'en_progreso', 'completado', 'vencido') DEFAULT 'pendiente',
                fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                fecha_inicio DATETIME NULL,
                fecha_fin DATETIME NULL,
                nota DECIMAL(5,2) NULL,
                FOREIGN KEY (examen_id) REFERENCES examenes(id) ON DELETE CASCADE,
                INDEX idx_examen (examen_id),
                INDEX idx_alumno (alumno_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'examen_asignaciones', null, ['mensaje' => 'Tabla examen_asignaciones creada'], $user['id']);
    }
    
} catch (PDOException $e) {
    $error = "Error al verificar estructura de base de datos: " . $e->getMessage();
    error_log($error);
}

// ============================================================================
// 6. INICIALIZAR VARIABLES DE FILTROS
// ============================================================================
$search_nombre = $_GET['search_nombre'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_profesor = $_GET['search_profesor'] ?? '';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;

$columnas_permitidas = ['id', 'nombre', 'codigo', 'profesor_id', 'activo', 'fecha_creacion'];
$orden_columna = $_GET['orden'] ?? 'nombre';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'ASC');

if (!in_array($orden_columna, $columnas_permitidas)) {
    $orden_columna = 'nombre';
}
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') {
    $orden_direccion = 'ASC';
}
$orden_direccion_next = ($orden_direccion === 'ASC') ? 'DESC' : 'ASC';

// ============================================================================
// 7. MANEJAR CREACIÓN DE MATERIA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_materia'])) {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');
        $profesor_id = !empty($_POST['profesor_id']) ? (int)$_POST['profesor_id'] : null;
        $duracion_horas = (int)($_POST['duracion_horas'] ?? 0);
        $creditos = (int)($_POST['creditos'] ?? 0);
        
        if (empty($nombre)) {
            throw new Exception('El nombre de la materia es obligatorio');
        }
        
        if (!empty($codigo)) {
            $stmt = $conn->prepare("SELECT id FROM materias WHERE codigo = ?");
            $stmt->execute([$codigo]);
            if ($stmt->fetch()) {
                throw new Exception('El código ya está registrado en el sistema');
            }
        }
        
        $stmt = $conn->prepare("
            INSERT INTO materias (
                nombre, descripcion, codigo, profesor_id, duracion_horas, creditos, activo
            ) VALUES (?, ?, ?, ?, ?, ?, TRUE)
        ");
        $stmt->execute([
            $nombre, $descripcion, $codigo, $profesor_id, $duracion_horas, $creditos
        ]);
        
        $materia_id = $conn->lastInsertId();
        
        $detalles = [
            'accion' => 'materia_creada',
            'id' => $materia_id,
            'nombre' => $nombre,
            'codigo' => $codigo,
            'profesor_id' => $profesor_id,
            'duracion_horas' => $duracion_horas,
            'creditos' => $creditos,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'MATERIA_CREADA', 'materias', $materia_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <div class='d-flex align-items-center'>
                <i class='fas fa-check-circle fa-2x me-3 text-success'></i>
                <div>
                    <h5 class='mb-1'><strong>¡Materia creada exitosamente!</strong></h5>
                    <p class='mb-0'><strong>Nombre:</strong> {$nombre}</p>
                    <p class='mb-0'><strong>Código:</strong> {$codigo}</p>
                </div>
            </div>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: curso.php');
        exit;
        
    } catch (Exception $e) {
        logAuditoria($conn, 'ERROR_CREACION_MATERIA', 'materias', null, [
            'error' => $e->getMessage(),
            'datos_intento' => compact('nombre', 'codigo')
        ], $user['id']);
        
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: curso.php');
        exit;
    }
}

// ============================================================================
// 8. MANEJAR ACTUALIZACIÓN DE MATERIA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_materia'])) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');
        $profesor_id = !empty($_POST['profesor_id']) ? (int)$_POST['profesor_id'] : null;
        $duracion_horas = (int)($_POST['duracion_horas'] ?? 0);
        $creditos = (int)($_POST['creditos'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        if ($id <= 0 || empty($nombre)) {
            throw new Exception('Datos inválidos para actualizar la materia');
        }
        
        $stmt = $conn->prepare("SELECT * FROM materias WHERE id = ?");
        $stmt->execute([$id]);
        $datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$datos_antiguos) {
            throw new Exception('Materia no encontrada');
        }
        
        if (!empty($codigo)) {
            $stmt = $conn->prepare("SELECT id FROM materias WHERE codigo = ? AND id != ?");
            $stmt->execute([$codigo, $id]);
            if ($stmt->fetch()) {
                throw new Exception('El código ya está registrado en otra materia');
            }
        }
        
        $stmt = $conn->prepare("
            UPDATE materias
            SET nombre = ?, descripcion = ?, codigo = ?, profesor_id = ?, 
                duracion_horas = ?, creditos = ?, activo = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $nombre, $descripcion, $codigo, $profesor_id, 
            $duracion_horas, $creditos, $activo, $id
        ]);
        
        $datos_nuevos = [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'codigo' => $codigo,
            'profesor_id' => $profesor_id,
            'duracion_horas' => $duracion_horas,
            'creditos' => $creditos,
            'activo' => (bool)$activo
        ];
        
        $cambios = obtenerCambios($datos_antiguos, $datos_nuevos);
        
        $detalles = [
            'accion' => 'materia_actualizada',
            'id' => $id,
            'datos_anteriores' => $datos_antiguos,
            'datos_nuevos' => $datos_nuevos,
            'cambios' => $cambios,
            'campos_modificados' => array_keys($cambios),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'MATERIA_ACTUALIZADA', 'materias', $id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <div class='d-flex align-items-center'>
                <i class='fas fa-check-circle fa-2x me-3 text-success'></i>
                <div>
                    <h5 class='mb-1'><strong>¡Materia actualizada exitosamente!</strong></h5>
                    <p class='mb-0'><strong>Nombre:</strong> {$nombre}</p>
                </div>
            </div>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: curso.php');
        exit;
        
    } catch (Exception $e) {
        logAuditoria($conn, 'ERROR_ACTUALIZACION_MATERIA', 'materias', $_POST['id'] ?? null, [
            'error' => $e->getMessage()
        ], $user['id']);
        
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: curso.php');
        exit;
    }
}

// ============================================================================
// 9. MANEJAR CREACIÓN DE MÓDULO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_modulo'])) {
    try {
        $materia_id = (int)($_POST['materia_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $orden = (int)($_POST['orden'] ?? 0);
        
        if ($materia_id <= 0 || empty($nombre)) {
            throw new Exception('Datos inválidos para crear el módulo');
        }
        
        $stmt = $conn->prepare("
            INSERT INTO modulos (materia_id, nombre, descripcion, orden, activo)
            VALUES (?, ?, ?, ?, TRUE)
        ");
        $stmt->execute([$materia_id, $nombre, $descripcion, $orden]);
        
        $modulo_id = $conn->lastInsertId();
        
        $detalles = [
            'accion' => 'modulo_creado',
            'id' => $modulo_id,
            'materia_id' => $materia_id,
            'nombre' => $nombre,
            'orden' => $orden,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'MODULO_CREADO', 'modulos', $modulo_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Módulo creado exitosamente: <strong>{$nombre}</strong>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: curso.php?ver_materia=' . $materia_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: curso.php');
        exit;
    }
}

// ============================================================================
// 10. MANEJAR CREACIÓN DE MATERIAL
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_material'])) {
    try {
        $modulo_id = (int)($_POST['modulo_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo = $_POST['tipo'] ?? 'lectura';
        $contenido = trim($_POST['contenido'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $orden = (int)($_POST['orden'] ?? 0);
        $profesor_id = $user['id'];
        
        if ($modulo_id <= 0 || empty($titulo)) {
            throw new Exception('Datos inválidos para crear el material');
        }
        
        $stmt = $conn->prepare("
            INSERT INTO materiales (
                modulo_id, profesor_id, titulo, descripcion, tipo, contenido, url, orden, activo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)
        ");
        $stmt->execute([
            $modulo_id, $profesor_id, $titulo, $descripcion, $tipo, $contenido, $url, $orden
        ]);
        
        $material_id = $conn->lastInsertId();
        
        $detalles = [
            'accion' => 'material_creado',
            'id' => $material_id,
            'modulo_id' => $modulo_id,
            'titulo' => $titulo,
            'tipo' => $tipo,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'MATERIAL_CREADO', 'materiales', $material_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Material creado exitosamente: <strong>{$titulo}</strong>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        $stmt = $conn->prepare("SELECT materia_id FROM modulos WHERE id = ?");
        $stmt->execute([$modulo_id]);
        $materia_id = $stmt->fetchColumn();
        
        header('Location: curso.php?ver_materia=' . $materia_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: curso.php');
        exit;
    }
}

// ============================================================================
// 11. ELIMINAR MATERIAL
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_material'])) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $modulo_id = (int)($_POST['modulo_id'] ?? 0);
        
        if ($id <= 0) {
            throw new Exception('ID de material inválido');
        }
        
        $stmt = $conn->prepare("SELECT * FROM materiales WHERE id = ?");
        $stmt->execute([$id]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$material) {
            throw new Exception('Material no encontrado');
        }
        
        $stmt = $conn->prepare("DELETE FROM materiales WHERE id = ?");
        $stmt->execute([$id]);
        
        $detalles = [
            'accion' => 'material_eliminado',
            'id' => $id,
            'titulo' => $material['titulo'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'MATERIAL_ELIMINADO', 'materiales', $id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Material eliminado exitosamente
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: curso.php?ver_materia=' . $_POST['materia_id']);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: curso.php');
        exit;
    }
}

// ============================================================================
// 12. OBTENER DATOS CON PAGINACIÓN
// ============================================================================
$materias = [];
$total_registros = 0;
$total_paginas = 0;

try {
    $where_clauses = [];
    $params = [];
    
    if (!empty($search_nombre)) {
        $where_clauses[] = "m.nombre LIKE :search_nombre";
        $params[':search_nombre'] = '%' . $search_nombre . '%';
    }
    
    if ($search_estado !== 'todos') {
        $where_clauses[] = "m.activo = :activo";
        $params[':activo'] = ($search_estado === 'activas') ? 1 : 0;
    }
    
    if (!empty($search_profesor)) {
        $where_clauses[] = "m.profesor_id = :profesor";
        $params[':profesor'] = $search_profesor;
    }
    
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    $count_sql = "SELECT COUNT(*) as total FROM materias m $where_sql";
    $stmt_count = $conn->prepare($count_sql);
    $stmt_count->execute($params);
    $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    
    $orden_sql = "ORDER BY m.$orden_columna $orden_direccion";
    $limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
    
    $sql = "
        SELECT m.*, CONCAT(p.nombre, ' ', p.apellido) as profesor_nombre
        FROM materias m
        LEFT JOIN personal p ON m.profesor_id = p.id
        $where_sql
        $orden_sql
        $limit_sql
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener materias para vista detallada
    $ver_materia = isset($_GET['ver_materia']) ? (int)$_GET['ver_materia'] : 0;
    $materia_detalle = null;
    $modulos = [];
    
    if ($ver_materia > 0) {
        $stmt = $conn->prepare("
            SELECT m.*, CONCAT(p.nombre, ' ', p.apellido) as profesor_nombre
            FROM materias m
            LEFT JOIN personal p ON m.profesor_id = p.id
            WHERE m.id = ?
        ");
        $stmt->execute([$ver_materia]);
        $materia_detalle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($materia_detalle) {
            $stmt = $conn->prepare("
                SELECT * FROM modulos 
                WHERE materia_id = ? 
                ORDER BY orden, id ASC
            ");
            $stmt->execute([$ver_materia]);
            $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Cargar materiales para cada módulo
            foreach ($modulos as &$modulo) {
                $stmt = $conn->prepare("
                    SELECT mat.*, CONCAT(p.nombre, ' ', p.apellido) as profesor_nombre
                    FROM materiales mat
                    LEFT JOIN personal p ON mat.profesor_id = p.id
                    WHERE mat.modulo_id = ?
                    ORDER BY mat.orden, mat.id ASC
                ");
                $stmt->execute([$modulo['id']]);
                $modulo['materiales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
    
    // Obtener profesores disponibles
    $stmt = $conn->query("
        SELECT id, nombre, apellido, email 
        FROM personal 
        WHERE rol = 'profesor' AND activo = 1 
        ORDER BY apellido, nombre
    ");
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener alumnos disponibles
    $stmt = $conn->query("
        SELECT id, nombre, apellido, email 
        FROM personal 
        WHERE rol = 'alumno' AND activo = 1 
        ORDER BY apellido, nombre
    ");
    $alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $materias = [];
    $error = "<strong>⚠️ Atención:</strong> No se pudieron cargar las materias. Error: " . htmlspecialchars($e->getMessage());
}

// ============================================================================
// 13. FUNCIONES DE UTILIDAD
// ============================================================================
function getAccionBadge($accion) {
    $accion_lower = strtolower($accion);
    if (strpos($accion_lower, 'creada') !== false || strpos($accion_lower, 'creacion') !== false) {
        return ['class' => 'bg-success', 'icon' => 'fa-plus-circle', 'color' => '#27ae60'];
    }
    if (strpos($accion_lower, 'actualizada') !== false || strpos($accion_lower, 'modificacion') !== false) {
        return ['class' => 'bg-warning text-dark', 'icon' => 'fa-edit', 'color' => '#f39c12'];
    }
    if (strpos($accion_lower, 'eliminada') !== false || strpos($accion_lower, 'eliminacion') !== false) {
        return ['class' => 'bg-danger', 'icon' => 'fa-trash', 'color' => '#e74c3c'];
    }
    return ['class' => 'bg-secondary', 'icon' => 'fa-info-circle', 'color' => '#95a5a6'];
}

function obtenerCambios($antiguos, $nuevos) {
    $cambios = [];
    foreach ($nuevos as $key => $value) {
        if (!isset($antiguos[$key]) || $antiguos[$key] !== $value) {
            $cambios[$key] = [
                'anterior' => $antiguos[$key] ?? null,
                'nuevo' => $value
            ];
        }
    }
    return $cambios;
}

function generarUrlOrden($columna, $direccion) {
    $params = $_GET;
    $params['orden'] = $columna;
    $params['direccion'] = $direccion;
    return '?' . http_build_query($params);
}

function mostrarIconoOrden($columna, $orden_columna, $orden_direccion) {
    if ($columna === $orden_columna) {
        return $orden_direccion === 'ASC' ? '<i class="fas fa-sort-up ms-1"></i>' : '<i class="fas fa-sort-down ms-1"></i>';
    }
    return '<i class="fas fa-sort ms-1 text-muted"></i>';
}

function getTipoMaterialBadge($tipo) {
    switch ($tipo) {
        case 'lectura':
            return ['class' => 'bg-info', 'icon' => 'fa-book'];
        case 'enlace':
            return ['class' => 'bg-primary', 'icon' => 'fa-link'];
        case 'video':
            return ['class' => 'bg-danger', 'icon' => 'fa-video'];
        case 'documento':
            return ['class' => 'bg-warning text-dark', 'icon' => 'fa-file'];
        case 'examen':
            return ['class' => 'bg-success', 'icon' => 'fa-clipboard-check'];
        default:
            return ['class' => 'bg-secondary', 'icon' => 'fa-file'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Cursos - Sistema de Seguridad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/sweetalert2.min.css">
    <script src="../css/bootstrap.bundle.min.js"></script>
    <script src="../css/sweetalert2.all.min.js"></script>
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
        .table thead th {
            font-weight: 600;
            color: #495057;
            border: none;
            padding: 12px;
        }
        .modulo-card {
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .modulo-header {
            background: #e9ecef;
            padding: 10px 15px;
            border-radius: 4px 4px 0 0;
            font-weight: 600;
        }
        .material-item {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 10px;
            margin: 5px 0;
        }
        .pagination-custom {
            gap: 4px;
        }
        .pagination-custom .page-link {
            color: #495057;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px !important;
            padding: 8px 12px;
            margin: 0 2px;
        }
        .pagination-custom .page-item.active .page-link {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <?php $page_title = 'Gestión de Cursos'; include '../includes/header.php'; ?>
    
    <div class="dashboard">
        <!-- SIDEBAR -->
        <?php include '../includes/sidebar.php'; ?>
        
        <!-- CONTENIDO PRINCIPAL -->
        <div class="main-content" style="margin-left: 280px; padding: 20px;">
            
            <!-- MENSAJES -->
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
            
            <!-- ESTADÍSTICAS -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-primary"><i class="fas fa-book fa-2x"></i></div>
                    <div class="stat-number"><?php echo $total_registros; ?></div>
                    <div class="stat-label">Materias Totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as total FROM materias WHERE activo = 1");
                        echo $stmt->fetch()['total'];
                        ?>
                    </div>
                    <div class="stat-label">Materias Activas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-info"><i class="fas fa-chalkboard-teacher fa-2x"></i></div>
                    <div class="stat-number"><?php echo count($profesores); ?></div>
                    <div class="stat-label">Profesores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-warning"><i class="fas fa-clipboard-check fa-2x"></i></div>
                    <div class="stat-number" style="font-size: 1rem; margin-top: 10px;">
                        <a href="examenes.php" class="btn btn-warning w-100">
                            <i class="fas fa-external-link-alt me-2"></i>Ir a Exámenes
                        </a>
                    </div>
                    <div class="stat-label">Acceso Rápido</div>
                </div>
            </div>
            
            <!-- VISTA DETALLADA DE MATERIA -->
            <?php if ($ver_materia > 0 && $materia_detalle): ?>
            <div class="section-box">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>
                        <i class="fas fa-book me-2"></i>
                        <?php echo htmlspecialchars($materia_detalle['nombre']); ?>
                        <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($materia_detalle['codigo'] ?? 'Sin código'); ?></span>
                    </h4>
                    <a href="curso.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Volver al Listado
                    </a>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-3">
                        <strong>Profesor:</strong><br>
                        <?php echo htmlspecialchars($materia_detalle['profesor_nombre'] ?? 'Sin asignar'); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Duración:</strong><br>
                        <?php echo $materia_detalle['duracion_horas']; ?> horas
                    </div>
                    <div class="col-md-3">
                        <strong>Créditos:</strong><br>
                        <?php echo $materia_detalle['creditos']; ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Estado:</strong><br>
                        <?php if ($materia_detalle['activo']): ?>
                            <span class="badge bg-success">Activa</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactiva</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- MÓDULOS -->
                <div class="section-title" data-bs-toggle="collapse" data-bs-target="#modulosSection">
                    <i class="fas fa-layer-group me-2"></i>Módulos de la Materia
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="modulosSection" class="collapse show">
                    <div class="d-flex justify-content-between mb-3">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#crearModuloModal">
                            <i class="fas fa-plus me-2"></i>Nuevo Módulo
                        </button>
                        <a href="examenes.php?materia_id=<?php echo $ver_materia; ?>" class="btn btn-warning">
                            <i class="fas fa-clipboard-list me-2"></i>Crear Examen
                        </a>
                    </div>
                    
                    <?php if (empty($modulos)): ?>
                    <div class="text-center py-4 bg-light rounded">
                        <i class="fas fa-layer-group fa-3x text-muted mb-3"></i>
                        <h5>No hay módulos registrados</h5>
                        <p class="text-muted">Crea el primer módulo para esta materia</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($modulos as $modulo): ?>
                    <div class="modulo-card">
                        <div class="modulo-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-folder me-2"></i>
                                Módulo <?php echo $modulo['orden']; ?>: <?php echo htmlspecialchars($modulo['nombre']); ?>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#crearMaterialModal<?php echo $modulo['id']; ?>">
                                    <i class="fas fa-plus"></i> Material
                                </button>
                            </div>
                        </div>
                        <div class="p-3">
                            <?php if (!empty($modulo['descripcion'])): ?>
                            <p class="text-muted mb-3"><?php echo htmlspecialchars($modulo['descripcion']); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($modulo['materiales'])): ?>
                            <div class="materials-list">
                                <?php foreach ($modulo['materiales'] as $material): ?>
                                <?php $tipo_badge = getTipoMaterialBadge($material['tipo']); ?>
                                <div class="material-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge <?php echo $tipo_badge['class']; ?> me-2">
                                            <i class="fas <?php echo $tipo_badge['icon']; ?>"></i>
                                            <?php echo ucfirst($material['tipo']); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars($material['titulo']); ?></strong>
                                        <?php if (!empty($material['descripcion'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($material['descripcion'], 0, 100)); ?>...</small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if ($material['tipo'] === 'enlace' && !empty($material['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($material['url']); ?>" target="_blank" class="btn btn-sm btn-primary">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($material['tipo'] === 'examen'): ?>
                                        <a href="examenes.php?ver=<?php echo $material['contenido']; ?>" class="btn btn-sm btn-warning">
                                            <i class="fas fa-clipboard-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#eliminarMaterialModal<?php echo $material['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Modal Eliminar Material -->
                                <div class="modal fade" id="eliminarMaterialModal<?php echo $material['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title"><i class="fas fa-trash"></i> Eliminar Material</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <div class="modal-body">
                                                    <p>¿Está seguro de eliminar este material?</p>
                                                    <p><strong><?php echo htmlspecialchars($material['titulo']); ?></strong></p>
                                                    <input type="hidden" name="eliminar_material" value="1">
                                                    <input type="hidden" name="id" value="<?php echo $material['id']; ?>">
                                                    <input type="hidden" name="modulo_id" value="<?php echo $modulo['id']; ?>">
                                                    <input type="hidden" name="materia_id" value="<?php echo $ver_materia; ?>">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-danger">
                                                        <i class="fas fa-trash me-1"></i>Eliminar
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modal Crear Material -->
                                <div class="modal fade" id="crearMaterialModal<?php echo $modulo['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">
                                                    <i class="fas fa-plus-circle"></i>
                                                    Nuevo Material - Módulo: <?php echo htmlspecialchars($modulo['nombre']); ?>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <div class="modal-body">
                                                    <input type="hidden" name="crear_material" value="1">
                                                    <input type="hidden" name="modulo_id" value="<?php echo $modulo['id']; ?>">
                                                    
                                                    <div class="row g-3">
                                                        <div class="col-md-8">
                                                            <label class="form-label">Título <span class="text-danger">*</span></label>
                                                            <input type="text" name="titulo" class="form-control" required>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Tipo <span class="text-danger">*</span></label>
                                                            <select name="tipo" class="form-select" required>
                                                                <option value="lectura">Lectura</option>
                                                                <option value="enlace">Enlace</option>
                                                                <option value="video">Video</option>
                                                                <option value="documento">Documento</option>
                                                                <option value="examen">Examen</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Descripción</label>
                                                            <textarea name="descripcion" class="form-control" rows="2"></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Contenido / URL</label>
                                                            <textarea name="contenido" class="form-control" rows="4" placeholder="Contenido del material o ID del examen"></textarea>
                                                            <small class="text-muted">Para tipo "Enlace", ingresar URL completa. Para "Examen", ingresar ID del examen.</small>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">URL (si es enlace)</label>
                                                            <input type="url" name="url" class="form-control" placeholder="https://...">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Orden</label>
                                                            <input type="number" name="orden" class="form-control" value="0">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fas fa-save me-1"></i>Guardar Material
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No hay materiales en este módulo</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Modal Crear Módulo -->
            <div class="modal fade" id="crearModuloModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nuevo Módulo</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="">
                            <div class="modal-body">
                                <input type="hidden" name="crear_modulo" value="1">
                                <input type="hidden" name="materia_id" value="<?php echo $ver_materia; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">Nombre del Módulo <span class="text-danger">*</span></label>
                                    <input type="text" name="nombre" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea name="descripcion" class="form-control" rows="3"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Orden</label>
                                    <input type="number" name="orden" class="form-control" value="<?php echo count($modulos) + 1; ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Crear Módulo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            
            <!-- LISTADO DE MATERIAS -->
            <div class="section-box">
                <div class="section-title">
                    <i class="fas fa-table me-2"></i>Listado de Materias
                    <span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
                </div>
                
                <!-- FILTROS -->
                <div class="section-title" data-bs-toggle="collapse" data-bs-target="#filtrosCursos" style="font-size: 1rem;">
                    <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="filtrosCursos" class="collapse">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Nombre de Materia</label>
                            <input type="text" name="search_nombre" class="form-control" value="<?php echo htmlspecialchars($search_nombre); ?>" placeholder="Buscar por nombre...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Estado</label>
                            <select name="search_estado" class="form-select">
                                <option value="todos">Todas las materias</option>
                                <option value="activas" <?php echo ($search_estado === 'activas') ? 'selected' : ''; ?>>Solo Activas</option>
                                <option value="inactivas" <?php echo ($search_estado === 'inactivas') ? 'selected' : ''; ?>>Solo Inactivas</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Profesor</label>
                            <select name="search_profesor" class="form-select">
                                <option value="">Todos los profesores</option>
                                <?php foreach ($profesores as $prof): ?>
                                <option value="<?php echo $prof['id']; ?>" <?php echo ($search_profesor == $prof['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prof['apellido'] . ', ' . $prof['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
                            <a href="curso.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
                        </div>
                    </form>
                </div>
                
                <!-- NUEVA MATERIA -->
                <div class="section-title" data-bs-toggle="collapse" data-bs-target="#nuevaMateriaForm" style="font-size: 1rem;">
                    <i class="fas fa-plus-circle me-2"></i>Nueva Materia
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="nuevaMateriaForm" class="collapse mt-3">
                    <form method="POST" action="">
                        <input type="hidden" name="crear_materia" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre de la Materia <span class="text-danger">*</span></label>
                                <input type="text" name="nombre" class="form-control" required placeholder="Ej: Matemáticas I">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Código</label>
                                <input type="text" name="codigo" class="form-control" placeholder="Ej: MAT-101">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Profesor Responsable</label>
                                <select name="profesor_id" class="form-select">
                                    <option value="">Sin asignar</option>
                                    <?php foreach ($profesores as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>">
                                        <?php echo htmlspecialchars($prof['apellido'] . ', ' . $prof['nombre']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Duración (horas)</label>
                                <input type="number" name="duracion_horas" class="form-control" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Créditos</label>
                                <input type="number" name="creditos" class="form-control" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-2"></i>Registrar Materia
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($materias)): ?>
                <div class="text-center py-5 bg-light rounded">
                    <i class="fas fa-book fa-3x text-muted mb-3"></i>
                    <h5>No hay materias registradas</h5>
                    <p class="text-muted">Registra tu primera materia para comenzar</p>
                    <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaMateriaForm">
                        <i class="fas fa-plus me-2"></i>Crear Materia
                    </button>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><a href="<?php echo generarUrlOrden('id', $orden_columna === 'id' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?></a></th>
                                <th><a href="<?php echo generarUrlOrden('nombre', $orden_columna === 'nombre' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Materia <?php echo mostrarIconoOrden('nombre', $orden_columna, $orden_direccion); ?></a></th>
                                <th>Código</th>
                                <th>Profesor</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th class="table-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materias as $materia): ?>
                            <tr>
                                <td><strong>#<?php echo $materia['id']; ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($materia['nombre']); ?></strong></td>
                                <td><?php echo !empty($materia['codigo']) ? htmlspecialchars($materia['codigo']) : '<span class="text-muted">-</span>'; ?></td>
                                <td><?php echo !empty($materia['profesor_nombre']) ? htmlspecialchars($materia['profesor_nombre']) : '<span class="text-muted">Sin asignar</span>'; ?></td>
                                <td><?php echo $materia['duracion_horas']; ?> hs</td>
                                <td>
                                    <?php if ($materia['activo']): ?>
                                        <span class="badge bg-success">Activa</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions">
                                    <div class="btn-group" role="group">
                                        <a href="curso.php?ver_materia=<?php echo $materia['id']; ?>" class="btn btn-sm btn-outline-primary" title="Ver Módulos">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editarMateriaModal<?php echo $materia['id']; ?>" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="examenes.php?materia_id=<?php echo $materia['id']; ?>" class="btn btn-sm btn-outline-warning" title="Crear Examen">
                                            <i class="fas fa-clipboard-check"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Modal Editar Materia -->
                            <div class="modal fade" id="editarMateriaModal<?php echo $materia['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i class="fas fa-edit"></i>
                                                Editar Materia: <?php echo htmlspecialchars($materia['nombre']); ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="">
                                            <div class="modal-body">
                                                <input type="hidden" name="actualizar_materia" value="1">
                                                <input type="hidden" name="id" value="<?php echo $materia['id']; ?>">
                                                
                                                <div class="row g-3">
                                                    <div class="col-md-8">
                                                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                                                        <input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($materia['nombre']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Código</label>
                                                        <input type="text" name="codigo" class="form-control" value="<?php echo htmlspecialchars($materia['codigo'] ?? ''); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Profesor Responsable</label>
                                                        <select name="profesor_id" class="form-select">
                                                            <option value="">Sin asignar</option>
                                                            <?php foreach ($profesores as $prof): ?>
                                                            <option value="<?php echo $prof['id']; ?>" <?php echo ($materia['profesor_id'] ?? 0) == $prof['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($prof['apellido'] . ', ' . $prof['nombre']); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Duración (horas)</label>
                                                        <input type="number" name="duracion_horas" class="form-control" value="<?php echo $materia['duracion_horas']; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Créditos</label>
                                                        <input type="number" name="creditos" class="form-control" value="<?php echo $materia['creditos']; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Descripción</label>
                                                        <textarea name="descripcion" class="form-control" rows="2"><?php echo htmlspecialchars($materia['descripcion'] ?? ''); ?></textarea>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Estado</label>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" name="activo" id="activo<?php echo $materia['id']; ?>" <?php echo $materia['activo'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="activo<?php echo $materia['id']; ?>">
                                                                <?php echo $materia['activo'] ? 'Activa' : 'Inactiva'; ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-1"></i>Guardar Cambios
                                                </button>
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
                <div class="d-flex justify-content-center align-items-center mt-4 mb-3">
                    <nav aria-label="Paginación de materias">
                        <ul class="pagination pagination-custom mb-0">
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
                            <?php
                            $rango = 1;
                            $inicio = max(1, $pagina_actual - $rango);
                            $fin = min($total_paginas, $pagina_actual + $rango);
                            for ($i = $inicio; $i <= $fin; $i++):
                            ?>
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
                    <span class="ms-3 text-muted">
                        Página <strong><?php echo $pagina_actual; ?></strong> de <strong><?php echo $total_paginas; ?></strong>
                    </span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-cerrar alertas después de 5 segundos
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
        
        // Toggle de secciones colapsables
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(trigger => {
            trigger.addEventListener('click', function() {
                const target = this.getAttribute('data-bs-target');
                const icon = this.querySelector('.fa-chevron-down, .fa-chevron-up');
                if (icon) {
                    setTimeout(() => {
                        const isShown = document.querySelector(target).classList.contains('show');
                        icon.className = isShown ? 'fas fa-chevron-up float-end mt-1' : 'fas fa-chevron-down float-end mt-1';
                    }, 300);
                }
            });
        });
    });
    </script>
</body>
</html>