<?php
// verificar_sucursal.php - Página pública de verificación (NO REQUIERE LOGIN)
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/html; charset=utf-8');

$sucursal_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$sucursal_id) {
    http_response_code(400);
    die('<h1 style="text-align:center;color:#e74c3c;padding:50px">❌ ID de sucursal no válido</h1>');
}

// ==================== VALIDACIÓN DE TOKEN DE SEGURIDAD ====================
$secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'TuClaveSecretaMuySegura_2026_ChubutSeguridad';
$received_token = $_GET['token'] ?? '';
$expiracion_timestamp = isset($_GET['exp']) && is_numeric($_GET['exp']) ? (int)$_GET['exp'] : 0;

if (empty($received_token) || $expiracion_timestamp <= 0) {
    http_response_code(403);
    die('<h1 style="text-align:center;color:#e74c3c;padding:50px">🔒 Acceso denegado</h1><p style="text-align:center;color:#7f8c8d">Faltan parámetros de seguridad en la URL.</p>');
}

// Verificar que la expiración sea válida (permite 24h de desfase hacia atrás y máximo 380 días hacia adelante)
$now = time();
if ($expiracion_timestamp < ($now - 86400) || $expiracion_timestamp > ($now + (380 * 24 * 60 * 60))) {
    http_response_code(403);
    die('<h1 style="text-align:center;color:#e74c3c;padding:50px">🔒 Token expirado o fecha inválida</h1>');
}

// Validar firma HMAC
$payload = $sucursal_id . '|' . $expiracion_timestamp;
$expected_token = hash_hmac('sha256', $payload, $secret_key);
if (!hash_equals($expected_token, $received_token)) {
    http_response_code(403);
    die('<h1 style="text-align:center;color:#e74c3c;padding:50px">🔒 Firma de seguridad inválida</h1>');
}
// ==================== FIN VALIDACIÓN DE TOKEN ====================

try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT s.*, e.nombre as empresa_nombre, e.cuit, e.domicilio as empresa_domicilio,
               e.localidad as empresa_localidad, e.razon_social
        FROM sucursales s
        LEFT JOIN empresas e ON s.empresa_id = e.id
        WHERE s.id = :id AND e.activo = TRUE
    ");
    $stmt->execute(['id' => $sucursal_id]);
    $sucursal = $stmt->fetch();

    if (!$sucursal) {
        http_response_code(404);
        die('<h1 style="text-align:center;color:#e74c3c;padding:50px">❌ Sucursal no encontrada o empresa inactiva</h1>');
    }

    // Estado real de habilitación
    $esta_habilitada = (bool)$sucursal['activa'] && (bool)$sucursal['en_funcionamiento'] && (bool)$sucursal['pago_arancel'];
    $fecha_verificacion = date('d/m/Y H:i:s');

    // Últimos 3 informes (si existe la tabla)
    $ultimos_informes = [];
    try {
        $stmt_informes = $conn->prepare("
            SELECT fecha_carga, tipo_documento, archivo_pdf, observaciones, estado
            FROM documentos_sucursales
            WHERE empresa_id = :empresa_id AND tipo_documento = 'informe'
            ORDER BY fecha_carga DESC LIMIT 3
        ");
        $stmt_informes->execute(['empresa_id' => $sucursal['empresa_id']]);
        $ultimos_informes = $stmt_informes->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $ultimos_informes = [];
    }
} catch (Exception $e) {
    die('<h1 style="text-align:center;color:#e74c3c;padding:50px">⚠️ Error de conexión a la base de datos</h1>');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación Oficial - Policía de Chubut</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #2c3e50; --success: #27ae60; --danger: #e74c3c; --info: #3498db; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background: linear-gradient(135deg, #1a2a6c, #2c3e50); color: #333; padding: 20px; min-height: 100vh; }
        .container { max-width: 950px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
        .header { background: var(--primary); color: white; padding: 25px; text-align: center; position: relative; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header h2 { font-size: 16px; opacity: 0.9; font-weight: 400; }
        .seal { position: absolute; top: 20px; right: 20px; width: 70px; height: 70px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; text-align: center; line-height: 1.2; opacity: 0.7; }
        .content { padding: 30px; }
        .status-badge { text-align: center; padding: 20px; border-radius: 12px; margin: 20px 0; font-size: 22px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 6px 15px rgba(0,0,0,0.15); }
        .status-ok { background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; border: 2px solid #27ae60; }
        .status-fail { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; border: 2px solid #c0392b; }
        .section { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px dashed #ddd; }
        .section:last-child { border-bottom: none; }
        .section-title { display: flex; align-items: center; margin-bottom: 15px; color: var(--primary); font-size: 18px; font-weight: 700; }
        .section-title i { margin-right: 10px; font-size: 20px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
        .info-item { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid var(--info); }
        .info-label { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 4px; font-weight: 600; }
        .info-value { font-size: 16px; color: var(--primary); font-weight: 600; }
        .doc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 10px; }
        .doc-card { padding: 15px; border-radius: 8px; text-align: center; font-weight: 600; font-size: 14px; }
        .doc-yes { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .doc-no { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .informe-item { display: flex; justify-content: space-between; align-items: center; background: #f1f3f5; padding: 12px 15px; border-radius: 8px; margin-bottom: 8px; border-left: 4px solid #4361ee; }
        .informe-info { flex: 1; }
        .informe-fecha { font-size: 12px; color: #666; }
        .informe-estado { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #eee; }
        .estado-aprobado { background: #d4edda; color: #155724; }
        .estado-pendiente { background: #fff3cd; color: #856404; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; color: #666; font-size: 12px; border-top: 1px solid #eee; }
        @media (max-width: 600px) { .content { padding: 20px; } .header h1 { font-size: 20px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="seal">VERIFICACIÓN<br>OFICIAL<br>CHUBUT</div>
            <h1><i class="fas fa-shield-alt"></i> SISTEMA DE GESTIÓN DE SEGURIDAD</h1>
            <h2>Área Investigaciones (D.S.) - Agencias Privadas</h2>
        </div>

        <div class="content">
            <div class="status-badge <?php echo $esta_habilitada ? 'status-ok' : 'status-fail'; ?>">
                <i class="fas fa-<?php echo $esta_habilitada ? 'check-circle' : 'times-circle'; ?>"></i>
                <?php echo $esta_habilitada ? 'SUCURSAL HABILITADA Y OPERATIVA' : 'SUCURSAL NO HABILITADA / INACTIVA'; ?>
            </div>

            <div class="section">
                <div class="section-title"><i class="fas fa-building"></i> Datos de la Empresa</div>
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Empresa</div><div class="info-value"><?php echo htmlspecialchars($sucursal['empresa_nombre']); ?></div></div>
                    <div class="info-item"><div class="info-label">Razón Social</div><div class="info-value"><?php echo htmlspecialchars($sucursal['razon_social'] ?? 'No registrada'); ?></div></div>
                    <div class="info-item"><div class="info-label">CUIT</div><div class="info-value"><?php echo htmlspecialchars($sucursal['cuit'] ?? 'N/A'); ?></div></div>
                    <div class="info-item"><div class="info-label">Domicilio Legal</div><div class="info-value"><?php echo htmlspecialchars($sucursal['empresa_domicilio'] . ' - ' . $sucursal['empresa_localidad']); ?></div></div>
                </div>
            </div>

            <div class="section">
                <div class="section-title"><i class="fas fa-store"></i> Datos de la Sucursal</div>
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Nombre</div><div class="info-value"><?php echo htmlspecialchars($sucursal['nombre']); ?></div></div>
                    <div class="info-item"><div class="info-label">Domicilio</div><div class="info-value"><?php echo htmlspecialchars($sucursal['domicilio'] . ' - ' . $sucursal['localidad']); ?></div></div>
                    <div class="info-item"><div class="info-label">Jurisdicción</div><div class="info-value"><?php echo htmlspecialchars($sucursal['jurisdiccion'] ?? 'No definida'); ?></div></div>
                    <div class="info-item"><div class="info-label">Nº Resolución</div><div class="info-value"><?php echo htmlspecialchars($sucursal['numero_resolucion'] ?? 'N/A'); ?></div></div>
                </div>
            </div>

            <div class="section">
                <div class="section-title"><i class="fas fa-file-certificate"></i> Documentación Requerida</div>
                <div class="doc-grid">
                    <div class="doc-card <?php echo $sucursal['renar'] ? 'doc-yes' : 'doc-no'; ?>"><i class="fas fa-file-alt fa-lg"></i><br>RENAR</div>
                    <div class="doc-card <?php echo $sucursal['certificado_cumplimiento'] ? 'doc-yes' : 'doc-no'; ?>"><i class="fas fa-certificate fa-lg"></i><br>Cert. Cumpl.</div>
                    <div class="doc-card <?php echo $sucursal['habilitacion_comercial'] ? 'doc-yes' : 'doc-no'; ?>"><i class="fas fa-building fa-lg"></i><br>Hab. Comercial</div>
                    <div class="doc-card <?php echo $sucursal['pago_arancel'] ? 'doc-yes' : 'doc-no'; ?>"><i class="fas fa-receipt fa-lg"></i><br>Arancel Pago</div>
                </div>
            </div>

            <?php if (!empty($ultimos_informes)): ?>
            <div class="section">
                <div class="section-title"><i class="fas fa-history"></i> Últimos Informes Cargados</div>
                <?php foreach($ultimos_informes as $inf): ?>
                <div class="informe-item">
                    <div class="informe-info">
                        <strong>📄 Informe de Sucursal</strong>
                        <div class="informe-fecha"><i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($inf['fecha_carga'])); ?></div>
                    </div>
                    <span class="informe-estado estado-<?php echo $inf['estado']; ?>"><?php echo strtoupper($inf['estado']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="background:#e3f2fd; padding:15px; border-radius:8px; text-align:center; color:#0d47a1; margin-top:10px;">
                <i class="fas fa-sync-alt"></i> Verificado en tiempo real: <strong><?php echo $fecha_verificacion; ?></strong><br>
                <small>Documento generado electrónicamente. Válido sin firma manuscrita.</small>
            </div>
        </div>

        <div class="footer">
            <p><strong>Policía de Chubut - Área Investigaciones (D.S.)</strong></p>
            <p>Sistema de Gestión de Agencias Privadas de Seguridad | © <?php echo date('Y'); ?></p>
        </div>
    </div>
</body>
</html>