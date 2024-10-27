<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

require_once 'config.php';

$conn = get_db_connection();
initialize_database($conn);

// Fetch current settings
$sql = "SELECT * FROM settings WHERE id = 1";
$result = $conn->query($sql);
$currentSettings = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['apply'])) {
        $operationMode = $_POST['operationMode'];
        $wifi_ssid = $_POST['wifi_ssid'] ?? '';
        $wifi_password = $_POST['wifi_password'] ?? '';

        // Update settings in the database
        $sql = "UPDATE settings SET operationMode='$operationMode', wifi_ssid='$wifi_ssid', wifi_password='$wifi_password' WHERE id = 1";
        if ($conn->query($sql) !== TRUE) {
            die("Error updating settings: " . $conn->error);
        }

        // Update hostapd configuration
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

        // Restart services
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
                $hash = hash('sha256', $email . $seller_id . SECRET);
                $updateStmt->bind_param('si', $hash, $seller_id);
                $updateStmt->execute();
            }
            $updateStmt->close();
            $hash_message = "Hashes recalculated and updated successfully!";
        } else {
            $hash_message = "No entries found in the sellers table.";
        }
    }

    if (isset($_POST['edit_secret'])) {
        $new_secret = $_POST['new_secret'];
        $config_content = file_get_contents('config.php');
        $config_content = preg_replace("/define\('SECRET', '.*?'\);/", "define('SECRET', '$new_secret');", $config_content);
        file_put_contents('config.php', $config_content);
        $secret_message = "Secret updated successfully!";
        echo "<script>document.addEventListener('DOMContentLoaded', function() { document.getElementById('current_secret').value = '$new_secret'; });</script>";
    }

    if (isset($_FILES['encrypted_file'])) {
        $encrypted_data = file_get_contents($_FILES['encrypted_file']['tmp_name']);
        $decrypted_content = decrypt_data($encrypted_data, SECRET);
    }
}

$conn->close();

function decrypt_data($data, $secret) {
    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $secret, 0, $iv);
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Systemeinstellungen</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .settings-group {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
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
                <div class="form-group">
                    <label for="wifi_ssid">Hotspot SSID</label>
                    <input type="text" name="wifi_ssid" class="form-control" id="wifi_ssid" value="<?php echo htmlspecialchars($currentSettings['wifi_ssid']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="wifi_password">Hotspot Passwort</label>
                    <input type="password" name="wifi_password" class="form-control" id="wifi_password" value="<?php echo htmlspecialchars($currentSettings['wifi_password']); ?>" required>
                </div>

                <button type="submit" name="apply" class="btn btn-primary mt-3">Anwenden</button>
            </form>
        </div>

        <div class="settings-group">
            <form method="post">
                <h3>Hashes neu berechnen</h3>
                <button type="submit" name="recalculate_hashes" class="btn btn-primary">Recalculate Hashes</button>
                <?php if (isset($hash_message)) { echo "<p>$hash_message</p>"; } ?>
            </form>
        </div>

        <div class="settings-group">
            <form method="post" enctype="multipart/form-data">
                <h3>Datei entschlüsseln</h3>
                <div class="form-group">
                    <label for="encrypted_file">Upload Encrypted File:</label>
                    <input type="file" class="form-control-file" id="encrypted_file" name="encrypted_file" required>
                </div>
                <button type="submit" class="btn btn-primary">Decrypt</button>
                <?php if (isset($decrypted_content)): ?>
                    <div class="mt-4">
                        <h4>Decrypted Content</h4>
                        <textarea class="form-control" rows="10" readonly><?php echo htmlspecialchars($decrypted_content); ?></textarea>
                        <a href="data:text/plain;charset=utf-8,<?php echo urlencode($decrypted_content); ?>" download="decrypted_content.csv" class="btn btn-success mt-3">Save Decrypted File</a>
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
                <button type="submit" name="edit_secret" class="btn btn-warning">Edit Secret</button>
                <?php if ($secret_message) { echo "<p id='secret_message'>$secret_message</p>"; } ?>
            </form>
        </div>

        <a href="dashboard.php" class="btn btn-secondary mt-3">Zurück zum Dashboard</a>
    </div>

    <script>
        function toggleSettings() {
            var operationMode = document.getElementById('operationMode').value;
            var offlineSettings = document.getElementById('offlineSettings');
            offlineSettings.style.display = (operationMode === 'offline') ? 'block' : 'none';
        }

        toggleSettings();
    </script>
</body>
</html>