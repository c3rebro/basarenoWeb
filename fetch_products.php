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

$seller_number = $_GET['seller_number'] ?? null;

if (!$seller_number) {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

$seller_number = (int)$_GET['seller_number'];

// Fetch all products for the seller, including `in_stock` status
$sql = "SELECT 
            id, 
            name, 
            size, 
            price, 
            in_stock, 
            sold 
        FROM products 
        WHERE seller_number = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seller_number);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $active_products_count = $result->num_rows;
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Add a human-readable `stock_status` for easier differentiation
        $row['stock_status'] = $row['in_stock'] == 1 ? 'Lager' : 'Verkauf';
        $rows[] = $row;
    }
    echo json_encode([
        'success' => true, 
        'data' => $rows,
        'active_products_count' => $active_products_count, // Updated count
        ]);
} else {
    echo json_encode([
        'success' => true, 
        'data' => [],
        'active_products_count' => 0
        ]);
}

$conn->close();
?>