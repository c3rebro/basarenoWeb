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

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    // Check if the account is locked
    if (isset($_SESSION['failed_attempts']) && $_SESSION['failed_attempts'] >= $max_attempts) {
        if (time() - $_SESSION['last_attempt_time'] < $lockout_time) {
            $message_type = 'danger';
            $message = "Ihr Konto ist vorübergehend gesperrt. Bitte versuchen Sie es später erneut.";
            $conn->close();
            exit;
        } else {
            // Reset failed attempts after lockout period
            $_SESSION['failed_attempts'] = 0;
        }
    }
	
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT * FROM users WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Verify the password
        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_id'] = $user['id']; // Store user_id in session

            // Log successful login
            log_action($conn, $user['id'], ucfirst($user['role']) . " logged in", "Username: $username");

            // Reset failed attempts on successful login
            $_SESSION['failed_attempts'] = 0;
			
			// Check if the user is a seller
            if ($user['role'] === 'seller') {
                $stmt = $conn->prepare("SELECT * FROM sellers WHERE email=?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $seller_result = $stmt->get_result();
                
                if ($seller_result->num_rows > 0) {
                    $seller = $seller_result->fetch_assoc();
                    $_SESSION['seller_id'] =  $seller['id'];
                    $_SESSION['seller_hash'] = $seller['hash'];
                    header("location: seller_products.php");
                    exit;
                } else {
                    $message_type = 'danger';
                    $message = "Kein Verkäuferkonto gefunden.";
                }
            } else {
                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header("location: admin_manage_users.php");
                        break;
                    case 'assistant':
                        header("location: dashboard.php");
                        break;
                    case 'cashier':
                        header("location: cashier.php");
                        break;
                    default:
                        $message_type = 'danger';
                        $message = "Unbekannte Rolle";
                        session_destroy();
                        break;
                }
                exit;
            }
        } else {
			$message_type = 'danger';
            $message = "Ungültiger Benutzername oder Passwort";

            // Increment failed attempts on failed login
            $_SESSION['failed_attempts'] = ($_SESSION['failed_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt_time'] = time();

            // Log failed login attempt
            log_action($conn, 0, "Failed login attempt", "Username: $username");
        }
    } else {
		$message_type = 'danger';
        $message = "Ungültiger Benutzername oder Passwort";

        // Increment failed attempts on failed login
        $_SESSION['failed_attempts'] = ($_SESSION['failed_attempts'] ?? 0) + 1;
        $_SESSION['last_attempt_time'] = time();

        // Log failed login attempt
        log_action($conn, 0, "Failed login attempt", "Username: $username");
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Benutzer Login</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container login-container">
        <h2 class="mt-5 text-center">Benutzer Login</h2>
		<?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        <form action="login.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <div class="form-group">
                <label for="username">Benutzername:</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Passwort:</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="login">Anmelden</button>
            <p class="text-center mt-3">
                <a href="forgot_password.php">Passwort vergessen?</a>
            </p>
        </form>
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
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>