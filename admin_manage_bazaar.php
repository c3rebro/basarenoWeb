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

// Ensure the user is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Fetch the latest bazaar data
$sql = "SELECT max_sellers, max_products_per_seller, brokerage, min_price, price_stepping, mailtxt_reqnewsellerid, mailtxt_reqexistingsellerid FROM bazaar ORDER BY startDate DESC LIMIT 1";
$result = $conn->query($sql);
$latestBazaar = $result->fetch_assoc();


$max_sellers = $latestBazaar['max_sellers'] ?? 0;
$max_products_per_seller = $latestBazaar['max_products_per_seller'] ?? 0;
$brokerage = $latestBazaar['brokerage'] ?? 0;
$min_price = $latestBazaar['min_price'] ?? 0;
$price_stepping = $latestBazaar['mailtxt_reqexistingsellerid'] ?? 0;
$mailtxt_reqnewsellerid = $latestBazaar['mailtxt_reqnewsellerid'] ?? '';
$mailtxt_reqexistingsellerid = $latestBazaar['mailtxt_reqexistingsellerid'] ?? '';

// Set default sorting options
$sortBy = filter_input(INPUT_GET, 'sortBy') !== null ? $_GET['sortBy'] : 'startDate';
$sortOrder = filter_input(INPUT_GET, 'sortOrder') !== null ? $_GET['sortOrder'] : 'DESC';

// CSV Export functionality
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'export_csv') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $tables = ['bazaar', 'sellers', 'products'];
    $csv_data = [];

    foreach ($tables as $table) {
        // Use parameterized query to prevent SQL injection
        $stmt = $conn->prepare("SELECT * FROM $table");
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Add table name as the first row
            $csv_data[] = [$table];

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

    $csv_string = '';
    foreach ($csv_data as $line) {
        $escaped_line = array_map(function ($field) {
            if ($field === null) {
                $field = ''; // Convert null to an empty string
            }
            return '"' . str_replace('"', '""', $field) . '"'; // Escape double quotes
        }, $line);
        $csv_string .= implode(',', $escaped_line) . "\n";
    }

    $encrypted_data = encrypt_data($csv_string);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bazaar_export.bdb"');
    echo $encrypted_data;

    // Log CSV export action
    log_action($conn, $_SESSION['user_id'], "Export CSV", "Exported tables: " . implode(', ', $tables));

    exit;
}

// CSV Import functionality
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'import_csv') !== null) {
    $conn = get_db_connection();

    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $encrypted_data = file_get_contents($_FILES['csv_file']['tmp_name']);
        $csv_string = decrypt_data($encrypted_data);

        if ($csv_string === false) {
            $message_type = 'danger';
            $message = "Fehler beim Entschlüsseln der Datei.";
        } else {
            $csv_file = fopen('php://temp', 'r+');
            fwrite($csv_file, $csv_string);
            rewind($csv_file);

            // Disable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS=0");

            $import_option = filter_input(INPUT_POST, 'importoptions');

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
                    // Get expected columns from the database
                    $expected_columns = get_expected_columns($conn, $current_table);

                    // Filter CSV columns to match expected columns
                    $filtered_header = array_intersect($header, $expected_columns);
                    $filtered_row = array_intersect_key($row, array_flip(array_keys($filtered_header)));

                    if (count($filtered_header) !== count($filtered_row)) {
                        $import_success = false;
                        $message_type = 'danger';
                        $message = "Column count mismatch between CSV and database for table $current_table.";
                        break;
                    }

                    $columns = implode(',', $filtered_header);
                    $placeholders = implode(',', array_fill(0, count($filtered_row), '?'));

                    $sql = "INSERT INTO $current_table ($columns) VALUES ($placeholders)";
                    if ($import_option === 'update_existing_only') {
                        $update_columns = implode(',', array_map(function ($col) {
                                    return "$col = VALUES($col)";
                                }, $filtered_header));
                        $sql .= " ON DUPLICATE KEY UPDATE $update_columns";
                    } elseif ($import_option === 'add_new_only') {
                        $sql .= " ON DUPLICATE KEY UPDATE id=id"; // No-op for existing rows
                    } elseif ($import_option === 'update_existing_and_add_new') {
                        $update_columns = implode(',', array_map(function ($col) {
                                    return "$col = VALUES($col)";
                                }, $filtered_header));
                        $sql .= " ON DUPLICATE KEY UPDATE $update_columns";
                    }

                    $stmt = $conn->prepare($sql);
                    if (!$stmt->bind_param(str_repeat('s', count($filtered_row)), ...$filtered_row) || !$stmt->execute()) {
                        $import_success = false;
                        break;
                    }
                }
            }
            fclose($csv_file);

            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS=1");

            if ($import_success) {
                $message_type = 'success';
                $message = "BDB erfolgreich importiert. Die Seite wird in 5 Sekunden aktualisiert.";
                log_action($conn, $user_id, "CSV import successful");

                // Script to auto-show the modal
                echo '<script nonce="' . $nonce . '">';
                echo 'setTimeout(function() {';
                echo '  window.location.href = "admin_manage_bazaar.php";';
                echo '}, 5000);';
                echo '</script>';

                // Log CSV import action
                log_action($conn, $_SESSION['user_id'], "Import CSV", "Imported successfully");
            } else {
                $message_type = 'danger';
                $message = "Fehler beim Importieren der BDB.";
            }
        }
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Hochladen der BDB-Datei.";
    }
}

// Handle bazaar addition
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'add_bazaar') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    // Sanitize input data to prevent XSS
    $startDate = htmlspecialchars($_POST['startDate']);
    $startReqDate = htmlspecialchars($_POST['startReqDate']);
    $brokerage = htmlspecialchars($_POST['brokerage']);
    $min_price = htmlspecialchars($_POST['min_price']);
    $price_stepping = htmlspecialchars($_POST['price_stepping']);
    $max_sellers = htmlspecialchars($_POST['max_sellers']);
    $max_products_per_seller = htmlspecialchars($_POST['max_products_per_seller']);
    $mailtxt_reqnewsellerid = filter_input(INPUT_POST, 'mailtxt_reqnewsellerid');
    $mailtxt_reqexistingsellerid = filter_input(INPUT_POST, 'mailtxt_reqexistingsellerid');

    if (empty($startDate) || empty($startReqDate) || empty($brokerage) || empty($min_price) || empty($price_stepping) || empty($max_sellers) || empty($mailtxt_reqnewsellerid) || empty($mailtxt_reqexistingsellerid) || empty($max_products_per_seller)) {
        $message_type = 'danger';
        $message = "Alle Felder sind erforderlich.";
    } else {
        // Use parameterized query to prevent SQL injection
        $sql = "SELECT COUNT(*) as count FROM bazaar WHERE startDate > ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $startDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result['count'] > 0) {
            $message_type = 'danger';
            $message = "Sie können diesen Bazaar nicht erstellen. Ein neuerer Bazaar existiert bereits.";
        } else {
            //$delete_sellers_sql = "SELECT id FROM sellers WHERE consent = 0";
            //$delete_sellers_result = $conn->query($delete_sellers_sql);
            //while ($seller = $delete_sellers_result->fetch_assoc()) {
            //    $seller_id = $seller['id'];
            //    $conn->query("DELETE FROM products WHERE seller_id = $seller_id");
            //    $conn->query("DELETE FROM sellers WHERE id = $seller_id");
            //}

            //$conn->query("UPDATE sellers SET checkout_id = 0, fee_payed = FALSE");

            // Delete products that are sold for sellers with consent = 1
            //$delete_sold_products_sql = "DELETE FROM products WHERE sold = TRUE AND seller_id IN (SELECT id FROM sellers WHERE consent = 1)";
            //$conn->query($delete_sold_products_sql);

            $brokerage = $brokerage / 100;
            $sql = "INSERT INTO bazaar (startDate, startReqDate, brokerage, min_price, price_stepping, max_sellers, mailtxt_reqnewsellerid, mailtxt_reqexistingsellerid, max_products_per_seller) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssddissi", $startDate, $startReqDate, $brokerage, $min_price, $price_stepping, $max_sellers, $mailtxt_reqnewsellerid, $mailtxt_reqexistingsellerid, $max_products_per_seller);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = "Bazaar erfolgreich hinzugefügt.";
                // Log bazaar addition
                log_action($conn, $_SESSION['user_id'], "Add Bazaar", "Bazaar added with start date: $startDate");
            } else {
                $message_type = 'danger';
                $message = "Fehler beim Hinzufügen des Bazaars: " . $stmt->error;
            }
        }
    }
}

// Handle bazaar modification
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'edit_bazaar') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $bazaar_id = htmlspecialchars($_POST['bazaar_id']);
    $startDate = htmlspecialchars($_POST['startDate']);
    $startReqDate = htmlspecialchars($_POST['startReqDate']);
    $brokerage = htmlspecialchars($_POST['brokerage']);
    $min_price = htmlspecialchars($_POST['min_price']);
    $price_stepping = htmlspecialchars($_POST['price_stepping']);
    $max_sellers = htmlspecialchars($_POST['max_sellers']);
    $max_products_per_seller = htmlspecialchars($_POST['max_products_per_seller']);
    $mailtxt_reqnewsellerid = filter_input(INPUT_POST, 'mailtxt_reqnewsellerid');
    $mailtxt_reqexistingsellerid = filter_input(INPUT_POST, 'mailtxt_reqexistingsellerid');

    if (empty($startDate) || empty($brokerage) || empty($min_price) || empty($price_stepping) || empty($max_sellers) || empty($mailtxt_reqnewsellerid) || empty($mailtxt_reqexistingsellerid) || empty($max_products_per_seller)) {
        $message_type = 'danger';
        $message = "Alle Felder sind erforderlich.";
    } else {
        // Convert brokerage to decimal before saving
        $brokerage = $brokerage / 100;

        // Use parameterized query to prevent SQL injection
        $sql = "UPDATE bazaar SET startDate=?, startReqDate=?, brokerage=?, min_price=?, price_stepping=?, max_sellers=?, mailtxt_reqnewsellerid=?, mailtxt_reqexistingsellerid=?, max_products_per_seller=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssddissii", $startDate, $startReqDate, $brokerage, $min_price, $price_stepping, $max_sellers, $mailtxt_reqnewsellerid, $mailtxt_reqexistingsellerid, $max_products_per_seller, $bazaar_id);
        if ($stmt->execute()) {
            $message_type = 'success';
            $message = "Bazaar erfolgreich aktualisiert.";

            // Log bazaar modification
            log_action($conn, $_SESSION['user_id'], "Edit Bazaar", "Bazaar id $bazaar_id updated with start date: $startDate");
        } else {
            $message_type = 'danger';
            $message = "Fehler beim Aktualisieren des Bazaars: " . $stmt->error;
        }
    }
}

// Handle bazaar removal
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'remove_bazaar') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $bazaar_id = htmlspecialchars($_POST['bazaar_id']);

    // Use parameterized query to prevent SQL injection
    $sql = "DELETE FROM bazaar WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $bazaar_id);
    if ($stmt->execute()) {

        // Log bazaar removal
        log_action($conn, $_SESSION['user_id'], "Remove Bazaar", "Bazaar id $bazaar_id removed");

        echo json_encode(['status' => 'success']);
        exit;
    } else {

        echo json_encode(['status' => 'error']);
        exit;
    }
    exit;
}

// Handle bazaar fetch details
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'action') !== null && $_POST['action'] === 'fetch_bazaar_data') {
    $bazaar_id = filter_input(INPUT_POST, 'bazaar_id');
    $conn = get_db_connection();
    // Fetch products count
    $products_count_all = $conn->query("SELECT COUNT(*) as count FROM products WHERE bazaar_id = $bazaar_id")->fetch_assoc()['count'] ?? 0;
    // Fetch sold products count
    $products_count_sold = $conn->query("SELECT COUNT(*) as count FROM products WHERE bazaar_id = $bazaar_id AND sold = 1")->fetch_assoc()['count'] ?? 0;
    // Fetch total sum of sold products
    $total_sum_sold = $conn->query("SELECT SUM(price) as total FROM products WHERE bazaar_id = $bazaar_id AND sold = 1")->fetch_assoc()['total'] ?? 0;
    // Fetch brokerage percentage for the bazaar
    $brokerage_percentage = $conn->query("SELECT brokerage FROM bazaar WHERE id = $bazaar_id")->fetch_assoc()['brokerage'] ?? 0;
    // Calculate total brokerage for sold products
    $total_brokerage = $total_sum_sold * $brokerage_percentage;

    echo json_encode([
        'products_count_all' => $products_count_all,
        'products_count_sold' => $products_count_sold,
        'total_sum_sold' => number_format($total_sum_sold, 2, ',', '.') . ' €',
        'total_brokerage' => number_format($total_brokerage, 2, ',', '.') . ' €'
    ]);

    exit;
}

// Fetch bazaar details with sorting
// Use parameterized query to prevent SQL injection
$sql = "SELECT * FROM bazaar ORDER BY $sortBy $sortOrder";
$result = $conn->query($sql);

// Log fetching bazaar details
log_action($conn, $_SESSION['user_id'], "Fetch Bazaar Details", "Fetched bazaar details sorted by $sortBy $sortOrder");

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="UTF-8">
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Bazaar Verwalten</title>
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

        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
    </head>
    <body>
		<!-- Navbar -->
		<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
	

        <div class="container">
            <!-- Hidden input for CSRF token -->
            <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <h1 class="text-center mb-4 headline-responsive">Basarverwaltung</h1>
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
    <?php echo $message; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
<?php endif; ?>

            <h3 class="mt-5">Neuen Bazaar hinzufügen</h3>
            <button class="btn btn-primary mb-3 btn-block" type="button" data-toggle="collapse" data-target="#addBazaarForm" aria-expanded="false" aria-controls="addBazaarForm">
                Formular: Neuer Bazaar
            </button>
            <div class="collapse" id="addBazaarForm">
                <div class="card card-body">
                    <form action="admin_manage_bazaar.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="startDate">Veranstaltungsdatum:</label>
                                <input type="date" class="form-control" id="startDate" name="startDate" required>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="startReqDate">Anforderungsdatum:</label>
                                <input type="date" class="form-control" id="startReqDate" name="startReqDate">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-2">
                                <label for="brokerage">Provision (%):</label>
                                <input type="number" step="0.01" class="form-control" id="brokerage" name="brokerage" required value="<?php echo htmlspecialchars($brokerage) * 100; ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="min_price">Mindestpreis (€):</label>
                                <input type="number" step="0.01" class="form-control" id="min_price" name="min_price" required value="<?php echo htmlspecialchars($min_price); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="max_sellers">Maximale Verkäufer:</label>
                                <input type="number" class="form-control" id="max_sellers" name="max_sellers" required value="<?php echo htmlspecialchars($max_sellers); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="max_products_per_seller">Max. Anz. Prod. / Verk.:</label>
                                <input type="number" class="form-control" id="max_products_per_seller" name="max_products_per_seller" required value="<?php echo htmlspecialchars($max_products_per_seller); ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="price_stepping">Preisabstufung (€):</label>
                                <select class="form-control" id="price_stepping" name="price_stepping" required>
                                    <option value="0.01" <?php if ($price_stepping == '0.01') echo 'selected'; ?>>0.01</option>
                                    <option value="0.1" <?php if ($price_stepping == '0.1') echo 'selected'; ?>>0.1</option>
                                    <option value="0.2" <?php if ($price_stepping == '0.2') echo 'selected'; ?>>0.2</option>
                                    <option value="0.25" <?php if ($price_stepping == '0.25') echo 'selected'; ?>>0.25</option>
                                    <option value="0.5" <?php if ($price_stepping == '0.5') echo 'selected'; ?>>0.5</option>
                                    <option value="1.0" <?php if ($price_stepping == '1.0') echo 'selected'; ?>>1.0</option>
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
                                    <textarea class="form-control" id="mailtxt_reqnewsellerid" name="mailtxt_reqnewsellerid" rows="10" required><?php echo htmlspecialchars($mailtxt_reqnewsellerid); ?></textarea>
                                </div>
                                <div class="expander">
                                    <div class="expander-header">
                                        Beschreibung und Beispiel
                                    </div>
                                    <div class="expander-content hidden">
                                        <p>Verfügbare Platzhalter:</p>
                                        <ul>
                                            <li><code>{BASE_URI}</code>: Basis-URL der Anwendung</li>
                                            <li><code>{given_name}</code>: Vorname des Benutzers</li>
                                            <li><code>{family_name}</code>: Nachname des Benutzers</li>
                                            <li><code>{verification_link}</code>: Verifizierungslink</li>
                                            <li><code>{create_products_link}</code>: Produkte erstellen Link</li>
                                            <li><code>{revert_link}</code>: Nummer-Rückgabelink</li>
                                            <li><code>{delete_link}</code>: DSGVO-Löschlink</li>
                                            <li><code>{seller_id}</code>: Verkäufer-ID</li>
                                            <li><code>{hash}</code>: Sicherer Hash</li>
                                        </ul>
                                        <p>Beispiel:</p>
                                        <pre>
<code>&lt;html&gt;&lt;body&gt;
&lt;p&gt;Hallo {given_name} {family_name}.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: &lt;a href='{verification_link}'&gt;{verification_link}&lt;/a&gt;&lt;/p&gt;
&lt;p&gt;Nach der Verifizierung können Sie sich mit Ihrer Mailadresse und Ihrem Passwort anmelden, um Ihre Artikel zu erstellen und Etiketten drucken zu können:&lt;/p&gt;
&lt;p&gt;&lt;a href='{create_products_link}'&gt;Artikel erstellen&lt;/a&gt;&lt;/p&gt;
&lt;p&gt;Bitte beachten Sie auch unsere Informationen für Verkäufer: &lt;a href='https://www.example.de/index.php/informationen/verkaeuferinfos'&gt;Verkäuferinfos&lt;/a&gt;. Bei Rückfragen stehen wir gerne unter der E-Mail-Adresse &lt;a href='mailto:basarteam@example.de'&gt;basarteam@example.de&lt;/a&gt; zur Verfügung.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Zur Durchführung eines erfolgreichen Kleiderbasars benötigen wir viele helfende Hände. Helfer für den Abbau am Samstagnachmittag dürfen sich gerne telefonisch oder per WhatsApp unter 0123 456 7890 melden.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Für alle Helfer besteht die Möglichkeit, bereits ab 13 Uhr einzukaufen. Außerdem bieten wir ein reichhaltiges Kuchenbuffet zum Verkauf an.&lt;/p&gt;
&lt;hr&gt;
&lt;p&gt;Nach der Verifizierung haben Sie die Möglichkeit, sich in Ihrem Konto anzumelden und:&lt;/p&gt;
&lt;ul&gt;
    &lt;li&gt;Artikel an zu legen oder zu ändern&lt;/li&gt;
    &lt;li&gt;Ihre persönlichen Daten zu ändern oder zu aktualisieren&lt;/li&gt;
    &lt;li&gt;Ihren Account mit all Ihren persönlichen Daten zu entfernen&lt;/li&gt;
    &lt;li&gt;Alle von Ihnen angelegten Produkte aus unserem System zu entfernen&lt;/li&gt;
&lt;/ul&gt;
&lt;p&gt;Bitte beachten Sie, dass Löschprozesse von uns nicht rückgängig gemacht werden können.&lt;/p&gt;
&lt;p&gt;&lt;strong&gt;WICHTIG:&lt;/strong&gt; Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter. Wir bitten darum, bei nicht benötigten Verkäufernummern über unseren Rückgabelink &lt;a href='{revert_link}'&gt;Nummer zurückgeben&lt;/a&gt; abzusagen.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;hr&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Wir wünschen Ihnen viel Erfolg beim Basar.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt; 
&lt;p&gt;Mit freundlichen Grüßen&lt;/p&gt;
&lt;p&gt;das Basarteam&lt;/p&gt;</code>
                                        </pre>
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
                                    <textarea class="form-control" id="mailtxt_reqexistingsellerid" name="mailtxt_reqexistingsellerid" rows="10" required><?php echo htmlspecialchars($mailtxt_reqexistingsellerid); ?></textarea>
                                </div>
                                <div class="expander">
                                    <div class="expander-header">
                                        Beschreibung und Beispiel
                                    </div>
                                    <div class="expander-content">
                                        <p>Verfügbare Platzhalter:</p>
                                        <ul>
                                            <li><code>{BASE_URI}</code>: Basis-URL der Anwendung</li>
                                            <li><code>{given_name}</code>: Vorname des Benutzers</li>
                                            <li><code>{family_name}</code>: Nachname des Benutzers</li>
                                            <li><code>{verification_link}</code>: Verifizierungslink</li>
                                            <li><code>{create_products_link}</code>: Produkte erstellen Link</li>
                                            <li><code>{revert_link}</code>: Nummer-Rückgabelink</li>
                                            <li><code>{delete_link}</code>: DSGVO-Löschlink</li>
                                            <li><code>{seller_id}</code>: Verkäufer-ID</li>
                                            <li><code>{hash}</code>: Sicherer Hash</li>
                                        </ul>
                                        <p>Beispiel:</p>
                                        <pre>
<code>&lt;html&gt;&lt;body&gt;
&lt;p&gt;Hallo {given_name} {family_name}.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Wir freuen uns, dass Sie wieder bei unserem Basar mitmachen möchten. Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: &lt;a href='{verification_link}'&gt;{verification_link}&lt;/a&gt;&lt;/p&gt;
&lt;p&gt;Nach der Verifizierung können Sie Ihre Artikel aus dem letzten Basar überprüfen oder ggf. neue erstellen und auch Etiketten drucken falls nötig: &lt;a href='{create_products_link}'&gt;Artikel erstellen&lt;/a&gt;&lt;/p&gt;&lt;br&gt;
&lt;p&gt;Bitte beachten Sie auch unsere Informationen für Verkäufer: &lt;a href='https://www.example.de/index.php/informationen/verkaeuferinfos'&gt;Verkäuferinfos&lt;/a&gt;. Bei Rückfragen stehen wir gerne unter der E-Mail-Adresse &lt;a href='mailto:basarteam@example.de'&gt;basarteam@example.de&lt;/a&gt; zur Verfügung.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Zur Durchführung eines erfolgreichen Kleiderbasars benötigen wir viele helfende Hände. Helfer für den Abbau am Samstagnachmittag dürfen sich gerne telefonisch oder per WhatsApp unter 0123 456 7890 melden.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Für alle Helfer besteht die Möglichkeit, bereits ab 13 Uhr einzukaufen. Außerdem bieten wir ein reichhaltiges Kuchenbuffet zum Verkauf an.&lt;/p&gt;
&lt;hr&gt;
&lt;p&gt;Nach der Verifizierung haben Sie die Möglichkeit, sich in Ihrem Konto anzumelden und:&lt;/p&gt;
&lt;ul&gt;
    &lt;li&gt;Artikel an zu legen oder zu ändern&lt;/li&gt;
    &lt;li&gt;Ihre persönlichen Daten zu ändern oder zu aktualisieren&lt;/li&gt;
    &lt;li&gt;Ihren Account mit all Ihren persönlichen Daten zu entfernen&lt;/li&gt;
    &lt;li&gt;Alle von Ihnen angelegten Produkte aus unserem System zu entfernen&lt;/li&gt;
&lt;/ul&gt;
&lt;p&gt;Bitte beachten Sie, dass Löschprozesse von uns nicht rückgängig gemacht werden können.&lt;/p&gt;
&lt;p&gt;&lt;strong&gt;WICHTIG:&lt;/strong&gt; Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter. Wir bitten darum, bei nicht benötigten Verkäufernummern über unseren Rückgabelink &lt;a href='{revert_link}'&gt;Nummer zurückgeben&lt;/a&gt; abzusagen.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt;
&lt;hr&gt;
&lt;p&gt;&lt;/p&gt;
&lt;p&gt;Wir wünschen Ihnen viel Erfolg beim Basar.&lt;/p&gt;
&lt;p&gt;&lt;/p&gt; 
&lt;p&gt;Mit freundlichen Grüßen&lt;/p&gt;
&lt;p&gt;das Basarteam&lt;/p&gt;</code>
                                        </pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block mb-3" name="add_bazaar">Bazaar hinzufügen</button>
                    </form>
                </div>
            </div>
            <form action="admin_manage_bazaar.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
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
                            <option value="delete_before_importing" class="bg-danger text-white">Vor dem Import löschen</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <button type="submit" class="btn btn-primary btn-block" name="import_csv" id="importCsvButton">Bazaar importieren</button>
                    </div>
                </div>
            </form>
            <form action="admin_manage_bazaar.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
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
                            <th>Basar<br>Startdatum</th>
                            <th>Start der<br>Nummernverg.</th>
                            <th>Prov. in (%)</th>
                            <th>Min.<br>Preis (€)</th>
                            <th>Preisab-<br>stufung (€)</th>
                            <th>Max. Anz.<br>Verkäufer</th>
                            <th>Max. Artikel<br> pro Verk.</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="bazaarTable">
                        <?php while ($row = $result->fetch_assoc()) { ?>
                            <tr id="bazaar-<?php echo htmlspecialchars($row['id']); ?>">
                                <td><?php echo htmlspecialchars($row['id']); ?></td>
                                <td><?php echo htmlspecialchars($row['startDate']); ?></td>
                                <td><?php echo htmlspecialchars($row['startReqDate']); ?></td>
                                <td><?php echo htmlspecialchars($row['brokerage'] * 100); ?></td>
                                <td><?php echo htmlspecialchars($row['min_price']); ?></td>
                                <td><?php echo htmlspecialchars($row['price_stepping']); ?></td>
                                <td><?php echo htmlspecialchars($row['max_sellers']); ?></td>
                                <td><?php echo htmlspecialchars($row['max_products_per_seller']); ?></td>
                                <td class="hidden"><?php echo htmlspecialchars($row['mailtxt_reqnewsellerid']); ?></td>
                                <td class="hidden"><?php echo htmlspecialchars($row['mailtxt_reqexistingsellerid']); ?></td>
                                <td class="text-center">
                                    <select class='form-control action-dropdown' data-bazaar-id="<?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value=''>Aktion wählen</option>
                                        <option value='edit'>Bearbeiten</option>
                                        <option value='view'>Auswertung</option>
                                        <option value='delete'>Entfernen</option>
                                    </select>
                                    <button class='btn btn-primary btn-sm execute-action' data-bazaar-id="<?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?>">Ausführen</button>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit Bazaar Modal -->
        <div class="modal fade" id="editBazaarModal" tabindex="-1" role="dialog" aria-labelledby="editBazaarModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editBazaarModalLabel">Bazaar bearbeiten</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form action="admin_manage_bazaar.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="bazaar_id" id="editBazaarId">
                            <div class="form-group">
                                <label for="editStartDate">Startdatum:</label>
                                <input type="date" class="form-control" id="editStartDate" name="startDate" required>
                            </div>
                            <div class="form-group">
                                <label for="editStartReqDate">Anforderungsdatum:</label>
                                <input type="date" class="form-control" id="editStartReqDate" name="startReqDate">
                            </div>
                            <div class="form-group">
                                <label for="editBrokerage">Provision (%):</label>
                                <input type="number" step="0.01" class="form-control" id="editBrokerage" name="brokerage" required>
                            </div>
                            <div class="form-group">
                                <label for="editMinPrice">Mindestpreis (€):</label>
                                <input type="number" step="0.01" class="form-control" id="editMinPrice" name="min_price" required>
                            </div>
                            <div class="form-group">
                                <label for="editMaxSellers">Maximale Verkäufer:</label>
                                <input type="number" class="form-control" id="editMaxSellers" name="max_sellers" required>
                            </div>
                            <div class="form-group">
                                <label for="editMaxProductsPerSeller">Maximale Produkte pro Verkäufer:</label>
                                <input type="number" class="form-control" id="editMaxProductsPerSeller" name="max_products_per_seller" required>
                            </div>
                            <div class="form-group">
                                <label for="editPriceStepping">Preisabstufung (€):</label>
                                <select class="form-control" id="editPriceStepping" name="price_stepping" required>
                                    <option value="0.01">0.01</option>
                                    <option value="0.1">0.1</option>
                                    <option value="0.2">0.2</option>
                                    <option value="0.25">0.25</option>
                                    <option value="0.5">0.5</option>
                                    <option value="1.0">1.0</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="editMailtxtReqNewSellerId">Mailtext für neue Verkäufer-ID:</label>
                                <textarea class="form-control" id="editMailtxtReqNewSellerId" name="mailtxt_reqnewsellerid" rows="10" required><?php echo $mailtxt_reqnewsellerid; ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="editMailtxtReqExistingSellerId">Mailtext für bestehende Verkäufer-ID:</label>
                                <textarea class="form-control" id="editMailtxtReqExistingSellerId" name="mailtxt_reqexistingsellerid" rows="10" required><?php echo $mailtxt_reqexistingsellerid; ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block" name="edit_bazaar">Speichern</button>
                        </form>
                    </div>
                </div>
            </div>
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

        <script nonce="<?php echo $nonce; ?>">
            $(document).on('click', '.execute-action', function() {
                const bazaarId = $(this).data('bazaar-id');
                const action = $(`.action-dropdown[data-bazaar-id="${bazaarId}"]`).val();

                if (action === 'edit') {
                    // Populate the modal with the bazaar data
                    const row = $(this).closest('tr');
                    $('#editBazaarId').val(bazaarId);
                    $('#editStartDate').val(row.find('td:nth-child(2)').text());
                    $('#editStartReqDate').val(row.find('td:nth-child(3)').text());
                    $('#editBrokerage').val(parseFloat(row.find('td:nth-child(4)').text().replace(',', '.')));
                    $('#editMinPrice').val(row.find('td:nth-child(5)').text());
                    $('#editMaxSellers').val(row.find('td:nth-child(7)').text());
                    $('#editMaxProductsPerSeller').val(row.find('td:nth-child(8)').text());
                    $('#editPriceStepping').val(row.find('td:nth-child(6)').text());
                    $('#editMailtxtReqNewSellerId').val(row.find('td:nth-child(9)').text());
                    $('#editMailtxtReqExistingSellerId').val(row.find('td:nth-child(10)').text());

                    // Open the modal
                    $('#editBazaarModal').modal('show');
                } else if (action === 'view') {
                    // Handle the "Auswerten" action
                    $.ajax({
                        url: 'admin_manage_bazaar.php',
                        type: 'POST',
                        data: { action: 'fetch_bazaar_data', bazaar_id: bazaarId },
                        success: function(response) {
                            const data = JSON.parse(response);
                            if (!data.error) {
                                $('#productsCountAll').val(data.products_count_all);
                                $('#productsCountSold').val(data.products_count_sold);
                                $('#totalSumSold').val(data.total_sum_sold);
                                $('#totalBrokerage').val(data.total_brokerage);

                                // Open the view modal
                                $('#viewBazaarModal').modal('show');
                            } else {
                                alert('Fehler beim Laden der Bazaar-Daten: ' + data.error);
                            }
                        },
                        error: function() {
                            alert('Fehler beim Laden der Bazaar-Daten.');
                        }
                    });
                } else if (action === 'delete') {
                    // Handle the "Entfernen" action
                    if (confirm('Möchten Sie diesen Bazaar wirklich entfernen?')) {
                        $.post('admin_manage_bazaar.php', { remove_bazaar: true, bazaar_id: bazaarId, csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>' }, function(response) {
                            const result = JSON.parse(response);
                            if (result.status === 'success') {
                                $(`#bazaar-${bazaarId}`).remove();
                                alert('Bazaar erfolgreich entfernt.');
                            } else {
                                alert('Fehler beim Entfernen des Bazaars.');
                            }
                        });
                    }
                }
            });
        </script>
        <script nonce="<?php echo $nonce; ?>">
            document.getElementById('importCsvButton').addEventListener('click', function () {
                document.getElementById('mailtxt_reqnewsellerid').removeAttribute('required');
                document.getElementById('mailtxt_reqexistingsellerid').removeAttribute('required');
            });
        </script>
        <script nonce="<?php echo $nonce; ?>">
            $(document).ready(function () {
                // Handle expander
                $('.expander-header').on('click', function (event) {
                    event.stopPropagation();
                    $(this).next('.expander-content').slideToggle();
                });

                // Handle sorting changes
                $('#sortBy, #sortOrder').on('change', function () {
                    var sortBy = $('#sortBy').val();
                    var sortOrder = $('#sortOrder').val();
                    window.location.href = 'admin_manage_bazaar.php?sortBy=' + sortBy + '&sortOrder=' + sortOrder;
                });
            });
        </script>

        <script nonce="<?php echo $nonce; ?>">
            $(document).ready(function () {
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
                $(window).scroll(function () {
                    toggleBackToTopButton();
                });

                // Smooth scroll to top
                $('#back-to-top').click(function () {
                    $('html, body').animate({scrollTop: 0}, 600);
                    return false;
                });
            });
        </script>
    </body>
</html>