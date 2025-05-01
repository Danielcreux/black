<?php

// Parámetros
$text = $_POST['text'] ?? '';
$eccLevel = strtoupper($_POST['ecc'] ?? 'L'); // L, M, Q, H

if ($text === '' || !in_array($eccLevel, ['L','M','Q','H'])) {
    http_response_code(400);
    exit('Parámetros inválidos');
}

// Configuración por versión 2 (25x25)
$version = 2;
$moduleCount = 25;

// Capacidad de caracteres por ECC
$capacity = [
    'L' => 44,
    'M' => 34,
    'Q' => 27,
    'H' => 17
];

$maxChars = $capacity[$eccLevel];
if (strlen($text) > $maxChars) {
    $text = substr($text, 0, $maxChars);
}

// Codificar el texto en modo Byte
$bits = '0100'; // Byte mode
$bits .= str_pad(decbin(strlen($text)), 8, '0', STR_PAD_LEFT);
foreach (str_split($text) as $char) {
    $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
}

// Añadir terminador
$maxBits = get_max_bits($eccLevel);
$bits .= '0000';
$bits = str_pad($bits, $maxBits, '0');

// Añadir bytes de relleno
$pads = ['11101100', '00010001'];
while (strlen($bits) % 8 !== 0) $bits .= '0';
$i = 0;
while (strlen($bits) < $maxBits) {
    $bits .= $pads[$i % 2];
    $i++;
}

// Convertir bits a bytes
$dataBytes = [];
for ($i = 0; $i < strlen($bits); $i += 8) {
    $dataBytes[] = bindec(substr($bits, $i, 8));
}

// Aplicar ECC
$blocks = create_blocks($dataBytes, $eccLevel);
$finalBytes = interleave_blocks($blocks);

// Crear matriz
$matrix = array_fill(0, $moduleCount, array_fill(0, $moduleCount, null));
add_finder($matrix, 0, 0);
add_finder($matrix, 0, $moduleCount - 7);
add_finder($matrix, $moduleCount - 7, 0);

// Colocar datos
place_data($matrix, $finalBytes);

// Dibujar imagen
header('Content-Type: image/png');
$scale = 10;
$size = $moduleCount * $scale;
$img = imagecreatetruecolor($size, $size);
$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
imagefill($img, 0, 0, $black);

for ($y = 0; $y < $moduleCount; $y++) {
    for ($x = 0; $x < $moduleCount; $x++) {
        if (!empty($matrix[$y][$x])) {
            imagefilledrectangle($img, $x*$scale, $y*$scale, ($x+1)*$scale-1, ($y+1)*$scale-1, $white);
        }
    }
}
imagepng($img);
imagedestroy($img);


// FUNCIONES ==============================================

function get_max_bits($ecc) {
    // Total data bits for version 2
    return [
        'L' => 352,
        'M' => 272,
        'Q' => 208,
        'H' => 144
    ][$ecc];
}

function add_finder(&$m, $r, $c) {
    for ($y = -1; $y <= 7; $y++) {
        for ($x = -1; $x <= 7; $x++) {
            $yy = $r + $y;
            $xx = $c + $x;
            if ($yy < 0 || $yy >= count($m) || $xx < 0 || $xx >= count($m)) continue;
            if (($y == 0 || $y == 6 || $x == 0 || $x == 6) || ($y >= 2 && $y <= 4 && $x >= 2 && $x <= 4)) {
                $m[$yy][$xx] = true;
            } else {
                $m[$yy][$xx] = false;
            }
        }
    }
}

function place_data(&$m, $bytes) {
    $bin = '';
    foreach ($bytes as $b) {
        $bin .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }

    $size = count($m);
    $dir = -1;
    $row = $size - 1;
    $col = $size - 1;
    $i = 0;

    while ($col > 0) {
        if ($col == 6) $col--;
        for ($y = 0; $y < $size; $y++) {
            $r = $row + $dir * $y;
            if ($r < 0 || $r >= $size) continue;

            for ($j = 0; $j < 2; $j++) {
                $c = $col - $j;
                if ($m[$r][$c] !== null) continue;
                $bit = ($i < strlen($bin)) ? $bin[$i++] : '0';
                $m[$r][$c] = ($bit === '1');
            }
        }
        $col -= 2;
        $dir *= -1;
    }
}
function create_blocks($data, $ecc) {
    $ecclen = [
        'L' => 10,
        'M' => 16,
        'Q' => 22,
        'H' => 28
    ][$ecc];

    $poly = generate_rs_poly($ecclen);
    $block = $data;
    $ec = array_fill(0, $ecclen, 0);

    foreach ($block as $b) {
        $f = $b ^ $ec[0];

        // Desplazar todos los elementos una posición hacia la izquierda
        for ($j = 0; $j < $ecclen - 1; $j++) {
            $ec[$j] = $ec[$j + 1];
        }

        // Calcular el nuevo valor final con el polinomio
        $ec[$ecclen - 1] = 0;
        for ($i = 0; $i < $ecclen; $i++) {
            $ec[$i] ^= gf_mul($poly[$i], $f);
        }
    }

    return [['data' => $data, 'ecc' => $ec]];
}

function interleave_blocks($blocks) {
    $res = [];
    $max = max(array_map(fn($b) => count($b['data']), $blocks));
    for ($i = 0; $i < $max; $i++) {
        foreach ($blocks as $b) {
            if ($i < count($b['data'])) $res[] = $b['data'][$i];
        }
    }
    $max = max(array_map(fn($b) => count($b['ecc']), $blocks));
    for ($i = 0; $i < $max; $i++) {
        foreach ($blocks as $b) {
            if ($i < count($b['ecc'])) $res[] = $b['ecc'][$i];
        }
    }
    return $res;
}

function gf_mul($x, $y) {
    static $log = null, $alog = null;
    if (!$log) {
        $alog = $log = [];
        $xv = 1;
        for ($i = 0; $i < 256; $i++) {
            $alog[$i] = $xv;
            $log[$xv] = $i;
            $xv <<= 1;
            if ($xv & 0x100) $xv ^= 0x11d;
        }
    }
    // Asegurarse de que $x y $y estén en el rango válido
    if ($x == 0 || $y == 0) return 0;
    
    $logX = isset($log[$x]) ? $log[$x] : null;
    $logY = isset($log[$y]) ? $log[$y] : null;

    if ($logX === null || $logY === null) {
        return 0;  // Si no está dentro del rango, devolvemos 0
    }

    return $alog[($logX + $logY) % 255];
}

function generate_rs_poly($degree) {
    $poly = [1];
    for ($i = 0; $i < $degree; $i++) {
        $poly = poly_mul($poly, [1, pow(2, $i) % 285]);
    }
    return $poly;
}

function poly_mul($p1, $p2) {
    $res = array_fill(0, count($p1) + count($p2) - 1, 0);
    for ($i = 0; $i < count($p1); $i++) {
        for ($j = 0; $j < count($p2); $j++) {
            $res[$i + $j] ^= gf_mul($p1[$i], $p2[$j]);
        }
    }
    return $res;
}
?>
