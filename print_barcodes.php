<?php
require_once 'config.php';
require_once 'barcode.php'; // Ensure the path to barcode.php is correct

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

// Fetch the seller to get the checkout_id
$seller = $result->fetch_assoc();
$checkout_id = $seller['checkout_id'];

$sql = "SELECT * FROM products WHERE seller_id='$seller_id'";
$result = $conn->query($sql);

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
            width: 8cm;
            height: 6cm;
            text-align: left;
            vertical-align: top;
            padding: 5px;
            position: relative;
        }
        .barcode-digits {
            font-family: Arial, sans-serif;
            font-size: 14px;
            margin-top: 5px;
            text-align: center;
        }
        .seller-id {
            font-size: 22px; /* Default text size */
            padding-top: 5px; /* Padding for top */
            padding-left: 5px; /* Padding for left */
        }
        .product-name, .price {
            font-size: 22px; /* Default text size */
            padding-left: 5px; /* Padding for left */
        }
        .product-name {
            color: darkred; /* Default color for product name */
        }
        .checkout-id {
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 30px; /* Larger text size for checkout ID */
            font-weight: bold;
            padding-top: 5px; /* Padding for top */
            padding-right: 10px; /* Padding for right */
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
                width: 8cm;
                height: 6cm;
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
                    // Adjust the width and height as needed
                    $barcode_image = generate_barcode_image(barcode($barcode_data), 3, 150); // Example: 3px width, 150px height

                    if (DEBUG) {
                        debug_log("Generating barcode for product: " . $product['name']);
                        debug_log("Barcode data: " . $barcode_data);
                        debug_log("Barcode image length: " . strlen($barcode_image));
                    }

                    // Encode the barcode image as a base64 string
                    $barcode_base64 = base64_encode($barcode_image);
                ?>
                    <td>
                        <div class="checkout-id"><?php echo $checkout_id; ?></div>
                        <div class="seller-id">Verkäufernummer: <strong><?php echo $seller_id; ?></strong></div>
                        <div class="product-name"><strong><?php echo $product['name']; ?></strong> Größe: <?php echo $product['size']; ?></div>
                        <div class="price">Preis: <?php echo number_format($product['price'], 2, ',', '.'); ?> €</div>
                        <div class="barcode-container">
                            <img src="data:image/png;base64,<?php echo $barcode_base64; ?>" alt="Barcode"><br>
                            <div class="barcode-digits"><?php echo $product['barcode']; ?></div>
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