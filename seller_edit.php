<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

require_once 'utilities.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors
		
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'seller')) {
    header("location: login.php");
    exit;
}

$seller_id = $_SESSION['seller_id'];
$hash = $_SESSION['seller_hash'];

if (!isset($seller_id) || !isset($hash)) {
        echo "Login fehlgeschlagen.";
        exit();
}

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

// Validate CSRF token
if ($_SERVER["REQUEST_METHOD"] == "POST" && !validate_csrf_token($_POST['csrf_token'])) {
    die("CSRF token validation failed.");
}

// Handle account deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_delete'])) {
    // Begin a transaction to ensure atomicity
    $conn->begin_transaction();

    try {
        // Delete products associated with the seller
        $sql = "DELETE FROM products WHERE seller_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute() !== TRUE) {
            throw new Exception("Fehler beim Löschen der Produkte: " . $conn->error);
        }

        // Delete the seller
        $sql = "DELETE FROM sellers WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute() !== TRUE) {
            throw new Exception("Fehler beim Löschen des Verkäufers: " . $conn->error);
        }

        // Commit the transaction
        $conn->commit();

        // Show success message and redirect
        $message_type = 'success';
        $message = "Ihre persönlichen Daten und alle zugehörigen Produkte wurden erfolgreich gelöscht.";
        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <title>Verkäufer löschen</title>
            <link href="css/bootstrap.min.css" rel="stylesheet">
            <style>
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
        <script>
            setTimeout(function() {
                window.location.href = 'index.php';
            }, 3000); // Redirect after 3 seconds
        </script>
        </body>
        </html>
        <?php
        exit();
    } catch (Exception $e) {
        // Rollback the transaction in case of an error
        $conn->rollback();
        $message = $e->getMessage();
        $message_type = 'danger';
    }

    $stmt->close();
}

// Fetch current seller data
$stmt = $conn->prepare("SELECT * FROM sellers WHERE id=? AND hash=? AND verified=1");
$stmt->bind_param("is", $seller_id, $hash);
$stmt->execute();
$seller_data = $stmt->get_result()->fetch_assoc();

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
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
        <a class="navbar-brand" href="dashboard.php">Bazaar</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
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
        <h2>Verkäuferdaten bearbeiten</h2>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        <form action="seller_edit.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="family_name">Nachname:</label>
                    <input type="text" class="form-control" id="family_name" name="family_name" value="<?php echo htmlspecialchars($seller_data['family_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="given_name">Vorname:</label>
                    <input type="text" class="form-control" id="given_name" name="given_name" value="<?php echo htmlspecialchars($seller_data['given_name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="email">E-Mail:</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($seller_data['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label for="phone">Telefon:</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($seller_data['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-8">
                    <label for="street">Straße:</label>
                    <input type="text" class="form-control" id="street" name="street" value="<?php echo htmlspecialchars($seller_data['street'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label for="house_number">Nr.:</label>
                    <input type="text" class="form-control" id="house_number" name="house_number" value="<?php echo htmlspecialchars($seller_data['house_number'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="zip">PLZ:</label>
                    <input type="text" class="form-control" id="zip" name="zip" value="<?php echo htmlspecialchars($seller_data['zip'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group col-md-8">
                    <label for="city">Stadt:</label>
                    <input type="text" class="form-control" id="city" name="city" value="<?php echo htmlspecialchars($seller_data['city'], ENT_QUOTES, 'UTF-8'); ?>">
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
                    Sind Sie sicher, dass Sie Ihr Konto und alle zugehörigen Daten löschen möchten? Diese Aktion kann nicht rückgängig gemacht werden.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                    <form action="seller_edit.php?seller_id=<?php echo urlencode($seller_id); ?>&hash=<?php echo urlencode($hash); ?>" method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-danger" name="confirm_delete">Ja, löschen</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>