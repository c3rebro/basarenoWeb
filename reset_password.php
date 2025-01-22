<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

require_once 'utilities.php';

$message = '';
$message_type = 'danger'; // Default message type for errors

if (isset($_GET['token']) && isset($_GET['hash'])) {
    $token = $_GET['token'];
    $hash = $_GET['hash'];

    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE reset_token=? AND reset_expiry > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_id = $user['id'];
        $email = $user['username'];

        // Verify the hash
        $expected_hash = generate_hash($email, $user_id);
        if ($hash !== $expected_hash) {
            $message = "Ungültiger Hash.";
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['csrf_token'])) {
            if (!validate_csrf_token($_POST['csrf_token'])) {
                die("CSRF token validation failed.");
            }

            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];

            if (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $message = "Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Zahl enthalten.";
            } elseif ($password !== $confirm_password) {
                $message = "Passwörter stimmen nicht überein.";
            } else {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);

                // Update the user's password
                $stmt = $conn->prepare("UPDATE users SET password_hash=?, reset_token=NULL, reset_expiry=NULL WHERE id=?");
                $stmt->bind_param("si", $password_hash, $user_id);

                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = "Passwort erfolgreich zurückgesetzt.";
                } else {
                    $message = "Fehler beim Zurücksetzen des Passworts: " . $conn->error;
                }
            }
        }
    } else {
        $message = "Ungültiger oder abgelaufener Token.";
    }

    $stmt->close();
    $conn->close();
} else {
    $message = "Ungültige Anfrage.";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Passwort zurücksetzen</title>
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
<div class="container">
    <div class="form-container mt-5">
        <h2 class="text-center">Passwort zurücksetzen</h2>
        <p class="text-center">Bitte geben Sie Ihr neues Passwort ein. Stellen Sie sicher, dass es den Sicherheitsanforderungen entspricht.</p>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form action="reset_password.php?token=<?php echo urlencode($token); ?>&hash=<?php echo urlencode($hash); ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <div class="form-group">
                <label for="password">Neues Passwort:</label>
                <input type="password" class="form-control" id="password" name="password" required>
                <small class="form-text text-muted">Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Zahl enthalten.</small>
            </div>
            <div class="form-group">
                <label for="confirm_password">Passwort bestätigen:</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="reset_password">Passwort zurücksetzen</button>
        </form>
    </div>
</div>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>