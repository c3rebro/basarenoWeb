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

// =========================
// Database Connection and Initialization
// =========================

/**
 * Initialize the database connection.
 *
 * @return mysqli|null
 */
function get_db_connection() {
    if (!check_config_exists()) {
        return null;
    }

    require_once 'config.php';
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

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
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
    if ($conn->query($sql) !== TRUE) {
        die("Error creating database: " . $conn->error);
    }

    // Select the database
    $conn->select_db(DB_NAME);

    // Create tables if they don't exist
    $tables = [
        "CREATE TABLE IF NOT EXISTS bazaar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            startDate DATE NOT NULL,
            startReqDate DATE NOT NULL,
            max_sellers INT NOT NULL,
            max_products_per_seller INT NOT NULL DEFAULT 0,
            brokerage DOUBLE,
            min_price DOUBLE,
            price_stepping DOUBLE,
            mailtxt_reqnewsellerid TEXT,
            mailtxt_reqexistingsellerid TEXT
        )",
        "CREATE TABLE IF NOT EXISTS sellers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hash VARCHAR(255) NOT NULL,
            bazaar_id INT(11) DEFAULT 0,
            email VARCHAR(255) NOT NULL,
            reserved BOOLEAN DEFAULT FALSE,
            verified BOOLEAN DEFAULT FALSE,
            checkout BOOLEAN DEFAULT FALSE,
            checkout_id INT(6) DEFAULT 0,
            verification_token VARCHAR(255),
            family_name VARCHAR(255) NOT NULL,
            given_name VARCHAR(255) NOT NULL,
            phone VARCHAR(255) NOT NULL,
            street VARCHAR(255) NOT NULL,
            house_number VARCHAR(255) NOT NULL,
            zip VARCHAR(255) NOT NULL,
            city VARCHAR(255) NOT NULL,
            consent BOOLEAN
        )",
        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bazaar_id INT(10) DEFAULT 0,
            name VARCHAR(255) NOT NULL,
            size VARCHAR(255) NOT NULL,
            price DOUBLE NOT NULL,
            barcode VARCHAR(255) NOT NULL,
            seller_id INT,
            sold BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (seller_id) REFERENCES sellers(id)
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'cashier') NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operationMode VARCHAR(50) NOT NULL DEFAULT 'online',
            wifi_ssid VARCHAR(255) DEFAULT '',
            wifi_password VARCHAR(255) DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($tables as $sql) {
        if ($conn->query($sql) !== TRUE) {
            die("Error creating table: " . $conn->error);
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
        'Hostapd-Dienst läuft' => (strpos(@shell_exec("systemctl is-active hostapd"), 'active') !== false),
        'Dnsmasq-Dienst läuft' => (strpos(@shell_exec("systemctl is-active dnsmasq"), 'active') !== false),
        'Hostapd-Konfiguration vorhanden' => @file_exists('/etc/hostapd/hostapd.conf'),
        'Webserver läuft' => (strpos(@shell_exec("systemctl is-active lighttpd"), 'active') !== false || 
                               strpos(@shell_exec("systemctl is-active nginx"), 'active') !== false || 
                               strpos(@shell_exec("systemctl is-active apache2"), 'active') !== false),
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
	$headers .= "Content-Type: text/html; charset=utf-8";

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
 * Sanitize input by allowing only specific characters.
 *
 * @param string $input
 * @return string
 */
function sanitize_input($input) {
    $input = preg_replace('/[^a-zA-Z0-9 \-\(\)\._@]/', '', $input);
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
 * Get the current bazaar ID.
 *
 * @param mysqli $conn
 * @return int|null
 */
function get_current_bazaar_id($conn) {
    $currentDateTime = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id FROM bazaar WHERE startReqDate <= ? AND startDate >= ? LIMIT 1");
    $stmt->bind_param("ss", $currentDateTime, $currentDateTime);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id'];
    } else {
        return null;
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
    $seller_id = $_POST['seller_id'];
    $stmt = $conn->prepare("SELECT id FROM sellers WHERE id = ? AND email = ?");
    $stmt->bind_param("is", $seller_id, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $hash = generate_hash($email, $seller_id);
        $bazaarId = get_current_bazaar_id($conn);
        $verification_token = generate_verification_token();

        $stmt = $conn->prepare("UPDATE sellers SET verification_token = ?, verified = 0, consent = ?, bazaar_id = ? WHERE id = ?");
        $stmt->bind_param("siii", $verification_token, $consent, $bazaarId, $seller_id);
        $stmt->execute();
        execute_sql_and_send_email($conn, $email, $seller_id, $hash, $verification_token, $mailtxt_reqexistingsellerid);
    } else {
        global $seller_message;
        $seller_message = "Ungültige Verkäufer-ID oder E-Mail.";
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
function process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid) {
    $seller_id = generate_unique_seller_id($conn);
    $hash = generate_hash($email, $seller_id);
    $bazaarId = get_current_bazaar_id($conn);
    $verification_token = generate_verification_token();

    $stmt = $conn->prepare("INSERT INTO sellers (id, email, reserved, verification_token, family_name, given_name, phone, street, house_number, zip, city, hash, bazaar_id, consent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isisssssssssis", $seller_id, $email, $reserve, $verification_token, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $hash, $bazaarId, $consent);
    $stmt->execute();
    execute_sql_and_send_email($conn, $email, $seller_id, $hash, $verification_token, $mailtxt_reqnewsellerid);
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
 * Execute SQL and send email.
 *
 * @param mysqli $conn
 * @param string $email
 * @param int $seller_id
 * @param string $hash
 * @param string $verification_token
 * @param string $mailtxt
 */
function execute_sql_and_send_email($conn, $email, $seller_id, $hash, $verification_token, $mailtxt) {
    global $seller_message, $given_name, $family_name;

    $verification_link = BASE_URI . "/verify.php?token=$verification_token&hash=$hash";
    $create_products_link = BASE_URI . "/seller_products.php?seller_id=$seller_id&hash=$hash";
    $revert_link = BASE_URI . "/verify.php?action=revert&seller_id=$seller_id&hash=$hash";
    $delete_link = BASE_URI . "/flush.php?seller_id=$seller_id&hash=$hash";

    $subject = "Verifizierung Ihrer Verkäufer-ID: $seller_id";
    $message = str_replace(
        ['{BASE_URI}', '{given_name}', '{family_name}', '{verification_link}', '{create_products_link}', '{revert_link}', '{delete_link}', '{seller_id}', '{hash}'],
        [BASE_URI, $given_name, $family_name, $verification_link, $create_products_link, $revert_link, $delete_link, $seller_id, $hash],
        $mailtxt
    );
    $send_result = send_email($email, $subject, $message);

    if ($send_result === true) {
        $seller_message = "Eine E-Mail mit einem Bestätigungslink wurde an $email gesendet.";
    } else {
        $seller_message = "Fehler beim Senden der Bestätigungs-E-Mail: $send_result";
    }
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
 * Show an alert for an existing request.
 */
function show_alert_existing_request() {
    echo "<script>
        alert('Eine Verkäufernr-Anfrage wurde bereits generiert. Pro Verkäufer ist in der Regel nur eine Verkäufernr zulässig. Bitte melden Sie sich per Mail, wenn Sie eine weitere Nummer haben möchten, oder wenn Sie Probleme haben, Ihre bereits angefragte Nummer frei zu Schalten.');
    </script>";
}

/**
 * Show an alert for an active ID.
 */
function show_alert_active_id() {
    echo "<script>
        alert('Eine Verkäufer Nummer wurde bereits für Sie aktiviert. Pro Verkäufer ist in der Regel nur eine Verkäufernr zulässig. Bitte melden Sie sich per Mail, wenn Sie eine weitere Nummer haben möchten.');
    </script>";
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