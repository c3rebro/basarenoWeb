<?php
require_once 'config.php';

if (isset($_GET['token']) && isset($_GET['hash'])) {
    $token = $_GET['token'];
    $hash = $_GET['hash'];

    $conn = get_db_connection();
    $sql = "SELECT id, email FROM sellers WHERE verification_token='$token' AND verified=0 AND hash='$hash'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $seller = $result->fetch_assoc();
        $seller_id = $seller['id'];
        $email = $seller['email'];

        // Verify the hash
        $expected_hash = hash('sha256', $email . $seller_id . SECRET);
        if ($hash === $expected_hash) {
            // Mark the seller as verified
            $sql = "UPDATE sellers SET verified=1, verification_token=NULL WHERE id='$seller_id'";
            if ($conn->query($sql) === TRUE) {
                // Redirect to product creation page
                header("Location: seller_products.php?seller_id=$seller_id&hash=$hash");
                exit();
            } else {
                echo "Fehler beim Verifizieren des Kontos: " . $conn->error;
            }
        } else {
            echo "Ungültiger Hash.";
        }
    } else {
        echo "Ungültiger oder abgelaufener Token.";
    }

    $conn->close();
} else {
    echo "Kein Token oder Hash angegeben.";
}
?>