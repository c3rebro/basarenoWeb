<?php

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

$alertMessage = "";
$alertMessage_Type = "";

$conn = get_db_connection();

// Check if the token parameter is set
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Query to check if the token exists in the database
    $sql = "SELECT id FROM users WHERE verification_token=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Update the seller's status to verified
        $update_sql = "UPDATE users SET user_verified=1, verification_token=NULL WHERE verification_token=?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("s", $token);

        if ($update_stmt->execute()) {
            $alertMessage = "Ihr Konto wurde erfolgreich verifiziert. Sie werden in wenigen Sekunden weitergeleitet.";
            $alertMessage_Type = 'success';
            header("Refresh: 3; url=login.php");
        } else {
            $alertMessage = "Fehler beim Aktivieren des Kontos.";
            $alertMessage_Type = 'danger';
        }
        $update_stmt->close();
    } else {
        $alertMessage = "Ungültiger oder abgelaufener Token.";
        $alertMessage_Type = 'danger';
    }

    $stmt->close();
} else {
    $alertMessage = "Ungültiger oder abgelaufener Token.";
    $alertMessage_Type = 'danger';
}

$conn->close();

// Display the message
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konto Verifizierung</title>
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
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-8 col-lg-6">
                <?php if ($alertMessage): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($alertMessage_Type); ?> text-center">
                        <?php echo htmlspecialchars($alertMessage); ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Schliessen">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Include JavaScript files -->
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>

