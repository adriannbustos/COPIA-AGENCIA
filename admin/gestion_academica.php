<?php
/**
* ============================================================================
* GESTIÓN ACADÉMICA - VERSIÓN COMPLETA CON ASISTENCIA Y CERTIFICADOS (ROL SUPERVISOR)
* ============================================================================
* Incluye: CRUD Alumnos/Materias, Asignaciones, Notas, Asistencia, Certificados Médicos,
*          Auditoría detallada, Exportación (CSV/JSON/PDF), Validaciones,
*          Paginación, Filtros Avanzados, Toggle de Estado.
*
* @author Sistema de Seguridad
* @version 1.4 - Toggle habilitar/deshabilitar en todas las pestañas
* @last_update 2026
* ============================================================================
*/
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// ============================================================================
// 1. VERIFICAR AUTENTICACIÓN Y PERMISOS
// ============================================================================
if (!$auth->isLoggedIn()) { header('Location: ../login.php'); exit; }
if (!$auth->hasRole('administrador') && !$auth->hasRole('supervisor')) {
$_SESSION['error'] = '<strong>❌ Acceso denegado:</strong> No tienes permisos para este módulo.';
header('Location: ../index.php'); exit;
}
$current_page = 'gestion_academica';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// ============================================================================
// 2. VERIFICAR/CREAR ESTRUCTURA DE TABLAS + MIGRACIÓN DE CURSOS
// ============================================================================
try {
$tablas = [
'cursos' => "CREATE TABLE IF NOT EXISTS cursos (
id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, codigo VARCHAR(20) UNIQUE NULL,
descripcion TEXT NULL, activo BOOLEAN DEFAULT TRUE, fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
'alumnos' => "CREATE TABLE IF NOT EXISTS alumnos (
id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, apellido VARCHAR(100) NOT NULL,
dni VARCHAR(20) UNIQUE NULL, email VARCHAR(100) UNIQUE NULL, activo BOOLEAN DEFAULT TRUE,
fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
'materias' => "CREATE TABLE IF NOT EXISTS materias (
id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, codigo VARCHAR(20) UNIQUE NULL,
descripcion TEXT NULL, activo BOOLEAN DEFAULT TRUE, fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
curso_id INT NULL DEFAULT NULL, INDEX idx_curso_materias (curso_id),
FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
'profesores_materias' => "CREATE TABLE IF NOT EXISTS profesores_materias (
id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, materia_id INT NOT NULL, activo BOOLEAN DEFAULT TRUE,
UNIQUE KEY unique_prof_mat (usuario_id, materia_id),
FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
'alumnos_materias' => "CREATE TABLE IF NOT EXISTS alumnos_materias (
id INT AUTO_INCREMENT PRIMARY KEY, alumno_id INT NOT NULL, materia_id INT NOT NULL, activo BOOLEAN DEFAULT TRUE,
UNIQUE KEY unique_alum_mat (alumno_id, materia_id),
FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
'notas' => "CREATE TABLE IF NOT EXISTS notas (
id INT AUTO_INCREMENT PRIMARY KEY, alumno_id INT NOT NULL, materia_id INT NOT NULL,
nota DECIMAL(5,2) NOT NULL CHECK (nota BETWEEN 0 AND 10), periodo VARCHAR(50) NOT NULL,
observacion TEXT NULL, registrado_por INT NULL, fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
activo BOOLEAN DEFAULT TRUE,
FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
'asistencias' => "CREATE TABLE IF NOT EXISTS asistencias (
id INT AUTO_INCREMENT PRIMARY KEY, alumno_id INT NOT NULL, materia_id INT NOT NULL,
fecha DATE NOT NULL, estado ENUM('presente','ausente','justificado') NOT NULL DEFAULT 'presente',
tiene_certificado BOOLEAN DEFAULT FALSE, num_certificado VARCHAR(50) NULL,
fecha_certificado DATE NULL, observacion TEXT NULL,
registrado_por INT NULL, fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
UNIQUE KEY unique_alum_mat_fecha (alumno_id, materia_id, fecha),
FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
];
foreach ($tablas as $sql) { $conn->exec($sql); }
// MIGRACIÓN: Agregar columna curso_id a materias si no existe (para vincular materias a cursos)
try {
$conn->exec("ALTER TABLE materias ADD COLUMN curso_id INT NULL DEFAULT NULL AFTER descripcion");
$conn->exec("ALTER TABLE materias ADD INDEX idx_curso_materias (curso_id)");
} catch (PDOException $e) {
if (strpos($e->getMessage(), '1060') === false && strpos($e->getMessage(), 'Duplicate') === false) {
error_log("Migración DB (Columna): " . $e->getMessage());
}
}
try {
$conn->exec("ALTER TABLE materias ADD CONSTRAINT fk_materias_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE SET NULL");
} catch (PDOException $e) {
if (strpos($e->getMessage(), '1061') === false && strpos($e->getMessage(), '1022') === false && strpos($e->getMessage(), 'Duplicate') === false) {
error_log("Migración DB (FK): " . $e->getMessage());
}
}
// MIGRACIÓN: Agregar columna 'activo' a tabla 'notas' si no existe
try {
$conn->exec("ALTER TABLE notas ADD COLUMN activo BOOLEAN DEFAULT TRUE AFTER fecha_registro");
} catch (PDOException $e) {
if (strpos($e->getMessage(), '1060') === false && strpos($e->getMessage(), 'Duplicate') === false) {
error_log("Migración DB (Columna activo en notas): " . $e->getMessage());
}
}
} catch (PDOException $e) {
$error = "Error al verificar estructura académica: " . $e->getMessage();
error_log($error);
}
// ============================================================================
// 3. MANEJO DE SOLICITUDES POST
// ============================================================================
// Toggle Alumno/Materia/Curso/Asignación/Nota/Asistencia (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
header('Content-Type: application/json');
try {
$id = (int)$_POST['id'];
// CORRECCIÓN: Aceptar tanto 'true'/'false' como '1'/'0' para el estado
$estado = (($_POST['estado'] === 'true' || $_POST['estado'] === '1') ? 1 : 0);
if ($_POST['action'] === 'toggle_alumno') {
$conn->prepare("UPDATE alumnos SET activo = ? WHERE id = ?")->execute([$estado, $id]);
logAuditoria($conn, 'ALUMNO_ESTADO_CAMBIADO', 'alumnos', $id, ['nuevo_estado'=>$estado], $user['id']);
} elseif ($_POST['action'] === 'toggle_materia') {
$conn->prepare("UPDATE materias SET activo = ? WHERE id = ?")->execute([$estado, $id]);
logAuditoria($conn, 'MATERIA_ESTADO_CAMBIADO', 'materias', $id, ['nuevo_estado'=>$estado], $user['id']);
} elseif ($_POST['action'] === 'toggle_curso') {
$conn->prepare("UPDATE cursos SET activo = ? WHERE id = ?")->execute([$estado, $id]);
logAuditoria($conn, 'CURSO_ESTADO_CAMBIADO', 'cursos', $id, ['nuevo_estado'=>$estado], $user['id']);
} elseif ($_POST['action'] === 'toggle_asistencia') {
$conn->prepare("UPDATE asistencias SET estado = CASE WHEN estado='presente' THEN 'ausente' WHEN estado='ausente' THEN 'presente' ELSE 'presente' END WHERE id = ?")->execute([$id]);
logAuditoria($conn, 'ASISTENCIA_ACTUALIZADA', 'asistencias', $id, ['accion'=>'toggle'], $user['id']);
} elseif ($_POST['action'] === 'toggle_profesor_materia') {
$conn->prepare("UPDATE profesores_materias SET activo = ? WHERE id = ?")->execute([$estado, $id]);
logAuditoria($conn, 'ASIGNACION_PROF_MAT_ESTADO_CAMBIADO', 'profesores_materias', $id, ['nuevo_estado'=>$estado], $user['id']);
} elseif ($_POST['action'] === 'toggle_alumno_materia') {
$conn->prepare("UPDATE alumnos_materias SET activo = ? WHERE id = ?")->execute([$estado, $id]);
logAuditoria($conn, 'ASIGNACION_ALUM_MAT_ESTADO_CAMBIADO', 'alumnos_materias', $id, ['nuevo_estado'=>$estado], $user['id']);
} elseif ($_POST['action'] === 'toggle_nota') {
$conn->prepare("UPDATE notas SET activo = ? WHERE id = ?")->execute([$estado, $id]);
logAuditoria($conn, 'NOTA_ESTADO_CAMBIADO', 'notas', $id, ['nuevo_estado'=>$estado], $user['id']);
}
echo json_encode(['success' => true]);
} catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
exit;
}
// Guardar Asistencia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_asistencia'])) {
try {
$alumno_id = (int)$_POST['alumno_id'];
$materia_id = (int)$_POST['materia_id'];
$fecha = $_POST['fecha'];
$estado = $_POST['estado'];
$tiene_cert = isset($_POST['tiene_certificado']) ? 1 : 0;
$num_cert = trim($_POST['num_certificado'] ?? '');
$fecha_cert = !empty($_POST['fecha_certificado']) ? $_POST['fecha_certificado'] : null;
$obs = trim($_POST['observacion'] ?? '');
if (empty($fecha)) throw new Exception('La fecha es obligatoria');
if ($estado === 'ausente' && $tiene_cert && (empty($num_cert) || empty($fecha_cert))) {
throw new Exception('Si posee certificado médico, debe completar el número y fecha de emisión');
}
$sql = "INSERT INTO asistencias (alumno_id, materia_id, fecha, estado, tiene_certificado, num_certificado, fecha_certificado, observacion, registrado_por)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE estado=?, tiene_certificado=?, num_certificado=?, fecha_certificado=?, observacion=?, registrado_por=?, fecha_registro=CURRENT_TIMESTAMP";
$stmt = $conn->prepare($sql);
$stmt->execute([
$alumno_id, $materia_id, $fecha, $estado, $tiene_cert, $num_cert, $fecha_cert, $obs, $user['id'],
$estado, $tiene_cert, $num_cert, $fecha_cert, $obs, $user['id']
]);
logAuditoria($conn, 'ASISTENCIA_REGISTRADA', 'asistencias', null,
['alum'=>$alumno_id, 'mat'=>$materia_id, 'fecha'=>$fecha, 'estado'=>$estado, 'cert'=>$tiene_cert], $user['id']);
$_SESSION['success'] = "✅ Asistencia registrada/actualizada correctamente.";
header('Location: gestion_academica.php?tab=asistencia'); exit;
} catch (Exception $e) { $_SESSION['error'] = "❌ Error: " . $e->getMessage(); header('Location: gestion_academica.php?tab=asistencia'); exit; }
}
// Crear/Actualizar Alumno/Materia/Curso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['guardar_alumno']) || isset($_POST['guardar_materia']) || isset($_POST['guardar_curso']))) {
$tipo = isset($_POST['guardar_alumno']) ? 'alumno' : (isset($_POST['guardar_curso']) ? 'curso' : 'materia');
$tabla = $tipo === 'alumno' ? 'alumnos' : ($tipo === 'curso' ? 'cursos' : 'materias');
$id = (int)($_POST['id'] ?? 0);
$nombre = trim($_POST['nombre']);
$extra1 = trim($_POST[$tipo==='alumno'?'apellido':($tipo==='curso'?'codigo':'codigo')] ?? '');
$extra2 = trim($_POST[$tipo==='alumno'?'dni':($tipo==='curso'?'descripcion':'descripcion')] ?? '');
$email = $tipo==='alumno' ? (trim($_POST['email'] ?? '')) : null;
$curso_id = $tipo==='materia' ? (!empty($_POST['curso_id']) ? (int)$_POST['curso_id'] : null) : null;
try {
if ($id > 0) {
if ($tipo === 'alumno') {
$stmt = $conn->prepare("UPDATE alumnos SET nombre=?, apellido=?, dni=?, email=?, activo=1 WHERE id=?");
$stmt->execute([$nombre, $extra1, $extra2, $email, $id]);
} elseif ($tipo === 'curso') {
$stmt = $conn->prepare("UPDATE cursos SET nombre=?, codigo=?, descripcion=?, activo=1 WHERE id=?");
$stmt->execute([$nombre, $extra1, $extra2, $id]);
} else {
$stmt = $conn->prepare("UPDATE materias SET nombre=?, codigo=?, descripcion=?, curso_id=?, activo=1 WHERE id=?");
$stmt->execute([$nombre, $extra2, $extra1, $curso_id, $id]);
}
} else {
if ($tipo === 'alumno') {
$stmt = $conn->prepare("INSERT INTO alumnos (nombre, apellido, dni, email, activo) VALUES (?, ?, ?, ?, 1)");
$stmt->execute([$nombre, $extra1, $extra2, $email]);
} elseif ($tipo === 'curso') {
$stmt = $conn->prepare("INSERT INTO cursos (nombre, codigo, descripcion, activo) VALUES (?, ?, ?, 1)");
$stmt->execute([$nombre, $extra1, $extra2]);
} else {
$stmt = $conn->prepare("INSERT INTO materias (nombre, codigo, descripcion, curso_id, activo) VALUES (?, ?, ?, ?, 1)");
$stmt->execute([$nombre, $extra2, $extra1, $curso_id]);
}
}
$log_id = $id > 0 ? $id : $conn->lastInsertId();
logAuditoria($conn, strtoupper($tipo).'_GUARDADO', $tabla, $log_id, ['accion'=>$id>0?'actualizado':'creado'], $user['id']);
$_SESSION['success'] = "✅ " . ucfirst($tipo) . " " . ($id > 0 ? 'actualizado' : 'creado') . " exitosamente.";
header('Location: gestion_academica.php' . ($tipo==='curso' ? '?tab=cursos' : '')); exit;
} catch (Exception $e) { $_SESSION['error'] = "❌ Error: " . $e->getMessage(); header('Location: gestion_academica.php' . ($tipo==='curso' ? '?tab=cursos' : '')); exit; }
}
// Asignaciones (Prof/Mat y Alum/Mat)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['asignar_profesor_materia']) || isset($_POST['asignar_alumno_materia']))) {
$tabla = isset($_POST['asignar_profesor_materia']) ? 'profesores_materias' : 'alumnos_materias';
$col1 = isset($_POST['asignar_profesor_materia']) ? 'usuario_id' : 'alumno_id';
$col2 = 'materia_id';
$val1 = (int)$_POST[$col1]; $val2 = (int)$_POST[$col2];
try {
$conn->prepare("INSERT IGNORE INTO $tabla ($col1, $col2, activo) VALUES (?, ?, 1)")->execute([$val1, $val2]);
logAuditoria($conn, 'ASIGNACION_REGISTRADA', $tabla, null, [$col1=>$val1, $col2=>$val2], $user['id']);
$_SESSION['success'] = "✅ Asignación registrada correctamente.";
header('Location: gestion_academica.php?tab=asignaciones'); exit;
} catch (Exception $e) { $_SESSION['error'] = "❌ Error: " . $e->getMessage(); header('Location: gestion_academica.php?tab=asignaciones'); exit; }
}
// Guardar Nota
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_nota'])) {
try {
$alumno_id = (int)$_POST['alumno_id']; $materia_id = (int)$_POST['materia_id'];
$periodo = trim($_POST['periodo']); $nota = (float)$_POST['nota']; $obs = trim($_POST['observacion'] ?? '');
if ($nota < 0 || $nota > 10) throw new Exception('La nota debe estar entre 0 y 10');
$stmt = $conn->prepare("SELECT id FROM notas WHERE alumno_id=? AND materia_id=? AND periodo=?");
$stmt->execute([$alumno_id, $materia_id, $periodo]);
$nota_id = $stmt->fetchColumn();
if ($nota_id) {
$conn->prepare("UPDATE notas SET nota=?, observacion=?, registrado_por=?, activo=1 WHERE id=?")->execute([$nota, $obs, $user['id'], $nota_id]);
} else {
$conn->prepare("INSERT INTO notas (alumno_id, materia_id, nota, periodo, observacion, registrado_por, activo) VALUES (?, ?, ?, ?, ?, ?, 1)")->execute([$alumno_id, $materia_id, $nota, $periodo, $obs, $user['id']]);
}
logAuditoria($conn, 'NOTA_REGISTRADA', 'notas', $nota_id ?? null, ['alum'=>$alumno_id, 'mat'=>$materia_id, 'nota'=>$nota], $user['id']);
$_SESSION['success'] = "✅ Nota registrada correctamente.";
header('Location: gestion_academica.php?tab=notas'); exit;
} catch (Exception $e) { $_SESSION['error'] = "❌ Error: " . $e->getMessage(); header('Location: gestion_academica.php?tab=notas'); exit; }
}
// ============================================================================
// 4. EXPORTAR ASISTENCIA (CSV/PDF)
// ============================================================================
if (isset($_GET['exportar_asistencia']) && in_array($_GET['exportar_asistencia'], ['csv', 'pdf'])) {
try {
$sql = "SELECT a.id, a.fecha, a.estado, a.tiene_certificado, a.num_certificado, a.observacion,
u.nombre as alumno_nombre, u.apellido as alumno_apellido, m.nombre as materia,
us.username as registrado_por, a.fecha_registro
FROM asistencias a
JOIN alumnos u ON a.alumno_id = u.id
JOIN materias m ON a.materia_id = m.id
LEFT JOIN usuarios us ON a.registrado_por = us.id
ORDER BY a.fecha DESC";
$stmt = $conn->query($sql);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
logAuditoria($conn, 'ASISTENCIA_EXPORTADA', 'asistencias', null, ['formato'=>$_GET['exportar_asistencia'], 'total'=>count($data)], $user['id']);
if ($_GET['exportar_asistencia'] === 'csv') {
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=asistencia_' . date('Y-m-d_His') . '.csv');
$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Fecha','Alumno','Materia','Estado','Certificado','N° Certificado','Observación','Registrado por','Fecha Registro']);
foreach ($data as $r) {
fputcsv($out, [$r['id'], $r['fecha'], $r['alumno_apellido'].', '.$r['alumno_nombre'], $r['materia'], $r['estado'],
$r['tiene_certificado']?'Sí':'No', $r['num_certificado'], $r['observacion'], $r['registrado_por'] ?? 'Sistema', $r['fecha_registro']]);
}
fclose($out); exit;
}
if ($_GET['exportar_asistencia'] === 'pdf') {
if (ob_get_level()) ob_end_clean();
require_once('../vendor/fpdf/fpdf.php');
$pdf = new FPDF('P','mm','A4'); $pdf->AddPage(); $pdf->SetFont('Arial','',10);
$pdf->SetFillColor(13,110,253); $pdf->SetTextColor(255,255,255);
$pdf->Cell(0,10,'REPORTE DE ASISTENCIA - ' . date('d/m/Y'),0,1,'C',true);
$pdf->Ln(5); $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial','B',8);
$pdf->SetFillColor(240,240,240);
$headers = ['Fecha','Alumno','Materia','Estado','Cert.','Observación'];
$widths = [25,40,35,20,20,50];
foreach($headers as $i=>$h) $pdf->Cell($widths[$i],7,$h,1,0,'C',true);
$pdf->Ln(); $pdf->SetFont('Arial','',8);
foreach($data as $r) {
$pdf->Cell($widths[0],6,$r['fecha'],1);
$pdf->Cell($widths[1],6,substr($r['alumno_apellido'].', '.$r['alumno_nombre'],0,25),1);
$pdf->Cell($widths[2],6,substr($r['materia'],0,20),1);
$pdf->Cell($widths[3],6,ucfirst($r['estado']),1);
$pdf->Cell($widths[4],6,$r['tiene_certificado']?'Sí':'No',1);
$pdf->Cell($widths[5],6,substr($r['observacion']??'',0,30),1);
$pdf->Ln();
}
$pdf->Output('D', 'asistencia_' . date('Y-m-d_His') . '.pdf'); exit;
}
} catch (Exception $e) { $_SESSION['error'] = 'Error exportando: '.$e->getMessage(); header('Location: gestion_academica.php?tab=asistencia'); exit; }
}
// ============================================================================
// 5. OBTENER DATOS CON PAGINACIÓN Y FILTROS AVANZADOS
// ============================================================================
$tab_activa = $_GET['tab'] ?? 'alumnos';
$search = $_GET['search'] ?? '';
$curso_filtro = $_GET['curso_id'] ?? '';
$estado_filtro = $_GET['estado_filtro'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$periodo_filtro = $_GET['periodo_filtro'] ?? '';
$estado_asistencia_filtro = $_GET['estado_asistencia_filtro'] ?? '';
$con_certificado_filtro = $_GET['con_certificado_filtro'] ?? '';
$nota_min = $_GET['nota_min'] ?? '';
$nota_max = $_GET['nota_max'] ?? '';
$registros_por_pagina = 10;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $registros_por_pagina;
// Cursos
$cursos = []; $total_cursos = 0;
if ($tab_activa === 'cursos' || $tab_activa === 'materias' || $tab_activa === 'asignaciones' || $tab_activa === 'notas' || $tab_activa === 'asistencia') {
$where = "WHERE 1=1";
$params = [];
if (!empty($search) && $tab_activa === 'cursos') {
$where .= " AND (nombre LIKE ? OR codigo LIKE ?)";
$params = array_merge($params, ["%$search%", "%$search%"]);
}
if (!empty($estado_filtro) && $tab_activa === 'cursos') {
$where .= " AND activo = ?";
$params[] = ($estado_filtro === 'activo') ? 1 : 0;
}
$stmt = $conn->prepare("SELECT COUNT(*) FROM cursos $where"); $stmt->execute($params); $total_cursos = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT * FROM cursos $where ORDER BY nombre LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$registros_por_pagina, $offset])); $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Alumnos
$alumnos = []; $total_alumnos = 0;
if ($tab_activa === 'alumnos' || $tab_activa === 'asignaciones' || $tab_activa === 'notas' || $tab_activa === 'asistencia') {
$where = "WHERE 1=1";
$params = [];
if (!empty($search) && $tab_activa === 'alumnos') {
$where .= " AND (nombre LIKE ? OR apellido LIKE ? OR dni LIKE ?)";
$params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if (!empty($estado_filtro) && $tab_activa === 'alumnos') {
$where .= " AND activo = ?";
$params[] = ($estado_filtro === 'activo') ? 1 : 0;
}
$stmt = $conn->prepare("SELECT COUNT(*) FROM alumnos $where"); $stmt->execute($params); $total_alumnos = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT * FROM alumnos $where ORDER BY apellido, nombre LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$registros_por_pagina, $offset])); $alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Materias
$materias = []; $total_materias = 0;
if ($tab_activa === 'materias' || $tab_activa === 'asignaciones' || $tab_activa === 'notas' || $tab_activa === 'asistencia') {
$where = "WHERE 1=1";
$params = [];
if (!empty($search) && $tab_activa === 'materias') {
$where .= " AND (nombre LIKE ? OR codigo LIKE ?)";
$params = array_merge($params, ["%$search%", "%$search%"]);
}
if (!empty($curso_filtro) && $tab_activa === 'materias') {
$where .= " AND curso_id = ?";
$params[] = $curso_filtro;
}
if (!empty($estado_filtro) && $tab_activa === 'materias') {
$where .= " AND activo = ?";
$params[] = ($estado_filtro === 'activo') ? 1 : 0;
}
$stmt = $conn->prepare("SELECT COUNT(*) FROM materias $where"); $stmt->execute($params); $total_materias = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT m.*, c.nombre as curso_nombre FROM materias m LEFT JOIN cursos c ON m.curso_id = c.id $where ORDER BY m.nombre LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$registros_por_pagina, $offset])); $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Profesores
$stmt_prof = $conn->query("SELECT id, username, nombre_completo FROM usuarios WHERE rol = 'profesores' AND activo = 1 ORDER BY username");
$profesores = $stmt_prof->fetchAll(PDO::FETCH_ASSOC);
// Asignaciones
$asignaciones = [];
$asignaciones_alumnos = [];
if ($tab_activa === 'asignaciones') {
$where_prof = "WHERE 1=1";
$params_prof = [];
if (!empty($search)) {
$where_prof .= " AND (u.username LIKE ? OR u.nombre_completo LIKE ? OR m.nombre LIKE ?)";
$params_prof = array_merge($params_prof, ["%$search%", "%$search%", "%$search%"]);
}
if (!empty($curso_filtro)) {
$where_prof .= " AND m.curso_id = ?";
$params_prof[] = $curso_filtro;
}
if (!empty($estado_filtro)) {
$where_prof .= " AND pm.activo = ?";
$params_prof[] = ($estado_filtro === 'activo') ? 1 : 0;
}
$stmt = $conn->prepare("SELECT pm.id, u.username, u.nombre_completo as nombre_prof, m.nombre as materia, c.nombre as curso_nombre, pm.activo FROM profesores_materias pm JOIN usuarios u ON pm.usuario_id = u.id JOIN materias m ON pm.materia_id = m.id LEFT JOIN cursos c ON m.curso_id = c.id $where_prof ORDER BY c.nombre, m.nombre LIMIT 50");
$stmt->execute($params_prof);
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$where_alum = "WHERE 1=1";
$params_alum = [];
if (!empty($search)) {
$where_alum .= " AND (a.nombre LIKE ? OR a.apellido LIKE ? OR m.nombre LIKE ?)";
$params_alum = array_merge($params_alum, ["%$search%", "%$search%", "%$search%"]);
}
if (!empty($curso_filtro)) {
$where_alum .= " AND m.curso_id = ?";
$params_alum[] = $curso_filtro;
}
if (!empty($estado_filtro)) {
$where_alum .= " AND am.activo = ?";
$params_alum[] = ($estado_filtro === 'activo') ? 1 : 0;
}
$stmt2 = $conn->prepare("SELECT am.id, a.nombre, a.apellido, m.nombre as materia, c.nombre as curso_nombre, am.activo FROM alumnos_materias am JOIN alumnos a ON am.alumno_id = a.id JOIN materias m ON am.materia_id = m.id LEFT JOIN cursos c ON m.curso_id = c.id $where_alum ORDER BY c.nombre, m.nombre LIMIT 50");
$stmt2->execute($params_alum);
$asignaciones_alumnos = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}
// Notas
$notas = [];
if ($tab_activa === 'notas') {
$where = "WHERE 1=1";
$params = [];
if (!empty($search)) {
$where .= " AND (a.nombre LIKE ? OR a.apellido LIKE ? OR m.nombre LIKE ?)";
$params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if (!empty($curso_filtro)) {
$where .= " AND m.curso_id = ?";
$params[] = $curso_filtro;
}
if (!empty($periodo_filtro)) {
$where .= " AND n.periodo LIKE ?";
$params[] = "%$periodo_filtro%";
}
if (!empty($nota_min)) {
$where .= " AND n.nota >= ?";
$params[] = (float)$nota_min;
}
if (!empty($nota_max)) {
$where .= " AND n.nota <= ?";
$params[] = (float)$nota_max;
}
if (!empty($fecha_desde)) {
$where .= " AND n.fecha_registro >= ?";
$params[] = $fecha_desde;
}
if (!empty($fecha_hasta)) {
$where .= " AND n.fecha_registro <= ?";
$params[] = $fecha_hasta;
}
// Agregar filtro por estado de nota (activo/inactivo)
if (!empty($estado_filtro) && $tab_activa === 'notas') {
$where .= " AND n.activo = ?";
$params[] = ($estado_filtro === 'activo') ? 1 : 0;
}
$stmt = $conn->prepare("SELECT n.id, a.nombre, a.apellido, m.nombre as materia, c.nombre as curso_nombre, n.nota, n.periodo, n.observacion, n.fecha_registro, u.username as registrado_por, n.activo FROM notas n JOIN alumnos a ON n.alumno_id = a.id JOIN materias m ON n.materia_id = m.id LEFT JOIN cursos c ON m.curso_id = c.id LEFT JOIN usuarios u ON n.registrado_por = u.id $where ORDER BY n.fecha_registro DESC LIMIT 50");
$stmt->execute($params);
$notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Asistencias
$asistencias = []; $total_asistencias = 0;
if ($tab_activa === 'asistencia') {
$where = "WHERE 1=1";
$params = [];
if (!empty($search)) {
$where .= " AND (u.apellido LIKE ? OR u.nombre LIKE ? OR m.nombre LIKE ? OR u.dni LIKE ?)";
$params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if (!empty($curso_filtro)) {
$where .= " AND m.curso_id = ?";
$params[] = $curso_filtro;
}
if (!empty($estado_asistencia_filtro)) {
$where .= " AND a.estado = ?";
$params[] = $estado_asistencia_filtro;
}
if (!empty($con_certificado_filtro)) {
if ($con_certificado_filtro === 'si') {
$where .= " AND a.tiene_certificado = 1";
} elseif ($con_certificado_filtro === 'no') {
$where .= " AND (a.tiene_certificado = 0 OR a.tiene_certificado IS NULL)";
}
}
if (!empty($fecha_desde)) {
$where .= " AND a.fecha >= ?";
$params[] = $fecha_desde;
}
if (!empty($fecha_hasta)) {
$where .= " AND a.fecha <= ?";
$params[] = $fecha_hasta;
}
$stmt = $conn->prepare("SELECT COUNT(*) FROM asistencias a JOIN alumnos u ON a.alumno_id = u.id JOIN materias m ON a.materia_id = m.id $where");
$stmt->execute($params); $total_asistencias = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT a.*, u.nombre, u.apellido, m.nombre as materia, c.nombre as curso_nombre, us.username as registrado_por
FROM asistencias a
JOIN alumnos u ON a.alumno_id = u.id
JOIN materias m ON a.materia_id = m.id
LEFT JOIN cursos c ON m.curso_id = c.id
LEFT JOIN usuarios us ON a.registrado_por = us.id
$where ORDER BY a.fecha DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$registros_por_pagina, $offset])); $asistencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Utilidades
function generarUrlPagina($page, $tab) {
$params = $_GET;
$params['pagina'] = $page;
$params['tab'] = $tab;
return '?' . http_build_query($params);
}
function formatearFecha($fecha) { return $fecha ? date('d/m/Y', strtotime($fecha)) : '-'; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión Académica - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../css/sweetalert2.all.min.js"></script>
<style>
:root { --primary-color: #0d6efd; --bg-color: #f8f9fa; --card-border: #dee2e6; --text-color: #212529; }
body { padding-top: 80px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-color); color: var(--text-color); }
.section-box { background: #ffffff; border: 1px solid var(--card-border); border-radius: 4px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.section-title { font-size: 1.25rem; font-weight: 600; color: #495057; margin-bottom: 15px; border-bottom: 1px solid var(--card-border); padding-bottom: 10px; }
.stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
.stat-card { background: #ffffff; border: 1px solid var(--card-border); border-radius: 4px; padding: 15px; text-align: center; }
.stat-number { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); }
.stat-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; }
.table-container { background: #ffffff; border: 1px solid var(--card-border); border-radius: 4px; overflow: hidden; }
.table { margin-bottom: 0; }
.table thead { background-color: #f8f9fa; border-bottom: 2px solid var(--card-border); }
.table thead th { font-weight: 600; color: #495057; border: none; padding: 12px; }
.table tbody tr { border-bottom: 1px solid var(--card-border); }
.table tbody tr:hover { background-color: #f8f9fa; }
.form-label { font-weight: 600; font-size: 0.9rem; color: #495057; }
.btn { border-radius: 4px; font-weight: 500; padding: 8px 16px; }
.btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
.nav-tabs .nav-link.active { color: var(--primary-color); border-bottom-color: #fff; font-weight: 600; }
.nav-tabs .nav-link { color: #495057; font-weight: 500; }
.pagination-custom { gap: 4px; }
.pagination-custom .page-link { color: #495057; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px !important; padding: 8px 12px; margin: 0 2px; font-weight: 500; min-width: 40px; text-align: center; }
.pagination-custom .page-item.active .page-link { background-color: #0d6efd; border-color: #0d6efd; color: #ffffff; }
.badge-estado { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
.badge-activo { background: #d1e7dd; color: #0f5132; }
.badge-inactivo { background: #f8d7da; color: #842029; }
.badge-asistencia-presente { background: #d1e7dd; color: #0f5132; }
.badge-asistencia-ausente { background: #f8d7da; color: #842029; }
.badge-asistencia-justificado { background: #fff3cd; color: #664d03; }
.certificado-fields { display: none; background: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px dashed #6c757d; margin-top: 10px; }
.badge-curso { background: #0dcaf0; color: #055160; padding: 3px 8px; border-radius: 3px; font-size: 0.75rem; }
.filtro-avanzado { background: #f8f9fa; padding: 12px; border-radius: 4px; border-left: 3px solid var(--primary-color); margin-bottom: 10px; }
</style>
</head>
<body>
<?php $page_title = 'Gestión Académica'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<!-- MENSAJES -->
<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card"><div class="stat-icon mb-2 text-primary"><i class="fas fa-user-graduate fa-2x"></i></div><div class="stat-number"><?php echo $total_alumnos; ?></div><div class="stat-label">Alumnos</div></div>
<div class="stat-card"><div class="stat-icon mb-2 text-info"><i class="fas fa-layer-group fa-2x"></i></div><div class="stat-number"><?php echo $total_cursos; ?></div><div class="stat-label">Cursos</div></div>
<div class="stat-card"><div class="stat-icon mb-2 text-success"><i class="fas fa-book fa-2x"></i></div><div class="stat-number"><?php echo $total_materias; ?></div><div class="stat-label">Materias</div></div>
<div class="stat-card"><div class="stat-icon mb-2 text-warning"><i class="fas fa-clipboard-check fa-2x"></i></div><div class="stat-number"><?php echo count($notas); ?></div><div class="stat-label">Notas</div></div>
<div class="stat-card"><div class="stat-icon mb-2 text-danger"><i class="fas fa-calendar-check fa-2x"></i></div><div class="stat-number"><?php echo $total_asistencias; ?></div><div class="stat-label">Registros Asistencia</div></div>
</div>
<!-- TABS -->
<ul class="nav nav-tabs mb-4">
<li class="nav-item"><a class="nav-link <?php echo $tab_activa==='alumnos'?'active':''; ?>" href="?tab=alumnos">👨‍🎓 Alumnos</a></li>
<li class="nav-item"><a class="nav-link <?php echo $tab_activa==='cursos'?'active':''; ?>" href="?tab=cursos">🎓 Cursos</a></li>
<li class="nav-item"><a class="nav-link <?php echo $tab_activa==='materias'?'active':''; ?>" href="?tab=materias">📚 Materias</a></li>
<li class="nav-item"><a class="nav-link <?php echo $tab_activa==='asignaciones'?'active':''; ?>" href="?tab=asignaciones">🔗 Asignaciones</a></li>
<li class="nav-item"><a class="nav-link <?php echo $tab_activa==='notas'?'active':''; ?>" href="?tab=notas">📝 Notas</a></li>
<li class="nav-item"><a class="nav-link <?php echo $tab_activa==='asistencia'?'active':''; ?>" href="?tab=asistencia">📋 Asistencia</a></li>
</ul>
<!-- FILTROS AVANZADOS -->
<div class="section-box">
<div class="section-title" data-bs-toggle="collapse" data-bs-target="#contenidoFiltros" style="cursor: pointer;" title="Clic para mostrar/ocultar filtros">
<i class="fas fa-filter me-2"></i>Filtros de Búsqueda Avanzados <i class="fas fa-chevron-down float-end mt-1"></i>
</div>
<div id="contenidoFiltros" class="collapse show">
<form method="GET" action="" class="row g-3">
<input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab_activa); ?>">
<input type="hidden" name="pagina" value="1">
<div class="col-md-4">
<label class="form-label">Buscar por texto</label>
<input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nombre, apellido, materia, DNI o código...">
</div>
<?php if ($tab_activa === 'materias' || $tab_activa === 'asignaciones' || $tab_activa === 'notas' || $tab_activa === 'asistencia' || $tab_activa === 'cursos'): ?>
<div class="col-md-3">
<label class="form-label">Filtrar por Curso</label>
<select name="curso_id" class="form-select">
<option value="">Todos los cursos</option>
<?php foreach($cursos as $c): ?>
<option value="<?php echo $c['id']; ?>" <?php echo $curso_filtro == $c['id'] ? 'selected' : ''; ?>>
<?php echo htmlspecialchars($c['nombre']); ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>
<?php if ($tab_activa === 'alumnos' || $tab_activa === 'cursos' || $tab_activa === 'materias' || $tab_activa === 'asignaciones' || $tab_activa === 'notas'): ?>
<div class="col-md-2">
<label class="form-label">Estado</label>
<select name="estado_filtro" class="form-select">
<option value="">Todos</option>
<option value="activo" <?php echo $estado_filtro === 'activo' ? 'selected' : ''; ?>>Activo</option>
<option value="inactivo" <?php echo $estado_filtro === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
</select>
</div>
<?php endif; ?>
<?php if ($tab_activa === 'notas'): ?>
<div class="col-md-2">
<label class="form-label">Periodo</label>
<input type="text" name="periodo_filtro" class="form-control" value="<?php echo htmlspecialchars($periodo_filtro); ?>" placeholder="Ej: 1° Trim">
</div>
<div class="col-md-2">
<label class="form-label">Nota mín</label>
<input type="number" name="nota_min" class="form-control" step="0.01" min="0" max="10" value="<?php echo htmlspecialchars($nota_min); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Nota máx</label>
<input type="number" name="nota_max" class="form-control" step="0.01" min="0" max="10" value="<?php echo htmlspecialchars($nota_max); ?>">
</div>
<?php endif; ?>
<?php if ($tab_activa === 'asistencia'): ?>
<div class="col-md-2">
<label class="form-label">Estado Asist.</label>
<select name="estado_asistencia_filtro" class="form-select">
<option value="">Todos</option>
<option value="presente" <?php echo $estado_asistencia_filtro === 'presente' ? 'selected' : ''; ?>>Presente</option>
<option value="ausente" <?php echo $estado_asistencia_filtro === 'ausente' ? 'selected' : ''; ?>>Ausente</option>
<option value="justificado" <?php echo $estado_asistencia_filtro === 'justificado' ? 'selected' : ''; ?>>Justificado</option>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Cert. Médico</label>
<select name="con_certificado_filtro" class="form-select">
<option value="">Todos</option>
<option value="si" <?php echo $con_certificado_filtro === 'si' ? 'selected' : ''; ?>>Con certificado</option>
<option value="no" <?php echo $con_certificado_filtro === 'no' ? 'selected' : ''; ?>>Sin certificado</option>
</select>
</div>
<?php endif; ?>
<?php if ($tab_activa === 'notas' || $tab_activa === 'asistencia'): ?>
<div class="col-md-2">
<label class="form-label">Desde</label>
<input type="date" name="fecha_desde" class="form-control" value="<?php echo htmlspecialchars($fecha_desde); ?>">
</div>
<div class="col-md-2">
<label class="form-label">Hasta</label>
<input type="date" name="fecha_hasta" class="form-control" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
</div>
<?php endif; ?>
<div class="col-md-12 d-flex align-items-end gap-2">
<button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrar</button>
<a href="?tab=<?php echo $tab_activa; ?>" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Limpiar</a>
</div>
</form>
</div>
</div>
<!-- CONTENIDO POR TAB -->
<?php if ($tab_activa === 'cursos'): ?>
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<span><i class="fas fa-layer-group me-2"></i>Listado de Cursos</span>
<button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalCurso"><i class="fas fa-plus me-1"></i>Nuevo Curso</button>
</div>
<div class="table-responsive">
<table class="table table-hover">
<thead><tr><th>ID</th><th>Código</th><th>Curso</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach ($cursos as $c): ?>
<tr><td>#<?php echo $c['id']; ?></td><td><?php echo htmlspecialchars($c['codigo']??'-'); ?></td><td><?php echo htmlspecialchars($c['nombre']); ?></td><td><?php echo htmlspecialchars($c['descripcion']??'-'); ?></td><td><span class="badge-estado <?php echo $c['activo']?'badge-activo':'badge-inactivo'; ?>"><?php echo $c['activo']?'Activo':'Inactivo'; ?></span></td><td><div class="btn-group"><button class="btn btn-sm btn-outline-primary" onclick="editarCurso(<?php echo htmlspecialchars(json_encode($c)); ?>)"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-outline-warning toggle-btn" data-table="curso" data-id="<?php echo $c['id']; ?>" data-estado="<?php echo $c['activo']?'0':'1'; ?>"><i class="fas fa-<?php echo $c['activo']?'ban':'check'; ?>"></i></button></div></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_cursos > $registros_por_pagina): ?><nav class="mt-3"><ul class="pagination pagination-custom justify-content-center"><?php for($i=1;$i<=ceil($total_cursos/$registros_por_pagina);$i++): ?><li class="page-item <?php echo $i==$pagina_actual?'active':''; ?>"><a class="page-link" href="<?php echo generarUrlPagina($i,'cursos'); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
</div>
<?php elseif ($tab_activa === 'alumnos'): ?>
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<span><i class="fas fa-user-graduate me-2"></i>Listado de Alumnos</span>
<button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAlumno"><i class="fas fa-plus me-1"></i>Nuevo Alumno</button>
</div>
<div class="table-responsive">
<table class="table table-hover">
<thead><tr><th>ID</th><th>Apellido</th><th>Nombre</th><th>DNI</th><th>Email</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach ($alumnos as $a): ?>
<tr><td>#<?php echo $a['id']; ?></td><td><?php echo htmlspecialchars($a['apellido']); ?></td><td><?php echo htmlspecialchars($a['nombre']); ?></td><td><?php echo htmlspecialchars($a['dni']??'-'); ?></td><td><?php echo htmlspecialchars($a['email']??'-'); ?></td><td><span class="badge-estado <?php echo $a['activo']?'badge-activo':'badge-inactivo'; ?>"><?php echo $a['activo']?'Activo':'Inactivo'; ?></span></td><td><div class="btn-group"><button class="btn btn-sm btn-outline-primary" onclick="editarAlumno(<?php echo htmlspecialchars(json_encode($a)); ?>)"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-outline-warning toggle-btn" data-table="alumno" data-id="<?php echo $a['id']; ?>" data-estado="<?php echo $a['activo']?'0':'1'; ?>"><i class="fas fa-<?php echo $a['activo']?'ban':'check'; ?>"></i></button></div></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_alumnos > $registros_por_pagina): ?><nav class="mt-3"><ul class="pagination pagination-custom justify-content-center"><?php for($i=1;$i<=ceil($total_alumnos/$registros_por_pagina);$i++): ?><li class="page-item <?php echo $i==$pagina_actual?'active':''; ?>"><a class="page-link" href="<?php echo generarUrlPagina($i,'alumnos'); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
</div>
<?php elseif ($tab_activa === 'materias'): ?>
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<span><i class="fas fa-book me-2"></i>Listado de Materias</span>
<button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalMateria"><i class="fas fa-plus me-1"></i>Nueva Materia</button>
</div>
<div class="table-responsive">
<table class="table table-hover">
<thead><tr><th>ID</th><th>Código</th><th>Materia</th><th>Curso</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach ($materias as $m): ?>
<tr><td>#<?php echo $m['id']; ?></td><td><?php echo htmlspecialchars($m['codigo']??'-'); ?></td><td><?php echo htmlspecialchars($m['nombre']); ?></td><td><?php echo !empty($m['curso_nombre']) ? '<span class="badge-curso">'.htmlspecialchars($m['curso_nombre']).'</span>' : '<span class="text-muted">-</span>'; ?></td><td><?php echo htmlspecialchars($m['descripcion']??'-'); ?></td><td><span class="badge-estado <?php echo $m['activo']?'badge-activo':'badge-inactivo'; ?>"><?php echo $m['activo']?'Activa':'Inactiva'; ?></span></td><td><div class="btn-group"><button class="btn btn-sm btn-outline-primary" onclick="editarMateria(<?php echo htmlspecialchars(json_encode($m)); ?>)"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-outline-warning toggle-btn" data-table="materia" data-id="<?php echo $m['id']; ?>" data-estado="<?php echo $m['activo']?'0':'1'; ?>"><i class="fas fa-<?php echo $m['activo']?'ban':'check'; ?>"></i></button></div></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($total_materias > $registros_por_pagina): ?><nav class="mt-3"><ul class="pagination pagination-custom justify-content-center"><?php for($i=1;$i<=ceil($total_materias/$registros_por_pagina);$i++): ?><li class="page-item <?php echo $i==$pagina_actual?'active':''; ?>"><a class="page-link" href="<?php echo generarUrlPagina($i,'materias'); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
</div>
<?php elseif ($tab_activa === 'asignaciones'): ?>
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center"><span><i class="fas fa-link me-2"></i>Gestión de Asignaciones</span></div>
<!-- Sub-tabs para tipo de asignación -->
<ul class="nav nav-tabs mb-4" id="asignacionTabs" role="tablist">
<li class="nav-item" role="presentation">
<a class="nav-link active" id="prof-mat-tab" data-bs-toggle="tab" href="#prof-mat" role="tab" aria-controls="prof-mat" aria-selected="true">👨‍🏫 Profesor → Materia</a>
</li>
<li class="nav-item" role="presentation">
<a class="nav-link" id="alum-mat-tab" data-bs-toggle="tab" href="#alum-mat" role="tab" aria-controls="alum-mat" aria-selected="false">👨‍🎓 Alumno → Materia</a>
</li>
</ul>
<div class="tab-content" id="asignacionTabsContent">
<!-- Tab: Profesor → Materia -->
<div class="tab-pane fade show active" id="prof-mat" role="tabpanel" aria-labelledby="prof-mat-tab">
<div class="row g-4">
<div class="col-md-6"><div class="card h-100 border-primary"><div class="card-header bg-primary text-white">Asignar Materia a Profesor</div><div class="card-body">
<form method="POST" action=""><input type="hidden" name="asignar_profesor_materia" value="1"><div class="mb-3"><label class="form-label">Profesor</label><select name="usuario_id" class="form-select" required><option value="">Seleccionar...</option><?php foreach($profesores as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['username'].' ('.$p['nombre_completo'].')'); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Curso (opcional)</label><select name="curso_filtro_materia" id="curso_filtro_prof_materia" class="form-select" onchange="filtrarMateriasPorCurso('prof_materia', this.value)"><option value="">Todos</option><?php foreach($cursos as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Materia</label><select name="materia_id" id="select_materia_prof" class="form-select" required><option value="">Seleccionar...</option><?php foreach($materias as $m): ?><option value="<?php echo $m['id']; ?>" data-curso="<?php echo $m['curso_id']??''; ?>"><?php echo htmlspecialchars($m['nombre']); ?><?php if(!empty($m['curso_nombre'])): ?> (<?php echo htmlspecialchars($m['curso_nombre']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div><button type="submit" class="btn btn-primary w-100"><i class="fas fa-user-plus me-1"></i>Asignar</button></form>
</div></div></div>
<div class="col-md-6"><div class="card h-100 border-primary"><div class="card-header bg-primary text-white">Asignaciones Existentes</div><div class="card-body">
<div class="table-responsive">
<table class="table table-sm table-hover">
<thead><tr><th>Profesor</th><th>Curso</th><th>Materia</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach($asignaciones as $asg): ?>
<tr><td><?php echo htmlspecialchars($asg['nombre_prof']); ?></td><td><?php echo !empty($asg['curso_nombre']) ? '<span class="badge-curso">'.htmlspecialchars($asg['curso_nombre']).'</span>' : '-'; ?></td><td><?php echo htmlspecialchars($asg['materia']); ?></td><td><span class="badge-estado <?php echo $asg['activo']?'badge-activo':'badge-inactivo'; ?>"><?php echo $asg['activo']?'Activa':'Inactiva'; ?></span></td><td><button class="btn btn-sm btn-outline-warning toggle-btn" data-table="profesor_materia" data-id="<?php echo $asg['id']; ?>" data-estado="<?php echo $asg['activo']?'0':'1'; ?>" title="<?php echo $asg['activo']?'Deshabilitar':'Habilitar'; ?>"><i class="fas fa-<?php echo $asg['activo']?'ban':'check'; ?>"></i></button></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div></div></div>
</div>
</div>
<!-- Tab: Alumno → Materia -->
<div class="tab-pane fade" id="alum-mat" role="tabpanel" aria-labelledby="alum-mat-tab">
<div class="row g-4">
<div class="col-md-6"><div class="card h-100 border-success"><div class="card-header bg-success text-white">Asignar Materia a Alumno</div><div class="card-body">
<form method="POST" action=""><input type="hidden" name="asignar_alumno_materia" value="1"><div class="mb-3"><label class="form-label">Alumno</label><select name="alumno_id" class="form-select" required><option value="">Seleccionar...</option><?php foreach($alumnos as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['apellido'].', '.$a['nombre']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Curso (opcional)</label><select name="curso_filtro_materia_alum" id="curso_filtro_alum_materia" class="form-select" onchange="filtrarMateriasPorCurso('alum_materia', this.value)"><option value="">Todos</option><?php foreach($cursos as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Materia</label><select name="materia_id" id="select_materia_alum" class="form-select" required><option value="">Seleccionar...</option><?php foreach($materias as $m): ?><option value="<?php echo $m['id']; ?>" data-curso="<?php echo $m['curso_id']??''; ?>"><?php echo htmlspecialchars($m['nombre']); ?><?php if(!empty($m['curso_nombre'])): ?> (<?php echo htmlspecialchars($m['curso_nombre']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div><button type="submit" class="btn btn-success w-100"><i class="fas fa-user-graduate me-1"></i>Asignar</button></form>
</div></div></div>
<div class="col-md-6"><div class="card h-100 border-success"><div class="card-header bg-success text-white">Asignaciones Existentes</div><div class="card-body">
<div class="table-responsive">
<table class="table table-sm table-hover">
<thead><tr><th>Alumno</th><th>Curso</th><th>Materia</th><th>Estado</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach($asignaciones_alumnos as $asg): ?>
<tr><td><?php echo htmlspecialchars($asg['apellido'].', '.$asg['nombre']); ?></td><td><?php echo !empty($asg['curso_nombre']) ? '<span class="badge-curso">'.htmlspecialchars($asg['curso_nombre']).'</span>' : '-'; ?></td><td><?php echo htmlspecialchars($asg['materia']); ?></td><td><span class="badge-estado <?php echo $asg['activo']?'badge-activo':'badge-inactivo'; ?>"><?php echo $asg['activo']?'Activa':'Inactiva'; ?></span></td><td><button class="btn btn-sm btn-outline-warning toggle-btn" data-table="alumno_materia" data-id="<?php echo $asg['id']; ?>" data-estado="<?php echo $asg['activo']?'0':'1'; ?>" title="<?php echo $asg['activo']?'Deshabilitar':'Habilitar'; ?>"><i class="fas fa-<?php echo $asg['activo']?'ban':'check'; ?>"></i></button></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div></div></div>
</div>
</div>
</div>
</div>
<?php elseif ($tab_activa === 'notas'): ?>
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center"><span><i class="fas fa-clipboard-list me-2"></i>Carga de Notas</span></div>
<form method="POST" action="" class="row g-3 mb-4 p-3 bg-light rounded"><input type="hidden" name="guardar_nota" value="1">
<div class="col-md-3"><label class="form-label">Curso</label><select name="curso_id" id="curso_filtro_notas" class="form-select" onchange="filtrarAlumnosYMateriasPorCurso('notas', this.value)"><option value="">Todos</option><?php foreach($cursos as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Alumno</label><select name="alumno_id" id="select_alumno_nota" class="form-select" required><option value="">Seleccionar...</option><?php foreach($alumnos as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['apellido'].', '.$a['nombre']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Materia</label><select name="materia_id" id="select_materia_nota" class="form-select" required><option value="">Seleccionar...</option><?php foreach($materias as $m): ?><option value="<?php echo $m['id']; ?>" data-curso="<?php echo $m['curso_id']??''; ?>"><?php echo htmlspecialchars($m['nombre']); ?><?php if(!empty($m['curso_nombre'])): ?> (<?php echo htmlspecialchars($m['curso_nombre']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Nota</label><input type="number" name="nota" class="form-control" step="0.01" min="0" max="10" required></div>
<div class="col-md-2"><label class="form-label">Periodo</label><input type="text" name="periodo" class="form-control" required placeholder="Ej: 1° Trimestre"></div>
<div class="col-md-2"><label class="form-label">Observación</label><input type="text" name="observacion" class="form-control"></div>
<div class="col-12 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Guardar Nota</button></div>
</form>
<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Curso</th><th>Alumno</th><th>Materia</th><th>Nota</th><th>Periodo</th><th>Registrado por</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($notas as $n): ?><tr><td><?php echo !empty($n['curso_nombre']) ? '<span class="badge-curso">'.htmlspecialchars($n['curso_nombre']).'</span>' : '-'; ?></td><td><?php echo htmlspecialchars($n['apellido'].', '.$n['nombre']); ?></td><td><?php echo htmlspecialchars($n['materia']); ?></td><td><span class="badge bg-<?php echo $n['nota']>=6?'success':($n['nota']>=4?'warning':'danger'); ?>"><?php echo number_format($n['nota'],2); ?></span></td><td><?php echo htmlspecialchars($n['periodo']); ?></td><td><?php echo htmlspecialchars($n['registrado_por']??'Supervisor'); ?></td><td><?php echo formatearFecha($n['fecha_registro']); ?></td><td><span class="badge-estado <?php echo ($n['activo']??true)?'badge-activo':'badge-inactivo'; ?>"><?php echo ($n['activo']??true)?'Activa':'Inactiva'; ?></span></td><td><button class="btn btn-sm btn-outline-warning toggle-btn" data-table="nota" data-id="<?php echo $n['id']; ?>" data-estado="<?php echo ($n['activo']??true)?'0':'1'; ?>" title="<?php echo ($n['activo']??true)?'Deshabilitar':'Habilitar'; ?>"><i class="fas fa-<?php echo ($n['activo']??true)?'ban':'check'; ?>"></i></button></td></tr><?php endforeach; ?>
</tbody></table></div>
</div>
<?php elseif ($tab_activa === 'asistencia'): ?>
<div class="section-box">
<div class="section-title d-flex justify-content-between align-items-center">
<span><i class="fas fa-calendar-check me-2"></i>Control de Asistencia</span>
<div class="btn-group">
<a href="?exportar_asistencia=csv<?php echo !empty($search)?'&search='.urlencode($search):''; ?><?php echo !empty($curso_filtro)?'&curso_id='.urlencode($curso_filtro):''; ?><?php echo !empty($estado_asistencia_filtro)?'&estado_asistencia_filtro='.urlencode($estado_asistencia_filtro):''; ?><?php echo !empty($fecha_desde)?'&fecha_desde='.urlencode($fecha_desde):''; ?><?php echo !empty($fecha_hasta)?'&fecha_hasta='.urlencode($fecha_hasta):''; ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-file-csv me-1"></i>CSV</a>
<a href="?exportar_asistencia=pdf<?php echo !empty($search)?'&search='.urlencode($search):''; ?><?php echo !empty($curso_filtro)?'&curso_id='.urlencode($curso_filtro):''; ?><?php echo !empty($estado_asistencia_filtro)?'&estado_asistencia_filtro='.urlencode($estado_asistencia_filtro):''; ?><?php echo !empty($fecha_desde)?'&fecha_desde='.urlencode($fecha_desde):''; ?><?php echo !empty($fecha_hasta)?'&fecha_hasta='.urlencode($fecha_hasta):''; ?>" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf me-1"></i>PDF</a>
</div>
</div>
<form method="POST" action="" class="row g-3 mb-4 p-3 bg-light rounded border">
<input type="hidden" name="guardar_asistencia" value="1">
<div class="col-md-3"><label class="form-label">Curso</label><select name="curso_id" id="curso_filtro_asistencia" class="form-select" onchange="filtrarAlumnosYMateriasPorCurso('asistencia', this.value)"><option value="">Todos</option><?php foreach($cursos as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Alumno</label><select name="alumno_id" id="select_alumno_asistencia" class="form-select" required><option value="">Seleccionar...</option><?php foreach($alumnos as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['apellido'].', '.$a['nombre']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Materia</label><select name="materia_id" id="select_materia_asistencia" class="form-select" required><option value="">Seleccionar...</option><?php foreach($materias as $m): ?><option value="<?php echo $m['id']; ?>" data-curso="<?php echo $m['curso_id']??''; ?>"><?php echo htmlspecialchars($m['nombre']); ?><?php if(!empty($m['curso_nombre'])): ?> (<?php echo htmlspecialchars($m['curso_nombre']); ?>)<?php endif; ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Fecha</label><input type="date" name="fecha" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
<div class="col-md-2"><label class="form-label">Estado</label><select name="estado" id="estado_asistencia" class="form-select" required><option value="presente">Presente</option><option value="ausente">Ausente</option><option value="justificado">Justificado</option></select></div>
<div class="col-md-2 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check_certificado" name="tiene_certificado"><label class="form-check-label" for="check_certificado">¿Cert. Médico?</label></div></div>
<div class="col-12 certificado-fields" id="campos_certificado">
<div class="row g-3">
<div class="col-md-4"><label class="form-label">N° Certificado</label><input type="text" name="num_certificado" class="form-control" placeholder="Ej: CM-2024-001"></div>
<div class="col-md-4"><label class="form-label">Fecha Emisión</label><input type="date" name="fecha_certificado" class="form-control"></div>
<div class="col-md-4"><label class="form-label">Observación / Diagnóstico</label><input type="text" name="observacion" class="form-control" placeholder="Opcional"></div>
</div>
</div>
<div class="col-12"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Registrar Asistencia</button></div>
</form>
<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Fecha</th><th>Curso</th><th>Alumno</th><th>Materia</th><th>Estado</th><th>Cert. Médico</th><th>Observación</th><th>Registrado por</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($asistencias as $as): ?>
<tr>
<td><?php echo formatearFecha($as['fecha']); ?></td>
<td><?php echo !empty($as['curso_nombre']) ? '<span class="badge-curso">'.htmlspecialchars($as['curso_nombre']).'</span>' : '-'; ?></td>
<td><?php echo htmlspecialchars($as['apellido'].', '.$as['nombre']); ?></td>
<td><?php echo htmlspecialchars($as['materia']); ?></td>
<td><span class="badge badge-asistencia-<?php echo $as['estado']; ?>"><?php echo ucfirst($as['estado']); ?></span></td>
<td><?php echo $as['tiene_certificado'] ? '<i class="fas fa-file-medical text-success"></i> <small class="text-muted">('.$as['num_certificado'].')</small>' : '<span class="text-muted">-</span>'; ?></td>
<td><small><?php echo htmlspecialchars($as['observacion']??'-'); ?></small></td>
<td><?php echo htmlspecialchars($as['registrado_por']??'Sistema'); ?></td>
<td>
<div class="btn-group">
<button class="btn btn-sm btn-outline-warning toggle-btn" data-table="asistencia" data-id="<?php echo $as['id']; ?>" data-estado="1" title="Cambiar estado"><i class="fas fa-sync-alt"></i></button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($total_asistencias > $registros_por_pagina): ?>
<nav class="mt-3"><ul class="pagination pagination-custom justify-content-center"><?php for($i=1;$i<=ceil($total_asistencias/$registros_por_pagina);$i++): ?><li class="page-item <?php echo $i==$pagina_actual?'active':''; ?>"><a class="page-link" href="<?php echo generarUrlPagina($i,'asistencia'); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
<!-- MODALES (Alumno/Materia/Curso) -->
<div class="modal fade" id="modalAlumno" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="alumnoModalTitle">Nuevo Alumno</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<form method="POST" action=""><input type="hidden" name="guardar_alumno" value="1"><input type="hidden" id="alumno_id" name="id" value="0">
<div class="modal-body"><div class="mb-3"><label class="form-label">Apellido</label><input type="text" name="apellido" id="alumno_apellido" class="form-control" required></div><div class="mb-3"><label class="form-label">Nombre</label><input type="text" name="nombre" id="alumno_nombre" class="form-control" required></div><div class="row g-2"><div class="col-6"><label class="form-label">DNI</label><input type="text" name="dni" id="alumno_dni" class="form-control"></div><div class="col-6"><label class="form-label">Email</label><input type="email" name="email" id="alumno_email" class="form-control"></div></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div></form>
</div></div></div>
<div class="modal fade" id="modalCurso" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="cursoModalTitle">Nuevo Curso</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<form method="POST" action=""><input type="hidden" name="guardar_curso" value="1"><input type="hidden" id="curso_id" name="id" value="0">
<div class="modal-body"><div class="mb-3"><label class="form-label">Nombre del Curso</label><input type="text" name="nombre" id="curso_nombre" class="form-control" required placeholder="Ej: Vigilador Privado"></div><div class="mb-3"><label class="form-label">Código</label><input type="text" name="codigo" id="curso_codigo" class="form-control" placeholder="Ej: VP-001"></div><div class="mb-3"><label class="form-label">Descripción</label><textarea name="descripcion" id="curso_descripcion" class="form-control" rows="2" placeholder="Descripción del curso..."></textarea></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div></form>
</div></div></div>
<div class="modal fade" id="modalMateria" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="materiaModalTitle">Nueva Materia</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<form method="POST" action=""><input type="hidden" name="guardar_materia" value="1"><input type="hidden" id="materia_id" name="id" value="0">
<div class="modal-body"><div class="mb-3"><label class="form-label">Curso</label><select name="curso_id" id="materia_curso" class="form-select"><option value="">Sin curso (general)</option><?php foreach($cursos as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Nombre</label><input type="text" name="nombre" id="materia_nombre" class="form-control" required></div><div class="mb-3"><label class="form-label">Código</label><input type="text" name="codigo" id="materia_codigo" class="form-control"></div><div class="mb-3"><label class="form-label">Descripción</label><textarea name="descripcion" id="materia_descripcion" class="form-control" rows="2"></textarea></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div></form>
</div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Toggle Estado AJAX - Función unificada para todas las tablas
function toggleEstado(btn) {
const id = btn.dataset.id;
const table = btn.dataset.table;
const estado = btn.dataset.estado;
const formData = new FormData();
formData.append('action', 'toggle_'+table);
formData.append('id', id);
formData.append('estado', estado);
fetch('', { method: 'POST', body: formData })
.then(r=>r.json())
.then(d=>{
if(d.success) {
location.reload();
} else {
Swal.fire('Error', d.message, 'error');
}
})
.catch(e => {
Swal.fire('Error de conexión', 'No se pudo comunicar con el servidor', 'error');
});
}
// Event delegation para todos los botones toggle (incluye dinámicos)
document.body.addEventListener('click', function(e) {
const btn = e.target.closest('.toggle-btn');
if(btn) {
e.preventDefault();
toggleEstado(btn);
}
});
// Mostrar/Ocultar campos de certificado médico
const checkCert = document.getElementById('check_certificado');
const camposCert = document.getElementById('campos_certificado');
const estadoAsist = document.getElementById('estado_asistencia');
function toggleCertFields() {
const esAusente = estadoAsist.value === 'ausente' || estadoAsist.value === 'justificado';
camposCert.style.display = (checkCert.checked && esAusente) ? 'block' : 'none';
if (!checkCert.checked || !esAusente) {
camposCert.querySelector('input[name="num_certificado"]').value = '';
camposCert.querySelector('input[name="fecha_certificado"]').value = '';
}
}
if(checkCert && camposCert && estadoAsist) {
checkCert.addEventListener('change', toggleCertFields);
estadoAsist.addEventListener('change', toggleCertFields);
toggleCertFields(); // init
}
// Filtrar materias por curso en formularios
window.filtrarMateriasPorCurso = function(contexto, cursoId) {
const selects = {
'prof_materia': 'select_materia_prof',
'alum_materia': 'select_materia_alum'
};
const selectId = selects[contexto];
if(!selectId) return;
const select = document.getElementById(selectId);
if(!select) return;
Array.from(select.options).forEach(opt => {
if(opt.value === '') return;
const materiaCurso = opt.dataset.curso;
opt.style.display = (!cursoId || !materiaCurso || materiaCurso == cursoId) ? '' : 'none';
});
if(select.querySelector('option:not([style*="display: none"])')) {
select.value = select.querySelector('option:not([style*="display: none"])').value;
}
};
// Filtrar alumnos y materias por curso en notas/asistencia
window.filtrarAlumnosYMateriasPorCurso = function(contexto, cursoId) {
const config = {
'notas': {alumno: 'select_alumno_nota', materia: 'select_materia_nota'},
'asistencia': {alumno: 'select_alumno_asistencia', materia: 'select_materia_asistencia'}
};
const cfg = config[contexto];
if(!cfg) return;
// Filtrar materias
const materiaSelect = document.getElementById(cfg.materia);
if(materiaSelect) {
Array.from(materiaSelect.options).forEach(opt => {
if(opt.value === '') return;
const materiaCurso = opt.dataset.curso;
opt.style.display = (!cursoId || !materiaCurso || materiaCurso == cursoId) ? '' : 'none';
});
if(materiaSelect.querySelector('option:not([style*="display: none"])')) {
materiaSelect.value = materiaSelect.querySelector('option:not([style*="display: none"])').value;
}
}
// Nota: Los alumnos no están vinculados a cursos en esta versión,
// pero se podría agregar esa relación en el futuro si se requiere.
};
// Modales editar
window.editarAlumno = function(data) {
document.getElementById('alumnoModalTitle').textContent = 'Editar Alumno';
document.getElementById('alumno_id').value = data.id;
document.getElementById('alumno_apellido').value = data.apellido;
document.getElementById('alumno_nombre').value = data.nombre;
document.getElementById('alumno_dni').value = data.dni || '';
document.getElementById('alumno_email').value = data.email || '';
new bootstrap.Modal(document.getElementById('modalAlumno')).show();
};
window.editarCurso = function(data) {
document.getElementById('cursoModalTitle').textContent = 'Editar Curso';
document.getElementById('curso_id').value = data.id;
document.getElementById('curso_nombre').value = data.nombre;
document.getElementById('curso_codigo').value = data.codigo || '';
document.getElementById('curso_descripcion').value = data.descripcion || '';
new bootstrap.Modal(document.getElementById('modalCurso')).show();
};
window.editarMateria = function(data) {
document.getElementById('materiaModalTitle').textContent = 'Editar Materia';
document.getElementById('materia_id').value = data.id;
document.getElementById('materia_nombre').value = data.nombre;
document.getElementById('materia_codigo').value = data.codigo || '';
document.getElementById('materia_descripcion').value = data.descripcion || '';
document.getElementById('materia_curso').value = data.curso_id || '';
new bootstrap.Modal(document.getElementById('modalMateria')).show();
};
// Limpiar modales al cerrar
document.querySelectorAll('.modal').forEach(modal => {
modal.addEventListener('hidden.bs.modal', function() {
const form = this.querySelector('form');
if(form) { form.reset(); const hiddenId = form.querySelector('input[name="id"]'); if(hiddenId) hiddenId.value = 0; }
const title = this.querySelector('.modal-title');
if(title) title.textContent = title.textContent.replace('Editar', 'Nueva').replace('Editar', 'Nuevo');
});
});
});
</script>
</body>
</html>