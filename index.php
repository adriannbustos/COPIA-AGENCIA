<?php
/**
 * Area Investigaciones A-2 - Control y Fiscalización
 * Plataforma de Gestión de Seguridad Privada
 * @version 1.0
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Investigaciones A-2 | Control y Fiscalización</title>
    <meta name="description" content="Plataforma oficial de Control y Fiscalización de Seguridad Privada">
    <style>
        :root {
            --primary: #1a3a5c;
            --secondary: #2c5282;
            --accent: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray: #64748b;
            --border: #e2e8f0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background: var(--light);
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo h1 { font-size: 1.5rem; font-weight: 600; }
        .logo p { font-size: 0.9rem; opacity: 0.9; }
        nav ul {
            display: flex;
            list-style: none;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 0;
            border-bottom: 2px solid transparent;
            transition: border-color 0.3s;
        }
        nav a:hover { border-bottom-color: var(--accent); }
        .btn {
            display: inline-block;
            background: var(--accent);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #2563eb; transform: translateY(-2px); }
        .btn-outline {
            background: transparent;
            border: 2px solid var(--accent);
            color: var(--accent);
        }
        .btn-outline:hover { background: var(--accent); color: white; }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #dc2626; }
        main { padding: 2rem 0; }
        .hero {
            background: white;
            border-radius: 16px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border-left: 4px solid var(--accent);
        }
        .hero h2 { font-size: 1.8rem; margin-bottom: 1rem; color: var(--primary); }
        .hero p { font-size: 1.1rem; color: var(--gray); margin-bottom: 1.5rem; }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .card h3 {
            color: var(--secondary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card p { color: var(--gray); margin-bottom: 1rem; }
        .card-links {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .card-links a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 6px;
            transition: background 0.3s;
        }
        .card-links a:hover { background: #eff6ff; }
        .card-links a.pdf { color: var(--danger); }
        .card-links a.whatsapp { color: #25D366; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin: 1rem 0;
        }
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }
        tr:hover { background: #f8fafc; }
        .alert {
            background: #fff7ed;
            border-left: 4px solid var(--warning);
            padding: 1rem 1.5rem;
            border-radius: 0 8px 8px 0;
            margin: 1.5rem 0;
            color: #92400e;
        }
        .alert strong { color: #b45309; }
        .section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .section h2 {
            color: var(--primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border);
        }
        .docs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        .doc-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: var(--light);
            border-radius: 8px;
            transition: background 0.3s;
        }
        .doc-item:hover { background: #e2e8f0; }
        .doc-item a {
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            flex: 1;
        }
        footer {
            background: var(--dark);
            color: white;
            padding: 3rem 0 1.5rem;
            margin-top: 3rem;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .footer-section h4 {
            color: var(--accent);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        .footer-section a {
            color: #cbd5e1;
            text-decoration: none;
            display: block;
            margin-bottom: 0.5rem;
            transition: color 0.3s;
        }
        .footer-section a:hover { color: white; }
        .footer-bottom {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid #334155;
            color: #94a3b8;
            font-size: 0.9rem;
        }
        @media (max-width: 768px) {
            .header-content { flex-direction: column; text-align: center; }
            nav ul { justify-content: center; }
            .hero { padding: 1.5rem; }
            .hero h2 { font-size: 1.4rem; }
            table { font-size: 0.9rem; }
            th, td { padding: 0.75rem 0.5rem; }
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-content">
            <div class="logo">
                <h1>Area Investigaciones A-2</h1>
                <p>Control y Fiscalización Seguridad Privada</p>
            </div>
            <nav>
                <ul>
                    <li><a href="#inicio">Inicio</a></li>
                    <li><a href="#normativa">Normativa</a></li>
                    <li><a href="#plazos">Plazos</a></li>
                    <li><a href="#contacto">Contacto</a></li>
                    <li><a href="login.php" class="btn btn-outline">Acceso Sistema</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container" id="inicio">
        <section class="hero">
            <h2>Control y Fiscalización de Empresas de Seguridad</h2>
            <p>Plataforma oficial para la gestión, auditoría y regulación de servicios de seguridad privada. Garantizando el cumplimiento normativo y la excelencia operativa.</p>
            <a href="#plazos" class="btn">Ver Plazos de Presentación de Documentación</a>
        </section>

        <div class="cards">
            <div class="card">
                <h3>🎓 Curso de Vigilador Privado</h3>
                <p>Plataforma de capacitación y cursos obligatorios para la formación y certificación de personal de seguridad.</p>
                <div class="card-links">
                    <a href="documentos/ficha-de-inscripcion.pdf" class="pdf" target="_blank">
                        📄 Ficha de Inscripción
                    </a>
                    <a href="documentos/inscripciones.pdf" class="pdf" target="_blank">
                        📄 Inscripciones
                    </a>
                </div>
            </div>

            <div class="card">
                <h3>🏢 Habilitaciones de Empresas, Personal u Otros</h3>
                <p>Gestión integral para la renovación y obtención de habilitaciones de funcionamiento para empresas, vigiladores y personal de seguridad.</p>
                <div class="card-links">
                    <a href="documentos/requisitos-empresa.pdf" class="pdf" target="_blank">
                        📄 Requisitos Empresa
                    </a>
                    <a href="documentos/requisitos-personal.pdf" class="pdf" target="_blank">
                        📄 Requisitos Personal
                    </a>
                    <a href="documentos/requisitos-otros.pdf" class="pdf" target="_blank">
                        📄 Requisitos Otros
                    </a>
                </div>
            </div>

            <div class="card">
                <h3>⚠️ Denuncias y Fiscalización</h3>
                <p>Canal oficial para reportar irregularidades en el servicio de seguridad privada o solicitar inspecciones.</p>
                <div class="card-links">
                    <a href="https://wa.me/5492804862992" class="whatsapp" target="_blank">
                        💬 Realizar denuncia por WhatsApp
                    </a>
                </div>
            </div>
        </div>

        <section class="section" id="plazos">
            <h2>Plazos de Presentación de Documentación</h2>
            <p>Tabla oficial de plazos según normativa vigente para trámites y reportes obligatorios.</p>
            <table>
                <thead>
                    <tr>
                        <th>Situación</th>
                        <th>Plazo</th>
                        <th>Artículo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Solicitud de habilitación inicial</td>
                        <td>La Autoridad debe expedirse en 30 días hábiles desde la presentación</td>
                        <td>Art. 9, inc. ll)</td>
                    </tr>
                    <tr>
                        <td>Modificaciones de datos (domicilio, personal, sociedad, etc.)</td>
                        <td>7 días desde ocurrida la modificación</td>
                        <td>Art. 16</td>
                    </tr>
                    <tr>
                        <td>Modificación de socios / accionistas</td>
                        <td>30 días desde producida la modificación</td>
                        <td>Art. 9, inc. p)</td>
                    </tr>
                    <tr>
                        <td>Fallecimiento o incapacidad del Director Técnico</td>
                        <td>10 días desde el deceso o situación de incapacidad</td>
                        <td>Art. 11</td>
                    </tr>
                    <tr>
                        <td>Cese del Director Técnico (renuncia, retiro, licencia)</td>
                        <td>5 días hábiles antes (previsibles) o 24 horas (imprevistos)</td>
                        <td>Art. 12</td>
                    </tr>
                    <tr>
                        <td>Cese de personal</td>
                        <td>3 días desde ocurrido el cese</td>
                        <td>Art. 15</td>
                    </tr>
                    <tr>
                        <td>Informe de investigaciones o hechos de interés público</td>
                        <td>24 horas desde ocurridos</td>
                        <td>Art. 18</td>
                    </tr>
                    <tr>
                        <td>Parte mensual de servicios (usuario, lugar, horarios, personal)</td>
                        <td>Del 1 al 10 de cada mes</td>
                        <td>Art. 19, inc. f)</td>
                    </tr>
                    <tr>
                        <td>Informe de pagos de aportes y contribuciones</td>
                        <td>Del 15 al 30 de cada mes</td>
                        <td>Art. 19, inc. g)</td>
                    </tr>
                    <tr>
                        <td>Actualización de legajos de personal</td>
                        <td>Cada 2 años desde la habilitación</td>
                        <td>Circular</td>
                    </tr>
                </tbody>
            </table>
            <div class="alert">
                <strong>⚠️ Importante:</strong> El incumplimiento de los plazos establecidos para la presentación de documentación podrá acarrear sanciones administrativas según lo dispuesto en la Ley de Seguridad Privada y su reglamentación.
            </div>
        </section>

        <section class="section" id="normativa">
            <h2>Marco Normativo y Recursos</h2>
            <p>Documentación oficial para el cumplimiento de la ley de seguridad privada.</p>
            
            <h3 style="margin: 1.5rem 0 1rem; color: var(--secondary);">Documentos Descargables</h3>
            <div class="docs-grid">
                <div class="doc-item">
                    <span>📋</span>
                    <a href="#" target="_blank">Reglamento de Ley de Seguridad Privada (PDF)</a>
                </div>
                <div class="doc-item">
                    <span>📝</span>
                    <a href="#" target="_blank">Formulario de Inspección Técnica</a>
                </div>
                <div class="doc-item">
                    <span>⚖️</span>
                    <a href="#" target="_blank">Código de Ética y Conducta</a>
                </div>
                <div class="doc-item">
                    <span>🔧</span>
                    <a href="#" target="_blank">Protocolos de Actuación</a>
                </div>
            </div>

            <h3 style="margin: 1.5rem 0 1rem; color: var(--secondary);">Estadísticas y Reportes</h3>
            <p style="margin-bottom: 1rem;">Acceda a los informes trimestrales sobre el estado de la seguridad privada en la región.</p>
            <a href="#" class="btn btn-outline">Ver Reportes 2024 →</a>
        </section>
    </main>

    <footer id="contacto">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-section">
                    <h4>Area Investigaciones A-2</h4>
                    <p>Control y Fiscalización de Seguridad</p>
                    <p style="margin-top: 0.5rem; color: #94a3b8;">
                        Av. Principal 1234, Edificio Administrativo<br>
                        Oficina 404, Ciudad Capital
                    </p>
                </div>
                <div class="footer-section">
                    <h4>Enlaces Rápidos</h4>
                    <a href="#">Soporte Técnico</a>
                    <a href="#">Preguntas Frecuentes</a>
                    <a href="#">Mesa de Ayuda</a>
                </div>
                <div class="footer-section">
                    <h4>Contacto</h4>
                    <p>📞 (+549) 280 486-2992</p>
                    <p>✉️ formacion.seguridad.privada.ch@gmail.com</p>
                    <p>🕒 Lun - Vie: 8:00 - 13:30</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Area Investigaciones A-2. Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>

    <script>
        // Smooth scroll para enlaces internos
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Highlight de sección activa en navegación
        const sections = document.querySelectorAll('section[id]');
        const navLinks = document.querySelectorAll('nav a[href^="#"]');
        
        window.addEventListener('scroll', () => {
            let current = '';
            sections.forEach(section => {
                const sectionTop = section.offsetTop - 100;
                if (pageYOffset >= sectionTop) {
                    current = section.getAttribute('id');
                }
            });
            
            navLinks.forEach(link => {
                link.style.borderBottomColor = '';
                if (link.getAttribute('href') === `#${current}`) {
                    link.style.borderBottomColor = 'var(--accent)';
                }
            });
        });
    </script>
</body>
</html>
