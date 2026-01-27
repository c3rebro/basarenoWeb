<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

// GET query, no CSRF token check
$nonce = base64_encode(random_bytes(16));
header('Content-Type: application/json');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

$conn = get_db_connection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// **🔐 Apply Rate Limiting (90 requests per 60 seconds)**
if (!rate_limit('fetch_products', 90, 60)) {
    log_action($conn, $user_id, "fetch_products: Rate limit!", "Zu viele Anfragen von: $seller_number");
    echo json_encode(['success' => false, 'message' => 'Immer langsam da.']);
    exit;
}

// Ensure user is logged in and has a valid role
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    log_action($conn, $user_id, "fetch_products: Unauthorized access.", "");
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (!isset($user_id)) {
    echo "unauthorized";
    exit();
}

// Validate seller_number input
$seller_number = $_GET['seller_number'] ?? null;
if (!$seller_number || !is_numeric($seller_number)) {
    log_action($conn, $user_id, "fetch_products: Unauthorized access.", "");
    echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
    exit;
}

// Check if the current user has permission to fetch this seller's products
if ($role === 'seller') {
    // Sellers can only fetch their own products
    $sql = "SELECT seller_number FROM sellers WHERE user_id = ? AND seller_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $seller_number);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        log_action($conn, $user_id, "fetch_products: Unauthorized access.", "Unauthorized: You do not own this seller number.");
        echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not own this seller number.']);
        exit;
    }
}

// If user is admin, cashier, or assistant, they can fetch for any seller_number (no restriction)
$allowed_roles = ['admin', 'cashier', 'assistant'];
if (!in_array($role, $allowed_roles) && $role !== 'seller') {
    log_action($conn, $user_id, "fetch_products: Unauthorized access.", "Unauthorized role.");
    echo json_encode(['success' => false, 'message' => 'Unauthorized role.']);
    exit;
}

$message = '';
$message_type = 'danger'; // Default message type for errors

if ($_SERVER['REQUEST_METHOD'] === 'GET' && intval($seller_number) > 0) {

    // Fetch seller's bazaar_id
    $sql = "SELECT bazaar_id FROM sellers WHERE seller_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $seller_number);
    $stmt->execute();
    $bazaar_id = $stmt->get_result()->fetch_assoc()['bazaar_id'];

    if (!$bazaar_id) {
        log_action($conn, $user_id, "fetch_products: Bazaar ID not found.", "Seller Number: $seller_number");
        echo json_encode([
            'success' => false, 
            'message' => 'Seller number is not linked to a bazaar.'
        ]);
        exit;
    }

    // Fetch commission from bazaar table
    $sql = "SELECT commission FROM bazaar WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $bazaar_id);
    $stmt->execute();
    $commission = $stmt->get_result()->fetch_assoc()['commission'] ?? 0;

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

    $products = [];
    $active_products_count = $result->num_rows;

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Add a human-readable `stock_status` for easier differentiation
            $row['stock_status'] = $row['in_stock'] == 1 ? 'Lager' : 'Verkauf';
            $products[] = $row;
        }
        echo json_encode([
            'success' => true, 
            'data' => $products,
            'active_products_count' => $active_products_count, // Updated count
            'commission' => $commission // ✅ Return commission for frontend calculations
            ]);         
    } else {
        echo json_encode([
            'success' => true, 
            'data' => [],
            'active_products_count' => 0,
            'commission' => $commission
            ]);
    }
} else {
    echo json_encode([
        'success' => true, 
        'data' => [],
        'active_products_count' => 0,
        'commission' => $commission
        ]);
}

$conn->close();
?>