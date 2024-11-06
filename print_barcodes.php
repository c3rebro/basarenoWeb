<?php
require_once 'utilities.php';
require_once 'barcode.php'; // Ensure the path to barcode.php is correct

if (!isset($_GET['seller_id']) || !isset($_GET['hash'])) {
    echo "Kein Verkäufer-ID oder Hash angegeben.";
    exit();
}

$seller_id = $_GET['seller_id'];
$hash = $_GET['hash'];

$conn = get_db_connection();

// Use prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT * FROM sellers WHERE id=? AND hash=? AND verified=1");
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

// Fetch the seller to get the checkout_id
$seller = $result->fetch_assoc();
$checkout_id = $seller['checkout_id'];

// Use prepared statement for fetching products
$stmt = $conn->prepare("SELECT * FROM products WHERE seller_id=?");
$stmt->bind_param("s", $seller_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "Keine Artikel gefunden.";
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Etiketten drucken</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .barcode-table {
            width: 100%;
            border-collapse: collapse;
        }
        .barcode-table td {
            border: 1px solid black;
            width: 6cm;
            height: 4cm;
            text-align: left;
            vertical-align: top;
            padding: 4px;
            position: relative;
        }
        .barcode-digits {
            font-family: Arial, sans-serif;
            font-size: 14px;
            margin-top: 5px;
            text-align: center;
        }
        .seller-price-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .seller-id {
            font-size: 22px;
            padding-top: 5px;
            padding-left: 5px;
            text-align: left;
            color: black;
        }
        .price {
            font-size: 22px;
            padding-right: 5px;
            text-align: right;
        }
        .product-name {
            font-size: 22px;
            padding-left: 5px;
            color: darkred;
        }
        .product-size {
            color: black;
        }
        .checkout-id {
            font-size: 18px;
            color: blue;
            font-weight: bold;
        }
        .barcode-container {
            text-align: center;
            margin-top: 10px;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .barcode-table td {
                border: 1px solid black;
                width: 6cm;
                height: 4cm;
                text-align: left;
                vertical-align: top;
                padding: 5px;
                position: relative;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mt-5">Etiketten drucken</h1>
        <table class="barcode-table">
            <tbody>
                <?php
                $counter = 0;
                while ($product = $result->fetch_assoc()):
                    if ($counter % 2 == 0) {
                        echo "<tr>";
                    }

                    // Generate the barcode
                    $barcode_data = encode($product['barcode'], 'EAN13', true);
                    $barcode_image = generate_barcode_image(barcode($barcode_data), 3, 50);

                    if (DEBUG) {
                        debug_log("Generating barcode for product: " . $product['name']);
                        debug_log("Barcode data: " . $barcode_data);
                        debug_log("Barcode image length: " . strlen($barcode_image));
                    }

                    $barcode_base64 = base64_encode($barcode_image);
                ?>
                    <td>
                        <div class="product-name">
                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            <?php if (!empty(trim($product['size']))): ?>
                                <span class="product-size">Größe: <?php echo htmlspecialchars($product['size']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="seller-price-container">
                            <div class="seller-id">Verkäufer: <strong><?php echo htmlspecialchars($seller_id); ?></strong></div>
                            <div class="price">Preis: <?php echo number_format($product['price'], 2, ',', '.'); ?> €</div>
                        </div>
                        <div class="barcode-container">
                            <img src="data:image/png;base64,<?php echo $barcode_base64; ?>" alt="Barcode"><br>
                            <div class="barcode-digits">
                                <?php echo htmlspecialchars($product['barcode']); ?> - 
                                <span class="checkout-id"><?php echo htmlspecialchars($checkout_id); ?></span>
                            </div>
                        </div>
                    </td>
                <?php
                    $counter++;
                    if ($counter % 2 == 0) {
                        echo "</tr>";
                    }
                endwhile;
                if ($counter % 2 != 0) {
                    echo "<td></td></tr>"; // Add an empty cell if the number of products is odd
                }
                ?>
            </tbody>
        </table>
        <button onclick="window.print()" class="btn btn-primary mt-3">Drucken</button>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>