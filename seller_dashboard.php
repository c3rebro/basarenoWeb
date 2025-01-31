<?php
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require_once 'utilities.php';

// Content Security Policy with Nonce
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' data:; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self';");

// Redirect without valid login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['seller', 'assistant', 'cashier', 'admin'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$conn = get_db_connection();

// Verify
$sql = "SELECT user_verified FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_verified = $stmt->get_result()->fetch_assoc()['user_verified'];

if (!$user_verified) {
    header("Location: index.php");
    exit;
}

$status = $_GET['status'] ?? null;
$error = $_GET['error'] ?? null;

// Fetch upcoming bazaars with available slots for sellers
// Includes:
// - Bazaar ID, start date, and maximum sellers allowed
// - Current number of verified sellers for the bazaar
$sql = "SELECT 
            b.id AS bazaar_id,                          -- Bazaar ID
            b.startDate,                                -- Start date of the bazaar
            b.startReqDate,                             -- Start date of the Registration
            b.max_sellers,                              -- Maximum sellers allowed
            b.max_products_per_seller,                  -- Maximum products per seller
			b.brokerage,								-- Bazaar brokerage
            (SELECT COUNT(*) 
             FROM sellers s 
             WHERE s.bazaar_id = b.id AND s.seller_verified = 1) AS current_sellers -- Number of verified sellers
        FROM bazaar b
        WHERE b.startDate > CURDATE()                  -- Only fetch bazaars starting in the future
			AND (SELECT COUNT(*) 
			FROM sellers s 
			WHERE s.bazaar_id = b.id AND s.seller_verified = 1) < b.max_sellers -- Bazaar must have available slots
		ORDER BY b.startDate ASC						-- Order by the nearest start date
		LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->execute();
$upcoming_bazaar = $stmt->get_result()->fetch_assoc();
$max_products_per_seller = $upcoming_bazaar['max_products_per_seller'] ?? null;

// Fetch all seller numbers associated with the user
$sql = "SELECT seller_number, seller_verified FROM sellers WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$sellers_result = $stmt->get_result();
$sellers = $sellers_result->fetch_all(MYSQLI_ASSOC);

// Extract seller numbers for the next query
$seller_numbers = array_column($sellers, 'seller_number');

if (!empty($seller_numbers)) {
    $placeholders = implode(',', array_fill(0, count($seller_numbers), '?')); // Create `?, ?, ?` placeholders for IN()
    
    // Fetch all products associated with these seller numbers
    $sql = "SELECT seller_number, name, size, price, in_stock, sold 
            FROM products 
            WHERE seller_number IN ($placeholders)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($seller_numbers)), ...$seller_numbers);
    $stmt->execute();
    $products_result = $stmt->get_result();
    $products = $products_result->fetch_all(MYSQLI_ASSOC);
} else {
    $products = [];
}

// Verkäufernummer anfordern
if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'Aha erwischt. CSRF token mismatch.']);
        exit;
    }

    $user_id = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? '';
    $bazaar_id = $_POST['bazaar_id'] ?? null;
    $helper_request = filter_input(INPUT_POST, 'helper_request') !== null && $_POST['helper_request'] == '1';
    $helper_message = htmlspecialchars($_POST['helper_message'] ?? '', ENT_QUOTES, 'UTF-8');
	$helper_options = filter_input(INPUT_POST, 'helper_options') !== null ? htmlspecialchars($_POST['helper_options'], ENT_QUOTES, 'UTF-8') : 'Keine Auswahl';

    $conn = get_db_connection();

    if (!$bazaar_id) {
        echo json_encode(['success' => false, 'message' => 'Kein Basar gefunden.']);
        exit;
    }

    // Check if the user already has a seller number
    $sql = "SELECT seller_number FROM sellers WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc() && !$helper_request) {
        echo json_encode(['success' => false, 'message' => 'Du hast schon eine aktive Verkäufernummer. Möchtest Du Dich als Helfer registrieren und eine 2. Nummer anfragen?', 'require_helper_confirmation' => true]);        
        //echo json_encode(['success' => false, 'message' => 'Du hast schon eine aktive Verkäufernummer.']);
        exit;
    }

    // Check for unassigned seller number
    $sql = "SELECT seller_number FROM sellers WHERE user_id = 0 ORDER BY seller_number ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $unassigned_number = $stmt->get_result()->fetch_assoc();

	if(!$helper_request) {
		if ($unassigned_number) {
			// Assign unassigned seller number
			$seller_number = $unassigned_number['seller_number'];
			$sql = "UPDATE sellers SET user_id = ?, bazaar_id = ?, seller_verified = 1 WHERE seller_number = ?";
			$stmt = $conn->prepare($sql);
			$stmt->bind_param("iii", $user_id, $bazaar_id, $seller_number);
		} else {
			// Create a new seller number
			$sql = "SELECT MAX(seller_number) + 1 AS next_number FROM sellers";
			$stmt = $conn->prepare($sql);
			$stmt->execute();
			$next_number = $stmt->get_result()->fetch_assoc()['next_number'] ?? 100;

			$sql = "SELECT MAX(checkout_id) + 1 AS next_checkout_id FROM sellers";
			$stmt = $conn->prepare($sql);
			$stmt->execute();
			$next_checkout_id = $stmt->get_result()->fetch_assoc()['next_checkout_id'] ?? 1;

			$seller_number = $next_number;
			$sql = "INSERT INTO sellers (user_id, bazaar_id, seller_number, checkout_id, seller_verified) VALUES (?, ?, ?, ?, 1)";
			$stmt = $conn->prepare($sql);
			$stmt->bind_param("iiii", $user_id, $bazaar_id, $seller_number, $next_checkout_id);
		}
	}

	if($helper_request) {
		$canContinue = true;
	} else {
		$canContinue = $stmt->execute();
	}
    if ($canContinue) {
        // Send email if helper request is checked
        if ($helper_request) {
            $to = "borga@basar-horrheim.de";
            $subject = "Neuer Zweitnummerantrag / Helferanfrage";
            $body = "Ein Nutzer (" . htmlspecialchars($username) . ") hat eine Verkäufernummer mit Zusatznummer beantragt.<br>";
            $body .= "Verkäufernummer: $seller_number<br>";
            $body .= "Benutzer-ID: $user_id<br>";
            $body .= "Bazaar-ID: $bazaar_id<br>";
            $body .= "Helfer-Anfrage: Ja<br>";
			$body .= "Ausgewählte Helferoptionen: " . nl2br($helper_options) . "<br>";
            $body .= "Nachricht: " . nl2br($helper_message) . "<br>";
            send_email($to, $subject, $body);
        }

        // Fetch updated seller numbers
        $sql = "SELECT seller_number, seller_verified FROM sellers WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $updated_sellers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

		if($helper_request) {
			echo json_encode([
            'success' => true,
            'message' => 'Zusätzliche Nummer erfolreich beantragt. Wir informieren Dich per Mail.',
            'data' => $updated_sellers,
        ]);
		} else {
			echo json_encode([
            'success' => true,
            'message' => 'Verkäufernummer erfolreich zugewiesen. Im Menü unter "Meine Artikel" gehts weiter...',
            'data' => $updated_sellers,
        ]);
		}
        
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Verkäufernummer konnte nicht zugewiesen werden. Bitte informiere uns per Mail.']);
        exit;
    }
}

?>


<!DOCTYPE html>
<html lang="de">
    <head>
        <style nonce="<?php echo $nonce; ?>">
            html {
                visibility: hidden;
            }
        </style>
        <meta charset="UTF-8">
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title>Verkäufer-Dashboard</title>
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
        <script nonce="<?php echo $nonce; ?>">
            const upcomingBazaar = <?php echo json_encode($upcoming_bazaar ?? null); ?>;
			const sellers = <?php echo json_encode($sellers); ?>;
			const products = <?php echo json_encode($products); ?>;
        </script>

    </head>
    <body>
	
		<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->

        <div class="container">
            <h1>Verkäufer-Dashboard</h1>
            <hr/>
            <h6 class="card-subtitle mb-4 text-muted">
                Verwalte hier Deine Verkäufernummern. Du kannst eine neue anfordern, vorhande freischalten (beim nächsten Basar) oder sie zurückgeben wenn Du nicht mehr teilnehmen möchtest.
            </h6>
            <!-- Seller Number Section -->
            <div class="card mb-4">
                <div class="card-header">Deine Verkäufernummer(n) verwalten</div>
                <div class="card-body">
                    <form method="POST" id="seller-number-actions">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                        <!-- Dropdown to select a seller number -->
                        <div class="form-group">
                            <label for="sellerNumberSelect">Wähle eine Verkäufernummer:</label>
                            <select class="form-control mb-2" id="sellerNumberSelect" name="selected_seller_number">
                                <option value="" disabled selected>Nicht verfügbar</option>
                            </select>
                        </div>

                        <!-- Dropdown for actions -->
                        <div class="dropdown mb-2">
                            <select class="form-control" name="action">
                                <option value="" selected disabled>Bitte wählen</option>
                                <option value="validate">freischalten</option>
                                <option value="revoke" class="bg-danger text-light">Nummer zurückgeben</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col">
                                <button type="submit" class="btn btn-primary w-100 mt-3">Ausführen</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>


            <!-- Available Bazaars Section -->
            <div class="card mb-4">
                <div class="card-header">Verfügbarer Basar</div>
                <div class="card-body">
                    <?php if (!empty($upcoming_bazaar)): ?>
                        <div class="table-responsive-sm">
                            <table class="table table-bordered table-striped w-100">
                                <thead>
                                    <tr>
                                        <th>Datum Basar</th>
                                        <th>Start Nr. Vergabe</th>
                                        <th>Maximale Verkäufer</th>
                                        <th>Aktuelle Verkäufer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo htmlspecialchars(DateTime::createFromFormat('Y-m-d', $upcoming_bazaar['startDate'])->format('d.m.Y')); ?></td>
                                        <td><?php echo htmlspecialchars(DateTime::createFromFormat('Y-m-d', $upcoming_bazaar['startReqDate'])->format('d.m.Y')); ?></td>
                                        <td><?php echo htmlspecialchars($upcoming_bazaar['max_sellers']); ?></td>
                                        <td><?php echo htmlspecialchars($upcoming_bazaar['current_sellers']); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <form method="post" id="requestSellerNumberForm">
                            <input type="hidden" name="bazaar_id" value="<?php echo htmlspecialchars($upcoming_bazaar['bazaar_id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="request_seller_number" value="1">
                            
                            <!-- Helper Request Checkbox -->
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="helperRequest" name="helper_request">
                                <label class="form-check-label" for="helperRequest">
                                    Ich möchte mich als Helfer*In eintragen lassen und eine zusätzliche Nummer erhalten.
                                </label>
                            </div>

                            <!-- Helper Options -->
                            <div class="form-group mt-3 hidden" id="helperOptionsContainer">
                                <p class="text-muted">
                                    Wir bitten um Verständnis dafür, dass wir unseren Teilnehmenden, die noch keine Nummer haben, bevorzugt Nummern vergeben werden.
                                </p>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="option1" name="helper_options[]" value="Ich bringe Kuchen mit">
                                    <label class="form-check-label" for="option1">Ich bringe Kuchen</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="option2" name="helper_options[]" value="Ich helfe beim Rücksortieren">
                                    <label class="form-check-label" for="option2">Ich helfe beim Rücksortieren (15:45Uhr - 18:00Uhr)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="option3" name="helper_options[]" value="Ich helfe bei der Aufsicht">
                                    <label class="form-check-label" for="option3">Ich helfe bei der Aufsicht (12:45Uhr - 15:30Uhr)</label>
                                </div>
                            </div>

                            <!-- Optional Message Textarea -->
                            <div class="form-group mt-3 hidden" id="helperMessageContainer">
                                <label for="helperMessage">Das finden wir super! 😊</label>
                                <textarea class="form-control" id="helperMessage" name="helper_message" rows="3" placeholder="Optional: Beschreibe kurz, wie Du uns unterstützen möchtest."></textarea>
                            </div>

                            <p id="sellerRequestInfoMessage" class="hidden text-muted">
                                Die Verkäufernummeranfrage ist geschlossen. Wir freuen uns darauf, Dich beim nächsten Basar wieder zu sehen.
                            </p>
                            <!-- Submit Button -->
                            <div class="row">
                                <div class="col">
                                    <button type="submit" class="btn btn-primary w-100 mt-3">
                                        Verkäufernummer anfordern
                                    </button>
                                </div>
                            </div>
                        </form>

                    <?php else: ?>
                        <p>Keine Basare derzeit verfügbar.</p>
                    <?php endif; ?>
                    <span id="stockSection"/>
                </div>
            </div>

            <!-- Per Seller Number Overview -->
            <div id="perSellerNumberOverview">
                <p>Keine Verkäufernummern vorhanden.</p>
            </div>

        </div>

        
        <!-- Helper Confirmation Modal -->
        <div class="modal fade" id="helperConfirmationModal" tabindex="-1" role="dialog" aria-labelledby="helperConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-white">
                        <h5 class="modal-title" id="helperConfirmationModalLabel">Helferanfrage bestätigen</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p>Du hast schon eine aktive Verkäufernummer. Möchtest Du Dich als Helfer registrieren und eine 2. Nummer anfragen?</p>
                        <div id="helperOptionsContainer">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="option1" name="helper_options[]" value="Ich bringe Kuchen mit">
                                <label class="form-check-label" for="option1">Ich bringe Kuchen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="option2" name="helper_options[]" value="Ich helfe beim Rücksortieren">
                                <label class="form-check-label" for="option2">Ich helfe beim Rücksortieren (15:45Uhr - 18:00Uhr)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="option3" name="helper_options[]" value="Ich helfe bei der Aufsicht">
                                <label class="form-check-label" for="option3">Ich helfe bei der Aufsicht (12:45Uhr - 15:30Uhr)</label>
                            </div>
                        </div>
                        <div id="helperMessageContainer" class="form-group mt-3">
                            <label for="helperMessage">Optional: Beschreibe kurz, wie Du uns unterstützen möchtest.</label>
                            <textarea class="form-control" id="helperMessage" rows="3" placeholder="Optional"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                        <button type="button" class="btn btn-danger" id="confirmHelperRequestButton">Bestätigen</button>
                    </div>
                </div>
            </div>
        </div>

        
        <!-- Delete Sellernumber Confirmation Modal -->
        <div class="modal fade" id="confirmRevokeModal" tabindex="-1" role="dialog" aria-labelledby="confirmRevokeModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-white">
                        <h5 class="modal-title" id="confirmRevokeModalLabel">Verkäufernummer zurückgeben</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p>Bist du sicher, dass du diese Verkäufernummer zurückgeben möchtest? Diese Aktion kann nicht rückgängig gemacht werden, und alle mit dieser Nummer verknüpften Artikel werden gelöscht.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Abbrechen</button>
                        <button type="button" class="btn btn-danger" id="confirmRevokeButton">Bestätigen</button>
                    </div>
                </div>
            </div>
        </div>
                  
        <!-- Toast Container -->
        <div aria-live="polite" aria-atomic="true">
            <!-- Toasts will be dynamically added here -->
            <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3">
                <!-- Toasts will be dynamically added here -->
            </div>
        </div>

		<!-- Back to Top Button -->
		<div id="back-to-top"><i class="fas fa-arrow-up"></i></div>

        <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
        <script src="js/utilities.js" nonce="<?php echo $nonce; ?>"></script>
        <script nonce="<?php echo $nonce; ?>">
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById('seller-number-actions');
                const confirmRevokeModal = document.getElementById('confirmRevokeModal');
                const confirmRevokeButton = document.getElementById('confirmRevokeButton');
                const sellerDropdown = document.getElementById('sellerNumberSelect');
                
                refreshSellerData(); // Refresh seller numbers and dropdown
                if (upcomingBazaar) {
                    updateRequestSellerSection(upcomingBazaar);
                } else {
                    console.error('No upcoming bazaar found');
                }
                
                let selectedAction = null;

                form.addEventListener('submit', function (e) {
                    const action = form.querySelector('select[name="action"]').value;

                    if (action === 'revoke') {
                        e.preventDefault(); // Prevent form submission
                        selectedAction = action; // Store the action
                        $(confirmRevokeModal).modal('show'); // Show the modal
                    } else if (action === 'validate') {
                        e.preventDefault(); // Prevent default form submission

                        const csrfToken = form.querySelector('input[name="csrf_token"]').value;
                        const selectedSellerNumber = form.querySelector('select[name="selected_seller_number"]').value;

                        // Perform the AJAX POST request to validate the seller number
                        $.post('validate_seller.php', {
                            action: 'validate',
                            csrf_token: csrfToken,
                            selected_seller_number: selectedSellerNumber,
							bazaar_id: upcomingBazaar.bazaar_id
                        }, function (response) {
                            if (response.success) {
                                showToast('Erfolgreich', response.message, 'success');
                                refreshSellerData(); // Refresh seller numbers and associated data
                            } else if (response.info){
                                showToast('Hinweis', response.message, 'info');
                            } else {
                                showToast('Fehler', response.message, 'danger');
                            }
                        }, 'json');
                    }
                });

                // Handle confirmation of revocation
                confirmRevokeButton.addEventListener('click', function () {
                    if (selectedAction === 'revoke') {
                        $(confirmRevokeModal).modal('hide'); // Close modal

                        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                        const selectedSellerNumber = sellerDropdown.value;

                        // Send AJAX POST request to revoke seller number
                        $.post(
                            'revoke_seller.php',
                            {
                                csrf_token: csrfToken,
                                selected_seller_number: selectedSellerNumber
                            },
                            function (response) {
                                if (response.success) {
                                    showToast('Erfolgreich', response.message, 'success');
                                    refreshSellerData(); // Refresh seller numbers and dropdown
                                } else {
                                    showToast('Fehler', response.message, 'danger');
                                }
                            },
                            'json'
                        );
                    }
                });
                
                $('#requestSellerNumberForm').on('submit', function (e) {
                    e.preventDefault(); // Prevent default form submission

                    const csrfToken = $('input[name="csrf_token"]').val();
                    const bazaarId = $('input[name="bazaar_id"]').val();
                    const helperRequest = $('#helperRequest').is(':checked') ? 1 : 0;
                    const helperMessage = $('#helperMessage').val();

                    // Validate helper request options if the checkbox is checked
                    if (helperRequest) {
                        const selectedOptions = $('input[name="helper_options[]"]:checked');
                        if (selectedOptions.length === 0) {
                            showToast(
                                'NeeNeeNee - So nich 😜',
                                'Bitte wähle mindestens eine Option aus, wenn Du Dich als Helfer eintragen möchtest.',
                                'danger',
                                5000
                            );
                            return; // Stop the form submission
                        }
                    }

                    // Send the AJAX request
                    $.post('seller_dashboard.php', {
                        csrf_token: csrfToken,
                        bazaar_id: bazaarId,
                        helper_request: helperRequest,
                        helper_message: helperMessage,
                        request_seller_number: 1
                    }, function (response) {
                        if (response.success) {
                            // Success: Show a toast and refresh seller data
                            showToast('Erfolgreich', response.message, 'success');
                            refreshSellerData();
                        } else if (response.require_helper_confirmation) {
                            // Show the modal for additional confirmation
                            $('#helperConfirmationModal').modal('show');
                        } else {
                            // Handle other errors with a toast
                            showToast('Fehler', response.message, 'danger');
                        }
                    }, 'json');
                });

                // Modal confirmation for helper request
                $('#confirmHelperRequestButton').on('click', function () {
                    const csrfToken = $('input[name="csrf_token"]').val();
                    const bazaarId = $('input[name="bazaar_id"]').val();
                    const helperMessage = $('#helperMessage').val();
                    const selectedOptions = $('#helperOptionsContainer input[name="helper_options[]"]:checked')
                        .map(function () {
                            return $(this).val();
                        })
                        .get();

                    if (selectedOptions.length === 0) {
                        showToast('Fehler', 'Bitte wähle mindestens eine Option aus, wenn Du Dich als Helfer eintragen möchtest.', 'danger');
                        return;
                    }

                    $.post('seller_dashboard.php', {
                        csrf_token: csrfToken,
                        bazaar_id: bazaarId,
                        helper_request: 1,
                        helper_message: helperMessage,
                        helper_options: selectedOptions.join(', ')
                    }, function (response) {
                        if (response.success) {
                            $('#helperConfirmationModal').modal('hide');
                            showToast('Erfolgreich', response.message, 'success');
                            refreshSellerData(); // Refresh seller data on success
                        } else {
                            showToast('Fehler', response.message, 'danger');
                        }
                    }, 'json');
                });
            });
        </script>
        <script nonce="<?php echo $nonce; ?>">
            $(document).ready(function () {
                // Attach functionality to all forms on the page
                $('form').each(function () {
                    const $form = $(this); // Wrap the current form in a jQuery object
                    const $helperRequestCheckbox = $form.find('#helperRequest');
                    const $helperOptionsContainer = $form.find('#helperOptionsContainer');
                    const $helperMessageContainer = $form.find('#helperMessageContainer');
                    const $messageInput = $form.find('#helperMessage');

                    if ($helperRequestCheckbox.length) {
                        // Show or hide helper options and message based on the checkbox state
                        $helperRequestCheckbox.on('change', function () {
                            if (this.checked) {
                                $helperOptionsContainer.removeClass('hidden');
                                $helperMessageContainer.removeClass('hidden');
                            } else {
                                $helperOptionsContainer.addClass('hidden');
                                $helperMessageContainer.addClass('hidden');
                                $messageInput.val(''); // Clear optional message
                            }
                        });
                    }
                });
            });
        </script>
        <script nonce="<?php echo $nonce; ?>">
            function openMailto(mailtoUrl) {
                window.open(mailtoUrl, '_blank');
            }
        </script>

        <style nonce="<?php echo $nonce; ?>">
            .toast {
                opacity: 0; /* Initially hidden */
                transition: opacity 0.5s ease-in-out; /* Smooth fade-in/out */
            }
        </style>

        <script nonce="<?php echo $nonce; ?>">
            // Show the HTML element once the DOM is fully loaded
            document.addEventListener("DOMContentLoaded", function () {
                document.documentElement.style.visibility = "visible";
            });
        </script>
		<script nonce="<?php echo $nonce; ?>">
			document.addEventListener("DOMContentLoaded", function () {
				// Function to toggle the visibility of the "Back to Top" button
				function toggleBackToTopButton() {
					const scrollTop = $(window).scrollTop();

					if (scrollTop > 100) {
						$('#back-to-top').fadeIn();
					} else {
						$('#back-to-top').fadeOut();
					}
				}

				// Initial check on page load
				toggleBackToTopButton();
			
		
				// Show or hide the "Back to Top" button on scroll
				$(window).scroll(function() {
					toggleBackToTopButton();
				});
			
				// Smooth scroll to top
				$('#back-to-top').click(function() {
					$('html, body').animate({ scrollTop: 0 }, 600);
					return false;
				});
			});
		</script>
    </body>
</html>



