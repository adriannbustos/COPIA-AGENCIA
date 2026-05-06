<?php
require_once '../config/database.php';
require_once '../config/auth.php';

// 1. VERIFICACIÓN DE SEGURIDAD
if (!$auth->isLoggedIn()) { header('Location: ../login.php'); exit; }
if (!$auth->hasRole('administrador') && !$auth->hasRole('carga')) { 
    die('<h3 style="color:red;text-align:center;padding:50px">Acceso denegado</h3>'); 
}

// 2. RECIBIR FILTROS
$empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
$sucursal_id = isset($_GET['sucursal_id']) && !empty($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : null;

if ($empresa_id <= 0) {
    die('<h3 style="color:red;text-align:center;padding:50px">Debe seleccionar una empresa válida.</h3><p><a href="personal.php">Volver</a></p>');
}

try {
    $conn = getDBConnection();
    
    // Obtener nombres para el reporte
    $stmt = $conn->prepare("SELECT nombre FROM empresas WHERE id = ?");
    $stmt->execute([$empresa_id]);
    $empresa_nombre = $stmt->fetchColumn() ?: 'Empresa desconocida';

    $sucursal_nombre = 'Todas las Sucursales';
    if ($sucursal_id) {
        $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ?");
        $stmt->execute([$sucursal_id]);
        $sucursal_nombre = $stmt->fetchColumn() ?: 'Sucursal desconocida';
    }

    // ==========================================
    // CONSULTA 1: PERSONAL
    // ==========================================
    $q_personal = "SELECT nombre, apellido, dni, cargo, activo FROM personal WHERE empresa_id = ?";
    $params_personal = [$empresa_id];
    if ($sucursal_id) { $q_personal .= " AND sucursal_id = ?"; $params_personal[] = $sucursal_id; }
    
    $stmt = $conn->prepare($q_personal);
    $stmt->execute($params_personal);
    $personal = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // CONSULTA 2: SERVICIOS
    // ==========================================
    $q_servicios = "SELECT nombre, tipo, estado, descripcion FROM servicios WHERE empresa_id = ?";
    $params_servicios = [$empresa_id];
    if ($sucursal_id) { $q_servicios .= " AND sucursal_id = ?"; $params_servicios[] = $sucursal_id; }
    
    $stmt = $conn->prepare($q_servicios);
    $stmt->execute($params_servicios);
    $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // CONSULTA 3: RECURSOS (Unión de Recursos + Items)
    // ==========================================
    // Primero obtenemos los IDs de recursos de la empresa/sucursal
    $q_recursos_ids = "SELECT id FROM recursos_sucursal WHERE empresa_id = ?";
    $params_recurso_ids = [$empresa_id];
    if ($sucursal_id) { $q_recursos_ids .= " AND sucursal_id = ?"; $params_recurso_ids[] = $sucursal_id; }
    
    $stmt_ids = $conn->prepare($q_recursos_ids);
    $stmt_ids->execute($params_recurso_ids);
    $recursos_ids = $stmt_ids->fetchAll(PDO::FETCH_COLUMN);

    $recursos_detallados = [];
    if (!empty($recursos_ids)) {
        // Obtenemos los items (marca, modelo) de esos recursos
        $placeholders = implode(',', array_fill(0, count($recursos_ids), '?'));
        $q_items = "SELECT tipo_recurso, atributos FROM recursos_items WHERE recursos_sucursal_id IN ($placeholders) ORDER BY tipo_recurso";
        $stmt_items = $conn->prepare($q_items);
        $stmt_items->execute($recursos_ids);
        $recursos_detallados = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==========================================
    // GENERACIÓN DE PDF (FPDF)
    // ==========================================
    if (!file_exists('../vendor/fpdf/fpdf.php')) {
        die('Error: FPDF no encontrado en ../vendor/fpdf/fpdf.php');
    }
    require_once '../vendor/fpdf/fpdf.php';

    class PDF_ReporteGlobal extends FPDF {
        function Header() {
            $this->SetFont('Arial','B',15);
            $this->SetTextColor(0, 51, 102);
            $this->Cell(0,10,'REPORTE GLOBAL DE EMPRESA',0,1,'C');
            $this->SetFont('Arial','',10);
            $this->SetTextColor(100,100,100);
            $this->Cell(0,5,'Generado el: '.date('d/m/Y H:i:s'),0,1,'C');
            $this->Ln(5);
            $this->SetDrawColor(0, 51, 102);
            $this->Line(10, 25, 200, 25);
            $this->Ln(5);
        }
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial','I',8);
            $this->SetTextColor(128);
            $this->Cell(0,10,'Pagina '.$this->PageNo().'/{nb}',0,0,'C');
        }
    }

    $pdf = new PDF_ReporteGlobal();
    $pdf->AliasNbPages();
    $pdf->AddPage('P', 'A4');
    
    // Cabecera de Datos
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(0, 8, utf8_decode("Empresa: $empresa_nombre"), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, utf8_decode("Sucursal: $sucursal_nombre"), 0, 1, 'L');
    $pdf->Ln(5);

    // ==========================================
    // SECCIÓN 1: PERSONAL
    // ==========================================
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(200, 220, 255);
    $pdf->Cell(0, 8, '1. Personal Habilitado', 0, 1, 'L', true);
    $pdf->Ln(2);
    
    // Tabla Header
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(15, 7, 'DNI', 1, 0, 'C', true);
    $pdf->Cell(70, 7, 'Apellido y Nombre', 1, 0, 'C', true);
    $pdf->Cell(40, 7, 'Cargo', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Estado', 1, 1, 'C', true);
    
    // Tabla Data
    $pdf->SetFont('Arial', '', 9);
    if (empty($personal)) {
        $pdf->Cell(0, 7, 'No se encontraron registros.', 1, 1, 'C');
    } else {
        foreach ($personal as $p) {
            $nombre_completo = utf8_decode($p['apellido'] . ', ' . $p['nombre']);
            $cargo = utf8_decode($p['cargo'] ?? '-');
            $estado = $p['activo'] ? 'Activo' : 'Inactivo';
            
            $pdf->Cell(15, 6, $p['dni'], 1, 0, 'C');
            $pdf->Cell(70, 6, $nombre_completo, 1, 0, 'L');
            $pdf->Cell(40, 6, $cargo, 1, 0, 'L');
            $pdf->Cell(25, 6, $estado, 1, 1, 'C');
        }
    }
    $pdf->Ln(10);

    // ==========================================
    // SECCIÓN 2: SERVICIOS
    // ==========================================
    $pdf->AddPage(); // Nueva página para servicios
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(200, 255, 200);
    $pdf->Cell(0, 8, '2. Servicios Asignados', 0, 1, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(20, 7, 'ID', 1, 0, 'C', true);
    $pdf->Cell(50, 7, 'Nombre', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Tipo', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Estado', 1, 0, 'C', true);
    $pdf->Cell(65, 7, 'Descripcion', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    if (empty($servicios)) {
        $pdf->Cell(0, 7, 'No se encontraron servicios.', 1, 1, 'C');
    } else {
        foreach ($servicios as $s) {
            $pdf->Cell(20, 6, $s['id'], 1, 0, 'C');
            $pdf->Cell(50, 6, utf8_decode($s['nombre']), 1, 0, 'L');
            $pdf->Cell(30, 6, utf8_decode(ucfirst($s['tipo'])), 1, 0, 'C');
            $pdf->Cell(25, 6, utf8_decode(ucfirst($s['estado'])), 1, 0, 'C');
            $pdf->Cell(65, 6, utf8_decode(substr($s['descripcion'] ?? '', 0, 50)), 1, 1, 'L');
        }
    }
    $pdf->Ln(10);

    // ==========================================
    // SECCIÓN 3: RECURSOS (MARCA/MODELO)
    // ==========================================
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(255, 200, 200);
    $pdf->Cell(0, 8, '3. Inventario de Recursos', 0, 1, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(40, 7, 'Tipo Recurso', 1, 0, 'C', true);
    $pdf->Cell(50, 7, 'Marca', 1, 0, 'C', true);
    $pdf->Cell(50, 7, 'Modelo', 1, 0, 'C', true);
    $pdf->Cell(50, 7, 'Detalles Adic.', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    if (empty($recursos_detallados)) {
        $pdf->Cell(0, 7, 'No se encontraron recursos asignados.', 1, 1, 'C');
    } else {
        foreach ($recursos_detallados as $r) {
            $tipo = utf8_decode(str_replace('_', ' ', ucfirst($r['tipo_recurso'])));
            $atributos = json_decode($r['atributos'], true);
            
            // Extraer Marca y Modelo (puede variar según el tipo, intentamos capturar los más comunes)
            $marca = isset($atributos['Marca']) ? utf8_decode($atributos['Marca']) : '-';
            $modelo = isset($atributos['Modelo']) ? utf8_decode($atributos['Modelo']) : '-';
            
            // Capturar serial u otro dato relevante si existe
            $detalle = '';
            if (isset($atributos['Numero de Serie'])) $detalle .= 'S/N: ' . $atributos['Numero de Serie'];
            if (isset($atributos['Calibre'])) $detalle .= ($detalle ? ' | ' : '') . $atributos['Calibre'];
            if (isset($atributos['Patente'])) $detalle .= ($detalle ? ' | ' : '') . 'Pat: ' . $atributos['Patente'];
            if (empty($detalle)) $detalle = '-';

            $pdf->Cell(40, 6, $tipo, 1, 0, 'C');
            $pdf->Cell(50, 6, $marca, 1, 0, 'L');
            $pdf->Cell(50, 6, $modelo, 1, 0, 'L');
            $pdf->Cell(50, 6, utf8_decode($detalle), 1, 1, 'L');
        }
    }

    // Salida
    $pdf->Output('I', 'Reporte_Global_' . preg_replace('/[^a-zA-Z0-9]/', '_', $empresa_nombre) . '_' . date('Ymd_His') . '.pdf');
    exit;

} catch (PDOException $e) {
    die("Error en la base de datos: " . $e->getMessage());
}
?>