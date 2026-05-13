<?php
// generar_cedula.php
session_start();
// 1. Conexión y Autenticación
require_once '../config/database.php';
require_once '../config/auth.php';
// 2. Cargar FPDF
if (file_exists(__DIR__ . '/../vendor/fpdf/fpdf.php')) {
    require_once __DIR__ . '/../vendor/fpdf/fpdf.php';
} else {
    die("Error: Librería FPDF no encontrada en ../vendor/fpdf/. Verifica la estructura de carpetas.");
}
// 3. Verificar permisos
if (!$auth->isLoggedIn() || !$auth->hasRole('administrador')) {
    header('Location: ../login.php');
    exit;
}
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tramite_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$tramite_id) {
    die("ID de trámite inválido");
}
try {
    // 4. ✅ CORREGIDO: Consulta SQL con '=' antes de :id
    $stmt = $conn->prepare("
    SELECT t.*, e.nombre as empresa_nombre
    FROM tramites_empresa t
    LEFT JOIN empresas e ON t.empresa_id = e.id
    WHERE t.id = :id
    ");
    $stmt->execute(['id' => $tramite_id]);
    $tramite = $stmt->fetch();
    if (!$tramite) {
        die("Trámite no encontrado");
    }
    
    // 5. ✅ Obtener configuración del jefe (firma y datos)
    $config_jefe = null;
    try {
        $stmt_jefe = $conn->query("SELECT jefe_apellido, jefe_nombre, jefe_gerarquia, firma_path FROM config_credenciales WHERE id = 1");
        $config_jefe = $stmt_jefe->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        $config_jefe = null;
    }
    
    // 6. Crear PDF
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    // Encabezado
    $pdf->Cell(0, 10, 'CEDULA DE NOTIFICACION', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, 'SISTEMA DE GESTION DE SEGURIDAD', 0, 1, 'C');
    $pdf->Ln(10);
    // Línea separadora
    $pdf->Line(20, 35, 190, 35);
    $pdf->Ln(10);
    // Cuerpo del documento
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 8, 'Empresa:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, utf8_decode($tramite['empresa_nombre']), 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 8, 'Tipo Movimiento:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, utf8_decode(ucfirst(str_replace('_', ' ', $tramite['tipo_movimiento']))), 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Ln(5);
    $pdf->Cell(50, 8, 'Fecha Notificacion:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, !empty($tramite['fecha_notificacion']) ? date('d/m/Y', strtotime($tramite['fecha_notificacion'])) : 'N/A', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 8, 'Plazo Otorgado:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, (!empty($tramite['plazo_dias']) ? $tramite['plazo_dias'] : '0') . ' días hábiles', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 8, 'Fecha Limite:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, !empty($tramite['fecha_limite']) ? date('d/m/Y', strtotime($tramite['fecha_limite'])) : 'N/A', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Observaciones:', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, utf8_decode($tramite['observaciones_admin'] ?? 'Sin observaciones.'), 0, 'L');
    
    // 7. ✅ Firmas con imagen de firma del jefe
    $pdf->Ln(20);
    
    // Guardar posición Y actual para alinear ambas firmas
    $y_firma = $pdf->GetY();
    
// === FIRMA RESPONSABLE ADMINISTRATIVO (IZQUIERDA) ===
// Calcular posición X para centrar la firma en página A4 (210mm de ancho)
// Ancho firma: 65mm, Posición X: (210 - 65) / 2 = 72.5mm
$pdf->SetXY(72.5, $y_firma);

// Insertar imagen de firma si existe
if ($config_jefe && !empty($config_jefe['firma_path']) && file_exists(__DIR__ . '/../uploads/firmas_jefe/' . $config_jefe['firma_path'])) {
    $firma_path = __DIR__ . '/../uploads/firmas_jefe/' . $config_jefe['firma_path'];
    // Insertar firma (ancho: 65mm, alto: 19.5mm) - 30% más grande
    $pdf->Image($firma_path, 72.5, $y_firma, 65, 19.5, 'PNG');
    $pdf->Ln(19.5);
} else {
    $pdf->Cell(70, 10, '__________________________', 0, 1, 'C');
    $pdf->Ln(5);
}
    
// Nombre y jerarquía del jefe
if ($config_jefe) {
    $pdf->SetFont('Arial', 'B', 10);
    $nombre_completo = utf8_decode($config_jefe['jefe_apellido'] . ', ' . $config_jefe['jefe_nombre']);
    
    $pdf->SetX(72.5); // ✅ Posicionar X antes del nombre
    $pdf->Cell(70, 6, $nombre_completo, 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'I', 9);
    
    $pdf->SetX(72.5); // ✅ Posicionar X antes de la jerarquía (ESTO FALTABA)
    $pdf->Cell(70, 5, utf8_decode($config_jefe['jefe_gerarquia']), 0, 1, 'C');
}
    
    // === FIRMA EMPRESA (DERECHA) ===
    $pdf->SetXY(120, $y_firma);
        // Firmas
    $pdf->Ln(35);
    $pdf->Cell(0, 10, '__________________________', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Firma Responsable Administrativo', 0, 1, 'C');
    
    $pdf->Ln(15);
    $pdf->Cell(0, 10, '__________________________', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Recibido por la Empresa', 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 5, 'Documento generado el: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    
    // Salida (D = Descargar)
    $nombre_archivo = 'Cedula_Notificacion_' . $tramite_id . '.pdf';
    $pdf->Output('D', $nombre_archivo);
} catch (Exception $e) {
    die("Error al generar cédula: " . $e->getMessage());
}
?>