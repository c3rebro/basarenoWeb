<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require_once 'utilities.php';

// Content Security Policy with Nonce
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self';");

// Redirect without valid login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['seller', 'assistant', 'cashier', 'admin'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$conn = get_db_connection();

// Verify
$sql = "SELECT user_verified FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_verified = $stmt->get_result()->fetch_assoc()['user_verified'];
$bazaar_id = 0;
$upcoming_bazaar = 0;

if (!$user_verified) {
    header("Location: index.php");
    exit;
}

$status = $_GET['status'] ?? null;

// Read query flags
$error_key = filter_input(INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

// Map error codes to messages
$error_messages = [
    'bazaarNotFound'     => 'Es ist derzeit kein aktiver Basar geöffnet.',
    'sellerNotFound'     => 'Keine Verkäufernummer für dein Konto gefunden.',
    'sellerNotActivated' => 'Du hast noch keine Verkäufernummer freigeschaltet. Bitte schalte sie hier zunächst frei ("Deine Verkäufernummer(n) verwalten"), um Artikel anlegen/bearbeiten und Etiketten drucken zu können.',
];

// Only fetch once per session.
// Reuse the shared utility to avoid duplicating bazaar-selection logic here.
if (!isset($_SESSION['bazaar_id']) || $_SESSION['bazaar_id'] === 0) {
    $_SESSION['bazaar_id'] = (int)get_active_or_registering_bazaar_id($conn);
}

// Fetch the same bazaar used for activation flow so the displayed seller count matches.
$selected_bazaar_id = (int)($_SESSION['bazaar_id'] ?? 0);
$sql = "SELECT 
            b.id AS bazaar_id,
            b.start_date,
            b.start_req_date,
            b.max_sellers,
            b.max_items_per_seller,
            b.commission,
            (SELECT COUNT(*) 
             FROM sellers s 
             WHERE s.bazaar_id = b.id AND s.seller_verified = 1) AS current_sellers
        FROM bazaar b
        WHERE b.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $selected_bazaar_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $upcoming_bazaar = $result->fetch_assoc();
}

$max_items_per_seller = $upcoming_bazaar['max_items_per_seller'] ?? null;
// ✅ Determine slot availability separately
$slots_available = ($upcoming_bazaar['current_sellers'] ?? 0) < ($upcoming_bazaar['max_sellers'] ?? 0);

// Fetch past bazaars for the current seller
$sql = "SELECT 
            bh.id, 
            bh.seller_number, 
            bh.bazaar_id, 
            bh.items_sold,
            bh.items,
            bh.payout,
            b.start_date AS bazaar_date 
        FROM bazaar_history bh
        JOIN bazaar b ON bh.bazaar_id = b.id
        WHERE bh.user_id = ? 
        ORDER BY b.start_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$past_bazaars = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all seller numbers associated with the user
$sql = "SELECT seller_number, seller_verified FROM sellers WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$sellers_result = $stmt->get_result();
$sellers = $sellers_result->fetch_all(MYSQLI_ASSOC);

// Extract seller numbers for the next query
$seller_numbers = array_column($sellers, 'seller_number');

if (!empty($seller_numbers)) {
    $placeholders = implode(',', array_fill(0, count($seller_numbers), '?')); // Create `?, ?, ?` placeholders for IN()
    
    // Fetch all products associated with these seller numbers
    $sql = "SELECT seller_number, name, size, price, in_stock, sold 
            FROM products 
            WHERE seller_number IN ($placeholders)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($seller_numbers)), ...$seller_numbers);
    $stmt->execute();
    $products_result = $stmt->get_result();
    $products = $products_result->fetch_all(MYSQLI_ASSOC);
} else {
    $products = [];
}

// Verkäufernummer anfordern
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'Aha erwischt. CSRF token mismatch.']);
        exit;
    }

    $user_id = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? '';
    $bazaar_id = (int)$_SESSION['bazaar_id'];
    $helper_request = filter_input(INPUT_POST, 'helper_request') !== null && $_POST['helper_request'] == '1';
    $helper_message = htmlspecialchars($_POST['helper_message'] ?? '', ENT_QUOTES, 'UTF-8');
	$helper_options = filter_input(INPUT_POST, 'helper_options') !== null ? htmlspecialchars($_POST['helper_options'], ENT_QUOTES, 'UTF-8') : 'Keine Auswahl';

    $conn = get_db_connection();

    if (!$bazaar_id) {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Anfordern einer Verkäufernummer. Bitte versuche es noch einmal.']);
        log_action($conn, $user_id, "Fehler beim Anfordern einer Verkäufernummer", "Benutzerdaten: username=$username, user_id=$user_id, bazaar_id=$bazaar_id");
        exit;
    }

    // Check if the user already has a seller number
    $sql = "SELECT seller_number FROM sellers WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc() && !$helper_request) {
        log_action($conn, $user_id, "Zweitnummernanfrage verhindert", "Benutzerdaten: username=$username, user_id=$user_id, bazaar_id=$bazaar_id");
        echo json_encode(['success' => false, 'message' => 'Du hast schon eine aktive Verkäufernummer. Möchtest Du dich als Helfer registrieren und eine 2. Nummer anfragen?', 'require_helper_confirmation' => true]);        
        exit;
    }

    // Check for unassigned seller number
    $sql = "SELECT seller_number FROM sellers WHERE user_id = 0 ORDER BY seller_number ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $unassigned_number = $stmt->get_result()->fetch_assoc();

	if ($unassigned_number) {
		// Assign unassigned seller number
		$seller_number = $unassigned_number['seller_number'];
		$sql = "UPDATE sellers SET user_id = ?, bazaar_id = ?, seller_verified = 1 WHERE seller_number = ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("iii", $user_id, $bazaar_id, $seller_number);
	} else {
		// Create a new seller number
		$sql = "SELECT MAX(seller_number) + 1 AS next_number FROM sellers";
		$stmt = $conn->prepare($sql);
		$stmt->execute();
		$next_number = $stmt->get_result()->fetch_assoc()['next_number'] ?? 100;

		$sql = "SELECT MAX(checkout_id) + 1 AS next_checkout_id FROM sellers";
		$stmt = $conn->prepare($sql);
		$stmt->execute();
		$next_checkout_id = $stmt->get_result()->fetch_assoc()['next_checkout_id'] ?? 1;

		$seller_number = $next_number;
		$sql = "INSERT INTO sellers (user_id, bazaar_id, seller_number, checkout_id, seller_verified) VALUES (?, ?, ?, ?, 1)";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("iiii", $user_id, $bazaar_id, $seller_number, $next_checkout_id);
	}

    if ($stmt->execute()) {
        // Send email if helper request is checked
        if ($helper_request) {
            $to = "borga@basar-horrheim.de";
            $subject = "Neuer Zweitnummerantrag / Helferanfrage";
            $body = "Ein Nutzer (" . htmlspecialchars($username) . ") hat eine Verkäufernummer mit Zusatznummer beantragt.<br>";
            $body .= "Verkäufernummer: $seller_number<br>";
            $body .= "Benutzer-ID: $user_id<br>";
            $body .= "Bazaar-ID: $bazaar_id<br>";
            $body .= "Helfer-Anfrage: Ja<br>";
			$body .= "Ausgewählte Helferoptionen: " . nl2br($helper_options) . "<br>";
            $body .= "Nachricht: " . nl2br($helper_message) . "<br>";
            send_email($to, $subject, $body);
        }

        // Fetch updated seller numbers
        $sql = "SELECT seller_number, seller_verified FROM sellers WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $updated_sellers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if($helper_request) {
            log_action($conn, $user_id, "Eine neue Zweit-Verkäufernummer wurde angefragt.", "Benutzerdaten: username=$username, user_id=$user_id, bazaar_id=$bazaar_id");
            echo json_encode([
                'success' => true,
                'message' => 'Zusätzliche Nummer erfolreich beantragt. Wir informieren dich per Mail. Im Menü unter "Meine Artikel" gehts weiter...',
                'data' => $updated_sellers,
            ]);
        } else {
            log_action($conn, $user_id, "Neue Verkäufernummer zugewiesen.", "Benutzerdaten: username=$username, user_id=$user_id, bazaar_id=$bazaar_id");
            echo json_encode([
                'success' => true,
                'message' => 'Verkäufernummer erfolreich zugewiesen. Im Menü unter "Meine Artikel" gehts weiter...',
                'data' => $updated_sellers,
            ]);
        }
        
        $_SESSION['bazaar_id'] = 0;
                
        exit;
    } else {
        log_action($conn, $user_id, "Fehler beim Anfordern einer Verkäufernummer", "Benutzerdaten: username=$username, user_id=$user_id, bazaar_id=$bazaar_id");
        echo json_encode(['success' => false, 'message' => 'Verkäufernummer konnte nicht zugewiesen werden. Bitte informiere uns per Mail.']);
        exit;
    }
}

?>


<!DOCTYPE html>
<html lang="de">
    <head>
        <style nonce="<?php echo $nonce; ?>">
            html {
                visibility: hidden;
            }
        </style>
        <meta charset="UTF-8">
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Verkäufer-Dashboard</title>
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
        <script nonce="<?php echo $nonce; ?>">
            const upcomingBazaar = <?php echo json_encode($upcoming_bazaar ?? null); ?>;
			const sellers = <?php echo json_encode($sellers); ?>;
			const products = <?php echo json_encode($products); ?>;
        </script>
        <script src="js/jspdf.umd.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/html2canvas.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/jspdf.plugin.autotable.min.js" nonce="<?php echo $nonce; ?>"></script>

    </head>
    <body>
	
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
        
        <div class="container">
            <h1>Verkäufer-Dashboard</h1>
            <hr/>
            <h6 class="card-subtitle mb-4 text-muted">
                Verwalte hier deine Verkäufernummer(n). Du kannst eine Neue anfordern, Vorhandene freischalten (beim nächsten Basar) oder sie zurückgeben, wenn du nicht mehr teilnehmen möchtest.
            </h6>
                    <?php if ($error_key && isset($error_messages[$error_key])): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                <?php echo htmlspecialchars($error_messages[$error_key], ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Schließen">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
            <!-- Available Bazaars Section -->
            <div class="card mb-4">
                <div class="card-header">Verfügbarer Basar</div>
                <div class="card-body">
                    <?php if (!empty($upcoming_bazaar)): ?>
                        <div class="table-responsive-sm">
                            <table class="table table-bordered table-striped w-100">
                                <thead>
                                    <tr>
                                        <th>Datum Basar</th>
                                        <th>Start Nr. Vergabe</th>
                                        <th>Maximale Verkäufer</th>
                                        <th>Aktuelle Verkäufer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo htmlspecialchars(DateTime::createFromFormat('Y-m-d', $upcoming_bazaar['start_date'])->format('d.m.Y')); ?></td>
                                        <td><?php echo htmlspecialchars(DateTime::createFromFormat('Y-m-d', $upcoming_bazaar['start_req_date'])->format('d.m.Y')); ?></td>
                                        <td><?php echo htmlspecialchars($upcoming_bazaar['max_sellers']); ?></td>
                                        <td><?php echo htmlspecialchars($upcoming_bazaar['current_sellers']); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <form id="requestSellerNumberForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="request_seller_number" value="1">
                            <input type="hidden" id="currentSellerCount" value="<?php echo count($sellers); ?>">

                            <div class="mt-3">
                                <div id="helperRequestStatus">
                                    <?php
                                    $seller_helper_request = $_COOKIE['seller_helper_request'] ?? null;
                                    $stored_seller_count = $_COOKIE['seller_helper_request_count'] ?? null;
                                    $current_seller_count = count($sellers); // Count from the database

                                    $helper_request_pending = ($seller_helper_request && $current_seller_count == $stored_seller_count);
                                    $helper_request_done = ($seller_helper_request && $current_seller_count > $stored_seller_count);
                                    ?>

                                    <?php if ($helper_request_pending): ?>
                                        <div id="helperRequestAlert" class="alert alert-info">
                                            Dein Antrag auf eine zusätzliche Verkäufernummer wird bearbeitet. Du wirst benachrichtigt, sobald er genehmigt wurde.
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($helper_request_done): ?>
                                        <div id="helperRequestDoneAlert" class="alert alert-success">
                                            Dein Antrag wurde genehmigt! Du hast jetzt eine zusätzliche Verkäufernummer.
                                        </div>
                                        <script>
                                            document.addEventListener("DOMContentLoaded", function () {
                                                setTimeout(function () {
                                                    removeCookie("seller_helper_request");
                                                    removeCookie("seller_helper_request_count");
                                                    $('#helperRequestAlert').remove(); // ✅ Remove pending message if it exists
                                                }, 3000);
                                            });
                                        </script>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Helper Request Checkbox -->
                            <div class="form-check d-none">
                                <input class="form-check-input" type="checkbox" id="helperRequest" name="helper_request">
                                <label class="form-check-label" for="helperRequest">
                                    Ich möchte mich als Helfer*In eintragen lassen und eine zusätzliche Nummer erhalten.
                                </label>
                            </div>

                            <!-- Helper Options -->
                            <div class="form-group mt-3 hidden" id="helperOptionsContainer">
                                <p class="text-muted">
                                    Wir bitten um Verständnis dafür, dass wir unseren Teilnehmenden, die noch keine Nummer haben, bevorzugt Nummern vergeben werden.
                                </p>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="option1" name="helper_options[]" value="Ich bringe Kuchen mit">
                                    <label class="form-check-label" for="option1">Ich bringe Kuchen</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="option2" name="helper_options[]" value="Ich helfe beim Rücksortieren">
                                    <label class="form-check-label" for="option2">Ich helfe beim Rücksortieren (15:45Uhr - 18:00Uhr)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="option3" name="helper_options[]" value="Ich helfe bei der Aufsicht">
                                    <label class="form-check-label" for="option3">Ich helfe bei der Aufsicht (12:45Uhr - 15:30Uhr)</label>
                                </div>
                            </div>

                            <!-- Optional Message Textarea -->
                            <div class="form-group mt-3 hidden" id="helperMessageContainer">
                                <label for="helperMessage">Das finden wir super! 😊</label>
                                <textarea class="form-control" id="helperMessage" name="helper_message" rows="3" placeholder="Optional: Beschreibe kurz, wie Du uns unterstützen möchtest."></textarea>
                            </div>

                            <p id="sellerRequestInfoMessage" class="hidden text-muted">
                                Die Verkäufernummeranfrage ist geschlossen. Wir freuen uns darauf, dich beim nächsten Basar wieder zu sehen.
                            </p>
                            <!-- Submit Button -->
                            <div class="row">
                                <div class="col">
                                    <button type="submit" class="btn btn-primary w-100 mt-3" id="requestSellerButton" <?php echo (($helper_request_pending || empty($_SESSION['bazaar_id']) || true) ? 'disabled' : ''); ?>>
                                        Verkäufernummer anfordern
                                    </button>
                                    <?php 
                                        if (empty($_SESSION['bazaar_id'])) {
                                            echo '<p class="text-muted">Die Registrierung ist derzeit nicht möglich. Bitte versuche es später erneut.</p>';
                                        }
                                    ?>
                                </div>
                            </div>
                        </form>

                    <?php else: ?>
                        <p>Keine Basare derzeit verfügbar.</p>
                    <?php endif; ?>
                    <span id="stockSection"/>
                </div>
            </div>

			<!-- Seller Number Section -->
            <div class="card mb-4">
                <div class="card-header">Deine Verkäufernummer(n) verwalten</div>
                <div class="card-body">
                    <form method="POST" id="seller-number-actions">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                        <!-- Dropdown to select a seller number -->
                        <div class="form-group">
                            <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
                                Bitte beachte, dass die Freischaltung erst ab dem o.g. Datum "Start Nr. Vergabe" funktioniert.
                                <button type="button" class="close" data-dismiss="alert" aria-label="Schließen">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <label for="sellerNumberSelect">Wähle eine Verkäufernummer:</label>
                            <select class="form-control mb-2" id="sellerNumberSelect" name="selected_seller_number">
                                <option value="" disabled selected>Nicht verfügbar</option>
                            </select>
                        </div>

                        <!-- Dropdown for actions -->
                        <div class="dropdown mb-2">
                            <select class="form-control" name="action">
                                <option value="" selected disabled>Bitte wählen</option>
                                <option value="validate">freischalten</option>
                                <option value="revoke" class="bg-danger text-light">Nummer zurückgeben</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col">
                                <button type="submit" class="btn btn-primary w-100 mt-3">Ausführen</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
			
            <!-- Per Seller Number Overview -->
            <div id="perSellerNumberOverview">
				<div class="card mb-4">
					<div class="card-header">
						An dieser Stelle kannst Du später den Status deiner verkauften Artikel einsehen.
					</div>
					<div class="card-body">
					</div>
				</div>
            </div>
			
			<!-- Past Bazaars Section -->
			<div class="card mb-4">
				<div class="card-header">Vergangene Basare</div>
				<div class="card-body table-responsive-sm">
					<?php if (!empty($past_bazaars)): ?>
						<table class="table table-bordered table-striped w-100">
							<thead>
								<tr>
									<th>Datum</th>
									<th>VerkäuferNr.</th>
									<th>Artikel verkauft</th>
									<th>Auszahlung (€)</th>
									<th>PDF</th>
								</tr>
							</thead>
							<tbody>
                                <?php foreach ($past_bazaars as $past_bazaar): ?>
                                    <tr data-items='<?php echo htmlspecialchars($past_bazaar["items"]); ?>'>
                                        <td><?php echo date('d.m.Y', strtotime($past_bazaar['bazaar_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($past_bazaar['seller_number']); ?></td>
                                        <td><?php echo htmlspecialchars($past_bazaar['items_sold']); ?></td>
                                        <td><?php echo number_format($past_bazaar['payout'], 2, ',', '.') . ' €'; ?></td>
                                        <td>
                                            <button class="btn btn-primary btn-sm">
                                                <i class="fas fa-file-download"></i> Download
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
						</table>
					<?php else: ?>
						<p>Es gibt noch keine abgeschlossenen Basare.</p>
					<?php endif; ?>
				</div>
			</div>
        </div>

        
        <!-- Helper Confirmation Modal -->
        <div class="modal fade" id="helperConfirmationModal" tabindex="-1" role="dialog" aria-labelledby="helperConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-white">
                        <h5 class="modal-title" id="helperConfirmationModalLabel">Anfrage zweite Verkäufernummer</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p>Du hast bereits eine aktive Verkäufernummer. Um eine zweite Nummer zu erhalten, trage Dich bitte, falls noch nicht geschehen, in unsere Helferliste ein. Diese findest Du unter:</p>
						
						<div class="text-center pb-3 font-weight-bold"><a href="https://www.basar-horrheim.de/index.php/helfer-gesucht
">Helfer gesucht</a></div>
                        <div class="d-none" id="helperOptionsContainer">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="option1" name="helper_options[]" value="Ich bringe Kuchen mit">
                                <label class="form-check-label" for="option1">Ich bringe Kuchen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="option2" name="helper_options[]" value="Ich helfe beim Rücksortieren">
                                <label class="form-check-label" for="option2">Ich helfe beim Rücksortieren (15:45Uhr - 18:00Uhr)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="option3" name="helper_options[]" value="Ich helfe bei der Aufsicht">
                                <label class="form-check-label" for="option3">Ich helfe bei der Aufsicht (12:45Uhr - 15:30Uhr)</label>
                            </div>
                        </div>
                        <div id="helperMessageContainer" class="form-group mt-3 d-none">
                            <label for="helperMessage">Optional: Beschreibe kurz, wie Du uns unterstützen möchtest.</label>
                            <textarea class="form-control" id="helperMessage" rows="3" placeholder="Optional"></textarea>
                        </div>
						
						<p class=""> Sollte kein Feld mehr frei sein, melde Dich gerne unter:</p>
						<div class="text-center"><a href="mailto:borga@basar-horrheim.de">borga@basar-horrheim.de</a></div>
                    </div>
                    <!--<div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                        <button type="button" class="btn btn-danger" id="confirmHelperRequestButton">Bestätigen</button>
                    </div>-->
					<div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">OK</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Sellernumber Confirmation Modal -->
        <div class="modal fade" id="confirmRevokeModal" tabindex="-1" role="dialog" aria-labelledby="confirmRevokeModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-white">
                        <h5 class="modal-title" id="confirmRevokeModalLabel">Verkäufernummer zurückgeben</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p>Bist du sicher, dass du diese Verkäufernummer zurückgeben möchtest? Diese Aktion kann nicht rückgängig gemacht werden, und alle mit dieser Nummer verknüpften Artikel werden gelöscht.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                        <button type="button" class="btn btn-danger" id="confirmRevokeButton">Bestätigen</button>
                    </div>
                </div>
            </div>
        </div>
                  
        <!-- Toast Container -->
        <div aria-live="polite" aria-atomic="true">
            <!-- Toasts will be dynamically added here -->
            <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3">
                <!-- Toasts will be dynamically added here -->
            </div>
        </div>

		<!-- Back to Top Button -->
		<div id="back-to-top"><i class="fas fa-arrow-up"></i></div>

        <!-- Footer -->
        <?php if (!empty(FOOTER)): ?>
            <footer class="p-2 bg-light text-center fixed-bottom">
                <div class="row justify-content-center">
                    <div class="col-lg-6 col-md-12">
                        <p class="m-0">
                            <?php echo process_footer_content(FOOTER); ?>
                        </p>
                    </div>
                </div>
            </footer>
        <?php endif; ?>

        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/utilities.js" nonce="<?php echo $nonce; ?>"></script>
        <script nonce="<?php echo $nonce; ?>">
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById('seller-number-actions');
                const confirmRevokeModal = document.getElementById('confirmRevokeModal');
                const confirmRevokeButton = document.getElementById('confirmRevokeButton');
                const sellerDropdown = document.getElementById('sellerNumberSelect');
                const sellerCount = parseInt($('#currentSellerCount').val(), 10);
                const cookieName = 'seller_helper_request';

                // Retrieve the stored seller count from the cookie
                const storedSellerCount = getCookie(cookieName);

                // Refresh seller numbers and dropdown
                refreshSellerData(); 

                if (upcomingBazaar) {
                    updateRequestSellerSection(upcomingBazaar);
                } else {
                    console.error('No upcoming bazaar found');
                }
                
                let selectedAction = null;

                form.addEventListener('submit', function (e) {
                    const selectedSellerNumber = form.querySelector('select[name="selected_seller_number"]').value;
                    const action = form.querySelector('select[name="action"]').value;

                    // Guard against missing seller number or action selection.
                    if (!selectedSellerNumber || !action) {
                        e.preventDefault();
                        showToast('Hinweis', 'Bitte wähle eine Verkäufernummer und eine Aktion aus.', 'info');
                        return;
                    }

                    if (action === 'revoke') {
                        e.preventDefault(); // Prevent form submission
                        selectedAction = action; // Store the action
                        $(confirmRevokeModal).modal('show'); // Show the modal
                    } else if (action === 'validate') {
                        e.preventDefault(); // Prevent default form submission

                        const csrfToken = form.querySelector('input[name="csrf_token"]').value;

                        // Perform the AJAX POST request to validate the seller number
                        $.post('validate_seller.php', {
                            action: 'validate',
                            csrf_token: csrfToken,
                            selected_seller_number: selectedSellerNumber,
							bazaar_id: upcomingBazaar.bazaar_id
                        }, function (response) {
                            if (response.success) {
                                showToast('Erfolgreich', response.message, 'success');
                                refreshSellerData(); // Refresh seller numbers and associated data
                            } else if (response.require_helper_confirmation) {
                                $('#helperConfirmationModal').modal('show');
                            } else if (response.info){
                                showToast('Hinweis', response.message, 'info');
                            } else {
                                showToast('Fehler', response.message, 'danger');
                            }
                        }, 'json');
                    }
                });

                // Handle confirmation of revocation
                confirmRevokeButton.addEventListener('click', function () {
                    if (selectedAction === 'revoke') {
                        $(confirmRevokeModal).modal('hide'); // Close modal

                        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                        const selectedSellerNumber = sellerDropdown.value;

                        // Send AJAX POST request to revoke seller number
                        $.post(
                            'revoke_seller.php',
                            {
                                csrf_token: csrfToken,
                                selected_seller_number: selectedSellerNumber
                            },
                            function (response) {
                                if (response.success) {
                                    showToast('Erfolgreich', response.message, 'success');
                                    refreshSellerData(); // Refresh seller numbers and dropdown
                                } else {
                                    showToast('Fehler', response.message, 'danger');
                                }
                            },
                            'json'
                        );
                    }
                });

                // Handle direct request seller number with optional helper request
                $('#requestSellerNumberForm').on('submit', function (e) {
                    e.preventDefault(); // Stop direct submission

                    const csrfToken = $('input[name="csrf_token"]').val();
                    const bazaarId = $('input[name="bazaar_id"]').val();
                    const helperRequest = $('#helperRequest').is(':checked') ? 1 : 0;
                    const helperMessage = $('#helperMessage').val();
                    const currentSellerCount = $('#sellerNumberSelect option').length; // Get current seller number count
					let helperOptions = '';

					// Capture helper options if helper request is checked
					if (helperRequest) {
						helperOptions = $('input[name="helper_options[]"]:checked')
							.map(function () {
								return $(this).val();
							})
							.get()
							.join(', ');
						// Optional: Validate that at least one option is selected
						if (!helperOptions) {
							showToast(
                                'NeeNeeNee - So nich 😜',
                                'Bitte wähle mindestens eine Option aus, wenn Du dich als Helfer eintragen möchtest.',
                                'danger',
                                5000
                            );
							return;
						}
					}
	
                    // ✅ Disable request button on submission
                    $('#requestSellerButton').prop('disabled', true);

                    // ✅ Send AJAX Request
                    $.post('seller_dashboard.php', {
                        csrf_token: csrfToken,
                        bazaar_id: bazaarId,
                        helper_request: helperRequest,
                        helper_message: helperMessage,
						helper_options: helperOptions,
                        request_seller_number: 1
                    }, function (response) {
                        if (response.success) {
                            showToast('Erfolgreich', response.message, 'success', 6000);
                            
                            // ✅ Store Cookie if Helper Request was made
                            if (helperRequest === 1) {
                                setCookie("seller_helper_request", "true", 7); // Store for 7 days
                                setCookie("seller_helper_request_count", currentSellerCount, 7); // Store for 7 days

                                // ✅ Inject the "pending" message inside the specific div
                                $('#helperRequestStatus').html(`
                                    <div id="helperRequestAlert" class="alert alert-info">
                                        Dein Antrag auf eine zusätzliche Verkäufernummer wird bearbeitet. Du wirst benachrichtigt, sobald er genehmigt wurde.
                                    </div>
                                `);
                            } else {
                                // ✅ Enable request button again
                                $('#requestSellerButton').prop('disabled', false);
                            }

                            refreshSellerData();
                        } else if (response.require_helper_confirmation) {
                            $('#helperConfirmationModal').modal('show');
                        } else {
                            showToast('Fehler', response.message, 'danger');
                            // ✅ Re-enable the button in case of failure
                            $('#requestSellerButton').prop('disabled', false);
                        }
                    }, 'json');
                });

                // ✅ handle Helper Confirmation Modal Submission
                $('#confirmHelperRequestButton').on('click', function () {
                    const csrfToken = $('input[name="csrf_token"]').val();
                    const bazaarId = $('input[name="bazaar_id"]').val();
                    const helperMessage = $('#helperMessage').val();
                    const currentSellerCount = $('#sellerNumberSelect option').length; // Get current seller number count
                    const selectedOptions = $('#helperOptionsContainer input[name="helper_options[]"]:checked')
                        .map(function () {
                            return $(this).val();
                        })
                        .get();

                    if (selectedOptions.length === 0) {
                        showToast('Fehler', 'Bitte wähle mindestens eine Option aus.', 'danger');
                        return;
                    }

                    // ✅ Disable request button on submission
                    $('#requestSellerButton').prop('disabled', true);

                    $.post('seller_dashboard.php', {
                        csrf_token: csrfToken,
                        bazaar_id: bazaarId,
                        helper_request: 1,
                        helper_message: helperMessage,
                        helper_options: selectedOptions.join(', ')
                    }, function (response) {
                        if (response.success) {
                            $('#helperConfirmationModal').modal('hide');
                            showToast('Erfolgreich', response.message, 'success');
                            setCookie('seller_helper_request', 'true', 7); // Store for 7 days
                            setCookie("seller_helper_request_count", currentSellerCount, 7); // Store for 7 days

                            // ✅ Inject the "pending" message inside the specific div
                            $('#helperRequestStatus').html(`
                                <div id="helperRequestAlert" class="alert alert-info">
                                    Dein Antrag auf eine zusätzliche Verkäufernummer wird bearbeitet. Du wirst benachrichtigt, sobald er genehmigt wurde.
                                </div>
                            `);
                            refreshSellerData();
                        } else {
                            showToast('Fehler', response.message, 'danger');
                            // ✅ Re-enable the button in case of failure
                            $('#requestSellerButton').prop('disabled', false);
                        }
                    }, 'json');
                });

                $('#helperConfirmationModal').on('hidden.bs.modal', function () {
                    if (!getCookie("seller_helper_request")) {
                        $('#requestSellerButton').prop('disabled', false);
                    }
                });

                if (getCookie("seller_helper_request")) {
                    $('#requestSellerButton').prop('disabled', true);
                }

                // Attach functionality to all forms on the page
                $('form').each(function () {
                    const $form = $(this); // Wrap the current form in a jQuery object
                    const $helperRequestCheckbox = $form.find('#helperRequest');
                    const $helperOptionsContainer = $form.find('#helperOptionsContainer');
                    const $helperMessageContainer = $form.find('#helperMessageContainer');
                    const $messageInput = $form.find('#helperMessage');

                    if ($helperRequestCheckbox.length) {
                        // Show or hide helper options and message based on the checkbox state
                        $helperRequestCheckbox.on('change', function () {
                            if (this.checked) {
                                $helperOptionsContainer.removeClass('hidden');
                                $helperMessageContainer.removeClass('hidden');
                            } else {
                                $helperOptionsContainer.addClass('hidden');
                                $helperMessageContainer.addClass('hidden');
                                $messageInput.val(''); // Clear optional message
                            }
                        });
                    }
                });

                document.documentElement.style.visibility = "visible";

                // Refresh seller numbers and dropdown
                // Wait for seller data refresh before checking if count increased
                refreshSellerData().then(() => {
                    const newSellerCount = parseInt($('#currentSellerCount').val(), 10);
                    if (storedSellerCount !== null && storedSellerCount < newSellerCount) {
                        $('#helperRequestAlert').remove(); // Remove the alert message
                        removeCookie('seller_helper_request'); // ✅ Remove cookie
                    }
                });

                document.querySelectorAll(".btn-primary.btn-sm").forEach(button => {
                    button.addEventListener("click", function () {
                        // ✅ Get data from the row
                        const row = this.closest("tr");
                        const bazaarDate = row.cells[0].innerText;
                        const sellerNumber = row.cells[1].innerText;
                        const itemsSold = row.cells[2].innerText;
                        const payout = row.cells[3].innerText;
                        const itemsJson = row.getAttribute("data-items"); // Items stored in data attribute

                        // ✅ Parse the sold items
                        let items = [];
                        if (itemsJson) {
                            items = JSON.parse(itemsJson);
                        }

                        // ✅ Create PDF Document
                        const { jsPDF } = window.jspdf;
                        const doc = new jsPDF();

                        // ✅ Title
                        doc.setFontSize(18);
                        doc.setFont("helvetica", "bold");
                        doc.text("Verkaufsabrechnung", 105, 15, { align: "center" });

                        // ✅ Seller Information
                        doc.setFontSize(12);
                        doc.setFont("helvetica", "normal");
                        doc.text(`Verkäufernummer: ${sellerNumber}`, 20, 30);
                        doc.text(`Basar Datum: ${bazaarDate}`, 20, 40);
                        doc.text(`Artikel verkauft: ${itemsSold}`, 20, 50);

                        // ✅ Define Page Limit
                        let yPos = 60;
                        let maxY = 260;  // Page height limit before adding a new page

                        doc.setFontSize(10);
                        doc.setFont("helvetica", "bold");
                        doc.text("Artikelname", 20, yPos);
                        doc.text("Preis (€)", 160, yPos);
                        doc.line(20, yPos + 2, 190, yPos + 2); 
                        yPos += 10;
                        doc.setFontSize(10);
                        doc.setFont("helvetica", "normal");

                        // ✅ Loop through items and add them to the PDF
                        items.forEach((item, index) => {
                            if (yPos >= maxY) {
                                doc.addPage();  // ✅ Add new page if the content reaches max height
                                yPos = 20;  // ✅ Reset y position for the new page
                                doc.text("Artikelname", 20, yPos);
                                doc.text("Preis (€)", 160, yPos);
                                doc.line(20, yPos + 2, 190, yPos + 2);
                                yPos += 10;
                            }
                            doc.text(item.name, 20, yPos);
                            doc.text(`${parseFloat(item.price).toFixed(2)} €`, 160, yPos);
                            yPos += 7;
                        });

                        // ✅ Ensure the summary & disclaimer always appear at the bottom of the last page
                        if (yPos + 20 >= maxY) {
                            doc.addPage();  // ✅ Add a new page if the summary would overflow
                            yPos = 20;
                        }

                        // ✅ Summary
                        yPos += 10;
                        doc.setFontSize(12);
                        doc.setFont("helvetica", "bold");
                        doc.text("Gesamt Auszahlung:", 20, yPos);
                        doc.text(payout, 160, yPos);

                        // ✅ Footer: Always at the bottom of the last page
                        doc.setFontSize(8);
                        doc.setFont("helvetica", "italic");
                        doc.text("Dies ist keine Rechnung im Sinne des Umsatzsteuergesetzes (§ 14 UStG) und berechtigt nicht zum Vorsteuerabzug.", 20, 280);

                        // ✅ Save PDF
                        doc.save(`Verkaufsabrechnung_${sellerNumber}.pdf`);
                    });
                });
            });
        </script>

        <script nonce="<?php echo $nonce; ?>">
            function openMailto(mailtoUrl) {
                window.open(mailtoUrl, '_blank');
            }
        </script>

        <style nonce="<?php echo $nonce; ?>">
            .toast {
                opacity: 0; /* Initially hidden */
                transition: opacity 0.5s ease-in-out; /* Smooth fade-in/out */
            }
        </style>

		<script nonce="<?php echo $nonce; ?>">
			document.addEventListener("DOMContentLoaded", function () {
				// Function to toggle the visibility of the "Back to Top" button
				function toggleBackToTopButton() {
					const scrollTop = $(window).scrollTop();

					if (scrollTop > 100) {
						$('#back-to-top').fadeIn();
					} else {
						$('#back-to-top').fadeOut();
					}
				}

				// Initial check on page load
				toggleBackToTopButton();
			
		
				// Show or hide the "Back to Top" button on scroll
				$(window).scroll(function() {
					toggleBackToTopButton();
				});
			
				// Smooth scroll to top
				$('#back-to-top').click(function() {
					$('html, body').animate({ scrollTop: 0 }, 600);
					return false;
				});
			});
		</script>
    </body>
</html>
