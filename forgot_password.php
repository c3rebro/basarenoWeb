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
load_config();

$message = '';
$message_type = 'danger'; // Default message type for errors

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['csrf_token'])) {
    debug_log("POST request received.");

    if (!validate_csrf_token($_POST['csrf_token'])) {
        debug_log("CSRF token validation failed.");
        die("CSRF token validation failed.");
    }

    $email = $_POST['email'];
    debug_log("Email received: " . $email);

    // Check if the email exists in the users table
    $conn = get_db_connection();
    if (!$conn) {
        debug_log("Database connection failed.");
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
        debug_log("Email found in database.");
        $user = $result->fetch_assoc();
        $user_id = $user['id'];

        // Generate a unique token
        $token = bin2hex(random_bytes(16));
        $hash = generate_hash($email, $user_id);
        debug_log("Token and hash generated.");

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
        $send_result = send_reset_password_email($email, $hash, $token);
        debug_log("Email sent result: " . $send_result);

        if ($send_result == 'true') {
            $message_type = 'success';
            $message = "Ein Link zum Zurücksetzen des Passworts wurde an Ihre E-Mail-Adresse gesendet.";
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
    <meta charset="UTF-8">
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
	<nav class="navbar navbar-expand-lg navbar-light">
		<a class="navbar-brand" href="index.php">Basar-Horrheim.de</a>
		<button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
				<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="navbarNav">
				<ul class="navbar-nav">
						<li class="nav-item">
								<a class="nav-link" href="index.php">Startseite</a>
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
	<div class="container">
		<div class="form-container mt-5">
			<h2 class="text-center">Passwort vergessen</h2>
			<p class="text-center">Bitte geben Sie Ihre E-Mail-Adresse ein, um einen Link zum Zurücksetzen Ihres Passworts zu erhalten.</p>
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

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>