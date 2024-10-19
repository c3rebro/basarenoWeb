<!-- load_seller_products.php -->
<?php
require_once 'config.php';

if (!isset($_GET['seller_id'])) {
    echo "Kein Verkäufer-ID angegeben.";
    exit();
}

$seller_id = $_GET['seller_id'];
$conn = get_db_connection();
$sql = "SELECT * FROM products WHERE seller_id='$seller_id'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $formatted_price = number_format($row['price'], 2, ',', '.') . ' €';
        echo "<tr>
                <td>{$row['name']}</td>
				<td>{$row['size']}</td>
                <td>{$formatted_price}</td>
                <td>
                    <button class='btn btn-warning btn-sm' onclick='editProduct({$row['id']}, \"{$row['name']}\", \"{$row['size']}\", {$row['price']})'>Bearbeiten</button>
                    <form action='admin_manage_sellers.php' method='post' style='display:inline-block'>
                        <input type='hidden' name='product_id' value='{$row['id']}'>
                        <input type='hidden' name='seller_id' value='{$seller_id}'>
                        <button type='submit' name='delete_product' class='btn btn-danger btn-sm'>Löschen</button>
                    </form>
                </td>
              </tr>";
    }
} else {
    echo "<tr><td colspan='3'>Keine Produkte gefunden.</td></tr>";
}

$conn->close();
?>