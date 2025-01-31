<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true, // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

$conn = get_db_connection();

$alertMessage = "";
$alertMessage_Type = "";

$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'login') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        $alertMessage_Type = 'danger';
        $alertMessage = "CSRF token validation failed.";
        exit;
    }

    // Check if the account is locked due to too many failed attempts
    if (isset($_SESSION['failed_attempts']) && $_SESSION['failed_attempts'] >= $max_attempts) {
        if (time() - $_SESSION['last_attempt_time'] < $lockout_time) {
            $alertMessage_Type = 'danger';
            $alertMessage = "Das Konto ist vorübergehend gesperrt worden. Bitte versuche es später erneut.";
            exit;
        } else {
            $_SESSION['failed_attempts'] = 0; // Reset failed attempts
        }
    }

    // Validate email as username
    $username = trim(filter_input(INPUT_POST, 'username', FILTER_VALIDATE_EMAIL));
    if (!$username) {
        $alertMessage_Type = 'danger';
        $alertMessage = "Ungültiges E-Mail-Format.";
        exit;
    }

    // Secure password handling (no sanitization)
    $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);

    // Use prepared statement
    $stmt = $conn->prepare("SELECT * FROM users WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_id'] = $user['id'];

            // Fetch seller numbers associated with the user
            $seller_numbers = [];
            $seller_stmt = $conn->prepare("SELECT seller_number FROM sellers WHERE user_id = ?");
            $seller_stmt->bind_param("i", $user['id']);
            $seller_stmt->execute();
            $seller_result = $seller_stmt->get_result();

            while ($row = $seller_result->fetch_assoc()) {
                $seller_numbers[] = $row['seller_number'];
            }

            // Assign seller numbers to the session
            $_SESSION['seller_numbers'] = $seller_numbers;

            // Log successful login
            log_action($conn, $user['id'], ucfirst($user['role']) . " logged in", "Username: $username");

            // Reset failed attempts on successful login
            $_SESSION['failed_attempts'] = 0;

            // Check if the user is verified
            if (!$user['user_verified']) {
                $alertMessage_Type = 'danger';
                $alertMessage = "Unverifiziertes Konto. Hast Du den Link in der E-Mail angeklickt?";
            } else {
                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header("location: admin_dashboard.php");
                        break;
                    case 'assistant':
                        header("location: admin_dashboard.php");
                        break;
                    case 'cashier':
                        header("location: cashier.php");
                        break;
                    case 'seller':
                        header("location: seller_dashboard.php");
                        break;
                    default:
                        $alertMessage_Type = 'danger';
                        $alertMessage = "Unbekannte Rolle.";
                        session_destroy();
                        break;
                }
                exit;
            }
        } else {
            $alertMessage_Type = 'danger';
            $alertMessage = "Ungültiger Benutzername oder Passwort.";

            // Increment failed attempts on failed login
            $_SESSION['failed_attempts'] = ($_SESSION['failed_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt_time'] = time();

            // Log failed login attempt
            log_action($conn, 0, "Failed login attempt", "Username: " . htmlspecialchars($username, ENT_QUOTES, 'UTF-8'));
        }
    } else {
        $alertMessage_Type = 'danger';
        $alertMessage = "Ungültiger Benutzername oder Passwort.";

        // Increment failed attempts on failed login
        $_SESSION['failed_attempts'] = ($_SESSION['failed_attempts'] ?? 0) + 1;
        $_SESSION['last_attempt_time'] = time();

        // Log failed login attempt
        log_action($conn, 0, "Failed login attempt", "Username: " . htmlspecialchars($username, ENT_QUOTES, 'UTF-8'));
    }
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
        <nav class="navbar navbar-expand-lg navbar-light">
            <a class="navbar-brand" href="index.php">Basareno<i>Web</i></a>
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
        <div class="container login-container">
            <h2 class="mt-5 text-center">Benutzer Login</h2>
            <?php if ($alertMessage): ?>
                <div class="alert alert-<?php echo htmlspecialchars($alertMessage_Type); ?>"><?php echo htmlspecialchars($alertMessage); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Schliessen">
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