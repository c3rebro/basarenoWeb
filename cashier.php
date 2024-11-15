<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' blob:; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier')) {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

$scanned_products = [];
$sum_of_prices = isset($_SESSION['sum_of_prices']) ? $_SESSION['sum_of_prices'] : 0.0;
$groups = isset($_SESSION['groups']) ? $_SESSION['groups'] : [];
$buyer_index = isset($_SESSION['buyer_index']) ? $_SESSION['buyer_index'] : 1;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['barcode'])) {
        $barcode = $_POST['barcode'];
        log_action($conn, $_SESSION['user_id'], "Scanned barcode", "Barcode: $barcode");

        $stmt = $conn->prepare("SELECT id, name, price, sold, seller_id FROM products WHERE barcode=?");
        $stmt->bind_param("s", $barcode);
        $stmt->execute();
        $result = $stmt->get_result();
        log_action($conn, $_SESSION['user_id'], "SQL Query executed with prepared statement", "Barcode: $barcode");

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if ($row['sold'] == 1) {
		$message_type = 'danger';
                $message = "Produkt bereits gescannt";
                log_action($conn, $_SESSION['user_id'], "Product already sold", "Product ID: " . $row['id']);
            } else {
                $formatted_price = number_format($row['price'], 2, ',', '.');
		$message_type = 'success';
                $message = "Produkt: " . htmlspecialchars($row['name']) . "<br>Preis: €" . $formatted_price . "<br>Verkäufer-ID: " . htmlspecialchars($row['seller_id']);

                $stmt = $conn->prepare("UPDATE products SET sold=1 WHERE barcode=?");
                $stmt->bind_param("s", $barcode);
                $stmt->execute();
                log_action($conn, $_SESSION['user_id'], "Product marked as sold", "Product ID: " . $row['id']);

                if (!isset($groups[0])) {
                    $groups[0] = ['products' => [], 'sum' => 0];
                }
                $groups[0]['products'][] = $row;
                $groups[0]['sum'] += $row['price'];

                $sum_of_prices += $row['price'];
                $_SESSION['sum_of_prices'] = $sum_of_prices;
            }
        } else {
            $message_type = 'danger';
            $message = "Produkt nicht gefunden";
            log_action($conn, $_SESSION['user_id'], "Product not found", "Barcode: $barcode");
        }
    } elseif (isset($_POST['unsell_product'])) {
        $product_id = $_POST['product_id'];

        $stmt = $conn->prepare("UPDATE products SET sold=0 WHERE id=?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        log_action($conn, $_SESSION['user_id'], "Product marked as unsold", "Product ID: $product_id");
    } elseif (isset($_POST['reset_sum'])) {
        array_unshift($groups, ['products' => [], 'sum' => 0]);
        $sum_of_prices = 0.0;
        $_SESSION['sum_of_prices'] = $sum_of_prices;

        $buyer_index++;
        $_SESSION['buyer_index'] = $buyer_index;

        log_action($conn, $_SESSION['user_id'], "Sum of prices reset");
    }
    $_SESSION['groups'] = $groups;
}

$scanned_products = [];

$sql = "SELECT id, name, price, seller_id FROM products WHERE sold=1 ORDER BY id DESC LIMIT 30";
$result = $conn->query($sql);
log_action($conn, $_SESSION['user_id'], "Fetched last 30 scanned products");
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
    <script src="js/quagga.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script nonce="<?php echo $nonce; ?>">
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Document loaded");

            let scannerActive = false;
            let timeoutHandle;
            const restartDelay = 3000; // 2 seconds delay before restarting the scanner

            function startScanner() {
                if (scannerActive) return;

                console.log("Starting scanner");
                Quagga.init({
                    inputStream: {
                        name: "Live",
                        type: "LiveStream",
                        target: document.querySelector('#scanner-container'),
                        constraints: {
                            width: 640,
                            height: 480,
                            frameRate: { max: 10 } // Limit to 10 FPS
                        }
                    },
                    decoder: {
                        readers: ["ean_reader"]
                    },
                    locate: true
                }, function (err) {
                    if (err) {
                        console.log("Error initializing Quagga: ", err);
                        return;
                    }
                    Quagga.start();
                    scannerActive = true;
                    document.getElementById('start-scanner').style.display = 'none';
                    document.getElementById('stop-scanner').style.display = 'inline-block';
                    resetScannerTimeout();
                });

                Quagga.onDetected(function (data) {
                    console.log("Barcode detected: ", data.codeResult.code);
                    document.getElementById('barcode').value = data.codeResult.code;
                    document.getElementById('scan-form').submit();
                    showNotification();
                    stopScanner();

                    // Restart the scanner after a delay
                    setTimeout(startScanner, restartDelay);
                });
            }

            function stopScanner() {
                if (!scannerActive) return;

                console.log("Stopping scanner");
                Quagga.stop();
                scannerActive = false;
                document.getElementById('stop-scanner').style.display = 'none';
                document.getElementById('start-scanner').style.display = 'inline-block';
                clearTimeout(timeoutHandle);
            }

            function resetScannerTimeout() {
                clearTimeout(timeoutHandle);
                timeoutHandle = setTimeout(stopScanner, 90000);
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

            document.getElementById('start-scanner').addEventListener('click', startScanner);
            document.getElementById('stop-scanner').addEventListener('click', stopScanner);

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    startScanner();
                } else {
                    stopScanner();
                }
            });

            window.onload = function() {
                startScanner();
            };
        });
    </script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="dashboard.php">Bazaar Kassier</a>
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
		<?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        <div class="scanner-wrapper">
            <div id="scanner-container">
                <div id="scanner-line"></div>
                <div class="overlay"></div>
            </div>
        </div>
        <div class="button-container text-center">
            <button id="start-scanner" class="btn btn-primary btn-full-width">Start Scanner</button>
            <button id="stop-scanner" class="btn btn-secondary btn-full-width hidden">Stop Scanner</button>
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
                <button type="submit" class="btn btn-primary btn-full-width">Artikel hinzufügen</button>
            </form>
        </div>
        <form action="cashier.php?nocache=<?php echo time(); ?>" method="post">
            <button type="submit" name="reset_sum" class="btn btn-warning btn-full-width mb-2">Abschluss</button>
        </form>
        <h3 class="mt-2">Summe: €<?php echo number_format($sum_of_prices, 2, ',', '.'); ?></h3>
        <hr>
        <h3 class="mt-1">Erfolgreich gescannte Artikel</h3>
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
                    <?php foreach ($groups as $index => $group): ?>
                    <tr data-toggle="collapse" data-target="#group-<?php echo $index; ?>" class="clickable">
                        <td colspan="4">
                            Käufer <?php echo $buyer_index - $index; ?> - Artikelanzahl: <?php echo count($group['products']); ?> - Summe: €<?php echo number_format($group['sum'], 2, ',', '.'); ?>
                        </td>
                    </tr>
                    <tr id="group-<?php echo $index; ?>" class="collapse <?php echo $index === 0 ? 'show' : ''; ?>">
                        <td colspan="4">
                            <table class="table">
                                <?php foreach ($group['products'] as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><?php echo number_format($product['price'], 2, ',', '.'); ?> €</td>
                                    <td><?php echo htmlspecialchars($product['seller_id']); ?></td>
                                    <td>
                                        <form class="d-block" action="cashier.php?nocache=<?php echo time(); ?>" method="post">
                                            <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id']); ?>">
                                            <button type="submit" name="unsell_product" class="btn btn-danger btn-sm">Entfernen</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty(FOOTER)): ?>
        <footer class="p-2 bg-light text-center fixed-bottom">
            <div class="row justify-content-center">
                <div class="col-lg-6 col-md-12">
                    <p class="m-0">
                        <?php echo process_footer_content(FOOTER); ?>
                    </p>
                </div>
            </div>
        </footer>
    <?php endif; ?>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>

    <script nonce="<?php echo $nonce; ?>">
        $(document).ready(function() {
            function toggleBackToTopButton() {
                const scrollTop = $(window).scrollTop();

                if (scrollTop > 100) {
                    $('#back-to-top').fadeIn();
                } else {
                    $('#back-to-top').fadeOut();
                }
            }

            toggleBackToTopButton();

            $(window).scroll(function() {
                toggleBackToTopButton();
            });

            $('#back-to-top').click(function() {
                $('html, body').animate({ scrollTop: 0 }, 600);
                return false;
            });
        });
    </script>
</body>
</html>