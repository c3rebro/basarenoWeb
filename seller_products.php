<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'assistant' && $_SESSION['role'] !== 'seller')) {
    header("location: login.php");
    exit;
}

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

$conn = get_db_connection();

// Fetch seller numbers associated with the logged-in user
$sql = "SELECT id, seller_number, bazaar_id FROM sellers WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$sellers_result = $stmt->get_result();

$sellers = [];
while ($row = $sellers_result->fetch_assoc()) {
    $sellers[] = $row;
}

// Determine the selected seller ID
$seller_number = $_GET['seller_number'] ?? ($sellers[0]['seller_number'] ?? null);
$bazaar_id = null;

if ($seller_number) {
    foreach ($sellers as $s) {
        if ($s['seller_number'] == $seller_number) {
            $bazaar_id = $s['bazaar_id'];
            break;
        }
    }
}

if (!$seller_number || !$bazaar_id) {
    header("location: seller_dashboard.php?error=notFound");
    exit();
}

// Validate the selected seller ID (name changed to seller_number)  belongs to the user
$sql = "SELECT * FROM sellers WHERE seller_number = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $seller_number, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("location: seller_dashboard.php?error=notAllowed"); // Redirect to a safe page if validation fails
    exit;
}

// Fetch max products per seller from the bazaar
$sql = "SELECT max_products_per_seller FROM bazaar WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bazaar_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
            <title>Bazaar nicht gefunden</title>
            <link href='css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='row'>
                <div class='col-4 mx-auto text-center alert alert-warning mt-5'>
                    <h4 class='alert-heading'>Bitte noch etwas Geduld.</h4>
                    <p>Sie können aktuell noch nicht auf Ihre Produkte zugreifen, weil der alte Bazaar beendet ist und noch kein Datum für den nächsten Bazaar eingetragen wurde.</p>
                    <hr>
                    <p class='mb-0 text-center'>Sie glauben dass das ein Fehler ist? Melden Sie dies bitte beim Basarteam.</p>
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

$bazaar_settings = $result->fetch_assoc();
$max_products_per_seller = $bazaar_settings['max_products_per_seller'];

// Check the current number of products for this seller
$sql = "SELECT COUNT(*) as product_count FROM products WHERE seller_number=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_number);
$stmt->execute();
$result = $stmt->get_result();
$current_product_count = $result->fetch_assoc()['product_count'];

// Handle product creation form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    if ($current_product_count >= $max_products_per_seller) {
        $message_type = 'danger';
        $message = 'Sie haben die maximale Anzahl an Artikeln erreicht, die Sie erstellen können.';
    } else {
        $name = $conn->real_escape_string($_POST['name']);
        $size = $conn->real_escape_string($_POST['size']);
        $price = $conn->real_escape_string($_POST['price']);

        $rules = get_bazaar_pricing_rules($conn, $bazaar_id);
        $min_price = $rules['min_price'];
        $price_stepping = $rules['price_stepping'];

        if ($price < $min_price) {
            echo "<script nonce=\"$nonce\">
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelector('#priceValidationModal .modal-body').textContent = 'Der eingegebene Preis ist niedriger als der Mindestpreis von ' + $min_price + ' €.';
                    $('#priceValidationModal').modal('show');
                });
            </script>";
        } elseif (fmod($price, $price_stepping) != 0) {
            echo "<script nonce=\"$nonce\">
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelector('#priceValidationModal .modal-body').textContent = 'Der Preis muss in Schritten von ' + $price_stepping + ' € eingegeben werden.';
                    $('#priceValidationModal').modal('show');
                });
            </script>";
        }  else {
            // Generate and insert unique barcode
            $barcode = null;
            $max_attempts = 10;
			$product_id = rand(1, 999);
            for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
                $barcode = sprintf("U%04dV%04dA%03d", $user_id, $seller_number, $product_id);
                $sql = "INSERT INTO products (name, size, price, barcode, bazaar_id, product_id, seller_number) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssdssii", $name, $size, $price, $barcode, $bazaar_id, $product_id, $seller_number);

                if ($stmt->execute()) {
                    // Successfully inserted
                    $message_type = 'success';
                    $message = 'Artikel erfolgreich erstellt.';
                    break;
                } elseif ($conn->errno === 1062) {
                    // Duplicate barcode, try again
                    continue;
                } else {
                    // Other error
                    $message_type = 'danger';
                    $message = 'Fehler beim Erstellen des Artikels: ' . $conn->error;
                    break;
                }
            }

            if (!$barcode) {
                $message_type = 'danger';
                $message = 'Fehler beim Generieren eines eindeutigen Barcodes.';
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
    //$price = $conn->real_escape_string($_POST['price']);

    $rules = get_bazaar_pricing_rules($conn, $bazaar_id);
    $min_price = $rules['min_price'];
    $price_stepping = $rules['price_stepping'];

    // Validate price
    // Currently: it might be not a good idea to update the price of an existing article
    //if ($price < $min_price) {
    //    $update_validation_message = "Der eingegebene Preis ist niedriger als der Mindestpreis von $min_price €.";
    //} elseif (fmod($price, $price_stepping) != 0) {
    //    $update_validation_message = "Der Preis muss in Schritten von $price_stepping € eingegeben werden.";
    //} else {
        $sql = "UPDATE products SET name=?, size=?, bazaar_id=? WHERE id=? AND seller_number=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiii", $name, $size, $bazaar_id, $product_id, $seller_number);
        if ($stmt->execute() === TRUE) {
            $message_type = 'success';
            $message= 'Artikel erfolgreich aktualisiert.';
        } else {
            $message_type = 'danger';
            $message = 'Fehler beim Aktualisieren des Artikels: ' . $conn->error;
        }
    //}
}

// Handle product deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $product_id = $conn->real_escape_string($_POST['product_id']);

    $sql = "DELETE FROM products WHERE id=? AND seller_number=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $product_id, $seller_number);
    if ($stmt->execute() === TRUE) {
        $message_type = 'success';
        $message = 'Artikel erfolgreich gelöscht.';
    } else {
        $message_type = 'danger';
        $message = 'Fehler beim Löschen des Artikels: ' . $conn->error;
    }
}

// Handle delete all products
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all_products'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }

    $sql = "DELETE FROM products WHERE seller_number=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $seller_number);
    if ($stmt->execute() === TRUE) {
        $message_type = 'success';
        $message = 'Alle Artikel erfolgreich gelöscht.';
    } else {
        $message_type = 'danger';
        $message = 'Fehler beim Löschen aller Artikel: ' . $conn->error;
    }
}

// Fetch all products for the seller
$sql = "SELECT * FROM products WHERE seller_number=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_number);
$stmt->execute();
$products_result = $stmt->get_result();

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <style nonce="<?php echo $nonce; ?>">
        html { visibility: hidden; }
    </style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Artikel erstellen - Verkäufernummer: <?php echo $seller_number; ?></title>
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
        <a class="navbar-brand" href="index.php">Basar-Horrheim.de</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="seller_dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="seller_products.php">Meine Artikel <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="seller_edit.php">Meine Daten</a>
                </li>
            </ul>
            <hr class="d-lg-none d-block">
            <?php if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']): ?>
                <li class="nav-item">
                    <a class="navbar-user" href="#">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white p-2" href="logout.php">Abmelden</a>
                </li>
            <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link btn btn-primary text-white p-2" href="login.php">Anmelden</a>
                </li>
            <?php endif; ?>
        </div>
    </nav>
	
    <div class="container">
	    <h1>Artikel Verwaltung</h1>

    <!-- Seller Number Selection -->
	 <form method="get" action="seller_products.php" id="sellerForm">
		<label for="seller_number">Wählen Sie Ihre Verkäufernummer:</label>
		<select id="seller_number" name="seller_number" class="form-control">
			<?php foreach ($sellers as $s): ?>
				<option value="<?php echo $s['seller_number']; ?>" <?php if ($s['seller_number'] == $seller_number) echo 'selected'; ?>>
					<?php echo htmlspecialchars($s['seller_number']); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<noscript><button type="submit" class="btn btn-primary">Submit</button></noscript>
	</form>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade mt-4 show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        <h2 class="mt-5">Artikel erstellen - Verkäufernummer: <?php echo $seller_number; ?></h2>
        <div class="action-buttons">
            <form action="seller_products.php" method="post" class="w-100">
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
        <a href="print_qrcodes.php" class="btn btn-secondary mt-3 w-100" target="_blank">Etiketten drucken</a>

        <h2 class="mt-5">Erstellte Artikel</h2>
        <div class="table-responsive">
            <table class="table table-bordered mt-3">
				<thead>
					<tr>
						<th>Artikelname</th>
						<th>Gr.</th>
						<th>Preis</th>
						<th>Verk.</th> <!-- Added "Verkauft" column -->
						<th>Aktionen</th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ($products_result->num_rows > 0) {
						while ($row = $products_result->fetch_assoc()) {
							$formatted_price = number_format($row['price'], 2, ',', '.') . ' €';
							$is_sold = $row['sold'] == 1 ? 'checked' : ''; // Determine if the product is sold
							echo "<tr>
									<td>{$row['name']}</td>
									<td>{$row['size']}</td>
									<td>{$formatted_price}</td>
									<td class='text-center'>
										<input type='checkbox' disabled {$is_sold}> <!-- Read-only checkbox -->
									</td>
									<td class='text-center p-2'>
										<select class='form-control action-dropdown' data-product-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>
											<option value=''>Aktion wählen</option>
											<option value='edit'>Bearbeiten</option>
											<option value='delete'>Löschen</option>
										</select>
										<button class='btn btn-primary btn-sm execute-action' data-product-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>Ausführen</button>
									</td>
								  </tr>";
						}
					} else {
						echo "<tr><td colspan='5'>Keine Artikel gefunden.</td></tr>";
					}
					?>
				</tbody>
			</table>
            </table>
            <form action="seller_products.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <button type="submit" class="btn btn-danger mb-3" name="delete_all_products">Alle Artikel löschen</button>
            </form>
        </div>

        <!-- Edit Product Modal -->
        <div class="modal fade" id="editProductModal" tabindex="-1" role="dialog" aria-labelledby="editProductModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form action="seller_products.php" method="post">
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
                                <input type="number" class="form-control" id="editProductPrice" name="price" step="0.01" disabled required>
                                <p class="text-secondary">Ein nachträgliches Ändern des Preises ist aus Sicherheitsgründen nicht möglich. Wenn Sie den Preis ändern möchten, löschen Sie bitte den Artikel und legen sie ihn dann mit anderem Preis erneut an.<br>Vielen Dank für Ihre Verständnis.</p>
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
			const sellerSelect = document.getElementById('seller_number');
			sellerSelect.addEventListener('change', function() {
				document.getElementById('sellerForm').submit();
			});
		});
	</script>
    <script nonce="<?php echo $nonce; ?>">
        $(document).on('click', '.execute-action', function() {
            const productId = $(this).data('product-id');
            const action = $(`.action-dropdown[data-product-id="${productId}"]`).val();

            if (action === 'edit') {
                // Open edit modal with product data
                // Assuming you have a function to handle this
                const row = $(this).closest('tr');
                const name = row.find('td:nth-child(1)').text();
                const size = row.find('td:nth-child(2)').text();
                const price = parseFloat(row.find('td:nth-child(3)').text().replace(',', '.'));
                editProduct(productId, name, size, price);
            } else if (action === 'delete') {
                if (confirm('Möchten Sie dieses Produkt wirklich löschen?')) {
                    $.post('seller_products.php', { delete_product: true, product_id: productId, csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>' }, function(response) {
                        location.reload();
                    });
                }
            }
        });
    </script>
    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', function() {
            // Attach event listeners to the edit buttons
            document.querySelectorAll('.edit-product-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.getAttribute('data-id');
                    const productName = this.getAttribute('data-name');
                    const productSize = this.getAttribute('data-size');
                    const productPrice = this.getAttribute('data-price');
                    editProduct(productId, productName, productSize, parseFloat(productPrice));
                });
            });
        });
        
        function editProduct(id, name, size, price) {
            document.getElementById('editProductId').value = id;
            document.getElementById('editProductName').value = name;
            document.getElementById('editProductSize').value = size;
            document.getElementById('editProductPrice').value = price.toFixed(2);
            $('#editProductModal').modal('show');
        }
    </script>
    <?php if (isset($validation_message)) { ?>
        <script nonce="<?php echo $nonce; ?>">
            $(document).ready(function() {
                $('#priceValidationModal .modal-body').text('<?php echo $validation_message; ?>');
                $('#priceValidationModal').modal('show');
            });
        </script>
    <?php } ?>
    <?php if (isset($update_validation_message)) { ?>
        <script nonce="<?php echo $nonce; ?>">
            $(document).ready(function() {
                $('#editProductAlert').text('<?php echo $update_validation_message; ?>').removeClass('d-none');
                $('#editProductModal').modal('show');
            });
        </script>
    <?php } ?>
	<script nonce="<?php echo $nonce; ?>">
        // Show the HTML element once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.documentElement.style.visibility = "visible";
        });
    </script>
</body>
</html>