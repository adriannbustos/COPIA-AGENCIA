<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';
// Verificar autenticación y rol empresa
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['rol'] !== 'empresa') {
    header('Location: ../login.php');
    exit;
}
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$empresa_id = $user['empresa_id'];
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
// Verificar columnas
$columnCheckPDF = $conn->query("SHOW COLUMNS FROM servicios LIKE 'pdf_file'")->fetch();
$hasPDF = !empty($columnCheckPDF);
$columnCheck = $conn->query("SHOW COLUMNS FROM servicios LIKE 'hora_inicio'")->fetch();
$hasHorarios = !empty($columnCheck);
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
    // Silencioso si la tabla no existe
}
// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo = $_POST['tipo'] ?? 'vigilancia';
        $prioridad = $_POST['prioridad'] ?? 'media';
        $estado = 'pendiente'; // ✅ FORZAR ESTADO A PENDIENTE
        $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
        $fecha_fin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
        $sucursal_id = !empty($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : null;
        $domicilio = trim($_POST['domicilio'] ?? '');
        $jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
        $hora_inicio = $hasHorarios && !empty($_POST['hora_inicio']) ? $_POST['hora_inicio'] : null;
        $hora_fin = $hasHorarios && !empty($_POST['hora_fin']) ? $_POST['hora_fin'] : null;
        $dias_semana = $hasHorarios && isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : null;
        $personal_id = $hasHorarios && !empty($_POST['personal_id']) ? (int)$_POST['personal_id'] : null;
        // Subida de PDF
        $pdf_file = null;
        if ($hasPDF && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['application/pdf'];
            $max_size = 5 * 1024 * 1024;
            if (!in_array($_FILES['pdf_file']['type'], $allowed_types)) {
                throw new Exception('Solo se permiten archivos PDF');
            }
            if ($_FILES['pdf_file']['size'] > $max_size) {
                throw new Exception('El PDF no puede superar los 5MB');
            }
            $upload_dir = '../uploads/servicios/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $extension = pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION);
            $pdf_file = time() . '_' . uniqid() . '.' . $extension;
            $file_path = $upload_dir . $pdf_file;
            if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $file_path)) {
                throw new Exception('Error al subir el archivo PDF');
            }
        }
        // Validaciones básicas
        if (empty($nombre)) {
            throw new Exception('El nombre del servicio es obligatorio');
        }
        if (empty($fecha_inicio)) {
            throw new Exception('La fecha de inicio es obligatoria');
        }
        // INSERT con estado fijo en pendiente
        if ($hasHorarios) {
            if ($hasPDF && $pdf_file) {
                $stmt = $conn->prepare("
                INSERT INTO servicios (
                nombre, descripcion, tipo, prioridad, estado,
                fecha_inicio, fecha_fin, hora_inicio, hora_fin,
                dias_semana, personal_id, empresa_id, sucursal_id,
                domicilio, jurisdiccion, pdf_file
                ) VALUES (?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre, $descripcion, $tipo, $prioridad,
                    $fecha_inicio, $fecha_fin, $hora_inicio, $hora_fin,
                    $dias_semana, $personal_id, $empresa_id, $sucursal_id,
                    $domicilio, $jurisdiccion, $pdf_file
                ]);
            } else {
                $stmt = $conn->prepare("
                INSERT INTO servicios (
                nombre, descripcion, tipo, prioridad, estado,
                fecha_inicio, fecha_fin, hora_inicio, hora_fin,
                dias_semana, personal_id, empresa_id, sucursal_id,
                domicilio, jurisdiccion
                ) VALUES (?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre, $descripcion, $tipo, $prioridad,
                    $fecha_inicio, $fecha_fin, $hora_inicio, $hora_fin,
                    $dias_semana, $personal_id, $empresa_id, $sucursal_id,
                    $domicilio, $jurisdiccion
                ]);
            }
        } else {
            if ($hasPDF && $pdf_file) {
                $stmt = $conn->prepare("
                INSERT INTO servicios (
                nombre, descripcion, tipo, prioridad, estado,
                fecha_inicio, fecha_fin, empresa_id, sucursal_id,
                domicilio, jurisdiccion, pdf_file
                ) VALUES (?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre, $descripcion, $tipo, $prioridad,
                    $fecha_inicio, $fecha_fin, $empresa_id, $sucursal_id,
                    $domicilio, $jurisdiccion, $pdf_file
                ]);
            } else {
                $stmt = $conn->prepare("
                INSERT INTO servicios (
                nombre, descripcion, tipo, prioridad, estado,
                fecha_inicio, fecha_fin, empresa_id, sucursal_id,
                domicilio, jurisdiccion
                ) VALUES (?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre, $descripcion, $tipo, $prioridad,
                    $fecha_inicio, $fecha_fin, $empresa_id, $sucursal_id,
                    $domicilio, $jurisdiccion
                ]);
            }
        }
        $new_id = $conn->lastInsertId();
        logAuditoria($conn, 'servicio_creado_empresa', 'servicios', $new_id, [
            'nombre' => $nombre,
            'empresa_id' => $empresa_id,
            'estado_forzado' => 'pendiente'
        ]);
        $_SESSION['success'] = 'Servicio creado correctamente. Estado: Pendiente de aprobación';
        header('Location: cargar_servicios_empresa.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        header('Location: cargar_servicios_empresa.php');
        exit;
    }
}
// Obtener sucursales de la empresa
$stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? ORDER BY nombre");
$stmt->execute([$empresa_id]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Obtener personal de la empresa
$stmt = $conn->prepare("SELECT id, nombre, cargo FROM personal WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
$stmt->execute([$empresa_id]);
$personal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Obtener servicios de la empresa
$stmt = $conn->prepare("
SELECT s.*, suc.nombre as sucursal_nombre
FROM servicios s
LEFT JOIN sucursales suc ON s.sucursal_id = suc.id
WHERE s.empresa_id = ?
ORDER BY s.created_at DESC
LIMIT 10
");
$stmt->execute([$empresa_id]);
$mis_servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargar Servicios - Empresa</title>
    <!-- Mantener CDN para Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Pero usar locales para Bootstrap y SweetAlert2 -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/sweetalert2.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
    /* ✅ ESTILOS ESPECÍFICOS DE CARGAR SERVICIOS */
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
    .urgency-alert i {
        font-size: 1.8rem;
    }
    .urgency-alert-content h6 {
        margin: 0;
        font-weight: 700;
        font-size: 0.95rem;
    }
    .urgency-alert-content p {
        margin: 5px 0 0;
        font-size: 0.85rem;
        opacity: 0.9;
    }
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
    .urgency-alert-close:hover {
        background: rgba(255,255,255,0.3);
    }
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes pulse {
        0%, 100% { box-shadow: 0 10px 40px rgba(220, 53, 69, 0.4); }
        50% { box-shadow: 0 10px 60px rgba(220, 53, 69, 0.6); }
    }
    .form-card {
        background: white;
        border-radius: 15px;
        padding: 30px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }
    .form-card-title {
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
    }
    .btn-pending {
        background: linear-gradient(135deg, #f39c12, #d35400);
        color: white;
        border: none;
    }
    .btn-pending:hover {
        background: linear-gradient(135deg, #d35400, #c0392b);
        color: white;
    }
    .status-badge {
        padding: 5px 15px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .info-alert {
        background: linear-gradient(135deg, #fff3cd, #ffeaa7);
        border-left: 4px solid #f39c12;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    /* ✅ RESPONSIVE PARA FORMULARIOS */
    @media (max-width: 768px) {
        .form-card {
            padding: 20px;
        }
        .form-card-title {
            font-size: 1.1rem;
        }
    }
    </style>
</head>
<body>
<!-- ✅ HEADER (primero) -->
<?php include '../includes/header_empresa.php'; ?>
<!-- ✅ SIDEBAR (después del header) -->
<?php include '../includes/sidebar_empresa.php'; ?>

<!-- ✅ CONTENIDO PRINCIPAL WRAPPER -->
<div class="main-content-wrapper">
<div class="container mt-4">
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

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

<div class="row">
    <div class="col-lg-8">
        <div class="form-card">
            <div class="form-card-title">
                <i class="fas fa-concierge-bell text-warning"></i>
                Registrar Nuevo Servicio
            </div>
            <div class="info-alert">
                <i class="fas fa-info-circle"></i>
                <strong>Importante:</strong> Todos los servicios creados desde este formulario quedarán en estado
                <strong class="text-warning">⏳ Pendiente</strong> hasta que un administrador los revise y apruebe.
                No podrá modificar el estado del servicio.
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="card mb-3">
                    <div class="card-header bg-light"><strong>📋 Información Básica</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre del Servicio *</label>
                                <input type="text" name="nombre" class="form-control" required placeholder="Ej: Vigilancia Nocturna">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tipo de Servicio *</label>
                                <select name="tipo" class="form-select" required>
                                    <option value="">Seleccione un tipo...</option>
                                    <option value="vigilancia">👮 Vigilancia</option>
                                    <option value="escolta">🚗 Escolta</option>
                                    <option value="eventos">🎉 Eventos</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sucursal</label>
                                <select name="sucursal_id" class="form-select">
                                    <option value="">Seleccione sucursal...</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                    <option value="<?php echo $sucursal['id']; ?>"><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Domicilio</label>
                                <input type="text" name="domicilio" class="form-control" placeholder="Ej: Av. Principal 123">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jurisdicción</label>
                                <input type="text" name="jurisdiccion" class="form-control" placeholder="Ej: Buenos Aires">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Prioridad *</label>
                                <select name="prioridad" class="form-select" required>
                                    <option value="baja">🟢 Baja</option>
                                    <option value="media" selected>🟡 Media</option>
                                    <option value="alta">🔴 Alta</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($hasHorarios): ?>
                <div class="card mb-3">
                    <div class="card-header bg-light"><strong>🕐 Horarios y Personal</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Hora Inicio</label>
                                <input type="time" name="hora_inicio" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hora Fin</label>
                                <input type="time" name="hora_fin" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Días de Atención</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="1" class="form-check-input"> Lunes
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="2" class="form-check-input"> Martes
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="3" class="form-check-input"> Miércoles
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="4" class="form-check-input"> Jueves
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="5" class="form-check-input"> Viernes
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="6" class="form-check-input"> Sábado
                                    </label>
                                    <label class="form-check">
                                        <input type="checkbox" name="dias_semana[]" value="0" class="form-check-input"> Domingo
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Personal Asignado</label>
                                <select name="personal_id" class="form-select">
                                    <option value="">Seleccione personal (opcional)...</option>
                                    <?php foreach ($personal_list as $p): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo htmlspecialchars($p['nombre']); ?>
                                        (<?php echo htmlspecialchars($p['cargo'] ?? 'Operativo'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="card mb-3">
                    <div class="card-header bg-light"><strong>📅 Fechas y Estado</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Fecha de Inicio *</label>
                                <input type="date" name="fecha_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha de Fin</label>
                                <input type="date" name="fecha_fin" class="form-control">
                            </div>
                            <!-- ✅ ESTADO FIJO - NO MODIFICABLE POR EMPRESA -->
                            <div class="col-md-6">
                                <label class="form-label">Estado</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="⏳ Pendiente (Solo Administrador puede modificar)" disabled>
                                    <span class="input-group-text bg-warning text-dark">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                </div>
                                <input type="hidden" name="estado" value="pendiente">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Este campo no es editable. Un administrador aprobará el servicio.
                                </small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3" placeholder="Descripción detallada del servicio..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($hasPDF): ?>
                <div class="card mb-3">
                    <div class="card-header bg-light"><strong>📄 Documentación PDF</strong></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Archivo PDF (Opcional)</label>
                            <input type="file" name="pdf_file" class="form-control" accept=".pdf" id="pdfFile">
                            <small class="text-muted">Máximo 5MB. Solo archivos PDF.</small>
                            <div id="pdfPreview" class="mt-2"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-pending btn-lg">
                        <i class="fas fa-save"></i> Registrar Servicio (Estado: Pendiente)
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver al Dashboard
                    </a>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="form-card">
            <div class="form-card-title">
                <i class="fas fa-history text-primary"></i>
                Mis Servicios Recientes
            </div>
            <?php if (count($mis_servicios) > 0): ?>
            <div class="list-group">
                <?php foreach ($mis_servicios as $servicio): ?>
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1"><?php echo htmlspecialchars($servicio['nombre']); ?></h6>
                        <span class="status-badge bg-warning text-dark">
                            <i class="fas fa-clock"></i> <?php echo ucfirst($servicio['estado']); ?>
                        </span>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($servicio['created_at'])); ?>
                    </small>
                    <?php if ($servicio['sucursal_nombre']): ?>
                    <br>
                    <small class="text-primary">
                        <i class="fas fa-store"></i> <?php echo htmlspecialchars($servicio['sucursal_nombre']); ?>
                    </small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-muted text-center">No hay servicios registrados</p>
            <?php endif; ?>
        </div>
        <div class="form-card">
            <div class="form-card-title">
                <i class="fas fa-info-circle text-info"></i>
                Información Importante
            </div>
            <div class="alert alert-info mb-0">
                <i class="fas fa-exclamation-circle"></i>
                <strong>Nota:</strong> Todos los servicios creados por empresas quedan en estado
                <strong>Pendiente</strong> hasta que un administrador los revise y apruebe.
                <hr class="my-2">
                <small>
                    El administrador podrá cambiar el estado a: Activo, Completado o Cancelado.
                </small>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<!-- ✅ SCRIPT UNIFICADO PARA TOGGLE SIDEBAR -->
<script src="../css/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Preview de PDF
document.getElementById('pdfFile')?.addEventListener('change', function(e) {
    const preview = document.getElementById('pdfPreview');
    if (e.target.files && e.target.files[0]) {
        const file = e.target.files[0];
        if (file.type !== 'application/pdf') {
            preview.innerHTML = '<div class="alert alert-danger">Solo se permiten archivos PDF</div>';
            e.target.value = '';
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            preview.innerHTML = '<div class="alert alert-danger">El PDF no puede superar los 5MB</div>';
            e.target.value = '';
            return;
        }
        preview.innerHTML = '<div class="alert alert-success"><i class="fas fa-check"></i> ' + file.name + ' listo para cargar</div>';
    } else {
        preview.innerHTML = '';
    }
});

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

// Función para cerrar la alerta de urgencia
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
</script>
</body>
</html>