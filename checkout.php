<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

$seller_number = $_SESSION['seller_number'];

if (!isset($seller_number)) {
    echo "Ungültige Daten.";
    exit();
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Use prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT * FROM sellers WHERE seller_number=?");
$stmt->bind_param("s", $seller_number);
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
            <h4 class='alert-heading'>Ungültige Verkäufer-ID oder Hash.</h4>
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

if ($result->num_rows > 0) {
    $seller = $result->fetch_assoc();
    $checkout_id = $seller['checkout_id'];    
}

if ($seller['seller_verified'] != 1) {
    echo "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
            <title>Unverifizierter Verkäufer</title>
            <link href='css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container'>
                <div class='alert alert-danger mt-5'>
                    <h4 class='alert-heading'>Unverifizierte Verkäufer können nicht abgerechnet werden.</h4>
                    <p>Bitte verifizieren Sie Ihren Account, um fortzufahren.</p>
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

// Use prepared statement for fetching products
$stmt = $conn->prepare("SELECT * FROM products WHERE seller_number=? AND in_stock=0");
$stmt->bind_param("s", $seller_number);
$stmt->execute();
$products_result = $stmt->get_result();

$total = 0.0;
$total_brokerage = 0.0;
$brokerage = 0.0;
$current_date = date('Y-m-d');
$bazaar_id = get_current_bazaar_id($conn) === 0 ? get_bazaar_id_with_open_registration($conn) : null;

// Use prepared statement for retrieving the current bazaar settings
$stmt = $conn->prepare("SELECT brokerage, price_stepping FROM bazaar WHERE id = ?");
$stmt->bind_param("s", $bazaar_id);
	
if (isset($bazaar_id) && $bazaar_id > 0 && $stmt->execute()) {
	$result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $brokerage = $row['brokerage'];
    $price_stepping = $row['price_stepping'];

    // Use prepared statement for updating seller checkout
    $stmt = $conn->prepare("UPDATE sellers SET checkout=TRUE WHERE seller_number=?");
    $stmt->bind_param("s", $seller_number);
    if ($stmt->execute()) {
		$message_type = 'success';
        $message = "Verkäufer erfolgreich ausgecheckt.";
        debug_log("Seller checked out: ID=$seller_number");
    } else {
		$message_type = 'danger';
        $message = "Fehler beim Auschecken des Verkäufers: " . $conn->error;
        debug_log("Error checking out seller: " . $conn->error);
    }
} else {
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
            <div class='alert alert-warning mt-5 alert-dismissible fade show' role='alert'>
                <h4 class='alert-heading'>Hinweis:</h4>
                <p>Es wurde kein Bazaar gefunden, der abgerechnet werden kann.<br>Läuft der aktuelle Basar eventuell noch? (siehe Startdatum)</p>
            </div>
        </div>
        <script src='js/jquery-3.7.1.min.js'></script>
        <script src='js/popper.min.js'></script>
        <script src='js/bootstrap.bundle.min.js'></script>
    </body>
    </html>
    ";
    exit;
}

// Function to round to nearest specified increment
function round_to_nearest($value, $increment) {
    return round($value / $increment) * $increment;
}

$conn->close();

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'notify_seller') !== null) {
    $total = 0;
    $total_brokerage = 0;
    $email_body = "<html><body>";
    $email_body .= "<h1>Checkout für Verkäufer: " . htmlspecialchars($seller['given_name']) . " " . htmlspecialchars($seller['family_name']) . " (VerkäuferNr: " . htmlspecialchars($seller['id']) . ")</h1>";
    $email_body .= "<table border='1' cellpadding='10'>";
    $email_body .= "<tr><th>Produktname</th><th>Größe</th><th>Preis</th><th>Verkauft</th></tr>";
    
    $products_result->data_seek(0); // Reset the result pointer to the beginning
    while ($product = $products_result->fetch_assoc()) {
        $sold = isset($_POST['sold_' . $product['id']]) ? 1 : 0;
        $size = htmlspecialchars($product['size']);
        $price = number_format($product['price'], 2, ',', '.') . ' €';
        $seller_brokerage = $sold ? $product['price'] * $brokerage : 0;
        $email_body .= "<tr><td>" . htmlspecialchars($product['name']) . "</td><td>{$size}</td><td>{$price}</td><td>" . ($sold ? 'Ja' : 'Nein') . "</td></tr>";
        if ($sold) {
            $total += $product['price'];
            $total_brokerage += $seller_brokerage;
        }
    }
    $email_body .= "</table>";
    $email_body .= "<h2>Auszahlungsbetrag: " . number_format($total - $total_brokerage, 2, ',', '.') . " €</h2>";
    $email_body .= "</body></html>";

    $subject = "Checkout für Verkäufer: " . htmlspecialchars($seller['given_name']) . " " . htmlspecialchars($seller['family_name']) . " (Verkäufernummer: " . htmlspecialchars($seller['id']) . ")";
    if ($total > 0) {
        $email_body .= "<p>Vielen Dank für Ihre Unterstützung!</p>";
    } else {
        $email_body .= "<p>Leider wurden keine Artikel verkauft. Wir hoffen auf das Beste beim nächsten Mal.</p>";
    }

    $send_result = send_email($seller['email'], $subject, $email_body);
    if ($send_result === true) {
		$message_type = 'success';
        $message = "E-Mail erfolgreich an den Verkäufer gesendet.";
    } else {
		$message_type = 'danger';		
        $message = "Fehler beim Senden der E-Mail: $send_result";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Checkout - Verkäufer: <?php echo htmlspecialchars($seller['given_name']); ?> <?php echo htmlspecialchars($seller['family_name']); ?> (Verkäufer Nr.: <?php echo htmlspecialchars($seller['id']); ?>) {<?php echo htmlspecialchars($seller['checkout_id']); ?>}</title>
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
	<!-- Navbar -->
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
	
    <div class="container">	
        <h3 class="mt-5">Checkout (Verk.Nr.: <?php echo htmlspecialchars($seller['seller_number']); ?>): <?php echo htmlspecialchars($seller['given_name']); ?> <?php echo htmlspecialchars($seller['family_name']); ?></h3>
		<?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show no-print" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        <form action="checkout.php?seller_number=<?php echo htmlspecialchars($seller_number); ?>" method="post">
            <div class="table-responsive">
                <table class="table table-bordered mt-3">
                    <thead>
                        <tr>
                            <th>Produktname</th>
                            <th>Größe</th>
                            <th>Preis</th>
                            <th>Verkauft</th>
                            <th class="provision">Provision</th>
                            <th class="provision">Auszahlung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
							while ($product = $products_result->fetch_assoc()) {
								$price = number_format($product['price'], 2, ',', '.') . ' €';
								$sold_checked = $product['sold'] ? 'checked' : '';

								// Calculate and round brokerage for each product
								$seller_brokerage = $product['sold'] ? round($product['price'] * $brokerage, 2) : 0;
								$provision = number_format($seller_brokerage, 2, ',', '.') . ' €';
								$auszahlung = $seller_brokerage === 0 ? number_format(0, 2, ',', '.') . ' €' : number_format($product['price'] - $seller_brokerage, 2, ',', '.') . ' €';

								echo "<tr>
										<td>" . htmlspecialchars($product['name']) . "</td>
										<td>" . htmlspecialchars($product['size']) . "</td>
										<td>{$price}</td>
										<td><input type='checkbox' name='sold_" . htmlspecialchars($product['id']) . "' $sold_checked></td>
										<td class='provision'>{$provision}</td>
										<td class='provision'>{$auszahlung}</td>
									  </tr>";

								if ($product['sold']) {
									$total += $product['price'];
									$total_brokerage += $seller_brokerage;
								}
							}
                        ?>
                    </tbody>
                </table>
            </div>
			<h4 class="provision">Gesamt: <?php echo number_format($total, 2, ',', '.'); ?> €</h4>
			<h4 class="provision">Provision: <?php echo number_format($total_brokerage, 2, ',', '.'); ?> € (gerundet)</h4>
			<h4>Auszahlungsbetrag: <?php echo number_format($total - $total_brokerage, 2, ',', '.'); ?> € (abzgl. Provision)</h4>
            <button type="submit" class="btn btn-primary btn-block no-print" name="notify_seller">Verkäufer benachrichtigen</button>
        </form>
        <button id="printWithBrokerage" class="btn btn-secondary btn-block mt-3 no-print">Drucken (mit Provision)</button>
        <button id="printWithoutBrokerage" class="btn btn-secondary btn-block mt-3 no-print">Drucken (ohne Provision)</button>
        <a href="admin_manage_sellers.php" class="btn btn-primary btn-block mt-3 mb-5 no-print">Zurück zu Verkäufer verwalten</a>
    </div>
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script nonce="<?php echo $nonce; ?>">
		document.getElementById('printWithBrokerage').addEventListener('click', function() {
			window.print();
		});

		document.getElementById('printWithoutBrokerage').addEventListener('click', function() {
			document.body.classList.add('no-print-brokerage');
			window.print();
			document.body.classList.remove('no-print-brokerage');
		});
	</script>
</body>
</html>