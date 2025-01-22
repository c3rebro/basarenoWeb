<?php
session_start();

// Check if the role is set in the session
if (isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
} else {
    $role = 'guest'; // Default role or handle as needed
}

// Get the referer
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

// Clear the session
$_SESSION = array();
session_destroy();

header("Location: index.php");
/*
// Determine redirect location
if ((strpos($referer, 'seller_products.php') || 
	strpos($referer, 'seller_dashboard.php') || 
	strpos($referer, 'seller_edit.php') || 
	strpos($referer, 'index.php') || 
	strpos($referer, 'print_qrcodes.php')) !== false) {
    
} else {
    header("Location: dashboard.php");
}
*/
exit;
