<!-- dashboard.php -->
<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

require_once 'utilities.php';

$username = $_SESSION['username'];
$role = $_SESSION['role'];

$conn = get_db_connection();
initialize_database($conn);

// Fetch additional user info if needed
$sql = "SELECT * FROM users WHERE username='$username'";
$result = $conn->query($sql);
$user = $result->fetch_assoc();

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .dashboard-container {
            max-width: 600px;
            margin: 0 auto;
            padding-top: 50px;
        }
        .dashboard-links ul {
            padding-left: 0;
            list-style: none;
        }
        .dashboard-links li {
            margin-bottom: 10px;
        }
        .dashboard-links a {
            display: block;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            text-decoration: none;
        }
        .dashboard-links a:hover {
            background-color: #e2e6ea;
        }
    </style>
</head>
<body>
    <div class="container dashboard-container">
        <h1 class="mt-5 text-center">Willkommen, <?php echo htmlspecialchars($username); ?>!</h1>
        <p class="lead text-center">Sie sind als <?php echo htmlspecialchars($role); ?> angemeldet.</p>

        <?php if ($role == 'admin') { ?>
            <h2 class="mt-5 text-center">Administrator-Bereich</h2>
            <div class="dashboard-links">
                <ul>
                    <li><a href="admin_manage_users.php">Benutzer verwalten</a></li>
                    <li><a href="admin_manage_bazaar.php">Bazaar verwalten</a></li>
                    <li><a href="admin_manage_sellers.php">Verkäufer verwalten</a></li>
					<li><a href="system_settings.php">Systemeinstellungen</a></li>
                    <!-- Add more admin-specific links here -->
                </ul>
            </div>
        <?php } elseif ($role == 'cashier') { ?>
            <h2 class="mt-5 text-center">Kassierer-Bereich</h2>
            <div class="dashboard-links">
                <ul>
                    <li><a href="cashier.php">Artikel scannen</a></li>
                    <!-- Add more cashier-specific links here -->
                </ul>
            </div>
        <?php } ?>

        <a href="logout.php" class="btn btn-danger btn-block mt-3">Abmelden</a>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>