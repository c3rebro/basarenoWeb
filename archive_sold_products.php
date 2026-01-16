<?php
require_once 'utilities.php';

// TEMP: verbose error output (remember to remove after debugging)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Make mysqli throw exceptions so try/catch works as expected
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = get_db_connection();

// Start a transaction to ensure atomicity
$conn->begin_transaction();

try {
	// 1) Archive sold products from completed bazaars
	$archive_query = "
		INSERT INTO archive_products (id, seller_number, name, size, price, sold_date, bazaar_id)
		SELECT id, seller_number, name, size, price, NOW(), bazaar_id
		FROM products 
		WHERE in_stock = 0
		  AND sold = 1
		  AND bazaar_id IN (
			  SELECT id
			  FROM bazaar
			  WHERE DATE_ADD(start_date, INTERVAL 1 DAY) < CURRENT_DATE
		  )
	";
	$stmt = $conn->prepare($archive_query);
	if (!$stmt->execute()) {
		throw new Exception('Failed to archive sold products: ' . $conn->error);
	}

	// 2) Delete those products
	$delete_query = "
		DELETE FROM products
		WHERE in_stock = 0
		  AND sold = 1
		  AND bazaar_id IN (
			  SELECT id
			  FROM bazaar
			  WHERE DATE_ADD(start_date, INTERVAL 1 DAY) < CURRENT_DATE
		  )
	";
	$stmt = $conn->prepare($delete_query);
	if (!$stmt->execute()) {
		throw new Exception('Failed to delete sold products: ' . $conn->error);
	}

	// 3) Unverify sellers for completed bazaars
	$unverify_query = "
		UPDATE sellers
		SET seller_verified = 0
		WHERE bazaar_id IN (
			SELECT id
			FROM bazaar
			WHERE DATE_ADD(start_date, INTERVAL 1 DAY) < CURRENT_DATE
		)
	";
	$stmt = $conn->prepare($unverify_query);
	if (!$stmt->execute()) {
		throw new Exception('Failed to unverify sellers: ' . $conn->error);
	}

    $conn->commit();
    echo "Archived, deleted sold products, and unverified sellers successfully.\n";
} catch (Exception $e) {
    // Rollback transaction in case of failure
    $conn->rollback();
    error_log("Error in archive process: " . $e->getMessage(), 3, "/var/log/bazaar_archive.log");
    echo "Error during archiving: " . $e->getMessage() . "\n";
}

$conn->close();
?>

