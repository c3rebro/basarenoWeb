<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
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
</head>
<body>
    <div class="container mt-5">
        <div class="jumbotron text-center">
            <h1 class="display-4">Willkommen zur Bazaar-Übersicht</h1>
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
                        <a class="btn btn-primary btn-lg" href="pickup.php" role="button">Abholen</a>
                    </td>
                    <td>
                        <a class="btn btn-danger btn-lg" href="admin_manage_sellers.php" role="button">Administrieren</a>
                    </td>
                </tr>
            </table>
        </div>
    </div>

            <footer class="p-2 bg-light text-center fixed-bottom">
            <div class="row justify-content-center">
                <div class="col-lg-6 col-md-12">
                    <p class="m-0">

                    </p>
                </div>
            </div>
        </footer>
	
    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
</body>
</html>