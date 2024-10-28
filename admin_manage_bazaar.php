<?php
session_start();
require_once 'config.php';
require_once 'utilities';

// Set default sorting options
$sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'startDate';
$sortOrder = isset($_GET['sortOrder']) ? $_GET['sortOrder'] : 'DESC';

// CSV Export functionality
if (isset($_POST['export_csv'])) {
    $conn = get_db_connection();
    $tables = ['bazaar', 'sellers', 'products'];
    $csv_data = [];

    foreach ($tables as $table) {
        $csv_data[] = [$table]; // Add table name
        $result = $conn->query("SELECT * FROM $table");
        if ($result->num_rows > 0) {
            $fields = $result->fetch_fields();
            $header = [];
            foreach ($fields as $field) {
                $header[] = $field->name;
            }
            $csv_data[] = $header;
            while ($row = $result->fetch_assoc()) {
                $csv_data[] = $row;
            }
        }
    }
    $conn->close();

    $csv_string = '';
    foreach ($csv_data as $line) {
        $escaped_line = array_map(function($field) {
            if ($field === null) {
                $field = ''; // Convert null to an empty string
            }
            return '"' . str_replace('"', '""', $field) . '"'; // Escape double quotes
        }, $line);
        $csv_string .= implode(',', $escaped_line) . "\n";
    }

    $encrypted_data = encrypt_data($csv_string, SECRET);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bazaar_export.bdb"');
    echo $encrypted_data;
    exit;
}

// CSV Import functionality
if (isset($_POST['import_csv'])) {
    if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $encrypted_data = file_get_contents($_FILES['csv_file']['tmp_name']);
        $csv_string = decrypt_data($encrypted_data, SECRET);

        if ($csv_string === false) {
            $error = "Fehler beim Entschlüsseln der Datei.";
        } else {
            $csv_file = fopen('php://temp', 'r+');
            fwrite($csv_file, $csv_string);
            rewind($csv_file);

            $conn = get_db_connection();

            // Disable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS=0");

            $import_option = $_POST['importoptions'];

            if ($import_option === 'delete_before_importing') {
                $conn->query("TRUNCATE TABLE products");
                $conn->query("TRUNCATE TABLE sellers");
                $conn->query("TRUNCATE TABLE bazaar");
            }

            $current_table = '';
            $header = null;
            $import_success = true;

            while ($row = fgetcsv($csv_file, 0, ',', '"')) {
                if (count($row) == 1 && in_array($row[0], ['bazaar', 'sellers', 'products'])) {
                    $current_table = $row[0];
                    $header = null; // Reset header for new table
                    continue;
                }

                if ($current_table && $header === null) {
                    $header = $row; // Set header
                    continue;
                }

                if ($current_table && $header !== null) {
                    $columns = implode(',', array_fill(0, count($row), '?'));
                    $update_columns = implode(',', array_map(function($col) { return "$col = VALUES($col)"; }, $header));
                    $sql = "INSERT INTO $current_table VALUES ($columns)";

                    if ($import_option === 'update_existing_only') {
                        $sql .= " ON DUPLICATE KEY UPDATE $update_columns";
                    } elseif ($import_option === 'add_new_only') {
                        $sql .= " ON DUPLICATE KEY UPDATE id=id"; // No-op for existing rows
                    } elseif ($import_option === 'update_existing_and_add_new') {
                        $sql .= " ON DUPLICATE KEY UPDATE $update_columns";
                    }

                    $stmt = $conn->prepare($sql);
                    if (!$stmt->bind_param(str_repeat('s', count($row)), ...$row) || !$stmt->execute()) {
                        $import_success = false;
                        break;
                    }
                }
            }
            fclose($csv_file);

            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS=1");

            $conn->close();

            if ($import_success) {
                $success = "BDB erfolgreich importiert. Die Seite wird in 5 Sekunden aktualisiert.";
                echo "<script>
                        setTimeout(function() {
                            window.location.href = 'admin_manage_bazaar.php';
                        }, 5000);
                      </script>";
            } else {
                $error = "Fehler beim Importieren der BDB.";
            }
        }
    } else {
        $error = "Fehler beim Hochladen der BDB-Datei.";
    }
}

// The rest of your PHP code
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

$conn = get_db_connection();
initialize_database($conn);

$error = '';
$success = '';

// Handle bazaar addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_bazaar'])) {
    $startDate = $_POST['startDate'];
    $startReqDate = $_POST['startReqDate'];
    $brokerage = $_POST['brokerage'];
    $min_price = $_POST['min_price'];
    $price_stepping = $_POST['price_stepping'];
    $max_sellers = $_POST['max_sellers'];
    $mailtxt_reqnewsellerid = $_POST['mailtxt_reqnewsellerid'];
    $mailtxt_reqexistingsellerid = $_POST['mailtxt_reqexistingsellerid'];

    if (empty($startDate) || empty($startReqDate) || empty($brokerage) || empty($min_price) || empty($price_stepping) || empty($max_sellers) || empty($mailtxt_reqnewsellerid) || empty($mailtxt_reqexistingsellerid)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        $sql = "SELECT COUNT(*) as count FROM bazaar WHERE startDate > ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $startDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result['count'] > 0) {
            $error = "Sie können diesen Bazaar nicht erstellen. Ein neuerer Bazaar existiert bereits.";
        } else {
            $delete_sellers_sql = "SELECT id FROM sellers WHERE consent = 0";
            $delete_sellers_result = $conn->query($delete_sellers_sql);
            while ($seller = $delete_sellers_result->fetch_assoc()) {
                $seller_id = $seller['id'];
                $conn->query("DELETE FROM products WHERE seller_id = $seller_id");
                $conn->query("DELETE FROM sellers WHERE id = $seller_id");
            }

            $conn->query("UPDATE sellers SET checkout_id = 0");

            $brokerage = $brokerage / 100;
            $sql = "INSERT INTO bazaar (startDate, startReqDate, brokerage, min_price, price_stepping, max_sellers, mailtxt_reqnewsellerid, mailtxt_reqexistingsellerid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssddiss", $startDate, $startReqDate, $brokerage, $min_price, $price_stepping, $max_sellers, $mailtxt_reqnewsellerid, $mailtxt_reqexistingsellerid);
            if ($stmt->execute()) {
                $success = "Bazaar erfolgreich hinzugefügt.";
            } else {
                $error = "Fehler beim Hinzufügen des Bazaars: " . $stmt->error;
            }
        }
    }
}

// Handle bazaar modification
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_bazaar'])) {
    $bazaar_id = $_POST['bazaar_id'];
    $startDate = $_POST['startDate'];
    $startReqDate = $_POST['startReqDate'];
    $brokerage = $_POST['brokerage'];
    $min_price = $_POST['min_price'];
    $price_stepping = $_POST['price_stepping'];
    $max_sellers = $_POST['max_sellers'];
    $mailtxt_reqnewsellerid = $_POST['mailtxt_reqnewsellerid'];
    $mailtxt_reqexistingsellerid = $_POST['mailtxt_reqexistingsellerid'];

    if (empty($startDate) || empty($startReqDate) || empty($brokerage) || empty($min_price) || empty($price_stepping) || empty($max_sellers) || empty($mailtxt_reqnewsellerid) || empty($mailtxt_reqexistingsellerid)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        $brokerage = $brokerage / 100;
        $sql = "UPDATE bazaar SET startDate=?, startReqDate=?, brokerage=?, min_price=?, price_stepping=?, max_sellers=?, mailtxt_reqnewsellerid=?, mailtxt_reqexistingsellerid=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssddissi", $startDate, $startReqDate, $brokerage, $min_price, $price_stepping, $max_sellers, $mailtxt_reqnewsellerid, $mailtxt_reqexistingsellerid, $bazaar_id);
        if ($stmt->execute()) {
            $success = "Bazaar erfolgreich aktualisiert.";
        } else {
            $error = "Fehler beim Aktualisieren des Bazaars: " . $stmt->error;
        }
    }
}

// Handle bazaar removal
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_bazaar'])) {
    $bazaar_id = $_POST['bazaar_id'];

    $sql = "DELETE FROM bazaar WHERE id='$bazaar_id'";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// Fetch bazaar details with sorting
$sql = "SELECT * FROM bazaar ORDER BY $sortBy $sortOrder";
$result = $conn->query($sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Bazaar Verwalten</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-row {
            margin-bottom: 1rem;
        }
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
            }
        }
        .expander {
            margin-bottom: 1rem;
        }
        .expander-header {
            cursor: pointer;
            background-color: #f8f9fa;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }
        .expander-content {
            display: none;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-top: none;
            border-radius: 0 0 0.25rem 0.25rem;
        }
    </style>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body>
    <div class="container">
        <h2 class="mt-5">Bazaar Verwalten</h2>
        <?php 
        if (!empty($error)) {
            echo "<div class='alert alert-danger'>$error</div>";
        }
        if (!empty($success)) {
            echo "<div class='alert alert-success'>$success</div>";
        }
        ?>

        <h3 class="mt-5">Neuen Bazaar hinzufügen</h3>
        <form action="admin_manage_bazaar.php" method="post">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="startDate">Startdatum:</label>
                    <input type="date" class="form-control" id="startDate" name="startDate" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="startReqDate">Anforderungsdatum:</label>
                    <input type="date" class="form-control" id="startReqDate" name="startReqDate" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label for="brokerage">Provision (%):</label>
                    <input type="number" step="0.01" class="form-control" id="brokerage" name="brokerage" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="min_price">Mindestpreis (€):</label>
                    <input type="number" step="0.01" class="form-control" id="min_price" name="min_price" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="max_sellers">Maximale Verkäufer:</label>
                    <input type="number" class="form-control" id="max_sellers" name="max_sellers" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="price_stepping">Preisabstufung (€):</label>
                    <select class="form-control" id="price_stepping" name="price_stepping" required>
                        <option value="0.01">0.01</option>
                        <option value="0.1">0.1</option>
                        <option value="0.2">0.2</option>
                        <option value="0.25">0.25</option>
                        <option value="0.5">0.5</option>
                        <option value="1.0">1.0</option>
                    </select>
                </div>
            </div>
            <div class="expander">
                <div class="expander-header">
                    Mailtext für neue Verkäufer-ID
                </div>
                <div class="expander-content">
                    <div class="form-group">
                        <label for="mailtxt_reqnewsellerid">HTML Text mit Platzhaltern:</label>
                        <textarea class="form-control" id="mailtxt_reqnewsellerid" name="mailtxt_reqnewsellerid" rows="10" required></textarea>
                    </div>
                    <div class="expander">
                        <div class="expander-header">
                            Beschreibung und Beispiel
                        </div>
                        <div class="expander-content" style="display: none;">
                            <p>Verfügbare Platzhalter:</p>
                            <ul>
                                <li><code>{BASE_URI}</code>: Basis-URL der Anwendung</li>
                                <li><code>{given_name}</code>: Vorname des Benutzers</li>
                                <li><code>{family_name}</code>: Nachname des Benutzers</li>
                                <li><code>{verification_link}</code>: Verifizierungslink</li>
                                <li><code>{seller_id}</code>: Verkäufer-ID</li>
                                <li><code>{hash}</code>: Sicherer Hash</li>
                            </ul>
                            <p>Beispiel:</p>
                            <pre><code>&lt;html&gt;&lt;body&gt;
&lt;p&gt;Hallo {given_name} {family_name}.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: &lt;a href='{verification_link}'&gt;{verification_link}&lt;/a&gt;&lt;/p&gt;
&lt;p&gt;Nach der Verifizierung können Sie Ihre Artikel erstellen und Etiketten drucken:&lt;/p&gt;
&lt;p&gt;&lt;a href='{BASE_URI}/seller_products.php?seller_id={seller_id}&amp;hash={hash}'&gt;Artikel erstellen&lt;/a&gt;&lt;/p&gt;
&lt;p&gt;Bitte beachten Sie auch unsere Informationen für Verkäufer: &lt;a href='https://www.basar-horrheim.de/index.php/informationen/verkaeuferinfos'&gt;Verkäuferinfos&lt;/a&gt; Bei Rückfragen stehen wir gerne unter der E-Mailadresse &lt;a href='mailto:basarteam@basar-horrheim.de'&gt;basarteam@basar-horrheim.de&lt;/a&gt; zur Verfügung.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Zur Durchführung eines erfolgreichen Kleiderbasars benötigen wir viele helfende Hände. Helfer für den Abbau am Samstagnachmittag dürfen sich gerne telefonisch oder per WhatsApp unter 0177 977 6225 melden.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Für alle Helfer besteht die Möglichkeit bereits ab 13 Uhr einzukaufen. Außerdem bieten wir ein reichhaltiges Kuchenbuffet zum Verkauf an.&lt;/p&gt;
&lt;p&gt;&lt;strong&gt;WICHTIG:&lt;/strong&gt; Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter. Bitte beachten Sie auch die Hinweise auf unserer Homepage unter "Verkäufer Infos"&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Wir wünschen Ihnen viel Erfolg beim Basar.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt; 
&lt;p&gt;Mit freundlichen Grüßen&lt;/p&gt;
&lt;p&gt;das Basarteam&lt;/p&gt;
&lt;/body&gt;&lt;/html&gt;
                            </code></pre>
                        </div>
                    </div>
                </div>
            </div>
            <div class="expander">
                <div class="expander-header">
                    Mailtext für bestehende Verkäufer-ID
                </div>
                <div class="expander-content">
                    <div class="form-group">
                        <label for="mailtxt_reqexistingsellerid">HTML Text mit Platzhaltern:</label>
                        <textarea class="form-control" id="mailtxt_reqexistingsellerid" name="mailtxt_reqexistingsellerid" rows="10" required></textarea>
                    </div>
                    <div class="expander">
                        <div class="expander-header">
                            Beschreibung und Beispiel
                        </div>
                        <div class="expander-content" style="display: block;">
                            <p>Verfügbare Platzhalter:</p>
                            <ul>
                                <li><code>{BASE_URI}</code>: Basis-URL der Anwendung</li>
                                <li><code>{given_name}</code>: Vorname des Benutzers</li>
                                <li><code>{family_name}</code>: Nachname des Benutzers</li>
                                <li><code>{verification_link}</code>: Verifizierungslink</li>
                                <li><code>{seller_id}</code>: Verkäufer-ID</li>
                                <li><code>{hash}</code>: Sicherer Hash</li>
                            </ul>
                            <p>Beispiel:</p>
                            <pre><code>&lt;html&gt;&lt;body&gt;
&lt;p&gt;Hallo {given_name} {family_name}.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Wir freuen uns, dass Sie wieder bei unserem Basar mitmachen möchten. Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: &lt;a href='{verification_link}'&gt;{verification_link}&lt;/a&gt;&lt;/p&gt;
&lt;p&gt;Nach der Verifizierung können Sie Ihre Artikel aus dem letzten Basar überprüfen oder ggf. neue erstellen und auch Etiketten drucken falls nötig: &lt;a href='{BASE_URI}/seller_products.php?seller_id={seller_id}&amp;hash={hash}'&gt;Artikel erstellen&lt;/a&gt;&lt;/p&gt;&lt;br&gt;
&lt;p&gt;Bitte beachten Sie auch unsere Informationen für Verkäufer: &lt;a href='https://www.basar-horrheim.de/index.php/informationen/verkaeuferinfos'&gt;Verkäuferinfos&lt;/a&gt; Bei Rückfragen stehen wir gerne unter der E-Mailadresse &lt;a href='mailto:basarteam@basar-horrheim.de'&gt;basarteam@basar-horrheim.de&lt;/a&gt; zur Verfügung.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Zur Durchführung eines erfolgreichen Kleiderbasars benötigen wir viele helfende Hände. Helfer für den Abbau am Samstagnachmittag dürfen sich gerne telefonisch oder per WhatsApp unter 0177 977 6225 melden.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Für alle Helfer besteht die Möglichkeit bereits ab 13 Uhr einzukaufen. Außerdem bieten wir ein reichhaltiges Kuchenbuffet zum Verkauf an.&lt;/p&gt;
&lt;p&gt;&lt;strong&gt;WICHTIG:&lt;/strong&gt; Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter. Bitte beachten Sie auch die Hinweise auf unserer Homepage unter "Verkäufer Infos"&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Wir wünschen Ihnen viel Erfolg beim Basar.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt; 
&lt;p&gt;Mit freundlichen Grüßen&lt;/p&gt;
&lt;p&gt;das Basarteam&lt;/p&gt;
&lt;/body&gt;&lt;/html&gt;
                            </code></pre>
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block mb-3" name="add_bazaar">Bazaar hinzufügen</button>
        </form>

        <form action="admin_manage_bazaar.php" method="post" enctype="multipart/form-data">
            <div class="form-row align-items-end">
                <div class="form-group col-md-6">
                    <label for="csv_file">BDB Datei hochladen:</label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".bdb" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="importoptions">Importoptionen:</label>
                    <select class="form-control" id="importoptions" name="importoptions">
                        <option value="update_existing_only">Nur vorh. aktualisieren</option>
                        <option value="add_new_only">Nur neue hinzufügen</option>
                        <option value="update_existing_and_add_new" selected>Aktualisieren und Hinzufügen</option>
                        <option value="delete_before_importing" style="color: red;">Vor dem Import löschen</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <button type="submit" class="btn btn-primary btn-block" name="import_csv" id="importCsvButton">Bazaar importieren</button>
                </div>
            </div>
        </form>
        <form action="admin_manage_bazaar.php" method="post">
            <button type="submit" class="btn btn-primary btn-block" name="export_csv">Bazaar exportieren</button>
        </form>

        <h3 class="mt-5">Bestehende Bazaars</h3>
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="sortBy">Sortieren nach:</label>
                <select class="form-control" id="sortBy">
                    <option value="startDate" <?php echo $sortBy == 'startDate' ? 'selected' : ''; ?>>Startdatum</option>
                    <option value="id" <?php echo $sortBy == 'id' ? 'selected' : ''; ?>>ID</option>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="sortOrder">Reihenfolge:</label>
                <select class="form-control" id="sortOrder">
                    <option value="DESC" <?php echo $sortOrder == 'DESC' ? 'selected' : ''; ?>>Absteigend</option>
                    <option value="ASC" <?php echo $sortOrder == 'ASC' ? 'selected' : ''; ?>>Aufsteigend</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Startdatum</th>
                        <th>Anforderungsdatum</th>
                        <th>Provision (%)</th>
                        <th>Mindestpreis (€)</th>
                        <th>Preisabstufung (€)</th>
                        <th>Maximale Verkäufer</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody id="bazaarTable">
                    <?php while ($row = $result->fetch_assoc()) { ?>
                        <tr id="bazaar-<?php echo $row['id']; ?>">
                            <td><?php echo htmlspecialchars($row['id']); ?></td>
                            <td><?php echo htmlspecialchars($row['startDate']); ?></td>
                            <td><?php echo htmlspecialchars($row['startReqDate']); ?></td>
                            <td><?php echo htmlspecialchars($row['brokerage'] * 100); ?></td>
                            <td><?php echo htmlspecialchars($row['min_price']); ?></td>
                            <td><?php echo htmlspecialchars($row['price_stepping']); ?></td>
                            <td><?php echo htmlspecialchars($row['max_sellers']); ?></td>
                            <td>
                                <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#editBazaarModal<?php echo $row['id']; ?>">Bearbeiten</button>
                                <button class="btn btn-info btn-sm view-bazaar" data-id="<?php echo $row['id']; ?>" data-toggle="modal" data-target="#viewBazaarModal">Auswertung</button>
                                <button class="btn btn-danger btn-sm remove-bazaar" data-id="<?php echo $row['id']; ?>">Entfernen</button>
                                <!-- Edit Bazaar Modal -->
                                <div class="modal fade" id="editBazaarModal<?php echo $row['id']; ?>" tabindex="-1" role="dialog" aria-labelledby="editBazaarModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="editBazaarModalLabel<?php echo $row['id']; ?>">Bazaar bearbeiten</h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <form action="admin_manage_bazaar.php" method="post">
                                                    <input type="hidden" name="bazaar_id" value="<?php echo $row['id']; ?>">
                                                    <div class="form-group">
                                                        <label for="startDate<?php echo $row['id']; ?>">Startdatum:</label>
                                                        <input type="date" class="form-control" id="startDate<?php echo $row['id']; ?>" name="startDate" value="<?php echo htmlspecialchars($row['startDate']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="startReqDate<?php echo $row['id']; ?>">Anforderungsdatum:</label>
                                                        <input type="date" class="form-control" id="startReqDate<?php echo $row['id']; ?>" name="startReqDate" value="<?php echo htmlspecialchars($row['startReqDate']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="brokerage<?php echo $row['id']; ?>">Provision (%):</label>
                                                        <input type="number" step="0.01" class="form-control" id="brokerage<?php echo $row['id']; ?>" name="brokerage" value="<?php echo htmlspecialchars($row['brokerage'] * 100); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="min_price<?php echo $row['id']; ?>">Mindestpreis (€):</label>
                                                        <input type="number" step="0.01" class="form-control" id="min_price<?php echo $row['id']; ?>" name="min_price" value="<?php echo htmlspecialchars($row['min_price']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="max_sellers<?php echo $row['id']; ?>">Maximale Verkäufer:</label>
                                                        <input type="number" class="form-control" id="max_sellers<?php echo $row['id']; ?>" name="max_sellers" value="<?php echo htmlspecialchars($row['max_sellers']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="price_stepping<?php echo $row['id']; ?>">Preisabstufung (€):</label>
                                                        <select class="form-control" id="price_stepping<?php echo $row['id']; ?>" name="price_stepping" required>
                                                            <option value="0.01" <?php echo $row['price_stepping'] == '0.01' ? 'selected' : ''; ?>>0.01</option>
                                                            <option value="0.1" <?php echo $row['price_stepping'] == '0.1' ? 'selected' : ''; ?>>0.1</option>
                                                            <option value="0.2" <?php echo $row['price_stepping'] == '0.2' ? 'selected' : ''; ?>>0.2</option>
                                                            <option value="0.25" <?php echo $row['price_stepping'] == '0.25' ? 'selected' : ''; ?>>0.25</option>
                                                            <option value="0.5" <?php echo $row['price_stepping'] == '0.5' ? 'selected' : ''; ?>>0.5</option>
                                                            <option value="1.0" <?php echo $row['price_stepping'] == '1.0' ? 'selected' : ''; ?>>1.0</option>
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="mailtxt_reqnewsellerid<?php echo $row['id']; ?>">Mailtext für neue Verkäufer-ID:</label>
                                                        <textarea class="form-control" id="mailtxt_reqnewsellerid<?php echo $row['id']; ?>" name="mailtxt_reqnewsellerid" rows="10" required><?php echo htmlspecialchars($row['mailtxt_reqnewsellerid']); ?></textarea>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="mailtxt_reqexistingsellerid<?php echo $row['id']; ?>">Mailtext für bestehende Verkäufer-ID:</label>
                                                        <textarea class="form-control" id="mailtxt_reqexistingsellerid<?php echo $row['id']; ?>" name="mailtxt_reqexistingsellerid" rows="10" required><?php echo htmlspecialchars($row['mailtxt_reqexistingsellerid']); ?></textarea>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary btn-block" name="edit_bazaar">Speichern</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <a href="dashboard.php" class="btn btn-primary btn-block mt-3 mb-5">Zurück zum Dashboard</a>
    </div>

    <!-- View Bazaar Modal -->
    <div class="modal fade" id="viewBazaarModal" tabindex="-1" role="dialog" aria-labelledby="viewBazaarModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBazaarModalLabel">Bazaar Auswertung</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form>
                        <div class="form-group">
                            <label for="productsCountAll">Gesamtzahl aller Artikel:</label>
                            <input type="text" class="form-control" id="productsCountAll" readonly>
                        </div>
                        <div class="form-group">
                            <label for="productsCountSold">Davon verkauft:</label>
                            <input type="text" class="form-control" id="productsCountSold" readonly>
                        </div>
                        <div class="form-group">
                            <label for="totalSumSold">Gesamtsumme der verkauften Artikel:</label>
                            <input type="text" class="form-control" id="totalSumSold" readonly>
                        </div>
                        <div class="form-group">
                            <label for="totalBrokerage">Gesamtsumme der Provision:</label>
                            <input type="text" class="form-control" id="totalBrokerage" readonly>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" role="dialog" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="errorModalLabel">Fehler</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Sie können diesen Bazaar nicht erstellen. Ein neuerer Bazaar existiert bereits.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('importCsvButton').addEventListener('click', function() {
            document.getElementById('mailtxt_reqnewsellerid').removeAttribute('required');
            document.getElementById('mailtxt_reqexistingsellerid').removeAttribute('required');
        });
    </script>
    <script>
        $(document).ready(function() {
            // Handle expander
            $('.expander-header').on('click', function(event) {
                event.stopPropagation();
                $(this).next('.expander-content').slideToggle();
            });
            
            // Handle removal of bazaar
            $('.remove-bazaar').on('click', function() {
                var bazaarId = $(this).data('id');
                if (confirm('Sind Sie sicher, dass Sie diesen Bazaar entfernen möchten?')) {
                    $.ajax({
                        url: 'admin_manage_bazaar.php',
                        type: 'POST',
                        data: {
                            remove_bazaar: true,
                            bazaar_id: bazaarId
                        },
                        success: function(response) {
                            var data = JSON.parse(response);
                            if (data.status === 'success') {
                                $('#bazaar-' + bazaarId).remove();
                            } else {
                                alert('Fehler beim Entfernen des Bazaars.');
                            }
                        },
                        error: function() {
                            alert('Fehler beim Entfernen des Bazaars.');
                        }
                    });
                }
            });

            // Handle viewing bazaar details
            $('.view-bazaar').on('click', function() {
                var bazaar_id = $(this).data('id');
                $.ajax({
                    url: 'admin_manage_bazaar.php',
                    type: 'POST',
                    data: {
                        action: 'fetch_bazaar_data',
                        bazaar_id: bazaar_id
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        $('#productsCountAll').val(data.products_count_all);
                        $('#productsCountSold').val(data.products_count_sold);
                        $('#totalSumSold').val(data.total_sum_sold);
                        $('#totalBrokerage').val(data.total_brokerage);
                    }
                });
            });

            // Handle sorting changes
            $('#sortBy, #sortOrder').on('change', function() {
                var sortBy = $('#sortBy').val();
                var sortOrder = $('#sortOrder').val();
                window.location.href = 'admin_manage_bazaar.php?sortBy=' + sortBy + '&sortOrder=' + sortOrder;
            });
        });
    </script>
</body>
</html>