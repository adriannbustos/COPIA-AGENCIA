<?php
if (!isset($user)) {
    $user = $auth->getCurrentUser() ?? ['username' => 'Usuario', 'nombre_completo' => 'Usuario Anónimo', 'rol' => 'invitado'];
}
?>
<div class="header-moderno fixed-top">
    <div class="container-fluid px-0">
        <div class="header-container">
            <div class="title-container">
                <button class="toggle-sidebar-btn" id="toggleSidebarBtn" type="button" title="Ocultar/Mostrar menú">
                    <i class="fas fa-bars" id="toggleIcon"></i>
                </button>
                <div class="icon-wrapper">
                    <i class="<?php echo $page_icon ?? 'fas fa-building'; ?>"></i>
                </div>
                <h1><?php echo $page_title ?? 'Panel de Empresas'; ?></h1>
            </div>
            <div class="user-info-container">
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($user['nombre_completo'] ?? $user['username']); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars(ucfirst($user['rol'] ?? 'Usuario')); ?></span>
                </div>
                <a href="../config/logout.php" class="logout-btn" title="Cerrar Sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
.header-moderno {
    background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
    box-shadow: 0 4px 25px rgba(0, 0, 0, 0.35);
    padding: 15px 0;
    z-index: 1100;
    transition: all 0.3s ease;
}
.header-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 30px;
    max-width: 1920px;
    margin: 0 auto;
}
.title-container {
    display: flex;
    align-items: center;
    gap: 15px;
}
.toggle-sidebar-btn {
    background: rgba(255, 255, 255, 0.15);
    color: white;
    border: none;
    width: 50px;
    height: 50px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    flex-shrink: 0;
}
.toggle-sidebar-btn:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: scale(1.1) rotate(5deg);
}
.icon-wrapper {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: white;
}
.title-container h1 {
    color: white;
    font-weight: 600;
    font-size: 1.5rem;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}
.user-info-container {
    display: flex;
    align-items: center;
    gap: 15px;
    background: rgba(255, 255, 255, 0.1);
    padding: 10px 20px;
    border-radius: 16px;
}
.user-avatar-btn {
    position: relative;
    display: flex;
    text-decoration: none;
}
.user-avatar {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.2);
}
.avatar-tooltip {
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(5px);
    background: #2c3e50;
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    z-index: 100;
}
.user-avatar-btn:hover .avatar-tooltip {
    opacity: 1;
    visibility: visible;
}
.user-details {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.user-name {
    color: white;
    font-weight: 600;
    font-size: 0.95rem;
}
.user-role {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.8rem;
    text-transform: capitalize;
}
.logout-btn {
    width: 40px;
    height: 40px;
    background: rgba(231, 76, 60, 0.2);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #e74c3c;
    text-decoration: none;
    transition: all 0.3s ease;
    margin-left: 10px;
}
.logout-btn:hover {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 1040;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.35s ease;
}
body.sidebar-mobile-open .sidebar-overlay {
    opacity: 1;
    visibility: visible;
}
body {
    font-family: 'Poppins', sans-serif;
    padding-top: 100px !important;
    background: #f8f9fa;
}
@media (max-width: 991px) {
    .header-container { padding: 0 20px; }
    .user-details { display: none; }
    .title-container h1 { font-size: 1.2rem; }
}
</style>
<script>
// Agregar favicon dinámicamente
function addFavicon() {
    let link = document.querySelector("link[rel~='icon']");
    if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.getElementsByTagName('head')[0].appendChild(link);
    }
    link.href = '../img/favicon.ico'; // Ajusta la ruta según donde guardes el logo
    link.type = 'image/x-icon';
}

// Ejecutar al cargar el documento
addFavicon();
</script>
