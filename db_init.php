<!-- db_init.php -->
<?php
function initialize_database($conn) {
    // Check if the database exists
    $dbname = "bazaar_db";
    $db_selected = mysqli_select_db($conn, $dbname);
    
    if (!$db_selected) {
        // Create the database
        $sql = "CREATE DATABASE $dbname";
        if ($conn->query($sql) === TRUE) {
            echo "Database created successfully<br>";
        } else {
            die("Error creating database: " . $conn->error);
        }
    }
    
    // Select the database
    mysqli_select_db($conn, $dbname);
    
    // Create the bazaar table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS bazaar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        startDate DATE NOT NULL,
        startReqDate DATE NOT NULL
    )";
    if ($conn->query($sql) !== TRUE) {
        die("Error creating bazaar table: " . $conn->error);
    }
    
    // Create the sellers table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS sellers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        reserved BOOLEAN DEFAULT FALSE
    )";
    if ($conn->query($sql) !== TRUE) {
        die("Error creating sellers table: " . $conn->error);
    }
    
    // Create the products table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        price DOUBLE NOT NULL,
        barcode VARCHAR(255) NOT NULL,
        seller_id INT,
        FOREIGN KEY (seller_id) REFERENCES sellers(id)
    )";
    if ($conn->query($sql) !== TRUE) {
        die("Error creating products table: " . $conn->error);
    }
}
?>