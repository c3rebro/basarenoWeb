<?php
function barcode($code) {
    $bars = array(
        'A' => array(
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101', '4' => '0100011',
            '5' => '0110001', '6' => '0101111', '7' => '0111011', '8' => '0110111', '9' => '0001011'
        ),
        'B' => array(
            '0' => '0100111', '1' => '0110011', '2' => '0011011', '3' => '0100001', '4' => '0011101',
            '5' => '0111001', '6' => '0000101', '7' => '0010001', '8' => '0001001', '9' => '0010111'
        ),
        'C' => array(
            '0' => '1110010', '1' => '1100110', '2' => '1101100', '3' => '1000010', '4' => '1011100',
            '5' => '1001110', '6' => '1010000', '7' => '1000100', '8' => '1001000', '9' => '1110100'
        )
    );
    
    $patterns = array(
        '0' => 'AAAAAA',
        '1' => 'AABABB',
        '2' => 'AABBAB',
        '3' => 'AABBBA',
        '4' => 'ABAABB',
        '5' => 'ABBAAB',
        '6' => 'ABBBAA',
        '7' => 'ABABAB',
        '8' => 'ABABBA',
        '9' => 'ABBABA'
    );

    $first_digit = $code[0];
    $left = substr($code, 1, 6);
    $right = substr($code, 7);
    $pattern = $patterns[$first_digit];

    $barcode = '101'; // Start code
    for ($i = 0; $i < 6; $i++) {
        $barcode .= $bars[$pattern[$i]][$left[$i]];
    }
    $barcode .= '01010'; // Center code
    for ($i = 0; $i < 6; $i++) {
        $barcode .= $bars['C'][$right[$i]]; // Right side always uses pattern C
    }
    $barcode .= '101'; // End code
    return $barcode;
}

function encode($code, $type = 'EAN13', $check_digit = false) {
    if ($type == 'EAN13') {
        if ($check_digit) {
            $code .= calculate_check_digit($code);
        }
        return $code;
    }
    return false;
}

function calculate_check_digit($code) {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += $code[$i] * ($i % 2 == 0 ? 1 : 3);
    }
    return (10 - ($sum % 10)) % 10;
}

function generate_barcode_image($barcode_data, $bar_width = 2, $barcode_height = 100) {
    $padding = 10; // Padding around the barcode

    $barcode_length = strlen($barcode_data);
    $image_width = $barcode_length * $bar_width + 2 * $padding;
    $image_height = $barcode_height + 2 * $padding;

    $image = imagecreate($image_width, $image_height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);

    // Fill the background with white
    imagefill($image, 0, 0, $white);

    // Draw the barcode
    for ($i = 0; $i < $barcode_length; $i++) {
        $color = $barcode_data[$i] == '1' ? $black : $white;
        imagefilledrectangle(
            $image,
            $i * $bar_width + $padding,
            $padding,
            ($i + 1) * $bar_width - 1 + $padding,
            $barcode_height + $padding,
            $color
        );
    }

    // Capture the output
    ob_start();
    imagepng($image);
    $image_data = ob_get_contents();
    ob_end_clean();

    // Clean up
    imagedestroy($image);

    return $image_data;
}
?>