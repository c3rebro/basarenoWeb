<?php
require_once 'utilities.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

$seller_id = isset($_GET['seller_id']) ? $conn->real_escape_string($_GET['seller_id']) : null;
$hash = isset($_GET['hash']) ? $conn->real_escape_string($_GET['hash']) : null;

if ($seller_id && $hash) {
    // Verify the seller_id and hash
    $sql = "SELECT id, email FROM sellers WHERE id='$seller_id' AND hash='$hash'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $seller = $result->fetch_assoc();
        $email = $seller['email'];
        $message_type = ''; // Clear message type if seller is found
    } else {
        $message = "Ungültiger Link oder Verkäufer-ID.";
    }
} else {
    $message = "Ungültiger oder fehlender Link.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_delete'])) {
    $seller_id = $conn->real_escape_string($_POST['seller_id']);

    // Begin a transaction to ensure atomicity
    $conn->begin_transaction();

    try {
        // Delete products associated with the seller
        $sql = "DELETE FROM products WHERE seller_id='$seller_id'";
        if ($conn->query($sql) !== TRUE) {
            throw new Exception("Fehler beim Löschen der Produkte: " . $conn->error);
        }

        // Delete the seller
        $sql = "DELETE FROM sellers WHERE id='$seller_id'";
        if ($conn->query($sql) !== TRUE) {
            throw new Exception("Fehler beim Löschen des Verkäufers: " . $conn->error);
        }

        // Commit the transaction
        $conn->commit();
        $message = "Ihre persönlichen Daten und alle zugehörigen Produkte wurden erfolgreich gelöscht.";
        $message_type = 'success';
    } catch (Exception $e) {
        // Rollback the transaction in case of an error
        $conn->rollback();
        $message = $e->getMessage();
        $message_type = 'danger';
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Verkäuferdaten löschen</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <style>
        .container {
            max-width: 600px;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4">Datenlöschung bestätigen</h1>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($message_type !== 'success' && !$message): ?>
            <p class="alert alert-warning">Wenn Sie fortfahren, werden alle Ihre persönlichen Daten und alle zugehörigen Produkte unwiderruflich gelöscht. Sind Sie sicher, dass Sie fortfahren möchten?</p>
            <form method="post" action="flush.php">
                <input type="hidden" name="seller_id" value="<?php echo htmlspecialchars($seller_id); ?>">
                <button type="submit" name="confirm_delete" class="btn btn-danger btn-block">Ja, Daten löschen</button>
                <a href="index.php" class="btn btn-secondary btn-block">Abbrechen</a>
            </form>
        <?php endif; ?>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>