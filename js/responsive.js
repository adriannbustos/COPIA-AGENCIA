document.addEventListener('DOMContentLoaded', function() {
    // Toggle Sidebar en Móvil - ¡FUNCIONA EN TODOS LOS NAVEGADORES!
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.querySelector('.sidebar');
    const body = document.body;
    const mainContent = document.querySelector('.main-content');
    
    if (toggleBtn && sidebar) {
        // Toggle al hacer clic en el botón
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('sidebar-mobile-show');
            body.classList.toggle('sidebar-open');
            
            // Cambiar ícono con animación
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('sidebar-mobile-show')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
                this.setAttribute('aria-expanded', 'true');
                // Bloquear scroll del body cuando sidebar está abierto
                body.style.overflow = 'hidden';
                // Oscurecer contenido principal
                if (mainContent) mainContent.style.opacity = '0.3';
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
                this.setAttribute('aria-expanded', 'false');
                // Restaurar scroll y opacidad
                body.style.overflow = '';
                if (mainContent) mainContent.style.opacity = '1';
            }
        });
        
        // Cerrar sidebar al hacer clic fuera (SOLO EN MÓVIL)
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 767 && 
                sidebar.classList.contains('sidebar-mobile-show') && 
                !sidebar.contains(e.target) && 
                !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('sidebar-mobile-show');
                body.classList.remove('sidebar-open');
                toggleBtn.querySelector('i').className = 'fas fa-bars';
                toggleBtn.setAttribute('aria-expanded', 'false');
                body.style.overflow = '';
                if (mainContent) mainContent.style.opacity = '1';
            }
        });
        
        // Cerrar sidebar al hacer clic en un enlace del menú (SOLO EN MÓVIL)
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 767 && sidebar.classList.contains('sidebar-mobile-show')) {
                    sidebar.classList.remove('sidebar-mobile-show');
                    body.classList.remove('sidebar-open');
                    toggleBtn.querySelector('i').className = 'fas fa-bars';
                    toggleBtn.setAttribute('aria-expanded', 'false');
                    body.style.overflow = '';
                    if (mainContent) mainContent.style.opacity = '1';
                }
            });
        });
    }
    
    // Ajustar sidebar en resize (cerrar en desktop)
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 767 && sidebar) {
                sidebar.classList.remove('sidebar-mobile-show');
                body.classList.remove('sidebar-open');
                if (toggleBtn) {
                    toggleBtn.querySelector('i').className = 'fas fa-bars';
                    toggleBtn.setAttribute('aria-expanded', 'false');
                    body.style.overflow = '';
                    if (mainContent) mainContent.style.opacity = '1';
                }
            } else if (window.innerWidth <= 767 && sidebar) {
                // En móvil, asegurar que el sidebar esté oculto al hacer resize
                sidebar.classList.remove('sidebar-mobile-show');
                body.classList.remove('sidebar-open');
                if (toggleBtn) {
                    toggleBtn.querySelector('i').className = 'fas fa-bars';
                    toggleBtn.setAttribute('aria-expanded', 'false');
                    body.style.overflow = '';
                    if (mainContent) mainContent.style.opacity = '1';
                }
            }
        }, 250);
    });
    
    // Mejorar inputs de fecha en móvil
    document.querySelectorAll('input[type="date"], input[type="time"]').forEach(input => {
        input.addEventListener('focus', function() {
            this.style.fontSize = '16px';
        });
        input.addEventListener('blur', function() {
            this.style.fontSize = '';
        });
    });
    
    // Prevenir zoom accidental en móviles
    document.querySelectorAll('input, select, textarea').forEach(element => {
        element.addEventListener('touchstart', function(e) {
            if (e.touches.length > 1) e.preventDefault();
        }, { passive: true });
    });
    
    // Inicializar estado del sidebar en desktop
    if (window.innerWidth > 767 && sidebar) {
        sidebar.style.display = 'block';
        sidebar.style.transform = 'translateX(0)';
    }
});