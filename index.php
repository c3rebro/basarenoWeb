<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!check_config_exists()) {
    header("location: first_time_setup.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

if (!$conn) {
    header("location: first_time_setup.php");
    exit;
}

// Fetch the operation mode
$operationMode = get_operation_mode($conn);

if ($operationMode === 'offline') {
    // Show the special welcome screen with the new button layout
    echo '<!DOCTYPE html>
			<html lang="de">
			<head>
				<meta charset="UTF-8">
				<meta name="viewport" content="width=device-width, initial-scale=1.0">
				<title>Hinweis zur Zertifikatssicherheit</title>
				<!-- Preload and link CSS files -->
				<link rel="preload" href="css/bootstrap.min.css" as="style" id="bootstrap-css">
				<link rel="preload" href="css/all.min.css" as="style" id="all-css">
				<link rel="preload" href="css/style.css" as="style" id="style-css">
				<noscript>
					<link href="css/bootstrap.min.css" rel="stylesheet">
					<link href="css/all.min.css" rel="stylesheet">
					<link href="css/style.css" rel="stylesheet">
				</noscript>
				<script nonce="' . htmlspecialchars($nonce) . '">
					document.getElementById("bootstrap-css").rel = "stylesheet";
					document.getElementById("all-css").rel = "stylesheet";
					document.getElementById("style-css").rel = "stylesheet";
				</script>
				<style nonce="' . htmlspecialchars($nonce) . '">
					.dashboard-table {
						width: 100%;
						border-collapse: collapse;
					}
					.dashboard-table td {
						border: 1px solid #ddd;
						width: 120px;
						height: 120px;
						padding: 0;
					}
					.dashboard-table .btn {
						width: 100%;
						height: 100%;
						display: flex;
						align-items: center;
						justify-content: center;
						padding: 0;
						text-align: center;
					}
				</style>
			</head>
			<body>
				<div class="container mt-5">
					<div class="jumbotron text-center">
						<h1 class="display-4">Zertifikats- Warnung</h1>
						<p class="lead">Beim Klick auf einen der Buttons wird vom Browser eine Warnung ausgegeben. Um fortzufahren, akzeptieren Sie bitte das selbstsignierte Zertifikat.</p>
						<hr class="my-4">
						<p>Öffnen Sie die "Erweiterte Optionen" und wählen Sie "Weiter zu bazaar.lan (unsicher)" aus.</p>
						<table class="dashboard-table mx-auto">
							<tr>
								<td colspan="2">
									<a class="btn btn-secondary btn-lg" href="index.php" role="button">Startseite anzeigen</a>
								</td>
							</tr>
							<tr>
								<td>
									<a class="btn btn-success btn-lg" href="acceptance.php" role="button">Annehmen</a>
								</td>
								<td>
									<a class="btn btn-warning btn-lg" href="cashier.php" role="button">Scannen</a>
								</td>
							</tr>
							<tr>
								<td>
									<a class="btn btn-primary btn-lg" href="pickup.php" role="button">Abholen</a>
								</td>
								<td>
									<a class="btn btn-danger btn-lg" href="admin_manage_sellers.php" role="button">Administrieren</a>
								</td>
							</tr>
						</table>
					</div>
				</div>

						<footer class="p-2 bg-light text-center fixed-bottom">
						<div class="row justify-content-center">
							<div class="col-lg-6 col-md-12">
								<p class="m-0">

								</p>
							</div>
						</div>
					</footer>
				
				<script src="js/jquery-3.7.1.min.js" nonce="' . htmlspecialchars($nonce) . '"></script>
				<script src="js/popper.min.js" nonce="' . htmlspecialchars($nonce) . '"></script>
				<script src="js/bootstrap.min.js" nonce="' . htmlspecialchars($nonce) . '"></script>
			</body>
			</html>';
    exit;
}

// Fetch bazaar dates and max_sellers
$sql = "SELECT id, startDate, startReqDate, max_sellers, mailtxt_reqnewsellerid, mailtxt_reqexistingsellerid FROM bazaar ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);
$bazaar = $result->fetch_assoc();
$seller_message = '';

$currentDate = new DateTime();
$startReqDate = null;
$startDate = null;
$canRequestSellerId = false;
$bazaarOver = true; // Default to bazaar being over
$maxSellersReached = false;
$mailtxt_reqnewsellerid = null;
$mailtxt_reqexistingsellerid = null;
    
if ($bazaar) {
    $startReqDate = !empty($bazaar['startReqDate']) ? new DateTime($bazaar['startReqDate']) : null;
    $startDate = !empty($bazaar['startDate']) ? new DateTime($bazaar['startDate']) : null;
    $bazaarId = $bazaar['id'];
    $maxSellers = $bazaar['max_sellers'];
    $mailtxt_reqnewsellerid = $bazaar['mailtxt_reqnewsellerid'];
    $mailtxt_reqexistingsellerid = $bazaar['mailtxt_reqexistingsellerid'];
    
    $formattedDate = $startReqDate->format('d.m.Y');

    if ($startReqDate && $startDate) {
        $canRequestSellerId = $currentDate >= $startReqDate && $currentDate <= $startDate;
        $bazaarOver = $currentDate > $startDate;
    }

    // Check if the max sellers limit has been reached
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM sellers WHERE bazaar_id = ?");
    $stmt->bind_param("i", $bazaarId);
    $stmt->execute();
    $result = $stmt->get_result();
    $sellerCount = $result->fetch_assoc()['count'];
    $maxSellersReached = $sellerCount >= $maxSellers;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_seller_id']) && $canRequestSellerId && !$maxSellersReached) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    // Send mail to user
	$email = sanitize_input($_POST['email']);
	$family_name = sanitize_input($_POST['family_name']);
	$given_name = !empty($_POST['given_name']) ? sanitize_input($_POST['given_name']) : 'Nicht angegeben';
	$phone = sanitize_input($_POST['phone']);
	$street = !empty($_POST['street']) ? sanitize_input($_POST['street']) : 'Nicht angegeben';
	$house_number = !empty($_POST['house_number']) ? sanitize_input($_POST['house_number']) : 'Nicht angegeben';
	$zip = !empty($_POST['zip']) ? sanitize_input($_POST['zip']) : 'Nicht angegeben';
	$city = !empty($_POST['city']) ? sanitize_input($_POST['city']) : 'Nicht angegeben';
	$reserve = isset($_POST['reserve']) ? 1 : 0;
	$use_existing_number = $_POST['use_existing_number'] === 'yes';
	$consent = isset($_POST['consent']) && $_POST['consent'] === 'yes' ? 1 : 0;

	// Check if a seller ID request already exists for this email
	$stmt = $conn->prepare("SELECT verification_token, verified FROM sellers WHERE email = ?");
	$stmt->bind_param("s", $email);
	$stmt->execute();
	$result = $stmt->get_result();
	$existing_seller = $result->fetch_assoc();

	if ($use_existing_number) {
		process_existing_number($conn, $email, $consent, $mailtxt_reqexistingsellerid);
		// Log existing seller number request
		log_action($conn, 0, "Existing seller number request", "Email: $email");
	} else {
		if ($existing_seller) {
			if (!empty($existing_seller['verification_token'])) {
                            show_modal($nonce, 'Eine Verkäufernr-Anfrage wurde bereits generiert. Pro Verkäufer ist in der Regel nur eine Verkäufernr zulässig. Bitte melden Sie sich per Mail, wenn Sie eine weitere Nummer haben möchten, oder wenn Sie Probleme haben, Ihre bereits angefragte Nummer frei zu Schalten.', 'warning');
			} elseif ($existing_seller['verified']) {
                            show_modal($nonce, 'Eine Verkäufer Nummer wurde bereits für Sie aktiviert. Pro Verkäufer ist in der Regel nur eine Verkäufernr zulässig. Bitte melden Sie sich per Mail, wenn Sie eine weitere Nummer haben möchten.', 'warning');
			} else {
				process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid);
			}
			// Log new seller request for existing seller
			log_action($conn, 0, "New seller request for existing seller", "Email: $email");
		} else {
			process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid);
			// Log new seller request
			log_action($conn, 0, "New seller request", "Email: $email");
		}
	}
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Bazaar Landing Page</title>
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
        <h1 class="mt-5">Willkommen</h1>
        <p class="lead">Verkäufer können hier Verkäufernummern anfordern und Artikellisten erstellen.</p>

        <?php if ($bazaarOver): ?>
            <div class="alert alert-info">Der Bazaar ist geschlossen. Bitte kommen Sie wieder, wenn der nächste Bazaar stattfindet.</div>
        <?php elseif ($maxSellersReached): ?>
            <div class="alert alert-info">Es tut uns leid, aber die maximale Anzahl an Verkäufern wurde erreicht. Die Registrierung für eine Verkäufernummer wurde geschlossen.</div>
        <?php elseif (!$canRequestSellerId): ?>
            <div class="alert alert-info">Anfragen für neue Verkäufer-IDs sind derzeit noch nicht freigeschalten. Die nächste Nummernvergabe startet am: <?php echo htmlspecialchars($formattedDate); ?></div>
        <?php else: ?>
            <h2 class="mt-5">Verkäufer-ID anfordern</h2>
            <?php if ($seller_message): ?>
                <div class="alert alert-info"><?php echo htmlspecialchars($seller_message); ?></div>
            <?php endif; ?>
            <form action="index.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="row form-row">
                    <div class="form-group col-md-6">
                        <label for="family_name" class="required">Nachname:</label>
                        <input type="text" class="form-control" id="family_name" name="family_name" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="given_name" class="required">Vorname:</label>
                        <input type="text" class="form-control" id="given_name" name="given_name" required>
                    </div>
                </div>
                <div class="row form-row">
                    <div class="form-group col-md-8">
                        <label for="street">Straße:</label>
                        <input type="text" class="form-control" id="street" name="street">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="house_number">Hausnummer:</label>
                        <input type="text" class="form-control" id="house_number" name="house_number">
                    </div>
                </div>
                <div class="row form-row">
                    <div class="form-group col-md-4">
                        <label for="zip">PLZ:</label>
                        <input type="text" class="form-control" id="zip" name="zip">
                    </div>
                    <div class="form-group col-md-8">
                        <label for="city">Stadt:</label>
                        <input type="text" class="form-control" id="city" name="city">
                    </div>
                </div>
                <div class="form-group">
                    <label for="phone" class="required">Telefonnummer:</label>
                    <input type="text" class="form-control" id="phone" name="phone" required>
                </div>
                <div class="form-group">
                    <label for="email" class="required">E-Mail:</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="reserve">Verkäufer-ID:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_yes" value="yes">
                        <label class="form-check-label" for="use_existing_number_yes">
                            Ich habe bereits eine Nummer und möchte diese erneut verwenden
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_no" value="no" checked>
                        <label class="form-check-label" for="use_existing_number_no">
                            Ich möchte eine neue Nummer erhalten
                        </label>
                    </div>
                </div>
                <div class="form-group hidden" id="seller_id_field">
                    <label for="seller_id">Verkäufer-ID:</label>
                    <input type="text" class="form-control" id="seller_id" name="seller_id">
                </div>
                <div class="form-group">
                    <label for="consent" class="required">Einwilligung zur Datenspeicherung:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="consent" id="consent_yes" value="yes" required>
                        <label class="form-check-label" for="consent_yes">
                            Ja: Ich möchte, dass meine persönlichen Daten bis zum nächsten Bazaar gespeichert werden. Ich kann meine Etiketten beim nächsten Mal wiederverwenden.
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="consent" id="consent_no" value="no" required>
                        <label class="form-check-label" for="consent_no">
                            Nein: Ich möchte nicht, dass meine persönlichen Daten gespeichert werden. Wenn ich das nächste Mal am Bazaar teilnehme, muss ich neue Etiketten drucken.
                        </label>
                    </div>
                </div>
                <p>Weitere Hinweise zum Datenschutz und wie wir mit Ihren Daten umgehen, erhalten Sie in unserer <a href='https://www.basar-horrheim.de/index.php/datenschutzerklaerung'>Datenschutzerklärung</a>. Bei Nutzung unserer Dienste erklären Sie sich mit den dort aufgeführen Bedingungen einverstanden.</p>
                <button type="submit" class="btn btn-primary btn-block" name="request_seller_id">Verkäufer-ID anfordern</button>
            </form>
            <p class="mt-3 text-muted">* Diese Felder sind Pflichtfelder.</p>
        <?php endif; ?>
    </div>

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
	
    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const useExistingNumberYes = document.getElementById('use_existing_number_yes');
            const useExistingNumberNo = document.getElementById('use_existing_number_no');
            const sellerIdField = document.getElementById('seller_id_field');

            function toggleSellerIdField() {
                if (useExistingNumberYes.checked) {
                    sellerIdField.classList.remove('hidden');
                } else {
                    sellerIdField.classList.add('hidden');
                }
            }

            useExistingNumberYes.addEventListener('click', toggleSellerIdField);
            useExistingNumberNo.addEventListener('click', toggleSellerIdField);
        });
    </script>
    
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>