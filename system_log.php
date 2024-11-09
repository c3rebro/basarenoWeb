<?php
session_start();
require_once 'utilities.php';

// Ensure the user is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: admin_login.php");
    exit;
}

$conn = get_db_connection();
initialize_database($conn);

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
    <link href="css/bootstrap.min.css" rel="stylesheet">
	<link href="css/all.min.css" rel="stylesheet">
	<link href="css/style.css" rel="stylesheet">
	
</head>
<body>
	<!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="#">Bazaar Administration</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_users.php">Benutzer verwalten</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_bazaar.php">Bazaar verwalten</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_sellers.php">Verkäufer verwalten <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="system_settings.php">Systemeinstellungen</a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="system_log.php">Protokolle</a>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white" href="logout.php">Abmelden</a>
                </li>
            </ul>
        </div>
    </nav>
	
    <div class="container">
        <h1 class="mb-4">Systemprotokoll</h1>
        
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
            <button class="btn btn-primary" onclick="applyFilters()">Anwenden</button>
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
					?>
						<tr data-timestamp="<?php echo strtotime($log['timestamp']); ?>">
							<td><?php echo htmlspecialchars($log['timestamp']); ?></td>
							<td><?php echo htmlspecialchars($username); ?></td>
							<td><?php echo htmlspecialchars($log['action']); ?></td>
							<td><?php echo htmlspecialchars($log['details']); ?></td>
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
	
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
	<script>
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

		$(document).ready(function() {
			applyFilters(); // Apply default filters on page load
		});
	</script>
	
	<script>
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