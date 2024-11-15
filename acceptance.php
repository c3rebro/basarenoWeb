<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'assistant')) {
    header("location: login.php");
    exit;
}

require_once 'utilities.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

$username = $_SESSION['username'] ?? '';

// Handle fee payment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['pay_fee'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    
    $seller_id = intval($_POST['seller_id']);
    $stmt = $conn->prepare("UPDATE sellers SET fee_payed=TRUE WHERE id=?");
    $stmt->bind_param("i", $seller_id);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Gebühr erfolgreich bezahlt.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Bezahlen der Gebühr: " . $conn->error;
    }
}

// Handle fee reversion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['revert_fee'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    
    $seller_id = intval($_POST['seller_id']);
    $stmt = $conn->prepare("UPDATE sellers SET fee_payed=FALSE WHERE id=?");
    $stmt->bind_param("i", $seller_id);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Gebühr erfolgreich zurückgesetzt.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Zurücksetzen der Gebühr: " . $conn->error;
    }
}

// Retrieve all sellers
$search_id = $_GET['search_id'] ?? ($_POST['search_id'] ?? '');
$sql = "SELECT * FROM sellers";
if ($search_id) {
    $sql .= " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $search_id);
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$sellers_result = $stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Gebührenverwaltung</title>
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
    <style nonce="<?php echo $nonce; ?>">
        .fee-paid {
            background-color: #d4edda; /* Light green background for paid fees */
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="dashboard.php">Assistenzfunktionen</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item active">
                    <a class="nav-link" href="acceptance.php">Korbannahme <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="pickup.php">Korbrückgabe</a>
                </li>
            </ul>
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
        <h2 class="mt-5">Gebührenverwaltung</h2>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        
        <form id="searchForm" action="acceptance.php" method="get" class="form-inline mb-3">
            <input type="text" class="form-control mr-2" name="search_id" placeholder="VerkäuferNr. suchen" value="<?php echo htmlspecialchars($search_id, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-primary">Suchen</button>
        </form>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Verk.Nr.</th>
                    <th>Nachname</th>
                    <th>Vorname</th>
                    <th>E-Mail</th>
                    <th>Gebühr bezahlt</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($sellers_result->num_rows > 0) {
                    while ($row = $sellers_result->fetch_assoc()) {
                        $row_class = $row['fee_payed'] ? 'fee-paid' : '';
                        echo "<tr class='$row_class'>
                                <td>" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "</td>
                                <td>" . htmlspecialchars($row['family_name'], ENT_QUOTES, 'UTF-8') . "</td>
                                <td>" . htmlspecialchars($row['given_name'], ENT_QUOTES, 'UTF-8') . "</td>
                                <td>" . htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') . "</td>
                                <td>" . ($row['fee_payed'] ? 'Ja' : 'Nein') . "</td>
                                <td>
                                    <form action='acceptance.php' method='post' class='d-inline'>
                                        <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') . "'>
                                        <input type='hidden' name='seller_id' value='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>
                                        <input type='hidden' name='search_id' value='" . htmlspecialchars($search_id, ENT_QUOTES, 'UTF-8') . "'>
                                        <button type='submit' class='btn btn-success' name='pay_fee' " . ($row['fee_payed'] ? 'disabled' : '') . ">Bezahlt</button>
                                        <button type='submit' class='btn btn-danger' name='revert_fee' " . (!$row['fee_payed'] ? 'disabled' : '') . ">Zurück</button>
                                    </form>
                                </td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='6'>Keine Verkäufer gefunden.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>