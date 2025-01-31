<?php
// utilities.php

// =========================
// Configuration Functions
// =========================

/**
 * Check if the configuration file exists.
 *
 * @return bool
 */
function check_config_exists() {
    return file_exists('config.php');
}

/**
 * Load the configuration file.
 */
function load_config() {
    if (check_config_exists()) {
        require_once 'config.php';
    }
}

/**
 * Update the configuration file with new content.
 *
 * @param array $replacements
 */
function update_config($replacements) {
    if (!check_config_exists()) {
        return false;
    }
    
    $config_content = file_get_contents('config.php');
    foreach ($replacements as $search => $replace) {
        // Use regex to ensure accurate replacement of define statements
        $pattern = "/define\('$search', '.*?'\);/";
        $replacement = "define('$search', '$replace');";
        $config_content = preg_replace($pattern, $replacement, $config_content);
    }
    file_put_contents('config.php', $config_content);
    return true;
}

// =========================
// Database Connection and Initialization
// =========================

/**
 * Initialize the database and tables if necessary.
 *
 * @param mysqli $conn
 */
function initialize_database_if_needed($conn) {
    if (!defined('DB_INITIALIZED') || DB_INITIALIZED === 'false') {
        initialize_database($conn);

        // Update the config file to set DB_INITIALIZED to true
        update_config(['DB_INITIALIZED' => 'true']);
    }
}

/**
 * Initialize the database connection.
 *
 * @return mysqli|null
 */
function get_db_connection() {
    load_config();
    
    if (!defined('DB_SERVER')) {
        return null;
    }

    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    $conn->set_charset("utf8");

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

	initialize_database_if_needed($conn);

    return $conn;
}

/**
 * Initialize the database and tables if necessary.
 *
 * @param mysqli $conn
 * @return bool
 */
function initialize_database($conn) {
    // Create the database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS `bazaar_db`";
    if ($conn->query($sql) !== TRUE) {
        die("Error creating database: " . $conn->error);
    }

    // Select the database
    $conn->select_db('bazaar_db');

    // Define the desired table structures
    $tables = [
        "bazaar" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "startDate DATE NOT NULL",
            "startReqDate DATE NOT NULL",
            "max_sellers INT(11) NOT NULL",
            "max_products_per_seller INT(11) NOT NULL DEFAULT 0",
            "brokerage DOUBLE DEFAULT NULL",
            "min_price DOUBLE DEFAULT NULL",
            "price_stepping DOUBLE DEFAULT NULL",
            "mailtxt_reqnewsellerid TEXT DEFAULT NULL",
            "mailtxt_reqexistingsellerid TEXT DEFAULT NULL"
        ],
        "logs" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "user_id INT(11) NOT NULL",
            "action VARCHAR(255) NOT NULL",
            "details TEXT DEFAULT NULL",
            "timestamp DATETIME DEFAULT CURRENT_TIMESTAMP"
        ],
        "products" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "bazaar_id INT(10) DEFAULT 0",
			"bucket_id INT(10) DEFAULT 0",
            "name VARCHAR(255) NOT NULL",
            "size VARCHAR(255) NOT NULL",
            "price DOUBLE NOT NULL",
			"product_id INT(10) DEFAULT NULL",
            "barcode VARCHAR(255) NOT NULL UNIQUE",
            "seller_number INT(11) DEFAULT NULL",
            "sold TINYINT(1) DEFAULT 0",
            "in_stock TINYINT(1) DEFAULT 1"
        ],
        "sellers" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "user_id INT(11) NOT NULL",
            "bazaar_id INT(11) DEFAULT 0",
            "checkout_id INT(11) NOT NULL",
            "checkout TINYINT(1) DEFAULT 0",
            "fee_payed TINYINT(1) DEFAULT 0",
            "signature MEDIUMTEXT DEFAULT NULL",
            "seller_number INT(11) DEFAULT 0",
            "seller_verified TINYINT(4) DEFAULT 0",
            "reserved TINYINT(1) DEFAULT 0",
            "verification_token VARCHAR(255) DEFAULT NULL"
        ],
        "settings" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "operationMode VARCHAR(50) NOT NULL DEFAULT 'online'",
            "wifi_ssid VARCHAR(255) DEFAULT ''",
            "wifi_password VARCHAR(255) DEFAULT ''"
        ],
        "users" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "username VARCHAR(255) NOT NULL UNIQUE",
            "password_hash VARCHAR(255) NOT NULL",
            "reset_token VARCHAR(64) DEFAULT NULL",
            "reset_expiry DATETIME DEFAULT NULL",
            "role ENUM('admin', 'cashier', 'assistant', 'seller') NOT NULL",
            "verification_token TEXT DEFAULT NULL",
            "user_verified TINYINT(1) NOT NULL DEFAULT 0"
        ],
        "user_details" => [
            "id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY",
            "user_id INT(11) DEFAULT NULL",
            "bazaar_id INT(11) DEFAULT 0",
            "email VARCHAR(255) NOT NULL",
            "reserved TINYINT(1) DEFAULT 0",
            "verified TINYINT(1) DEFAULT 0",
            "family_name VARCHAR(255) NOT NULL",
            "given_name VARCHAR(255) NOT NULL",
            "phone VARCHAR(255) NOT NULL",
            "street VARCHAR(255) NOT NULL",
            "house_number VARCHAR(255) NOT NULL",
            "zip VARCHAR(255) NOT NULL",
            "city VARCHAR(255) NOT NULL",
            "consent TINYINT(1) DEFAULT NULL",
            "signature MEDIUMTEXT DEFAULT NULL"
        ]
    ];

    foreach ($tables as $tableName => $columns) {
        // Check if the table exists
        $result = $conn->query("SHOW TABLES LIKE '$tableName'");
        if ($result->num_rows == 0) {
            // Table doesn't exist, create it
            $sql = "CREATE TABLE $tableName (" . implode(", ", $columns) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8";
            if ($conn->query($sql) !== TRUE) {
                die("Error creating table $tableName: " . $conn->error);
            }
        } else {
            // Table exists, check for missing columns
            $existingColumns = [];
            $result = $conn->query("SHOW COLUMNS FROM $tableName");
            while ($row = $result->fetch_assoc()) {
                $existingColumns[] = $row['Field'];
            }

            foreach ($columns as $columnDefinition) {
                preg_match('/^\w+/', $columnDefinition, $matches);
                $columnName = $matches[0];
                if (!in_array($columnName, $existingColumns)) {
                    // Column is missing, add it
                    $sql = "ALTER TABLE $tableName ADD $columnDefinition";
                    if ($conn->query($sql) !== TRUE) {
                        die("Error adding column $columnName to table $tableName: " . $conn->error);
                    }
                }
            }
        }
    }

    // Check if the users table is empty (first time setup)
    $sql = "SELECT COUNT(*) as count FROM users";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['count'] == 0;
}


/**
 * Check preconditions.
 *
 * @param mysqli $conn
 * @return bool
 */
function check_preconditions() {
    $preconditions = [
        'WiFi-Schnittstelle verfügbar' => shell_exec("iw dev wlan0 info") !== null,
        'Hostapd-Dienst läuft' => (strpos(@shell_exec("systemctl is-active hostapd") ?? '', 'active') !== false),
        'Dnsmasq-Dienst läuft' => (strpos(@shell_exec("systemctl is-active dnsmasq") ?? '', 'active') !== false),
        'Hostapd-Konfiguration vorhanden' => @file_exists('/etc/hostapd/hostapd.conf') ?? '',
        'Webserver läuft' => (strpos(@shell_exec("systemctl is-active lighttpd") ?? '', 'active') !== false || 
                               strpos(@shell_exec("systemctl is-active nginx") ?? '', 'active') !== false || 
                               strpos(@shell_exec("systemctl is-active apache2") ?? '', 'active') !== false),
        'Berechtigungen für Shell-Befehle' => is_executable('/bin/sh'),
        'Berechtigungen für Hostapd-Konfiguration' => is_readable('/etc/hostapd/hostapd.conf') && is_writable('/etc/hostapd/hostapd.conf'),
    ];
    return $preconditions;
}

// =========================
// Security Functions
// =========================

/**
 * Generate a CSRF token.
 *
 * @return string
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token.
 *
 * @param string $token
 * @return bool
 */
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a hash for a seller.
 *
 * @param string $email
 * @param int $seller_id
 * @return string
 */
function generate_hash($email, $seller_id) {
    return hash('sha256', strtolower($email) . $seller_id . SECRET);
}

/**
 * Encrypt data.
 *
 * @param string $data
 * @return string
 */
function encrypt_data($data) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', SECRET, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt data.
 *
 * @param string $data
 * @return string
 */
function decrypt_data($data) {
    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', SECRET, 0, $iv);
}

// =========================
// Logging Functions
// =========================

/**
 * Log an action.
 *
 * @param mysqli $conn
 * @param int $user_id
 * @param string $action
 * @param string|null $details
 */
function log_action($conn, $user_id, $action, $details = null) {
    // Treat null, 0, or empty string as "Guest"
    if (is_null($user_id) || $user_id === '') {
        $user_id = 0; // Or any other identifier for "Guest"
    }

    $stmt = $conn->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $action, $details);
    if (!$stmt->execute()) {
        die("Error logging action: " . $stmt->error);
    }
}

// =========================
// Email Functions
// =========================

/**
 * Encode the subject to handle non-ASCII characters.
 *
 * @param string $subject
 * @return string
 */
function encode_subject($subject) {
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

/**
 * Send an email using PHP's mail function.
 *
 * @param string $to
 * @param string $subject
 * @param string $body
 * @return bool|string
 */
function send_email($to, $subject, $body) {
	$headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n"; 
	$headers .= "Reply-to: " . SMTP_FROM . "\r\n";
	$headers .= "MIME-Version: 1.0\r\n";
	$headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        
    if (mail($to, encode_subject($subject), $body, $headers, "-f " . SMTP_FROM)) {
        return true;
    } else {
        return 'Mail Error: Unable to send email.';
    }
}

// =========================
// Utility Functions
// =========================

/**
 * Sanitize input by allowing characters commonly found in URLs.
 *
 * @param string $input
 * @return string
 */
function sanitize_url($input) {
    // Allow basic URL characters
    $input = preg_replace('/[^a-zA-Z0-9 \-\._~:\/?#\[\]@!$&\'()*+,;=%]/', '', $input);
    $input = trim($input);
    return $input;
}

/**
 * Function to sanitize names
 *
 * @param string $input
 * @return string
 */
function sanitize_name($name) {
    // Trim whitespace at the beginning and end
    $name = trim($name);
    // Allow only letters, hyphens, and spaces
    return preg_replace('/[^a-zA-ZäöüÄÖÜß -]/u', '', $name);
}

/**
 * Function to sanitize email
 *
 * @param string $input
 * @return string
 */
function sanitize_email($email) {
    // Trim whitespace, remove display name if present, and convert to lowercase
    $email = trim($email);
    if (preg_match("/<(.*)>/", $email, $matches)) {
        $email = $matches[1];
    }
    return strtolower($email);
}

/**
 * Sanitize input by allowing only specific characters.
 *
 * @param string $input
 * @return string
 */
function sanitize_input($input) {
    $input = preg_replace('/[^a-zA-Z0-9 \-\(\)\._@<>]/', '', $input);
    $input = trim($input);
    return $input;
}

/**
 * Sanitize ID by allowing only decimal digits.
 *
 * @param string $input
 * @return string
 */
function sanitize_id($input) {
    $input = preg_replace('/\D/', '', $input);
    $input = trim($input);
    return $input;
}

/**
 * Sanitize footer content by allowing URL-specific characters and common special characters.
 *
 * @param string $input
 * @return string
 */
function sanitize_footer_content($input) {
    // Allow basic URL characters, HTML tags, and common special characters
    $input = preg_replace('/[^a-zA-Z0-9 \-\(\)\._@\/:;?&=#<>"äöüÄÖÜß]/u', '', $input);
    $input = trim($input);
    return $input;
}

/**
 * Output debug messages if debugging is enabled.
 *
 * @param string $message
 */
function debug_log($message) {
    if (DEBUG) {
        echo "<pre>DEBUG: $message</pre>";
    }
}

/**
 * Retrieve the current language.
 *
 * @return string
 */
function get_current_language() {
    return defined('LANGUAGE') ? LANGUAGE : 'en';
}

/**
 * Function to replace placeholders in the footer content
 *
 * @return string
 */
function process_footer_content($content) {
    $year = date('Y');
    return str_replace('{year}', $year, $content);
}

// =========================
// Bazaar Management
// =========================


/**
 * Check if a bazaar is running based on the current date and startDate.
 *
 * @param mysqli $conn Database connection.
 * @param int $bazaar_id The ID of the bazaar to check.
 * @return bool True if the bazaar is running, false otherwise.
 */
function is_bazaar_running($conn, $bazaar_id) {
    // Get the current date in 'YYYY-MM-DD' format
    $current_date = date('Y-m-d');

    // Query the bazaar's startDate
    $sql = "SELECT startDate FROM bazaar WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $bazaar_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if a result exists
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $start_date = $row['startDate'];

        // Compare the startDate with the current date
        if ($start_date === $current_date) {
            return true; // Bazaar is running
        }
    }

    return false; // Bazaar is not running
}


/**
 * Get the current bazaar ID.
 *
 * @param mysqli $conn
 * @return int|null
 */
function get_current_bazaar_id($conn) {
    $currentDateTime = date('Y-m-d H:i:s');
    
    // SQL Query:
    // 1. Selects the latest bazaar whose startDate is in the past and within the 14-day grace period
    // 2. If no such bazaar exists, selects the next upcoming bazaar
    $stmt = $conn->prepare("
        SELECT id 
        FROM bazaar
        WHERE startDate <= DATE_ADD(?, INTERVAL 14 DAY)
        ORDER BY 
            CASE WHEN startDate <= ? THEN 1 ELSE 2 END, -- Prefer bazaars within the grace period
            startDate ASC -- Otherwise, pick the next upcoming bazaar
        LIMIT 1
    ");
    
    // Bind parameters
    $stmt->bind_param("ss", $currentDateTime, $currentDateTime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Check if a bazaar was found
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id']; // Return the bazaar ID
    } else {
        return 0; // No relevant bazaar found
    }
}

/**
 * Get the bazaar ID with open public registration.
 *
 * @param mysqli $conn
 * @return int|null
 */
function get_bazaar_id_with_open_registration($conn) {
    $currentDateTime = date('Y-m-d H:i:s');

    // SQL Query:
    // 1. Selects the bazaar where current datetime is >= startReqDate AND < startDate
    $stmt = $conn->prepare(" 
        SELECT id 
        FROM bazaar 
        WHERE startReqDate <= ? 
          AND startDate > ? 
        ORDER BY startReqDate ASC 
        LIMIT 1
    ");

    // Bind parameters
    $stmt->bind_param("ss", $currentDateTime, $currentDateTime);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if a bazaar was found
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id']; // Return the bazaar ID
    } else {
        return null; // No bazaar found within the specified period
    }
}

/**
 * Check if there is an active bazaar.
 *
 * @param mysqli $conn
 * @return bool
 */
function has_active_bazaar($conn) {
    $current_date = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bazaar WHERE startDate <= ?");
    $stmt->bind_param("s", $current_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['count'] > 0;
}

/**
 * Get bazaar pricing rules.
 *
 * @param mysqli $conn
 * @param int $bazaar_id
 * @return array|null
 */
function get_bazaar_pricing_rules($conn, $bazaar_id) {
    $stmt = $conn->prepare("SELECT min_price, price_stepping FROM bazaar WHERE id = ?");
    $stmt->bind_param("i", $bazaar_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    } else {
        return null;
    }
}

// =========================
// Seller Management
// =========================

/**
 * Process an existing seller number.
 *
 * @param mysqli $conn
 * @param string $email
 * @param bool $consent
 * @param string $mailtxt_reqexistingsellerid
 */
function process_existing_number($conn, $email, $consent, $mailtxt_reqexistingsellerid) {
    $seller_id = filter_input(INPUT_POST, 'seller_id');
    $stmt = $conn->prepare("SELECT id FROM sellers WHERE id = ? AND email = ?");
    $stmt->bind_param("is", $seller_id, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        //$hash = generate_hash($email, $seller_id);
        //$bazaarId = get_current_bazaar_id($conn);
        $verification_token = generate_verification_token();

        $stmt = $conn->prepare("UPDATE sellers SET verification_token = ?, verified = 0, consent = ?, bazaar_id = ? WHERE id = ?");
        $stmt->bind_param("siii", $verification_token, $consent, $bazaarId, $seller_id);
        $stmt->execute();
        execute_sql_and_send_email($conn, $email, $seller_id, $hash, $verification_token, $mailtxt_reqexistingsellerid);
    } else {
        global $alertMessage;
        $alertMessage = "Ungültige Verkäufer-ID oder E-Mail.";
    }
}

/**
 * Process a new seller registration.
 *
 * @param mysqli $conn
 * @param string $email
 * @param string $family_name
 * @param string $given_name
 * @param string $phone
 * @param string $street
 * @param string $house_number
 * @param string $zip
 * @param string $city
 * @param bool $reserve
 * @param bool $consent
 * @param string $mailtxt_reqnewsellerid
 */
function process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid, $password, $nonce = 0) {
    try {
        // Hash the password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $verification_token = generate_verification_token();

        // Insert the new user into the `users` table
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, verification_token, role) VALUES (?, ?, ?, 'seller')");
        $stmt->bind_param("sss", $email, $password_hash, $verification_token);


        if ($stmt->execute()) {

                $user_id = $stmt->insert_id; // Get the inserted user's ID

                // Insert the new user details into the new database table user_details
                $stmt = $conn->prepare("INSERT INTO user_details (email, user_id, family_name, given_name, phone, street, house_number, zip, city, consent) 
                                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    "sissssssii",
                    $email,
                    $user_id,
                    $family_name,
                    $given_name,
                    $phone,
                    $street,
                    $house_number,
                    $zip,
                    $city,
                    $consent
                );

                if ($stmt->execute()) {
                        log_action($conn, 0, "New unverified user account created", "Email: $email");
                        return(execute_sql_and_send_email($conn, $email, "", "", $verification_token, $mailtxt_reqnewsellerid, $nonce));
                } else {
                        show_modal($nonce, 
                            "Ein Fehler ist aufgetreten. Der Admin wurde automatisch informiert. Bitte versuchen Sie es später erneut.", 
                            "danger",
                            "Fehler",
                            "createAccountFailed");
                }
    }
    
    } catch (Exception $e) {
        show_modal($nonce, 
            "An error occurred. The admin has been informed. Please try again later." . $e->getMessage(), 
            "danger", 
            "Error", 
            "createAccountFailed"
        );
    }
}

// =========================
// Miscellaneous Functions
// =========================

/**
 * Generate a verification token.
 *
 * @return string
 */
function generate_verification_token() {
    return bin2hex(random_bytes(16));
}

/**
 * Craft a reset Password email.
 *
 * @param mysqli $conn
 * @param string $email
 * @param int $user_id
 * @param string $hash
 * @param string $token
 * @param string $subject
 */
function send_reset_password_email($email, $verification_token) {
    $reset_link = BASE_URI . "/reset_password.php?token=$verification_token";

    $subject = "Zurücksetzen Ihres Passwortes";
    
    // Prepare the HTML email body
    $body = "
    <html>
    <head>
        <title>Passwort zurücksetzen</title>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
            .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); }
            .email-header { text-align: center; margin-bottom: 20px; }
            .email-content { line-height: 1.6; color: #333333; }
            .email-footer { margin-top: 20px; font-size: 12px; color: #777777; text-align: center; }
            .reset-button { display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='email-header'>
                <h2>Passwort zurücksetzen</h2>
            </div>
            <div class='email-content'>
                <p>Hallo,</p>
                <p>Sie haben angefordert, Ihr Passwort zurückzusetzen. Bitte klicken Sie auf den untenstehenden Button, um Ihr Passwort zu ändern:</p>
                <a href='$reset_link' class='reset-button'>Passwort zurücksetzen</a>
                <p>Wenn Sie kein Passwort-Reset angefordert haben, ignorieren Sie bitte diese E-Mail.</p>
            </div>
            <div class='email-footer'>
                <p>&copy; Wir verwenden Basareno<i>Web</i>. Die kostenlose Basarlösung.</p>
            </div>
        </div>
    </body>
    </html>
    ";

		
    // Use the existing send_email function
    return send_email($email, $subject, $body);
}

/**
 * Execute SQL and send email.
 *
 * @param mysqli $conn
 * @param string $email
 * @param int $seller_id
 * @param string $hash
 * @param string $verification_token
 * @param string $mailtxt
 */
function execute_sql_and_send_email($conn, $email, $seller_id, $hash, $verification_token, $mailtxt, $nonce = 0) {
    global $alertMessage, $alertMessage_Type, $given_name, $family_name;

    $verification_link = BASE_URI . "/verify.php?token=$verification_token";
    //$create_products_link = BASE_URI . "/seller_products.php";
    //$revert_link = BASE_URI . "/verify.php?action=revert&seller_id=$seller_id&hash=$hash";
    //$delete_link = BASE_URI . "/flush.php?seller_id=$seller_id&hash=$hash";

    $subject = "Verifizierung Ihres Verkäuferkontos."; //: $seller_id";
    $message = str_replace(
        ['{BASE_URI}', '{given_name}', '{family_name}', '{verification_link}'],
        [BASE_URI, $given_name, $family_name, $verification_link],
        $mailtxt
    );
    $send_result = send_email($email, $subject, $message);
    if ($send_result === true) {
        return true;
    } else {
        $alertMessage = "Fehler beim Senden der Bestätigungs-E-Mail: $send_result";
        $alertMessage_Type = "danger";
    }
    
    return false;
}

/**
 * Generate a unique seller ID.
 *
 * @param mysqli $conn
 * @return int
 */
function generate_unique_seller_id($conn) {
    do {
        $seller_id = rand(1, 10000);
        $stmt = $conn->prepare("SELECT id FROM sellers WHERE id = ?");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } while ($result->num_rows > 0);
    return $seller_id;
}

/**
 * Show a modal.
 *
 * @param string $nonce          A nonce for CSP compliance.
 * @param string $message        The content of the modal message.
 * @param string $message_type   The type of the modal (e.g., success, danger, info).
 * @param string $message_title  The title of the modal.
 * @param string $modalId        The unique ID for the modal (default: 'customModal').
 */
function show_modal($nonce, $message, $message_type = "danger", $message_title = "Title", $modalId = 'customModal') {
    // Ensure valid input for message type
    $validTypes = ['primary', 'success', 'danger', 'warning', 'info', 'secondary', 'light', 'dark'];
    $modalClass = in_array($message_type, $validTypes) ? "bg-$message_type" : 'bg-primary';

    echo '<div class="modal fade" id="' . $modalId . '" tabindex="-1" role="dialog" aria-labelledby="' . $modalId . 'Label" aria-hidden="true">';
    echo '  <div class="modal-dialog" role="document">';
    echo '    <div class="modal-content">';
    echo '      <div class="modal-header ' . $modalClass . ' text-white">';
    echo '        <h5 class="modal-title" id="' . $modalId . 'Label">' . htmlspecialchars($message_title) . '</h5>';
    echo '        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">';
    echo '          <span aria-hidden="true">&times;</span>';
    echo '        </button>';
    echo '      </div>';
    echo '      <div class="modal-body">';
    echo $message;
    echo '      </div>';
    echo '      <div class="modal-footer">';
    echo '        <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>';
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    // Script to auto-show the modal
    echo '<script nonce="' . $nonce . '">';
    echo 'document.addEventListener("DOMContentLoaded", function() {';
    echo '  $("#' . $modalId . '").modal("show");';
    echo '});';
    echo '</script>';
}

/**
 * Show a toast notification.
 *
 * @param string $nonce          A nonce for CSP compliance.
 * @param string $message        The content of the toast message.
 * @param string $message_type   The type of the toast (e.g., success, danger, info).
 * @param string $toastId        The unique ID for the toast (default: 'customToast').
 * @param int    $timeout        The duration in milliseconds before the toast disappears (default: 5000).
 * @param string $title          The title of the toast (default: '').
 */
function show_toast($nonce, $message, $title = '', $message_type = "info", $toastId = 'customToast', $timeout = 5000) {
    // Ensure valid input for message type
    $validTypes = ['primary', 'success', 'danger', 'warning', 'info', 'secondary', 'light', 'dark'];
    $toastClass = in_array($message_type, $validTypes) ? "bg-$message_type text-white" : 'bg-info text-white';

    // Render the toast HTML
    echo '<div id="' . $toastId . '" class="toast custom-toast" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="' . $timeout . '">';
    echo '  <div class="toast-header ' . $toastClass . '">';
    echo '    <strong class="mr-auto">' . htmlspecialchars($title ?: ucfirst($message_type)) . '</strong>'; // Use title or fallback to message_type
    echo '    <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close">';
    echo '      <span aria-hidden="true">&times;</span>';
    echo '    </button>';
    echo '  </div>';
    echo '  <div class="toast-body">';
    echo htmlspecialchars($message);
    echo '  </div>';
    echo '</div>';

    // JavaScript to initialize and show the toast
    echo '<script nonce="' . $nonce . '">';
    echo 'document.addEventListener("DOMContentLoaded", function() {';
    echo '  var toastEl = document.getElementById("' . $toastId . '");';
    echo '  if (toastEl) {';
    echo '      var toast = new bootstrap.Toast(toastEl);';
    echo '      toast.show();';
    echo '  }';
    echo '});';
    echo '</script>';
}






// =========================
// Seller Management (Admin)
// =========================

/**
 * Check if a seller ID exists.
 *
 * @param mysqli $conn
 * @param int $seller_id
 * @return bool
 */
function seller_id_exists($conn, $seller_id) {
    $stmt = $conn->prepare("SELECT id FROM sellers WHERE id = ?");
    $stmt->bind_param("i", $seller_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

/**
 * Check if a checkout ID is unique.
 *
 * @param mysqli $conn
 * @param int $seller_id
 * @return bool
 */
function is_checkout_id_unique($conn, $checkout_id, $seller_id = null) {
    $query = "SELECT id FROM sellers WHERE checkout_id = ?";
    if ($seller_id) {
        $query .= " AND id != ?";
    }
    $stmt = $conn->prepare($query);
    if ($seller_id) {
        $stmt->bind_param("si", $checkout_id, $seller_id);
    } else {
        $stmt->bind_param("s", $checkout_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows === 0;
}

/**
 * Check if a seller has products.
 *
 * @param mysqli $conn
 * @param int $seller_id
 * @return bool
 */
function seller_has_products($conn, $seller_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as product_count FROM products WHERE seller_id = ?");
    $stmt->bind_param("i", $seller_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['product_count'] > 0;
}

// =========================
// Product Management
// =========================

function clean_csv_value($value) {
    // Trim leading/trailing spaces
    $value = trim($value);

    // Check if the field starts and ends with double quotes (Excel escaping)
    if (substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
        // Remove the outermost double quotes
        $value = substr($value, 1, -1);
        
        // Convert doubled double quotes ("") back to a single double quote (")
        $value = str_replace('""', '"', $value);
    }

    return $value;
}


function normalize_price($price) {
    // Remove spaces and non-numeric characters except dots and commas
    $price = trim($price);
    
    // If the value contains both '.' and ',', assume European format (e.g., "1.300,50")
    if (strpos($price, '.') !== false && strpos($price, ',') !== false) {
        // Remove dots (thousands separator)
        $price = str_replace('.', '', $price);
    }

    // Replace last comma with a dot (handling cases like "1,3" => "1.3")
    $price = str_replace(',', '.', strrchr($price, ',') ? substr_replace($price, '.', strrpos($price, ','), 1) : $price);

    // Convert to float
    return is_numeric($price) ? (float)$price : 0;
}

function can_move_to_sale($conn, $user_id, $max_products_per_seller) {
    $sql = "SELECT COUNT(*) AS count 
            FROM products 
            WHERE seller_number IN (SELECT seller_number FROM sellers WHERE user_id = ?) 
            AND in_stock = 0 AND bazaar_id != 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['count'];
    return $count < $max_products_per_seller;
}

/**
 * Calculate the check digit for EAN-13 barcode.
 *
 * @param string $barcode
 * @return int
 */
function calculateCheckDigit($barcode) {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$barcode[$i];
        $sum += ($i % 2 === 0) ? $digit : $digit * 3;
    }
    $mod = $sum % 10;
    return ($mod === 0) ? 0 : 10 - $mod;
}

// =========================
// Admin Bazaar Management
// =========================

/**
 * Get expected columns from a database table.
 *
 * @param mysqli $conn
 * @param string $table_name
 * @return array
 * @throws InvalidArgumentException if the table name is not in the whitelist
 */
function get_expected_columns($conn, $table_name) {
    // Define a whitelist of allowed table names
    $allowed_tables = ['bazaar', 'sellers', 'settings', 'products', 'users'];

    // Check if the provided table name is in the whitelist
    if (!in_array($table_name, $allowed_tables)) {
        throw new InvalidArgumentException('Table name is not allowed.');
    }

    $columns = [];
    // Directly include the table name in the query
    $query = "SHOW COLUMNS FROM `$table_name`";
    $result = $conn->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    } else {
        throw new Exception("Failed to retrieve columns: " . $conn->error);
    }

    return $columns;
}

/**
 * Check and add missing columns to a table.
 *
 * @param mysqli $conn
 * @param string $table
 * @param array $header
 */
function check_and_add_columns($conn, $table, $header) {
    $stmt = $conn->prepare("SHOW COLUMNS FROM ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_columns = [];
    while ($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }

    foreach ($header as $column) {
        if (!in_array($column, $existing_columns)) {
            $stmt = $conn->prepare("ALTER TABLE ? ADD ? VARCHAR(255)");
            $stmt->bind_param("ss", $table, $column);
            $stmt->execute();
        }
    }
}

// =========================
// Index Page Functions
// =========================

/**
 * Get the next available checkout id.
 *
 * @param mysqli $conn
 * @return string
 */
function get_next_checkout_id($conn) {
    $stmt = $conn->prepare("SELECT MAX(checkout_id) AS max_checkout_id FROM sellers");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['max_checkout_id'] + 1;
}

/**
 * Get the operation mode from settings.
 *
 * @param mysqli $conn
 * @return string
 */
function get_operation_mode($conn) {
    $stmt = $conn->prepare("SELECT operationMode FROM settings LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['operationMode'];
    }
    return 'online'; // Default to 'online' if not set
}

// =========================
// Settings Page Functions
// =========================

/**
 * Get current settings.
 *
 * @param mysqli $conn
 * @return string
 */
function fetch_current_settings($conn) {
    $stmt = $conn->prepare("SELECT * FROM settings WHERE id = 1");
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Update the settings.
 *
 * @param mysqli $conn
 * @return string
 */
function update_settings($conn, $operationMode, $wifi_ssid, $wifi_password) {
    $stmt = $conn->prepare("UPDATE settings SET operationMode = ?, wifi_ssid = ?, wifi_password = ? WHERE id = 1");
    $stmt->bind_param("sss", $operationMode, $wifi_ssid, $wifi_password);
    return $stmt->execute();
}

?>