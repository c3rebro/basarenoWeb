<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'assistant')) {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

// Handle signature submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_signature'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    
    $seller_id = intval($_POST['seller_id']);
    $signature_data = $_POST['signature_data']; // SVG or base64 data

    // Store the signature in the database
    $stmt = $conn->prepare("UPDATE sellers SET signature=? WHERE id=?");
    $stmt->bind_param("si", $signature_data, $seller_id);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Unterschrift erfolgreich gespeichert.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Speichern der Unterschrift: " . $conn->error;
    }
}

// Handle signature removal
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_signature'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    
    $seller_id = intval($_POST['seller_id']);
    $stmt = $conn->prepare("UPDATE sellers SET signature=NULL WHERE id=?");
    $stmt->bind_param("i", $seller_id);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Unterschrift erfolgreich entfernt.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Entfernen der Unterschrift: " . $conn->error;
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
    <title>Abholung</title>
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
    <script src="js/signature_pad.min.js" nonce="<?php echo $nonce; ?>"></script>
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
                <li class="nav-item">
                    <a class="nav-link" href="acceptance.php">Korbannahme</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="pickup.php">Korbrückgabe <span class="sr-only">(current)</span></a>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-itemml ml-auto">
                    <a class="navbar-brand" href="#">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($username); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white" href="logout.php">Abmelden</a>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <h2 class="mt-5">Abholung</h2>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        
        <form id="searchForm" action="pickup.php" method="get" class="form-inline mb-3">
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
                    <th>Unterschrift vorhanden</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($sellers_result->num_rows > 0) {
                    while ($row = $sellers_result->fetch_assoc()) {
                        $signature_present = $row['signature'] ? 'Ja' : 'Nein';
                        echo "<tr>
                            <td>" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "</td>
                            <td>" . htmlspecialchars($row['family_name'], ENT_QUOTES, 'UTF-8') . "</td>
                            <td>" . htmlspecialchars($row['given_name'], ENT_QUOTES, 'UTF-8') . "</td>
                            <td>" . htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') . "</td>
                            <td>";
                        if ($row['signature']) {
                            echo "<button class='btn btn-info' data-toggle='modal' data-target='#viewSignatureModal' data-signature='" . htmlspecialchars($row['signature'], ENT_QUOTES, 'UTF-8') . "'>Anzeigen</button>";
                        } else {
                            echo "Keine Unterschrift vorhanden";
                        }
                        echo "</td>
                            <td>
                                <button class='btn btn-success' data-toggle='modal' data-target='#signatureModal' data-seller-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>Unterschrift</button>
                                <form action='pickup.php' method='post' class='d-inline'>
                                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') . "'>
                                    <input type='hidden' name='seller_id' value='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>
                                    <input type='hidden' name='search_id' value='" . htmlspecialchars($search_id, ENT_QUOTES, 'UTF-8') . "'>
                                    <button type='submit' class='btn btn-danger' name='remove_signature' " . (!$row['signature'] ? 'disabled' : '') . ">Löschen</button>
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

    <!-- Signature Modal for Drawing -->
    <div class="modal fade" id="signatureModal" tabindex="-1" role="dialog" aria-labelledby="signatureModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="signatureModalLabel">Unterschrift</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                            Bitte zählen Sie das Geld sofort nach. Eine spätere Reklamation ist nicht möglich. Mit Ihrer Unterschrift bestätigen Sie, dies verstanden zu haben und damit einverstanden zu sein.
                    </div>
                    <canvas id="signature-pad" class="signature-pad"></canvas>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                    <button type="button" class="btn btn-danger" id="clear-signature">Löschen</button>
                    <button type="button" class="btn btn-primary" id="save-signature">Speichern</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal to View Existing Signature -->
    <div class="modal fade" id="viewSignatureModal" tabindex="-1" role="dialog" aria-labelledby="viewSignatureModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewSignatureModalLabel">Unterschrift anzeigen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <img class="signature-pad" id="signature-image" src="" alt="Signature">
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script nonce="<?php echo $nonce; ?>">
        var signaturePad;
        var currentSellerId;

        $(document).ready(function() {
            var canvas = document.getElementById('signature-pad');
            signaturePad = new SignaturePad(canvas);

            $('#signatureModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                currentSellerId = button.data('seller-id');
                signaturePad.clear();
            });

            $('#clear-signature').click(function() {
                signaturePad.clear();
            });

            $('#save-signature').click(function() {
                if (signaturePad.isEmpty()) {
                    alert('Bitte unterschreiben Sie im Feld.');
                    return;
                }

                var signatureData = signaturePad.toDataURL('image/svg+xml');
                $.post('pickup.php', {
                    submit_signature: true,
                    csrf_token: '<?php echo $csrf_token; ?>',
                    seller_id: currentSellerId,
                    signature_data: signatureData,
                    search_id: '<?php echo htmlspecialchars($search_id, ENT_QUOTES, 'UTF-8'); ?>'
                }, function(response) {
                    location.reload();
                });
            });

            $('#viewSignatureModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var signature = button.data('signature');
                $('#signature-image').attr('src', signature);
            });
        });
    </script>
</body>
</html>