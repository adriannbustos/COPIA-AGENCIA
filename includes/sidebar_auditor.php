<?php
// Verificar que $current_page esté definido
if (!isset($current_page)) {
$current_page = '';
}
// Verificar que $user esté definido para obtener el rol
if (!isset($user)) {
$user = $auth->getCurrentUser() ?? ['rol' => 'invitado'];
}
// Obtener rol del usuario
$user_role = $user['rol'] ?? 'invitado';
// Verificar alertas críticas (si existe la variable)
$alertas_criticas = $alertas_criticas ?? [];
// Definir si es administrador (puede ver todo)
$is_admin = ($user_role === 'administrador');
?>
<div class="sidebar-moderno">
<div class="sidebar-menu-container" id="sidebarMenuContainer">
<!-- Dashboard - Siempre visible para todos los roles -->
<a href="dashboard.php" class="sidebar-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>" data-tooltip="Dashboard">
<div class="sidebar-icon">
<i class="fas fa-tachometer-alt"></i>
</div>
<span class="sidebar-text">Panel Control</span>
</a>
<div class="sidebar-divider"></div>
<!-- ========================================== -->
<!-- SECCIÓN GESTIÓN (VISIBLE: ADMIN, CARGA y OPERADOR) -->
<!-- ========================================== -->
<?php if ($is_admin || $user_role === 'carga' || $user_role === 'operador'): ?>
<div class="sidebar-section">
<button class="sidebar-section-header <?php echo in_array($current_page, ['empresas', 'sucursales', 'personal', 'recursos', 'servicios']) ? '' : 'collapsed'; ?>"
type="button"
data-bs-toggle="collapse"
data-bs-target="#gestionSection"
aria-expanded="<?php echo in_array($current_page, ['empresas', 'sucursales', 'personal', 'recursos', 'servicios']) ? 'true' : 'false'; ?>"
aria-controls="gestionSection">
<div class="sidebar-icon-section">
<i class="fas fa-cubes"></i>
</div>
<span class="sidebar-text">Gestión</span>
<i class="fas fa-chevron-down sidebar-arrow"></i>
</button>
<div class="collapse <?php echo in_array($current_page, ['empresas', 'sucursales', 'personal', 'recursos', 'servicios']) ? 'show' : ''; ?>" id="gestionSection">
<a href="empresas.php" class="sidebar-subitem <?php echo $current_page == 'empresas' ? 'active' : ''; ?>">
<i class="fas fa-building"></i>
<span>Empresas</span>
</a>
<a href="sucursales.php" class="sidebar-subitem <?php echo $current_page == 'sucursales' ? 'active' : ''; ?>">
<i class="fas fa-store"></i>
<span>Sucursales</span>
</a>
<a href="personal.php" class="sidebar-subitem <?php echo $current_page == 'personal' ? 'active' : ''; ?>">
<i class="fas fa-users"></i>
<span>Personal</span>
</a>
<a href="recursos.php" class="sidebar-subitem <?php echo $current_page == 'recursos' ? 'active' : ''; ?>">
<i class="fas fa-toolbox"></i>
<span>Recursos</span>
</a>
<a href="servicios.php" class="sidebar-subitem <?php echo $current_page == 'servicios' ? 'active' : ''; ?>">
<i class="fas fa-concierge-bell"></i>
<span>Servicios</span>
</a>
</div>
</div>
<?php endif; ?>
<!-- ========================================== -->
<!-- SECCIÓN OPERACIONES (VISIBLE: ADMIN, CARGA y OPERADOR) -->
<!-- ========================================== -->
<?php if ($is_admin || $user_role === 'carga' || $user_role === 'operador'): ?>
<div class="sidebar-section">
<button class="sidebar-section-header <?php echo in_array($current_page, ['gestion_movimientos', 'inspecciones', 'documentos_empresas', 'sumarios']) ? '' : 'collapsed'; ?>"
type="button"
data-bs-toggle="collapse"
data-bs-target="#operacionesSection"
aria-expanded="<?php echo in_array($current_page, ['gestion_movimientos', 'inspecciones', 'documentos_empresas', 'sumarios']) ? 'true' : 'false'; ?>"
aria-controls="operacionesSection">
<div class="sidebar-icon-section">
<i class="fas fa-clipboard-list"></i>
</div>
<span class="sidebar-text">Operaciones</span>
<i class="fas fa-chevron-down sidebar-arrow"></i>
</button>
<div class="collapse <?php echo in_array($current_page, ['gestion_movimientos', 'inspecciones', 'documentos_empresas', 'sumarios']) ? 'show' : ''; ?>" id="operacionesSection">
<a href="gestion_movimientos.php" class="sidebar-subitem <?php echo $current_page == 'gestion_movimientos' ? 'active' : ''; ?>">
<i class="fas fa-dolly"></i>
<span>Movimientos</span>
</a>
<a href="inspecciones.php" class="sidebar-subitem <?php echo $current_page == 'inspecciones' ? 'active' : ''; ?>">
<i class="fas fa-clipboard-check"></i>
<span>Inspecciones</span>
</a>
<a href="documentos_empresas.php" class="sidebar-subitem <?php echo $current_page == 'documentos_empresas' ? 'active' : ''; ?>">
<i class="fas fa-file-contract"></i>
<span>Documentos</span>
</a>
<a href="sumarios.php" class="sidebar-subitem <?php echo $current_page == 'sumarios' ? 'active' : ''; ?>">
<i class="fas fa-book-open"></i>
<span>Sumarios</span>
</a>
</div>
</div>
<?php endif; ?>
<!-- ========================================== -->
<!-- SECCIÓN PLANIFICACIÓN (VISIBLE: ADMIN, CARGA y OPERADOR) -->
<!-- ========================================== -->
<?php if ($is_admin || $user_role === 'carga' || $user_role === 'operador'): ?>
<div class="sidebar-section">
<button class="sidebar-section-header <?php echo in_array($current_page, ['inspecciones_programadas', 'calendario_vencimientos']) ? '' : 'collapsed'; ?>"
type="button"
data-bs-toggle="collapse"
data-bs-target="#planificacionSection"
aria-expanded="<?php echo in_array($current_page, ['inspecciones_programadas', 'calendario_vencimientos']) ? 'true' : 'false'; ?>"
aria-controls="planificacionSection">
<div class="sidebar-icon-section">
<i class="fas fa-calendar-check"></i>
</div>
<span class="sidebar-text">Planificación</span>
<i class="fas fa-chevron-down sidebar-arrow"></i>
</button>
<div class="collapse <?php echo in_array($current_page, ['inspecciones_programadas', 'calendario_vencimientos']) ? 'show' : ''; ?>" id="planificacionSection">
<a href="inspecciones_programadas.php" class="sidebar-subitem <?php echo $current_page == 'inspecciones_programadas' ? 'active' : ''; ?>">
<i class="fas fa-calendar-day"></i>
<span>Inspecciones Prog.</span>
</a>
<a href="calendario_vencimientos.php" class="sidebar-subitem <?php echo $current_page == 'calendario_vencimientos' ? 'active' : ''; ?>">
<i class="fas fa-hourglass-end"></i>
<span>Vencimientos</span>
</a>
</div>
</div>
<?php endif; ?>
<!-- ========================================== -->
<!-- SECCIÓN FINANZAS (VISIBLE: ADMIN y CARGA) -->
<!-- ========================================== -->
<?php if ($is_admin || $user_role === 'carga'): ?>
<div class="sidebar-section">
<button class="sidebar-section-header <?php echo in_array($current_page, ['pagos_servicios']) ? '' : 'collapsed'; ?>"
type="button"
data-bs-toggle="collapse"
data-bs-target="#finanzasSection"
aria-expanded="<?php echo in_array($current_page, ['pagos_servicios']) ? 'true' : 'false'; ?>"
aria-controls="finanzasSection">
<div class="sidebar-icon-section">
<i class="fas fa-file-invoice-dollar"></i>
</div>
<span class="sidebar-text">Finanzas</span>
<i class="fas fa-chevron-down sidebar-arrow"></i>
</button>
<div class="collapse <?php echo in_array($current_page, ['pagos_servicios']) ? 'show' : ''; ?>" id="finanzasSection">
<a href="pagos_servicios.php" class="sidebar-subitem <?php echo $current_page == 'pagos_servicios' ? 'active' : ''; ?>">
<i class="fas fa-credit-card"></i>
<span>Pagos Servicios</span>
</a>
</div>
</div>
<?php endif; ?>
<!-- ================================================== -->
<!-- SECCIÓN AUDITORÍA Y ALERTAS (SOLO ADMINISTRADOR) -->
<!-- ================================================== -->
<?php if ($is_admin || $user_role === 'auditor'): ?>
<div class="sidebar-section">
<button class="sidebar-section-header <?php echo in_array($current_page, ['alertas', 'auditoria', 'reportes']) ? '' : 'collapsed'; ?>"
type="button"
data-bs-toggle="collapse"
data-bs-target="#auditoriaAlertasSection"
aria-expanded="<?php echo in_array($current_page, ['alertas', 'auditoria', 'reportes']) ? 'true' : 'false'; ?>"
aria-controls="auditoriaAlertasSection">
<div class="sidebar-icon-section">
<i class="fas fa-history"></i>
</div>
<span class="sidebar-text">Auditoría</span>
<i class="fas fa-chevron-down sidebar-arrow"></i>
</button>
<div class="collapse <?php echo in_array($current_page, ['alertas', 'auditoria', 'reportes']) ? 'show' : ''; ?>" id="auditoriaAlertasSection">
<a href="auditoria.php" class="sidebar-subitem <?php echo $current_page == 'auditoria' ? 'active' : ''; ?>">
<i class="fas fa-clipboard-list"></i>
<span>Auditoría</span>
</a>
</div>
</div>
<?php endif; ?>
<div class="sidebar-divider"></div>
<!-- ================================ -->
<!-- ADMINISTRACIÓN (SOLO ADMINISTRADOR) -->
<!-- ================================ -->
<?php if ($is_admin || $user_role === 'auditor'): ?>
<div class="sidebar-section">
<button class="sidebar-section-header <?php echo in_array($current_page, ['usuarios', 'subir_fondo_credencial']) ? '' : 'collapsed'; ?>"
type="button"
data-bs-toggle="collapse"
data-bs-target="#adminSection"
aria-expanded="<?php echo in_array($current_page, ['usuarios', 'subir_fondo_credencial']) ? 'true' : 'false'; ?>"
aria-controls="adminSection">
<div class="sidebar-icon-section">
<i class="fas fa-user-shield"></i>
</div>
<span class="sidebar-text">Administración</span>
<i class="fas fa-chevron-down sidebar-arrow"></i>
</button>
<div class="collapse <?php echo in_array($current_page, ['usuarios', 'subir_fondo_credencial']) ? 'show' : ''; ?>" id="adminSection">
<a href="usuarios.php" class="sidebar-subitem <?php echo $current_page == 'usuarios' ? 'active' : ''; ?>">
<i class="fas fa-users-cog"></i>
<span>Usuarios</span>
</a>
</div>
</div>
<?php endif; ?>
</div>
</div>
<style>
/* ===== SIDEBAR MODERNO ===== */
.sidebar-moderno {
position: fixed;
top: 80px;
left: 0;
height: calc(100vh - 80px);
width: 280px;
background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
color: white;
z-index: 1050;
box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2);
overflow-y: auto;
overflow-x: hidden;
transition: transform 0.35s ease, width 0.35s ease;
scrollbar-width: thin;
scrollbar-color: #4361ee #2c3e50;
font-family: 'Poppins', sans-serif;
}
.sidebar-moderno::-webkit-scrollbar {
width: 6px;
}
.sidebar-moderno::-webkit-scrollbar-track {
background: #2c3e50;
}
.sidebar-moderno::-webkit-scrollbar-thumb {
background: #4361ee;
border-radius: 3px;
}
.sidebar-moderno::-webkit-scrollbar-thumb:hover {
background: #3a0ca3;
}
/* ===== MENU CONTAINER ===== */
.sidebar-menu-container {
padding: 15px 10px;
}
/* ===== SIDEBAR ITEMS ===== */
.sidebar-item {
display: flex;
align-items: center;
gap: 15px;
padding: 14px 20px;
color: rgba(255, 255, 255, 0.85);
text-decoration: none;
border-radius: 12px;
transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
border-left: 4px solid transparent;
margin: 5px 10px;
position: relative;
overflow: hidden;
}
.sidebar-item::before {
content: '';
position: absolute;
top: 0;
left: 0;
width: 0;
height: 100%;
background: linear-gradient(90deg, rgba(67, 97, 238, 0.2), transparent);
transition: width 0.3s ease;
z-index: 0;
}
.sidebar-item:hover::before {
width: 100%;
}
.sidebar-item:hover,
.sidebar-item.active {
background: rgba(67, 97, 238, 0.15);
color: white;
border-left-color: #4361ee;
transform: translateX(5px);
}
.sidebar-item.active {
background: linear-gradient(90deg, rgba(67, 97, 238, 0.25), transparent);
box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
}
.sidebar-icon {
width: 45px;
height: 45px;
background: rgba(67, 97, 238, 0.15);
border-radius: 12px;
display: flex;
align-items: center;
justify-content: center;
font-size: 1.2rem;
color: #4361ee;
transition: all 0.3s ease;
position: relative;
z-index: 1;
flex-shrink: 0;
}
.sidebar-item:hover .sidebar-icon,
.sidebar-item.active .sidebar-icon {
background: linear-gradient(135deg, #4361ee, #3a0ca3);
color: white;
transform: scale(1.1);
box-shadow: 0 5px 15px rgba(67, 97, 238, 0.4);
}
.sidebar-text {
font-weight: 600;
font-size: 1rem;
position: relative;
z-index: 1;
white-space: nowrap;
}
/* ===== SECCIONES COLAPSABLES ===== */
.sidebar-section {
margin: 10px 0;
}
.sidebar-section-header {
display: flex;
align-items: center;
gap: 15px;
padding: 14px 20px;
color: rgba(255, 255, 255, 0.85);
text-decoration: none;
border-radius: 12px;
transition: all 0.3s ease;
border-left: 4px solid transparent;
margin: 5px 10px;
background: transparent;
border: none;
width: calc(100% - 20px);
text-align: left;
cursor: pointer;
font-size: 1rem;
font-weight: 600;
font-family: 'Poppins', sans-serif;
}
.sidebar-section-header:hover,
.sidebar-section-header:not(.collapsed) {
background: rgba(67, 97, 238, 0.15);
color: white;
border-left-color: #4361ee;
}
.sidebar-icon-section {
width: 45px;
height: 45px;
background: rgba(67, 97, 238, 0.15);
border-radius: 12px;
display: flex;
align-items: center;
justify-content: center;
font-size: 1.2rem;
color: #4361ee;
flex-shrink: 0;
}
.sidebar-section-header:hover .sidebar-icon-section,
.sidebar-section-header:not(.collapsed) .sidebar-icon-section {
background: linear-gradient(135deg, #4361ee, #3a0ca3);
color: white;
}
.sidebar-arrow {
margin-left: auto;
transition: transform 0.3s ease;
font-size: 0.9rem;
color: rgba(255, 255, 255, 0.6);
}
.sidebar-section-header:not(.collapsed) .sidebar-arrow {
transform: rotate(180deg);
}
/* ===== SUBITEMS ===== */
.sidebar-subitem {
display: flex;
align-items: center;
gap: 12px;
padding: 10px 20px 10px 55px;
color: rgba(255, 255, 255, 0.7);
text-decoration: none;
transition: all 0.3s ease;
border-radius: 8px;
margin: 3px 15px;
font-size: 0.95rem;
position: relative;
font-family: 'Poppins', sans-serif;
}
.sidebar-subitem::before {
content: '';
position: absolute;
left: 30px;
top: 50%;
transform: translateY(-50%);
width: 15px;
height: 2px;
background: rgba(67, 97, 238, 0.3);
transition: all 0.3s ease;
}
.sidebar-subitem:hover,
.sidebar-subitem.active {
color: white;
background: rgba(67, 97, 238, 0.1);
}
.sidebar-subitem.active {
color: #4cc9f0;
font-weight: 700;
}
.sidebar-subitem.active::before {
background: #4cc9f0;
width: 25px;
}
.sidebar-subitem i {
width: 20px;
text-align: center;
font-size: 1rem;
}
.sidebar-badge {
margin-left: auto;
padding: 2px 8px;
border-radius: 10px;
font-size: 0.75rem;
font-weight: 700;
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
box-shadow: 0 2px 8px rgba(231, 76, 60, 0.4);
}
/* ===== DIVIDER ===== */
.sidebar-divider {
height: 1px;
background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
margin: 20px 15px;
}
/* ===== LOGOUT ITEM ===== */
.logout-item {
color: rgba(231, 76, 60, 0.85);
}
.logout-item:hover {
background: rgba(231, 76, 60, 0.15);
border-left-color: #e74c3c;
color: #e74c3c;
}
.logout-item .sidebar-icon {
background: rgba(231, 76, 60, 0.15);
color: #e74c3c;
}
.logout-item:hover .sidebar-icon {
background: linear-gradient(135deg, #e74c3c, #c0392b);
color: white;
box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
}
/* ===== RESPONSIVE ===== */
@media (max-width: 991px) {
.sidebar-moderno {
transform: translateX(-100%);
}
body.sidebar-mobile-open .sidebar-moderno {
transform: translateX(0);
}
}
body.sidebar-collapsed .sidebar-moderno {
transform: translateX(-280px);
}
/* ===== TOOLTIPS ===== */
.sidebar-item[data-tooltip]:hover::after {
content: attr(data-tooltip);
position: absolute;
left: 100%;
top: 50%;
transform: translateY(-50%);
background: #2c3e50;
color: white;
padding: 8px 15px;
border-radius: 8px;
font-size: 0.9rem;
white-space: nowrap;
margin-left: 10px;
box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
z-index: 100;
opacity: 0;
animation: fadeIn 0.3s ease forwards;
font-family: 'Poppins', sans-serif;
}
@keyframes fadeIn {
to {
opacity: 1;
}
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
const sidebar = document.querySelector('.sidebar-moderno');
// Efecto hover en íconos
document.querySelectorAll('.sidebar-item, .sidebar-subitem').forEach(item => {
item.addEventListener('mouseenter', function() {
const icon = this.querySelector('.sidebar-icon, i');
if (icon) {
icon.style.transform = 'scale(1.15) rotate(5deg)';
}
});
item.addEventListener('mouseleave', function() {
const icon = this.querySelector('.sidebar-icon, i');
if (icon) {
icon.style.transform = 'scale(1) rotate(0)';
}
});
});
// Guardar estado de secciones colapsables
document.querySelectorAll('.sidebar-section-header').forEach(header => {
header.addEventListener('click', function() {
setTimeout(() => {
const target = this.getAttribute('data-bs-target');
const section = document.querySelector(target);
if (section) {
const isExpanded = section.classList.contains('show');
localStorage.setItem('section_' + target.replace('#', ''), isExpanded ? 'open' : 'closed');
}
}, 100);
});
});
// Cerrar sidebar mobile al hacer clic en enlaces
if (sidebar) {
sidebar.querySelectorAll('a, .sidebar-subitem').forEach(link => {
link.addEventListener('click', function() {
if (window.innerWidth <= 991) {
const body = document.body;
body.classList.remove('sidebar-mobile-open');
body.style.overflow = '';
const toggleIcon = document.getElementById('toggleIcon');
if (toggleIcon) {
toggleIcon.className = 'fas fa-bars';
}
}
});
});
}
});
</script>