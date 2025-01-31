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

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
    if (!validate_csrf_token(filter_input(INPUT_POST, 'csrf_token'))) {
        echo json_encode(['success' => false, 'message' => 'Erwischt. CSRF token.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $seller_number = $_POST['selected_seller_number'] ?? null;
    $bazaar_id = $_POST['bazaar_id'] ?? null;

    if (!$seller_number || !$bazaar_id) {
        echo json_encode(['success' => false, 'message' => 'Verkäufernummer oder aktuellen Basar nicht gefunden.']);
        exit;
    }

    $conn = get_db_connection();

    // Check if seller number is already verified
    $sql = "SELECT seller_verified FROM sellers WHERE seller_number = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $seller_number, $user_id);
    $stmt->execute();
    $is_verified = $stmt->get_result()->fetch_assoc()['seller_verified'] ?? 0;

    if ($is_verified) {
        echo json_encode(['success' => false, 'info' => true, 'message' => 'Verkäufernummer ist bereits freigeschalten. Weiter gehts im Menü "Meine Artikel".']);
        exit;
    }

    // Validate the seller number
    $sql = "UPDATE sellers SET bazaar_id = ?, seller_verified = 1 WHERE seller_number = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $bazaar_id, $seller_number, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Verkäufernummer erfolgreich freigeschalten. Du kannst Jetzt Deine Artikel anlegen.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Validieren der Verkäufernummer.']);
    }

    $conn->close();
}
?>