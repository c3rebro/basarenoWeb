<?php
// Include the config file
include 'utilities.php';

// Get the database connection
$conn = get_db_connection();

// Fetch all entries from the sellers table
$sql = "SELECT id, email FROM sellers";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    // Prepare the update statement
    $updateStmt = $conn->prepare("UPDATE sellers SET hash = ? WHERE id = ?");

    // Loop through each entry
    while ($row = $result->fetch_assoc()) {
        $seller_id = $row['id'];
        $email = $row['email'];

        // Calculate the new hash
        $hash = generate_hash($email, $seller_id);

        // Bind parameters and execute the update statement
        $updateStmt->bind_param('si', $hash, $seller_id);
        $updateStmt->execute();
    }

    $updateStmt->close();
    echo "Hashes recalculated and updated successfully!";
} else {
    echo "No entries found in the sellers table.";
}

$conn->close();
?>