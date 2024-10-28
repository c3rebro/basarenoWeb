<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

require_once 'config.php';

$conn = get_db_connection();
initialize_database($conn);

$error = '';
$success = '';

// Function to check if seller ID exists
function seller_id_exists($conn, $seller_id) {
    $sql = "SELECT id FROM sellers WHERE id='$seller_id'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

// Check if the seller has products
function seller_has_products($conn, $seller_id) {
    $sql = "SELECT COUNT(*) as product_count FROM products WHERE seller_id='$seller_id'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['product_count'] > 0;
}

// Function to get the current bazaar ID
function get_current_bazaar_id($conn) {
    $currentDateTime = date('Y-m-d H:i:s');
    $sql = "SELECT id FROM bazaar WHERE startReqDate <= '$currentDateTime' AND startDate >= '$currentDateTime' LIMIT 1";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id'];
    } else {
        return null;
    }
}

function sanitize_input($input) {
    $input = preg_replace('/[^\x20-\x7E]/', '', $input);
    $input = trim($input);
    return $input;
}

function sanitize_id($input) {
    $input = preg_replace('/\D/', '', $input);
    $input = trim($input);
    return $input;
}

// Handle seller addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_seller'])) {
    $family_name = $conn->real_escape_string($_POST['family_name']);
    $given_name = $conn->real_escape_string($_POST['given_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $street = $conn->real_escape_string($_POST['street']);
    $house_number = $conn->real_escape_string($_POST['house_number']);
    $zip = $conn->real_escape_string($_POST['zip']);
    $city = $conn->real_escape_string($_POST['city']);
    $verified = isset($_POST['verified']) ? 1 : 0;
	$consent = 0;
	
    if (empty($family_name) || empty($email)) {
        $error = "Erforderliche Felder fehlen.";
    } else {
        // Generate a random unique ID between 1 and 10000
        do {
            $seller_id = rand(1, 10000);
            $sql = "SELECT id FROM sellers WHERE id='$seller_id'";
            $result = $conn->query($sql);
        } while ($result->num_rows > 0);

        // Generate a secure hash using the seller's email and ID
        $hash = hash('sha256', $email . $seller_id . SECRET);

        $sql = "INSERT INTO sellers (id, email, reserved, family_name, given_name, phone, street, house_number, zip, city, hash, verified, consent) VALUES ('$seller_id', '$email', 0, '$family_name', '$given_name', '$phone', '$street', '$house_number', '$zip', '$city', '$hash', '$verified', '$consent')";
        if ($conn->query($sql) === TRUE) {
            $success = "Verkäufer erfolgreich hinzugefügt.";
            debug_log("Seller added: ID=$seller_id, Name=$family_name, Email=$email, Verified=$verified");
        } else {
            $error = "Fehler beim Hinzufügen des Verkäufers: " . $conn->error;
            debug_log("Error adding seller: " . $conn->error);
        }
    }
}

// Handle seller update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_seller'])) {
    $seller_id = $conn->real_escape_string($_POST['seller_id']);
    $family_name = $conn->real_escape_string($_POST['family_name']);
    $given_name = $conn->real_escape_string($_POST['given_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $street = $conn->real_escape_string($_POST['street']);
    $house_number = $conn->real_escape_string($_POST['house_number']);
    $zip = $conn->real_escape_string($_POST['zip']);
    $city = $conn->real_escape_string($_POST['city']);
    $verified = isset($_POST['verified']) ? 1 : 0;

    if (empty($family_name) || empty($email)) {
        $error = "Erforderliche Felder fehlen.";
    } else {
        $sql = "UPDATE sellers SET family_name='$family_name', given_name='$given_name', email='$email', phone='$phone', street='$street', house_number='$house_number', zip='$zip', city='$city', verified='$verified' WHERE id='$seller_id'";
        if ($conn->query($sql) === TRUE) {
            $success = "Verkäufer erfolgreich aktualisiert.";
            debug_log("Seller updated: ID=$seller_id, Name=$family_name, Email=$email, Verified=$verified");
        } else {
            $error = "Fehler beim Aktualisieren des Verkäufers: " . $conn->error;
            debug_log("Error updating seller: " . $conn->error);
        }
    }
}

// Handle seller deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_seller'])) {
    $seller_id = $conn->real_escape_string($_POST['seller_id']);
    $delete_products = isset($_POST['delete_products']) ? $_POST['delete_products'] : false;

    if (seller_has_products($conn, $seller_id)) {
        if ($delete_products) {
            // Delete products first
            $sql = "DELETE FROM products WHERE seller_id='$seller_id'";
            if ($conn->query($sql) === TRUE) {
                debug_log("Products deleted for seller: ID=$seller_id");
            } else {
                $error = "Fehler beim Löschen der Produkte: " . $conn->error;
                debug_log("Error deleting products: " . $conn->error);
            }
        } else {
            $error = "Dieser Verkäufer hat noch Produkte. Möchten Sie wirklich fortfahren?";
        }
    }

    if (empty($error)) {
        $sql = "DELETE FROM sellers WHERE id='$seller_id'";
        if ($conn->query($sql) === TRUE) {
            $success = "Verkäufer erfolgreich gelöscht.";
            debug_log("Seller deleted: ID=$seller_id");
        } else {
            $error = "Fehler beim Löschen des Verkäufers: " . $conn->error;
            debug_log("Error deleting seller: " . $conn->error);
        }
    }
}

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_import'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $delimiter = $_POST['delimiter'];
    $encoding = $_POST['encoding'];
    $handle = fopen($file, 'r');
	$consent = 0;
	
    if ($handle !== FALSE) {
        while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            if ($encoding === 'ansi') {
                $data = array_map(function($field) {
                    return iconv('windows-1252', 'UTF-8//IGNORE', $field);
                }, $data);
            }

            $data = array_map('sanitize_input', $data);
            $data[0] = sanitize_id($data[0]);

            if (empty($data[1]) || empty($data[5])) {
                continue;
            }

            $seller_id = $data[0];
            $family_name = $data[1];
            $given_name = $data[2] ?: "Nicht angegeben";
            $city = $data[3] ?: "Nicht angegeben";
            $phone = $data[4] ?: "Nicht angegeben";
            $email = $data[5];

            if (preg_match('/<(.+)>/', $email, $matches)) {
                $email = $matches[1];
            }

            $reserved = 0;
            $street = "Nicht angegeben";
            $house_number = "Nicht angegeben";
            $zip = "Nicht angegeben";
            $hash = hash('sha256', $email . $seller_id . SECRET);
            $verification_token = NULL;
            $verified = 0;

            $sql = "INSERT INTO sellers (id, email, reserved, verification_token, family_name, given_name, phone, street, house_number, zip, city, hash, verified, consent) 
                    VALUES ('$seller_id', '$email', '$reserved', '$verification_token', '$family_name', '$given_name', '$phone', '$street', '$house_number', '$zip', '$city', '$hash', '$verified', '$consent')
                    ON DUPLICATE KEY UPDATE 
                    email='$email', reserved='$reserved', verification_token='$verification_token', family_name='$family_name', given_name='$given_name', phone='$phone', street='$street', house_number='$house_number', zip='$zip', city='$city', hash='$hash', verified='$verified', consent='$consent'";

            if ($conn->query($sql) !== TRUE) {
                echo "Error importing seller with email $email: " . $conn->error;
            }
        }

        fclose($handle);
        echo "Sellers imported successfully. Existing records have been updated.";
    } else {
        echo "Error opening the CSV file.";
    }
}

// Product exist check
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['check_seller_products'])) {
    $seller_id = $conn->real_escape_string($_POST['seller_id']);
    $has_products = seller_has_products($conn, $seller_id);
    echo json_encode(['has_products' => $has_products]);
    exit;
}

// Handle seller checkout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['checkout_seller'])) {
    $seller_id = $conn->real_escape_string($_POST['seller_id']);
    $sql = "UPDATE sellers SET checkout=TRUE WHERE id='$seller_id'";
    if ($conn->query($sql) === TRUE) {
        $success = "Verkäufer erfolgreich ausgecheckt.";
        debug_log("Seller checked out: ID=$seller_id");
    } else {
        $error = "Fehler beim Auschecken des Verkäufers: " . $conn->error;
        debug_log("Error checking out seller: " . $conn->error);
    }
}

// Handle product update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_product'])) {
    $bazaar_id = get_current_bazaar_id($conn);
    $product_id = $conn->real_escape_string($_POST['product_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $size = $conn->real_escape_string($_POST['size']);
    $price = $conn->real_escape_string($_POST['price']);

    $sql = "UPDATE products SET name='$name', size='$size', price='$price', bazaar_id='$bazaar_id' WHERE id='$product_id'";
    if ($conn->query($sql) === TRUE) {
        $success = "Produkt erfolgreich aktualisiert.";
        debug_log("Product updated: ID=$product_id, Name=$name, Size=$size, Basar-ID=$bazaar_id, Price=$price");
    } else {
        $error = "Fehler beim Aktualisieren des Produkts: " . $conn->error;
        debug_log("Error updating product: " . $conn->error);
    }
}

// Handle product deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_product'])) {
    $product_id = $conn->real_escape_string($_POST['product_id']);
    $seller_id = $conn->real_escape_string($_POST['seller_id']);

    $sql = "DELETE FROM products WHERE id='$product_id' AND seller_id='$seller_id'";
    if ($conn->query($sql) === TRUE) {
        $success = "Produkt erfolgreich gelöscht.";
        debug_log("Product deleted: ID=$product_id, Seller ID=$seller_id");
    } else {
        $error = "Fehler beim Löschen des Produkts: " . $conn->error;
        debug_log("Error deleting product: " . $conn->error);
    }
}

// Default filter is "undone"
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'undone';

// Get all sellers
$sql = "SELECT * FROM sellers";
if ($filter == 'done') {
    $sql .= " WHERE checkout=TRUE";
} elseif ($filter == 'undone') {
    $sql .= " WHERE checkout=FALSE";
}

$sellers_result = $conn->query($sql);
debug_log("Fetched sellers with filter '$filter': " . $sellers_result->num_rows);

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verkäufer Verwalten</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-row {
            margin-bottom: 1rem;
        }
        .table-responsive {
            margin-top: 1rem;
        }
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
            }
        }
        .action-cell {
            text-align: center;
            padding-top: 5px;
        }
        .action-dropdown {
            margin-bottom: 5px;
        }
        .done {
            background-color: #d4edda;
        }
        @media print {
            @page {
                size: landscape;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="mt-5">Verkäufer Verwalten</h2>
        <?php if ($error) { echo "<div class='alert alert-danger'>$error</div>"; } ?>
        <?php if ($success) { echo "<div class='alert alert-success'>$success</div>"; } ?>

        <h3 class="mt-5">Neuen Verkäufer hinzufügen</h3>
        <form action="admin_manage_sellers.php" method="post">
            <div class="form-row">
                 <div class="form-group col-md-3">
                    <label for="family_name">Nachname:</label>
                    <input type="text" class="form-control" id="family_name" name="family_name" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="given_name">Vorname:</label>
                    <input type="text" class="form-control" id="given_name" name="given_name">
                </div>
                <div class="form-group col-md-3">
                    <label for="email">E-Mail:</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="phone">Telefon:</label>
                    <input type="text" class="form-control" id="phone" name="phone">
                </div>
                <div class="form-group col-md-3">
                    <label for="street">Straße:</label>
                    <input type="text" class="form-control" id="street" name="street">
                </div>
                <div class="form-group col-md-3">
                    <label for="house_number">Hausnummer:</label>
                    <input type="text" class="form-control" id="house_number" name="house_number">
                </div>
                <div class="form-group col-md-3">
                    <label for="zip">PLZ:</label>
                    <input type="text" class="form-control" id="zip" name="zip">
                </div>
                <div class="form-group col-md-3">
                    <label for="city">Stadt:</label>
                    <input type="text" class="form-control" id="city" name="city">
                </div>
                <div class="form-group col-md-3">
                    <label for="verified">Verifiziert:</label>
                    <input type="checkbox" class="form-control" id="verified" name="verified">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="add_seller">Verkäufer hinzufügen</button>
            <button type="button" class="btn btn-info btn-block" data-toggle="modal" data-target="#importSellersModal">Verkäufer importieren</button>
            <button type="button" class="btn btn-secondary btn-block" id="printVerifiedSellers">Verkäuferliste drucken</button>
        </form>

        <h3 class="mt-5">Verkäuferliste</h3>
        <div class="form-group">
            <label for="filter">Filter:</label>
            <select class="form-control" id="filter" name="filter">
                <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>Alle</option>
                <option value="done" <?php echo $filter == 'done' ? 'selected' : ''; ?>>Abgeschlossen</option>
                <option value="undone" <?php echo $filter == 'undone' ? 'selected' : ''; ?>>Nicht abgeschlossen</option>
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>Verk.Nr.</th>
                        <th>Abr.Nr.</th>
                        <th>Nachname</th>
                        <th>Vorname</th>
                        <th>E-Mail</th>
                        <th>Verifiziert</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($sellers_result->num_rows > 0) {
                        while ($row = $sellers_result->fetch_assoc()) {
                            $hash = $row['hash'];
                            $checkout_class = $row['checkout'] ? 'done' : '';
                            echo "<tr class='$checkout_class'>
                                    <td>{$row['id']}</td>
                                    <td>{$row['checkout_id']}</td>
                                    <td>{$row['family_name']}</td>
                                    <td>{$row['given_name']}</td>
                                    <td>{$row['email']}</td>
                                    <td>" . ($row['verified'] ? 'Ja' : 'Nein') . "</td>
                                    <td class='action-cell'>
                                        <select class='form-control action-dropdown' data-seller-id='{$row['id']}' data-seller-hash='{$hash}'>
                                            <option value=''>Aktion wählen</option>
                                            <option value='edit'>Bearbeiten</option>
                                            <option value='delete'>Löschen</option>
                                            <option value='show_products'>Produkte anzeigen</option>
                                            <option value='create_products'>Produkte erstellen</option>
                                            <option value='checkout'>Checkout</option>
                                        </select>
                                        <button class='btn btn-primary btn-sm execute-action' data-seller-id='{$row['id']}'>Ausführen</button>
                                    </td>
                                  </tr>";
                            echo "<tr id='seller-products-{$row['id']}' style='display:none;'>
                                    <td colspan='11'>
                                        <div class='table-responsive'>
                                            <table class='table table-bordered'>
                                                <thead>
                                                    <tr>
                                                        <th>Produktname</th>
                                                        <th>Größe</th>
                                                        <th>Preis</th>
                                                        <th>Aktionen</th>
                                                    </tr>
                                                </thead>
                                                <tbody id='products-{$row['id']}'>
                                                    <!-- Products will be loaded here via AJAX -->
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                  </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='11'>Keine Verkäufer gefunden.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <a href="dashboard.php" class="btn btn-primary btn-block mt-3 mb-5">Zurück zum Dashboard</a>
    </div>

    <!-- Import Sellers Modal -->
    <div class="modal fade" id="importSellersModal" tabindex="-1" role="dialog" aria-labelledby="importSellersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="max-height: 80vh; overflow-y: auto;">
                <div class="modal-header">
                    <h5 class="modal-title" id="importSellersModalLabel">Verkäufer importieren</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form enctype="multipart/form-data" method="post" action="">
                        <div class="form-group">
                            <label for="csv_file">CSV-Datei auswählen:</label>
                            <input type="file" class="form-control-file" name="csv_file" id="csv_file" accept=".csv" required>
                        </div>
                        <div class="form-group">
                            <label>Trennzeichen:</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="delimiter" value="," checked>
                                <label class="form-check-label">Komma</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="delimiter" value=";">
                                <label class="form-check-label">Semikolon</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Kodierung:</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="encoding" value="utf-8" checked>
                                <label class="form-check-label">UTF-8</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="encoding" value="ansi">
                                <label class="form-check-label">ANSI</label>
                            </div>
                        </div>
                        <div id="preview" class="mt-3"></div>
                        <button type="submit" class="btn btn-primary mt-3" name="confirm_import" id="confirm_import" style="display:none;">Bestätigen und Importieren</button>
                    </form>

                    <h2 class="mt-4">Erwartete CSV-Dateistruktur</h2>
                    <p>Die importierte CSV-Datei darf keine Spaltenüberschriften enthalten.</p>
                    <table class="table table-striped table-bordered">
                        <thead class="thead-dark">
                            <tr>
                                <th>Nr.</th>
                                <th>Familienname</th>
                                <th>Vorname</th>
                                <th>Stadt</th>
                                <th>Telefon</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>101</td>
                                <td>Müller</td>
                                <td>Hans</td>
                                <td>Berlin</td>
                                <td>030 1234567</td>
                                <td>hans.mueller@example.com</td>
                            </tr>
                            <tr>
                                <td>102</td>
                                <td>Schneider</td>
                                <td>Anna</td>
                                <td>Hamburg</td>
                                <td>040 9876543</td>
                                <td>anna.schneider@example.com</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

	<!-- Edit Seller Modal -->
    <div class="modal fade" id="editSellerModal" tabindex="-1" role="dialog" aria-labelledby="editSellerModalLabel" aria-hidden="true"> 
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="admin_manage_sellers.php" method="post">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editSellerModalLabel">Verkäufer bearbeiten</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="seller_id" id="editSellerId">
                        <div class="form-group">
                            <label for="editSellerIdDisplay">Verkäufer-ID:</label>
                            <input type="text" class="form-control" id="editSellerIdDisplay" name="seller_id_display" disabled>
                        </div>
                        <div class="form-group">
                            <label for="editSellerFamilyName">Nachname:</label>
                            <input type="text" class="form-control" id="editSellerFamilyName" name="family_name" required>
                        </div>
                        <div class="form-group">
                            <label for="editSellerGivenName">Vorname:</label>
                            <input type="text" class="form-control" id="editSellerGivenName" name="given_name">
                        </div>
                        <div class="form-group">
                            <label for="editSellerEmail">E-Mail:</label>
                            <input type="email" class="form-control" id="editSellerEmail" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="editSellerPhone">Telefon:</label>
                            <input type="text" class="form-control" id="editSellerPhone" name="phone">
                        </div>
                        <div class="form-group">
                            <label for="editSellerStreet">Straße:</label>
                            <input type="text" class="form-control" id="editSellerStreet" name="street">
                        </div>
                        <div class="form-group">
                            <label for="editSellerHouseNumber">Hausnummer:</label>
                            <input type="text" class="form-control" id="editSellerHouseNumber" name="house_number">
                        </div>
                        <div class="form-group">
                            <label for="editSellerZip">PLZ:</label>
                            <input type="text" class="form-control" id="editSellerZip" name="zip">
                        </div>
                        <div class="form-group">
                            <label for="editSellerCity">Stadt:</label>
                            <input type="text" class="form-control" id="editSellerCity" name="city">
                        </div>
                        <div class="form-group">
                            <label for="editSellerVerified">Verifiziert:</label>
                            <input type="checkbox" class="form-control" id="editSellerVerified" name="verified">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                        <button type="submit" class="btn btn-primary" name="edit_seller">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1" role="dialog" aria-labelledby="editProductModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="admin_manage_sellers.php" method="post">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editProductModalLabel">Produkt bearbeiten</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="product_id" id="editProductId">
                        <div class="form-group">
                            <label for="editProductName">Produktname:</label>
                            <input type="text" class="form-control" id="editProductName" name="name" required>
                        </div>
						<div class="form-group">
                            <label for="editProductSize">Größe:</label>
                            <input type="text" class="form-control" id="editProductSize" name="size">
                        </div>
                        <div class="form-group">
                            <label for="editProductPrice">Preis:</label>
                            <input type="number" class="form-control" id="editProductPrice" name="price" step="0.01" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                        <button type="submit" class="btn btn-primary" name="update_product">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
	
	<!-- Confirm Delete Seller Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmDeleteModalLabel">Verkäufer löschen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Dieser Verkäufer hat noch Produkte. Möchten Sie wirklich fortfahren und alle Produkte löschen?</p>
                    <input type="hidden" id="confirmDeleteSellerId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteButton">Verkäufer und Produkte löschen</button>
                </div>
            </div>
        </div>
    </div>
	
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
	<script>
	function editSeller(id, family_name, given_name, email, phone, street, house_number, zip, city, verified) {
            $('#editSellerId').val(id);
            $('#editSellerIdDisplay').val(id);
            $('#editSellerFamilyName').val(family_name);
            $('#editSellerGivenName').val(given_name);
            $('#editSellerEmail').val(email);
            $('#editSellerPhone').val(phone);
            $('#editSellerStreet').val(street);
            $('#editSellerHouseNumber').val(house_number);
            $('#editSellerZip').val(zip);
            $('#editSellerCity').val(city);
            $('#editSellerVerified').prop('checked', verified);
            $('#editSellerModal').modal('show');
        }

        function toggleProducts(sellerId) {
            const row = $(`#seller-products-${sellerId}`);
            if (row.is(':visible')) {
                row.hide();
            } else {
                loadProducts(sellerId);
                row.show();
            }
        }

        function loadProducts(sellerId) {
            $.ajax({
                url: 'load_seller_products.php',
                method: 'GET',
                data: { seller_id: sellerId },
                success: function(response) {
                    $(`#products-${sellerId}`).html(response);
                },
                error: function() {
                    alert('Fehler beim Laden der Produkte.');
                }
            });
        }

        function editProduct(productId, name, size, price) {
            $('#editProductId').val(productId);
            $('#editProductName').val(name);
            $('#editProductSize').val(size);
            $('#editProductPrice').val(price.toFixed(2));
            $('#editProductModal').modal('show');
        }

        $(document).on('click', '.execute-action', function() {
            const sellerId = $(this).data('seller-id');
            const action = $(`.action-dropdown[data-seller-id="${sellerId}"]`).val();
            const hash = $(`.action-dropdown[data-seller-id="${sellerId}"]`).data('seller-hash');

            if (action === 'edit') {
                const row = $(this).closest('tr');
                const family_name = row.find('td:nth-child(3)').text();
                const given_name = row.find('td:nth-child(4)').text();
                const email = row.find('td:nth-child(5)').text();
                const verified = row.find('td:nth-child(6)').text() === 'Ja';
                editSeller(sellerId, family_name, given_name, email, '', '', '', '', '', verified);
            } else if (action === 'delete') {
                $.post('admin_manage_sellers.php', { check_seller_products: true, seller_id: sellerId }, function(response) {
                    if (response.has_products) {
                        $('#confirmDeleteSellerId').val(sellerId);
                        $('#confirmDeleteModal').modal('show');
                    } else {
                        if (confirm('Möchten Sie diesen Verkäufer wirklich löschen?')) {
                            $.post('admin_manage_sellers.php', { delete_seller: true, seller_id: sellerId }, function(response) {
                                location.reload();
                            });
                        }
                    }
                }, 'json');
            } else if (action === 'show_products') {
                toggleProducts(sellerId);
            } else if (action === 'create_products') {
                window.location.href = `seller_products.php?seller_id=${sellerId}&hash=${hash}`;
            } else if (action === 'checkout') {
                if (confirm('Möchten Sie diesen Verkäufer wirklich auschecken?')) {
                    window.location.href = `checkout.php?seller_id=${sellerId}&hash=${hash}`;
                }
            }
        });

        $('#confirmDeleteButton').on('click', function() {
            const sellerId = $('#confirmDeleteSellerId').val();
            $.post('admin_manage_sellers.php', { delete_seller: true, seller_id: sellerId, delete_products: true }, function(response) {
                location.reload();
            });
        });

        $('#filter').on('change', function() {
            const filter = $(this).val();
            window.location.href = `admin_manage_sellers.php?filter=${filter}`;
        });

        $('#printVerifiedSellers').on('click', function() {
            $.ajax({
                url: 'print_verified_sellers.php',
                method: 'GET',
                success: function(response) {
                    const printWindow = window.open('', '', 'height=600,width=800');
                    printWindow.document.write('<html><head><title>Verifizierte Verkäufer</title>');
                    printWindow.document.write('<link href="css/bootstrap.min.css" rel="stylesheet">');
                    printWindow.document.write('</head><body>');
                    printWindow.document.write(response);
                    printWindow.document.write('</body></html>');
                    printWindow.document.close();
                    printWindow.print();
                },
                error: function() {
                    alert('Fehler beim Laden der verifizierten Verkäufer.');
                }
            });
        });
	</script>
    <script>
    (function() {
        let selectedFile = null;

        function previewCSV() {
            if (!selectedFile) return;

            const delimiter = document.querySelector('input[name="delimiter"]:checked').value;
            const encoding = document.querySelector('input[name="encoding"]:checked').value;

            const reader = new FileReader();
            reader.onload = function(e) {
                let contents = e.target.result;
                if (encoding === 'ansi') {
                    contents = new TextDecoder('windows-1252').decode(new Uint8Array(contents));
                }
                const rows = contents.split('\n');
                let html = '<table class="table table-striped table-bordered"><thead class="thead-dark"><tr><th>Nr.</th><th>Family Name</th><th>Given Name</th><th>City</th><th>Phone</th><th>Email</th></tr></thead><tbody>';
                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].split(delimiter).map(cell => cell.trim());
                    if (cells.length > 1 && cells[1] && cells[5]) {
                        let email = cells[5];
                        if (email.includes('<')) {
                            email = email.match(/<(.+)>/)[1];
                        }
                        html += '<tr>';
                        html += `<td>${cells[0].replace(/\D/g, '')}</td>`;
                        html += `<td>${cells[1]}</td>`;
                        html += `<td>${cells[2] || "Nicht angegeben"}</td>`;
                        html += `<td>${cells[3] || "Nicht angegeben"}</td>`;
                        html += `<td>${cells[4] || "Nicht angegeben"}</td>`;
                        html += `<td>${email}</td>`;
                        html += '</tr>';
                    }
                }
                html += '</tbody></table>';
                document.getElementById('preview').innerHTML = html;
                document.getElementById('confirm_import').style.display = 'block';
            };
            if (encoding === 'ansi') {
                reader.readAsArrayBuffer(selectedFile);
            } else {
                reader.readAsText(selectedFile, 'UTF-8');
            }
        }

        function handleFileSelect(event) {
            selectedFile = event.target.files[0];
            previewCSV();
        }

        function handleOptionChange() {
            previewCSV();
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('#csv_file').addEventListener('change', handleFileSelect);
            document.querySelectorAll('input[name="delimiter"]').forEach(function(elem) {
                elem.addEventListener('change', handleOptionChange);
            });
            document.querySelectorAll('input[name="encoding"]').forEach(function(elem) {
                elem.addEventListener('change', handleOptionChange);
            });
        });
		
    })();
</script>
</body>
</html>