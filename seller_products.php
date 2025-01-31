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
$seller_number = null;

$sellers = [];
while ($row = $sellers_result->fetch_assoc()) {
    $sellers[] = $row;
}

// Check if this is a POST request for setting the seller_number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && filter_input(INPUT_POST, 'set_seller_number') !== null && $_POST['set_seller_number'] == 'true') {
	if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }
	
	// Validate and update the session variable
    if (filter_input(INPUT_POST, 'seller_number') !== null) {
		$selected_seller_number = filter_input(INPUT_POST, 'seller_number');
		
		// Convert sellers array into an array of seller numbers only
		$seller_numbers_array = array_column($sellers, 'seller_number');

		if (in_array($selected_seller_number, $seller_numbers_array)) {
			$_SESSION['seller_number'] = $selected_seller_number;
			echo json_encode(['success' => 'Seller number updated']);
		} else {
			echo json_encode(['error' => 'Unauthorized seller number']);
		}
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
    }
    exit;
}

// Determine the selected seller ID
if (isset($_SESSION['seller_number']) && $_SESSION['seller_number']) {
    // prioritize SESSION value
    $seller_number = $_SESSION['seller_number'] ?? null;
} elseif (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    // If the request is a POST (e.g., creating a product), prioritize POST data
    $seller_number = $_POST['seller_number'] ?? null;
    $_SESSION['seller_number'] = $seller_number;
} elseif (filter_input(INPUT_GET, 'seller_number') !== null) {
    // If the request has a GET parameter for seller_number, use it
    $seller_number = filter_input(INPUT_GET, 'seller_number');
} else {
    // Fall back to the default seller_number if no other input is provided (first load)
    $seller_number = $sellers[0]['seller_number'] ?? null;
    $_SESSION['seller_number'] = $seller_number;
}

$bazaar_id = get_current_bazaar_id($conn) === 0 ? get_bazaar_id_with_open_registration($conn) : null;

if (!$bazaar_id) {
    // Redirect if no bazaar available
    header("Location: seller_dashboard.php?error=bazaarNotFound");
    exit;
}

if (!$seller_number) {
    header("location: seller_dashboard.php?error=notFound");
    exit();
}


// Validate the selected seller_number belongs to the user
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
$sql = "SELECT max_products_per_seller, price_stepping, min_price, startDate, startDate > NOW() AS is_upcoming FROM bazaar WHERE id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bazaar_id);
$stmt->execute();
$result = $stmt->get_result();
$bazaar_settings = null;
$max_products_per_seller = 200;
$min_price = 100;
$price_stepping = 0.01;

if ($result->num_rows > 0) {
    $bazaar_settings = $result->fetch_assoc();
    $max_products_per_seller = $bazaar_settings['max_products_per_seller'];
	$min_price = $bazaar_settings['min_price'];
	$price_stepping = $bazaar_settings['price_stepping'];
}

// Fetch all products for the seller in sale
$sql = "SELECT * FROM products WHERE seller_number=? AND in_stock = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_number);
$stmt->execute();
$products_result = $stmt->get_result();
$active_products_count = $products_result->num_rows;

// Fetch all products for the seller in stock
$sql = "SELECT * FROM products WHERE seller_number=? AND in_stock = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_number);
$stmt->execute();
$stock_products = $stmt->get_result();
$stock_products_count = $stock_products->num_rows;

// Handle bulk actions for Stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && filter_input(INPUT_POST, 'bulk_action') !== null) {
	if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }
	
    $action = filter_input(INPUT_POST, 'bulk_action');
    $product_ids = $_POST['product_ids'] ?? [];
    $seller_number = $_SESSION['seller_number'];

    // Handle "move to sale" action
    if ($action === 'move_to_sale' && $seller_number) {
        $total_products_to_move = count($product_ids);

        // Check if moving these products exceeds the max allowed
        if (($active_products_count + $total_products_to_move) > $max_products_per_seller) {
            echo json_encode([
                'success' => false,
                'message' => "Korb voll: Du kannst maximal $max_products_per_seller Artikel im Verkauf haben. Du hast bereits $active_products_count."
            ]);
            exit;
        }

        // Move selected products to sale
        $ids = implode(',', array_map('intval', $product_ids));
        $sql = "UPDATE products SET in_stock = 0 WHERE id IN ($ids)";
        $conn->query($sql);
    }  elseif ($action === 'delete') {
        $ids = implode(',', array_map('intval', $product_ids));
        $sql = "DELETE FROM products WHERE id IN ($ids)";
        $conn->query($sql);
    }

    echo json_encode(['success' => true]);
    exit;
}

// Handle product creation form submission
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'create_product') !== null) {
    header('Content-Type: application/json'); // Set JSON response type
    
	$sale_is_full = false;
	
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    if ($active_products_count >= $max_products_per_seller) {
		$sale_is_full = true;
        //echo json_encode(['success' => false, 'message' => 'Du hast die maximale Anzahl an Artikeln erreicht, die Du erstellen kannst.']);
        //exit;
    } 

    $name = filter_input(INPUT_POST, 'name');
    $size = filter_input(INPUT_POST, 'size');
    $price = normalize_price($_POST['price'] ?? '0');

    $rules = get_bazaar_pricing_rules($conn, $bazaar_id);
    $min_price = $rules['min_price'];
    $price_stepping = $rules['price_stepping'];

    if ($price < $min_price) {
        echo json_encode(['success' => false, 'message' => "Der eingegebene Preis ist niedriger als der Mindestpreis von $min_price €."]);
        exit;
    }

    if (fmod($price, $price_stepping) != 0) {
        echo json_encode(['success' => false, 'message' => "Der Preis muss in Schritten von $price_stepping € eingegeben werden."]);
        exit;
    }

    // Generate and insert unique barcode
    $barcode = null;
    $max_attempts = 10;
    $product_id = rand(1, 999);
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $barcode = sprintf("U%04dV%04dA%03d", $user_id, $seller_number, $product_id);
		if($sale_is_full) {
			$sql = "INSERT INTO products (name, size, price, barcode, bazaar_id, product_id, seller_number, in_stock) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
		} else {
			$sql = "INSERT INTO products (name, size, price, barcode, bazaar_id, product_id, seller_number, in_stock) VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
		}
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdssii", $name, $size, $price, $barcode, $bazaar_id, $product_id, $seller_number);

        if ($stmt->execute()) {
			
			if($sale_is_full) {
				// Return success response with new product details
				echo json_encode([
					'success' => false,
					'message' => 'Fehler: Die maximale Anzahl Artikel wurde erreicht. Artikel ins Lager verschoben.',
					'product' => [
						'id' => $conn->insert_id,
						'name' => $name,
						'size' => $size,
						'price' => $price . ' €',
					],
				]);
            exit;
			} else {
				// Return success response with new product details
				echo json_encode([
					'success' => true,
					'message' => 'Artikel erfolgreich erstellt.',
					'product' => [
						'id' => $conn->insert_id,
						'name' => $name,
						'size' => $size,
						'price' => $price . ' €',
					],
				]);
				exit;
			}

        } elseif ($conn->errno === 1062) {
            continue; // Try again for a unique barcode
        } else {
            echo json_encode(['success' => false, 'message' => 'Fehler beim Erstellen des Artikels.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Fehler beim Generieren eines eindeutigen Barcodes.']);
    exit;
}

// Handle product update form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && filter_input(INPUT_POST, 'update_product') !== null) {
    header('Content-Type: application/json'); // Ensure JSON response

    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $bazaar_id = get_current_bazaar_id($conn);
    $product_id = $conn->real_escape_string($_POST['product_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $size = $conn->real_escape_string($_POST['size']);

    $sql = "UPDATE products SET name = ?, size = ?, bazaar_id = ? WHERE id = ? AND seller_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssiii", $name, $size, $bazaar_id, $product_id, $seller_number);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Artikel erfolgreich aktualisiert.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Aktualisieren des Artikels: ' . $conn->error]);
    }
    exit;
}

// Handle product deletion
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'delete_product') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $product_id = $conn->real_escape_string($_POST['product_id']);

    $sql = "DELETE FROM products WHERE id=? AND seller_number=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $product_id, $seller_number);
    if ($product_id) {
        $sql = "DELETE FROM products WHERE id = ? AND seller_number = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $product_id, $seller_number);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Artikel erfolgreich gelöscht.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fehler beim Löschen des Artikels.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Ungültige Artikel-ID.']);
    }
    exit;
}

// Handle delete all products
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'delete_all_products') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $sql = "DELETE FROM products WHERE seller_number=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $seller_number);
    if ($stmt->execute() === TRUE) {
        echo json_encode(['success' => true, 'message' => 'Alles gelöscht.']);
    } else {
        echo json_encode(['error' => true, 'message' => 'Fehler beim Löschen der Artikel']);
    }
    exit;
}

// Handle move product to stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && filter_input(INPUT_POST, 'move_to_stock') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token.']);
        exit;
    }

    $product_id = (int) $_POST['product_id'];

    // Update the product's `in_stock` status to 1
    $sql = "UPDATE products 
            SET in_stock = 1 
            WHERE id = ? AND seller_number IN 
                (SELECT seller_number FROM sellers WHERE user_id = ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $product_id, $user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Artikel erfolgreich ins Lager gelegt.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Verschieben ins Lager.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && filter_input(INPUT_POST, 'confirm_import') !== null) {
	if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }
	
	$encoding = $_POST['encoding'] ?? 'utf-8'; // Default to UTF-8
	
	// Read csv_file content
    $csv_content = file_get_contents($_FILES['csv_file']['tmp_name']);
	
	    // Convert encoding if necessary
    if ($encoding === 'ansi') {
        $csv_content = mb_convert_encoding($csv_content, 'UTF-8', 'Windows-1252');
    }
	
    // Create a temporary file with UTF-8 content
    $temp_file = tmpfile();
    fwrite($temp_file, $csv_content);
    rewind($temp_file);

    // Get the actual filename from tmpfile()
    $temp_meta = stream_get_meta_data($temp_file);
    $filename = $temp_meta['uri'];
	
    $delimiter = $_POST['delimiter'] ?? ',';
    $max_attempts = 10; // Retry limit for unique barcodes
	
    if (($handle = fopen($filename, 'r')) === false) {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Öffnen der Datei.']);
        exit;
    }

    $rowCount = 0;
    while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
        // Skip empty rows
        if (empty($data[0]) && empty($data[1]) && empty($data[2])) {
            continue;
        }

        // Ensure each row has at least 3 columns (name, size, price)
		if (count($data) < 3 || empty($data[0]) || empty($data[2])) {
			continue; // Skip rows with missing essential data
		}

		// Sanitize and validate fields
		$name = clean_csv_value($data[0]); // Restore original names
        $size = !empty($data[1]) ? $data[1] : '.'; // Clean size column
		$price = (float) normalize_price(trim($data[2]) ?? '0'); // Convert price to float, ensure it's numeric
		
		// Validate price against rules
		$scaled_price = (int)round($price * 100);
		$scaled_stepping = (int)round($price_stepping * 100);

		if ($scaled_price < ($min_price * 100) || $scaled_price % $scaled_stepping !== 0) {
			$skippedRows++;
			continue; // Skip invalid rows
		}

        // Generate unique barcode and product ID
        $barcode = null;
        $product_id = null;

        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
			
			$remaining_slots = $max_products_per_seller - $current_active_count;

            $product_id = rand(1, 999);
            $barcode = sprintf("U%04dV%04dA%03d", $user_id, $seller_number, $product_id);
            $sql = "INSERT INTO products (name, size, price, barcode, bazaar_id, product_id, seller_number, in_stock) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssdssii", $name, $size, $price, $barcode, $bazaar_id, $product_id, $seller_number);


            if ($stmt->execute()) {
                $rowCount++;
                break; // Exit the loop after successful insertion
            } elseif ($conn->errno === 1062) {
                continue; // Retry for a unique barcode
            } else {
                echo json_encode(['success' => false, 'message' => 'Fehler beim Importieren der Artikel.']);
                exit;
            }
        }

        if (!$barcode) {
            fclose($handle);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Generieren eines eindeutigen Barcodes.']);
            exit;
        }
    }

    fclose($handle);

    echo json_encode(['success' => true, 'message' => "$rowCount Artikel erfolgreich importiert."]);
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
    <head>
        <style nonce="<?php echo $nonce; ?>">
            html {
                visibility: hidden;
            }
        </style>
        <meta charset="UTF-8">
        <link rel="icon" type="image/x-icon" href="favicon.ico">
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
        <script nonce="<?php echo $nonce; ?>">
            // Make `seller_number` available globally in JavaScript
            const sellerNumber = <?php echo json_encode($seller_number); ?>;
            const bazaarId = <?php echo json_encode($bazaar_id); ?>;
            const maxProdPerSellers = <?php echo json_encode($max_products_per_seller); ?>;
        </script>
    </head>
    <body>
        <!-- Navbar -->
		<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->

        <div class="container">
            <h1>Artikel Verwaltung</h1>
            <hr/>
            <h4>Basardatum: <?php echo DateTime::createFromFormat('Y-m-d', $bazaar_settings['startDate'])->format('d.m.Y'); ?></h4>
            <h4>Verkäufernummer: <?php echo $seller_number; ?></h4>
            <h2 class="mt-5"></h2>
            <div class="card mb-4 mt-4">
                <div class="card-header card-header-remaining">Neuen Artikel erstellen (max. <?php echo htmlspecialchars($max_products_per_seller); ?> noch: <?php echo htmlspecialchars($max_products_per_seller - $active_products_count); ?> möglich)</div>
                <div class="card-body">
                    <!-- Seller Number Selection -->
                    <form method="post" action="seller_products.php" id="sellerForm">
                        <label for="seller_number">Wähle Deine Verkäufernummer:</label>
                        <select id="seller_number_select" name="seller_number" class="form-control mb-2">
                            <?php foreach ($sellers as $s): ?>
                                <option value="<?php echo $s['seller_number']; ?>" <?php if ($s['seller_number'] == $seller_number) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($s['seller_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade mt-4 show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>
                    <div class="action-buttons">
                        <form action="seller_products.php" method="post" class="w-100" id="createProductForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="seller_number" id="hiddenSellerNumber" value="<?php echo htmlspecialchars($seller_number); ?>">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label for="name">Artikelbezeichnung:</label>
                                    <input type="text" class="form-control" id="name" name="name" required tabindex="1">
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="size">Größe:</label>
                                    <input type="text" class="form-control" id="size" name="size" tabindex="2">
                                </div>
                                <div class="form-group col-md-4">
                                    <label for="price">Preis:</label>
                                    <input type="number" class="form-control" id="price" name="price" step="0.01" required tabindex="3">
                                </div>
                            </div>
							<div class="row">
								<div class="col-md-6 col-sm-12 mt-3">
									<button type="submit" class="btn btn-primary w-100" name="create_product" tabindex="4">Artikel erstellen</button>
								</div>
								<div class="col-md-6 col-sm-12 mt-3">
									<button type="button" class="btn btn-info w-100" data-toggle="modal" data-target="#importProductsModal">Artikel importieren</button>
								</div>
							</div>
                            
                        </form>
                    </div>
                    <form action="print_qrcodes.php" method="POST" target="_blank">
                        <input type="hidden" name="seller_number" value="<?php echo htmlspecialchars($seller_number); ?>">
                        <button type="submit" class="btn btn-secondary mt-3 w-100">Etiketten drucken</button>
                    </form>

                </div>
            </div>

            <!-- Sale Section -->
            <div class="card mb-4 mt-4">
                <div class="card-header card-header-sale-products">Deine aktiven Artikel (Anzahl: <?php echo htmlspecialchars($active_products_count); ?>) </div>
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">
                        Nur die aktiven Artikel dürfen zum Basar mitgebracht werden. 
                    </h6>
                    <div class="table-responsive">
                        <table id="productTable" class="table table-bordered mt-3">
                            <thead>
                                <tr>
                                    <th>Artikelname</th>
                                    <th>Gr.</th>
                                    <th>Preis</th>
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
											<td class='text-center p-2'>
												<select class='form-control action-dropdown' data-product-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>
													<option value=''>Aktion wählen</option>
													<option value='edit'>Bearbeiten</option>
													<option value='stock'>Ins Lager verschieben</option>
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

                        <button class="btn btn-danger deleteAllProductsBtn" data-action="delete_all_products">Alle Artikel löschen</button>

                    </div>
                </div>
            </div>

            <!-- Stock Section -->
            <div class="card mb-4">
                <div class="card-header card-header-stock-products">Deine Aktikel im Lager (Anzahl: <?php echo htmlspecialchars($stock_products_count); ?>) </div>
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">
                        Dein Lager nimmt nach Ende des Basars automatisch alle unverkauften Artikel auf. Beim nächsten Basar können diese Artikel dem neuen Basar wieder hinzugefügt werden.
                    </h6>

                    <?php if (!empty($stock_products)): ?>
                        <!-- Expandable Table -->
                        <div class="accordion" id="stockAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header row" id="headingStock">
                                    <div class="col">
                                        <button class="btn btn-primary btn-block collapsed" type="button" data-toggle="collapse" data-target="#collapseStock" aria-expanded="false" aria-controls="collapseStock">
                                            Lagerartikel anzeigen
                                        </button>
                                    </div>
                                </h2>
                                <div id="collapseStock" class="accordion-collapse collapse" aria-labelledby="headingStock" data-bs-parent="#stockAccordion">
                                    <div class="accordion-body table-responsive-sm">
                                        <form method="POST" action="seller_products.php" id="stockForm">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                            <table class="table table-striped w-100" id="stockTable">
                                                <thead>
                                                    <tr>
                                                        <th>
                                                            <input type="checkbox" id="selectAllStock" title="Alle auswählen">
                                                        </th>
                                                        <th>Bezeichnung</th>
                                                        <th>Größe</th>
                                                        <th>Preis (€)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($stock_products as $product): ?>
                                                        <tr>
                                                            <td>
                                                                <input type="checkbox" class="bulk-select-stock" name="product_ids[]" value="<?php echo $product['id']; ?>">
                                                            </td>
                                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($product['size']); ?></td>
                                                            <td><?php echo number_format($product['price'], 2); ?> €</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>

                                            <!-- Bulk Actions -->
                                            <div class="row">
                                                <div class="col-sm-12 col-md-6 mt-3">
                                                    <button type="button" class="btn btn-primary w-100 bulk-move-to-sale">Ausgewählte Artikel zum Verkauf stellen</button>
                                                </div>
                                                <div class="col-sm-12 col-md-6 mt-3">
                                                    <button type="button" class="btn btn-danger w-100 bulk-delete-stock">Ausgewählte Artikel löschen</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p>Keine Artikel im Lager.</p>
                    <?php endif; ?>
                </div>
            </div>



            <!-- Edit Product Modal -->
            <div class="modal fade" id="editProductModal" tabindex="-1" role="dialog" aria-labelledby="editProductModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <form action="seller_products.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="seller_number" id="hiddenSellerNumber" value="<?php echo htmlspecialchars($seller_number); ?>">
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
                                    <p class="text-secondary">Ein nachträgliches Ändern des Preises ist aus Sicherheitsgründen nicht möglich. Wenn Du den Preis ändern möchtest, lösche bitte den Artikel und legen ihn dann mit anderem Preis erneut an.<br>Vielen Dank für Dein Verständnis.</p>
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

            <!-- Delete Signle Item Confirmation Modal -->
            <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteConfirmationModalLabel">Bestätigen</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Schließen">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            Möchtest Du diesen Artikel wirklich löschen?
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteButton">Löschen</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Confirm Delete All Modal -->
            <div class="modal fade" id="deleteAllConfirmationModal" tabindex="-1" role="dialog" aria-labelledby="deleteAllConfirmationModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteAllConfirmationModalLabel">Alle Artikel löschen</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Schließen">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            Möchtest Du wirklich alle Artikel löschen? Diese Aktion kann nicht rückgängig gemacht werden.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                            <button type="button" class="btn btn-danger" id="confirmDeleteAllButton">Alle löschen</button>
                        </div>
                    </div>
                </div>
            </div>

			<!-- Import Products Modal -->
			<div class="modal fade" id="importProductsModal" tabindex="-1" role="dialog" aria-labelledby="importProductsModalLabel" aria-hidden="true">
				<div class="modal-dialog modal-lg" role="document">
					<div class="modal-content modal-xxl">
						<div class="modal-header">
							<h5 class="modal-title" id="importProductsModalLabel">Artikel importieren</h5>
							<button type="button" class="close" data-dismiss="modal" aria-label="Schließen">
								<span aria-hidden="true">&times;</span>
							</button>
						</div>
						<div class="modal-body">
							<form enctype="multipart/form-data" method="post" action="" id="importProductsForm">
								<!-- CSRF Token -->
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
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
								<!-- Loading Spinner -->
								<div id="importLoadingSpinner" class="text-center mt-3 d-none">
									<div class="spinner-border text-primary" role="status">
										<span class="sr-only">Import läuft...</span>
									</div>
									<p>Import läuft... Bitte warten.</p>
								</div>
								<button type="submit" class="btn btn-primary mt-3 mb-3 hidden" name="confirm_import" id="confirm_import">Bestätigen und Importieren</button>
									<h6 class="mb-2 text-muted">Importierte Artikel landen automatisch zuerst im Lager und können dann zu den aktiven Artikeln verschoben werden.
									</h6>
							</form>

							<div class="alert alert-info mt-4">
								<strong>Hinweis:</strong> Es wird empfohlen, den Import auf einem Desktop-PC oder Tablet durchzuführen.
							</div>

							<h2 class="mt-4">Erwartete CSV-Dateistruktur</h2>
							<p>Die importierte CSV-Datei darf keine Spaltenüberschriften enthalten.</p>
							<table class="table table-striped table-bordered">
								<thead class="thead-dark">
									<tr>
										<th>Bezeichnung</th>
										<th>Größe</th>
										<th>Preis (€)</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>Winterjacke</td>
										<td>146</td>
										<td>5.00</td>
									</tr>
									<tr>
										<td>Sportschuhe</td>
										<td>24</td>
										<td>3.50</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>

            <!-- Toast Container -->
            <div aria-live="polite" aria-atomic="true">
                <!-- Toasts will be dynamically added here -->
                <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3">
                    <!-- Toasts will be dynamically added here -->
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

        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/utilities.js" nonce="<?php echo $nonce; ?>"></script>
		<script nonce="<?php echo $nonce; ?>">
			(function () {
				let selectedFile = null;

				function previewCSV() {
					if (!selectedFile) return;

					const delimiter = document.querySelector('input[name="delimiter"]:checked').value;
					const encoding = document.querySelector('input[name="encoding"]:checked').value;

					const reader = new FileReader();
					reader.onload = function (e) {
						let contents = e.target.result;
						if (encoding === 'ansi') {
							contents = new TextDecoder('windows-1252').decode(new Uint8Array(contents));
						}
						const rows = contents.split('\n');
						let html = '<table class="table table-striped table-bordered"><thead class="thead-dark"><tr><th>Bezeichnung</th><th>Größe</th><th>Preis (€)</th></tr></thead><tbody>';
						for (let i = 0; i < rows.length; i++) {
							const cells = rows[i].split(delimiter).map(cell => cell.trim());
							if (cells.length >= 3 && cells[0] && cells[1]) {
								html += '<tr>';
								html += `<td>${cells[0]}</td>`;
								html += `<td>${cells[1] || "k/A"}</td>`;
								html += `<td>${cells[2]}</td>`;
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

				document.addEventListener('DOMContentLoaded', function () {
					document.querySelector('#csv_file').addEventListener('change', handleFileSelect);
					document.querySelectorAll('input[name="delimiter"]').forEach(function (elem) {
						elem.addEventListener('change', handleOptionChange);
					});
					document.querySelectorAll('input[name="encoding"]').forEach(function (elem) {
						elem.addEventListener('change', handleOptionChange);
					});
				});
			})();
		</script>

        <script nonce="<?php echo $nonce; ?>">
            document.addEventListener('DOMContentLoaded', function () {
                const sellerSelect = document.getElementById('seller_number_select');

                // Handle dropdown change and update session using AJAX
                sellerSelect.addEventListener('change', function () {
                    const selectedValue = sellerSelect.value;

                    // Send the new seller_number to the server via AJAX or
                    // Send a POST request with jQuery
                    $.post('seller_products.php', {
                        set_seller_number: true,
						csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>',
                        seller_number: selectedValue
                    })
                            .done(function (response) {
                                const data = JSON.parse(response);
                                if (data.success) {
                                    location.reload();
                                } else {
                                    console.error('Error updating seller number:', data.error);
                                }
                            })
                            .fail(function (xhr, status, error) {
                                console.error('AJAX request failed:', error);
                            });
                });
                
                
                document.getElementById('selectAllStock').addEventListener('change', function () {
                    const isChecked = this.checked; // Get the state of the "Select All" checkbox
                    document.querySelectorAll('.bulk-select-stock').forEach(checkbox => {
                        checkbox.checked = isChecked; // Set all checkboxes below to match the "Select All" state
                    });
                });
            });
        </script>
        <script nonce="<?php echo $nonce; ?>">
            $(document).on('click', '.execute-action', function () {
                const productId = $(this).data('product-id');
                const action = $(`.action-dropdown[data-product-id="${productId}"]`).val();

                if (action === 'edit') {
                    // Open the edit modal with pre-filled data
                    const row = $(this).closest('tr');
                    const name = row.find('td:nth-child(1)').text();
                    const size = row.find('td:nth-child(2)').text();
                    const price = parseFloat(row.find('td:nth-child(3)').text().replace(',', '.'));
                    editProduct(productId, name, size, price);
                } 
                else if (action === 'delete') {
                    // Show the Bootstrap modal
                    $('#deleteConfirmationModal').modal('show');

                    // Handle the confirm delete button click
                    $('#confirmDeleteButton').off('click').on('click', function () {
                        // Send the delete request via AJAX
                        $.post('seller_products.php', {
                            delete_product: true,
                            product_id: productId,
                            seller_number: sellerNumber,
                            csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>'
                        }, function (response) {
                            const data = JSON.parse(response);
                            if (data.success) {
                                // Refresh the table and update the card header
                                refreshProductTable(sellerNumber)
                                    .then(() => {const products = Array.isArray(data.data) ? data.data : [];
                                        const activeProductCount = document.querySelectorAll('#productTable tbody tr').length;
                                        const stockProductCount = document.querySelectorAll('#stockTable tbody tr').length;

                                        // Update the active products count dynamically
                                        document.querySelector('.card-header-sale-products').textContent = `Deine aktiven Artikel (Anzahl: ${activeProductCount})`;
                                        document.querySelector('.card-header-remaining').textContent = `Neuen Artikel erstellen (max. ${maxProdPerSellers} noch: ${maxProdPerSellers - activeProductCount} möglich)`;
                                        // Show success notification
                                        showToast('Erfolgreich', 'Artikel wurde erfolgreich gelöscht.', 'success');
                                    })
                                    .catch(() => {
                                        showToast('Fehler', 'Fehler beim Aktualisieren der Tabelle.', 'error');
                                    });
                            } else {
                                // Show error notification
                                showToast('Fehler', data.message, 'error');
                            }
                        }).fail(function () {
                            showToast('Fehler', 'Fehler beim Senden der Anfrage.', 'error');
                        });

                        // Hide the modal after the action
                        $('#deleteConfirmationModal').modal('hide');
                    });
                }
                else if (action === 'stock') {
                    // Handle "move to stock" action
                    $.post('seller_products.php', {
                        move_to_stock: true,
                        product_id: productId,
                        seller_number: sellerNumber,
                        csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>'
                    })
                    .done(function (response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            // Start by refreshing the product table
                            refreshProductTable(sellerNumber)
                                .then(() => {
                                    // Once the product table is updated, refresh the stock table
                                    return refreshStockTable();
                                })
                                .then(() => {
                                    const activeProductCount = document.querySelectorAll('#productTable tbody tr').length;
                                    const stockProductCount = document.querySelectorAll('#stockTable tbody tr').length;
                                    
                                    // Update the active products count dynamically
                                    document.querySelector('.card-header-sale-products').textContent = `Deine aktiven Artikel (Anzahl: ${activeProductCount})`;
                                    document.querySelector('.card-header-remaining').textContent = `Neuen Artikel erstellen (max. ${maxProdPerSellers} noch: ${maxProdPerSellers - activeProductCount} möglich)`;
                                    document.querySelector('.card-header-stock-products').textContent = `Deine Aktikel im Lager (Anzahl: ${stockProductCount})`;
                                    // Show success toast after both tables are updated
                                    showToast('Erfolgreich', 'Artikel wurde ins Lager gelegt.', 'success');
                                })
                                .catch(() => {
                                    // Handle errors during table refresh
                                    showToast('Fehler', 'Fehler beim Aktualisieren der Tabellen.', 'error');
                                });
                        } else {
                            // Show error toast for server-side issues
                            showToast('Fehler', data.message, 'error');
                        }
                    })
                    .fail(function () {
                        // Handle AJAX failure
                        showToast('Fehler', 'Fehler beim Senden der Anfrage.', 'error');
                    });
                } 
            });

            $(document).on('click', '.deleteAllProductsBtn', function () {
                if ($(this).data('action') === 'delete_all_products') {
                    // Show the Bootstrap modal
                    $('#deleteAllConfirmationModal').modal('show');

                    // Handle the confirm delete button click
                    $('#confirmDeleteAllButton').off('click').on('click', function () {

                        // Send the delete request via AJAX
                        // Send delete request
                        // Create form data
                        const formData = new FormData();
                        formData.append('csrf_token', '<?php echo htmlspecialchars(generate_csrf_token()); ?>');
                        formData.append('delete_all_products', '1'); // To match the backend check
                        formData.append('seller_number', sellerNumber);

                        // Send delete request
                        fetch('seller_products.php', {
                            method: 'POST',
                            body: formData,
                        })
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json(); // Parse JSON
                            })
                            .then(data => {
                                if (data.success) {
                                    // Refresh the table using the reusable function
                                    refreshProductTable(sellerNumber)
                                        .then(data => {
                                            // Safely handle cases where data.data is undefined or empty
                                            const activeProductCount = document.querySelectorAll('#productTable tbody tr').length;
                                            const stockProductCount = document.querySelectorAll('#stockTable tbody tr').length;

                                            // Update the active products count dynamically
                                            document.querySelector('.card-header-sale-products').textContent = `Deine aktiven Artikel (Anzahl: ${activeProductCount})`;
                                            document.querySelector('.card-header-remaining').textContent = `Neuen Artikel erstellen (max. ${maxProdPerSellers} noch: ${maxProdPerSellers - activeProductCount} möglich)`;
                                            document.querySelector('.card-header-stock-products').textContent = `Deine Aktikel im Lager (Anzahl: ${stockProductCount})`;
                                            // Show success notification
                                            showToast('Erfolgreich', 'Alle Artikel wurden erfolgreich gelöscht.', 'success');

                                            // Hide the confirmation modal
                                            $('#deleteAllConfirmationModal').modal('hide');
                                        })
                                        .catch(() => {
                                            showToast('Fehler', 'Fehler beim Aktualisieren der Tabelle.', 'error');
                                        });
                                } else {
                                    // Show error notification
                                    showToast('Fehler', 'Fehler beim Löschen der Artikel.', 'danger');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showToast('Fehler', 'Es gab ein Problem beim Löschen der Artikel.', 'danger');
                            });
                    });
                }
            });

            $('#createProductForm').on('submit', function (e) {
                e.preventDefault(); // Prevent default form submission

                const formData = $(this).serialize(); // Serialize form data

                $.post('seller_products.php', formData + '&create_product=true')
                .done(function (response) {
                    let data;

                    // Ensure the response is a JavaScript object
                    try {
                        data = typeof response === 'string' ? JSON.parse(response) : response;
                    } catch (e) {
                        showToast('Fehler', 'Ungültige Antwort vom Server.', 'danger');
                        console.error('JSON Parsing Error:', e, response);
                        return;
                    }
                    
                    if (data.success) {
                        // Refresh the product table
                        refreshProductTable(sellerNumber)
                            .then(() => {
                                // Fetch the updated product list to calculate counts
                                return fetch(`fetch_products.php?seller_number=${sellerNumber}`);
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    const defaultElement = document.getElementById('name');

                                    const activeProductCount = document.querySelectorAll('#productTable tbody tr').length;
                                    const stockProductCount = document.querySelectorAll('#stockTable tbody tr').length;
                                    
                                    // Update the card headers dynamically
                                    document.querySelector('.card-header-sale-products').textContent = `Deine aktiven Artikel (Anzahl: ${activeProductCount ?? '0'})`;
                                    document.querySelector('.card-header-remaining').textContent = `Neuen Artikel erstellen (max. ${maxProdPerSellers} noch: ${Math.max(0, maxProdPerSellers - activeProductCount)} möglich)`;
                                    document.querySelector('.card-header-stock-products').textContent = `Deine Artikel im Lager (Anzahl: ${stockProductCount ?? '0'})`;

                                    if (defaultElement) {
                                        defaultElement.focus();
                                    }
                                    
                                    showToast('Erfolgreich', 'Artikel erfolgreich erstellt.', 'success');
                                    
                                } else {
                                    showToast('Fehler', 'Fehler beim Aktualisieren der Artikelanzahl.', 'danger');
                                }
                            })
                            .catch(() => {
                                showToast('Fehler', 'Fehler beim Abrufen der aktualisierten Artikelliste.', 'danger');
                            });

                        // Reset the form after successful creation
                        $('#createProductForm')[0].reset();
                    } else {
                        showToast('Fehler', data.message || 'Fehler beim Erstellen des Artikels.', 'danger');
                    }
                })
                .fail(function () {
                    showToast('Fehler', 'Fehler beim Senden des Formulars.', 'danger');
                });
            });

            $('#editProductModal form').on('submit', function (e) {
                e.preventDefault(); // Prevent the form from submitting normally

                const formData = $(this).serialize(); // Serialize form data

                // Send the AJAX request
                $.post('seller_products.php', formData + '&update_product=true')
                    .done(function (response) {
                        if (response.success) {
                            // Close the modal
                            $('#editProductModal').modal('hide');

                            // Refresh the table
                            refreshProductTable($('#hiddenSellerNumber').val())
                                .then(() => {
                                    showToast('Erfolgreich', response.message, 'success');
                                })
                                .catch(() => {
                                    showToast('Fehler', 'Fehler beim Aktualisieren der Tabelle.', 'error');
                                });
                        } else {
                            // Show error in the modal
                            $('#editProductAlert')
                                .removeClass('d-none')
                                .text(response.message);
                        }
                    })
                    .fail(function () {
                        // Show generic error message
                        $('#editProductAlert')
                            .removeClass('d-none')
                            .text('Fehler beim Senden des Formulars.');
                    });
            });
            
            $('.bulk-move-to-sale').on('click', function () {
                const selectedProducts = Array.from(document.querySelectorAll('.bulk-select-stock:checked'))
                    .map(checkbox => checkbox.value);

                if (selectedProducts.length === 0) {
                    showToast('Hinweis', 'Bitte wähle mindestens einen Artikel aus.', 'warning');
                    return;
                }

                $.post('seller_products.php', {
                    bulk_action: 'move_to_sale',
                    product_ids: selectedProducts,
                    seller_number: sellerNumber,
                    csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>',
                })
                    .done(function (response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            // Start by refreshing the stock table
                            refreshStockTable()
                                .then(() => {
                                    // Once the stock table is updated, refresh the product table
                                    return refreshProductTable(sellerNumber);
                                })
                                .then(() => {
                                    // Safely handle cases where data.data is undefined or empty
                                    const activeProductCount = document.querySelectorAll('#productTable tbody tr').length;
                                    const stockProductCount = document.querySelectorAll('#stockTable tbody tr').length;
                                    
                                    // Update the active products count dynamically
                                    document.querySelector('.card-header-sale-products').textContent = `Deine aktiven Artikel (Anzahl: ${activeProductCount})`;
                                    document.querySelector('.card-header-remaining').textContent = `Neuen Artikel erstellen (max. ${maxProdPerSellers} noch: ${maxProdPerSellers - activeProductCount} möglich)`;
                                    document.querySelector('.card-header-stock-products').textContent = `Deine Aktikel im Lager (Anzahl: ${stockProductCount})`;
                                    // Show a single success toast after both tables are refreshed
                                    showToast('Erfolgreich', 'Die ausgewählten Artikel wurden zum Verkauf gestellt.', 'success');
                                })
                                .catch(() => {
                                    // Handle any failure during the table refresh process
                                    showToast('Fehler', 'Fehler beim Aktualisieren der Tabellen.', 'danger');
                                });
                        } else {
						// Handle error returned from backend (e.g., exceeding product limit)
						showToast('Fehler', data.message, 'danger');
					}
                    })
                    .fail(function () {
                        showToast('Fehler', 'Fehler beim Senden der Anfrage.', 'danger');
                    });
            });
            
            $('.bulk-delete-stock').on('click', function () {
                const selectedProducts = Array.from(document.querySelectorAll('.bulk-select-stock:checked'))
                    .map(checkbox => checkbox.value);

                if (selectedProducts.length === 0) {
                    showToast('Hinweis', 'Bitte wähle mindestens einen Artikel aus.', 'warning');
                    return;
                }

                $.post('seller_products.php', {
                    bulk_action: 'delete',
                    product_ids: selectedProducts,
                    seller_number: sellerNumber,
                    csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>',
                })
                    .done(function (response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            // Refresh the stock table and header
                            refreshStockTable()
                                .then(() => {
                                    // Safely handle cases where data.data is undefined or empty
                                    const activeProductCount = document.querySelectorAll('#productTable tbody tr').length;
                                    const stockProductCount = document.querySelectorAll('#stockTable tbody tr').length;
                                    
                                    // Update the active products count dynamically
                                    document.querySelector('.card-header-stock-products').textContent = `Deine Aktikel im Lager (Anzahl: ${stockProductCount})`;
                                    // Show success toast
                                    showToast('Erfolgreich', 'Die ausgewählten Artikel wurden gelöscht.', 'success');
                                })
                                .catch(() => {
                                    showToast('Fehler', 'Fehler beim Aktualisieren der Tabelle.', 'error');
                                });
                        } else {
                            // Show error toast
                            showToast('Fehler', 'Die Artikel konnten nicht gelöscht werden.', 'danger');
                        }
                    })
                    .fail(function () {
                        showToast('Fehler', 'Fehler beim Senden der Anfrage.', 'danger');
                    });
            });
        
			$('#importProductsModal').on('submit', function (e) {
				e.preventDefault(); // Prevent the default form submission

				// Show loading spinner and disable the import button
				$('#importLoadingSpinner').removeClass('d-none');
				$('#confirm_import').prop('disabled', true);
	
				const form = document.getElementById('importProductsForm'); // If this returns null
				const formData = new FormData(form); // Collect form data

				// Manually add the `confirm_import` marker to FormData
				formData.append('confirm_import', '1');
				formData.append('csrf_token','<?php echo htmlspecialchars(generate_csrf_token()); ?>');
				formData.append("encoding", document.querySelector('input[name="encoding"]:checked').value);
				
				// Send the data via AJAX
				$.ajax({
					url: 'seller_products.php', // Replace with your backend URL
					type: 'POST',
					data: formData,
					processData: false, // Required for FormData
					contentType: false, // Required for FormData
					success: function (response) {
						// Parse JSON response if needed
						const data = typeof response === 'string' ? JSON.parse(response) : response;

						if (data.success) {
							// Handle success, e.g., refresh tables or show a success toast
							showToast('Erfolgreich', 'Artikel wurden erfolgreich importiert.', 'success');
						} else {
							// Handle error, e.g., show an error toast
							showToast('Fehler', data.message, 'danger');
						}
					},
					error: function () {
						// Handle AJAX errors
						showToast('Fehler', 'Es gab ein Problem beim Import.', 'danger');
					},
					complete: function () {
						// Hide spinner and re-enable button
						$('#importLoadingSpinner').addClass('d-none');
						$('#confirm_import').prop('disabled', false);
					}
				});
			});
		
			// Attach an event listener to the modal
			$('#importProductsModal').on('hidden.bs.modal', function () {
				// Refresh the page when the modal is closed
				location.reload();
			});
		</script>
        <script nonce="<?php echo $nonce; ?>">
            document.addEventListener('DOMContentLoaded', function () {
                // Attach event listeners to the edit buttons
                document.querySelectorAll('.edit-product-btn').forEach(button => {
                    button.addEventListener('click', function () {
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
                $(document).ready(function () {
                    $('#priceValidationModal .modal-body').text('<?php echo $validation_message; ?>');
                    $('#priceValidationModal').modal('show');
                });
            </script>
        <?php } ?>
        <?php if (isset($update_validation_message)) { ?>
            <script nonce="<?php echo $nonce; ?>">
                $(document).ready(function () {
                    $('#editProductAlert').text('<?php echo $update_validation_message; ?>').removeClass('d-none');
                    $('#editProductModal').modal('show');
                });
            </script>
        <?php } ?>
        <script nonce="<?php echo $nonce; ?>">
            // Show the HTML element once the DOM is fully loaded
            document.addEventListener("DOMContentLoaded", function () {			
                document.documentElement.style.visibility = "visible";
                document.addEventListener('DOMContentLoaded', function () {
                    const defaultElement = document.getElementById('name'); // Replace 'name' with the ID of your target element
                    if (defaultElement) {
                        defaultElement.focus();
                    }
                });
            });
        </script>
		<script nonce="<?php echo $nonce; ?>">
			document.addEventListener("DOMContentLoaded", function () {
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