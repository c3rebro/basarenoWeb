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

$user_id = $_SESSION['user_id'];

if (!isset($user_id)) {
    header("location: login.php");
    exit();
}

$seller_number = $_SESSION['seller_number'] ?? null;

if (!$seller_number) {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

$total = 0.0;
$commission = 0.0;
$products = [];
$sold_products = []; // Store products in an array
$total_commission = 0.0;
$current_date = date('Y-m-d');
$bazaar_id = 0;

if (!isset($_SESSION['bazaar_id']) || $_SESSION['bazaar_id'] === 0) {

	$bazaar_id = get_current_bazaar_id($conn);
	if ($bazaar_id === 0) {
		$bazaar_id = get_bazaar_id_with_open_registration($conn);
	}
	
	$_SESSION['bazaar_id'] = $bazaar_id; // Fetch bazaar ID only once per session
	
	if (!$_SESSION['bazaar_id'] || $_SESSION['bazaar_id'] === 0) {
		header("location: admin_dashboard.php");
		$conn->close();
		exit();
    }
} else {
	$bazaar_id = $_SESSION['bazaar_id'];
}

// Use prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT * FROM sellers WHERE seller_number=?");
$stmt->bind_param("i", $seller_number);
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
            <h4 class='alert-heading'>Ungültige Verkäufer-Nummer.</h4>
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

if ($result->num_rows == 1) {
    $seller = $result->fetch_assoc();   
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

// Use prepared statement for retrieving the current bazaar settings
$stmt = $conn->prepare("SELECT commission FROM bazaar WHERE id = ?");
$stmt->bind_param("i", $bazaar_id);

if (isset($bazaar_id) && $bazaar_id > 0 && $stmt->execute()) {
	$result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $commission = $row['commission'];

    // Use prepared statement for fetching products
	$stmt = $conn->prepare("SELECT * FROM products WHERE seller_number=? AND in_stock=0");
	$stmt->bind_param("s", $seller_number);
	$stmt->execute();
	$products = $stmt->get_result();

	// Calculate total sales and commission
	while ($product = $products->fetch_assoc()) {
		if ($product['sold']) {
			$total += $product['price'];
			$total_commission += $product['price'] * $commission;
		}
		
		$sold_products[] = $product; // Store each product as an array entry
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

// Round commission only once
$total_commission = round_to_nearest($total_commission, 0.10);
$final_payout = $total - $total_commission;

// ✅ Fetch `user_id` from `sellers` Table
$stmt = $conn->prepare("SELECT user_id FROM sellers WHERE seller_number = ?");
$stmt->bind_param("i", $seller_number);
$stmt->execute();
$result = $stmt->get_result();
$seller = $result->fetch_assoc();

if (!$seller) {
	echo json_encode(['success' => false, 'message' => 'Verkäufer nicht gefunden.']);
	exit;
}

$user_id = $seller['user_id']; // Extract user_id

// ✅ Fetch Email from `user_details` Table
$stmt = $conn->prepare("SELECT email, given_name, family_name, consent FROM user_details WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_details = $result->fetch_assoc();

if (!$user_details) {
	echo json_encode(['success' => false, 'message' => 'E-Mail-Adresse nicht gefunden.']);
	exit;
}

$email = $user_details['email']; // Extract email
$given_name = $user_details['given_name']; // Extract given_name
$family_name = $user_details['family_name']; // Extract family_name
$consent = $user_details['consent']; // Extract family_name

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'checkout_action') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $conn = get_db_connection();

    $total = 0;
    $total_commission = 0;

    $checkout_action = $_POST['checkout_action'] ?? null;

    if (!$seller_number || !$checkout_action) {
        echo json_encode(['success' => false, 'message' => 'Ungültige Daten.']);
        exit;
    }

    // ✅ Mark Seller as Checked Out
    $stmt = $conn->prepare("UPDATE sellers SET checkout=TRUE WHERE seller_number=?");
    $stmt->bind_param("i", $seller_number);
    $stmt->execute();

    // ✅ Fetch Sold Items
    $stmt = $conn->prepare("SELECT name, price FROM products WHERE seller_number=? AND in_stock=0 AND sold=1");
    $stmt->bind_param("i", $seller_number);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($product = $result->fetch_assoc()) {
        $sold_items[] = [
            'name' => $product['name'],
            'price' => round($product['price'], 2)
        ];
        $total += $product['price'];
    }

    // ✅ Calculate commission & Payout
    $stmt = $conn->prepare("SELECT commission FROM bazaar WHERE id = ?");
    $stmt->bind_param("i", $bazaar_id);
    $stmt->execute();
    $bazaar = $stmt->get_result()->fetch_assoc();
    $commission_rate = $bazaar['commission'] ?? 0;

    $total_commission = round($total * $commission_rate, 2);
    $final_payout = round_to_nearest($total - $total_commission, 0.10);

    // ✅ Convert Items to JSON for Storage
    $items_json = json_encode($sold_items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	$items_sold_count = count($sold_items);

    // ✅ Insert or Update Bazaar History Entry
    $stmt = $conn->prepare("
		INSERT INTO bazaar_history (user_id, seller_number, bazaar_id, items, items_sold, payout) 
		VALUES (?, ?, ?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE 
			items = VALUES(items), 
			items_sold = VALUES(items_sold), 
			payout = VALUES(payout),
			created_at = CURRENT_TIMESTAMP
	");
	$stmt->bind_param("iiisid", $user_id, $seller_number, $bazaar_id, $items_json, $items_sold_count, $final_payout);
	$stmt->execute();

	// ✅ Handle Button Actions
	if ($checkout_action === 'notify_seller') {
        $email_body = "<html><body>";
        $email_body .= "<h1>Basar-Abschluss und Abrechnung</h1>";
        $email_body .= "<p>Hallo liebe(r) Verkäufer(in). Der Basar ist beendet und deine Verkäufe wurden erfolgreich abgerechnet. "
                     . "Untenstehend findest Du eine Übersicht über die verkauften Artikel und den Auszahlungsbetrag. Hinweis: Wir Runden auf volle 0,10€."
                     . "Diese E-Mail dient ausschließlich zu Informationszwecken. "
                     . "Darüber hinaus kannst Du dich jederzeit in dein Konto einloggen, um eine PDF-Quittung einzusehen oder herunterzuladen.</p>";
        $email_body .= "<p></p>";

        if(!$consent) {
            $email_body .= "<p>Bitte beachte: Laut unseren Daten hast Du deine Zustimmung zur Datenspeicherung bei der Konto-Erstellung nicht erteilt. "
                        . "Daher <strong>müssen</strong> wir diese Daten mitsamt dem Konto in den nächsten 90 Tagen automatisch löschen. War das ein versehen? Keine Sorge: Es wird vorher " 
                        . "eine Informations-Email mit einem Link versendet, mit dem die Zustimmung nachträglich erteilt, und dieser Löschvorgang verhindert werden kann. Dazu sind wir gesetzlich verpflichtet.</p>";
            $email_body .= "<p></p>";
        }

        $email_body .= "<h3>Übersicht für Verkäufer: Verkäufer Nr: " . htmlspecialchars($seller_number) . "</h3>";
        $email_body .= "<table border='1' cellpadding='10'>";
        $email_body .= "<tr><th>Produktname</th><th>Größe</th><th>Preis</th><th>Verkauft</th></tr>";

		foreach ($products as $product) {
			$sold = $product['sold']; // Sold status (1 = sold, 0 = unsold)
			$size = htmlspecialchars($product['size']);
			$price = number_format($product['price'], 2, ',', '.') . ' €';

			// Apply commission **only if the product was sold**
			$seller_commission = $sold ? $product['price'] * $commission : 0;

			// ✅ Add row to email table
			$email_body .= "<tr>
								<td>" . htmlspecialchars($product['name']) . "</td>
								<td>{$size}</td>
								<td>{$price}</td>
								<td>" . ($sold ? 'Ja' : 'Nein') . "</td>
							</tr>";
		}

		// ✅ Apply rounding to commission and calculate final payout
		$total_commission = round($total_commission, 2);
		$final_payout = round_to_nearest($total - $total_commission, 0.10);

		// ✅ Add the final total row to the email
		$email_body .= "</table>";
		$email_body .= "<h2>Gesamtverkauf: " . number_format($total, 2, ',', '.') . " €</h2>";
		// $email_body .= "<h2>Provision: " . number_format($total_commission, 2, ',', '.') . " €</h2>";
		$email_body .= "<h2>Auszahlungsbetrag: " . number_format($final_payout, 2, ',', '.') . " €</h2>";
		$email_body .= "</body></html>";

		$subject = "Abrechnung für Verkäufernummer: " . htmlspecialchars($seller_number);
		
		if ($total > 0) {
			$email_body .= "<p>Vielen Dank für deine Teilnahme!</p>";
		} else {
			$email_body .= "<p>Es tut uns Leid. Leider wurden keine Artikel verkauft. Wir würden uns trotzdem sehr freuen, dich beim nächsten mal wieder zusehen.</p>";
		}

		$send_result = send_email($email, $subject, $email_body);
		
		if ($send_result === true) {
			echo json_encode(['success' => true, 'message' => 'Checkout erfolgreich und Verkäufer benachrichtigt.']);
		} else {
			echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der E-Mail.']);
		}
		exit;
	}

	if ($checkout_action === 'print_with_commission') {
		echo json_encode(['success' => true, 'message' => 'Abrechnung erfolgreich ausgeführt.', 'print' => true, 'hide_commission' => false]);
		exit;
	}

	if ($checkout_action === 'print_without_commission') {
		echo json_encode(['success' => true, 'message' => 'Abrechnung erfolgreich ausgeführt.', 'print' => true, 'hide_commission' => true]);
		exit;
	}
	
	exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Abrechnung - Verkäufer Nr.: <?php echo htmlspecialchars($seller_number); ?> </title>
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
        <h3 class="mt-5">Abrechnung: <?php echo htmlspecialchars($given_name); ?> <?php echo htmlspecialchars($family_name); ?> (Verk.Nr.: <?php echo htmlspecialchars($seller_number); ?>)</h3>
		<?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show no-print" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        <form action="checkout.php" method="post">
			<input type="hidden" name="seller_number" id="hiddenSellerNumber" value="<?php echo htmlspecialchars($seller_number); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
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
                            $sold_count = 0;
							foreach ($products as $product) {
								$price = number_format($product['price'], 2, ',', '.') . ' €';
								$sold_checked = $product['sold'] ? 'checked' : '';

								// Calculate and round commission for each product
								$seller_commission = $product['sold'] ? round($product['price'] * $commission, 2) : 0;
								$provision = number_format($seller_commission, 2, ',', '.') . ' €';
								$auszahlung = $seller_commission === 0 ? number_format(0, 2, ',', '.') . ' €' : number_format($product['price'] - $seller_commission, 2, ',', '.') . ' €';

                                                // Count sold products
                                if ($product['sold']) {
                                    $sold_count++;
                                }

								echo "<tr>
										<td>" . htmlspecialchars($product['name']) . "</td>
										<td>" . htmlspecialchars($product['size']) . "</td>
										<td>{$price}</td>
										<td><input type='checkbox' name='sold_" . htmlspecialchars($product['id']) . "' $sold_checked disabled></td>
										<td class='provision'>{$provision}</td>
										<td class='provision'>{$auszahlung}</td>
									  </tr>";
							}
                        ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="text-end"><strong>Verkaufte Produkte: <?php echo $sold_count; ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
			<h4 class="provision">Gesamt: <?php echo number_format($total, 2, ',', '.'); ?> €</h4>
			<h4 class="provision">Provision: <?php echo number_format($total_commission, 2, ',', '.'); ?> € (gerundet)</h4>
			<h4>Auszahlungsbetrag: <?php echo number_format($final_payout, 2, ',', '.'); ?> € (abzgl. Provision)</h4>
            <button type="button" class="btn btn-primary btn-block no-print checkout-action" data-action="notify_seller">Abrechnen und Verkäufer benachrichtigen</button>
			<button type="button" id="printWithcommission" class="btn btn-secondary btn-block mt-3 no-print checkout-action" data-action="print_with_commission">Abrechnen und Drucken (mit Provision)</button>
			<button type="button" id="printWithoutcommission" class="btn btn-secondary btn-block mt-3 no-print checkout-action" data-action="print_without_commission">Abrechnen und Drucken (ohne Provision)</button>
        </form>
        <a href="admin_manage_sellers.php" class="btn btn-primary btn-block mt-3 mb-5 no-print">Zurück zu Verkäufer verwalten</a>
    </div>
	
	<!-- Toast Container -->
	<div class="no-print" aria-live="polite" aria-atomic="true">
		<!-- Toasts will be dynamically added here -->
		<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3">
			<!-- Toasts will be dynamically added here -->
		</div>
	</div>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script src="js/utilities.js" nonce="<?php echo $nonce; ?>"></script>
	<script nonce="<?php echo $nonce; ?>">
		document.addEventListener("DOMContentLoaded", function() {
			document.querySelectorAll("button.checkout-action").forEach(button => {
				button.addEventListener("click", function() {
					const checkoutAction = this.getAttribute("data-action");
					const sellerNumber = document.getElementById("hiddenSellerNumber").value;
					const csrfToken = document.querySelector("input[name='csrf_token']").value;

					fetch("checkout.php", {
						method: "POST",
						headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							seller_number: sellerNumber,
							checkout_action: checkoutAction,
							csrf_token: csrfToken
						})
					})
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							showToast("Erfolgreich", data.message, "success");

							if (data.print) {
								if (data.hide_commission) {
									document.body.classList.add("no-print-commission");
								}
								window.print();
								document.body.classList.remove("no-print-commission");
							}
						} else {
							showToast("Fehler", data.message, "danger");
						}
					})
					.catch(error => {
						showToast("Fehler", "Ein Problem ist aufgetreten.", "danger");
						console.error("Checkout Fehler:", error);
					});
				});
			});
		});
	</script>
</body>
</html>