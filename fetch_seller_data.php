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

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'GET') {
    
    $conn = get_db_connection();

    // Fetch seller data for the logged-in user
    // Fetch seller numbers and their products
    $sql = "
        SELECT 
            s.seller_number, 
            s.seller_verified, 
            b.startDate AS bazaar_date, 
            b.brokerage, 
            p.id AS product_id, 
            p.name AS product_name, 
            p.size AS product_size, 
            p.price AS product_price
        FROM sellers s
        LEFT JOIN bazaar b ON b.id = s.bazaar_id
        LEFT JOIN products p ON p.seller_number = s.seller_number AND p.sold = 1
        WHERE s.user_id = ?
        ORDER BY s.seller_number, p.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $seller_data = [];
    while ($row = $result->fetch_assoc()) {
        $seller_number = $row['seller_number'];

        // Group data by seller_number
        if (!isset($seller_data[$seller_number])) {
            $seller_data[$seller_number] = [
                'seller_number' => $seller_number,
                'seller_verified' => $row['seller_verified'],
                'bazaar_date' => $row['bazaar_date'],
                'brokerage' => $row['brokerage'],
                'products' => [] // Initialize empty products array
            ];
        }

        // Add sold product data if available
        if ($row['product_id']) {
            $seller_data[$seller_number]['products'][] = [
                'id' => $row['product_id'],
                'name' => $row['product_name'] ?? '.',
                'size' => $row['product_size'] ?? '.',
                'price' => $row['product_price'] ?? '.'
            ];
        }
    }

    // Convert seller_data to indexed array
    $seller_data = array_values($seller_data);

    echo json_encode(['success' => true, 'data' => $seller_data]);

    $conn->close();
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}
?>