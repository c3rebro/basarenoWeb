<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

require_once 'utilities.php';

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Handle user addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (empty($username) || empty($password) || empty($role)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $password_hash, $role);
        if ($stmt->execute()) {
            $message_type = 'success';
            $message = "Benutzer erfolgreich hinzugefügt.";
        } else {
            $message_type = 'danger';
            $message = "Fehler beim Hinzufügen des Benutzers: " . $conn->error;
        }
    }
}

// Handle user deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $message_type = 'success';
        $message = "Benutzer erfolgreich gelöscht.";
    } else {
        $message_type = 'danger';
        $message = "Fehler beim Löschen des Benutzers: " . $conn->error;
    }
}

// Handle password update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $stmt->bind_param("si", $password_hash, $user_id);
    if ($stmt->execute()) {
        // Return a JSON response indicating success
        echo json_encode(['success' => true, 'message' => 'Passwort erfolgreich aktualisiert.']);
    } else {
        // Return a JSON response indicating failure
        echo json_encode(['success' => false, 'message' => 'Fehler beim Aktualisieren des Passworts: ' . $conn->error]);
    }
    exit; // Ensure the script stops executing after the response
}

// Fetch users grouped by role
$sql = "SELECT * FROM users ORDER BY role, id";
$result = $conn->query($sql);

$users_by_role = [
    'admin' => [],
    'cashier' => [],
    'assistant' => [],
    'seller' => []
];

while ($row = $result->fetch_assoc()) {
    $users_by_role[$row['role']][] = $row;
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
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Benutzer Verwalten</title>
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
    <nav class="navbar navbar-expand-lg navbar-light">
        <a class="navbar-brand" href="#">Basareno<i>Web</i></a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
				<li class="nav-item">
                    <a class="nav-link" href="admin_dashboard.php">Admin Dashboard</a>
                </li>
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
        <h1 class="text-center mb-4 headline-responsive">Benutzerverwaltung</h1>
        <div id="messageContainer">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
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
                        <option value="assistant">Assistent</option>
                        <option value="seller">Verkäufer</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="add_user">Benutzer hinzufügen</button>
        </form>

        <h3 class="mt-5">Benutzerliste</h3>

        <?php
        $roles = [
            'admin' => 'Administratoren',
            'cashier' => 'Kassierer',
            'assistant' => 'Assistenten',
            'seller' => 'Verkäufer'
        ];

        foreach ($roles as $role_key => $role_name) {
            echo '<div class="card mt-3">';
            echo '<div class="card-header">';
            echo '<h5 class="mb-0">';
            echo '<button class="btn btn-link" data-toggle="collapse" data-target="#collapse' . ucfirst($role_key) . '" aria-expanded="true" aria-controls="collapse' . ucfirst($role_key) . '">';
            echo $role_name;
            echo '</button>';
            echo '</h5>';
            echo '</div>';
            echo '<div id="collapse' . ucfirst($role_key) . '" class="collapse">';
            echo '<div class="card-body">';
            echo '<div class="table-responsive">';
            echo '<table class="table table-bordered">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>ID</th>';
            echo '<th>Benutzername</th>';
            echo '<th>Rolle</th>';
            echo '<th>Aktionen</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($users_by_role[$role_key] as $user) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td class="text-center">';
                echo '<select class="action-dropdown form-control mb-2" data-user-id="' . htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') . '">';
                echo '<option value="">Aktion wählen</option>';
                echo '<option value="delete">Löschen</option>';
                echo '<option value="set_password">Passwort setzen</option>';
                echo '</select>';
                echo '<button class="btn btn-primary btn-sm execute-action" data-user-id="' . htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') . '">Ausführen</button>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        ?>
    </div>
    
    <!-- Back to Top Button -->
    <div id="back-to-top"><i class="fas fa-arrow-up"></i></div>
    
    <!-- Set Password Modal -->
    <div class="modal fade" id="setPasswordModal" tabindex="-1" role="dialog" aria-labelledby="setPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="setPasswordModalLabel">Passwort setzen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="passwordMessage" class="alert alert-danger hidden"></div>
                    <form id="setPasswordForm" method="post">
                        <input type="hidden" id="setPasswordUserId" name="user_id">
                        <div class="form-group">
                            <label for="newPassword">Neues Passwort:</label>
                            <input type="password" class="form-control" id="newPassword" name="password" required>
                            <small class="form-text text-muted">Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Zahl enthalten.</small>
                        </div>
                        <div class="form-group">
                            <label for="confirmNewPassword">Passwort bestätigen:</label>
                            <input type="password" class="form-control" id="confirmNewPassword" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Passwort setzen</button>
                    </form>
                </div>
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

            // Handle action execution
            $('.execute-action').on('click', function() {
                const userId = $(this).data('user-id');
                const action = $(`.action-dropdown[data-user-id="${userId}"]`).val();

                if (action === 'set_password') {
                    $('#setPasswordUserId').val(userId);
                    $('#setPasswordModal').modal('show');
                } else if (action === 'delete') {
                    if (confirm('Möchten Sie diesen Benutzer wirklich löschen?')) {
                        $.post('admin_manage_users.php', { delete_user: true, user_id: userId }, function(response) {
                            location.reload();
                        });
                    }
                } else {
                    alert('Bitte wählen Sie eine Aktion aus.');
                }
            });
            
            // Handle set password form submission
            $('#setPasswordForm').on('submit', function(e) {
                e.preventDefault();
                const userId = $('#setPasswordUserId').val();
                const newPassword = $('#newPassword').val();
                const confirmNewPassword = $('#confirmNewPassword').val();

                if (newPassword !== confirmNewPassword) {
                    $('#passwordMessage').text('Die Passwörter stimmen nicht überein.').show();
                    return;
                }

                $.post('admin_manage_users.php', { set_password: true, user_id: userId, new_password: newPassword }, function(response) {
                    if (response.success) {
                        $('#setPasswordModal').modal('hide');
                        // Display success message
                        $('#messageContainer').html(
                            '<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                            response.message +
                            '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                            '<span aria-hidden="true">&times;</span>' +
                            '</button>' +
                            '</div>'
                        );
                        // Scroll to the top of the page
                        $('html, body').animate({ scrollTop: 0 }, 'slow');
                    } else {
                        $('#passwordMessage').text(response.message).show();
                    }
                }, 'json');
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