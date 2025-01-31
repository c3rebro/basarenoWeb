<?php
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require_once 'utilities.php';

// Redirect unauthorized users
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

$conn = get_db_connection();

// Fetch user counts by role
$sql = "SELECT role, COUNT(*) AS count FROM users GROUP BY role";
$stmt = $conn->prepare($sql);
$stmt->execute();
$user_counts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch current, upcoming, or recent bazaar
$sql = "SELECT * FROM bazaar 
        WHERE startDate >= CURDATE() 
        ORDER BY startDate ASC 
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute();
$current_bazaar = $stmt->get_result()->fetch_assoc();

if (!$current_bazaar) {
    $sql = "SELECT * FROM bazaar 
            WHERE startDate < CURDATE() 
            ORDER BY startDate DESC 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $current_bazaar = $stmt->get_result()->fetch_assoc();
}

// Fetch seller and product statistics
$sql = "SELECT 
            COUNT(*) AS total_sellers, 
            SUM(seller_verified) AS verified_sellers 
        FROM sellers";
$stmt = $conn->prepare($sql);
$stmt->execute();
$seller_stats = $stmt->get_result()->fetch_assoc();

$sql = "SELECT COUNT(*) AS total_products FROM products";
$stmt = $conn->prepare($sql);
$stmt->execute();
$total_products = $stmt->get_result()->fetch_assoc()['total_products'];

$conn->close();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin-Dashboard</title>
    <!-- Preload and link CSS files -->
    <link rel="preload" href="css/bootstrap.min.css" as="style" id="bootstrap-css">
    <link rel="preload" href="css/all.min.css" as="style" id="all-css">
    <link rel="preload" href="css/style.css" as="style" id="style-css">
	<style nonce="<?php echo $nonce; ?>">
        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
        }
        .dashboard-table td {
            border: 1px solid #ddd;
            width: 120px;
            height: 120px;
            padding: 0;
        }
        .dashboard-table .btn {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            text-align: center;
        }
    </style>
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
		<div class="jumbotron text-center">
			<h1 class="mb-4">Administrator Dashboard</h1>
            <p class="lead">Nutzen Sie die untenstehenden Optionen, um fortzufahren.</p>
            <hr class="my-4">
            <table class="dashboard-table mx-auto">
                <tr>
                    <td colspan="2">
                        <a class="btn btn-secondary btn-lg" href="index.php" role="button">Startseite anzeigen</a>
                    </td>
                </tr>
                <tr>
                    <td>
                        <a class="btn btn-success btn-lg" href="acceptance.php" role="button">Annehmen</a>
                    </td>
                    <td>
                        <a class="btn btn-warning btn-lg" href="cashier.php" role="button">Scannen</a>
                    </td>
                </tr>
                <tr>
					<td>
                        <a class="btn btn-primary btn-lg" href="admin_manage_sellers.php" role="button">Abrechnen</a>
                    </td>
                    <td>
                        <a class="btn btn-danger btn-lg" href="pickup.php" role="button">Korbrückgabe</a>
                    </td>
                </tr>
            </table>
        </div>
        <!-- User Management Section -->
        <div class="card mb-4">
            <div class="card-header">Benutzerverwaltung</div>
            <div class="card-body">
                <ul>
                    <?php foreach ($user_counts as $user_count): ?>
                        <li><?php echo ucfirst($user_count['role']); ?>: <?php echo $user_count['count']; ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="admin_manage_users.php" class="btn btn-primary">Benutzer verwalten</a>
            </div>
        </div>

        <!-- Bazaar Management Section -->
        <div class="card mb-4">
            <div class="card-header">Basarverwaltung</div>
            <div class="card-body">
                <?php if ($current_bazaar): ?>
                    <p><strong>Aktueller Basar:</strong></p>
                    <ul>
                        <li>Startdatum: <?php echo htmlspecialchars($current_bazaar['startDate']); ?></li>
                        <li>Maximale Verkäufer: <?php echo htmlspecialchars($current_bazaar['max_sellers']); ?></li>
                        <li>Maximale Artikel pro Verkäufer: <?php echo htmlspecialchars($current_bazaar['max_products_per_seller']); ?></li>
                    </ul>
                <?php else: ?>
                    <p>Kein aktueller oder kommender Basar verfügbar.</p>
                <?php endif; ?>
                <a href="admin_manage_bazaar.php" class="btn btn-primary">Basare verwalten</a>
            </div>
        </div>

        <!-- Seller Management Section -->
        <div class="card">
            <div class="card-header">Verkäuferverwaltung</div>
            <div class="card-body">
                <p>Gesamtanzahl der Verkäufer: <?php echo htmlspecialchars($seller_stats['total_sellers']); ?></p>
                <p>Anzahl der freigeschalteten Verkäufer: <?php echo htmlspecialchars($seller_stats['verified_sellers']); ?></p>
                <p>Gesamtanzahl der Produkte: <?php echo htmlspecialchars($total_products); ?></p>
                <a href="admin_manage_sellers.php" class="btn btn-primary">Verkäufer verwalten</a>
            </div>
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
	<!-- Back to Top Button -->
    <div id="back-to-top"><i class="fas fa-arrow-up"></i></div>
	
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
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
	<script nonce="<?php echo $nonce; ?>">
		// Show the HTML element once the DOM is fully loaded
		document.addEventListener("DOMContentLoaded", function () {
			document.documentElement.style.visibility = "visible";
		});
	</script>
</body>
</html>
