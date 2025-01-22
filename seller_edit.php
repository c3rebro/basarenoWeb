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
if ($_SERVER["REQUEST_METHOD"] == "POST" && !validate_csrf_token($_POST['csrf_token'])) {
    die("CSRF token validation failed.");
}

// Handle POST request to edit user details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_seller'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
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
        $message = 'Ihre Daten wurden erfolgreich aktualisiert.';
    } else {
        $message_type = 'danger';
        $message = 'Fehler beim Aktualisieren der Daten: ' . $conn->error;
    }
}

// Handle account deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_delete'])) {
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
        <title>Account Deletion</title>
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
                <li class="nav-item">
                    <a class="nav-link" href="seller_products.php">Meine Artikel</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="seller_edit.php">Meine Daten <span class="sr-only">(current)</span></a>
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
        <h1 class="mt-5">Verkäuferdaten bearbeiten</h1>
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
            <div class="form-row">
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary" name="edit_seller">Änderungen speichern</button>
                </div>
                <div class="col-md-6 text-right">
                    <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#confirmDeleteModal">Konto löschen</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmDeleteModalLabel">Bestätigen Sie die Löschung</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Sind Sie sicher, dass Sie Ihr Konto und alle zugehörigen Daten löschen möchten? Dies betrifft auch alle Verkäufernummern. Diese Aktion kann nicht rückgängig gemacht werden. Die Verkäufernummern werden frei gegeben. Trotzdem orfahren?
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
    <script nonce="<?php echo $nonce; ?>">
        // Show the HTML element once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.documentElement.style.visibility = "visible";
        });
    </script>
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>