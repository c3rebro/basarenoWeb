<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin'])) {
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

//$bazaar_id = get_current_bazaar_id($conn) === 0 ? get_bazaar_id_with_open_registration($conn) : null;
$active_bazaar_id = get_active_or_registering_bazaar_id($conn);
$open_registration_bazaar_id = get_bazaar_id_with_open_registration($conn);
$bazaar_id = $active_bazaar_id !== 0 ? $active_bazaar_id : $open_registration_bazaar_id;
$bazaar_filter_ids = array_values(array_unique(array_filter([
    $active_bazaar_id,
    $open_registration_bazaar_id
])));

$checkout_id = 0;

// Handle setting session variables for product creation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['set_session'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }

    $seller_number = intval($_POST['seller_number']);

    // Check if the seller exists
    $stmt = $conn->prepare("SELECT user_id FROM sellers WHERE seller_number = ?");
    $stmt->bind_param("i", $seller_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Verkäufer nicht gefunden.']);
        exit;
    }

    $seller = $result->fetch_assoc();
    $seller_user_id = $seller['user_id'];

    // Allow only admins to set the session
    if ($_SESSION['role'] === 'admin') {
        $_SESSION['seller_number'] = $seller_number;
        $_SESSION['acting_as_admin'] = ($_SESSION['role'] === 'admin'); // Flag admin actions

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Nicht autorisiert.']);
    }
    exit;
}

// Handle AJAX search request
if ($_SERVER["REQUEST_METHOD"] === "GET" && filter_input(INPUT_GET, 'search_term') !== null) {
    $search_term = trim(filter_input(INPUT_GET, 'search_term'));

    if (strlen($search_term) >= 3) {
        // Prepare SQL query
        $sql = "
            SELECT 
                u.id AS user_id,
                ud.family_name,
                ud.given_name,
                ud.email,
                ud.phone
            FROM 
                users u
            LEFT JOIN 
                user_details ud ON u.id = ud.user_id
            WHERE 
                ud.family_name LIKE ? 
                OR ud.given_name LIKE ? 
                OR ud.email LIKE ?
            ORDER BY 
                ud.family_name ASC, ud.given_name ASC
        ";

        $search_like = "%" . $search_term . "%";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sss", $search_like, $search_like, $search_like);
            $stmt->execute();
            $result = $stmt->get_result();

            $search_results = [];
            while ($row = $result->fetch_assoc()) {
                $search_results[] = [
                    "user_id" => $row["user_id"],
                    "family_name" => htmlspecialchars($row["family_name"], ENT_QUOTES, 'UTF-8'),
                    "given_name" => htmlspecialchars($row["given_name"], ENT_QUOTES, 'UTF-8'),
                    "email" => htmlspecialchars($row["email"], ENT_QUOTES, 'UTF-8'),
                    "phone" => htmlspecialchars($row["phone"], ENT_QUOTES, 'UTF-8'),
                ];
            }

            echo json_encode($search_results);
            exit;
        }
    }

    echo json_encode([]);
    exit;
}

// Handle seller addition
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'add_seller') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    // Check if creating a new user or adding a seller number to an existing user
    $create_new_user = filter_input(INPUT_POST, 'createNewUser') !== null && $_POST['createNewUser'] == '1';
    $selected_user_id = filter_input(INPUT_POST, 'selected_user_id') !== null ? intval($_POST['selected_user_id']) : null;

    if ($create_new_user) {
        // Create a new user with a seller number
        $family_name = sanitize_input($_POST['family_name']);
        $given_name = sanitize_input($_POST['given_name']);
        $email = sanitize_input($_POST['email']);
        $password = filter_input(INPUT_POST, 'password');
        $phone = sanitize_input($_POST['phone']);
        $street = sanitize_input($_POST['street']);
        $house_number = sanitize_input($_POST['house_number']);
        $zip = sanitize_input($_POST['zip']);
        $city = sanitize_input($_POST['city']);
        $consent = filter_input(INPUT_POST, 'consent') !== null ? 1 : 0;

        try {
			// Validate email duplication
			$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
			$stmt->bind_param("s", $email);
			$stmt->execute();
			$result = $stmt->get_result();
			if ($result->num_rows > 0) {
				$errors[] = "Ein Benutzer mit dieser E-Mail existiert bereits.";
			}

			// Validate password complexity
			if (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
				$errors[] = "Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Groß- und Kleinbuchstaben enthalten.";
			}

			// Hash the password
			$password_hash = password_hash($password, PASSWORD_BCRYPT);

			// Begin transaction
			$conn->begin_transaction();

            // Insert into users table
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, user_verified) VALUES (?, ?, 'seller', 1)");
            $stmt->bind_param("ss", $email, $password_hash);
            $stmt->execute();
            $user_id = $conn->insert_id;

            // Insert into user_details table
            $stmt = $conn->prepare("INSERT INTO user_details (user_id, family_name, given_name, email, phone, street, house_number, zip, city, consent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssssi", $user_id, $family_name, $given_name, $email, $phone, $street, $house_number, $zip, $city, $consent);
            $stmt->execute();

            // Proceed to seller number assignment
			if (!empty($errors)) {
				throw new Exception(implode(' ', $errors));
			}

			 $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
			$message_type = 'danger';
			$message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } elseif ($selected_user_id) {
        // Add a seller number to an existing user
        $user_id = $selected_user_id;
    } else {
        die("Ungültige Anforderung: Weder neuer Benutzer noch bestehender Benutzer ausgewählt.");
    }

    // Insert into sellers table
    try {
		// Step 1: Check for the lowest available revoked seller number (set to 0)
		$stmt = $conn->prepare("
			SELECT seller_number 
			FROM sellers 
			WHERE seller_number >= 100 
			  AND user_id = 0 
			  AND bazaar_id = 0 
			  AND seller_verified = 0
			ORDER BY seller_number ASC
			LIMIT 1
		");
		$stmt->execute();
		$result = $stmt->get_result();

		if ($result->num_rows > 0) {
			// Step 2: Reuse an existing revoked seller number
			$seller_number = $result->fetch_assoc()['seller_number'];

			// Update the existing revoked seller entry
			$stmt = $conn->prepare("
				UPDATE sellers 
				SET user_id = ?, bazaar_id = ?, seller_verified = 1, checkout_id = ?, reserved = 1
				WHERE seller_number = ? AND user_id = 0 AND bazaar_id = 0 AND seller_verified = 0
			");
			$stmt->bind_param("iiii", $user_id, $bazaar_id, $checkout_id, $seller_number);
			$stmt->execute();
		} else {
			// Step 3: No revoked seller number found, generate the next sequential number
			$stmt = $conn->prepare("SELECT MAX(seller_number) AS max_seller_number FROM sellers");
			$stmt->execute();
			$result = $stmt->get_result()->fetch_assoc();
			$seller_number = max(100, $result['max_seller_number'] + 1);

			// Step 4: Determine next checkout ID
			$stmt = $conn->prepare("SELECT MAX(checkout_id) AS max_checkout_id FROM sellers");
			$stmt->execute();
			$result = $stmt->get_result()->fetch_assoc();
			$checkout_id = $result['max_checkout_id'] + 1;

			// Step 5: Insert a new seller record
			$stmt = $conn->prepare("
				INSERT INTO sellers (user_id, seller_number, checkout_id, bazaar_id, seller_verified, reserved) 
				VALUES (?, ?, ?, ?, 1, 1)
			");
			$stmt->bind_param("iiii", $user_id, $seller_number, $checkout_id, $bazaar_id);
			$stmt->execute();
		}

		echo "Verkäufer erfolgreich erstellt. Verkäufernummer: $seller_number.";
	} catch (Exception $e) {
		$conn->rollback();
		die("Fehler beim Zuweisen der Verkäufernummer: " . $e->getMessage());
	}

}

// Handle seller update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_seller'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
        die("CSRF token validation failed.");
    }

    $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT);
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $family_name = htmlspecialchars($_POST['family_name'], ENT_QUOTES, 'UTF-8');
    $given_name = htmlspecialchars($_POST['given_name'], ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($_POST['phone'], ENT_QUOTES, 'UTF-8');
    $street = htmlspecialchars($_POST['street'], ENT_QUOTES, 'UTF-8');
    $house_number = htmlspecialchars($_POST['house_number'], ENT_QUOTES, 'UTF-8');
    $zip = htmlspecialchars($_POST['zip'], ENT_QUOTES, 'UTF-8');
    $city = htmlspecialchars($_POST['city'], ENT_QUOTES, 'UTF-8');
    // seller_verified controls whether the seller number is currently active.
    $seller_verified = filter_input(INPUT_POST, 'seller_verified', FILTER_VALIDATE_INT);
    $seller_verified = ($seller_verified === 1) ? 1 : 0;

    if (empty($family_name) || empty($email) || empty($user_id)) {
        $message_type = 'danger';
        $message = "Erforderliche Felder fehlen.";
    } else {
        // Check if the user exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userExists = $stmt->get_result()->num_rows > 0;

        if (!$userExists) {
            $message_type = 'danger';
            $message = "Benutzer nicht gefunden.";
        } else {
            // Update user_details table
            $stmt = $conn->prepare("
                UPDATE user_details 
                SET family_name = ?, given_name = ?, email = ?, phone = ?, 
                    street = ?, house_number = ?, zip = ?, city = ?
                WHERE user_id = ?
            ");
            $stmt->bind_param("ssssssssi", $family_name, $given_name, $email, $phone, 
                              $street, $house_number, $zip, $city, $user_id);

            if ($stmt->execute()) {
                // Keep admin-side activation consistent with seller self-service activation:
                // when a seller is activated, bind it to the currently active/registering bazaar.
                if ($seller_verified === 1 && $bazaar_id > 0) {
                    $seller_stmt = $conn->prepare("UPDATE sellers SET seller_verified = 1, bazaar_id = ? WHERE seller_number = ?");
                    $seller_stmt->bind_param("ii", $bazaar_id, $seller_number);
                } elseif ($seller_verified === 0) {
                    // Deactivation clears bazaar assignment to avoid stale future-bazaar linkage.
                    $seller_stmt = $conn->prepare("UPDATE sellers SET seller_verified = 0, bazaar_id = 0 WHERE seller_number = ?");
                    $seller_stmt->bind_param("i", $seller_number);
                } else {
                    $message_type = 'danger';
                    $message = "Kein aktiver oder Anmelde-Basar für die Freischaltung gefunden.";
                    log_action($conn, $user_id, "Error updating seller status", "Missing target bazaar for activation. SellerNumber=$seller_number");
                    $seller_stmt = null;
                }

                if ($seller_stmt && $seller_stmt->execute()) {
                    $message_type = 'success';
                    $message = "Verkäufer erfolgreich aktualisiert.";
                    log_action($conn, $user_id, "Seller updated", "UserID=$user_id, SellerNumber=$seller_number, Name=$family_name, Email=$email, SellerVerified=$seller_verified, BazaarID=" . ($seller_verified === 1 ? $bazaar_id : 0));
                } else {
                    $message_type = 'danger';
                    $message = "Fehler beim Aktualisieren des Verkäuferstatus: " . $conn->error;
                    log_action($conn, $user_id, "Error updating seller status", $conn->error);
                }
            } else {
                $message_type = 'danger';
                $message = "Fehler beim Aktualisieren der Verkäuferdaten: " . $conn->error;
                log_action($conn, $user_id, "Error updating seller", $conn->error);
            }
        }
    }
}

// Handle seller deletion
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'delete_seller') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        log_action($conn, $user_id, "CSRF token validation failed for deleting seller");
        die("CSRF token validation failed.");
    }

    $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT);
    $delete_products = filter_input(INPUT_POST, 'delete_products') !== null ? $_POST['delete_products'] : false;

    if (seller_has_products($conn, $seller_number)) {
        if ($delete_products) {
            // Attempt to delete products
            $stmt = $conn->prepare("DELETE FROM products WHERE seller_number=?");
            $stmt->bind_param("i", $seller_number);
            $deletion_successful = $stmt->execute();

            if ($deletion_successful) {
                log_action($conn, $user_id, "Products deleted for seller", "Seller ID=$seller_number");
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
        $stmt = $conn->prepare("DELETE FROM sellers WHERE seller_number=?");
        $stmt->bind_param("i", $seller_number);
        if ($stmt->execute()) {
            $message_type = 'success';
            $message = "Verkäufer erfolgreich gelöscht.";
            log_action($conn, $user_id, "Seller deleted", "ID=$seller_number");
        } else {
            $message_type = 'danger';
            $message = "Fehler beim Löschen des Verkäufers: " . $conn->error;
            log_action($conn, $user_id, "Error deleting seller", $conn->error);
        }
    }
}

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && filter_input(INPUT_POST, 'confirm_import') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    }
	
    $file = $_FILES['csv_file']['tmp_name'];
    $delimiter = filter_input(INPUT_POST, 'delimiter');
    $encoding = filter_input(INPUT_POST, 'encoding');
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

            $seller_number = $data[0];
            $family_name = htmlspecialchars(sanitize_name($data[1]), ENT_QUOTES, 'UTF-8');
            $given_name = htmlspecialchars(sanitize_name($data[2] ?: "Nicht angegeben"), ENT_QUOTES, 'UTF-8');
            $city = htmlspecialchars($data[3] ?: "Nicht angegeben", ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars($data[4] ?: "Nicht angegeben", ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(sanitize_email($data[5]), ENT_QUOTES, 'UTF-8');

            $reserved = 0;
            $street = "Nicht angegeben";
            $house_number = "Nicht angegeben";
            $zip = "Nicht angegeben";
            $verification_token = NULL;
            $verified = 0;

			$stmt = $conn->prepare("
				INSERT INTO sellers (
					seller_number, email, reserved, verification_token,
					family_name, given_name, phone, street, house_number, zip, city,
					verified, consent
				)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE
					email = VALUES(email),
					reserved = VALUES(reserved),
					verification_token = VALUES(verification_token),
					family_name = VALUES(family_name),
					given_name = VALUES(given_name),
					phone = VALUES(phone),
					street = VALUES(street),
					house_number = VALUES(house_number),
					zip = VALUES(zip),
					city = VALUES(city),
					verified = VALUES(verified),
					consent = VALUES(consent)
			");
			$stmt->bind_param(
				"isissssssssii",
				$seller_number,   // i
				$email,           // s
				$reserved,        // i
				$verification_token, // s (NULL allowed)
				$family_name,     // s
				$given_name,      // s
				$phone,           // s
				$street,          // s
				$house_number,    // s
				$zip,             // s
				$city,            // s
				$verified,        // i
				$consent          // i
			);

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
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'check_seller_products') !== null) {
    $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT);
    $has_products = seller_has_products($conn, $seller_number);
    echo json_encode(['has_products' => $has_products]);
    exit;
}

// Handle product update
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'update_product') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    } else {
        $bazaar_id = get_current_bazaar_id($conn);
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $name = htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars($_POST['size'], ENT_QUOTES, 'UTF-8');
        $price = floatval($_POST['price']);
		$sold = filter_input(INPUT_POST, 'sold') !== null ? 1 : 0; // Convert checkbox to boolean
		
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
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'delete_product') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        log_action($conn, $user_id, "CSRF token validation failed for editing seller");
		
        die("CSRF token validation failed.");
    } else {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT);

        $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND seller_number=?");
        $stmt->bind_param("ii", $product_id, $seller_number);
        if ($stmt->execute()) {
			$message_type = 'success';
            $message = "Produkt erfolgreich gelöscht.";
            log_action($conn, $user_id, "Product deleted", "ID=$product_id, Seller ID=$seller_number");
        } else {
			$message_type = 'danger';
            $message = "Fehler beim Löschen des Produkts: " . $conn->error;
            log_action($conn, $user_id, "Error deleting product", $conn->error);
        }
    }
}

// Default filter is "undone"
$filter = isset($_COOKIE['filter']) ? $_COOKIE['filter'] : 'undone_paid';
$sort_by = isset($_COOKIE['sort_by']) ? $_COOKIE['sort_by'] : 'seller_number';
$order = isset($_COOKIE['order']) ? $_COOKIE['order'] : 'ASC';

// Validate sorting column to prevent SQL injection
$valid_columns = ['seller_number', 'checkout_id', 'family_name', 'checkout'];
if (!in_array($sort_by, $valid_columns)) {
    $sort_by = 'seller_number';
}

// Filter sellers based on selection
$filter_condition = "";
if ($filter == 'done') {
    $filter_condition = "WHERE s.checkout = 1";
} elseif ($filter == 'undone') {
    $filter_condition = "WHERE s.checkout = 0";
} elseif ($filter == 'paid') {
    $filter_condition = "WHERE s.fee_payed = 1"; // Show only sellers who have paid
} elseif ($filter == 'undone_paid') {
    $filter_condition = "WHERE s.checkout = 0 AND s.fee_payed = 1"; // Show not completed, but paid sellers
} elseif ($filter == 'current_bazaar') {
    if (!empty($bazaar_filter_ids)) {
        $id_list = implode(',', array_map('intval', $bazaar_filter_ids));
        $filter_condition = "WHERE s.bazaar_id IN ($id_list)";
    } else {
        $filter_condition = "WHERE 1 = 0"; // No bazaar context available, show nothing
    }
}

// Modify the SQL query to apply filtering and sorting
$sql = "
    SELECT 
        u.id AS user_id,
        u.username,
        u.role,
        ud.family_name,
        ud.given_name,
        ud.email,
        ud.phone,
        ud.street,
        ud.house_number,
        ud.zip,
        ud.city,
        s.seller_number,
        s.seller_verified,
        s.fee_payed,
        s.checkout,
        s.checkout_id
    FROM 
        users u
    LEFT JOIN 
        user_details ud ON u.id = ud.user_id
    LEFT JOIN 
        sellers s ON u.id = s.user_id
    $filter_condition
    ORDER BY 
        $sort_by $order;
";

// Execute the query
$sellers_result = $conn->query($sql);

// Log the action
log_action($conn, $user_id, "Fetched user details", "Count=" . ($sellers_result ? $sellers_result->num_rows : 0));

// Close the database connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <style nonce="<?php echo $nonce; ?>">
        html { visibility: hidden; }
    </style>
    <meta charset="UTF-8">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
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
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->

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
		<button class="btn btn-primary mb-3 btn-block" type="button" data-toggle="collapse" data-target="#addSellerFormContainer" aria-expanded="false" aria-controls="addSellerFormContainer">
			Formular: Neuer Verkäufer
		</button>

		<div id="addSellerFormContainer" class="collapse">
			<div class="card card-body mb-3">
				<form action="admin_manage_sellers.php" method="post">
					<!-- CSRF Token -->
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

					<!-- Checkbox to toggle between new user and existing user -->
					<div class="form-group">
						<label>
							<input type="checkbox" id="createNewUser" name="createNewUser" value="1" checked>
							Neuen Benutzer anlegen
						</label>
					</div>

					<!-- Fields for creating a new user -->
					<div id="newUserFields">
						<div class="form-row">
							<div class="form-group col-md-6">
								<label for="family_name">Nachname:<span class="required"></span></label>
								<input type="text" class="form-control" id="family_name" name="family_name" required>
							</div>
							<div class="form-group col-md-6">
								<label for="given_name">Vorname:</label>
								<input type="text" class="form-control" id="given_name" name="given_name">
							</div>
						</div>
						<div class="form-row">
							<div class="form-group col-md-6">
								<label for="email">E-Mail:<span class="required"></span></label>
								<input type="email" class="form-control" id="email" name="email" required>
							</div>
							<div class="form-group col-md-6">
								<label for="phone">Telefon:</label>
								<input type="text" class="form-control" id="phone" name="phone">
							</div>
						</div>
						<div class="form-row">
							<div class="form-group col-md-6">
								<label for="street">Straße:</label>
								<input type="text" class="form-control" id="street" name="street">
							</div>
							<div class="form-group col-md-2">
								<label for="house_number">Nr.:</label>
								<input type="text" class="form-control" id="house_number" name="house_number">
							</div>
							<div class="form-group col-md-4">
								<label for="zip">PLZ:</label>
								<input type="text" class="form-control" id="zip" name="zip">
							</div>
						</div>
						<div class="form-row">
							<div class="form-group col-md-6">
								<label for="city">Stadt:</label>
								<input type="text" class="form-control" id="city" name="city">
							</div>
							<div class="form-group col-md-6">
								<label for="password">Passwort:<span class="required"></span></label>
								<input type="password" class="form-control" id="password" name="password" required>
							</div>
						</div>
					</div>

					<!-- Search for existing user -->
					<div id="existingUserSearch" class="hidden table-responsive-sm">
						<label for="userSearchInput">Benutzer suchen:</label>
						<input type="text" id="userSearchInput" class="form-control" placeholder="Suchen nach Name, Vorname oder E-Mail (mind. 3 Zeichen)">

						<div id="userSearchPlaceholder" class="alert alert-info mt-2">Bitte mindestens 3 Zeichen eingeben.</div>

						<table class="table table-bordered w-100 mt-3 hidden" id="userSearchResultsTable">
							<thead>
								<tr>
									<th>Benutzer-ID</th>
									<th>Nachname</th>
									<th>Vorname</th>
									<th>E-Mail</th>
									<th>Telefon</th>
									<th>Aktion</th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>

					<!-- Hidden field to hold selected user ID -->
					<input type="hidden" name="selected_user_id" id="selectedUserId">

					<button type="submit" class="btn btn-primary btn-block mt-3" name="add_seller">Verkäufer hinzufügen</button>
				</form>
			</div>
		</div>

		<button type="button" class="btn btn-info btn-block" data-toggle="modal" data-target="#importSellersModal">Verkäufer importieren</button>
		<button type="button" class="btn btn-secondary btn-block" id="printVerifiedSellers">Verkäuferliste drucken</button>
		
        <h3 class="mt-5">Verkäuferliste</h3>

        <div class="form-row">
            <div class="form-group col-md-12">
                <label for="filter">Filter:</label>
                <select class="form-control" id="filter" name="filter">
                    <option value="paid" <?php echo $filter == 'paid' ? 'selected' : ''; ?>>Alle (Gebühr bezahlt)</option>
                    <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>Alle</option>
                    <option value="done" <?php echo $filter == 'done' ? 'selected' : ''; ?>>Abgeschlossen</option>
                    <option value="undone" <?php echo $filter == 'undone' ? 'selected' : ''; ?>>Nicht abgeschlossen</option>
					<option value="current_bazaar" <?php echo $filter == 'current_bazaar' ? 'selected' : ''; ?>>Aktive Verkäufernummer aktueller Basar</option>
                    <option value="undone_paid" <?php echo $filter == 'undone_paid' ? 'selected' : ''; ?>>Nicht abgeschlossen (Gebühr bezahlt)</option>
                </select>
            </div>
        </div>

		<!-- NEW: Table search -->
		<div class="form-row">
		<div class="col-md-12">
		  <label for="sellerTableSearch" class="sr-only">In der Tabelle suchen</label>
		  <div class="input-group">
			<input
			  type="text"
			  class="form-control"
			  id="sellerTableSearch"
			  placeholder="In der Liste suchen (Nr., Nachname, Vorname, E-Mail, Verifiziert)…"
			  autocomplete="off"
			/>
			<div class="input-group-append">
			  <button class="btn btn-outline-secondary" type="button" id="clearSellerTableSearch">Löschen</button>
			</div>
		  </div>
		  <small class="form-text text-muted">
			Die Suche wirkt nur auf die aktuell gefilterte Liste.
		  </small>
		  </div>
		</div>
		
        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="sort_by">Sortieren nach:</label>
                <select class="form-control" id="sort_by" name="sort_by">
                    <option value="seller_number" <?php echo $sort_by == 'seller_number' ? 'selected' : ''; ?>>VerkäuferNr.</option>
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
                        <th>Nachname</th>
                        <th>Vorname</th>
                        <th>E-Mail</th>
                        <th>Verkäufernummer aktiv</th>
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
                                            $checkout_class = $row['checkout'] ? 'done' : '';
                                            echo "<tr class='$checkout_class'>
                                                            <td>" . htmlspecialchars($row['seller_number'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['family_name'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['given_name'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . htmlspecialchars($row['email'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td>" . ((int)($row['seller_verified'] ?? 0) === 1 ? 'Ja' : 'Nein') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['phone'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['street'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['house_number'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['zip'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='hidden'>" . htmlspecialchars($row['city'] ?? '.', ENT_QUOTES, 'UTF-8') . "</td>
                                                            <td class='action-cell'>
                                                                    <select class='form-control action-dropdown' data-seller-id='" . htmlspecialchars($row['seller_number'] ?? 0, ENT_QUOTES, 'UTF-8') . "'>
                                                                            <option value=''>Aktion wählen</option>
                                                                            <option value='checkout'>Abrechnen</option>
                                                                            <option value='edit'>Bearbeiten</option>
                                                                            <option value='delete'>Löschen</option>
                                                                            <option value='show_products'>Produkte anzeigen</option>
                                                                            <option value='create_products'>Produkte erstellen</option>
                                                                    </select>
                                                                    <button class='btn btn-primary btn-sm execute-action' data-seller-id='" . htmlspecialchars($row['seller_number'] ?? 0, ENT_QUOTES, 'UTF-8') . "' data-user-id='" . htmlspecialchars($row['user_id'] ?? 0, ENT_QUOTES, 'UTF-8') . "'>Ausführen</button>
                                                            </td>
                                                      </tr>";
                                            echo "<tr class='hidden' id='seller-products-{$row['seller_number']}'>
                                                    <td colspan='11'>
                                                        <div class='table-responsive'>
                                                            <table class='table table-bordered'>
                                                                <thead>
                                                                    <tr>
                                                                        <th>Produktname</th>
                                                                        <th>Größe</th>
                                                                        <th>Preis</th>
                                                                        <th>Verkauft</th>
																		<th>im Lag./ zu Verk.</th>
                                                                        <th>Aktionen</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody id='products-{$row['seller_number']}'>
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
					<input type="hidden" name="seller_number" id="editSellerId">
					<input type="hidden" name="user_id" id="editUserId"> <!-- New hidden field -->
					
                    <div class="modal-header">
                        <h5 class="modal-title" id="editSellerModalLabel">Verkäufer bearbeiten</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="editSellerIdDisplay">Verk.Nr.:</label>
                            <input type="text" class="form-control" id="editSellerIdDisplay" name="seller_number_display" disabled>
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
                            <label for="editSellerVerified">Verkäufernummer aktiv:</label>
                            <select class="form-control" id="editSellerVerified" name="seller_verified">
                                <option value="1">Ja</option>
                                <option value="0">Nein</option>
                            </select>
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
  $(document).ready(function () {
    // --- Seller table client-side search (applies on the already server-filtered rows) ---
    const $search = $('#sellerTableSearch');
    const $clear  = $('#clearSellerTableSearch');
    const $tbody  = $('table.table tbody');
    const $noRes  = $('#no-seller-search-results');

    // Helper: debounce
    function debounce(fn, ms) {
      let t; return function() { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), ms); };
    }

    function normalize(s) { return (s || '').toString().toLowerCase(); }

    // Filter only the primary seller rows (exclude the hidden product-detail rows)
    function filterSellerTable(query) {
      const q = normalize(query);
      let anyVisible = false;

      // primary rows are those NOT having id that starts with 'seller-products-'
      const $rows = $tbody.find('tr').filter(function () {
        const id = this.id || '';
        return !/^seller-products-/.test(id);
      });

      $rows.each(function () {
        const $tr = $(this);
        // cells we consider searchable: seller no, family, given, email, verified
        const tds = $tr.children('td');
        const sellerNo  = normalize($(tds[0]).text());
        const family    = normalize($(tds[1]).text());
        const given     = normalize($(tds[2]).text());
        const email     = normalize($(tds[3]).text());
        const verified  = normalize($(tds[4]).text()); // "ja" / "nein"

        const haystack = [sellerNo, family, given, email, verified].join(' ');
        const match = q === '' || haystack.indexOf(q) !== -1;

        // Also ensure we never toggle the special "no results" row
        if ($tr.attr('id') !== 'no-seller-search-results') {
          $tr.toggle(match);
          if (match) anyVisible = true;
        }
      });

      // Hide any expanded product-rows for sellers that were hidden
      $tbody.find('tr[id^="seller-products-"]').each(function () {
        const $detail = $(this);
        const sellerNumber = ($detail.attr('id') || '').replace('seller-products-', '');
        const $main = $rows.filter(function () {
          const firstCell = $(this).children('td').eq(0).text().trim();
          return firstCell === sellerNumber;
        });
        // Hide detail row if its main row is hidden
        if ($main.length && !$main.is(':visible')) {
          $detail.addClass('hidden');
        }
      });

      // Toggle "no results"
      if ($noRes.length) $noRes.toggle(!anyVisible);
    }

    const debouncedFilter = debounce(function () {
      filterSellerTable($search.val());
    }, 200);

    $search.on('input', debouncedFilter);

    $clear.on('click', function () {
      $search.val('');
      filterSellerTable('');
      $search.trigger('focus');
    });

    // If the page loads with a saved value (e.g., browser autocomplete), apply it
    filterSellerTable($search.val() || '');
  });
</script>

    <script nonce="<?php echo $nonce; ?>">
		//function to edit the seller will open a modal
        function editSeller(id, userId, family_name, given_name, email, phone, street, house_number, zip, city, verified) {
			$('#editSellerId').val(id);
			$('#editUserId').val(userId);
			$('#editSellerIdDisplay').val(id);
			$('#editSellerFamilyName').val(family_name);
			$('#editSellerGivenName').val(given_name);
			$('#editSellerEmail').val(email);
			$('#editSellerPhone').val(phone);
			$('#editSellerStreet').val(street);
			$('#editSellerHouseNumber').val(house_number);
			$('#editSellerZip').val(zip);
			$('#editSellerCity').val(city);
			$('#editSellerVerified').val(verified ? '1' : '0');
			$('#editSellerModal').modal('show');
		}

		//function that toggles the list of products
        function toggleProducts(sellerNumber) {
            const row = $(`#seller-products-${sellerNumber}`);
            if (row.hasClass('hidden')) {
                loadProducts(sellerNumber);
                row.removeClass('hidden');
            } else {
                row.addClass('hidden');
            }
        }

		//load the list of all products
		function loadProducts(sellerNumber) {
			$.ajax({
				url: 'fetch_products.php',
				method: 'GET',
				data: { seller_number: sellerNumber },
				dataType: 'json', // Ensure we handle JSON properly
				success: function(response) {
					const productsContainer = $(`#products-${sellerNumber}`);

					if (!response.success) {
						productsContainer.html('<tr><td colspan="5">Fehler beim Laden der Produkte.</td></tr>');
						return;
					}

					// Clear previous products
					productsContainer.empty();

					if (response.data.length === 0) {
						productsContainer.html('<tr><td colspan="5">Keine Produkte gefunden.</td></tr>');
						return;
					}

					// Loop through products and create rows dynamically
					response.data.forEach(product => {
						let soldText = product.sold ? "Ja" : "Nein";
						let stockText = product.stock_status; // 'Lager' or 'Verkauf'
						let productRow = `
							<tr>
								<td>${product.name}</td>
								<td>${product.size || '-'}</td>
								<td>${product.price.toFixed(2)} €</td>
								<td>${soldText}</td>
								<td>${stockText}</td>
								<td>
									<button class="btn btn-sm btn-primary edit-product-btn" 
											data-id="${product.id}" 
											data-name="${product.name}" 
											data-size="${product.size}" 
											data-price="${product.price}" 
											data-sold="${product.sold}">
										Bearbeiten
									</button>
								</td>
							</tr>`;

						productsContainer.append(productRow);
					});

					// Attach event listeners to edit buttons dynamically
					productsContainer.find('.edit-product-btn').on('click', function() {
						const productId = $(this).data('id');
						const productName = $(this).data('name');
						const productSize = $(this).data('size');
						const productPrice = $(this).data('price');
						const productSold = $(this).data('sold');

						editProduct(productId, productName, productSize, productPrice, productSold);
					});
				},
				error: function() {
					const productsContainer = $(`#products-${sellerNumber}`);
					productsContainer.html('<tr><td colspan="5">Fehler beim Laden der Produkte.</td></tr>');
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
			const csrfToken = $('#csrf_token').val(); // Get the CSRF token

			if (action === 'edit') {
				const row = $(this).closest('tr');
				const userId = $(this).data('user-id');
				const family_name = row.find('td:nth-child(2)').text(); // Nachname
				const given_name = row.find('td:nth-child(3)').text(); // Vorname
				const email = row.find('td:nth-child(4)').text(); // E-Mail
				const verified = row.find('td:nth-child(5)').text() === 'Ja'; // Verkäufernummer aktiv
				const phone = row.find('td:nth-child(6)').text(); // Telefon
				const street = row.find('td:nth-child(7)').text(); // Straße
				const house_number = row.find('td:nth-child(8)').text(); // Nr.
				const zip = row.find('td:nth-child(9)').text(); // PLZ
				const city = row.find('td:nth-child(10)').text(); // Stadt

				editSeller(sellerId, userId, family_name, given_name, email, phone, street, house_number, zip, city, verified);
			} else if (action === 'delete') {
				$.post('admin_manage_sellers.php', {
					check_seller_products: true, seller_number: sellerId, csrf_token: csrfToken }, 
					function(response) {
					if (response.has_products) {
						$('#confirmDeleteSellerId').val(sellerId);
						$('#confirmDeleteModal').modal('show');
					} else {
						if (confirm('Möchten Sie diesen Verkäufer wirklich löschen?')) {
							$.post('admin_manage_sellers.php', { 
							delete_seller: true, seller_number: sellerId, csrf_token: csrfToken }, function(response) {
								location.reload();
							});
						}
					}
				}, 'json');
                    //Show current product
					} else if (action === 'show_products') {
						toggleProducts(sellerId);
						//Create product
					} else if (action === 'create_products') {
						// Set session variables and redirect
						$.post('admin_manage_sellers.php', { 
						set_session: true, seller_number: sellerId, csrf_token: csrfToken }, function(response) {
							if (response.success) {
								window.location.href = 'seller_products.php?seller_number=' + sellerId;
							} else {
								alert('Fehler beim Setzen der Sitzungsvariablen: ' + response.error);
							}
						}, 'json');
					} else if (action === 'checkout') {
						const csrfToken = $('#csrf_token').val();

						$.post('admin_manage_sellers.php', { set_session: true, seller_number: sellerId, csrf_token: csrfToken }, function(response) {
								if (response.success) {
										window.location.href = 'checkout.php';
								} else {
										alert('Fehler beim Auschecken des Verkäufers: ' + response.error);
								}
						}, 'json');
					}
				});

        $('#confirmDeleteButton').on('click', function() {
			const sellerId = $('#confirmDeleteSellerId').val();
			const csrfToken = $('#csrf_token').val(); // Get the CSRF token
			$.post('admin_manage_sellers.php', { delete_seller: true, seller_number: sellerId, delete_products: true, csrf_token: csrfToken }, function(response) {
				location.reload();
			});
		});

        $('#filter, #sort_by, #order').on('change', function() {
			const filter = $('#filter').val();
			const sort_by = $('#sort_by').val();
			const order = $('#order').val();

			document.cookie = `filter=${filter}; path=/`;
			document.cookie = `sort_by=${sort_by}; path=/`;
			document.cookie = `order=${order}; path=/`;

			const url = new URL(window.location.href);
			url.searchParams.set('filter', filter);
			url.searchParams.set('sort_by', sort_by);
			url.searchParams.set('order', order);
			window.location.href = url.toString();
		});

		$('#printVerifiedSellers').on('click', function () {
		  // Read the current UI selections (these are also what you write to cookies)
		  const filter   = $('#filter').val();
		  const sort_by  = $('#sort_by').val();
		  const order    = $('#order').val();

		  $.ajax({
			url: 'print_verified_sellers.php',
			method: 'GET',
			data: { filter, sort_by, order },      // << pass them along
			dataType: 'html',
			success: function (response) {
			  const printWindow = window.open('', '', 'height=600,width=800');
			  printWindow.document.write('<html><head><title>Verifizierte Verkäufer</title>');
			  printWindow.document.write('<link href="css/bootstrap.min.css" rel="stylesheet">');
			  printWindow.document.write('</head><body>');
			  printWindow.document.write(response);
			  printWindow.document.write('</body></html>');
			  printWindow.document.close();
			  printWindow.focus();
			  printWindow.print();
			},
			error: function () {
			  alert('Fehler beim Laden der verifizierten Verkäufer.');
			}
		  });
		});

    </script>
    <script nonce="<?php echo $nonce; ?>">
	//function to show a preview for import
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
			const createNewUserCheckbox = document.getElementById('createNewUser');
			const newUserFields = document.getElementById('newUserFields');
			const existingUserSearch = document.getElementById('existingUserSearch');
			const searchInput = document.getElementById('userSearchInput');
			const placeholder = document.getElementById('userSearchPlaceholder');
			const resultsTable = document.getElementById('userSearchResultsTable');
			const resultsBody = resultsTable.querySelector('tbody');
			const selectedUserIdInput = document.getElementById('selectedUserId');
			const requiredFields = [
				'#family_name',
				'#email',
				'#password',
			];
			
			// Function to toggle required attribute
			function toggleRequiredFields(isRequired) {
				requiredFields.forEach(selector => {
					$(selector).prop('required', isRequired);
				});
			}
	
			// Toggle visibility of new user fields and search
			createNewUserCheckbox.addEventListener('change', function () {
				const isChecked = createNewUserCheckbox.checked;
				newUserFields.classList.toggle('hidden', !isChecked);
				existingUserSearch.classList.toggle('hidden', isChecked);
				
				// Toggle required attributes
				toggleRequiredFields(isChecked);
			});
	
			// Handle live search
			searchInput.addEventListener('input', function () {
				const query = searchInput.value.trim();

				if (query.length >= 3) {
					// Perform AJAX request to fetch search results
					fetch(`admin_manage_sellers.php?search_term=${encodeURIComponent(query)}`)
						.then(response => response.json())
						.then(data => {
							resultsBody.innerHTML = ''; // Clear existing rows

							if (data.length === 0) {
								placeholder.textContent = 'Keine Ergebnisse gefunden.';
								placeholder.classList.remove('hidden');
								resultsTable.classList.add('hidden');
							} else {
								placeholder.classList.add('hidden');
								resultsTable.classList.remove('hidden');

								data.forEach(user => {
									const row = document.createElement('tr');
									row.classList.add('search-result-row');
									row.dataset.userId = user.user_id;

									row.innerHTML = `
										<td>${user.user_id}</td>
										<td>${user.family_name}</td>
										<td>${user.given_name}</td>
										<td>${user.email}</td>
										<td>${user.phone}</td>
										<td>
											<button type="button" class="btn btn-primary select-user-button">Auswählen</button>
										</td>
									`;

									resultsBody.appendChild(row);

									// Click event for the row
									row.addEventListener('click', function () {
										document.querySelectorAll('.search-result-row').forEach(r => r.classList.remove('table-active'));
										row.classList.add('table-active');
										selectedUserIdInput.value = user.user_id;
									});

									// Click event for the "Select" button
									const selectButton = row.querySelector('.select-user-button');
									selectButton.addEventListener('click', function (e) {
										e.stopPropagation(); // Prevent row click event
										row.click();
									});
								});
							}
						})
						.catch(() => {
							placeholder.textContent = 'Fehler beim Abrufen der Daten.';
							placeholder.classList.remove('hidden');
							resultsTable.classList.add('hidden');
						});
				} else {
					placeholder.textContent = 'Bitte mindestens 3 Zeichen eingeben.';
					placeholder.classList.remove('hidden');
					resultsTable.classList.add('hidden');
				}
			});
	
	
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
	<script nonce="<?php echo $nonce; ?>">
		// Show the HTML element once the DOM is fully loaded
		document.addEventListener("DOMContentLoaded", function () {
			document.documentElement.style.visibility = "visible";
		});
	</script>
</body>
</html>
