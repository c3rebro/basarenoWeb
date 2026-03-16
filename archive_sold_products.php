<?php
require_once __DIR__ . '/utilities.php';

// Make mysqli throw exceptions so try/catch works as expected
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Build a helpful DB connection error for cron/CLI troubleshooting.
 *
 * @return string
 */
function build_db_connection_error_message() {
	$configPath = __DIR__ . '/config.php';
	$details = [
		'cwd=' . getcwd(),
		'config_path=' . $configPath,
		'config_exists=' . (file_exists($configPath) ? 'yes' : 'no'),
		'config_readable=' . (is_readable($configPath) ? 'yes' : 'no'),
	];

	return 'Failed to get database connection. ' . implode('; ', $details);
}

/**
 * Ensure archive table can store full UTF-8 product names.
 *
 * Legacy installations may have a narrower charset for archive_products
 * causing INSERT ... SELECT failures for names with special characters.
 *
 * @param mysqli $conn
 */
function ensure_archive_products_charset($conn) {
	$conn->query("ALTER TABLE archive_products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$conn = get_db_connection();

if (!$conn instanceof mysqli) {
	$errorMessage = build_db_connection_error_message();
	error_log("Error in archive process: {$errorMessage}", 3, "/var/log/bazaar_archive.log");
	echo "Error during archiving: {$errorMessage}\n";
	exit(1);
}

try {
	// Ensure text columns support special characters before copying sold products.
	ensure_archive_products_charset($conn);

	// Start a transaction only after validating the connection
	$conn->begin_transaction();

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
