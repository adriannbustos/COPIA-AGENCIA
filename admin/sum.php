<?php
// Configuración básica de la página
$titulo_pagina = "Guía de Sumario Administrativo - Argentina";
$fecha_actual = date("d/m/Y");
// Datos del proceso legal (Array asociativo para organizar la información)
$proceso_sumario = [
[
"etapa" => "1. Inicio del Sumario",
"accion" => "Acta de Inicio o Denuncia",
"descripcion" => "El procedimiento comienza cuando la autoridad competente toma conocimiento de un hecho irregular. Puede ser de oficio (la autoridad lo ve) o por denuncia de un tercero. Según el Art. 1 de la Ley 19.549, la Administración Pública debe actuar con objetividad y legalidad. El Art. 2 establece que los actos administrativos deben fundarse en hechos ciertos y derecho aplicable. El Art. 3 dispone que toda actuación administrativa debe iniciarse por impulso de parte o de oficio, debiendo quedar constancia escrita en el expediente. La autoridad instructora debe verificar la competencia territorial y material antes de avanzar, conforme al principio de legalidad del Art. 7.",
"base_legal" => "Art. 1, 2, 3 y 7, Ley 19.549 de Procedimientos Administrativos."
],
[
"etapa" => "2. Notificación al Imputado",
"accion" => "Cédula de Notificación",
"descripcion" => "Se debe notificar al agente imputado la existencia del sumario, detallando los cargos en su contra. Es fundamental garantizar el derecho de defensa. El Art. 25 de la Ley 19.549 establece que las notificaciones deben ser personales o por cédula, indicando claramente el acto que se notifica, la autoridad que lo emite, los recursos procedentes y los plazos para interponerlos. El Art. 26 dispone que el imputado debe ser informado de los hechos que se le atribuyen, con precisión temporal y circunstancial, para ejercer adecuadamente su defensa. La notificación defectuosa puede acarrear la nulidad del procedimiento conforme al Art. 14.",
"base_legal" => "Art. 14, 25 y 26, Ley 19.549 (Notificaciones y Derecho de Defensa)."
],
[
"etapa" => "3. Descargo del Imputado",
"accion" => "Presentación de Defensa",
"descripcion" => "El imputado tiene un plazo (generalmente 5 días hábiles) para presentar su descargo escrito, negando los hechos o justificándolos. El Art. 26 de la Ley 19.549 garantiza el derecho de defensa en todo procedimiento administrativo, permitiendo al imputado ofrecer pruebas, formular alegatos y solicitar la producción de actuaciones necesarias. El Art. 27 establece que el plazo para contestar el traslado no podrá ser menor de cinco (5) días hábiles, salvo disposición legal expresa en contrario. Durante este período, el imputado puede solicitar vista de actuaciones, acompañar documentación, proponer testigos y requerir pericias, conforme al principio de bilateralidad procesal.",
"base_legal" => "Art. 26 y 27, Ley 19.549; Principio de Defensa en Juicio (Art. 18 CN).",
],
[
"etapa" => "4. Período de Prueba",
"accion" => "Ofrecimiento y Producción",
"descripcion" => "Tanto la administración como el imputado pueden ofrecer pruebas (documentales, testificales, periciales). El instructor debe analizar si son pertinentes. El Art. 27 de la Ley 19.549 dispone que las partes pueden ofrecer toda clase de pruebas, debiendo el instructor admitir las que sean conducentes, útiles y pertinentes. El Art. 28 regula la producción de la prueba testimonial, estableciendo que los testigos deben ser citados con anticipación y se les debe tomar juramento o promesa de decir verdad. El Art. 29 prevé la prueba pericial cuando se requieran conocimientos especiales, debiendo designarse peritos oficiales o de parte según corresponda. Todas las pruebas deben producirse con contradicción, garantizando el derecho de las partes a presenciarlas y cuestionarlas.",
"base_legal" => "Art. 27, 28, 29 y 30, Ley 19.549; Decreto Reglamentario 1759/72 (T.O. 1991)."
],
[
"etapa" => "5. Vista del Expediente",
"accion" => "Corrimiento de Vista",
"descripcion" => "Una vez cerrada la etapa probatoria, se le da vista al imputado por un plazo breve (usualmente 3 a 5 días) para que tome conocimiento de todo lo actuado y alegue lo que convenga a su derecho. Esta etapa se fundamenta en el Principio de Defensa en Juicio consagrado en el Art. 18 de la Constitución Nacional y desarrollado por el Art. 26 de la Ley 19.549. El Art. 31 establece que, antes de dictar resolución definitiva, debe correrse vista a los interesados por un plazo no menor de tres (3) días para que formulen los alegatos que estimen pertinentes. Esta garantía procesal permite al imputado conocer el estado final del expediente, verificar que todas sus pruebas hayan sido valoradas y ejercer su derecho de última palabra antes de la decisión.",
"base_legal" => "Art. 18 Constitución Nacional; Art. 26 y 31, Ley 19.549."
],
[
"etapa" => "6. Dictamen del Letrado",
"accion" => "Informe Jurídico",
"descripcion" => "El expediente pasa a la Asesoría Letrada o Procuración. Un abogado del estado emite un dictamen opinando si corresponde sanción o sobreseimiento. Conforme al Decreto 1023/2001 (Reglamento de la Ley de Procedimientos Administrativos para la Administración Pública Nacional), el dictamen jurídico es obligatorio en los sumarios que puedan derivar en sanciones graves. El Art. 32 de la Ley 19.549 dispone que los servicios jurídicos permanentes deben emitir opinión sobre la legalidad de los actos administrativos. Este dictamen no es vinculante para la autoridad decisora, pero debe ser considerado fundadamente; su omisión puede generar nulidad por violación al debido proceso adjetivo. El letrado debe analizar la tipicidad de la falta, la existencia de dolo o culpa, las circunstancias atenuantes o agravantes, y la proporcionalidad de la sanción propuesta.",
"base_legal" => "Art. 32, Ley 19.549; Decreto 1023/2001; Dictámenes de la Procuración del Tesoro de la Nación."
],
[
"etapa" => "7. Resolución Final",
"accion" => "Sanción o Sobreseimiento",
"descripcion" => "La autoridad competente dicta la resolución final. Puede ser: Sanción (Apercibimiento, Suspensión, Cesantía, Exoneración) o Sobreseimiento (si no hubo falta). El Art. 33 de la Ley 19.549 establece que todo acto administrativo debe ser fundado, expresando los hechos y antecedentes que lo motivan, así como el derecho aplicable. El Art. 34 exige que la resolución sea clara, precisa y congruente con lo debatido. En materia disciplinaria, el Estatuto del Empleado Público (Ley 11.712 para Nación, o normas provinciales equivalentes) y la Ley 25.188 de Ética Pública establecen el catálogo de faltas y sanciones. La resolución debe notificar expresamente los recursos procedentes, autoridad ante quien se interponen y plazo, conforme al Art. 25. El sobreseimiento procede cuando no se configura la falta, existe causa de justificación, o se extingue la acción disciplinaria por prescripción (Art. 35).",
"base_legal" => "Art. 33, 34 y 35, Ley 19.549; Ley 25.188 (Ética Pública); Estatuto del Empleado Público (Ley 11.712 o normativa provincial/municipal)."
],
[
"etapa" => "8. Recursos Administrativos",
"accion" => "Reconsideración o Jerárquico",
"descripcion" => "Si el agente no está conforme, puede interponer recursos dentro de los 10 días hábiles de notificado. El Art. 84 de la Ley 19.549 regula el Recurso de Reconsideración, que procede contra actos definitivos o que causen gravamen irreparable, y debe fundarse en nueva prueba o error de hecho que resulte de los autos. El Art. 85 establece el Recurso Jerárquico, que se interpone ante la autoridad superior de quien dictó el acto, por cuestiones de derecho o de hecho. El Art. 86 dispone que los recursos deben resolverse en un plazo máximo de 30 días hábiles, vencido el cual se opera el silencio administrativo negativo (Art. 87), habilitando la vía judicial. El Art. 90 permite la interposición subsidiaria de recursos cuando no se indica expresamente el procedente. Contra la resolución administrativa firme, cabe acción judicial ante la Cámara Federal o Tribunales Contencioso-Administrativos provinciales, según la jurisdicción.",
"base_legal" => "Art. 84, 85, 86, 87 y 90, Ley 19.549; Art. 110, Código Procesal Civil y Comercial de la Nación (vía judicial)."
]
];
// Función auxiliar para renderizar HTML
function renderizarPaso($paso) {
echo "<div class='card'>";
echo "<h3>" . htmlspecialchars($paso['etapa']) . "</h3>";
echo "<div class='badge'>" . htmlspecialchars($paso['accion']) . "</div>";
echo "<p>" . htmlspecialchars($paso['descripcion']) . "</p>";
echo "<div class='legal-ref'><strong>Base Legal:</strong> " . htmlspecialchars($paso['base_legal']) . "</div>";
echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $titulo_pagina; ?></title>
<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f9; color: #333; line-height: 1.6; margin: 0; padding: 20px; }
.container { max-width: 900px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
header { border-bottom: 2px solid #0056b3; padding-bottom: 20px; margin-bottom: 30px; }
h1 { color: #0056b3; margin: 0; }
.meta-info { color: #666; font-size: 0.9em; margin-top: 10px; }
.card { border: 1px solid #e0e0e0; border-radius: 6px; padding: 20px; margin-bottom: 20px; background-color: #fff; transition: transform 0.2s; }
.card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-left: 5px solid #0056b3; }
h3 { margin-top: 0; color: #2c3e50; }
.badge { display: inline-block; background-color: #e3f2fd; color: #0d47a1; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; margin-bottom: 10px; }
.legal-ref { margin-top: 15px; font-size: 0.85em; color: #555; background: #f9f9f9; padding: 10px; border-radius: 4px; border-left: 3px solid #ff9800; }
footer { text-align: center; margin-top: 40px; font-size: 0.8em; color: #777; }
</style>
</head>
<body>
<div class="container">
<header>
<h1>Procedimiento de Sumario Administrativo</h1>
<div class="meta-info">Generado el: <?php echo $fecha_actual; ?> | Jurisdicción: República Argentina</div>
<p>Este sistema detalla los pasos procesales basados en la <strong>Ley 19.549 de Procedimientos Administrativos</strong> y normas complementarias.</p>
</header>
<main>
<?php foreach ($proceso_sumario as $paso): ?>
<?php renderizarPaso($paso); ?>
<?php endforeach; ?>
</main>
<footer>
<p>Nota: Esta información es orientativa. Para casos reales, consulte el estatuto del empleado público específico de su jurisdicción (Nación, Provincia o Municipio).</p>
</footer>
</div>
</body>
</html>