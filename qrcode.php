<?php
require_once 'vendor/autoload.php'; // Assuming you are using the BaconQrCode library

use BaconQrCode\Renderer\Image\Png;
use BaconQrCode\Writer;

/**
 * Generates a QR code image for the given data.
 *
 * @param string $data The data to encode in the QR code.
 * @return string The raw PNG binary data of the QR code image.
 */
function generate_qrcode(string $data): string
{
    $renderer = new Png();
    $renderer->setHeight(256); // Set height of the QR code
    $renderer->setWidth(256); // Set width of the QR code

    $writer = new Writer($renderer);

    // Generate the QR code as a binary string
    return $writer->writeString($data);
}

// Example usage (for debugging purposes)
if (isset($_GET['test'])) {
    header('Content-Type: image/png');
    echo generate_qrcode("Example QR Code Data");
    exit;
}