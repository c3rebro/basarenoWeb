<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: login.php");
    exit;
}

// Assume $user_id is available from the session or another source
$user_id = $_SESSION['user_id'] ?? 0;
$seller_id = $_SESSION['seller_id'] ?? $_GET['seller_id'] ?? '0';
$username = $_SESSION['username'] ?? '';

if (!isset($seller_id)) {
    echo "Kein Verkäufer-ID angegeben.";
    exit();
}

$conn = get_db_connection();
$sql = "SELECT * FROM products WHERE seller_id='$seller_id'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $formatted_price = number_format($row['price'], 2, ',', '.') . ' €';
		$sold_checked = $row['sold'] ? 'checked' : ''; // Assuming 'sold' is a boolean column
		
        echo "<tr>
                <td>{$row['name']}</td>
                <td>{$row['size']}</td>
                <td>{$formatted_price}</td>
                <td>
                    <input type='checkbox' class='sold-checkbox' disabled data-product-id='{$row['id']}' $sold_checked>
                </td>
                <td class='text-center'>
                    <button class='btn btn-warning btn-sm edit-product-btn' 
                        data-id='{$row['id']}' 
                        data-name='{$row['name']}' 
                        data-size='{$row['size']}' 
                        data-price='{$row['price']}'>Bearbeiten</button>
                    <form class='inline-block action-cell' action='admin_manage_sellers.php' method='post'>
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . "'>
                        <input type='hidden' name='product_id' value='{$row['id']}'>
                        <input type='hidden' name='seller_id' value='{$seller_id}'>
                        <button type='submit' name='delete_product' class='btn btn-danger btn-sm'>Löschen</button>
                    </form>
                </td>
              </tr>";
    }
} else {
    echo "<tr>
	<td>Keine Produkte gefunden.</td>
	<td>N/A</td>
	<td>N/A</td>
	<td>N/A</td>
	<td>N/A</td>
	</tr>";
}

$conn->close();
?>