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

$total = 0.0;
$total_brokerage = 0.0;
$brokerage = 0.0;
$current_date = date('Y-m-d');

// Retrieve the current bazaar based on the current date
$sql = "SELECT brokerage FROM bazaar WHERE startDate <= '$current_date' AND DATE_ADD(startDate, INTERVAL 30 DAY) >= '$current_date' LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $brokerage = $row['brokerage'];
} else {
    echo "Kein aktueller Bazaar gefunden.";
    exit;
}

$conn->close();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['notify_seller'])) {
    $total = 0;
    $total_brokerage = 0;
    $email_body = "<html><body>";
    $email_body .= "<h1>Checkout für Verkäufer: {$seller['name']} (Verkäufernummer: {$seller['id']})</h1>";
    $email_body .= "<table border='1' cellpadding='10'>";
    $email_body .= "<tr><th>Produktname</th><th>Größe</th><th>Preis</th><th>Verkauft</th></tr>";
    
    $products_result->data_seek(0); // Reset the result pointer to the beginning
    while ($product = $products_result->fetch_assoc()) {
        $sold = isset($_POST['sold_' . $product['id']]) ? 1 : 0;
        $size = $product['size'];
        $price = number_format($product['price'], 2, ',', '.') . ' €';
        $seller_brokerage = $sold ? $product['price'] * $brokerage : 0;
        $email_body .= "<tr><td>{$product['name']}</td><td>{$size}</td><td>{$price}</td><td>" . ($sold ? 'Ja' : 'Nein') . "</td></tr>";
        if ($sold) {
            $total += $product['price'];
            $total_brokerage += $seller_brokerage;
        }
    }
    $email_body .= "</table>";
    $email_body .= "<h2>Auszahlungsbetrag: " . number_format($total - $total_brokerage, 2, ',', '.') . " €</h2>";
    $email_body .= "</body></html>";

    $subject = "Checkout für Verkäufer: {$seller['name']} (Verkäufernummer: {$seller['id']})";
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
        @media print {
            .no-print {
                display: none;
            }
            .no-print-brokerage .brokerage,
            .no-print-brokerage .auszahlung,
            .no-print-brokerage .gesamt,
            .no-print-brokerage .provision {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h3 class="mt-5">Checkout (Verk.Nr.: <?php echo htmlspecialchars($seller['id']); ?>): <?php echo htmlspecialchars($seller['name']); ?> </h3>
        <?php if (isset($error)) { echo "<div class='alert alert-danger'>$error</div>"; } ?>
        <?php if (isset($success)) { echo "<div class='alert alert-success'>$success</div>"; } ?>

        <form action="checkout.php?seller_id=<?php echo $seller_id; ?>&hash=<?php echo $hash; ?>" method="post">
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
                                    <td>{$product['name']}</td>
                                    <td>{$product['size']}</td>
                                    <td>{$price}</td>
                                    <td><input type='checkbox' name='sold_{$product['id']}' $sold_checked></td>
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
            <h4 class="provision">Provision: <?php echo number_format($total_brokerage, 2, ',', '.'); ?> €</h4>
            <h4>Auszahlungsbetrag: <?php echo number_format($total - $total_brokerage, 2, ',', '.'); ?> €</h4>
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