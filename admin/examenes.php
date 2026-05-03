<?php
/**
* ============================================================================
* GESTIÓN DE EXÁMENES - VERSIÓN COMPLETA CON AUDITORÍA
* ============================================================================
* Incluye: CRUD completo de Exámenes, Preguntas, Opciones,
*          Asignación a Alumnos, Temporizador, Calificación,
*          Auditoría detallada, Exportación PDF, Validaciones,
*          Paginación, Búsqueda, Filtros Avanzados
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

$current_page = 'examenes';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ============================================================================
// 2. OBTENER DATOS AJAX (ALUMNOS POR EXAMEN, PREGUNTAS, ETC)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_alumnos_count' && isset($_GET['examen_id'])) {
    header('Content-Type: application/json');
    try {
        $examen_id = (int)$_GET['examen_id'];
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM examen_asignaciones WHERE examen_id = ?");
        $stmt->execute([$examen_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'total' => $result['total']]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'total' => 0]);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_preguntas' && isset($_GET['examen_id'])) {
    header('Content-Type: application/json');
    try {
        $examen_id = (int)$_GET['examen_id'];
        $stmt = $conn->prepare("
            SELECT p.*, 
            (SELECT COUNT(*) FROM opciones WHERE pregunta_id = p.id) as opciones_count
            FROM preguntas p
            WHERE p.examen_id = ?
            ORDER BY p.orden, p.id ASC
        ");
        $stmt->execute([$examen_id]);
        $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($preguntas as &$pregunta) {
            $stmt_opc = $conn->prepare("SELECT * FROM opciones WHERE pregunta_id = ? ORDER BY orden, id ASC");
            $stmt_opc->execute([$pregunta['id']]);
            $pregunta['opciones'] = $stmt_opc->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['success' => true, 'preguntas' => $preguntas]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_resultados' && isset($_GET['examen_id'])) {
    header('Content-Type: application/json');
    try {
        $examen_id = (int)$_GET['examen_id'];
        $stmt = $conn->prepare("
            SELECT ea.*, 
            CONCAT(p.nombre, ' ', p.apellido) as alumno_nombre,
            p.email as alumno_email
            FROM examen_asignaciones ea
            LEFT JOIN personal p ON ea.alumno_id = p.id
            WHERE ea.examen_id = ?
            ORDER BY ea.fecha_asignacion DESC
        ");
        $stmt->execute([$examen_id]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'resultados' => $resultados]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============================================================================
// 3. TOGGLE ACTIVO/INACTIVO EXAMEN (AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado_examen') {
    header('Content-Type: application/json');
    try {
        $examen_id = (int)($_POST['examen_id'] ?? 0);
        $nuevo_estado = ($_POST['estado'] === 'true') ? 1 : 0;
        
        if ($examen_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de examen inválido']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT titulo, activo FROM examenes WHERE id = :id");
        $stmt->execute([':id' => $examen_id]);
        $examen_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$examen_data) {
            echo json_encode(['success' => false, 'message' => 'Examen no encontrado']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE examenes SET activo = :activo WHERE id = :id");
        $stmt->execute([
            ':activo' => $nuevo_estado,
            ':id' => $examen_id
        ]);
        
        $detalles = [
            'accion' => 'toggle_estado',
            'id' => $examen_id,
            'titulo' => $examen_data['titulo'],
            'estado_anterior' => $examen_data['activo'],
            'estado_nuevo' => $nuevo_estado,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'EXAMEN_ESTADO_CAMBIADO', 'examenes', $examen_id, $detalles, $user['id']);
        
        echo json_encode([
            'success' => true,
            'message' => $nuevo_estado ? 'Examen activado correctamente' : 'Examen desactivado correctamente'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================================================
// 4. VERIFICAR/CREAR ESTRUCTURA DE TABLAS
// ============================================================================
try {
    // Tabla EXAMENES
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
                fecha_actualizacion TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
                INDEX idx_materia (materia_id),
                INDEX idx_activo (activo),
                INDEX idx_creador (creado_por)
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
    
    // Tabla OPCIONES
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
                respuestas JSON NULL,
                FOREIGN KEY (examen_id) REFERENCES examenes(id) ON DELETE CASCADE,
                INDEX idx_examen (examen_id),
                INDEX idx_alumno (alumno_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'examen_asignaciones', null, ['mensaje' => 'Tabla examen_asignaciones creada'], $user['id']);
    }
    
    // Tabla EXAMEN_RESPUESTAS
    $table_exists = $conn->query("SHOW TABLES LIKE 'examen_respuestas'")->rowCount() > 0;
    if (!$table_exists) {
        $conn->exec("
            CREATE TABLE examen_respuestas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asignacion_id INT NOT NULL,
                pregunta_id INT NOT NULL,
                respuesta_texto TEXT NULL,
                opcion_seleccionada INT NULL,
                es_correcta BOOLEAN NULL,
                puntos_obtenidos DECIMAL(5,2) DEFAULT 0,
                fecha_respuesta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (asignacion_id) REFERENCES examen_asignaciones(id) ON DELETE CASCADE,
                FOREIGN KEY (pregunta_id) REFERENCES preguntas(id) ON DELETE CASCADE,
                INDEX idx_asignacion (asignacion_id),
                INDEX idx_pregunta (pregunta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        logAuditoria($conn, 'TABLA_CREADA', 'examen_respuestas', null, ['mensaje' => 'Tabla examen_respuestas creada'], $user['id']);
    }
    
} catch (PDOException $e) {
    $error = "Error al verificar estructura de base de datos: " . $e->getMessage();
    error_log($error);
}

// ============================================================================
// 5. INICIALIZAR VARIABLES DE FILTROS
// ============================================================================
$search_titulo = $_GET['search_titulo'] ?? '';
$search_estado = $_GET['search_estado'] ?? 'todos';
$search_materia = $_GET['search_materia'] ?? '';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;

$columnas_permitidas = ['id', 'titulo', 'tiempo_limite_minutos', 'activo', 'fecha_creacion'];
$orden_columna = $_GET['orden'] ?? 'titulo';
$orden_direccion = strtoupper($_GET['direccion'] ?? 'ASC');

if (!in_array($orden_columna, $columnas_permitidas)) {
    $orden_columna = 'titulo';
}
if ($orden_direccion !== 'ASC' && $orden_direccion !== 'DESC') {
    $orden_direccion = 'ASC';
}
$orden_direccion_next = ($orden_direccion === 'ASC') ? 'DESC' : 'ASC';

// ============================================================================
// 6. MANEJAR CREACIÓN DE EXAMEN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_examen'])) {
    try {
        $materia_id = (int)($_POST['materia_id'] ?? 0);
        $modulo_id = !empty($_POST['modulo_id']) ? (int)$_POST['modulo_id'] : null;
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tiempo_limite_minutos = (int)($_POST['tiempo_limite_minutos'] ?? 60);
        $intentos_permitidos = (int)($_POST['intentos_permitidos'] ?? 1);
        $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
        $fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
        
        if (empty($titulo)) {
            throw new Exception('El título del examen es obligatorio');
        }
        if ($materia_id <= 0) {
            throw new Exception('Debe seleccionar una materia');
        }
        if ($tiempo_limite_minutos <= 0) {
            throw new Exception('El tiempo límite debe ser mayor a 0');
        }
        
        $stmt = $conn->prepare("
            INSERT INTO examenes (
                materia_id, modulo_id, titulo, descripcion, tiempo_limite_minutos,
                intentos_permitidos, fecha_inicio, fecha_fin, activo, creado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?)
        ");
        $stmt->execute([
            $materia_id, $modulo_id, $titulo, $descripcion, $tiempo_limite_minutos,
            $intentos_permitidos, $fecha_inicio, $fecha_fin, $user['id']
        ]);
        
        $examen_id = $conn->lastInsertId();
        
        $detalles = [
            'accion' => 'examen_creado',
            'id' => $examen_id,
            'titulo' => $titulo,
            'materia_id' => $materia_id,
            'tiempo_limite' => $tiempo_limite_minutos,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'EXAMEN_CREADO', 'examenes', $examen_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <div class='d-flex align-items-center'>
                <i class='fas fa-check-circle fa-2x me-3 text-success'></i>
                <div>
                    <h5 class='mb-1'><strong>¡Examen creado exitosamente!</strong></h5>
                    <p class='mb-0'><strong>Título:</strong> {$titulo}</p>
                    <p class='mb-0'><strong>Tiempo límite:</strong> {$tiempo_limite_minutos} minutos</p>
                </div>
            </div>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: examenes.php?ver_examen=' . $examen_id);
        exit;
        
    } catch (Exception $e) {
        logAuditoria($conn, 'ERROR_CREACION_EXAMEN', 'examenes', null, [
            'error' => $e->getMessage()
        ], $user['id']);
        
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: examenes.php');
        exit;
    }
}

// ============================================================================
// 7. MANEJAR ACTUALIZACIÓN DE EXAMEN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_examen'])) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $materia_id = (int)($_POST['materia_id'] ?? 0);
        $modulo_id = !empty($_POST['modulo_id']) ? (int)$_POST['modulo_id'] : null;
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tiempo_limite_minutos = (int)($_POST['tiempo_limite_minutos'] ?? 60);
        $intentos_permitidos = (int)($_POST['intentos_permitidos'] ?? 1);
        $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
        $fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        if ($id <= 0 || empty($titulo)) {
            throw new Exception('Datos inválidos para actualizar el examen');
        }
        
        $stmt = $conn->prepare("SELECT * FROM examenes WHERE id = ?");
        $stmt->execute([$id]);
        $datos_antiguos = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$datos_antiguos) {
            throw new Exception('Examen no encontrado');
        }
        
        $stmt = $conn->prepare("
            UPDATE examenes
            SET materia_id = ?, modulo_id = ?, titulo = ?, descripcion = ?,
                tiempo_limite_minutos = ?, intentos_permitidos = ?,
                fecha_inicio = ?, fecha_fin = ?, activo = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $materia_id, $modulo_id, $titulo, $descripcion,
            $tiempo_limite_minutos, $intentos_permitidos,
            $fecha_inicio, $fecha_fin, $activo, $id
        ]);
        
        $detalles = [
            'accion' => 'examen_actualizado',
            'id' => $id,
            'titulo' => $titulo,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'EXAMEN_ACTUALIZADO', 'examenes', $id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Examen actualizado exitosamente: <strong>{$titulo}</strong>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: examenes.php?ver_examen=' . $id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: examenes.php');
        exit;
    }
}

// ============================================================================
// 8. MANEJAR CREACIÓN DE PREGUNTA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_pregunta'])) {
    try {
        $examen_id = (int)($_POST['examen_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'multiple_choice';
        $enunciado = trim($_POST['enunciado'] ?? '');
        $puntos = (int)($_POST['puntos'] ?? 1);
        $orden = (int)($_POST['orden'] ?? 0);
        $respuesta_max_caracteres = (int)($_POST['respuesta_max_caracteres'] ?? 200);
        
        if ($examen_id <= 0 || empty($enunciado)) {
            throw new Exception('Datos inválidos para crear la pregunta');
        }
        
        $stmt = $conn->prepare("
            INSERT INTO preguntas (
                examen_id, tipo, enunciado, puntos, orden, respuesta_max_caracteres
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $examen_id, $tipo, $enunciado, $puntos, $orden, $respuesta_max_caracteres
        ]);
        
        $pregunta_id = $conn->lastInsertId();
        
        // Si es multiple choice, guardar opciones
        if ($tipo === 'multiple_choice' && isset($_POST['opciones'])) {
            foreach ($_POST['opciones'] as $index => $opcion_texto) {
                $texto = trim($opcion_texto);
                if (!empty($texto)) {
                    $es_correcta = isset($_POST['opcion_correcta']) && $_POST['opcion_correcta'] == $index ? 1 : 0;
                    $stmt_opc = $conn->prepare("
                        INSERT INTO opciones (pregunta_id, texto, es_correcta, orden)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt_opc->execute([$pregunta_id, $texto, $es_correcta, $index]);
                }
            }
        }
        
        $detalles = [
            'accion' => 'pregunta_creada',
            'id' => $pregunta_id,
            'examen_id' => $examen_id,
            'tipo' => $tipo,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'PREGUNTA_CREADA', 'preguntas', $pregunta_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Pregunta creada exitosamente
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: examenes.php?ver_examen=' . $examen_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: examenes.php');
        exit;
    }
}

// ============================================================================
// 9. MANEJAR ELIMINACIÓN DE PREGUNTA
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_pregunta'])) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $examen_id = (int)($_POST['examen_id'] ?? 0);
        
        if ($id <= 0) {
            throw new Exception('ID de pregunta inválido');
        }
        
        $stmt = $conn->prepare("SELECT * FROM preguntas WHERE id = ?");
        $stmt->execute([$id]);
        $pregunta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pregunta) {
            throw new Exception('Pregunta no encontrada');
        }
        
        $stmt = $conn->prepare("DELETE FROM preguntas WHERE id = ?");
        $stmt->execute([$id]);
        
        $detalles = [
            'accion' => 'pregunta_eliminada',
            'id' => $id,
            'examen_id' => $examen_id,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'PREGUNTA_ELIMINADA', 'preguntas', $id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Pregunta eliminada exitosamente
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: examenes.php?ver_examen=' . $examen_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: examenes.php');
        exit;
    }
}

// ============================================================================
// 10. MANEJAR ASIGNACIÓN DE EXAMEN A ALUMNOS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_examen'])) {
    try {
        $examen_id = (int)($_POST['examen_id'] ?? 0);
        $alumno_ids = $_POST['alumno_ids'] ?? [];
        
        if ($examen_id <= 0 || empty($alumno_ids)) {
            throw new Exception('Debe seleccionar al menos un alumno');
        }
        
        $stmt = $conn->prepare("SELECT * FROM examenes WHERE id = ?");
        $stmt->execute([$examen_id]);
        $examen = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$examen) {
            throw new Exception('Examen no encontrado');
        }
        
        $asignados = 0;
        foreach ($alumno_ids as $alumno_id) {
            $alumno_id = (int)$alumno_id;
            if ($alumno_id <= 0) continue;
            
            // Verificar si ya está asignado
            $stmt_check = $conn->prepare("SELECT id FROM examen_asignaciones WHERE examen_id = ? AND alumno_id = ?");
            $stmt_check->execute([$examen_id, $alumno_id]);
            if ($stmt_check->fetch()) continue;
            
            $stmt_insert = $conn->prepare("
                INSERT INTO examen_asignaciones (examen_id, alumno_id, estado)
                VALUES (?, ?, 'pendiente')
            ");
            $stmt_insert->execute([$examen_id, $alumno_id]);
            $asignados++;
        }
        
        $detalles = [
            'accion' => 'examen_asignado',
            'examen_id' => $examen_id,
            'alumnos_asignados' => $asignados,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'EXAMEN_ASIGNADO', 'examen_asignaciones', $examen_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Examen asignado a <strong>{$asignados}</strong> alumno(s) exitosamente
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: examenes.php?ver_examen=' . $examen_id);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: examenes.php');
        exit;
    }
}

// ============================================================================
// 11. MANEJAR CALIFICACIÓN DE EXAMEN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar_examen'])) {
    try {
        $asignacion_id = (int)($_POST['asignacion_id'] ?? 0);
        $nota = (float)($_POST['nota'] ?? 0);
        
        if ($asignacion_id <= 0) {
            throw new Exception('ID de asignación inválido');
        }
        
        $stmt = $conn->prepare("
            UPDATE examen_asignaciones
            SET estado = 'completado', nota = ?, fecha_fin = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nota, $asignacion_id]);
        
        $detalles = [
            'accion' => 'examen_calificado',
            'asignacion_id' => $asignacion_id,
            'nota' => $nota,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ];
        
        logAuditoria($conn, 'EXAMEN_CALIFICADO', 'examen_asignaciones', $asignacion_id, $detalles, $user['id']);
        
        $_SESSION['success'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert'>
            <i class='fas fa-check-circle me-2'></i>
            Examen calificado con nota: <strong>{$nota}</strong>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>
        ";
        
        header('Location: examenes.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
        header('Location: examenes.php');
        exit;
    }
}

// ============================================================================
// 12. OBTENER DATOS CON PAGINACIÓN
// ============================================================================
$examenes = [];
$total_registros = 0;
$total_paginas = 0;

try {
    $where_clauses = [];
    $params = [];
    
    if (!empty($search_titulo)) {
        $where_clauses[] = "e.titulo LIKE :search_titulo";
        $params[':search_titulo'] = '%' . $search_titulo . '%';
    }
    
    if ($search_estado !== 'todos') {
        $where_clauses[] = "e.activo = :activo";
        $params[':activo'] = ($search_estado === 'activos') ? 1 : 0;
    }
    
    if (!empty($search_materia)) {
        $where_clauses[] = "e.materia_id = :materia";
        $params[':materia'] = $search_materia;
    }
    
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    $count_sql = "SELECT COUNT(*) as total FROM examenes e $where_sql";
    $stmt_count = $conn->prepare($count_sql);
    $stmt_count->execute($params);
    $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    
    $orden_sql = "ORDER BY e.$orden_columna $orden_direccion";
    $limit_sql = "LIMIT $registros_por_pagina OFFSET $offset";
    
    $sql = "
        SELECT e.*, m.nombre as materia_nombre,
        CONCAT(p.nombre, ' ', p.apellido) as creador_nombre,
        (SELECT COUNT(*) FROM preguntas WHERE examen_id = e.id) as total_preguntas,
        (SELECT COUNT(*) FROM examen_asignaciones WHERE examen_id = e.id) as total_asignaciones
        FROM examenes e
        LEFT JOIN materias m ON e.materia_id = m.id
        LEFT JOIN personal p ON e.creado_por = p.id
        $where_sql
        $orden_sql
        $limit_sql
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $examenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener examen detallado
    $ver_examen = isset($_GET['ver_examen']) ? (int)$_GET['ver_examen'] : 0;
    $examen_detalle = null;
    $preguntas = [];
    $asignaciones = [];
    
    if ($ver_examen > 0) {
        $stmt = $conn->prepare("
            SELECT e.*, m.nombre as materia_nombre, mod.nombre as modulo_nombre,
            CONCAT(p.nombre, ' ', p.apellido) as creador_nombre
            FROM examenes e
            LEFT JOIN materias m ON e.materia_id = m.id
            LEFT JOIN modulos mod ON e.modulo_id = mod.id
            LEFT JOIN personal p ON e.creado_por = p.id
            WHERE e.id = ?
        ");
        $stmt->execute([$ver_examen]);
        $examen_detalle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($examen_detalle) {
            // Obtener preguntas
            $stmt = $conn->prepare("
                SELECT * FROM preguntas
                WHERE examen_id = ?
                ORDER BY orden, id ASC
            ");
            $stmt->execute([$ver_examen]);
            $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener opciones para cada pregunta
            foreach ($preguntas as &$pregunta) {
                $stmt_opc = $conn->prepare("
                    SELECT * FROM opciones
                    WHERE pregunta_id = ?
                    ORDER BY orden, id ASC
                ");
                $stmt_opc->execute([$pregunta['id']]);
                $pregunta['opciones'] = $stmt_opc->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Obtener asignaciones
            $stmt = $conn->prepare("
                SELECT ea.*,
                CONCAT(alu.nombre, ' ', alu.apellido) as alumno_nombre,
                alu.email as alumno_email
                FROM examen_asignaciones ea
                LEFT JOIN personal alu ON ea.alumno_id = alu.id
                WHERE ea.examen_id = ?
                ORDER BY ea.fecha_asignacion DESC
            ");
            $stmt->execute([$ver_examen]);
            $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Obtener materias disponibles
    $stmt = $conn->query("SELECT id, nombre FROM materias WHERE activo = 1 ORDER BY nombre");
    $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener módulos disponibles
    $stmt = $conn->query("SELECT id, nombre, materia_id FROM modulos WHERE activo = 1 ORDER BY nombre");
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener alumnos disponibles
    $stmt = $conn->query("
        SELECT id, nombre, apellido, email
        FROM personal
        WHERE rol = 'alumno' AND activo = 1
        ORDER BY apellido, nombre
    ");
    $alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $examenes = [];
    $error = "<strong>⚠️ Atención:</strong> No se pudieron cargar los exámenes. Error: " . htmlspecialchars($e->getMessage());
}

// ============================================================================
// 13. FUNCIONES DE UTILIDAD
// ============================================================================
function getTipoPreguntaBadge($tipo) {
    switch ($tipo) {
        case 'multiple_choice':
            return ['class' => 'bg-primary', 'icon' => 'fa-list-ul', 'label' => 'Opción Múltiple'];
        case 'seleccion':
            return ['class' => 'bg-info', 'icon' => 'fa-check-square', 'label' => 'Selección Múltiple'];
        case 'texto':
            return ['class' => 'bg-success', 'icon' => 'fa-font', 'label' => 'Respuesta Texto'];
        default:
            return ['class' => 'bg-secondary', 'icon' => 'fa-question', 'label' => $tipo];
    }
}

function getEstadoAsignacionBadge($estado) {
    switch ($estado) {
        case 'pendiente':
            return ['class' => 'bg-warning text-dark', 'label' => 'Pendiente'];
        case 'en_progreso':
            return ['class' => 'bg-info', 'label' => 'En Progreso'];
        case 'completado':
            return ['class' => 'bg-success', 'label' => 'Completado'];
        case 'vencido':
            return ['class' => 'bg-danger', 'label' => 'Vencido'];
        default:
            return ['class' => 'bg-secondary', 'label' => $estado];
    }
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Exámenes - Sistema de Seguridad</title>
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
        .pregunta-card {
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .pregunta-header {
            background: #e9ecef;
            padding: 10px 15px;
            border-radius: 4px 4px 0 0;
            font-weight: 600;
        }
        .opcion-item {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 8px;
            margin: 5px 0;
        }
        .opcion-correcta {
            border-left: 4px solid #27ae60;
            background: #d4edda;
        }
        .asignacion-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
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
        .timer-display {
            font-size: 1.5rem;
            font-weight: 700;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <?php $page_title = 'Gestión de Exámenes'; include '../includes/header.php'; ?>
    
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
                    <div class="stat-icon mb-2 text-primary"><i class="fas fa-clipboard-list fa-2x"></i></div>
                    <div class="stat-number"><?php echo $total_registros; ?></div>
                    <div class="stat-label">Exámenes Totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-success"><i class="fas fa-check-circle fa-2x"></i></div>
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as total FROM examenes WHERE activo = 1");
                        echo $stmt->fetch()['total'];
                        ?>
                    </div>
                    <div class="stat-label">Exámenes Activos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-info"><i class="fas fa-question-circle fa-2x"></i></div>
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as total FROM preguntas");
                        echo $stmt->fetch()['total'];
                        ?>
                    </div>
                    <div class="stat-label">Preguntas Totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-warning"><i class="fas fa-users fa-2x"></i></div>
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as total FROM examen_asignaciones WHERE estado = 'pendiente'");
                        echo $stmt->fetch()['total'];
                        ?>
                    </div>
                    <div class="stat-label">Pendientes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon mb-2 text-success"><i class="fas fa-graduation-cap fa-2x"></i></div>
                    <div class="stat-number">
                        <?php
                        $stmt = $conn->query("SELECT COUNT(*) as total FROM examen_asignaciones WHERE estado = 'completado'");
                        echo $stmt->fetch()['total'];
                        ?>
                    </div>
                    <div class="stat-label">Completados</div>
                </div>
            </div>
            
            <!-- VISTA DETALLADA DE EXAMEN -->
            <?php if ($ver_examen > 0 && $examen_detalle): ?>
            <div class="section-box">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>
                        <i class="fas fa-clipboard-list me-2"></i>
                        <?php echo htmlspecialchars($examen_detalle['titulo']); ?>
                        <?php if ($examen_detalle['activo']): ?>
                            <span class="badge bg-success ms-2">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2">Inactivo</span>
                        <?php endif; ?>
                    </h4>
                    <div>
                        <a href="examenes.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Volver al Listado
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#asignarExamenModal">
                            <i class="fas fa-user-plus me-2"></i>Asignar a Alumnos
                        </button>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-3">
                        <strong>Materia:</strong><br>
                        <?php echo htmlspecialchars($examen_detalle['materia_nombre'] ?? 'Sin asignar'); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Módulo:</strong><br>
                        <?php echo htmlspecialchars($examen_detalle['modulo_nombre'] ?? 'Todos'); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Tiempo Límite:</strong><br>
                        <span class="timer-display"><?php echo $examen_detalle['tiempo_limite_minutos']; ?> min</span>
                    </div>
                    <div class="col-md-3">
                        <strong>Intentos:</strong><br>
                        <?php echo $examen_detalle['intentos_permitidos']; ?>
                    </div>
                </div>
                
                <?php if (!empty($examen_detalle['descripcion'])): ?>
                <div class="alert alert-info">
                    <strong>Descripción:</strong> <?php echo htmlspecialchars($examen_detalle['descripcion']); ?>
                </div>
                <?php endif; ?>
                
                <!-- PREGUNTAS -->
                <div class="section-title" data-bs-toggle="collapse" data-bs-target="#preguntasSection">
                    <i class="fas fa-question-circle me-2"></i>Preguntas del Examen
                    <span class="badge bg-primary ms-2"><?php echo count($preguntas); ?></span>
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="preguntasSection" class="collapse show">
                    <div class="d-flex justify-content-between mb-3">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#crearPreguntaModal">
                            <i class="fas fa-plus me-2"></i>Nueva Pregunta
                        </button>
                    </div>
                    
                    <?php if (empty($preguntas)): ?>
                    <div class="text-center py-4 bg-light rounded">
                        <i class="fas fa-question-circle fa-3x text-muted mb-3"></i>
                        <h5>No hay preguntas registradas</h5>
                        <p class="text-muted">Crea la primera pregunta para este examen</p>
                    </div>
                    <?php else: ?>
                    <?php $num_pregunta = 1; ?>
                    <?php foreach ($preguntas as $pregunta): ?>
                    <?php $tipo_badge = getTipoPreguntaBadge($pregunta['tipo']); ?>
                    <div class="pregunta-card">
                        <div class="pregunta-header d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge <?php echo $tipo_badge['class']; ?> me-2">
                                    <i class="fas <?php echo $tipo_badge['icon']; ?>"></i>
                                    <?php echo $tipo_badge['label']; ?>
                                </span>
                                <strong>Pregunta <?php echo $num_pregunta++; ?>:</strong>
                                <?php echo htmlspecialchars(substr($pregunta['enunciado'], 0, 80)); ?>...
                                <span class="badge bg-secondary ms-2"><?php echo $pregunta['puntos']; ?> pts</span>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#eliminarPreguntaModal<?php echo $pregunta['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="p-3">
                            <p class="mb-2"><?php echo htmlspecialchars($pregunta['enunciado']); ?></p>
                            
                            <?php if (!empty($pregunta['opciones'])): ?>
                            <div class="mt-3">
                                <strong>Opciones:</strong>
                                <?php foreach ($pregunta['opciones'] as $opcion): ?>
                                <div class="opcion-item <?php echo $opcion['es_correcta'] ? 'opcion-correcta' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span><?php echo htmlspecialchars($opcion['texto']); ?></span>
                                        <?php if ($opcion['es_correcta']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Correcta</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($pregunta['tipo'] === 'texto'): ?>
                            <div class="mt-3">
                                <span class="badge bg-info">Respuesta máxima: <?php echo $pregunta['respuesta_max_caracteres']; ?> caracteres</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Modal Eliminar Pregunta -->
                    <div class="modal fade" id="eliminarPreguntaModal<?php echo $pregunta['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title"><i class="fas fa-trash"></i> Eliminar Pregunta</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST" action="">
                                    <div class="modal-body">
                                        <p>¿Está seguro de eliminar esta pregunta?</p>
                                        <p><strong><?php echo htmlspecialchars(substr($pregunta['enunciado'], 0, 100)); ?>...</strong></p>
                                        <input type="hidden" name="eliminar_pregunta" value="1">
                                        <input type="hidden" name="id" value="<?php echo $pregunta['id']; ?>">
                                        <input type="hidden" name="examen_id" value="<?php echo $ver_examen; ?>">
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
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- ASIGNACIONES -->
                <div class="section-title mt-4" data-bs-toggle="collapse" data-bs-target="#asignacionesSection">
                    <i class="fas fa-users me-2"></i>Alumnos Asignados
                    <span class="badge bg-info ms-2"><?php echo count($asignaciones); ?></span>
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="asignacionesSection" class="collapse show">
                    <?php if (empty($asignaciones)): ?>
                    <div class="text-center py-4 bg-light rounded">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No hay alumnos asignados</h5>
                        <p class="text-muted">Asigne este examen a los alumnos</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Alumno</th>
                                    <th>Email</th>
                                    <th>Estado</th>
                                    <th>Fecha Asignación</th>
                                    <th>Fecha Inicio</th>
                                    <th>Nota</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($asignaciones as $asignacion): ?>
                                <?php $estado_badge = getEstadoAsignacionBadge($asignacion['estado']); ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($asignacion['alumno_nombre'] ?? 'Sin nombre'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($asignacion['alumno_email'] ?? '-'); ?></td>
                                    <td><span class="badge <?php echo $estado_badge['class']; ?>"><?php echo $estado_badge['label']; ?></span></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($asignacion['fecha_asignacion'])); ?></td>
                                    <td><?php echo !empty($asignacion['fecha_inicio']) ? date('d/m/Y H:i', strtotime($asignacion['fecha_inicio'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($asignacion['nota'] !== null): ?>
                                        <strong class="text-success"><?php echo number_format($asignacion['nota'], 2); ?></strong>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($asignacion['estado'] === 'completado' && $asignacion['nota'] === null): ?>
                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#calificarModal<?php echo $asignacion['id']; ?>">
                                            <i class="fas fa-star"></i> Calificar
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- Modal Calificar -->
                                <div class="modal fade" id="calificarModal<?php echo $asignacion['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-success text-white">
                                                <h5 class="modal-title"><i class="fas fa-star"></i> Calificar Examen</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <div class="modal-body">
                                                    <p><strong>Alumno:</strong> <?php echo htmlspecialchars($asignacion['alumno_nombre']); ?></p>
                                                    <div class="mb-3">
                                                        <label class="form-label">Nota (0-100)</label>
                                                        <input type="number" name="nota" class="form-control" min="0" max="100" step="0.01" required>
                                                    </div>
                                                    <input type="hidden" name="calificar_examen" value="1">
                                                    <input type="hidden" name="asignacion_id" value="<?php echo $asignacion['id']; ?>">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="fas fa-check me-1"></i>Guardar Nota
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
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Modal Crear Pregunta -->
            <div class="modal fade" id="crearPreguntaModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nueva Pregunta</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="">
                            <div class="modal-body">
                                <input type="hidden" name="crear_pregunta" value="1">
                                <input type="hidden" name="examen_id" value="<?php echo $ver_examen; ?>">
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Tipo de Pregunta <span class="text-danger">*</span></label>
                                        <select name="tipo" class="form-select" id="tipoPregunta" required onchange="toggleOpciones()">
                                            <option value="multiple_choice">Opción Múltiple (1 correcta)</option>
                                            <option value="seleccion">Selección Múltiple (+1 correctas)</option>
                                            <option value="texto">Respuesta Texto (200 car.)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Puntos <span class="text-danger">*</span></label>
                                        <input type="number" name="puntos" class="form-control" value="1" min="1" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Enunciado <span class="text-danger">*</span></label>
                                        <textarea name="enunciado" class="form-control" rows="3" required></textarea>
                                    </div>
                                    
                                    <!-- Opciones para multiple choice -->
                                    <div class="col-12" id="opcionesContainer">
                                        <label class="form-label">Opciones</label>
                                        <div id="opcionesList">
                                            <div class="input-group mb-2">
                                                <input type="text" name="opciones[]" class="form-control" placeholder="Opción 1">
                                                <div class="input-group-text">
                                                    <input class="form-check-input mt-0" type="radio" name="opcion_correcta" value="0" checked>
                                                </div>
                                            </div>
                                            <div class="input-group mb-2">
                                                <input type="text" name="opciones[]" class="form-control" placeholder="Opción 2">
                                                <div class="input-group-text">
                                                    <input class="form-check-input mt-0" type="radio" name="opcion_correcta" value="1">
                                                </div>
                                            </div>
                                            <div class="input-group mb-2">
                                                <input type="text" name="opciones[]" class="form-control" placeholder="Opción 3">
                                                <div class="input-group-text">
                                                    <input class="form-check-input mt-0" type="radio" name="opcion_correcta" value="2">
                                                </div>
                                            </div>
                                            <div class="input-group mb-2">
                                                <input type="text" name="opciones[]" class="form-control" placeholder="Opción 4">
                                                <div class="input-group-text">
                                                    <input class="form-check-input mt-0" type="radio" name="opcion_correcta" value="3">
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarOpcion()">
                                            <i class="fas fa-plus"></i> Agregar Opción
                                        </button>
                                    </div>
                                    
                                    <div class="col-md-6" id="maxCaracteresContainer" style="display: none;">
                                        <label class="form-label">Máx. Caracteres</label>
                                        <input type="number" name="respuesta_max_caracteres" class="form-control" value="200" max="200">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Orden</label>
                                        <input type="number" name="orden" class="form-control" value="<?php echo count($preguntas) + 1; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Guardar Pregunta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Modal Asignar Examen -->
            <div class="modal fade" id="asignarExamenModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="fas fa-user-plus"></i> Asignar Examen a Alumnos</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="">
                            <div class="modal-body">
                                <input type="hidden" name="asignar_examen" value="1">
                                <input type="hidden" name="examen_id" value="<?php echo $ver_examen; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">Seleccionar Alumnos</label>
                                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                                        <?php foreach ($alumnos as $alumno): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="alumno_ids[]" value="<?php echo $alumno['id']; ?>" id="alumno_<?php echo $alumno['id']; ?>">
                                            <label class="form-check-label" for="alumno_<?php echo $alumno['id']; ?>">
                                                <?php echo htmlspecialchars($alumno['apellido'] . ', ' . $alumno['nombre']); ?>
                                                <small class="text-muted">(<?php echo htmlspecialchars($alumno['email']); ?>)</small>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllAlumnos()">Seleccionar Todos</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllAlumnos()">Deseleccionar Todos</button>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check me-1"></i>Asignar Examen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            
            <!-- LISTADO DE EXÁMENES -->
            <div class="section-box">
                <div class="section-title">
                    <i class="fas fa-table me-2"></i>Listado de Exámenes
                    <span class="badge bg-primary ms-2"><?php echo $total_registros; ?> registros</span>
                </div>
                
                <!-- FILTROS -->
                <div class="section-title" data-bs-toggle="collapse" data-bs-target="#filtrosExamenes" style="font-size: 1rem;">
                    <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="filtrosExamenes" class="collapse">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Título del Examen</label>
                            <input type="text" name="search_titulo" class="form-control" value="<?php echo htmlspecialchars($search_titulo); ?>" placeholder="Buscar por título...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Estado</label>
                            <select name="search_estado" class="form-select">
                                <option value="todos">Todos los exámenes</option>
                                <option value="activos" <?php echo ($search_estado === 'activos') ? 'selected' : ''; ?>>Solo Activos</option>
                                <option value="inactivos" <?php echo ($search_estado === 'inactivos') ? 'selected' : ''; ?>>Solo Inactivos</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Materia</label>
                            <select name="search_materia" class="form-select">
                                <option value="">Todas las materias</option>
                                <?php foreach ($materias as $materia): ?>
                                <option value="<?php echo $materia['id']; ?>" <?php echo ($search_materia == $materia['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($materia['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
                            <a href="examenes.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
                        </div>
                    </form>
                </div>
                
                <!-- NUEVO EXAMEN -->
                <div class="section-title" data-bs-toggle="collapse" data-bs-target="#nuevoExamenForm" style="font-size: 1rem;">
                    <i class="fas fa-plus-circle me-2"></i>Nuevo Examen
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </div>
                <div id="nuevoExamenForm" class="collapse mt-3">
                    <form method="POST" action="">
                        <input type="hidden" name="crear_examen" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Título del Examen <span class="text-danger">*</span></label>
                                <input type="text" name="titulo" class="form-control" required placeholder="Ej: Examen Parcial 1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Materia <span class="text-danger">*</span></label>
                                <select name="materia_id" class="form-select" required>
                                    <option value="">Seleccione una materia</option>
                                    <?php foreach ($materias as $materia): ?>
                                    <option value="<?php echo $materia['id']; ?>">
                                        <?php echo htmlspecialchars($materia['nombre']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Módulo (Opcional)</label>
                                <select name="modulo_id" class="form-select">
                                    <option value="">Todos los módulos</option>
                                    <?php foreach ($modulos as $modulo): ?>
                                    <option value="<?php echo $modulo['id']; ?>">
                                        <?php echo htmlspecialchars($modulo['nombre']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tiempo Límite (minutos) <span class="text-danger">*</span></label>
                                <input type="number" name="tiempo_limite_minutos" class="form-control" value="60" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Intentos Permitidos</label>
                                <input type="number" name="intentos_permitidos" class="form-control" value="1" min="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha Inicio</label>
                                <input type="datetime-local" name="fecha_inicio" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha Fin</label>
                                <input type="datetime-local" name="fecha_fin" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-2"></i>Crear Examen
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($examenes)): ?>
                <div class="text-center py-5 bg-light rounded">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5>No hay exámenes registrados</h5>
                    <p class="text-muted">Crea tu primer examen para comenzar</p>
                    <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#nuevoExamenForm">
                        <i class="fas fa-plus me-2"></i>Crear Examen
                    </button>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><a href="<?php echo generarUrlOrden('id', $orden_columna === 'id' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">ID <?php echo mostrarIconoOrden('id', $orden_columna, $orden_direccion); ?></a></th>
                                <th><a href="<?php echo generarUrlOrden('titulo', $orden_columna === 'titulo' ? $orden_direccion_next : 'ASC'); ?>" class="text-decoration-none text-dark">Título <?php echo mostrarIconoOrden('titulo', $orden_columna, $orden_direccion); ?></a></th>
                                <th>Materia</th>
                                <th>Tiempo</th>
                                <th>Preguntas</th>
                                <th>Asignados</th>
                                <th>Estado</th>
                                <th class="table-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($examenes as $examen): ?>
                            <tr>
                                <td><strong>#<?php echo $examen['id']; ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($examen['titulo']); ?></strong></td>
                                <td><?php echo htmlspecialchars($examen['materia_nombre'] ?? '-'); ?></td>
                                <td><?php echo $examen['tiempo_limite_minutos']; ?> min</td>
                                <td><span class="badge bg-info"><?php echo $examen['total_preguntas']; ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?php echo $examen['total_asignaciones']; ?></span></td>
                                <td>
                                    <?php if ($examen['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions">
                                    <div class="btn-group" role="group">
                                        <a href="examenes.php?ver_examen=<?php echo $examen['id']; ?>" class="btn btn-sm btn-outline-primary" title="Ver Detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editarExamenModal<?php echo $examen['id']; ?>" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="curso.php?ver_materia=<?php echo $examen['materia_id']; ?>" class="btn btn-sm btn-outline-info" title="Ver Materia">
                                            <i class="fas fa-book"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Modal Editar Examen -->
                            <div class="modal fade" id="editarExamenModal<?php echo $examen['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i class="fas fa-edit"></i>
                                                Editar Examen: <?php echo htmlspecialchars($examen['titulo']); ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="">
                                            <div class="modal-body">
                                                <input type="hidden" name="actualizar_examen" value="1">
                                                <input type="hidden" name="id" value="<?php echo $examen['id']; ?>">
                                                
                                                <div class="row g-3">
                                                    <div class="col-md-8">
                                                        <label class="form-label">Título <span class="text-danger">*</span></label>
                                                        <input type="text" name="titulo" class="form-control" value="<?php echo htmlspecialchars($examen['titulo']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Materia</label>
                                                        <select name="materia_id" class="form-select">
                                                            <?php foreach ($materias as $materia): ?>
                                                            <option value="<?php echo $materia['id']; ?>" <?php echo $examen['materia_id'] == $materia['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($materia['nombre']); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Tiempo Límite (min)</label>
                                                        <input type="number" name="tiempo_limite_minutos" class="form-control" value="<?php echo $examen['tiempo_limite_minutos']; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Intentos</label>
                                                        <input type="number" name="intentos_permitidos" class="form-control" value="<?php echo $examen['intentos_permitidos']; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Fecha Inicio</label>
                                                        <input type="datetime-local" name="fecha_inicio" class="form-control" value="<?php echo !empty($examen['fecha_inicio']) ? date('Y-m-d\TH:i', strtotime($examen['fecha_inicio'])) : ''; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Fecha Fin</label>
                                                        <input type="datetime-local" name="fecha_fin" class="form-control" value="<?php echo !empty($examen['fecha_fin']) ? date('Y-m-d\TH:i', strtotime($examen['fecha_fin'])) : ''; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Estado</label>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" name="activo" id="activo<?php echo $examen['id']; ?>" <?php echo $examen['activo'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="activo<?php echo $examen['id']; ?>">
                                                                <?php echo $examen['activo'] ? 'Activo' : 'Inactivo'; ?>
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
                    <nav aria-label="Paginación de exámenes">
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
    
    // Toggle opciones según tipo de pregunta
    function toggleOpciones() {
        const tipo = document.getElementById('tipoPregunta').value;
        const opcionesContainer = document.getElementById('opcionesContainer');
        const maxCaracteresContainer = document.getElementById('maxCaracteresContainer');
        
        if (tipo === 'texto') {
            opcionesContainer.style.display = 'none';
            maxCaracteresContainer.style.display = 'block';
        } else {
            opcionesContainer.style.display = 'block';
            maxCaracteresContainer.style.display = 'none';
        }
    }
    
    // Agregar opción dinámica
    function agregarOpcion() {
        const opcionesList = document.getElementById('opcionesList');
        const index = opcionesList.children.length;
        const div = document.createElement('div');
        div.className = 'input-group mb-2';
        div.innerHTML = `
            <input type="text" name="opciones[]" class="form-control" placeholder="Opción ${index + 1}">
            <div class="input-group-text">
                <input class="form-check-input mt-0" type="radio" name="opcion_correcta" value="${index}">
            </div>
        `;
        opcionesList.appendChild(div);
    }
    
    // Seleccionar todos los alumnos
    function selectAllAlumnos() {
        document.querySelectorAll('input[name="alumno_ids[]"]').forEach(cb => cb.checked = true);
    }
    
    // Deseleccionar todos los alumnos
    function deselectAllAlumnos() {
        document.querySelectorAll('input[name="alumno_ids[]"]').forEach(cb => cb.checked = false);
    }
    </script>
</body>
</html>