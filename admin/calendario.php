<?php
require_once '../config/database.php';
require_once '../config/auth.php';
if (!$auth->isLoggedIn()) {
header('Location: ../login.php');
exit;
}
$user = $auth->getCurrentUser();
$conn = getDBConnection();
// ==================== OBTENER EVENTOS DEL CALENDARIO ====================
$eventos = [];
// 1. SERVICIOS - Vencimientos
$stmt = $conn->prepare("
SELECT
'servicio' as tipo,
id,
nombre as titulo,
fecha_inicio,
fecha_fin as fecha_vencimiento,
estado,
empresa_id,
sucursal_id,
'servicios.php?edit=' as enlace
FROM servicios
WHERE fecha_fin IS NOT NULL
AND fecha_fin >= CURDATE() - INTERVAL 30 DAY
ORDER BY fecha_fin ASC
LIMIT 50
");
$stmt->execute();
$servicios_vencimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($servicios_vencimientos as $s) {
$eventos[] = [
'tipo' => 'servicio',
'id' => $s['id'],
'titulo' => $s['titulo'],
'fecha' => $s['fecha_vencimiento'],
'estado' => $s['estado'],
'fecha_envio' => $s['fecha_inicio'] ?? $s['fecha_vencimiento'],
'color' => '#0d6efd',
'icono' => 'fa-concierge-bell',
'enlace' => "servicios.php?edit={$s['id']}"
];
}
// 2. SUCURSALES - Vencimientos (Habilitación, Arancel, Resolución)
$stmt = $conn->prepare("
SELECT
'sucursal' as tipo,
id,
nombre as titulo,
fecha_habilitacion,
fecha_pago_arancel,
fecha_resolucion,
estado_aprobacion,
empresa_id,
'sucursales.php?edit=' as enlace
FROM sucursales
WHERE (fecha_habilitacion IS NOT NULL OR fecha_pago_arancel IS NOT NULL OR fecha_resolucion IS NOT NULL)
ORDER BY id ASC
LIMIT 200
");
$stmt->execute();
$sucursales_vencimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sucursales_vencimientos as $s) {
// Fecha de habilitación
if (!empty($s['fecha_habilitacion'])) {
$eventos[] = [
'tipo' => 'sucursal_habilitacion',
'id' => $s['id'],
'titulo' => $s['titulo'] . ' - Habilitación',
'fecha' => $s['fecha_habilitacion'],
'estado' => $s['estado_aprobacion'],
'fecha_envio' => $s['fecha_habilitacion'],
'color' => '#28a745',
'icono' => 'fa-building',
'enlace' => "sucursales.php?edit={$s['id']}"
];
}
// Fecha de pago de arancel - Lógica especial: 370 días desde último pago o 02/01 anual
if (!empty($s['fecha_pago_arancel'])) {
// Calcular 370 días desde la fecha de último pago
$fecha_base = new DateTime($s['fecha_pago_arancel']);
$fecha_base->modify('+370 days');
$fecha_arancel = $fecha_base->format('Y-m-d');
} else {
// Sin fecha de pago: usar 02 de enero de cada año
$anio_actual = date('Y');
$fecha_enero = $anio_actual . '-01-02';
// Si ya pasó el 02/01 de este año, usar el del próximo año
if ($fecha_enero < date('Y-m-d')) {
$fecha_enero = ($anio_actual + 1) . '-01-02';
}
$fecha_arancel = $fecha_enero;
}
// Solo agregar evento si la fecha calculada está dentro del rango de visualización
if ($fecha_arancel && $fecha_arancel >= date('Y-m-d', strtotime('-30 days'))) {
$eventos[] = [
'tipo' => 'sucursal_arancel',
'id' => $s['id'],
'titulo' => $s['titulo'] . ' - Arancel',
'fecha' => $fecha_arancel,
'estado' => $s['estado_aprobacion'],
'fecha_envio' => $s['fecha_pago_arancel'] ?? ($anio_actual . '-01-02'),
'color' => '#ffc107',
'icono' => 'fa-money-bill',
'enlace' => "sucursales.php?edit={$s['id']}",
'meta' => [
'ultimo_pago' => $s['fecha_pago_arancel'],
'es_anual' => empty($s['fecha_pago_arancel'])
]
];
}
// Fecha de resolución
if (!empty($s['fecha_resolucion'])) {
$eventos[] = [
'tipo' => 'sucursal_resolucion',
'id' => $s['id'],
'titulo' => $s['titulo'] . ' - Resolución',
'fecha' => $s['fecha_resolucion'],
'estado' => $s['estado_aprobacion'],
'fecha_envio' => $s['fecha_resolucion'],
'color' => '#17a2b8',
'icono' => 'fa-file-alt',
'enlace' => "sucursales.php?edit={$s['id']}"
];
}
}
// 3. PERSONAL - Vencimientos (Credencial, Revalidación, Documentos)
$stmt = $conn->prepare("
SELECT
'personal' as tipo,
id,
CONCAT(apellido, ', ', nombre) as titulo,
fecha_vencimiento,
fecha_revalidacion,
fecha_pago_credencial,
activo,
estado_documentacion,
sucursal_id,
'personal.php?edit=' as enlace
FROM personal
WHERE (fecha_vencimiento IS NOT NULL OR fecha_revalidacion IS NOT NULL)
AND (
fecha_vencimiento >= CURDATE() - INTERVAL 30 DAY OR
fecha_revalidacion >= CURDATE() - INTERVAL 30 DAY
)
ORDER BY fecha_vencimiento ASC
LIMIT 50
");
$stmt->execute();
$personal_vencimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($personal_vencimientos as $p) {
// Fecha de vencimiento de credencial
if (!empty($p['fecha_vencimiento'])) {
$eventos[] = [
'tipo' => 'personal_credencial',
'id' => $p['id'],
'titulo' => $p['titulo'] . ' - Credencial',
'fecha' => $p['fecha_vencimiento'],
'estado' => $p['activo'] ? 'activo' : 'inactivo',
'estado_doc' => $p['estado_documentacion'] ?? null,
'fecha_envio' => $p['fecha_pago_credencial'] ?? $p['fecha_vencimiento'] ?? $p['fecha_revalidacion'],
'color' => '#dc3545',
'icono' => 'fa-id-card',
'enlace' => "personal.php?edit={$p['id']}"
];
}
// Fecha de revalidación
if (!empty($p['fecha_revalidacion'])) {
$eventos[] = [
'tipo' => 'personal_revalidacion',
'id' => $p['id'],
'titulo' => $p['titulo'] . ' - Revalidación',
'fecha' => $p['fecha_revalidacion'],
'estado' => $p['activo'] ? 'activo' : 'inactivo',
'estado_doc' => $p['estado_documentacion'] ?? null,
'fecha_envio' => $p['fecha_pago_credencial'] ?? $p['fecha_vencimiento'] ?? $p['fecha_revalidacion'],
'color' => '#fd7e14',
'icono' => 'fa-sync-alt',
'enlace' => "personal.php?edit={$p['id']}"
];
}
}
// 4. RECURSOS - Vencimientos (Chalecos, Vehículos, etc.)
$stmt = $conn->prepare("
SELECT
'recurso' as tipo,
rs.id,
CONCAT(e.nombre, ' - ', s.nombre) as titulo,
ri.atributos,
rs.estado,
rs.created_at as fecha_creacion,
'recursos.php?edit=' as enlace
FROM recursos_sucursal rs
LEFT JOIN empresas e ON rs.empresa_id = e.id
LEFT JOIN sucursales s ON rs.sucursal_id = s.id
LEFT JOIN recursos_items ri ON rs.id = ri.recursos_sucursal_id
WHERE rs.estado = 'aprobado'
AND rs.created_at >= CURDATE() - INTERVAL 30 DAY
ORDER BY rs.created_at DESC
LIMIT 50
");
$stmt->execute();
$recursos_envios = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recursos_envios as $r) {
$atributos = json_decode($r['atributos'], true);
$fecha_vencimiento = $atributos['Fecha de Vencimiento'] ?? $atributos['VTV Vencimiento'] ?? null;
$eventos[] = [
'tipo' => 'recurso_envio',
'id' => $r['id'],
'titulo' => $r['titulo'] . ' - Recurso',
'fecha' => $fecha_vencimiento ?? $r['fecha_creacion'],
'estado' => $r['estado'],
'fecha_envio' => $r['fecha_creacion'],
'color' => '#6f42c1',
'icono' => 'fa-boxes',
'enlace' => "recursos.php?edit={$r['id']}"
];
}
// ==================== ORDENAR EVENTOS POR FECHA ====================
usort($eventos, function($a, $b) {
return strtotime($a['fecha']) - strtotime($b['fecha']);
});
// ==================== ESTADÍSTICAS ====================
$hoy = new DateTime();
$proximos_7_dias = clone $hoy;
$proximos_7_dias->modify('+7 days');
$proximos_30_dias = clone $hoy;
$proximos_30_dias->modify('+30 days');
$vencimientos_7_dias = 0;
$vencimientos_30_dias = 0;
$vencimientos_vencidos = 0;
foreach ($eventos as $evento) {
$fecha_evento = new DateTime($evento['fecha']);
if ($fecha_evento < $hoy) {
$vencimientos_vencidos++;
} elseif ($fecha_evento <= $proximos_7_dias) {
$vencimientos_7_dias++;
} elseif ($fecha_evento <= $proximos_30_dias) {
$vencimientos_30_dias++;
}
}
// ==================== FILTROS ====================
$filtro_tipo = $_GET['filtro_tipo'] ?? 'todos';
$filtro_mes = $_GET['filtro_mes'] ?? date('Y-m');
$filtro_estado = $_GET['filtro_estado'] ?? 'todos';
if ($filtro_tipo !== 'todos') {
$eventos = array_filter($eventos, function($e) use ($filtro_tipo) {
return strpos($e['tipo'], $filtro_tipo) !== false;
});
}
if ($filtro_mes !== 'todos') {
$eventos = array_filter($eventos, function($e) use ($filtro_mes) {
return substr($e['fecha'], 0, 7) === $filtro_mes;
});
}
// ==================== GENERAR CALENDARIO MENSUAL ====================
$anio_actual = date('Y', strtotime($filtro_mes));
$mes_actual = date('m', strtotime($filtro_mes));
$dias_en_mes = cal_days_in_month(CAL_GREGORIAN, $mes_actual, $anio_actual);
$primer_dia_semana = date('w', mktime(0, 0, 0, $mes_actual, 1, $anio_actual));
$eventos_por_dia = [];
foreach ($eventos as $evento) {
$dia = date('d', strtotime($evento['fecha']));
if (!isset($eventos_por_dia[$dia])) {
$eventos_por_dia[$dia] = [];
}
$eventos_por_dia[$dia][] = $evento;
}
$meses = [
'01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
'05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
'09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendario de Vencimientos - Sistema de Seguridad</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
--primary-color: #0d6efd;
--bg-color: #f8f9fa;
--card-border: #dee2e6;
}
body {
padding-top: 80px;
font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
background-color: var(--bg-color);
}
.section-box {
background: #ffffff;
border: 1px solid var(--card-border);
border-radius: 4px;
padding: 20px;
margin-bottom: 20px;
box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
.calendar-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 20px;
padding: 15px;
background: #f8f9fa;
border-radius: 4px;
}
.calendar-grid {
display: grid;
grid-template-columns: repeat(7, 1fr);
gap: 2px;
background: var(--card-border);
border: 1px solid var(--card-border);
}
.calendar-day-header {
background: #f8f9fa;
padding: 10px;
text-align: center;
font-weight: 600;
color: #495057;
}
.calendar-day {
background: #ffffff;
min-height: 100px;
padding: 5px;
position: relative;
}
.calendar-day.empty {
background: #f8f9fa;
}
.calendar-day.today {
background: #e3f2fd;
border: 2px solid var(--primary-color);
}
.calendar-day.has-events {
background: #fff3cd;
}
.event-badge {
display: block;
font-size: 0.7rem;
padding: 2px 4px;
margin: 1px 0;
border-radius: 3px;
color: white;
white-space: nowrap;
overflow: hidden;
text-overflow: ellipsis;
cursor: pointer;
}
.event-count {
position: absolute;
top: 5px;
right: 5px;
background: var(--primary-color);
color: white;
border-radius: 50%;
width: 20px;
height: 20px;
font-size: 0.7rem;
display: flex;
align-items: center;
justify-content: center;
}
.filter-section {
background: #f8f9fa;
padding: 15px;
border-radius: 4px;
margin-bottom: 20px;
}
.timeline-item {
display: flex;
gap: 15px;
padding: 10px;
border-left: 3px solid var(--primary-color);
margin-bottom: 10px;
background: #ffffff;
border-radius: 4px;
}
.timeline-item.vencido {
border-left-color: #dc3545;
background: #fff5f5;
}
.timeline-item.proximo {
border-left-color: #ffc107;
background: #fffdf5;
}
.timeline-item.vigente {
border-left-color: #28a745;
background: #f5fff5;
}
.timeline-date {
min-width: 100px;
font-weight: 600;
color: #495057;
}
.timeline-content {
flex: 1;
}
.timeline-title {
font-weight: 600;
margin-bottom: 5px;
}
.timeline-meta {
font-size: 0.85rem;
color: #6c757d;
}
</style>
</head>
<body>
<?php $page_title = 'Calendario de Vencimientos'; include '../includes/header.php'; ?>
<div class="dashboard">
<?php include '../includes/sidebar.php'; ?>
<div class="main-content" style="margin-left: 280px; padding: 20px;">
<!-- ESTADÍSTICAS -->
<div class="stats-container">
<div class="stat-card" style="border-left: 4px solid #dc3545;">
<div class="stat-number" style="color: #dc3545;"><?php echo $vencimientos_vencidos; ?></div>
<div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Vencidos</div>
</div>
<div class="stat-card" style="border-left: 4px solid #ffc107;">
<div class="stat-number" style="color: #ffc107;"><?php echo $vencimientos_7_dias; ?></div>
<div class="stat-label"><i class="fas fa-clock"></i> Próximos 7 Días</div>
</div>
<div class="stat-card" style="border-left: 4px solid #0d6efd;">
<div class="stat-number" style="color: #0d6efd;"><?php echo $vencimientos_30_dias; ?></div>
<div class="stat-label"><i class="fas fa-calendar"></i> Próximos 30 Días</div>
</div>
<div class="stat-card" style="border-left: 4px solid #28a745;">
<div class="stat-number" style="color: #28a745;"><?php echo count($eventos); ?></div>
<div class="stat-label"><i class="fas fa-list"></i> Total Eventos</div>
</div>
</div>
<!-- FILTROS -->
<div class="filter-section">
<form method="GET" class="row g-3">
<div class="col-md-3">
<label class="form-label">Tipo de Evento</label>
<select name="filtro_tipo" class="form-select">
<option value="todos" <?php echo $filtro_tipo === 'todos' ? 'selected' : ''; ?>>Todos</option>
<option value="servicio" <?php echo $filtro_tipo === 'servicio' ? 'selected' : ''; ?>>Servicios</option>
<option value="sucursal" <?php echo $filtro_tipo === 'sucursal' ? 'selected' : ''; ?>>Sucursales</option>
<option value="personal" <?php echo $filtro_tipo === 'personal' ? 'selected' : ''; ?>>Personal</option>
<option value="recurso" <?php echo $filtro_tipo === 'recurso' ? 'selected' : ''; ?>>Recursos</option>
</select>
</div>
<div class="col-md-3">
<label class="form-label">Mes</label>
<input type="month" name="filtro_mes" class="form-control" value="<?php echo $filtro_mes; ?>">
</div>
<div class="col-md-2">
<label class="form-label">&nbsp;</label>
<button type="submit" class="btn btn-primary w-100">
<i class="fas fa-filter"></i> Filtrar
</button>
</div>
<div class="col-md-2">
<label class="form-label">&nbsp;</label>
<a href="calendario.php" class="btn btn-secondary w-100">
<i class="fas fa-undo"></i> Limpiar
</a>
</div>
<div class="col-md-2">
<label class="form-label">&nbsp;</label>
<button type="button" class="btn btn-success w-100" onclick="exportarCalendario()">
<i class="fas fa-file-export"></i> Exportar
</button>
</div>
</form>
</div>
<!-- CALENDARIO MENSUAL -->
<div class="section-box">
<div class="calendar-header">
<div>
<a href="?filtro_mes=<?php echo date('Y-m', strtotime($filtro_mes . ' -1 month')); ?>" class="btn btn-sm btn-outline-primary">
<i class="fas fa-chevron-left"></i> Anterior
</a>
<span class="mx-3 fw-bold" style="font-size: 1.25rem;">
<?php echo $meses[$mes_actual] . ' ' . $anio_actual; ?>
</span>
<a href="?filtro_mes=<?php echo date('Y-m', strtotime($filtro_mes . ' +1 month')); ?>" class="btn btn-sm btn-outline-primary">
Siguiente <i class="fas fa-chevron-right"></i>
</a>
</div>
<div>
<button class="btn btn-sm btn-outline-secondary" onclick="location.href='?filtro_mes=<?php echo date('Y-m'); ?>'">
<i class="fas fa-calendar-day"></i> Mes Actual
</button>
</div>
</div>
<div class="calendar-grid">
<div class="calendar-day-header">Dom</div>
<div class="calendar-day-header">Lun</div>
<div class="calendar-day-header">Mar</div>
<div class="calendar-day-header">Mié</div>
<div class="calendar-day-header">Jue</div>
<div class="calendar-day-header">Vie</div>
<div class="calendar-day-header">Sáb</div>
<?php for ($i = 0; $i < $primer_dia_semana; $i++): ?>
<div class="calendar-day empty"></div>
<?php endfor; ?>
<?php for ($dia = 1; $dia <= $dias_en_mes; $dia++):
$fecha_completa = sprintf('%04d-%02d-%02d', $anio_actual, $mes_actual, $dia);
$es_hoy = ($fecha_completa === date('Y-m-d'));
$tiene_eventos = isset($eventos_por_dia[$dia]);
$cantidad_eventos = $tiene_eventos ? count($eventos_por_dia[$dia]) : 0;
?>
<div class="calendar-day <?php echo $es_hoy ? 'today' : ''; ?> <?php echo $tiene_eventos ? 'has-events' : ''; ?>"
onclick="verEventosDia('<?php echo $fecha_completa; ?>')">
<div class="fw-bold <?php echo $es_hoy ? 'text-primary' : ''; ?>"><?php echo $dia; ?></div>
<?php if ($cantidad_eventos > 0): ?>
<div class="event-count"><?php echo $cantidad_eventos; ?></div>
<?php foreach (array_slice($eventos_por_dia[$dia], 0, 3) as $evento): ?>
<a href="<?php echo $evento['enlace']; ?>" class="event-badge"
style="background: <?php echo $evento['color']; ?>;"
title="<?php echo htmlspecialchars($evento['titulo']); ?>">
<i class="fas <?php echo $evento['icono']; ?>"></i>
<?php echo substr($evento['titulo'], 0, 15); ?>...
</a>
<?php endforeach; ?>
<?php if ($cantidad_eventos > 3): ?>
<div class="event-badge bg-secondary">+<?php echo $cantidad_eventos - 3; ?> más</div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endfor; ?>
</div>
</div>
<!-- TIMELINE DE VENCIMIENTOS -->
<div class="section-box">
<h5 class="mb-3"><i class="fas fa-history"></i> Próximos Vencimientos</h5>
<?php if (empty($eventos)): ?>
<div class="text-center py-5">
<i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
<p class="text-muted">No hay vencimientos próximos en el período seleccionado</p>
</div>
<?php else: ?>
<div class="timeline-container">
<?php foreach (array_slice($eventos, 0, 20) as $evento):
$fecha_evento = new DateTime($evento['fecha']);
$hoy = new DateTime();
$diferencia = $hoy->diff($fecha_evento);
$dias_restantes = $diferencia->days;
$vencido = $fecha_evento < $hoy;
$proximo = !$vencido && $dias_restantes <= 7;
?>
<div class="timeline-item <?php echo $vencido ? 'vencido' : ($proximo ? 'proximo' : 'vigente'); ?>">
<div class="timeline-date">
<i class="fas fa-calendar-alt"></i>
<?php echo date('d/m/Y', strtotime($evento['fecha'])); ?>
<?php if (!$vencido): ?>
<br><small class="text-muted"><?php echo $dias_restantes; ?> días</small>
<?php else: ?>
<br><small class="text-danger">Vencido</small>
<?php endif; ?>
</div>
<div class="timeline-content">
<div class="timeline-title">
<i class="fas <?php echo $evento['icono']; ?>" style="color: <?php echo $evento['color']; ?>;"></i>
<?php echo htmlspecialchars($evento['titulo']); ?>
</div>
<div class="timeline-meta">
<span class="badge" style="background: <?php echo $evento['color']; ?>;">
<?php echo ucfirst(str_replace('_', ' ', $evento['tipo'])); ?>
</span>
<span class="ms-2">
<i class="fas fa-info-circle"></i>
<?php 
// Mostrar estado específico según tipo
if ($evento['tipo'] === 'personal_credencial' || $evento['tipo'] === 'personal_revalidacion') {
    if (!empty($evento['estado_doc'])) {
        echo 'Estado Doc: ' . ucfirst($evento['estado_doc']);
    } else {
        echo 'Estado: ' . ucfirst($evento['estado'] ?? 'N/A');
    }
} elseif ($evento['tipo'] === 'sucursal_habilitacion' || $evento['tipo'] === 'sucursal_arancel' || $evento['tipo'] === 'sucursal_resolucion') {
    echo 'Estado Aprobación: ' . ucfirst($evento['estado'] ?? 'N/A');
} else {
    echo 'Estado: ' . ucfirst($evento['estado'] ?? 'N/A');
}
?>
</span>
<?php if (!empty($evento['fecha_envio'])): ?>
<span class="ms-2">
<i class="fas fa-paper-plane"></i>
Fecha Envío: <?php echo date('d/m/Y', strtotime($evento['fecha_envio'])); ?>
</span>
<?php endif; ?>
<?php if ($evento['tipo'] === 'sucursal_arancel' && isset($evento['meta'])): ?>
<?php if ($evento['meta']['es_anual']): ?>
<span class="ms-2 text-muted small"><i class="fas fa-repeat"></i> Anual (02/01)</span>
<?php else: ?>
<span class="ms-2 text-muted small"><i class="fas fa-history"></i> 370 días desde pago</span>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
<div>
<a href="<?php echo $evento['enlace']; ?>" class="btn btn-sm btn-outline-primary">
<i class="fas fa-edit"></i> Ver
</a>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<!-- MODAL DETALLES DEL DÍA -->
<div class="modal fade" id="modalEventosDia" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-calendar-day"></i> Eventos del Día</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="modalEventosContent">
<!-- Contenido cargado dinámicamente -->
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const eventosPorDia = <?php echo json_encode($eventos_por_dia, JSON_UNESCAPED_UNICODE); ?>;
function verEventosDia(fecha) {
const dia = parseInt(fecha.split('-')[2]);
const eventos = eventosPorDia[dia] || [];
if (eventos.length === 0) {
Swal.fire({
icon: 'info',
title: 'Sin Eventos',
text: 'No hay eventos registrados para este día'
});
return;
}
let html = '<div class="list-group">';
eventos.forEach(evento => {
const fechaEvento = new Date(evento.fecha);
const hoy = new Date();
const vencido = fechaEvento < hoy;
// Determinar texto de estado según tipo
let estadoTexto = '';
if (evento.tipo === 'personal_credencial' || evento.tipo === 'personal_revalidacion') {
    estadoTexto = evento.estado_doc ? 'Estado Doc: ' + evento.estado_doc : 'Estado: ' + evento.estado;
} else if (evento.tipo === 'sucursal_habilitacion' || evento.tipo === 'sucursal_arancel' || evento.tipo === 'sucursal_resolucion') {
    estadoTexto = 'Estado Aprobación: ' + evento.estado;
} else {
    estadoTexto = 'Estado: ' + evento.estado;
}
html += `
<a href="${evento.enlace}" class="list-group-item list-group-item-action">
<div class="d-flex w-100 justify-content-between">
<h6 class="mb-1">
<i class="fas ${evento.icono}" style="color: ${evento.color};"></i>
${evento.titulo}
</h6>
<small class="${vencido ? 'text-danger' : 'text-success'}">
${vencido ? 'Vencido' : 'Vigente'}
</small>
</div>
<p class="mb-1 small">
<i class="fas fa-calendar"></i> ${evento.fecha}
</p>
<p class="mb-1 small text-muted">
<i class="fas fa-paper-plane"></i> Fecha Envío: ${evento.fecha_envio ? new Date(evento.fecha_envio).toLocaleDateString('es-AR') : 'N/A'}
</p>
<span class="badge" style="background: ${evento.color};">
${estadoTexto}
</span>
${evento.tipo === 'sucursal_arancel' && evento.meta ?
`<small class="text-muted d-block mt-1"><i class="fas ${evento.meta.es_anual ? 'fa-repeat' : 'fa-history'}"></i> ${evento.meta.es_anual ? 'Vencimiento anual fijo (02/01)' : '370 días desde último pago'}</small>` : ''}
</a>
`;
});
html += '</div>';
document.getElementById('modalEventosContent').innerHTML = html;
new bootstrap.Modal(document.getElementById('modalEventosDia')).show();
}
function exportarCalendario() {
Swal.fire({
title: 'Exportar Calendario',
text: '¿En qué formato desea exportar?',
icon: 'question',
showCancelButton: true,
confirmButtonText: 'PDF',
cancelButtonText: 'Excel',
confirmButtonColor: '#0d6efd',
cancelButtonColor: '#28a745'
}).then((result) => {
if (result.isConfirmed) {
window.open('calendario_exportar.php?formato=pdf', '_blank');
} else if (result.dismiss === Swal.DismissReason.cancel) {
window.open('calendario_exportar.php?formato=excel', '_blank');
}
});
}
// Notificaciones de vencimientos próximos
document.addEventListener('DOMContentLoaded', function() {
<?php if ($vencimientos_7_dias > 0): ?>
Swal.fire({
icon: 'warning',
title: '⚠️ Vencimientos Próximos',
html: `Tienes <strong><?php echo $vencimientos_7_dias; ?></strong> vencimientos en los próximos 7 días`,
timer: 5000,
showConfirmButton: true,
confirmButtonText: 'Ver Calendario'
});
<?php endif; ?>
<?php if ($vencimientos_vencidos > 0): ?>
Swal.fire({
icon: 'error',
title: '❌ Vencimientos Atrasados',
html: `Hay <strong><?php echo $vencimientos_vencidos; ?></strong> items vencidos que requieren atención`,
timer: 8000,
showConfirmButton: true,
confirmButtonText: 'Revisar Ahora'
});
<?php endif; ?>
});
</script>
</body>
</html>