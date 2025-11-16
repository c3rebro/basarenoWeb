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

$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes
$user_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
	header('Content-Type: application/json');
	
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF Token Fehler", "Anmeldeversuch verhindert");
		header("Refresh: 1; url=login.php");
        //echo json_encode(['success' => false, 'message' => "CSRF token validation failed.", 'type' => 'danger']);
        exit;
    }

    $username = trim(filter_input(INPUT_POST, 'username', FILTER_VALIDATE_EMAIL));
    if (!$username) {
        log_action($conn, $user_id, "Ungültiges E-Mailformat", "Login verhindert: $username");
        echo json_encode(['success' => false, 'message' => "Ungültiges E-Mail-Format.", 'type' => 'danger']);
        exit;
    }

    $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);

    $stmt = $conn->prepare("SELECT id, password_hash, role, user_verified FROM users WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // Check if account is locked due to too many failed attempts (SESSION-based)
        if (isset($_SESSION['failed_attempts']) && $_SESSION['failed_attempts'] >= $max_attempts) {
            if (time() - $_SESSION['last_attempt_time'] < $lockout_time) {
                log_action($conn, $user_id, "Zu viele falsche Anmeldeversuche", "Login verhindert: $username");
                echo json_encode(['success' => false, 'message' => "Konto gesperrt. Bitte versuche es später erneut.", 'type' => 'danger']);
                exit;
            } else {
                $_SESSION['failed_attempts'] = 0; // Reset attempts
            }
        }

        // Verify password
        if (password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_id'] = $user['id'];

            $_SESSION['failed_attempts'] = 0; // Reset attempts on success

            if (!$user['user_verified']) {
                log_action($conn, $user_id, "Anmeldeversuch auf unverifiziertes Konto", "Anmeldeversuch verhindert");
                echo json_encode([
                    'success' => false,
                    'message' => "Unverifiziertes Konto. Hast Du den Link in der E-Mail angeklickt?",
                    'type' => 'danger'
                ]);
                exit;
            }

            // Whitelist-based redirect
            $redirects = [
                'admin' => 'admin_dashboard.php',
                'assistant' => 'seller_dashboard.php',
                'cashier' => 'seller_dashboard.php',
                'seller' => 'seller_dashboard.php'
            ];
            log_action($conn, $user_id, "Anmeldung erfolgreich", "Benutzer: $username");
            echo json_encode(['success' => true, 'redirect' => $redirects[$user['role']] ?? 'index.php']);
            exit;
        } else {
            // Increment failed attempts
            $_SESSION['failed_attempts'] = ($_SESSION['failed_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt_time'] = time();

            log_action($conn, $user_id, "Anmeldung fehlgeschlagen, Benutzer oder Pwd falsch.", "Benutzer: $username");
            echo json_encode(['success' => false, 'message' => "Ungültiger Benutzername oder Passwort.", 'type' => 'danger']);
            exit;
        }
    }

    log_action($conn, $user_id, "Anmeldung fehlgeschlagen, Benutzer oder Pwd falsch.", "Benutzer: $username");
    echo json_encode(['success' => false, 'message' => "Ungültiger Benutzername oder Passwort.", 'type' => 'danger']);
    exit;
}

$conn->close();
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
        <title>Benutzer Login</title>
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
        
        <div class="container login-container">
            <h2 class="mt-5 text-center">Benutzer Login</h2>
			<form id="loginForm" method="post">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
				<div class="form-group">
					<label for="username">Benutzername:</label>
					<input type="text" class="form-control" id="username" name="username" required>
				</div>
				<div class="form-group">
					<label for="password">Passwort:</label>
					<input type="password" class="form-control" id="password" name="password" required>
				</div>
				<button type="submit" class="btn btn-primary btn-block">Anmelden</button>
				<p class="text-center mt-3">
					<a href="forgot_password.php">Passwort vergessen?</a>
				</p>
			</form>
        </div>

		<!-- Toast Container -->
		<div aria-live="polite" aria-atomic="true">
			<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3">
				<!-- Toasts will be dynamically added here -->
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
		<script src="js/utilities.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
		
		<script nonce="<?php echo $nonce; ?>">
			$(document).ready(function () {
				$('#loginForm').on('submit', function (e) {
					e.preventDefault();

					let formData = $(this).serializeArray(); // Convert form data to an array of key-value pairs
					formData.push({ name: 'login', value: '1' }); // Explicitly add the 'login' field
					
					$.post('login.php', formData)
						.done(function (response) {
							let data;
							try {
								data = typeof response === 'string' ? JSON.parse(response) : response;
							} catch (e) {
								showToast('Fehler', 'Ungültige Antwort vom Server.', 'danger');
								console.error('JSON Parsing Error:', e, response);
								return;
							}

							if (data.success) {
								window.location.href = data.redirect;
							} else {
								showToast('Fehler', data.message, data.type || 'danger');
							}
						})
						.fail(function () {
							//showToast('Fehler', 'Fehler beim Senden des Formulars.', 'danger');
                            window.location.href = "login.php";
						});
				});

				// Show the page after DOM is ready
				document.documentElement.style.visibility = "visible";
			});
		</script>
    </body>
</html>