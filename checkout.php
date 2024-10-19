<!-- checkout.php -->
<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

if (!isset($_GET['seller_id']) || !isset($_GET['hash'])) {
    echo "Kein Verkäufer-ID oder Hash angegeben.";
    exit();
}

$seller_id = $_GET['seller_id'];
$hash = $_GET['hash'];

$conn = get_db_connection();
$sql = "SELECT * FROM sellers WHERE id='$seller_id' AND hash='$hash' AND verified=1";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    echo "
<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
    <title>Verkäufer-ID Verifizierung</title>
    <link href='css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container'>
        <div class='alert alert-warning mt-5'>
            <h4 class='alert-heading'>Ungültige oder nicht verifizierte Verkäufer-ID oder Hash.</h4>
            <p>Bitte überprüfen Sie Ihre Verkäufer-ID und versuchen Sie es erneut.</p>
            <hr>
            <p class='mb-0'>Haben Sie auf den Verifizierungslink in der E-Mail geklickt?</p>
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

$sql = "SELECT * FROM sellers WHERE id='$seller_id'";
$result = $conn->query($sql);
$seller = $result->fetch_assoc();

$sql = "SELECT * FROM products WHERE seller_id='$seller_id'";
$products_result = $conn->query($sql);

$conn->close();

function send_checkout_email($to, $subject, $body) {
    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\n";
    $headers .= "MIME-Version: 1.0\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\n";

    if (mail($to, $subject, $body, $headers)) {
        return true;
    } else {
        return 'Mail Error: Unable to send email.';
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['notify_seller'])) {
    $total = 0;
    $email_body = "<html><body>";
    $email_body .= "<h1>Checkout für Verkäufer: {$seller['name']} (Verkäufernummer: {$seller['id']})</h1>";
    $email_body .= "<table border='1' cellpadding='10'>";
    $email_body .= "<tr><th>Produktname</th><th>Preis</th><th>Verkauft</th></tr>";
    
    $products_result->data_seek(0); // Reset the result pointer to the beginning
    while ($product = $products_result->fetch_assoc()) {
        $sold = isset($_POST['sold_' . $product['id']]) ? 1 : 0;
        $price = number_format($product['price'], 2, ',', '.') . ' €';
        $email_body .= "<tr><td>{$product['name']}</td><td>{$price}</td><td>" . ($sold ? 'Ja' : 'Nein') . "</td></tr>";
        if ($sold) {
            $total += $product['price'];
        }
    }
    $email_body .= "</table>";
    $email_body .= "<h2>Gesamt: " . number_format($total, 2, ',', '.') . " €</h2>";
    $email_body .= "</body></html>";

    $subject = "Checkout für Verkäufer: {$seller['name']} (Verkäufernummer: {$seller['id']})";
    if ($total > 0) {
        $email_body .= "<p>Vielen Dank für Ihre Unterstützung!</p>";
    } else {
        $email_body .= "<p>Leider wurden keine Artikel verkauft. Wir hoffen auf das Beste beim nächsten Mal.</p>";
    }

    $send_result = send_checkout_email($seller['email'], $subject, $email_body);
    if ($send_result === true) {
        $success = "E-Mail erfolgreich an den Verkäufer gesendet.";
    } else {
        $error = "Fehler beim Senden der E-Mail: $send_result";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Checkout - Verkäufer: <?php echo htmlspecialchars($seller['name']); ?> (Verkäufernummer: <?php echo htmlspecialchars($seller['id']); ?>)</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-responsive {
            margin-top: 1rem;
        }
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mt-5">Checkout - Verkäufer: <?php echo htmlspecialchars($seller['name']); ?> (Verkäufernummer: <?php echo htmlspecialchars($seller['id']); ?>)</h1>
        <?php if (isset($error)) { echo "<div class='alert alert-danger'>$error</div>"; } ?>
        <?php if (isset($success)) { echo "<div class='alert alert-success'>$success</div>"; } ?>

        <form action="checkout.php?seller_id=<?php echo $seller_id; ?>" method="post">
            <div class="table-responsive">
                <table class="table table-bordered mt-3">
                    <thead>
                        <tr>
                            <th>Produktname</th>
                            <th>Preis</th>
                            <th>Verkauft</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total = 0;
                        while ($product = $products_result->fetch_assoc()) {
                            $price = number_format($product['price'], 2, ',', '.') . ' €';
                            $sold_checked = $product['sold'] ? 'checked' : '';
                            echo "<tr>
                                    <td>{$product['name']}</td>
                                    <td>{$price}</td>
                                    <td><input type='checkbox' name='sold_{$product['id']}' $sold_checked></td>
                                  </tr>";
                            if ($product['sold']) {
                                $total += $product['price'];
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <h3>Gesamt: <?php echo number_format($total, 2, ',', '.'); ?> €</h3>
            <button type="submit" class="btn btn-primary btn-block" name="notify_seller">Verkäufer benachrichtigen</button>
        </form>
        <button onclick="window.print()" class="btn btn-secondary btn-block mt-3">Drucken</button>
        <a href="admin_manage_sellers.php" class="btn btn-primary btn-block mt-3">Zurück zu Verkäufer verwalten</a>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>