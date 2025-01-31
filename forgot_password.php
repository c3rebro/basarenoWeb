<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'csrf_token') !== null) {
    debug_log("POST request received.");

    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        debug_log("CSRF token validation failed.");
        die("CSRF token validation failed.");
    }

    $email = filter_input(INPUT_POST, 'email');

    // Check if the email exists in the users table
    if (!$conn) {
        die("Database connection failed.");
    }
    debug_log("Database connection established.");

    $stmt = $conn->prepare("SELECT id FROM users WHERE username=?");
    if (!$stmt) {
        debug_log("Statement preparation failed: " . $conn->error);
        die("Statement preparation failed.");
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_id = $user['id'];

        // Generate a unique token
        $token = bin2hex(random_bytes(16));

        // Store the token in the database with an expiration time
        $stmt = $conn->prepare("UPDATE users SET reset_token=?, reset_expiry=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?");
        if (!$stmt) {
            debug_log("Statement preparation for update failed: " . $conn->error);
            die("Statement preparation for update failed.");
        }
        $stmt->bind_param("si", $token, $user_id);
        $stmt->execute();
        debug_log("Token stored in database.");

        // Use the utility function to send the email
        $send_result = send_reset_password_email($email, $token);
        debug_log("Email sent result: " . $send_result);

        if ($send_result == 'true') {
            $message_type = 'success';
            $message = "Ein Link zum Zurücksetzen des Passworts wurde an Deine E-Mail-Adresse gesendet.";
        } else {
            $message_type = 'danger';
            $message = "Fehler beim Senden der Email. Ist die Adresse korrekt?";
        }
    } else {
        debug_log("Email not found.");
        $message = "Mailadresse nicht gefunden.";
    }

    $stmt->close();
    $conn->close();
    debug_log("Database connection closed.");
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
    <title>Passwort vergessen</title>
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
		<div class="form-container mt-5">
			<h2 class="text-center">Passwort vergessen</h2>
			<p class="text-center">Bitte gib Deine E-Mail-Adresse ein, um einen Link zum Zurücksetzen des Passworts zu erhalten.</p>
			<?php if ($message): ?>
				<div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<form action="forgot_password.php" method="post">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
				<div class="form-group">
					<label for="email">E-Mail:</label>
					<input type="email" class="form-control" id="email" name="email" required>
				</div>
				<button type="submit" class="btn btn-primary btn-block">Link senden</button>
			</form>
		</div>
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
        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script nonce="<?php echo $nonce; ?>">
		// Show the HTML element once the DOM is fully loaded
		document.addEventListener("DOMContentLoaded", function () {
			document.documentElement.style.visibility = "visible";
		});
	</script>
</body>
</html>