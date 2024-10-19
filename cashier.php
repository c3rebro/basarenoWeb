<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

require_once 'config.php';

$conn = get_db_connection();
initialize_database($conn);

$message = '';
$scanned_products = [];
$sum_of_prices = isset($_SESSION['sum_of_prices']) ? $_SESSION['sum_of_prices'] : 0.0;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['barcode'])) {
        $barcode = $_POST['barcode'];
        debug_log("Scanned Barcode: $barcode");

        $sql = "SELECT id, name, price, sold, seller_id FROM products WHERE barcode='$barcode'";
        $result = $conn->query($sql);
        debug_log("SQL Query: $sql");

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if ($row['sold'] == 1) {
                $message = "Produkt bereits gescannt";
                debug_log("Product already sold.");
            } else {
                $formatted_price = number_format($row['price'], 2, ',', '.');
                $message = "Produkt: " . $row['name'] . "<br>Preis: €" . $formatted_price . "<br>Verkäufer-ID: " . $row['seller_id'];

                // Mark the product as sold
                $sql = "UPDATE products SET sold=1 WHERE barcode='$barcode'";
                $conn->query($sql);
                debug_log("Product found and marked as sold.");

                // Add the price to the sum
                $sum_of_prices += $row['price'];
                $_SESSION['sum_of_prices'] = $sum_of_prices;
            }
        } else {
            $message = "Produkt nicht gefunden";
            debug_log("Product not found in the database.");
        }
    } elseif (isset($_POST['unsell_product'])) {
        $product_id = $_POST['product_id'];

        // Mark the product as unsold
        $sql = "UPDATE products SET sold=0 WHERE id='$product_id'";
        $conn->query($sql);
        debug_log("Product ID $product_id marked as unsold.");
    } elseif (isset($_POST['reset_sum'])) {
        $sum_of_prices = 0.0;
        $_SESSION['sum_of_prices'] = $sum_of_prices;
    }
}

// Clear the scanned products array before fetching the last 30 scanned products
$scanned_products = [];

// Fetch the last 30 scanned products
$sql = "SELECT id, name, price, seller_id FROM products WHERE sold=1 ORDER BY id DESC LIMIT 30";
$result = $conn->query($sql);
debug_log("Fetching last 30 scanned products.");
while ($row = $result->fetch_assoc()) {
    $scanned_products[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Kassierer</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <script src="js/quagga.min.js"></script>
    <style>
        #scanner-container {
            position: relative;
            width: 100%;
            height: 100%;
            border: 1px solid #ddd;
        }
        #scanner-line {
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: red;
            z-index: 1;
        }
        .scanner-wrapper {
            width: 100%;
            height: 400px;
            overflow: hidden;
        }
        .overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 80%;
            height: 30%;
            border: 2px solid red;
            transform: translate(-50%, -50%);
            z-index: 2;
        }
        .table-container {
            max-height: 400px;
            overflow-y: scroll;
        }
        .manual-entry-form {
            margin: 20px 0;
        }
    </style>
    <script>
        function startScanner() {
            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: document.querySelector('#scanner-container')
                },
                decoder: {
                    readers: ["ean_reader"],
                    debug: {
                        drawBoundingBox: true,
                        showFrequency: true,
                        drawScanline: true,
                        showPattern: true
                    },
                    multiple: false
                },
                locate: true,
                locator: {
                    halfSample: true,
                    patchSize: "medium", // x-small, small, medium, large, x-large
                    debug: {
                        showCanvas: true,
                        showPatches: true,
                        showFoundPatches: true,
                        showSkeleton: true,
                        showLabels: true,
                        showPatchLabels: true,
                        showRemainingPatchLabels: true,
                        boxFromPatches: {
                            showTransformed: true,
                            showTransformedBox: true,
                            showBB: true
                        }
                    }
                }
            }, function (err) {
                if (err) {
                    console.log(err);
                    return;
                }
                Quagga.start();
            });

            Quagga.onDetected(function (data) {
                document.getElementById('barcode').value = data.codeResult.code;
                document.getElementById('scan-form').submit();
                showNotification();
            });
        }

        function showNotification() {
            if (Notification.permission === "granted") {
                new Notification("Produkt erfolgreich gescannt!");
            } else if (Notification.permission !== "denied") {
                Notification.requestPermission().then(permission => {
                    if (permission === "granted") {
                        new Notification("Produkt erfolgreich gescannt!");
                    }
                });
            }
        }
    </script>
</head>
<body onload="startScanner()">
    <div class="container">
        <h2 class="mt-5">Kassierer</h2>
        <?php if ($message) { echo "<div class='alert alert-info'>$message</div>"; } ?>
        <?php if (DEBUG) { debug_log("Debug log enabled"); } ?>
        <div class="scanner-wrapper">
            <div id="scanner-container">
                <div id="scanner-line"></div>
                <div class="overlay"></div>
            </div>
        </div>
        <form id="scan-form" action="cashier.php?nocache=<?php echo time(); ?>" method="post">
            <input type="hidden" id="barcode" name="barcode">
        </form>
        <div class="manual-entry-form">
            <form action="cashier.php?nocache=<?php echo time(); ?>" method="post">
                <div class="form-group">
                    <label for="manual-barcode">Manuelle Barcodeeingabe:</label>
                    <input type="text" class="form-control" id="manual-barcode" name="barcode" required>
                </div>
                <button type="submit" class="btn btn-primary">Artikel hinzufügen</button>
            </form>
        </div>
        <h3 class="mt-5">Erfolgreich gescannte Artikel</h3>
        <div class="table-container">
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>Artikelname</th>
                        <th>Preis</th>
                        <th>Verkäufer-Nr.</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scanned_products as $product): ?>
                    <tr>
                        <td><?php echo $product['name']; ?></td>
                        <td><?php echo number_format($product['price'], 2, ',', '.'); ?> €</td>
                        <td><?php echo $product['seller_id']; ?></td>
                        <td>
                            <form action="cashier.php?nocache=<?php echo time(); ?>" method="post" style="display:inline-block">
                                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                <button type="submit" name="unsell_product" class="btn btn-danger btn-sm">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h3 class="mt-5">Summe: €<?php echo number_format($sum_of_prices, 2, ',', '.'); ?></h3>
        <form action="cashier.php?nocache=<?php echo time(); ?>" method="post">
            <button type="submit" name="reset_sum" class="btn btn-warning mb-5">Abschluss</button>
        </form>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>