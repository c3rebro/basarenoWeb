<?php

// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

// Send mail to user
$email = "";
$family_name = "";
$given_name = "";
$phone = "";
$street = "";
$house_number = "";
$zip = "";
$city = "";
$use_existing_number = false;
$useExistingNumberYes = false;
	
if (!check_config_exists()) {
    header("location: first_time_setup.php");
    exit;
}

$conn = get_db_connection();

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
						<p class="lead">Beim Klick auf einen der Buttons wird vom Browser eine Warnung ausgegeben. Um fortzufahren, akzeptiere bitte das selbstsignierte Zertifikat.</p>
						<hr class="my-4">
						<p>Öffne die "Erweiterte Optionen" und wähle "Weiter zu bazaar.lan (unsicher)" aus.</p>
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
$alertMessage = "";
$alertMessage_Type = "";

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

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'request_seller_id') !== null && $canRequestSellerId && !$maxSellersReached) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
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
	$reserve = filter_input(INPUT_POST, 'reserve') !== null ? 1 : 0;
	$use_existing_number = false; //$_POST['use_existing_number'] === 'yes';
	$consent = filter_input(INPUT_POST, 'consent') !== null && $_POST['consent'] === 'yes' ? 1 : 0;

	// Check if a seller ID request already exists for this email
	$stmt = $conn->prepare("SELECT verification_token, user_verified FROM users WHERE username = ?");
	$stmt->bind_param("s", $email);
	$stmt->execute();
	$result = $stmt->get_result();
	$existing_seller = $result->fetch_assoc();
	
	$password = filter_input(INPUT_POST, 'password');
        $confirm_password = filter_input(INPUT_POST, 'confirm_password');

	// PWD Check
	if (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
                show_modal($nonce, 
                        "Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Großbuchstaben und einen Kleinbuchstaben enthalten.",
                        "warning", 
                        "Passwort",
                        "pwdReqNotMetModal");
	} elseif ($password !== $confirm_password) {
                show_modal($nonce, 
                        "Die Passwörter stimmen nicht überein",
                        "warning", 
                        "Passwort",
                        "pwdReqNotSameModal");
	} else {
		//New or existing user ?
/*
		if ($use_existing_number) {
		process_existing_number($conn, $email, $consent, $mailtxt_reqexistingsellerid);
		// Log existing seller number request
		log_action($conn, 0, "Existing seller number request", "Email: $email");
		
		
	} else {
		
		*/
		if ($existing_seller) {
			if (!empty($existing_seller['verification_token'])) {
                            show_modal($nonce, 
                                "Eine Registrierungs-Anfrage wurde für diese Mailadresse bereits generiert. Bitte klicke auf den Bestätigungs-Link in der Email. Danach kannst Du Dich oben rechts anmelden.",
                                "warning", 
                                "Email bereits vergeben",
                                "emailExitModal");
							
			} elseif ($existing_seller['user_verified']) {
                            show_modal($nonce, 
                                "Dieses Konto existiert bereits. Bitte melde Dich oben rechts an.",
                                "warning", 
                                "Email bereits vergeben",
                                "emailExitModal");
			} else {
				//process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid);
			}
			// Log new seller request for existing seller
			log_action($conn, 0, "New seller request for existing seller", "Email: $email");
		} else {
			
			if(process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid, $password, $nonce)) {
                            show_modal($nonce, 
                            "Das Konto wurde erfolgreich erstellt. Bitte schaue in Deine E-Mails und bestätige die Registrierung.", 
                            "success",
                            "Erfolgreich",
                            "createAccountSuccess");
                            
                            // Send mail to user successful, reset FORM
                            $email = "";
                            $family_name = "";
                            $given_name = "";
                            $phone = "";
                            $street = "";
                            $house_number = "";
                            $zip = "";
                            $city = "";
                            $use_existing_number = false;
                            $useExistingNumberYes = false;
                        }
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
    <style nonce="<?php echo $nonce; ?>">
            html { visibility: hidden; }
    </style>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>BasarenoWeb für Basar-Horrheim.de</title>
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
	<script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
</head>
<body>
    <!-- Navbar -->
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
		
	<div class="container">
        <h1 class="mt-5">Willkommen</h1>
        <p class="lead">Verkäufer können hier neue Verkäufernummern anfordern und Artikellisten erstellen.</p>

        <?php if ($alertMessage): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alertMessage_Type); ?>"><?php echo htmlspecialchars($alertMessage); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Schliessen">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
		
        <?php if ($bazaarOver): ?>
            <div class="alert alert-info">Der Basar ist (noch) geschlossen. Bitte komm wieder, wenn der nächste Basar stattfindet.</div>
        <?php elseif ($maxSellersReached): ?>
            <div class="alert alert-info">Es tut uns leid, aber die maximale Anzahl an Verkäufern wurde erreicht. Die Registrierung für eine Verkäufernummer wurde geschlossen.</div>
        <?php elseif (!$canRequestSellerId): ?>
            <div class="alert alert-info">Anfragen für neue Verkäufer Nummern sind derzeit noch nicht freigeschalten. Die nächste Nummernvergabe startet am: <?php echo htmlspecialchars($formattedDate); ?></div>
        <?php else: ?>
            <h2 class="mt-5">Neue Verkäufer-Nummer anfordern</h2>
            <form action="index.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="row form-row">
                    <div class="form-group col-md-6">
                        <label for="family_name" class="required">Nachname:</label>
                        <input type="text" class="form-control" id="family_name" value="<?php echo htmlspecialchars($family_name); ?>" name="family_name" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="given_name" class="required">Vorname:</label>
                        <input type="text" class="form-control" id="given_name" value="<?php echo htmlspecialchars($given_name); ?>" name="given_name" required>
                    </div>
                </div>
                <div class="row form-row">
                    <div class="form-group col-md-8">
                        <label for="street">Straße:</label>
                        <input type="text" class="form-control" id="street" value="<?php echo htmlspecialchars($street); ?>" name="street">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="house_number">Hausnummer:</label>
                        <input type="text" class="form-control" id="house_number" value="<?php echo htmlspecialchars($house_number); ?>" name="house_number">
                    </div>
                </div>
                <div class="row form-row">
                    <div class="form-group col-md-4">
                        <label for="zip">PLZ:</label>
                        <input type="text" class="form-control" id="zip" value="<?php echo htmlspecialchars($zip); ?>" name="zip">
                    </div>
                    <div class="form-group col-md-8">
                        <label for="city">Stadt:</label>
                        <input type="text" class="form-control" id="city" value="<?php echo htmlspecialchars($city); ?>" name="city">
                    </div>
                </div>
                <div class="form-group">
                    <label for="phone" class="required">Telefonnummer:</label>
                    <input type="text" class="form-control" id="phone" value="<?php echo htmlspecialchars($phone); ?>" name="phone" placeholder="Wie können wir Dich im &quot;Notfall&quot; schnell erreichen?" required>
                </div>
                <div class="form-group">
                    <label for="email" class="required">E-Mail:</label>
                    <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($email); ?>" name="email" placeholder="Das wird gleichzeitig Dein Benutzername sein." required>
                </div>
				<div class="row form-row">
					<div class="form-group col-md-6">
						<label for="password" class="required">Passwort erstellen:</label>
						<input type="password" class="form-control" id="password" name="password" required>
					</div>
					<div class="form-group col-md-6">
						<label for="confirm_password" class="required">Passwort wiederholen:</label>
						<input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
					</div>
				</div>

				
                <div class="form-group d-none">
                    <label for="reserve">Verkäufer-Nummer:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio"  value="no" disabled>
                        <label class="form-check-label">
                            Ich habe bereits eine Nummer und möchte diese erneut verwenden (Ab dem nächsten Basar verfügbar.)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_no" value="yes" checked>
                        <label class="form-check-label" for="use_existing_number_no">
                            Ich möchte eine neue Nummer erhalten
                        </label>
                    </div>
                </div>
				
                <div class="form-group hidden" id="seller_id_field">
                    <label for="seller_id">Verkäufer-Nummer:</label>
                    <input type="text" class="form-control" id="seller_id" name="seller_id">
                </div>
                <div class="form-group">
                    <label for="consent" class="required">Einwilligung zur Datenspeicherung:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="consent" id="consent_yes" value="yes" required>
                        <label class="form-check-label" for="consent_yes">
                            Ja: Ich möchte, dass meine pers. Daten bis zum nächsten Basar gespeichert werden. Meine Etiketten kann ich beim nächsten Basar wiederverwenden.
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="consent" id="consent_no" value="no" required>
                        <label class="form-check-label" for="consent_no">
                            Nein: Ich möchte nicht, dass meine pers. Daten gespeichert werden. Wenn ich das nächste Mal am Basar teilnehme, muss ich neue Etiketten erstellen.
                        </label>
                    </div>
                </div>
                <p>Weitere Hinweise zum Datenschutz und wie wir mit den Daten umgehen, haben Wir in unserer <a href='https://www.basar-horrheim.de/index.php/datenschutzerklaerung'>Datenschutzerklärung</a> für Dich zusammen gestellt. Bei Nutzung unserer Dienste erklärst Du uns gegenüber, mit den dort aufgeführen Bedingungen einverstanden zu sein.</p>
                <button type="submit" class="btn btn-primary btn-block" name="request_seller_id">Verkäufernummer anfordern</button>
            </form>
            <p class="mt-3 text-muted">* Diese Felder sind Pflichtfelder.</p>
        <?php endif; ?>
    </div>
	
	<!-- Back to Top Button -->
	<div id="back-to-top"><i class="fas fa-arrow-up"></i></div>
	
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
        // Show the HTML element once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.documentElement.style.visibility = "visible";
        });
    </script>
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
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>