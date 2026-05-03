<?php
// Verificar que $user esté definido
if (!isset($user)) {
    $user = $auth->getCurrentUser() ?? ['username' => 'Usuario', 'nombre_completo' => 'Usuario Anónimo', 'rol' => 'invitado'];
}
?>
<div class="header-moderno fixed-top">
    <div class="container-fluid px-0">
        <div class="header-container">
            <div class="title-container">
                <!-- BOTÓN TOGGLE SIDEBAR -->
                <button class="toggle-sidebar-btn" id="toggleSidebarBtn" type="button" title="Ocultar/Mostrar menú">
                    <i class="fas fa-bars" id="toggleIcon"></i>
                </button>
                <div class="icon-wrapper">
                    <i class="<?php echo $page_icon ?? 'fas fa-tachometer-alt'; ?>"></i>
                </div>
                <h1><?php echo $page_title ?? 'Dashboard'; ?></h1>
            </div>
            
            <!-- USER INFO -->
            <div class="user-info-container">
                <!-- AVATAR COMO BOTÓN A PANEL USUARIO -->
                <a href="panel_usuario.php" class="user-avatar-btn" title="Ir a Mi Perfil">
                    <div class="user-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <span class="avatar-tooltip">Mi Perfil</span>
                </a>
                
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($user['nombre_completo'] ?? $user['username']); ?></span>
                </div>
                <a href="../config/logout.php" class="logout-btn" title="Cerrar Sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- OVERLAY PARA MOBILE -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
/* ===== HEADER MODERNO ===== */
.header-moderno {
    background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
    box-shadow: 0 4px 25px rgba(0, 0, 0, 0.35);
    padding: 15px 0;
    z-index: 1100;
    transition: all 0.3s ease;
}

.header-moderno.scrolled {
    box-shadow: 0 6px 30px rgba(0, 0, 0, 0.45);
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

/* ===== BOTÓN TOGGLE SIDEBAR ===== */
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
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
}

.toggle-sidebar-btn:active {
    transform: scale(0.95);
}

.toggle-sidebar-btn.rotated i {
    transform: rotate(90deg);
    transition: transform 0.3s ease;
}

/* ===== ICON WRAPPER ===== */
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
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

.title-container h1 {
    color: white;
    font-weight: 600;
    font-size: 1.5rem;
    margin: 0;
    font-family: 'Poppins', sans-serif;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

/* ===== USER INFO CONTAINER ===== */
.user-info-container {
    display: flex;
    align-items: center;
    gap: 15px;
    background: rgba(255, 255, 255, 0.1);
    padding: 10px 20px;
    border-radius: 16px;
    backdrop-filter: blur(10px);
}

/* ===== USER AVATAR BUTTON ===== */
.user-avatar-btn {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.3s ease;
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
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.user-avatar-btn:hover .user-avatar {
    transform: scale(1.15);
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    box-shadow: 0 6px 20px rgba(67, 97, 238, 0.5);
    border-color: rgba(255, 255, 255, 0.4);
}

.user-avatar-btn:active .user-avatar {
    transform: scale(0.95);
}

/* Tooltip del Avatar */
.avatar-tooltip {
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(5px);
    background: linear-gradient(135deg, #2c3e50, #1a252f);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    font-family: 'Poppins', sans-serif;
    z-index: 100;
}

.avatar-tooltip::before {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-bottom-color: #2c3e50;
}

.user-avatar-btn:hover .avatar-tooltip {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(10px);
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
    font-family: 'Poppins', sans-serif;
}

.user-role {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.8rem;
    font-family: 'Poppins', sans-serif;
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
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
}

/* ===== SIDEBAR OVERLAY ===== */
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
    backdrop-filter: blur(3px);
}

body.sidebar-mobile-open .sidebar-overlay {
    opacity: 1;
    visibility: visible;
}

/* ===== BODY ADJUSTMENTS ===== */
body {
    font-family: 'Poppins', sans-serif;
    transition: padding-left 0.35s ease;
    padding-top: 100px !important;
    background: #f8f9fa;
}

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
    transition: transform 0.35s ease, width 0.35s ease;
}

body.sidebar-collapsed .sidebar-moderno {
    transform: translateX(-280px);
}

.main-content {
    transition: margin-left 0.35s ease, width 0.35s ease;
    padding-left: 320px !important;
    width: calc(100% - 280px) !important;
}

body.sidebar-collapsed .main-content {
    margin-left: 0 !important;
    width: 100% !important;
    padding-left: 40px !important;
    padding-right: 40px !important;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 991px) {
    .header-container {
        padding: 0 20px;
    }
    
    .sidebar-moderno {
        transform: translateX(-100%);
    }
    
    body.sidebar-mobile-open .sidebar-moderno {
        transform: translateX(0);
    }
    
    .main-content {
        padding-left: 20px !important;
        padding-right: 20px !important;
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .toggle-sidebar-btn {
        margin-right: 10px;
        width: 45px;
        height: 45px;
        font-size: 1.2rem;
    }
    
    .title-container h1 {
        font-size: 1.2rem;
    }
    
    .user-details {
        display: none;
    }
    
    .icon-wrapper {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }
}

@media (max-width: 576px) {
    .user-info-container {
        padding: 8px 12px;
    }
    
    .user-avatar {
        width: 35px;
        height: 35px;
        font-size: 1rem;
    }
    
    .logout-btn {
        width: 35px;
        height: 35px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleSidebarBtn');
    const toggleIcon = document.getElementById('toggleIcon');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;
    const sidebar = document.querySelector('.sidebar-moderno');
    const header = document.querySelector('.header-moderno');
    
    // Función para alternar el sidebar
    function toggleSidebar() {
        if (window.innerWidth <= 991) {
            // Mobile/Tablet: usar overlay y sidebar-mobile-open
            body.classList.toggle('sidebar-mobile-open');
            body.style.overflow = body.classList.contains('sidebar-mobile-open') ? 'hidden' : '';
            
            if (toggleIcon) {
                toggleIcon.className = body.classList.contains('sidebar-mobile-open')
                    ? 'fas fa-times'
                    : 'fas fa-bars';
            }
        } else {
            // Desktop: usar sidebar-collapsed
            body.classList.toggle('sidebar-collapsed');
            toggleBtn.classList.toggle('rotated');
            
            if (toggleIcon) {
                toggleIcon.className = body.classList.contains('sidebar-collapsed')
                    ? 'fas fa-indent'
                    : 'fas fa-bars';
            }
            
            localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed'));
        }
    }
    
    // Event listener para el botón
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    // Cerrar sidebar mobile al hacer clic en el overlay
    if (overlay) {
        overlay.addEventListener('click', function() {
            body.classList.remove('sidebar-mobile-open');
            body.style.overflow = '';
            if (toggleIcon) {
                toggleIcon.className = 'fas fa-bars';
            }
        });
    }
    
    // Cerrar sidebar mobile al hacer resize a desktop
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 991) {
                body.classList.remove('sidebar-mobile-open');
                body.style.overflow = '';
                
                if (localStorage.getItem('sidebarCollapsed') === 'true') {
                    body.classList.add('sidebar-collapsed');
                } else {
                    body.classList.remove('sidebar-collapsed');
                }
            } else {
                body.classList.remove('sidebar-mobile-open');
                body.classList.remove('sidebar-collapsed');
                body.style.overflow = '';
            }
        }, 250);
    });
    
    // Cerrar sidebar mobile al hacer clic en enlaces del sidebar
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 991 && body.classList.contains('sidebar-mobile-open')) {
                    body.classList.remove('sidebar-mobile-open');
                    body.style.overflow = '';
                    if (toggleIcon) {
                        toggleIcon.className = 'fas fa-bars';
                    }
                }
            });
        });
    }
    
    // Inicializar estado del sidebar en desktop
    if (window.innerWidth > 991 && localStorage.getItem('sidebarCollapsed') === 'true') {
        body.classList.add('sidebar-collapsed');
    }
    
    // Efecto de sombra al hacer scroll
    window.addEventListener('scroll', function() {
        if (header && window.scrollY > 20) {
            header.classList.add('scrolled');
        } else if (header) {
            header.classList.remove('scrolled');
        }
    });
});
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