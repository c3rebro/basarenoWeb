<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'assistant')) {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger';

// Generate CSRF token for the form
$csrf_token = generate_csrf_token();

$username = $_SESSION['username'] ?? '';

// Handle fee payment
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'pay_fee') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
            header("location: logout.php");
			exit;
    }

    $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT); // Use seller_number instead of id
    $stmt = $conn->prepare("UPDATE sellers SET fee_payed=TRUE WHERE seller_number=?");
    $stmt->bind_param("i", $seller_number);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Gebühr erfolgreich bezahlt.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Bezahlen der Gebühr: " . $conn->error;
    }
}

// Handle fee reversion
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST' && filter_input(INPUT_POST, 'revert_fee') !== null) {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        die("CSRF token validation failed.");
    }

    $seller_number = filter_input(INPUT_POST, 'seller_number', FILTER_VALIDATE_INT); // Use seller_number instead of id
    $stmt = $conn->prepare("UPDATE sellers SET fee_payed=FALSE WHERE seller_number=?");
    $stmt->bind_param("i", $seller_number);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Gebühr erfolgreich zurückgesetzt.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Zurücksetzen der Gebühr: " . $conn->error;
    }
}

// Process AJAX search requests
if ($_SERVER["REQUEST_METHOD"] === "GET" && filter_input(INPUT_GET, 'search_term') !== null) {
    $search_term = trim(filter_input(INPUT_GET, 'search_term'));

    if (strlen($search_term) >= 3) {
        $sql = "
            SELECT 
                s.seller_number,
                s.fee_payed,
				s.reserved,
                ud.family_name,
                ud.given_name,
                ud.email,
                ud.phone,
                ud.street,
                ud.house_number,
                ud.zip,
                ud.city
            FROM 
                sellers s
            INNER JOIN 
                user_details ud
            ON 
                s.user_id = ud.user_id
            WHERE 
                s.seller_number LIKE ?
                OR ud.family_name LIKE ?
                OR ud.given_name LIKE ?";

        $search_like = "%" . $search_term . "%";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $search_like, $search_like, $search_like);
        $stmt->execute();
        $result = $stmt->get_result();

        $sellers = [];
        while ($row = $result->fetch_assoc()) {
			$row['csrf_token'] = $csrf_token;
            $sellers[] = $row;
        }

        header('Content-Type: application/json');
        echo json_encode($sellers);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

$conn->close();
?>


<!DOCTYPE html>
<html lang="de">
<head>
    <style nonce="<?php echo $nonce; ?>">
        html { visibility: hidden; }
    </style>
    <meta charset="UTF-8">
	<link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Assistenzfunktionen - Korbannahme</title>
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
</head>
<body>
	<!-- Navbar -->
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
	

    <div class="container">
        <h2 class="mt-5">Korbannahme</h2>

        <form id="searchForm" class="form-inline mb-3">
            <input type="text" id="searchInput" class="form-control mr-2" placeholder="Suchen nach Name, Vorname oder Verkäufernummer">
        </form>

        <div id="placeholder" class="alert alert-info">Bitte mindestens 3 Zeichen eingeben.</div>

		<div class="table-responsive">
			<table class="table table-bordered hidden" id="resultsTable">
				<thead>
					<tr>
						<th>Verk.Nr.</th>
						<th>Nachname</th>
						<th class="d-none">Vorname</th>
						<th class="d-none">E-Mail</th>
						<th class="d-none">Telefon</th>
						<th class="d-none">Adresse</th>
						<th>Zus. Nummer</th>
						<th>Bezahlt</th>
						<th>Aktion</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
    </div>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script nonce="<?php echo $nonce; ?>">
    $(document).ready(function () {
        const searchInput = $('#searchInput'); // Search input field
        const placeholder = $('#placeholder'); // Placeholder for no results or instructions
        const resultsTable = $('#resultsTable'); // Table to show results
        const resultsBody = resultsTable.find('tbody'); // Table body for results

        // Event listener for live search
        searchInput.on('input', function () {
            const query = $(this).val().trim(); // Get the input value and trim whitespace

            if (query.length >= 3) {
                // Perform AJAX request to fetch results
                $.getJSON('acceptance.php', { search_term: query }, function (data) {
                    resultsBody.empty(); // Clear existing table rows

                    if (data.length === 0) {
                        // Show placeholder if no results are found
                        placeholder.text('Keine Ergebnisse gefunden.').show();
                        resultsTable.hide();
                    } else {
                        // Populate the table with results
                        placeholder.hide();
                        resultsTable.show();

                        data.forEach(row => {
                            const feeStatus = row.fee_payed ? 'Ja' : 'Nein';
							const helperStatus = row.reserved ? 'Ja' : 'Nein';
                            const feeButton = row.fee_payed
                                ? `<button type="submit" class="btn btn-danger" name="revert_fee">Zurück</button>`
                                : `<button type="submit" class="btn btn-success" name="pay_fee">Bezahlt</button>`;

                            resultsBody.append(`
                                <tr>
                                    <td>${row.seller_number}</td>
                                    <td>${row.family_name}</td>
                                    <td class="d-none">${row.given_name}</td>
                                    <td class="d-none">${row.email}</td>
                                    <td class="d-none">${row.phone}</td>
                                    <td class="d-none">${row.street} ${row.house_number}, ${row.zip} ${row.city}</td>
									<td>${helperStatus}</td>
                                    <td>${feeStatus}</td>
                                    <td>
                                        <form action="acceptance.php" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="${row.csrf_token}">
                                            <input type="hidden" name="seller_number" value="${row.seller_number}">
                                            ${feeButton}
                                        </form>
                                    </td>
                                </tr>
                            `);
                        });
                    }
                }).fail(function () {
                    // Handle AJAX request failure
                    placeholder.text('Fehler beim Abrufen der Daten.').show();
                    resultsTable.hide();
                });
            } else {
                // Show instruction to input at least 3 characters
                placeholder.text('Bitte mindestens 3 Zeichen eingeben.').show();
                resultsTable.hide();
            }
        });
    });
</script>
    <script nonce="<?php echo $nonce; ?>">
        // Show the HTML element once the DOM is fully loaded
        document.addEventListener("DOMContentLoaded", function () {
            document.documentElement.style.visibility = "visible";
        });
    </script>
	<script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>
