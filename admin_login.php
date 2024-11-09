<?php
session_start();
require_once 'utilities.php';

$conn = get_db_connection();
initialize_database($conn);

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $username = $_POST['username'];
    $password = $_POST['password'];

    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND role='admin'");
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

            // Log successful admin login
            log_action($conn, $user['id'], "Admin logged in", "Username: $username");

            header("location: admin_manage_users.php");
            exit;
        } else {
            $error = "Ungültiger Benutzername oder Passwort";

            // Log failed admin login attempt
            log_action($conn, 0, "Failed admin login attempt", "Username: $username");
        }
    } else {
        $error = "Ungültiger Benutzername oder Passwort";

        // Log failed admin login attempt
        log_action($conn, 0, "Failed admin login attempt", "Username: $username");
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Administrator Login</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container login-container">
        <h2 class="mt-5 text-center">Administrator Login</h2>
        <?php if ($error) { echo "<div class='alert alert-danger'>" . htmlspecialchars($error) . "</div>"; } ?>
        <form action="admin_login.php" method="post">
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