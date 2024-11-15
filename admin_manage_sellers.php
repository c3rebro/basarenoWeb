<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

require_once 'utilities.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

// Handle setting session variables for product creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_session'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }

    $_SESSION['seller_id'] = intval($_POST['seller_id']);
    $_SESSION['seller_hash'] = $_POST['hash'];

    echo json_encode(['success' => true]);
    exit;
}

// Handle seller addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_seller'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for adding seller");
		
        die("CSRF token validation failed.");
    }
	
	$family_name = htmlspecialchars($_POST['family_name'], ENT_QUOTES, 'UTF-8');
	$given_name = htmlspecialchars($_POST['given_name'], ENT_QUOTES, 'UTF-8');
	$email = htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8');
	$phone = htmlspecialchars($_POST['phone'], ENT_QUOTES, 'UTF-8');
	$street = htmlspecialchars($_POST['street'], ENT_QUOTES, 'UTF-8');
	$house_number = htmlspecialchars($_POST['house_number'], ENT_QUOTES, 'UTF-8');
	$zip = htmlspecialchars($_POST['zip'], ENT_QUOTES, 'UTF-8');
	$city = htmlspecialchars($_POST['city'], ENT_QUOTES, 'UTF-8');
	$verified = isset($_POST['verified']) ? 1 : 0;
	$checkout_id = htmlspecialchars($_POST['checkout_id'], ENT_QUOTES, 'UTF-8');
	$consent = 0;
	
	if (empty($family_name) || empty($email)) {
		$message_type = 'danger';
		$message = "Erforderliche Felder fehlen.";
	} else {
		do {
			$seller_id = rand(1, 10000);
			$stmt = $conn->prepare("SELECT id FROM sellers WHERE id=?");
			$stmt->bind_param("i", $seller_id);
			$stmt->execute();
			$result = $stmt->get_result();
		} while ($result->num_rows > 0);

		$hash = generate_hash($email, $seller_id);

		$stmt = $conn->prepare("INSERT INTO sellers (id, email, reserved, family_name, given_name, phone, street, house_number, zip, city, checkout_id, hash, verified, consent) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
		$stmt->bind_param("issssssssssii", $seller_id, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $checkout_id, $hash, $verified, $consent);

		if ($stmt->execute()) {
			$message_type = 'success';
			$message = "Verkäufer erfolgreich hinzugefügt.";
			log_action($conn, $user_id, "Seller added", "ID=$seller_id, Name=$family_name, Email=$email, Verified=$verified");
		} else {
			$message_type = 'danger';
			$message = "Fehler beim Hinzufügen des Verkäufers: " . $conn->error;
			log_action($conn, $user_id, "Error adding seller", $conn->error);
		}
	}  
}

// Handle seller update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_seller'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    }
	
	$seller_id = intval($_POST['seller_id']);
	$family_name = htmlspecialchars($_POST['family_name'], ENT_QUOTES, 'UTF-8');
	$given_name = htmlspecialchars($_POST['given_name'], ENT_QUOTES, 'UTF-8');
	$email = htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8');
	$phone = htmlspecialchars($_POST['phone'], ENT_QUOTES, 'UTF-8');
	$street = htmlspecialchars($_POST['street'], ENT_QUOTES, 'UTF-8');
	$house_number = htmlspecialchars($_POST['house_number'], ENT_QUOTES, 'UTF-8');
	$zip = htmlspecialchars($_POST['zip'], ENT_QUOTES, 'UTF-8');
	$city = htmlspecialchars($_POST['city'], ENT_QUOTES, 'UTF-8');
	$checkout_id = htmlspecialchars($_POST['checkout_id'], ENT_QUOTES, 'UTF-8');
	$verified = isset($_POST['verified']) ? 1 : 0;

	if (empty($family_name) || empty($email)) {
            $message_type = 'danger';
            $message = "Erforderliche Felder fehlen.";
	} elseif (!is_checkout_id_unique($conn, $checkout_id, $seller_id)) {
            $message_type = 'danger';
            $message = "Checkout ID bereits vorhanden.";
	} else {
		$stmt = $conn->prepare("UPDATE sellers SET family_name=?, given_name=?, email=?, phone=?, street=?, house_number=?, zip=?, city=?, verified=?, checkout_id=? WHERE id=?");
		$stmt->bind_param("ssssssssisi", $family_name, $given_name, $email, $phone, $street, $house_number, $zip, $city, $verified, $checkout_id, $seller_id);

		if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = "Verkäufer erfolgreich aktualisiert.";
			log_action($conn, $user_id, "Seller updated", "ID=$seller_id, Name=$family_name, Email=$email, Verified=$verified");
		} else {
                    $message_type = 'danger';
                    $message = "Fehler beim Aktualisieren des Verkäufers: " . $conn->error;
                    log_action($conn, $user_id, "Error updating seller", $conn->error);
		}
	}
}

// Handle seller deletion
// Handle seller deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_seller'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for deleting seller");
        die("CSRF token validation failed.");
    }

    $seller_id = intval($_POST['seller_id']);
    $delete_products = isset($_POST['delete_products']) ? $_POST['delete_products'] : false;

    if (seller_has_products($conn, $seller_id)) {
        if ($delete_products) {
            // Attempt to delete products
            $stmt = $conn->prepare("DELETE FROM products WHERE seller_id=?");
            $stmt->bind_param("i", $seller_id);
            $deletion_successful = $stmt->execute();

            if ($deletion_successful) {
                log_action($conn, $user_id, "Products deleted for seller", "Seller ID=$seller_id");
            } else {
                $message_type = 'danger';
                $message = "Fehler beim Löschen der Produkte: " . $conn->error;
                log_action($conn, $user_id, "Error deleting products", $conn->error);
            }
        } else {
            // Inform the user that products must be deleted first
            $message_type = 'danger';
            $message = "Dieser Verkäufer hat noch Produkte. Möchten Sie wirklich fortfahren?";
            return; // Exit the function early to prevent seller deletion
        }
    } else {
        // If no products exist, set deletion_successful to true
        $deletion_successful = true;
    }

    // Proceed to delete the seller if products were deleted successfully or there were no products
    if ($deletion_successful) {
        $stmt = $conn->prepare("DELETE FROM sellers WHERE id=?");
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute()) {
            $message_type = 'success';
            $message = "Verkäufer erfolgreich gelöscht.";
            log_action($conn, $user_id, "Seller deleted", "ID=$seller_id");
        } else {
            $message_type = 'danger';
            $message = "Fehler beim Löschen des Verkäufers: " . $conn->error;
            log_action($conn, $user_id, "Error deleting seller", $conn->error);
        }
    }
}

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_import'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    }
	
    $file = $_FILES['csv_file']['tmp_name'];
    $delimiter = $_POST['delimiter'];
    $encoding = $_POST['encoding'];
    $handle = fopen($file, 'r');
    $consent = 1;
	
    if ($handle !== FALSE) {
        log_action($conn, $user_id, "CSV import initiated");

        while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            if ($encoding !== 'utf-8') {
                // Convert each field to UTF-8 if the file encoding is not UTF-8
                $sourceEncoding = $encoding === 'ansi' ? 'Windows-1252' : $encoding;
                $data = array_map(function($field) use ($sourceEncoding) {
                    return mb_convert_encoding($field, 'UTF-8', $sourceEncoding);
                }, $data);
            }

            $data = array_map('sanitize_input', $data);
            $data[0] = sanitize_id($data[0]);

            if (empty($data[1]) || empty($data[5])) {
                continue;
            }

            $seller_id = $data[0];
            $family_name = htmlspecialchars(sanitize_name($data[1]), ENT_QUOTES, 'UTF-8');
            $given_name = htmlspecialchars(sanitize_name($data[2] ?: "Nicht angegeben"), ENT_QUOTES, 'UTF-8');
            $city = htmlspecialchars($data[3] ?: "Nicht angegeben", ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars($data[4] ?: "Nicht angegeben", ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(sanitize_email($data[5]), ENT_QUOTES, 'UTF-8');

            $reserved = 0;
            $street = "Nicht angegeben";
            $house_number = "Nicht angegeben";
            $zip = "Nicht angegeben";
            $hash = generate_hash($email, $seller_id);
            $verification_token = NULL;
            $verified = 0;

            $stmt = $conn->prepare("INSERT INTO sellers (id, email, reserved, verification_token, family_name, given_name, phone, street, house_number, zip, city, hash, verified, consent) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE 
                                    email=VALUES(email), reserved=VALUES(reserved), verification_token=VALUES(verification_token), family_name=VALUES(family_name), given_name=VALUES(given_name), phone=VALUES(phone), street=VALUES(street), house_number=VALUES(house_number), zip=VALUES(zip), city=VALUES(city), hash=VALUES(hash), verified=VALUES(verified), consent=VALUES(consent)");
            $stmt->bind_param("isssssssssssii", $seller_id, $email, $reserved, $verification_token, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $hash, $verified, $consent);
            if (!$stmt->execute()) {
                log_action($conn, $user_id, "Error importing seller", "Email=$email, Error=" . $conn->error);
            }
        }

        fclose($handle);
        log_action($conn, $user_id, "CSV import completed successfully");
		
        $message_type = 'success';
        $message = 'Verkäufer erfolgreich importiert. Bestehende Einträge aktualisiert.';
    } else {
        log_action($conn, $user_id, "Error opening CSV file for import");

        $message_type = 'danger';
        $message = 'Fehler beim Öffnen der CSV Datei.';
    }
}

// Product exist check
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['check_seller_products'])) {
    $seller_id = intval($_POST['seller_id']);
    $has_products = seller_has_products($conn, $seller_id);
    echo json_encode(['has_products' => $has_products]);
    exit;
}

// Handle seller checkout
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['checkout_seller'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for seller checkout");
        echo json_encode(['error' => 'CSRF token validation failed.']);
        exit;
    }

    $seller_id = intval($_POST['seller_id']);
    $hash = $_POST['hash'];

    // Set session variables for checkout
    $_SESSION['seller_id'] = $seller_id;
    $_SESSION['seller_hash'] = $hash;

    // Perform any additional actions needed for checkout
    $stmt = $conn->prepare("UPDATE sellers SET checkout=TRUE WHERE id=?");
    $stmt->bind_param("i", $seller_id);
    if ($stmt->execute()) {
        log_action($conn, $user_id, "Seller checked out", "ID=$seller_id");
        echo json_encode(['success' => true]);
    } else {
        log_action($conn, $user_id, "Error checking out seller", $conn->error);
        echo json_encode(['error' => 'Fehler beim Auschecken des Verkäufers: ' . $conn->error]);
    }
    exit;
}

// Handle product update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    } else {
        $bazaar_id = get_current_bazaar_id($conn);
        $product_id = intval($_POST['product_id']);
        $name = htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars($_POST['size'], ENT_QUOTES, 'UTF-8');
        $price = floatval($_POST['price']);
		$sold = isset($_POST['sold']) ? 1 : 0; // Convert checkbox to boolean
		
        $stmt = $conn->prepare("UPDATE products SET name=?, size=?, price=?, sold=?, bazaar_id=? WHERE id=?");
        $stmt->bind_param("ssdiii", $name, $size, $price, $sold, $bazaar_id, $product_id);
        if ($stmt->execute()) {
            $message_type = 'success';
            $message = "Produkt erfolgreich aktualisiert.";
            log_action($conn, $user_id, "Product updated", "ID=$product_id, Name=$name, Size=$size, Bazaar ID=$bazaar_id, Price=$price, Sold=$sold");
        } else {
            $message_type = 'danger';
            $message = "Fehler beim Aktualisieren des Produkts: " . $conn->error;
            log_action($conn, $user_id, "Error updating product", $conn->error);
        }
    }
}

// Handle product deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_product'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    } else {
        $product_id = intval($_POST['product_id']);
        $seller_id = intval($_POST['seller_id']);

        $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND seller_id=?");
        $stmt->bind_param("ii", $product_id, $seller_id);
        if ($stmt->execute()) {
			$message_type = 'success';
            $message = "Produkt erfolgreich gelöscht.";
            log_action($conn, $user_id, "Product deleted", "ID=$product_id, Seller ID=$seller_id");
        } else {
			$message_type = 'danger';
            $message = "Fehler beim Löschen des Produkts: " . $conn->error;
            log_action($conn, $user_id, "Error deleting product", $conn->error);
        }
    }
}

// Default filter is "undone"
$filter = isset($_COOKIE['filter']) ? $_COOKIE['filter'] : 'undone';
$sort_by = isset($_COOKIE['sort_by']) ? $_COOKIE['sort_by'] : 'id';
$order = isset($_COOKIE['order']) ? $_COOKIE['order'] : 'ASC';

// Validate sort_by to prevent SQL injection
$valid_columns = ['id', 'checkout_id', 'family_name', 'checkout'];
if (!in_array($sort_by, $valid_columns)) {
    $sort_by = 'id'; // Default to 'id' if invalid
}

// Get all sellers
$sql = "SELECT * FROM sellers";
if ($filter == 'done') {
    $sql .= " WHERE checkout=TRUE";
} elseif ($filter == 'undone') {
    $sql .= " WHERE checkout=FALSE";
}

$sql .= " ORDER BY $sort_by $order";

$sellers_result = $conn->query($sql);
log_action($conn, $user_id, "Fetched sellers", "Filter='$filter', Sort='$sort_by $order', Count=" . $sellers_result->num_rows);

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verkäufer Verwalten</title>
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
                <li class="nav-item active">
                    <a class="nav-link" href="admin_manage_sellers.php">Verkäufer verwalten <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
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
		<!-- Hidden input for CSRF token -->
		<input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <h1 class="text-center mb-4 headline-responsive">Verkäuferverwaltung</h1>
		<?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
		
        <h3 class="mt-5">Neuen Verkäufer hinzufügen</h3>	
		<button class="btn btn-primary mb-3 btn-block" type="button" data-toggle="collapse" data-target="#addSellerForm" aria-expanded="false" aria-controls="addSellerForm">
			Formular: Neuer Verkäufer
		</button>
        <form action="admin_manage_sellers.php" method="post">
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
			
                <div class="collapse" id="addSellerForm">
				
				<div class="card card-body">
					<form action="admin_manage_sellers.php" method="post">
						<!-- CSRF Token -->
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
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
								<label for="house_number">Nr.:</label>
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
					</form>
				</div>
            </div>
            
            <button type="button" class="btn btn-info btn-block" data-toggle="modal" data-target="#importSellersModal">Verkäufer importieren</button>
            <button type="button" class="btn btn-secondary btn-block" id="printVerifiedSellers">Verkäuferliste drucken</button>
        </form>

        <h3 class="mt-5">Verkäuferliste</h3>
        <div class="form-row">
            <div class="form-group col-md-12">
                <label for="filter">Filter:</label>
                <select class="form-control" id="filter" name="filter">
                    <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>Alle</option>
                    <option value="done" <?php echo $filter == 'done' ? 'selected' : ''; ?>>Abgeschlossen</option>
                    <option value="undone" <?php echo $filter == 'undone' ? 'selected' : ''; ?>>Nicht abgeschlossen</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="sort_by">Sortieren nach:</label>
                <select class="form-control" id="sort_by" name="sort_by">
                    <option value="id" <?php echo $sort_by == 'id' ? 'selected' : ''; ?>>VerkäuferNr.</option>
                    <option value="checkout_id" <?php echo $sort_by == 'checkout_id' ? 'selected' : ''; ?>>AbrNr.</option>
                    <option value="family_name" <?php echo $sort_by == 'family_name' ? 'selected' : ''; ?>>Nachname</option>
                    <option value="checkout" <?php echo $sort_by == 'checkout' ? 'selected' : ''; ?>>Abgerechnet</option>
                </select>
            </div>

            <div class="form-group col-md-6">
                <label for="order">Reihenfolge:</label>
                <select class="form-control" id="order" name="order">
                    <option value="ASC" <?php echo $order == 'ASC' ? 'selected' : ''; ?>>Aufsteigend</option>
                    <option value="DESC" <?php echo $order == 'DESC' ? 'selected' : ''; ?>>Absteigend</option>
                </select>
            </div>
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
                        <th class="hidden">Telefon</th> <!-- Hidden Column -->
                        <th class="hidden">Straße</th> <!-- Hidden Column -->
                        <th class="hidden">Nr.</th> <!-- Hidden Column -->
                        <th class="hidden">PLZ</th> <!-- Hidden Column -->
                        <th class="hidden">Stadt</th> <!-- Hidden Column -->
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                            <?php
                            if ($sellers_result->num_rows > 0) {
                                    while ($row = $sellers_result->fetch_assoc()) {
                                            $hash = htmlspecialchars($row['hash'], ENT_QUOTES, 'UTF-8');
                                            $checkout_class = $row['checkout'] ? 'done' : '';
                                            echo "<tr class='$checkout_class'>
                                                            <td>" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['checkout_id'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['family_name'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['given_name'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . ($row['verified'] ? 'Ja' : 'Nein') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['street'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['house_number'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['zip'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['city'], ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='action-cell'>
                                                                    <select class='form-control action-dropdown' data-seller-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "' data-seller-hash='$hash'>
                                                                            <option value=''>Aktion wählen</option>
                                                                            <option value='checkout'>Abrechnen</option>
                                                                            <option value='edit'>Bearbeiten</option>
                                                                            <option value='delete'>Löschen</option>
                                                                            <option value='show_products'>Produkte anzeigen</option>
                                                                            <option value='create_products'>Produkte erstellen</option>
                                                                    </select>
                                                                    <button class='btn btn-primary btn-sm execute-action' data-seller-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>Ausführen</button>
                                                            </td>
                                                      </tr>";
                                            echo "<tr class='hidden' id='seller-products-{$row['id']}'>
                                                    <td colspan='11'>
                                                        <div class='table-responsive'>
                                                            <table class='table table-bordered'>
                                                                <thead>
                                                                    <tr>
                                                                        <th>Produktname</th>
                                                                        <th>Größe</th>
                                                                        <th>Preis</th>
                                                                        <th>Verkauft</th>
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
                                    echo "<tr><td colspan='12'>Keine Verkäufer gefunden.</td></tr>";
                            }
                            ?>
                    </tbody>
            </table>
        </div>
    </div>

    <!-- Import Sellers Modal -->
    <div class="modal fade" id="importSellersModal" tabindex="-1" role="dialog" aria-labelledby="importSellersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content modal-xxl">
                <div class="modal-header">
                    <h5 class="modal-title" id="importSellersModalLabel">Verkäufer importieren</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form enctype="multipart/form-data" method="post" action="">
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
                        <button type="submit" class="btn btn-primary mt-3 hidden" name="confirm_import" id="confirm_import">Bestätigen und Importieren</button>
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
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editSellerModalLabel">Verkäufer bearbeiten</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="seller_id" id="editSellerId">
                        <div class="form-group">
                            <label for="editSellerIdDisplay">Verk.Nr.:</label>
                            <input type="text" class="form-control" id="editSellerIdDisplay" name="seller_id_display" disabled>
                        </div>
						<div class="form-group">
							<label for="editSellerCheckoutId">Abr.Nr.:</label>
							<input type="text" class="form-control" id="editSellerCheckoutId" name="checkout_id">
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
                            <label for="editSellerHouseNumber">Nr.:</label>
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
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
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
                    <div class="form-group">
                        <label for="editProductSold">Verkauft:</label>
                        <input type="checkbox" id="editProductSold" name="sold">
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
				<!-- CSRF Token -->
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
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

	<!-- Confirm Checkout Modal -->
	<div class="modal fade" id="confirmCheckoutModal" tabindex="-1" role="dialog" aria-labelledby="confirmCheckoutModalLabel" aria-hidden="true">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="confirmCheckoutModalLabel">Verkäufer abrechnen</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<p>Möchten Sie diesen Verkäufer wirklich abrechnen?</p>
					<input type="hidden" id="confirmCheckoutSellerId">
					<input type="hidden" id="confirmCheckoutHash">
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
					<button type="button" class="btn btn-primary" id="confirmCheckoutButton">Abrechnen</button>
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
	
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script nonce="<?php echo $nonce; ?>">
        function editSeller(id, checkout_id, family_name, given_name, email, phone, street, house_number, zip, city, verified) {
			$('#editSellerId').val(id);
			$('#editSellerIdDisplay').val(id);
			$('#editSellerCheckoutId').val(checkout_id);
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
            if (row.hasClass('hidden')) {
                loadProducts(sellerId);
                row.removeClass('hidden');
            } else {
                row.addClass('hidden');
            }
        }

        function loadProducts(sellerId) {
            $.ajax({
                url: 'load_seller_products.php',
                method: 'GET',
                data: { seller_id: sellerId },
                success: function(response) {
                    const productsContainer = $(`#products-${sellerId}`);
                    productsContainer.html(response);

                    // Attach event listeners to the edit buttons
                    productsContainer.find('.edit-product-btn').on('click', function() {
                        const productId = $(this).data('id');
                        const productName = $(this).data('name');
                        const productSize = $(this).data('size');
                        const productPrice = $(this).data('price');
                        editProduct(productId, productName, productSize, productPrice);
                    });

                    // Optionally, attach event listeners to other elements like checkboxes here
                },
                error: function() {
                    alert('Fehler beim Laden der Produkte.');
                }
            });
        }

        function editProduct(productId, name, size, price, sold) {
                $('#editProductId').val(productId);
                $('#editProductName').val(name);
                $('#editProductSize').val(size);
                $('#editProductPrice').val(price.toFixed(2));
                $('#editProductSold').prop('checked', sold);
                $('#editProductModal').modal('show');
        }

        $(document).on('click', '.execute-action', function() {
			const sellerId = $(this).data('seller-id');
			const action = $(`.action-dropdown[data-seller-id="${sellerId}"]`).val();
			const hash = $(`.action-dropdown[data-seller-id="${sellerId}"]`).data('seller-hash');
			const csrfToken = $('#csrf_token').val(); // Get the CSRF token

			if (action === 'edit') {
				const row = $(this).closest('tr');
				const checkout_id = row.find('td:nth-child(2)').text(); // Abr.Nr.
				const family_name = row.find('td:nth-child(3)').text(); // Nachname
				const given_name = row.find('td:nth-child(4)').text(); // Vorname
				const email = row.find('td:nth-child(5)').text(); // E-Mail
				const verified = row.find('td:nth-child(6)').text() === 'Ja'; // Verifiziert
				const phone = row.find('td:nth-child(7)').text(); // Telefon
				const street = row.find('td:nth-child(8)').text(); // Straße
				const house_number = row.find('td:nth-child(9)').text(); // Nr.
				const zip = row.find('td:nth-child(10)').text(); // PLZ
				const city = row.find('td:nth-child(11)').text(); // Stadt

				editSeller(sellerId, checkout_id, family_name, given_name, email, phone, street, house_number, zip, city, verified);
			} else if (action === 'delete') {
				$.post('admin_manage_sellers.php', { check_seller_products: true, seller_id: sellerId, csrf_token: csrfToken }, function(response) {
					if (response.has_products) {
						$('#confirmDeleteSellerId').val(sellerId);
						$('#confirmDeleteModal').modal('show');
					} else {
						if (confirm('Möchten Sie diesen Verkäufer wirklich löschen?')) {
							$.post('admin_manage_sellers.php', { delete_seller: true, seller_id: sellerId, csrf_token: csrfToken }, function(response) {
								location.reload();
							});
						}
					}
				}, 'json');
                        } else if (action === 'show_products') {
                            toggleProducts(sellerId);
                        } else if (action === 'create_products') {
                            // Set session variables and redirect
                            $.post('admin_manage_sellers.php', { set_session: true, seller_id: sellerId, hash: hash, csrf_token: csrfToken }, function(response) {
                                if (response.success) {
                                    window.location.href = 'seller_products.php';
                                } else {
                                    alert('Fehler beim Setzen der Sitzungsvariablen: ' + response.error);
                                }
                            }, 'json');
                        } else if (action === 'checkout') {
                                            $('#confirmCheckoutSellerId').val(sellerId);
                                            $('#confirmCheckoutHash').val(hash);
                                            $('#confirmCheckoutModal').modal('show');
                                    }
                    });

        $('#confirmDeleteButton').on('click', function() {
			const sellerId = $('#confirmDeleteSellerId').val();
			const csrfToken = $('#csrf_token').val(); // Get the CSRF token
			$.post('admin_manage_sellers.php', { delete_seller: true, seller_id: sellerId, delete_products: true, csrf_token: csrfToken }, function(response) {
				location.reload();
			});
		});

        $('#confirmCheckoutButton').on('click', function() {
                const sellerId = $('#confirmCheckoutSellerId').val();
                const hash = $('#confirmCheckoutHash').val();
                const csrfToken = $('#csrf_token').val();

                $.post('admin_manage_sellers.php', { checkout_seller: true, seller_id: sellerId, hash: hash, csrf_token: csrfToken }, function(response) {
                        if (response.success) {
                                window.location.href = 'checkout.php';
                        } else {
                                alert('Fehler beim Auschecken des Verkäufers: ' + response.error);
                        }
                }, 'json');
        });

        $('#filter').on('change', function() {
            const filter = $(this).val();
            document.cookie = `filter=${filter}; path=/`;
            window.location.href = `admin_manage_sellers.php?filter=${filter}`;
        });

        $('#sort_by, #order').on('change', function() {
            const sort_by = $('#sort_by').val();
            const order = $('#order').val();
            document.cookie = `sort_by=${sort_by}; path=/`;
            document.cookie = `order=${order}; path=/`;
            window.location.href = `admin_manage_sellers.php?sort_by=${sort_by}&order=${order}`;
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
    <script nonce="<?php echo $nonce; ?>">
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