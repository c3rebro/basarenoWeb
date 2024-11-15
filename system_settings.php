<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

// Load configuration
load_config();

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Fetch current settings
$currentSettings = fetch_current_settings($conn);
$currentSSID = htmlspecialchars($currentSettings['wifi_ssid']);
$currentPassword = htmlspecialchars($currentSettings['wifi_password']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $updates = [];

    if (isset($_POST['edit_base_uri'])) {
        $new_base_uri = sanitize_url($_POST['new_base_uri']);
        $updates['BASE_URI'] = $new_base_uri;
        $base_uri_message = "BASE_URI erfolgreich aktualisiert!";
    }

    if (isset($_POST['edit_debug_mode'])) {
        $new_debug_mode = isset($_POST['new_debug_mode']) ? true : false;
        $updates['DEBUG'] = $new_debug_mode;
        $debug_mode_message = "DEBUG Modus erfolgreich aktualisiert!";
    }

    if (!empty($updates)) {
        update_config($updates);
    }
	
    if (isset($_POST['apply'])) {
        $operationMode = sanitize_input($_POST['operationMode']);
        $wifi_ssid = $operationMode === 'offline' ? sanitize_input($_POST['wifi_ssid']) : '';
        $wifi_password = $operationMode === 'offline' ? sanitize_input($_POST['wifi_password']) : '';

        if (!update_settings($conn, $operationMode, $wifi_ssid, $wifi_password)) {
            die("Fehler beim Aktualisieren der Einstellungen: " . $conn->error);
        }

        // Update hostapd configuration and restart services
        $hostapd_conf = "/etc/hostapd/hostapd.conf";
        $hostapd_content = "interface=wlan0
driver=nl80211
ssid=$wifi_ssid
hw_mode=g
channel=6
wmm_enabled=0
macaddr_acl=0
auth_algs=1
ignore_broadcast_ssid=0
wpa=2
wpa_passphrase=$wifi_password
wpa_key_mgmt=WPA-PSK
wpa_pairwise=TKIP
rsn_pairwise=CCMP";

        file_put_contents($hostapd_conf, $hostapd_content);
        exec("sudo systemctl restart hostapd");
        exec("sudo systemctl restart dnsmasq");

        header("Location: system_settings.php");
        exit;
    }

    if (isset($_POST['recalculate_hashes'])) {
        $sql = "SELECT id, email FROM sellers";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $updateStmt = $conn->prepare("UPDATE sellers SET hash = ? WHERE id = ?");
            while ($row = $result->fetch_assoc()) {
                $seller_id = $row['id'];
                $email = $row['email'];
                $hash = generate_hash($email, $seller_id);
                $updateStmt->bind_param('si', $hash, $seller_id);
                $updateStmt->execute();
            }
            $updateStmt->close();
            $hash_message = "Hashes erfolgreich neu berechnet und aktualisiert!";
        } else {
            $hash_message = "Keine Einträge in der Verkäufer-Tabelle gefunden.";
        }
    }
	
    if (isset($_POST['edit_secret'])) {
        $new_secret = sanitize_input($_POST['new_secret']);
        $updates['SECRET'] = $new_secret;
        $secret_message = "Geheimnis erfolgreich aktualisiert!";
    }

    if (isset($_POST['edit_footer'])) {
        $new_footer = sanitize_footer_content($_POST['new_footer']);
        $updates['FOOTER'] = addcslashes($new_footer, "'");
        $footer_message = "Footer erfolgreich aktualisiert!";
    }

    if (isset($_POST['update_smtp'])) {
        $smtp_from = sanitize_input($_POST['smtp_from']);
        $smtp_from_name = sanitize_input($_POST['smtp_from_name']);
        $updates['SMTP_FROM'] = $smtp_from;
        $updates['SMTP_FROM_NAME'] = $smtp_from_name;
        $smtp_message = "SMTP-Einstellungen erfolgreich aktualisiert!";
    }

    if (isset($_POST['update_db'])) {
        $db_server = sanitize_input($_POST['db_server']);
        $db_username = sanitize_input($_POST['db_username']);
        $db_password = sanitize_input($_POST['db_password']);
        $db_name = sanitize_input($_POST['db_name']);
        $updates['DB_SERVER'] = $db_server;
        $updates['DB_USERNAME'] = $db_username;
        $updates['DB_PASSWORD'] = $db_password;
        $updates['DB_NAME'] = $db_name;
        $db_message = "Datenbankeinstellungen erfolgreich aktualisiert!";
    }

    if (!empty($updates)) {
        update_config($updates);
    }

    if (isset($_FILES['encrypted_file'])) {
        $encrypted_data = file_get_contents($_FILES['encrypted_file']['tmp_name']);
        $decrypted_content = decrypt_data($encrypted_data, SECRET);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Systemeinstellungen</title>
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
        <a class="navbar-brand" href="dashboard.php">Bazaar Administration</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_users.php">Benutzer verwalten</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_bazaar.php">Bazaar verwalten</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_sellers.php">Verkäufer verwalten <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="system_settings.php">Systemeinstellungen</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="system_log.php">Protokolle</a>
                </li>
            </ul>
            <hr class="d-lg-none d-block">
            <ul class="navbar-nav">
                <li class="nav-item ml-lg-auto">
                    <a class="navbar-user" href="#">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($username); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white p-2" href="logout.php">Abmelden</a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <h1 class="text-center mb-4 headline-responsive">Systemeinstellungen</h1>
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
		<div class="settings-group">
			<form method="post">
				<h3>Domain bearbeiten</h3>
				<div class="form-group">
					<label for="current_base_uri">Aktuelle Domain/Pfad</label>
					<input type="text" class="form-control" id="current_base_uri" value="<?php echo BASE_URI; ?>" readonly>
				</div>
				<div class="form-group">
					<label for="new_base_uri">Neue Domain/Pfad</label>
					<input type="text" name="new_base_uri" class="form-control" id="new_base_uri" required>
				</div>
				<button type="submit" name="edit_base_uri" class="btn btn-warning">BASE_URI bearbeiten</button>
				<?php if (isset($base_uri_message)) { echo "<p id='base_uri_message'>$base_uri_message</p>"; } ?>
			</form>
		</div>

		<div class="settings-group">
			<form method="post">
				<h3>DEBUG Modus bearbeiten</h3>
				<div class="form-group form-check">
					<input type="checkbox" class="form-check-input" id="new_debug_mode" name="new_debug_mode" <?php if (DEBUG) echo 'checked'; ?>>
					<label class="form-check-label" for="new_debug_mode">DEBUG Modus aktivieren</label>
				</div>
				<button type="submit" name="edit_debug_mode" class="btn btn-warning">DEBUG Modus bearbeiten</button>
				<?php if (isset($debug_mode_message)) { echo "<p id='debug_mode_message'>$debug_mode_message</p>"; } ?>
			</form>
		</div>
	
        <div class="settings-group">
            <form method="post">
                <h3>Modus</h3>
                <div class="form-group">
                    <label for="operationMode">Modus</label>
                    <select name="operationMode" id="operationMode" class="form-control" id="toggleSettings">
                        <option value="online" <?php if ($currentSettings['operationMode'] == 'online') echo 'selected'; ?>>Online</option>
                        <option value="offline" <?php if ($currentSettings['operationMode'] == 'offline') echo 'selected'; ?>>Offline</option>
                    </select>
                </div>

                <div class="hidden" id="offlineSettings">
                    <h4>Raspberry Pi Steuerung</h4>
                    <button type="submit" name="action" value="shutdown" class="btn btn-danger">Herunterfahren</button>
                    <button type="submit" name="action" value="restart" class="btn btn-warning">Neustarten</button>
                </div>

                <h4>WLAN Hotspot Einstellungen</h4>
                <div class="form-group hidden" id="ssidField">
                    <label for="wifi_ssid">Hotspot SSID</label>
                    <input type="text" name="wifi_ssid" class="form-control" id="wifi_ssid" value="<?php echo $currentSSID; ?>">
                </div>
                <div class="form-group hidden" id="passwordField">
                    <label for="wifi_password">Hotspot Passwort</label>
                    <input type="password" name="wifi_password" class="form-control" id="wifi_password" value="<?php echo $currentPassword; ?>">
                    <input type="checkbox" id="togglePasswordCheckbox"> Passwort anzeigen
                </div>

                <div class="hidden" id="debugExpander">
                    <h5>Debugging Informationen</h5>
                    <table class="table table-borderless">
                        <?php foreach (check_preconditions() as $desc => $status): ?>
                            <tr>
                                <td><?php echo $desc; ?></td>
                                <td class="<?php echo $status ? 'true' : 'false'; ?>">
                                    <?php echo $status ? 'Wahr' : 'Falsch'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <button type="submit" name="apply" class="btn btn-primary mt-3">Anwenden</button>
            </form>
        </div>

        <div class="settings-group">
            <form method="post">
                <h3>Hashes neu berechnen</h3>
                <button type="submit" name="recalculate_hashes" class="btn btn-primary">Hashes neu berechnen</button>
                <?php if (isset($hash_message)) { echo "<p>$hash_message</p>"; } ?>
            </form>
        </div>

        <div class="settings-group">
            <form method="post" enctype="multipart/form-data">
                <h3>Datei entschlüsseln</h3>
                <div class="form-group">
                    <label for="encrypted_file">Verschlüsselte Datei hochladen:</label>
                    <input type="file" class="form-control-file" id="encrypted_file" name="encrypted_file" required>
                </div>
                <button type="submit" class="btn btn-primary">Entschlüsseln</button>
                <?php if (isset($decrypted_content)): ?>
                    <div class="mt-4">
                        <h4>Entschlüsselter Inhalt</h4>
                        <textarea class="form-control" rows="10" readonly><?php echo htmlspecialchars($decrypted_content); ?></textarea>
                        <a href="data:text/plain;charset=utf-8,<?php echo urlencode($decrypted_content); ?>" download="decrypted_content.csv" class="btn btn-success mt-3">Entschlüsselte Datei speichern</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- SMTP Settings -->
        <div class="settings-group">
            <form method="post">
                <h3>SMTP-Einstellungen</h3>
                <div class="form-group">
                    <label for="smtp_from">SMTP Von E-Mail:</label>
                    <input type="email" name="smtp_from" class="form-control" id="smtp_from" value="<?php echo SMTP_FROM; ?>" required>
                </div>
                <div class="form-group">
                    <label for="smtp_from_name">SMTP Von Name:</label>
                    <input type="text" name="smtp_from_name" class="form-control" id="smtp_from_name" value="<?php echo SMTP_FROM_NAME; ?>" required>
                </div>
                <button type="submit" name="update_smtp" class="btn btn-primary">SMTP-Einstellungen aktualisieren</button>
                <?php if (isset($smtp_message)) { echo "<p>$smtp_message</p>"; } ?>
            </form>
        </div>

        <!-- Database Settings -->
        <div class="settings-group">
            <form method="post">
                <h3>Datenbankeinstellungen</h3>
                <div class="form-group">
                    <label for="db_server">Datenbank-Server:</label>
                    <input type="text" name="db_server" class="form-control" id="db_server" value="<?php echo DB_SERVER; ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_username">Datenbank-Benutzername:</label>
                    <input type="text" name="db_username" class="form-control" id="db_username" value="<?php echo DB_USERNAME; ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_password">Datenbank-Passwort:</label>
                    <input type="password" name="db_password" class="form-control" id="db_password" value="<?php echo DB_PASSWORD; ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_name">Datenbank-Name:</label>
                    <input type="text" name="db_name" class="form-control" id="db_name" value="<?php echo DB_NAME; ?>" required>
                </div>
                <button type="submit" name="update_db" class="btn btn-primary">Datenbankeinstellungen aktualisieren</button>
                <?php if (isset($db_message)) { echo "<p>$db_message</p>"; } ?>
            </form>
        </div>
		
        <div class="settings-group">
            <form method="post">
                <h3>Geheimnis bearbeiten</h3>
                <div class="form-group">
                    <label for="current_secret">Aktuelles Geheimnis</label>
                    <input type="text" class="form-control" id="current_secret" value="<?php echo SECRET; ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="new_secret">Neues Geheimnis</label>
                    <input type="text" name="new_secret" class="form-control" id="new_secret" required>
                </div>
                <button type="submit" name="edit_secret" class="btn btn-warning">Geheimnis bearbeiten</button>
                <?php if (isset($secret_message)) { echo "<p id='secret_message'>$secret_message</p>"; } ?>
            </form>
        </div>
        
        <div class="settings-group">
            <form method="post">
                <h3>Footer bearbeiten</h3>
                <div class="form-group">
                    <label for="current_footer">Aktueller Footer</label>
                    <textarea class="form-control" id="current_footer" readonly><?php echo FOOTER; ?></textarea>
                </div>
                <div class="form-group">
                    <label for="new_footer">Neuer Footer</label>
                    <textarea name="new_footer" class="form-control" id="new_footer" required></textarea>
                </div>
                <button type="submit" name="edit_footer" class="btn btn-warning">Footer bearbeiten</button>
                <?php if (isset($footer_message)) { echo "<p id='footer_message'>$footer_message</p>"; } ?>
            </form>
        </div>
    </div>
    
    <!-- Back to Top Button -->
    <div id="back-to-top"><i class="fas fa-arrow-up"></i></div>
    
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
        document.addEventListener('DOMContentLoaded', function() {
            // Function to toggle settings based on operation mode
            function toggleSettings() {
                var operationMode = document.getElementById('operationMode').value;
                var ssidField = document.getElementById('ssidField');
                var passwordField = document.getElementById('passwordField');
                var offlineSettings = document.getElementById('offlineSettings');
                var debugExpander = document.getElementById('debugExpander');

                if (operationMode === 'offline') {
                    ssidField.style.display = 'block';
                    passwordField.style.display = 'block';
                    document.getElementById('wifi_ssid').required = true;
                    document.getElementById('wifi_password').required = true;
                    offlineSettings.style.display = 'block';
                    debugExpander.style.display = 'block';
                } else {
                    ssidField.style.display = 'none';
                    passwordField.style.display = 'none';
                    document.getElementById('wifi_ssid').required = false;
                    document.getElementById('wifi_password').required = false;
                    offlineSettings.style.display = 'none';
                    debugExpander.style.display = 'none';
                }
            }

            // Function to toggle password visibility
            function togglePasswordVisibility() {
                var passwordField = document.getElementById('wifi_password');
                passwordField.type = passwordField.type === 'password' ? 'text' : 'password';
            }

            // Add event listener for operationMode change
            document.getElementById('operationMode').addEventListener('change', toggleSettings);

            // Add event listener for password visibility checkbox
            document.getElementById('togglePasswordCheckbox').addEventListener('change', togglePasswordVisibility);

            // Call toggleSettings on page load to set the initial state
            toggleSettings();
        });
    </script>
    
    <script nonce="<?php echo $nonce; ?>">
        $(document).ready(function() {
            // Function to toggle the visibility of the "Back to Top" button
            function toggleBackToTopButton() {
                const scrollTop = $(window).scrollTop();

                if (scrollTop > 100) {
                    $('#back-to-top').fadeIn();
                } else {
                    $('#back-to-top').fadeOut();
                }
            }

            // Initial check on page load
            toggleBackToTopButton();

            // Show or hide the "Back to Top" button on scroll
            $(window).scroll(function() {
                toggleBackToTopButton();
            });

            // Smooth scroll to top
            $('#back-to-top').click(function() {
                $('html, body').animate({ scrollTop: 0 }, 600);
                return false;
            });
        });
    </script>
</body>
</html>