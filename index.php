<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Investigaciones A-2 | Control y Fiscalización</title>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
            --light-bg: #f8f9fa;
            --text-color: #333;
            --border-color: #ddd;
            --warning-bg: #f8d7da;
            --warning-text: #721c24;
            --warning-border: #f5c6cb;
            --whatsapp-color: #25D366;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            color: var(--text-color);
            line-height: 1.6;
            background-color: #fff;
        }

        /* Header & Nav */
        header {
            background-color: var(--primary-color);
            color: white;
            padding: 1rem 0;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        .logo img {
            height: 60px;
            width: auto;
            border-radius: 50%;
        }

        .logo-text {
            font-size: 1.3rem;
            line-height: 1.2;
        }

        .logo-text small {
            display: block;
            font-size: 0.85rem;
            font-weight: 300;
            opacity: 0.9;
        }

        .nav-links {
            list-style: none;
            display: flex;
            gap: 20px;
            margin: 0;
            padding: 0;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-links a:hover {
            color: var(--accent-color);
        }

        .btn-login {
            background-color: var(--accent-color);
            padding: 8px 15px;
            border-radius: 4px;
            color: white !important;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(rgba(44, 62, 80, 0.8), rgba(44, 62, 80, 0.8)), url('hero-bg.jpg');
            background-size: cover;
            background-position: center;
            color: white;
            text-align: center;
            padding: 4rem 0;
        }

        .hero h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .btn-hero {
            display: inline-block;
            background-color: var(--accent-color);
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 20px;
        }

        .btn-hero:hover {
            background-color: #2980b9;
        }

        /* Services Grid */
        .services {
            padding: 3rem 0;
            background-color: var(--light-bg);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card h3 {
            color: var(--primary-color);
            margin-top: 0;
        }

        .card a {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: bold;
        }

        .card ul {
            padding-left: 20px;
            margin: 15px 0;
        }

        .card ul li {
            margin-bottom: 8px;
        }

        .pdf-links {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }

        .pdf-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #e74c3c;
            color: white !important;
            padding: 10px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
        }

        .pdf-link:hover {
            background-color: #c0392b;
        }

        .whatsapp-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: var(--whatsapp-color);
            color: white !important;
            padding: 10px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            margin-top: 10px;
        }

        .whatsapp-link:hover {
            background-color: #1ebc57;
        }

        /* Plazos Section Specifics */
        .plazos-section {
            background-color: white;
            padding: 3rem 0;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.95rem;
        }

        th, td {
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            text-align: left;
        }

        th {
            background-color: var(--secondary-color);
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        /* Aviso de Sanciones */
        .sanction-notice {
            background-color: var(--warning-bg);
            border: 1px solid var(--warning-border);
            color: var(--warning-text);
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            text-align: center;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Footer */
        footer {
            background-color: var(--primary-color);
            color: white;
            padding: 3rem 0;
            margin-top: auto;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer h4 {
            border-bottom: 2px solid var(--accent-color);
            padding-bottom: 10px;
            display: inline-block;
        }

        .footer ul {
            list-style: none;
            padding: 0;
        }

        .footer ul li {
            margin-bottom: 10px;
        }

        .footer a {
            color: #ddd;
            text-decoration: none;
        }

        .copyright {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 15px;
            }
            .nav-links {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            .sanction-notice {
                flex-direction: column;
                text-align: center;
            }
            .pdf-links {
                flex-direction: column;
            }
            .logo {
                flex-direction: column;
                text-align: center;
            }
            .logo img {
                height: 80px;
            }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header>
        <div class="container navbar">
            <a href="index.php" class="logo">
                <img src="img/logo.png" alt="Area Investigaciones A-2 Logo">
                <div class="logo-text">
                    Area Investigaciones A-2
                    <small>Control y Fiscalización Seguridad Privada</small>
                </div>
            </a>
            <ul class="nav-links">
                <li><a href="#inicio">Inicio</a></li>
                <li><a href="moodle/index.php">Aula Virtual</a></li>
                <li><a href="#normativa">Normativa</a></li>
                <li><a href="#plazos">Plazos</a></li>
                <li><a href="#contacto">Contacto</a></li>
                <li><a href="login.php" class="btn-login">Acceso Sistema</a></li>
            </ul>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="inicio">
        <div class="container">
            <h1>Control y Fiscalización de Empresas de Seguridad</h1>
            <p>Plataforma oficial para la gestión, auditoría y regulación de servicios de seguridad privada. Garantizando el cumplimiento normativo y la excelencia operativa.</p>
            <!-- BOTÓN MODIFICADO: Ahora enlaza a la sección de Plazos -->
            <a href="#plazos" class="btn-hero">Ver Plazos de Presentación de Documentación</a>
        </div>
    </section>

    <!-- Main Services -->
    <section class="services">
        <div class="container">
            <div class="grid">
                <!-- Aula Virtual -->
                <div class="card">
                    <h3>Aula Virtual Vigiladores</h3>
                    <p>Plataforma de capacitación y cursos obligatorios para la formación y certificación de personal de seguridad.</p>
                    <a href="/cursos/index.php">Ingresar a Cursos →</a>
                </div>

                <!-- Habilitaciones de Empresas, Personal u Otros -->
                <div class="card">
                    <h3>Habilitaciones de Empresas, Personal u Otros</h3>
                    <p>Gestión integral para la renovación y obtención de habilitaciones de funcionamiento para empresas, vigiladores y personal de seguridad.</p>
                    <div class="pdf-links">
                        <a href="documentos/requisitos-empresa.pdf" class="pdf-link" target="_blank">
                            📄 Requisitos Empresa
                        </a>
                        <a href="documentos/requisitos-personal.pdf" class="pdf-link" target="_blank">
                            📄 Requisitos Personal
                        </a>
                        <a href="documentos/requisitos-otros.pdf" class="pdf-link" target="_blank">
                            📄 Requisitos Otros
                        </a>
                    </div>
                </div>

                <!-- Denuncias y Fiscalización (CON WHATSAPP) -->
                <div class="card">
                    <h3>Denuncias y Fiscalización</h3>
                    <p>Canal oficial para reportar irregularidades en el servicio de seguridad privada o solicitar inspecciones.</p>
                    <a href="https://wa.me/5492804862992" class="whatsapp-link" target="_blank">
                        💬 Realizar denuncia por WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- SECCIÓN: PLAZOS DE PRESENTACIÓN -->
    <section class="plazos-section" id="plazos">
        <div class="container">
            <h2 style="color: var(--primary-color); text-align: center;">Plazos de Presentación de Documentación</h2>
            <p style="text-align: center; margin-bottom: 2rem;">Tabla oficial de plazos según normativa vigente para trámites y reportes obligatorios.</p>
            
            <div class="table-responsive">
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
            </div>

            <!-- AVISO DE SANCIONES -->
            <div class="sanction-notice">
                <span>⚠️</span>
                <div>
                    <strong>Importante:</strong> El incumplimiento de los plazos establecidos para la presentación de documentación podrá acarrear sanciones administrativas según lo dispuesto en la Ley de Seguridad Privada y su reglamentación.
                </div>
            </div>
        </div>
    </section>

    <!-- Normativa y Recursos -->
    <section class="services" id="normativa">
        <div class="container">
            <h2 style="color: var(--primary-color);">Marco Normativo y Recursos</h2>
            <p>Documentación oficial para el cumplimiento de la ley de seguridad privada.</p>
            
            <div class="grid">
                <div class="card">
                    <h3>Documentos Descargables</h3>
                    <ul style="padding-left: 20px;">
                        <li><a href="#">Reglamento de Ley de Seguridad Privada (PDF)</a></li>
                        <li><a href="#">Formulario de Inspección Técnica</a></li>
                        <li><a href="#">Código de Ética y Conducta</a></li>
                        <li><a href="#">Protocolos de Actuación</a></li>
                    </ul>
                </div>
                <div class="card">
                    <h3>Estadísticas y Reportes</h3>
                    <p>Acceda a los informes trimestrales sobre el estado de la seguridad privada en la región.</p>
                    <a href="#">Ver Reportes 2024 →</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contacto">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4>Area Investigaciones A-2</h4>
                    <p>Control y Fiscalización de Seguridad</p>
                    <p>Av. Principal 1234, Edificio Administrativo<br>Oficina 404, Ciudad Capital</p>
                </div>
                <div class="footer-col">
                    <h4>Enlaces Rápidos</h4>
                    <ul>
                        <li><a href="#">Soporte Técnico</a></li>
                        <li><a href="#">Preguntas Frecuentes</a></li>
                        <li><a href="#">Mesa de Ayuda</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h4>Contacto</h4>
                    <p>📞 (555) 123-4567</p>
                    <p>✉️ fiscalizacion@a2.gob</p>
                    <p>🕒 Lun - Vie: 8:00 - 16:00</p>
                </div>
            </div>
            <div class="copyright">
                &copy; 2024 Area Investigaciones A-2. Todos los derechos reservados.
            </div>
        </div>
    </footer>

</body>
</html>