<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

require_once 'utilities.php';

$conn = get_db_connection();
initialize_database($conn);

$error = '';
$success = '';

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;

// Handle user addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (empty($username) || empty($password) || empty($role)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $password_hash, $role);
        if ($stmt->execute()) {
            $success = "Benutzer erfolgreich hinzugefügt.";
        } else {
            $error = "Fehler beim Hinzufügen des Benutzers: " . $conn->error;
        }
    }
}

// Handle user deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $success = "Benutzer erfolgreich gelöscht.";
    } else {
        $error = "Fehler beim Löschen des Benutzers: " . $conn->error;
    }
}

// Fetch users
$sql = "SELECT * FROM users";
$result = $conn->query($sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Benutzer Verwalten</title>
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
                <li class="nav-item active">
                    <a class="nav-link" href="admin_manage_users.php">Benutzer verwalten <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_bazaar.php">Bazaar verwalten</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_manage_sellers.php">Verkäufer verwalten</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="system_settings.php">Systemeinstellungen</a>
                </li>
                <li class="nav-item">
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
        <h2 class="mt-5">Benutzer Verwalten</h2>
        <?php if ($error) { echo "<div class='alert alert-danger'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>"; } ?>
        <?php if ($success) { echo "<div class='alert alert-success'>" . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . "</div>"; } ?>

        <h3 class="mt-5">Benutzer hinzufügen</h3>
        <form action="admin_manage_users.php" method="post">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="username">Benutzername:</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="password">Passwort:</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="role">Rolle:</label>
                    <select class="form-control" id="role" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="cashier">Kassierer</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="add_user">Benutzer hinzufügen</button>
        </form>

        <h3 class="mt-5">Benutzerliste</h3>
        <div class="table-responsive">
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Benutzername</th>
                        <th>Rolle</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <form action="admin_manage_users.php" method="post" style="display:inline-block">
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" name="delete_user" class="btn btn-danger btn-sm">Löschen</button>
                            </form>
                        </td>
                    </tr>
                    <?php } ?>
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