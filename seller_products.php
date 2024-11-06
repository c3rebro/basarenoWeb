<?php
session_start();
require_once 'utilities.php';

if (!isset($_GET['seller_id']) || !isset($_GET['hash'])) {
    echo "Kein Verkäufer-ID oder Hash angegeben.";
    exit();
}

$seller_id = $_GET['seller_id'];
$hash = $_GET['hash'];

$conn = get_db_connection();
$sql = "SELECT * FROM sellers WHERE id=? AND hash=? AND verified=1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $seller_id, $hash);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
            <title>Verkäufer-ID Verifizierung</title>
            <link href='css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container'>
                <div class='alert alert-warning mt-5'>
                    <h4 class='alert-heading'>Ungültige oder nicht verifizierte Verkäufer-ID oder Hash.</h4>
                    <p>Bitte überprüfen Sie Ihre Verkäufer-ID und versuchen Sie es erneut.</p>
                    <hr>
                    <p class='mb-0'>Haben Sie auf den Verifizierungslink in der E-Mail geklickt?</p>
                </div>
            </div>
            <script src='js/jquery-3.7.1.min.js'></script>
            <script src='js/popper.min.js'></script>
            <script src='js/bootstrap.min.js'></script>
        </body>
        </html>
        ";
    exit();
}

// Fetch the current bazaar ID
$bazaar_id = get_current_bazaar_id($conn);

// Fetch max products per seller from the bazaar
$sql = "SELECT max_products_per_seller FROM bazaar WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bazaar_id);
$stmt->execute();
$result = $stmt->get_result();
$bazaar_settings = $result->fetch_assoc();
$max_products_per_seller = $bazaar_settings['max_products_per_seller'];

// Check the current number of products for this seller
$sql = "SELECT COUNT(*) as product_count FROM products WHERE seller_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$result = $stmt->get_result();
$current_product_count = $result->fetch_assoc()['product_count'];

// Handle product creation form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    if ($current_product_count >= $max_products_per_seller) {
        echo "<div class='alert alert-danger'>Sie haben die maximale Anzahl an Artikeln erreicht, die Sie erstellen können.</div>";
    } else {
        $name = $conn->real_escape_string($_POST['name']);
        $size = $conn->real_escape_string($_POST['size']);
        $price = $conn->real_escape_string($_POST['price']);

        $rules = get_bazaar_pricing_rules($conn, $bazaar_id);
        $min_price = $rules['min_price'];
        $price_stepping = $rules['price_stepping'];

        // Validate price
        if ($price < $min_price) {
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelector('#priceValidationModal .modal-body').textContent = 'Der eingegebene Preis ist niedriger als der Mindestpreis von ' + $min_price + ' €.';
                    $('#priceValidationModal').modal('show');
                });
            </script>";
        } elseif (fmod($price, $price_stepping) != 0) {
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelector('#priceValidationModal .modal-body').textContent = 'Der Preis muss in Schritten von ' + $price_stepping + ' € eingegeben werden.';
                    $('#priceValidationModal').modal('show');
                });
            </script>";
        } else {
            // Generate a unique EAN-13 barcode
            do {
                $barcode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT) . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT); // Ensure 12-digit barcode as string
                $checkDigit = calculateCheckDigit($barcode);
                $barcode .= $checkDigit; // Append the check digit to make it a valid EAN-13 barcode
                $sql = "SELECT id FROM products WHERE barcode=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $barcode);
                $stmt->execute();
                $result = $stmt->get_result();
            } while ($result->num_rows > 0);

            // Insert product into the database
            $sql = "INSERT INTO products (name, size, price, barcode, bazaar_id, seller_id) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssdssi", $name, $size, $price, $barcode, $bazaar_id, $seller_id);
            if ($stmt->execute() === TRUE) {
                echo "<div class='alert alert-success'>Artikel erfolgreich erstellt.</div>";
            } else {
                echo "<div class='alert alert-danger'>Fehler beim Erstellen des Artikels: " . $conn->error . "</div>";
            }
        }
    }
}

// Handle product update form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $bazaar_id = get_current_bazaar_id($conn);
    $product_id = $conn->real_escape_string($_POST['product_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $size = $conn->real_escape_string($_POST['size']);
    $price = $conn->real_escape_string($_POST['price']);

    $rules = get_bazaar_pricing_rules($conn, $bazaar_id);
    $min_price = $rules['min_price'];
    $price_stepping = $rules['price_stepping'];

    // Validate price
    if ($price < $min_price) {
        $update_validation_message = "Der eingegebene Preis ist niedriger als der Mindestpreis von $min_price €.";
    } elseif (fmod($price, $price_stepping) != 0) {
        $update_validation_message = "Der Preis muss in Schritten von $price_stepping € eingegeben werden.";
    } else {
        $sql = "UPDATE products SET name=?, price=?, size=?, bazaar_id=? WHERE id=? AND seller_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdiii", $name, $price, $size, $bazaar_id, $product_id, $seller_id);
        if ($stmt->execute() === TRUE) {
            echo "<div class='alert alert-success'>Artikel erfolgreich aktualisiert.</div>";
        } else {
            echo "<div class='alert alert-danger'>Fehler beim Aktualisieren des Artikels: " . $conn->error . "</div>";
        }
    }
}

// Handle product deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $product_id = $conn->real_escape_string($_POST['product_id']);

    $sql = "DELETE FROM products WHERE id=? AND seller_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $product_id, $seller_id);
    if ($stmt->execute() === TRUE) {
        echo "<div class='alert alert-success'>Artikel erfolgreich gelöscht.</div>";
    } else {
        echo "<div class='alert alert-danger'>Fehler beim Löschen des Artikels: " . $conn->error . "</div>";
    }
}

// Handle delete all products
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all_products'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $sql = "DELETE FROM products WHERE seller_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $seller_id);
    if ($stmt->execute() === TRUE) {
        echo "<div class='alert alert-success'>Alle Artikel erfolgreich gelöscht.</div>";
    } else {
        echo "<div class='alert alert-danger'>Fehler beim Löschen aller Artikel: " . $conn->error . "</div>";
    }
}

// Fetch all products for the seller
$sql = "SELECT * FROM products WHERE seller_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$products_result = $stmt->get_result();

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Artikel erstellen - Verkäufernummer: <?php echo $seller_id; ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
        }
        .action-buttons .btn {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mt-5">Artikel erstellen - Verkäufernummer: <?php echo $seller_id; ?></h1>
        <div class="action-buttons">
            <form action="seller_products.php?seller_id=<?php echo $seller_id; ?>&hash=<?php echo $hash; ?>" method="post" class="w-100">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="name">Artikelname:</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="size">Größe:</label>
                        <input type="text" class="form-control" id="size" name="size">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="price">Preis:</label>
                        <input type="number" class="form-control" id="price" name="price" step="0.01" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100" name="create_product">Artikel erstellen</button>
            </form>
        </div>
        <a href="print_barcodes.php?seller_id=<?php echo $seller_id; ?>&hash=<?php echo $hash; ?>" class="btn btn-secondary mt-3 w-100">Etiketten drucken</a>

        <h2 class="mt-5">Erstellte Artikel</h2>
        <div class="table-responsive">
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>Artikelname</th>
                        <th>Größe</th>
                        <th>Preis</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($products_result->num_rows > 0) {
                        while ($row = $products_result->fetch_assoc()) {
                            $formatted_price = number_format($row['price'], 2, ',', '.') . ' €';
                            echo "<tr>
                                    <td>{$row['name']}</td>
                                    <td>{$row['size']}</td>
                                    <td>{$formatted_price}</td>
                                    <td>
                                        <button class='btn btn-warning btn-sm' onclick='editProduct({$row['id']}, \"{$row['name']}\", \"{$row['size']}\", {$row['price']})'>Bearbeiten</button>
                                        <form action='seller_products.php?seller_id=$seller_id&hash=$hash' method='post' style='display:inline-block'>
                                            <input type='hidden' name='csrf_token' value='" . htmlspecialchars(generate_csrf_token()) . "'>
                                            <input type='hidden' name='product_id' value='{$row['id']}'>
                                            <button type='submit' name='delete_product' class='btn btn-danger btn-sm'>Löschen</button>
                                        </form>
                                    </td>
                                  </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4'>Keine Artikel gefunden.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
            <form action="seller_products.php?seller_id=<?php echo $seller_id; ?>&hash=<?php echo $hash; ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <button type="submit" class="btn btn-danger mb-3" name="delete_all_products">Alle Artikel löschen</button>
            </form>
        </div>

        <!-- Edit Product Modal -->
        <div class="modal fade" id="editProductModal" tabindex="-1" role="dialog" aria-labelledby="editProductModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form action="seller_products.php?seller_id=<?php echo $seller_id; ?>&hash=<?php echo $hash; ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editProductModalLabel">Artikel bearbeiten</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="product_id" id="editProductId">
                            <div class="form-group">
                                <label for="editProductName">Artikelname:</label>
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
                            <div id="editProductAlert" class="alert alert-danger d-none"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                            <button type="submit" class="btn btn-primary" name="update_product">Änderungen speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Price Validation Modal -->
        <div class="modal fade" id="priceValidationModal" tabindex="-1" role="dialog" aria-labelledby="priceValidationModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="priceValidationModalLabel">Preisvalidierung</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <!-- The message will be set dynamically via JavaScript -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
        function editProduct(id, name, size, price) {
            $('#editProductId').val(id);
            $('#editProductName').val(name);
            $('#editProductSize').val(size);
            $('#editProductPrice').val(price.toFixed(2));
            $('#editProductModal').modal('show');
        }
    </script>
    <?php if (isset($validation_message)) { ?>
        <script>
            $(document).ready(function() {
                $('#priceValidationModal .modal-body').text('<?php echo $validation_message; ?>');
                $('#priceValidationModal').modal('show');
            });
        </script>
    <?php } ?>
    <?php if (isset($update_validation_message)) { ?>
        <script>
            $(document).ready(function() {
                $('#editProductAlert').text('<?php echo $update_validation_message; ?>').removeClass('d-none');
                $('#editProductModal').modal('show');
            });
        </script>
    <?php } ?>
</body>
</html>