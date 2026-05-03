<?php
session_start();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: image/png');

// Generar código de 5 caracteres
$characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$length = 5;
$captcha = '';

for ($i = 0; $i < $length; $i++) {
    $captcha .= $characters[random_int(0, strlen($characters) - 1)];
}

// Guardar en sesión (en mayúsculas)
$_SESSION['captcha_code'] = strtoupper($captcha);

// Crear imagen
$width = 120;
$height = 45;
$image = imagecreatetruecolor($width, $height);

// Colores
$bg_color = imagecolorallocate($image, 245, 245, 245);
$text_color = imagecolorallocate($image, 30, 60, 114);
$noise_color = imagecolorallocate($image, 180, 180, 180);

// Fondo
imagefilledrectangle($image, 0, 0, $width, $height, $bg_color);

// Ruido (líneas)
for ($i = 0; $i < 4; $i++) {
    imageline($image, 0, rand() % $height, $width, rand() % $height, $noise_color);
}

// Ruido (puntos)
for ($i = 0; $i < 30; $i++) {
    imagesetpixel($image, rand() % $width, rand() % $height, $noise_color);
}

// Texto
$font_size = 5;
$char_width = imagefontwidth($font_size);
$total_width = $char_width * strlen($captcha);
$start_x = ($width - $total_width) / 2;

for ($i = 0; $i < strlen($captcha); $i++) {
    $x = $start_x + ($i * $char_width) + rand(-1, 1);
    $y = 12 + rand(-2, 2);
    imagestring($image, $font_size, $x, $y, $captcha[$i], $text_color);
}

imagepng($image);
imagedestroy($image);
?>