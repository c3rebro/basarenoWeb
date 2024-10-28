<?php
// utilities.php

// GLOBAL
// 

// Function to get the current bazaar ID
function get_current_bazaar_id($conn) {
    $currentDateTime = date('Y-m-d H:i:s');
    $sql = "SELECT id FROM bazaar WHERE startReqDate <= '$currentDateTime' AND startDate >= '$currentDateTime' LIMIT 1";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['id'];
    } else {
        return null;
    }
}

// allow only ASCII Letters and Numbers
function sanitize_input($input) {
    $input = preg_replace('/[^\x20-\x7E]/', '', $input);
    $input = trim($input);
    return $input;
}

// allow only decimals
function sanitize_id($input) {
    $input = preg_replace('/\D/', '', $input);
    $input = trim($input);
    return $input;
}

// Function to check for active bazaars
function has_active_bazaar($conn) {
    $current_date = date('Y-m-d');
    $sql = "SELECT COUNT(*) as count FROM bazaar WHERE startDate <= '$current_date'";
    $result = $conn->query($sql)->fetch_assoc();
    return $result['count'] > 0;
}

function get_next_checkout_id($conn) {
    $sql = "SELECT MAX(checkout_id) AS max_checkout_id FROM sellers";
    $result = $conn->query($sql);
    return $result->fetch_assoc()['max_checkout_id'] + 1;
}

function generate_hash($email, $seller_id) {
    global $SECRET;
    return hash('sha256', $email . $seller_id . $SECRET);
}

// Function to encrypt data
function encrypt_data($data, $secret) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $secret, 0, $iv);
    return base64_encode($iv . $encrypted);
}

// Function to decrypt data
function decrypt_data($data, $secret) {
    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $secret, 0, $iv);
}

// PAGE: admin_manage_bazaar.php
// 

// Function to check and add missing columns
function check_and_add_columns($conn, $table, $header) {
    $result = $conn->query("SHOW COLUMNS FROM $table");
    $existing_columns = [];
    while ($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }

    foreach ($header as $column) {
        if (!in_array($column, $existing_columns)) {
            $conn->query("ALTER TABLE $table ADD $column VARCHAR(255)");
        }
    }
}

// PAGE: index.php
function process_existing_number($conn, $email, $consent, $mailtxt_reqexistingsellerid) {
    $seller_id = $_POST['seller_id'];
    $sql = "SELECT id FROM sellers WHERE id='$seller_id' AND email='$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $next_checkout_id = get_next_checkout_id($conn);
        $hash = generate_hash($email, $seller_id);
        $verification_token = generate_verification_token();

        $sql = "UPDATE sellers SET verification_token='$verification_token', verified=0, consent='$consent', checkout_id='$next_checkout_id' WHERE id='$seller_id'";
        execute_sql_and_send_email($conn, $sql, $email, $seller_id, $hash, $verification_token, $mailtxt_reqexistingsellerid);
    } else {
        global $seller_message;
        $seller_message = "Ungültige Verkäufer-ID oder E-Mail.";
    }
}

function process_new_seller($conn, $email, $family_name, $given_name, $phone, $street, $house_number, $zip, $city, $reserve, $consent, $mailtxt_reqnewsellerid) {
    $seller_id = generate_unique_seller_id($conn);
    $next_checkout_id = get_next_checkout_id($conn);
    $hash = generate_hash($email, $seller_id);
    $verification_token = generate_verification_token();

    $sql = "INSERT INTO sellers (id, email, reserved, verification_token, family_name, given_name, phone, street, house_number, zip, city, hash, bazaar_id, consent, checkout_id) VALUES ('$seller_id', '$email', '$reserve', '$verification_token', '$family_name', '$given_name', '$phone', '$street', '$house_number', '$zip', '$city', '$hash', '$bazaarId', '$consent', '$next_checkout_id')";
    execute_sql_and_send_email($conn, $sql, $email, $seller_id, $hash, $verification_token, $mailtxt_reqnewsellerid);
}

function generate_verification_token() {
    return bin2hex(random_bytes(16));
}

function execute_sql_and_send_email($conn, $sql, $email, $seller_id, $hash, $verification_token, $mailtxt) {
    global $seller_message, $BASE_URI, $given_name, $family_name;

    if ($conn->query($sql) === TRUE) {
        $verification_link = $BASE_URI . "/verify.php?token=$verification_token&hash=$hash";
        $subject = "Verifizierung Ihrer Verkäufer-ID: $seller_id";
        $message = str_replace(
            ['{BASE_URI}', '{given_name}', '{family_name}', '{verification_link}', '{seller_id}', '{hash}'],
            [$BASE_URI, $given_name, $family_name, $verification_link, $seller_id, $hash],
            $mailtxt
        );
        $send_result = send_email($email, $subject, $message);

        if ($send_result === true) {
            $seller_message = "Eine E-Mail mit einem Bestätigungslink wurde an $email gesendet.";
        } else {
            $seller_message = "Fehler beim Senden der Bestätigungs-E-Mail: $send_result";
        }
    } else {
        $seller_message = "Fehler: " . $sql . "<br>" . $conn->error;
    }
}

function generate_unique_seller_id($conn) {
    do {
        $seller_id = rand(1, 10000);
        $sql = "SELECT id FROM sellers WHERE id='$seller_id'";
        $result = $conn->query($sql);
    } while ($result->num_rows > 0);
    return $seller_id;
}

function show_alert_existing_request() {
    echo "<script>
        alert('Eine Verkäufernr-Anfrage wurde bereits generiert. Pro Verkäufer ist in der Regel nur eine Verkäufernr zulässig. Bitte melden Sie sich per Mail, wenn Sie eine weitere Nummer haben möchten, oder wenn Sie Probleme haben, Ihre bereits angefragte Nummer frei zu Schalten.');
    </script>";
}

function show_alert_active_id() {
    echo "<script>
        alert('Eine Verkäufer Nummer wurde bereits für Sie aktiviert. Pro Verkäufer ist in der Regel nur eine Verkäufernr zulässig. Bitte melden Sie sich per Mail, wenn Sie eine weitere Nummer haben möchten.');
    </script>";
}

// PAGE: admin_manage_sellers.php

// Function to check if seller ID exists
function seller_id_exists($conn, $seller_id) {
    $sql = "SELECT id FROM sellers WHERE id='$seller_id'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

// Check if the seller has products
function seller_has_products($conn, $seller_id) {
    $sql = "SELECT COUNT(*) as product_count FROM products WHERE seller_id='$seller_id'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['product_count'] > 0;
}

?>