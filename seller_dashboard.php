<?php
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require_once 'utilities.php';

// Content Security Policy with Nonce
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self';");

// Weiterleitung unautorisierter Benutzer
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['seller', 'assistant', 'cashier', 'admin'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$conn = get_db_connection();

// Benutzerverifizierung prüfen
$sql = "SELECT user_verified FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_verified = $stmt->get_result()->fetch_assoc()['user_verified'];

if (!$user_verified) {
    header("Location: index.php");
    exit;
}

$status = $_GET['status'] ?? null;
$error = $_GET['error'] ?? null;

if ($status === 'validated') {
    show_toast($nonce, 'Die Verkäufernummer wurde erfolgreich freigeschalten.', 'Erfolgreich', 'success', 'validatedToast', 3000);
} elseif ($status === 'revoked') {
    show_toast($nonce, 'Die Verkäufernummer wurde erfolgreich zurückgegeben.', 'Erfolgreich', 'success', 'revokedToast', 3000);
} elseif ($error === 'validation_failed') {
    show_toast($nonce, 'Die Validierung der Verkäufernummer ist fehlgeschlagen.', 'Fehler', 'danger', 'validationFailedToast', 3000);
} elseif ($error === 'revocation_failed') {
    show_toast($nonce, 'Das Zurückgeben der Verkäufernummer ist fehlgeschlagen.', 'Fehler', 'danger', 'revocationFailedToast', 3000);
} elseif ($error === 'invalid_action') {
    show_toast($nonce, 'Ungültige Aktion ausgeführt.', 'Fehler', 'danger', 'invalidActionToast', 3000);
}elseif ($error === 'notFound') {
    show_toast($nonce, 'Es wurde keine Verkäufernummer gefunden. Haben Sie schon eine angefordert?', 'Fehler', 'warning', 'noSellerNumberFoundToast', 3000);
} elseif ($status === 'sellernumber_assigned') {
    show_toast($nonce, 'Verkäufernummer erfolgreich zugewiesen. Sie können jetzt Ihre Artikel anlegen.', 'Erfolgreich', 'success', 'sellerNumberAssignedToast', 3000);
} elseif ($status === 'sellernumberHelper_assigned') {
    show_toast($nonce, 'Eine Verkäufernummer wurde erfolgreich zugewiesen. Sie können jetzt Ihre Artikel anlegen. Die Zweitnummer wird nach Prüfung mov Team frei geschalten. Wir melden uns bei Dir.', 'Erfolgreich', 'success', 'sellerNumberAssignedToast', 7000);
} elseif ($error === 'sellernumber_alreadyAssigned') {
	$mailto = 'mailto:basarteam@basar-horrheim.de?subject=Helferanfrage:%20Zweitnummer&body=Ich%20möchte%20als%20Helfer%20mitmachen.%0A%0ABitte%20sagt%20mir,%20wie%20ich%20Euch%20unterstützen%20kann.';

    show_modal($nonce, 'Eine Verkäufernummer wurde bereits zugewiesen. Wenn Sie eine zweite Nummer wünschen, melden Sie sich bitte als Helfer unter <a href="' . $mailto . '">basarteam@basar-horrheim.de</a>.', 'warning', 'Bereits registriert', 'sellerNumberAssignedModal');
} elseif ($error === 'sellernumber_alreadyVerified') {
    show_toast($nonce, 'Die Verkäufernummer ist bereits freigeschalten. Sie können über das Menü oben "Meine Artikel" Ihre Artikel anlegen.', 'Info', 'info', 'alreadyValidatedToast', 4000);
}

// Letzte Verkäufernummer abrufen
$sql = "SELECT seller_number, seller_verified FROM sellers WHERE user_id = ? ORDER BY id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$last_seller_number = $stmt->get_result()->fetch_assoc();

// Verfügbare Basare abrufen
$sql = "SELECT b.id, b.startDate, b.max_sellers, COUNT(s.id) AS current_sellers
        FROM bazaar b
        LEFT JOIN sellers s ON b.id = s.bazaar_id AND s.seller_verified = 1
        WHERE b.startDate > CURDATE()
        GROUP BY b.id
        HAVING current_sellers < b.max_sellers";
$stmt = $conn->prepare($sql);
$stmt->execute();
$available_bazaars = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Anzahl der Produkte abrufen, die dem Benutzer zugeordnet sind
$sql = "SELECT COUNT(*) AS product_count FROM products WHERE seller_number IN (SELECT seller_number FROM sellers WHERE user_id = ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$product_count = $stmt->get_result()->fetch_assoc()['product_count'];

// Verkäufernummer anfordern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_seller_number'])) {
	if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $bazaar_id = $_POST['bazaar_id'];

	$helper_request = isset($_POST['helper_request']); // Check if the checkbox is checked
    $helper_message = htmlspecialchars($_POST['helper_message'] ?? '', ENT_QUOTES, 'UTF-8');

    // Check if the user already has an assigned seller number
    $sql = "SELECT seller_number FROM sellers WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_seller_number = $result->fetch_assoc();

    if ($existing_seller_number) {
        // Redirect with error if a seller number is already assigned
        header("Location: seller_dashboard.php?error=sellernumber_alreadyAssigned");
        exit;
    }
	
    // Prüfen, ob eine ungenutzte Verkäufernummer verfügbar ist
    $sql = "SELECT seller_number FROM sellers WHERE user_id = 0 ORDER BY seller_number ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $unassigned_number = $result->fetch_assoc();

    if ($unassigned_number) {
		// Assign existing unassigned seller number
		$seller_number = $unassigned_number['seller_number'];

		// Get the next available checkout_id
		$sql = "SELECT MAX(checkout_id) + 1 AS next_checkout_id FROM sellers";
		$stmt = $conn->prepare($sql);
		$stmt->execute();
		$next_checkout_id = $stmt->get_result()->fetch_assoc()['next_checkout_id'] ?? 1; // Start at 1 if no checkout_id exists

		// Update the seller record
		$sql = "UPDATE sellers SET user_id = ?, bazaar_id = ?, checkout_id = ?, seller_verified = 1 WHERE seller_number = ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("iiii", $user_id, $bazaar_id, $next_checkout_id, $seller_number);
	} else {
		// Generate new seller number
		$sql = "SELECT MAX(seller_number) + 1 AS next_number FROM sellers";
		$stmt = $conn->prepare($sql);
		$stmt->execute();
		$next_number = $stmt->get_result()->fetch_assoc()['next_number'] ?? 100; // Start at 100 if no numbers exist

		// Get the next available checkout_id
		$sql = "SELECT MAX(checkout_id) + 1 AS next_checkout_id FROM sellers";
		$stmt = $conn->prepare($sql);
		$stmt->execute();
		$next_checkout_id = $stmt->get_result()->fetch_assoc()['next_checkout_id'] ?? 1; // Start at 1 if no checkout_id exists

		// Insert new seller record
		$seller_number = $next_number;
		$sql = "INSERT INTO sellers (user_id, bazaar_id, seller_number, checkout_id, seller_verified) VALUES (?, ?, ?, ?, 1)";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("iiii", $user_id, $bazaar_id, $seller_number, $next_checkout_id);
	}

    if($stmt->execute()) {
	
		// Send email if helper request is checked
		if ($helper_request) {
			$to = "borga@basar-horrheim.de";
			$subject = "Neuer Zweitnummerantrag / Helferanfrage";
			$body = "Ein Nutzer (" . htmlspecialchars($username) . ") hat eine Verkäufernummer mit Zusatznummer beantragt.<br>";
			$body .= "Verkäufernummer: $new_seller_number<br>";
			$body .= "Benutzer-ID: $user_id<br>";
			$body .= "Bazaar-ID: $bazaar_id<br>";
			$body .= "Helfer-Anfrage: Ja<br>";
			$body .= "Nachricht: " . nl2br($helper_message) . "<br>";
			send_email($to, $subject, $body);
			header("Location: seller_dashboard.php?status=sellernumberHelper_assigned");
			exit;
		}
		
		header("Location: seller_dashboard.php?status=sellernumber_assigned");
		exit;
	} else {
		header("Location: seller_dashboard.php?error=invalid_action");
		exit;
	}
	
	header("Location: seller_dashboard.php?error=invalid_action");
	exit;
}

// Revoke Sellernumber
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    if (isset($_POST['action']) && !empty($_POST['action'])) {
        $action = $_POST['action'];
        $seller_number = $last_seller_number['seller_number'];
        
        if ($action === 'validate') {
			// Check if the seller_number is already verified
            $sql = "SELECT seller_verified FROM sellers WHERE seller_number = ? AND user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $seller_number, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $is_verified = $result->fetch_assoc()['seller_verified'] ?? 0;

            if ($is_verified) {
                // Redirect if already verified
                header("Location: seller_dashboard.php?error=sellernumber_alreadyVerified");
                exit;
            }
			
            // Validate the seller number
            $sql = "UPDATE sellers SET seller_verified = 1 WHERE seller_number = ? AND user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $seller_number, $user_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                header("Location: seller_dashboard.php?status=validated");
            } else {
                header("Location: seller_dashboard.php?error=validation_failed");
            }
        } elseif ($action === 'revoke') {
            // Revoke the seller number
            $sql = "UPDATE sellers SET user_id = 0, bazaar_id = 0, seller_verified = 0 WHERE seller_number = ? AND user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $seller_number, $user_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                header("Location: seller_dashboard.php?status=revoked");
            } else {
                header("Location: seller_dashboard.php?error=revocation_failed");
            }
        } else {
            header("Location: seller_dashboard.php?error=invalid_action");
        }
        exit;
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
    <meta charset="UTF-8">
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="#">Basareno<i>Web</i></a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item active">
                    <a class="nav-link" href="seller_dashboard.php">Dashboard <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="seller_products.php">Meine Artikel</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="seller_edit.php">Meine Daten</a>
                </li>
            </ul>
            <hr class="d-lg-none d-block">
            <?php if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']): ?>
                <li class="nav-item">
                    <a class="navbar-user" href="#">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white p-2" href="logout.php">Abmelden</a>
                </li>
            <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link btn btn-primary text-white p-2" href="login.php">Anmelden</a>
                </li>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container mt-4">
        <h1 class="mb-4">Verkäufer-Dashboard</h1>

        <!-- Seller Number Section -->
        <div class="card mb-4">
            <div class="card-header">Ihre Verkäufernummer</div>
            <div class="card-body row">
                <?php if ($last_seller_number): ?>
                    <div class="col-md-8">
                        <p>Deine aktuelle Verkäufernummer: <?php echo htmlspecialchars($last_seller_number['seller_number']); ?> (Status: <?php echo $last_seller_number['seller_verified'] ? 'frei geschalten' : 'Nicht frei geschalten'; ?>)</p>
                    </div>
                    <div class="col-md-4 text-center">
                        <form method="POST" id="seller-number-actions">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <div class="dropdown mb-2">
                                <select class="form-control" name="action">
                                    <option value="" selected disabled>Bitte wählen</option>
                                    <option value="validate">freischalten</option>
                                    <option value="revoke">Nummer zurückgeben</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Ausführen</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="col-md-12">
                        <p>Du hast keine aktive Verkäufernummer.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Available Bazaars Section -->
        <div class="card mb-4">
            <div class="card-header">Verfügbare Basare</div>
            <div class="card-body">
                <?php if (!empty($available_bazaars)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Basar-ID</th>
                                    <th>Startdatum</th>
                                    <th>Maximale Verkäufer</th>
                                    <th>Aktuelle Verkäufer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($available_bazaars as $bazaar): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($bazaar['id']); ?></td>
                                        <td><?php echo htmlspecialchars($bazaar['startDate']); ?></td>
                                        <td><?php echo htmlspecialchars($bazaar['max_sellers']); ?></td>
                                        <td><?php echo htmlspecialchars($bazaar['current_sellers']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <form method="post">
						<input type="hidden" name="bazaar_id" value="<?php echo htmlspecialchars($bazaar['id']); ?>">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="helperRequest" name="helper_request">
                            <label class="form-check-label" for="helperRequest">Ich möchte mich als Helfer eintragen lassen und eine zusätzliche Nummer erhalten.</label>
                        </div>
                        <div class="form-group mt-3 hidden" id="helperMessageContainer">
                            <label for="helperMessage">Das finden wir super! 😊</label>
                            <textarea class="form-control" id="helperMessage" name="helper_message" rows="3" placeholder="Bitte beschreibe kurz, wie Du uns unterstützen möchtest. Zum Beispiel: &quot;Ich bringe einen Kuchen mit.&quot; oder: &quot;Ich helfe beim Rücksortieren.&quot;"></textarea>
                        </div>
                        <button type="submit" name="request_seller_number" value="<?php echo $bazaar['id']; ?>" class="btn btn-primary mt-3">Verkäufernummer anfordern</button>
                    </form>
                <?php else: ?>
                    <p>Keine Basare derzeit verfügbar.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Product Section -->
        <div class="card">
            <div class="card-header">Ihre Produkte</div>
            <div class="card-body">
                <p>Sie haben <?php echo htmlspecialchars($product_count); ?> Produkte mit Ihrem Konto verknüpft.</p>
                <a href="seller_products.php" class="btn btn-secondary">Produkte verwalten</a>
            </div>
        </div>
    </div>

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
        document.addEventListener('DOMContentLoaded', function () {
            const checkbox = document.getElementById('helperRequest');
            const messageContainer = document.getElementById('helperMessageContainer');
            const messageInput = document.getElementById('helperMessage');

            // Attach the event listener directly
            checkbox.addEventListener('change', function () {
                if (checkbox.checked) {
                    messageContainer.classList.remove('hidden');
                    messageInput.required = true;
                } else {
                    messageContainer.classList.add('hidden');
                    messageInput.required = false;
                }
            });
        });
    </script>
    <script nonce="<?php echo $nonce; ?>">
        // Show the HTML element once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.documentElement.style.visibility = "visible";
        });
    </script>
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>



