<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true, // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

require_once 'utilities.php';
//require_once 'barcode.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'seller')) {
    header("location: login.php");
    exit;
}

$seller_number = $_POST['seller_number'] ?? null;
$user_id = $_SESSION['user_id'];

if (!isset($seller_number) || !isset($user_id)) {
    echo "Login fehlgeschlagen.";
    exit();
}

// Use prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT * FROM sellers WHERE seller_number=? AND user_id=? AND seller_verified=1");
$stmt->bind_param("ii", $seller_number, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "
<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
    <title>Verkäufernummer Verifizierung</title>
    <link href='css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container'>
        <div class='alert alert-warning mt-5'>
            <h4 class='alert-heading'>Ungültige oder nicht verifizierte Verkäufer-Nummer.</h4>
            <p>Bitte überprüfe Deine Verkäufernummer und versuche es erneut.</p>
            <hr>
            <p class='mb-0'>Verifizierungslink in der E-Mail angeklickt?</p>
        </div>
    </div>
    <script src='js/jquery-3.7.1.min.js'></script>
    <script src='js/popper.min.js'></script>
    <script src='js/bootstrap.min.js'></script>
</body>
</html>
";
    exit();
}

// Fetch the seller to get the checkout_id
$seller = $result->fetch_assoc();
$checkout_id = $seller['checkout_id'];

// Use prepared statement for fetching products
$stmt = $conn->prepare("SELECT * FROM products WHERE seller_number=? AND in_stock=0");
$stmt->bind_param("s", $seller_number);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "Keine Artikel gefunden.";
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Etiketten drucken</title>
        <!-- Preload and link CSS files -->
		<link rel="preload" href="css/style.css" as="style" id="style-css">
        <link rel="preload" href="css/bootstrap.min.css" as="style" id="bootstrap-css">
        <link rel="preload" href="css/all.min.css" as="style" id="all-css">
        <noscript>
			<link href="css/style.css" rel="stylesheet">
			<link href="css/bootstrap.min.css" rel="stylesheet">
			<link href="css/all.min.css" rel="stylesheet">
        </noscript>
        <script nonce="<?php echo $nonce; ?>">
			document.getElementById('style-css').rel = 'stylesheet';
            document.getElementById('bootstrap-css').rel = 'stylesheet';
            document.getElementById('all-css').rel = 'stylesheet';
        </script>
        <script src="js/qrcode.min.js" nonce="<?php echo $nonce; ?>"></script>


    </head>
    <body>
	    <!-- Cover Page for Seller Number (Ensures Full-Page Print) -->
		<div class="hidden cover-page-hint"><p>Die Verkäufernummer bitte gut sichtbar am Korb anbringen.</p></div>
		<div class="text-center hidden cover-page">
			<?php echo sprintf("%03d", $seller_number); ?> <!-- Ensures always 3 digits -->
		</div>
		<!-- Page Break Before Price Tags -->
<div style="page-break-before: always;"></div>
		
        <div class="container">		

            <h1 class="mt-5 no-print">Etiketten drucken</h1>
            <table class="outer-table">
                <tbody>
                    <?php
                    $counter = 0;
                    while ($product = $result->fetch_assoc()):
                        // Start a new row every 3 products
                        if ($counter % 3 == 0) {
                            echo "<tr>";
                        }
                        ?>
                    <td>
                        <table class="barcode-table" style="width: 100%; table-layout: fixed;">
                            <tr>
                                <!-- Row 1: Product Name -->
                                <td colspan="2" style="font-weight: bold; padding: 4px;">
                                    <p><?php echo htmlspecialchars($product['name']); ?></p>
                                </td>
                                <td style="height: 20px; text-align: right; position: relative;">
                                    <!-- Inline SVG for hole puncher -->
                                    <svg width="30" height="30" style="position: absolute; top: 0; right: 0; margin-right: 15px; margin-top: 15px;">
                                    <circle cx="16" cy="16" r="12" stroke="black" stroke-width="2" fill="none" />
                                    </svg>
                                </td>
                            </tr>
                            <tr>
                                <!-- Row 2: QR Code -->
                                <td rowspan="3" style="text-align: center; vertical-align: middle; padding: 1px;">
                                    <div>
                                        <canvas id="qrcode-<?php echo htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8'); ?>" style="display: block; margin-left: -10px; position: relative; z-index: -1;">t</canvas>
                                    </div>
                                    <script nonce="<?php echo $nonce; ?>">
                                document.addEventListener("DOMContentLoaded", function () {
                                    const canvas = document.getElementById("qrcode-<?php echo htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8'); ?>");
                                    QRCode.toCanvas(canvas, "<?php echo htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8'); ?>", {
                                        width: 128,
                                        height: 128
                                    }, function (error) {
                                        if (error)
                                            console.error("QR code generation failed for barcode: <?php echo htmlspecialchars($product['barcode']); ?>", error);
                                    });
                                });
                                    </script>
                                </td>
                                <?php if (!empty($product['size'])): ?>
                                    <td style="vertical-align:bottom">
                                        Größe: <?php echo htmlspecialchars($product['size']); ?>
                                    </td>
                                <?php else: ?>
                                    <td ></td> <!-- Empty cell if size is not available -->
                                <?php endif; ?>
                                <td></td>
                            </tr>
                            <tr>
                                <!-- Row 3: Price -->
                                <td colspan="2">
                                    <span>Preis: <span class="price"><?php echo number_format($product['price'], 2, ',', '.'); ?> €</span></span>
                                </td>
                            </tr>
                            <tr>
                                <!-- Row 4: Seller and ID -->
                                <td style="vertical-align:top">
                                    <span>Verk.Nr.: <span class="seller-nr text-danger"><?php echo htmlspecialchars($seller_number); ?></span></span>
                                </td>
                                <td class="product-number">
                                    <?php echo htmlspecialchars($product['product_id']); ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <?php
                    $counter++;
                    // Close the row after 3 products
                    if ($counter % 3 == 0) {
                        echo "</tr>";
                    }
                endwhile;
                // Fill the remaining cells in the last row if products are not a multiple of 3
                if ($counter % 3 != 0) {
                    $remaining = 3 - ($counter % 3);
                    for ($i = 0; $i < $remaining; $i++) {
                        echo "<td style='border: 1px solid black;'></td>";
                    }
                    echo "</tr>";
                }
                ?>
                </tbody>
            </table>

            <button onclick="window.print()" class="btn btn-primary mt-3 no-print">Drucken</button>

        </div>
        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
    </body>
</html>