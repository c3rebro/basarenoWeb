<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header('Content-Type: application/json');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'assistant' && $_SESSION['role'] !== 'seller')) {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

if (!isset($user_id)) {
    echo "unauthorized";
    exit();
}

// revoke_seller.php
require_once 'utilities.php';

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $seller_number = $_POST['selected_seller_number'] ?? null;

    if (!$seller_number) {
        echo json_encode(['success' => false, 'message' => 'Fehlende Daten.']);
        exit;
    }

    $conn = get_db_connection();
    $conn->begin_transaction();

    try {
        // Delete products associated with the seller number
        $sql = "DELETE FROM products WHERE seller_number = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $seller_number);
        $stmt->execute();

        // Revoke the seller number
        $sql = "UPDATE sellers SET user_id = 0, bazaar_id = 0, seller_verified = 0 WHERE seller_number = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $seller_number, $user_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Verkäufernummer und Artikel erfolgreich entfernt.']);
        } else {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Fehler beim lsöchen der Verkäufernummer.']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Ein Fehler ist aufgetreten: ' . $e->getMessage()]);
    }

    $conn->close();
}
?>