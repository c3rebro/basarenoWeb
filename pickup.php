<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
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
$message_type = 'danger';

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

// Handle AJAX search requests
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'GET') {
	if (filter_input(INPUT_GET, 'search_term') !== null && strlen(trim($_GET['search_term'])) >= 3) {
		header('Content-Type: application/json');
		$search_term = '%' . trim($_GET['search_term']) . '%';
		$sql = "
			SELECT 
				s.seller_number,
				s.signature,
				ud.family_name,
				ud.given_name,
				ud.email
			FROM 
				sellers s
			INNER JOIN 
				user_details ud
			ON 
				s.user_id = ud.user_id
			WHERE 
				s.seller_number LIKE ? OR ud.family_name LIKE ? OR ud.given_name LIKE ?";
		
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("sss", $search_term, $search_term, $search_term);
		$stmt->execute();
		$result = $stmt->get_result();

		$data = [];
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}

		echo json_encode($data);
		
		exit;
	} else {
		//echo json_encode([]); // Return an empty dataset if search term is invalid
	}
}

// Handle signature submission
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['submit_signature']) && $input['submit_signature'] === true) {
		header('Content-Type: application/json');
        $seller_number = intval($input['seller_number']);
        $signature_data = $input['signature_data'];
        $csrf_token = $input['csrf_token'];

        if (!validate_csrf_token($csrf_token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE sellers SET signature = ? WHERE seller_number = ?");
        $stmt->bind_param("si", $signature_data, $seller_number);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Unterschrift erfolgreich gespeichert.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern der Unterschrift: ' . $conn->error]);
        }
        exit;
    }
}


// Handle signature removal
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'remove_signature') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }
	header('Content-Type: application/json');
	
    $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT);
    $stmt = $conn->prepare("UPDATE sellers SET signature=NULL WHERE seller_number=?");
    $stmt->bind_param("i", $seller_number);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Unterschrift erfolgreich entfernt.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Entfernen der Unterschrift: " . $conn->error;
    }
}

// Retrieve all sellers
$search_term = $_GET['search_id'] ?? ($_POST['search_id'] ?? '');
$sql = "
    SELECT 
        s.seller_number,
        s.signature,
        ud.family_name,
        ud.given_name,
        ud.email
    FROM 
        sellers s
    INNER JOIN 
        user_details ud
    ON 
        s.user_id = ud.user_id";

if ($search_term) {
    $sql .= " WHERE s.seller_number LIKE ?";
    $stmt = $conn->prepare($sql);
    $search_like = "%" . $search_term . "%";
    $stmt->bind_param("s", $search_like);
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
    <title>Korbrückgabe</title>
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
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
	
    
    <div class="container">
    <h2 class="mt-5">Abholung</h2>

    <!-- Search Form -->
    <form id="searchForm" class="form-inline mb-3">
        <input type="text" id="searchInput" class="form-control mr-2" placeholder="Bitte einen Suchbegriff eingeben">
    </form>

    <!-- Placeholder Message -->
    <div id="placeholder" class="alert alert-info" role="alert">
        Bitte einen Suchbegriff eingeben.
    </div>

    <!-- Results Table -->
    <table id="resultsTable" class="table table-bordered table-responsive hidden">
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
        <tbody></tbody>
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
$(document).ready(function () {
    const searchInput = $('#searchInput');
    const placeholder = $('#placeholder');
    const resultsTable = $('#resultsTable');
    const resultsBody = resultsTable.find('tbody');
    const canvas = document.getElementById('signature-pad');
    const signaturePad = canvas ? new SignaturePad(canvas) : null;
    let currentSellerNumber = null;

    // Resize the canvas
    function resizeCanvas() {
        if (!canvas) return;
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        signaturePad.clear();
    }
    $(window).on('resize', resizeCanvas);
    resizeCanvas();

    // Open signature modal and set currentSellerNumber
    $(document).on('click', 'button[data-target="#signatureModal"]', function () {
        currentSellerNumber = $(this).data('seller-number');
        console.log('Seller number set to:', currentSellerNumber); // Debugging log
        if (signaturePad) signaturePad.clear();
    });

    // Clear signature
    $('#clear-signature').on('click', function () {
        if (signaturePad) signaturePad.clear();
    });

    // Save signature
    $('#save-signature').on('click', function () {
        if (!currentSellerNumber) {
            alert('Kein Verkäufer ausgewählt.');
            return;
        }

        if (signaturePad.isEmpty()) {
            alert('Bitte unterschreiben Sie im Feld.');
            return;
        }

        const signatureData = signaturePad.toDataURL('image/png');
        $.ajax({
            url: 'pickup.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                submit_signature: true,
                csrf_token: '<?php echo $csrf_token; ?>',
                seller_number: currentSellerNumber,
                signature_data: signatureData
            }),
            success: function (response) {
                if (response.success) {
                    alert('Unterschrift erfolgreich gespeichert.');
                    location.reload();
                } else {
                    alert('Fehler: ' + response.message);
                }
            },
            error: function (xhr, status, error) {
                console.error('Error saving signature:', error);
                alert('Es ist ein Fehler beim Speichern der Unterschrift aufgetreten.');
            }
        });
    });

    // View signature modal
    $(document).on('click', 'button[data-target="#viewSignatureModal"]', function () {
        const signature = $(this).data('signature');
        $('#signature-image').attr('src', signature);
    });

    // Live search logic
    searchInput.on('input', function () {
        const query = $(this).val().trim();
        if (query.length >= 3) {
            fetchResults(query);
        } else {
            placeholder.text('Bitte mindestens 3 Zeichen eingeben.').show();
            resultsTable.hide();
        }
    });

    // Fetch and display results
    function fetchResults(query) {
        $.getJSON('pickup.php', { search_term: query }, function (data) {
            resultsBody.empty(); // Clear previous results
            if (data.length === 0) {
                placeholder.text('Keine Ergebnisse gefunden.').show();
                resultsTable.hide();
            } else {
                placeholder.hide();
                resultsTable.show();
                data.forEach(function (row) {
                    const tr = $(`
                        <tr>
                            <td>${row.seller_number}</td>
                            <td>${row.family_name}</td>
                            <td>${row.given_name}</td>
                            <td>${row.email}</td>
                            <td>${row.signature ? 'Ja' : 'Nein'}</td>
                            <td class="text-center">
                                <button class="btn btn-success" data-toggle="modal" data-target="#signatureModal" 
                                        data-seller-number="${row.seller_number}">Unterschrift</button>
                                <button class="btn btn-info" data-toggle="modal" data-target="#viewSignatureModal" 
                                        data-signature="${row.signature}">Anzeigen</button>
                            </td>
                        </tr>
                    `);
                    resultsBody.append(tr);
                });
            }
        }).fail(function (xhr, status, error) {
            console.error('Error fetching results:', error);
        });
    }
});
</script>


</body>
</html>