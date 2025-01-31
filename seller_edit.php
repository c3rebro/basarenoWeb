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

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors
		
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'seller')) {
    header("location: login.php");
    exit;
}

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$seller_id = $_SESSION['seller_id'] ?? $_GET['seller_id'] ?? '0';
$username = $_SESSION['username'] ?? '';

if (!isset($seller_id) || !isset($user_id)) {
        echo "Login fehlgeschlagen.";
        exit();
}

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

// Validate CSRF token
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && !validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
    die("CSRF token validation failed.");
}

// Handle POST request to edit user details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && filter_input(INPUT_POST, 'edit_seller') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $family_name = $conn->real_escape_string($_POST['family_name']);
    $given_name = $conn->real_escape_string($_POST['given_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $street = $conn->real_escape_string($_POST['street']);
    $house_number = $conn->real_escape_string($_POST['house_number']);
    $zip = $conn->real_escape_string($_POST['zip']);
    $city = $conn->real_escape_string($_POST['city']);

    $sql = "UPDATE user_details 
            SET family_name = ?, given_name = ?, email = ?, phone = ?, street = ?, house_number = ?, zip = ?, city = ?
            WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssssssi",
        $family_name,
        $given_name,
        $email,
        $phone,
        $street,
        $house_number,
        $zip,
        $city,
        $user_id
    );

    if ($stmt->execute()) {
        $message_type = 'success';
        $message = 'Deine Daten wurden erfolgreich aktualisiert.';
    } else {
        $message_type = 'danger';
        $message = 'Fehler beim Aktualisieren der Daten: ' . $conn->error;
    }
}

// Handle account deletion
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'confirm_delete') !== null) {
    // Begin a transaction to ensure atomicity
    $conn->begin_transaction();

    try {
        // Step 1: Fetch all seller_numbers associated with the user
        $sql = "SELECT seller_number FROM sellers WHERE user_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $seller_numbers = $result->fetch_all(MYSQLI_ASSOC);

        // Step 2: Delete products for each seller_number
        foreach ($seller_numbers as $row) {
            $seller_number = $row['seller_number'];
            $sql = "DELETE FROM products WHERE seller_number=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $seller_number);
            if (!$stmt->execute()) {
                throw new Exception("Error deleting products for seller_number: $seller_number");
            }
        }

        // Step 3: Delete seller records for the user
        $sql = "DELETE FROM sellers WHERE user_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Error deleting seller records for user_id: $user_id");
        }

        // Step 4: Delete user details
        $sql = "DELETE FROM user_details WHERE user_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Error deleting user details for user_id: $user_id");
        }

        // Step 5: Delete the user
        $sql = "DELETE FROM users WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {

            throw new Exception("Error deleting user for user_id: $user_id");
        }

        // Step 6: Commit the transaction
        $conn->commit();

        // Show success message and redirect
        $message_type = 'success';
        $message = "Your personal data and all associated products have been successfully deleted.";
        header("location: logout.php");
        exit;

    } catch (Exception $e) {
        // Rollback the transaction in case of an error
        $conn->rollback();
        $message_type = 'danger';
        $message = $e->getMessage();
    }

    // Cleanup
    if (isset($stmt)) {
        $stmt->close();
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
		<meta charset="UTF-8">
        <title>Kontolöschung</title>
        <link href="css/bootstrap.min.css" rel="stylesheet">
        <style nonce="<?php echo $nonce; ?>">
            .message-container {
                max-width: 600px;
                margin: 50px auto;
                text-align: center;
            }
        </style>
    </head>
    <body>
    <div class="message-container">
        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <script nonce="<?php echo $nonce; ?>">
        setTimeout(function () {
            window.location.href = 'index.php';
        }, 3000); // Redirect after 3 seconds
    </script>
    </body>
    </html>
    <?php
    exit();
}


$sql = "SELECT * 
        FROM user_details
        JOIN users ON user_details.user_id = users.id
        WHERE users.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_details = $stmt->get_result()->fetch_assoc();

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
    <title>Verkäufer bearbeiten</title>
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
        <h1 class="mt-5">Mein Benutzerkonto</h1>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        <form action="seller_edit.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="family_name">Nachname:</label>
                    <input type="text" class="form-control" id="family_name" name="family_name" value="<?php echo htmlspecialchars($user_details['family_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="given_name">Vorname:</label>
                    <input type="text" class="form-control" id="given_name" name="given_name" value="<?php echo htmlspecialchars($user_details['given_name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="email">E-Mail:</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_details['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="phone">Telefon:</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user_details['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-8">
                    <label for="street">Straße:</label>
                    <input type="text" class="form-control" id="street" name="street" value="<?php echo htmlspecialchars($user_details['street'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="house_number">Nr.:</label>
                    <input type="text" class="form-control" id="house_number" name="house_number" value="<?php echo htmlspecialchars($user_details['house_number'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="zip">PLZ:</label>
                    <input type="text" class="form-control" id="zip" name="zip" value="<?php echo htmlspecialchars($user_details['zip'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group col-md-8">
                    <label for="city">Stadt:</label>
                    <input type="text" class="form-control" id="city" name="city" value="<?php echo htmlspecialchars($user_details['city'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12 col-md-6 mt-3">
                    <button type="submit" class="btn btn-primary w-100" name="edit_seller">Änderungen speichern</button>
                </div>
                <div class="col-sm-12 col-md-6 mt-3">
                    <button type="button" class="btn btn-danger w-100" data-toggle="modal" data-target="#confirmDeleteModal">Konto löschen</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmDeleteModalLabel">Wirklich ALLES löschen?</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Bist Du sicher, dass Du Dein Konto und alle zugehörigen Daten löschen möchtest? Dies betrifft auch alle Verkäufernummern und deren Artikel. Diese Aktion kann nicht rückgängig gemacht werden. Die Verkäufernummern werden frei gegeben. Sicher?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                    <form class="inline" action="seller_edit.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-danger" name="confirm_delete">Ja, löschen</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
	
	<!-- Back to Top Button -->
	<div id="back-to-top"><i class="fas fa-arrow-up"></i></div>
	
    <script nonce="<?php echo $nonce; ?>">
        // Show the HTML element once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.documentElement.style.visibility = "visible";
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
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>