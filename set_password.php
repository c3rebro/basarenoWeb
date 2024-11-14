<?php
require_once 'utilities.php';

if (!isset($_GET['seller_id']) || !isset($_GET['hash'])) {
    echo "Kein Verkäufer-ID oder Hash angegeben.";
    exit();
}

$message = '';
$message_type = 'danger'; // Default message type for errors

$seller_id = $_GET['seller_id'];
$hash = $_GET['hash'];

$conn = get_db_connection();
$sql = "SELECT * FROM sellers WHERE id=? AND hash=? AND verified=1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $seller_id, $hash);
$stmt->execute();
$seller_result = $stmt->get_result();

if ($seller_result->num_rows == 0) {
    echo "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
            <title>Verkäufer Verifizierung</title>
            <link href='css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container'>
                <div class='alert alert-warning mt-5'>
                    <h4 class='alert-heading'>Ungültige oder bereits verifizierte Verkäufer-ID.</h4>
                    <p>Bitte überprüfen Sie Ihre Verkäufer-ID und versuchen Sie es erneut.</p>
                    <hr>
                    <p class='mb-0'>Haben Sie ihr Passwort vergessen?</p>
                </div>
            </div>
            <script src='js/jquery-3.7.1.min.js'></script>
            <script src='js/popper.min.js'></script>
            <script src='js/bootstrap.min.js'></script>
        </body>
        </html>
        ";
    exit();
}

$seller = $seller_result->fetch_assoc();
$email = $seller['email'];

// Check if the user exists
$sql = "SELECT * FROM users WHERE username=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows > 0) {
    echo "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>
            <title>Passwort bereits gesetzt</title>
            <link href='css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container'>
                <div class='alert alert-warning mt-5'>
                    <h4 class='alert-heading'>Passwort bereits gesetzt.</h4>
                    <p>Ihr Passwort wurde bereits gesetzt. Bitte loggen Sie sich ein oder setzen Sie Ihr Passwort zurück, falls Sie es vergessen haben.</p>
                </div>
            </div>
            <script src='js/jquery-3.7.1.min.js'></script>
            <script src='js/popper.min.js'></script>
            <script src='js/bootstrap.min.js'></script>
        </body>
        </html>
        ";
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_password'])) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Enforce password policy
    if (strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $message = "Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Zahl enthalten.";
    } elseif ($password !== $confirm_password) {
        $message = "Passwörter stimmen nicht überein.";
    } else {
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // Insert new user
        $role = 'seller'; // Assign the seller role
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $email, $password_hash, $role);
        
        if ($stmt->execute()) {
            // Update the sellers table to set verified to true
            $stmt = $conn->prepare("UPDATE sellers SET verified=1, password_hash=? WHERE id=?");
            $stmt->bind_param("si", $password_hash, $seller_id);
            $stmt->execute();
            
            $message_type = 'success';
            $message = "Passwort erfolgreich gesetzt. Sie können sich jetzt einloggen.";
            ?>
            <!DOCTYPE html>
            <html lang="de">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
                <title>Passwort setzen</title>
                <link href="css/bootstrap.min.css" rel="stylesheet">
                <style>
                    .message-container {
                        max-width: 600px;
                        margin: 50px auto;
                        text-align: center;
                    }
                </style>
            </head>
            <body>
            <div class="message-container">
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = 'login.php';
                }, 3000); // Redirect after 3 seconds
            </script>
            </body>
            </html>
            <?php
            exit();
        } else {
            $message = "Fehler beim Setzen des Passworts: " . $conn->error;
        }
    }
    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Passwort setzen</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 15px;
            border-radius: 5px;
            background-color: #f7f7f7;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-container">
        <h2 class="mt-3">Passwort setzen</h2>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form action="set_password.php?seller_id=<?php echo urlencode($seller_id); ?>&hash=<?php echo urlencode($hash); ?>" method="post">
            <input type="hidden" name="seller_id" value="<?php echo htmlspecialchars($_GET['seller_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="hash" value="<?php echo htmlspecialchars($_GET['hash'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="password">Neues Passwort:</label>
                <input type="password" class="form-control" id="password" name="password" required>
                <small class="form-text text-muted">Das Passwort muss mindestens 6 Zeichen lang sein und mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Zahl enthalten.</small>
            </div>
            <div class="form-group">
                <label for="confirm_password">Passwort bestätigen:</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="set_password">Passwort setzen</button>
        </form>
    </div>
</div>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>