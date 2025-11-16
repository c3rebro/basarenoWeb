<?php
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$seller_number = $_GET['seller_number'] ?? null;

if (!$seller_number) {
    echo json_encode(['success' => false, 'message' => 'Keine Verkäufernummer angegeben.']);
    exit;
}

$conn = get_db_connection();
$sql = "SELECT pdf_path FROM bazaar_history WHERE user_id = ? AND seller_number = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $seller_number);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if (!$data || empty($data['pdf_path'])) {
    echo json_encode(['success' => false, 'message' => 'Keine Abrechnung gefunden.']);
    exit;
}

// Return the JSON receipt
echo json_encode(['success' => true] + json_decode($data['pdf_path'], true));
?>
