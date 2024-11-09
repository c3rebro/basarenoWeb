<?php
require_once 'utilities.php';
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

// Use prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT * FROM sellers WHERE id=? AND hash=?");
$stmt->bind_param("ss", $seller_id, $hash);
$stmt->execute();
$result = $stmt->get_result();

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
            <h4 class='alert-heading'>Ungültige Verkäufer-ID oder Hash.</h4>
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

if ($result->num_rows > 0) {
    $seller = $result->fetch_assoc();
    $checkout_id = $seller['checkout_id'];    
}

if ($seller['verified'] != 1) {
    echo "
<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
    <title>Unverifizierter Verkäufer</title>
    <link href='css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container'>
        <div class='alert alert-danger mt-5'>
            <h4 class='alert-heading'>Unverifizierte Verkäufer können nicht abgerechnet werden.</h4>
            <p>Bitte verifizieren Sie Ihren Account, um fortzufahren.</p>
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

// Use prepared statement for fetching products
$stmt = $conn->prepare("SELECT * FROM products WHERE seller_id=?");
$stmt->bind_param("s", $seller_id);
$stmt->execute();
$products_result = $stmt->get_result();

$total = 0.0;
$total_brokerage = 0.0;
$brokerage = 0.0;
$current_date = date('Y-m-d');

// Use prepared statement for retrieving the current bazaar
$stmt = $conn->prepare("SELECT brokerage, price_stepping FROM bazaar WHERE startDate <= ? AND DATE_ADD(startDate, INTERVAL 30 DAY) >= ? LIMIT 1");
$stmt->bind_param("ss", $current_date, $current_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $brokerage = $row['brokerage'];
    $price_stepping = $row['price_stepping'];

    // Use prepared statement for updating seller checkout
    $stmt = $conn->prepare("UPDATE sellers SET checkout=TRUE WHERE id=?");
    $stmt->bind_param("s", $seller_id);
    if ($stmt->execute()) {
        $success = "Verkäufer erfolgreich ausgecheckt.";
        debug_log("Seller checked out: ID=$seller_id");
    } else {
        $error = "Fehler beim Auschecken des Verkäufers: " . $conn->error;
        debug_log("Error checking out seller: " . $conn->error);
    }
} else {
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
            <div class='alert alert-warning mt-5 alert-dismissible fade show' role='alert'>
                <h4 class='alert-heading'>Hinweis:</h4>
                <p>Es wurde kein Bazaar gefunden, der abgerechnet werden kann.<br>Läuft der aktuelle Basar eventuell noch? (siehe Startdatum)</p>
            </div>
        </div>
        <script src='js/jquery-3.7.1.min.js'></script>
        <script src='js/popper.min.js'></script>
        <script src='js/bootstrap.bundle.min.js'></script>
    </body>
    </html>
    ";
    exit;
}

// Function to round to nearest specified increment
function round_to_nearest($value, $increment) {
    return round($value / $increment) * $increment;
}

$conn->close();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['notify_seller'])) {
    $total = 0;
    $total_brokerage = 0;
    $email_body = "<html><body>";
    $email_body .= "<h1>Checkout für Verkäufer: " . htmlspecialchars($seller['given_name']) . " " . htmlspecialchars($seller['family_name']) . " (VerkäuferNr: " . htmlspecialchars($seller['id']) . ")</h1>";
    $email_body .= "<table border='1' cellpadding='10'>";
    $email_body .= "<tr><th>Produktname</th><th>Größe</th><th>Preis</th><th>Verkauft</th></tr>";
    
    $products_result->data_seek(0); // Reset the result pointer to the beginning
    while ($product = $products_result->fetch_assoc()) {
        $sold = isset($_POST['sold_' . $product['id']]) ? 1 : 0;
        $size = htmlspecialchars($product['size']);
        $price = number_format($product['price'], 2, ',', '.') . ' €';
        $seller_brokerage = $sold ? $product['price'] * $brokerage : 0;
        $email_body .= "<tr><td>" . htmlspecialchars($product['name']) . "</td><td>{$size}</td><td>{$price}</td><td>" . ($sold ? 'Ja' : 'Nein') . "</td></tr>";
        if ($sold) {
            $total += $product['price'];
            $total_brokerage += $seller_brokerage;
        }
    }
    $email_body .= "</table>";
    $email_body .= "<h2>Auszahlungsbetrag: " . number_format($total - $total_brokerage, 2, ',', '.') . " €</h2>";
    $email_body .= "</body></html>";

    $subject = "Checkout für Verkäufer: " . htmlspecialchars($seller['given_name']) . " " . htmlspecialchars($seller['family_name']) . " (Verkäufernummer: " . htmlspecialchars($seller['id']) . ")";
    if ($total > 0) {
        $email_body .= "<p>Vielen Dank für Ihre Unterstützung!</p>";
    } else {
        $email_body .= "<p>Leider wurden keine Artikel verkauft. Wir hoffen auf das Beste beim nächsten Mal.</p>";
    }

    $send_result = send_email($seller['email'], $subject, $email_body);
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
    <title>Checkout - Verkäufer: <?php echo htmlspecialchars($seller['given_name']); ?> <?php echo htmlspecialchars($seller['family_name']); ?> (Verkäufer Nr.: <?php echo htmlspecialchars($seller['id']); ?>) {<?php echo htmlspecialchars($seller['checkout_id']); ?>}</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/style.css" rel="stylesheet">
	
</head>
<body>
    <div class="container">
        <h3 class="mt-5">Checkout (Verk.Nr.: <?php echo htmlspecialchars($seller['id']); ?>): <?php echo htmlspecialchars($seller['given_name']); ?> <?php echo htmlspecialchars($seller['family_name']); ?> {<?php echo htmlspecialchars($seller['checkout_id']); ?>}</h3>
        <?php if (isset($error)) { echo "<div class='alert alert-danger'>" . htmlspecialchars($error) . "</div>"; } ?>
        <?php if (isset($success)) { echo "<div class='alert alert-success'>" . htmlspecialchars($success) . "</div>"; } ?>

        <form action="checkout.php?seller_id=<?php echo htmlspecialchars($seller_id); ?>&hash=<?php echo htmlspecialchars($hash); ?>" method="post">
            <div class="table-responsive">
                <table class="table table-bordered mt-3">
                    <thead>
                        <tr>
                            <th>Produktname</th>
                            <th>Größe</th>
                            <th>Preis</th>
                            <th>Verkauft</th>
                            <th class="brokerage">Provision</th>
                            <th class="auszahlung">Auszahlung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total = 0;
                        $total_brokerage = 0;
                        while ($product = $products_result->fetch_assoc()) {
                            $price = number_format($product['price'], 2, ',', '.') . ' €';
                            $sold_checked = $product['sold'] ? 'checked' : '';
                            $seller_brokerage = $product['sold'] ? $product['price'] * $brokerage : 0;
                            $provision = number_format($seller_brokerage, 2, ',', '.') . ' €';
                            $auszahlung = number_format($product['price'] - $seller_brokerage, 2, ',', '.') . ' €';
                            echo "<tr>
                                    <td>" . htmlspecialchars($product['name']) . "</td>
                                    <td>" . htmlspecialchars($product['size']) . "</td>
                                    <td>{$price}</td>
                                    <td><input type='checkbox' name='sold_" . htmlspecialchars($product['id']) . "' $sold_checked></td>
                                    <td class='brokerage'>{$provision}</td>
                                    <td class='auszahlung'>{$auszahlung}</td>
                                  </tr>";
                            if ($product['sold']) {
                                $total += $product['price'];
                                $total_brokerage += $seller_brokerage;
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <h4 class="gesamt">Gesamt: <?php echo number_format($total, 2, ',', '.'); ?> €</h4>
            <h4 class="provision">Provision: <?php echo number_format(round_to_nearest($total_brokerage, $price_stepping), 2, ',', '.'); ?> € (gerundet)</h4>
            <h4>Auszahlungsbetrag: <?php echo number_format($total - round_to_nearest($total_brokerage, $price_stepping), 2, ',', '.'); ?> € (abzgl. Provision - gerundet)</h4>
            <button type="submit" class="btn btn-primary btn-block no-print" name="notify_seller">Verkäufer benachrichtigen</button>
        </form>
        <button id="printWithBrokerage" class="btn btn-secondary btn-block mt-3 no-print">Drucken (mit Provision)</button>
        <button id="printWithoutBrokerage" class="btn btn-secondary btn-block mt-3 no-print">Drucken (ohne Provision)</button>
        <a href="admin_manage_sellers.php" class="btn btn-primary btn-block mt-3 mb-5 no-print">Zurück zu Verkäufer verwalten</a>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
        document.getElementById('printWithBrokerage').addEventListener('click', function() {
            window.print();
        });

        document.getElementById('printWithoutBrokerage').addEventListener('click', function() {
            document.body.classList.add('no-print-brokerage');
            window.print();
            document.body.classList.remove('no-print-brokerage');
        });
    </script>
</body>
</html>