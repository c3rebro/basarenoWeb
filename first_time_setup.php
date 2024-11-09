<?php
// Check if config.php exists before including utilities.php
if (file_exists('config.php')) {
    require_once('utilities.php');
}

// Initialize error and success messages
$db_error = '';
$db_success = '';
$mail_error = '';
$mail_success = '';
$setup_error = '';
$setup_success = '';

// Initialize form variables with POST data or defaults
$db_host = isset($_POST['db_host']) ? $_POST['db_host'] : '';
$db_name = isset($_POST['db_name']) ? $_POST['db_name'] : '';
$db_username = isset($_POST['db_username']) ? $_POST['db_username'] : '';
$db_password = isset($_POST['db_password']) ? $_POST['db_password'] : '';
$smtp_from = isset($_POST['smtp_from']) ? $_POST['smtp_from'] : '';
$smtp_from_name = isset($_POST['smtp_from_name']) ? $_POST['smtp_from_name'] : '';
$admin_email = isset($_POST['admin_email']) ? $_POST['admin_email'] : '';
$admin_username = isset($_POST['admin_username']) ? $_POST['admin_username'] : '';
$admin_password = isset($_POST['admin_password']) ? $_POST['admin_password'] : '';
$secret = isset($_POST['secret']) ? $_POST['secret'] : 'Of3lG8HGdf452nF653oFG93hGF93hf';
$base_uri = isset($_POST['base_uri']) ? $_POST['base_uri'] : 'https://www.example.de/bazaar';
$language = isset($_POST['language']) ? $_POST['language'] : 'en';

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Test database connection
    if (isset($_POST['test_db'])) {
        $db_conn = new mysqli($db_host, $db_username, $db_password);
        if ($db_conn->connect_error) {
            $db_error = "Datenbankverbindung fehlgeschlagen: " . htmlspecialchars($db_conn->connect_error);
        } else {
            $db_success = "Datenbankverbindung erfolgreich!";
            $db_conn->close();
        }
    }

    // Test mail settings
    if (isset($_POST['test_mail'])) {
        $subject = "Test-E-Mail";
        $body = "Dies ist eine Test-E-Mail.";
        $headers = "From: " . htmlspecialchars($smtp_from_name) . " <" . htmlspecialchars($smtp_from) . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8";

        if (mail($admin_email, $subject, $body, $headers)) {
            $mail_success = "Test-E-Mail erfolgreich gesendet!";
        } else {
            $mail_error = "Mail-Fehler: E-Mail konnte nicht gesendet werden.";
        }
    }

    // Complete setup
    if (isset($_POST['complete_setup'])) {
        // Ensure no errors before proceeding
        if (!$db_error && !$mail_error) {
            // Create config.php from template
            $config_content = file_get_contents('config.php.template');
            $config_content = str_replace(
				['<?php echo $db_host; ?>', '<?php echo $db_name; ?>', '<?php echo $db_username; ?>', '<?php echo $db_password; ?>', '<?php echo $smtp_from; ?>', '<?php echo $smtp_from_name; ?>', '<?php echo $secret; ?>', '<?php echo $base_uri; ?>', '<?php echo $language; ?>'],
				[htmlspecialchars($db_host), htmlspecialchars($db_name), htmlspecialchars($db_username), htmlspecialchars($db_password), htmlspecialchars($smtp_from), htmlspecialchars($smtp_from_name), htmlspecialchars($secret), htmlspecialchars($base_uri), htmlspecialchars($language)], $config_content
			);
            file_put_contents('config.php', $config_content);

            // Initialize the database and insert settings
            require_once 'config.php';
            $conn = get_db_connection();
            if ($conn) {
                initialize_database($conn);

                // Use prepared statement to create the admin user
                $password_hash = password_hash($admin_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
                $stmt->bind_param("ss", $admin_username, $password_hash);
                if ($stmt->execute()) {
                    // Insert initial settings including Betriebsart
                    $stmt = $conn->prepare("INSERT INTO settings (operationMode, wifi_ssid, wifi_password) VALUES ('online', '', '')");
                    if ($stmt->execute()) {
                        $setup_success = "Ersteinrichtung abgeschlossen. Administrator-Konto erstellt. Weiterleitung zur Login-Seite...";
                        header("refresh:5;url=index.php");
                    } else {
                        $setup_error = "Fehler beim Speichern der Einstellungen: " . htmlspecialchars($conn->error);
                    }
                } else {
                    $setup_error = "Fehler beim Erstellen des Administrator-Kontos: " . htmlspecialchars($conn->error);
                }
                $conn->close();
            } else {
                $setup_error = "Fehler beim Herstellen der Datenbankverbindung.";
            }
        } else {
            $setup_error = "Bitte beheben Sie die oben genannten Fehler, bevor Sie die Einrichtung abschließen.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Ersteinrichtung</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Styling for the setup page */
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin-top: 50px;
            padding: 20px;
            background-color: #ffffff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }
        .form-group label {
            font-weight: bold;
        }
        .btn-primary, .btn-secondary {
            width: 100%;
            margin-bottom: 10px;
        }
        @media (max-width: 768px) {
            .container {
                padding: 15px;
                margin-top: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mt-3">Ersteinrichtung</h2>
        <p class="text-center">Bitte geben Sie die folgenden Informationen ein, um die Ersteinrichtung abzuschließen.</p>
        <!-- Display error and success messages -->
        <?php if ($db_error) { echo "<div class='alert alert-danger'>" . htmlspecialchars($db_error) . "</div>"; } ?>
        <?php if ($db_success) { echo "<div class='alert alert-success'>" . htmlspecialchars($db_success) . "</div>"; } ?>
        <?php if ($mail_error) { echo "<div class='alert alert-danger'>" . htmlspecialchars($mail_error) . "</div>"; } ?>
        <?php if ($mail_success) { echo "<div class='alert alert-success'>" . htmlspecialchars($mail_success) . "</div>"; } ?>
        <?php if ($setup_error) { echo "<div class='alert alert-danger'>" . htmlspecialchars($setup_error) . "</div>"; } ?>
        <?php if ($setup_success) { echo "<div class='alert alert-success'>" . htmlspecialchars($setup_success) . "</div>"; } ?>

        <form action="first_time_setup.php" method="post">
            <!-- Database settings -->
            <h4>Datenbankeinstellungen</h4>
            <div class="form-group">
                <label for="db_host">Datenbank-Host:</label>
                <input type="text" class="form-control" id="db_host" name="db_host" required value="<?php echo htmlspecialchars($db_host); ?>">
            </div>
            <div class="form-group">
                <label for="db_name">Datenbank-Name:</label>
                <input type="text" class="form-control" id="db_name" name="db_name" required value="<?php echo htmlspecialchars($db_name); ?>">
            </div>
            <div class="form-group">
                <label for="db_username">Datenbank-Benutzername:</label>
                <input type="text" class="form-control" id="db_username" name="db_username" required value="<?php echo htmlspecialchars($db_username); ?>">
            </div>
            <div class="form-group">
                <label for="db_password">Datenbank-Passwort:</label>
                <input type="password" class="form-control" id="db_password" name="db_password" required value="<?php echo htmlspecialchars($db_password); ?>">
            </div>
            <button type="submit" class="btn btn-secondary" name="test_db">Datenbankverbindung testen</button>

            <!-- Mail settings -->
            <h4>Mail-Einstellungen</h4>
            <div class="form-group">
                <label for="smtp_from">SMTP Von E-Mail:</label>
                <input type="email" class="form-control" id="smtp_from" name="smtp_from" required value="<?php echo htmlspecialchars($smtp_from); ?>">
            </div>
            <div class="form-group">
                <label for="smtp_from_name">SMTP Von Name:</label>
                <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" required value="<?php echo htmlspecialchars($smtp_from_name); ?>">
            </div>
            <div class="form-group">
                <label for="admin_email">Administrator E-Mail (für Test-E-Mail):</label>
                <input type="email" class="form-control" id="admin_email" name="admin_email" required value="<?php echo htmlspecialchars($admin_email); ?>">
            </div>
            <button type="submit" class="btn btn-secondary" name="test_mail">Mail-Einstellungen testen</button>

            <!-- Admin account settings -->
            <h4>Administrator-Konto</h4>
            <div class="form-group">
                <label for="admin_username">Administrator-Benutzername:</label>
                <input type="text" class="form-control" id="admin_username" name="admin_username" required value="<?php echo htmlspecialchars($admin_username); ?>">
            </div>
            <div class="form-group">
                <label for="admin_password">Administrator-Passwort:</label>
                <input type="password" class="form-control" id="admin_password" name="admin_password" required value="<?php echo htmlspecialchars($admin_password); ?>">
            </div>

            <!-- Additional settings -->
            <h4>Zusätzliche Einstellungen</h4>
            <div class="form-group">
                <label for="secret">Geheimnis:</label>
                <input type="text" class="form-control" id="secret" name="secret" required value="<?php echo htmlspecialchars($secret); ?>">
                <small class="form-text text-muted">Ändern Sie diesen Wert aus Sicherheitsgründen.</small>
            </div>
            <div class="form-group">
                <label for="base_uri">Basis-URI:</label>
                <input type="text" class="form-control" id="base_uri" name="base_uri" required value="<?php echo htmlspecialchars($base_uri); ?>">
            </div>
			<!-- Language settings -->
			<h4>Spracheinstellungen</h4>
			<div class="form-group">
				<label for="language">Sprache:</label>
				<select class="form-control" id="language" name="language" required>
					<option value="en" <?php echo ($language === 'en' ? 'selected' : ''); ?>>English</option>
					<option value="de" <?php echo ($language === 'de' ? 'selected' : ''); ?>>Deutsch</option>
					<!-- Add more languages as needed -->
				</select>
			</div>
            <button type="submit" class="btn btn-primary" name="complete_setup">Ersteinrichtung abschließen</button>
        </form>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>