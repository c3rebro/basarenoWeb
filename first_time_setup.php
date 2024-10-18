<?php
$db_error = '';
$db_success = '';
$mail_error = '';
$mail_success = '';
$setup_error = '';
$setup_success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['test_db'])) {
        // Test database connection
        $db_host = $_POST['db_host'];
        $db_username = $_POST['db_username'];
        $db_password = $_POST['db_password'];
        $db_name = $_POST['db_name'];

        $db_conn = new mysqli($db_host, $db_username, $db_password);
        if ($db_conn->connect_error) {
            $db_error = "Database connection failed: " . $db_conn->connect_error;
        } else {
            $db_success = "Database connection successful!";
            $db_conn->close();
        }
    }

    if (isset($_POST['test_mail'])) {
        // Test mail settings
        $smtp_from = $_POST['smtp_from'];
        $smtp_from_name = $_POST['smtp_from_name'];
        $admin_email = $_POST['admin_email'];

        $subject = "Test Email";
        $body = "This is a test email.";
        $headers = "From: " . $smtp_from_name . " <" . $smtp_from . ">\n";
        $headers .= "MIME-Version: 1.0\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\n";

        if (mail($admin_email, $subject, $body, $headers)) {
            $mail_success = "Test email sent successfully!";
        } else {
            $mail_error = "Mail Error: Unable to send email.";
        }
    }

    if (isset($_POST['complete_setup'])) {
        // Complete setup
        $db_host = $_POST['db_host'];
        $db_name = $_POST['db_name'];
        $db_username = $_POST['db_username'];
        $db_password = $_POST['db_password'];
        $smtp_from = $_POST['smtp_from'];
        $smtp_from_name = $_POST['smtp_from_name'];
        $admin_email = $_POST['admin_email'];
        $admin_username = $_POST['admin_username'];
        $admin_password = $_POST['admin_password'];
        $config_file = 'config.php';

        // If no errors, create config.php
        if (!$db_error && !$mail_error) {
            $config_content = file_get_contents('config.php.template');
            $config_content = str_replace(
                ['<?php echo $db_host; ?>', '<?php echo $db_name; ?>', '<?php echo $db_username; ?>', '<?php echo $db_password; ?>', '<?php echo $smtp_from; ?>', '<?php echo $smtp_from_name; ?>'],
                [$db_host, $db_name, $db_username, $db_password, $smtp_from, $smtp_from_name],
                $config_content
            );
            file_put_contents($config_file, $config_content);

            // Initialize the database
            require_once 'config.php';
            $conn = get_db_connection();
            initialize_database($conn);

            // Create the admin user
            $password_hash = password_hash($admin_password, PASSWORD_BCRYPT);
            $sql = "INSERT INTO users (username, password_hash, role) VALUES ('$admin_username', '$password_hash', 'admin')";
            if ($conn->query($sql) === TRUE) {
                $setup_success = "Administrator account created successfully. Redirecting to login page...";
                header("refresh:5;url=index.php");
            } else {
                $setup_error = "Error creating admin account: " . $conn->error;
            }
            $conn->close();
        } else {
            $setup_error = "Please fix the errors above before completing the setup.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Ersteinrichtung</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin-top: 50px;
            padding: 20px;
            background-color: #ffffff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }
        .form-group label {
            font-weight: bold;
        }
        .btn-primary, .btn-secondary {
            width: 100%;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mt-3">Ersteinrichtung</h2>
        <p class="text-center">Bitte geben Sie die folgenden Informationen ein, um die Ersteinrichtung abzuschließen.</p>
        <?php if ($db_error) { echo "<div class='alert alert-danger'>$db_error</div>"; } ?>
        <?php if ($db_success) { echo "<div class='alert alert-success'>$db_success</div>"; } ?>
        <?php if ($mail_error) { echo "<div class='alert alert-danger'>$mail_error</div>"; } ?>
        <?php if ($mail_success) { echo "<div class='alert alert-success'>$mail_success</div>"; } ?>
        <?php if ($setup_error) { echo "<div class='alert alert-danger'>$setup_error</div>"; } ?>
        <?php if ($setup_success) { echo "<div class='alert alert-success'>$setup_success</div>"; } ?>
        <form action="first_time_setup.php" method="post">
            <h4>Database Settings</h4>
            <div class="form-group">
                <label for="db_host">Database Host:</label>
                <input type="text" class="form-control" id="db_host" name="db_host" required>
            </div>
            <div class="form-group">
                <label for="db_name">Database Name:</label>
                <input type="text" class="form-control" id="db_name" name="db_name" required>
            </div>
            <div class="form-group">
                <label for="db_username">Database Username:</label>
                <input type="text" class="form-control" id="db_username" name="db_username" required>
            </div>
            <div class="form-group">
                <label for="db_password">Database Password:</label>
                <input type="password" class="form-control" id="db_password" name="db_password" required>
            </div>
            <button type="submit" class="btn btn-secondary" name="test_db">Test Database Connection</button>
            
            <h4>Mail Settings</h4>
            <div class="form-group">
                <label for="smtp_from">SMTP From Email:</label>
                <input type="email" class="form-control" id="smtp_from" name="smtp_from" required>
            </div>
            <div class="form-group">
                <label for="smtp_from_name">SMTP From Name:</label>
                <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" required>
            </div>
            <div class="form-group">
                <label for="admin_email">Administrator Email (for test email):</label>
                <input type="email" class="form-control" id="admin_email" name="admin_email" required>
            </div>
            <button type="submit" class="btn btn-secondary" name="test_mail">Test Mail Settings</button>
            
            <h4>Administrator Account</h4>
            <div class="form-group">
                <label for="admin_username">Administrator Username:</label>
                <input type="text" class="form-control" id="admin_username" name="admin_username" required>
            </div>
            <div class="form-group">
                <label for="admin_password">Administrator Password:</label>
                <input type="password" class="form-control" id="admin_password" name="admin_password" required>
            </div>
            <button type="submit" class="btn btn-primary" name="complete_setup">Ersteinrichtung Abschließen</button>
        </form>
    </div>
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>