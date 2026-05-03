<?php
session_start();
// Incluir configuración de base de datos y autenticación
require_once '../config/database.php';
require_once '../config/auth.php';
// ============================================================================
// AGREGADO: Incluir funciones de auditoría centralizada
// ============================================================================
require_once '../config/auditoria_func.php';
// ============================================================================
// Obtener conexión a la base de datos
$conn = getDBConnection();
// Verificar autenticación y rol estricto
if (!$auth->isLoggedIn() || !$auth->hasRole('empresa')) {
header('Location: ../login.php');
exit;
}
$user = $auth->getCurrentUser();
$empresa_id = $user['empresa_id'];
// ============================================================================
// B1: Validar relación usuario-empresa en base de datos
// ============================================================================
try {
    $stmt_relacion = $conn->prepare("SELECT id FROM usuarios WHERE id = :usuario_id AND empresa_id = :empresa_id AND activo = 1");
    $stmt_relacion->execute([':usuario_id' => $user['id'], ':empresa_id' => $empresa_id]);
    if (!$stmt_relacion->fetch()) {
        error_log("Intento de acceso no autorizado: usuario {$user['id']} a empresa {$empresa_id}");
        header('Location: ../login.php');
        exit;
    }
} catch(PDOException $e) {
    error_log("Error validando relación usuario-empresa: " . $e->getMessage());
    header('Location: ../login.php');
    exit;
}
// ============================================================================
$error = '';
$success = '';
$personal_seleccionado = null;
// ==================== VERIFICAR TRÁMITES URGENTES ====================
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
// Silencioso
}
// ==================== FILTRO POR DNI ====================
$filtro_dni = isset($_GET['filtro_dni']) && !empty($_GET['filtro_dni']) ? sanitizeInput($_GET['filtro_dni']) : '';
// ==================== DIRECTORIOS DE SUBIDA (MISMOS QUE personal.php) ====================
$target_dir_fotos = "../uploads/fotos_personal/";
$target_dir_pdf = "../uploads/pdf_personal/";
if (!file_exists($target_dir_fotos)) mkdir($target_dir_fotos, 0777, true);
if (!file_exists($target_dir_pdf)) mkdir($target_dir_pdf, 0777, true);
// ==================== OBTENER SOLO PERSONAL INACTIVO DE LA EMPRESA ====================
try {
$query = "
SELECT p.*,
s.nombre as sucursal_nombre,
e.nombre as empresa_nombre
FROM personal p
INNER JOIN sucursales s ON p.sucursal_id = s.id
INNER JOIN empresas e ON p.empresa_id = e.id
WHERE p.empresa_id = :empresa_id
AND p.activo = 0
";
$params = [':empresa_id' => $empresa_id];
// Agregar filtro por DNI si existe
if (!empty($filtro_dni)) {
$query .= " AND p.dni = :filtro_dni";
$params[':filtro_dni'] = $filtro_dni;
}
$query .= " ORDER BY s.nombre, p.apellido, p.nombre";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
$error = 'Error al cargar el personal: ' . $e->getMessage();
$personal_list = [];
}
// ==================== OBTENER DATOS DEL PERSONAL SELECCIONADO ====================
if (isset($_GET['personal_id']) && !empty($_GET['personal_id'])) {
$personal_id = (int)$_GET['personal_id'];
try {
$stmt = $conn->prepare("
SELECT p.*,
s.nombre as sucursal_nombre,
e.nombre as empresa_nombre
FROM personal p
INNER JOIN sucursales s ON p.sucursal_id = s.id
INNER JOIN empresas e ON p.empresa_id = e.id
WHERE p.id = :personal_id AND p.empresa_id = :empresa_id
");
$stmt->execute([':personal_id' => $personal_id, ':empresa_id' => $empresa_id]);
$personal_seleccionado = $stmt->fetch();
if (!$personal_seleccionado) {
$error = 'El personal seleccionado no pertenece a su empresa';
$personal_seleccionado = null;
}
} catch(PDOException $e) {
$error = 'Error al cargar los datos del personal: ' . $e->getMessage();
}
}
// ==================== CARGOS DISPONIBLES ====================
$cargos_disponibles = [
'ACCIONISTA', 'ADMINISTRATIVO', 'VIGILADOR', 'CHOFER', 'DIRECTOR TECNICO',
'GERENTE GENERAL', 'SUPERVISOR', 'PRESIDENTE DEL DIRECTORIO', 'REPRESENTANTE LEGAL'
];
// ============================================================================
// ✅ MODALIDADES DE CONTRATO (RG 2143) - 52 CÓDIGOS
// ============================================================================
$modalidades_contrato = [
['codigo' => '0', 'descripcion' => 'Contrato Modalidad Promovida. Reduccion 0%'],
['codigo' => '1', 'descripcion' => 'A tiempo parcial: Indeterminado/permanente'],
['codigo' => '2', 'descripcion' => 'Becarios- Residencias medicas Ley N°22.127'],
['codigo' => '3', 'descripcion' => 'De aprendizaje Ley N 25.013'],
['codigo' => '4', 'descripcion' => 'Especial de Fomento del Empleo: Ley N° 24.465'],
['codigo' => '5', 'descripcion' => 'Fomento del empleo Leyes N 24.013 y N 24.465.'],
['codigo' => '6', 'descripcion' => 'Lanzamiento nueva actividad. Idem 005'],
['codigo' => '7', 'descripcion' => 'Periodo de prueba. Leyes N° 24.465 y N° 25.013'],
['codigo' => '8', 'descripcion' => 'A Tiempo completo indeterminado/Trabajo pemanente'],
['codigo' => '9', 'descripcion' => 'Practica laboral para jovenes.'],
['codigo' => '10', 'descripcion' => 'Pasantias. Ley N° 25.165. Decreto N 340/92'],
['codigo' => '11', 'descripcion' => 'Trabajo de temporada.'],
['codigo' => '12', 'descripcion' => 'Trabajo eventual.'],
['codigo' => '13', 'descripcion' => 'Trabajo formacion.'],
['codigo' => '14', 'descripcion' => 'Nuevo Periodo de Prueba'],
['codigo' => '15', 'descripcion' => 'Puesto Nuevo Varones y Mujeres de 25 a 44 anos'],
['codigo' => '16', 'descripcion' => 'Nuevo Periodo de Prueba Trabajador Discapacitado Art. 34 de la Ley N° 24.147.'],
['codigo' => '17', 'descripcion' => 'Puesto Nuevo menor de 25 anos, Varones y Mujeres de 45 o mas anos y Mujer Jefe de flia. S/limite/edad'],
['codigo' => '18', 'descripcion' => 'Trabajador Discapacitado Art. 34 de la Ley N° 24.147.'],
['codigo' => '19', 'descripcion' => 'Puesto Nuevo. Varones y Mujeres 25 a 44 anos. Art. 34 de la Ley N°24.147.'],
['codigo' => '20', 'descripcion' => 'Pto. Nuevo Menor 25 anos, Varones y Mujeres 45 o mas y Mujer Jefe de flia.S/limite de edad. Art. 34 de la Ley N° 24.147.'],
['codigo' => '21', 'descripcion' => 'A tiempo parcial deteminado (contrato a plazo fijo)'],
['codigo' => '22', 'descripcion' => 'A Tiempo completo determinado (contrato a plazo fijo)'],
['codigo' => '23', 'descripcion' => 'Personal no permanente Ley N° 22.248.'],
['codigo' => '24', 'descripcion' => 'Personal de la Construcción Ley N° 22.250.'],
['codigo' => '25', 'descripcion' => 'Empleo publico provincial.'],
['codigo' => '26', 'descripcion' => 'Beneficiario de programa de empleo, capacitacion y de recuperacion productiva.'],
['codigo' => '27', 'descripcion' => 'Pasantias Decreto N° 1.227/01.'],
['codigo' => '28', 'descripcion' => 'Programas Jefes y Jefas de Hogar.'],
['codigo' => '29', 'descripcion' => 'Decreto N° 1212/03 Aportante Autonomo.'],
['codigo' => '30', 'descripcion' => 'Nuevo Periodo de Prueba Trabajador Discapacitado. Art. 87 de la Ley N° 24.013.'],
['codigo' => '31', 'descripcion' => 'Trabajador Discapacitado Art. 87 de la Ley N° 24.013.'],
['codigo' => '32', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877.'],
['codigo' => '33', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N 25.877. Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '34', 'descripcion' => 'Periodo de Prueba Art. 6°de la Ley N° 25.877. Art. 34 de la Ley N° 24.147.'],
['codigo' => '35', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877. Art. 34 de la Ley N° 24.147.Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '36', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877. Trabajador Discapacitado Art. 87de la Ley N° 24.013.'],
['codigo' => '37', 'descripcion' => 'Periodo de Prueba Art. 6° de la Ley N° 25.877.Trab. Discapacitado Art 87de la Ley N° 24.013 Beneficiarios planes Jefes y Jefas hogar'],
['codigo' => '38', 'descripcion' => 'Puesto Nuevo Art. 6°de la Ley N° 25.877.'],
['codigo' => '39', 'descripcion' => 'Puesto Nuevo Art. 6° de la Ley N 25.877. Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '40', 'descripcion' => 'Puesto Nuevo Art. 6°de la Ley N° 25.877. Art. 34. Ley N 24.147'],
['codigo' => '41', 'descripcion' => 'Puesto Nuevo Art. 6° de la Ley N 25.877. Art. 34 de la Ley N24.147.Beneficiarios de planes Jefes y Jefas de hogar'],
['codigo' => '42', 'descripcion' => 'Puesto Nuevo Art. 6° de la Ley N 25.877.Trabajador Discapacitado Art. 87 de la Ley N° 24.013'],
['codigo' => '43', 'descripcion' => 'Puesto Nuevo Art.6° de la Ley N° 25.877.Trabajador Discapacitado Art. 87 de la Ley N° 24.013 Beneficiarios de planes Jefes y Jefas hogar'],
['codigo' => '44', 'descripcion' => 'Changa Solidaria. CCT 62/75'],
['codigo' => '45', 'descripcion' => 'Personal no permanente hoteles Art. 68 del CCT 362/03'],
['codigo' => '46', 'descripcion' => 'Planta transitoria Adm Publica Nacional. Provincial y/o Municipal'],
['codigo' => '47', 'descripcion' => 'Representacion gremial'],
['codigo' => '48', 'descripcion' => 'Art. 4° de la Ley N° 24.241.Traslado temporario desde el exterior 6 Convenios bilaterales de Seguridad Social'],
['codigo' => '50', 'descripcion' => 'Contrato Modalidad Promovida. Reduccion 50%'],
['codigo' => '99', 'descripcion' => 'LRT (Directores SA, municipios, org, cent y descent. Emp mixt provin, docentes de jurisdicciones incorporadas o no al SIJP)'],
['codigo' => '100', 'descripcion' => 'Contrato Modalidad Promovida. Reduccion 100%']
];
// ============================================================================
// ✅ FUNCIÓN PARA OBTENER DESCRIPCIÓN POR CÓDIGO
// ============================================================================
function obtenerDescripcionModalidad($codigo, $modalidades) {
foreach ($modalidades as $modalidad) {
if ($modalidad['codigo'] == $codigo) {
return $modalidad['descripcion'];
}
}
return $codigo ?? 'Sin especificar';
}
// ==================== PROCESAR ACTUALIZACIÓN DE DATOS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_personal'])) {
$personal_id = (int)$_POST['personal_id'];
$personal_data = null;
// Verificar que el personal pertenece a la empresa
try {
$check_stmt = $conn->prepare("
SELECT p.id, p.empresa_id, p.foto, p.pdf_datos_personales
FROM personal p
INNER JOIN sucursales s ON p.sucursal_id = s.id
WHERE p.id = :personal_id AND p.empresa_id = :empresa_id
");
$check_stmt->execute([':personal_id' => $personal_id, ':empresa_id' => $empresa_id]);
$personal_data = $check_stmt->fetch();
if (!$personal_data) {
$error = 'El personal seleccionado no pertenece a su empresa';
$personal_seleccionado = null;
}
} catch(PDOException $e) {
$error = 'Error al validar el personal: ' . $e->getMessage();
}
if ($personal_data) {
try {
// ==================== CAMPOS QUE LA EMPRESA PUEDE EDITAR ====================
$fecha_nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
$domicilio = sanitizeInput($_POST['domicilio'] ?? '');
$telefono = sanitizeInput($_POST['telefono'] ?? '');
$email = sanitizeInput($_POST['email'] ?? '');
$cargo = sanitizeInput($_POST['cargo'] ?? '');
$observaciones = sanitizeInput($_POST['observaciones'] ?? '');
$fecha_autorizacion = !empty($_POST['fecha_autorizacion']) ? $_POST['fecha_autorizacion'] : null;
$fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
$fecha_revalidacion = !empty($_POST['fecha_revalidacion']) ? $_POST['fecha_revalidacion'] : null;
// ==================== SUBIR FOTO ====================
$foto_file = $personal_data['foto'];
$foto_cambiada = false;
if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
if (!in_array($file_extension, ['jpg', 'jpeg', 'png'])) {
throw new Exception('La foto debe ser JPG, JPEG o PNG');
}
// ============================================================================
// A3: Validar MIME type real del archivo con finfo_file()
// ============================================================================
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($_FILES['foto']['tmp_name']);
$allowed_mime_types = ['image/jpeg', 'image/png'];
if (!in_array($mime_type, $allowed_mime_types)) {
throw new Exception('El tipo de archivo no es válido');
}
// ============================================================================
if ($_FILES['foto']['size'] > 2000000) {
throw new Exception('La foto no debe superar los 2MB');
}
// VALIDAR DIMENSIONES DE LA IMAGEN (285x354)
$image_info = getimagesize($_FILES['foto']['tmp_name']);
if ($image_info === false) {
throw new Exception('El archivo no es una imagen válida');
}
$image_width = $image_info[0];
$image_height = $image_info[1];
// ============================================================================
// A7: Forzar validación server-side de dimensiones con getimagesize()
// ============================================================================
if ($image_width !== 285 || $image_height !== 354) {
throw new Exception("La foto debe tener dimensiones exactas de 285x354 píxeles. Dimensiones actuales: {$image_width}x{$image_height}");
}
// ============================================================================
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
$fecha_actual = date('Ymd');
$new_filename = 'foto_carnet_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $personal_data['id'] . '.' . $file_extension;
$target_file = $target_dir_fotos . $new_filename;
if (!empty($personal_data['foto']) && file_exists($target_dir_fotos . $personal_data['foto'])) {
unlink($target_dir_fotos . $personal_data['foto']);
}
if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_file)) {
$foto_file = $new_filename;
$foto_cambiada = true;
} else {
throw new Exception('Error al subir la foto');
}
}
// ==================== SUBIR PDF DATOS PERSONALES ====================
$pdf_file = $personal_data['pdf_datos_personales'];
$pdf_cambiado = false;
if (isset($_FILES['pdf_datos_personales']) && $_FILES['pdf_datos_personales']['error'] === UPLOAD_ERR_OK) {
$file_extension = strtolower(pathinfo($_FILES['pdf_datos_personales']['name'], PATHINFO_EXTENSION));
if ($file_extension !== 'pdf') {
throw new Exception('El archivo debe ser PDF');
}
if ($_FILES['pdf_datos_personales']['size'] > 5000000) {
throw new Exception('El PDF no debe superar los 5MB');
}
$empresa_nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user['empresa_nombre'] ?? 'empresa');
$fecha_actual = date('Ymd');
$new_filename = 'datos_personales_' . $empresa_nombre_limpio . '_' . $fecha_actual . '_' . $personal_data['id'] . '.pdf';
$target_file = $target_dir_pdf . $new_filename;
if (!empty($personal_data['pdf_datos_personales']) && file_exists($target_dir_pdf . $personal_data['pdf_datos_personales'])) {
unlink($target_dir_pdf . $personal_data['pdf_datos_personales']);
}
if (move_uploaded_file($_FILES['pdf_datos_personales']['tmp_name'], $target_file)) {
$pdf_file = $new_filename;
$pdf_cambiado = true;
} else {
throw new Exception('Error al subir el PDF');
}
}
// ==================== ACTUALIZAR EN BASE DE DATOS ====================
$stmt = $conn->prepare("
UPDATE personal SET
fecha_nacimiento = :fecha_nacimiento,
domicilio = :domicilio,
telefono = :telefono,
email = :email,
cargo = :cargo,
foto = :foto,
pdf_datos_personales = :pdf_datos_personales,
observaciones = :observaciones,
fecha_autorizacion = :fecha_autorizacion,
fecha_vencimiento = :fecha_vencimiento,
fecha_revalidacion = :fecha_revalidacion,
estado_documentacion = 'pendiente',
updated_at = NOW()
WHERE id = :id
");
$stmt->execute([
':fecha_nacimiento' => $fecha_nacimiento,
':domicilio' => $domicilio,
':telefono' => $telefono,
':email' => $email,
':cargo' => $cargo,
':foto' => $foto_file,
':pdf_datos_personales' => $pdf_file,
':observaciones' => $observaciones,
':fecha_autorizacion' => $fecha_autorizacion,
':fecha_vencimiento' => $fecha_vencimiento,
':fecha_revalidacion' => $fecha_revalidacion,
':id' => $personal_id
]);
// ============================================================================
// AGREGADO: REGISTRAR EN AUDITORÍA CENTRALIZADA (Usando logAuditoria)
// ============================================================================
try {
logAuditoria(
$conn,
'ACTUALIZACION_DOCUMENTACION',
'personal',
$personal_id,
[
'mensaje' => 'Datos actualizados por empresa para personal INACTIVO',
'estado_documentacion' => 'pendiente',
'foto_actualizada' => $foto_cambiada ? 1 : 0,
'pdf_actualizado' => $pdf_cambiado ? 1 : 0,
'empresa_id' => $empresa_id
],
$user['id']
);
} catch(Exception $e) {
error_log('Error en auditoría centralizada: ' . $e->getMessage());
}
// ============================================================================
$success = 'Datos del personal actualizados correctamente. <strong>La documentación está pendiente de aprobación por el administrador.</strong>';
// Recargar datos del personal
$stmt = $conn->prepare("
SELECT p.*,
s.nombre as sucursal_nombre,
e.nombre as empresa_nombre
FROM personal p
INNER JOIN sucursales s ON p.sucursal_id = s.id
INNER JOIN empresas e ON p.empresa_id = e.id
WHERE p.id = :personal_id AND p.empresa_id = :empresa_id
");
$stmt->execute([':personal_id' => $personal_id, ':empresa_id' => $empresa_id]);
$personal_seleccionado = $stmt->fetch();
} catch(Exception $e) {
$error = $e->getMessage();
}
}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Personal Inactivo - Empresa</title>
<!-- Mantener CDN para Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Pero usar locales para Bootstrap y SweetAlert2 -->
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sweetalert2.min.css">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ✅ ESTILOS ESPECÍFICOS DE CARGAR DOCUMENTACION */
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
/* ✅ TABLET (992px - 1199px) */
@media (min-width: 992px) and (max-width: 1199px) {
.main-content-wrapper {
padding-left: 20px;
padding-right: 20px;
}
}
/* ✅ NOTEBOOK/LAPTOP (1200px - 1440px) */
@media (min-width: 1200px) and (max-width: 1440px) {
.main-content-wrapper {
padding-left: 25px;
padding-right: 25px;
}
}
/* ✅ PC ESCRITORIO (> 1441px) */
@media (min-width: 1441px) {
.main-content-wrapper {
padding-left: 40px;
padding-right: 40px;
}
}
/* ✅ CELULAR ANDROID/MOBIL (< 991px) */
@media (max-width: 991px) {
.main-content-wrapper {
margin-left: 0 !important;
width: 100% !important;
padding-left: 15px;
padding-right: 15px;
padding-top: 80px;
}
}
/* ✅ CELULARS PEQUEÑOS (< 576px) */
@media (max-width: 576px) {
.main-content-wrapper {
padding-left: 10px;
padding-right: 10px;
padding-top: 70px;
}
}
/* ✅ TRANSICIONES SUAVES */
.main-content-wrapper,
.sidebar-moderno {
transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}
/* ✅ PREVENIR SCROLL HORIZONTAL */
body {
overflow-x: hidden;
}
.container {
max-width: 100%;
padding-left: 15px;
padding-right: 15px;
}
/* ✅ ALERTA FLOTANTE DE URGENCIA */
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
.card-shadow {
box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}
.form-label {
font-weight: 500;
}
.foto-preview {
width: 150px;
height: 150px;
border-radius: 50%;
object-fit: cover;
border: 4px solid #3498db;
box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
.foto-thumbnail {
width: 60px;
height: 60px;
border-radius: 50%;
object-fit: cover;
border: 2px solid #3498db;
}
.upload-area {
border: 3px dashed #4361ee;
border-radius: 15px;
padding: 30px;
text-align: center;
background: linear-gradient(135deg, #f8f9fa, #e9ecef);
transition: all 0.3s ease;
cursor: pointer;
}
.upload-area:hover {
border-color: #3a0ca3;
background: linear-gradient(135deg, #e3f2fd, #bbdefb);
transform: translateY(-3px);
}
.upload-area i {
font-size: 3rem;
color: #4361ee;
margin-bottom: 15px;
}
.file-status-badge {
display: inline-flex;
align-items: center;
gap: 5px;
padding: 6px 15px;
border-radius: 20px;
font-size: 0.85rem;
font-weight: 600;
}
.file-status-exists {
background: linear-gradient(135deg, #27ae60, #219653);
color: white;
}
.file-status-missing {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
}
.readonly-field {
background: #e9ecef !important;
cursor: not-allowed;
opacity: 0.7;
}
.section-card {
background: white;
border-radius: 15px;
padding: 25px;
margin-bottom: 20px;
box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
border-left: 4px solid #4361ee;
transition: all 0.3s ease;
}
.section-card:hover {
box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
transform: translateY(-2px);
}
.section-card.restricted {
border-left-color: #e74c3c;
background: linear-gradient(135deg, #fdf2f2, #ffeaea);
opacity: 0.85;
}
.section-title {
font-weight: 700;
color: #2c3e50;
margin-bottom: 20px;
padding-bottom: 10px;
border-bottom: 2px solid #e9ecef;
display: flex;
align-items: center;
gap: 10px;
font-size: 1.1rem;
}
.section-title i {
color: #4361ee;
width: 30px;
height: 30px;
background: linear-gradient(135deg, #4361ee, #3a0ca3);
border-radius: 8px;
display: flex;
align-items: center;
justify-content: center;
color: white;
font-size: 0.9rem;
}
.section-card.restricted .section-title i {
background: linear-gradient(135deg, #e74c3c, #c0392b);
}
.alert-restricted {
background: linear-gradient(135deg, #fff3cd, #ffeaa7);
border-left: 4px solid #e74c3c;
color: #856404;
border-radius: 10px;
padding: 15px 20px;
}
.lock-icon {
color: #e74c3c;
margin-right: 5px;
}
.list-group-item-action {
cursor: pointer;
transition: all 0.3s ease;
}
.list-group-item-action:hover {
background: linear-gradient(135deg, #f8f9fa, #e9ecef);
transform: translateX(5px);
}
.list-group-item-action.active {
background: linear-gradient(135deg, #4361ee, #3a0ca3);
border-color: #4361ee;
}
.personal-selected-indicator {
background: linear-gradient(135deg, #95a5a6, #7f8c8d);
color: white;
padding: 10px 15px;
border-radius: 10px;
margin-bottom: 15px;
display: none;
}
.personal-selected-indicator.show {
display: block;
animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
from { opacity: 0; transform: translateY(-10px); }
to { opacity: 1; transform: translateY(0); }
}
input[name="filtro_dni"] {
font-family: 'Courier New', monospace;
font-weight: 600;
letter-spacing: 1px;
}
input[name="filtro_dni"]:focus {
border-color: #f39c12;
box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.15);
}
.search-box-modern {
background: linear-gradient(135deg, #f8f9fa, #e9ecef);
border-radius: 12px;
padding: 15px;
margin-bottom: 15px;
}
/* Badge Inactivo */
.badge-inactivo-custom {
background: linear-gradient(135deg, #95a5a6, #7f8c8d);
color: white;
padding: 5px 12px;
border-radius: 15px;
font-size: 0.75rem;
font-weight: 700;
text-transform: uppercase;
}
/* Badge Estado Documentación */
.badge-estado-doc {
padding: 5px 12px;
border-radius: 15px;
font-size: 0.75rem;
font-weight: 700;
text-transform: uppercase;
}
.badge-doc-pendiente {
background: linear-gradient(135deg, #f39c12, #d35400);
color: white;
}
.badge-doc-aprobada {
background: linear-gradient(135deg, #27ae60, #219653);
color: white;
}
.badge-doc-rechazada {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
}
/* SweetAlert2 Moderno */
.swal-popup-modern {
border-radius: 20px !important;
box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3) !important;
padding: 30px !important;
max-width: 500px !important;
}
.swal-title-modern {
font-weight: 700 !important;
font-size: 1.5rem !important;
color: #2c3e50 !important;
margin-bottom: 20px !important;
display: flex !important;
align-items: center !important;
gap: 10px !important;
}
.swal-title-modern i {
color: #4361ee;
font-size: 1.8rem;
}
.swal-content-modern {
text-align: center !important;
padding: 10px 0 !important;
}
.swal-message {
font-size: 1rem !important;
color: #555 !important;
margin-bottom: 20px !important;
display: flex !important;
align-items: center !important;
justify-content: center !important;
gap: 8px !important;
}
.swal-message i {
color: #3498db;
}
.swal-warning-box {
background: linear-gradient(135deg, #fff3cd, #ffeaa7);
border-left: 4px solid #f39c12;
border-radius: 10px;
padding: 15px 20px;
display: flex;
align-items: center;
gap: 10px;
margin-top: 15px;
text-align: left;
}
.swal-warning-box i {
color: #f39c12;
font-size: 1.2rem;
flex-shrink: 0;
}
.swal-warning-box span {
color: #856404;
font-size: 0.9rem;
font-weight: 500;
}
.swal-confirm-modern {
background: linear-gradient(135deg, #27ae60, #219653) !important;
border: none !important;
border-radius: 10px !important;
padding: 12px 30px !important;
font-weight: 600 !important;
font-size: 1rem !important;
box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4) !important;
transition: all 0.3s ease !important;
}
.swal-confirm-modern:hover {
transform: translateY(-2px) !important;
box-shadow: 0 6px 20px rgba(39, 174, 96, 0.6) !important;
}
.swal-cancel-modern {
background: linear-gradient(135deg, #95a5a6, #7f8c8d) !important;
border: none !important;
border-radius: 10px !important;
padding: 12px 30px !important;
font-weight: 600 !important;
font-size: 1rem !important;
box-shadow: 0 4px 15px rgba(149, 165, 166, 0.4) !important;
transition: all 0.3s ease !important;
}
.swal-cancel-modern:hover {
transform: translateY(-2px) !important;
box-shadow: 0 6px 20px rgba(149, 165, 166, 0.6) !important;
}
/* ✅ RESPONSIVE PARA LISTA DE PERSONAL */
@media (max-width: 768px) {
.list-group-item-action {
padding: 10px 15px;
}
.list-group-item-action strong {
font-size: 0.9rem;
}
.list-group-item-action small {
font-size: 0.8rem;
}
.section-card {
padding: 15px;
}
.upload-area {
padding: 20px;
}
.upload-area i {
font-size: 2rem;
}
.foto-preview {
width: 120px;
height: 120px;
}
}
</style>
</head>
<body class="bg-light">
<!-- ✅ HEADER (primero) -->
<?php include '../includes/header_empresa.php'; ?>
<!-- ✅ SIDEBAR (después del header) -->
<?php include '../includes/sidebar_empresa.php'; ?>
<!-- ✅ CONTENIDO PRINCIPAL WRAPPER -->
<div class="main-content-wrapper">
<div class="container mt-4">
<div class="row">
<!-- ==================== LISTADO DE PERSONAL INACTIVO ==================== -->
<div class="col-md-4">
<div class="card card-shadow mb-4">
<div class="card-header bg-secondary text-white">
<h5 class="mb-0">
<i class="fas fa-user-times"></i> Personal Inactivo
<span class="badge bg-light text-secondary"><?php echo count($personal_list); ?></span>
</h5>
</div>
<div class="card-body p-0">
<!-- Indicador de personal seleccionado -->
<?php if ($personal_seleccionado): ?>
<div class="personal-selected-indicator show">
<i class="fas fa-user-check"></i>
<strong><?php echo htmlspecialchars($personal_seleccionado['apellido'] . ', ' . $personal_seleccionado['nombre']); ?></strong>
<br><small>DNI: <?php echo htmlspecialchars($personal_seleccionado['dni']); ?></small>
<span class="badge-inactivo-custom ms-2">INACTIVO</span>
<?php
$estado_doc = $personal_seleccionado['estado_documentacion'] ?? 'pendiente';
$badge_doc_class = $estado_doc === 'aprobada' ? 'badge-doc-aprobada' :
($estado_doc === 'rechazada' ? 'badge-doc-rechazada' : 'badge-doc-pendiente');
$icono_doc = $estado_doc === 'aprobada' ? 'check-circle' :
($estado_doc === 'rechazada' ? 'times-circle' : 'clock');
?>
<br><small class="mt-1">
<span class="badge-estado-doc <?php echo $badge_doc_class; ?>">
<i class="fas fa-<?php echo $icono_doc; ?>"></i>
<?php echo ucfirst($estado_doc); ?>
</span>
</small>
</div>
<?php endif; ?>
<!-- ==================== BUSCADOR POR DNI ==================== -->
<div class="search-box-modern m-3">
<form method="GET" action="">
<label class="form-label small">
<i class="fas fa-fingerprint me-1"></i> Buscar por DNI
</label>
<div class="input-group">
<span class="input-group-text"><i class="fas fa-search"></i></span>
<input type="text" name="filtro_dni" class="form-control"
placeholder="Ej: 25464490"
value="<?php echo htmlspecialchars($filtro_dni); ?>"
maxlength="20">
<button type="submit" class="btn btn-secondary">
<i class="fas fa-search"></i>
</button>
<?php if (!empty($filtro_dni)): ?>
<a href="cargar_documentacion.php" class="btn btn-outline-secondary">
<i class="fas fa-times"></i>
</a>
<?php endif; ?>
</div>
</form>
</div>
<div class="list-group list-group-flush">
<?php if (count($personal_list) > 0): ?>
<?php foreach ($personal_list as $p): ?>
<a href="?personal_id=<?php echo $p['id']; ?><?php echo !empty($filtro_dni) ? '&filtro_dni=' . urlencode($filtro_dni) : ''; ?>"
class="list-group-item list-group-item-action <?php echo $personal_seleccionado && $personal_seleccionado['id'] == $p['id'] ? 'active' : ''; ?>">
<div class="d-flex w-100 justify-content-between align-items-center">
<div>
<strong><?php echo htmlspecialchars($p['apellido'] . ', ' . $p['nombre']); ?></strong>
<br><small>DNI: <?php echo htmlspecialchars($p['dni']); ?></small>
</div>
<span class="badge bg-secondary">
<i class="fas fa-user-times"></i> Inactivo
</span>
</div>
</a>
<?php endforeach; ?>
<?php else: ?>
<div class="list-group-item text-center py-4">
<i class="fas fa-inbox fa-2x text-muted"></i>
<p class="text-muted mb-0">No hay personal inactivo registrado</p>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
<!-- ==================== FORMULARIO DE EDICIÓN ==================== -->
<div class="col-md-8">
<?php if ($personal_seleccionado): ?>
<div class="card card-shadow">
<div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
<h4 class="mb-0">
<i class="fas fa-user-edit"></i> Gestionar Datos del Personal (Inactivo)
</h4>
<span class="badge bg-light text-secondary">
<i class="fas fa-user-times"></i> Inactivo
</span>
</div>
<div class="card-body">
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
<i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
<i class="fas fa-check-circle"></i> <?php echo $success; ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- Alerta de Campos Restringidos -->
<div class="alert alert-restricted mb-4">
<i class="fas fa-lock lock-icon"></i>
<strong>Campos Restringidos:</strong> Los campos de <strong>Estado y Certificaciones</strong> y <strong>Cupón de Pago</strong> solo pueden ser modificados por el <strong>Administrador del Sistema</strong> desde el panel de administración.
</div>
<!-- Alerta de Personal Inactivo -->
<div class="alert alert-warning mb-4">
<i class="fas fa-exclamation-triangle"></i>
<strong>Atención:</strong> Está editando datos de personal <strong>INACTIVO</strong>. Para reactivar al personal, contacte al administrador.
</div>
<!-- Alerta de Estado de Documentación -->
<?php
$estado_doc = $personal_seleccionado['estado_documentacion'] ?? 'pendiente';
if ($estado_doc === 'pendiente'):
?>
<div class="alert alert-warning mb-4">
<i class="fas fa-clock"></i>
<strong>Documentación Pendiente:</strong> La documentación cargada está esperando aprobación del administrador.
</div>
<?php elseif ($estado_doc === 'aprobada'): ?>
<div class="alert alert-success mb-4">
<i class="fas fa-check-circle"></i>
<strong>Documentación Aprobada:</strong> La documentación ha sido aprobada por el administrador.
<?php if (!empty($personal_seleccionado['fecha_revision_documentacion'])): ?>
<br><small>Revisado el: <?php echo date('d/m/Y H:i', strtotime($personal_seleccionado['fecha_revision_documentacion'])); ?></small>
<?php endif; ?>
</div>
<?php elseif ($estado_doc === 'rechazada'): ?>
<div class="alert alert-danger mb-4">
<i class="fas fa-times-circle"></i>
<strong>Documentación Rechazada:</strong> La documentación fue rechazada por el administrador.
<?php if (!empty($personal_seleccionado['fecha_revision_documentacion'])): ?>
<br><small>Revisado el: <?php echo date('d/m/Y H:i', strtotime($personal_seleccionado['fecha_revision_documentacion'])); ?></small>
<?php endif; ?>
<br><small>Debe corregir los observaciones y volver a cargar.</small>
</div>
<?php endif; ?>
<form method="POST" action="?personal_id=<?php echo $personal_seleccionado['id']; ?><?php echo !empty($filtro_dni) ? '&filtro_dni=' . urlencode($filtro_dni) : ''; ?>" enctype="multipart/form-data" id="formGestionPersonal">
<input type="hidden" name="actualizar_personal" value="1">
<input type="hidden" name="personal_id" value="<?php echo $personal_seleccionado['id']; ?>">
<!-- ==================== DATOS DE IDENTIDAD (SOLO LECTURA) ==================== -->
<div class="section-card">
<h6 class="section-title">
<i class="fas fa-id-card"></i> Datos de Identidad (Solo Lectura)
</h6>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Nombre</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['nombre']); ?>" readonly>
</div>
<div class="col-md-4">
<label class="form-label">Apellido</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['apellido']); ?>" readonly>
</div>
<div class="col-md-4">
<label class="form-label">DNI</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['dni']); ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Empresa</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['empresa_nombre']); ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['sucursal_nombre']); ?>" readonly>
</div>
</div>
</div>
<!-- ==================== DATOS PERSONALES (EDITABLES) ==================== -->
<div class="section-card">
<h6 class="section-title">
<i class="fas fa-user"></i> Datos Personales
</h6>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Fecha de Nacimiento</label>
<input type="date" name="fecha_nacimiento" class="form-control"
value="<?php echo htmlspecialchars($personal_seleccionado['fecha_nacimiento'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Teléfono</label>
<input type="text" name="telefono" class="form-control"
value="<?php echo htmlspecialchars($personal_seleccionado['telefono'] ?? ''); ?>">
</div>
<div class="col-md-4">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control"
value="<?php echo htmlspecialchars($personal_seleccionado['email'] ?? ''); ?>">
</div>
<div class="col-md-12">
<label class="form-label">Domicilio</label>
<input type="text" name="domicilio" class="form-control"
value="<?php echo htmlspecialchars($personal_seleccionado['domicilio'] ?? ''); ?>">
</div>
</div>
</div>
<!-- ==================== MODALIDAD DE CONTRATO (SOLO LECTURA) ==================== -->
<div class="section-card restricted">
<h6 class="section-title">
<i class="fas fa-file-contract"></i> Modalidad de Contrato (Solo Lectura)
</h6>
<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Cargo</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['cargo'] ?? ''); ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Fecha de Ingreso</label>
<input type="date" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['fecha_ingreso'] ?? ''); ?>" readonly>
</div>
<div class="col-md-4">
<label class="form-label">Fecha de Autorización</label>
<input type="date" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['fecha_autorizacion'] ?? ''); ?>" readonly>
</div>
<div class="col-md-4">
<label class="form-label">Fecha de Vencimiento</label>
<input type="date" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['fecha_vencimiento'] ?? ''); ?>" readonly>
</div>
<div class="col-md-4">
<label class="form-label">Fecha de Revalidación</label>
<input type="date" class="form-control readonly-field"
value="<?php echo htmlspecialchars($personal_seleccionado['fecha_revalidacion'] ?? ''); ?>" readonly>
</div>
<?php if (!empty($personal_seleccionado['modalidad_contrato'])): ?>
<div class="col-md-7">
<label class="form-label">Modalidad de Contrato</label>
<input type="text" class="form-control readonly-field"
value="<?php echo htmlspecialchars(obtenerDescripcionModalidad($personal_seleccionado['modalidad_contrato'], $modalidades_contrato)); ?>"
readonly>
</div>
<?php endif; ?>
</div>
</div>
<!-- ==================== DOCUMENTACIÓN (EDITABLES) ==================== -->
<div class="section-card">
<h6 class="section-title">
<i class="fas fa-file-upload"></i> Documentación (Solo Foto y PDF)
</h6>
<div class="row g-3">
<!-- Foto Carnet -->
<div class="col-md-6">
<label class="form-label">Foto Carnet</label>
<!-- Especificaciones de la foto -->
<div class="alert alert-info mb-2" style="font-size: 0.85rem; padding: 10px;">
<strong><i class="fas fa-info-circle"></i> Requisitos de la foto:</strong>
<ul class="mb-0 mt-1" style="padding-left: 20px;">
<li><strong>Tamaño:</strong> 285x354 píxeles (exactos)</li>
<li><strong>Formato:</strong> JPG o PNG</li>
<li><strong>Peso máximo:</strong> 2MB</li>
<li><strong>Fondo:</strong> Blanco o celeste claro</li>
<li><strong>Resolución:</strong> 300 DPI recomendado</li>
</ul>
</div>
<!-- Preview de referencia -->
<div class="text-center mb-2">
<small class="text-muted">
<i class="fas fa-image"></i> Tamaño de referencia: 285 x 354 píxeles
</small>
<div class="mt-1" style="width: 142px; height: 177px; border: 2px dashed #4361ee; border-radius: 8px; margin: 0 auto; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
<span class="text-muted small" style="font-size: 0.7rem;">285x354</span>
</div>
</div>
<div class="upload-area" onclick="document.getElementById('foto_input').click()">
<i class="fas fa-cloud-upload-alt"></i>
<p class="mb-0">Haga clic para subir foto</p>
<p class="text-muted small">JPG/PNG - Máx 2MB - 285x354 px</p>
<input type="file" id="foto_input" name="foto" class="d-none" accept=".jpg,.jpeg,.png">
</div>
<?php if (!empty($personal_seleccionado['foto'])): ?>
<div class="text-center mt-2">
<img src="../uploads/fotos_personal/<?php echo htmlspecialchars($personal_seleccionado['foto']); ?>"
class="foto-preview mb-2" alt="Foto Actual">
<div class="file-status-badge file-status-exists">
<i class="fas fa-check-circle"></i> Foto Existente
</div>
</div>
<?php else: ?>
<div class="text-center mt-2">
<div class="file-status-badge file-status-missing">
<i class="fas fa-exclamation-circle"></i> Sin Foto
</div>
</div>
<?php endif; ?>
</div>
<!-- PDF Datos Personales -->
<div class="col-md-6">
<label class="form-label">PDF Datos Personales</label>
<div class="upload-area" onclick="document.getElementById('pdf_input').click()">
<i class="fas fa-cloud-upload-alt"></i>
<p class="mb-0">Haga clic para subir PDF</p>
<p class="text-muted small">PDF - Máx 5MB</p>
<input type="file" id="pdf_input" name="pdf_datos_personales" class="d-none" accept=".pdf">
</div>
<?php if (!empty($personal_seleccionado['pdf_datos_personales'])): ?>
<div class="text-center mt-2">
<i class="fas fa-file-pdf fa-4x text-danger mb-2"></i>
<div class="file-status-badge file-status-exists">
<i class="fas fa-check-circle"></i> PDF Existente
</div>
<div class="mt-2">
<a href="../uploads/pdf_personal/<?php echo htmlspecialchars($personal_seleccionado['pdf_datos_personales']); ?>"
target="_blank" class="btn btn-sm btn-success">
<i class="fas fa-eye"></i> Ver
</a>
</div>
</div>
<?php else: ?>
<div class="text-center mt-2">
<div class="file-status-badge file-status-missing">
<i class="fas fa-exclamation-circle"></i> Sin PDF
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
<!-- ==================== OBSERVACIONES (EDITABLES) ==================== -->
<div class="section-card">
<h6 class="section-title">
<i class="fas fa-sticky-note"></i> Observaciones
</h6>
<div class="row g-3">
<div class="col-12">
<textarea name="observaciones" class="form-control" rows="4"
placeholder="Ingrese observaciones adicionales..."><?php echo htmlspecialchars($personal_seleccionado['observaciones'] ?? ''); ?></textarea>
</div>
</div>
</div>
<!-- ==================== BOTONES DE ACCIÓN ==================== -->
<div class="d-grid gap-2">
<button type="submit" class="btn btn-secondary btn-lg">
<i class="fas fa-save"></i> Guardar Cambios
</button>
<a href="dashboard.php" class="btn btn-outline-secondary btn-lg">
<i class="fas fa-arrow-left"></i> Volver al Panel
</a>
</div>
</form>
</div>
</div>
<?php else: ?>
<div class="card card-shadow">
<div class="card-body text-center py-5">
<i class="fas fa-user-times fa-4x text-muted mb-3"></i>
<h4>Seleccione un empleado inactivo</h4>
<p class="text-muted">Seleccione un miembro del personal inactivo de la lista para gestionar sus datos.</p>
<div class="alert alert-info mt-3">
<i class="fas fa-info-circle"></i>
Puede usar el buscador por DNI para encontrar más rápido al personal.
</div>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
<!-- ✅ ALERTA FLOTANTE DE URGENCIA -->
<?php if ($hay_urgencia): ?>
<div class="urgency-alert" id="urgencyAlert">
<i class="fas fa-exclamation-triangle"></i>
<div class="urgency-alert-content">
<h6>⚠️ TRÁMITE URGENTE</h6>
<p>Debe presentarse en las oficinas de forma URGENTE</p>
</div>
<button class="urgency-alert-close" onclick="closeUrgencyAlert()">
<i class="fas fa-times"></i>
</button>
</div>
<?php endif; ?>
<!-- ✅ SCRIPT UNIFICADO PARA TOGGLE SIDEBAR -->
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Validación del lado del cliente para fotos (DIMENSIONES 285x354)
document.getElementById('foto_input')?.addEventListener('change', function(e) {
const file = e.target.files[0];
if (file) {
const maxSize = 2 * 1024 * 1024; // 2MB
const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
if (file.size > maxSize) {
Swal.fire({
icon: 'warning',
title: 'Archivo Muy Grande',
text: 'La foto no debe superar los 2MB.',
confirmButtonColor: '#4361ee'
});
this.value = '';
return;
}
if (!allowedTypes.includes(file.type)) {
Swal.fire({
icon: 'warning',
title: 'Tipo No Permitido',
text: 'Tipo de archivo no permitido. Solo JPG, JPEG, PNG.',
confirmButtonColor: '#4361ee'
});
this.value = '';
return;
}
// VALIDAR DIMENSIONES DE LA IMAGEN
const img = new Image();
const objectUrl = URL.createObjectURL(file);
img.onload = function() {
URL.revokeObjectURL(objectUrl);
const width = this.width;
const height = this.height;
if (width !== 285 || height !== 354) {
Swal.fire({
icon: 'error',
title: 'Dimensiones Incorrectas',
html: `La foto debe tener dimensiones exactas de <strong>285x354 píxeles</strong>.<br><br>Dimensiones actuales: <strong>${width}x${height} píxeles</strong>.<br><br>Por favor, redimensione la imagen antes de subirla.`,
confirmButtonColor: '#4361ee',
confirmButtonText: '<i class="fas fa-check"></i> Entendido'
});
this.value = '';
return;
}
// Si las dimensiones son correctas, continuar
console.log('Dimensiones correctas:', width, 'x', height);
};
img.onerror = function() {
URL.revokeObjectURL(objectUrl);
Swal.fire({
icon: 'error',
title: 'Error al Cargar Imagen',
text: 'No se pudo cargar la imagen para validar sus dimensiones.',
confirmButtonColor: '#4361ee'
});
this.value = '';
};
img.src = objectUrl;
}
});
// Validación del lado del cliente para PDF
document.getElementById('pdf_input')?.addEventListener('change', function(e) {
const file = e.target.files[0];
if (file) {
const maxSize = 5 * 1024 * 1024; // 5MB
const allowedTypes = ['application/pdf'];
if (file.size > maxSize) {
Swal.fire({
icon: 'warning',
title: 'Archivo Muy Grande',
text: 'El PDF no debe superar los 5MB.',
confirmButtonColor: '#4361ee'
});
this.value = '';
return;
}
if (!allowedTypes.includes(file.type)) {
Swal.fire({
icon: 'warning',
title: 'Tipo No Permitido',
text: 'Tipo de archivo no permitido. Solo PDF.',
confirmButtonColor: '#4361ee'
});
this.value = '';
return;
}
}
});
// Validación del campo DNI (solo números)
document.querySelector('input[name="filtro_dni"]')?.addEventListener('input', function(e) {
this.value = this.value.replace(/[^0-9-]/g, '');
});
// ==================== CONFIRMACIÓN DE GUARDADO MODERNA ====================
document.getElementById('formGestionPersonal')?.addEventListener('submit', function(e) {
e.preventDefault();
if (!this.checkValidity()) {
this.classList.add('was-validated');
Swal.fire({
icon: 'warning',
title: 'Campos Requeridos',
text: 'Por favor complete todos los campos obligatorios',
confirmButtonColor: '#4361ee'
});
return;
}
// Confirmación moderna de guardado
Swal.fire({
title: '<i class="fas fa-save"></i> ¿Guardar Cambios?',
html: `
<div class="swal-content-modern">
<p class="swal-message">
<i class="fas fa-info-circle"></i>
Se actualizarán los datos del personal <strong class="text-danger">INACTIVO</strong>
</p>
<div class="swal-warning-box">
<i class="fas fa-exclamation-triangle"></i>
<span>La documentación quedará pendiente de aprobación del administrador</span>
</div>
</div>
`,
icon: 'question',
showCancelButton: true,
confirmButtonColor: '#95a5a6',
cancelButtonColor: '#7f8c8d',
confirmButtonText: '<i class="fas fa-check-circle"></i> Sí, Guardar',
cancelButtonText: '<i class="fas fa-times-circle"></i> Cancelar',
reverseButtons: true,
focusCancel: false,
customClass: {
popup: 'swal-popup-modern',
title: 'swal-title-modern',
confirmButton: 'swal-confirm-modern',
cancelButton: 'swal-cancel-modern'
}
}).then((result) => {
if (result.isConfirmed) {
// Mostrar loading mientras se guarda
Swal.fire({
title: 'Guardando...',
html: 'Por favor espere mientras se actualizan los datos',
timer: 1500,
timerProgressBar: true,
didOpen: () => {
Swal.showLoading()
},
allowOutsideClick: false,
allowEscapeKey: false,
showConfirmButton: false,
customClass: {
popup: 'swal-popup-modern'
}
}).then(() => {
this.submit();
});
}
});
});
// ✅ Funciones para alerta de urgencia
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
// ✅ Toggle Sidebar con Persistencia de Estado
document.addEventListener('DOMContentLoaded', function() {
const toggleBtn = document.getElementById('toggleSidebarBtn');
const toggleIcon = document.getElementById('toggleIcon');
const overlay = document.getElementById('sidebarOverlay');
const body = document.body;
const sidebar = document.querySelector('.sidebar-moderno');
// ✅ RESTAURAR ESTADO GUARDADO AL CARGAR
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
// ✅ GUARDAR ESTADO EN LOCALSTORAGE
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
// ✅ DETECTAR CAMBIO DE TAMAÑO DE VENTANA
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