<?php
session_start();

if (!file_exists('config.php')) {
    header("location: first_time_setup.php");
    exit;
}

require_once 'config.php';

$conn = get_db_connection();
$first_time_setup = initialize_database($conn);

if ($first_time_setup) {
    header("location: first_time_setup.php");
    exit;
}

$error = '';

// Fetch bazaar dates
$sql = "SELECT startDate, startReqDate FROM bazaar ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);
$bazaar = $result->fetch_assoc();

$currentDate = new DateTime();
$startReqDate = null;
$startDate = null;
$canRequestSellerId = false;
$bazaarOver = true; // Default to bazaar being over

if ($bazaar) {
    $startReqDate = !empty($bazaar['startReqDate']) ? new DateTime($bazaar['startReqDate']) : null;
    $startDate = !empty($bazaar['startDate']) ? new DateTime($bazaar['startDate']) : null;

	$formattedDate = $startReqDate->format('d.m.Y');
	
    if ($startReqDate && $startDate) {
        $canRequestSellerId = $currentDate >= $startReqDate && $currentDate <= $startDate;
        $bazaarOver = $currentDate > $startDate;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Fetch user from the database
    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Verify the password
        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $user['role'];
            header("location: dashboard.php");
        } else {
            $error = "Ungültiger Benutzername oder Passwort";
        }
    } else {
        $error = "Ungültiger Benutzername oder Passwort";
    }
}

$seller_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_seller_id']) && $canRequestSellerId) {
    $email = $_POST['email'];
    $family_name = $_POST['family_name'];
    $given_name = !empty($_POST['given_name']) ? $_POST['given_name'] : 'Nicht angegeben';
    $phone = $_POST['phone'];
    $street = !empty($_POST['street']) ? $_POST['street'] : 'Nicht angegeben';
    $house_number = !empty($_POST['house_number']) ? $_POST['house_number'] : 'Nicht angegeben';
    $zip = !empty($_POST['zip']) ? $_POST['zip'] : 'Nicht angegeben';
    $city = !empty($_POST['city']) ? $_POST['city'] : 'Nicht angegeben';
    $reserve = isset($_POST['reserve']) ? 1 : 0;
    $use_existing_number = $_POST['use_existing_number'] === 'yes';

    if ($use_existing_number) {
        $seller_id = $_POST['seller_id'];
        // Validate seller ID and email
        $sql = "SELECT id FROM sellers WHERE id='$seller_id' AND email='$email'";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
			// Generate a secure hash using the seller's email and ID
			$hash = hash('sha256', $email . $seller_id . SECRET);
            // Send verification email
            $verification_token = bin2hex(random_bytes(16));
            $sql = "UPDATE sellers SET verification_token='$verification_token', verified=0 WHERE id='$seller_id'";

            if ($conn->query($sql) === TRUE) {
				// Send verification email
				$verification_link = BASE_URI . "/verify.php?token=$verification_token&hash=$hash";
				$subject = "Verifizierung Ihrer Verkäufer-ID: $seller_id";
				$message = "<html><body>";
				$message .= "<p>Hallo $given_name $family_name.</p>";
				$message .= "<p></p>";
				$message .= "<p>Wir freuen uns, dass Sie wieder bei unserem Basar mitmachen möchten. Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: <a href='$verification_link'>$verification_link</a></p>";
				$message .= "<p>Nach der Verifizierung können Sie Ihre Artikel erstellen und Etiketten drucken:</p>";
				$message .= "<p><a href='" . BASE_URI . "/seller_products.php?seller_id=$seller_id&hash=$hash'>Artikel erstellen</a></p>";
				$message .= "<p><strong>WICHTIG:</strong> Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter.</p>";
				$message .= "</body></html>";
                $send_result = send_email($email, $subject, $message);
				
                if ($send_result === true) {
                    $seller_message = "Eine E-Mail mit einem Bestätigungslink wurde an $email gesendet.";
                } else {
                    $seller_message = "Fehler beim Senden der Bestätigungs-E-Mail: $send_result";
                }
            } else {
                $seller_message = "Fehler: " . $sql . "<br>" . $conn->error;
            }
        } else {
            $seller_message = "Ungültige Verkäufer-ID oder E-Mail.";
        }
    } else {
        // Generate a random unique ID between 1 and 10000
        do {
            $seller_id = rand(1, 10000);
            $sql = "SELECT id FROM sellers WHERE id='$seller_id'";
            $result = $conn->query($sql);
        } while ($result->num_rows > 0);

		// Generate a secure hash using the seller's email and ID
		$hash = hash('sha256', $email . $seller_id . SECRET);
			
        // Generate a verification token
        $verification_token = bin2hex(random_bytes(16));

        $sql = "INSERT INTO sellers (id, email, reserved, verification_token, family_name, given_name, phone, street, house_number, zip, city, hash) VALUES ('$seller_id', '$email', '$reserve', '$verification_token', '$family_name', '$given_name', '$phone', '$street', '$house_number', '$zip', '$city', '$hash')";

        if ($conn->query($sql) === TRUE) {
			// Send verification email
			$verification_link = BASE_URI . "/verify.php?token=$verification_token&hash=$hash";
			$subject = "Verifizierung Ihrer Verkäufer-ID: $seller_id";
			$message = "<html><body>";
			$message .= "<p>Hallo $given_name $family_name.</p>";
			$message .= "<p></p>";
			$message .= "<p>Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: <a href='$verification_link'>$verification_link</a></p>";
			$message .= "<p>Nach der Verifizierung können Sie Ihre Artikel erstellen und Etiketten drucken:</p>";
			$message .= "<p><a href='" . BASE_URI . "/seller_products.php?seller_id=$seller_id&hash=$hash'>Artikel erstellen</a></p>";
			$message .= "<p><strong>WICHTIG:</strong> Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter.</p>";
			$message .= "</body></html>";

            $send_result = send_email($email, $subject, $message);
            if ($send_result === true) {
                $seller_message = "Eine E-Mail mit einem Bestätigungslink wurde an $email gesendet.";
            } else {
                $seller_message = "Fehler beim Senden der Bestätigungs-E-Mail: $send_result";
            }
        } else {
            $seller_message = "Fehler: " . $sql . "<br>" . $conn->error;
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
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-row {
            margin-bottom: 1rem;
        }
        .form-check-label {
            margin-bottom: 0.5rem;
        }
        .required:after {
            content: "*";
            color: red;
            margin-left: 5px;
        }
    </style>
    <script>
        function toggleSellerIdField() {
            const useExistingNumber = document.getElementById('use_existing_number_yes').checked;
            document.getElementById('seller_id_field').style.display = useExistingNumber ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <div class="container">
        <h1 class="mt-5">Willkommen</h1>
        <p class="lead">Verkäufer können hier Verkäufernummern anfordern und Artikellisten erstellen.</p>

        <?php if ($bazaarOver): ?>
            <div class="alert alert-info">Der Bazaar ist geschlossen. Bitte kommen Sie wieder, wenn der nächste Bazaar stattfindet.</div>
        <?php elseif (!$canRequestSellerId): ?>
            <div class="alert alert-info">Anfragen für neue Verkäufer-IDs sind derzeit noch nicht freigeschalten. Die nächste Nummernvergabe startet am: <?php echo htmlspecialchars($formattedDate); ?></div>
        <?php else: ?>
            <h2 class="mt-5">Verkäufer-ID anfordern</h2>
            <?php if ($seller_message) { echo "<div class='alert alert-info'>$seller_message</div>"; } ?>
            <form action="index.php" method="post">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="family_name" class="required">Nachname:</label>
                        <input type="text" class="form-control" id="family_name" name="family_name" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="given_name">Vorname:</label>
                        <input type="text" class="form-control" id="given_name" name="given_name">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label for="street">Straße:</label>
                        <input type="text" class="form-control" id="street" name="street">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="house_number">Hausnummer:</label>
                        <input type="text" class="form-control" id="house_number" name="house_number">
                    </div>
                </div>
                <div class="form-row">
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
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_yes" value="yes" onclick="toggleSellerIdField()">
                        <label class="form-check-label" for="use_existing_number_yes">
                            Ich habe bereits eine Nummer und möchte diese erneut verwenden
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_no" value="no" onclick="toggleSellerIdField()" checked>
                        <label class="form-check-label" for="use_existing_number_no">
                            Ich möchte eine neue Nummer erhalten
                        </label>
                    </div>
                </div>
                <div class="form-group" id="seller_id_field" style="display: none;">
                    <label for="seller_id">Verkäufer-ID:</label>
                    <input type="text" class="form-control" id="seller_id" name="seller_id">
                </div>
                <button type="submit" class="btn btn-primary btn-block" name="request_seller_id">Verkäufer-ID anfordern</button>
            </form>
            <p class="mt-3 text-muted">* Diese Felder sind Pflichtfelder.</p>
        <?php endif; ?>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>