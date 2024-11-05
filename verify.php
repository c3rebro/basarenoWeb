<?php
require_once 'utilities.php';

$message = "";
$showResetButton = false; // Flag to control the display of the reset button

// Check if the form was submitted for reverting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_revert'])) {
    $seller_id = $_POST['seller_id'];
    $hash = $_POST['hash'];

    $conn = get_db_connection();
    $sql = "SELECT email FROM sellers WHERE id='$seller_id' AND hash='$hash'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $seller = $result->fetch_assoc();
        $email = $seller['email'];

        // Verify the hash
        $expected_hash = generate_hash($email, $seller_id);
        if ($hash === $expected_hash) {
            // Revert the request
            $sql = "UPDATE sellers SET verified=0, verification_token=NULL, bazaar_id=NULL, checkout_id=NULL WHERE id='$seller_id'";
            if ($conn->query($sql) === TRUE) {
                $message = "<div class='alert alert-success' role='alert'>
                                Ihre Anfrage wurde erfolgreich zurückgesetzt und die Verkäufernummer frei gegeben. Wenn dies ein Versehen war, müssen Sie eine erneute Nummernanfrage stellen.
                            </div>";
            } else {
                $message = "<div class='alert alert-danger' role='alert'>
                                Fehler beim Zurücksetzen der Anfrage: " . $conn->error . "
                            </div>";
            }
        } else {
            $message = "<div class='alert alert-danger' role='alert'>
                            Ungültiger Hash.
                        </div>";
        }
    } else {
        $message = "<div class='alert alert-danger' role='alert'>
                        Ungültige Verkäufer-ID oder Hash.
                    </div>";
    }

    $conn->close();
} elseif (isset($_GET['action']) && $_GET['action'] === 'revert' && isset($_GET['seller_id']) && isset($_GET['hash'])) {
    // Validate seller_id and hash
    $seller_id = $_GET['seller_id'];
    $hash = $_GET['hash'];

    $conn = get_db_connection();
    $sql = "SELECT email, verified, verification_token FROM sellers WHERE id='$seller_id' AND hash='$hash'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $seller = $result->fetch_assoc();
        $email = $seller['email'];

        // Verify the hash
        $expected_hash = generate_hash($email, $seller_id);
        if ($hash === $expected_hash) {
            // Check if the seller is active
            if ($seller['verified'] == 1 || !empty($seller['verification_token'])) {
                $showResetButton = true; // Valid hash and seller_id, show the reset button
            } else {
                $message = "<div class='alert alert-warning' role='alert'>
                                Die Verkäufer-ID ist nicht aktiv. Der Rücksetzvorgang kann nicht durchgeführt werden.
                            </div>";
            }
        } else {
            $message = "<div class='alert alert-danger' role='alert'>
                            Ungültiger Hash.
                        </div>";
        }
    } else {
        $message = "<div class='alert alert-danger' role='alert'>
                        Ungültige Verkäufer-ID oder Hash.
                    </div>";
    }

    $conn->close();
} elseif (isset($_GET['token']) && isset($_GET['hash'])) {
    $token = $_GET['token'];
    $hash = $_GET['hash'];

    $conn = get_db_connection();
    $sql = "SELECT id, email FROM sellers WHERE verification_token='$token' AND hash='$hash'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $seller = $result->fetch_assoc();
        $seller_id = $seller['id'];
        $email = $seller['email'];

        // Verify the hash
        $expected_hash = generate_hash($email, $seller_id);
        if ($hash === $expected_hash) {
            // Proceed with verification
            $sql = "SELECT id FROM bazaar ORDER BY id DESC LIMIT 1";
            $bazaar_result = $conn->query($sql);

            if ($bazaar_result->num_rows > 0) {
                $current_bazaar = $bazaar_result->fetch_assoc();
                $current_bazaar_id = $current_bazaar['id'];
                $next_checkout_id = get_next_checkout_id($conn);

                $sql = "UPDATE sellers SET verified=1, verification_token=NULL, bazaar_id='$current_bazaar_id', checkout_id='$next_checkout_id' WHERE id='$seller_id'";
                if ($conn->query($sql) === TRUE) {
                    header("Location: seller_products.php?seller_id=$seller_id&hash=$hash");
                    exit();
                } else {
                    $message = "<div class='alert alert-danger' role='alert'>
                                    Fehler beim Verifizieren des Kontos: " . $conn->error . "
                                </div>";
                }
            } else {
                $message = "<div class='alert alert-warning' role='alert'>
                                Kein aktueller Bazaar gefunden.
                            </div>";
            }
        } else {
            $message = "<div class='alert alert-danger' role='alert'>
                            Ungültiger Hash.
                        </div>";
        }
    } else {
        $message = "<div class='alert alert-danger' role='alert'>
                        Ungültiger oder abgelaufener Token.
                    </div>";
    }

    $conn->close();
} else {
    $message = "<div class='alert alert-danger' role='alert'>
                    Keine gültigen Parameter angegeben.
                </div>";
}

// Display the message and modal if needed
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verification</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
	<h1 class="mb-4">Verkäufernummer-rückgabe bestätigen</h1>
    <?php echo $message; ?>
    <?php if ($showResetButton): ?>
        <!-- Description Section -->
        <div class="alert alert-info" role="alert">
            <h4 class="alert-heading">Verkäufer-ID: <?php echo htmlspecialchars($_GET['seller_id']); ?></h4>
            <p>Diese Seite ermöglicht es Ihnen, eine vorherige Anfrage zurückzusetzen. Wenn Sie fortfahren, wird Ihre Verkäufernummer freigegeben. Stellen Sie sicher, dass Sie diese Aktion durchführen möchten.</p>
        </div>

        <!-- Button to trigger modal -->
        <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#confirmModal">
            Anfrage zurücksetzen
        </button>

        <!-- Confirmation Modal -->
        <div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmModalLabel">Bestätigung erforderlich</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Schließen">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        Sind Sie sicher, dass Sie die Anfrage zurücksetzen möchten? Diese Aktion wird Ihre Verkäufernummer freigeben, und wenn dies ein Versehen war, müssen Sie eine erneute Nummernanfrage stellen.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                        <form method="post" action="">
                            <input type="hidden" name="seller_id" value="<?php echo htmlspecialchars($_GET['seller_id']); ?>">
                            <input type="hidden" name="hash" value="<?php echo htmlspecialchars($_GET['hash']); ?>">
                            <button type="submit" name="confirm_revert" class="btn btn-danger">Ja, zurücksetzen</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>