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

    $user_id      = (int)$_SESSION['user_id'];
    $seller_number= (int)($_POST['selected_seller_number'] ?? 0);
    $bazaar_id    = (int)($_POST['bazaar_id'] ?? 0);

    if (!$seller_number || !$bazaar_id) {
        echo json_encode(['success' => false, 'message' => 'Verkäufernummer oder aktuellen Basar nicht gefunden.']);
        exit;
    }

    $conn = get_db_connection();

    // Check current state (verified + which bazaar it's associated with)
    $sql  = "SELECT seller_verified, COALESCE(bazaar_id,0) AS current_bazaar
             FROM sellers
             WHERE seller_number = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $seller_number, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Verkäufernummer gehört nicht zu deinem Konto.']);
        exit;
    }

    $alreadyVerified = (int)($row['seller_verified'] ?? 0) === 1;
    $currentBazaar   = (int)($row['current_bazaar'] ?? 0);

    // Always make the two writes (verify + move products) in a single tx
    $conn->begin_transaction();
    try {
        // 1) Verify/activate this seller for the requested bazaar (idempotent)
        //    If it was verified for another bazaar, move it to the new one.
        $sql = "UPDATE sellers
                SET bazaar_id = ?, seller_verified = 1
                WHERE seller_number = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $bazaar_id, $seller_number, $user_id);
        $stmt->execute();

        // 2) Move ALL UNSOLD products for this seller number to the new bazaar,
        //    regardless of in_stock; keep sold items historical.
        $sql = "UPDATE products
                SET bazaar_id = ?
                WHERE seller_number = ?
                  AND sold = 0
                  AND bazaar_id <> ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $bazaar_id, $seller_number, $bazaar_id);
        $stmt->execute();
        $moved = $stmt->affected_rows; // how many rows we actually moved

        $conn->commit();

        // Friendly messaging
        if ($alreadyVerified && $currentBazaar === $bazaar_id) {
            echo json_encode([
                'success' => true,
                'info'    => true,
                'message' => "Verkäufernummer war bereits freigeschalten. $moved Artikel wurden dem aktuellen Basar zugeordnet."
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => "Verkäufernummer erfolgreich freigeschalten. $moved Artikel wurden zum aktuellen Basar übernommen."
            ]);
        }
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Fehler beim Freischalten/Übernehmen der Artikel.']);
    } finally {
        $conn->close();
    }
}
?>