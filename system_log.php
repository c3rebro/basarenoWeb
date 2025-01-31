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

// Ensure the user is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Delete log entries older than 2 years
$stmt = $conn->prepare("DELETE FROM logs WHERE timestamp < NOW() - INTERVAL 2 YEAR");
$stmt->execute();

// Fetch log entries with usernames
$query = "
    SELECT logs.*, users.username 
    FROM logs 
    LEFT JOIN users ON logs.user_id = users.id 
    ORDER BY logs.timestamp DESC";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$logs = $result->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Systemprotokoll</title>
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
	
</head>
<body>
	<!-- Navbar -->
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
	
	
    <div class="container">
        <h1 class="text-center mb-4 headline-responsive">Protokolle</h1>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        <div class="filter-sort-group">
            <div class="form-row">
                <div class="col-md-4 mb-3">
                    <label for="filterAction">Filtern nach Aktion:</label>
                    <input type="text" class="form-control" id="filterAction" placeholder="Aktion eingeben">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="sortOptions">Sortieren nach:</label>
                    <select class="form-control" id="sortOptions">
                        <option value="timestamp_desc">Zeitstempel: Neueste zuerst</option>
                        <option value="timestamp_asc">Zeitstempel: Älteste zuerst</option>
                        <option value="username_asc">Benutzername: Aufsteigend</option>
                        <option value="username_desc">Benutzername: Absteigend</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="groupOptions">Gruppieren nach:</label>
                    <select class="form-control" id="groupOptions">
                        <option value="none">Keine Gruppierung</option>
                        <option value="week">Woche</option>
                        <option value="month">Monat</option>
                        <option value="year">Jahr</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary" id="applyFiltersButton">Anwenden</button>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="logsTable">
                <thead class="thead-dark">
                    <tr>
                        <th>Zeitstempel</th>
                        <th>Benutzername</th>
                        <th>Aktion</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                            $username = $log['username'] ?? 'Guest'; // Use 'Guest' if username is null
                            $details = $log['details'] ?? 'No details available'; // Provide a default value for details
                    ?>
                            <tr data-timestamp="<?php echo strtotime($log['timestamp']); ?>">
                                    <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                    <td><?php echo htmlspecialchars($username); ?></td>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td><?php echo htmlspecialchars($details); ?></td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
	
	<!-- Back to Top Button -->
	<div id="back-to-top"><i class="fas fa-arrow-up"></i></div>
	
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
            document.addEventListener('DOMContentLoaded', function() {
                function applyFilters() {
                    var filterAction = $('#filterAction').val().toLowerCase();
                    var sortOption = $('#sortOptions').val();
                    var groupOption = $('#groupOptions').val();
                    var rows = $('#logsTable tbody tr').get();

                    // Filter rows
                    rows.forEach(function(row) {
                        var action = $(row).find('td:eq(2)').text().toLowerCase();
                        $(row).toggle(action.includes(filterAction));
                    });

                    // Sort rows
                    rows.sort(function(a, b) {
                        var valA, valB;
                        switch (sortOption) {
                            case 'timestamp_asc':
                                valA = new Date($(a).find('td:eq(0)').text());
                                valB = new Date($(b).find('td:eq(0)').text());
                                return valA - valB;
                            case 'timestamp_desc':
                                valA = new Date($(a).find('td:eq(0)').text());
                                valB = new Date($(b).find('td:eq(0)').text());
                                return valB - valA;
                            case 'username_asc':
                                valA = $(a).find('td:eq(1)').text().toLowerCase();
                                valB = $(b).find('td:eq(1)').text().toLowerCase();
                                return valA.localeCompare(valB);
                            case 'username_desc':
                                valA = $(a).find('td:eq(1)').text().toLowerCase();
                                valB = $(b).find('td:eq(1)').text().toLowerCase();
                                return valB.localeCompare(valA);
                        }
                    });

                    $.each(rows, function(index, row) {
                        $('#logsTable tbody').append(row);
                    });

                    // Group rows
                    $('#logsTable tbody').empty(); // Clear previous group headers
                    if (groupOption !== 'none') {
                        var groupedRows = {};
                        rows.forEach(function(row) {
                            var timestamp = $(row).data('timestamp');
                            // Convert Unix timestamp (seconds) to milliseconds
                            var date = new Date(timestamp * 1000);
                            if (isNaN(date)) {
                                console.error("Invalid Date:", timestamp);
                                return;
                            }

                            var key;
                            switch (groupOption) {
                                case 'week':
                                    key = getISOWeek(date);
                                    break;
                                case 'month':
                                    key = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
                                    break;
                                case 'year':
                                    key = date.getFullYear();
                                    break;
                            }
                            if (!groupedRows[key]) {
                                groupedRows[key] = [];
                            }
                            groupedRows[key].push(row);
                        });

                        $.each(groupedRows, function(key, group) {
                            var groupHeader = $('<tr class="group-header"><td colspan="4">' + key + '</td></tr>');
                            groupHeader.click(function() {
                                $(this).nextUntil('.group-header').toggle();
                            });
                            $('#logsTable tbody').append(groupHeader);
                            group.forEach(function(row) {
                                $(row).addClass('hidden');
                                $('#logsTable tbody').append(row);
                            });
                        });
                    } else {
                        rows.forEach(function(row) {
                            $('#logsTable tbody').append(row);
                        });
                    }
                }

                function getISOWeek(date) {
                    var target = new Date(date.valueOf());
                    var dayNr = (date.getDay() + 6) % 7;
                    target.setDate(target.getDate() - dayNr + 3);
                    var firstThursday = target.valueOf();
                    target.setMonth(0, 1);
                    if (target.getDay() !== 4) {
                        target.setMonth(0, 1 + ((4 - target.getDay()) + 7) % 7);
                    }
                    var weekNumber = 1 + Math.ceil((firstThursday - target) / 604800000);
                    return date.getFullYear() + '-W' + String(weekNumber).padStart(2, '0');
                }

                // Add event listener for "Anwenden" button
                document.getElementById('applyFiltersButton').addEventListener('click', applyFilters);

                // Apply default filters on page load
                applyFilters();
            });
        </script>
	
	<script nonce="<?php echo $nonce; ?>">
        $(document).ready(function() {
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