<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

require_once 'utilities.php';

$conn = get_db_connection();
initialize_database($conn);

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;

// Fetch current settings
$currentSettings = fetch_current_settings($conn);
$currentSSID = htmlspecialchars($currentSettings['wifi_ssid']);
$currentPassword = htmlspecialchars($currentSettings['wifi_password']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
        $config_content = file_get_contents('config.php');
        $config_content = preg_replace("/define\('SECRET', '.*?'\);/", "define('SECRET', '$new_secret');", $config_content);
        file_put_contents('config.php', $config_content);
        $secret_message = "Geheimnis erfolgreich aktualisiert!";
        echo "<script>document.addEventListener('DOMContentLoaded', function() { document.getElementById('current_secret').value = '$new_secret'; });</script>";
    }

	if (isset($_POST['edit_footer'])) {
		// Assuming sanitize_footer_content is handling necessary sanitization
		$new_footer = sanitize_footer_content($_POST['new_footer']);
		$config_content = file_get_contents('config.php');
		$config_content = preg_replace("/define\('FOOTER', '.*?'\);/s", "define('FOOTER', '" . addcslashes($new_footer, "'") . "');", $config_content);
		file_put_contents('config.php', $config_content);
		$footer_message = "Footer erfolgreich aktualisiert!";
		echo "<script>document.addEventListener('DOMContentLoaded', function() { document.getElementById('current_footer').value = '" . htmlspecialchars($new_footer, ENT_QUOTES, 'UTF-8') . "'; });</script>";
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
    <link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/all.min.css" rel="stylesheet">
	<link href="css/style.css" rel="stylesheet">
	
</head>
<body>
	<!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="#">Bazaar Administration</a>
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
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white" href="logout.php">Abmelden</a>
                </li>
            </ul>
        </div>
    </nav>
	
    <div class="container">
        <h1 class="text-center mb-4">Systemeinstellungen</h1>

        <div class="settings-group">
            <form method="post">
                <h3>Modus</h3>
                <div class="form-group">
                    <label for="operationMode">Modus</label>
                    <select name="operationMode" id="operationMode" class="form-control" onchange="toggleSettings()">
                        <option value="online" <?php if ($currentSettings['operationMode'] == 'online') echo 'selected'; ?>>Online</option>
                        <option value="offline" <?php if ($currentSettings['operationMode'] == 'offline') echo 'selected'; ?>>Offline</option>
                    </select>
                </div>

                <div id="offlineSettings" style="display: none;">
                    <h4>Raspberry Pi Steuerung</h4>
                    <button type="submit" name="action" value="shutdown" class="btn btn-danger">Herunterfahren</button>
                    <button type="submit" name="action" value="restart" class="btn btn-warning">Neustarten</button>
                </div>

                <h4>WLAN Hotspot Einstellungen</h4>
                <div class="form-group" id="ssidField" style="display: none;">
                    <label for="wifi_ssid">Hotspot SSID</label>
                    <input type="text" name="wifi_ssid" class="form-control" id="wifi_ssid" value="<?php echo $currentSSID; ?>">
                </div>
                <div class="form-group" id="passwordField" style="display: none;">
                    <label for="wifi_password">Hotspot Passwort</label>
                    <input type="password" name="wifi_password" class="form-control" id="wifi_password" value="<?php echo $currentPassword; ?>">
                    <input type="checkbox" onclick="togglePasswordVisibility()"> Passwort anzeigen
                </div>

                <div id="debugExpander" style="display: none;">
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
	
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
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

        function togglePasswordVisibility() {
            var passwordField = document.getElementById('wifi_password');
            passwordField.type = passwordField.type === 'password' ? 'text' : 'password';
        }

        // Call toggleSettings on page load to set the initial state
        toggleSettings();
    </script>
	
	<script>
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