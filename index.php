<?php
/**
* Area Investigaciones A-2 - Control y Fiscalización
* Página Principal - index.php
* Versión Modernizada: Diseño visual mejorado, UX optimizado, accesibilidad y responsive
*/
// ==================== LÓGICA DE BÚSQUEDA POR CUIT ====================
$search_result = null;
$search_error = null;
$search_cuit = '';
if (isset($_GET['cuit_search']) && !empty($_GET['cuit_search'])) {
try {
// Ajustar la ruta según la estructura real de carpetas (asumiendo config/ en la raíz)
if (file_exists('config/database.php')) {
require_once 'config/database.php';
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$search_cuit = trim($_GET['cuit_search']);
// Consulta segura solo los campos solicitados
$stmt = $conn->prepare("SELECT cuit, nombre, activo FROM empresas WHERE cuit = :cuit LIMIT 1");
$stmt->execute(['cuit' => $search_cuit]);
$search_result = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
$search_error = "Configuración de base de datos no disponible.";
}
} catch (Exception $e) {
error_log("Error búsqueda CUIT: " . $e->getMessage());
$search_error = "Error al realizar la búsqueda. Intente nuevamente.";
}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Plataforma oficial de Control y Fiscalización de Seguridad Privada - Area Investigaciones A-2">
<title>Area Investigaciones A-2 | Control y Fiscalización</title>
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
--primary-900: #1a365d;
--primary-800: #2c5282;
--primary-700: #3a6ea5;
--primary-600: #4a85c4;
--accent-500: #f6ad55;
--accent-600: #ed8936;
--success-500: #38a169;
--warning-500: #d69e2e;
--danger-500: #e53e3e;
--gray-50: #f9fafb;
--gray-100: #f3f4f6;
--gray-200: #e5e7eb;
--gray-300: #d1d5db;
--gray-400: #9ca3af;
--gray-500: #6b7280;
--gray-600: #4b5563;
--gray-700: #374151;
--gray-800: #1f2937;
--gray-900: #111827;
--shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
--shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
--shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
--shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
--shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
--radius-sm: 0.375rem;
--radius: 0.5rem;
--radius-md: 0.75rem;
--radius-lg: 1rem;
--radius-xl: 1.5rem;
--transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
--transition-fast: all 0.15s ease-in-out;
}
*, *::before, *::after {
margin: 0;
padding: 0;
box-sizing: border-box;
}
html {
scroll-behavior: smooth;
}
body {
font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
line-height: 1.6;
color: var(--gray-700);
background: linear-gradient(135deg, var(--gray-50) 0%, #eef2f7 100%);
min-height: 100vh;
}
.container {
width: 100%;
max-width: 1280px;
margin: 0 auto;
padding: 0 1.5rem;
}
/* Header */
header {
position: sticky;
top: 0;
z-index: 100;
background: linear-gradient(135deg, var(--primary-900) 0%, var(--primary-800) 100%);
color: white;
padding: 0.75rem 0;
box-shadow: var(--shadow-lg);
backdrop-filter: blur(12px);
border-bottom: 1px solid rgba(255,255,255,0.1);
}
.header-content {
display: flex;
justify-content: space-between;
align-items: center;
gap: 1rem;
}
.logo {
display: flex;
align-items: center;
gap: 0.75rem;
}
.logo-icon {
width: 40px;
height: 40px;
background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
border-radius: var(--radius);
display: flex;
align-items: center;
justify-content: center;
font-weight: 700;
font-size: 1.1rem;
box-shadow: var(--shadow);
}
.logo h1 {
font-size: 1.25rem;
font-weight: 700;
letter-spacing: -0.025em;
line-height: 1.2;
}
.logo p {
font-size: 0.8rem;
opacity: 0.9;
font-weight: 400;
}
nav ul {
display: flex;
list-style: none;
gap: 0.25rem;
flex-wrap: wrap;
align-items: center;
}
nav a {
color: rgba(255,255,255,0.9);
text-decoration: none;
padding: 0.5rem 0.875rem;
border-radius: var(--radius-sm);
transition: var(--transition-fast);
font-size: 0.875rem;
font-weight: 500;
display: flex;
align-items: center;
gap: 0.375rem;
}
nav a:hover, nav a:focus {
background: rgba(255,255,255,0.15);
color: white;
outline: none;
}
nav a:active {
transform: scale(0.98);
}
.btn-acceso {
background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
color: var(--primary-900) !important;
font-weight: 600;
box-shadow: var(--shadow);
}
.btn-acceso:hover {
background: linear-gradient(135deg, #f7b76a, #f09a4a);
transform: translateY(-1px);
box-shadow: var(--shadow-md);
}
.mobile-toggle {
display: none;
background: none;
border: none;
color: white;
font-size: 1.25rem;
cursor: pointer;
padding: 0.5rem;
border-radius: var(--radius-sm);
transition: var(--transition-fast);
}
.mobile-toggle:hover {
background: rgba(255,255,255,0.15);
}
/* Hero Section with Carousel */
.hero {
position: relative;
padding: 0;
text-align: center;
overflow: hidden;
height: clamp(400px, 60vh, 600px);
background: var(--primary-900);
}
.hero-carousel {
position: relative;
width: 100%;
height: 100%;
}
.hero-slide {
position: absolute;
top: 0;
left: 0;
width: 100%;
height: 100%;
opacity: 0;
transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
.hero-slide.active {
opacity: 1;
}
.hero-slide::before {
content: '';
position: absolute;
top: 0;
left: 0;
width: 100%;
height: 100%;
background: linear-gradient(135deg, rgba(26,54,93,0.85) 0%, rgba(44,82,130,0.75) 50%, rgba(26,54,93,0.9) 100%);
z-index: 5;
}
.hero-slide img {
width: 100%;
height: 100%;
object-fit: cover;
object-position: center;
}
.hero-overlay {
position: absolute;
top: 0;
left: 0;
width: 100%;
height: 100%;
display: flex;
flex-direction: column;
justify-content: center;
align-items: center;
z-index: 10;
padding: 2rem;
}
.hero-content {
color: white;
z-index: 20;
max-width: 900px;
animation: fadeInUp 0.6s ease-out;
}
@keyframes fadeInUp {
from {
opacity: 0;
transform: translateY(24px);
}
to {
opacity: 1;
transform: translateY(0);
}
}
.hero-content h2 {
font-size: clamp(1.75rem, 4vw, 2.75rem);
font-weight: 800;
margin-bottom: 1.25rem;
text-shadow: 0 2px 8px rgba(0,0,0,0.3);
letter-spacing: -0.025em;
line-height: 1.1;
}
.hero-content p {
font-size: clamp(1rem, 2.5vw, 1.25rem);
max-width: 700px;
margin: 0 auto 2rem;
opacity: 0.95;
font-weight: 400;
line-height: 1.6;
}
.hero-actions {
display: flex;
gap: 1rem;
justify-content: center;
flex-wrap: wrap;
}
.btn {
display: inline-flex;
align-items: center;
gap: 0.5rem;
background: linear-gradient(135deg, var(--primary-700), var(--primary-600));
color: white;
padding: 0.875rem 1.75rem;
text-decoration: none;
border-radius: var(--radius-lg);
transition: var(--transition);
font-weight: 600;
font-size: 0.95rem;
border: none;
cursor: pointer;
box-shadow: var(--shadow);
}
.btn:hover, .btn:focus {
background: linear-gradient(135deg, var(--primary-600), var(--primary-700));
transform: translateY(-2px);
box-shadow: var(--shadow-lg);
outline: none;
}
.btn:active {
transform: translateY(0);
}
.btn-primary {
background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
color: var(--primary-900);
}
.btn-primary:hover {
background: linear-gradient(135deg, #f7b76a, #f09a4a);
}
.btn-outline {
background: transparent;
border: 2px solid rgba(255,255,255,0.6);
color: white;
}
.btn-outline:hover {
background: rgba(255,255,255,0.15);
border-color: white;
}
/* Carousel Navigation */
.carousel-controls {
position: absolute;
bottom: 2rem;
left: 50%;
transform: translateX(-50%);
display: flex;
align-items: center;
gap: 1rem;
z-index: 30;
}
.carousel-dots {
display: flex;
gap: 0.5rem;
}
.carousel-dot {
width: 10px;
height: 10px;
border-radius: 50%;
background: rgba(255,255,255,0.5);
cursor: pointer;
transition: var(--transition-fast);
border: 2px solid transparent;
}
.carousel-dot:hover {
background: rgba(255,255,255,0.8);
}
.carousel-dot.active {
background: var(--accent-500);
transform: scale(1.2);
border-color: white;
}
.carousel-btn {
background: rgba(255,255,255,0.15);
border: none;
color: white;
width: 40px;
height: 40px;
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
cursor: pointer;
transition: var(--transition-fast);
font-size: 1rem;
}
.carousel-btn:hover {
background: rgba(255,255,255,0.25);
transform: scale(1.05);
}
.carousel-btn:active {
transform: scale(0.95);
}
/* Cards Section */
.section {
padding: clamp(3rem, 8vw, 5rem) 0;
}
.section-header {
text-align: center;
max-width: 700px;
margin: 0 auto 3rem;
}
.section-header h2 {
font-size: clamp(1.5rem, 3vw, 2rem);
font-weight: 700;
color: var(--primary-900);
margin-bottom: 0.75rem;
letter-spacing: -0.025em;
}
.section-header p {
color: var(--gray-600);
font-size: 1.05rem;
}
.cards {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
gap: 1.5rem;
}
.card {
background: white;
padding: 1.75rem;
border-radius: var(--radius-lg);
box-shadow: var(--shadow-md);
transition: var(--transition);
display: flex;
flex-direction: column;
gap: 1rem;
border: 1px solid var(--gray-200);
position: relative;
overflow: hidden;
}
.card::before {
content: '';
position: absolute;
top: 0;
left: 0;
right: 0;
height: 4px;
background: linear-gradient(90deg, var(--primary-700), var(--accent-500));
opacity: 0;
transition: var(--transition-fast);
}
.card:hover {
transform: translateY(-4px);
box-shadow: var(--shadow-xl);
border-color: var(--primary-200);
}
.card:hover::before {
opacity: 1;
}
.card-icon {
width: 48px;
height: 48px;
background: linear-gradient(135deg, var(--primary-100), var(--primary-50));
border-radius: var(--radius-lg);
display: flex;
align-items: center;
justify-content: center;
font-size: 1.25rem;
color: var(--primary-700);
}
.card h3 {
color: var(--primary-900);
font-size: 1.15rem;
font-weight: 600;
margin: 0;
letter-spacing: -0.01em;
}
.card p {
color: var(--gray-600);
font-size: 0.95rem;
line-height: 1.6;
margin: 0;
}
.card-links {
display: flex;
flex-wrap: wrap;
gap: 0.5rem;
margin-top: 0.5rem;
}
.card-link {
background: var(--gray-100);
color: var(--primary-700);
padding: 0.5rem 1rem;
text-decoration: none;
border-radius: var(--radius);
font-size: 0.85rem;
font-weight: 500;
transition: var(--transition-fast);
display: inline-flex;
align-items: center;
gap: 0.375rem;
}
.card-link:hover {
background: var(--primary-700);
color: white;
transform: translateY(-1px);
}
.card-link.btn-whatsapp {
background: #25D366;
color: white;
}
.card-link.btn-whatsapp:hover {
background: #128C7E;
}
/* Search form */
.search-form {
display: flex;
gap: 0.5rem;
margin-top: 0.25rem;
flex-wrap: wrap;
}
.search-input {
flex: 1;
min-width: 180px;
padding: 0.75rem 1rem;
border: 2px solid var(--gray-300);
border-radius: var(--radius);
font-size: 0.95rem;
transition: var(--transition-fast);
background: white;
color: var(--gray-800);
}
.search-input:focus {
outline: none;
border-color: var(--primary-600);
box-shadow: 0 0 0 3px rgba(58, 110, 165, 0.15);
}
.search-input::placeholder {
color: var(--gray-400);
}
.search-btn {
background: linear-gradient(135deg, var(--primary-700), var(--primary-600));
color: white;
border: none;
padding: 0.75rem 1.5rem;
border-radius: var(--radius);
cursor: pointer;
font-weight: 600;
font-size: 0.95rem;
transition: var(--transition);
white-space: nowrap;
display: inline-flex;
align-items: center;
gap: 0.375rem;
box-shadow: var(--shadow);
}
.search-btn:hover {
background: linear-gradient(135deg, var(--primary-600), var(--primary-700));
transform: translateY(-1px);
box-shadow: var(--shadow-md);
}
.search-btn:active {
transform: translateY(0);
}
/* Result card */
.result-card {
margin-top: 0.5rem;
text-align: left;
border: 1px solid var(--gray-200);
border-radius: var(--radius-lg);
overflow: hidden;
font-size: 0.9rem;
background: var(--gray-50);
animation: slideIn 0.3s ease-out;
}
@keyframes slideIn {
from {
opacity: 0;
transform: translateY(8px);
}
to {
opacity: 1;
transform: translateY(0);
}
}
.result-header {
background: linear-gradient(135deg, var(--primary-800), var(--primary-700));
color: white;
padding: 0.875rem 1rem;
font-weight: 600;
font-size: 0.95rem;
display: flex;
align-items: center;
gap: 0.5rem;
}
.result-row {
display: flex;
justify-content: space-between;
align-items: center;
padding: 0.75rem 1rem;
border-bottom: 1px solid var(--gray-200);
gap: 1rem;
}
.result-row:last-child {
border-bottom: none;
}
.result-label {
font-weight: 600;
color: var(--gray-600);
font-size: 0.85rem;
}
.result-value {
color: var(--gray-800);
font-weight: 500;
text-align: right;
}
.status-badge {
display: inline-flex;
align-items: center;
gap: 0.375rem;
padding: 0.25rem 0.75rem;
border-radius: 9999px;
font-size: 0.8rem;
font-weight: 600;
}
.status-active {
background: rgba(56, 161, 105, 0.15);
color: var(--success-500);
}
.status-inactive {
background: rgba(229, 62, 62, 0.15);
color: var(--danger-500);
}
/* Messages */
.error-msg, .info-msg {
margin-top: 0.75rem;
padding: 0.875rem 1rem;
border-radius: var(--radius);
font-size: 0.9rem;
display: flex;
align-items: flex-start;
gap: 0.5rem;
}
.error-msg {
background: rgba(239, 68, 68, 0.1);
border-left: 3px solid var(--danger-500);
color: var(--danger-500);
}
.info-msg {
background: rgba(245, 158, 11, 0.1);
border-left: 3px solid var(--warning-500);
color: var(--gray-800);
}
/* Table Section */
.table-section {
background: white;
}
.table-container {
overflow-x: auto;
border-radius: var(--radius-lg);
box-shadow: var(--shadow-md);
border: 1px solid var(--gray-200);
}
table {
width: 100%;
border-collapse: collapse;
background: white;
min-width: 600px;
}
thead {
background: linear-gradient(135deg, var(--primary-800), var(--primary-700));
color: white;
}
th {
padding: 1rem 1.25rem;
text-align: left;
font-weight: 600;
font-size: 0.875rem;
text-transform: uppercase;
letter-spacing: 0.025em;
}
td {
padding: 1rem 1.25rem;
border-bottom: 1px solid var(--gray-200);
font-size: 0.95rem;
color: var(--gray-700);
}
tbody tr {
transition: var(--transition-fast);
}
tbody tr:hover {
background: var(--gray-50);
}
tbody tr:last-child td {
border-bottom: none;
}
/* Alert box */
.alert {
background: linear-gradient(135deg, #fffbeb, #fef3c7);
border-left: 4px solid var(--accent-500);
padding: 1.25rem 1.5rem;
margin-top: 2rem;
border-radius: 0 var(--radius) var(--radius) 0;
display: flex;
gap: 0.75rem;
align-items: flex-start;
}
.alert i {
font-size: 1.25rem;
color: var(--accent-600);
margin-top: 0.125rem;
}
.alert strong {
color: var(--gray-800);
font-weight: 600;
display: block;
margin-bottom: 0.25rem;
}
.alert p {
color: var(--gray-700);
margin: 0;
font-size: 0.95rem;
}
/* Normativa Section */
.normativa-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
gap: 1.5rem;
}
.normativa-item {
background: white;
padding: 1.75rem;
border-radius: var(--radius-lg);
box-shadow: var(--shadow-md);
border: 1px solid var(--gray-200);
transition: var(--transition);
}
.normativa-item:hover {
transform: translateY(-3px);
box-shadow: var(--shadow-lg);
border-color: var(--primary-300);
}
.normativa-item h3 {
color: var(--primary-900);
margin-bottom: 1rem;
font-size: 1.1rem;
font-weight: 600;
display: flex;
align-items: center;
gap: 0.5rem;
}
.normativa-item ul {
list-style: none;
display: flex;
flex-direction: column;
gap: 0.5rem;
}
.normativa-item a {
color: var(--primary-700);
text-decoration: none;
font-weight: 500;
font-size: 0.95rem;
transition: var(--transition-fast);
display: flex;
align-items: center;
gap: 0.5rem;
padding: 0.25rem 0;
}
.normativa-item a:hover {
color: var(--primary-900);
transform: translateX(3px);
}
.normativa-item a i {
color: var(--accent-500);
font-size: 0.875rem;
}
/* Footer */
footer {
background: linear-gradient(135deg, var(--primary-900) 0%, var(--primary-800) 100%);
color: white;
padding: 4rem 0 2rem;
margin-top: 3rem;
}
.footer-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
gap: 2rem;
margin-bottom: 2.5rem;
}
.footer-section h3 {
margin-bottom: 1.25rem;
font-size: 1.1rem;
font-weight: 600;
position: relative;
padding-bottom: 0.75rem;
}
.footer-section h3::after {
content: '';
position: absolute;
bottom: 0;
left: 0;
width: 40px;
height: 2px;
background: var(--accent-500);
border-radius: 1px;
}
.footer-section p,
.footer-section a {
color: rgba(255,255,255,0.85);
margin-bottom: 0.625rem;
display: flex;
align-items: center;
gap: 0.5rem;
text-decoration: none;
font-size: 0.95rem;
transition: var(--transition-fast);
}
.footer-section a:hover {
color: var(--accent-500);
transform: translateX(2px);
}
.footer-section i {
width: 18px;
text-align: center;
opacity: 0.9;
}
.copyright {
text-align: center;
padding-top: 2rem;
border-top: 1px solid rgba(255,255,255,0.15);
color: rgba(255,255,255,0.7);
font-size: 0.9rem;
}
/* Back to top */
.back-to-top {
position: fixed;
bottom: 1.5rem;
right: 1.5rem;
width: 44px;
height: 44px;
background: linear-gradient(135deg, var(--primary-700), var(--primary-600));
color: white;
border: none;
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
cursor: pointer;
transition: var(--transition);
box-shadow: var(--shadow-lg);
opacity: 0;
visibility: hidden;
z-index: 99;
font-size: 1rem;
}
.back-to-top.visible {
opacity: 1;
visibility: visible;
}
.back-to-top:hover {
background: linear-gradient(135deg, var(--primary-600), var(--primary-700));
transform: translateY(-3px);
}
/* Responsive */
@media (max-width: 992px) {
.hero-content h2 {
font-size: 2.25rem;
}
}
@media (max-width: 768px) {
.mobile-toggle {
display: flex;
align-items: center;
justify-content: center;
}
nav ul {
position: absolute;
top: 100%;
left: 0;
right: 0;
background: linear-gradient(135deg, var(--primary-900), var(--primary-800));
flex-direction: column;
padding: 1rem;
gap: 0.25rem;
max-height: 0;
overflow: hidden;
transition: max-height 0.3s ease-out, padding 0.3s ease;
}
nav ul.active {
max-height: 500px;
padding: 1rem;
}
nav a {
justify-content: center;
padding: 0.75rem;
font-size: 1rem;
}
.hero {
height: clamp(350px, 50vh, 500px);
}
.hero-content h2 {
font-size: 1.75rem;
}
.hero-content p {
font-size: 1rem;
}
.hero-actions {
flex-direction: column;
align-items: center;
}
.btn {
width: 100%;
max-width: 300px;
justify-content: center;
}
.cards {
grid-template-columns: 1fr;
}
.card .search-form {
flex-direction: column;
}
.card .search-input,
.card .search-btn {
width: 100%;
}
.search-btn {
justify-content: center;
}
.carousel-controls {
flex-direction: column;
gap: 0.75rem;
}
.table-container {
border-radius: var(--radius);
}
th, td {
padding: 0.875rem 1rem;
font-size: 0.9rem;
}
.footer-grid {
grid-template-columns: 1fr;
text-align: center;
}
.footer-section h3::after {
left: 50%;
transform: translateX(-50%);
}
.footer-section p,
.footer-section a {
justify-content: center;
}
}
@media (max-width: 480px) {
.container {
padding: 0 1rem;
}
.logo h1 {
font-size: 1.1rem;
}
.logo p {
font-size: 0.75rem;
}
.hero-content h2 {
font-size: 1.5rem;
}
.section-header h2 {
font-size: 1.35rem;
}
.card {
padding: 1.5rem;
}
.result-row {
flex-direction: column;
align-items: flex-start;
gap: 0.25rem;
}
.result-value {
text-align: left;
}
}
/* Accessibility */
@media (prefers-reduced-motion: reduce) {
*, *::before, *::after {
animation-duration: 0.01ms !important;
animation-iteration-count: 1 !important;
transition-duration: 0.01ms !important;
}
html {
scroll-behavior: auto;
}
}
/* Focus visible for keyboard navigation */
a:focus-visible,
button:focus-visible,
input:focus-visible {
outline: 2px solid var(--accent-500);
outline-offset: 2px;
}
/* Print styles */
@media print {
header, footer, .hero, .carousel-controls, .back-to-top {
display: none;
}
body {
background: white;
color: black;
}
.card, .normativa-item {
break-inside: avoid;
box-shadow: none;
border: 1px solid #ccc;
}
table {
box-shadow: none;
border: 1px solid #ccc;
}
}
</style>
</head>
<body>
<!-- Header -->
<header id="inicio">
<div class="container">
<div class="header-content">
<div class="logo">
<div class="logo-icon">A-2</div>
<div>
<h1>Area Investigaciones A-2</h1>
<p>Control y Fiscalización Seguridad Privada</p>
</div>
</div>
<button class="mobile-toggle" id="mobileToggle" aria-label="Toggle menu" aria-expanded="false">
<i class="fas fa-bars"></i>
</button>
<nav>
<ul id="navMenu">
<li><a href="#inicio"><i class="fas fa-home"></i> Inicio</a></li>
<li><a href="#normativa"><i class="fas fa-book"></i> Normativa</a></li>
<li><a href="#consulta-empresas"><i class="fas fa-search"></i> Consultar</a></li>
<li><a href="#plazos"><i class="fas fa-clock"></i> Plazos</a></li>
<li><a href="#contacto"><i class="fas fa-envelope"></i> Contacto</a></li>
<li><a href="curso/index.php" class="btn-acceso"><i class="fas fa-graduation-cap"></i> Aula</a></li>
<li><a href="login.php" class="btn-acceso"><i class="fas fa-sign-in-alt"></i> Acceso</a></li>
</ul>
</nav>
</div>
</div>
</header>
<!-- Hero Section with Carousel -->
<section class="hero" id="hero">
<div class="hero-carousel">
<!-- Slide 1 -->
<div class="hero-slide active">
<img src="documentos/hero-slide-1.jpg" alt="Control y Fiscalización de Seguridad" onerror="this.style.display='none'; this.parentElement.style.background='linear-gradient(135deg, #1a365d 0%, #2c5282 100%)'">
</div>
<!-- Slide 2 -->
<div class="hero-slide">
<img src="documentos/hero-slide-2.jpg" alt="Auditoría y Regulación" onerror="this.style.display='none'">
</div>
<!-- Slide 3 -->
<div class="hero-slide">
<img src="documentos/hero-slide-3.jpg" alt="Cumplimiento Normativo" onerror="this.style.display='none'">
</div>
<!-- Overlay Content -->
<div class="hero-overlay">
<div class="hero-content">
<h2>Control y Fiscalización de Empresas de Seguridad</h2>
<p>Plataforma oficial para la gestión, auditoría y regulación de servicios de seguridad privada. Garantizando el cumplimiento normativo y la excelencia operativa.</p>
<div class="hero-actions">
<a href="#plazos" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Ver Plazos</a>
<a href="#consulta-empresas" class="btn btn-outline"><i class="fas fa-search"></i> Consultar Empresa</a>
</div>
</div>
</div>
<!-- Carousel Navigation -->
<div class="carousel-controls">
<button class="carousel-btn" id="prevSlide" aria-label="Slide anterior">
<i class="fas fa-chevron-left"></i>
</button>
<div class="carousel-dots">
<span class="carousel-dot active" data-slide="0" role="button" tabindex="0" aria-label="Ir al slide 1"></span>
<span class="carousel-dot" data-slide="1" role="button" tabindex="0" aria-label="Ir al slide 2"></span>
<span class="carousel-dot" data-slide="2" role="button" tabindex="0" aria-label="Ir al slide 3"></span>
</div>
<button class="carousel-btn" id="nextSlide" aria-label="Siguiente slide">
<i class="fas fa-chevron-right"></i>
</button>
</div>
</div>
</section>
<!-- Cards Section -->
<section class="section container" id="consulta-empresas">
<div class="section-header">
<h2>Servicios y Consultas</h2>
<p>Acceda a las herramientas principales para la gestión de seguridad privada.</p>
</div>
<div class="cards">
<!-- Consulta Pública de Empresas -->
<div class="card">
<div class="card-icon"><i class="fas fa-building"></i></div>
<h3>🔍 Consulta Pública de Empresas</h3>
<p>Verifique el estado de habilitación de una empresa de seguridad ingresando su CUIT.</p>
<form method="GET" action="#consulta-empresas" class="search-form">
<input type="text" name="cuit_search" class="search-input" placeholder="Ingrese CUIT (ej: 30-12345678-9)" value="<?php echo htmlspecialchars($search_cuit); ?>" required pattern="[0-9\-]+" aria-label="Número de CUIT">
<button type="submit" class="search-btn"><i class="fas fa-search"></i> Buscar</button>
</form>
<?php if ($search_error): ?>
<div class="error-msg">
<i class="fas fa-exclamation-circle"></i> <?php echo $search_error; ?>
</div>
<?php endif; ?>
<?php if ($search_result): ?>
<div class="result-card" role="region" aria-label="Resultado de búsqueda">
<div class="result-header"><i class="fas fa-check-circle"></i> Empresa Encontrada</div>
<div class="result-row">
<span class="result-label">CUIT</span>
<span class="result-value"><?php echo htmlspecialchars($search_result['cuit']); ?></span>
</div>
<div class="result-row">
<span class="result-label">Razón Social</span>
<span class="result-value"><?php echo htmlspecialchars($search_result['nombre']); ?></span>
</div>
<div class="result-row">
<span class="result-label">Estado</span>
<span class="result-value">
<span class="status-badge <?php echo $search_result['activo'] ? 'status-active' : 'status-inactive'; ?>">
<i class="fas <?php echo $search_result['activo'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
<?php echo $search_result['activo'] ? 'ACTIVO' : 'INACTIVO'; ?>
</span>
</span>
</div>
</div>
<?php elseif (isset($_GET['cuit_search']) && !$search_error): ?>
<div class="info-msg">
<i class="fas fa-search"></i> No se encontró ninguna empresa con el CUIT ingresado.
</div>
<?php endif; ?>
</div>
<!-- Habilitaciones -->
<div class="card">
<div class="card-icon"><i class="fas fa-clipboard-list"></i></div>
<h3>📋 Habilitaciones</h3>
<p>Gestión integral para la renovación y obtención de habilitaciones de funcionamiento para empresas, vigiladores y personal de seguridad.</p>
<div class="card-links">
<a href="documentos/requisitos-completos.pdf" class="card-link" target="_blank" rel="noopener">
<i class="fas fa-file-pdf"></i> Requisitos Completos
</a>
</div>
</div>
<!-- Denuncias -->
<div class="card">
<div class="card-icon"><i class="fas fa-exclamation-triangle"></i></div>
<h3>⚠️ Denuncias y Fiscalización</h3>
<p>Canal oficial para reportar irregularidades en el servicio de seguridad privada o solicitar inspecciones.</p>
<a href="https://wa.me/5492804862992" class="card-link btn-whatsapp" target="_blank" rel="noopener">
<i class="fab fa-whatsapp"></i> Denunciar por WhatsApp
</a>
</div>
</div>
</section>
<!-- Plazos Section -->
<section id="plazos" class="section table-section">
<div class="container">
<div class="section-header">
<h2>📅 Plazos de Presentación</h2>
<p>Tabla oficial de plazos según normativa vigente para trámites y reportes obligatorios.</p>
</div>
<div class="table-container">
<table>
<thead>
<tr>
<th scope="col">Situación</th>
<th scope="col">Plazo</th>
<th scope="col">Artículo</th>
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
<div class="alert">
<i class="fas fa-exclamation-triangle"></i>
<div>
<strong>⚠️ Importante</strong>
<p>El incumplimiento de los plazos establecidos para la presentación de documentación podrá acarrear sanciones administrativas según lo dispuesto en la Ley de Seguridad Privada y su reglamentación.</p>
</div>
</div>
</div>
</section>
<!-- Normativa Section -->
<section id="normativa" class="section">
<div class="container">
<div class="section-header">
<h2>📚 Marco Normativo y Recursos</h2>
<p>Documentación oficial para el cumplimiento de la ley de seguridad privada.</p>
</div>
<div class="normativa-grid">
<div class="normativa-item">
<h3><i class="fas fa-folder-open"></i> Documentos Descargables</h3>
<ul>
<li><a href="documentos/reglamento-seguridad-privada.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Reglamento de Seguridad Privada</a></li>
<li><a href="documentos/formulario-inspeccion-tecnica.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Formulario de Inspección Técnica</a></li>
<li><a href="documentos/codigo-etica-conducta.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Código de Ética y Conducta</a></li>
<li><a href="documentos/protocolos-actuacion.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Protocolos de Actuación</a></li>
<li><a href="documentos/armar-carpeta-empresa-sucursal.pdf" target="_blank" rel="noopener"><i class="fas fa-folder"></i> Armar carpeta Empresa/Sucursal</a></li>
<li><a href="documentos/armar-carpeta-personal.pdf" target="_blank" rel="noopener"><i class="fas fa-folder"></i> Armar carpeta Personal</a></li>
</ul>
</div>
<div class="normativa-item">
<h3><i class="fas fa-balance-scale"></i> Leyes Principales</h3>
<p style="margin-bottom: 1rem; color: var(--gray-600);">Acceda a la legislación vigente y normativas clave que regulan la seguridad privada.</p>
<ul>
<li><a href="documentos/ley-seguridad-privada.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Ley de Seguridad Privada</a></li>
<li><a href="documentos/ley-reglamentacion-general.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Ley de Reglamentación General</a></li>
<li><a href="documentos/ley-organica-seguridad.pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Ley Orgánica de Seguridad</a></li>
</ul>
</div>
</div>
</div>
</section>
<!-- Footer -->
<footer id="contacto">
<div class="container">
<div class="footer-grid">
<div class="footer-section">
<h3>Area Investigaciones A-2</h3>
<p><i class="fas fa-shield-alt"></i> Control y Fiscalización de Seguridad</p>
<p><i class="fas fa-map-marker-alt"></i> Pedro Martinez</p>
<p><i class="fas fa-door-open"></i> Rawson, Chubut</p>
</div>
<div class="footer-section">
<h3>Enlaces Rápidos</h3>
<a href="#"><i class="fas fa-headset"></i> Soporte Técnico</a>
<a href="#"><i class="fas fa-question-circle"></i> Preguntas Frecuentes</a>
<a href="#"><i class="fas fa-life-ring"></i> Mesa de Ayuda</a>
</div>
<div class="footer-section">
<h3>Contacto</h3>
<p><i class="fas fa-phone-alt"></i> (+549) 2804----</p>
<p><i class="fas fa-envelope"></i> fiscalizacion@a2.gob</p>
<p><i class="fas fa-clock"></i> Lun - Vie: 8:00 - 14:00</p>
</div>
</div>
<div class="copyright">
<p>© <?php echo date('Y'); ?> Area Investigaciones A-2. Todos los derechos reservados.</p>
</div>
</div>
</footer>
<!-- Back to Top Button -->
<button class="back-to-top" id="backToTop" aria-label="Volver arriba">
<i class="fas fa-arrow-up"></i>
</button>
<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
// Mobile Menu Toggle
const mobileToggle = document.getElementById('mobileToggle');
const navMenu = document.getElementById('navMenu');
if (mobileToggle && navMenu) {
mobileToggle.addEventListener('click', function() {
const expanded = this.getAttribute('aria-expanded') === 'true' || false;
this.setAttribute('aria-expanded', !expanded);
navMenu.classList.toggle('active');
this.innerHTML = !expanded ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
});
// Close menu when clicking a link (mobile)
navMenu.querySelectorAll('a').forEach(link => {
link.addEventListener('click', () => {
navMenu.classList.remove('active');
mobileToggle.setAttribute('aria-expanded', 'false');
mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
});
});
}
// Carousel Functionality
const slides = document.querySelectorAll('.hero-slide');
const dots = document.querySelectorAll('.carousel-dot');
const prevBtn = document.getElementById('prevSlide');
const nextBtn = document.getElementById('nextSlide');
let currentSlide = 0;
const slideInterval = 6000;
let slideTimer;
function showSlide(index) {
slides.forEach((slide, i) => {
slide.classList.remove('active');
if (dots[i]) dots[i].classList.remove('active');
});
if (slides[index]) slides[index].classList.add('active');
if (dots[index]) dots[index].classList.add('active');
currentSlide = index;
}
function nextSlide() {
showSlide((currentSlide + 1) % slides.length);
}
function prevSlide() {
showSlide((currentSlide - 1 + slides.length) % slides.length);
}
function startSlider() {
slideTimer = setInterval(nextSlide, slideInterval);
}
function stopSlider() {
clearInterval(slideTimer);
}
// Dot navigation
dots.forEach((dot, index) => {
dot.addEventListener('click', () => {
stopSlider();
showSlide(index);
startSlider();
});
dot.addEventListener('keypress', (e) => {
if (e.key === 'Enter' || e.key === ' ') {
e.preventDefault();
stopSlider();
showSlide(index);
startSlider();
}
});
});
// Button navigation
if (prevBtn) prevBtn.addEventListener('click', () => { stopSlider(); prevSlide(); startSlider(); });
if (nextBtn) nextBtn.addEventListener('click', () => { stopSlider(); nextSlide(); startSlider(); });
// Pause on hover
const heroSection = document.querySelector('.hero');
if (heroSection) {
heroSection.addEventListener('mouseenter', stopSlider);
heroSection.addEventListener('mouseleave', startSlider);
}
// Start slider
startSlider();
// Back to Top Button
const backToTop = document.getElementById('backToTop');
if (backToTop) {
window.addEventListener('scroll', () => {
if (window.pageYOffset > 400) {
backToTop.classList.add('visible');
} else {
backToTop.classList.remove('visible');
}
});
backToTop.addEventListener('click', (e) => {
e.preventDefault();
window.scrollTo({ top: 0, behavior: 'smooth' });
});
}
// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
anchor.addEventListener('click', function(e) {
const href = this.getAttribute('href');
if (href !== '#') {
e.preventDefault();
const target = document.querySelector(href);
if (target) {
const headerOffset = 80;
const elementPosition = target.getBoundingClientRect().top;
const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
}
}
});
});
// Form validation enhancement
const searchForm = document.querySelector('.search-form');
if (searchForm) {
searchForm.addEventListener('submit', function(e) {
const input = this.querySelector('.search-input');
const value = input.value.trim().replace(/[^\d\-]/g, '');
if (value.length < 11) {
e.preventDefault();
input.focus();
input.style.borderColor = '#e53e3e';
setTimeout(() => input.style.borderColor = '', 2000);
}
});
}
});
</script>
</body>
</html>