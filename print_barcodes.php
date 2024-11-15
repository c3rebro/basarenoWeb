<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

require_once 'utilities.php';
require_once 'barcode.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors
		
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'seller')) {
    header("location: login.php");
    exit;
}

$seller_id = $_SESSION['seller_id'];
$hash = $_SESSION['seller_hash'];

if (!isset($seller_id) || !isset($hash)) {
        echo "Login fehlgeschlagen.";
        exit();
}

// Use prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT * FROM sellers WHERE id=? AND hash=? AND verified=1");
$stmt->bind_param("ss", $seller_id, $hash);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "
<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
    <title>Verkäufer-ID Verifizierung</title>
    <link href='css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container'>
        <div class='alert alert-warning mt-5'>
            <h4 class='alert-heading'>Ungültige oder nicht verifizierte Verkäufer-ID oder Hash.</h4>
            <p>Bitte überprüfen Sie Ihre Verkäufer-ID und versuchen Sie es erneut.</p>
            <hr>
            <p class='mb-0'>Haben Sie auf den Verifizierungslink in der E-Mail geklickt?</p>
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
$stmt = $conn->prepare("SELECT * FROM products WHERE seller_id=?");
$stmt->bind_param("s", $seller_id);
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
    <link rel="preload" href="css/bootstrap.min.css" as="style" id="bootstrap-css">
    <link rel="preload" href="css/all.min.css" as="style" id="all-css">
    <link rel="preload" href="css/style.css" as="style" id="style-css">
    <noscript>
        <link href="css/bootstrap.min.css" rel="stylesheet">
        <link href="css/all.min.css" rel="stylesheet">
        <link href="css/style.css" rel="stylesheet">
    </noscript>
    <script nonce="<?php echo $nonce; ?>">
        document.getElementById('bootstrap-css').rel = 'stylesheet';
        document.getElementById('all-css').rel = 'stylesheet';
        document.getElementById('style-css').rel = 'stylesheet';
    </script>
	
</head>
<body>
    <div class="container">
        <h1 class="mt-5">Etiketten drucken</h1>
        <table class="barcode-table">
            <tbody>
                <?php
                $counter = 0;
                while ($product = $result->fetch_assoc()):
                    if ($counter % 2 == 0) {
                        echo "<tr>";
                    }

                    // Generate the barcode
                    $barcode_data = encode($product['barcode'], 'EAN13', true);
                    $barcode_image = generate_barcode_image(barcode($barcode_data), 3, 50);

                    if (DEBUG) {
                        debug_log("Generating barcode for product: " . $product['name']);
                        debug_log("Barcode data: " . $barcode_data);
                        debug_log("Barcode image length: " . strlen($barcode_image));
                    }

                    $barcode_base64 = base64_encode($barcode_image);
                ?>
                    <td>
                        <div class="product-name">
                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            <?php if (!empty(trim($product['size']))): ?>
                                <span class="product-size">Größe: <?php echo htmlspecialchars($product['size']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="seller-price-container">
                            <div class="seller-id">Verkäufer: <strong><?php echo htmlspecialchars($seller_id); ?></strong></div>
                            <div class="price">Preis: <?php echo number_format($product['price'], 2, ',', '.'); ?> €</div>
                        </div>
                        <div class="barcode-container">
                            <img src="data:image/png;base64,<?php echo $barcode_base64; ?>" alt="Barcode"><br>
                            <div class="barcode-digits">
                                <?php echo htmlspecialchars($product['barcode']); ?> - 
                                <span class="checkout-id"><?php echo htmlspecialchars($checkout_id); ?></span>
                            </div>
                        </div>
                    </td>
                <?php
                    $counter++;
                    if ($counter % 2 == 0) {
                        echo "</tr>";
                    }
                endwhile;
                if ($counter % 2 != 0) {
                    echo "<td></td></tr>"; // Add an empty cell if the number of products is odd
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