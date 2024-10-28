<?php
session_start();

if (!file_exists('config.php')) {
    header("location: first_time_setup.php");
    exit;
}

require_once 'config.php';
require_once 'utilities.php';

$conn = get_db_connection();
$first_time_setup = initialize_database($conn);

if ($first_time_setup) {
    header("location: first_time_setup.php");
    exit;
}

$error = '';

// Fetch bazaar dates and max_sellers
$sql = "SELECT id, startDate, startReqDate, max_sellers, mailtxt_reqnewsellerid, mailtxt_reqexistingsellerid FROM bazaar ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);
$bazaar = $result->fetch_assoc();

$currentDate = new DateTime();
$startReqDate = null;
$startDate = null;
$canRequestSellerId = false;
$bazaarOver = true; // Default to bazaar being over
$maxSellersReached = false;
$mailtxt_reqnewsellerid = null;
$mailtxt_reqexistingsellerid = null;
    
if ($bazaar) {
    $startReqDate = !empty($bazaar['startReqDate']) ? new DateTime($bazaar['startReqDate']) : null;
    $startDate = !empty($bazaar['startDate']) ? new DateTime($bazaar['startDate']) : null;
    $bazaarId = $bazaar['id'];
    $maxSellers = $bazaar['max_sellers'];
    $mailtxt_reqnewsellerid = $bazaar['mailtxt_reqnewsellerid'];
    $mailtxt_reqexistingsellerid = $bazaar['mailtxt_reqexistingsellerid'];
    
    $formattedDate = $startReqDate->format('d.m.Y');

    if ($startReqDate && $startDate) {
        $canRequestSellerId = $currentDate >= $startReqDate && $currentDate <= $startDate;
        $bazaarOver = $currentDate > $startDate;
    }

    // Check if the max sellers limit has been reached
    $sql = "SELECT COUNT(*) as count FROM sellers WHERE bazaar_id = $bazaarId";
    $result = $conn->query($sql);
    $sellerCount = $result->fetch_assoc()['count'];
    $maxSellersReached = $sellerCount >= $maxSellers;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Fetch user from the database
    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Verify the password
        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $user['role'];
            header("location: dashboard.php");
        } else {
            $error = "Ungültiger Benutzername oder Passwort";
        }
    } else {
        $error = "Ungültiger Benutzername oder Passwort";
    }
}

$seller_message = '';

// Send mail to user
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_seller_id']) && $canRequestSellerId && !$maxSellersReached) {
    $email = $_POST['email'];
    $family_name = $_POST['family_name'];
    $given_name = !empty($_POST['given_name']) ? $_POST['given_name'] : 'Nicht angegeben';
    $phone = $_POST['phone'];
    $street = !empty($_POST['street']) ? $_POST['street'] : 'Nicht angegeben';
    $house_number = !empty($_POST['house_number']) ? $_POST['house_number'] : 'Nicht angegeben';
    $zip = !empty($_POST['zip']) ? $_POST['zip'] : 'Nicht angegeben';
    $city = !empty($_POST['city']) ? $_POST['city'] : 'Nicht angegeben';
    $reserve = isset($_POST['reserve']) ? 1 : 0;
    $use_existing_number = $_POST['use_existing_number'] === 'yes';
    $consent = isset($_POST['consent']) && $_POST['consent'] === 'yes' ? 1 : 0;

    // Check if a seller ID request already exists for this email
    $sql = "SELECT verification_token, verified FROM sellers WHERE email='$email'";
    $result = $conn->query($sql);
    $existing_seller = $result->fetch_assoc();

    if ($use_existing_number) {
        process_existing_number($conn, $email, $consent, $mailtxt_reqexistingsellerid);
    } else {
        if ($existing_seller) {
            if (!empty($existing_seller['verification_token'])) {
                show_alert_existing_request();
           } elseif ($existing_seller['verified']) {
               show_alert_active_id();
           } else {
               process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid);
           }
       } else {
           process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid);
       }
    }
}


$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Bazaar Landing Page</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-row {
            margin-bottom: 1rem;
        }
        .form-check-label {
            margin-bottom: 0.5rem;
        }
        .required:after {
            content: "*";
            color: red;
            margin-left: 5px;
        }
    </style>
    <script>
        function toggleSellerIdField() {
            const useExistingNumber = document.getElementById('use_existing_number_yes').checked;
            document.getElementById('seller_id_field').style.display = useExistingNumber ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <div class="container">
        <h1 class="mt-5">Willkommen</h1>
        <p class="lead">Verkäufer können hier Verkäufernummern anfordern und Artikellisten erstellen.</p>

        <?php if ($bazaarOver): ?>
            <div class="alert alert-info">Der Bazaar ist geschlossen. Bitte kommen Sie wieder, wenn der nächste Bazaar stattfindet.</div>
        <?php elseif ($maxSellersReached): ?>
            <div class="alert alert-info">Wir entschuldigen uns, aber die maximale Anzahl an Verkäufern wurde erreicht. Die Registrierung für eine Verkäufer-ID wurde geschlossen.</div>
        <?php elseif (!$canRequestSellerId): ?>
            <div class="alert alert-info">Anfragen für neue Verkäufer-IDs sind derzeit noch nicht freigeschalten. Die nächste Nummernvergabe startet am: <?php echo htmlspecialchars($formattedDate); ?></div>
        <?php else: ?>
            <h2 class="mt-5">Verkäufer-ID anfordern</h2>
            <?php if ($seller_message) { echo "<div class='alert alert-info'>$seller_message</div>"; } ?>
            <form action="index.php" method="post">
                <div class="row form-row">
                    <div class="form-group col-md-6">
                        <label for="family_name" class="required">Nachname:</label>
                        <input type="text" class="form-control" id="family_name" name="family_name" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="given_name">Vorname:</label>
                        <input type="text" class="form-control" id="given_name" name="given_name">
                    </div>
                </div>
                <div class="row form-row">
                    <div class="form-group col-md-8">
                        <label for="street">Straße:</label>
                        <input type="text" class="form-control" id="street" name="street">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="house_number">Hausnummer:</label>
                        <input type="text" class="form-control" id="house_number" name="house_number">
                    </div>
                </div>
                <div class="row form-row">
                    <div class="form-group col-md-4">
                        <label for="zip">PLZ:</label>
                        <input type="text" class="form-control" id="zip" name="zip">
                    </div>
                    <div class="form-group col-md-8">
                        <label for="city">Stadt:</label>
                        <input type="text" class="form-control" id="city" name="city">
                    </div>
                </div>
                <div class="form-group">
                    <label for="phone" class="required">Telefonnummer:</label>
                    <input type="text" class="form-control" id="phone" name="phone" required>
                </div>
                <div class="form-group">
                    <label for="email" class="required">E-Mail:</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="reserve">Verkäufer-ID:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_yes" value="yes" onclick="toggleSellerIdField()">
                        <label class="form-check-label" for="use_existing_number_yes">
                            Ich habe bereits eine Nummer und möchte diese erneut verwenden
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="use_existing_number" id="use_existing_number_no" value="no" onclick="toggleSellerIdField()" checked>
                        <label class="form-check-label" for="use_existing_number_no">
                            Ich möchte eine neue Nummer erhalten
                        </label>
                    </div>
                </div>
                <div class="form-group" id="seller_id_field" style="display: none;">
                    <label for="seller_id">Verkäufer-ID:</label>
                    <input type="text" class="form-control" id="seller_id" name="seller_id">
                </div>
                <div class="form-group">
                    <label for="consent" class="required">Einwilligung zur Datenspeicherung:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="consent" id="consent_yes" value="yes" required>
                        <label class="form-check-label" for="consent_yes">
                            Ja: Ich möchte, dass meine persönlichen Daten bis zum nächsten Bazaar gespeichert werden. Ich kann meine Etiketten beim nächsten Mal wiederverwenden.
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="consent" id="consent_no" value="no" required>
                        <label class="form-check-label" for="consent_no">
                            Nein: Ich möchte nicht, dass meine persönlichen Daten gespeichert werden. Wenn ich das nächste Mal am Bazaar teilnehme, muss ich neue Etiketten drucken.
                        </label>
                    </div>
                </div>
                <p>Weitere Hinweise zum Datenschutz und wie wir mit Ihren Daten umgehen, erhalten Sie in unserer <a href='https://www.basar-horrheim.de/index.php/datenschutzerklaerung'>Datenschutzerklärung</a>. Bei Nutzung unserer Dienste erklären Sie sich mit den dort aufgeführen Bedingungen einverstanden.</p>
                <button type="submit" class="btn btn-primary btn-block" name="request_seller_id">Verkäufer-ID anfordern</button>
            </form>
            <p class="mt-3 text-muted">* Diese Felder sind Pflichtfelder.</p>
        <?php endif; ?>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>