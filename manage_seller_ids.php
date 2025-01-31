<?php
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'seller') {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();
$email = $_SESSION['username'] ?? '';

// Handle various actions
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $seller_id = filter_input(INPUT_POST, 'seller_id', FILTER_VALIDATE_INT);
    $action = filter_input(INPUT_POST, 'action');

    if ($action === 'delete') {
        // Delete all products associated with the seller ID
        $stmt = $conn->prepare("DELETE FROM products WHERE seller_id=?");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();

        // Delete the seller ID
        $stmt = $conn->prepare("DELETE FROM sellers WHERE id=?");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();

        $message = "Verkäufernummer und zugehörige Produkte erfolgreich gelöscht.";
    } elseif ($action === 'release') {
        // Set the seller ID to not verified
        $stmt = $conn->prepare("UPDATE sellers SET verified=0 WHERE id=?");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();

        $message = "Verkäufernummer wurde freigegeben.";
    }
}

// Fetch all seller IDs for the user
$stmt = $conn->prepare("SELECT id, verified FROM sellers WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$seller_result = $stmt->get_result();

$seller_ids = [];
while ($seller = $seller_result->fetch_assoc()) {
    $seller_ids[] = $seller;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verkäufernummern verwalten</title>
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
    <style>
        .verified {
            background-color: #d4edda; /* Light green background */
        }
        .disabled {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="#">Bazaar</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="seller_products.php">Artikel verwalten</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="manage_seller_ids.php">Verkäufernummern verwalten <span class="sr-only">(current)</span></a>
                </li>
            </ul>
            <hr class="d-lg-none d-block">
            <ul class="navbar-nav">
                <li class="nav-item ml-lg-auto">
                    <a class="navbar-user" href="#">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($email); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white p-2" href="logout.php">Abmelden</a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <h2>Verkäufernummern verwalten</h2>
        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <table class="table table-bordered mt-3">
            <thead>
                <tr>
                    <th>Verkäufernummer</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($seller_ids as $seller): ?>
                    <tr class="<?php echo $seller['verified'] ? 'verified' : 'disabled'; ?>">
                        <td><?php echo $seller['id']; ?></td>
                        <td><?php echo $seller['verified'] ? 'Verifiziert' : 'Nicht verifiziert'; ?></td>
                        <td class="text-center">
                            <select class="form-control action-dropdown" data-seller-id="<?php echo $seller['id']; ?>">
                                <option value="">Aktion wählen</option>
                                <option value="delete">Löschen</option>
                                <?php if ($seller['verified']): ?>
                                    <option value="release">Freigeben</option>
                                <?php endif; ?>
                            </select>
                            <button class="btn btn-primary btn-sm execute-action" data-seller-id="<?php echo $seller['id']; ?>">Ausführen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <button class="btn btn-success mt-3" onclick="requestNewSellerId()">Neue Verkäufernummer anfordern</button>
    </div>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script nonce="<?php echo $nonce; ?>">
        $(document).on('click', '.execute-action', function() {
            const sellerId = $(this).data('seller-id');
            const action = $(`.action-dropdown[data-seller-id="${sellerId}"]`).val();

            if (action) {
                $.post('manage_seller_ids.php', { action: action, seller_id: sellerId, csrf_token: '<?php echo htmlspecialchars(generate_csrf_token()); ?>' }, function(response) {
                    location.reload();
                });
            }
        });

        function requestNewSellerId() {
            // Implement AJAX request to handle new seller ID request
            alert('Neue Verkäufernummer angefordert.');
        }
    </script>
</body>
</html>